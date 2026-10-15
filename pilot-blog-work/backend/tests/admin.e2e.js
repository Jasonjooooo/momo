// Admin flows in Chromium (SPEC T7, parts of T8 and T9). Each viewport gets its own temp site
// (temp public dir + temp data dir, served by php -S with the dev router), so the repo's pilot-blog/
// and blog-data/ are never touched. Nothing here needs a real API key (BLOG_CHAT_FAKE=1).
// Usage: node pilot-blog-work/backend/tests/admin.e2e.js [390|1440|390,1440] [noshots]
// Screenshots: pilot-blog-work/screens/admin/<page>-<width>.png (gitignored).
const { chromium } = require('/opt/node-tools/node_modules/playwright');
const { execFileSync, spawn } = require('child_process');
const fs = require('fs');
const os = require('os');
const net = require('net');
const path = require('path');

const REPO = path.resolve(__dirname, '../../..');
const SHOTS = REPO + '/pilot-blog-work/screens/admin';
const WIDTHS = (process.argv[2] || '390,1440').split(',').map(Number);
const NOSHOTS = process.argv.includes('noshots');
const PW1 = '云底下的跑道灯2026春';
const PW2 = '换一个新密码abc12345';
const FAKE_KEY = 'sk-ant-test' + 'a1B2c3D4e5F6g7H8i9J0k1L2m3N4o5P6' + 'WXYZ';
const XSS = '<img src=x onerror=alert(1)>';
const sleep = ms => new Promise(r => setTimeout(r, ms));

let failures = 0;
let log = [];
function check(name, cond, detail) {
  const line = (cond ? 'ok   ' : 'FAIL ') + name + (cond || detail === undefined ? '' : ' -- ' + JSON.stringify(detail).slice(0, 400));
  log.push(line);
  console.log('  ' + line);
  if (!cond) failures++;
}

// ---------- site and server ----------
function makeSite() {
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'pbadmin-'));
  fs.mkdirSync(tmp + '/pub/posts', { recursive: true });
  fs.mkdirSync(tmp + '/pub/uploads', { recursive: true });
  fs.mkdirSync(tmp + '/data');
  fs.mkdirSync(tmp + '/files');
  for (const d of ['assets', 'api', 'app', 'admin']) fs.symlinkSync(REPO + '/pilot-blog/' + d, tmp + '/pub/' + d);
  for (const f of ['index.html', '.htaccess', 'posts/.htaccess', 'uploads/.htaccess']) fs.copyFileSync(REPO + '/pilot-blog/' + f, tmp + '/pub/' + f);
  // test images, made with GD so no binary fixtures live in the repo
  execFileSync('php', ['-r', `
    $d = $argv[1];
    $im = imagecreatetruecolor(1600, 900);
    imagefill($im, 0, 0, imagecolorallocate($im, 19, 45, 97));
    imagefilledrectangle($im, 0, 600, 1600, 900, imagecolorallocate($im, 61, 68, 107));
    imagefilledellipse($im, 800, 600, 300, 120, imagecolorallocate($im, 79, 208, 138));
    imagejpeg($im, $d . '/cover.jpg', 88);
    $p = imagecreatetruecolor(900, 1200);
    imagefill($p, 0, 0, imagecolorallocate($p, 234, 76, 34));
    imagepng($p, $d . '/photo.png');
    file_put_contents($d . '/not-an-image.jpg', "<?php echo 'pwned'; ?>\\n");
    $f = fopen($d . '/huge.jpg', 'wb'); fwrite($f, "\\xFF\\xD8\\xFF\\xE0"); fwrite($f, random_bytes(13 * 1024 * 1024)); fclose($f);
  `, tmp + '/files']);
  return tmp;
}
const portFree = port => new Promise(res => { const s = net.createServer().once('error', () => res(false)).once('listening', () => s.close(() => res(true))).listen(port, '127.0.0.1'); });
async function startServer(tmp, port) {
  const env = { ...process.env, BLOG_PUBLIC_DIR: tmp + '/pub', BLOG_DATA_DIR: tmp + '/data', BLOG_CHAT_FAKE: '1' };
  delete env.ANTHROPIC_API_KEY;
  const p = spawn('php', ['-d', 'upload_max_filesize=12M', '-d', 'post_max_size=16M', '-S', '127.0.0.1:' + port, '-t', tmp + '/pub', REPO + '/pilot-blog/app/dev/router.php'], { env, stdio: ['ignore', 'ignore', 'pipe'] });
  p.stderr.on('data', b => { const s = String(b); if (/PHP (Fatal|Warning|Notice|Deprecated)|Uncaught/.test(s)) console.log('  [php] ' + s.trim().slice(0, 300)); });
  for (let i = 0; i < 50 && await portFree(port); i++) await sleep(100);
  return p;
}
async function freePort(from) { for (let p = from; p < from + 200; p++) if (await portFree(p)) return p; throw new Error('no port'); }

// ---------- page helpers ----------
function track(page, R, base) {
  // 4xx answers the tests provoke on purpose (refused upload, expired session) log 'Failed to load resource'; 5xx are tracked separately
  page.on('console', m => { if (m.type() === 'error' && !/^Failed to load resource: the server responded with a status of 4\d\d/.test(m.text())) R.console.push(m.text()); });
  page.on('response', r => { if (r.status() >= 500) R.server.push(r.status() + ' ' + r.url()); });
  page.on('pageerror', e => R.console.push('PAGEERROR ' + e.message));
  page.on('dialog', d => { R.dialogs.push(d.type() + ':' + d.message()); d.dismiss().catch(() => {}); });
  page.on('request', r => { const u = r.url(); if (!u.startsWith(base) && !/^(blob|data|about):/.test(u)) R.external.push(u); });
}
async function layout(page) {
  return page.evaluate(() => {
    const de = document.documentElement;
    const vis = e => { if (e.closest('[hidden]')) return false; const r = e.getBoundingClientRect(); return r.width > 0 && r.height > 0 && getComputedStyle(e).visibility !== 'hidden'; };
    const small = [];
    document.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=file]), select, textarea').forEach(e => {
      if (!vis(e) || e.classList.contains('vh')) return;
      const fs = parseFloat(getComputedStyle(e).fontSize);
      if (fs < 16) small.push((e.id || e.name || e.tagName) + ':' + fs);
    });
    const short = [];
    document.querySelectorAll('button, .btn, summary, .fchip, .prow, .menu-list a, input[type=checkbox], .srow, [role=tab]').forEach(e => {
      if (!vis(e)) return;
      const t = e.matches('input[type=checkbox]') ? (e.closest('label') || e) : e;
      const r = t.getBoundingClientRect();
      if (r.height < 44 || r.width < 44) short.push(((e.textContent || e.id || e.tagName).trim().slice(0, 14)) + ':' + Math.round(r.width) + 'x' + Math.round(r.height));
    });
    return { sw: de.scrollWidth, cw: de.clientWidth, small, short };
  });
}
async function layoutCheck(page, name, W) {
  const m = await layout(page);
  check(`[${W}] ${name}: no horizontal scroll`, m.sw <= m.cw, m);
  check(`[${W}] ${name}: inputs at least 16px`, m.small.length === 0, m.small);
  check(`[${W}] ${name}: tap targets at least 44px`, m.short.length === 0, m.short);
}
async function shot(page, name, W) {
  if (NOSHOTS) return;
  fs.mkdirSync(SHOTS, { recursive: true });
  await page.screenshot({ path: `${SHOTS}/${name}-${W}.png`, fullPage: true });
}
async function sheetOk(page) {
  await page.waitForSelector('.sheet-btns button', { timeout: 5000 });
  await page.locator('.sheet-btns button').last().click();
}
async function sheetAct(page, label) {
  await page.waitForSelector('.sheet-acts button', { timeout: 5000 });
  await page.locator('.sheet-acts button', { hasText: label }).first().click();
}
async function barDone(page, kind = 'ok', timeout = 15000) {
  await page.waitForSelector(`#bar-status.is-${kind}, #bar-status.is-err`, { timeout }).catch(() => {});
  return { text: await page.locator('#bar-status').textContent(), cls: await page.locator('#bar-status').getAttribute('class') };
}
async function openDet(page, sel) {
  const loc = typeof sel === 'string' ? page.locator(sel) : sel;
  if (!(await loc.evaluate(e => e.open))) await loc.locator('summary').first().click();
}
async function flashText(page) { return (await page.locator('#flashes').textContent()).trim(); }
async function openMenuIfPhone(page) {
  const btn = page.locator('.menu-btn');
  if (await btn.isVisible()) { await btn.click(); await page.waitForSelector('#menu.open'); }
}
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
async function go(page, base, r, q = '') { await page.goto(base + '/admin/?r=' + r + q); }
const sha = f => require('crypto').createHash('sha256').update(fs.readFileSync(f)).digest('hex');
const read = f => fs.readFileSync(f, 'utf8');
function grepData(dir, needle) {
  const hits = [];
  const walk = d => { for (const n of fs.readdirSync(d)) { const p = d + '/' + n; const st = fs.lstatSync(p); if (st.isDirectory()) walk(p); else if (st.isFile() && fs.readFileSync(p).includes(needle)) hits.push(p.slice(dir.length + 1)); } };
  walk(dir);
  return hits;
}
function shelfJson(html) {
  const m = html.match(/<script type="application\/json" id="shelf-data">([\s\S]*?)<\/script>/);
  return m ? JSON.parse(m[1]) : null;
}

// ---------- one full run at one width ----------
async function run(browser, W) {
  console.log(`\n== admin at ${W} px ==`);
  const tmp = makeSite();
  const PUB = tmp + '/pub', DATA = tmp + '/data', FILES = tmp + '/files';
  const port = await freePort(8990 + (W === 390 ? 0 : 50));
  const server = await startServer(tmp, port);
  const base = 'http://127.0.0.1:' + port;
  const R = { console: [], dialogs: [], external: [], server: [] };
  const opt = W === 390
    ? { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true }
    : { viewport: { width: 1440, height: 900 } };
  const ctx = await browser.newContext({ ...opt, acceptDownloads: true });
  const page = await ctx.newPage();
  track(page, R, base);
  try {
    // ----- first run: setup code -----
    let res = await page.goto(base + '/admin/');
    check(`[${W}] fresh install sends /admin/ to setup`, page.url().includes('r=setup'), page.url());
    const codeFile = DATA + '/setup-code.txt';
    check(`[${W}] setup-code.txt created in the data dir`, fs.existsSync(codeFile));
    const code = read(codeFile).match(/[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}/)[0];
    check(`[${W}] the setup code is never in the page`, !(await page.content()).includes(code) && !(await page.content()).includes(code.replace(/-/g, '')));
    const hdr = res.headers();
    check(`[${W}] admin headers: CSP, DENY, nosniff, noindex, no-store`, /script-src 'self'/.test(hdr['content-security-policy'] || '') && hdr['x-frame-options'] === 'DENY' && hdr['x-content-type-options'] === 'nosniff' && /noindex/.test(hdr['x-robots-tag'] || '') && /no-store/.test(hdr['cache-control'] || ''), hdr);
    check(`[${W}] admin page has meta robots noindex`, await page.locator('meta[name="robots"][content*="noindex"]').count() === 1);
    await layoutCheck(page, 'setup', W);
    await shot(page, 'setup', W);
    await page.fill('#code', 'AAAA-BBBB-CCCC');
    await page.fill('#password', PW1);
    await page.fill('#password2', PW1);
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    check(`[${W}] wrong setup code refused`, (await page.textContent('main')).includes('设置码不对'), await page.textContent('main'));
    await page.fill('#code', code.toLowerCase().replace(/-/g, ' '));
    await page.fill('#password', PW1);
    await page.fill('#password2', PW1 + 'x');
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    check(`[${W}] mismatched passwords refused`, (await page.textContent('main')).includes('两次输入的密码不一样'));
    await page.fill('#code', code.toLowerCase());
    await page.fill('#password', '1234567890');
    await page.fill('#password2', '1234567890');
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    check(`[${W}] common password refused`, (await page.textContent('main')).includes('太常见'));
    await page.fill('#code', code);
    await page.fill('#password', PW1);
    await page.fill('#password2', PW1);
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    check(`[${W}] right setup code logs in and lands on 概览`, page.url().endsWith('/admin/') && (await page.textContent('h1')) === '概览', page.url());
    check(`[${W}] setup-code.txt deleted after setup`, !fs.existsSync(codeFile));
    check(`[${W}] admin.json holds a bcrypt hash, not the password`, /"\$2y\$12\$/.test(read(DATA + '/admin.json')) && !read(DATA + '/admin.json').includes(PW1));
    await go(page, base, 'setup');
    check(`[${W}] second setup refused (redirected away)`, !page.url().includes('r=setup'), page.url());

    // ----- dashboard -----
    originPlaceholder(DATA);
    await go(page, base, 'dashboard');
    const dash = await page.textContent('main');
    check(`[${W}] 概览 shows the last publish time`, /上次发布\s*\d+月\d+日/.test(dash.replace(/\s+/g, ' ')), dash.slice(0, 200));
    check(`[${W}] 概览 shows post counts (5 预告)`, (await page.locator('#c-log ~ dl dd').allTextContents()).join(',') === '0,5,0');
    check(`[${W}] 概览 shows subscribers and assistant status`, dash.includes('订阅者') && dash.includes('已关闭') && dash.includes('0 / 300'));
    check(`[${W}] 概览 links to the public site`, await page.locator('main a[target=_blank]', { hasText: '查看网站' }).count() === 1);
    check(`[${W}] first dashboard visit made version 1 (初始内容)`, fs.readdirSync(DATA + '/versions').length === 1);
    await layoutCheck(page, 'dashboard', W);
    await shot(page, 'dashboard', W);
    await Promise.all([page.waitForNavigation(), page.locator('form[action*="site.origin"] button').click()]);
    check(`[${W}] one-tap site address saved and published`, (await flashText(page)).includes('网站地址已经设成') && read(PUB + '/index.html').includes(`content="https://127.0.0.1:${port}/assets/og.jpg"`), await flashText(page));

    // ----- CSRF and method rules -----
    let r = await page.request.post(base + '/admin/?r=site.save', { data: { rev: 1, doc: {} }, headers: { Accept: 'application/json' } });
    check(`[${W}] JSON save without CSRF token: 403 csrf`, r.status() === 403 && (await r.json()).error === 'csrf', r.status());
    r = await page.request.post(base + '/admin/?r=publish.all', { form: { x: '1' } });
    check(`[${W}] form post without CSRF token: 403`, r.status() === 403);
    const token = await page.locator('meta[name=csrf]').getAttribute('content');
    r = await page.request.post(base + '/admin/?r=publish.all', { form: { _csrf: token }, headers: { Origin: 'https://evil.example' } });
    check(`[${W}] cross-origin post refused`, r.status() === 403);
    r = await page.request.get(base + '/admin/?r=logout');
    check(`[${W}] GET on a POST route changes nothing (405)`, r.status() === 405);
    r = await page.request.get(base + '/admin/?r=post.edit&id=../../etc/passwd');
    check(`[${W}] traversal in an id: 404`, r.status() === 404);
    r = await page.request.get(base + '/admin/?r=versions.view&id=20261014-000000-abcdef&f=../../admin.json');
    check(`[${W}] traversal in a version file: 404`, r.status() === 404);

    // ----- 网站文字 -----
    const index0 = sha(PUB + '/index.html');
    await go(page, base, 'site');
    await layoutCheck(page, 'site (closed groups)', W);
    await page.fill('#find', '订阅');
    const visibleGroups = await page.locator('#site-form details.grp:not([hidden])').count();
    check(`[${W}] search box narrows the groups`, visibleGroups > 0 && visibleGroups < 14, visibleGroups);
    await page.fill('#find', '');
    await openDet(page, '#g-hero');
    const newHl = '测试标题，|云底之下。';
    await page.fill('#f-hero-b1-hl', newHl);
    await openDet(page, '#g-about');
    await page.fill('#f-about-h2', '关于<i>机长</i>');
    check(`[${W}] 改过 badge counts edited fields`, (await page.locator('#g-about .badge').textContent()) === '改过 1' && await page.locator('#g-about .badge').isVisible());
    check(`[${W}] status says there are unsaved changes`, (await page.textContent('#bar-status')).includes('有改动没保存'));
    await openDet(page, '#g-faq');
    const faqBefore = await page.locator('#faq-list .item').count();
    await page.click('#faq-list [data-act="add"]');
    const lastFaq = page.locator('#faq-list .item').last();
    await lastFaq.locator('input').nth(0).fill('测试问题：后台好用吗？');
    await lastFaq.locator('input').nth(1).fill('还行');
    await lastFaq.locator('textarea').fill("回答里有 ';alert(1)// 和 " + XSS);
    check(`[${W}] FAQ row added in the editor`, await page.locator('#faq-list .item').count() === faqBefore + 1);
    // move the new row up once (phone-friendly buttons)
    await lastFaq.locator('[data-act="up"]').click();
    check(`[${W}] FAQ 上移 moves the row`, (await page.locator('#faq-list .item').nth(faqBefore - 1).locator('input').nth(0).inputValue()) === '测试问题：后台好用吗？');
    // unsaved-changes guard on in-app links
    await openMenuIfPhone(page);
    await page.locator('#menu a', { hasText: '概览' }).click();
    await page.waitForSelector('.sheet', { timeout: 5000 }).catch(() => {});
    check(`[${W}] leaving with unsaved changes asks first`, (await page.locator('.sheet').count()) === 1 && (await page.textContent('.sheet')).includes('有改动还没保存'));
    await page.locator('.sheet-btns button', { hasText: '取消' }).click();
    if (await page.locator('.menu-close').isVisible()) await page.click('.menu-close');
    check(`[${W}] cancel keeps the page and the text`, page.url().includes('r=site') && (await page.inputValue('#f-about-h2')) === '关于<i>机长</i>');
    await layoutCheck(page, 'site (editing)', W);
    await shot(page, 'site', W);
    await page.click('#bar [data-act="save"]');
    let st = await barDone(page);
    check(`[${W}] 保存草稿 works`, st.cls.includes('is-ok') && st.text.includes('已保存草稿'), st);
    check(`[${W}] saving a draft leaves the public page unchanged`, sha(PUB + '/index.html') === index0);
    const [pv] = await Promise.all([ctx.waitForEvent('page'), page.click('#bar [data-act="preview"]')]);
    await pv.waitForLoadState('domcontentloaded');
    const pvHtml = await pv.content();
    check(`[${W}] 预览 shows the draft with a preview banner`, pvHtml.includes('测试标题，') && pvHtml.includes('预览：这是还没发布的草稿'), pvHtml.length);
    await pv.close();
    // session expiry while editing: re-login sheet, text kept, save succeeds
    for (const f of fs.readdirSync(DATA + '/sessions')) fs.unlinkSync(DATA + '/sessions/' + f);
    await page.fill('#f-about-h2', '关于<i>机长</i> 二');
    await page.click('#bar [data-act="save"]');
    await page.waitForSelector('.sheet input[type=password]', { timeout: 8000 }).catch(() => {});
    check(`[${W}] expired session opens the re-login sheet`, (await page.locator('.sheet input[type=password]').count()) === 1);
    await page.fill('.sheet input[type=password]', 'wrong-password-123');
    await page.locator('.sheet-btns button').last().click();
    await page.waitForSelector('.sheet .ferr:not([hidden])', { timeout: 8000 }).catch(() => {});
    check(`[${W}] wrong password in the sheet shows an error`, (await page.textContent('.sheet .ferr')).includes('密码不对'));
    await page.fill('.sheet input[type=password]', PW1);
    await page.locator('.sheet-btns button').last().click();
    st = await barDone(page);
    check(`[${W}] after re-login the save goes through and the text is kept`, st.text.includes('已保存草稿') && (await page.inputValue('#f-about-h2')).endsWith(' 二'), st);
    await page.click('#bar [data-act="publish"]');
    st = await barDone(page);
    check(`[${W}] 保存并发布 works`, st.cls.includes('is-ok') && st.text.includes('已发布'), st);
    const home1 = read(PUB + '/index.html');
    check(`[${W}] published home page has the new copy`, home1.includes('测试标题，<wbr>云底之下。') && home1.includes('关于&lt;i&gt;机长&lt;/i&gt; 二'), home1.match(/<h2[^>]*id="about-h"[^>]*>[^<]*/));
    check(`[${W}] published home page has the new FAQ row`, home1.includes('测试问题：后台好用吗？'));
    check(`[${W}] publishing made a version`, fs.readdirSync(DATA + '/versions').length === 2);
    const hp = await ctx.newPage();
    track(hp, R, base);
    await hp.goto(base + '/', { waitUntil: 'domcontentloaded' });
    await sleep(800);
    check(`[${W}] visitor sees the new about title`, (await hp.textContent('#about-h')) === '关于<i>机长</i> 二');
    check(`[${W}] injected markup stays text (no onerror element)`, await hp.evaluate(() => document.querySelectorAll('[onerror]').length === 0));
    await hp.close();
    check(`[${W}] hero code untouched by the publish`, require('child_process').spawnSync('php', [__dirname + '/hero-check.php', PUB + '/index.html']).stdout.toString().trim() === 'HERO OK');

    // ----- 关于 · 照片 (INSET A album): upload, caption, reorder, delete, keep one, publish -----
    await go(page, base, 'site');
    await openDet(page, '#g-about');
    const albItems = () => page.locator('#album-list .item');
    check(`[${W}] album starts with the bundled photo, which cannot be deleted while it is the only one`, await albItems().count() === 1 && await albItems().first().locator('[data-act="del"]').isDisabled() && (await albItems().first().locator('input').first().inputValue()) === 'ON TOP · 云层上方 · 日落之后');
    check(`[${W}] the legacy alt/caption fields are not shown twice`, await page.locator('#f-about-figcaption, #f-about-img_alt').count() === 0);
    const addPhoto = async file => {
      const [fc] = await Promise.all([page.waitForEvent('filechooser'), page.click('#album-list [data-act="add"]')]);
      await fc.setFiles(file);
      return barDone(page);
    };
    let ast = await addPhoto(FILES + '/not-an-image.jpg');
    check(`[${W}] album: a file that is not a picture is refused`, ast.cls.includes('is-err') && await albItems().count() === 1, ast);
    ast = await addPhoto(FILES + '/cover.jpg');
    check(`[${W}] album: photo uploaded and added with a thumbnail`, ast.text.includes('照片已上传') && await albItems().count() === 2 && await albItems().nth(1).locator('img.alb-thumb').evaluate(i => i.complete && i.naturalWidth > 0), ast);
    await albItems().nth(1).locator('input').first().fill('后台加的第二张');
    ast = await addPhoto(FILES + '/photo.png');
    await albItems().nth(2).locator('input').first().fill('竖着的第三张');
    await albItems().nth(2).locator('textarea').fill('一张橙色的照片');
    await albItems().nth(2).locator('[data-act="up"]').click();
    check(`[${W}] album: 上移 moves the photo`, (await albItems().nth(1).locator('input').first().inputValue()) === '竖着的第三张');
    check(`[${W}] album: 改过 badge on 关于`, (await page.locator('#g-about .badge').textContent()).startsWith('改过'));
    await layoutCheck(page, 'site (album)', W);
    await shot(page, 'site-album', W);
    await albItems().nth(1).locator('[data-act="del"]').click();
    await sheetOk(page);
    check(`[${W}] album: 删除 removes the photo`, await albItems().count() === 2 && (await albItems().nth(1).locator('input').first().inputValue()) === '后台加的第二张');
    await albItems().nth(1).locator('input').first().fill('');
    await page.click('#bar [data-act="publish"]');
    ast = await barDone(page);
    check(`[${W}] album: an empty caption is refused and named`, ast.cls.includes('is-err') && ast.text.includes('第 2 张照片'), ast);
    await albItems().nth(1).locator('input').first().fill('后台加的第二张');
    await page.click('#bar [data-act="publish"]');
    ast = await barDone(page);
    check(`[${W}] album: 保存并发布 works`, ast.cls.includes('is-ok'), ast);
    const homeA = read(PUB + '/index.html');
    check(`[${W}] album: the public page shows two photos in order`, homeA.includes('data-album') && (homeA.match(/class="alb-slide"/g) || []).length === 2 && homeA.indexOf('assets/about.webp') < homeA.indexOf('data-cap="后台加的第二张"') && !homeA.includes('竖着的第三张'));
    check(`[${W}] album: hero code untouched`, require('child_process').spawnSync('php', [__dirname + '/hero-check.php', PUB + '/index.html']).stdout.toString().trim() === 'HERO OK');
    // back to one photo (the uploaded one): the plain figure again
    await go(page, base, 'site');
    await openDet(page, '#g-about');
    await albItems().first().locator('[data-act="del"]').click();
    await sheetOk(page);
    check(`[${W}] album: the last photo cannot be deleted`, await albItems().count() === 1 && await albItems().first().locator('[data-act="del"]').isDisabled());
    await page.click('#bar [data-act="publish"]');
    ast = await barDone(page);
    const homeB = read(PUB + '/index.html');
    check(`[${W}] album: one photo renders the plain figure with the uploaded picture`, ast.cls.includes('is-ok') && !homeB.includes('data-album') && /<figure class="about-fig">\n\s*<div class="about-art">\n\s*<img src="uploads\//.test(homeB) && homeB.includes('<figcaption>后台加的第二张</figcaption>'), ast);

    // ----- 飞行日志 -----
    await go(page, base, 'posts');
    await layoutCheck(page, 'posts', W);
    await shot(page, 'posts', W);
    await page.locator('.chips a', { hasText: '预告' }).click();
    check(`[${W}] filter 预告 lists the 5 teasers`, await page.locator('.plist .prow').count() === 5);
    await page.locator('.chips a', { hasText: '草稿' }).click();
    check(`[${W}] filter 草稿 is empty`, await page.locator('.plist .prow').count() === 0);
    await Promise.all([page.waitForNavigation(), page.locator('form[action*="post.new"] button').first().click()]);
    check(`[${W}] 写新日志 opens the editor`, page.url().includes('r=post.edit'), page.url());
    const postId = new URL(page.url()).searchParams.get('id');
    const slug = await page.inputValue('#p-slug');
    const fix = await page.inputValue('#p-fix');
    check(`[${W}] new post gets a 5-letter fix and slug = lowercase fix`, /^[A-Z]{5}$/.test(fix) && slug === fix.toLowerCase(), [fix, slug]);
    await page.fill('#p-title', '后台测试日志：侧风落地');
    await page.click('#p-body');
    await page.click('.mdbar [data-md="h2"]');
    check(`[${W}] toolbar 小标题 inserts ## `, (await page.inputValue('#p-body')).startsWith('## 小标题'));
    await page.locator('#p-body').press('End');
    await page.locator('#p-body').type('\n落地前我先看风向袋。\n**侧风**超过 20 节就去备降场。\n- 第一条\n- 第二条');
    await page.fill('#p-tags', 'WX 侧风');
    await page.fill('#p-date', '2026-10-12');
    await page.click('#bar [data-act="save"]');
    st = await barDone(page);
    check(`[${W}] post 保存草稿 works`, st.text.includes('已保存草稿'), st);
    check(`[${W}] a draft is not public (no page, not on the home page)`, !fs.existsSync(`${PUB}/posts/${slug}.html`) && !read(PUB + '/index.html').includes('后台测试日志'));
    // uploads: refused files first
    await page.setInputFiles('#cover-file', FILES + '/not-an-image.jpg');
    st = await barDone(page, 'ok', 15000);
    check(`[${W}] a PHP file named .jpg is refused`, st.cls.includes('is-err') && !/uploads/.test(st.text), st);
    await page.setInputFiles('#cover-file', FILES + '/huge.jpg');
    await page.waitForFunction(() => /太大|MB/.test(document.getElementById('bar-status').textContent), null, { timeout: 30000 }).catch(() => {});
    st = { text: await page.textContent('#bar-status') };
    check(`[${W}] a 13 MB file is refused`, /太大/.test(st.text), st);
    await page.setInputFiles('#cover-file', FILES + '/cover.jpg');
    await page.waitForFunction(() => document.getElementById('bar-status').textContent.includes('封面图已上传'), null, { timeout: 20000 }).catch(() => {});
    const coverSrc = await page.getAttribute('#cover-img', 'src');
    check(`[${W}] cover uploaded and shown`, /\/uploads\/\d{4}\/\d{2}\/[0-9a-f]{16}\.webp$/.test(coverSrc || '') && await page.locator('#cover-img').isVisible(), coverSrc);
    check(`[${W}] cover image loads`, await page.evaluate(() => new Promise(res => { const i = document.getElementById('cover-img'); if (i.complete) res(i.naturalWidth > 0); else { i.onload = () => res(i.naturalWidth > 0); i.onerror = () => res(false); } })));
    await page.fill('#p-cover-alt', '雨后的跑道');
    // an image inside the body
    await page.locator('#p-body').press('End');
    await page.setInputFiles('#body-file', FILES + '/photo.png');
    await page.waitForSelector('.sheet input', { timeout: 20000 }).catch(() => {});
    await page.locator('.sheet input').nth(0).fill('橙色测试图');
    await page.locator('.sheet input').nth(1).fill('图下的小字');
    await sheetOk(page);
    check(`[${W}] 插入图片 writes an image line into the body`, /\n!\[橙色测试图\]\(uploads\/\d{4}\/\d{2}\/[0-9a-f]{16}\.webp "图下的小字"\)/.test(await page.inputValue('#p-body')));
    // preview (phone: the 预览 tab; desktop: side by side)
    if (W < 1100) await page.click('#tab-preview');
    await sleep(1600);
    const frame = page.frame({ name: 'pv' });
    await frame.waitForSelector('h1', { timeout: 10000 }).catch(() => {});
    check(`[${W}] live preview shows title, cover and body image`, (await frame.textContent('h1').catch(() => '')).includes('后台测试日志') && await frame.locator('img').count() >= 2, await frame.textContent('body').catch(() => ''));
    await layoutCheck(page, W < 1100 ? 'post editor (预览 tab)' : 'post editor', W);
    await shot(page, W < 1100 ? 'post_preview' : 'post_edit', W);
    if (W < 1100) await page.click('#tab-edit');
    await layoutCheck(page, 'post editor', W);
    if (W < 1100) await shot(page, 'post_edit', W);
    await page.click('#bar [data-act="publish"]');
    st = await barDone(page);
    check(`[${W}] 发布 works`, st.text.includes('已发布'), st);
    check(`[${W}] the post page exists`, fs.existsSync(`${PUB}/posts/${slug}.html`));
    check(`[${W}] the home page links to the post`, read(PUB + '/index.html').includes(`href="posts/${slug}.html"`));
    check(`[${W}] the archive lists the post`, read(PUB + '/posts/index.html').includes(`href="${slug}.html"`));
    check(`[${W}] the button now reads 发布修改 and the slug is locked`, (await page.textContent('#bar [data-act="publish"]')) === '发布修改' && await page.locator('#p-slug').getAttribute('readonly') !== null);
    const pp = await ctx.newPage();
    track(pp, R, base);
    await pp.goto(`${base}/posts/${slug}.html`);
    check(`[${W}] visitor opens the post page with the cover`, (await pp.textContent('h1')).includes('后台测试日志') && await pp.evaluate(() => [...document.images].every(i => i.complete && i.naturalWidth > 0) && document.images.length >= 2));
    const pm = await pp.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }));
    check(`[${W}] post page has no horizontal scroll`, pm.sw <= pm.cw, pm);
    const pageSha = sha(`${PUB}/posts/${slug}.html`);
    await page.locator('#p-body').press('End');
    await page.locator('#p-body').type('\n补一句：复飞不丢人。');
    await page.click('#bar [data-act="save"]');
    st = await barDone(page);
    check(`[${W}] editing a published post and saving leaves the page unchanged`, st.text.includes('已保存草稿') && sha(`${PUB}/posts/${slug}.html`) === pageSha);
    await page.reload();
    check(`[${W}] status shows 有未发布的修改`, (await page.textContent('#bar-status')).includes('有未发布的修改'));
    await page.click('#bar [data-act="publish"]');
    st = await barDone(page);
    check(`[${W}] 发布修改 updates the page`, st.text.includes('已发布') && read(`${PUB}/posts/${slug}.html`).includes('复飞不丢人'));
    await pp.reload();
    check(`[${W}] visitor sees the edit`, (await pp.textContent('body')).includes('复飞不丢人'));
    // 撤下
    await page.click('#bar [data-act="more"]');
    await sheetAct(page, '撤下');
    await sheetOk(page);
    st = await barDone(page);
    r = await page.request.get(`${base}/posts/${slug}.html`);
    check(`[${W}] 撤下 removes the page (404) and the home link`, st.text.includes('已撤下') && r.status() === 404 && !read(PUB + '/index.html').includes('后台测试日志'), [st, r.status()]);
    // 设为预告 (needs an excerpt and a reading time)
    await page.fill('#p-excerpt', '侧风落地的几条个人习惯。');
    await page.fill('#p-minutes', '4');
    await page.click('#bar [data-act="more"]');
    await sheetAct(page, '设为预告');
    st = await barDone(page);
    const home2 = read(PUB + '/index.html');
    check(`[${W}] 设为预告 shows the title on the home page without a link`, st.text.includes('预告') && home2.includes('后台测试日志：侧风落地') && !home2.includes(`posts/${slug}.html`), st);
    // 删除
    await page.click('#bar [data-act="more"]');
    await sheetAct(page, '删除这篇');
    await page.waitForSelector('.sheet-btns .btn-danger');
    await Promise.all([page.waitForNavigation(), page.locator('.sheet-btns .btn-danger').click()]);
    check(`[${W}] 删除 goes back to the list with a message`, page.url().includes('r=posts') && (await flashText(page)).includes('已删除《后台测试日志：侧风落地》'), await flashText(page));
    check(`[${W}] deleted post is gone from the list and the home page`, !(await page.locator('.plist').textContent()).includes('后台测试日志') && !read(PUB + '/index.html').includes('后台测试日志'));
    check(`[${W}] deleted post moved to the trash`, fs.readdirSync(DATA + '/trash/posts').some(f => f.startsWith(postId)));
    // home leg limit
    await page.selectOption('#leg_limit', '4');
    await Promise.all([page.waitForNavigation(), page.locator('form[action*="posts.limit"] button').click()]);
    check(`[${W}] 首页最多显示 saves and republishes`, (await flashText(page)).includes('首页最多显示 4 篇') && (read(PUB + '/index.html').match(/data-route/g) || []).length > 0);

    // ----- AI 超市 -----
    await go(page, base, 'shelf');
    await page.click('#shelf-add');
    const S = () => page.locator('#shelves > details.shelf').filter({ has: page.locator('.shelf-t', { hasText: '测试货架' }) });
    await page.locator('#shelves > details.shelf').last().locator('input').first().fill('测试货架');
    await S().locator('textarea').first().fill('给后台测试用的货架。');
    for (const [name, url] of [['甲工具', 'https://a.example.com/x'], ['乙工具', 'https://b.example.com'], ['临时工具', 'https://c.example.com']]) {
      await S().locator('[data-act="add-tool"]').click();
      const T = S().locator('details.tool').last();
      await T.locator('input').nth(0).fill(name);
      await T.locator('input').nth(1).fill(name + '的一句话介绍');
      await T.locator('input').nth(2).fill(url);
    }
    check(`[${W}] new tools default to 还没确认`, (await S().locator('details.tool').first().locator('select[id$="-cn"]').inputValue()) === '' && (await S().locator('details.tool .chip').first().textContent()) === '还没确认');
    await S().locator('details.tool').nth(1).locator('select[id$="-cn"]').selectOption('需要国际网络');
    await S().locator('details.tool').first().locator('[data-act="down"]').click();
    check(`[${W}] tool 下移 reorders`, (await S().locator('.tool-n').allTextContents()).join(',') === '乙工具,甲工具,临时工具');
    await S().locator('details.tool').last().locator('[data-act="del"]').click();
    await sheetOk(page);
    check(`[${W}] tool 删除 asks and removes`, (await S().locator('.tool-n').allTextContents()).join(',') === '乙工具,甲工具');
    await S().locator('button', { hasText: '上移货架' }).click();
    const order = await page.locator('#shelves .shelf-t').allTextContents();
    check(`[${W}] shelf 上移货架 reorders`, order.indexOf('测试货架') === order.length - 2, order);
    // move a tool from the first shelf into the test shelf
    const first = page.locator('#shelves > details.shelf').first();
    await openDet(page, first);
    const firstName = await first.locator('.tool-n').first().textContent();
    await openDet(page, first.locator('details.tool').first());
    const mv = first.locator('details.tool').first().locator('.move-row select');
    await mv.selectOption({ label: '测试货架' });
    await first.locator('details.tool').first().locator('[data-act="move"]').click();
    check(`[${W}] 移到别的货架 moves a tool`, (await S().locator('.tool-n').allTextContents()).join(',') === '乙工具,甲工具,' + firstName);
    await layoutCheck(page, 'shelf (open)', W);
    await shot(page, 'shelf', W);
    const tool0 = S().locator('details.tool').first();
    await openDet(page, tool0);
    await tool0.locator('textarea').fill('心得里有 ' + XSS);
    await page.click('#bar [data-act="publish"]');
    st = await barDone(page);
    check(`[${W}] shelf 保存并发布 works`, st.text.includes('已发布'), st);
    const shelf = shelfJson(read(PUB + '/index.html'));
    const ts = shelf && (shelf.shelves || shelf).find(s => s.title === '测试货架');
    check(`[${W}] published shelf JSON is valid and holds the new shelf in order`, !!ts && ts.tools.map(t => t.name).join(',') === '乙工具,甲工具,' + firstName, ts);
    check(`[${W}] 还没确认 publishes as null, 需要国际网络 as set`, ts && ts.tools[1].cn_access === null && ts.tools[0].cn_access === '需要国际网络', ts && ts.tools.map(t => t.cn_access));
    const sp = await ctx.newPage();
    track(sp, R, base);
    await sp.goto(base + '/', { waitUntil: 'domcontentloaded' });
    await sleep(800);
    const shelfOnPage = await sp.evaluate(() => { try { const d = JSON.parse(document.getElementById('shelf-data').textContent); return (d.shelves || d).map(s => s.title); } catch (e) { return String(e); } });
    check(`[${W}] visitor page parses the new shelf`, Array.isArray(shelfOnPage) && shelfOnPage.includes('测试货架'), shelfOnPage);
    check(`[${W}] no script runs from shelf text`, await sp.evaluate(() => document.querySelectorAll('[onerror]').length === 0));

    // ----- 订阅 from the public page, then the admin list -----
    await sp.evaluate(() => document.getElementById('subscribe').scrollIntoView({ block: 'center', behavior: 'instant' }));
    await sleep(1700);
    await sp.fill('#sub-email', 'Reader.One@Example.com');
    await sp.check('#sub-consent');
    await sp.click('#sub-btn');
    await sp.waitForSelector('#sub-done:not([hidden])', { timeout: 10000 }).catch(() => {});
    check(`[${W}] visitor subscribes from the home page`, (await sp.textContent('#sub-done').catch(() => '')).includes('已订阅'));
    await sp.close();
    for (const email of ['=sum@example.com', 'second.reader@example.org']) {
      r = await page.request.post(base + '/api/subscribe.php', { form: { email, consent: '1', website: '', t: '5000' }, headers: { Accept: 'application/json', Origin: base } });
      check(`[${W}] subscribe endpoint stores ${email}`, r.status() === 200 && (await r.json()).ok === true, r.status());
    }
    await go(page, base, 'subs');
    const emails = await page.locator('.srow-e').allTextContents();
    check(`[${W}] 订阅者 lists the addresses (lowercased)`, emails.length === 3 && emails.includes('reader.one@example.com'), emails);
    check(`[${W}] 订阅者 shows the no-automatic-mail note`, (await page.textContent('main')).includes('本站不会自动发邮件'));
    await layoutCheck(page, 'subs', W);
    await shot(page, 'subs', W);
    await page.fill('#q', 'READER.ONE');
    await Promise.all([page.waitForNavigation(), page.locator('.find-row button').click()]);
    check(`[${W}] search finds by part of the address, any case`, (await page.locator('.srow-e').allTextContents()).join() === 'reader.one@example.com');
    await page.fill('#q', 'nobody-here');
    await Promise.all([page.waitForNavigation(), page.locator('.find-row button').click()]);
    check(`[${W}] search with no hit says so`, (await page.textContent('main')).includes('没有找到包含'));
    await go(page, base, 'subs');
    await page.click('#copy-all');
    await sleep(300);
    const copied = (await page.textContent('#copy-msg')) + (await page.locator('.sheet textarea').count() ? (await page.locator('.sheet textarea').inputValue()) : '');
    check(`[${W}] 复制全部邮箱 copies or offers manual copy`, copied.includes('已复制 3 个邮箱') || copied.includes('reader.one@example.com'), copied);
    if (await page.locator('.sheet').count()) await page.locator('.sheet-btns button').first().click();
    const [dl] = await Promise.all([page.waitForEvent('download'), page.locator('a', { hasText: '导出表格' }).click()]);
    const csvPath = tmp + '/export.csv';
    await dl.saveAs(csvPath);
    const csv = fs.readFileSync(csvPath);
    const csvText = csv.slice(3).toString('utf8');
    check(`[${W}] CSV file name subscribers-YYYYMMDD.csv`, /^subscribers-\d{8}\.csv$/.test(dl.suggestedFilename()), dl.suggestedFilename());
    check(`[${W}] CSV starts with a UTF-8 BOM and the Chinese header, CRLF`, csv[0] === 0xEF && csv[1] === 0xBB && csv[2] === 0xBF && csvText.startsWith('"邮箱","订阅时间","同意的内容"\r\n'), csvText.slice(0, 60));
    check(`[${W}] CSV quotes every cell and defuses formula cells`, csvText.includes('"\'=sum@example.com"') && csvText.includes('"reader.one@example.com"'), csvText);
    await page.locator('.srow', { hasText: '=sum@example.com' }).locator('input').check();
    await page.click('#subs-del button[type=submit]');
    await page.waitForSelector('.sheet');
    check(`[${W}] delete asks first`, (await page.textContent('.sheet')).includes('删除选中的邮箱'));
    await Promise.all([page.waitForNavigation(), page.locator('.sheet-btns .btn-danger').click()]);
    check(`[${W}] 删除所选 removes one address`, (await flashText(page)).includes('已删除 1 个邮箱') && (await page.locator('.srow-e').count()) === 2);
    await Promise.all([page.waitForNavigation().catch(() => {}), page.locator('form[action*="subs.toggle"] button').click()]);
    if (await page.locator('.sheet').count()) await Promise.all([page.waitForNavigation(), sheetOk(page)]);
    check(`[${W}] 关掉订阅表单 republishes the off state`, (await flashText(page)).includes('订阅表单已关闭') && read(PUB + '/index.html').includes('class="micro mode-a"'));
    await Promise.all([page.waitForNavigation(), page.locator('form[action*="subs.toggle"] button').click()]);
    check(`[${W}] 打开订阅表单 republishes the live form`, (await flashText(page)).includes('订阅表单已打开') && read(PUB + '/index.html').includes('id="sub-consent"'));

    // ----- AI 助手 -----
    await go(page, base, 'ai');
    await page.fill('#key', 'not-a-key');
    await Promise.all([page.waitForNavigation(), page.locator('form[action*="ai.key"] button[type=submit]').click()]);
    check(`[${W}] a wrong-looking key is refused`, (await flashText(page)).includes('这不像是 Anthropic 的 API 密钥'), await flashText(page));
    await page.fill('#key', FAKE_KEY);
    await Promise.all([page.waitForNavigation(), page.locator('form[action*="ai.key"] button[type=submit]').click()]);
    const aiHtml = await page.content();
    check(`[${W}] key saved, page shows only the last 4 (WXYZ)`, (await flashText(page)).includes('结尾是 WXYZ') && (await page.textContent('#key-status')).includes('WXYZ'));
    check(`[${W}] the full key is never in the page`, !aiHtml.includes(FAKE_KEY) && !aiHtml.includes(FAKE_KEY.slice(0, 20)) && (await page.inputValue('#key')) === '');
    check(`[${W}] only secrets.json holds the key`, grepData(DATA, FAKE_KEY).join() === 'secrets.json', grepData(DATA, FAKE_KEY));
    await page.click('#ai-test');
    await page.waitForFunction(() => document.getElementById('ai-test-msg').textContent !== '', null, { timeout: 10000 }).catch(() => {});
    check(`[${W}] 测试连接 answers in plain Chinese`, (await page.textContent('#ai-test-msg')).includes('连接正常'));
    await Promise.all([page.waitForNavigation(), page.locator('form', { has: page.locator('input[name=part][value=switch]') }).locator('button').click()]);
    check(`[${W}] 打开助手 publishes CHAT_LIVE = true`, (await flashText(page)).includes('助手已打开') && read(PUB + '/index.html').includes('const CHAT_LIVE = true;'));
    for (let i = 0; i < 2; i++) {
      r = await page.request.post(base + '/api/chat.php', { data: { messages: [{ role: 'user', content: '测试问题' + i }] }, headers: { Accept: 'application/json', Origin: base } });
      check(`[${W}] fake chat answer ${i + 1}`, r.status() === 200 && (await r.json()).reply === '（测试回答）', r.status());
    }
    await page.fill('#daily_limit', '120');
    await page.fill('#ip_limit', '9');
    await page.fill('#extra_note', '最近在改装备，不聊具体航线。');
    await Promise.all([page.waitForNavigation(), page.locator('form', { has: page.locator('input[name=part][value=limits]') }).locator('button[type=submit]').click()]);
    check(`[${W}] limits and note saved`, (await flashText(page)).includes('已保存限额') && (await page.inputValue('#daily_limit')) === '120' && (await page.inputValue('#ip_limit')) === '9');
    await page.fill('#ip_limit', '999');
    await Promise.all([page.waitForNavigation(), page.locator('form', { has: page.locator('input[name=part][value=limits]') }).locator('button[type=submit]').click()]);
    check(`[${W}] out-of-range limit refused in plain Chinese`, (await flashText(page)).startsWith('没有保存'), await flashText(page));
    const row = await page.locator('table.usage tbody tr').first().locator('td').allTextContents();
    check(`[${W}] usage table shows today's requests and tokens`, row[0] === '2' && row[1] === '2' && row[4] === '240' && row[5] === '80', row);
    check(`[${W}] usage bar chart is inline SVG with 30 bars`, await page.locator('figure.chart svg rect').count() === 30);
    await layoutCheck(page, 'ai', W);
    await shot(page, 'ai', W);
    await page.locator('form[action*="ai.key_remove"] button').click();
    await page.waitForSelector('.sheet-btns .btn-danger');
    await Promise.all([page.waitForNavigation(), page.locator('.sheet-btns .btn-danger').click()]);
    check(`[${W}] 删除密钥 removes it and the page goes back to INOP`, (await flashText(page)).includes('密钥已删除') && (await page.textContent('#key-status')).includes('还没有设置') && read(PUB + '/index.html').includes('const CHAT_LIVE = false;'));
    check(`[${W}] the key is gone from the data dir`, grepData(DATA, FAKE_KEY).length === 0);
    await Promise.all([page.waitForNavigation(), page.locator('form', { has: page.locator('input[name=part][value=switch]') }).locator('button').click()]);
    check(`[${W}] 关闭助手 works`, (await flashText(page)).includes('助手已关闭'));
    await go(page, base, 'dashboard');
    check(`[${W}] 概览 shows today's usage`, (await page.textContent('#c-ai ~ dl')).includes('2 / 120'));

    // ----- 访问统计 -----
    {
      const day = new Date(Date.now() + 8 * 3600e3).toISOString().slice(0, 10);   // Beijing day
      fs.mkdirSync(DATA + '/stats', { recursive: true });
      fs.writeFileSync(DATA + '/stats/' + day + '.json', JSON.stringify({ date: day, views: 57, visitors: 21,
        paths: { '/': 30, '/posts/': 5, '/posts/gone-post.html': 2 },
        refs: { 'mp.weixin.qq.com': 6, 'google.com.hk': 3, 'a-rather-long-host-name-for-the-phone-layout.example.com': 1 },
        devices: { phone: 15, desktop: 6 }, wechat: 9 }));
      await go(page, base, 'stats');
      const t = await page.textContent('main');
      check(`[${W}] 访问统计 shows today's visitors and views`, (await page.textContent('#st-today-visitors')).trim() === '21' && (await page.textContent('#st-today-views')).trim() === '57');
      check(`[${W}] 访问统计 names pages and referrers in plain Chinese`, t.includes('首页') && t.includes('全部日志') && t.includes('已撤下的日志（gone-post）') && t.includes('微信公众号') && t.includes('谷歌'), t.slice(0, 300));
      check(`[${W}] 访问统计 device split`, t.includes('71%') && t.includes('29%') && t.includes('在微信里打开的 9 位'));
      check(`[${W}] 访问统计 says nothing personal is kept`, t.includes('不保存访客的 IP 地址'));
      check(`[${W}] 访问统计 chart has 30 bars`, await page.locator('.chart svg rect').count() === 30);
      await layoutCheck(page, 'stats', W);
      await shot(page, 'stats', W);
      await go(page, base, 'dashboard');
      const card = await page.textContent('section[aria-labelledby="c-stats"]');
      check(`[${W}] 概览 card: 今天访客 21 / 今天浏览 57`, card.includes('今天访客') && card.includes('21') && card.includes('今天浏览') && card.includes('57'), card);
    }

    // ----- 版本记录 and rollback -----
    await go(page, base, 'versions');
    const rows = page.locator('.vrow');
    const nv = await rows.count();
    check(`[${W}] 版本记录 lists the publishes (newest marked 当前)`, nv >= 5 && (await rows.first().textContent()).includes('当前'), nv);
    await layoutCheck(page, 'versions', W);
    await shot(page, 'versions', W);
    const [vv] = await Promise.all([ctx.waitForEvent('page'), rows.last().locator('a', { hasText: '看一看' }).click()]);
    await vv.waitForLoadState('domcontentloaded');
    check(`[${W}] 看一看 shows an old version with a banner`, (await vv.content()).includes('只是看一看'));
    await vv.close();
    const firstReason = (await rows.last().locator('.vrow-r').textContent()).trim();
    await rows.last().locator('form button').click();
    await page.waitForSelector('.sheet');
    check(`[${W}] rollback asks first`, (await page.textContent('.sheet')).includes('草稿不受影响'));
    await Promise.all([page.waitForNavigation(), sheetOk(page)]);
    const home3 = read(PUB + '/index.html');
    check(`[${W}] rollback to「${firstReason}」restores the old page`, (await flashText(page)).includes('已回滚') && !home3.includes('测试标题，') && !home3.includes('测试货架'), await flashText(page));
    check(`[${W}] rollback keeps the site address`, home3.includes(`https://127.0.0.1:${port}/assets/og.jpg`));
    check(`[${W}] rollback adds a version on top`, (await page.locator('.vrow').first().textContent()).includes('回滚到'));
    await go(page, base, 'site');
    check(`[${W}] drafts untouched by the rollback`, (await page.inputValue('#f-hero-b1-hl')) === newHl && (await page.textContent('#bar-status')).includes('还没发布'));

    // ----- 修改密码 and 退出 -----
    await go(page, base, 'password');
    await layoutCheck(page, 'password', W);
    await shot(page, 'password', W);
    await page.fill('#old', 'not-my-password');
    await page.fill('#new1', PW2);
    await page.fill('#new2', PW2);
    await Promise.all([page.waitForNavigation(), page.locator('main form button[type=submit]').click()]);
    check(`[${W}] wrong current password refused`, (await page.textContent('main')).includes('当前密码不对'));
    await page.fill('#old', PW1);
    await page.fill('#new1', PW2);
    await page.fill('#new2', PW2);
    await Promise.all([page.waitForNavigation(), page.locator('main form button[type=submit]').click()]);
    check(`[${W}] password changed`, (await flashText(page)).includes('密码已经改好'));
    await openMenuIfPhone(page);
    await Promise.all([page.waitForNavigation(), page.locator('.menu-logout button').click()]);
    check(`[${W}] 退出 goes to the login page`, page.url().includes('r=login') && (await flashText(page)).includes('已退出'));
    await go(page, base, 'subs');
    check(`[${W}] pages need a login after 退出`, page.url().includes('r=login'));
    r = await page.request.get(base + '/admin/?r=subs.export');
    check(`[${W}] CSV export needs a login`, !(await r.text()).includes('reader.one@example.com'));
    await layoutCheck(page, 'login', W);
    await shot(page, 'login', W);
    await page.fill('#password', PW1);
    await Promise.all([page.waitForNavigation(), page.click('main button[type=submit]')]);
    check(`[${W}] old password no longer works`, (await page.textContent('main')).includes('密码不对'));
    await page.fill('#password', PW2);
    await Promise.all([page.waitForNavigation(), page.click('main button[type=submit]')]);
    check(`[${W}] new password logs in`, (await page.textContent('h1')) === '概览');

    // ----- login throttling (last: it locks this IP for 15 minutes) -----
    await openMenuIfPhone(page);
    await Promise.all([page.waitForNavigation(), page.locator('.menu-logout button').click()]);
    let msg = '';
    for (let i = 0; i < 6; i++) {
      await page.fill('#password', 'wrong-password-' + i);
      await Promise.all([page.waitForNavigation(), page.click('main button[type=submit]')]);
      msg = await page.textContent('main');
    }
    check(`[${W}] repeated wrong passwords lock the login`, msg.includes('尝试次数太多，请 15 分钟后再试'), msg.slice(0, 120));
    await page.fill('#password', PW2);
    await Promise.all([page.waitForNavigation(), page.click('main button[type=submit]')]);
    check(`[${W}] while locked even the right password waits`, (await page.textContent('main')).includes('尝试次数太多'));

    check(`[${W}] no console errors in admin and public pages`, R.console.length === 0, R.console);
    check(`[${W}] no browser dialogs (no confirm(), no injected alert)`, R.dialogs.length === 0, R.dialogs);
    check(`[${W}] no external requests`, R.external.length === 0, R.external);
    check(`[${W}] no server errors (5xx)`, R.server.length === 0, R.server);
    const leaked = grepData(DATA, PW1).concat(grepData(DATA, PW2));
    check(`[${W}] passwords are not stored in plain text anywhere`, leaked.length === 0, leaked);
  } catch (e) {
    check(`[${W}] run finished without an exception`, false, String(e && e.stack || e).slice(0, 600));
    if (!NOSHOTS) { fs.mkdirSync(SHOTS, { recursive: true }); await page.screenshot({ path: `${SHOTS}/ERROR-${W}.png`, fullPage: true }).catch(() => {}); }
  } finally {
    await ctx.close();
    server.kill();
    fs.rmSync(tmp, { recursive: true, force: true });
  }
}

(async () => {
  const browser = await chromium.launch();
  for (const W of WIDTHS) await run(browser, W);
  // 360 px: the narrowest phone in the spec, layout only
  if (WIDTHS.includes(390)) await narrow(browser);
  await browser.close();
  console.log(`\n${failures === 0 ? 'ALL PASS' : failures + ' FAILED'}: ${log.length} checks`);
  process.exit(failures ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });

async function narrow(browser) {
  console.log('\n== admin layout at 360 px ==');
  const tmp = makeSite();
  const port = await freePort(9100);
  const server = await startServer(tmp, port);
  const base = 'http://127.0.0.1:' + port;
  const ctx = await browser.newContext({ viewport: { width: 360, height: 740 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
  const page = await ctx.newPage();
  try {
    await page.goto(base + '/admin/');
    await layoutCheck(page, 'setup', 360);
    const code = read(tmp + '/data/setup-code.txt').match(/[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}/)[0];
    await page.fill('#code', code);
    await page.fill('#password', PW1);
    await page.fill('#password2', PW1);
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    for (const r of ['dashboard', 'site', 'posts', 'shelf', 'subs', 'stats', 'ai', 'versions', 'password']) {
      await go(page, base, r);
      if (r === 'site') { await openDet(page, '#g-faq'); await openDet(page, '#g-type'); }
      if (r === 'shelf') { await openDet(page, page.locator('#shelves > details.shelf').first()); await openDet(page, page.locator('details.tool').first()); }
      await layoutCheck(page, r, 360);
    }
    await go(page, base, 'posts');
    await page.locator('.prow').first().click();
    await page.waitForSelector('#p-title');
    await layoutCheck(page, 'post editor', 360);
    await page.click('#bar [data-act="more"]');
    await layoutCheck(page, 'post editor (more sheet)', 360);
    await shot(page, 'post_more_sheet', 360);
    await page.locator('.sheet-btns button').first().click();
    await openMenuIfPhone(page);
    await layoutCheck(page, 'menu', 360);
    await shot(page, 'menu', 360);
  } catch (e) {
    check('[360] layout run finished without an exception', false, String(e && e.stack || e).slice(0, 600));
  } finally {
    await ctx.close();
    server.kill();
    fs.rmSync(tmp, { recursive: true, force: true });
  }
}
