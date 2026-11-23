const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
let sortKey = 'score', sortDir = -1, weights = XW.DEFAULT_WEIGHTS, posts = [], me = null;

const COLS = [
  ['created', '发布时间'], ['text', '内容', 'txt'], ['views', '浏览'], ['replies', '回复'], ['reposts', '转发'],
  ['likes', '点赞'], ['bookmarks', '收藏'], ['replyRate', '回复率'], ['score', '参考分'],
];

function load() {
  chrome.storage.local.get(['posts', 'weights', 'myHandle'], r => {
    weights = Object.assign({}, XW.DEFAULT_WEIGHTS, r.weights || {});
    me = r.myHandle || null;
    posts = Object.values(r.posts || {}).map(p => {
      const s = XW.score(p, weights);
      return Object.assign({}, p, { score: s ? s.total : null, replyRate: p.views ? p.replies / p.views * 100 : null });
    });
    render();
  });
}

function render() {
  $('who').textContent = me ? `账号 @${me} · 已记录 ${posts.length} 条帖子` : '还没有识别到你的账号:请先登录 X 并打开 x.com';
  const scored = posts.filter(p => p.score != null);
  const avg = scored.length ? scored.reduce((a, p) => a + p.score, 0) / scored.length : null;
  const best = scored.slice().sort((a, b) => b.score - a.score)[0];
  const tv = posts.reduce((a, p) => a + (p.views || 0), 0);
  const tr = posts.reduce((a, p) => a + (p.replies || 0), 0);
  $('stats').innerHTML = [
    ['平均参考分', XW.fmt(avg)], ['最高参考分', best ? XW.fmt(best.score) : '—'],
    ['总浏览', tv.toLocaleString()], ['整体回复率', tv ? (tr / tv * 100).toFixed(2) + '%' : '—'],
  ].map(([l, v]) => `<div class="card stat"><span class="sub">${l}</span><b>${v}</b></div>`).join('');

  const list = posts.slice().sort((a, b) => {
    const x = a[sortKey], y = b[sortKey];
    if (x == null) return 1; if (y == null) return -1;
    return (x > y ? 1 : x < y ? -1 : 0) * sortDir;
  });
  $('tbl').innerHTML = posts.length ? `<tr>${COLS.map(([k, l, c]) => `<th class="${c || ''}" data-k="${k}">${l}${k === sortKey ? (sortDir < 0 ? ' ↓' : ' ↑') : ''}</th>`).join('')}</tr>` +
    list.map(p => {
      const t = XW.tier(p.score);
      return `<tr><td>${p.created ? new Date(p.created).toLocaleString('zh-CN', { hour12: false }).slice(0, 16) : '—'}</td>
        <td class="txt"><a href="https://x.com/${esc(p.handle)}/status/${esc(p.id)}" target="_blank" rel="noopener">${esc((p.text || '(无文字)').slice(0, 90))}</a></td>
        <td>${(p.views || 0).toLocaleString()}</td><td>${p.replies || 0}</td><td>${p.reposts || 0}</td><td>${p.likes || 0}</td><td>${p.bookmarks || 0}</td>
        <td>${p.replyRate == null ? '—' : p.replyRate.toFixed(2) + '%'}</td>
        <td class="${t.key === 'hot' ? 'hot' : t.key === 'good' ? 'good' : ''}" title="${t.label}">${XW.fmt(p.score)}</td></tr>`;
    }).join('') : '';
  if (!posts.length) $('tbl').innerHTML = '<tr><td class="empty">还没有数据。打开你的 X 主页往下翻,插件会自动记录。</td></tr>';
  document.querySelectorAll('#tbl th').forEach(th => th.onclick = () => {
    const k = th.dataset.k; sortDir = sortKey === k ? -sortDir : -1; sortKey = k; render();
  });

  const buckets = Array.from({ length: 24 }, () => []);
  scored.forEach(p => { if (p.created) buckets[new Date(p.created).getHours()].push(p.score); });
  const means = buckets.map(b => b.length ? b.reduce((a, x) => a + x, 0) / b.length : 0);
  const mx = Math.max(1, ...means);
  $('hours').innerHTML = means.map((m, h) => `<div style="height:${m / mx * 100}%" title="${h}点:平均 ${XW.fmt(buckets[h].length ? m : null)},${buckets[h].length} 条"></div>`).join('');
  $('hlab').innerHTML = buckets.map((b, h) => `<span>${h}<br><span style="opacity:.6">${b.length || ''}</span></span>`).join('');

  $('wsrc').textContent = '默认值来源:' + XW.WEIGHTS_SOURCE;
  $('w_reply').value = weights.reply; $('w_repost').value = weights.repost; $('w_like').value = weights.like;
}

$('wsave').onclick = () => {
  const w = { reply: +$('w_reply').value, repost: +$('w_repost').value, like: +$('w_like').value };
  if (Object.values(w).some(v => !isFinite(v))) return alert('请填数字');
  chrome.storage.local.set({ weights: w }, load);
};
$('wreset').onclick = () => chrome.storage.local.remove('weights', load);
$('csv').onclick = () => {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([XW.toCsv(posts)], { type: 'text/csv' }));
  a.download = `my-x-posts-${new Date().toISOString().slice(0, 10)}.csv`; a.click();
};
$('clear').onclick = () => { if (confirm('清空所有记录的帖子数据?(权重设置保留)')) chrome.storage.local.remove('posts', load); };
chrome.storage.onChanged.addListener(load);
load();
