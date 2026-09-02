import json, os, re, time, urllib.parse, urllib.request
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo

TOKEN=os.environ["TELEGRAM_BOT_TOKEN"]
TARGET=os.environ["TELEGRAM_TARGET_CHAT_ID"]
STATE_FILE="data/state.json"
TZ=ZoneInfo("Asia/Bangkok")
API=f"https://api.telegram.org/bot{TOKEN}/"

def tg(method, params=None):
    data=urllib.parse.urlencode(params or {}).encode()
    req=urllib.request.Request(API+method,data=data)
    with urllib.request.urlopen(req,timeout=30) as r:
        return json.loads(r.read().decode())

def load():
    with open(STATE_FILE,encoding="utf-8") as f:return json.load(f)
def save(s):
    with open(STATE_FILE,"w",encoding="utf-8") as f:json.dump(s,f,ensure_ascii=False,indent=2)

def keyboard():
    return {"inline_keyboard":[
      [{"text":"➕ เพิ่มลิงก์","callback_data":"add"},{"text":"📋 ดูคิว","callback_data":"queue"}],
      [{"text":"▶️ เริ่มโพสต์","callback_data":"start"},{"text":"⏸ หยุด","callback_data":"stop"}],
      [{"text":"⏭ ข้าม","callback_data":"skip"},{"text":"🗑 ล้างคิว","callback_data":"clear"}],
      [{"text":"📊 สถานะ","callback_data":"status"}]
    ]}

def home(s):
    q=s["queue"]; pending=sum(x["status"]=="pending" for x in q); sent=sum(x["status"]=="sent" for x in q)
    nxt=s.get("next_run") or "-"
    return (f"🤖 AUTO POST BOT\n━━━━━━━━━━━━━━━━\n\n"
            f"สถานะ: {'🟢 กำลังทำงาน' if s['running'] else '⏸ หยุด'}\n"
            f"📋 คิวทั้งหมด: {len(q)}\n⏳ รอส่ง: {pending}\n✅ ส่งแล้ว: {sent}\n"
            f"⏱ ช่วงเวลา: {s['interval_minutes']} นาที\n⏰ รอบถัดไป: {nxt}")

def send(chat,text,markup=None):
    p={"chat_id":chat,"text":text}
    if markup:p["reply_markup"]=json.dumps(markup,ensure_ascii=False)
    return tg("sendMessage",p)

def urls(text):
    return list(dict.fromkeys(re.findall(r'https?://[^\s<>]+',text)))

def add_urls(s, arr):
    existing={x["url"] for x in s["queue"]}
    added=0; dup=0
    for u in arr:
        if u in existing: dup+=1; continue
        s["queue"].append({"url":u,"status":"pending","scheduled_at":None,"sent_at":None})
        existing.add(u); added+=1
    return added,dup

def schedule_pending(s):
    now=datetime.now(TZ)
    # Preserve existing scheduled times; append new pending URLs after latest schedule.
    scheduled=[x["scheduled_at"] for x in s["queue"] if x["status"]=="pending" and x["scheduled_at"]]
    if scheduled:
        last=max(datetime.fromisoformat(x) for x in scheduled)
        if last<now:last=now
    else:last=now
    for x in s["queue"]:
        if x["status"]=="pending" and not x["scheduled_at"]:
            x["scheduled_at"]=last.isoformat()
            last=last+timedelta(minutes=s["interval_minutes"])
    if any(x["status"]=="pending" for x in s["queue"]):
        s["next_run"]=min(x["scheduled_at"] for x in s["queue"] if x["status"]=="pending" and x["scheduled_at"])

def process_updates(s):
    offset=s.get("last_update_id",0)+1
    try:r=tg("getUpdates",{"offset":offset,"timeout":1})
    except Exception:return False
    changed=False
    for u in r.get("result",[]):
        s["last_update_id"]=u["update_id"]; changed=True
        m=u.get("message"); c=u.get("callback_query")
        if c:
            uid=c["from"]["id"]; chat=c["message"]["chat"]["id"]; d=c["data"]
            tg("answerCallbackQuery",{"callback_query_id":c["id"]})
            if d=="add": send(chat,"➕ เพิ่มลิงก์\n\nส่ง URL ได้หลายบรรทัดในข้อความเดียว\n/cancel เพื่อยกเลิก")
            elif d=="queue":
                lines=["📋 QUEUE","━━━━━━━━━━━━━━━━"]
                for i,x in enumerate(s["queue"],1):
                    lines.append(f"#{i} {'✅' if x['status']=='sent' else '⏳'} {x['url']}")
                    if x.get("scheduled_at"):lines.append(f"⏰ {x['scheduled_at']}")
                send(chat,"\n".join(lines) if len(lines)>2 else "📋 ยังไม่มีคิว")
            elif d=="start":
                s["running"]=True;schedule_pending(s);send(chat,home(s));changed=True
            elif d=="stop":
                s["running"]=False;send(chat,home(s));changed=True
            elif d=="skip":
                for x in s["queue"]:
                    if x["status"]=="pending":x["status"]="skipped";break
                schedule_pending(s);send(chat,home(s));changed=True
            elif d=="clear":
                s["queue"]=[x for x in s["queue"] if x["status"] not in ("pending","skipped")]
                s["next_run"]=None;send(chat,home(s));changed=True
            elif d=="status":send(chat,home(s))
            continue
        if not m:continue
        chat=m["chat"]["id"]; text=(m.get("text") or "").strip()
        if text=="/start" or text=="/menu":send(chat,home(s),keyboard());continue
        if text=="/cancel":send(chat,"ยกเลิกแล้ว");continue
        if text.startswith("/add "):
            a,b=add_urls(s,urls(text[5:]));send(chat,f"✅ เพิ่ม {a} ลิงก์\n⚠️ ซ้ำ {b}");changed=True;continue
        if text:
            arr=urls(text)
            if arr:
                a,b=add_urls(s,arr);send(chat,f"✅ รับลิงก์แล้ว\nเพิ่มใหม่: {a}\nซ้ำ: {b}\n\nกด ▶️ เริ่มโพสต์",keyboard());changed=True
    return changed

def send_due(s):
    if not s["running"]:return False
    now=datetime.now(TZ)
    due=[x for x in s["queue"] if x["status"]=="pending" and x.get("scheduled_at") and datetime.fromisoformat(x["scheduled_at"])<=now]
    if not due:return False
    x=sorted(due,key=lambda z:z["scheduled_at"])[0]
    r=tg("sendMessage",{"chat_id":TARGET,"text":x["url"],"disable_web_page_preview":"false"})
    if r.get("ok"):
        x["status"]="sent";x["sent_at"]=now.isoformat()
        pending=[z for z in s["queue"] if z["status"]=="pending" and z.get("scheduled_at")]
        if pending:s["next_run"]=min(z["scheduled_at"] for z in pending)
        else:
            s["next_run"]=None
            # If there are newly appended unscheduled links, continue cadence.
            schedule_pending(s)
        return True
    return False

s=load()
changed=process_updates(s)
if s["running"] and any(x["status"]=="pending" and not x.get("scheduled_at") for x in s["queue"]):
    schedule_pending(s);changed=True
if send_due(s):changed=True
if changed:save(s)
