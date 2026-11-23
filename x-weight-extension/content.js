// 在 X 页面上给每条帖子加参考分;记录自己的帖子到本机存储。
(() => {
  const XW = self.XW;
  let weights = XW.DEFAULT_WEIGHTS;
  let myHandle = null;
  let pending = {};                    // 待写入的自己帖子
  let saveTimer = null;

  chrome.storage.local.get(['weights', 'myHandle'], r => {
    if (r.weights) weights = Object.assign({}, XW.DEFAULT_WEIGHTS, r.weights);
    if (r.myHandle) myHandle = r.myHandle;
    scan();
  });
  chrome.storage.onChanged.addListener(ch => {
    if (ch.weights) { weights = Object.assign({}, XW.DEFAULT_WEIGHTS, ch.weights.newValue || {}); scan(true); }
  });

  // 按钮里显示的数字;读不到时退而从 aria-label 里找第一个数字
  function countIn(el) {
    if (!el) return 0;
    const t = el.querySelector('[data-testid="app-text-transition-container"]');
    let n = XW.parseCount(t ? t.textContent : '');
    if (n == null || (n === 0 && !t)) {
      const m = (el.getAttribute('aria-label') || '').match(/[\d.,]+\s*[KkMm万萬亿億]?/);
      n = m ? XW.parseCount(m[0]) : 0;
    }
    return n || 0;
  }

  function detectMe() {
    const a = document.querySelector('a[data-testid="AppTabBar_Profile_Link"]');
    const h = a && (a.getAttribute('href') || '').replace(/^\//, '').toLowerCase();
    if (h && h !== myHandle) { myHandle = h; chrome.storage.local.set({ myHandle: h }); }
  }

  function readTweet(article) {
    const group = article.querySelector('div[role="group"]');
    if (!group) return null;
    const timeLink = [...article.querySelectorAll('a[href*="/status/"]')].find(a => a.querySelector('time'));
    const info = timeLink && XW.parseStatusHref(timeLink.getAttribute('href'));
    const q = id => group.querySelector(`[data-testid="${id}"]`);
    const viewsEl = group.querySelector('a[href$="/analytics"]');
    const textEl = article.querySelector('[data-testid="tweetText"]');
    return {
      group, info,
      created: timeLink ? timeLink.querySelector('time').getAttribute('datetime') : null,
      text: textEl ? textEl.innerText.slice(0, 280) : '',
      replies: countIn(q('reply')),
      reposts: countIn(q('retweet') || q('unretweet')),
      likes: countIn(q('like') || q('unlike')),
      bookmarks: countIn(q('bookmark') || q('removeBookmark')),
      views: viewsEl ? countIn(viewsEl) : 0,
    };
  }

  function render(t) {
    const s = XW.score(t, weights);
    const tier = XW.tier(s && s.total, t.views);
    let b = t.group.querySelector(':scope > .xw-badge');
    if (!b) { b = document.createElement('span'); t.group.appendChild(b); }
    const cls = `xw-badge xw-${tier.key}`, txt = `⚖ ${XW.fmt(s && s.total)}${tier.key === 'few' ? '?' : ''}`;
    // 只在有变化时改动,避免触发自己的 MutationObserver 形成循环
    if (b.className !== cls) b.className = cls;
    if (b.textContent !== txt) b.textContent = txt;
    const title = s
      ? `X 权重参考分 ${XW.fmt(s.total)}(${tier.label})\n每千次浏览的加权互动:\n` +
        `回复 ${t.replies} × ${weights.reply} → ${XW.fmt(s.parts.reply)}\n` +
        `转发 ${t.reposts} × ${weights.repost} → ${XW.fmt(s.parts.repost)}\n` +
        `点赞 ${t.likes} × ${weights.like} → ${XW.fmt(s.parts.like)}\n` +
        `浏览 ${t.views}\n\n只按页面可见数据估算,不是 X 内部真实分数。`
      : '没有浏览数,无法计算参考分';
    if (b.title !== title) b.title = title;
    return s;
  }

  function remember(t, s) {
    if (!myHandle || !t.info || t.info.handle !== myHandle) return;
    pending[t.info.id] = {
      id: t.info.id, handle: t.info.handle, created: t.created, text: t.text,
      replies: t.replies, reposts: t.reposts, likes: t.likes, bookmarks: t.bookmarks, views: t.views,
      score: s ? s.total : null, lastSeen: new Date().toISOString(),
    };
    clearTimeout(saveTimer);
    saveTimer = setTimeout(flush, 1500);
  }

  function flush() {
    const batch = pending; pending = {};
    if (!Object.keys(batch).length) return;
    chrome.storage.local.get(['posts'], r => {
      const posts = r.posts || {};
      for (const [id, p] of Object.entries(batch)) {
        const old = posts[id];
        const history = (old && old.history) || [];
        const last = history[history.length - 1];
        if (!last || last.views !== p.views || last.likes !== p.likes || last.replies !== p.replies)
          history.push({ t: p.lastSeen, views: p.views, replies: p.replies, reposts: p.reposts, likes: p.likes });
        posts[id] = Object.assign({}, old, p, { history: history.slice(-30) });
      }
      chrome.storage.local.set({ posts });
    });
  }

  let scanning = false;
  function scan() {
    if (scanning) return;
    scanning = true;
    try {
      detectMe();
      document.querySelectorAll('article[data-testid="tweet"]').forEach(a => {
        const t = readTweet(a);
        if (!t) return;
        remember(t, render(t));
      });
    } catch (e) { console.warn('[X 权重参考分]', e); }
    scanning = false;
  }

  let queued = false;
  new MutationObserver(() => {
    if (queued) return;
    queued = true;
    setTimeout(() => { queued = false; scan(); }, 400);   // 合并频繁的页面变化
  }).observe(document.body, { childList: true, subtree: true, characterData: true });
  scan();
})();
