import json, os, sys, tempfile, time, unittest
from datetime import date
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
import server  # noqa: E402  (主程序在 __main__ 保护里,导入不会启动服务)


class AlertTests(unittest.TestCase):
    """与网页端 tests/calc.test.js 保持同样的口径"""
    def test_next_day(self):
        cases = [((2026, 3, 15), 15, 0), ((2026, 3, 15), 20, 5), ((2026, 3, 15), 10, 26),
                 ((2026, 12, 20), 5, 16), ((2026, 2, 10), 31, 18), ((2026, 2, 10), 5, 23), ((2028, 2, 10), 31, 19)]
        for ymd, day, want in cases:
            self.assertEqual(server.next_day(day, date(*ymd)), want, (ymd, day))

    def test_money(self):
        self.assertEqual(server.money(1234.5), "¥1,234.50")
        self.assertEqual(server.money(-75, "USD"), "-US$75.00")
        self.assertEqual(server.money(1000, "JPY"), "JP¥1,000")

    def test_offer_alerts(self):
        today = date(2026, 3, 15)
        db = {"offers": [
            {"name": "soon", "amount": 1, "days": 10, "start": "2026-03-12"},
            {"name": "far", "amount": 1, "days": 30, "start": "2026-03-12"},
            {"name": "ready", "amount": 1, "days": 5, "start": "2026-03-01"},
            {"name": "move", "amount": 1, "days": 5, "start": "2026-03-01", "rewardedDate": "2026-03-08"},
            {"name": "done", "amount": 1, "days": 5, "start": "2026-03-01", "rewardedDate": "2026-03-08", "movedDate": "2026-03-09"},
            {"name": "broken"}], "cards": []}
        texts = [t for _, t in server.build_alerts(db, today)]
        joined = "\n".join(texts)
        for n in ("soon", "ready", "move"):
            self.assertIn(n, joined)
        for n in ("far", "done", "broken"):
            self.assertNotIn(n, joined)
        urgent = {t.split("(")[0].replace("💸 ", ""): u for u, t in server.build_alerts(db, today)}
        self.assertTrue(urgent["move"]); self.assertTrue(urgent["ready"]); self.assertFalse(urgent["soon"])

    def test_card_due_alerts(self):
        today = date(2026, 3, 15)
        db = {"offers": [], "cards": [{"name": "A", "dueDay": 16}, {"name": "B", "billDay": 25}, {"name": "C", "dueDay": 15}]}
        texts = [t for _, t in server.build_alerts(db, today)]
        self.assertTrue(any("A 还款日还有1天" in t for t in texts))
        self.assertTrue(any("C 还款日就是今天" in t for t in texts))
        self.assertFalse(any(t.startswith("B") for t in texts))


class BackupTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.old = server.DATA
        server.DATA = Path(self.tmp.name)

    def tearDown(self):
        server.DATA = self.old
        self.tmp.cleanup()

    def write(self, obj):
        (server.DATA / "db.json").write_text(json.dumps(obj), "utf-8")

    def test_same_second_backups_do_not_overwrite(self):
        self.write({"v": 1}); a = server.snapshot(force=True)
        self.write({"v": 2}); b = server.snapshot(force=True)
        self.assertNotEqual(a, b)
        self.assertEqual(len(server.backups()), 2)
        self.assertEqual(json.loads((server.DATA / "backups" / a).read_text())["v"], 1)

    def test_unchanged_data_is_not_backed_up_twice(self):
        self.write({"v": 1})
        self.assertIsNotNone(server.snapshot(force=True))
        self.assertIsNone(server.snapshot(force=True))

    def test_auto_backup_is_rate_limited_but_force_is_not(self):
        self.write({"v": 1}); server.snapshot(force=True)
        self.write({"v": 2})
        self.assertIsNone(server.snapshot())            # 1 小时内不重复自动备份
        self.assertIsNotNone(server.snapshot(force=True))

    def test_retention(self):
        old_keep = server.KEEP; server.KEEP = 5
        try:
            for i in range(9):
                self.write({"v": i}); server.snapshot(force=True)
            self.assertEqual(len(server.backups()), 5)
        finally:
            server.KEEP = old_keep

    def test_backup_name_validation(self):
        for bad in ("../db.json", "db-1.json", "db-20260101-000000.json/../x", "x"):
            self.assertIsNone(server.BK_RE.match(bad))
        self.assertIsNotNone(server.BK_RE.match("db-20260101-000000.json"))


if __name__ == "__main__":
    unittest.main()
