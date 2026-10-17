// 商城 in Chromium (SPEC §20): the buyer's whole flow for a physical and a digital product at 390 and 1440, the
// owner's 确认收款 / 发货 / 完成 in the admin, the digital reveal and download, lookup auth, layout and CSP checks.
// Screenshots go to pilot-blog-work/screens/shop/. Temp dirs only.
// Usage: node pilot-blog-work/backend/tests/shop.e2e.js [390,1440]
const { chromium } = require('/opt/node-tools/node_modules/playwright');
const { execFileSync, spawn } = require('child_process');
const fs = require('fs');
const os = require('os');
const net = require('net');
const path = require('path');
const crypto = require('crypto');

const REPO = path.resolve(__dirname, '../../..');
const SHOTS = path.join(REPO, 'pilot-blog-work/screens/shop/');
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

function makeSite() {
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'pbshop-'));
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
/** Phone layout rules on a page: no sideways scroll, inputs >= 16px, tap targets >= 44px tall. */
async function layout(p, name, W, overflowOnly = false) {
  const r = await p.evaluate(() => {
    const out = { sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth, small: [], short: [] };
    document.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=file]), textarea, select').forEach(e => {
      if (e.offsetParent && parseFloat(getComputedStyle(e).fontSize) < 16) out.small.push(e.name || e.id);
    });
    document.querySelectorAll('main button, main .btn, main input[type=submit], main .qr-l, .shop-find').forEach(e => {
      const b = e.getBoundingClientRect();
      if (e.offsetParent && b.height > 0 && b.height < 44) out.short.push((e.textContent || e.value || '').trim().slice(0, 12) + ':' + Math.round(b.height));
    });
    return out;
  });
  check(`[${W}] ${name}: no sideways scroll`, r.sw <= r.cw, r);
  if (overflowOnly) return;
  check(`[${W}] ${name}: inputs >= 16px`, r.small.length === 0, r.small);
  check(`[${W}] ${name}: buttons >= 44px tall`, r.short.length === 0, r.short);
}
const shot = (p, name, W, full = true) => p.screenshot({ path: SHOTS + name + '-' + W + '.png', fullPage: full });
const money = s => Math.round(parseFloat(String(s).replace(/[^\d.]/g, '')) * 100);

async function sheetOk(p) {
  await p.waitForSelector('.sheet .sheet-btns .btn:not(.btn-ghost)', { timeout: 5000 });
  await Promise.all([p.waitForNavigation(), p.click('.sheet .sheet-btns .btn:not(.btn-ghost)')]);
}

async function adminLogin(ctx, base) {
  const a = await ctx.newPage();
  await a.goto(base + '/admin/?r=login');
  await a.fill('#password', PW);
  await Promise.all([a.waitForNavigation(), a.click('main button[type=submit]')]);
  return a;
}

async function run(W, browser) {
  console.log(`\n[shop ${W}]`);
  const tmp = makeSite();
  const out = JSON.parse(execFileSync('php', [__dirname + '/shop-fixture.php', PW], { env: { ...process.env, BLOG_PUBLIC_DIR: tmp + '/pub', BLOG_DATA_DIR: tmp + '/data' } }).toString());
  const port = 9400 + Math.floor(Math.random() * 400);
  const srv = await startServer(tmp, port);
  const base = 'http://127.0.0.1:' + port;
  const logs = [];
  const ctx = await browser.newContext({ ...VIEWPORTS[W], locale: 'zh-CN' });
  await ctx.route('**/assets/hero-scrub*.mp4', r => r.fulfill({ status: 404, body: '' }));
  const p = await ctx.newPage();
  track(p, logs, base);
  try {
    // ----- home: the 商城 section -----
    await p.goto(base + '/', { waitUntil: 'load' });
    await sleep(800);
    await p.evaluate(() => document.getElementById('ai').scrollIntoView({ block: 'start', behavior: 'instant' }));
    await sleep(900);
    const home = await p.evaluate(() => ({
      goods: [...document.querySelectorAll('#shop .goods > li')].map(li => li.querySelector('.good-n').textContent + '|' + (li.querySelector('.good-s') || {}).textContent),
      lamp: document.querySelector('#shop .lamp-code').textContent,
      nav: [...document.querySelectorAll('#nav-links a')].map(a => a.textContent),
      h2: document.getElementById('ai-h').textContent,
      chat: document.getElementById('chat-h').textContent,
      shelf: !!document.getElementById('shelf-data') || !!document.getElementById('tune'),
    }));
    check(`[${W}] home 商城 lists the three products in the owner's order`, home.goods.length === 3 && home.goods[0].startsWith('PC-12 夜航航路图海报') && home.goods[2] === '机组臂章|售罄', home.goods);
    check(`[${W}] home 商城: OPEN lamp, title 商城, nav 商城, 店铺客服 chat, no AI shelf left`, home.lamp === 'OPEN' && home.h2 === '商城' && home.nav.includes('商城') && home.chat === '店铺客服' && !home.shelf, home);
    await layout(p, 'home', W, W === '1440');   // the home page's desktop chat keeps its mouse-sized controls
    await p.locator('#ai').screenshot({ path: SHOTS + 'home-shop-' + W + '.png' });

    // ----- product page -----
    await Promise.all([p.waitForNavigation(), p.click('#shop .goods > li:first-child a')]);
    check(`[${W}] product page opens from the home grid`, p.url().endsWith('/shop/' + out.physical + '.html'), p.url());
    const prod = await p.evaluate(() => ({ h1: document.querySelector('h1').textContent, price: document.querySelector('.prod-price').textContent, slides: document.querySelectorAll('.gal-slide').length, scripts: document.scripts.length, buy: (document.querySelector('.buy-btn') || {}).textContent, h2: (document.querySelector('.prod-body h2') || {}).textContent }));
    check(`[${W}] product page: name, ¥99.00, two photos, Markdown body, 购买, no scripts`, prod.h1 === 'PC-12 夜航航路图海报' && prod.price.includes('99.00') && prod.slides === 2 && prod.h2 === '尺寸和纸' && prod.buy === '购买' && prod.scripts === 0, prod);
    await layout(p, 'product', W);
    await shot(p, 'product', W);
    // sold out page has no buy button
    const so = await ctx.newPage();
    await so.goto(base + '/shop/' + out.soldout + '.html');
    check(`[${W}] sold-out product page shows 售罄 and no 购买`, (await so.locator('.buy-btn').count()) === 0 && (await so.textContent('.buy')).includes('售罄'));
    await so.close();

    // ----- order form: errors, then a good order -----
    await Promise.all([p.waitForNavigation(), p.click('.buy-btn')]);
    await sleep(2100);
    await p.evaluate(() => { document.querySelector('form.sform').noValidate = true; });   // the server's own checks, past the browser's
    await Promise.all([p.waitForNavigation(), p.click('form.sform button[type=submit]')]);
    const errs = await p.locator('.sf-err').allTextContents();
    check(`[${W}] empty order form: plain Chinese errors, nothing stored`, errs.some(e => e.includes('收货人')) && errs.some(e => e.includes('手机号')) && errs.some(e => e.includes('勾选同意')) && (!fs.existsSync(tmp + '/data/shop/orders.json') || JSON.parse(fs.readFileSync(tmp + '/data/shop/orders.json')).items.length === 0), errs);
    await p.fill('#f-qty', '2');
    await p.fill('#f-name', '李四');
    await p.fill('#f-phone', '139 1234 5678');
    await p.fill('#f-addr', '四川省成都市双流区机场路 88 号 3 栋 2 单元');
    await p.fill('#f-note', '请用纸筒寄');
    await p.check('#f-consent');
    await layout(p, 'order form', W);
    await shot(p, 'order-form', W);
    await sleep(2100);
    await Promise.all([p.waitForNavigation(), p.click('form.sform button[type=submit]')]);
    check(`[${W}] order created: lands on the pay page`, p.url().includes('a=pay&o='), p.url());
    const pay = await p.evaluate(() => ({ amt: document.querySelector('.pay-amt').textContent, no: document.querySelector('.pay-no b').textContent, qr: document.querySelectorAll('.qr-p img').length, tabs: document.querySelectorAll('.qr-l').length, why: document.querySelector('.pay-why').textContent }));
    const fen = money(pay.amt);
    const offset = fen - (2 * 9900 + 1000);
    check(`[${W}] pay page: amount = 2 × 99 + 运费 10 + unique cents (1..199)`, offset >= 1 && offset <= 199, pay);
    check(`[${W}] pay page: order number, both 收款码, 付款时请付这个金额，备注订单号`, /^\d{8}-[A-Z2-9]{6}$/.test(pay.no) && pay.qr === 2 && pay.tabs === 2 && pay.why.includes('付款时请付这个金额，备注订单号'), pay);
    const orderNo = pay.no;
    const stored = JSON.parse(fs.readFileSync(tmp + '/data/shop/orders.json')).items.find(o => o.id === orderNo);
    check(`[${W}] stored order: server-side amount, phone normalized, stock reserved`, stored && stored.amount === fen && stored.buyer.phone === '13912345678' && JSON.parse(fs.readFileSync(tmp + '/data/shop/products.json')).items[0].stock === 10, stored);
    // tabs work without scripts: 支付宝 shows the other code
    await p.click('label[for="qr-alipay"]');
    check(`[${W}] 支付宝 tab shows the Alipay code (CSS only)`, await p.locator('.qr-p-alipay img').isVisible() && !(await p.locator('.qr-p-wechat img').isVisible()));
    await p.click('label[for="qr-wechat"]');
    await layout(p, 'pay page', W);
    await shot(p, 'pay', W);
    await sleep(2100);
    await p.fill('#f-payer', '李*');
    await Promise.all([p.waitForNavigation(), p.click('.paid-form button[type=submit]')]);
    check(`[${W}] 我已付款 → 已付款，等站长确认`, (await p.textContent('.strip-tag')).includes('等站长确认') && (await p.textContent('main')).includes('已记下'), await p.textContent('main'));

    // ----- owner: orders, confirm, ship -----
    const a = await adminLogin(ctx, base);
    track(a, logs, base);
    const dash = await a.textContent('main');
    check(`[${W}] 概览 shows 待确认收款 1`, dash.includes('待确认收款') && (await a.locator('#c-shop').locator('..').locator('.num').first().textContent()) === '1', dash.slice(0, 200));
    await a.goto(base + '/admin/?r=orders');
    check(`[${W}] 订单 lists the new order first with its amount`, (await a.locator('.orow').first().textContent()).includes((fen / 100).toFixed(2)) && (await a.locator('.menu-list .badge-n').first().textContent()) === '1');
    await layout(a, 'admin orders', W);
    await shot(a, 'admin-orders', W, true);
    await Promise.all([a.waitForNavigation(), a.locator('.orow').first().click()]);
    check(`[${W}] order detail: buyer data and payer note for the owner`, (await a.textContent('main')).includes('四川省成都市双流区机场路 88 号') && (await a.textContent('main')).includes('李*'));
    await a.click('form:has(input[value=confirm]) button');
    await sheetOk(a);
    check(`[${W}] 确认收款 → 已确认收款`, (await a.textContent('.flashes')).includes('已确认收款'), await a.textContent('.flashes'));
    await a.fill('#o-courier', '顺丰');
    await a.fill('#o-track', 'SF1234567890');
    await Promise.all([a.waitForNavigation(), a.click('form:has(input[value=ship]) button')]);
    check(`[${W}] 填快递并发货 → 已发货`, (await a.textContent('.card-amt')).includes('已发货'));
    await layout(a, 'admin order', W);
    await shot(a, 'admin-order', W);

    // ----- buyer: lookup (wrong, then right) -----
    await p.goto(base + '/api/shop.php?a=lookup');
    await sleep(2100);
    await p.fill('#f-o', orderNo.toLowerCase().replace('-', ''));
    await p.fill('#f-v', '1234');
    await Promise.all([p.waitForNavigation(), p.click('form.sform button[type=submit]')]);
    check(`[${W}] lookup with the wrong last 4 digits: one generic message`, (await p.textContent('.form-err')).includes('订单号或验证信息不对'));
    await sleep(2100);
    await p.fill('#f-v', '5678');
    await Promise.all([p.waitForNavigation(), p.click('form.sform button[type=submit]')]);
    const lk = await p.evaluate(() => ({ tag: document.querySelector('.strip-tag').textContent, ship: (document.querySelector('.shipbox') || {}).textContent || '', who: (document.querySelector('.who') || {}).textContent || '', steps: [...document.querySelectorAll('.tl li')].map(l => l.className) }));
    check(`[${W}] lookup: 已发货 with 顺丰 SF1234567890 and a masked address`, lk.tag === '已发货' && lk.ship.includes('SF1234567890') && lk.ship.includes('顺丰') && lk.who.includes('139****5678') && !lk.who.includes('88 号'), lk);
    check(`[${W}] lookup timeline: 4 steps done, 已发货 now`, lk.steps.join(',') === 'tl-done,tl-done,tl-done,tl-now,tl-todo', lk.steps);
    await layout(p, 'lookup', W);
    await shot(p, 'lookup-shipped', W);
    // owner completes
    await a.click('form:has(input[value=complete]) button');
    await sheetOk(a);
    check(`[${W}] 完成 → 已完成`, (await a.textContent('.card-amt')).includes('已完成'));

    // ----- digital product: order with an email, owner confirms, buyer sees the content and downloads -----
    await p.goto(base + '/shop/' + out.digital + '.html');
    await Promise.all([p.waitForNavigation(), p.click('.buy-btn')]);
    check(`[${W}] digital order form asks only for an email`, (await p.locator('#f-email').count()) === 1 && (await p.locator('#f-addr').count()) === 0);
    await p.fill('#f-email', 'Reader.Pilot@Example.com');
    await p.check('#f-consent');
    await sleep(2100);
    await Promise.all([p.waitForNavigation(), p.click('form.sform button[type=submit]')]);
    const dNo = await p.textContent('.pay-no b');
    const dFen = money(await p.textContent('.pay-amt'));
    check(`[${W}] digital: amount = ¥29.90 + cents, no shipping, different from the open order`, dFen > 2990 && dFen < 2990 + 200 && dFen !== fen, dFen);
    await a.goto(base + '/admin/?r=order&id=' + dNo);
    await a.click('form:has(input[value=confirm]) button');
    await sheetOk(a);
    check(`[${W}] digital 确认收款 completes the order`, (await a.textContent('.card-amt')).includes('已完成'));
    await p.goto(base + '/api/shop.php?a=lookup&o=' + dNo);
    await sleep(2100);
    await p.fill('#f-v', 'reader.pilot');
    await Promise.all([p.waitForNavigation(), p.click('form.sform button[type=submit]')]);
    const dl = await p.evaluate(() => ({ box: (document.querySelector('.dlbox') || {}).textContent || '', link: (document.querySelector('.dl-link') || {}).href, file: (document.querySelector('.dlbox a.btn-primary') || {}).href }));
    check(`[${W}] digital lookup reveals the text, the link and a download`, dl.box.includes('网盘提取码：ab12') && dl.link === 'https://pan.example.com/s/low-cloud' && /a=dl&o=/.test(dl.file || ''), dl);
    const res = await ctx.request.get(dl.file);
    const body = await res.body();
    check(`[${W}] download: the owner's exact file, as an attachment`, res.status() === 200 && crypto.createHash('sha256').update(body).digest('hex') === out.file_sha256 && /attachment/.test(res.headers()['content-disposition'] || ''), res.status());
    const tampered = await ctx.request.get(dl.file.replace(/s=[0-9a-f]{4}/, 's=0000'));
    check(`[${W}] a tampered download link is refused`, tampered.status() === 403, tampered.status());
    await shot(p, 'lookup-digital', W);

    // ----- owner pages: product editor, 收款设置 -----
    await a.goto(base + '/admin/?r=shop');
    await layout(a, 'admin products', W);
    await Promise.all([a.waitForNavigation(), a.locator('.grow-a').first().click()]);
    await layout(a, 'admin product editor', W);
    await shot(a, 'admin-product', W);
    await a.goto(base + '/admin/?r=pay');
    await layout(a, 'admin 收款设置', W);
    await shot(a, 'admin-pay', W);

    // the two form pages answered 400 on purpose (empty order form, wrong lookup); anything else is a problem
    const bad = logs.filter(l => !/^HTTP 400 .*\/api\/shop\.php\?a=(order|lookup)$/.test(l));
    check(`[${W}] no console errors, no external requests, no failed loads`, bad.length === 0, bad);
  } finally {
    await ctx.close();
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
  } finally {
    await browser.close();
  }
  console.log(failures ? `\nFAILED: ${failures} failing, ${passes} passing` : `\nALL PASS: ${passes} checks`);
  process.exit(failures ? 1 : 0);
})();
