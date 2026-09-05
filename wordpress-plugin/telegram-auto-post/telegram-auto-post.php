<?php
/**
 * Plugin Name: Telegram Auto Post
 * Description: Multi-bot Telegram queue with webhook controls and scheduled link previews. Enhanced UI version.
 * Version: 1.1.0
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
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_post_tap_save_bot', [$this, 'save_bot']);
        add_action('admin_post_tap_delete_bot', [$this, 'delete_bot']);
        add_action('admin_post_tap_test_bot', [$this, 'test_bot']);
        add_action('admin_post_tap_clear_queue', [$this, 'clear_queue']);
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
    public function enqueue_admin_assets($hook) {
        if ($hook !== 'settings_page_tap-settings') return;
        wp_register_style('tap-admin', false, [], '1.1.1');
        wp_enqueue_style('tap-admin');
        $css = '
        .tap-wrap{max-width:1100px;margin:24px 0;padding-right:20px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
        .tap-wrap *{box-sizing:border-box}
        .tap-header{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);border-radius:16px;padding:28px 32px;margin-bottom:24px;box-shadow:0 4px 20px rgba(102,126,234,.25)}
        .tap-header h1{color:#fff;margin:0 0 6px 0;font-size:26px;font-weight:700}
        .tap-header p{color:rgba(255,255,255,.85);margin:0;font-size:14px}
        .tap-notice{background:#fff;border-left:4px solid #10b981;border-radius:8px;padding:14px 18px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
        .tap-section{background:#fff;border-radius:12px;padding:24px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,.06);border:1px solid #e5e7eb}
        .tap-section-title{display:flex;align-items:center;gap:10px;margin:0 0 20px 0;font-size:18px;font-weight:600;color:#111827}
        .tap-section-title .tap-icon{width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;color:#fff;flex-shrink:0}
        .tap-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px}
        .tap-bot-card{background:linear-gradient(145deg,#ffffff,#f9fafb);border:1px solid #e5e7eb;border-radius:12px;padding:20px;transition:all .25s ease;position:relative;overflow:hidden}
        .tap-bot-card:hover{transform:translateY(-3px);box-shadow:0 12px 32px rgba(0,0,0,.1);border-color:#667eea}
        .tap-bot-card::before{content:"";position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,#667eea,#764ba2)}
        .tap-bot-name{font-size:17px;font-weight:600;color:#111827;margin-bottom:12px;display:flex;align-items:center;gap:8px}
        .tap-status{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:500}
        .tap-status.running{background:#d1fae5;color:#065f46}
        .tap-status.stopped{background:#f3f4f6;color:#6b7280}
        .tap-status-dot{width:7px;height:7px;border-radius:50%;background:currentColor}
        .tap-bot-meta{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px;font-size:13px}
        .tap-meta-item{background:#f9fafb;padding:8px 10px;border-radius:6px}
        .tap-meta-label{color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:.05em;margin-bottom:2px}
        .tap-meta-value{color:#111827;font-weight:500;word-break:break-all}
        .tap-bot-actions{display:flex;flex-wrap:wrap;gap:8px}
        .tap-btn{display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border-radius:8px;font-size:13px;font-weight:500;text-decoration:none;border:none;cursor:pointer;transition:all .15s ease}
        .tap-btn-primary{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff}
        .tap-btn-primary:hover{opacity:.9;color:#fff;transform:translateY(-1px)}
        .tap-btn-secondary{background:#f3f4f6;color:#374151;border:1px solid #e5e7eb}
        .tap-btn-secondary:hover{background:#e5e7eb;color:#111827}
        .tap-btn-danger{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}
        .tap-btn-danger:hover{background:#fee2e2}
        .tap-btn-success{background:#ecfdf5;color:#059669;border:1px solid #a7f3d0}
        .tap-btn-success:hover{background:#d1fae5}
        .tap-form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:18px}
        .tap-form-group{display:flex;flex-direction:column;gap:6px}
        .tap-form-group label{font-size:13px;font-weight:600;color:#374151}
        .tap-form-group input[type="text"],
        .tap-form-group input[type="password"],
        .tap-form-group input[type="number"]{padding:10px 14px;border:1.5px solid #d1d5db;border-radius:8px;font-size:14px;transition:all .15s ease;background:#fff}
        .tap-form-group input:focus{outline:none;border-color:#667eea;box-shadow:0 0 0 3px rgba(102,126,234,.15)}
        .tap-checkbox{display:flex;align-items:center;gap:8px;padding:10px 14px;background:#f9fafb;border-radius:8px;cursor:pointer}
        .tap-checkbox input{width:18px;height:18px;accent-color:#667eea}
        .tap-form-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px;padding-top:20px;border-top:1px solid #e5e7eb}
        .tap-empty{text-align:center;padding:40px 20px;color:#6b7280}
        .tap-empty-icon{font-size:48px;margin-bottom:12px;opacity:.5}
        .tap-queue-section{margin-top:24px}
        .tap-queue-list{max-height:300px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:8px}
        .tap-queue-item{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:13px}
        .tap-queue-item:last-child{border-bottom:none}
        .tap-queue-item:nth-child(even){background:#fafafa}
        .tap-queue-url{color:#111827;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-right:12px}
        .tap-queue-time{color:#6b7280;font-size:12px;flex-shrink:0}
        .tap-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600}
        .tap-badge-pending{background:#fef3c7;color:#92400e}
        .tap-badge-sent{background:#d1fae5;color:#065f46}
        @media (max-width:782px){
            .tap-wrap{padding-right:10px}
            .tap-header{padding:20px}
            .tap-section{padding:16px}
            .tap-grid{grid-template-columns:1fr}
            .tap-form-grid{grid-template-columns:1fr}
            .tap-bot-meta{grid-template-columns:1fr}
        }
        ';
        wp_add_inline_style('tap-admin', $css);
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
            $this->send_message($bot, $chat_id, "ส่ง URL ได้หลายบรรทัด\n/cancel เพื่อยกเลิก");
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
            [['text' => 'เพิ่มลิงก์', 'callback_data' => 'add_url'], ['text' => 'ดูคิว', 'callback_data' => 'view_queue']],
            [['text' => 'เริ่มโพสต์', 'callback_data' => 'start_posting'], ['text' => 'หยุด', 'callback_data' => 'stop_posting']],
            [['text' => 'ล้างคิว', 'callback_data' => 'clear_queue'], ['text' => 'สถานะ', 'callback_data' => 'show_status']],
        ]];
    }
    private function panel($bot_id) {
        global $wpdb;
        $table = self::table_name();
        $pending = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE bot_id = %s AND status = 'pending'", $bot_id));
        $sent = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE bot_id = %s AND status = 'sent'", $bot_id));
        $bot = $this->bot($bot_id);
        $hours = max(1, (int) ceil((int) $bot['interval_minutes'] / 60));
        return "{$bot['name']}\n\nสถานะ: " . (!empty($bot['running']) ? 'กำลังทำงาน' : 'หยุด') . "\nรอส่ง: {$pending} ลิงก์\nส่งแล้ว: {$sent} ลิงก์\nช่วงเวลา: {$hours} ชั่วโมง";
    }
    private function queue_text($bot_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE bot_id = %s AND status = %s ORDER BY scheduled_at ASC LIMIT 50', $bot_id, 'pending'));
        if (!$rows) return 'ยังไม่มีคิว';
        $text = ['คิวโพสต์'];
        foreach ($rows as $index => $row) $text[] = ($index + 1) . ". {$row->url}\n   {$row->scheduled_at}";
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
        if (isset($bots[$bot_id])) {
            $bots[$bot_id]['running'] = (bool) $running;
            update_option(self::OPTION, $bots, false);
            if ($running) $this->reschedule_pending($bot_id, (int) $bots[$bot_id]['interval_minutes']);
        }
    }
    private function reschedule_pending($bot_id, $interval_minutes) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id FROM ' . self::table_name() . ' WHERE bot_id = %s AND status = %s ORDER BY created_at ASC, id ASC', $bot_id, 'pending'));
        $cursor = time();
        foreach ($rows as $row) {
            $wpdb->update(self::table_name(), ['scheduled_at' => gmdate('Y-m-d H:i:s', $cursor)], ['id' => $row->id], ['%s'], ['%d']);
            $cursor += $interval_minutes * 60;
        }
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
        global $wpdb;
        $bots = $this->bots();
        $edit = isset($_GET['edit']) ? $this->bot(sanitize_key($_GET['edit'])) : null;
        $view_queue = isset($_GET['queue']) ? sanitize_key($_GET['queue']) : null;
        ?>
        <div class="tap-wrap">
            <div class="tap-header">
                <h1>Telegram Auto Post</h1>
                <p>จัดการบอท Telegram สำหรับส่งข้อความอัตโนมัติ ตั้งค่าได้หลายบอท พร้อมระบบคิวอัจฉริยะ</p>
            </div>
            <?php if (!empty($_GET['tap_saved'])): ?>
                <div class="tap-notice">บันทึกการตั้งค่าและตั้ง Webhook เรียบร้อยแล้ว</div>
            <?php endif; ?>
            <?php if (!empty($_GET['tap_deleted'])): ?>
                <div class="tap-notice" style="border-left-color:#dc2626">ลบบอทเรียบร้อยแล้ว</div>
            <?php endif; ?>
            <?php if (!empty($_GET['tap_tested'])): ?>
                <div class="tap-notice" style="border-left-color:#667eea">
                    <?php echo sanitize_text_field(urldecode($_GET['tap_tested'])); ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($_GET['tap_cleared'])): ?>
                <div class="tap-notice" style="border-left-color:#f59e0b">ล้างคิวเรียบร้อยแล้ว</div>
            <?php endif; ?>

            <?php if ($view_queue && ($qbot = $this->bot($view_queue))): ?>
                <?php
                $queue_items = $wpdb->get_results($wpdb->prepare(
                    'SELECT * FROM ' . self::table_name() . ' WHERE bot_id = %s ORDER BY FIELD(status,"pending","sent"), scheduled_at ASC LIMIT 100',
                    $view_queue
                ));
                ?>
                <div class="tap-section">
                    <h2 class="tap-section-title">
                        <span class="tap-icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                        </span>
                        คิวของ: <?php echo esc_html($qbot['name']); ?>
                        <a href="<?php echo esc_url(admin_url('options-general.php?page=tap-settings')); ?>" class="tap-btn tap-btn-secondary" style="margin-left:auto">กลับ</a>
                    </h2>
                    <?php if ($queue_items): ?>
                        <div class="tap-queue-list">
                            <?php foreach ($queue_items as $item): ?>
                                <div class="tap-queue-item">
                                    <span class="tap-queue-url" title="<?php echo esc_attr($item->url); ?>"><?php echo esc_html($item->url); ?></span>
                                    <span class="tap-badge <?php echo $item->status === 'sent' ? 'tap-badge-sent' : 'tap-badge-pending'; ?>" style="margin-right:10px">
                                        <?php echo $item->status === 'sent' ? 'ส่งแล้ว' : 'รอส่ง'; ?>
                                    </span>
                                    <span class="tap-queue-time"><?php echo esc_html($item->scheduled_at); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if (count(array_filter($queue_items, function($i){return $i->status === 'pending';})) > 0): ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:16px">
                                <?php wp_nonce_field('tap_clear_queue'); ?>
                                <input type="hidden" name="action" value="tap_clear_queue">
                                <input type="hidden" name="bot_id" value="<?php echo esc_attr($view_queue); ?>">
                                <button type="submit" class="tap-btn tap-btn-danger" onclick="return confirm('แน่ใจว่าต้องการล้างคิวที่ยังไม่ส่ง?')">ล้างคิวที่ยังไม่ส่ง</button>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="tap-empty">
                            <div class="tap-empty-icon">📋</div>
                            <p>ยังไม่มีรายการในคิว</p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="tap-section">
                <h2 class="tap-section-title">
                    <span class="tap-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
                    </span>
                    บอทที่ตั้งค่าไว้
                    <?php if (!$edit && !$view_queue): ?>
                        <a href="#add-bot" class="tap-btn tap-btn-primary" style="margin-left:auto">+ เพิ่มบอทใหม่</a>
                    <?php endif; ?>
                </h2>
                <?php if ($bots): ?>
                    <div class="tap-grid">
                        <?php foreach ($bots as $id => $bot):
                            $pending = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table_name() . " WHERE bot_id = %s AND status = 'pending'", $id));
                            $sent = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table_name() . " WHERE bot_id = %s AND status = 'sent'", $id));
                            $hours = max(1, (int) ceil((int) $bot['interval_minutes'] / 60));
                        ?>
                            <div class="tap-bot-card">
                                <div class="tap-bot-name">
                                    <?php echo esc_html($bot['name']); ?>
                                    <span class="tap-status <?php echo !empty($bot['running']) ? 'running' : 'stopped'; ?>">
                                        <span class="tap-status-dot"></span>
                                        <?php echo !empty($bot['running']) ? 'กำลังทำงาน' : 'หยุด'; ?>
                                    </span>
                                </div>
                                <div class="tap-bot-meta">
                                    <div class="tap-meta-item">
                                        <div class="tap-meta-label">รอส่ง</div>
                                        <div class="tap-meta-value"><?php echo $pending; ?> ลิงก์</div>
                                    </div>
                                    <div class="tap-meta-item">
                                        <div class="tap-meta-label">ส่งแล้ว</div>
                                        <div class="tap-meta-value"><?php echo $sent; ?> ลิงก์</div>
                                    </div>
                                    <div class="tap-meta-item">
                                        <div class="tap-meta-label">ช่วงเวลา</div>
                                        <div class="tap-meta-value"><?php echo $hours; ?> ชั่วโมง</div>
                                    </div>
                                    <div class="tap-meta-item">
                                        <div class="tap-meta-label">Chat ID</div>
                                        <div class="tap-meta-value"><?php echo esc_html($bot['target_chat_id']); ?></div>
                                    </div>
                                </div>
                                <div style="margin-bottom:12px">
                                    <div class="tap-meta-label" style="margin-bottom:4px">Webhook URL</div>
                                    <code style="font-size:11px;background:#f3f4f6;padding:6px 8px;border-radius:6px;display:block;overflow-x:auto;color:#374151;word-break:break-all"><?php echo esc_html(rest_url('telegram-auto-post/v1/webhook/' . $id . '/' . $bot['webhook_secret'])); ?></code>
                                </div>
                                <div class="tap-bot-actions">
                                    <a class="tap-btn tap-btn-secondary" href="<?php echo esc_url(admin_url('options-general.php?page=tap-settings&edit=' . $id)); ?>">แก้ไข</a>
                                    <a class="tap-btn tap-btn-secondary" href="<?php echo esc_url(admin_url('options-general.php?page=tap-settings&queue=' . $id)); ?>">ดูคิว</a>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                                        <?php wp_nonce_field('tap_test_bot'); ?>
                                        <input type="hidden" name="action" value="tap_test_bot">
                                        <input type="hidden" name="bot_id" value="<?php echo esc_attr($id); ?>">
                                        <button type="submit" class="tap-btn tap-btn-success">ทดสอบ</button>
                                    </form>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                                        <?php wp_nonce_field('tap_delete_bot'); ?>
                                        <input type="hidden" name="action" value="tap_delete_bot">
                                        <input type="hidden" name="bot_id" value="<?php echo esc_attr($id); ?>">
                                        <button type="submit" class="tap-btn tap-btn-danger" onclick="return confirm('แน่ใจว่าต้องการลบบอทนี้?')">ลบ</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="tap-empty">
                        <div class="tap-empty-icon">🤖</div>
                        <p>ยังไม่มีบอทที่ตั้งค่าไว้ เพิ่มบอทแรกของคุณด้านล่าง</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="tap-section" id="add-bot">
                <h2 class="tap-section-title">
                    <span class="tap-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                    </span>
                    <?php echo $edit ? 'แก้ไขบอท: ' . esc_html($edit['name']) : 'เพิ่มบอทใหม่'; ?>
                    <?php if ($edit): ?>
                        <a href="<?php echo esc_url(admin_url('options-general.php?page=tap-settings')); ?>" class="tap-btn tap-btn-secondary" style="margin-left:auto">ยกเลิก</a>
                    <?php endif; ?>
                </h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('tap_save_bot'); ?>
                    <input type="hidden" name="action" value="tap_save_bot">
                    <input type="hidden" name="bot_id" value="<?php echo esc_attr($edit['id'] ?? ''); ?>">
                    <div class="tap-form-grid">
                        <div class="tap-form-group">
                            <label>ชื่อบอท</label>
                            <input required name="name" type="text" value="<?php echo esc_attr($edit['name'] ?? ''); ?>" placeholder="เช่น ข่าวอัปเดตทุกวัน">
                        </div>
                        <div class="tap-form-group">
                            <label>Bot Token</label>
                            <input required name="token" type="password" value="<?php echo esc_attr($edit['token'] ?? ''); ?>" placeholder="123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11" autocomplete="new-password">
                        </div>
                        <div class="tap-form-group">
                            <label>Target Chat ID</label>
                            <input required name="target_chat_id" type="text" value="<?php echo esc_attr($edit['target_chat_id'] ?? ''); ?>" placeholder="-1001234567890">
                        </div>
                        <div class="tap-form-group">
                            <label>Admin User ID</label>
                            <input required name="admin_user_id" type="text" value="<?php echo esc_attr($edit['admin_user_id'] ?? ''); ?>" placeholder="123456789">
                        </div>
                        <div class="tap-form-group">
                            <label>ช่วงเวลา (ชั่วโมง)</label>
                            <input required min="1" type="number" name="interval_hours" value="<?php echo esc_attr(max(1, (int) ceil((int) ($edit['interval_minutes'] ?? 60) / 60))); ?>">
                        </div>
                        <div class="tap-form-group">
                            <label style="visibility:hidden">เปิดใช้งาน</label>
                            <label class="tap-checkbox">
                                <input type="checkbox" name="running" value="1" <?php checked(!empty($edit['running'])); ?>>
                                <span>เริ่มส่งตามคิวทันทีหลังบันทึก</span>
                            </label>
                        </div>
                    </div>
                    <div class="tap-form-actions">
                        <button type="submit" class="tap-btn tap-btn-primary">บันทึกและตั้ง Webhook</button>
                        <?php if ($edit): ?>
                            <a href="<?php echo esc_url(admin_url('options-general.php?page=tap-settings')); ?>" class="tap-btn tap-btn-secondary">ยกเลิก</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }
    public function save_bot() {
        if (!current_user_can('manage_options')) wp_die('Forbidden'); check_admin_referer('tap_save_bot');
        $id = sanitize_key($_POST['bot_id'] ?? '') ?: 'bot_' . wp_generate_password(8, false, false); $bots = $this->bots();
        $old = $bots[$id] ?? [];
        $interval_hours = max(1, absint($_POST['interval_hours'] ?? 1));
        $bots[$id] = [
            'id' => $id,
            'name' => sanitize_text_field($_POST['name']),
            'token' => sanitize_text_field($_POST['token']),
            'target_chat_id' => sanitize_text_field($_POST['target_chat_id']),
            'admin_user_id' => sanitize_text_field($_POST['admin_user_id']),
            'interval_minutes' => $interval_hours * 60,
            'running' => !empty($_POST['running']),
            'webhook_secret' => $old['webhook_secret'] ?? wp_generate_password(32, false, false)
        ];
        update_option(self::OPTION, $bots, false);
        $url = rest_url('telegram-auto-post/v1/webhook/' . $id . '/' . $bots[$id]['webhook_secret']);
        $this->telegram($bots[$id], 'setWebhook', ['url' => $url, 'allowed_updates' => wp_json_encode(['message', 'callback_query'])]);
        if ($bots[$id]['running']) {
            $this->reschedule_pending($id, $bots[$id]['interval_minutes']);
        }
        wp_safe_redirect(admin_url('options-general.php?page=tap-settings&tap_saved=1')); exit;
    }
    public function delete_bot() {
        if (!current_user_can('manage_options')) wp_die('Forbidden'); check_admin_referer('tap_delete_bot');
        $id = sanitize_key($_POST['bot_id'] ?? '');
        if ($id) {
            $bots = $this->bots();
            if (isset($bots[$id])) {
                $bot = $bots[$id];
                unset($bots[$id]);
                update_option(self::OPTION, $bots, false);
                delete_option('tap_waiting_' . $id);
                global $wpdb;
                $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table_name() . ' WHERE bot_id = %s', $id));
                $this->telegram($bot, 'deleteWebhook');
            }
        }
        wp_safe_redirect(admin_url('options-general.php?page=tap-settings&tap_deleted=1')); exit;
    }
    public function test_bot() {
        if (!current_user_can('manage_options')) wp_die('Forbidden'); check_admin_referer('tap_test_bot');
        $id = sanitize_key($_POST['bot_id'] ?? '');
        $bot = $this->bot($id);
        $msg = '';
        if ($bot) {
            $result = $this->send_message($bot, $bot['target_chat_id'], 'ทดสอบการเชื่อมต่อ Telegram Auto Post สำเร็จ!');
            if (is_wp_error($result)) {
                $msg = 'ทดสอบล้มเหลว: ' . $result->get_error_message();
            } else {
                $msg = 'ทดสอบสำเร็จ! ข้อความทดสอบถูกส่งไปยังกลุ่มเป้าหมายแล้ว';
            }
        }
        wp_safe_redirect(admin_url('options-general.php?page=tap-settings&tap_tested=' . urlencode($msg))); exit;
    }
    public function clear_queue() {
        if (!current_user_can('manage_options')) wp_die('Forbidden'); check_admin_referer('tap_clear_queue');
        $id = sanitize_key($_POST['bot_id'] ?? '');
        if ($id) {
            global $wpdb;
            $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table_name() . ' WHERE bot_id = %s AND status = %s', $id, 'pending'));
        }
        wp_safe_redirect(admin_url('options-general.php?page=tap-settings&tap_cleared=1')); exit;
    }
}
add_filter('cron_schedules', function ($schedules) { $schedules['minute'] = ['interval' => 60, 'display' => 'Every minute']; return $schedules; });
register_activation_hook(__FILE__, ['TAP_Telegram_Auto_Post', 'activate']);
register_deactivation_hook(__FILE__, ['TAP_Telegram_Auto_Post', 'deactivate']);
TAP_Telegram_Auto_Post::boot();
