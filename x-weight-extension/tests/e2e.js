// 端到端:加载插件,把 x.com 请求指向本地假页面,检查标记、记录和复盘页。
// 运行:CHROMIUM_PATH=... NODE_PATH=$(npm root -g) node tests/e2e.js
const { chromium } = require('playwright');
const path = require('path'), fs = require('fs'), os = require('os'), assert = require('assert');
(async () => {
  const ext = path.resolve(__dirname, '..');
  const ctx = await chromium.launchPersistentContext(fs.mkdtempSync(path.join(os.tmpdir(), 'xw-')), {
    headless: true, executablePath: process.env.CHROMIUM_PATH || undefined,
    args: [`--disable-extensions-except=${ext}`, `--load-extension=${ext}`],
  });
  const errors = [];
  let fixture = fs.readFileSync(path.join(__dirname, 'fixture.html'), 'utf8');
  await ctx.route('https://x.com/**', r => r.fulfill({ contentType: 'text/html', body: fixture }));
  const page = await ctx.newPage();
  page.on('pageerror', e => errors.push(e.message));
  await page.goto('https://x.com/MyHandle');
  await page.waitForSelector('#t1 .xw-badge', { timeout: 10000 });
  const badges = await page.$$eval('.xw-badge', bs => bs.map(b => [b.closest('article').id, b.textContent, b.className]));
  console.log('badges:', badges);
  assert.deepStrictEqual(badges.map(b => b[1]), ['⚖ 18.1', '⚖ 124', '⚖ 242?', '⚖ —']);   // 18.08;别人:(5*1200+300+0.708*5100)/80000*1000 = 123.9
  assert.ok(badges[1][2].includes('xw-hot') && badges[0][2].includes('xw-ok') && badges[2][2].includes('xw-few') && badges[3][2].includes('xw-na'));
  const tip = await page.$eval('#t1 .xw-badge', b => b.title); assert.ok(tip.includes('回复 20 × 5'));

  // 不应因自己的修改而无限循环:记录一段时间内的 DOM 变动次数
  const muts = await page.evaluate(() => new Promise(res => { let n = 0; const o = new MutationObserver(m => n += m.length);
    o.observe(document.body, { subtree: true, childList: true, characterData: true, attributes: true }); setTimeout(() => res(n), 2500); }));
  assert.ok(muts < 5, 'badge loop: ' + muts);

  // 数字增长后,徽章更新、历史记录追加
  await page.evaluate(() => { document.querySelector('#t1 [data-testid="reply"] span').textContent = '40'; });
  await page.waitForFunction(() => document.querySelector('#t1 .xw-badge').textContent === '⚖ 28.1');
  await page.waitForTimeout(2500);

  const [sw] = ctx.serviceWorkers().length ? ctx.serviceWorkers() : [await ctx.waitForEvent('serviceworker')];
  const id = sw.url().split('/')[2];
  const dash = await ctx.newPage();
  dash.on('pageerror', e => errors.push(e.message));
  await dash.goto(`chrome-extension://${id}/dashboard.html`);
  await dash.waitForSelector('#tbl tr:nth-child(2)');
  const stored = await dash.evaluate(() => new Promise(r => chrome.storage.local.get(null, r)));
  console.log('myHandle:', stored.myHandle, 'posts:', Object.keys(stored.posts));
  assert.strictEqual(stored.myHandle, 'myhandle');
  assert.deepStrictEqual(Object.keys(stored.posts), ['111']);                 // 只记录自己的帖子
  assert.strictEqual(stored.posts['111'].replies, 40);
  assert.strictEqual(stored.posts['111'].history.length, 2);
  const row = await dash.$eval('#tbl tr:nth-child(2)', r => r.innerText.replace(/\s+/g, ' '));
  console.log('dashboard row:', row); assert.ok(row.includes('C919') && row.includes('28.1'));

  // 修改权重 → 复盘页和 X 页面都按新权重重算
  await dash.fill('#w_reply', '10'); await dash.click('#wsave');
  await dash.waitForFunction(() => document.querySelector('#tbl').innerText.includes('48.1'));
  await page.waitForFunction(() => document.querySelector('#t1 .xw-badge').textContent === '⚖ 48.1');
  if (process.env.SHOT) await dash.screenshot({ path: process.env.SHOT, fullPage: true });
  assert.deepStrictEqual(errors, []);
  console.log('全部通过');
  await ctx.close();
})().catch(e => { console.error('失败:', e.message); process.exit(1); });
