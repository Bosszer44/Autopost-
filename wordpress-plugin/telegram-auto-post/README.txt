Telegram Auto Post WordPress Plugin

Installation
1. Zip the telegram-auto-post folder.
2. In WordPress, open Plugins > Add New > Upload Plugin.
3. Activate Telegram Auto Post.
4. Open Settings > Telegram Auto Post.
5. Add one or more bots and save each one. Saving automatically registers its Telegram webhook.

Requirements
- WordPress 6.0+
- PHP 7.4+
- HTTPS enabled on the WordPress site.
- WordPress cron running at least once per minute. For reliable timing, configure a real server cron to call wp-cron.php every minute.

Each bot has its own token, target chat, admin user ID, queue, interval, and webhook secret. The plugin adds no frontend output or scripts. Disable the old GitHub Actions getUpdates workflow before enabling these webhooks.