// Quick look: PORT=8961 OUT=dir node look.js [desk|phone]
const { chromium } = require('/opt/node-tools/node_modules/playwright');
const fs = require('fs');
const PORT = process.env.PORT || 8961;
const URL = `http://127.0.0.1:${PORT}/`;
const SCR = '/tmp/claude-0/-home-user-momo/9d5abb52-bf47-5e03-b701-f78e1dba1457/scratchpad/';
const PSTAND = fs.readFileSync(SCR + 'mobile/standin-portrait-vp9.webm');
const DSTAND = fs.readFileSync(SCR + 'standin-vp9.mp4');
const OUT = process.env.OUT || SCR + 'Alook/';
fs.mkdirSync(OUT, { recursive: true });
const sleep = ms => new Promise(r => setTimeout(r, ms));
const mode = process.argv[2] || 'desk';
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--autoplay-policy=no-user-gesture-required'] });
  const opts = mode === 'phone' ? { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true } : { viewport: { width: +(process.env.W || 1440), height: +(process.env.H || 900) } };
  const ctx = await browser.newContext(opts);
  await ctx.route('**/assets/hero-scrub-portrait.mp4', r => r.fulfill({ status: 200, contentType: 'video/mp4', body: PSTAND }));
  await ctx.route('**/assets/hero-scrub.mp4', r => r.fulfill({ status: 200, contentType: 'video/mp4', body: DSTAND }));
  const p = await ctx.newPage();
  const logs = [];
  p.on('console', m => { if (m.type() === 'error' || m.type() === 'warning') logs.push(m.type() + ': ' + m.text()); });
  p.on('pageerror', e => logs.push('PAGEERROR: ' + e.message));
  p.on('requestfailed', r => { if (!r.url().startsWith('blob:')) logs.push('REQFAILED ' + r.url()); });
  p.on('request', r => { if (!r.url().startsWith(URL) && !r.url().startsWith('blob:') && !r.url().startsWith('data:')) logs.push('EXTERNAL ' + r.url()); });
  await p.goto(URL, { waitUntil: 'load' });
  await sleep(2500);
  const info = await p.evaluate(() => ({ cls: document.getElementById('cockpit').className, track: document.querySelector('.ck-track').style.height, sw: document.documentElement.scrollWidth, iw: innerWidth }));
  console.log(JSON.stringify(info));
  // pages via soft keys (instant jumps once the display is pinned)
  const ckTop = await p.evaluate(() => document.getElementById('cockpit').getBoundingClientRect().top + scrollY);
  await p.evaluate(t => window.scrollTo({ top: t - 64, behavior: 'instant' }), ckTop);
  await sleep(1200);
  await p.screenshot({ path: OUT + mode + '-p0.png' });
  const keys = await p.$$('.sk');
  for (let k = 1; k < 6; k++) {
    if (mode === 'phone') { await p.evaluate(k => document.querySelectorAll('.pg')[k].scrollIntoView({ behavior: 'instant' }), k); await sleep(1200); }
    else { await keys[k].click(); await sleep(1300); }
    await p.screenshot({ path: OUT + mode + '-p' + k + '.png' });
    const st = await p.evaluate(() => ({ y: scrollY, sw: document.documentElement.scrollWidth }));
    console.log('page', k, JSON.stringify(st));
  }
  console.log(logs.join('\n') || 'console clean');
  await browser.close();
})();
