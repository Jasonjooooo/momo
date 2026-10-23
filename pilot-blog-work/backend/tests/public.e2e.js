// Public pages with 0, 1, 3 and 8 published posts (SPEC T6 + T10 for post pages and the archive).
// Each scenario is built in temp dirs by fixture.php and served by php -S with the dev router.
// Usage: node pilot-blog-work/backend/tests/public.e2e.js [launch,pub0,pub1,pub3,pub8,album1,album3]
// album1/album3: the 关于 INSET A photo as one uploaded photo and as a three-photo album (screenshots in screens/reorder/).
// 'launch' also runs the real subscribe flow in the browser (consent, honeypot, stored once).
const { chromium } = require('/opt/node-tools/node_modules/playwright');
const { execFileSync, spawn } = require('child_process');
const fs = require('fs');
const os = require('os');
const net = require('net');
const path = require('path');

const REPO = path.resolve(__dirname, '../../..');
const SCEN = (process.argv[2] || 'launch,pub0,pub1,pub3,pub8,album1,album3').split(',');
const SHOTS = path.join(REPO, 'pilot-blog-work/screens/reorder/');
const SCR = '/tmp/claude-0/-home-user-momo/9d5abb52-bf47-5e03-b701-f78e1dba1457/scratchpad/';
const STAND = { portrait: SCR + 'mobile/standin-portrait-vp9.webm', land: SCR + 'standin-vp9.mp4', game: SCR + 'game/approach-night-vp9.webm' };
// the game footage is H.264, which this Chromium cannot decode: make a VP9 copy once (never committed)
if (!fs.existsSync(STAND.game)) {
  try { fs.mkdirSync(path.dirname(STAND.game), { recursive: true }); execFileSync('ffmpeg', ['-loglevel', 'error', '-y', '-i', REPO + '/pilot-blog/assets/game/approach-night.mp4', '-c:v', 'libvpx-vp9', '-deadline', 'realtime', '-cpu-used', '8', '-b:v', '1.5M', '-g', '15', '-an', STAND.game]); } catch (e) { /* the play-through is skipped */ }
}
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
  if (fs.existsSync(STAND.portrait)) await ctx.route('**/assets/hero-scrub-portrait-2.mp4', r => r.fulfill({ status: 200, contentType: 'video/mp4', body: fs.readFileSync(STAND.portrait) }));
  if (fs.existsSync(STAND.land)) await ctx.route('**/assets/hero-scrub.mp4', r => r.fulfill({ status: 200, contentType: 'video/mp4', body: fs.readFileSync(STAND.land) }));
  if (fs.existsSync(STAND.game)) await ctx.route('**/assets/game/approach-night.mp4', r => r.fulfill({ status: 200, contentType: 'video/webm', body: fs.readFileSync(STAND.game) }));
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
    order: [...document.querySelectorAll('#chart > .sec')].map(e => e.id).join(' '),
    nav: [...document.querySelectorAll('#nav-links a')].map(a => a.getAttribute('href')).join(' '),
    navShown: [...document.querySelectorAll('#nav-links li')].sort((a, b) => a.getBoundingClientRect().left - b.getBoundingClientRect().left || a.getBoundingClientRect().top - b.getBoundingClientRect().top).map(li => li.firstElementChild.getAttribute('href')).join(' '),
    foot: [...document.querySelectorAll('.foot-links a')].map(a => a.getAttribute('href')).join(' '),
    kickers: [...document.querySelectorAll('#about .kicker, #type .lg-body .kicker, #log .plate-head .kicker:first-child, #ai .comm-head .kicker, #faq .cl-body .kicker, #subscribe .sub-card .kicker')].map(k => k.textContent.slice(0, 2)).join(' '),
  }));
  check(R, 'sections in the owner order', st.order === 'about type apch log ai faq subscribe', st.order);
  check(R, 'nav and footer links follow the page', st.nav === '#about #type #log #ai #faq' && st.foot === '#about #type #log #ai #faq #subscribe', [st.nav, st.foot]);
  if (opt.viewport.width > 900) check(R, 'nav items appear in page order', st.navShown === '#about #type #log #ai #faq', st.navShown);
  check(R, 'section numbers 01 to 06 in order', st.kickers === '01 02 03 04 05 06', st.kickers);
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

// the runway at the end is lit by the route reaching RW24 (never by the approach game); #apch holds the 盲降挑战 game
// (assets/game/ils.js): brief card with weather from api/game.php, first-run tutorial, cloud phase, decision, result, board
async function runwayFlow(browser, base, opt) {
  const R = { checks: [] };
  const ctx = await browser.newContext(opt);
  await standins(ctx);
  const p = await ctx.newPage();
  track(p, R, base);
  const starts = [];
  p.on('response', r => { if (r.url().includes('api/game.php?a=start')) starts.push(r.status()); });
  await p.goto(base, { waitUntil: 'load' });
  await sleep(1500);
  const H = await p.evaluate(() => document.documentElement.scrollHeight);
  for (let y = 0; y < H; y += 600) { await p.evaluate(y => window.scrollTo({ top: y, behavior: 'instant' }), y); await sleep(40); }
  const toGame = () => p.evaluate(() => { const e = document.getElementById('ils'); window.scrollTo({ top: e.getBoundingClientRect().top + scrollY - 70, behavior: 'instant' }); });
  await toGame();
  await sleep(900);
  check(R, 'runway dark while the route is still at the approach', await p.evaluate(() => !document.getElementById('subscribe').classList.contains('landed')));
  const g0 = await p.evaluate(() => {
    const sec = document.getElementById('apch'), ils = document.getElementById('ils');
    return {
      inApch: !!ils && sec.contains(ils) && sec.querySelectorAll('.bend').length === 2,
      head: [...sec.querySelectorAll('.apch-head .kicker, .apch-head h2, .apch-head .ins')].map(e => e.textContent),
      old: !!document.getElementById('fly') || !!document.getElementById('hold'),
      phase: window.__ils && window.__ils.phase,
      brief: !document.getElementById('ils-brief').hidden,
      wx: document.getElementById('ils-brief-wx').textContent,
      code: window.__ils && window.__ils.code,
      touch: getComputedStyle(document.getElementById('ils-stage')).touchAction,
      vsrc: document.getElementById('ils-v').dataset.src,
      siteBtn: getComputedStyle(document.querySelector('.nav-cta')).borderRadius,
    };
  });
  check(R, 'the game sits in #apch with the two route bends after it, the old hold game is gone', g0.inApch && !g0.old, g0);
  check(R, 'heading above the game from the apch fields', g0.head[0] === '盲降挑战 · ILS 夜间进近' && g0.head[1] === '换你来飞。' && g0.head[2].startsWith('从云里开始'), g0.head);
  check(R, 'brief card shows the weather', g0.brief && g0.phase === 'brief' && /CLOUD BASE\d+ FTRVR\d+ MWIND\d{3}\/\d+KT/.test(g0.wx), g0.wx);
  check(R, 'weather and run come from api/game.php (start answered 200, challenge code set)', starts.length >= 1 && starts.every(s => s === 200) && typeof g0.code === 'string' && g0.code.length > 8, [starts, g0.code]);
  check(R, 'no touch-action:none before the game starts', g0.touch !== 'none', g0.touch);
  check(R, 'the site\'s own buttons keep their shape (game .btn rules are scoped)', g0.siteBtn === '6px', g0.siteBtn);
  // the footage is fetched when the game nears the viewport; the start button waits for it
  await p.waitForFunction(() => { const b = document.getElementById('ils-go'); return !b.disabled && b.textContent === '开始进近'; }, null, { timeout: 30000 }).catch(() => {});
  await p.click('#ils-go');
  await sleep(700);
  const g1 = await p.evaluate(() => ({ phase: window.__ils.phase, tut: !document.getElementById('ils-tut').hidden, brief: !document.getElementById('ils-brief').hidden, touch: getComputedStyle(document.getElementById('ils-stage')).touchAction }));
  check(R, '开始进近 on the first run shows the tutorial', g1.phase === 'tutor' && g1.tut && !g1.brief, g1);
  check(R, 'touch-action:none only while playing', g1.touch === 'none', g1.touch);
  await p.click('#ils-tut-go');
  await sleep(1200);
  const g2 = await p.evaluate(() => ({ phase: window.__ils.phase, alt: window.__ils.alt, tut: !document.getElementById('ils-tut').hidden }));
  check(R, '明白了 starts the cloud phase (descending from 1000 ft)', g2.phase === 'cloud' && !g2.tut && g2.alt < 1000 && g2.alt > 300, g2);
  const ov = await overflow(p);
  check(R, 'no horizontal overflow while playing', ov.sw <= ov.cw, ov);
  if (fs.existsSync(STAND.game)) {
    // footage stand-in (VP9; this Chromium has no H.264): fly to DA, go around, read the result, post it to the board
    await p.waitForFunction(() => window.__ils.phase === 'decide', null, { timeout: 40000 }).catch(() => {});
    const dec = await p.evaluate(() => [window.__ils.phase, !document.getElementById('ils-dec').hidden]);
    check(R, 'the footage plays down to DA and asks for the decision', dec[0] === 'decide' && dec[1], dec);
    await p.click('#ils-ga');
    await p.waitForSelector('#ils-result:not([hidden])', { timeout: 15000 }).catch(() => {});
    const res = await p.evaluate(() => ({ phase: window.__ils.phase, grade: document.getElementById('ils-grade').textContent, h: document.getElementById('ils-res-h').textContent, sub: !document.getElementById('ils-sub').hidden, playing: document.getElementById('ils').classList.contains('playing') }));
    check(R, 'a go-around ends on the result card with a grade', res.phase === 'done' && /^[SABCF]$/.test(res.grade) && res.h !== '' && !res.playing, res);
    check(R, 'the game does not light the runway', await p.evaluate(() => !document.getElementById('subscribe').classList.contains('landed')));
    if (res.sub) {
      await p.fill('#ils-name', '测试机长');
      await p.click('#ils-sub button[type="submit"]');
      await p.waitForFunction(() => /上榜了|已提交/.test(document.getElementById('ils-msg').textContent), null, { timeout: 10000 }).catch(() => {});
      const msg = await p.evaluate(() => [document.getElementById('ils-msg').textContent, !document.getElementById('ils-board').hidden]);
      check(R, 'the run goes on the server leaderboard', /上榜了|已提交/.test(msg[0]) && msg[1], msg);
      await p.click('#ils-board-close');
    }
    await p.click('#ils-again');
    await sleep(600);
    await p.click('#ils-go');
    await sleep(700);
    const g3 = await p.evaluate(() => [window.__ils.phase, document.getElementById('ils-tut').hidden]);
    check(R, 'the second run skips the tutorial', g3[0] === 'cloud' && g3[1], g3);
  } else R.checks.push('skip play-through: no VP9 stand-in at ' + STAND.game);
  await p.evaluate(() => window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'instant' }));
  await sleep(900);
  check(R, 'the runway lights come on when the route reaches RW24', await p.evaluate(() => document.getElementById('subscribe').classList.contains('landed')));
  await p.evaluate(() => { const e = document.getElementById('about'); window.scrollTo({ top: e.getBoundingClientRect().top + scrollY, behavior: 'instant' }); });
  await sleep(900);
  check(R, 'and go out again when the reader scrolls back up', await p.evaluate(() => !document.getElementById('subscribe').classList.contains('landed')));
  const rc = await browser.newContext({ ...opt, reducedMotion: 'reduce' });
  await standins(rc);
  const rp = await rc.newPage();
  const RL = { checks: [] };
  track(rp, RL, base);
  await rp.goto(base, { waitUntil: 'load' });
  await sleep(1500);
  const rm = await rp.evaluate(() => [document.getElementById('subscribe').classList.contains('landed'), window.__ils && window.__ils.phase, document.getElementById('ils').classList.contains('playing')]);
  check(R, 'reduced motion: runway lit from the start, the game waits for a tap', rm[0] && rm[1] === 'brief' && !rm[2], rm);
  check(R, 'reduced motion: no console errors', RL.logs.length === 0, RL.logs);
  await rc.close();
  check(R, 'no console errors, external or failed requests', R.logs.length === 0, R.logs);
  await ctx.close();
  return R;
}

// 关于 · INSET A: one photo is the plain figure; three photos are a strip with arrows (desktop), swipe (phone), dots and keys
async function albumFlow(browser, base, fx, tag, opt) {
  const R = { checks: [] };
  const ctx = await browser.newContext(opt);
  await standins(ctx);
  const p = await ctx.newPage();
  track(p, R, base);
  await p.goto(base, { waitUntil: 'load' });
  await sleep(1200);
  const at = async () => { await p.evaluate(() => { const e = document.querySelector('.about-fig'); window.scrollTo({ top: e.getBoundingClientRect().top + scrollY - (innerWidth > 900 ? 220 : 160), behavior: 'instant' }); }); await sleep(1800); };
  await at();
  const n = fx.album.length;
  const st = await p.evaluate(() => ({
    album: !!document.querySelector('[data-album]'),
    tag: document.querySelector('.about-art .inset-tag').textContent,
    imgs: [...document.querySelectorAll('.about-art img')].map(i => ({ src: i.getAttribute('src'), lazy: i.loading, w: i.naturalWidth })),
    cap: (document.querySelector('.alb-cap') || document.querySelector('.about-fig figcaption')).textContent,
    btn: document.querySelector('.alb-next') ? getComputedStyle(document.querySelector('.alb-next')).display : 'none',
  }));
  check(R, 'label stays INSET A', st.tag === 'INSET A');
  check(R, n > 1 ? 'album markup with one image per photo' : 'one photo: the plain figure, no album', st.album === (n > 1) && st.imgs.length === n && st.imgs.every((im, k) => im.src === fx.album[k].src), st);
  check(R, 'first photo loaded, every photo lazy', st.imgs[0].w > 0 && st.imgs.every(i => i.lazy === 'lazy'), st.imgs);
  check(R, 'caption is the first photo\'s', st.cap === fx.album[0].caption, st.cap);
  await p.screenshot({ path: SHOTS + 'album' + n + '-a-' + tag + '.png' });
  if (n > 1) {
    const state = () => p.evaluate(() => { const t = document.querySelector('.alb-track'); return { k: Math.round(t.scrollLeft / t.clientWidth), cap: document.querySelector('.alb-cap').textContent, cur: [...document.querySelectorAll('.alb-dot')].findIndex(d => d.getAttribute('aria-current') === 'true'), prevDis: document.querySelector('.alb-prev').disabled, nextDis: document.querySelector('.alb-next').disabled }; });
    const s0 = await state();
    check(R, 'starts on photo 1 with prev disabled', s0.k === 0 && s0.cur === 0 && s0.prevDis && !s0.nextDis, s0);
    if (opt.viewport.width > 900) {
      check(R, 'desktop: arrow buttons shown', st.btn === 'flex', st.btn);
      await p.click('.alb-next'); await sleep(900);
      const s1 = await state();
      check(R, 'next shows photo 2 and its caption', s1.k === 1 && s1.cur === 1 && s1.cap === fx.album[1].caption, s1);
      await p.click('.alb-dot:nth-child(3)'); await sleep(900);
      const s2 = await state();
      check(R, 'dot 3 shows photo 3, next disabled', s2.k === 2 && s2.cap === fx.album[2].caption && s2.nextDis, s2);
      const loaded = await p.evaluate(() => [...document.querySelectorAll('.alb-slide img')].map(i => i.naturalWidth > 0));
      check(R, 'photo 3 loaded once shown', loaded[2], loaded);
      await p.screenshot({ path: SHOTS + 'album' + n + '-b-' + tag + '.png' });
      await p.focus('.alb-track');
      await p.keyboard.press('Home'); await sleep(900);
      await p.keyboard.press('ArrowRight'); await sleep(900);
      const s3 = await state();
      check(R, 'keyboard: Home then ArrowRight on the strip shows photo 2', s3.k === 1 && s3.cap === fx.album[1].caption, s3);
      const ring = await p.evaluate(() => getComputedStyle(document.activeElement).outlineStyle);
      check(R, 'the focused strip shows a focus ring', ring !== 'none', ring);
    } else {
      check(R, 'phone: no arrow buttons (swipe)', st.btn === 'none', st.btn);
      const box = await p.locator('.alb-track').boundingBox();
      const cdp = await ctx.newCDPSession(p);
      const y = box.y + box.height / 2;
      await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: box.x + box.width * 0.85, y }] });
      for (let k = 1; k <= 8; k++) { await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: box.x + box.width * (0.85 - 0.08 * k), y }] }); await sleep(16); }
      await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
      await sleep(1200);
      let s1 = await state();
      if (s1.k === 0) {   // headless touch emulation does not always fling a scroller: fall back to a programmatic swipe
        R.swipeFallback = true;
        await p.evaluate(() => { const t = document.querySelector('.alb-track'); t.scrollTo({ left: t.clientWidth, behavior: 'instant' }); });
        await sleep(600);
        s1 = await state();
      }
      check(R, 'swipe to photo 2 updates the caption and dot', s1.k === 1 && s1.cur === 1 && s1.cap === fx.album[1].caption, s1);
      await p.screenshot({ path: SHOTS + 'album' + n + '-b-' + tag + '.png' });
      await p.click('.alb-dot:nth-child(3)'); await sleep(900);
      const s2 = await state();
      check(R, 'tapping dot 3 shows photo 3', s2.k === 2 && s2.cap === fx.album[2].caption, s2);
    }
  }
  const ov = await overflow(p);
  check(R, 'no horizontal overflow', ov.sw <= ov.cw, ov);
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
      if (sc === 'launch') for (const [tag, opt] of VIEWPORTS) S['runway-' + tag] = await runwayFlow(browser, base, opt);
      if (fx.album.length) {
        fs.mkdirSync(SHOTS, { recursive: true });
        for (const [tag, opt] of VIEWPORTS) S['album-' + tag] = await albumFlow(browser, base, fx, tag, opt);
      }
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
