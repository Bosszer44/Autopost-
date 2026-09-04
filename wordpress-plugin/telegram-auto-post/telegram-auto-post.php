<?php
/**
 * Plugin Name: Telegram Auto Post
 * Description: Multi-bot Telegram queue with webhook controls and scheduled link previews.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

final class TAP_Telegram_Auto_Post {
    private const OPTION = 'tap_bots';
    private const CRON = 'tap_process_queues';
    private static $instance;

    public static function boot() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action(self::CRON, [$this, 'process_queues']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_post_tap_save_bot', [$this, 'save_bot']);
        add_action('admin_post_tap_delete_bot', [$this, 'delete_bot']);
        add_action('admin_post_tap_test_bot', [$this, 'test_bot']);
    }

    public static function activate() {
        global $wpdb;
        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            bot_id varchar(64) NOT NULL,
            url text NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            scheduled_at datetime NOT NULL,
            sent_at datetime NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY bot_status_time (bot_id, status, scheduled_at)
        ) {$charset};");
        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + 60, 'minute', self::CRON);
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::CRON);
    }

    private static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'tap_queue';
    }

    private function bots() {
        $bots = get_option(self::OPTION, []);
        return is_array($bots) ? $bots : [];
    }

    private function bot($id) {
        $bots = $this->bots();
        return isset($bots[$id]) && is_array($bots[$id]) ? $bots[$id] : null;
    }

    public function register_routes() {
        register_rest_route('telegram-auto-post/v1', '/webhook/(?P<bot_id>[a-zA-Z0-9_-]+)/(?P<secret>[a-zA-Z0-9_-]+)', [
            'methods' => 'POST',
            'callback' => [$this, 'webhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function webhook(WP_REST_Request $request) {
        $bot_id = sanitize_key($request['bot_id']);
        $bot = $this->bot($bot_id);
        if (!$bot || !hash_equals((string) $bot['webhook_secret'], (string) $request['secret'])) {
            return new WP_Error('tap_forbidden', 'Invalid webhook.', ['status' => 403]);
        }

        $update = $request->get_json_params();
        if (!is_array($update)) {
            return new WP_Error('tap_invalid_update', 'Invalid Telegram update.', ['status' => 400]);
        }
        if (!empty($update['callback_query'])) {
            $this->handle_callback($bot_id, $bot, $update['callback_query']);
        } elseif (!empty($update['message'])) {
            $this->handle_message($bot_id, $bot, $update['message']);
        }
        return new WP_REST_Response(['ok' => true], 200);
    }

    private function is_admin($bot, $message) {
        $user = $message['from'] ?? [];
        return (string) ($user['id'] ?? '') === (string) $bot['admin_user_id']
            && ($message['chat']['type'] ?? '') === 'private';
    }

    private function handle_message($bot_id, $bot, $message) {
        if (!$this->is_admin($bot, $message)) {
            return;
        }
        $chat_id = $message['chat']['id'];
        $text = trim((string) ($message['text'] ?? ''));
        $state = get_option('tap_waiting_' . $bot_id, false);
        if ($text === '/start' || $text === '/menu') {
            update_option('tap_waiting_' . $bot_id, false, false);
            $this->send_message($bot, $chat_id, $this->panel($bot_id), $this->keyboard());
        } elseif ($text === '/cancel') {
            update_option('tap_waiting_' . $bot_id, false, false);
            $this->send_message($bot, $chat_id, 'ยกเลิกการเพิ่มลิงก์แล้ว', $this->keyboard());
        } elseif ($text === '/queue') {
            $this->send_message($bot, $chat_id, $this->queue_text($bot_id));
        } elseif ($text === '/status') {
            $this->send_message($bot, $chat_id, $this->panel($bot_id), $this->keyboard());
        } elseif ($text === '/stop') {
            $this->set_running($bot_id, false);
            $this->send_message($bot, $chat_id, $this->panel($bot_id), $this->keyboard());
        } elseif ($text === '/add' || strpos($text, '/add ') === 0 || $state) {
            $content = strpos($text, '/add') === 0 ? trim(substr($text, 4)) : $text;
            $result = $this->add_urls($bot_id, $bot, $content);
            update_option('tap_waiting_' . $bot_id, false, false);
            $this->send_message($bot, $chat_id, "เพิ่ม {$result['added']} ลิงก์ | ซ้ำ {$result['duplicates']} ลิงก์", $this->keyboard());
        }
    }

    private function handle_callback($bot_id, $bot, $callback) {
        $this->telegram($bot, 'answerCallbackQuery', ['callback_query_id' => $callback['id']]);
        $callback_message = $callback['message'];
        $callback_message['from'] = $callback['from'] ?? [];
        if (!$this->is_admin($bot, $callback_message)) {
            return;
        }
        $chat_id = $callback['message']['chat']['id'];
        $action = $callback['data'] ?? '';
        if ($action === 'add_url') {
            update_option('tap_waiting_' . $bot_id, true, false);
            $this->send_message($bot, $chat_id, "➕ ส่ง URL ได้หลายบรรทัด\n/cancel เพื่อยกเลิก");
        } elseif ($action === 'view_queue') {
            $this->send_message($bot, $chat_id, $this->queue_text($bot_id));
        } elseif ($action === 'start_posting') {
            $this->set_running($bot_id, true);
            $this->send_message($bot, $chat_id, $this->panel($bot_id), $this->keyboard());
        } elseif ($action === 'stop_posting') {
            $this->set_running($bot_id, false);
            $this->send_message($bot, $chat_id, $this->panel($bot_id), $this->keyboard());
        } elseif ($action === 'clear_queue') {
            global $wpdb;
            $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table_name() . ' WHERE bot_id = %s AND status = %s', $bot_id, 'pending'));
            $this->send_message($bot, $chat_id, 'ล้างคิวที่ยังไม่ส่งแล้ว', $this->keyboard());
        } elseif ($action === 'show_status') {
            $this->send_message($bot, $chat_id, $this->panel($bot_id), $this->keyboard());
        }
    }

    private function keyboard() {
        return ['inline_keyboard' => [
            [['text' => '➕ เพิ่มลิงก์', 'callback_data' => 'add_url'], ['text' => '📋 ดูคิว', 'callback_data' => 'view_queue']],
            [['text' => '▶️ เริ่มโพสต์', 'callback_data' => 'start_posting'], ['text' => '⏸ หยุด', 'callback_data' => 'stop_posting']],
            [['text' => '🗑 ล้างคิว', 'callback_data' => 'clear_queue'], ['text' => '📊 สถานะ', 'callback_data' => 'show_status']],
        ]];
    }

    private function panel($bot_id) {
        global $wpdb;
        $table = self::table_name();
        $pending = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE bot_id = %s AND status = 'pending'", $bot_id));
        $bot = $this->bot($bot_id);
        return "🤖 {$bot['name']}\n\nสถานะ: " . (!empty($bot['running']) ? '🟢 กำลังทำงาน' : '⏸ หยุด') . "\n⏳ รอส่ง: {$pending}\n⏱ ช่วงเวลา: {$bot['interval_minutes']} นาที";
    }

    private function queue_text($bot_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE bot_id = %s AND status = %s ORDER BY scheduled_at ASC LIMIT 50', $bot_id, 'pending'));
        if (!$rows) return '📋 ยังไม่มีคิว';
        $text = ['📋 คิวโพสต์'];
        foreach ($rows as $index => $row) $text[] = ($index + 1) . ". ⏳ {$row->url}\n   ⏰ {$row->scheduled_at}";
        return implode("\n", $text);
    }

    private function add_urls($bot_id, $bot, $content) {
        global $wpdb;
        preg_match_all('~https?://[^\s<>]+~i', $content, $matches);
        $urls = array_unique($matches[0]);
        $added = 0; $duplicates = 0;
        $last = $wpdb->get_var($wpdb->prepare('SELECT scheduled_at FROM ' . self::table_name() . ' WHERE bot_id = %s AND status = %s ORDER BY scheduled_at DESC LIMIT 1', $bot_id, 'pending'));
        $cursor = $last ? strtotime($last) + ((int) $bot['interval_minutes'] * 60) : time();
        foreach ($urls as $url) {
            $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table_name() . ' WHERE bot_id = %s AND url = %s AND status = %s', $bot_id, esc_url_raw($url), 'pending'));
            if ($exists) { $duplicates++; continue; }
            $wpdb->insert(self::table_name(), ['bot_id' => $bot_id, 'url' => esc_url_raw($url), 'status' => 'pending', 'scheduled_at' => gmdate('Y-m-d H:i:s', $cursor), 'created_at' => current_time('mysql', true)], ['%s', '%s', '%s', '%s', '%s']);
            $cursor += (int) $bot['interval_minutes'] * 60; $added++;
        }
        return compact('added', 'duplicates');
    }

    private function set_running($bot_id, $running) {
        $bots = $this->bots();
        if (isset($bots[$bot_id])) { $bots[$bot_id]['running'] = (bool) $running; update_option(self::OPTION, $bots, false); }
    }

    public function process_queues() {
        global $wpdb;
        foreach ($this->bots() as $bot_id => $bot) {
            if (empty($bot['running'])) continue;
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table_name() . " WHERE bot_id = %s AND status = 'pending' AND scheduled_at <= UTC_TIMESTAMP() ORDER BY scheduled_at ASC LIMIT 1", $bot_id));
            if (!$row) continue;
            $result = $this->send_message($bot, $bot['target_chat_id'], $row->url, null, true);
            if (!is_wp_error($result)) {
                $wpdb->update(self::table_name(), ['status' => 'sent', 'sent_at' => current_time('mysql', true)], ['id' => $row->id], ['%s', '%s'], ['%d']);
            }
        }
    }

    private function send_message($bot, $chat_id, $text, $keyboard = null, $preview = false) {
        $parameters = ['chat_id' => $chat_id, 'text' => $text];
        if ($keyboard) $parameters['reply_markup'] = wp_json_encode($keyboard, JSON_UNESCAPED_UNICODE);
        if ($preview) $parameters['link_preview_options'] = wp_json_encode(['is_disabled' => false, 'prefer_large_media' => true]);
        return $this->telegram($bot, 'sendMessage', $parameters);
    }

    private function telegram($bot, $method, $parameters = []) {
        $response = wp_remote_post('https://api.telegram.org/bot' . rawurlencode($bot['token']) . '/' . $method, ['timeout' => 20, 'body' => $parameters]);
        if (is_wp_error($response)) return $response;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return !empty($body['ok']) ? $body['result'] : new WP_Error('tap_telegram_error', $body['description'] ?? 'Telegram API error');
    }

    public function admin_menu() { add_options_page('Telegram Auto Post', 'Telegram Auto Post', 'manage_options', 'tap-settings', [$this, 'settings_page']); }

    public function settings_page() {
        if (!current_user_can('manage_options')) return;
        $bots = $this->bots(); $edit = isset($_GET['edit']) ? $this->bot(sanitize_key($_GET['edit'])) : null;
        ?>
        <div class="wrap"><h1>Telegram Auto Post</h1>
        <p>ตั้งค่าได้หลายบอท ข้อมูลจะไม่ถูกแสดงบนหน้าเว็บผู้เข้าชม</p>
        <?php if (!empty($_GET['tap_saved'])): ?><div class="notice notice-success is-dismissible"><p>บันทึกการตั้งค่าแล้ว</p></div><?php endif; ?>
        <h2>บอทที่ตั้งค่าไว้</h2><table class="widefat"><thead><tr><th>ชื่อ</th><th>สถานะ</th><th>ช่วงเวลา</th><th>Webhook</th><th></th></tr></thead><tbody>
        <?php foreach ($bots as $id => $bot): ?><tr><td><?php echo esc_html($bot['name']); ?></td><td><?php echo !empty($bot['running']) ? 'กำลังทำงาน' : 'หยุด'; ?></td><td><?php echo esc_html($bot['interval_minutes']); ?> นาที</td><td><code><?php echo esc_html(rest_url('telegram-auto-post/v1/webhook/' . $id . '/' . $bot['webhook_secret'])); ?></code></td><td><a class="button" href="<?php echo esc_url(admin_url('options-general.php?page=tap-settings&edit=' . $id)); ?>">แก้ไข</a></td></tr><?php endforeach; ?>
        </tbody></table>
        <h2><?php echo $edit ? 'แก้ไขบอท' : 'เพิ่มบอท'; ?></h2><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('tap_save_bot'); ?><input type="hidden" name="action" value="tap_save_bot"><input type="hidden" name="bot_id" value="<?php echo esc_attr($edit['id'] ?? ''); ?>">
        <table class="form-table"><tr><th><label>ชื่อบอท</label></th><td><input required name="name" class="regular-text" value="<?php echo esc_attr($edit['name'] ?? ''); ?>"></td></tr><tr><th><label>Bot Token</label></th><td><input required name="token" type="password" class="regular-text" value="<?php echo esc_attr($edit['token'] ?? ''); ?>" autocomplete="new-password"></td></tr><tr><th><label>Target Chat ID</label></th><td><input required name="target_chat_id" class="regular-text" value="<?php echo esc_attr($edit['target_chat_id'] ?? ''); ?>"></td></tr><tr><th><label>Admin User ID</label></th><td><input required name="admin_user_id" class="regular-text" value="<?php echo esc_attr($edit['admin_user_id'] ?? ''); ?>"></td></tr><tr><th><label>ช่วงเวลา (นาที)</label></th><td><input required min="1" type="number" name="interval_minutes" value="<?php echo esc_attr($edit['interval_minutes'] ?? 60); ?>"></td></tr><tr><th>เปิดใช้งาน</th><td><label><input type="checkbox" name="running" value="1" <?php checked(!empty($edit['running'])); ?>> เริ่มส่งตามคิว</label></td></tr></table>
        <?php submit_button('บันทึกและตั้ง Webhook'); ?></form></div>
        <?php
    }

    public function save_bot() {
        if (!current_user_can('manage_options')) wp_die('Forbidden'); check_admin_referer('tap_save_bot');
        $id = sanitize_key($_POST['bot_id'] ?? '') ?: 'bot_' . wp_generate_password(8, false, false); $bots = $this->bots();
        $old = $bots[$id] ?? [];
        $bots[$id] = ['id' => $id, 'name' => sanitize_text_field($_POST['name']), 'token' => sanitize_text_field($_POST['token']), 'target_chat_id' => sanitize_text_field($_POST['target_chat_id']), 'admin_user_id' => sanitize_text_field($_POST['admin_user_id']), 'interval_minutes' => max(1, absint($_POST['interval_minutes'])), 'running' => !empty($_POST['running']), 'webhook_secret' => $old['webhook_secret'] ?? wp_generate_password(32, false, false)];
        update_option(self::OPTION, $bots, false);
        $url = rest_url('telegram-auto-post/v1/webhook/' . $id . '/' . $bots[$id]['webhook_secret']);
        $this->telegram($bots[$id], 'setWebhook', ['url' => $url, 'allowed_updates' => wp_json_encode(['message', 'callback_query'])]);
        wp_safe_redirect(admin_url('options-general.php?page=tap-settings&tap_saved=1')); exit;
    }

    public function delete_bot() { /* Reserved for a future UI action. */ }
    public function test_bot() { /* Reserved for a future UI action. */ }
}

add_filter('cron_schedules', function ($schedules) { $schedules['minute'] = ['interval' => 60, 'display' => 'Every minute']; return $schedules; });
register_activation_hook(__FILE__, ['TAP_Telegram_Auto_Post', 'activate']);
register_deactivation_hook(__FILE__, ['TAP_Telegram_Auto_Post', 'deactivate']);
TAP_Telegram_Auto_Post::boot();