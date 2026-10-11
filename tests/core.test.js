// 核心计算与命令行测试(不需要浏览器)。运行:node --test tests/
const test = require('node:test'), assert = require('node:assert/strict');
const fs = require('node:fs'), os = require('node:os'), path = require('node:path'), { spawnSync, execFileSync } = require('node:child_process');
const Core = require('../core/calc.js');
const near = (a, b, e = 1e-9) => assert.ok(Math.abs(a - b) < e, `期望 ${b},得到 ${a}`);
const mk = (o = {}) => Core.norm({ cards: [], txs: [], offers: [], ...o });

test('core 与网页、Skill 副本保持一致', () => {
  execFileSync('node', [path.join(__dirname, '..', 'tools', 'sync-core.js'), '--check']);
});
test('报告:折合人民币的合计、年费减免、开户奖励', () => {
  const db = mk({ rates: { USD: 7 },
    cards: [{ id: 'a', name: 'A', base: 1, pv: 1, rules: [{ cat: '餐饮', rate: 5 }] }, { id: 'b', name: 'B', currency: 'USD', base: 2, pv: 1, fee: 95 }],
    txs: [{ id: '1', card: 'a', date: '2026-03-10', cat: '餐饮', amount: 1000 }, { id: '2', card: 'b', date: '2026-03-11', cat: '其他', amount: 1000 }],
    offers: [{ id: 'o', name: 'X', amount: 50000, days: 30, reward: 200, start: '2026-02-01', rewardedDate: '2026-03-04', got: 200 }] });
  const r = Core.report(db, 2026, '2026-03-15');
  near(r.totals.spendB, 1000 + 7000); near(r.totals.rewardB, 50 + 140); near(r.totals.feeB, 665); near(r.totals.bonusB, 200);
  near(r.totals.netB, 190 + 200 - 665); near(r.totals.cashbackRate, 190 / 8000 * 100);
  near(r.totals.bonusAnnualWeighted, 200 / (50000 * 30) * 365 * 100);
  near(r.monthlyB[2], 190 + 200);        // 3 月:卡片收益 + 开户奖励到账
  assert.equal(r.alerts.length, 1);
});
test('月度收益之和 = 年度收益(含封顶)', () => {
  const card = { id: 'a', base: 1.7, pv: 0.5, cap: 30, rules: [{ cat: '餐饮', rate: 6 }] };
  const txs = Array.from({ length: 40 }, (_, i) => ({ id: 't' + i, card: 'a', date: `2026-${String(1 + i % 12).padStart(2, '0')}-1${i % 9}`, cat: i % 3 ? '餐饮' : '其他', amount: 90 * (i + 1) }));
  const db = mk({ cards: [card], txs });
  near(Core.cardMonthly(db, card, '2026').reduce((a, b) => a + b, 0), Core.cardStats(db, card, '2026').reward);
});
test('日期工具', () => {
  assert.equal(Core.addDays('2024-02-28', 1), '2024-02-29'); assert.equal(Core.addDays('2026-12-31', 1), '2027-01-01');
  assert.equal(Core.daysBetween('2026-03-01', '2026-03-31'), 30);
  assert.deepEqual(Core.nextDay(31, '2026-04-10'), { date: '2026-04-30', days: 20 });
  assert.deepEqual(Core.nextDay(5, '2026-12-20'), { date: '2027-01-05', days: 16 });
  assert.deepEqual(Core.nextDay(15, '2026-03-15'), { date: '2026-03-15', days: 0 });
});

// ---- 命令行 ----
const CC = path.join(__dirname, '..', 'skill', 'credit-card-manager', 'scripts', 'cc.js');
const run = (data, ...args) => spawnSync('node', [CC, '--data', data, '--today', '2026-03-15', ...args], { encoding: 'utf8' });
const tmp = () => path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'cc-')), 'data.json');

test('命令行:完整流程,并与 core 结果一致', () => {
  const d = tmp();
  const ok = (...a) => { const r = run(d, ...a); assert.equal(r.status, 0, r.stderr); return r.stdout; };
  ok('add-card', '--name', '餐饮卡', '--last4', '1234', '--base', '1', '--rule', '餐饮=5', '--rule', '超市=3', '--due-day', '16');
  ok('add-card', '--name', '美元卡', '--currency', 'usd', '--base', '2', '--fee', '95');
  ok('rates', '--set', 'USD=7');
  assert.match(ok('add-tx', '--card', '餐饮', '--date', '2026-03-10', '--cat', '餐饮', '--amount', '1000'), /本笔收益 ¥50\.00/);
  ok('add-tx', '--card', '美元卡', '--date', '2026-03-11', '--amount', '1000');
  ok('add-offer', '--name', 'XX证券', '--amount', '50000', '--days', '30', '--reward', '200', '--start', '2026-02-01');
  assert.match(ok('alerts'), /已满期12天/);
  assert.match(ok('offer-got', 'XX证券'), /请把本金 ¥50,000\.00 转走/);
  assert.match(ok('alerts'), /💸 XX证券/);
  ok('offer-moved', 'XX证券');
  assert.doesNotMatch(ok('alerts'), /XX证券/);
  const j = JSON.parse(ok('report', '--year', '2026', '--json'));
  near(j.totals.rewardB, 190); near(j.totals.feeB, 665); near(j.totals.netB, 190 + 200 - 665);
  const direct = Core.report(JSON.parse(fs.readFileSync(d, 'utf8')), 2026, '2026-03-15');
  near(direct.totals.netB, j.totals.netB);
});
test('命令行:导出的文件可以再导入,结果相同', () => {
  const a = tmp(), b = tmp(), f = tmp();
  run(a, 'add-card', '--name', 'A', '--base', '1'); run(a, 'add-tx', '--card', 'A', '--amount', '500', '--date', '2026-01-02');
  assert.equal(run(a, 'export', f).status, 0);
  assert.equal(run(b, 'import', f).status, 0);
  assert.equal(run(a, 'report', '--year', '2026', '--json').stdout, run(b, 'report', '--year', '2026', '--json').stdout);
});
test('命令行:输入校验与安全', () => {
  const d = tmp();
  run(d, 'add-card', '--name', 'A', '--base', '1');
  const bad = (...a) => { const r = run(d, ...a); assert.notEqual(r.status, 0, a.join(' ')); return r.stderr; };
  assert.match(bad('add-card', '--name', 'B', '--last4', '1234567890123456'), /后 4 位/);
  assert.match(bad('add-tx', '--card', 'A', '--amount', 'abc'), /需要数字/);
  assert.match(bad('add-tx', '--card', 'A', '--amount', '10', '--date', '2026-13-40'), /YYYY-MM-DD/);
  assert.match(bad('add-tx', '--card', 'A', '--amount', '0'), /不能为 0/);
  assert.match(bad('add-tx', '--card', '不存在', '--amount', '10'), /找不到/);
  assert.match(bad('add-card', '--name', 'C', '--currency', 'XXX'), /币种只支持/);
  assert.match(bad('add-card', '--name', 'A'), /同名/);
  assert.match(bad('delete', 'card', 'A'), /--yes/);
  assert.match(bad('offer-moved', 'nothing'), /找不到/);
  assert.equal(JSON.parse(fs.readFileSync(d, 'utf8')).cards.length, 1);      // 失败的命令没有改动数据
});
test('命令行:同名模糊匹配有歧义时要求用 id', () => {
  const d = tmp();
  run(d, 'add-card', '--name', '招行卡一', '--base', '1'); run(d, 'add-card', '--name', '招行卡二', '--base', '1');
  const r = run(d, 'add-tx', '--card', '招行', '--amount', '10'); assert.notEqual(r.status, 0); assert.match(r.stderr, /匹配到多个/);
});
