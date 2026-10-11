// 信用卡管家:计算核心(网页版、命令行、Claude Skill 共用同一份)。
// 纯函数,不读取全局状态、不访问网络。日期一律用 'YYYY-MM-DD' 字符串,"今天"由调用方传入。
// 修改本文件后运行 `node tools/sync-core.js` 同步到网页和 Skill。
(function (root, factory) {
  if (typeof module === 'object' && module.exports) module.exports = factory();
  else root.Core = factory();
})(this, function () {
  'use strict';
  const CURS = { CNY: '¥', USD: 'US$', HKD: 'HK$', EUR: '€', GBP: '£', JPY: 'JP¥' };
  const DEFAULT_RATES = { USD: 7.2, HKD: 0.92, EUR: 7.8, GBP: 9.1, JPY: 0.048 };

  const money = (n, cur = 'CNY') => {
    const d = cur === 'JPY' ? 0 : 2;
    return (n < 0 ? '-' : '') + (CURS[cur] || cur + ' ') +
      Math.abs(Math.round(n * 100) / 100).toLocaleString('zh-CN', { minimumFractionDigits: d, maximumFractionDigits: d });
  };
  // 1 单位外币折合多少人民币;人民币和未设置汇率的币种按 1
  const fx = (db, cur) => !cur || cur === 'CNY' ? 1 : (db.rates && db.rates[cur]) || 1;
  const norm = d => {
    d.cards = d.cards || []; d.txs = d.txs || []; d.offers = d.offers || [];
    d.rates = Object.assign({}, DEFAULT_RATES, d.rates || {});
    return d;
  };

  // ---------- 日期(按 UTC 计算整天数,不受夏令时影响) ----------
  const toUtc = s => { const [y, m, d] = s.split('-').map(Number); return Date.UTC(y, m - 1, d); };
  const fromUtc = t => { const d = new Date(t); return d.getUTCFullYear() + '-' + String(d.getUTCMonth() + 1).padStart(2, '0') + '-' + String(d.getUTCDate()).padStart(2, '0'); };
  const addDays = (s, n) => fromUtc(toUtc(s) + n * 864e5);
  const daysBetween = (a, b) => Math.round((toUtc(b) - toUtc(a)) / 864e5);
  const daysInMonth = (y, m) => new Date(Date.UTC(y, m, 0)).getUTCDate();   // m: 1-12
  // 下一个"每月 day 号"(当天算 0 天后;31 号在小月顺延到月底)
  function nextDay(day, today) {
    const [y, m] = today.split('-').map(Number);
    const at = (yy, mm) => { const t = new Date(Date.UTC(yy, mm - 1, 1)); return fromUtc(Date.UTC(t.getUTCFullYear(), t.getUTCMonth(), Math.min(day, daysInMonth(t.getUTCFullYear(), t.getUTCMonth() + 1)))); };
    let date = at(y, m);
    if (date < today) date = at(y, m + 1);
    return { date, days: daysBetween(today, date) };
  }

  // ---------- 单笔与卡片收益 ----------
  // rate 的含义是"每消费 100 元得多少"(返现卡填 1 就是 1% 返现;积分卡填积分数,再用 pv 折成钱)
  function earn(card, tx) {
    const r = (card.rules || []).find(r => r.cat === tx.cat);
    const rate = +(r ? r.rate : card.base) || 0;
    return { v: tx.amount * rate / 100, rate };
  }
  const yearTxs = (db, card, y) => db.txs.filter(t => t.card === card.id && t.date.startsWith(y));
  // 某卡某年各月收益(含月封顶,再乘积分折算),长度 12
  function cardMonthly(db, card, y) {
    const m = Array(12).fill(0);
    yearTxs(db, card, y).forEach(t => { m[+t.date.slice(5, 7) - 1] += earn(card, t).v; });
    return m.map(v => (card.cap > 0 ? Math.min(v, card.cap) : v) * (card.pv ?? 1));
  }
  // 年费减免:全年消费金额或笔数任一达标即免(达标含等于)
  function waiver(card, spend, n) {
    const hasS = card.waiveSpend > 0, hasN = card.waiveCount > 0;
    if (!hasS && !hasN) return { ok: false, has: false };
    const ok = (hasS && spend >= card.waiveSpend) || (hasN && n >= card.waiveCount);
    const parts = [];
    if (hasS) parts.push(`消费 ${money(spend, card.currency)} / ${money(card.waiveSpend, card.currency)}`);
    if (hasN) parts.push(`笔数 ${n} / ${card.waiveCount}`);
    return { ok, has: true, text: parts.join(' 或 ') };
  }
  function cardStats(db, card, y) {
    const txs = yearTxs(db, card, y);
    const spend = txs.reduce((s, t) => s + t.amount, 0);
    const reward = cardMonthly(db, card, y).reduce((a, b) => a + b, 0);
    const w = waiver(card, spend, txs.length);
    const fee = w.ok ? 0 : (card.fee || 0);
    const k = fx(db, card.currency);
    return { spend, reward, fee, net: reward - fee, n: txs.length, waived: w.ok && (card.fee || 0) > 0, k,
      spendB: spend * k, rewardB: reward * k, feeB: fee * k, netB: (reward - fee) * k };
  }
  const usedThisMonth = (db, card, today) => db.txs.filter(t => t.card === card.id && t.date.startsWith(today.slice(0, 7))).reduce((s, t) => s + t.amount, 0);

  // ---------- 开户奖励 ----------
  // 年化 = 收益 ÷ 本金 × 365 ÷ 持有天数
  const annual = o => o.amount > 0 && o.days > 0 ? o.reward / o.amount * 365 / o.days * 100 : 0;
  function offerInfo(o, today) {
    const end = addDays(o.start, o.days);
    if (o.movedDate) return { st: 'done', end, label: '已完成', cls: 'sub' };
    if (o.rewardedDate) return { st: 'move', end, label: '收益已到账,请把这笔钱转走', cls: 'bad' };
    const d = daysBetween(today, end);
    if (d <= 0) return { st: 'ready', end, label: d === 0 ? '今天满期,等待收益到账' : '已满期' + (-d) + '天,请确认收益是否到账', cls: 'warn' };
    return { st: 'hold', end, d, label: '持有中,还需 ' + d + ' 天', cls: d <= 7 ? 'warn' : 'sub' };
  }
  const offerAlerts = (db, today) => db.offers.map(o => ({ o, ...offerInfo(o, today) }))
    .filter(x => x.st === 'move' || x.st === 'ready' || (x.st === 'hold' && x.d <= 7));
  // 提醒文案:开户奖励(7 天内到期/已满期/待转走)+ 3 天内的账单日、还款日
  function alertTexts(db, today) {
    const a = offerAlerts(db, today).map(x => ({ urgent: x.st !== 'hold',
      text: (x.st === 'move' ? '💸 ' : '') + x.o.name + '(' + money(x.o.amount, x.o.currency) + '):' + x.label }));
    db.cards.forEach(c => [['账单日', c.billDay], ['还款日', c.dueDay]].forEach(([k, d]) => {
      if (!d) return; const n = nextDay(+d, today);
      if (n.days <= 3) a.push({ urgent: k === '还款日', text: c.name + ' ' + k + (n.days === 0 ? '就是今天' : '还有' + n.days + '天') });
    }));
    return a;
  }

  // ---------- 年度汇总(命令行与 Skill 使用)----------
  function report(db, year, today) {
    const Y = String(year);
    const cards = db.cards.map(c => ({ card: c, ...cardStats(db, c, Y), monthly: cardMonthly(db, c, Y) }));
    const bonusM = Array(12).fill(0);
    db.offers.forEach(o => { if (o.rewardedDate && o.rewardedDate.startsWith(Y)) bonusM[+o.rewardedDate.slice(5, 7) - 1] += (o.got ?? o.reward) * fx(db, o.currency); });
    const sum = f => cards.reduce((a, c) => a + f(c), 0);
    const bonus = bonusM.reduce((a, b) => a + b, 0);
    const done = db.offers.filter(o => o.rewardedDate && o.days > 0);
    const cap = done.reduce((a, o) => a + o.amount * fx(db, o.currency) * o.days, 0);
    const weighted = cap ? done.reduce((a, o) => a + (o.got ?? o.reward) * fx(db, o.currency), 0) / cap * 365 * 100 : 0;
    const spendB = sum(c => c.spendB), rewardB = sum(c => c.rewardB), feeB = sum(c => c.feeB);
    return {
      year: Y, cards,
      totals: { spendB, rewardB, feeB, bonusB: bonus, netB: rewardB + bonus - feeB, cashbackRate: spendB ? rewardB / spendB * 100 : 0, bonusAnnualWeighted: weighted },
      monthlyB: Array.from({ length: 12 }, (_, i) => sum(c => c.monthly[i] * c.k) + bonusM[i]),
      offers: db.offers.map(o => ({ o, annual: annual(o), ...offerInfo(o, today) })),
      alerts: alertTexts(db, today),
    };
  }

  return { CURS, DEFAULT_RATES, money, fx, norm, addDays, daysBetween, nextDay, earn, cardMonthly, waiver, cardStats,
    usedThisMonth, annual, offerInfo, offerAlerts, alertTexts, report };
});
