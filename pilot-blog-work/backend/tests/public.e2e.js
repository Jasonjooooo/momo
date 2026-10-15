// Public pages with 0, 1, 3 and 8 published posts (SPEC T6 + T10 for post pages and the archive).
// Each scenario is built in temp dirs by fixture.php and served by php -S with the dev router.
// Usage: node pilot-blog-work/backend/tests/public.e2e.js [launch,pub0,pub1,pub3,pub8]
// 'launch' also runs the real subscribe flow in the browser (consent, honeypot, stored once).
const { chromium } = require('/opt/node-tools/node_modules/playwright');
const { execFileSync, spawn } = require('child_process');
const fs = require('fs');
const os = require('os');
const net = require('net');
const path = require('path');

const REPO = path.resolve(__dirname, '../../..');
const SCEN = (process.argv[2] || 'launch,pub0,pub1,pub3,pub8').split(',');
const SCR = '/tmp/claude-0/-home-user-momo/9d5abb52-bf47-5e03-b701-f78e1dba1457/scratchpad/';
const STAND = { portrait: SCR + 'mobile/standin-portrait-vp9.webm', land: SCR + 'standin-vp9.mp4' };
const VIEWPORTS = [
  ['1440', { viewport: { width: 1440, height: 900 } }],
  ['390', { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true }],
];
const sleep = ms => new Promise(r => setTimeout(r, ms));
const report = {};
let failures = 0;
const check = (R, name, cond, detail) => { R.checks.push((cond ? 'ok   ' : 'FAIL ') + name + (cond || detail === undefined ? '' : ' -- ' + JSON.stringify(detail))); if (!cond) failures++; };

function makeSite() {
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'pbe2e-'));
  fs.mkdirSync(tmp + '/pub/posts', { recursive: true });
  fs.mkdirSync(tmp + '/pub/uploads', { recursive: true });
  fs.mkdirSync(tmp + '/data');
  for (const d of ['assets', 'api', 'app', 'admin']) fs.symlinkSync(REPO + '/pilot-blog/' + d, tmp + '/pub/' + d);
  for (const f of ['index.html', '.htaccess', 'posts/.htaccess', 'uploads/.htaccess']) fs.copyFileSync(REPO + '/pilot-blog/' + f, tmp + '/pub/' + f);
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
function track(p, R, base) {
  R.logs = [];
  p.on('console', m => { if (m.type() === 'error' || m.type() === 'warning') R.logs.push(m.type() + ': ' + m.text()); });
  p.on('pageerror', e => R.logs.push('PAGEERROR: ' + e.message));
  p.on('requestfailed', r => { if (!r.url().startsWith('blob:')) R.logs.push('REQFAILED ' + r.url()); });
  p.on('request', r => { const u = r.url(); if (!u.startsWith(base) && !u.startsWith('blob:') && !u.startsWith('data:')) R.logs.push('EXTERNAL ' + u); });
  p.on('response', r => { if (r.status() >= 400 && !r.url().includes('chat.php')) R.logs.push('HTTP ' + r.status() + ' ' + r.url()); });
}
async function standins(ctx) {
  if (fs.existsSync(STAND.portrait)) await ctx.route('**/assets/hero-scrub-portrait.mp4', r => r.fulfill({ status: 200, contentType: 'video/mp4', body: fs.readFileSync(STAND.portrait) }));
  if (fs.existsSync(STAND.land)) await ctx.route('**/assets/hero-scrub.mp4', r => r.fulfill({ status: 200, contentType: 'video/mp4', body: fs.readFileSync(STAND.land) }));
}
const overflow = p => p.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }));

async function homeChecks(browser, base, fx, tag, opt) {
  const R = { checks: [] };
  const ctx = await browser.newContext(opt);
  await standins(ctx);
  const p = await ctx.newPage();
  track(p, R, base);
  await p.goto(base, { waitUntil: 'load' });
  await sleep(1500);
  const H = await p.evaluate(() => document.documentElement.scrollHeight);
  for (let y = 0; y < H; y += 500) { await p.evaluate(y => window.scrollTo({ top: y, behavior: 'instant' }), y); await sleep(40); }
  await p.evaluate(() => document.querySelector('.legs').scrollIntoView({ block: 'center', behavior: 'instant' }));
  for (let k = 0; k < 6; k++) { await p.mouse.wheel(0, 60); await sleep(70); }
  await sleep(1200);
  const st = await p.evaluate(() => ({
    legs: document.querySelectorAll('.legs > li').length,
    links: document.querySelectorAll('.post h3 a').length,
    crs: [...document.querySelectorAll('.legs [data-crs]')].map(e => e.textContent),
    dis: [...document.querySelectorAll('.legs [data-dis]')].map(e => e.textContent),
    route: document.getElementById('route-path').getAttribute('d'),
    count: document.querySelector('.plate-head .kicker:last-child').textContent,
    listEnd: !!document.querySelector('.list-end'),
    allLink: !!document.querySelector('a.tlink[href="posts/"]'),
    text: document.body.innerText,
    hud: document.getElementById('hud-to').textContent,
  }));
  check(R, 'legs on the home page', st.legs === fx.legs, st.legs);
  check(R, 'published titles are links', st.links === Math.min(fx.published.length, fx.legs), st.links);
  check(R, 'every leg course filled by chartBuild', st.crs.every(t => t !== '---°'), st.crs);
  check(R, 'every leg distance filled', st.dis.every(t => t !== '-- NM'), st.dis);
  check(R, 'route path drawn', st.route && st.route !== 'M0 0', st.route && st.route.slice(0, 20));
  check(R, 'draft title not on the page', !st.text.includes(fx.draft_title));
  check(R, 'list-end line only with teasers', st.listEnd === (fx.legs > fx.published.length));
  check(R, 'all-posts link only when something is published', st.allLink === (fx.published.length > 0));
  R.count = st.count;
  R.hud = st.hud;
  const ov = await overflow(p);
  check(R, 'no horizontal overflow', ov.sw <= ov.cw, ov);
  if (fx.published.length) {
    await p.evaluate(() => document.querySelector('.post h3 a').scrollIntoView({ block: 'center', behavior: 'instant' }));
    await sleep(600);
    await Promise.all([p.waitForNavigation({ timeout: 10000 }), p.click('.post h3 a')]);
    check(R, 'published title navigates to its post page', p.url().endsWith('/posts/' + fx.published[0].slug + '.html'), p.url());
  }
  check(R, 'no console errors, external or failed requests', R.logs.length === 0, R.logs);
  await ctx.close();
  return R;
}

async function subscribeFlow(browser, base, tmp, opt) {
  const R = { checks: [] };
  const ctx = await browser.newContext(opt);
  await standins(ctx);
  const p = await ctx.newPage();
  track(p, R, base);
  await p.goto(base, { waitUntil: 'load' });
  const served = await (await ctx.request.get(base)).text();
  check(R, 'published launch index.html equals the committed page', served === fs.readFileSync(REPO + '/pilot-blog/index.html', 'utf8'));
  await sleep(2000);
  await p.evaluate(() => document.querySelector('.sub-card').scrollIntoView({ block: 'center', behavior: 'instant' }));
  await sleep(1500);
  const hp = await p.evaluate(() => { const r = document.getElementById('sub-web').getBoundingClientRect(); return { x: r.right, w: r.width }; });
  check(R, 'honeypot field is off screen', hp.x <= 0, hp);
  const lab = await p.evaluate(() => Math.round(document.querySelector('.sub-consent').getBoundingClientRect().height));
  check(R, 'consent row is at least 44px tall', lab >= 44, lab);
  check(R, 'Mode A note is gone', await p.evaluate(() => !document.querySelector('.mode-a')));
  await p.fill('#sub-email', 'Reader@Example.com');
  await p.click('#sub-btn');
  await sleep(400);
  const e1 = await p.evaluate(() => [document.getElementById('sub-err').textContent, document.activeElement.id]);
  check(R, 'unchecked consent asks for it and focuses the box', e1[0] === '请先勾选同意，再提交。' && e1[1] === 'sub-consent', e1);
  await p.check('#sub-consent');
  check(R, 'checking the box clears the message', await p.evaluate(() => document.getElementById('sub-err').textContent === ''));
  await p.click('#sub-btn');
  await p.waitForSelector('#sub-done:not([hidden])', { timeout: 10000 }).catch(() => {});
  const done = await p.evaluate(() => document.getElementById('sub-done').textContent);
  check(R, 'success message names the address', done === '已订阅。下一篇日志写完，我会发到 Reader@Example.com。', done);
  const subs = JSON.parse(fs.readFileSync(tmp + '/data/subscribers.json', 'utf8')).items;
  check(R, 'address stored once, lowercased, with the consent text', subs.length === 1 && subs[0].email === 'reader@example.com' && subs[0].consent.startsWith('我同意本站保存这个邮箱'), subs);
  check(R, 'no console errors, external or failed requests', R.logs.length === 0, R.logs);
  await ctx.close();
  return R;
}

async function pageChecks(browser, base, url, tag, opt, expect) {
  const R = { checks: [] };
  const ctx = await browser.newContext(opt);
  const p = await ctx.newPage();
  track(p, R, base);
  await p.goto(base + url, { waitUntil: 'load' });
  await sleep(500);
  const st = await p.evaluate(() => ({
    h1: (document.querySelector('h1') || {}).textContent || '',
    scripts: document.querySelectorAll('script').length,
    imgs: [...document.images].map(i => [i.currentSrc.split('/').pop(), i.naturalWidth]),
    cards: document.querySelectorAll('.acard').length,
    tap: [...document.querySelectorAll('.pbar a, .pn, .back a, .subbox a')].map(a => Math.round(a.getBoundingClientRect().height)),
    bodyFont: getComputedStyle(document.querySelector('.pbody p') || document.body).fontSize,
    text: document.body.innerText,
  }));
  const ov = await overflow(p);
  check(R, 'no horizontal overflow', ov.sw <= ov.cw, ov);
  check(R, 'no scripts on the page', st.scripts === 0);
  check(R, 'images load', st.imgs.every(i => i[1] > 0), st.imgs);
  check(R, 'tap targets at least 40px', st.tap.every(h => h >= 40), st.tap);
  if (expect.title) check(R, 'h1 is the post title', st.h1 === expect.title, st.h1);
  if (expect.cards !== undefined) check(R, 'archive lists every published post', st.cards === expect.cards, st.cards);
  check(R, 'draft not listed', !st.text.includes(expect.draft));
  check(R, 'no console errors (CSP included), external or failed requests', R.logs.length === 0, R.logs);
  R.bodyFont = st.bodyFont;
  await ctx.close();
  return R;
}

// Visitor statistics in a real browser: the home beacon and the post/archive pixel under the post CSP.
// Headless Chromium says "HeadlessChrome" (a bot to hit.php), so this context uses a phone Safari user agent.
async function statsFlow(browser, base, tmp, fx, opt) {
  const R = { checks: [] };
  const ctx = await browser.newContext({ ...opt, userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1' });
  await standins(ctx);
  const p = await ctx.newPage();
  track(p, R, base);
  const hits = [];
  p.on('response', r => { if (r.url().includes('/api/hit.php')) hits.push([r.request().method(), r.status(), new URL(r.url()).search]); });
  await p.goto(base, { waitUntil: 'load', referer: 'https://www.baidu.com/s?wd=secret-search-words' });
  await sleep(1200);
  const slug = fx.published[0].slug;
  await p.goto(base + 'posts/' + slug + '.html', { waitUntil: 'load' });
  await sleep(500);
  const px = await p.evaluate(() => { const i = document.querySelector('img.hit-px'); return i ? { w: i.naturalWidth, shown: i.getBoundingClientRect().height, display: getComputedStyle(i).display } : null; });
  check(R, 'post page pixel loads under the post CSP and is not shown', px && px.w === 1 && px.shown === 0 && px.display === 'none', px);
  await p.goto(base + 'posts/', { waitUntil: 'load' });
  await sleep(500);
  check(R, 'beacon (POST 204) on home, pixel (GET 200) on post and archive', JSON.stringify(hits.map(h => h[0] + ' ' + h[1])) === JSON.stringify(['POST 204', 'GET 200', 'GET 200']), hits);
  check(R, 'beacon sends only the referrer hostname', hits.length > 0 && hits[0][2] === '?p=%2F&r=www.baidu.com', hits[0]);
  const dir = tmp + '/data/stats';
  const files = fs.existsSync(dir) ? fs.readdirSync(dir).filter(f => /^\d{4}-\d{2}-\d{2}\.json$/.test(f)) : [];
  const d = files.length === 1 ? JSON.parse(fs.readFileSync(dir + '/' + files[0], 'utf8')) : {};
  check(R, 'three views, one visitor, one phone', d.views === 3 && d.visitors === 1 && d.devices && d.devices.phone === 1, d);
  check(R, 'paths and referrer recorded', d.paths && d.paths['/'] === 1 && d.paths['/posts/' + slug + '.html'] === 1 && d.paths['/posts/'] === 1 && JSON.stringify(d.refs) === '{"baidu.com":1}', d);
  const raw = fs.readdirSync(dir).map(f => fs.readFileSync(dir + '/' + f, 'utf8')).join('');
  check(R, 'no search words, IP or user agent stored', !raw.includes('secret') && !raw.includes('127.0.0.1') && !raw.includes('iPhone'));
  check(R, 'no console errors (CSP included), external or failed requests', R.logs.length === 0, R.logs);
  await ctx.close();
  return R;
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  let port = 21000 + Math.floor(Math.random() * 3000);
  for (const sc of SCEN) {
    const tmp = makeSite();
    const env = { ...process.env, BLOG_PUBLIC_DIR: tmp + '/pub', BLOG_DATA_DIR: tmp + '/data' };
    const fx = JSON.parse(execFileSync('php', [__dirname + '/fixture.php', sc], { env }).toString());
    port++;
    const srv = await startServer(tmp, port);
    const base = `http://127.0.0.1:${port}/`;
    const S = report[sc] = { legs: fx.legs, published: fx.published.length };
    try {
      if (sc === 'launch') S['subscribe-390'] = await subscribeFlow(browser, base, tmp, VIEWPORTS[1][1]);
      if (sc === 'pub1') S['stats-390'] = await statsFlow(browser, base, tmp, fx, VIEWPORTS[1][1]);
      for (const [tag, opt] of VIEWPORTS) {
        S['home-' + tag] = await homeChecks(browser, base, fx, tag, opt);
        for (const pp of fx.published.slice(0, 3)) S[pp.slug + '-' + tag] = await pageChecks(browser, base, 'posts/' + pp.slug + '.html', tag, opt, { title: pp.title, draft: fx.draft_title });
        S['archive-' + tag] = await pageChecks(browser, base, 'posts/', tag, opt, { cards: fx.published.length, draft: fx.draft_title });
      }
    } finally {
      srv.kill();
      fs.rmSync(tmp, { recursive: true, force: true });
    }
  }
  await browser.close();
  for (const [sc, S] of Object.entries(report)) {
    console.log(`\n[${sc}] legs ${S.legs}, published ${S.published}`);
    for (const [k, R] of Object.entries(S)) if (R && R.checks) { console.log('  ' + k + (R.count ? ' (count: ' + R.count + ', hud: ' + R.hud + ')' : '')); R.checks.forEach(c => console.log('    ' + c)); }
  }
  console.log(failures ? `\nFAILED: ${failures}` : '\nALL PASS');
  process.exit(failures ? 1 : 0);
})();
