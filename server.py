#!/usr/bin/env python3
"""信用卡管家服务端:静态页面 + 数据同步 API + 每日 Bark 推送。仅依赖 Python 3.8+ 标准库。

环境变量:
  MOMO_TOKEN       (必填) 访问令牌,网页端同步时填写
  BARK_KEY         (推送必填) Bark App 里的设备 key
  BARK_SERVER      Bark 服务器地址,默认 https://api.day.app
  MOMO_PUSH_HOURS  每天推送的整点,逗号分隔,默认 "9,20"
  MOMO_PORT        监听端口,默认 8765
  MOMO_HOST        监听地址,默认 127.0.0.1(配合反向代理);Docker 里设为 0.0.0.0
  MOMO_DATA        数据目录,默认 ./data
  TZ               时区,例如 Asia/Shanghai
"""
import hmac, json, os, sys, threading, time, urllib.request
from datetime import date, datetime, timedelta
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

ROOT = Path(__file__).resolve().parent
DATA = Path(os.environ.get("MOMO_DATA", ROOT / "data"))
TOKEN = os.environ.get("MOMO_TOKEN", "")
BARK_KEY = os.environ.get("BARK_KEY", "")
BARK_SERVER = os.environ.get("BARK_SERVER", "https://api.day.app").rstrip("/")
HOURS = sorted({int(h) for h in os.environ.get("MOMO_PUSH_HOURS", "9,20").split(",") if h.strip()})
STATIC = {"/": "index.html", "/index.html": "index.html", "/manifest.json": "manifest.json",
          "/sw.js": "sw.js", "/icon.svg": "icon.svg"}
TYPES = {".html": "text/html; charset=utf-8", ".json": "application/json", ".js": "text/javascript", ".svg": "image/svg+xml"}
MAX_BODY = 2 * 1024 * 1024
lock = threading.Lock()


def load(name, default):
    try:
        return json.loads((DATA / name).read_text("utf-8"))
    except Exception:
        return default


def store(name, obj):
    DATA.mkdir(parents=True, exist_ok=True)
    tmp = DATA / (name + ".tmp")
    tmp.write_text(json.dumps(obj, ensure_ascii=False), "utf-8")
    os.replace(tmp, DATA / name)


# ---------- 提醒逻辑(与网页端 offerInfo / alertTexts 保持一致) ----------
def money(n):
    return "¥{:,.2f}".format(float(n or 0))


def next_day(day, today):
    def clamp(y, m):
        nxt = date(y + (m == 12), m % 12 + 1, 1)
        return date(y, m, min(day, (nxt - timedelta(days=1)).day))
    t = clamp(today.year, today.month)
    if t < today:
        y, m = (today.year + 1, 1) if today.month == 12 else (today.year, today.month + 1)
        t = clamp(y, m)
    return (t - today).days


def build_alerts(db, today):
    """返回 [(urgent, text)]"""
    out = []
    for o in db.get("offers", []):
        try:
            if o.get("movedDate"):
                continue
            end = date.fromisoformat(o["start"]) + timedelta(days=int(o["days"]))
            head = "{}({})".format(o["name"], money(o["amount"]))
            if o.get("rewardedDate"):
                out.append((True, "💸 {}:收益已到账,请把这笔钱转走".format(head)))
                continue
            d = (end - today).days
            if d <= 0:
                msg = "今天满期,等待收益到账" if d == 0 else "已满期{}天,请确认收益是否到账".format(-d)
                out.append((True, "{}:{}".format(head, msg)))
            elif d <= 7:
                out.append((False, "{}:持有中,还需 {} 天".format(head, d)))
        except (KeyError, ValueError, TypeError):
            continue
    for c in db.get("cards", []):
        for label, key in (("账单日", "billDay"), ("还款日", "dueDay")):
            try:
                day = int(c.get(key) or 0)
                if day:
                    n = next_day(day, today)
                    if n <= 3:
                        out.append((key == "dueDay", "{} {}{}".format(c.get("name", ""), label, "就是今天" if n == 0 else "还有{}天".format(n))))
            except (ValueError, TypeError):
                continue
    return out


def bark(title, body, urgent):
    payload = {"device_key": BARK_KEY, "title": title, "body": body, "group": "信用卡管家",
               "level": "timeSensitive" if urgent else "active"}
    req = urllib.request.Request(BARK_SERVER + "/push", json.dumps(payload).encode(),
                                 {"Content-Type": "application/json; charset=utf-8"})
    with urllib.request.urlopen(req, timeout=15) as r:
        return r.status == 200


def push_alerts(db, today, force=False):
    alerts = build_alerts(db, today)
    if not alerts:
        return 0
    title = "信用卡管家:{} 条待办".format(len(alerts))
    bark(title, "\n".join(t for _, t in alerts), any(u for u, _ in alerts))
    return len(alerts)


def scheduler():
    while True:
        try:
            now = datetime.now()
            slot = "{}-{:02d}".format(now.date().isoformat(), now.hour)
            state = load("state.json", {})
            if BARK_KEY and now.hour in HOURS and state.get("last") != slot:
                with lock:
                    db = load("db.json", {})
                push_alerts(db, now.date())
                store("state.json", {"last": slot})   # 无待办或推送成功都记为已处理
                print(time.strftime("%F %T"), "checked", slot, flush=True)
        except Exception as e:  # 推送失败 60 秒后重试
            print(time.strftime("%F %T"), "push failed:", e, file=sys.stderr, flush=True)
        time.sleep(60)


# ---------- HTTP ----------
class H(BaseHTTPRequestHandler):
    server_version = "momo"

    def log_message(self, fmt, *a):
        pass

    def send(self, code, body=b"", ctype="application/json"):
        if isinstance(body, (dict, list)):
            body = json.dumps(body, ensure_ascii=False).encode()
        self.send_response(code)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.end_headers()
        self.wfile.write(body)

    def authed(self):
        return bool(TOKEN) and hmac.compare_digest(self.headers.get("X-Token", ""), TOKEN)

    def do_GET(self):
        path = self.path.split("?")[0]
        if path in STATIC:
            f = ROOT / STATIC[path]
            return self.send(200, f.read_bytes(), TYPES.get(f.suffix, "application/octet-stream"))
        if path == "/api/data":
            if not self.authed():
                return self.send(401, {"error": "unauthorized"})
            with lock:
                return self.send(200, load("db.json", {}))
        self.send(404, {"error": "not found"})

    def do_PUT(self):
        if self.path.split("?")[0] != "/api/data":
            return self.send(404, {"error": "not found"})
        if not self.authed():
            return self.send(401, {"error": "unauthorized"})
        n = int(self.headers.get("Content-Length") or 0)
        if n <= 0 or n > MAX_BODY:
            return self.send(413, {"error": "bad size"})
        try:
            d = json.loads(self.rfile.read(n))
            assert isinstance(d.get("cards"), list) and isinstance(d.get("txs"), list)
        except Exception:
            return self.send(400, {"error": "bad data"})
        with lock:
            cur = load("db.json", {})
            if (cur.get("updatedAt") or 0) > (d.get("updatedAt") or 0):   # 不让旧数据覆盖新数据
                return self.send(409, cur)
            store("db.json", d)
        self.send(200, {"ok": True})

    def do_POST(self):
        if self.path.split("?")[0] != "/api/test-push":
            return self.send(404, {"error": "not found"})
        if not self.authed():
            return self.send(401, {"error": "unauthorized"})
        if not BARK_KEY:
            return self.send(400, {"error": "BARK_KEY not set"})
        try:
            with lock:
                db = load("db.json", {})
            n = push_alerts(db, date.today())
            if not n:
                bark("信用卡管家", "推送测试成功,目前没有待办 ✅", False)
            self.send(200, {"ok": True, "alerts": n})
        except Exception as e:
            self.send(502, {"error": str(e)})


if __name__ == "__main__":
    if not TOKEN or len(TOKEN) < 8:
        sys.exit("请先设置 MOMO_TOKEN(至少 8 位),例如:export MOMO_TOKEN=$(openssl rand -hex 16)")
    if not BARK_KEY:
        print("提示:未设置 BARK_KEY,将只提供同步,不推送", file=sys.stderr)
    host, port = os.environ.get("MOMO_HOST", "127.0.0.1"), int(os.environ.get("MOMO_PORT", "8765"))
    threading.Thread(target=scheduler, daemon=True).start()
    print("listening on {}:{}, push hours {}".format(host, port, HOURS), flush=True)
    ThreadingHTTPServer((host, port), H).serve_forever()
