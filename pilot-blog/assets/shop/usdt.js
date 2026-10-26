// USDT pay page (api/shop.php?a=pay): QR of the receiving address, copy buttons, countdown, and a poll of
// api/shop.php?a=usdt that asks the server to look for the transfer on chain. The server decides everything;
// this script only shows it. Without script the page still shows the address, amount and deadline.
(function () {
  'use strict';
  var box = document.querySelector('[data-usdt]');
  if (!box) return;
  var exp = Number(box.getAttribute('data-exp')) || 0;
  var grace = Number(box.getAttribute('data-grace')) || 600;
  var skew = Date.now() / 1000 - (Number(box.getAttribute('data-now')) || Date.now() / 1000);
  var expiredPage = box.classList.contains('usdt-exp');
  var status = box.querySelector('[data-status]');
  var leftEl = box.querySelector('[data-left]');
  var leftWrap = box.querySelector('[data-left-wrap]');
  var done = false;

  function now() { return Date.now() / 1000 - skew; }
  function say(t) { if (status && status.textContent !== t) status.textContent = t; }

  // copy buttons
  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (ok, no) {
      var ta = document.createElement('textarea');
      ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy') ? ok() : no(); } catch (e) { no(e); }
      document.body.removeChild(ta);
    });
  }
  Array.prototype.forEach.call(box.querySelectorAll('[data-copy]'), function (b) {
    var label = b.textContent;
    b.hidden = false;
    b.addEventListener('click', function () {
      copyText(b.getAttribute('data-copy')).then(function () {
        b.textContent = '已复制'; b.classList.add('copied');
      }, function () {
        b.textContent = '请长按上面的文字复制';
      });
      setTimeout(function () { b.textContent = label; b.classList.remove('copied'); }, 2200);
    });
  });

  // QR code of the address (qrcode-generator, drawn on a canvas)
  var cv = box.querySelector('.usdt-qr');
  var addr = box.getAttribute('data-addr');
  if (cv && addr && window.qrcode) {
    try {
      var qr = window.qrcode(0, 'M');
      qr.addData(addr);
      qr.make();
      var n = qr.getModuleCount();
      var scale = Math.max(2, Math.floor(232 / (n + 8)));
      var size = (n + 8) * scale;
      cv.width = size; cv.height = size;
      var g = cv.getContext('2d');
      g.fillStyle = '#fff'; g.fillRect(0, 0, size, size);
      g.fillStyle = '#000';
      for (var r = 0; r < n; r++) {
        for (var c = 0; c < n; c++) {
          if (qr.isDark(r, c)) g.fillRect((c + 4) * scale, (r + 4) * scale, scale, scale);
        }
      }
      cv.hidden = false;
    } catch (e) { /* the address text and copy button are enough */ }
  }

  // countdown
  function pad(x) { return (x < 10 ? '0' : '') + x; }
  function tick() {
    if (!leftEl) return;
    var l = Math.max(0, Math.round(exp - now()));
    if (leftWrap) leftWrap.hidden = false;
    leftEl.textContent = Math.floor(l / 60) + ':' + pad(l % 60);
    if (l === 0 && !expiredPage && !done) {
      say('报价时间到了。已经转账的话请稍等，还会再核对 10 分钟；还没转的话不要再转，页面马上刷新，请重新报价。');
      done = true;
      setTimeout(function () { location.reload(); }, 4000);
    }
  }
  tick();
  setInterval(tick, 1000);

  // poll the server
  var delay = 8000;
  function next() { setTimeout(poll, document.hidden ? Math.max(delay, 20000) : delay); }
  function poll() {
    if (now() > exp + grace + 60) {
      if (!expiredPage) say('报价已经过期。刷新这一页可以重新报价。');
      return;
    }
    fetch(box.getAttribute('data-poll'), { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
      .then(function (res) {
        return res.json().then(function (j) { return [res.status, j]; });
      })
      .then(function (a) {
        var st = a[0], j = a[1] || {};
        if (j.state === 'paid') {
          say('已经到账，正在确认……');
          location.reload();
          return;
        }
        if (j.state === 'qr' || j.state === 'cancelled' || j.state === 'claimed') { location.reload(); return; }
        if (j.state === 'expired' && !expiredPage && !done) { done = true; location.reload(); return; }
        if (!expiredPage) say('等待到账。到账后这一页会自动变成「已确认收款」，不用点任何按钮。（上次查看 ' + new Date().toTimeString().slice(0, 5) + '）');
        delay = st === 429 ? 30000 : 8000;
        next();
      })
      .catch(function () {
        delay = Math.min(delay * 2, 30000);
        next();
      });
  }
  setTimeout(poll, 2500);
})();
