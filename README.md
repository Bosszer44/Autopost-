# Telegram Auto Post Bot

A serverless Telegram URL queue that runs on GitHub Actions every five minutes.

## Required GitHub Actions secrets

- `TELEGRAM_BOT_TOKEN`: token created with BotFather
- `TELEGRAM_TARGET_CHAT_ID`: target group or channel ID; scheduled posts go only here
- `TELEGRAM_ADMIN_USER_ID`: numeric Telegram user ID allowed to operate the bot

The admin must use the bot in a private 1-to-1 chat. Commands and buttons in groups, channels, and other chats are ignored.

## Use

Send `/start` privately to open the Thai control panel. Select **เพิ่มลิงก์** and send URLs (one per line), or use `/add` followed by URLs. Use `/queue`, `/status`, `/stop`, `/skip`, and `/cancel` as needed.

Each successful post contains only the raw URL. The bot sends at most one item per workflow run and enforces at least 60 minutes between successful Telegram posts. Queue state is saved in `data/state.json`.

## Webhook note

This project uses Telegram `getUpdates` polling. Remove any active Telegram webhook before using it. The bot will stop safely if Telegram reports a webhook conflict.
