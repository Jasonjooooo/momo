// End-to-end QA of the 云底之下 admin, from the owner's side (phone at 390 px, desktop at 1440 px).
//
// Every width gets its own throwaway site: a temp public dir (code symlinked from pilot-blog/, a copy of
// the committed index.html) and a FRESH temp data dir that the app fills with the launch seed on first
// request. The repo's pilot-blog/index.html, posts/, uploads/ and blog-data/ are never written; the
// script checks that at the end. No real API key is used (BLOG_CHAT_FAKE=1).
//
// Covers every admin flow of SPEC §12 at both widths, plus:
//   - a publish with no changes leaves the home page byte-identical to the seed (committed index.html)
//   - hero regions unchanged after every kind of publish (tests/hero-check.php, SPEC T3)
//   - no console errors and no horizontal overflow on any admin page (and the public pages it touches)
//   - drafts never reachable publicly (guessed URLs, and a grep of the whole public dir)
//   - the subscribe form end to end from the public page, CSV export read back as UTF-8 Chinese
//   - rollback restores the previous public files byte for byte
//   - session idle/absolute expiry, password change and 退出 really end access (old cookie replayed)
//   - owner mistakes: pasted emoji / line breaks / tabs / over-long text, HEIC and 12 MB iPhone photos,
//     double-tapping 发布, two tabs on one post, publishing offline and retrying after a lost answer
//
// Usage: PORT=9300 node pilot-blog-work/backend/e2e.js [1440,390] [keep]
//   keep = leave the temp dirs (printed) for inspection. Error screenshots go to the scratch dir printed.
'use strict';
const { chromium } = require('/opt/node-tools/node_modules/playwright');
const { execFileSync, spawn, spawnSync } = require('child_process');
const crypto = require('crypto');
const fs = require('fs');
const net = require('net');
const os = require('os');
const path = require('path');

const REPO = path.resolve(__dirname, '../..');
const PUBLIC_REPO = REPO + '/pilot-blog';
const ARGS = process.argv.slice(2);
const WIDTHS = (ARGS.find(a => /^\d+(,\d+)*$/.test(a)) || '1440,390').split(',').map(Number);
const KEEP = ARGS.includes('keep');
const PORT0 = Number(process.env.PORT) || 9300;
const PW1 = '跑道灯在雾里是绿色的2026';
const PW2 = '换个新密码：复飞不丢人42';
const FAKE_KEY = 'sk-ant-test' + 'Q1w2E3r4T5y6U7i8O9p0A1s2D3f4G5h6' + 'KQ7Z';
const sleep = ms => new Promise(r => setTimeout(r, ms));
const sha = f => crypto.createHash('sha256').update(fs.readFileSync(f)).digest('hex');
const read = f => fs.readFileSync(f, 'utf8');
const ERRDIR = fs.mkdtempSync(path.join(os.tmpdir(), 'pbe2e-shots-'));

// ---------- results ----------
let total = 0;
let failed = 0;
const failLines = [];
function check(T, name, cond, detail) {
  total++;
  const line = (cond ? 'ok   ' : 'FAIL ') + `[${T.W}] ` + name + (cond || detail === undefined ? '' : ' -- ' + JSON.stringify(detail).slice(0, 600));
  console.log('  ' + line);
  if (!cond) { failed++; failLines.push(line); }
  return !!cond;
}
function info(T, msg) { console.log(`  info [${T.W}] ${msg}`); }

// ---------- the throwaway site ----------
function makeSite() {
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'pbe2e-'));
  fs.mkdirSync(tmp + '/pub/posts', { recursive: true });
  fs.mkdirSync(tmp + '/pub/uploads', { recursive: true });
  fs.mkdirSync(tmp + '/data');
  fs.mkdirSync(tmp + '/files');
  for (const d of ['assets', 'api', 'app', 'admin']) fs.symlinkSync(PUBLIC_REPO + '/' + d, tmp + '/pub/' + d);
  for (const f of ['index.html', '.htaccess', 'posts/.htaccess', 'uploads/.htaccess']) fs.copyFileSync(PUBLIC_REPO + '/' + f, tmp + '/pub/' + f);
  makeFixtures(tmp + '/files');
  return tmp;
}
function makeFixtures(dir) {
  // ordinary pictures, made with GD (no binary fixtures in the repo)
  execFileSync('php', ['-r', `
    $d = $argv[1];
    $im = imagecreatetruecolor(1600, 900);
    imagefill($im, 0, 0, imagecolorallocate($im, 19, 45, 97));
    imagefilledellipse($im, 800, 600, 300, 120, imagecolorallocate($im, 79, 208, 138));
    imagejpeg($im, $d . '/cover.jpg', 88);
    $p = imagecreatetruecolor(900, 1200);
    imagefill($p, 0, 0, imagecolorallocate($p, 234, 76, 34));
    imagepng($p, $d . '/photo.png');
  `, dir]);
  // a 4032 x 3024 iPhone-style JPEG of about 12 MB, EXIF orientation 6 (held sideways, shown upright)
  const py = `
import os, sys
from PIL import Image
d = sys.argv[1]
W, H = 4032, 3024
noise = Image.effect_noise((W, H), 40).convert('L')
base = Image.linear_gradient('L').resize((W, H))
img = Image.merge('RGB', (Image.blend(base, noise, 0.5), noise, base))
exif = Image.Exif(); exif[0x0112] = 6; exif[0x010F] = 'Apple'; exif[0x0110] = 'iPhone 15 Pro'
for q in (98, 96, 94, 92, 90, 85, 80):
    img.save(d + '/IMG_0042.JPG', 'JPEG', quality=q, exif=exif.tobytes())
    if os.path.getsize(d + '/IMG_0042.JPG') <= 12 * 1024 * 1024:
        break
`;
  const r = spawnSync('python3', ['-I', '-c', py, dir]);
  if (r.status !== 0) {
    // no Pillow: a big noisy JPEG from GD (no EXIF), still about the size of an iPhone photo
    execFileSync('php', ['-d', 'memory_limit=1G', '-r', `
      $im = imagecreatetruecolor(4032, 3024);
      for ($y = 0; $y < 3024; $y += 2) for ($x = 0; $x < 4032; $x += 2) imagefilledrectangle($im, $x, $y, $x + 1, $y + 1, mt_rand(0, 0xFFFFFF));
      imagejpeg($im, $argv[1] . '/IMG_0042.JPG', 92);
    `, dir]);
  }
  // an iPhone HEIC as it comes out of the Files app: ISO-BMFF 'ftyp heic' box + image data
  const ftyp = Buffer.from('00000018667479706865696300000000' + '6d69663168656963', 'hex');
  const meta = Buffer.concat([Buffer.from('0000002c6d657461', 'hex'), crypto.randomBytes(36)]);
  fs.writeFileSync(dir + '/IMG_0043.HEIC', Buffer.concat([ftyp, meta, crypto.randomBytes(2 * 1024 * 1024)]));
  // a 12.5 MB photo the phone cannot shrink (damaged JPEG), so it reaches the server at full size
  const f = fs.openSync(dir + '/IMG_0044.JPG', 'w');
  fs.writeSync(f, Buffer.from('ffd8ffe0', 'hex'));
  fs.writeSync(f, crypto.randomBytes(12.5 * 1024 * 1024));
  fs.closeSync(f);
}
const portFree = port => new Promise(res => {
  const s = net.createServer().once('error', () => res(false)).once('listening', () => s.close(() => res(true))).listen(port, '127.0.0.1');
});
async function freePort(from) { for (let p = from; p < from + 200; p++) if (await portFree(p)) return p; throw new Error('no free port'); }
async function startServer(T, tmp, port) {
  const env = { ...process.env, BLOG_PUBLIC_DIR: tmp + '/pub', BLOG_DATA_DIR: tmp + '/data', BLOG_CHAT_FAKE: '1' };
  delete env.ANTHROPIC_API_KEY;
  const p = spawn('php', ['-d', 'upload_max_filesize=12M', '-d', 'post_max_size=16M', '-S', '127.0.0.1:' + port, '-t', tmp + '/pub', PUBLIC_REPO + '/app/dev/router.php'], { env, stdio: ['ignore', 'ignore', 'pipe'] });
  p.stderr.on('data', b => {
    for (const line of String(b).split('\n')) if (/PHP (Fatal|Warning|Notice|Deprecated|Parse)|Uncaught/.test(line)) T.R.php.push(line.trim().slice(0, 300));
  });
  for (let i = 0; i < 60 && await portFree(port); i++) await sleep(100);
  return p;
}

// ---------- page helpers ----------
function where(page) { try { const u = new URL(page.url()); return u.pathname + u.search; } catch (e) { return page.url(); } }
function track(T, page) {
  // 4xx answers the tests provoke on purpose (409, 413, 401) log 'Failed to load resource'; missing static files are tracked separately
  page.on('console', m => {
    if (m.type() !== 'error' || /^Failed to load resource: the server responded with a status of 4\d\d/.test(m.text())) return;
    if (T.R.netDown && /net::ERR_(INTERNET_DISCONNECTED|CONNECTION_RESET|FAILED)/.test(m.text())) return;   // the network failure this test makes on purpose
    T.R.console.push(where(page) + ' :: ' + m.text());
  });
  page.on('pageerror', e => T.R.console.push(where(page) + ' :: PAGEERROR ' + e.message));
  page.on('response', r => {
    if (r.status() >= 500) T.R.server.push(r.status() + ' ' + r.url());
    if (r.status() === 404 && /\.(css|js|woff2?|png|jpe?g|webp|svg|mp4|ico)(\?|$)/.test(r.url()) && r.url().startsWith(T.base)) T.R.missing.push(r.url());
  });
  page.on('dialog', d => {
    if (d.type() === 'beforeunload') { T.R.beforeunload++; d.accept().catch(() => {}); return; }
    T.R.dialogs.push(d.type() + ':' + d.message());
    d.dismiss().catch(() => {});
  });
  page.on('request', r => { const u = r.url(); if (!u.startsWith(T.base) && !/^(blob|data|about):/.test(u)) T.R.external.push(u); });
}
async function newPage(T, ctx) { const p = await (ctx || T.ctx).newPage(); track(T, p); return p; }
async function go(T, page, r, q = '') { return page.goto(T.base + '/admin/?r=' + r + q, { waitUntil: 'load' }); }
async function flashText(page) { return ((await page.locator('#flashes').textContent().catch(() => '')) || '').trim(); }
async function openDet(page, sel) {
  const loc = typeof sel === 'string' ? page.locator(sel) : sel;
  if (!(await loc.evaluate(e => e.open))) await loc.locator('summary').first().click();
}
async function openAllDetails(page) { await page.evaluate(() => document.querySelectorAll('main details').forEach(d => { d.open = true; })); await sleep(100); }
async function closeSheets(page) {
  for (let i = 0; i < 3 && await page.locator('.sheet').count().catch(() => 0); i++) await page.keyboard.press('Escape').catch(() => {});
  if (await page.locator('#menu.open').count().catch(() => 0)) await page.click('.menu-close').catch(() => {});
}
/** A sheet focuses its first field 30 ms after opening; wait for that before typing (a person is never that fast). */
async function sheetReady(page) {
  await page.waitForSelector('.sheet', { timeout: 30000 });
  await page.waitForFunction(() => document.activeElement && document.activeElement.closest('.sheet'), null, { timeout: 5000 }).catch(() => {});
}
async function sheetOk(page) { await page.waitForSelector('.sheet-btns button', { timeout: 5000 }); await page.locator('.sheet-btns button').last().click(); }
async function sheetAct(page, label) { await page.waitForSelector('.sheet-acts button', { timeout: 5000 }); await page.locator('.sheet-acts button', { hasText: label }).first().click(); }
async function openMenuIfPhone(page) { const b = page.locator('.menu-btn'); if (await b.isVisible()) { await b.click(); await page.waitForSelector('#menu.open'); } }
/** Clear the action bar status, run fn, wait for the result (is-ok / is-err). */
async function barAct(page, fn, timeout = 30000) {
  await page.evaluate(() => { const s = document.getElementById('bar-status'); if (s) { s.className = 'bar-status'; s.textContent = ''; } });
  await fn();
  await page.waitForSelector('#bar-status.is-ok, #bar-status.is-err', { timeout }).catch(() => {});
  const cls = (await page.getAttribute('#bar-status', 'class')) || '';
  return { ok: cls.includes('is-ok'), err: cls.includes('is-err'), text: ((await page.textContent('#bar-status')) || '').trim() };
}
/** What a paste does: put the caret at the end (or clear) and insert text as one edit (fires input). */
async function pasteInto(page, sel, text, replace = false) {
  const loc = page.locator(sel);
  await loc.scrollIntoViewIfNeeded();
  await loc.focus();
  await loc.evaluate((e, rep) => { if (rep) e.value = ''; const n = e.value.length; try { e.setSelectionRange(n, n); } catch (x) { /* date inputs */ } }, replace);
  await page.keyboard.insertText(text);
}
function heroOk(file) { return spawnSync('php', [REPO + '/pilot-blog-work/backend/tests/hero-check.php', file]).stdout.toString().trim() === 'HERO OK'; }
// The committed launch content already carries the real address (https://hxpilot.com). The one-tap
// 网站地址 banner is for a site that still has the placeholder, so the tests put the placeholder back first.
function originPlaceholder(dataDir) {
  for (const f of ['site.draft.json', 'site.live.json']) {
    const p = dataDir + '/content/' + f;
    const d = JSON.parse(fs.readFileSync(p, 'utf8'));
    d.fields['site.origin'] = 'https://DEPLOY-DOMAIN';
    fs.writeFileSync(p, JSON.stringify(d, null, 4) + '\n');
  }
}
function versionsCount(T) { return fs.existsSync(T.DATA + '/versions') ? fs.readdirSync(T.DATA + '/versions').filter(n => /^\d{8}-\d{6}-[0-9a-f]{6}$/.test(n)).length : 0; }
function newestVersion(T) {
  const ids = fs.readdirSync(T.DATA + '/versions').filter(n => /^\d{8}-\d{6}-[0-9a-f]{6}$/.test(n));
  return ids.map(id => [JSON.parse(read(`${T.DATA}/versions/${id}/manifest.json`)).seq || 0, id]).sort((a, b) => a[0] - b[0]).pop()[1];
}
function publicFiles(T) {
  const out = { 'index.html': sha(T.PUB + '/index.html') };
  for (const n of fs.readdirSync(T.PUB + '/posts')) if (n.endsWith('.html')) out['posts/' + n] = sha(T.PUB + '/posts/' + n);
  return out;
}
function grepTree(dir, needle, skipLinks = true) {
  const hits = [];
  const walk = d => {
    for (const n of fs.readdirSync(d)) {
      const p = d + '/' + n;
      const st = fs.lstatSync(p);
      if (st.isSymbolicLink() && skipLinks) continue;
      if (st.isDirectory()) walk(p);
      else if (st.isFile() && fs.readFileSync(p).includes(needle)) hits.push(p.slice(dir.length + 1));
    }
  };
  walk(dir);
  return hits;
}
function shelfJson(html) {
  const m = html.match(/<script type="application\/json" id="shelf-data">([\s\S]*?)<\/script>/);
  try { return m ? JSON.parse(m[1]) : null; } catch (e) { return 'BAD JSON: ' + e.message; }
}
function parseCsv(text) {
  const rows = [];
  let row = [], cell = '', q = false;
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (q) { if (c === '"' && text[i + 1] === '"') { cell += '"'; i++; } else if (c === '"') q = false; else cell += c; continue; }
    if (c === '"') q = true;
    else if (c === ',') { row.push(cell); cell = ''; }
    else if (c === '\r' && text[i + 1] === '\n') { row.push(cell); rows.push(row); row = []; cell = ''; i++; }
    else cell += c;
  }
  if (cell !== '' || row.length) { row.push(cell); rows.push(row); }
  return rows;
}

// Layout of the current page: horizontal overflow (document and single elements), and on phones the
// iOS-zoom rule (inputs >= 16px) and tap targets >= 44px.
async function measure(page, phone) {
  return page.evaluate(phone => {
    const de = document.documentElement;
    const cw = de.clientWidth;
    const vis = e => { if (e.closest('[hidden]') || e.closest('.vh')) return false; const r = e.getBoundingClientRect(); return r.width > 0 && r.height > 0 && getComputedStyle(e).visibility !== 'hidden'; };
    const clipped = e => { for (let a = e.parentElement; a && a !== document.body; a = a.parentElement) { if (getComputedStyle(a).overflowX !== 'visible') return true; } return false; };
    const label = e => (e.tagName.toLowerCase() + (e.id ? '#' + e.id : '') + (e.className && typeof e.className === 'string' ? '.' + e.className.trim().split(/\s+/).join('.') : '') + ' "' + (e.textContent || '').trim().slice(0, 24) + '"');
    const wide = [];
    for (const e of document.body.querySelectorAll('*')) {
      if (wide.length >= 5) break;
      const r = e.getBoundingClientRect();
      if (r.width === 0 || r.height === 0 || r.right <= cw + 1) continue;
      if (!vis(e) || clipped(e) || e.classList.contains('skip')) continue;
      wide.push(label(e) + ' right=' + Math.round(r.right));
    }
    const small = [], short = [];
    if (phone) {
      document.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=file]), select, textarea').forEach(e => {
        if (!vis(e)) return;
        const fs = parseFloat(getComputedStyle(e).fontSize);
        if (fs < 16) small.push((e.id || e.name || e.tagName) + ':' + fs);
      });
      document.querySelectorAll('button, .btn, summary, .fchip, .prow, .menu-list a, input[type=checkbox], .srow, [role=tab]').forEach(e => {
        if (!vis(e)) return;
        const t = e.matches('input[type=checkbox]') ? (e.closest('label') || e) : e;
        const r = t.getBoundingClientRect();
        if (r.height < 44 || r.width < 44) short.push(((e.textContent || e.id || e.tagName).trim().slice(0, 14)) + ':' + Math.round(r.width) + 'x' + Math.round(r.height));
      });
    }
    return { sw: de.scrollWidth, cw, wide, small, short };
  }, phone);
}
async function layoutCheck(T, page, name) {
  await sleep(120);
  const m = await measure(page, T.W < 600);
  check(T, `${name}: no horizontal overflow`, m.sw <= m.cw && m.wide.length === 0, { sw: m.sw, cw: m.cw, wide: m.wide });
  if (T.W < 600) {
    check(T, `${name}: inputs at least 16px (no iOS zoom)`, m.small.length === 0, m.small);
    check(T, `${name}: tap targets at least 44px`, m.short.length === 0, m.short);
  }
}
/** Console errors that appeared since mark (and belong to this page). */
function consoleSince(T, mark) { return T.R.console.slice(mark); }

async function login(T, page, pw) {
  await go(T, page, 'login');
  if (!page.url().includes('r=login')) return;
  await page.fill('#password', pw);
  await Promise.all([page.waitForNavigation(), page.click('main button[type=submit]')]);
}
async function ensureIn(T) {
  await closeSheets(T.page);
  await go(T, T.page, 'dashboard');
  if (T.page.url().includes('r=login')) await login(T, T.page, T.pw);
}
async function section(T, name, fn) {
  console.log(`\n  -- [${T.W}] ${name}`);
  try {
    await ensureIn(T);
    await fn();
  } catch (e) {
    check(T, `${name}: ran to the end without an exception`, false, String((e && e.stack) || e).slice(0, 900));
    const f = `${ERRDIR}/${T.W}-${name.replace(/[^\w\u4e00-\u9fff]+/g, '_').slice(0, 40)}.png`;
    await T.page.screenshot({ path: f, fullPage: true }).catch(() => {});
    console.log('  (screenshot ' + f + ')');
    await T.ctx.setOffline(false).catch(() => {});
    await T.page.unrouteAll({ behavior: 'ignoreErrors' }).catch(() => {});
    await closeSheets(T.page);
  }
}

// ---------- every admin page: console errors and overflow ----------
async function sweepAdmin(T, label) {
  const page = T.page;
  const pages = [
    ['dashboard', '', '概览'], ['site', '', '网站文字'], ['posts', '', '飞行日志'], ['posts', '&f=published', '飞行日志 已发布'],
    ['posts', '&f=teaser', '飞行日志 预告'], ['posts', '&f=draft', '飞行日志 草稿'], ['shop', '', '商品'], ['orders', '', '订单'], ['orders', '&f=all', '订单 全部'], ['pay', '', '收款设置'], ['subs', '', '订阅者'], ['stats', '', '访问统计'], ['game', '', '小游戏排行榜'],
    ['ai', '', 'AI 助手'], ['versions', '', '版本记录'], ['password', '', '修改密码'],
    ['no-such-page', '', '404 页'], ['post.edit', '&id=p000000000000', '找不到的日志'], ['versions.view', '&id=19990101-000000-abcdef', '找不到的版本'],
    ['logout', '', '用 GET 打开 POST 地址（405）'],
  ];
  for (const [r, q, name] of pages) {
    const mark = T.R.console.length;
    await go(T, page, r, q);
    if (r === 'site') await openAllDetails(page);
    await layoutCheck(T, page, `${label} ${name}`);
    check(T, `${label} ${name}: no console errors`, consoleSince(T, mark).length === 0, consoleSince(T, mark));
  }
  // the post editor of every post on the list, with its sheets
  await go(T, page, 'posts');
  const hrefs = await page.locator('.plist a.prow').evaluateAll(as => as.map(a => a.getAttribute('href')));
  for (const [i, href] of hrefs.entries()) {
    const mark = T.R.console.length;
    await page.goto(new URL(href, T.base + '/admin/').href);
    await page.waitForSelector('#p-title');
    await layoutCheck(T, page, `${label} 日志编辑器 ${i + 1}`);
    if (i === 0) {
      if (T.W < 1100) { await page.click('#tab-preview'); await sleep(1200); await layoutCheck(T, page, `${label} 日志编辑器（预览）`); await page.click('#tab-edit'); }
      await page.click('#bar [data-act="more"]');
      await layoutCheck(T, page, `${label} 日志编辑器「更多」`);
      await page.locator('.sheet-btns button').first().click();
      await page.click('.mdbar [data-md="help"]');
      await layoutCheck(T, page, `${label} 日志编辑器「怎么写」`);
      await page.locator('.sheet-btns button').first().click();
      await page.click('#p-body');
      await page.click('.mdbar [data-md="link"]');
      await layoutCheck(T, page, `${label} 日志编辑器「链接」`);
      await page.locator('.sheet-btns button').first().click();
    }
    check(T, `${label} 日志编辑器 ${i + 1}: no console errors`, consoleSince(T, mark).length === 0, consoleSince(T, mark));
  }
  if (T.W < 1024) {
    await go(T, page, 'dashboard');
    await openMenuIfPhone(page);
    await layoutCheck(T, page, `${label} 菜单`);
    await page.click('.menu-close');
  }
}

// ======================================================================================
async function run(browser, W) {
  console.log(`\n==== admin QA at ${W} px ====`);
  const tmp = makeSite();
  const T = { W, tmp, PUB: tmp + '/pub', DATA: tmp + '/data', FILES: tmp + '/files', pw: PW1, R: { console: [], dialogs: [], external: [], server: [], missing: [], php: [], beforeunload: 0 } };
  const port = await freePort(PORT0 + (WIDTHS.indexOf(W) * 10));
  T.base = 'http://127.0.0.1:' + port;
  const server = await startServer(T, tmp, port);
  const phone = W < 600;
  T.opt = phone ? { viewport: { width: W, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true } : { viewport: { width: W, height: 900 } };
  T.ctx = await browser.newContext({ ...T.opt, acceptDownloads: true });
  T.page = await newPage(T);
  T.anonCtx = await browser.newContext(T.opt);
  const SEED = sha(PUBLIC_REPO + '/index.html');
  try {
    await flows(T, browser, SEED);
  } finally {
    await T.ctx.close().catch(() => {});
    await T.anonCtx.close().catch(() => {});
    server.kill();
    if (KEEP) console.log('  kept ' + tmp); else fs.rmSync(tmp, { recursive: true, force: true });
  }
}

async function flows(T, browser, SEED) {
  const { page, PUB, DATA, FILES } = T;
  const anon = await newPage(T, T.anonCtx);

  // ---------------- first run ----------------
  console.log(`\n  -- [${T.W}] 第一次使用（设置码）`);
  {
    const mark = T.R.console.length;
    await page.goto(T.base + '/admin/');
    check(T, 'fresh install opens 第一次使用', page.url().includes('r=setup'), page.url());
    await layoutCheck(T, page, '设置页');
    const code = read(DATA + '/setup-code.txt').match(/[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}/)[0];
    check(T, 'the setup code is only in the file, never in the page', !(await page.content()).includes(code));
    await page.fill('#code', 'ABCD-EFGH-JKMN');
    await page.fill('#password', PW1);
    await page.fill('#password2', PW1);
    await Promise.all([page.waitForNavigation(), page.click('main button[type=submit]')]);
    check(T, 'a wrong setup code is refused in plain Chinese', (await page.textContent('main')).includes('设置码不对'));
    // typed on a phone: lower case, spaces instead of dashes
    await page.fill('#code', ' ' + code.toLowerCase().replace(/-/g, ' ') + ' ');
    await page.fill('#password', PW1);
    await page.fill('#password2', PW1);
    await Promise.all([page.waitForNavigation(), page.click('main button[type=submit]')]);
    check(T, 'the right code (typed in lower case with spaces) sets the password and opens 概览', (await page.textContent('h1').catch(() => '')) === '概览', page.url());
    check(T, 'setup-code.txt is deleted after setup', !fs.existsSync(DATA + '/setup-code.txt'));
    await go(T, page, 'setup');
    check(T, 'setup cannot be run a second time', !page.url().includes('r=setup'), page.url());
    check(T, 'setup and 概览: no console errors', consoleSince(T, mark).length === 0, consoleSince(T, mark));
  }

  // ---------------- publish with no changes = seed ----------------
  await section(T, '没改内容就发布：首页和种子一字不差', async () => {
    check(T, 'the first 概览 visit recorded version 1 (初始内容)', versionsCount(T) === 1, versionsCount(T));
    check(T, 'after the first 概览 visit index.html is still the seed', sha(PUB + '/index.html') === SEED);
    const launch = execFileSync('php', [PUBLIC_REPO + '/app/bin/render-launch.php']);
    check(T, 'the seed equals render-launch.php (T2)', crypto.createHash('sha256').update(launch).digest('hex') === SEED);
    await go(T, page, 'site');
    let st = await barAct(page, () => page.click('#bar [data-act="publish"]'));
    check(T, '网站文字 保存并发布 without changes reports 已发布', st.ok && st.text.includes('已发布'), st);
    check(T, '... and index.html is byte-identical to the seed', sha(PUB + '/index.html') === SEED);
    check(T, '... and no new version entry for an unchanged site', versionsCount(T) === 1, versionsCount(T));
    await go(T, page, 'posts', '&f=teaser');
    await page.locator('.plist a.prow').first().click();
    await page.waitForSelector('#p-title');
    await page.click('#bar [data-act="more"]');
    st = await barAct(page, () => sheetAct(page, '更新预告'));
    check(T, 'a seed teaser 更新预告 without changes: index.html byte-identical', st.ok && sha(PUB + '/index.html') === SEED, st);
    await go(T, page, 'dashboard');
    await page.locator('form[action*="publish.all"] button').click();
    await Promise.all([page.waitForNavigation(), sheetOk(page)]);
    check(T, '重新发布全部: index.html byte-identical', (await flashText(page)).includes('已重新生成') && sha(PUB + '/index.html') === SEED, await flashText(page));
    check(T, 'hero regions untouched (T3)', heroOk(PUB + '/index.html'));
    check(T, 'posts/index.html exists and lists no post yet', fs.existsSync(PUB + '/posts/index.html') && !/href="[a-z0-9-]+\.html"/.test(read(PUB + '/posts/index.html').replace(/href="\.\.\/[^"]*"/g, '')));
  });

  await section(T, '每个后台页面：控制台和横向溢出（种子内容）', async () => { await sweepAdmin(T, '种子'); });

  // ---------------- 网站文字 ----------------
  await section(T, '网站文字：贴进来的表情、换行、制表符、超长文字', async () => {
    await go(T, page, 'site');
    const before = sha(PUB + '/index.html');
    await openDet(page, '#g-hero');
    await pasteInto(page, '#f-hero-b1-hl', '三千英尺，|云顶之上 ✈️', true);
    await openDet(page, '#g-about');
    await pasteInto(page, '#f-about-h2', '关于我 👨‍✈️', true);
    // a three-paragraph answer copied out of WeChat: CRLF line ends, blank lines, emoji
    await pasteInto(page, '#f-about-body', '我是机长，飞 PC-12 NGX。🛩️\r\n\r\n航段很短，一天起落好几次。\r\n\r\n这里写的都是自己的经验，不代表公司。', true);
    // two lines with a tab, pasted from a spreadsheet into a one-line field
    await openDet(page, '#g-log');
    await pasteInto(page, '#f-log-lede', '第一行\r\n第二行\t第三段 😀', true);
    // far too long for a 20-character title
    await pasteInto(page, '#f-about-h2', '关于我这个在云底下面飞了很多年的普通机长 👨‍✈️', true);
    let st = await barAct(page, () => page.click('#bar [data-act="save"]'));
    check(T, 'an over-long title is refused with a plain Chinese reason naming the field', !st.ok && /关于.*标题.*最多 20 个字/.test(st.text), st);
    check(T, '... the field is marked and its message shown under it', await page.locator('[data-path="fields.about.h2"].has-err .ferr:not([hidden])').count() === 1);
    check(T, '... the counter turns red before saving', await page.locator('[data-path="fields.about.h2"] .count.over').count() === 1);
    check(T, '... nothing reached the public site', sha(PUB + '/index.html') === before);
    await pasteInto(page, '#f-about-h2', '关于我 👨‍✈️', true);
    check(T, '改过 badge counts the edited fields of a group', (await page.locator('#g-about .badge').textContent()) === '改过 2' && await page.locator('#g-about .badge').isVisible(), await page.locator('#g-about .badge').textContent());
    st = await barAct(page, () => page.click('#bar [data-act="save"]'));
    check(T, 'with emoji, CRLF and a tab the draft saves', st.ok && st.text.includes('已保存草稿'), st);
    check(T, 'saving a draft leaves index.html unchanged', sha(PUB + '/index.html') === before);
    const draft = JSON.parse(read(DATA + '/content/site.draft.json')).fields;
    check(T, 'the one-line field was joined into one line (no newline, no tab)', !/[\n\t]/.test(draft['log.lede']) && draft['log.lede'].includes('第三段 😀'), draft['log.lede']);
    check(T, 'the multi-paragraph field kept its paragraphs (LF only)', draft['about.body'].split('\n').filter(Boolean).length === 3 && !draft['about.body'].includes('\r'), draft['about.body']);
    // preview in a new tab
    const mark = T.R.console.length;
    const [pv] = await Promise.all([T.ctx.waitForEvent('page'), page.click('#bar [data-act="preview"]')]);
    track(T, pv);
    await pv.waitForLoadState('load');
    await sleep(600);
    const pvHtml = await pv.content();
    check(T, '预览 shows the draft (emoji title) with the 预览 banner', pvHtml.includes('关于我 👨‍✈️') && pvHtml.includes('预览：这是还没发布的草稿'));
    await pv.close();
    check(T, '预览: no console errors', consoleSince(T, mark).length === 0, consoleSince(T, mark));
    // FAQ: a new row with a pasted multi-line answer, moved up; a 数据格子 edit
    await openDet(page, '#g-faq');
    const n0 = await page.locator('#faq-list .item').count();
    await page.click('#faq-list [data-act="add"]');
    const row = page.locator('#faq-list .item').last();
    await row.locator('input[type=text]').nth(0).fill('夜里也飞吗？🌙');
    await row.locator('input[type=text]').nth(1).fill('偶尔');
    await row.locator('textarea').focus();
    await page.keyboard.insertText('夜航不多。\r\n冬天天黑得早，\r\n最后一段常常落在夜里。');
    await row.locator('[data-act="up"]').click();
    const firstQ = await page.locator('#faq-list .item').first().locator('input[type=text]').nth(0).inputValue();
    await page.locator('#faq-list .item').first().locator('[data-act="del"]').click();
    await sheetOk(page);
    check(T, 'FAQ 删除 (confirmed) removes the row', await page.locator('#faq-list .item').count() === n0 && (await page.locator('#faq-list .item').first().locator('input[type=text]').nth(0).inputValue()) !== firstQ);
    check(T, 'FAQ 加一条 + 上移', (await page.locator('#faq-list .item').nth(n0 - 2).locator('input[type=text]').nth(0).inputValue()) === '夜里也飞吗？🌙');
    await openDet(page, '#g-type');
    const factVal = page.locator('#facts-list .item').first().locator('input[type=text]').nth(1);
    await factVal.fill('PT6E-67XP ⚙️');
    // the owner taps 保存并发布 and the phone loses the answer (the server got it): retry must not claim a conflict
    await page.route(u => u.href.includes('r=site.publish'), async route => { try { await route.fetch(); } catch (e) { /* ignore */ } await route.abort('connectionreset'); }, { times: 1 });
    T.R.netDown = true;
    st = await barAct(page, () => page.click('#bar [data-act="publish"]'));
    T.R.netDown = false;
    check(T, 'a lost answer to 发布 shows the no-network message, text kept', !st.ok && st.text.includes('网络断了') && (await page.inputValue('#f-about-h2')) === '关于我 👨‍✈️', st);
    st = await barAct(page, () => page.click('#bar [data-act="publish"]'));
    check(T, 'tapping 保存并发布 again succeeds (no false "another window" conflict)', st.ok && st.text.includes('已发布'), st);
    const home = read(PUB + '/index.html');
    check(T, 'published: emoji headline with its line break hint', home.includes('三千英尺，<wbr>云顶之上 ✈️'));
    check(T, 'published: the about title and three paragraphs', home.includes('关于我 👨‍✈️') && home.includes('航段很短，一天起落好几次。'));
    check(T, 'published: the pasted one-line text is one line', /第一行 第二行 第三段 😀/.test(home), (home.match(/第一行[^<]*/) || [''])[0]);
    check(T, 'published: the deleted FAQ row is gone', !home.includes('>' + firstQ + '<'), firstQ);
    check(T, 'published: the new FAQ row, its answer on one line', home.includes('夜里也飞吗？🌙') && home.includes('夜航不多。 冬天天黑得早， 最后一段常常落在夜里。'));
    check(T, 'hero regions untouched after editing hero text (T3)', heroOk(PUB + '/index.html'));
    const vmark = T.R.console.length;
    await anon.goto(T.base + '/', { waitUntil: 'load' });
    await sleep(900);
    check(T, 'visitor sees the new about title', (await anon.textContent('#about-h')) === '关于我 👨‍✈️');
    const m = await anon.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }));
    check(T, 'visitor home page: no horizontal overflow', m.sw <= m.cw, m);
    check(T, 'visitor home page: no console errors', consoleSince(T, vmark).filter(s => !/chat\.php/.test(s)).length === 0, consoleSince(T, vmark));
    // 放弃草稿
    await go(T, page, 'site');
    await openDet(page, '#g-about');
    await pasteInto(page, '#f-about-h2', '要放弃的标题', true);
    st = await barAct(page, () => page.click('#bar [data-act="save"]'));
    await page.click('#bar [data-act="more"]');
    await sheetAct(page, '放弃草稿');
    await Promise.all([page.waitForNavigation(), sheetOk(page)]);
    check(T, '放弃草稿 brings back the published text', (await page.inputValue('#f-about-h2')) === '关于我 👨‍✈️' && (await flashText(page)).includes('已放弃草稿'), await page.inputValue('#f-about-h2'));
    // the longest allowed blog name: the admin shell must still fit a phone
    await openDet(page, '#g-site');
    const longBrand = '云底之下｜一位PC-12机长的飞行日志记';
    await pasteInto(page, '#f-site-brand', longBrand, true);
    st = await barAct(page, () => page.click('#bar [data-act="publish"]'));
    check(T, 'a 20-character blog name publishes', st.ok, st);
    for (const r of ['dashboard', 'site', 'posts', 'subs']) {
      await go(T, page, r);
      await layoutCheck(T, page, `最长的博客名 ${r}`);
    }
    await anon.goto(T.base + '/', { waitUntil: 'load' });
    const m2 = await anon.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }));
    if (m2.sw > m2.cw) info(T, `the public nav (hero code, not editable) overflows with a 20-character blog name: ${JSON.stringify(m2)}; the editor warns above 6 characters`);
    await go(T, page, 'site');
    await openDet(page, '#g-site');
    await pasteInto(page, '#f-site-brand', '云底之下', true);
    st = await barAct(page, () => page.click('#bar [data-act="publish"]'));
    check(T, 'blog name back to 云底之下', st.ok && read(PUB + '/index.html').includes('<title>云底之下'), st);
  });

  await section(T, '概览：网站地址、计数、待发布提醒', async () => {
    originPlaceholder(T.DATA);
    await go(T, page, 'dashboard');
    check(T, '概览 says the site address is missing', (await page.textContent('main')).includes('网站地址还没填'));
    await Promise.all([page.waitForNavigation(), page.locator('form[action*="site.origin"] button').click()]);
    check(T, 'one tap sets https://<this host> and republishes', (await flashText(page)).includes('网站地址已经设成') && read(PUB + '/index.html').includes(`content="https://127.0.0.1:${new URL(T.base).port}/assets/og.jpg"`), await flashText(page));
    check(T, '... the banner is gone', !(await page.textContent('main')).includes('网站地址还没填'));
    check(T, '概览 counts 0 已发布 / 5 预告 / 0 草稿', (await page.locator('#c-log ~ dl dd').allTextContents()).join(',') === '0,5,0');
    check(T, '概览 says everything is published', (await page.textContent('main')).includes('后台的内容都已经发布了'));
    await layoutCheck(T, page, '概览（地址已填）');
  });

  await section(T, '网站文字：找文字、没保存就离开、登录过期时保存', async () => {
    await go(T, page, 'site');
    await page.fill('#find', '订阅');
    const shown = await page.locator('#site-form details.grp:not([hidden])').count();
    check(T, '找文字「订阅」 narrows the groups', shown > 0 && shown < 14, shown);
    await page.fill('#find', '没有这个词');
    check(T, '... says so when nothing matches', await page.locator('#find-none:not([hidden])').count() === 1);
    await page.fill('#find', '');
    check(T, '... an empty box shows every group again', await page.locator('#site-form details.grp[hidden]').count() === 0);
    const MARK2 = 'SD' + crypto.randomBytes(4).toString('hex');
    await openDet(page, '#g-about');
    const h2Live = await page.inputValue('#f-about-h2');
    await pasteInto(page, '#f-about-h2', MARK2, true);
    await openMenuIfPhone(page);
    await page.locator('#menu a', { hasText: '概览' }).click();
    await page.waitForSelector('.sheet', { timeout: 5000 }).catch(() => {});
    check(T, 'leaving with unsaved text asks 有改动还没保存', (await page.locator('.sheet').count()) === 1 && (await page.textContent('.sheet')).includes('有改动还没保存'));
    await page.locator('.sheet-btns button', { hasText: '取消' }).click();
    if (await page.locator('#menu.open').count()) await page.click('.menu-close');
    check(T, '... 取消 keeps the page and the text', page.url().includes('r=site') && (await page.inputValue('#f-about-h2')) === MARK2);
    // the session runs out while the owner is typing (phone left in a pocket)
    for (const f of fs.readdirSync(DATA + '/sessions')) fs.unlinkSync(DATA + '/sessions/' + f);
    await page.click('#bar [data-act="save"]');
    await page.waitForSelector('.sheet input[type=password]', { timeout: 8000 }).catch(() => {});
    check(T, 'expired session: the save opens the 登录已经过期 sheet', (await page.locator('.sheet input[type=password]').count()) === 1 && (await page.textContent('.sheet')).includes('你写的内容还在'));
    await page.fill('.sheet input[type=password]', 'not-the-password');
    await page.locator('.sheet-btns button').last().click();
    await page.waitForSelector('.sheet .ferr:not([hidden])', { timeout: 8000 }).catch(() => {});
    check(T, '... a wrong password says 密码不对 in the sheet', ((await page.textContent('.sheet .ferr').catch(() => '')) || '').includes('密码不对'));
    const st = await barAct(page, async () => { await page.fill('.sheet input[type=password]', T.pw); await page.locator('.sheet-btns button').last().click(); });
    check(T, '... the right password continues and the save goes through, text kept', st.ok && st.text.includes('已保存草稿') && (await page.inputValue('#f-about-h2')) === MARK2, st);
    check(T, 'the saved site draft is nowhere in the public dir', grepTree(PUB, MARK2).length === 0);
    const r = await T.anonCtx.request.post(T.base + '/admin/?r=preview.home', { form: { site: '{}' }, failOnStatusCode: false });
    check(T, '... and a visitor cannot open the draft preview', !(await r.text()).includes(MARK2));
    await go(T, page, 'dashboard');
    check(T, '概览 links to 网站文字有没发布的修改', await page.locator('.pending a', { hasText: '网站文字有没发布的修改' }).count() === 1);
    await go(T, page, 'site');
    await page.click('#bar [data-act="more"]');
    await sheetAct(page, '放弃草稿');
    await Promise.all([page.waitForNavigation(), sheetOk(page)]);
    check(T, '放弃草稿 again: back to the published text', (await page.inputValue('#f-about-h2')) === h2Live);
  });

  // ---------------- 飞行日志 ----------------
  const MARK = 'DRAFTMARK' + crypto.randomBytes(4).toString('hex');
  const TITLE = '夜航带飞：落地后的几点想法 🌙（第一篇）';
  const P = {};
  await section(T, '飞行日志：新草稿、贴进来的标题和正文、草稿不公开', async () => {
    await go(T, page, 'posts');
    await page.locator('.chips a', { hasText: '预告' }).click();
    check(T, 'filter 预告 lists the 5 teasers', await page.locator('.plist .prow').count() === 5);
    await go(T, page, 'posts');
    const n0 = fs.readdirSync(DATA + '/posts').length;
    await Promise.all([page.waitForNavigation(), page.locator('form[action*="post.new"] button').first().dblclick()]);
    await sleep(500);
    check(T, 'double-tapping 写新日志 makes one draft, not two', fs.readdirSync(DATA + '/posts').length === n0 + 1, [n0, fs.readdirSync(DATA + '/posts').length]);
    P.url = page.url();
    P.id = new URL(page.url()).searchParams.get('id');
    P.fix = await page.inputValue('#p-fix');
    P.slug = await page.inputValue('#p-slug');
    check(T, '写新日志 opens the editor with a fix and an address', /^p[0-9a-f]{12}$/.test(P.id) && /^[A-Z]{5}$/.test(P.fix) && P.slug === P.fix.toLowerCase(), P);
    // the title copied from a chat: two lines and an emoji
    await pasteInto(page, '#p-title', '夜航带飞：落地后的几点想法 🌙\r\n（第一篇）', true);
    check(T, 'a two-line title paste becomes one line in the title box', !/[\r\n]/.test(await page.inputValue('#p-title')));
    // far too long
    await pasteInto(page, '#p-title', '夜航带飞：落地后的几点想法，还有很多很多想说的话'.repeat(3), true);
    check(T, 'the title counter turns red past 60 characters', await page.locator('[data-path="title"] .count.over').count() === 1);
    let st = await barAct(page, () => page.click('#bar [data-act="save"]'));
    check(T, 'an over-long title is refused in plain Chinese', !st.ok && st.text.includes('标题最多 60 个字'), st);
    check(T, '... shown under the title field', (await page.textContent('[data-path="title"] .ferr')).includes('标题最多 60 个字'));
    await pasteInto(page, '#p-title', TITLE, true);
    await page.fill('#p-body', '');
    await page.click('#p-body');
    await page.click('.mdbar [data-md="link"]');
    await sheetReady(page);
    await page.locator('.sheet input').nth(0).fill('风的预报');
    await page.locator('.sheet input').nth(1).fill('https://www.windy.com');
    await sheetOk(page);
    await page.waitForFunction(() => document.getElementById('p-body').value !== '', null, { timeout: 3000 }).catch(() => {});
    check(T, 'toolbar 链接 inserts [文字](网址)', (await page.inputValue('#p-body')) === '[风的预报](https://www.windy.com)', await page.inputValue('#p-body'));
    for (const [md, want] of [['h2', '## 小标题'], ['bold', '**加粗的字**'], ['ul', '- 一条'], ['quote', '> 一句提醒']]) {
      await page.fill('#p-body', '');
      await page.click('#p-body');
      await page.click(`.mdbar [data-md="${md}"]`);
      check(T, `toolbar ${md} inserts ${want}`, (await page.inputValue('#p-body')) === want, await page.inputValue('#p-body'));
    }
    await page.fill('#p-body', '长'.repeat(60001));
    let stb = await barAct(page, () => page.click('#bar [data-act="save"]'));
    check(T, 'a 60 001-character body is refused: 正文最多 60000 个字', !stb.ok && stb.text.includes('正文最多 60000 个字'), stb);
    const LONGURL = 'https://example.com/flight/' + 'night-approach-notes-'.repeat(8) + 'end';
    await pasteInto(page, '#p-body', '## 落地以后\r\n跑道灯很亮，风不大。🛬\r\n\r\n参考：' + LONGURL + '\r\n- 第一条：别急\r\n- 第二条：看仪表\r\n**记住**：复飞不丢人。\r\n' + MARK, true);
    await pasteInto(page, '#p-tags', 'IMC，夜航 🌙', true);
    await page.fill('#p-date', '2026-10-12');
    await pasteInto(page, '#p-fix', 'nite', true);
    st = await barAct(page, () => page.click('#bar [data-act="save"]'));
    check(T, 'a 4-letter fix is refused with the rule spelled out', !st.ok && (await page.textContent('[data-path="fix"] .ferr')).includes('5 个大写英文字母'), st);
    await pasteInto(page, '#p-fix', 'nitex', true);
    check(T, 'the fix is upper-cased while typing, the address follows it', (await page.inputValue('#p-fix')) === 'NITEX' && (await page.inputValue('#p-slug')) === 'nitex');
    st = await barAct(page, () => page.click('#bar [data-act="save"]'));
    check(T, 'the post saves as a draft', st.ok && st.text.includes('已保存草稿'), st);
    P.fix = 'NITEX'; P.slug = 'nitex';
    const saved = JSON.parse(read(`${DATA}/posts/${P.id}.json`));
    check(T, 'tags split on a Chinese comma, emoji tag kept', JSON.stringify(saved.draft.tags) === JSON.stringify(['IMC', '夜航', '🌙']), saved.draft.tags);
    check(T, 'the body is stored with LF line ends', !saved.draft.body.includes('\r') && saved.draft.body.includes('🛬'));
    // drafts never reachable publicly
    check(T, 'the draft text is nowhere in the public dir', grepTree(PUB, MARK).length === 0 && grepTree(PUB, '夜航带飞').length === 0, grepTree(PUB, MARK));
    const guesses = [`/posts/${P.slug}.html`, `/posts/${P.slug.toUpperCase()}.html`, `/posts/${P.fix}.html`, `/posts/${P.id}.html`, `/posts/${P.id}.json`,
      `/posts/${P.slug}`, `/posts/${P.slug}/`, `/posts/${P.slug}.html?preview=1`, `/posts/`, `/posts/index.html`, `/`, `/index.html`,
      `/blog-data/posts/${P.id}.json`, `/data/posts/${P.id}.json`, `/%2e%2e/data/posts/${P.id}.json`, `/posts/..%2f..%2fdata/posts/${P.id}.json`,
      `/uploads/index.json`, `/uploads/`, `/uploads/${new Date().getUTCFullYear()}/`, `/app/seed/launch.patch.json`, `/admin/?r=post.edit&id=${P.id}`, `/admin/?r=preview.post&id=${P.id}`, `/admin/?r=versions`,
      `/admin/index.php?r=post.edit&id=${P.id}`, `/api/subscribe.php?id=${P.id}`];
    const leaks = [];
    for (const u of guesses) {
      const r = await T.anonCtx.request.get(T.base + u, { failOnStatusCode: false });
      const body = await r.text();
      if (body.includes(MARK) || body.includes('夜航带飞')) leaks.push(r.status() + ' ' + u);
      if (u.startsWith(`/posts/${P.slug}`) && r.status() === 200) leaks.push('200 ' + u);
    }
    check(T, `a visitor guessing ${guesses.length} URLs never sees the draft`, leaks.length === 0, leaks);
    const r = await T.anonCtx.request.post(T.base + `/admin/?r=preview.post`, { form: { id: P.id, post: '{}' }, failOnStatusCode: false, maxRedirects: 0 });
    check(T, 'the draft preview needs a login', !(await r.text()).includes(MARK) && r.status() !== 200, r.status());
  });

  await section(T, '飞行日志：iPhone 照片（HEIC、12 MB）、预览、连点两下发布', async () => {
    await page.goto(P.url);
    await page.waitForSelector('#p-title');
    // the phone sat for two hours: the session is gone when the photo is picked
    for (const f of fs.readdirSync(DATA + '/sessions')) fs.unlinkSync(DATA + '/sessions/' + f);
    await page.setInputFiles('#cover-file', FILES + '/cover.jpg');
    await page.waitForSelector('.sheet input[type=password]', { timeout: 10000 }).catch(() => {});
    let st0 = await barAct(page, async () => { await page.fill('.sheet input[type=password]', T.pw); await page.locator('.sheet-btns button').last().click(); });
    check(T, 'a photo picked after the session ran out: re-login sheet, then it uploads', st0.ok && st0.text.includes('封面图已上传'), st0);
    // HEIC straight from the Files app
    let st = await barAct(page, () => page.setInputFiles('#cover-file', { name: 'IMG_0043.HEIC', mimeType: 'image/heic', buffer: fs.readFileSync(FILES + '/IMG_0043.HEIC') }));
    check(T, 'a HEIC photo gets a clear Chinese how-to, no crash', st.err && st.text.includes('HEIC') && st.text.includes('兼容性最佳'), st);
    check(T, '... the same message under the cover field', (await page.textContent('[data-path="cover"] .ferr')).includes('HEIC'));
    fs.writeFileSync(FILES + '/shell.jpg', "<?php system($_GET['c']); ?>\n");
    st = await barAct(page, () => page.setInputFiles('#cover-file', FILES + '/shell.jpg'));
    check(T, 'a PHP file renamed .jpg is refused (只能上传 JPG、PNG…)', st.err && st.text.includes('只能上传'), st);
    // a damaged 12.5 MB file the phone cannot shrink
    st = await barAct(page, () => page.setInputFiles('#cover-file', FILES + '/IMG_0044.JPG'), 60000);
    check(T, 'a 12.5 MB file the phone cannot shrink is refused: 图片太大了', st.err && st.text.includes('图片太大了') && st.text.includes('12 MB'), st);
    // a real 12 MB iPhone photo: shrunk on the phone, uploaded, upright
    const big = fs.statSync(FILES + '/IMG_0042.JPG').size;
    // note the size of the image the page hands to fetch()
    await page.evaluate(() => { const f = window.fetch; window.fetch = function (u, i) { if (String(u).includes('r=upload') && i && i.body instanceof FormData) window.__upSize = i.body.get('image').size; return f.apply(this, arguments); }; });
    st = await barAct(page, () => page.setInputFiles('#cover-file', FILES + '/IMG_0042.JPG'), 90000);
    const sent = await page.evaluate(() => window.__upSize || 0);
    check(T, `a ${(big / 1048576).toFixed(1)} MB iPhone photo uploads as the cover`, st.ok && st.text.includes('封面图已上传'), st);
    check(T, `... the phone sent a shrunk copy (${(sent / 1048576).toFixed(1)} MB)`, sent > 0 && sent < 6 * 1048576, sent);
    const idx = JSON.parse(read(DATA + '/uploads/index.json')).items;
    const newest = Object.entries(idx).sort((a, b) => a[1].created_at < b[1].created_at ? 1 : -1)[0];
    check(T, '... stored upright (EXIF rotation applied), long edge 2000 px', newest && newest[1].h > newest[1].w && Math.max(newest[1].w, newest[1].h) === 2000, newest && newest[1]);
    check(T, '... the cover shows in the editor', await page.evaluate(() => new Promise(res => { const i = document.getElementById('cover-img'); const ok = () => res(i.naturalWidth > 0 && !i.hidden); if (i.complete) ok(); else { i.onload = ok; i.onerror = () => res(false); } })));
    await page.fill('#p-cover-alt', '夜里的跑道 🌙');
    // a PNG inside the body
    await page.click('#p-body');
    await page.locator('#p-body').press('Control+End');
    await page.setInputFiles('#body-file', FILES + '/photo.png');
    await sheetReady(page);
    await page.locator('.sheet input').nth(0).fill('橙色的天');
    await sheetOk(page);
    check(T, '插入图片 writes the image line into the body', /!\[橙色的天\]\(uploads\/\d{4}\/\d{2}\/[0-9a-f]{16}\.webp\)/.test(await page.inputValue('#p-body')));
    // preview
    const mark = T.R.console.length;
    if (T.W < 1100) await page.click('#tab-preview');
    await sleep(1800);
    const fr = page.frame({ name: 'pv' });
    await fr.waitForSelector('h1', { timeout: 10000 }).catch(() => {});
    check(T, 'the preview shows the emoji title, cover and body image', ((await fr.textContent('h1').catch(() => '')) || '').includes('🌙') && await fr.locator('img').count() >= 2);
    const pm = await fr.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth })).catch(() => null);
    check(T, 'the preview has no horizontal overflow (long URL in the body)', pm && pm.sw <= pm.cw, pm);
    if (T.W < 1100) await page.click('#tab-edit');
    check(T, 'post editor with preview: no console errors', consoleSince(T, mark).length === 0, consoleSince(T, mark));
    // double tap on 发布
    const v0 = versionsCount(T);
    let pubReqs = 0;
    const onPub = q => { if (q.url().includes('r=post.publish')) pubReqs++; };
    page.on('request', onPub);
    st = await barAct(page, () => page.dblclick('#bar [data-act="publish"]'));
    await sleep(1500);
    page.off('request', onPub);
    check(T, 'double-tapping 发布 publishes once, no error', st.ok && st.text.includes('已发布') && pubReqs === 1, { st, pubReqs });
    check(T, '... exactly one new version', versionsCount(T) === v0 + 1, [v0, versionsCount(T)]);
    check(T, '... the button now reads 发布修改, address locked', (await page.textContent('#bar [data-act="publish"]')) === '发布修改' && await page.locator('#p-slug[readonly]').count() === 1);
    const vmark = T.R.console.length;
    const r = await anon.goto(`${T.base}/posts/${P.slug}.html`, { waitUntil: 'load' });
    check(T, 'the post page is public', r.status() === 200 && (await anon.textContent('h1')).includes(TITLE));
    await anon.evaluate(async () => { for (const i of document.images) { i.scrollIntoView(); if (!i.complete) await new Promise(res => { i.onload = i.onerror = res; setTimeout(res, 4000); }); } window.scrollTo(0, 0); });
    const m = await anon.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth, imgs: [...document.images].map(i => i.complete && i.naturalWidth > 0) }));
    check(T, 'post page: no horizontal overflow with a long pasted URL', m.sw <= m.cw, m);
    check(T, 'post page: cover and body image load', m.imgs.length >= 2 && m.imgs.every(Boolean), m.imgs);
    check(T, 'post page: no console errors, no scripts', consoleSince(T, vmark).length === 0 && await anon.locator('script').count() === 0, consoleSince(T, vmark));
    check(T, 'home leg links to the post; archive lists it', read(PUB + '/index.html').includes(`href="posts/${P.slug}.html"`) && read(PUB + '/posts/index.html').includes(`href="${P.slug}.html"`));
    await anon.goto(T.base + '/', { waitUntil: 'load' });
    await sleep(600);
    const hm = await anon.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }));
    check(T, 'home page with the new leg: no horizontal overflow', hm.sw <= hm.cw, hm);
    check(T, 'hero regions untouched after a post publish (T3)', heroOk(PUB + '/index.html'));
  });

  await section(T, '飞行日志：两个窗口改同一篇', async () => {
    await page.goto(P.url);
    await page.waitForSelector('#p-title');
    const B = await newPage(T);
    await B.goto(P.url);
    await B.waitForSelector('#p-title');
    await pasteInto(page, '#p-body', '\nA 窗口补的一句。');
    let st = await barAct(page, () => page.click('#bar [data-act="save"]'));
    check(T, 'window A saves', st.ok, st);
    await pasteInto(B, '#p-excerpt', 'B 窗口写的摘要。', true);
    st = await barAct(B, () => B.click('#bar [data-act="save"]'));
    check(T, 'window B gets a clear conflict message instead of overwriting A', !st.ok && st.text.includes('在别处改过了'), st);
    check(T, '... B keeps its text on the page', (await B.inputValue('#p-excerpt')) === 'B 窗口写的摘要。');
    const onDisk = JSON.parse(read(`${DATA}/posts/${P.id}.json`)).draft;
    check(T, "... A's saved text is not overwritten", onDisk.body.includes('A 窗口补的一句。') && onDisk.excerpt !== 'B 窗口写的摘要。');
    // the message says: refresh, then save. The owner refreshes right away.
    await B.reload({ waitUntil: 'load' });
    await B.waitForSelector('#restore:not([hidden])', { timeout: 4000 }).catch(() => {});
    check(T, 'after the refresh B offers its unsaved text back (发现一份没保存的内容)', (await B.locator('#restore:not([hidden])').count()) === 1 && (await B.textContent('#restore')).includes('发现一份没保存的内容'));
    if (await B.locator('#restore:not([hidden])').count()) {
      await B.locator('#restore button', { hasText: '恢复' }).click();
      check(T, '恢复 puts B\'s excerpt back', (await B.inputValue('#p-excerpt')) === 'B 窗口写的摘要。');
      st = await barAct(B, () => B.click('#bar [data-act="save"]'));
      check(T, 'B can now save', st.ok, st);
    }
    await B.close();
    // phone habit: type, then leave within a second (iOS Safari has no "leave page?" question)
    await page.goto(P.url);
    await page.waitForSelector('#p-title');
    await pasteInto(page, '#p-excerpt', '刚写完就离开的摘要。', true);
    await sleep(300);
    await page.goto(T.base + '/admin/?r=posts');
    await page.goto(P.url);
    await page.waitForSelector('#restore:not([hidden])', { timeout: 4000 }).catch(() => {});
    check(T, 'text typed a moment before leaving the page is offered back', (await page.locator('#restore:not([hidden])').count()) === 1);
    if (await page.locator('#restore:not([hidden])').count()) await page.locator('#restore button', { hasText: '丢掉' }).click();
  });

  await section(T, '飞行日志：断网时发布，联网后再点', async () => {
    await page.goto(P.url);
    await page.waitForSelector('#p-title');
    const postFile = `${PUB}/posts/${P.slug}.html`;
    await pasteInto(page, '#p-body', '\n离线时写的一句。');
    await T.ctx.setOffline(true);
    T.R.netDown = true;
    let st = await barAct(page, () => page.click('#bar [data-act="publish"]'));
    T.R.netDown = false;
    check(T, 'offline 发布: plain message, nothing lost', !st.ok && st.text.includes('网络断了') && (await page.inputValue('#p-body')).includes('离线时写的一句。'), st);
    check(T, '... the public page did not change', !read(postFile).includes('离线时写的一句'));
    await T.ctx.setOffline(false);
    st = await barAct(page, () => page.click('#bar [data-act="publish"]'));
    check(T, 'back online, 发布修改 again works', st.ok && st.text.includes('已发布') && read(postFile).includes('离线时写的一句。'), st);
    // the request reaches the server but the answer is lost on a bad mobile network
    await pasteInto(page, '#p-body', '\n信号不好时发的一句。');
    await page.route(u => u.href.includes('r=post.publish'), async route => { try { await route.fetch(); } catch (e) { /* ignore */ } await route.abort('connectionreset'); }, { times: 1 });
    T.R.netDown = true;
    st = await barAct(page, () => page.click('#bar [data-act="publish"]'));
    T.R.netDown = false;
    check(T, 'a lost answer shows the no-network message', !st.ok && st.text.includes('网络断了'), st);
    st = await barAct(page, () => page.click('#bar [data-act="publish"]'));
    check(T, 'tapping 发布修改 again succeeds (no false "another window" conflict)', st.ok && st.text.includes('已发布'), st);
    check(T, '... and the page has the sentence once', (read(postFile).match(/信号不好时发的一句/g) || []).length === 1);
  });

  await section(T, '飞行日志：撤下、预告、改回预告、删除、首页篇数', async () => {
    await page.goto(P.url);
    await page.waitForSelector('#p-title');
    await page.click('#bar [data-act="more"]');
    await sheetAct(page, '撤下');
    let st = await barAct(page, () => sheetOk(page));
    let r = await T.anonCtx.request.get(`${T.base}/posts/${P.slug}.html`, { failOnStatusCode: false });
    check(T, '撤下: page gone (404) and off the home page', st.ok && st.text.includes('已撤下') && r.status() === 404 && !read(PUB + '/index.html').includes(P.slug + '.html'), [st, r.status()]);
    await pasteInto(page, '#p-excerpt', '夜航带飞的几条体会。', true);
    await page.fill('#p-minutes', '');
    await page.click('#bar [data-act="more"]');
    st = await barAct(page, () => sheetAct(page, '设为预告'));
    check(T, '设为预告 without a reading time says what is missing', !st.ok && st.text.includes('阅读时间'), st);
    await page.fill('#p-minutes', '６');   // typed with a Chinese input method in full-width mode
    await page.click('#bar [data-act="more"]');
    st = await barAct(page, () => sheetAct(page, '设为预告'));
    const home = read(PUB + '/index.html');
    check(T, 'a full-width ６ in 阅读时间 counts as 6', JSON.parse(read(`${DATA}/posts/${P.id}.json`)).draft.minutes === 6);
    check(T, '设为预告: title on the home page, no link', st.ok && home.includes(TITLE.replace('🌙', '🌙')) && !home.includes(`posts/${P.slug}.html`), st);
    st = await barAct(page, () => page.click('#bar [data-act="publish"]'));
    check(T, '发布 from 预告 gives the page back', st.ok && fs.existsSync(`${PUB}/posts/${P.slug}.html`), st);
    await page.click('#bar [data-act="more"]');
    await sheetAct(page, '改回预告');
    st = await barAct(page, () => sheetOk(page));
    r = await T.anonCtx.request.get(`${T.base}/posts/${P.slug}.html`, { failOnStatusCode: false });
    check(T, '改回预告 (confirmed): page 404, title stays as a teaser', st.ok && r.status() === 404 && read(PUB + '/index.html').includes('夜航带飞'), [st, r.status()]);
    await page.click('#bar [data-act="more"]');
    await sheetAct(page, '删除这篇');
    await page.waitForSelector('.sheet-btns .btn-danger');
    await Promise.all([page.waitForNavigation(), page.locator('.sheet-btns .btn-danger').click()]);
    check(T, '删除 goes back to the list, gone from home, kept in the trash', page.url().includes('r=posts') && !read(PUB + '/index.html').includes('夜航带飞') && fs.readdirSync(DATA + '/trash/posts').some(f => f.startsWith(P.id)), await flashText(page));
    await page.selectOption('#leg_limit', '3');
    await Promise.all([page.waitForNavigation(), page.locator('form[action*="posts.limit"] button').click()]);
    check(T, '首页最多显示 3 篇 saves and republishes', (await flashText(page)).includes('首页最多显示 3 篇'), await flashText(page));
    await page.selectOption('#leg_limit', '6');
    await Promise.all([page.waitForNavigation(), page.locator('form[action*="posts.limit"] button').click()]);
  });

  // ---------------- 商城 ----------------
  await section(T, '商城：加商品，填错价格，没照片不能上架，收款设置，暂停营业', async () => {
    await go(T, page, 'shop');
    await Promise.all([page.waitForNavigation(), page.click('form[action*="shop.new"] button')]);
    await page.fill('#s-name', '夜航航路图海报 🌙');
    await page.fill('#s-price', '九十九');
    await page.fill('#s-stock', '５');
    await page.check('input[name=on_sale]');
    await Promise.all([page.waitForNavigation(), page.click('form[action*="shop.save"] button[type=submit]')]);
    check(T, 'a price in words is refused in plain Chinese, the typed name is kept', (await flashText(page)).includes('价格') && (await page.inputValue('#s-name')) === '夜航航路图海报 🌙');
    check(T, '... and 上架 without a photo is refused too', (await page.textContent('main')).includes('一张照片'));
    await Promise.all([page.waitForNavigation(), page.setInputFiles('#ph-file', FILES + '/photo.png')]);
    check(T, 'choosing a photo uploads it right away', (await flashText(page)).includes('照片已上传') && (await page.locator('.ph-item').count()) === 1, await flashText(page));
    await page.fill('#s-name', '夜航航路图海报 🌙');
    await page.fill('#s-price', '９９.５');
    await page.fill('#s-stock', '５');
    await page.check('input[name=on_sale]');
    await Promise.all([page.waitForNavigation(), page.click('form[action*="shop.save"] button[type=submit]')]);
    check(T, 'full-width price and stock are understood; 上架 publishes', (await flashText(page)).includes('网站已经更新') && read(PUB + '/index.html').includes('99.50'), await flashText(page));
    await layoutCheck(T, page, '商品编辑');
    const vmark = T.R.console.length;
    await anon.goto(T.base + '/', { waitUntil: 'load' });
    await sleep(700);
    check(T, 'visitor home shows the product card', (await anon.textContent('#shop')).includes('夜航航路图海报 🌙'));
    await Promise.all([anon.waitForNavigation(), anon.click('#shop .good-a')]);
    check(T, 'product page opens with no console errors', (await anon.textContent('h1')).includes('夜航航路图海报') && consoleSince(T, vmark).length === 0, consoleSince(T, vmark));
    // 收款设置
    await go(T, page, 'pay');
    await page.fill('#p-ship', '十块');
    await Promise.all([page.waitForNavigation(), page.click('form:has(input[value=rules]) button[type=submit]')]);
    check(T, 'shipping fee in words refused, form kept', (await flashText(page)).includes('运费') && (await page.inputValue('#p-ship')) === '十块');
    await page.fill('#p-ship', '10');
    await Promise.all([page.waitForNavigation(), page.click('form:has(input[value=rules]) button[type=submit]')]);
    check(T, 'shipping fee saved', (await flashText(page)).includes('已保存') && read(PUB + '/shop/' + (read(PUB + '/index.html').match(/href="shop\/([a-z0-9-]+)\.html"/) || [])[1] + '.html').includes('运费每单 ¥10.00'));
    await Promise.all([page.waitForNavigation(), page.setInputFiles('#qr-wechat', FILES + '/photo.png')]);
    check(T, '微信收款码 uploads right away', (await flashText(page)).includes('收款码已更新') && (await page.locator('.qr-img').count()) === 1, await flashText(page));
    await page.click('form:has(input[value=switch]) button');
    await Promise.all([page.waitForNavigation(), sheetOk(page)]);
    check(T, '暂停营业 asks first, then the home page says 暂停营业', read(PUB + '/index.html').includes('CLSD · 暂停营业'));
    await Promise.all([page.waitForNavigation(), page.click('form:has(input[value=switch]) button')]);
    check(T, '开门营业 brings the shop back', read(PUB + '/index.html').includes('class="goods"'));
    await layoutCheck(T, page, '收款设置');
  });

  // ---------------- 订阅 ----------------
  await section(T, '订阅：访客从首页订阅，到后台名单、CSV', async () => {
    const vmark = T.R.console.length;
    await anon.goto(T.base + '/', { waitUntil: 'load' });
    await anon.evaluate(() => document.getElementById('subscribe').scrollIntoView({ block: 'center', behavior: 'instant' }));
    await sleep(1800);
    const err = async () => ((await anon.textContent('#sub-err')) || '').trim();
    await anon.click('#sub-btn');
    check(T, 'visitor: empty email → 请先填写邮箱', (await err()).includes('请先填写邮箱'), await err());
    await anon.fill('#sub-email', 'reader@qq');
    await anon.click('#sub-btn');
    check(T, 'visitor: bad address → 邮箱格式不对', (await err()).includes('邮箱格式不对'), await err());
    await anon.fill('#sub-email', '  Night.Reader@QQ.com ');
    await anon.click('#sub-btn');
    check(T, 'visitor: consent not ticked → 请先勾选同意', (await err()).includes('请先勾选同意'), await err());
    await anon.check('#sub-consent');
    await anon.click('#sub-btn');
    await anon.waitForSelector('#sub-done:not([hidden])', { timeout: 10000 }).catch(() => {});
    const done = (await anon.textContent('#sub-done').catch(() => '')) || '';
    check(T, 'visitor: subscribed, told honestly it is sent by hand', done.includes('已订阅') && done.includes('我会发到'), done);
    check(T, 'visitor page never promises automatic mail', !/自动发送|自动推送|系统会.*发|自动.*邮件/.test(await anon.textContent('#subscribe')));
    // the same person again in a new visit: same friendly answer
    await anon.goto(T.base + '/', { waitUntil: 'load' });
    await anon.evaluate(() => document.getElementById('subscribe').scrollIntoView({ block: 'center', behavior: 'instant' }));
    await sleep(1800);
    await anon.fill('#sub-email', 'night.reader@qq.com');
    await anon.check('#sub-consent');
    await anon.click('#sub-btn');
    await anon.waitForSelector('#sub-done:not([hidden])', { timeout: 10000 }).catch(() => {});
    check(T, 'visitor: a second subscribe gets the same answer (no enumeration)', ((await anon.textContent('#sub-done').catch(() => '')) || '').includes('已订阅'));
    check(T, 'visitor subscribe pages: no console errors', consoleSince(T, vmark).length === 0, consoleSince(T, vmark));
    const subs = JSON.parse(read(DATA + '/subscribers.json')).items;
    check(T, 'stored once, lowercased and trimmed, with the consent sentence', subs.length === 1 && subs[0].email === 'night.reader@qq.com' && subs[0].consent === '我同意本站保存这个邮箱，只用来给我发飞行日志。', subs);
    // endpoint rules
    const post = (form, headers = {}) => T.anonCtx.request.post(T.base + '/api/subscribe.php', { form, headers: { Accept: 'application/json', Origin: T.base, ...headers }, failOnStatusCode: false });
    const clearRl = () => { for (const b of ['sub-ip-hour', 'sub-ip-day', 'sub-site-day']) fs.rmSync(`${DATA}/ratelimit/${b}`, { recursive: true, force: true }); };
    clearRl();
    let r = await post({ email: 'not-an-email', consent: '1', website: '', t: '5000' });
    check(T, 'endpoint: invalid email 400 in Chinese', r.status() === 400 && (await r.json()).message === '邮箱格式不对，请再检查一遍。');
    r = await post({ email: 'a@example.com', website: '', t: '5000' });
    check(T, 'endpoint: no consent 400', r.status() === 400 && (await r.json()).error === 'consent_required');
    r = await post({ email: 'bot@example.com', consent: '1', website: 'http://spam', t: '5000' });
    check(T, 'endpoint: honeypot answered ok, not stored', r.status() === 200 && !read(DATA + '/subscribers.json').includes('bot@example.com'));
    r = await post({ email: 'fast@example.com', consent: '1', website: '', t: '300' });
    check(T, 'endpoint: too fast answered ok, not stored', r.status() === 200 && !read(DATA + '/subscribers.json').includes('fast@example.com'));
    r = await post({ email: 'x@example.com', consent: '1', website: '', t: '5000' }, { Origin: 'https://evil.example' });
    check(T, 'endpoint: another site posting is refused (403)', r.status() === 403);
    r = await post({ email: 'bot2@example.com', consent: '1', website: 'x', t: '5000' });
    r = await post({ email: 'y@example.com', consent: '1', website: '', t: '5000' });
    check(T, 'endpoint: the 6th try within an hour is rate limited (429, Chinese)', r.status() === 429 && (await r.json()).message === '提交得有点频繁，过一会儿再试。', r.status());
    clearRl();
    const LONG_EMAIL = 'a.very.long.name.of.a.reader.who.likes.night.flights' + '@' + 'mail.' + 'subdomain-of-a-long-company-name.'.repeat(4) + 'example.com';
    for (const email of ['=cmd@example.com', 'zh.reader@163.com', LONG_EMAIL]) {
      r = await post({ email, consent: '1', website: '', t: '5000' });
      check(T, `endpoint stores ${email.slice(0, 30)}…`, r.status() === 200, [r.status(), await r.text()]);
    }
    clearRl();
    // admin list
    await go(T, page, 'subs');
    const emails = await page.locator('.srow-e').allTextContents();
    check(T, '订阅者 lists 4 addresses, newest first', emails.length === 4 && emails[0].startsWith('a.very.long'), emails);
    await layoutCheck(T, page, '订阅者（很长的邮箱）');
    await page.fill('#q', 'QQ.COM');
    await Promise.all([page.waitForNavigation(), page.locator('.find-row button').click()]);
    check(T, 'search is case-insensitive', (await page.locator('.srow-e').allTextContents()).join() === 'night.reader@qq.com');
    await go(T, page, 'subs');
    await page.click('#copy-all');
    await sleep(300);
    const copied = (await page.textContent('#copy-msg')) + (await page.locator('.sheet textarea').count() ? await page.locator('.sheet textarea').inputValue() : '');
    check(T, '复制全部邮箱 copies all 4 (or offers a manual copy)', copied.includes('已复制 4 个邮箱') || copied.split(',').length === 4, copied.slice(0, 120));
    await closeSheets(page);
    const [dl] = await Promise.all([page.waitForEvent('download'), page.locator('a', { hasText: '导出表格' }).click()]);
    const csvPath = T.tmp + '/subs.csv';
    await dl.saveAs(csvPath);
    const buf = fs.readFileSync(csvPath);
    const text = new TextDecoder('utf-8', { fatal: true }).decode(buf.subarray(3));
    const rows = parseCsv(text);
    check(T, 'CSV: subscribers-YYYYMMDD.csv', /^subscribers-\d{8}\.csv$/.test(dl.suggestedFilename()), dl.suggestedFilename());
    check(T, 'CSV: UTF-8 BOM so Excel shows Chinese', buf[0] === 0xEF && buf[1] === 0xBB && buf[2] === 0xBF);
    check(T, 'CSV: valid UTF-8, CRLF rows, Chinese header', rows[0].join('|') === '邮箱|订阅时间|同意的内容' && text.includes('\r\n') && !/[^\r]\n/.test(text), rows[0]);
    check(T, 'CSV: 5 rows, consent sentence intact in Chinese', rows.length === 5 && rows.slice(1).every(rw => rw[2] === '我同意本站保存这个邮箱，只用来给我发飞行日志。'), rows.map(rw => rw[2]));
    check(T, 'CSV: Beijing time like 2026-10-14 16:05', rows.slice(1).every(rw => /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(rw[1])), rows.map(rw => rw[1]));
    check(T, 'CSV: a formula-looking address is defused with a quote', rows.some(rw => rw[0] === "'=cmd@example.com"), rows.map(rw => rw[0]));
    const head = await page.request.get(T.base + '/admin/?r=subs.export');
    check(T, 'CSV: served as text/csv; charset=utf-8, never cached', /text\/csv;\s*charset=utf-8/i.test(head.headers()['content-type'] || '') && /no-store/.test(head.headers()['cache-control'] || ''), head.headers());
    // delete one
    await page.locator('.srow', { hasText: '=cmd@example.com' }).locator('input').check();
    await page.click('#subs-del button[type=submit]');
    await Promise.all([page.waitForNavigation(), page.locator('.sheet-btns .btn-danger').click()]);
    check(T, '删除所选 removes one', (await flashText(page)).includes('已删除 1 个邮箱') && await page.locator('.srow-e').count() === 3);
    // switch off: the public form stops storing
    await page.locator('form[action*="subs.toggle"] button').click();
    await Promise.all([page.waitForNavigation(), sheetOk(page)]);
    r = await post({ email: 'late@example.com', consent: '1', website: '', t: '5000' });
    check(T, '订阅表单 off: home shows the closed note, endpoint says closed (503)', read(PUB + '/index.html').includes('class="micro mode-a"') && r.status() === 503 && (await r.json()).error === 'closed');
    await Promise.all([page.waitForNavigation(), page.locator('form[action*="subs.toggle"] button').click()]);
    check(T, '订阅表单 on again', read(PUB + '/index.html').includes('id="sub-consent"'));
  });

  // ---------------- AI 助手 ----------------
  await section(T, 'AI 助手：密钥、测试、开关、限额、用量', async () => {
    await go(T, page, 'ai');
    await page.fill('#key', 'sk-proj-abc123');
    await Promise.all([page.waitForNavigation(), page.locator('form[action*="ai.key"] button[type=submit]').click()]);
    check(T, 'a non-Anthropic key is refused in Chinese', (await flashText(page)).includes('这不像是 Anthropic 的 API 密钥'));
    // pasted with a trailing space and newline, as copied from an email
    await page.fill('#key', FAKE_KEY + ' ');
    await Promise.all([page.waitForNavigation(), page.locator('form[action*="ai.key"] button[type=submit]').click()]);
    const flash = await flashText(page);
    check(T, 'key with a trailing space saves; only the last 4 shown', flash.includes('结尾是 KQ7Z') && (await page.textContent('#key-status')).includes('KQ7Z'), flash);
    check(T, 'the key is never in the page', !(await page.content()).includes(FAKE_KEY.slice(0, 24)));
    check(T, 'only secrets.json holds the key', grepTree(DATA, FAKE_KEY).join() === 'secrets.json', grepTree(DATA, FAKE_KEY));
    await page.click('#ai-test');
    await page.waitForFunction(() => document.getElementById('ai-test-msg').textContent !== '', null, { timeout: 10000 }).catch(() => {});
    check(T, '测试连接 answers in Chinese', (await page.textContent('#ai-test-msg')).includes('连接正常'));
    await Promise.all([page.waitForNavigation(), page.locator('form', { has: page.locator('input[name=part][value=switch]') }).locator('button').click()]);
    check(T, '打开 → CHAT_LIVE = true on the home page', read(PUB + '/index.html').includes('const CHAT_LIVE = true;'));
    check(T, 'hero regions untouched after the switch (T3)', heroOk(PUB + '/index.html'));
    for (let i = 0; i < 2; i++) {
      const r = await page.request.post(T.base + '/api/chat.php', { data: { messages: [{ role: 'user', content: '夜航怕不怕？' }] }, headers: { Accept: 'application/json', Origin: T.base } });
      check(T, `fake chat answer ${i + 1}`, r.status() === 200 && (await r.json()).reply === '（测试回答）', r.status());
    }
    await page.fill('#daily_limit', '１２０');
    await Promise.all([page.waitForNavigation(), page.locator('form', { has: page.locator('input[name=part][value=limits]') }).locator('button[type=submit]').click()]);
    const fl = await flashText(page);
    check(T, 'full-width digits in a limit (１２０) are saved as 120', fl.includes('已保存') && (await page.inputValue('#daily_limit')) === '120', fl);
    await page.fill('#daily_limit', '120');
    await page.fill('#ip_limit', '9');
    await pasteInto(page, '#extra_note', '最近在改装备，\r\n不聊具体航线。😀', true);
    await Promise.all([page.waitForNavigation(), page.locator('form', { has: page.locator('input[name=part][value=limits]') }).locator('button[type=submit]').click()]);
    check(T, 'limits and a pasted two-line note save', (await flashText(page)).includes('已保存限额') && (await page.inputValue('#daily_limit')) === '120' && (await page.inputValue('#extra_note')).includes('😀'));
    const row = await page.locator('table.usage tbody tr').first().locator('td').allTextContents();
    check(T, 'usage table: today 2 asked, 2 answered, 240 / 80 tokens', row[0] === '2' && row[1] === '2' && row[4] === '240' && row[5] === '80', row);
    check(T, 'usage never stores the question', grepTree(DATA, '夜航怕不怕').length === 0);
    await layoutCheck(T, page, 'AI 助手（有用量）');
    await page.locator('form[action*="ai.key_remove"] button').click();
    await Promise.all([page.waitForNavigation(), page.locator('.sheet-btns .btn-danger').click()]);
    check(T, '删除密钥 → INOP again, key gone from the data dir', read(PUB + '/index.html').includes('const CHAT_LIVE = false;') && grepTree(DATA, FAKE_KEY).length === 0);
    await go(T, page, 'dashboard');
    check(T, '概览 warns: AI 助手开着，但还没有密钥', (await page.textContent('main')).includes('AI 助手开着，但还没有密钥'));
    await go(T, page, 'ai');
    await Promise.all([page.waitForNavigation(), page.locator('form', { has: page.locator('input[name=part][value=switch]') }).locator('button').click()]);
    check(T, '关闭助手', (await flashText(page)).includes('助手已关闭'));
  });

  // ---------------- 版本记录 ----------------
  await section(T, '版本记录：回滚后网站和以前一字不差', async () => {
    const v0 = newestVersion(T);
    const snap = publicFiles(T);
    // two publishes: a site text change and a new published post
    await go(T, page, 'site');
    await openDet(page, '#g-about');
    await pasteInto(page, '#f-about-h2', '回滚测试', true);
    let st = await barAct(page, () => page.click('#bar [data-act="publish"]'));
    check(T, 'publish 1 (网站文字)', st.ok, st);
    await go(T, page, 'posts');
    await Promise.all([page.waitForNavigation(), page.locator('form[action*="post.new"] button').first().click()]);
    await page.fill('#p-title', 'Lijiang-ZPLJ-night-approach-briefing-notes-回滚以后不该出现的日志');
    await pasteInto(page, '#p-body', '这一篇会被回滚掉。');
    const slug2 = await page.inputValue('#p-slug');
    st = await barAct(page, () => page.click('#bar [data-act="publish"]'));
    check(T, 'publish 2 (新日志)', st.ok && fs.existsSync(`${PUB}/posts/${slug2}.html`), st);
    const id2 = new URL(page.url()).searchParams.get('id');
    for (const [u, name] of [['/', '首页'], [`/posts/${slug2}.html`, '日志页'], ['/posts/', '全部日志']]) {
      await anon.goto(T.base + u, { waitUntil: 'load' });
      await sleep(500);
      const m = await anon.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }));
      check(T, `a 50-letter unbroken title: ${name} has no horizontal overflow`, m.sw <= m.cw, m);
    }
    await go(T, page, 'posts');
    await layoutCheck(T, page, '飞行日志列表（长英文标题）');
    await go(T, page, 'versions');
    await layoutCheck(T, page, '版本记录');
    const row = page.locator('.vrow', { has: page.locator(`input[name=id][value="${v0}"]`) });
    check(T, 'the version from before is listed with 回滚到这个版本', await row.count() === 1);
    const [vv] = await Promise.all([T.ctx.waitForEvent('page'), row.locator('a', { hasText: '看一看' }).click()]);
    track(T, vv);
    await vv.waitForLoadState('load');
    check(T, '看一看 shows that version with its banner', (await vv.content()).includes('只是看一看') && !(await vv.content()).includes('回滚测试'));
    await vv.close();
    await row.locator('form button').click();
    await page.waitForSelector('.sheet');
    check(T, 'rollback asks first and says drafts are safe', (await page.textContent('.sheet')).includes('草稿不受影响'));
    await Promise.all([page.waitForNavigation(), sheetOk(page)]);
    check(T, 'rollback reports success', (await flashText(page)).includes('已回滚'), await flashText(page));
    const now = publicFiles(T);
    const diff = Object.keys({ ...snap, ...now }).filter(k => snap[k] !== now[k]);
    check(T, 'every public page is byte-identical to the rolled-back version', diff.length === 0, diff);
    const man = JSON.parse(read(`${DATA}/versions/${v0}/manifest.json`));
    const stored = man.current_files || man.files;   // settings-only republishes (no version) refresh current_files
    check(T, '... and to the files recorded for that version', Object.entries(stored).every(([f, h]) => now[f] === h), Object.keys(stored).filter(f => now[f] !== stored[f]));
    const r = await T.anonCtx.request.get(`${T.base}/posts/${slug2}.html`, { failOnStatusCode: false });
    check(T, 'the newer post page is gone (404)', r.status() === 404);
    check(T, 'hero regions untouched after rollback (T3)', heroOk(PUB + '/index.html'));
    await go(T, page, 'site');
    check(T, 'drafts untouched by the rollback', (await page.inputValue('#f-about-h2')) === '回滚测试');
    await go(T, page, 'posts', '&f=draft');
    check(T, 'the rolled-back post is a draft again, not deleted', (await page.textContent('main')).includes('回滚以后不该出现的日志'));
    // roll forward again to the newest pre-rollback version
    await go(T, page, 'versions');
    const fwd = page.locator('.vrow').nth(1);
    await fwd.locator('form button').click();
    await Promise.all([page.waitForNavigation(), sheetOk(page)]);
    const r2 = await T.anonCtx.request.get(`${T.base}/posts/${slug2}.html`, { failOnStatusCode: false });
    check(T, 'rolling forward brings the newer post back', r2.status() === 200 && read(PUB + '/index.html').includes('回滚测试'), r2.status());
    // the owner deletes a published post by mistake, then rolls back
    const beforeDel = publicFiles(T);
    await page.goto(T.base + '/admin/?r=post.edit&id=' + id2);
    await page.waitForSelector('#p-title');
    await page.click('#bar [data-act="more"]');
    await sheetAct(page, '删除这篇');
    await page.waitForSelector('.sheet-btns .btn-danger');
    await Promise.all([page.waitForNavigation(), page.locator('.sheet-btns .btn-danger').click()]);
    const r3 = await T.anonCtx.request.get(`${T.base}/posts/${slug2}.html`, { failOnStatusCode: false });
    check(T, 'deleting the published post takes its page down', r3.status() === 404);
    await go(T, page, 'versions');
    check(T, 'the delete is the newest entry in 版本记录', (await page.locator('.vrow').first().textContent()).includes('删除日志'));
    await page.locator('.vrow').nth(1).locator('form button').click();
    await Promise.all([page.waitForNavigation(), sheetOk(page)]);
    const afterBack = publicFiles(T);
    const d2 = Object.keys({ ...beforeDel, ...afterBack }).filter(k => beforeDel[k] !== afterBack[k]);
    check(T, 'rollback after a mistaken delete: every page byte-identical again', d2.length === 0, d2);
    check(T, '... the post is back in the list (from the trash)', fs.existsSync(`${DATA}/posts/${id2}.json`) && JSON.parse(read(`${DATA}/posts/${id2}.json`)).live !== null);
    // 11 more publishes keep only 10 versions
    await go(T, page, 'site');
    const csrf = await page.locator('meta[name=csrf]').getAttribute('content');
    const data = JSON.parse(await page.textContent('#editor-data'));
    let rev = data.rev;
    const fields = await page.evaluate(() => { const f = {}; document.querySelectorAll('[data-k]').forEach(i => { f[i.dataset.k] = i.value; }); return f; });
    for (let i = 1; i <= 11; i++) {
      fields['about.h2'] = '第 ' + i + ' 次发布';
      const res = await page.evaluate(async ({ rev, fields, faq, facts, csrf }) => {
        const r = await fetch('?r=site.publish', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ rev, doc: { fields, faq, type_facts: facts } }) });
        return r.json();
      }, { rev, fields, faq: data.faq, facts: data.type_facts, csrf });
      if (!res.ok) { check(T, `quick publish ${i}`, false, res); break; }
      rev = res.rev;
    }
    check(T, '11 more publishes keep the newest 10 versions', versionsCount(T) === 10, versionsCount(T));
    await go(T, page, 'versions');
    check(T, '版本记录 lists 10 rows', await page.locator('.vrow').count() === 10);
  });

  // ---------------- 登录、过期、退出 ----------------
  await section(T, '登录：过期、改密码、退出以后真的进不去', async () => {
    const cookie = async ctx => ((await ctx.cookies()).find(c => c.name === 'pb_admin') || {}).value;
    const sessFile = async ctx => `${DATA}/sessions/sess_${await cookie(ctx)}`;
    const editSession = async (ctx, fn) => { const f = await sessFile(ctx); fs.writeFileSync(f, fn(read(f))); };
    // the preview pane while the session has run out: a plain note, not a browser error
    await go(T, page, 'posts', '&f=teaser');
    await page.locator('.plist a.prow').first().click();
    await page.waitForSelector('#p-title');
    const pmark = T.R.console.length;
    for (const f of fs.readdirSync(DATA + '/sessions')) fs.unlinkSync(DATA + '/sessions/' + f);
    if (T.W < 1100) await page.click('#tab-preview');
    else { await pasteInto(page, '#p-title', '！'); }
    await sleep(2000);
    const pvText = ((await page.frame({ name: 'pv' }).textContent('body').catch(() => '')) || '').trim();
    check(T, 'expired session: the preview pane says so in Chinese', /登录/.test(pvText), pvText.slice(0, 80));
    check(T, '... without console errors', consoleSince(T, pmark).length === 0, consoleSince(T, pmark));
    await login(T, page, T.pw);
    // CSRF and methods
    let r = await page.request.post(T.base + '/admin/?r=site.save', { data: { rev: 1, doc: {} }, headers: { Accept: 'application/json' }, failOnStatusCode: false });
    check(T, 'JSON save without the CSRF token: 403 csrf', r.status() === 403 && (await r.json()).error === 'csrf');
    r = await page.request.post(T.base + '/admin/?r=publish.all', { form: { _csrf: await page.locator('meta[name=csrf]').getAttribute('content') }, headers: { Origin: 'https://evil.example' }, failOnStatusCode: false });
    check(T, 'a post from another site: 403', r.status() === 403);
    // idle timeout (120 min)
    await editSession(T.ctx, s => s.replace(/last_seen\|i:\d+;/, `last_seen|i:${Math.floor(Date.now() / 1000) - 121 * 60};`));
    await go(T, page, 'dashboard');
    check(T, 'after 2 hours idle: back to 登录 with 登录已经过期', page.url().includes('r=login') && (await flashText(page)).includes('登录已经过期'), [page.url(), await flashText(page)]);
    r = await page.request.post(T.base + '/admin/?r=post.save', { data: { id: 'p000000000001', rev: 1 }, headers: { Accept: 'application/json', 'X-CSRF-Token': 'x' }, failOnStatusCode: false });
    check(T, '... and JSON calls answer 401/403', r.status() === 401 || r.status() === 403, r.status());
    await login(T, page, T.pw);
    // absolute timeout (12 h) even when active
    await editSession(T.ctx, s => s.replace(/login_at\|i:\d+;/, `login_at|i:${Math.floor(Date.now() / 1000) - 12 * 3600 - 60};`));
    await go(T, page, 'posts');
    check(T, 'after 12 hours: logged out even while active', page.url().includes('r=login'));
    await login(T, page, T.pw);
    // a second device is signed out by a password change
    const other = await T.anonCtx.browser().newContext(T.opt);
    const op = await newPage(T, other);
    await login(T, op, T.pw);
    check(T, 'second device logged in', (await op.textContent('h1').catch(() => '')) === '概览');
    await go(T, page, 'password');
    await page.fill('#old', 'not-my-password');
    await page.fill('#new1', PW2);
    await page.fill('#new2', PW2);
    await Promise.all([page.waitForNavigation(), page.locator('main form button[type=submit]').click()]);
    check(T, '修改密码 with a wrong current password: 当前密码不对', (await page.textContent('main')).includes('当前密码不对'));
    await page.fill('#old', T.pw);
    await page.fill('#new1', PW2);
    await page.fill('#new2', PW2);
    await Promise.all([page.waitForNavigation(), page.locator('main form button[type=submit]').click()]);
    check(T, '修改密码 works', (await flashText(page)).includes('密码已经改好'));
    T.pw = PW2;
    await go(T, op, 'subs');
    check(T, 'the other device is signed out by the password change', op.url().includes('r=login'));
    await other.close();
    // 退出, then replay the old cookie from a fresh browser
    await go(T, page, 'dashboard');
    const old = await cookie(T.ctx);
    const oldFile = await sessFile(T.ctx);
    await openMenuIfPhone(page);
    await Promise.all([page.waitForNavigation(), page.locator('.menu-logout button').click()]);
    check(T, '退出 lands on 登录 with 已退出', page.url().includes('r=login') && (await flashText(page)).includes('已退出'));
    check(T, 'the old session file is gone from the server', !fs.existsSync(oldFile));
    const thief = await T.anonCtx.browser().newContext(T.opt);
    await thief.addCookies([{ name: 'pb_admin', value: old, url: T.base + '/admin/' }]);
    const tp = await newPage(T, thief);
    const leaks = [];
    for (const [u, needle] of [['dashboard', '上次发布'], ['subs', 'night.reader'], ['subs.export', 'night.reader'], ['versions', '回滚到这个版本'], ['site', 'editor-data'], [`post.edit&id=p000000000001`, 'p-title'], ['ai', 'key-status'], ['orders.export', '订单号'], ['pay', '收款码']]) {
      const res = await thief.request.get(T.base + '/admin/?r=' + u, { failOnStatusCode: false });
      if ((await res.text()).includes(needle)) leaks.push(u);
    }
    for (const u of ['post.save', 'site.save', 'upload', 'shop.save', 'order.act', 'pay.save']) {
      const res = await thief.request.post(T.base + '/admin/?r=' + u, { data: {}, headers: { Accept: 'application/json' }, failOnStatusCode: false });
      if (res.status() < 400) leaks.push(u + ' ' + res.status());
    }
    check(T, 'the cookie from before 退出 opens nothing (pages, CSV, JSON calls)', leaks.length === 0, leaks);
    await tp.goto(T.base + '/admin/');
    check(T, '... a browser with that cookie lands on 登录', tp.url().includes('r=login'));
    await thief.close();
    await layoutCheck(T, page, '登录页');
    await page.fill('#password', PW1);
    await Promise.all([page.waitForNavigation(), page.click('main button[type=submit]')]);
    check(T, 'the old password no longer works', (await page.textContent('main')).includes('密码不对'));
    await page.fill('#password', PW2);
    await Promise.all([page.waitForNavigation(), page.click('main button[type=submit]')]);
    check(T, 'the new password works', (await page.textContent('h1').catch(() => '')) === '概览');
  });

  await section(T, '每个后台页面：控制台和横向溢出（改过以后的内容）', async () => { await sweepAdmin(T, '改后'); });

  // ---------------- lockout last (it locks this IP for 15 minutes) ----------------
  console.log(`\n  -- [${T.W}] 连续输错密码`);
  {
    await go(T, page, 'dashboard');
    await openMenuIfPhone(page);
    await Promise.all([page.waitForNavigation(), page.locator('.menu-logout button').click()]);
    let msg = '';
    for (let i = 0; i < 6; i++) {
      await page.fill('#password', 'wrong-password-' + i);
      await Promise.all([page.waitForNavigation(), page.click('main button[type=submit]')]);
      msg = await page.textContent('main');
    }
    check(T, '6 wrong passwords: 尝试次数太多，请 15 分钟后再试', msg.includes('尝试次数太多，请 15 分钟后再试'), msg.slice(0, 100));
    await page.fill('#password', T.pw);
    await Promise.all([page.waitForNavigation(), page.click('main button[type=submit]')]);
    check(T, '... even the right password waits', (await page.textContent('main')).includes('尝试次数太多'));
  }

  // ---------------- whole run ----------------
  console.log(`\n  -- [${T.W}] 整体`);
  check(T, 'no console errors anywhere (admin, previews, public pages)', T.R.console.length === 0, T.R.console);
  check(T, 'no browser alert/confirm/prompt dialogs', T.R.dialogs.length === 0, T.R.dialogs);
  check(T, 'no external requests (works inside mainland China)', T.R.external.length === 0, T.R.external);
  check(T, 'no missing CSS/JS/fonts/images', T.R.missing.length === 0, T.R.missing);
  check(T, 'no 5xx answers', T.R.server.length === 0, T.R.server);
  check(T, 'no PHP warnings or errors in the server log', T.R.php.length === 0, T.R.php);
  check(T, 'passwords never stored in plain text', grepTree(DATA, PW1).length === 0 && grepTree(DATA, PW2).length === 0);
  const log = fs.existsSync(DATA + '/log/admin.log') ? read(DATA + '/log/admin.log') : '';
  check(T, 'audit log holds no emails, keys or passwords', !log.includes('@') && !log.includes(FAKE_KEY.slice(0, 20)) && !log.includes(PW1) && !log.includes(PW2));
  if (T.R.beforeunload) info(T, `${T.R.beforeunload} "leave page?" question(s) accepted while leaving with unsaved changes`);
}

// ======================================================================================
(async () => {
  const guard = () => ({
    index: sha(PUBLIC_REPO + '/index.html'),
    posts: fs.readdirSync(PUBLIC_REPO + '/posts').sort().join(','),
    uploads: fs.readdirSync(PUBLIC_REPO + '/uploads').sort().join(','),
    data: fs.existsSync(REPO + '/blog-data') ? fs.readdirSync(REPO + '/blog-data').sort().join(',') : null,
  });
  const before = guard();
  const browser = await chromium.launch();
  const t0 = Date.now();
  for (const W of WIDTHS) await run(browser, W);
  await browser.close();
  const after = guard();
  total++;
  if (JSON.stringify(before) === JSON.stringify(after)) console.log('\n  ok   the repo\'s pilot-blog/ and blog-data/ were not touched');
  else { failed++; failLines.push('FAIL repo files changed: ' + JSON.stringify({ before, after })); console.log('\n  FAIL the repo\'s pilot-blog/ or blog-data/ changed'); }
  console.log(`\n${failed === 0 ? 'ALL PASS' : failed + ' FAILED'}: ${total} checks at ${WIDTHS.join(' and ')} px in ${Math.round((Date.now() - t0) / 1000)} s`);
  if (failed) { console.log(failLines.map(l => '  ' + l).join('\n')); console.log('  screenshots: ' + ERRDIR); }
  else fs.rmSync(ERRDIR, { recursive: true, force: true });
  process.exit(failed ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
