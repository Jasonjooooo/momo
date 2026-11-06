#!/usr/bin/env python3
"""审核发布机器人:自动收集 RSS 新闻和网友投稿,发到审核群,管理员点「发布」后才发到公开频道。

只依赖 Python 3.9+ 标准库。可选:Pillow(去除投稿图片里的 EXIF/GPS)、ffmpeg(去除视频元数据)。

环境变量:
  BOT_TOKEN        (必填) BotFather 给的机器人 Token
  NEWSBOT_CONFIG   配置文件路径,默认与本文件同目录的 config.json
  NEWSBOT_DATA     数据库目录,默认 ./data
  TG_API_BASE      Telegram API 地址,默认 https://api.telegram.org(测试时替换)

发到 X(可选,config.json 里 "x": {"enabled": true} 时需要,来自 X 开发者后台):
  X_API_KEY / X_API_SECRET          应用的 API Key 和 Secret(Consumer Keys)
  X_ACCESS_TOKEN / X_ACCESS_SECRET  你账号的 Access Token 和 Secret(需要 Read and Write 权限)
"""
import base64
import email.utils
import hashlib
import hmac
import html
import json
import os
import re
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import threading
import time
import urllib.parse
import urllib.request
import uuid
import xml.etree.ElementTree as ET
from datetime import datetime, timedelta, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent
API = os.environ.get("TG_API_BASE", "https://api.telegram.org").rstrip("/")
TOKEN = os.environ.get("BOT_TOKEN", "")
UA = "Mozilla/5.0 (compatible; newsbot/1.0)"
MAX_DOWNLOAD = 20 * 1024 * 1024          # Bot API 只能下载 20MB 以内的文件
X_API = os.environ.get("X_API_BASE", "https://api.x.com").rstrip("/")


def log(*a):
    print(time.strftime("%F %T"), *a, flush=True)


# ---------------------------------------------------------------- 配置 ----
def load_config(path=None):
    p = Path(path or os.environ.get("NEWSBOT_CONFIG", ROOT / "config.json"))
    cfg = json.loads(p.read_text("utf-8"))
    cfg.setdefault("feeds", [])
    cfg.setdefault("include", [])
    cfg.setdefault("exclude", [])
    cfg.setdefault("interval_minutes", 30)
    cfg.setdefault("max_age_hours", 48)
    cfg.setdefault("max_per_cycle", 15)
    cfg.setdefault("first_run_keep", 3)
    cfg.setdefault("accept_submissions", True)
    cfg.setdefault("submission_reply", "已收到,感谢投稿。内容会经人工审核后再决定是否发布,我们不会公开你的身份。")
    cfg["admin_ids"] = [int(x) for x in cfg.get("admin_ids", [])]
    cfg.setdefault("x", {"enabled": False})
    for k in ("review_chat_id", "channel_id"):
        if not cfg.get(k):
            raise SystemExit(f"配置缺少 {k}")
    if not cfg["admin_ids"]:
        raise SystemExit("配置缺少 admin_ids(可以点审核按钮的人的 Telegram 用户 ID)")
    return cfg


def compile_terms(terms):
    out = []
    for t in terms:
        t = str(t).strip()
        if not t:
            continue
        if len(t) > 2 and t.startswith("/") and t.endswith("/"):
            out.append(re.compile(t[1:-1], re.I))
        else:
            out.append(re.compile(re.escape(t), re.I))
    return out


def wanted(title, include, exclude):
    """include 为空表示全部要;任一 exclude 命中就不要。"""
    if any(r.search(title) for r in exclude):
        return False
    return not include or any(r.search(title) for r in include)


# ------------------------------------------------------------- RSS 解析 ----
def _text(el, *names):
    for n in names:
        x = el.find(n)
        if x is not None and (x.text or "").strip():
            return x.text.strip()
    return ""


def _date(s):
    if not s:
        return None
    try:
        d = email.utils.parsedate_to_datetime(s)
    except (TypeError, ValueError):
        try:
            d = datetime.fromisoformat(s.replace("Z", "+00:00"))
        except ValueError:
            return None
    return d if d.tzinfo else d.replace(tzinfo=timezone.utc)


def parse_feed(xml_bytes):
    """返回 [{title, link, date}],同时支持 RSS 2.0 和 Atom。"""
    root = ET.fromstring(xml_bytes)
    items = []
    atom = "{http://www.w3.org/2005/Atom}"
    for it in root.iter("item"):
        items.append({"title": _text(it, "title"), "link": _text(it, "link", "guid"),
                      "date": _date(_text(it, "pubDate", "{http://purl.org/dc/elements/1.1/}date"))})
    for en in root.iter(atom + "entry"):
        link = ""
        for l in en.findall(atom + "link"):
            if l.get("rel", "alternate") == "alternate" and l.get("href"):
                link = l.get("href"); break
        items.append({"title": _text(en, atom + "title"), "link": link,
                      "date": _date(_text(en, atom + "updated", atom + "published"))})
    return [i for i in items if i["title"] or i["link"]]


def fetch(url, timeout=30):
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return r.read(5 * 1024 * 1024)


# ------------------------------------------------------------- 数据库 ----
class Store:
    def __init__(self, path):
        Path(path).parent.mkdir(parents=True, exist_ok=True)
        self.db = sqlite3.connect(path, check_same_thread=False)
        self.lock = threading.Lock()
        self.db.executescript("""
        CREATE TABLE IF NOT EXISTS items(
          id TEXT PRIMARY KEY, kind TEXT, source TEXT, title TEXT, link TEXT, published TEXT,
          status TEXT, review_msg INTEGER, content_msg INTEGER, custom_text TEXT, created REAL);
        CREATE TABLE IF NOT EXISTS feeds_seen(feed TEXT PRIMARY KEY);
        CREATE TABLE IF NOT EXISTS submitters(h TEXT PRIMARY KEY, count INTEGER, last REAL, banned INTEGER DEFAULT 0);
        CREATE TABLE IF NOT EXISTS kv(k TEXT PRIMARY KEY, v TEXT);
        """)
        cols = {r[1] for r in self.db.execute("PRAGMA table_info(items)")}
        for c, t in (("body", "TEXT"), ("media_kind", "TEXT"), ("media_file", "TEXT"),
                     ("tg_done", "INTEGER DEFAULT 0"), ("x_done", "INTEGER DEFAULT 0"), ("x_id", "TEXT")):
            if c not in cols:
                self.db.execute(f"ALTER TABLE items ADD COLUMN {c} {t}")
        self.db.commit()

    def q(self, sql, args=(), one=False):
        with self.lock:
            cur = self.db.execute(sql, args)
            self.db.commit()
            rows = cur.fetchall()
        return (rows[0] if rows else None) if one else rows

    def get(self, k, default=None):
        r = self.q("SELECT v FROM kv WHERE k=?", (k,), one=True)
        return r[0] if r else default

    def set(self, k, v):
        self.q("INSERT OR REPLACE INTO kv VALUES(?,?)", (k, str(v)))


def item_id(feed_id, link, title):
    return hashlib.sha1(f"{feed_id}|{link or title}".encode()).hexdigest()[:16]


# --------------------------------------------------------- Telegram API ----
class TG:
    def __init__(self, token, base=API):
        self.base = f"{base}/bot{token}"
        self.file_base = f"{base}/file/bot{token}"

    def call(self, method, timeout=70, **params):
        data = json.dumps({k: v for k, v in params.items() if v is not None}).encode()
        req = urllib.request.Request(f"{self.base}/{method}", data, {"Content-Type": "application/json"})
        for attempt in range(4):
            try:
                with urllib.request.urlopen(req, timeout=timeout) as r:
                    res = json.loads(r.read())
            except urllib.error.HTTPError as e:
                with e:
                    res = json.loads(e.read() or b"{}")
            if res.get("ok"):
                return res["result"]
            retry = (res.get("parameters") or {}).get("retry_after")
            if retry and attempt < 3:           # 触发频率限制时按 Telegram 要求等待
                time.sleep(int(retry) + 1); continue
            raise RuntimeError(f"{method}: {res.get('description', res)}")

    def upload(self, method, fields, file_field, file_path, filename):
        boundary = uuid.uuid4().hex
        body = bytearray()
        for k, v in fields.items():
            if v is None:
                continue
            body += f"--{boundary}\r\nContent-Disposition: form-data; name=\"{k}\"\r\n\r\n{v}\r\n".encode()
        body += (f"--{boundary}\r\nContent-Disposition: form-data; name=\"{file_field}\"; filename=\"{filename}\"\r\n"
                 f"Content-Type: application/octet-stream\r\n\r\n").encode()
        body += Path(file_path).read_bytes() + f"\r\n--{boundary}--\r\n".encode()
        req = urllib.request.Request(f"{self.base}/{method}", bytes(body),
                                     {"Content-Type": f"multipart/form-data; boundary={boundary}"})
        try:
            with urllib.request.urlopen(req, timeout=300) as r:
                res = json.loads(r.read())
        except urllib.error.HTTPError as e:
            with e:
                res = json.loads(e.read() or b"{}")
        if not res.get("ok"):
            raise RuntimeError(f"{method}: {res.get('description', res)}")
        return res["result"]

    def download(self, file_id, dest):
        f = self.call("getFile", file_id=file_id)
        if f.get("file_size", 0) > MAX_DOWNLOAD:
            raise RuntimeError("文件超过 20MB,机器人无法下载")
        with urllib.request.urlopen(f"{self.file_base}/{f['file_path']}", timeout=120) as r, open(dest, "wb") as out:
            shutil.copyfileobj(r, out)
        return dest


# ------------------------------------------------------- 去除媒体元数据 ----
def strip_image(src, dst):
    """重新编码图片,丢弃 EXIF(含 GPS、设备型号、拍摄时间)。需要 Pillow。"""
    from PIL import Image, ImageOps
    with Image.open(src) as im:
        im = ImageOps.exif_transpose(im)      # 先按 EXIF 方向转正,再丢弃 EXIF
        clean = Image.frombytes(im.mode, im.size, im.tobytes())   # 只复制像素,不带任何元数据
        if clean.mode not in ("RGB", "L"):
            clean = clean.convert("RGB")
        clean.save(dst, "JPEG", quality=92)
    return dst


def strip_video(src, dst):
    """不重新编码,只去掉容器元数据(位置、设备、时间)。需要 ffmpeg。"""
    subprocess.run(["ffmpeg", "-v", "error", "-y", "-i", src, "-map", "0", "-map_metadata", "-1",
                    "-map_chapters", "-1", "-c", "copy", "-movflags", "+faststart", dst],
                   check=True, timeout=600)
    return dst


# ------------------------------------------------------------------ X ----
def _pct(v):
    return urllib.parse.quote(str(v), safe="~-._")


def oauth1_header(method, url, ck, cs, tk, ts, params=None, nonce=None, timestamp=None):
    """OAuth 1.0a 签名(HMAC-SHA1)。params 只放查询参数或表单参数,JSON 和 multipart 正文不参与签名。"""
    oauth = {"oauth_consumer_key": ck, "oauth_nonce": nonce or uuid.uuid4().hex,
             "oauth_signature_method": "HMAC-SHA1", "oauth_timestamp": str(timestamp or int(time.time())),
             "oauth_token": tk, "oauth_version": "1.0"}
    pairs = sorted((_pct(k), _pct(v)) for k, v in {**(params or {}), **oauth}.items())
    base = "&".join([method.upper(), _pct(url), _pct("&".join(f"{k}={v}" for k, v in pairs))])
    sig = base64.b64encode(hmac.new(f"{_pct(cs)}&{_pct(ts)}".encode(), base.encode(), hashlib.sha1).digest()).decode()
    oauth["oauth_signature"] = sig
    return "OAuth " + ", ".join(f'{_pct(k)}="{_pct(v)}"' for k, v in sorted(oauth.items()))


def x_weight(text):
    """按 X 的规则估算长度:链接一律算 23,中日韩等字符算 2,其余算 1;上限 280。"""
    urls = re.findall(r"https?://\S+", text)
    w = 23 * len(urls)
    for ch in re.sub(r"https?://\S+", "", text):
        c = ord(ch)
        w += 1 if (c <= 4351 or 8192 <= c <= 8205 or 8208 <= c <= 8223 or 8242 <= c <= 8247) else 2
    return w


def fit_x(body, tail="", limit=280):
    """正文过长时截断并加省略号,tail(链接、来源)始终保留。"""
    if x_weight(body + tail) <= limit:
        return body + tail
    while body and x_weight(body + "…" + tail) > limit:
        body = body[:-1]
    return body.rstrip() + "…" + tail


class X:
    def __init__(self, cfg, base=X_API):
        self.enabled = bool((cfg.get("x") or {}).get("enabled"))
        self.base = base
        self.keys = [os.environ.get(k, "") for k in ("X_API_KEY", "X_API_SECRET", "X_ACCESS_TOKEN", "X_ACCESS_SECRET")]
        if self.enabled and not all(self.keys):
            raise SystemExit("已开启发到 X,但缺少环境变量 X_API_KEY / X_API_SECRET / X_ACCESS_TOKEN / X_ACCESS_SECRET")

    def _send(self, url, data, ctype):
        ck, cs, tk, ts = self.keys
        req = urllib.request.Request(url, data, {"Content-Type": ctype, "User-Agent": UA,
                                                 "Authorization": oauth1_header("POST", url, ck, cs, tk, ts)})
        try:
            with urllib.request.urlopen(req, timeout=120) as r:
                return json.loads(r.read() or b"{}")
        except urllib.error.HTTPError as e:
            with e:
                detail = e.read().decode("utf-8", "replace")[:300]
            raise RuntimeError(f"X 返回 {e.code}:{detail}")

    def upload_image(self, path):
        boundary = uuid.uuid4().hex
        body = (f"--{boundary}\r\nContent-Disposition: form-data; name=\"media_category\"\r\n\r\ntweet_image\r\n"
                f"--{boundary}\r\nContent-Disposition: form-data; name=\"media\"; filename=\"image.jpg\"\r\n"
                f"Content-Type: image/jpeg\r\n\r\n").encode() + Path(path).read_bytes() + f"\r\n--{boundary}--\r\n".encode()
        res = self._send(f"{self.base}/2/media/upload", body, f"multipart/form-data; boundary={boundary}")
        mid = (res.get("data") or {}).get("id") or res.get("media_id_string")
        if not mid:
            raise RuntimeError(f"图片上传失败:{res}")
        return str(mid)

    def post(self, text, media_ids=None):
        payload = {"text": text}
        if media_ids:
            payload["media"] = {"media_ids": media_ids}
        res = self._send(f"{self.base}/2/tweets", json.dumps(payload).encode(), "application/json")
        tid = (res.get("data") or {}).get("id")
        if not tid:
            raise RuntimeError(f"发帖失败:{res}")
        return tid


# --------------------------------------------------------------- 机器人 ----
def kb(item, x_on=False, tg_done=False, x_done=False):
    row1 = []
    if not tg_done:
        row1.append({"text": "✅ 发频道" if x_on else "✅ 发布", "callback_data": f"tg:{item}"})
    elif x_on:
        row1.append({"text": "✔️ 频道已发", "callback_data": "noop:"})
    if x_on:
        row1.append({"text": "𝕏 发到 X", "callback_data": f"x:{item}"} if not x_done
                    else {"text": "✔️ X 已发", "callback_data": "noop:"})
    row2 = []
    if x_on and not tg_done and not x_done:
        row2.append({"text": "🚀 两边都发", "callback_data": f"both:{item}"})
    row2.append({"text": "❌ 忽略" if not (tg_done or x_done) else "✔️ 不再发布", "callback_data": f"ign:{item}"})
    return {"inline_keyboard": [row1, row2]}


class Bot:
    def __init__(self, cfg, tg, store, x=None):
        self.cfg, self.tg, self.store = cfg, tg, store
        self.x = x if x is not None else X(cfg)
        self.include = compile_terms(cfg["include"])
        self.exclude = compile_terms(cfg["exclude"])
        self.salt = store.get("salt") or uuid.uuid4().hex
        store.set("salt", self.salt)

    # ---------- RSS ----------
    def poll_feeds(self, now=None):
        now = now or datetime.now(timezone.utc)
        cutoff = now - timedelta(hours=self.cfg["max_age_hours"])
        sent = 0
        for f in self.cfg["feeds"]:
            if f.get("enabled", True) is False:
                continue
            try:
                items = parse_feed(fetch(f["url"]))
            except Exception as e:
                log("抓取失败", f.get("name", f["url"]), e); continue
            first = not self.store.q("SELECT 1 FROM feeds_seen WHERE feed=?", (f["id"],), one=True)
            fresh = []
            for it in items:
                iid = item_id(f["id"], it["link"], it["title"])
                if self.store.q("SELECT 1 FROM items WHERE id=?", (iid,), one=True):
                    continue
                if it["date"] and it["date"] < cutoff:
                    continue
                if not wanted(it["title"], self.include, self.exclude):
                    continue
                fresh.append((iid, it))
            fresh.sort(key=lambda x: x[1]["date"] or now, reverse=True)
            if first:   # 新加入的源只送最新几条,其余标记已读,避免刷屏
                for iid, it in fresh[self.cfg["first_run_keep"]:]:
                    self._save_rss(iid, f, it, "skipped")
                fresh = fresh[: self.cfg["first_run_keep"]]
                self.store.q("INSERT OR IGNORE INTO feeds_seen VALUES(?)", (f["id"],))
            for iid, it in fresh:
                if sent >= self.cfg["max_per_cycle"]:
                    break                     # 超出的留到下一轮(未入库,下次还会出现)
                self._send_rss(iid, f, it)
                sent += 1
                time.sleep(0.5)
        return sent

    def _save_rss(self, iid, f, it, status, review_msg=None):
        self.store.q("INSERT OR IGNORE INTO items(id,kind,source,title,link,published,status,review_msg,created)"
                     " VALUES(?,?,?,?,?,?,?,?,?)",
                     (iid, "rss", f.get("name", f["id"]), it["title"], it["link"],
                      it["date"].isoformat() if it["date"] else "", status, review_msg, time.time()))

    def _send_rss(self, iid, f, it):
        when = it["date"].astimezone().strftime("%m-%d %H:%M") if it["date"] else ""
        text = (f"📰 <b>{html.escape(it['title'])}</b>\n来源:{html.escape(f.get('name', f['id']))}"
                f"{' · ' + when if when else ''}\n{html.escape(it['link'])}\n\n"
                f"<i>回复本条消息可以改写发布文字</i>")
        m = self.tg.call("sendMessage", chat_id=self.cfg["review_chat_id"], text=text, parse_mode="HTML",
                         reply_markup=kb(iid, self.x.enabled))
        self._save_rss(iid, f, it, "pending", m["message_id"])

    # ---------- 更新处理 ----------
    def handle(self, upd):
        if "callback_query" in upd:
            return self.on_button(upd["callback_query"])
        msg = upd.get("message")
        if not msg:
            return
        uid = (msg.get("from") or {}).get("id")
        chat = msg["chat"]
        if chat["id"] == int(self.cfg["review_chat_id"]) or (chat["type"] == "private" and uid in self.cfg["admin_ids"]):
            return self.on_admin(msg)
        if chat["type"] == "private" and self.cfg["accept_submissions"]:
            return self.on_submission(msg)

    def on_admin(self, msg):
        text = (msg.get("text") or "").strip()
        uid = (msg.get("from") or {}).get("id")
        if uid not in self.cfg["admin_ids"]:
            return
        reply = msg.get("reply_to_message")
        if reply and text and not text.startswith("/"):
            row = self.store.q("SELECT id,status FROM items WHERE review_msg=?", (reply["message_id"],), one=True)
            if row and row[1] == "pending":
                self.store.q("UPDATE items SET custom_text=? WHERE id=?", (text, row[0]))
                self.tg.call("sendMessage", chat_id=msg["chat"]["id"], reply_to_message_id=reply["message_id"],
                             text="已保存改写,再点这条的发布按钮就会用新文字发布(频道和 X 都会用)。")
            return
        if text.startswith("/stats"):
            rows = self.store.q("SELECT status,count(*) FROM items GROUP BY status")
            names = {"pending": "待审核", "published": "已发布", "ignored": "已忽略", "skipped": "首次跳过"}
            s = "\n".join(f"{names.get(k, k)}:{n}" for k, n in rows) or "暂无数据"
            self.tg.call("sendMessage", chat_id=msg["chat"]["id"], text=s)
        elif text.startswith("/id"):
            self.tg.call("sendMessage", chat_id=msg["chat"]["id"], text=f"这个对话的 ID:{msg['chat']['id']}\n你的用户 ID:{uid}")

    def on_submission(self, msg):
        uid = msg["from"]["id"]
        if (msg.get("text") or "").startswith("/start"):
            self.tg.call("sendMessage", chat_id=uid, text="你好!直接把文字、图片或视频发给我就是投稿。"
                         "图片和视频会自动去除位置等隐藏信息,你的账号不会被公开。\n"
                         "请注意画面里的人脸、车牌、门牌等也可能暴露你的身份。")
            return
        h = hashlib.sha256(f"{self.salt}{uid}".encode()).hexdigest()[:16]   # 只存加盐哈希,不存用户 ID
        row = self.store.q("SELECT count,last,banned FROM submitters WHERE h=?", (h,), one=True)
        if row and row[2]:
            return
        if row and time.time() - row[1] < 5:
            return self.tg.call("sendMessage", chat_id=uid, text="发送太快了,请稍等几秒再发。")
        self.store.q("INSERT INTO submitters(h,count,last) VALUES(?,1,?) ON CONFLICT(h) DO UPDATE SET count=count+1,last=?",
                     (h, time.time(), time.time()))
        sid = uuid.uuid4().hex[:12]
        review = int(self.cfg["review_chat_id"])
        try:
            content_id, note, media = self._copy_clean(msg, review)
        except Exception as e:
            log("投稿转存失败", e)
            self.tg.call("sendMessage", chat_id=uid, text="这条内容处理失败(可能文件超过 20MB),请压缩后重发。")
            return
        n = self.store.q("SELECT count(*) FROM items WHERE kind='sub'", one=True)[0] + 1
        ctrl = self.tg.call("sendMessage", chat_id=review, reply_to_message_id=content_id,
                            text=f"📮 匿名投稿 #{n}(投稿人 {h[:6]}){note}\n发布时会把上面这条原样发出。",
                            reply_markup=kb(sid, self.x.enabled))
        body = msg.get("text") or msg.get("caption") or ""
        self.store.q("INSERT INTO items(id,kind,source,title,body,media_kind,media_file,status,review_msg,content_msg,created)"
                     " VALUES(?,?,?,?,?,?,?,?,?,?,?)",
                     (sid, "sub", "投稿", body[:200], body, media[0], media[1], "pending",
                      ctrl["message_id"], content_id, time.time()))
        self.tg.call("sendMessage", chat_id=uid, text=self.cfg["submission_reply"])

    def _copy_clean(self, msg, review):
        """把投稿放进审核群:去掉转发来源;能去元数据的媒体先处理再上传。返回(消息ID, 备注)。"""
        cap = msg.get("caption")
        doc = msg.get("document") or {}
        mime = doc.get("mime_type", "")
        media = None
        if doc and mime.startswith("image/"):
            media = ("photo", doc["file_id"], "sendPhoto")
        elif msg.get("video") or (doc and mime.startswith("video/")):
            media = ("video", (msg.get("video") or doc)["file_id"], "sendVideo")
        if media:
            kind, fid, method = media
            with tempfile.TemporaryDirectory() as td:
                raw = self.tg.download(fid, os.path.join(td, "raw"))
                try:
                    out = (strip_image(raw, os.path.join(td, "clean.jpg")) if kind == "photo"
                           else strip_video(raw, os.path.join(td, "clean.mp4")))
                    note = ""
                except Exception as e:
                    log("去除元数据失败", e)
                    if kind == "video":   # 视频无法清理时不上传原文件,只提醒管理员
                        r = self.tg.call("copyMessage", chat_id=review, from_chat_id=msg["chat"]["id"],
                                         message_id=msg["message_id"])
                        return r["message_id"], "\n⚠️ 视频元数据未能去除,发布前请注意", ("video", fid)
                    raise
                r = self.tg.upload(method, {"chat_id": review, "caption": cap}, kind, out,
                                   "photo.jpg" if kind == "photo" else "video.mp4")
                got = (r.get("photo") or [{}])[-1].get("file_id") if kind == "photo" else (r.get("video") or {}).get("file_id")
                return r["message_id"], note, (kind, got or fid)
        # 文字、Telegram 普通图片(服务器已重新压缩,不含 EXIF)等:复制而不是转发,不显示来源
        r = self.tg.call("copyMessage", chat_id=review, from_chat_id=msg["chat"]["id"], message_id=msg["message_id"])
        note = "\n⚠️ 这是文件形式,元数据可能未去除" if doc else ""
        media = ("photo", msg["photo"][-1]["file_id"]) if msg.get("photo") else (None, None)
        return r["message_id"], note, media

    def on_button(self, cq):
        uid = cq["from"]["id"]
        answer = lambda t: self.tg.call("answerCallbackQuery", callback_query_id=cq["id"], text=t[:190])
        if uid not in self.cfg["admin_ids"]:
            return answer("你没有审核权限")
        action, _, iid = (cq.get("data") or "").partition(":")
        if action == "pub":
            action = "tg"          # 兼容旧按钮
        if action not in ("tg", "x", "both", "ign"):
            return answer("")
        row = self.store.q("SELECT kind,title,link,source,status,content_msg,custom_text,body,media_kind,media_file,"
                           "tg_done,x_done FROM items WHERE id=?", (iid,), one=True)
        if not row or row[4] != "pending":
            return answer("这条已经处理过了")
        kind, title, link, source, _, content_msg, custom, body, mkind, mfile, tg_done, x_done = row
        if action in ("x", "both") and not self.x.enabled:
            return answer("还没有配置 X,请先在 config.json 打开并填写密钥")
        msg = cq.get("message") or {}
        who = cq["from"].get("first_name", "")
        errors = []
        if action in ("tg", "both") and not tg_done:
            try:
                self._publish_tg(kind, title, link, source, content_msg, custom)
                tg_done = 1
                self.store.q("UPDATE items SET tg_done=1 WHERE id=?", (iid,))
            except Exception as e:
                errors.append(f"频道发布失败:{e}")
        if action in ("x", "both") and not x_done:
            try:
                tid = self._publish_x(kind, title, link, source, custom, body, mkind, mfile)
                x_done = 1
                self.store.q("UPDATE items SET x_done=1, x_id=? WHERE id=?", (tid, iid))
            except Exception as e:
                errors.append(f"X 发布失败:{e}")
        if action == "ign":
            self.store.q("UPDATE items SET status=? WHERE id=?", ("published" if (tg_done or x_done) else "ignored", iid))
            final = "✔️ 已结束" if (tg_done or x_done) else "❌ 已忽略"
            markup = {"inline_keyboard": [[{"text": f"{final}({who})", "callback_data": "noop:"}]]}
        elif tg_done and (x_done or not self.x.enabled):
            self.store.q("UPDATE items SET status='published' WHERE id=?", (iid,))
            done = "✅ 已发布到频道" + (" 和 X" if self.x.enabled else "")
            markup = {"inline_keyboard": [[{"text": f"{done}({who})", "callback_data": "noop:"}]]}
        else:
            markup = kb(iid, self.x.enabled, tg_done, x_done)
        self.tg.call("editMessageReplyMarkup", chat_id=msg["chat"]["id"], message_id=msg["message_id"], reply_markup=markup)
        if errors:
            self.tg.call("sendMessage", chat_id=msg["chat"]["id"], reply_to_message_id=msg["message_id"], text="\n".join(errors))
            return answer("有内容发送失败,详情见回复")
        answer({"tg": "已发到频道", "x": "已发到 X", "both": "两边都已发布", "ign": "已处理"}[action])

    def _publish_tg(self, kind, title, link, source, content_msg, custom):
        ch = self.cfg["channel_id"]
        if kind == "sub":
            self.tg.call("copyMessage", chat_id=ch, from_chat_id=self.cfg["review_chat_id"], message_id=content_msg)
        else:
            text = custom or f"<b>{html.escape(title)}</b>\n\n{html.escape(link)}\n\n来源:{html.escape(source)}"
            self.tg.call("sendMessage", chat_id=ch, text=text, parse_mode=None if custom else "HTML")

    def _publish_x(self, kind, title, link, source, custom, body, mkind, mfile):
        if kind == "sub":
            if mkind == "video":
                raise RuntimeError("视频暂不支持自动发到 X,请手动上传")
            media_ids = []
            if mkind == "photo" and mfile:
                with tempfile.TemporaryDirectory() as td:
                    raw = self.tg.download(mfile, os.path.join(td, "raw"))
                    media_ids.append(self.x.upload_image(strip_image(raw, os.path.join(td, "x.jpg"))))
            text = fit_x(custom or body or "")
            if not text and not media_ids:
                raise RuntimeError("没有可以发的内容")
            return self.x.post(text, media_ids)
        if custom:
            return self.x.post(fit_x(custom))
        return self.x.post(fit_x(title, f"\n\n{link}\n来源:{source}"))

    # ---------- 主循环 ----------
    def run(self):
        def feeder():
            while True:
                try:
                    n = self.poll_feeds()
                    log(f"RSS 检查完成,新增待审核 {n} 条")
                except Exception as e:
                    log("RSS 循环出错", e)
                time.sleep(self.cfg["interval_minutes"] * 60)
        threading.Thread(target=feeder, daemon=True).start()
        offset = int(self.store.get("offset", 0))
        log("机器人已启动")
        while True:
            try:
                ups = self.tg.call("getUpdates", offset=offset, timeout=50, allowed_updates=["message", "callback_query"])
            except Exception as e:
                log("getUpdates 出错", e); time.sleep(5); continue
            for u in ups:
                offset = u["update_id"] + 1
                self.store.set("offset", offset)
                try:
                    self.handle(u)
                except Exception as e:
                    log("处理更新出错", e)


def main():
    if not TOKEN:
        sys.exit("请先设置环境变量 BOT_TOKEN")
    cfg = load_config()
    data = Path(os.environ.get("NEWSBOT_DATA", ROOT / "data"))
    Bot(cfg, TG(TOKEN), Store(data / "newsbot.db")).run()


if __name__ == "__main__":
    main()
