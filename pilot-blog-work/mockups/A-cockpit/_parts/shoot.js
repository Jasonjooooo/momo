// A-cockpit self-test and screenshots.  PORT=8961 node shoot.js [desk] [phone] [extra]
// Chromium here cannot decode H.264: hero requests get the VP9 stand-ins of the same footage (test only).
const { chromium } = require('/opt/node-tools/node_modules/playwright');
const fs = require('fs');
const PORT = process.env.PORT || 8961;
const URL = `http://127.0.0.1:${PORT}/`;
const SCR = '/tmp/claude-0/-home-user-momo/9d5abb52-bf47-5e03-b701-f78e1dba1457/scratchpad/';
const PSTAND = fs.readFileSync(SCR + 'mobile/standin-portrait-vp9.webm');
const DSTAND = fs.readFileSync(SCR + 'standin-vp9.mp4');
const OUT = process.env.OUT || '/home/user/momo/pilot-blog-work/screens/mockups/A-cockpit/';
fs.mkdirSync(OUT, { recursive: true });
const RUN = new Set(process.argv.slice(2).length ? process.argv.slice(2) : ['desk', 'phone', 'extra']);
const sleep = ms => new Promise(r => setTimeout(r, ms));
const problems = [];
const note = s => { console.log(s); };
const fail = s => { problems.push(s); console.log('FAIL ' + s); };

function track(p, name) {
  p.on('console', m => { if (m.type() === 'error' || m.type() === 'warning') { if (!/503/.test(m.text())) fail(name + ' console ' + m.type() + ': ' + m.text()); } });
  p.on('pageerror', e => fail(name + ' PAGEERROR ' + e.message));
  p.on('request', r => { const u = r.url(); if (!u.startsWith(URL) && !u.startsWith('blob:') && !u.startsWith('data:')) fail(name + ' EXTERNAL ' + u); });
}
async function standins(ctx) {
  await ctx.route('**/assets/hero-scrub-portrait.mp4', r => r.fulfill({ status: 200, contentType: 'video/mp4', body: PSTAND }));
  await ctx.route('**/assets/hero-scrub.mp4', r => r.fulfill({ status: 200, contentType: 'video/mp4', body: DSTAND }));
}
async function noHScroll(p, tag) {
  const r = await p.evaluate(() => ({ sw: document.documentElement.scrollWidth, bw: document.body.scrollWidth, iw: innerWidth, sx: scrollX }));
  if (r.sw > r.iw || r.bw > r.iw || r.sx) fail(tag + ' horizontal overflow ' + JSON.stringify(r));
}
async function heroTo(p, s) {
  await p.evaluate(s => { const h = document.querySelector('.hero'), st = document.getElementById('stage'); const top = h.getBoundingClientRect().top + scrollY; window.scrollTo({ top: Math.round(top + s * (h.offsetHeight - st.offsetHeight)), behavior: 'instant' }); }, s);
}
async function settle(p) {
  await sleep(700);
  for (let i = 0; i < 50; i++) {
    const a = await p.evaluate(() => { const v = document.getElementById('hero-video'); return v.seeking + ':' + v.currentTime + ':' + scrollY; });
    await sleep(120);
    const b = await p.evaluate(() => { const v = document.getElementById('hero-video'); return v.seeking + ':' + v.currentTime + ':' + scrollY; });
    if (a === b && !a.startsWith('true')) break;
  }
  await sleep(300);
}
async function shot(p, name, tag) { await noHScroll(p, tag + ' ' + name); await p.screenshot({ path: OUT + name + '.png' }); note('shot ' + name); }
const state = p => p.evaluate(() => {
  const ck = document.getElementById('cockpit');
  const on = [...document.querySelectorAll('.pg')].findIndex(x => x.classList.contains('on'));
  const cur = [...document.querySelectorAll('.sk')].findIndex(x => x.getAttribute('aria-current') === 'true');
  return { mode: ck.className, on, cur, y: Math.round(scrollY) };
});
// desktop: bring an element fully into the glass by scrolling the document (content moves 1:1 inside the page)
async function reveal(p, sel, margin = 24) {
  for (let i = 0; i < 12; i++) {
    const d = await p.evaluate(([sel, m]) => { const el = document.querySelector(sel), pg = el.closest('.pg'); const v = pg.querySelector('.pg-view').getBoundingClientRect(), r = el.getBoundingClientRect(); return Math.round(r.bottom - v.bottom + m); }, [sel, margin]);
    if (d <= 0) return;
    await p.evaluate(d => window.scrollBy({ top: d, behavior: 'instant' }), d);
    await sleep(150);
  }
}
async function slow(cdp, rate) { await cdp.send('Animation.setPlaybackRate', { playbackRate: rate }); }

async function desk(browser, W = 1440, H = 900, prefix = '') {
  const tag = 'desk' + W;
  const ctx = await browser.newContext({ viewport: { width: W, height: H } });
  await standins(ctx);
  const p = await ctx.newPage(); track(p, tag);
  const cdp = await ctx.newCDPSession(p); await cdp.send('Animation.enable');
  await p.goto(URL, { waitUntil: 'load' });
  await sleep(2500);
  const N = (n, s) => prefix + String(n).padStart(2, '0') + '-desk-' + s;
  await heroTo(p, 1); await settle(p);
  await shot(p, N(1, 'hero-settle'), tag);
  if (prefix) { // 1920 pass: a few pages only
    for (const [k, nm] of [[0, 'pilot'], [1, 'fpl'], [2, 'eng'], [3, 'dlk'], [5, 'logon']]) {
      if (k === 0) { await p.evaluate(() => { const t = document.getElementById('cockpit').getBoundingClientRect().top + scrollY - 64; window.scrollTo({ top: t, behavior: 'instant' }); }); await sleep(1400); }
      else { await p.click('.sk[data-pg="' + k + '"]'); await sleep(1300); }
      await shot(p, N(k + 2, nm), tag);
    }
    await ctx.close(); return;
  }
  // the display rises out of the hero
  await p.evaluate(() => { const t = document.getElementById('cockpit').getBoundingClientRect().top + scrollY; window.scrollTo({ top: t - innerHeight * 0.70, behavior: 'instant' }); });
  await sleep(500);
  await shot(p, N(2, 'display-rises'), tag);
  // power-up sweep, slowed 10x for the capture
  await slow(cdp, 0.1);
  await p.evaluate(() => { const t = document.getElementById('cockpit').getBoundingClientRect().top + scrollY - 64; window.scrollTo({ top: t, behavior: 'instant' }); });
  await sleep(2600);
  await shot(p, N(3, 'power-up-sweep'), tag);
  await slow(cdp, 1); await sleep(800);
  await shot(p, N(4, 'pilot'), tag);
  note(JSON.stringify(await state(p)));
  // page swap by scrolling (no hijack): wheel down through the PILOT range until FPL wipes in, captured mid-wipe
  await slow(cdp, 0.1);
  for (let i = 0; i < 40; i++) { await p.mouse.wheel(0, 120); await sleep(60); if ((await state(p)).on === 1) break; }
  await sleep(1300);
  await shot(p, N(5, 'scanline-wipe-to-fpl'), tag);
  await slow(cdp, 1); await sleep(600);
  const s1 = await state(p); if (s1.on !== 1 || s1.cur !== 1) fail(tag + ' wheel did not reach FPL ' + JSON.stringify(s1));
  await p.click('.sk[data-pg="1"]'); await sleep(900);
  await shot(p, N(6, 'fpl'), tag);
  await p.click('#leg-b2'); await sleep(900);
  await p.hover('#leg-b3'); await sleep(300);
  await shot(p, N(7, 'fpl-leg3-selected'), tag);
  const sel = await p.evaluate(() => ({ exp: document.getElementById('leg-b2').getAttribute('aria-expanded'), hidden0: document.getElementById('leg-x0').hidden, crs: getComputedStyle(document.getElementById('map-leg')).getPropertyValue('--crs') }));
  if (sel.exp !== 'true' || !sel.hidden0) fail(tag + ' leg select ' + JSON.stringify(sel));
  await reveal(p, '#fly');
  await sleep(300);
  await shot(p, N(8, 'fpl-vsd'), tag);
  const hb = await p.$('#hold'); const bb = await hb.boundingBox();
  await p.mouse.move(bb.x + bb.width / 2, bb.y + bb.height / 2); await p.mouse.down();
  await sleep(1400);
  await shot(p, N(9, 'fpl-holding'), tag);
  await sleep(500); await p.mouse.up(); await sleep(500);
  const ga = await p.evaluate(() => document.getElementById('fly-status').textContent);
  if (!/复飞/.test(ga)) fail(tag + ' early release did not go around: ' + ga);
  await shot(p, N(10, 'fpl-go-around'), tag);
  await sleep(2600);
  await p.mouse.down(); await sleep(3200); await p.mouse.up(); await sleep(1500);
  const done = await p.evaluate(() => ({ s: document.getElementById('fly-status').textContent, landed: document.getElementById('log').classList.contains('landed') }));
  if (!done.landed) fail(tag + ' hold did not land ' + JSON.stringify(done));
  await shot(p, N(11, 'fpl-minimums'), tag);
  await p.click('.sk[data-pg="2"]'); await sleep(2200);
  await shot(p, N(12, 'eng'), tag);
  await reveal(p, '.outline .callouts'); await sleep(2400);
  await shot(p, N(13, 'eng-synoptic'), tag);
  await p.click('.sk[data-pg="3"]'); await sleep(1100);
  await shot(p, N(14, 'datalink'), tag);
  await p.click('#tab-aviation'); await sleep(400);
  await p.hover('#shelf-aviation .tool:nth-child(2)'); await sleep(400);
  await shot(p, N(15, 'datalink-tab-a5'), tag);
  await reveal(p, '#chat-form', 60);
  await p.fill('#chat-in', 'PC-12 的失速速度是多少？');
  await p.press('#chat-in', 'Enter');
  await p.waitForFunction(() => document.querySelectorAll('#chat-log .msg.assistant:not(.sr)').length >= 2, null, { timeout: 15000 });
  await sleep(300);
  await shot(p, N(16, 'cpdlc-inop-uplink'), tag);
  const chat = await p.evaluate(() => ({ lamp: document.getElementById('lamp').dataset.state, last: document.querySelector('#chat-log .msg.assistant:last-child').textContent }));
  if (chat.lamp !== 'inop' || !/INOP/.test(chat.last)) fail(tag + ' chat state ' + JSON.stringify(chat));
  note('chat ' + JSON.stringify(chat));
  await p.click('.sk[data-pg="4"]'); await sleep(2100);
  await shot(p, N(17, 'checklist-reading'), tag);
  await reveal(p, '.cl-done'); await sleep(5600);
  const cl = await p.evaluate(() => ({ done: document.getElementById('checklist').classList.contains('done'), n: document.getElementById('cl-n').textContent }));
  if (!cl.done) fail(tag + ' checklist not complete ' + JSON.stringify(cl));
  await shot(p, N(18, 'checklist-complete'), tag);
  await p.click('.sk[data-pg="5"]'); await sleep(1300);
  await shot(p, N(19, 'logon'), tag);
  await p.fill('#sub-email', 'pilot@'); await p.click('#sub-btn'); await sleep(400);
  await shot(p, N(20, 'logon-format-error'), tag);
  await p.fill('#sub-email', 'pilot@example.com'); await p.click('#sub-btn'); await sleep(500);
  const sub = await p.evaluate(() => ({ hidden: document.getElementById('sub-form').hidden, txt: document.getElementById('sub-done').textContent, focus: document.activeElement.id }));
  if (!sub.hidden || !/没有保存/.test(sub.txt)) fail(tag + ' mode A ' + JSON.stringify(sub));
  await shot(p, N(21, 'logon-inop-mode-a'), tag);
  // keyboard: arrows walk the soft keys, Enter jumps, Tab lands in the active page
  await p.focus('.sk[data-pg="5"]'); await p.keyboard.press('ArrowLeft'); await p.keyboard.press('ArrowLeft'); await p.keyboard.press('Enter'); await sleep(900);
  const kb = await state(p);
  if (kb.on !== 3) fail(tag + ' keyboard soft key ' + JSON.stringify(kb));
  await p.keyboard.press('Tab'); await sleep(250);
  const kf = await p.evaluate(() => { const a = document.activeElement; return { tag: a.tagName, cls: a.className, inPage: !!a.closest('#ai'), inert: [...document.querySelectorAll('.pg')].map(x => x.inert) }; });
  if (!kf.inPage) fail(tag + ' Tab after soft key did not enter the page ' + JSON.stringify(kf));
  await p.keyboard.press('ArrowRight'); await sleep(300);
  await shot(p, N(22, 'keyboard-focus-tabs'), tag);
  // tab deep into the DLK page: the focused control must sit inside the glass
  for (let i = 0; i < 8; i++) { await p.keyboard.press("Tab"); await sleep(60); }
  await sleep(300);
  const vis = await p.evaluate(() => { const a = document.activeElement, pg = a.closest('.pg'); if (!pg) return { out: a.tagName + '.' + a.className }; const v = pg.querySelector('.pg-view').getBoundingClientRect(), r = a.getBoundingClientRect(); return { el: a.tagName + '.' + a.className + ' ' + (a.textContent || '').slice(0, 12), top: Math.round(r.top), bottom: Math.round(r.bottom), vt: Math.round(v.top), vb: Math.round(v.bottom) }; });
  note('deep tab ' + JSON.stringify(vis));
  if (vis.out) fail(tag + ' deep tab left the page ' + vis.out);
  if (vis.top !== undefined && (vis.top < vis.vt - 1 || vis.bottom > vis.vb + 1)) fail(tag + ' focused control outside the glass ' + JSON.stringify(vis));
  await shot(p, N(23, 'keyboard-deep-focus'), tag);
  // anchors from the nav jump to pages
  await p.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' })); await sleep(500);
  await p.click('.nav-links a[href="#faq"]'); await sleep(2600);
  const nv = await state(p); if (nv.on !== 4) fail(tag + ' nav link to #faq landed on ' + JSON.stringify(nv));
  // footer
  await p.evaluate(() => window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'instant' })); await sleep(900);
  await shot(p, N(24, 'footer'), tag);
  await ctx.close();
}

async function phone(browser, vp = { width: 390, height: 844 }, prefix = '') {
  const tag = 'phone' + vp.width;
  const ctx = await browser.newContext({ viewport: vp, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
  await standins(ctx);
  const p = await ctx.newPage(); track(p, tag);
  const cdp = await ctx.newCDPSession(p); await cdp.send('Animation.enable');
  await p.goto(URL, { waitUntil: 'load' });
  await sleep(2500);
  const N = (n, s) => prefix + String(n).padStart(2, '0') + '-phone-' + s;
  await heroTo(p, 1); await settle(p);
  await shot(p, N(1, 'hero-settle'), tag);
  const top = sel => p.evaluate(sel => document.querySelector(sel).getBoundingClientRect().top + scrollY, sel);
  const to = y => p.evaluate(y => window.scrollTo({ top: y, behavior: 'instant' }), y);
  if (prefix) {
    for (const [i, id, nm] of [[2, '#about', 'pilot'], [3, '#log', 'fpl'], [4, '#type', 'eng'], [5, '#ai', 'dlk'], [6, '#faq', 'chkl'], [7, '#subscribe', 'logon']]) {
      await to((await top(id)) - 64); await sleep(1400); await shot(p, N(i, nm), tag);
    }
    await ctx.close(); return;
  }
  // PILOT sweeps in as it rises (slowed 10x for the capture)
  await slow(cdp, 0.1);
  await to((await top('#about')) - vp.height * 0.6); await sleep(2400);
  await shot(p, N(2, 'pilot-sweeps-in'), tag);
  await slow(cdp, 1); await sleep(800);
  await to((await top('#about')) - 64); await sleep(700);
  await shot(p, N(3, 'pilot'), tag);
  const bar = await p.evaluate(() => { const r = document.querySelector('.ck-keys').getBoundingClientRect(); return { top: Math.round(r.top), h: Math.round(r.height), vis: getComputedStyle(document.querySelector('.ck-keys')).visibility }; });
  if (bar.vis !== "visible" || bar.top + bar.h > vp.height + 1) fail(tag + ' bottom bar ' + JSON.stringify(bar));
  await to((await top('.crew')) - 160); await sleep(500);
  await shot(p, N(4, 'pilot-crew-data'), tag);
  await p.tap('.sk[data-pg="1"]'); await sleep(1500);
  await shot(p, N(5, 'fpl'), tag);
  await p.tap('#leg-b0'); await sleep(300);            // fold the open leg
  await p.tap('#leg-b1'); await sleep(600);
  await to((await top('#leg-b1')) - 120); await sleep(400);
  await shot(p, N(6, 'fpl-leg2-open'), tag);
  await to((await top('#fly')) - 110); await sleep(600);
  await shot(p, N(7, 'vsd'), tag);
  const hb = await p.$('#hold'); const bb = await hb.boundingBox();
  // touch hold via CDP touch events
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: bb.x + bb.width / 2, y: bb.y + bb.height / 2 }] });
  await sleep(3300);
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  await sleep(1400);
  const landed = await p.evaluate(() => document.getElementById('log').classList.contains('landed'));
  if (!landed) fail(tag + ' touch hold did not land');
  await shot(p, N(8, 'vsd-minimums'), tag);
  await p.tap('.sk[data-pg="2"]'); await sleep(1800);
  await shot(p, N(9, 'eng'), tag);
  await to((await top('#facts')) - 120); await sleep(1600);
  await shot(p, N(10, 'eng-instruments'), tag);
  await p.evaluate(() => { const f = document.getElementById('facts'); f.scrollTo({ left: f.querySelectorAll('.fx')[3].offsetLeft - 16, behavior: 'instant' }); }); await sleep(600);
  await shot(p, N(11, 'eng-instruments-swiped'), tag);
  await to((await top('.syn')) - 120); await sleep(1800);
  await shot(p, N(12, 'eng-synoptic'), tag);
  await p.tap('.sk[data-pg="3"]'); await sleep(1500);
  await shot(p, N(13, 'datalink'), tag);
  await to((await top('#shelves')) - 110); await sleep(300);
  await p.evaluate(() => { const t = document.querySelector('.tabs'); t.scrollTo({ left: t.scrollWidth, behavior: 'instant' }); }); await sleep(300);
  await p.tap('#tab-aviation'); await sleep(500);
  await shot(p, N(14, 'datalink-tabs-swiped'), tag);
  await to((await top('.chat')) - 100); await sleep(300);
  await p.tap('#chips button'); await p.waitForFunction(() => document.querySelectorAll('#chat-log .msg.assistant:not(.sr)').length >= 2, null, { timeout: 15000 }); await sleep(400);
  await shot(p, N(15, 'cpdlc-inop-uplink'), tag);
  await p.tap('.sk[data-pg="4"]'); await sleep(1500);
  await to((await top('#checklist')) - 110); await sleep(3200);
  await shot(p, N(16, 'checklist-reading'), tag);
  await to((await top('.cl-done')) - vp.height + 220); await sleep(6000);
  const cl = await p.evaluate(() => document.getElementById('checklist').classList.contains('done'));
  if (!cl) fail(tag + ' checklist not complete on phone');
  await shot(p, N(17, 'checklist-complete'), tag);
  await p.tap('.sk[data-pg="5"]'); await sleep(1500);
  await shot(p, N(18, 'logon'), tag);
  await to((await top('.logon')) - 90); await sleep(300);
  await p.fill('#sub-email', 'pilot@example.com'); await p.tap('#sub-btn'); await sleep(500);
  await shot(p, N(19, 'logon-inop-mode-a'), tag);
  await to(1e6); await sleep(900);
  await shot(p, N(20, 'footer'), tag);
  await ctx.close();
}

async function reduced(browser) {
  const tag = 'rm';
  for (const vp of [{ width: 1440, height: 900 }, { width: 390, height: 844, isMobile: true, hasTouch: true }]) {
    const ctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height }, reducedMotion: 'reduce', isMobile: !!vp.isMobile, hasTouch: !!vp.hasTouch });
    const reqs = [];
    const p = await ctx.newPage(); track(p, tag + vp.width);
    p.on('request', r => reqs.push(r.url()));
    await p.goto(URL, { waitUntil: 'load' }); await sleep(1500);
    const st = await p.evaluate(() => ({
      seen: [...document.querySelectorAll('.pg')].every(x => x.classList.contains('seen')),
      cl: document.getElementById('checklist').classList.contains('done'),
      landed: document.getElementById('log').classList.contains('landed'),
      needle: getComputedStyle(document.querySelector('.needle')).transform
    }));
    note('RM ' + vp.width + ' ' + JSON.stringify(st));
    if (!st.seen || !st.cl || !st.landed) fail('RM final states ' + vp.width + ' ' + JSON.stringify(st));
    if (reqs.some(u => /\.mp4/.test(u))) fail('RM requested video ' + vp.width);
    if (vp.width === 1440) {
      await p.click('.sk[data-pg="2"]').catch(async () => { await p.evaluate(() => document.querySelector('.sk[data-pg="2"]').click()); });
      await sleep(400);
      await shot(p, 'x1-reduced-motion-desk-eng', tag);
    } else {
      await p.evaluate(() => document.getElementById('type').scrollIntoView()); await sleep(400);
      await shot(p, 'x2-reduced-motion-phone-eng', tag);
    }
    await ctx.close();
  }
  // live flip, both directions
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  await standins(ctx);
  const p = await ctx.newPage(); track(p, 'rmflip');
  await p.goto(URL, { waitUntil: 'load' }); await sleep(1500);
  await p.emulateMedia({ reducedMotion: 'reduce' }); await sleep(500);
  const a = await p.evaluate(() => ({ cl: document.getElementById('checklist').classList.contains('done'), landed: document.getElementById('log').classList.contains('landed') }));
  await p.emulateMedia({ reducedMotion: 'no-preference' }); await sleep(800);
  const b = await p.evaluate(() => ({ cl: document.getElementById('checklist').classList.contains('done'), landed: document.getElementById('log').classList.contains('landed'), status: document.getElementById('fly-status').textContent }));
  note('RM flip in ' + JSON.stringify(a) + ' out ' + JSON.stringify(b));
  if (!a.cl || !a.landed || b.cl || b.landed) fail('RM live flip ' + JSON.stringify([a, b]));
  await ctx.close();
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--autoplay-policy=no-user-gesture-required'] });
  try {
    if (RUN.has('desk')) await desk(browser);
    if (RUN.has('phone')) await phone(browser);
    if (RUN.has('extra')) {
      await desk(browser, 1920, 1080, 'x3-1920-');
      await phone(browser, { width: 360, height: 740 }, 'x4-360-');
      await reduced(browser);
    }
  } catch (e) { fail('SCRIPT ' + (e && e.stack || e)); }
  await browser.close();
  console.log(problems.length ? 'PROBLEMS:\n' + problems.join('\n') : 'ALL CHECKS PASSED');
})();
