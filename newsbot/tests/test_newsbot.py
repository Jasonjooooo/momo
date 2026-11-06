"""用一个假的 Telegram 服务器端到端测试机器人。运行:python3 -m unittest discover -s newsbot/tests"""
import io, json, os, sys, tempfile, threading, time, unittest, urllib.parse
from datetime import datetime, timedelta, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
import newsbot  # noqa: E402

NOW = datetime(2026, 11, 6, 12, 0, tzinfo=timezone.utc)
RSS = f"""<?xml version="1.0"?><rss version="2.0"><channel><title>T</title>
<item><title>Boeing 777X first flight</title><link>https://a.test/1</link><pubDate>{(NOW-timedelta(hours=1)).strftime('%a, %d %b %Y %H:%M:%S +0000')}</pubDate></item>
<item><title>赞助内容:某某推广</title><link>https://a.test/2</link><pubDate>{(NOW-timedelta(hours=2)).strftime('%a, %d %b %Y %H:%M:%S +0000')}</pubDate></item>
<item><title>Very old news</title><link>https://a.test/3</link><pubDate>{(NOW-timedelta(days=10)).strftime('%a, %d %b %Y %H:%M:%S +0000')}</pubDate></item>
<item><title>Airline adds new route</title><link>https://a.test/4</link><pubDate>{(NOW-timedelta(hours=3)).strftime('%a, %d %b %Y %H:%M:%S +0000')}</pubDate></item>
</channel></rss>""".encode()
ATOM = b"""<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><entry><title>Atom entry</title>
<link rel="alternate" href="https://b.test/x"/><updated>2026-11-06T11:00:00Z</updated></entry></feed>"""


class FakeTG:
    """记录所有 API 调用;为下载提供文件。"""
    def __init__(self):
        self.calls, self.files, self.next_id, self.x_fail = [], {}, 100, False
        fake = self

        class H(BaseHTTPRequestHandler):
            def log_message(self, *a): pass
            def do_GET(self):
                p = self.path
                if p.startswith("/file/"):
                    data = fake.files[p.rsplit("/", 1)[1]]
                    self.send_response(200); self.end_headers(); self.wfile.write(data); return
                body = {"/feed.xml": RSS, "/atom.xml": ATOM}.get(p)
                self.send_response(200 if body else 404); self.end_headers(); self.wfile.write(body or b"")
            def do_POST(self):
                n = int(self.headers["Content-Length"]); raw = self.rfile.read(n)
                method = self.path.rsplit("/", 1)[1]
                ctype = self.headers.get("Content-Type", "")
                if ctype.startswith("application/json"):
                    params = json.loads(raw)
                else:
                    params = {"_multipart": raw}
                if self.path.startswith("/2/"):     # 假的 X 接口
                    fake.calls.append(("x" + self.path, {**params, "_auth": self.headers.get("Authorization", "")}))
                    fake.next_id += 1
                    data = {"data": {"id": f"X{fake.next_id}"}}
                    if fake.x_fail:
                        self.send_response(403); out = b'{"title":"Forbidden","detail":"not allowed"}'
                    else:
                        self.send_response(200); out = json.dumps(data).encode()
                    self.send_header("Content-Length", str(len(out))); self.end_headers(); self.wfile.write(out); return
                fake.calls.append((method, params))
                fake.next_id += 1
                if method == "getFile":
                    res = {"file_id": params["file_id"], "file_path": f"docs/{params['file_id']}", "file_size": len(fake.files[params["file_id"]])}
                else:
                    res = {"message_id": fake.next_id}
                out = json.dumps({"ok": True, "result": res}).encode()
                self.send_response(200); self.send_header("Content-Length", str(len(out))); self.end_headers(); self.wfile.write(out)
        self.srv = ThreadingHTTPServer(("127.0.0.1", 0), H)
        threading.Thread(target=self.srv.serve_forever, daemon=True).start()
        self.base = f"http://127.0.0.1:{self.srv.server_address[1]}"

    def by(self, method):
        return [p for m, p in self.calls if m == method]


class BotTest(unittest.TestCase):
    def setUp(self):
        self.fake = FakeTG()
        self.tmp = tempfile.TemporaryDirectory()
        cfg_path = Path(self.tmp.name) / "config.json"
        cfg_path.write_text(json.dumps({
            "review_chat_id": -100, "channel_id": "@chan", "admin_ids": [1],
            "exclude": ["/推广|赞助/"], "first_run_keep": 3, "max_age_hours": 48,
            "feeds": [{"id": "a", "name": "源A", "url": self.fake.base + "/feed.xml"},
                      {"id": "b", "name": "源B", "url": self.fake.base + "/atom.xml"},
                      {"id": "bad", "name": "坏源", "url": self.fake.base + "/missing.xml"}]}))
        self.cfg = newsbot.load_config(cfg_path)
        self.store = newsbot.Store(Path(self.tmp.name) / "db.sqlite")
        self.bot = newsbot.Bot(self.cfg, newsbot.TG("TOKEN", self.fake.base), self.store)

    def tearDown(self):
        self.fake.srv.shutdown(); self.tmp.cleanup()

    def press(self, data, uid=1):
        review = self.fake.by("sendMessage")[-1]
        self.bot.handle({"callback_query": {"id": "cq", "from": {"id": uid, "first_name": "管理员"}, "data": data,
                                            "message": {"message_id": 1, "chat": {"id": -100}}}})

    def test_rss_filter_dedupe_and_publish(self):
        n = self.bot.poll_feeds(now=NOW)
        titles = [p["text"] for p in self.fake.by("sendMessage")]
        self.assertEqual(n, 3)                         # 777X + new route + Atom;排除了推广和过期
        self.assertTrue(any("777X" in t for t in titles) and any("Atom entry" in t for t in titles))
        self.assertFalse(any("推广" in t or "Very old" in t for t in titles))
        self.assertEqual(self.bot.poll_feeds(now=NOW), 0)   # 再抓一次不重复
        iid = self.store.q("SELECT id FROM items WHERE title LIKE '%777X%'", one=True)[0]
        self.press(f"pub:{iid}", uid=999)               # 非管理员
        self.assertEqual(self.fake.by("sendMessage")[-1]["chat_id"], -100)
        self.assertIn("没有审核权限", self.fake.by("answerCallbackQuery")[-1]["text"])
        self.press(f"pub:{iid}")
        pub = self.fake.by("sendMessage")[-1]
        self.assertEqual(pub["chat_id"], "@chan"); self.assertIn("777X", pub["text"])
        self.press(f"pub:{iid}")                        # 重复点击不会重复发布
        self.assertEqual(len([p for p in self.fake.by("sendMessage") if p["chat_id"] == "@chan"]), 1)

    def test_custom_text_and_ignore(self):
        self.bot.poll_feeds(now=NOW)
        rows = self.store.q("SELECT id,review_msg FROM items WHERE status='pending' ORDER BY title")
        (a, ma), (b, mb) = rows[0], rows[1]
        self.bot.handle({"message": {"message_id": 5, "from": {"id": 1}, "chat": {"id": -100, "type": "supergroup"},
                                     "text": "我改写的标题", "reply_to_message": {"message_id": ma}}})
        self.press(f"pub:{a}")
        self.assertEqual(self.fake.by("sendMessage")[-1]["text"], "我改写的标题")
        self.press(f"ign:{b}")
        self.assertEqual(self.store.q("SELECT status FROM items WHERE id=?", (b,), one=True)[0], "ignored")

    def test_text_submission_is_anonymous(self):
        self.bot.handle({"message": {"message_id": 7, "from": {"id": 555, "username": "secret_user"},
                                     "chat": {"id": 555, "type": "private"}, "text": "某地发生了一件事"}})
        copy = self.fake.by("copyMessage")[-1]
        self.assertEqual((copy["chat_id"], copy["from_chat_id"]), (-100, 555))   # 复制而非转发
        ctrl = [p for p in self.fake.by("sendMessage") if p["chat_id"] == -100][-1]
        self.assertNotIn("555", ctrl["text"]); self.assertNotIn("secret_user", ctrl["text"])
        self.assertIn("感谢投稿", self.fake.by("sendMessage")[-1]["text"])
        db = Path(self.tmp.name, "db.sqlite").read_bytes()
        self.assertNotIn(b"secret_user", db)
        sid = self.store.q("SELECT id FROM items WHERE kind='sub'", one=True)[0]
        self.press(f"pub:{sid}")
        last = self.fake.by("copyMessage")[-1]
        self.assertEqual((last["chat_id"], last["from_chat_id"]), ("@chan", -100))

    def test_image_document_exif_is_removed(self):
        from PIL import Image
        im = Image.new("RGB", (40, 30), "red"); exif = Image.Exif(); exif[0x8825] = {2: (30.0, 15.0, 0.0)}; exif[0x0110] = "iPhone 99"
        buf = io.BytesIO(); im.save(buf, "JPEG", exif=exif); raw = buf.getvalue()
        self.assertIn(b"iPhone 99", raw)
        self.fake.files["F1"] = raw
        self.bot.handle({"message": {"message_id": 8, "from": {"id": 556}, "chat": {"id": 556, "type": "private"},
                                     "document": {"file_id": "F1", "mime_type": "image/jpeg"}, "caption": "图"}})
        up = self.fake.by("sendPhoto")[-1]["_multipart"]
        self.assertNotIn(b"iPhone 99", up); self.assertNotIn(b"Exif", up)

    def test_video_metadata_is_removed(self):
        import shutil, subprocess
        if not shutil.which("ffmpeg"):
            self.skipTest("no ffmpeg")
        p = Path(self.tmp.name, "v.mp4")
        subprocess.run(["ffmpeg", "-v", "error", "-f", "lavfi", "-i", "color=c=blue:s=64x48:d=1", "-metadata",
                        "location=+30.0000+120.0000/", "-metadata", "comment=SECRET-DEVICE", str(p)], check=True)
        self.assertIn(b"SECRET-DEVICE", p.read_bytes())
        self.fake.files["V1"] = p.read_bytes()
        self.bot.handle({"message": {"message_id": 9, "from": {"id": 557}, "chat": {"id": 557, "type": "private"},
                                     "video": {"file_id": "V1"}}})
        up = self.fake.by("sendVideo")[-1]["_multipart"]
        self.assertNotIn(b"SECRET-DEVICE", up); self.assertNotIn(b"+30.0000+120.0000", up)

    def test_rate_limit_and_first_run_keep(self):
        for i in range(2):
            self.bot.handle({"message": {"message_id": 20 + i, "from": {"id": 600}, "chat": {"id": 600, "type": "private"}, "text": f"稿{i}"}})
        self.assertIn("太快", self.fake.by("sendMessage")[-1]["text"])
        self.assertEqual(self.store.q("SELECT count(*) FROM items WHERE kind='sub'", one=True)[0], 1)
        self.cfg["first_run_keep"] = 1
        self.store.q("DELETE FROM items"); self.store.q("DELETE FROM feeds_seen")
        self.assertEqual(self.bot.poll_feeds(now=NOW), 2)   # 每个新源只送最新 1 条
        self.assertEqual(self.store.q("SELECT count(*) FROM items WHERE status='skipped'", one=True)[0], 1)


class XTest(unittest.TestCase):
    def setUp(self):
        self.fake = FakeTG()
        self.tmp = tempfile.TemporaryDirectory()
        os.environ.update(X_API_KEY="ck", X_API_SECRET="cs", X_ACCESS_TOKEN="tk", X_ACCESS_SECRET="ts")
        cfg_path = Path(self.tmp.name) / "config.json"
        cfg_path.write_text(json.dumps({"review_chat_id": -100, "channel_id": "@chan", "admin_ids": [1], "x": {"enabled": True},
                                        "feeds": [{"id": "a", "name": "源A", "url": self.fake.base + "/feed.xml"}]}))
        self.cfg = newsbot.load_config(cfg_path)
        self.store = newsbot.Store(Path(self.tmp.name) / "db.sqlite")
        self.bot = newsbot.Bot(self.cfg, newsbot.TG("TOKEN", self.fake.base), self.store, newsbot.X(self.cfg, self.fake.base))

    def tearDown(self):
        self.fake.srv.shutdown(); self.tmp.cleanup()

    def press(self, data):
        self.bot.handle({"callback_query": {"id": "cq", "from": {"id": 1, "first_name": "管理员"}, "data": data,
                                            "message": {"message_id": 1, "chat": {"id": -100}}}})

    def tweets(self):
        return [p for m, p in self.fake.calls if m == "x/2/tweets"]

    def test_buttons_show_x(self):
        self.bot.poll_feeds(now=NOW)
        btns = [b["text"] for row in self.fake.by("sendMessage")[0]["reply_markup"]["inline_keyboard"] for b in row]
        self.assertEqual(btns, ["✅ 发频道", "𝕏 发到 X", "🚀 两边都发", "❌ 忽略"])

    def test_both_then_already_done(self):
        self.bot.poll_feeds(now=NOW)
        iid = self.store.q("SELECT id FROM items WHERE title LIKE '%777X%'", one=True)[0]
        self.press(f"both:{iid}")
        main, reply = self.tweets()[-2:]
        t = main
        self.assertIn("777X", main["text"]); self.assertIn("来源:源A", main["text"])
        self.assertNotIn("https://", main["text"])                     # 默认:正文不带外链
        self.assertEqual(reply["text"], "原文:https://a.test/1")      # 链接发在自己帖子下的回复里
        self.assertTrue(reply["reply"]["in_reply_to_tweet_id"].startswith("X"))
        self.assertTrue(t["_auth"].startswith("OAuth ") and 'oauth_signature="' in t["_auth"] and 'oauth_consumer_key="ck"' in t["_auth"])
        self.assertTrue(any(p["chat_id"] == "@chan" for p in self.fake.by("sendMessage")))
        self.assertEqual(self.store.q("SELECT status,x_done,tg_done FROM items WHERE id=?", (iid,), one=True), ("published", 1, 1))
        self.press(f"x:{iid}")
        self.assertEqual(len(self.tweets()), 2)

    def test_link_modes(self):
        self.bot.poll_feeds(now=NOW)
        ids = [r[0] for r in self.store.q("SELECT id FROM items WHERE kind='rss' ORDER BY title")]
        self.bot.x.link_mode = "inline"; self.press(f"x:{ids[0]}")
        self.assertIn("https://", self.tweets()[-1]["text"]); self.assertEqual(len(self.tweets()), 1)
        self.bot.x.link_mode = "none"; self.press(f"x:{ids[1]}")
        self.assertNotIn("https://", self.tweets()[-1]["text"]); self.assertEqual(len(self.tweets()), 2)

    def test_x_only_keeps_channel_button(self):
        self.bot.poll_feeds(now=NOW)
        iid = self.store.q("SELECT id FROM items WHERE title LIKE '%777X%'", one=True)[0]
        self.press(f"x:{iid}")
        mk = self.fake.by("editMessageReplyMarkup")[-1]["reply_markup"]["inline_keyboard"]
        self.assertEqual([b["text"] for b in mk[0]], ["✅ 发频道", "✔️ X 已发"])
        self.assertEqual(self.store.q("SELECT status FROM items WHERE id=?", (iid,), one=True)[0], "pending")
        self.press(f"tg:{iid}")
        self.assertEqual(self.store.q("SELECT status FROM items WHERE id=?", (iid,), one=True)[0], "published")

    def test_photo_submission_to_x_strips_exif(self):
        from PIL import Image
        im = Image.new("RGB", (40, 30), "red"); exif = Image.Exif(); exif[0x0110] = "iPhone 99"
        buf = io.BytesIO(); im.save(buf, "JPEG", exif=exif)
        self.fake.files["P1"] = buf.getvalue()
        self.bot.handle({"message": {"message_id": 3, "from": {"id": 700}, "chat": {"id": 700, "type": "private"},
                                     "photo": [{"file_id": "small"}, {"file_id": "P1"}], "caption": "现场照片"}})
        sid = self.store.q("SELECT id FROM items WHERE kind='sub'", one=True)[0]
        self.press(f"x:{sid}")
        up = [p for m, p in self.fake.calls if m == "x/2/media/upload"][-1]["_multipart"]
        self.assertNotIn(b"iPhone 99", up); self.assertIn(b"tweet_image", up)
        t = self.tweets()[-1]
        self.assertEqual(t["text"], "现场照片"); self.assertEqual(len(t["media"]["media_ids"]), 1)

    def test_x_error_is_reported_and_retryable(self):
        self.bot.poll_feeds(now=NOW)
        iid = self.store.q("SELECT id FROM items WHERE title LIKE '%777X%'", one=True)[0]
        self.fake.x_fail = True
        self.press(f"x:{iid}")
        self.assertIn("X 发布失败", self.fake.by("sendMessage")[-1]["text"])
        self.assertEqual(self.store.q("SELECT x_done,status FROM items WHERE id=?", (iid,), one=True), (0, "pending"))
        self.fake.x_fail = False
        self.press(f"x:{iid}")
        self.assertEqual(self.store.q("SELECT x_done FROM items WHERE id=?", (iid,), one=True)[0], 1)

    def test_video_submission_refused_for_x(self):
        self.bot.handle({"message": {"message_id": 4, "from": {"id": 701}, "chat": {"id": 701, "type": "private"}, "text": "文字稿"}})
        sid = self.store.q("SELECT id FROM items WHERE kind='sub'", one=True)[0]
        self.store.q("UPDATE items SET media_kind='video' WHERE id=?", (sid,))
        self.press(f"x:{sid}")
        self.assertIn("视频暂不支持", self.fake.by("sendMessage")[-1]["text"])


class XHelpersTest(unittest.TestCase):
    def test_oauth_signature_matches_twitter_docs_example(self):
        h = newsbot.oauth1_header(
            "POST", "https://api.twitter.com/1.1/statuses/update.json",
            "xvz1evFS4wEEPTGEFPHBog", "kAcSOqF21Fu85e7zjz7ZN2U4ZRhfV3WpwPAoE3Z7kBw",
            "370773112-GmHxMAgYyLbNEtIKZeRNFsMKPR9EyMZeS9weJAEb", "LswwdoUaIvS8ltyTt5jkRh4J50vUPVVHtR2YPi5kE",
            params={"include_entities": "true", "status": "Hello Ladies + Gentlemen, a signed OAuth request!"},
            nonce="kYjzVBB8Y0ZFabxSWbWovY3uYSQ2pTgmZeNu2VS4cg", timestamp=1318622958)
        self.assertIn('oauth_signature="hCtSmYh%2BiHYCEqBWrE7C7hYmtUk%3D"', h)

    def test_weight_and_fit(self):
        self.assertEqual(newsbot.x_weight("abc"), 3)
        self.assertEqual(newsbot.x_weight("中文"), 4)
        self.assertEqual(newsbot.x_weight("see https://example.com/very/long/path?x=1"), 4 + 23)
        tail = "\n\nhttps://a.test/1\n来源:源A"
        out = newsbot.fit_x("长" * 300, tail)
        self.assertLessEqual(newsbot.x_weight(out), 280); self.assertTrue(out.endswith(tail)); self.assertIn("…", out)
        self.assertEqual(newsbot.fit_x("short", tail), "short" + tail)


class ParseTest(unittest.TestCase):
    def test_terms(self):
        inc, exc = newsbot.compile_terms(["/C9\\d9/", "eVTOL"]), newsbot.compile_terms(["广告"])
        self.assertTrue(newsbot.wanted("C919 新消息", inc, exc))
        self.assertTrue(newsbot.wanted("an evtol test", inc, exc))
        self.assertFalse(newsbot.wanted("C919 广告", inc, exc))
        self.assertTrue(newsbot.wanted("任何内容", [], exc))


if __name__ == "__main__":
    unittest.main()
