"""Serverless, admin-only Telegram URL auto-posting bot."""
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timedelta
from pathlib import Path
from zoneinfo import ZoneInfo

STATE_FILE = Path("data/state.json")
TZ = ZoneInfo("Asia/Bangkok")
URL_PATTERN = re.compile(r"https?://[^\s<>]+")

class TelegramError(RuntimeError):
    pass

def required(name):
    value = os.environ.get(name, "").strip()
    if not value or value.startswith("your_"):
        raise RuntimeError(f"Missing required environment variable: {name}")
    return value

def now(): return datetime.now(TZ)
def parse_time(value): return datetime.fromisoformat(value) if value else None
def duration(state): return timedelta(minutes=int(state["interval_minutes"]))

def api(state, method, parameters=None):
    data = urllib.parse.urlencode(parameters or {}).encode("utf-8")
    request = urllib.request.Request(f"https://api.telegram.org/bot{state['_token']}/{method}", data=data)
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            payload = json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as error:
        if error.code == 409: raise TelegramError("WEBHOOK_CONFLICT") from error
        raise TelegramError(f"Telegram API returned HTTP {error.code}") from error
    except (urllib.error.URLError, TimeoutError) as error:
        raise TelegramError("Telegram API request failed") from error
    if not payload.get("ok"): raise TelegramError("Telegram API returned an unsuccessful response")
    return payload["result"]

def load_state():
    with STATE_FILE.open(encoding="utf-8") as file: state = json.load(file)
    state.setdefault("queue", [])
    state.setdefault("running", False)
    state.setdefault("next_run", None)
    state.setdefault("last_update_id", 0)
    state.setdefault("last_posted_time", None)
    state.setdefault("interval_minutes", 60)
    state.setdefault("waiting_for_urls", False)
    state["_token"] = required("TELEGRAM_BOT_TOKEN")
    state["_target"] = required("TELEGRAM_TARGET_CHAT_ID")
    state["_admin"] = required("TELEGRAM_ADMIN_USER_ID")
    return state

def save_state(state):
    stored = {key: value for key, value in state.items() if not key.startswith("_")}
    STATE_FILE.parent.mkdir(parents=True, exist_ok=True)
    with STATE_FILE.open("w", encoding="utf-8") as file:
        json.dump(stored, file, ensure_ascii=False, indent=2)
        file.write("\n")

def private_admin(state, event):
    sender = event.get("from", {})
    chat = event.get("chat") or event.get("message", {}).get("chat", {})
    return str(sender.get("id")) == state["_admin"] and chat.get("type") == "private"

def pending(state): return [item for item in state["queue"] if item["status"] == "pending"]
def update_next(state):
    values = [item["scheduled_at"] for item in pending(state) if item.get("scheduled_at")]
    state["next_run"] = min(values) if values else None

def rebuild(state, first):
    for item in pending(state):
        item["scheduled_at"] = first.isoformat()
        first += duration(state)
    update_next(state)

def schedule_missing(state):
    valid = [parse_time(item.get("scheduled_at")) for item in pending(state)]
    valid = [value for value in valid if value]
    cursor = max(valid) + duration(state) if valid else now()
    for item in pending(state):
        if not item.get("scheduled_at"):
            item["scheduled_at"] = cursor.isoformat()
            cursor += duration(state)
    update_next(state)

def panel(state):
    count = len(pending(state)); sent = sum(item["status"] == "sent" for item in state["queue"])
    status = "🟢 กำลังทำงาน" if state["running"] else "⏸ หยุด"
    return f"🤖 AUTO POST BOT\n\nสถานะ: {status}\n📋 คิวทั้งหมด: {len(state['queue'])}\n⏳ รอส่ง: {count}\n✅ ส่งแล้ว: {sent}\n⏰ โพสต์ถัดไป: {state.get('next_run') or '-'}\n⏱ ช่วงเวลา: {state['interval_minutes']} นาที"

def keyboard():
    return {"inline_keyboard": [
        [{"text":"➕ เพิ่มลิงก์","callback_data":"add_url"},{"text":"📋 ดูคิว","callback_data":"view_queue"}],
        [{"text":"▶️ เริ่มโพสต์","callback_data":"start_posting"},{"text":"⏸ หยุด","callback_data":"stop_posting"}],
        [{"text":"⏭ ข้าม","callback_data":"skip_next"},{"text":"🗑 ล้างคิว","callback_data":"clear_queue"}],
        [{"text":"📊 สถานะ","callback_data":"show_status"}]
    ]}

def control(state, chat_id, text, markup=None):
    params = {"chat_id": chat_id, "text": text}
    if markup: params["reply_markup"] = json.dumps(markup, ensure_ascii=False)
    api(state, "sendMessage", params)

def queue_text(state):
    rows = ["📋 คิวโพสต์"]
    for i, item in enumerate(state["queue"], 1):
        icon = {"pending":"⏳", "sent":"✅", "skipped":"⏭"}.get(item["status"], "•")
        rows.append(f"{i}. {icon} {item['url']}")
        if item.get("scheduled_at"): rows.append(f"   ⏰ {item['scheduled_at']}")
    return "\n".join(rows) if len(rows) > 1 else "📋 ยังไม่มีคิว"

def add_urls(state, text):
    existing = {item["url"] for item in state["queue"]}; added = 0; duplicates = 0
    for url in dict.fromkeys(URL_PATTERN.findall(text)):
        if url in existing: duplicates += 1; continue
        state["queue"].append({"url":url,"status":"pending","scheduled_at":None,"sent_at":None})
        existing.add(url); added += 1
    if state["running"] and added: schedule_missing(state)
    return added, duplicates

def start(state):
    state["running"] = True
    scheduled = [parse_time(item.get("scheduled_at")) for item in pending(state)]
    valid = [value for value in scheduled if value]
    if valid and min(valid) >= now():
        schedule_missing(state)
        return
    last = parse_time(state.get("last_posted_time"))
    rebuild(state, max(now(), last + duration(state)) if last else now())

def skip(state):
    items = sorted(pending(state), key=lambda item: item.get("scheduled_at") or "")
    if not items: return False
    items[0]["status"] = "skipped"
    last = parse_time(state.get("last_posted_time"))
    rebuild(state, max(now(), last + duration(state)) if last else now())
    return True

def handle_message(state, message):
    if not private_admin(state, message): return False
    text = (message.get("text") or "").strip(); chat = message["chat"]["id"]
    if text in ("/start", "/menu"):
        state["waiting_for_urls"] = False; control(state, chat, panel(state), keyboard()); return True
    if text == "/cancel": state["waiting_for_urls"] = False; control(state, chat, "ยกเลิกการเพิ่มลิงก์แล้ว"); return True
    if text == "/queue": control(state, chat, queue_text(state)); return True
    if text == "/status": control(state, chat, panel(state), keyboard()); return True
    if text == "/stop": state["running"] = False; control(state, chat, panel(state), keyboard()); return True
    if text == "/skip": control(state, chat, "ข้ามลิงก์ถัดไปแล้ว" if skip(state) else "ไม่มีลิงก์รอส่ง", keyboard()); return True
    if text.startswith("/add") or state.get("waiting_for_urls"):
        added, duplicates = add_urls(state, text[4:] if text.startswith("/add") else text)
        state["waiting_for_urls"] = False
        control(state, chat, f"เพิ่ม {added} ลิงก์ | ซ้ำ {duplicates} ลิงก์", keyboard()); return True
    return False

def handle_callback(state, callback):
    if not private_admin(state, callback): return False
    api(state, "answerCallbackQuery", {"callback_query_id": callback["id"]})
    action = callback.get("data"); chat = callback["message"]["chat"]["id"]
    if action == "add_url": state["waiting_for_urls"] = True; control(state, chat, "➕ ส่ง URL ได้หลายบรรทัดในข้อความถัดไป\n/cancel เพื่อยกเลิก")
    elif action == "view_queue": control(state, chat, queue_text(state))
    elif action == "start_posting": start(state); control(state, chat, panel(state), keyboard())
    elif action == "stop_posting": state["running"] = False; control(state, chat, panel(state), keyboard())
    elif action == "skip_next": control(state, chat, "ข้ามลิงก์ถัดไปแล้ว" if skip(state) else "ไม่มีลิงก์รอส่ง", keyboard())
    elif action == "clear_queue": state["queue"] = [item for item in state["queue"] if item["status"] == "sent"]; update_next(state); control(state, chat, "ล้างคิวที่ยังไม่ส่งแล้ว (เก็บประวัติส่งแล้ว)", keyboard())
    elif action == "show_status": control(state, chat, panel(state), keyboard())
    else: return False
    return True

def updates(state):
    try: results = api(state, "getUpdates", {"offset": state["last_update_id"] + 1, "timeout": 1})
    except TelegramError as error:
        if str(error) == "WEBHOOK_CONFLICT": print("ERROR: A Telegram webhook is configured. Please remove it using Telegram Bot API.", file=sys.stderr); return None
        print(f"WARNING: {error}", file=sys.stderr); return False
    changed = False
    for update in results:
        state["last_update_id"] = update["update_id"]; changed = True
        if update.get("callback_query"): changed = handle_callback(state, update["callback_query"]) or changed
        elif update.get("message"): changed = handle_message(state, update["message"]) or changed
    return changed

def post_due(state):
    if not state["running"]: return False
    last = parse_time(state.get("last_posted_time"))
    if last and now() < last + duration(state): return False
    due = [item for item in pending(state) if item.get("scheduled_at") and parse_time(item["scheduled_at"]) <= now()]
    if not due: return False
    item = min(due, key=lambda candidate: candidate["scheduled_at"])
    try: api(state, "sendMessage", {"chat_id":state["_target"],"text":item["url"],"disable_web_page_preview":"false"})
    except TelegramError as error: print(f"WARNING: URL was not sent: {error}", file=sys.stderr); return False
    sent = now(); item["status"] = "sent"; item["sent_at"] = sent.isoformat(); state["last_posted_time"] = sent.isoformat()
    if pending(state): rebuild(state, sent + duration(state))
    else: update_next(state)
    return True

def main():
    state = load_state(); changed = updates(state)
    if changed is None: return 1
    if changed or post_due(state): save_state(state)
    return 0

if __name__ == "__main__":
    try: raise SystemExit(main())
    except RuntimeError as error: print(f"ERROR: {error}", file=sys.stderr); raise SystemExit(1)
