// 货源 in Chromium (SPEC §20 货源): the owner connects nothing by hand (fixture points at the mock supplier), opens 货源, sees
// the supplier's catalog, tries a price below cost, 上架 a card; a buyer orders it, pays (QR flow), the owner 确认收款,
// the card is bought from the mock and the buyer's lookup reveals it. At 390 and 1440. Screenshots in screens/supplier/.
// Usage: node pilot-blog-work/backend/tests/supply.e2e.js [390,1440]
const { chromium } = require('/opt/node-tools/node_modules/playwright');
const { execFileSync, spawn } = require('child_process');
const fs = require('fs');
const os = require('os');
const net = require('net');
const path = require('path');

const REPO = path.resolve(__dirname, '../../..');
const SHOTS = path.join(REPO, 'pilot-blog-work/screens/supplier/');
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
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'pbsup-'));
  for (const d of ['pub/posts', 'pub/uploads', 'pub/shop', 'data']) fs.mkdirSync(tmp + '/' + d, { recursive: true });
  for (const d of ['assets', 'api', 'app', 'admin']) fs.symlinkSync(REPO + '/pilot-blog/' + d, tmp + '/pub/' + d);
  for (const f of ['index.html', '.htaccess', 'posts/.htaccess', 'uploads/.htaccess', 'shop/.htaccess']) fs.copyFileSync(REPO + '/pilot-blog/' + f, tmp + '/pub/' + f);
  return tmp;
}
const portFree = port => new Promise(res => { const s = net.createServer().once('error', () => res(false)).once('listening', () => s.close(() => res(true))).listen(port, '127.0.0.1'); });
async function waitPort(port) { for (let i = 0; i < 50 && await portFree(port); i++) await sleep(100); }
function track(p, logs, base) {
  p.on('console', m => { if (m.type() === 'error' && !m.text().startsWith('Failed to load resource')) logs.push('console: ' + m.text()); });
  p.on('pageerror', e => logs.push('PAGEERROR: ' + e.message));
  p.on('dialog', d => { logs.push('DIALOG ' + d.message()); d.dismiss(); });
  p.on('request', r => { const u = r.url(); if (!u.startsWith(base) && !u.startsWith('blob:') && !u.startsWith('data:')) logs.push('EXTERNAL ' + u); });
  p.on('response', r => { if (r.status() >= 400 && !r.url().includes('chat.php') && !r.url().includes('hero-scrub')) logs.push('HTTP ' + r.status() + ' ' + r.url()); });
}
async function layout(p, name, W) {
  const r = await p.evaluate(() => {
    const out = { sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth, small: [], short: [] };
    document.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=file]), textarea, select').forEach(e => {
      if (e.offsetParent && parseFloat(getComputedStyle(e).fontSize) < 16) out.small.push(e.name || e.id);
    });
    document.querySelectorAll('main button, main .btn').forEach(e => {
      const b = e.getBoundingClientRect();
      if (e.offsetParent && b.height > 0 && b.height < 44) out.short.push((e.textContent || '').trim().slice(0, 12) + ':' + Math.round(b.height));
    });
    return out;
  });
  check(`[${W}] ${name}: no sideways scroll`, r.sw <= r.cw, r);
  check(`[${W}] ${name}: inputs >= 16px`, r.small.length === 0, r.small);
  check(`[${W}] ${name}: buttons >= 44px tall`, r.short.length === 0, r.short);
}
const shot = (p, name, W, full = true) => p.screenshot({ path: SHOTS + name + '-' + W + '.png', fullPage: full });
const money = s => Math.round(parseFloat(String(s).replace(/[^\d.]/g, '')) * 100);
async function sheetOk(p) {
  await p.waitForSelector('.sheet .sheet-btns .btn:not(.btn-ghost)', { timeout: 5000 });
  await Promise.all([p.waitForNavigation({ timeout: 30000 }), p.click('.sheet .sheet-btns .btn:not(.btn-ghost)')]);
}

async function run(W, browser) {
  console.log(`\n[supply ${W}]`);
  const tmp = makeSite();
  const state = tmp + '/mock-state.json';
  fs.writeFileSync(state, execFileSync('php', ['-r', 'require ' + JSON.stringify(__dirname + '/supply-mock-data.php') + '; echo json_encode(supply_mock_state(), JSON_UNESCAPED_UNICODE);']));
  const mport = 9800 + Math.floor(Math.random() * 150);
  const mock = spawn('php', ['-S', '127.0.0.1:' + mport, __dirname + '/supply-mock.php'], { env: { ...process.env, MOCK_STATE: state }, stdio: 'ignore' });
  await waitPort(mport);
  const env = { ...process.env, BLOG_PUBLIC_DIR: tmp + '/pub', BLOG_DATA_DIR: tmp + '/data', BLOG_SUPPLY_TEST_LOOPBACK: '1', MOCK_BASE: 'http://127.0.0.1:' + mport };
  delete env.ANTHROPIC_API_KEY;
  execFileSync('php', [__dirname + '/supply-fixture.php', PW], { env });
  const port = 9400 + Math.floor(Math.random() * 380);
  const srv = spawn('php', ['-S', '127.0.0.1:' + port, '-t', tmp + '/pub', REPO + '/pilot-blog/app/dev/router.php'], { env, stdio: 'ignore' });
  await waitPort(port);
  const base = 'http://127.0.0.1:' + port;
  const logs = [];
  const ctx = await browser.newContext({ ...VIEWPORTS[W], locale: 'zh-CN' });
  await ctx.route('**/assets/hero-scrub*.mp4', r => r.fulfill({ status: 404, body: '' }));
  const mockBooks = () => JSON.parse(fs.readFileSync(state));
  try {
    // ----- owner: 货源 page -----
    const a = await ctx.newPage();
    track(a, logs, base);
    await a.goto(base + '/admin/?r=login');
    await a.fill('#password', PW);
    await Promise.all([a.waitForNavigation(), a.click('main button[type=submit]')]);
    await a.goto(base + '/admin/?r=supply');
    const sp = await a.evaluate(() => ({
      h1: document.querySelector('h1').textContent,
      chip: document.querySelector('#s-sw + p .chip').textContent,
      rows: [...document.querySelectorAll('.sup-row .prow-t')].map(e => e.textContent),
      menu: [...document.querySelectorAll('.menu-list a')].map(e => e.textContent.trim()),
      keyLabel: document.querySelector('label[for=s-key]').textContent,
      keyVal: document.querySelector('#s-key').value,
      html: document.documentElement.outerHTML,
      xssImg: document.querySelectorAll('img[src="x"]').length,
    }));
    check(`[${W}] 货源 in the menu, page open, switch 已打开`, sp.menu.includes('货源') && sp.h1 === '货源' && sp.chip === '已打开', sp.chip);
    check(`[${W}] the supplier's products are listed (names escaped, no injected image)`, sp.rows.includes('王者荣耀 60 点券（自动发卡）') && sp.rows.some(r => r.includes('alert(8)点卡')) && sp.xssImg === 0, sp.rows);
    check(`[${W}] key: only the last 4 shown, the field is empty, the key is nowhere in the page`, sp.keyLabel.includes('Z4w'.slice(-3)) && sp.keyVal === '' && !sp.html.includes('mK9#xQ2'), sp.keyLabel);
    await layout(a, 'admin 货源', W);
    await shot(a, 'admin-supply', W);
    // 测试连接
    await Promise.all([a.waitForNavigation(), a.click('form[action*="supply.test"] button')]);
    check(`[${W}] 测试连接: 连接正常`, (await a.textContent('.flashes')).includes('连接正常'), await a.textContent('.flashes'));
    // an unsupported one
    await a.goto(base + '/admin/?r=supply.item&code=WIDGET01');
    check(`[${W}] a product that needs extra fields is 暂不支持 with the reason, no 上架 form`, (await a.textContent('main')).includes('暂不支持') && (await a.textContent('main')).includes('游戏账号') && (await a.locator('form[action*="supply.import"]').count()) === 0);
    // import WZRY60
    await a.goto(base + '/admin/?r=supply');
    await Promise.all([a.waitForNavigation(), a.click('a[href*="code=WZRY60"]')]);
    const item = await a.evaluate(() => ({ h1: document.querySelector('h1').textContent, info: document.querySelector('#i-info').parentElement.textContent, price: document.querySelector('#i-price').value, body: document.querySelector('#i-body').value }));
    check(`[${W}] import page: supplier info with cost ¥5.80, default price above cost, plain-text description`, item.info.includes('¥5.80') && money(item.price) >= 580 && item.body.includes('官方 60 点券兑换码。') && !item.body.includes('<'), item);
    await a.fill('#i-name', '王者荣耀 60 点券');
    await a.fill('#i-price', '5');
    await Promise.all([a.waitForNavigation(), a.click('form[action*="supply.import"] button[type=submit]')]);
    check(`[${W}] a price below cost is refused with a plain message`, (await a.textContent('.flashes')).includes('低于成本') && (await a.locator('.field.has-err #i-price').count()) === 1, await a.textContent('.flashes'));
    await a.fill('#i-price', '6.80');
    await layout(a, 'admin import', W);
    await shot(a, 'admin-import', W);
    await Promise.all([a.waitForNavigation(), a.click('form[action*="supply.import"] button[type=submit]')]);
    const ed = await a.evaluate(() => ({ url: location.href, flash: document.querySelector('.flashes').textContent, box: (document.getElementById('g-supply') || {}).parentElement ? document.getElementById('g-supply').parentElement.textContent : '' }));
    check(`[${W}] 上架 lands on the product editor with the 货源 box: cost, price, margin`, ed.url.includes('r=shop.edit') && ed.flash.includes('已上架') && ed.box.includes('¥5.80') && ed.box.includes('¥6.80') && ed.box.includes('¥1.00'), ed);
    await layout(a, 'admin supply product', W);
    await shot(a, 'admin-supply-product', W);

    // ----- buyer -----
    const p = await ctx.newPage();
    track(p, logs, base);
    await p.goto(base + '/', { waitUntil: 'load' });
    const good = await p.evaluate(() => [...document.querySelectorAll('#shop .goods > li')].map(li => ({ n: li.querySelector('.good-n').textContent, tag: li.querySelector('.good-tag').textContent, href: li.querySelector('a').getAttribute('href') })));
    const g = good.find(x => x.n === '王者荣耀 60 点券');
    check(`[${W}] home 商城 shows the card with 自动发卡`, g && g.tag === '自动发卡', good);
    await p.goto(base + '/' + g.href);
    const prod = await p.evaluate(() => ({ text: document.body.textContent, html: document.documentElement.outerHTML, price: document.querySelector('.prod-price').textContent }));
    check(`[${W}] product page: ¥6.80, cards sent automatically after the owner confirms payment, no supplier code or cost`, prod.price.includes('6.80') && prod.text.includes('站长确认收款后系统自动发卡') && !prod.html.includes('WZRY60') && !prod.html.includes('5.80') && !prod.html.includes('（自动发卡）'), prod.price);
    await Promise.all([p.waitForNavigation(), p.click('.buy-btn')]);
    check(`[${W}] order form asks for an email only`, (await p.locator('#f-email').count()) === 1 && (await p.locator('#f-addr').count()) === 0);
    await p.fill('#f-qty', '2');
    await p.fill('#f-email', 'Gamer.One@Example.com');
    await p.check('#f-consent');
    await sleep(2100);
    await Promise.all([p.waitForNavigation(), p.click('form.sform button[type=submit]')]);
    const no = await p.textContent('.pay-no b');
    const fen = money(await p.textContent('.pay-amt'));
    check(`[${W}] pay page: 2 × ¥6.80 + unique cents`, fen > 1360 && fen < 1360 + 200, fen);
    check(`[${W}] nothing bought before the owner confirms`, Object.keys(mockBooks().trades || {}).length === 0);
    await sleep(2100);
    await Promise.all([p.waitForNavigation(), p.click('.paid-form button[type=submit]')]);

    // ----- owner confirms → bought -----
    await a.goto(base + '/admin/?r=order&id=' + no);
    await a.click('form:has(input[value=confirm]) button');
    await sheetOk(a);
    const conf = await a.evaluate(() => ({ flash: document.querySelector('.flashes').textContent, sup: (document.getElementById('o-sup') || {}).parentElement ? document.getElementById('o-sup').parentElement.textContent : '', amt: document.querySelector('.card-amt').textContent }));
    check(`[${W}] 确认收款 buys the card at once: 已进货, order 已完成, supplier order number and margin shown`, conf.flash.includes('已进货') && conf.amt.includes('已完成') && conf.sup.includes('供货平台订单号') && conf.sup.includes('¥11.60'), conf);
    check(`[${W}] the mock charged once for 2 cards`, Object.keys(mockBooks().trades).length === 1 && mockBooks().balance === 100000 - 1160, mockBooks().balance);
    await layout(a, 'admin order', W);
    await shot(a, 'admin-order', W);

    // ----- buyer lookup: the card -----
    await p.goto(base + '/api/shop.php?a=lookup&o=' + no);
    await sleep(2100);
    await p.fill('#f-v', 'gamer.one');
    await Promise.all([p.waitForNavigation(), p.click('form.sform button[type=submit]')]);
    const lk = await p.evaluate(() => ({ tag: document.querySelector('.strip-tag').textContent, card: [...document.querySelectorAll('.card-secret p')].map(e => e.textContent), steps: [...document.querySelectorAll('.tl li')].map(l => l.className + ':' + l.querySelector('.tl-l').textContent), html: document.documentElement.outerHTML }));
    check(`[${W}] lookup reveals both cards`, lk.tag === '已完成' && lk.card.length === 2 && lk.card.every(c => c.startsWith('CARD-')), lk);
    check(`[${W}] lookup timeline ends with 已发卡，可以查看卡密 and shows no supplier details`, lk.steps[lk.steps.length - 1].includes('已发卡，可以查看卡密') && !lk.html.includes('WZRY60') && !/PB\d{8}/.test(lk.html), lk.steps);
    await layout(p, 'lookup card', W);
    await shot(p, 'lookup-card', W);

    const bad = logs.filter(l => !/^HTTP 400 .*admin\/\?r=supply\.import$/.test(l));
    check(`[${W}] no console errors, dialogs, external requests or failed loads`, bad.length === 0, bad);
  } finally {
    await ctx.close();
    srv.kill();
    mock.kill();
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
