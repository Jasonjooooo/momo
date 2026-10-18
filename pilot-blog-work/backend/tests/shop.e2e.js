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


// ---------- 商品分类 (大类 / 小类): admin page, editor picker (with and without script), home tabs and chips, deep link,
// no-script view, product breadcrumb. Screenshots in pilot-blog-work/screens/categories/. ----------
const CAT_SHOTS = path.join(REPO, 'pilot-blog-work/screens/categories/');

async function runCats(W, browser) {
  console.log(`\n[categories ${W}]`);
  const tmp = makeSite();
  const out = JSON.parse(execFileSync('php', [__dirname + '/shop-fixture.php', PW, 'more'], { env: { ...process.env, BLOG_PUBLIC_DIR: tmp + '/pub', BLOG_DATA_DIR: tmp + '/data' } }).toString());
  const port = 9800 + Math.floor(Math.random() * 150);
  const srv = await startServer(tmp, port);
  const base = 'http://127.0.0.1:' + port;
  const logs = [];
  const ctx = await browser.newContext({ ...VIEWPORTS[W], locale: 'zh-CN' });
  await ctx.route('**/assets/hero-scrub*.mp4', r => r.fulfill({ status: 404, body: '' }));
  const cats = () => JSON.parse(fs.readFileSync(tmp + '/data/shop/categories.json')).items;
  const products = () => JSON.parse(fs.readFileSync(tmp + '/data/shop/products.json')).items;
  const bySlug = slug => products().find(x => x.slug === slug);
  const flash = async a => (await a.textContent('.flashes')) || '';
  try {
    const a = await adminLogin(ctx, base);
    track(a, logs, base);
    // ----- the 商品分类 page: create, limits, order -----
    await a.goto(base + '/admin/?r=shop');
    check(`[${W}] 商品 links to 商品分类, and the menu has it`, (await a.locator('main a.btn', { hasText: '商品分类' }).count()) === 1 && (await a.locator('.menu-list a', { hasText: '商品分类' }).count()) === 1);
    await Promise.all([a.waitForNavigation(), a.click('main a.btn:has-text("商品分类")')]);
    check(`[${W}] empty 商品分类 page explains that categories are optional`, (await a.textContent('main')).includes('还没有分类'));
    const addTop = async name => { await a.fill('#c-new-name', name); await Promise.all([a.waitForNavigation(), a.click('form.cat-add:has(#c-new-name) button')]); };
    await addTop('航空周边');
    await addTop('书和资料');
    await addTop('空的大类');
    check(`[${W}] three 大类 created`, cats().map(c => c.name).join(',') === '航空周边,书和资料,空的大类', cats());
    const [air, books, empty] = cats();
    const addSub = async (top, name) => { await a.fill('#ns-' + top.id, name); await Promise.all([a.waitForNavigation(), a.click(`form.cat-add-sub:has(#ns-${top.id}) button`)]); };
    await addSub(air, '海报');
    await addSub(air, '徽章');
    const subs = cats()[0].subs;
    check(`[${W}] two 小类 in 航空周边`, subs.map(x => x.name).join(',') === '海报,徽章', subs);
    const [poster, badge] = subs;
    // a name over 12 characters (past the input's maxlength) is refused by the server with a plain message
    await a.evaluate(() => document.getElementById('c-new-name').removeAttribute('maxlength'));
    await addTop('一二三四五六七八九十十一十二');
    check(`[${W}] 13-character name refused with a plain message`, (await flash(a)).includes('最多 12 个字') && cats().length === 3, await flash(a));
    await addTop('航空周边');
    check(`[${W}] a duplicate 大类 name refused`, (await flash(a)).includes('已经有') && cats().length === 3, await flash(a));
    // order: 书和资料 up (phone-sized buttons, plain forms)
    await Promise.all([a.waitForNavigation(), a.click(`[data-cat-id="${books.id}"] .grow-move button:has-text("上移")`)]);
    check(`[${W}] 上移 moves 书和资料 before 航空周边`, cats().map(c => c.name).join(',') === '书和资料,航空周边,空的大类', cats().map(c => c.name));
    await Promise.all([a.waitForNavigation(), a.click(`[data-cat-id="${badge.id}"] .grow-move button:has-text("上移")`)]);
    check(`[${W}] 上移 on a 小类 reorders inside its 大类`, cats()[1].subs.map(x => x.name).join(',') === '徽章,海报', cats()[1].subs);
    await Promise.all([a.waitForNavigation(), a.click(`[data-cat-id="${badge.id}"] .grow-move button:has-text("下移")`)]);
    check(`[${W}] 下移 puts it back`, cats()[1].subs.map(x => x.name).join(',') === '海报,徽章', cats()[1].subs);
    // ----- assign products: the two-select picker (script) -----
    const assign = async (slug, top, sub) => {
      await a.goto(base + '/admin/?r=shop.edit&id=' + bySlug(slug).id);
      await a.selectOption('#s-cat-top', top);
      if (sub) await a.selectOption('#s-cat-sub', sub);
      await Promise.all([a.waitForNavigation(), a.click('form.editor .bar button[type=submit]')]);
    };
    await a.goto(base + '/admin/?r=shop.edit&id=' + bySlug(out.physical).id);
    const pick = await a.evaluate(() => ({ plain: !document.getElementById('s-cat').offsetParent, top: !!document.getElementById('s-cat-top'), subHidden: document.getElementById('s-cat-sub').closest('div').hidden }));
    check(`[${W}] editor: the plain select becomes 大类 + 小类 selects (小类 hidden until a 大类 with 小类 is picked)`, pick.plain && pick.top && pick.subHidden, pick);
    await a.selectOption('#s-cat-top', air.id);
    const subOpts = await a.locator('#s-cat-sub option').allTextContents();
    check(`[${W}] picking 航空周边 offers its 小类`, subOpts.join(',') === '不选小类（直接放在大类里）,海报,徽章' && await a.locator('#s-cat-sub').isVisible(), subOpts);
    await layout(a, 'admin product editor with categories', W);
    await a.selectOption('#s-cat-sub', poster.id);
    await Promise.all([a.waitForNavigation(), a.click('form.editor .bar button[type=submit]')]);
    check(`[${W}] product saved into 航空周边 / 海报`, bySlug(out.physical).cat === poster.id && (await flash(a)).includes('已保存'), bySlug(out.physical).cat);
    check(`[${W}] the editor shows the saved category again`, (await a.inputValue('#s-cat-top')) === air.id && (await a.inputValue('#s-cat-sub')) === poster.id);
    await assign(out.extra[0], air.id, null);
    await assign(out.digital, books.id, null);
    await assign(out.extra[1], books.id, null);
    // ----- the same editor without script: one plain select with 「大类 / 小类」 options -----
    const nojs = await browser.newContext({ ...VIEWPORTS[W], locale: 'zh-CN', javaScriptEnabled: false });
    const cookies = await ctx.cookies();
    await nojs.addCookies(cookies);
    const n = await nojs.newPage();
    await n.goto(base + '/admin/?r=shop.edit&id=' + bySlug(out.soldout).id);
    const plainOpts = await n.locator('#s-cat option').allTextContents();
    check(`[${W}] without script the editor has one select: 不分类, 大类 and 「大类 / 小类」`, plainOpts.join(',') === '不分类,书和资料,航空周边,航空周边 / 海报,航空周边 / 徽章,空的大类' && await n.locator('#s-cat').isVisible(), plainOpts);
    await n.selectOption('#s-cat', badge.id);
    await Promise.all([n.waitForNavigation(), n.click('form.editor .bar button[type=submit]')]);
    check(`[${W}] saved without script into 航空周边 / 徽章`, bySlug(out.soldout).cat === badge.id, bySlug(out.soldout).cat);
    await nojs.close();
    // ----- delete: refused while products are inside, fine when empty -----
    await a.goto(base + '/admin/?r=cats');
    const posterRow = a.locator(`[data-cat-id="${poster.id}"]`);
    await posterRow.locator('summary').click();
    check(`[${W}] a 小类 with a product shows why it cannot be deleted, and no delete button`, (await posterRow.textContent()).includes('还有 1 件商品，不能删除') && (await posterRow.locator('button.btn-danger').count()) === 0);
    const meta = await a.locator(`[data-cat-id="${air.id}"] .cat-meta`).first().textContent();
    check(`[${W}] 大类 counts include its 小类`, meta.includes('3 件商品') && meta.includes('在卖 3 件'), meta);
    const emptyRow = a.locator(`[data-cat-id="${empty.id}"]`);
    check(`[${W}] an empty 大类 says it is not shown on the home page`, (await emptyRow.textContent()).includes('首页不显示'));
    await layout(a, 'admin categories', W);
    await shot(a, 'admin-cats', W).catch(() => {});
    fs.renameSync(SHOTS + 'admin-cats-' + W + '.png', CAT_SHOTS + 'admin-cats-' + W + '.png');
    await emptyRow.locator('summary').click();
    await emptyRow.locator('button.btn-danger').click();
    await sheetOk(a);
    check(`[${W}] the empty 大类 is deleted after the confirm sheet`, cats().length === 2 && (await flash(a)).includes('已删除'), await flash(a));
    // the server refuses too, whatever the page shows
    const refused = await a.evaluate(async ([id]) => {
      const fd = new FormData(); fd.append('_csrf', document.querySelector('meta[name=csrf]').content); fd.append('id', id);
      const r = await fetch('?r=cats.delete', { method: 'POST', body: fd, credentials: 'same-origin' });
      return r.status;
    }, [air.id]);
    check(`[${W}] a forged delete of a 大类 with products is refused`, cats().some(c => c.id === air.id), refused);
    // product list: filter by category
    await a.goto(base + '/admin/?r=shop&cat=' + air.id);
    const listed = await a.locator('.grow .prow-t').allTextContents();
    check(`[${W}] 商品 filtered by 航空周边 lists its 3 products`, listed.length === 3, listed);
    await a.goto(base + '/admin/?r=shop&cat=none');
    check(`[${W}] 商品 filtered by 不分类 lists the one left`, (await a.locator('.grow .prow-t').allTextContents()).join(',') === '反光贴纸');

    // ----- home: tabs and chips -----
    const p = await ctx.newPage();
    track(p, logs, base);
    await p.goto(base + '/', { waitUntil: 'load' });
    await sleep(600);
    await p.evaluate(() => document.getElementById('shop').scrollIntoView({ block: 'start', behavior: 'instant' }));
    await sleep(700);
    const st = () => p.evaluate(() => ({
      tabs: [...document.querySelectorAll('.cat-tab')].map(b => b.firstChild.textContent + (b.getAttribute('aria-pressed') === 'true' ? '*' : '')),
      chips: [...document.querySelectorAll('.cat-chips:not([hidden]) .cat-chip')].map(b => b.textContent + (b.getAttribute('aria-pressed') === 'true' ? '*' : '')),
      shown: [...document.querySelectorAll('#shop .goods > .good')].filter(g => g.offsetParent).map(g => g.querySelector('.good-n').textContent),
      hash: location.hash,
      live: document.querySelector('[data-cat-status]').textContent,
    }));
    let s0 = await st();
    check(`[${W}] home tabs: 全部 first, 大类 in the owner's order, 其他 last; empty 大类 hidden`, s0.tabs.join(',') === '全部*,书和资料,航空周边,其他' && s0.shown.length === 6 && s0.chips.length === 0, s0);
    const tapBad = await p.evaluate(() => [...document.querySelectorAll('.cat-tab, .cat-chip')].filter(b => b.offsetParent && b.getBoundingClientRect().height < 44).length);
    check(`[${W}] tabs are at least 44px tall`, tapBad === 0, tapBad);
    await p.click('.cat-tab[data-cat="' + air.id + '"]');
    let s1 = await st();
    check(`[${W}] 航空周边: its 3 products, 小类 chips 全部 / 海报 / 徽章, hash #shop/c/<id>`, s1.shown.length === 3 && s1.chips.join(',') === '全部*,海报,徽章' && s1.hash === '#shop/c/' + air.id && s1.tabs[2] === '航空周边*' && s1.live === '显示 3 件商品', s1);
    await layout(p, 'home with category tabs', W, true);
    const rowOk = await p.evaluate(() => { const r = document.querySelector('.cat-tabs'); return getComputedStyle(r).overflowX === 'auto' && getComputedStyle(r).flexWrap === 'nowrap'; });
    check(`[${W}] tab rows scroll sideways instead of wrapping`, rowOk);
    await p.locator('#shop').screenshot({ path: CAT_SHOTS + 'home-shop-tabs-' + W + '.png' });
    await p.click('.cat-chips:not([hidden]) .cat-chip[data-sub="' + poster.id + '"]');
    let s2 = await st();
    check(`[${W}] 小类 海报: one product, chip pressed, hash names the 小类`, s2.shown.join(',') === 'PC-12 夜航航路图海报' && s2.chips.join(',') === '全部,海报*,徽章' && s2.hash === '#shop/c/' + poster.id, s2);
    await p.locator('#shop').screenshot({ path: CAT_SHOTS + 'home-shop-chips-' + W + '.png' });
    await p.click('.cat-tab[data-cat="other"]');
    let s3 = await st();
    check(`[${W}] 其他: the uncategorized product, no chips`, s3.shown.join(',') === '反光贴纸' && s3.chips.length === 0 && s3.hash === '#shop/c/other', s3);
    await p.click('.cat-tab[data-cat=""]');
    let s4 = await st();
    check(`[${W}] 全部 again: all 6, hash back to #shop`, s4.shown.length === 6 && s4.hash === '#shop', s4);
    // ----- deep link -----
    const d = await ctx.newPage();
    track(d, logs, base);
    await d.goto(base + '/#shop/c/' + poster.id, { waitUntil: 'load' });
    await sleep(900);
    const dl = await d.evaluate(() => {
      const r = document.getElementById('shop').getBoundingClientRect();
      return { top: Math.round(r.top), vh: innerHeight, shown: [...document.querySelectorAll('#shop .goods > .good')].filter(g => g.offsetParent).length, tab: (document.querySelector('.cat-tab[aria-pressed="true"]') || {}).textContent, chip: (document.querySelector('.cat-chips:not([hidden]) .cat-chip[aria-pressed="true"]') || {}).textContent };
    });
    check(`[${W}] deep link #shop/c/<小类>: filtered to 1 product, 航空周边 + 海报 pressed, the shop scrolled into view`, dl.shown === 1 && /^航空周边/.test(dl.tab) && dl.chip === '海报' && dl.top >= 0 && dl.top < dl.vh / 2, dl);
    if (W === '1440') {
      await d.click('#nav-links a[href="#ai"]');
      await sleep(1200);
      const nav = await d.evaluate(() => ({ hash: location.hash, top: Math.round(document.getElementById('ai').getBoundingClientRect().top), shown: [...document.querySelectorAll('#shop .goods > .good')].filter(g => g.offsetParent).length }));
      check(`[${W}] the nav 商城 link (#ai) still scrolls to the section and keeps the filter`, nav.hash === '#ai' && Math.abs(nav.top) < 120 && nav.shown === 1, nav);
    }
    await d.goto(base + '/#shop/c/c00000000', { waitUntil: 'load' });
    await sleep(500);
    check(`[${W}] deep link to a category that is gone: everything shows`, (await d.evaluate(() => [...document.querySelectorAll('#shop .goods > .good')].filter(g => g.offsetParent).length)) === 6);
    await d.close();
    // ----- without script: every product shows, the tab rows stay hidden -----
    const nj = await browser.newContext({ ...VIEWPORTS[W], locale: 'zh-CN', javaScriptEnabled: false });
    const q = await nj.newPage();
    await q.goto(base + '/#shop/c/' + poster.id);
    const njs = await q.evaluate(() => ({ shown: [...document.querySelectorAll('#shop .goods > .good')].filter(g => g.offsetParent).length, tabs: !!document.querySelector('.shop-cats').offsetParent, labels: [...document.querySelectorAll('.good-c')].map(e => e.textContent) }));
    check(`[${W}] without script: all 6 products visible, no dead tab buttons, each card names its category`, njs.shown === 6 && !njs.tabs && njs.labels.includes('航空周边 / 海报') && njs.labels.includes('其他'), njs);
    await nj.close();
    // ----- product page breadcrumb -----
    await p.goto(base + '/shop/' + out.physical + '.html');
    const cr = await p.evaluate(() => [...document.querySelectorAll('.crumbs a')].map(a => a.textContent + '>' + a.getAttribute('href')));
    check(`[${W}] product breadcrumb 商城 / 航空周边 / 海报 with links to the filtered shop`, cr.join(' ') === `商城>../#shop 航空周边>../#shop/c/${air.id} 海报>../#shop/c/${poster.id}`, cr);
    await layout(p, 'product with breadcrumb', W);
    await shot(p, 'product-crumbs', W, false);
    fs.renameSync(SHOTS + 'product-crumbs-' + W + '.png', CAT_SHOTS + 'product-crumbs-' + W + '.png');
    await Promise.all([p.waitForNavigation(), p.click('.crumbs a:has-text("航空周边")')]);
    await sleep(900);
    const back = await p.evaluate(() => ({ hash: location.hash, shown: [...document.querySelectorAll('#shop .goods > .good')].filter(g => g.offsetParent).length, top: Math.round(document.getElementById('shop').getBoundingClientRect().top), vh: innerHeight }));
    check(`[${W}] breadcrumb 航空周边 opens the home shop filtered to it`, back.hash === '#shop/c/' + air.id && back.shown === 3 && back.top >= 0 && back.top < back.vh / 2, back);
    const bad = logs.filter(l => !/^HTTP 4\d\d .*\/admin\/\?r=cats\.(add|delete)$/.test(l));
    check(`[${W}] categories: no console errors, no external requests, no failed loads`, bad.length === 0, bad);
  } finally {
    await ctx.close();
    srv.kill();
    if (!process.env.KEEP) fs.rmSync(tmp, { recursive: true, force: true });
    else console.log('  kept ' + tmp);
  }
}

(async () => {
  fs.mkdirSync(SHOTS, { recursive: true });
  fs.mkdirSync(CAT_SHOTS, { recursive: true });
  const browser = await chromium.launch();
  try {
    for (const W of WANT) {
      if (!process.env.ONLY_CATS) await run(W, browser);
      await runCats(W, browser);
    }
  } finally {
    await browser.close();
  }
  console.log(failures ? `\nFAILED: ${failures} failing, ${passes} passing` : `\nALL PASS: ${passes} checks`);
  process.exit(failures ? 1 : 0);
})();
