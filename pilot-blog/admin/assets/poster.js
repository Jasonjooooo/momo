// 分享海报: draws a 1080x1920 poster in the browser from the page data on #poster (app/lib/poster.php):
// picture on top, kind / title / one line / price, then the blog name and a QR code of the page's address.
(() => {
  'use strict';
  const box = document.getElementById('poster');
  if (!box) return;
  let data;
  try { data = JSON.parse(box.dataset.poster || '{}'); } catch (e) { return; }
  const items = Array.isArray(data.items) ? data.items : [];
  const $ = id => document.getElementById(id);
  const sel = $('pst-target'), line = $('pst-line'), make = $('pst-make'), save = $('pst-save'), msg = $('pst-msg');
  const out = $('pst-out'), img = $('pst-img'), tip = $('pst-tip');
  const W = 1080, H = 1920, PAD = 72;
  const CJK = '"PingFang SC","Hiragino Sans GB","Noto Sans CJK SC","Microsoft YaHei",sans-serif';
  const MONO = 'Menlo,Consolas,"Noto Sans Mono",monospace';
  let lastUrl = null;

  const current = () => items.find(it => it.id === sel.value) || items[0];
  function fillLine() { const it = current(); line.value = it ? (it.text || '').slice(0, 80) : ''; }

  function loadImg(src) {
    return new Promise(ok => {
      if (!src) { ok(null); return; }
      const im = new Image();
      im.onload = () => ok(im); im.onerror = () => ok(null);
      im.src = src;
    });
  }
  // CJK-friendly wrap: break anywhere, never more than `max` lines; the last line ends with … when cut
  function wrap(g, text, width, max) {
    const lines = []; let cur = '';
    for (const ch of String(text).replace(/\s+/g, ' ').trim()) {
      if (g.measureText(cur + ch).width > width && cur) {
        lines.push(cur); cur = ch.trim();
        if (lines.length === max) break;
      } else cur += ch;
    }
    if (lines.length < max && cur) lines.push(cur);
    else if (lines.length === max && cur) {
      let l = lines[max - 1];
      while (l && g.measureText(l + '…').width > width) l = l.slice(0, -1);
      lines[max - 1] = l + '…';
    }
    return lines;
  }
  // A wide picture (a plane side-on) would lose its nose and tail to a cover crop: show it whole across the width,
  // with soft top and bottom edges over a blurred, darker copy of itself that fills the rest of the picture area.
  function widePicture(g, im, w, h) {
    g.save();
    if ('filter' in g) g.filter = 'blur(28px) brightness(0.55)';
    cover(g, im, -40, -40, w + 80, h + 80);
    g.restore();
    if (!('filter' in g)) { g.fillStyle = 'rgba(10,23,51,.55)'; g.fillRect(0, 0, w, h); }
    const dh = Math.round(w * im.naturalHeight / im.naturalWidth), y = Math.round((h - dh) * 0.42);
    const off = document.createElement('canvas'); off.width = w; off.height = dh;
    const o = off.getContext('2d');
    o.drawImage(im, 0, 0, w, dh);
    const m = o.createLinearGradient(0, 0, 0, dh), e = Math.min(0.14, 90 / dh);
    m.addColorStop(0, 'rgba(0,0,0,0)'); m.addColorStop(e, 'rgba(0,0,0,1)'); m.addColorStop(1 - e, 'rgba(0,0,0,1)'); m.addColorStop(1, 'rgba(0,0,0,0)');
    o.globalCompositeOperation = 'destination-in'; o.fillStyle = m; o.fillRect(0, 0, w, dh);
    g.drawImage(off, 0, y);
  }
  function cover(g, im, x, y, w, h) {
    const s = Math.max(w / im.naturalWidth, h / im.naturalHeight), sw = w / s, sh = h / s;
    g.drawImage(im, (im.naturalWidth - sw) / 2, (im.naturalHeight - sh) / 2, sw, sh, x, y, w, h);
  }

  async function draw() {
    const it = current();
    if (!it) return;
    make.disabled = true; msg.textContent = '正在生成…';
    const c = document.createElement('canvas'); c.width = W; c.height = H;
    const g = c.getContext('2d');
    g.fillStyle = '#0A1733'; g.fillRect(0, 0, W, H);
    const IMG_H = 1120;
    const im = await loadImg(it.img);
    if (im && im.naturalWidth / im.naturalHeight > 1.2) {
      widePicture(g, im, W, IMG_H);
    } else if (im) {
      cover(g, im, 0, 0, W, IMG_H);
    } else {
      const bg = g.createLinearGradient(0, 0, 0, IMG_H); bg.addColorStop(0, '#132D61'); bg.addColorStop(1, '#0A1733');
      g.fillStyle = bg; g.fillRect(0, 0, W, IMG_H);
    }
    const fade = g.createLinearGradient(0, IMG_H - 420, 0, IMG_H); fade.addColorStop(0, 'rgba(10,23,51,0)'); fade.addColorStop(1, 'rgba(10,23,51,1)');
    g.fillStyle = fade; g.fillRect(0, IMG_H - 420, W, 420);

    // kind · date
    let y = IMG_H + 40;
    g.textBaseline = 'alphabetic';
    g.fillStyle = '#E6A948'; g.font = `600 30px ${CJK}`;
    g.fillText([it.kind, it.date].filter(Boolean).join(' · '), PAD, y);
    // title: up to 2 lines
    y += 86;
    g.fillStyle = '#ECE9F1'; g.font = `600 64px ${CJK}`;
    for (const l of wrap(g, it.title, W - PAD * 2, 2)) { g.fillText(l, PAD, y); y += 84; }
    // the line: up to 3 lines, stopping above the footer
    const text = line.value.trim();
    if (text) {
      y += 10; g.fillStyle = '#B9B8CC'; g.font = `400 36px ${CJK}`;
      for (const l of wrap(g, text, W - PAD * 2, it.price ? 2 : 3)) { g.fillText(l, PAD, y); y += 56; }
    }
    if (it.price) { y += 30; g.fillStyle = '#EA4C22'; g.font = `700 72px ${MONO}`; g.fillText(it.price, PAD, y); }

    // footer: blog name on the left, QR on the right
    const FY = 1630;
    g.strokeStyle = '#2A3A66'; g.lineWidth = 2; g.beginPath(); g.moveTo(PAD, FY); g.lineTo(W - PAD, FY); g.stroke();
    g.fillStyle = '#ECE9F1'; g.font = `600 52px ${CJK}`; g.fillText(data.brand || '', PAD, FY + 110);
    if (data.tail) { g.fillStyle = '#A9A8C0'; g.font = `400 30px ${CJK}`; g.fillText(data.tail.replace(/^[\s|·—-]+/, ''), PAD, FY + 162); }
    g.fillStyle = '#A9A8C0'; g.font = `400 26px ${MONO}`; g.fillText(it.url.replace(/^https?:\/\//, '').replace(/\/$/, ''), PAD, FY + 214);
    if (window.qrcode) {
      const qr = window.qrcode(0, 'M'); qr.addData(it.url); qr.make();
      const n = qr.getModuleCount(), cell = Math.floor(216 / (n + 4)), sz = cell * (n + 4), x0 = W - PAD - sz, y0 = FY + 30;
      g.fillStyle = '#fff'; g.fillRect(x0, y0, sz, sz); g.fillStyle = '#0A1733';
      for (let r = 0; r < n; r++) for (let k = 0; k < n; k++) if (qr.isDark(r, k)) g.fillRect(x0 + (k + 2) * cell, y0 + (r + 2) * cell, cell, cell);
      g.fillStyle = '#A9A8C0'; g.font = `400 24px ${CJK}`; g.textAlign = 'center'; g.fillText('长按识别二维码', x0 + sz / 2, y0 + sz + 36); g.textAlign = 'left';
    }

    c.toBlob(b => {
      make.disabled = false;
      if (!b) { msg.textContent = '生成失败了，换个浏览器再试试。'; return; }
      if (lastUrl) URL.revokeObjectURL(lastUrl);
      lastUrl = URL.createObjectURL(b);
      img.src = lastUrl; out.hidden = false; tip.hidden = false;
      save.href = lastUrl; save.download = 'poster-' + it.id + '.jpg'; save.hidden = false;
      msg.textContent = im || !it.img ? '' : '这一项的图片没读到，先用了纯色背景。';
    }, 'image/jpeg', 0.92);
  }

  sel.addEventListener('change', () => { fillLine(); out.hidden = true; save.hidden = true; tip.hidden = true; });
  make.addEventListener('click', () => { draw().catch(() => { make.disabled = false; msg.textContent = '生成失败了，刷新页面再试试。'; }); });
  fillLine();
})();
