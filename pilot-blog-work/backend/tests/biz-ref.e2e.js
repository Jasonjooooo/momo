// 经营总览 and 推广链接 in Chromium (SPEC §20): the owner sets a 成本价, turns 推广 on and adds a 推广人; a buyer opens the
// 推广链接 (cookie), buys; the owner confirms, ships and completes; the commission becomes 可结算; the owner settles; the
// 推广人's view page shows it; then the dashboard numbers, quick ranges and the trend with 30 days of history.
// 390 and 1440. Screenshots in pilot-blog-work/screens/dashboard/. Temp dirs only.
// Usage: node pilot-blog-work/backend/tests/biz-ref.e2e.js [390,1440]
const { chromium } = require('/opt/node-tools/node_modules/playwright');
const { execFileSync, spawn } = require('child_process');
const fs = require('fs');
const os = require('os');
const net = require('net');
const path = require('path');

const REPO = path.resolve(__dirname, '../../..');
const SHOTS = path.join(REPO, 'pilot-blog-work/screens/dashboard/');
const PW = '云底下的跑道灯2026秋';
const WANT = (process.argv[2] || '390,1440').split(',');
const VIEWPORTS = {
  '1440': { viewport: { width: 1440, height: 900 } },
  '390': { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true },
};
const sleep = ms => new Promise(r => setTimeout(r, ms));
let failures = 0;
let passes = 0;
const check = (name, cond, detail) => {
  if (cond) { passes++; console.log('  ok   ' + name); } else { failures++; console.log('  FAIL ' + name + (detail === undefined ? '' : ' -- ' + JSON.stringify(detail).slice(0, 400))); }
};
const yuan = fen => '¥' + (fen / 100).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
const money = s => Math.round(parseFloat(String(s).replace(/[^\d.]/g, '')) * 100);

function makeSite() {
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'pbbiz-'));
  for (const d of ['pub/posts', 'pub/uploads', 'pub/shop', 'data']) fs.mkdirSync(tmp + '/' + d, { recursive: true });
  for (const d of ['assets', 'api', 'app', 'admin']) fs.symlinkSync(REPO + '/pilot-blog/' + d, tmp + '/pub/' + d);
  for (const f of ['index.html', '.htaccess', 'posts/.htaccess', 'uploads/.htaccess', 'shop/.htaccess']) fs.copyFileSync(REPO + '/pilot-blog/' + f, tmp + '/pub/' + f);
  return tmp;
}
const portFree = port => new Promise(res => { const s = net.createServer().once('error', () => res(false)).once('listening', () => s.close(() => res(true))).listen(port, '127.0.0.1'); });
async function startServer(tmp, port) {
  const env = { ...process.env, BLOG_PUBLIC_DIR: tmp + '/pub', BLOG_DATA_DIR: tmp + '/data' };
  delete env.ANTHROPIC_API_KEY;
  const p = spawn('php', ['-S', '127.0.0.1:' + port, '-t', tmp + '/pub', REPO + '/pilot-blog/app/dev/router.php'], { env, stdio: 'ignore' });
  for (let i = 0; i < 50 && await portFree(port); i++) await sleep(100);
  return p;
}
function track(p, logs, base) {
  p.on('console', m => { if (m.type() === 'error' && !m.text().startsWith('Failed to load resource')) logs.push('console: ' + m.text()); });
  p.on('pageerror', e => logs.push('PAGEERROR: ' + e.message));
  p.on('request', r => { const u = r.url(); if (!u.startsWith(base) && !u.startsWith('blob:') && !u.startsWith('data:')) logs.push('EXTERNAL ' + u); });
  p.on('response', r => { if (r.status() >= 400 && !r.url().includes('chat.php') && !r.url().includes('hero-scrub')) logs.push('HTTP ' + r.status() + ' ' + r.url()); });
}
/** Phone layout rules: no sideways scroll, inputs >= 16px, tap targets >= 44px tall. */
async function layout(p, name, W) {
  const r = await p.evaluate(() => {
    const out = { sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth, small: [], short: [], wide: [] };
    document.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=file]), textarea, select').forEach(e => {
      if (e.offsetParent && parseFloat(getComputedStyle(e).fontSize) < 16) out.small.push(e.name || e.id);
    });
    document.querySelectorAll('main button, main .btn, main .fchip, .sform button').forEach(e => {
      const b = e.getBoundingClientRect();
      if (e.offsetParent && b.height > 0 && b.height < 44) out.short.push((e.textContent || '').trim().slice(0, 12) + ':' + Math.round(b.height));
    });
    document.querySelectorAll('main *').forEach(e => {
      const b = e.getBoundingClientRect();
      if (e.offsetParent && b.right > document.documentElement.clientWidth + 1 && getComputedStyle(e).position !== 'fixed' && !e.closest('.table-wrap')) out.wide.push(e.tagName + '.' + e.className);
    });
    out.wide = out.wide.slice(0, 5);
    return out;
  });
  check(`[${W}] ${name}: no sideways scroll`, r.sw <= r.cw && r.wide.length === 0, r);
  check(`[${W}] ${name}: inputs >= 16px`, r.small.length === 0, r.small);
  check(`[${W}] ${name}: buttons >= 44px tall`, r.short.length === 0, r.short);
}
const shot = (p, name, W, full = true) => p.screenshot({ path: SHOTS + name + '-' + W + '.png', fullPage: full });

async function sheetOk(p) {
  await p.waitForSelector('.sheet .sheet-btns .btn:not(.btn-ghost)', { timeout: 5000 });
  await Promise.all([p.waitForNavigation(), p.click('.sheet .sheet-btns .btn:not(.btn-ghost)')]);
}
async function submit(p, sel) {
  await Promise.all([p.waitForNavigation(), p.click(sel)]);
}

async function run(W, browser) {
  console.log(`\n[biz+ref ${W}]`);
  const tmp = makeSite();
  const env = { ...process.env, BLOG_PUBLIC_DIR: tmp + '/pub', BLOG_DATA_DIR: tmp + '/data' };
  const out = JSON.parse(execFileSync('php', [__dirname + '/shop-fixture.php', PW], { env }).toString());
  const port = 9800 + Math.floor(Math.random() * 150);
  const srv = await startServer(tmp, port);
  const base = 'http://127.0.0.1:' + port;
  const logs = [];
  const owner = await browser.newContext({ ...VIEWPORTS[W], locale: 'zh-CN' });
  // a real browser's user agent: HeadlessChrome is (rightly) not counted as a visitor
  const buyerUA = W === '390' ? 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1'
    : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
  const buyerCtx = await browser.newContext({ ...VIEWPORTS[W], locale: 'zh-CN', userAgent: buyerUA });
  for (const c of [owner, buyerCtx]) await c.route('**/assets/hero-scrub*.mp4', r => r.fulfill({ status: 404, body: '' }));
  try {
    // ----- owner: login, 成本价 on the poster -----
    const a = await owner.newPage();
    track(a, logs, base);
    await a.goto(base + '/admin/?r=login');
    await a.fill('#password', PW);
    await submit(a, 'main button[type=submit]');
    const menu = await a.$$eval('.menu-list a', els => els.map(e => e.textContent.trim()));
    check(`[${W}] menu: 经营总览 first in the shop run, 推广 after 货源`, menu.indexOf('经营总览') === menu.indexOf('商品') - 1 && menu.indexOf('推广') === menu.indexOf('货源') + 1, menu);
    await a.goto(base + '/admin/?r=shop');
    await submit(a, '.grow-a >> nth=0');
    await a.fill('#s-cost', '40');
    await submit(a, 'form.editor button[type=submit]:has-text("保存")');
    check(`[${W}] product editor: 成本价 saved`, (await a.inputValue('#s-cost')) === '40.00' && (await a.textContent('.flashes')).includes('已保存'), await a.textContent('.flashes'));

    // ----- 推广: switch on, add a 推广人 -----
    await a.goto(base + '/admin/?r=ref');
    check(`[${W}] 推广 page: single-level rules in plain words`, (await a.textContent('#ref-rules')).includes('只有一级') && (await a.textContent('#ref-rules')).includes('没有「下线」'));
    await submit(a, '#ref-toggle');
    check(`[${W}] 推广 switched on`, (await a.textContent('#ref-toggle')) === '关掉推广');
    await a.evaluate(() => { document.querySelector('form[action*="ref.add"]').noValidate = true; });
    await a.fill('#ra-name', '小王');
    await a.fill('#ra-contact', '13911112222');
    await submit(a, 'form[action*="ref.add"] button[type=submit]');
    check(`[${W}] adding without a rate: refused, the form keeps what was typed`, (await a.textContent('.flashes')).includes('请填佣金比例') && (await a.inputValue('#ra-name')) === '小王');
    await a.fill('#ra-rate', '8');
    await submit(a, 'form[action*="ref.add"] button[type=submit]');
    check(`[${W}] 推广人 added, opens the 推广人 page`, a.url().includes('r=ref.edit') && (await a.textContent('h1')) === '小王', a.url());
    const shown = await a.inputValue('#re-link');
    const code = (shown.match(/c=([A-Z2-9]{7})$/) || [])[1];
    const origin = (shown.match(/^https:\/\/[a-z0-9.-]+/) || [''])[0];
    check(`[${W}] 推广链接 = https://<site address>/api/shop.php?a=ref&c=<7-character code>`, !!origin && shown === origin + '/api/shop.php?a=ref&c=' + code, shown);
    const local = u => base + u.slice(origin.length);   // the fixture's site address is the real domain: open it here
    const link = local(shown);
    await a.click('[data-copy="re-link"]');
    await sleep(200);
    const copied = (await a.textContent('[data-copy="re-link"]')) === '已复制' || (await a.locator('.sheet').count()) === 1;
    check(`[${W}] 复制 copies the link (or offers the manual sheet)`, copied);
    if (await a.locator('.sheet').count()) await a.click('.sheet .btn-ghost');
    await a.selectOption('#re-to', 'shop/' + out.physical + '.html');
    await submit(a, 'form.inline-form button[type=submit]');
    const plink = local(await a.inputValue('#re-link'));
    check(`[${W}] a product link: &to=shop/<slug>.html`, plink === link + '&to=shop/' + out.physical + '.html', plink);
    const view = local(await a.inputValue('#re-view'));
    await layout(a, 'admin 推广人', W);

    // ----- buyer: home link, then the product link, cookie, order -----
    const b = await buyerCtx.newPage();
    track(b, logs, base);
    await b.goto(link);
    check(`[${W}] the 推广链接 lands on the home page 商城 (#ai)`, b.url() === base + '/#ai', b.url());
    let ck = (await buyerCtx.cookies()).find(c => c.name === 'pb_ref');
    check(`[${W}] cookie pb_ref: the code, HttpOnly, SameSite Lax, about 30 days`, ck && ck.value === code && ck.httpOnly && ck.sameSite === 'Lax' && ck.expires * 1000 - Date.now() > 29 * 86400e3, ck);
    await b.goto(plink);
    check(`[${W}] the product link lands on the product page`, b.url() === base + '/shop/' + out.physical + '.html', b.url());
    const evil = await buyerCtx.request.get(link + '&to=//evil.example', { maxRedirects: 0 });
    check(`[${W}] a foreign target is ignored (redirect stays on this site)`, evil.status() === 302 && evil.headers()['location'] === '../#ai', evil.headers()['location']);
    await submit(b, '.buy-btn');
    await b.fill('#f-qty', '2');
    await b.fill('#f-name', '李四');
    await b.fill('#f-phone', '139 1234 5678');
    await b.fill('#f-addr', '四川省成都市双流区机场路 88 号 3 栋 2 单元');
    await b.check('#f-consent');
    await sleep(2100);
    await submit(b, 'form.sform button[type=submit]');
    const orderNo = (await b.textContent('.pay-no b')).trim();
    const fen = money(await b.textContent('.pay-amt'));
    const stored = JSON.parse(fs.readFileSync(tmp + '/data/shop/orders.json')).items.find(o => o.id === orderNo);
    check(`[${W}] the order carries the code and the rate (8%) and the 成本价`, stored && stored.ref && stored.ref.code === code && stored.ref.rate_bp === 800 && stored.ref.self === false && stored.cost_unit === 4000, stored && stored.ref);
    const clicks = JSON.parse(fs.readFileSync(tmp + '/data/shop/ref.json')).partners[0].clicks;
    check(`[${W}] the buyer's clicks were counted (once per link visit, not the owner's)`, clicks === 1, clicks);

    // ----- owner: order shows 推广, confirm, ship, complete -----
    await a.goto(base + '/admin/?r=order&id=' + orderNo);
    check(`[${W}] admin order: 推广 card with 小王, 还没付款`, (await a.textContent('#o-ref')).includes('小王') && (await a.textContent('#o-ref')).includes('还没付款'));
    await a.click('form:has(input[value=confirm]) button');
    await sheetOk(a);
    check(`[${W}] after 确认收款: commission 待确认 ¥15.84`, (await a.textContent('#o-ref')).includes('待确认') && (await a.textContent('#o-ref')).includes('¥15.84'), await a.textContent('#o-ref'));
    await a.fill('#o-courier', '顺丰');
    await a.fill('#o-track', 'SF1234567890');
    await submit(a, 'form:has(input[value=ship]) button');
    await a.click('form:has(input[value=complete]) button');
    await sheetOk(a);
    check(`[${W}] completed: commission 可结算`, (await a.textContent('#o-ref')).includes('可结算'));

    // ----- the dashboard (today) -----
    await a.goto(base + '/admin/?r=biz');
    const kv = await a.evaluate(() => Object.fromEntries([...document.querySelectorAll('.biz-kpi')].map(k => [k.id, k.querySelector('.biz-v').textContent.trim()])));
    check(`[${W}] 经营总览 today: 实付 = the order, 1 paid order, 100% paid`, kv['k-paid'] === yuan(fen) && kv['k-orders'] === '1' && kv['k-rate'] === '100%' && kv['k-aov'] === yuan(fen), kv);
    check(`[${W}] 经营总览: 毛利 = paid - 2 × ¥40 cost; 推广成交额 ¥198; 待结算 ¥15.84`, kv['k-profit'] === yuan(fen - 8000) && kv['k-refsales'] === '¥198.00' && kv['k-refdue'] === '¥15.84', kv);
    check(`[${W}] 待处理: 库存不足 lists the sold-out patch, 待结算佣金 links to 推广`, (await a.textContent('#t-low')).includes('1 件') && (await a.getAttribute('#t-ref a', 'href')).includes('r=ref') && (await a.getAttribute('#t-low a', 'href')).includes('cat=low'));
    check(`[${W}] 热销排行 has the poster; the trend chart is drawn; 毛利 note in plain words`, (await a.textContent('#biz-top')).includes('PC-12') && (await a.locator('.biz-chart svg rect').count()) === 2 && (await a.textContent('#biz-note')).includes('不是净利润'));
    await submit(a, '#t-low a');
    check(`[${W}] 库存不足 opens the filtered 商品 list`, (await a.locator('#shop-low').count()) === 1 && (await a.locator('.grow').count()) === 1);

    // ----- settle -----
    await a.goto(base + '/admin/?r=ref.edit&id=' + JSON.parse(fs.readFileSync(tmp + '/data/shop/ref.json')).partners[0].id);
    check(`[${W}] 推广人 page: 可结算 ¥15.84`, (await a.textContent('#re-due')) === '¥15.84');
    await a.fill('#re-note', '10月 微信转账');
    await a.click('#re-settle-btn');
    await sheetOk(a);
    check(`[${W}] 标记已结算 recorded: log row, 已结算 ¥15.84, nothing left`, (await a.textContent('.flashes')).includes('已记下结算 ¥15.84') && (await a.locator('#re-log li').count()) === 1 && (await a.textContent('#re-settled')) === '¥15.84' && (await a.textContent('#re-due')) === '¥0.00');
    await a.goto(base + '/admin/?r=ref');
    await layout(a, 'admin 推广', W);
    await shot(a, 'admin-ref', W);

    // ----- the 推广人's view page -----
    const v = await buyerCtx.newPage();
    track(v, logs, base);
    await v.goto(view);
    const pv = await v.evaluate(() => ({ text: document.body.innerText, scripts: document.scripts.length, robots: (document.querySelector('meta[name=robots]') || {}).content }));
    check(`[${W}] view page: settled ¥15.84 with the note, masked order number, no script, noindex`, pv.text.includes('¥15.84') && pv.text.includes('10月 微信转账') && pv.text.includes(orderNo.slice(0, 11) + '****') && pv.scripts === 0 && /noindex/.test(pv.robots || ''), pv.text.slice(0, 300));
    check(`[${W}] view page: no full order number, buyer name, phone or address, no cost`, !pv.text.includes(orderNo) && !pv.text.includes('李四') && !pv.text.includes('5678') && !pv.text.includes('机场路') && !pv.text.includes('成本') && !pv.text.includes('40.00'));
    await layout(v, 'partner view', W);
    await shot(v, 'partner', W);
    const bad = await buyerCtx.request.get(base + '/api/shop.php?a=partner&t=' + '0'.repeat(32));
    check(`[${W}] a wrong view token: generic 404`, bad.status() === 404 && !(await bad.text()).includes('小王'));

    // ----- dashboard with history: quick ranges, trend, screenshots -----
    execFileSync('php', [__dirname + '/biz-fixture.php'], { env });
    await a.goto(base + '/admin/?r=biz');
    await submit(a, '.biz-quick a:has-text("7天")');
    check(`[${W}] quick range 7天`, (await a.getAttribute('.biz-quick a[aria-current]', 'href')).includes('q=7') && (await a.textContent('#biz-span')).includes('（7 天）'));
    await submit(a, '.biz-quick a:has-text("30天")');
    const bars = await a.locator('.biz-chart svg g').count();
    const kv30 = await a.evaluate(() => Object.fromEntries([...document.querySelectorAll('.biz-kpi')].map(k => [k.id, k.querySelector('.biz-v').textContent.trim()])));
    check(`[${W}] 30天: one bar per day, more paid orders and visitors than today`, bars === 30 && parseInt(kv30['k-orders'], 10) > 1 && parseInt(kv30['k-visitors'].replace(/,/g, ''), 10) > 30, { bars, kv30 });
    const box = await a.locator('.biz-chart svg').boundingBox();
    check(`[${W}] the trend chart fits the screen and is tall enough to read`, box && box.width <= VIEWPORTS[W].viewport.width && box.height >= 150, box);
    await a.fill('#biz-from', '2026-01-01');
    await a.fill('#biz-to', new Date().toISOString().slice(0, 10));   // UTC date: never after the Beijing date (the input's max)
    await submit(a, 'form.biz-range button[type=submit]');
    check(`[${W}] a range over 92 days is cut with a note`, (await a.textContent('main')).includes('最多看 92 天') && (await a.locator('.biz-chart svg g').count()) === 92 || (await a.textContent('main')).includes('还没有付款的订单'));
    await submit(a, '.biz-quick a:has-text("30天")');
    await layout(a, 'admin 经营总览', W);
    await shot(a, 'dashboard', W);

    const badLogs = logs.filter(l => !/^HTTP 400 .*\/admin\/\?r=ref\.add$/.test(l) && !/^HTTP 404 .*a=partner/.test(l));
    check(`[${W}] no console errors, no external requests, no failed loads`, badLogs.length === 0, badLogs);
  } finally {
    await owner.close();
    await buyerCtx.close();
    srv.kill();
    if (!process.env.KEEP) fs.rmSync(tmp, { recursive: true, force: true });
    else console.log('  kept ' + tmp);
  }
}

(async () => {
  fs.mkdirSync(SHOTS, { recursive: true });
  const browser = await chromium.launch();
  try {
    for (const W of WANT) await run(W, browser);
  } catch (e) {
    failures++;
    console.log('  FAIL crashed: ' + (e && e.stack || e));
  } finally {
    await browser.close();
  }
  console.log(`\n${failures ? 'FAILED: ' + failures + ' failing, ' : 'ALL PASS: '}${passes} checks`);
  process.exit(failures ? 1 : 0);
})();
