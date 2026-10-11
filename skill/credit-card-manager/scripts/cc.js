#!/usr/bin/env node
// 信用卡管家命令行(Node 18+,无依赖)。数据文件格式与网页版的「导出/导入」完全一致。
// 数据位置:--data <文件> 或环境变量 CC_DATA,默认 ~/.credit-card-manager/data.json
const fs = require('fs'), os = require('os'), path = require('path'), crypto = require('crypto');
const Core = require('./calc.js');

// ---------- 参数解析 ----------
const argv = process.argv.slice(2);
const flags = {}, pos = [], rules = [];
for (let i = 0; i < argv.length; i++) {
  const a = argv[i];
  if (a.startsWith('--')) {
    const k = a.slice(2);
    if (['json', 'help', 'yes'].includes(k)) flags[k] = true;
    else { const v = argv[++i]; if (v === undefined) die(`参数 --${k} 缺少值`); if (k === 'rule') rules.push(v); else flags[k] = v; }
  } else pos.push(a);
}
function die(msg) { console.error('错误:' + msg); process.exit(1); }

const DATA = flags.data || process.env.CC_DATA || path.join(os.homedir(), '.credit-card-manager', 'data.json');
const pad = x => String(x).padStart(2, '0');
const TODAY = flags.today || (() => { const d = new Date(); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); })();
const isDate = s => /^\d{4}-\d{2}-\d{2}$/.test(s) && !isNaN(Date.parse(s + 'T00:00:00Z'));
const num = (k, v, { min } = {}) => { const n = Number(v); if (v === undefined || v === '' || !isFinite(n)) die(`--${k} 需要数字,收到:${v}`); if (min !== undefined && n < min) die(`--${k} 不能小于 ${min}`); return n; };
const uid = () => crypto.randomBytes(4).toString('hex');

function load() {
  if (!fs.existsSync(DATA)) return Core.norm({ cards: [], txs: [], offers: [] });
  try { return Core.norm(JSON.parse(fs.readFileSync(DATA, 'utf8'))); } catch (e) { die(`数据文件无法读取(${DATA}):${e.message}`); }
}
function save(db) {
  db.updatedAt = Date.now();
  fs.mkdirSync(path.dirname(DATA), { recursive: true });
  const tmp = DATA + '.tmp'; fs.writeFileSync(tmp, JSON.stringify(db, null, 2), { mode: 0o600 }); fs.renameSync(tmp, DATA);
}
function find(list, key, what) {
  if (!key) die(`请指定${what}(名称或 id)`);
  const exact = list.filter(x => x.id === key || x.name === key);
  if (exact.length === 1) return exact[0];
  const part = list.filter(x => x.name && x.name.includes(key));
  if (part.length === 1) return part[0];
  if (!exact.length && !part.length) die(`找不到${what}:${key}`);
  die(`${what}「${key}」匹配到多个:${(exact.length ? exact : part).map(x => `${x.name}(${x.id})`).join('、')},请用 id`);
}
const CUR = c => { const u = String(c || 'CNY').toUpperCase(); if (!Core.CURS[u]) die(`币种只支持:${Object.keys(Core.CURS).join('、')}`); return u; };

// ---------- 命令 ----------
function cardFields(f, base = {}) {
  const c = { ...base };
  if (f.name !== undefined) c.name = f.name;
  if (f.last4 !== undefined) { if (!/^\d{4}$/.test(f.last4)) die('--last4 只填卡号后 4 位数字(请不要提供完整卡号)'); c.last4 = f.last4; }
  if (f.currency !== undefined) c.currency = CUR(f.currency);
  for (const [k, key, o] of [['limit', 'limit', { min: 0 }], ['fee', 'fee', { min: 0 }], ['base', 'base', { min: 0 }], ['pv', 'pv', { min: 0 }], ['cap', 'cap', { min: 0 }], ['waive-spend', 'waiveSpend', { min: 0 }], ['waive-count', 'waiveCount', { min: 0 }]])
    if (f[k] !== undefined) c[key] = num(k, f[k], o);
  for (const [k, key] of [['bill-day', 'billDay'], ['due-day', 'dueDay']])
    if (f[k] !== undefined) { const n = num(k, f[k]); if (!Number.isInteger(n) || n < 1 || n > 31) die(`--${k} 需要 1-31 的整数`); c[key] = n; }
  if (rules.length) c.rules = rules.map(r => { const [cat, rate] = r.split('='); if (!cat || !isFinite(Number(rate))) die(`--rule 格式应为 类别=每100元得多少,例如 餐饮=5,收到:${r}`); return { cat, rate: Number(rate) }; });
  return c;
}
const cmds = {
  init() { const db = load(); save(db); console.log('数据文件:' + DATA); },
  'add-card'() {
    if (!flags.name) die('请用 --name 指定卡片名称');
    const db = load(); if (db.cards.some(c => c.name === flags.name)) die(`已有同名卡片:${flags.name}`);
    const c = { id: uid(), base: 0, pv: 1, fee: 0, cap: 0, rules: [], color: db.cards.length % 8, ...cardFields(flags) };
    db.cards.push(c); save(db); console.log(`已添加卡片「${c.name}」(id ${c.id})`);
  },
  'set-card'() {
    const db = load(), c = find(db.cards, pos[1], '卡片'); Object.assign(c, cardFields(flags, c)); save(db); console.log(`已更新卡片「${c.name}」`);
  },
  'add-tx'() {
    const db = load(), c = find(db.cards, flags.card, '卡片'), date = flags.date || TODAY;
    if (!isDate(date)) die('--date 格式应为 YYYY-MM-DD');
    const t = { id: uid(), date, card: c.id, cat: flags.cat || '其他', amount: num('amount', flags.amount), note: flags.note || '' };
    if (t.amount === 0) die('金额不能为 0(退款请填负数)');
    db.txs.push(t); save(db);
    const e = Core.earn(c, t);
    console.log(`已记录:${c.name} ${t.date} ${t.cat} ${Core.money(t.amount, c.currency)},本笔收益 ${Core.money(e.v * (c.pv ?? 1), c.currency)}`);
  },
  'add-offer'() {
    const db = load(); if (!flags.name) die('请用 --name 指定名称');
    const start = flags.start || TODAY; if (!isDate(start)) die('--start 格式应为 YYYY-MM-DD');
    const o = { id: uid(), name: flags.name, type: flags.type || '其他', currency: CUR(flags.currency), amount: num('amount', flags.amount, { min: 0 }), days: num('days', flags.days, { min: 0 }), reward: num('reward', flags.reward, { min: 0 }), start, note: flags.note || '' };
    db.offers.push(o); save(db);
    const i = Core.offerInfo(o, TODAY);
    console.log(`已添加:${o.name},到期日 ${i.end},年化 ${Core.annual(o).toFixed(2)}%(id ${o.id})`);
  },
  'offer-got'() {
    const db = load(), o = find(db.offers, pos[1], '开户奖励');
    if (o.rewardedDate) die('这条奖励已经确认过到账');
    o.rewardedDate = flags.date || TODAY; o.got = flags.amount !== undefined ? num('amount', flags.amount, { min: 0 }) : o.reward; save(db);
    console.log(`已确认「${o.name}」收益到账 ${Core.money(o.got, o.currency)}。提醒:请把本金 ${Core.money(o.amount, o.currency)} 转走,转走后运行 offer-moved。`);
  },
  'offer-moved'() {
    const db = load(), o = find(db.offers, pos[1], '开户奖励');
    if (!o.rewardedDate) die('收益还没有确认到账,请先运行 offer-got');
    o.movedDate = flags.date || TODAY; save(db); console.log(`已记录「${o.name}」本金转走,此条已完成。`);
  },
  delete() {
    const [, kind, key] = pos, db = load();
    const map = { card: ['cards', '卡片'], tx: ['txs', '消费'], offer: ['offers', '开户奖励'] }[kind];
    if (!map) die('用法:delete card|tx|offer <名称或id>');
    const item = map[0] === 'txs' ? db.txs.find(t => t.id === key) : find(db[map[0]], key, map[1]);
    if (!item) die('找不到:' + key);
    if (!flags.yes) die(`这会删除「${item.name || item.id}」${kind === 'card' ? '及其全部消费记录' : ''}。确认请加 --yes`);
    if (kind === 'card') db.txs = db.txs.filter(t => t.card !== item.id);
    db[map[0]] = db[map[0]].filter(x => x.id !== item.id); save(db); console.log('已删除');
  },
  rates() {
    const db = load();
    if (flags.set) { const [c, v] = flags.set.split('='); const u = CUR(c); if (u === 'CNY') die('人民币汇率固定为 1'); db.rates[u] = num('set', v, { min: 0.0000001 }); save(db); }
    console.log('1 单位外币 = 多少人民币:' + Object.entries(db.rates).map(([k, v]) => `${k} ${v}`).join(',  '));
  },
  list() {
    const db = load(), what = pos[1] || 'cards';
    if (what === 'cards') db.cards.forEach(c => console.log(`${c.name} ${c.last4 || ''} [${c.currency || 'CNY'}] 年费 ${Core.money(c.fee || 0, c.currency)} 基础每100元得${c.base || 0}${(c.rules || []).map(r => ` ${r.cat}${r.rate}`).join('')}  id ${c.id}`));
    else if (what === 'txs') [...db.txs].sort((a, b) => b.date.localeCompare(a.date)).slice(0, +flags.limit || 30).forEach(t => { const c = db.cards.find(x => x.id === t.card); console.log(`${t.date} ${c ? c.name : '(已删除)'} ${t.cat} ${Core.money(t.amount, c && c.currency)} ${t.note || ''} id ${t.id}`); });
    else if (what === 'offers') db.offers.forEach(o => { const i = Core.offerInfo(o, TODAY); console.log(`${o.name} [${o.type}] ${Core.money(o.amount, o.currency)} ${o.days}天 → ${Core.money(o.reward, o.currency)} 年化${Core.annual(o).toFixed(2)}% ${i.start || ''}到期${i.end} ${i.label} id ${o.id}`); });
    else die('用法:list cards|txs|offers');
    if (!db.cards.length && what === 'cards') console.log('(还没有卡片)');
  },
  alerts() {
    const a = Core.alertTexts(load(), TODAY);
    if (flags.json) return console.log(JSON.stringify(a, null, 2));
    console.log(a.length ? a.map(x => (x.urgent ? '‼ ' : '· ') + x.text).join('\n') : '目前没有待办提醒。');
  },
  report() {
    const db = load(), year = flags.year || TODAY.slice(0, 4), r = Core.report(db, year, TODAY), m = Core.money;
    if (flags.json) return console.log(JSON.stringify({ ...r, cards: r.cards.map(({ card, monthly, ...x }) => ({ name: card.name, currency: card.currency || 'CNY', ...x })), offers: r.offers.map(({ o, ...x }) => ({ name: o.name, ...x })) }, null, 2));
    const T = r.totals;
    const out = [`${year} 年度报告(合计折合人民币)`,
      `总消费 ${m(T.spendB)} | 卡片返现/积分 ${m(T.rewardB)} | 开户奖励(已到账) ${m(T.bonusB)} | 年费 ${m(T.feeB)} | 净收益 ${m(T.netB)}`,
      `综合返现率 ${T.cashbackRate.toFixed(2)}% | 开户奖励资金加权年化 ${T.bonusAnnualWeighted.toFixed(2)}%`, '', '各卡(原币种):'];
    r.cards.forEach(c => out.push(`· ${c.card.name}:消费 ${m(c.spend, c.card.currency)},收益 ${m(c.reward, c.card.currency)},年费 ${m(c.fee, c.card.currency)}${c.waived ? '(已减免)' : ''},净 ${m(c.net, c.card.currency)}`));
    if (!r.cards.length) out.push('(没有卡片)');
    out.push('', '开户奖励:');
    r.offers.forEach(x => out.push(`· ${x.o.name}:${m(x.o.amount, x.o.currency)} ${x.o.days}天 → ${m(x.o.reward, x.o.currency)},年化 ${x.annual.toFixed(2)}%,${x.label}`));
    if (!r.offers.length) out.push('(没有记录)');
    out.push('', '待办提醒:', ...(r.alerts.length ? r.alerts.map(a => (a.urgent ? '‼ ' : '· ') + a.text) : ['无']), '', '计算结果仅供参考,以银行/券商实际结算为准。');
    console.log(out.join('\n'));
  },
  export() { const db = load(); const out = JSON.stringify(db, null, 2); if (pos[1]) { fs.writeFileSync(pos[1], out); console.log('已导出到 ' + pos[1] + '(可在网页版「导入」)'); } else console.log(out); },
  import() {
    if (!pos[1]) die('用法:import <网页导出的 json 文件> --yes'); let d;
    try { d = JSON.parse(fs.readFileSync(pos[1], 'utf8')); } catch (e) { die('无法读取文件:' + e.message); }
    if (!Array.isArray(d.cards) || !Array.isArray(d.txs)) die('这不是有效的导出文件');
    if (fs.existsSync(DATA) && !flags.yes) die('这会覆盖现有数据,确认请加 --yes');
    save(Core.norm(d)); console.log(`已导入:${d.cards.length} 张卡、${d.txs.length} 笔消费、${(d.offers || []).length} 条开户奖励`);
  },
};
const help = `用法:node cc.js <命令> [参数]   (数据文件:${DATA})
  init | add-card --name X [--last4 1234 --currency USD --limit N --fee N --base N --pv N --cap N --bill-day N --due-day N --waive-spend N --waive-count N --rule 餐饮=5 ...]
  set-card <卡> [同上参数] | add-tx --card <卡> --amount N [--date --cat --note] | add-offer --name X --amount N --days N --reward N [--start --type --currency]
  offer-got <奖励> [--amount N --date D] | offer-moved <奖励> | delete card|tx|offer <名称或id> --yes
  list cards|txs|offers | alerts [--json] | report [--year Y --json] | rates [--set USD=7.2] | export [文件] | import <文件> --yes
  通用:--data <文件> --today YYYY-MM-DD(测试用)`;
const cmd = pos[0];
if (!cmd || flags.help || !cmds[cmd]) { console.log(help); process.exit(cmd && !cmds[cmd] && !flags.help ? 1 : 0); }
cmds[cmd]();
