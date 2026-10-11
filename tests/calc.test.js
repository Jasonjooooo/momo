// 计算逻辑测试:在真实页面里调用 index.html 的函数。运行:npm test(需 npm i 并安装 playwright 浏览器)
const assert = require('node:assert/strict');
const path = require('node:path');
const { chromium } = require('playwright');

const near = (a, b, eps = 1e-6) => assert.ok(Math.abs(a - b) < eps, `期望 ${b},得到 ${a}`);
let passed = 0, failed = 0;
const tests = [];
const test = (name, fn) => tests.push([name, fn]);

(async () => {
  const browser = await chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {});
  const page = await browser.newPage();
  const pageErrors = [];
  page.on('pageerror', e => pageErrors.push(e.message));
  page.on('dialog', d => d.accept());
  await page.clock.install({ time: new Date('2026-03-15T12:00:00') });
  await page.goto('file://' + (process.env.HTML_PATH || path.join(__dirname, '..', 'index.html')));
  const ev = (fn, arg) => page.evaluate(fn, arg);
  const setClock = iso => page.clock.setFixedTime(new Date(iso));

  // ---------- 单笔收益 ----------
  test('分类加成与基础返还', async () => {
    const r = await ev(() => { const c = { id: 'a', base: 1, pv: 1, rules: [{ cat: '餐饮', rate: 5 }] };
      return [earn(c, { cat: '餐饮', amount: 1000 }).v, earn(c, { cat: '超市', amount: 1000 }).v]; });
    near(r[0], 50); near(r[1], 10);
  });
  test('未填基础返还时按 0 算,不出现 NaN', async () => {
    near(await ev(() => earn({ id: 'a' }, { cat: '餐饮', amount: 500 }).v), 0);
  });
  test('退款(负数)冲减收益', async () => {
    near(await ev(() => earn({ id: 'a', base: 1, rules: [{ cat: '餐饮', rate: 5 }] }, { cat: '餐饮', amount: -200 }).v), -10);
  });

  // ---------- 卡片年度统计 ----------
  const stats = (card, txs, y = '2026', rates) => ev(([card, txs, y, rates]) => {
    db = norm({ cards: [card], txs: txs.map((t, i) => ({ id: 't' + i, card: card.id, cat: '其他', note: '', ...t })), offers: [], rates: rates || {} });
    return cardStats(card, y); }, [card, txs, y, rates]);

  test('积分折算:每100元得100积分,1积分=0.01元', async () => {
    near((await stats({ id: 'p', base: 100, pv: 0.01 }, [{ date: '2026-01-05', amount: 1000 }])).reward, 10);
  });
  test('月封顶:同月加总后封顶,跨月各自计算', async () => {
    const c = { id: 'k', base: 1, pv: 1, cap: 80, rules: [{ cat: '餐饮', rate: 5 }] };
    const s = await stats(c, [{ date: '2026-01-05', cat: '餐饮', amount: 1000 }, { date: '2026-01-20', cat: '餐饮', amount: 1000 }, { date: '2026-02-03', cat: '餐饮', amount: 1000 }]);
    near(s.reward, 130); near(s.spend, 3000);
    const m = await ev(c => cardMonthly(c, '2026'), c);
    near(m[0], 80); near(m[1], 50);
  });
  test('封顶按返还单位计算,之后再乘积分折算', async () => {
    near((await stats({ id: 'k', base: 100, pv: 0.01, cap: 15 }, [{ date: '2026-01-05', amount: 3000 }])).reward, 0.15);
  });
  test('只统计所选年份', async () => {
    const s = await stats({ id: 'y', base: 1, pv: 1 }, [{ date: '2025-12-31', amount: 1000 }, { date: '2026-01-01', amount: 1000 }]);
    near(s.spend, 1000); near(s.reward, 10);
  });
  test('逐月收益之和等于年度收益(无封顶)', async () => {
    const txs = Array.from({ length: 24 }, (_, i) => ({ date: `2026-${String(1 + (i % 12)).padStart(2, '0')}-1${i % 9}`, amount: 137.5 * (i + 1), cat: i % 2 ? '餐饮' : '其他' }));
    const c = { id: 'm', base: 1.3, pv: 1, rules: [{ cat: '餐饮', rate: 4 }] };
    const s = await stats(c, txs); const m = await ev(c => cardMonthly(c, '2026'), c);
    near(m.reduce((a, b) => a + b, 0), s.reward, 1e-6);
  });

  // ---------- 年费减免 ----------
  const wcard = { id: 'w', fee: 600, base: 0, waiveSpend: 1000, waiveCount: 3 };
  test('年费减免:消费金额达标', async () => {
    const s = await stats(wcard, [{ date: '2026-01-01', amount: 600 }, { date: '2026-02-01', amount: 500 }]);
    assert.equal(s.fee, 0); assert.equal(s.waived, true);
  });
  test('年费减免:刚好等于门槛也算达标', async () => {
    assert.equal((await stats(wcard, [{ date: '2026-01-01', amount: 1000 }])).fee, 0);
  });
  test('年费减免:笔数达标', async () => {
    const s = await stats(wcard, [1, 2, 3].map(d => ({ date: `2026-01-0${d}`, amount: 10 })));
    assert.equal(s.fee, 0);
  });
  test('年费减免:两项都不达标则收年费', async () => {
    const s = await stats(wcard, [1, 2].map(d => ({ date: `2026-01-0${d}`, amount: 10 })));
    assert.equal(s.fee, 600); assert.equal(s.waived, false);
  });
  test('未设置减免条件时收全额年费', async () => {
    assert.equal((await stats({ id: 'n', fee: 300, base: 1 }, [{ date: '2026-01-01', amount: 999999 }])).fee, 300);
  });

  // ---------- 开户奖励 ----------
  test('年化收益率', async () => {
    near(await ev(() => annual({ amount: 50000, days: 30, reward: 200 })), 200 / 50000 * 365 / 30 * 100);
    assert.equal(await ev(() => annual({ amount: 50000, days: 0, reward: 200 })), 0);
    assert.equal(await ev(() => annual({ amount: 0, days: 30, reward: 200 })), 0);
  });
  test('状态:持有中 / 今天满期 / 已满期 / 已到账待转走 / 已完成', async () => {
    const r = await ev(() => ({
      hold: offerInfo({ start: '2026-03-10', days: 30 }),
      today: offerInfo({ start: '2026-02-13', days: 30 }),
      late: offerInfo({ start: '2026-02-01', days: 30 }),
      move: offerInfo({ start: '2026-02-01', days: 30, rewardedDate: '2026-03-04' }),
      done: offerInfo({ start: '2026-02-01', days: 30, rewardedDate: '2026-03-04', movedDate: '2026-03-05' }) }));
    assert.equal(r.hold.st, 'hold'); assert.equal(r.hold.d, 25);
    assert.equal(r.today.st, 'ready'); assert.match(r.today.label, /今天满期/);
    assert.equal(r.late.st, 'ready'); assert.match(r.late.label, /已满期12天/);
    assert.equal(r.move.st, 'move'); assert.equal(r.done.st, 'done');
  });
  test('到期日跨月、跨闰年', async () => {
    assert.equal(await ev(() => addDays('2026-01-31', 30)), '2026-03-02');
    assert.equal(await ev(() => addDays('2026-02-28', 1)), '2026-03-01');
    assert.equal(await ev(() => addDays('2028-02-28', 1)), '2028-02-29');
    assert.equal(await ev(() => addDays('2026-12-31', 1)), '2027-01-01');
  });
  test('提醒:7 天内到期、已满期、待转走会提醒;更远的、已完成的不提醒', async () => {
    const names = await ev(() => { db = norm({ cards: [], txs: [], offers: [
      { id: '1', name: 'soon', amount: 1, days: 10, reward: 1, start: '2026-03-12' },      // 还剩 7 天
      { id: '2', name: 'far', amount: 1, days: 30, reward: 1, start: '2026-03-12' },       // 还剩 27 天
      { id: '3', name: 'ready', amount: 1, days: 5, reward: 1, start: '2026-03-01' },
      { id: '4', name: 'move', amount: 1, days: 5, reward: 1, start: '2026-03-01', rewardedDate: '2026-03-08' },
      { id: '5', name: 'done', amount: 1, days: 5, reward: 1, start: '2026-03-01', rewardedDate: '2026-03-08', movedDate: '2026-03-09' }] });
      return offerAlerts().map(x => x.o.name).sort(); });
    assert.deepEqual(names, ['move', 'ready', 'soon']);
  });

  // ---------- 账单日 / 还款日 ----------
  const nd = async (iso, day) => { await setClock(iso); return ev(d => nextDay(d).days, day); };
  test('下一个账单日:当天、本月、下月、年底翻年', async () => {
    assert.equal(await nd('2026-03-15T12:00:00', 15), 0);
    assert.equal(await nd('2026-03-15T12:00:00', 20), 5);
    assert.equal(await nd('2026-03-15T12:00:00', 10), 26);      // 4 月 10 日
    assert.equal(await nd('2026-12-20T12:00:00', 5), 16);       // 次年 1 月 5 日
  });
  test('31 号的账单日在小月顺延到月底(含闰年)', async () => {
    assert.equal(await nd('2026-02-10T12:00:00', 31), 18);      // 2 月 28 日
    assert.equal(await nd('2026-02-10T12:00:00', 5), 23);
    assert.equal(await nd('2028-02-10T12:00:00', 31), 19);      // 2 月 29 日
  });

  // ---------- 币种 ----------
  test('金额格式化:币种符号、负数、日元无小数', async () => {
    const r = await ev(() => [money(1234.5), money(-75, 'USD'), money(1000, 'JPY'), money(0.005), money(5, 'XYZ')]);
    assert.deepEqual(r, ['¥1,234.50', '-US$75.00', 'JP¥1,000', '¥0.01', 'XYZ 5.00']);
  });
  test('汇率:人民币为 1,未设置的币种按 1', async () => {
    const r = await ev(() => { db = norm({ cards: [], txs: [], offers: [], rates: { USD: 7 } }); return [fx('CNY'), fx(undefined), fx('USD'), fx('XYZ')]; });
    assert.deepEqual(r, [1, 1, 7, 1]);
  });
  test('外币卡折合人民币:消费、收益、年费', async () => {
    const s = await stats({ id: 'u', currency: 'USD', base: 2, pv: 1, fee: 95 }, [{ date: '2026-01-05', amount: 1000 }], '2026', { USD: 7 });
    near(s.reward, 20); near(s.rewardB, 140); near(s.spendB, 7000); near(s.feeB, 665); near(s.netB, (20 - 95) * 7);
  });

  // ---------- 运行 ----------
  for (const [name, fn] of tests) {
    try { await fn(); passed++; console.log('  ✓', name); }
    catch (e) { failed++; console.log('  ✗', name, '\n     ', e.message.split('\n').slice(0, 3).join('\n      ')); }
  }
  if (pageErrors.length) { failed++; console.log('  ✗ 页面出现脚本错误:', pageErrors); }
  console.log(`\n${passed} 通过,${failed} 失败`);
  await browser.close();
  process.exit(failed ? 1 : 0);
})();
