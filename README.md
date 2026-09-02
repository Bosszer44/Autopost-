# Telegram Auto Post Bot

> 🤖 Serverless Telegram Bot that automatically posts URLs to a target chat/group on a schedule — powered by GitHub Actions. No VPS or server required!

[![GitHub Actions](https://img.shields.io/badge/GitHub_Actions-2088FF?style=for-the-badge&logo=github-actions&logoColor=white)](https://github.com/features/actions)
[![Python](https://img.shields.io/badge/Python-3.9+-3776AB?style=for-the-badge&logo=python&logoColor=white)](https://www.python.org/)
[![Telegram](https://img.shields.io/badge/Telegram-26A5E4?style=for-the-badge&logo=telegram&logoColor=white)](https://telegram.org/)
[![License](https://img.shields.io/badge/License-MIT-yellow.svg?style=for-the-badge)](./LICENSE)

---

## ✨ Features

| Feature | Description |
|---------|-------------|
| 🆓 **Serverless** | Runs entirely on GitHub Actions — no VPS, no hosting costs |
| ⏰ **Scheduled Posting** | Automatically posts URLs at configurable intervals (default: 60 minutes) |
| 📋 **Queue Management** | Add multiple URLs at once, view queue status, skip items |
| 🎛️ **Bot Control Panel** | Inline keyboard for easy control: Start / Stop / Queue / Skip / Clear / Status |
| 🔗 **Bulk URL Import** | Paste multiple URLs in one message, supports up to dozens of links |
| 🛡️ **Duplicate Protection** | Automatically detects and skips duplicate URLs |
| 💾 **Persistent State** | Queue state saved in `data/state.json` and committed back to GitHub |
| 🔐 **Secure Secrets** | Bot token and chat ID stored in GitHub Secrets — never exposed in code |
| 🌏 **Thai Timezone** | Configured for `Asia/Bangkok` timezone |

---

## 🚀 Quick Start

### Prerequisites

1. A **GitHub account**
2. A **Telegram Bot Token** — create one via [@BotFather](https://t.me/BotFather)
3. A **Target Chat ID** — the group/channel where URLs will be posted

### Step 1: Create Repository

1. Click **"Use this template"** or create a new repository
2. Upload all files from this project to your repository

### Step 2: Configure Secrets

Go to your repository → **Settings** → **Secrets and variables** → **Actions** → **New repository secret**

Create these two secrets:

| Secret Name | Value |
|-------------|-------|
| `TELEGRAM_BOT_TOKEN` | Your Telegram Bot Token from @BotFather |
| `TELEGRAM_TARGET_CHAT_ID` | Target chat/group ID (e.g., `-1001234567890`) |

> 💡 **How to get Chat ID:** Add your bot to the group, send a message, then visit:
> `https://api.telegram.org/bot<YOUR_TOKEN>/getUpdates`

### Step 3: Enable Workflow

1. Go to the **Actions** tab in your repository
2. Select **"Telegram Auto Post"** from the left sidebar
3. Click **"Enable workflow"**

The workflow will run automatically every **5 minutes** to:
- Process new commands/messages sent to the bot
- Add URLs to the queue
- Check for scheduled items that are due
- Post one due URL to the target chat
- Save the updated state back to GitHub

### Step 4: Start Using the Bot

1. Open a chat with your Telegram bot
2. Send `/start` to open the control panel
3. Click **➕ เพิ่มลิงก์** (Add Link) and paste your URLs (one per line)
4. Click **▶️ เริ่มโพสต์** (Start Posting) to begin the schedule

---

## 📖 How It Works

### Architecture

```
┌─────────────────┐     Every 5 minutes      ┌──────────────────┐
│  GitHub Actions │ ───────────────────────▶ │  bot.py runs     │
└─────────────────┘                          └────────┬─────────┘
                                                      │
                        ┌─────────────────────────────┼─────────────────────────────┐
                        ▼                             ▼                             ▼
              ┌──────────────────┐        ┌──────────────────────┐      ┌────────────────────┐
              │  Read state.json │        │  Check Telegram API  │      │  Post due URL to   │
              │  (load queue)    │        │  for new messages    │      │  target chat       │
              └────────┬─────────┘        └──────────┬───────────┘      └─────────┬──────────┘
                       │                             │                            │
                       └─────────────────────────────┼────────────────────────────┘
                                                     ▼
                                          ┌──────────────────────┐
                                          │  Commit updated      │
                                          │  state.json to repo  │
                                          └──────────────────────┘
```

### Queue Logic

- URLs are scheduled at intervals defined by `interval_minutes` (default: **60 minutes**)
- Each workflow run posts **at most 1 URL** that is due
- The workflow checks every 5 minutes, so actual posting time may vary slightly
- If GitHub Actions is paused/suspended, posting resumes automatically when re-enabled

---

## 🎮 Bot Commands

| Command | Description |
|---------|-------------|
| `/start` or `/menu` | Open the main control panel |
| `/cancel` | Cancel current operation |
| `/add <url1> <url2> ...` | Add URLs directly via command |

### Control Panel Buttons

| Button | Action |
|--------|--------|
| ➕ เพิ่มลิงก์ | Add URLs mode — paste URLs in next message |
| 📋 ดูคิว | View current queue with statuses |
| ▶️ เริ่มโพสต์ | Start the auto-posting schedule |
| ⏸ หยุด | Pause auto-posting |
| ⏭ ข้าม | Skip the next pending URL |
| 🗑 ล้างคิว | Clear all pending/skipped items from queue |
| 📊 สถานะ | Show current bot status |

---

## ⚙️ Configuration

### Adjust Posting Interval

Edit `data/state.json` and change the `interval_minutes` value:

```json
{
  "interval_minutes": 60,
  ...
}
```

### State File Structure (`data/state.json`)

```json
{
  "queue": [
    {
      "url": "https://example.com/article",
      "status": "pending",
      "scheduled_at": "2026-09-02T10:00:00+07:00",
      "sent_at": null
    }
  ],
  "running": true,
  "next_run": "2026-09-02T10:00:00+07:00",
  "last_update_id": 12345,
  "interval_minutes": 60,
  "admin_user_ids": []
}
```

**Status values:**
- `pending` — Waiting to be posted
- `sent` — Successfully posted
- `skipped` — Manually skipped

---

## 📁 Project Structure

```
telegram-auto-post/
├── .github/
│   └── workflows/
│       └── auto-post.yml      # GitHub Actions workflow
├── data/
│   └── state.json             # Bot state & queue storage
├── bot.py                     # Main bot logic
├── README.md                  # English documentation
├── README_TH.md               # Thai documentation
├── .gitignore                 # Git ignore rules
├── requirements.txt           # Python dependencies
├── .env.example               # Environment variables example
└── LICENSE                    # MIT License
```

---

## ⚠️ Important Notes

- **GitHub Actions Schedule** is approximate — it may be delayed by a few minutes and is not guaranteed to the second
- **One URL per run** — At most one URL is posted per 5-minute workflow run
- **Free tier limits** — GitHub Actions provides 2,000 minutes/month for free accounts (this bot uses ~90 minutes/month)
- **Never commit secrets** — Always use GitHub Secrets, never hardcode tokens in files
- **Bot privacy** — For group chats, ensure the bot has permission to read messages

---

## 🛠️ Manual Run

You can manually trigger the workflow:

1. Go to **Actions** → **Telegram Auto Post**
2. Click **"Run workflow"** → **"Run workflow"** (green button)

---

## 📄 License

This project is licensed under the **MIT License** — see the [LICENSE](./LICENSE) file for details.

---

## 🤝 Contributing

Contributions, issues, and feature requests are welcome! Feel free to check the [issues page](../../issues).

---

## 💡 Tips

- **Batch import:** You can paste 20+ URLs at once in a single message
- **Web preview:** URLs are posted with web page preview enabled
- **Timezone:** All times use `Asia/Bangkok` (UTC+7)
- **Idempotent:** Safe to run multiple times — duplicate URLs are automatically rejected

---

> 📚 **Thai Documentation:** For full Thai documentation, see [README_TH.md](./README_TH.md)
