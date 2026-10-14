// B-chart mockup: screenshots in scroll order + interaction, overflow, console, reduced-motion and keyboard checks.
// Usage: PORT=8977 node test.js [desk|phone|both] [noshots]
const { chromium } = require('/opt/node-tools/node_modules/playwright');
const fs = require('fs');
const PORT = process.env.PORT || 8977;
const URL = `http://127.0.0.1:${PORT}/`;
const SCR = '/tmp/claude-0/-home-user-momo/9d5abb52-bf47-5e03-b701-f78e1dba1457/scratchpad/';
const PSTAND = fs.readFileSync(SCR + 'mobile/standin-portrait-vp9.webm');
const DSTAND = fs.readFileSync(SCR + 'standin-vp9.mp4');
const OUT = process.env.OUT || '/home/user/momo/pilot-blog-work/screens/mockups/B-chart/';
fs.mkdirSync(OUT, { recursive: true });
const sleep = ms => new Promise(r => setTimeout(r, ms));
const mode = process.argv[2] || 'both';
const SHOTS = process.argv[3] !== 'noshots';
const report = {};

async function standins(ctx) {
  await ctx.route('**/assets/hero-scrub-portrait.mp4', r => r.fulfill({ status: 200, contentType: 'video/mp4', body: PSTAND }));
  await ctx.route('**/assets/hero-scrub.mp4', r => r.fulfill({ status: 200, contentType: 'video/mp4', body: DSTAND }));
}
function track(p, R) {
  R.logs = [];
  p.on('console', m => { if (m.type() === 'error' || m.type() === 'warning') R.logs.push(m.type() + ': ' + m.text()); });
  p.on('pageerror', e => R.logs.push('PAGEERROR: ' + e.message));
  p.on('requestfailed', r => { if (!r.url().startsWith('blob:')) R.logs.push('REQFAILED ' + r.url()); });
  p.on('request', r => { const u = r.url(); if (!u.startsWith(URL) && !u.startsWith('blob:') && !u.startsWith('data:')) R.logs.push('EXTERNAL ' + u); });
  p.on('response', r => { if (r.status() >= 400 && !r.url().includes('chat.php')) R.logs.push('HTTP ' + r.status() + ' ' + r.url()); });
}
const scrollTo = (p, y) => p.evaluate(y => window.scrollTo({ top: Math.max(0, Math.round(y)), behavior: 'instant' }), y);
// put an element's top at `off` px from the viewport top
async function at(p, sel, off) {
  const y = await p.evaluate(([s, o]) => { const e = document.querySelector(s); return e.getBoundingClientRect().top + scrollY - o; }, [sel, off]);
  await scrollTo(p, y);
  await sleep(2600);
}
async function settleHero(p) {
  await p.evaluate(() => { const h = document.querySelector('.hero'), st = document.getElementById('stage'); const top = h.getBoundingClientRect().top + scrollY; window.scrollTo({ top: Math.round(top + (h.offsetHeight - st.offsetHeight)), behavior: 'instant' }); });
  await sleep(800);
  for (let i = 0; i < 60; i++) {
    const a = await p.evaluate(() => { const v = document.getElementById('hero-video'); return v.seeking + ':' + v.currentTime.toFixed(3) + ':' + v.readyState; });
    await sleep(150);
    const b = await p.evaluate(() => { const v = document.getElementById('hero-video'); return v.seeking + ':' + v.currentTime.toFixed(3) + ':' + v.readyState; });
    if (a === b && !a.startsWith('true') && a.endsWith(':4')) break;
  }
  await sleep(1200);
}
const overflow = p => p.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth, bw: document.body.scrollWidth }));

async function run(browser, tag, opt) {
  const R = report[tag] = {};
  const ctx = await browser.newContext(opt);
  await standins(ctx);
  const p = await ctx.newPage();
  track(p, R);
  await p.goto(URL, { waitUntil: 'load' });
  await sleep(2500);
  const W = opt.viewport.width;
  let n = 0;
  const shot = async (name, full) => { if (!SHOTS) return; n++; await p.screenshot({ path: OUT + String(n).padStart(2, '0') + '-' + name + '-' + W + '.png', fullPage: !!full }); };

  await settleHero(p);
  R.heroVideo = await p.evaluate(() => { const st = document.getElementById('stage'); return st.className + ' t=' + document.getElementById('hero-video').currentTime.toFixed(2); });
  await shot('hero-settle');

  // chart head unfolds, route starts
  await at(p, '.ch-head', W > 900 ? 150 : 110); await shot('chart-head');
  await at(p, '#about', W > 900 ? -40 : 0); await shot('01-about-pilot');
  if (W <= 900) { await at(p, '.about-fig', 120); await shot('01-about-inset'); }
  await at(p, '.hold-fig', W > 900 ? 380 : 260); await shot('leg-to-logbk');
  await at(p, '.legs li:nth-child(2)', W > 900 ? 200 : 140); await shot('02-log-legs');
  // the active-leg readout wakes while the page moves: catch it mid-scroll
  for (let k = 0; k < 6; k++) { await p.mouse.wheel(0, 60); await sleep(70); }
  await sleep(250);
  R.hud = await p.evaluate(() => [document.getElementById('hud').className, document.getElementById('hud').textContent]);
  await shot('02-log-hud-scrolling');
  await at(p, '.legend', W > 900 ? 160 : 150); await shot('03-type-legend');
  await at(p, '.facts', W > 900 ? 220 : 120); await shot('03-type-facts');
  await at(p, '.comm', W > 900 ? 120 : 90); await shot('04-ai-comm');
  await at(p, '.radio-head', W > 900 ? 120 : 90);
  // tune A5 (click the tab), then the chat chip
  await p.click('#tab-aviation');
  await sleep(900);
  R.tuned = await p.evaluate(() => [document.getElementById('f-act').textContent, document.getElementById('f-sby').textContent, document.querySelector('#tab-aviation').getAttribute('aria-selected'), document.getElementById('panel-aviation').hidden]);
  await at(p, '.radio-head', W > 900 ? 120 : 90); await shot('04-ai-tuned-a5');
  await p.click('#f-swap'); await sleep(500);
  R.swapped = await p.evaluate(() => document.getElementById('f-act').textContent);
  await p.click('#tab-chat'); await sleep(400);
  await at(p, '.chat', W > 900 ? 90 : 70);
  await p.click('#chips button:first-child');
  await p.waitForFunction(() => document.querySelectorAll('#chat-log .msg').length >= 3, null, { timeout: 15000 }).catch(() => {});
  await sleep(600);
  R.chat = await p.evaluate(() => ({ lamp: document.getElementById('lamp').dataset.state, last: [...document.querySelectorAll('#chat-log .msg')].pop().textContent }));
  await shot('04-ai-chat-inop');
  await at(p, '.checklist', W > 900 ? 110 : 90); await shot('05-faq-notam');
  await at(p, '#fly', W > 900 ? 120 : 80); await shot('approach-plate');
  // hold to descend: mid-hold, released early (go-around), then complete
  const hb = await p.locator('#hold').boundingBox();
  await p.mouse.move(hb.x + hb.width / 2, hb.y + hb.height / 2);
  await p.mouse.down(); await sleep(1300);
  await shot('approach-holding');
  await p.mouse.up(); await sleep(250);
  R.goAround = await p.evaluate(() => document.getElementById('fly-status').textContent);
  await sleep(2500);
  await p.mouse.down(); await sleep(3300); await p.mouse.up(); await sleep(900);
  R.landed = await p.evaluate(() => [document.getElementById('fly').className, document.getElementById('subscribe').className, document.getElementById('fly-status').textContent, document.getElementById('hold-label').textContent]);
  await shot('approach-landed');
  await at(p, '.rwy-zone', W > 900 ? -60 : -20); await shot('turn-onto-final');
  await at(p, '.sub-card', W > 900 ? 150 : 80); await shot('06-runway-subscribe');
  await p.click('#sub-btn'); await sleep(400);
  R.subEmpty = await p.evaluate(() => document.getElementById('sub-err').textContent);
  await p.fill('#sub-email', 'pilot@example.com'); await p.click('#sub-btn'); await sleep(500);
  R.subDone = await p.evaluate(() => document.getElementById('sub-done').textContent);
  await at(p, '.sub-card', W > 900 ? 150 : 80); await shot('06-subscribe-modeA');
  await p.evaluate(() => window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'instant' })); await sleep(1200);
  R.ownEnd = await p.evaluate(() => document.getElementById('ownship').style.transform);
  await shot('footer');
  R.overflow = await overflow(p);

  // keyboard: tab into the frequency bar and tune with arrow keys; focus ring visible
  await at(p, '.radio-head', W > 900 ? 120 : 90);
  await p.focus('#tab-chat');
  await p.keyboard.press('ArrowRight'); await p.keyboard.press('ArrowRight'); await sleep(700);
  R.keyTabs = await p.evaluate(() => [document.activeElement.id, document.getElementById('f-act').textContent]);
  await shot('kbd-focus-tab');
  await p.focus('#hold');
  await p.keyboard.down('Space'); await sleep(3200); await p.keyboard.up('Space'); await sleep(300);
  R.keyHold = await p.evaluate(() => document.getElementById('fly-status').textContent);

  // reduced motion, flipped live: route drawn to the runway, hold complete, HUD hidden; then back
  await p.emulateMedia({ reducedMotion: 'reduce' }); await sleep(600);
  await at(p, '#about', 0);
  R.rmOn = await p.evaluate(() => ({ clip: document.getElementById('route-clip').style.transform, landed: document.getElementById('subscribe').classList.contains('landed'), hud: getComputedStyle(document.getElementById('hud')).display, labels: [...document.querySelectorAll('.leg-lbl')].map(l => l.classList.contains('on')).join(','), own: document.getElementById('ownship').style.transform }));
  await shot('reduced-motion-01');
  await at(p, '.legend', 120); await shot('reduced-motion-03');
  await p.emulateMedia({ reducedMotion: 'no-preference' }); await sleep(800);
  R.rmOff = await p.evaluate(() => ({ clip: document.getElementById('route-clip').style.transform, landed: document.getElementById('subscribe').classList.contains('landed'), status: document.getElementById('fly-status').textContent }));
  R.overflow2 = await overflow(p);
  await ctx.close();
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  if (mode !== 'phone') await run(browser, 'desk1440', { viewport: { width: 1440, height: 900 } });
  if (mode !== 'desk') await run(browser, 'phone390', { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
  // overflow + console sweep at the other two sizes, plus a reduced-motion cold load
  for (const [tag, opt] of [['desk1920', { viewport: { width: 1920, height: 1080 } }], ['phone360', { viewport: { width: 360, height: 740 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true }], ['rm-cold-1440', { viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' }]]) {
    if (mode === 'desk' && tag.startsWith('phone')) continue;
    if (mode === 'phone' && !tag.startsWith('phone')) continue;
    const R = report[tag] = {};
    const ctx = await browser.newContext(opt); await standins(ctx);
    const p = await ctx.newPage(); track(p, R);
    await p.goto(URL, { waitUntil: 'load' }); await sleep(1500);
    const H = await p.evaluate(() => document.documentElement.scrollHeight);
    for (let y = 0; y < H; y += 600) { await scrollTo(p, y); await sleep(60); }
    await sleep(800);
    R.overflow = await overflow(p);
    R.videoReq = R.logs.length;
    if (tag === 'rm-cold-1440') R.rm = await p.evaluate(() => ({ stage: getComputedStyle(document.getElementById('stage')).display, src: document.getElementById('hero-video').getAttribute('src'), landed: document.getElementById('subscribe').classList.contains('landed') }));
    await ctx.close();
  }
  await browser.close();
  console.log(JSON.stringify(report, null, 1));
})();
