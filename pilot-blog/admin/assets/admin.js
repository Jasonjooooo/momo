/* 云底之下 后台脚本。No libraries and no inline scripts (CSP script-src 'self'); styles only through classes or el.style. */
(function () {
  'use strict';
  var d = document;
  function $(s, r) { return (r || d).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || d).querySelectorAll(s)); }
  function meta(n) { var m = $('meta[name="' + n + '"]'); return m ? m.getAttribute('content') : ''; }
  var BASE = meta('admin-base') || './';
  function route(r) { return BASE + '?r=' + encodeURIComponent(r); }
  function csrf() { return meta('csrf'); }
  function setCsrf(t) {
    var m = $('meta[name="csrf"]');
    if (m) m.setAttribute('content', t);
    $$('input[name="_csrf"]').forEach(function (i) { i.value = t; });
  }
  function len(s) { return Array.from(s || '').length; }
  function copy(o) { return JSON.parse(JSON.stringify(o)); }

  /** Small DOM builder: text always goes through textContent, never innerHTML. */
  function el(tag, props, kids) {
    var e = d.createElement(tag);
    Object.keys(props || {}).forEach(function (k) {
      var v = props[k];
      if (v === null || v === undefined || v === false) return;
      if (k === 'style') throw new Error('no inline styles');
      if (k === 'text') e.textContent = v;
      else if (k === 'class') e.className = v;
      else if (k.slice(0, 2) === 'on') e.addEventListener(k.slice(2), v);
      else if (k === 'value') e.value = v;
      else if (k === 'checked') e.checked = !!v;
      else e.setAttribute(k, v === true ? '' : String(v));
    });
    (kids || []).forEach(function (c) {
      if (c === null || c === undefined || c === false) return;
      e.appendChild(typeof c === 'string' ? d.createTextNode(c) : c);
    });
    return e;
  }

  // ---------- sheets: confirm, re-login, more actions (never the browser's confirm(), WeChat blocks it) ----------
  var openSheet = null;
  function sheet(o) {
    return new Promise(function (resolve) {
      if (openSheet) openSheet.close(false);
      var prev = d.activeElement;
      var wrap = el('div', { class: 'sheet-wrap' });
      var box = el('div', { class: 'sheet', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'sheet-h' });
      box.appendChild(el('h2', { id: 'sheet-h', text: o.title || '请确认' }));
      if (o.text) box.appendChild(el('p', { class: 'sheet-text', text: o.text }));
      if (o.node) box.appendChild(o.node);
      var inputs = (o.inputs || []).map(function (f, i) {
        var id = 'sheet-in-' + i;
        var inp = f.type === 'textarea'
          ? el('textarea', { id: id, rows: 6, readonly: f.readonly })
          : el('input', { id: id, type: f.type || 'text', autocomplete: f.autocomplete || 'off', autocapitalize: 'off', spellcheck: 'false', inputmode: f.inputmode, placeholder: f.placeholder });
        inp.value = f.value || '';
        box.appendChild(el('div', { class: 'field' }, [el('label', { for: id, text: f.label }), f.help ? el('p', { class: 'where', text: f.help }) : null, inp]));
        return inp;
      });
      var err = el('p', { class: 'ferr', role: 'alert', hidden: true });
      box.appendChild(err);
      if (o.actions) {
        box.appendChild(el('div', { class: 'sheet-acts' }, o.actions.map(function (a) {
          return el('button', { type: 'button', class: 'btn ' + (a.cls || ''), text: a.label, onclick: function () { close(a.value); } });
        })));
      }
      var btns = el('div', { class: 'sheet-btns' });
      var cancel = el('button', { type: 'button', class: 'btn btn-ghost', text: o.cancel || '取消', onclick: function () { close(false); } });
      btns.appendChild(cancel);
      var okBtn = null;
      if (o.ok) {
        okBtn = el('button', { type: 'button', class: 'btn ' + (o.okClass || 'btn-primary'), text: o.ok, onclick: doOk });
        btns.appendChild(okBtn);
      }
      box.appendChild(btns);
      wrap.appendChild(box);
      wrap.addEventListener('click', function (e) { if (e.target === wrap) close(false); });
      box.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { e.preventDefault(); close(false); return; }
        if (e.key === 'Enter' && e.target.tagName === 'INPUT' && okBtn && !e.isComposing) { e.preventDefault(); doOk(); return; }
        if (e.key === 'Tab') {
          var f = $$('button, input, textarea, select, a[href]', box).filter(function (x) { return !x.disabled && x.offsetParent !== null; });
          if (!f.length) return;
          if (e.shiftKey && d.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
          else if (!e.shiftKey && d.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
        }
      });
      d.body.appendChild(wrap);
      d.body.classList.add('has-sheet');
      var busyNow = false;
      async function doOk() {
        if (busyNow) return;
        var vals = inputs.map(function (i) { return i.value; });
        if (!o.onOk) { close(o.inputs ? vals : true); return; }
        busyNow = true;
        var label = okBtn.textContent;
        okBtn.setAttribute('aria-disabled', 'true');
        okBtn.textContent = o.busyText || '请稍等…';
        var setErr = function (m) { err.textContent = m || ''; err.hidden = !m; };
        var r = false;
        try { r = await o.onOk(vals, setErr); } catch (e) { setErr('网络断了，再试一次。'); }
        busyNow = false;
        okBtn.removeAttribute('aria-disabled');
        okBtn.textContent = label;
        if (r) close(r);
      }
      function close(v) {
        if (!wrap.parentNode) return;
        wrap.parentNode.removeChild(wrap);
        d.body.classList.remove('has-sheet');
        openSheet = null;
        if (prev && prev.focus) { try { prev.focus({ preventScroll: true }); } catch (e) { /* gone */ } }
        resolve(v);
      }
      openSheet = { close: close, inputs: inputs };
      setTimeout(function () {
        var first = inputs[0] || okBtn || cancel;
        first.focus();
        if (o.selectAll && inputs[0]) { inputs[0].select(); try { inputs[0].setSelectionRange(0, inputs[0].value.length); } catch (e) { /* ignore */ } }
      }, 30);
    });
  }
  function confirmSheet(text, ok, danger, title) {
    return sheet({ title: title || '请确认', text: text, ok: ok || '确定', okClass: danger ? 'btn-danger' : 'btn-primary' });
  }

  // ---------- status line ----------
  function barStatus(text, kind) {
    var s = $('#bar-status');
    if (!s) return;
    s.textContent = text || '';
    s.className = 'bar-status' + (kind ? ' is-' + kind : '');
  }

  // ---------- fetch + JSON, with the re-login sheet on an expired session ----------
  async function freshLoginToken() {
    var r = await fetch(route('login'), { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
    return r.json();
  }
  async function relogin() {
    try {
      var j = await freshLoginToken();
      if (j.logged_in && j.csrf) { setCsrf(j.csrf); return true; }
    } catch (e) { return false; }
    var ok = await sheet({
      title: '登录已经过期',
      text: '登录已经过期。输入密码继续，你写的内容还在。',
      inputs: [{ label: '密码', type: 'password', autocomplete: 'current-password' }],
      ok: '继续',
      busyText: '登录中…',
      onOk: async function (vals, setErr) {
        if (!vals[0]) { setErr('请输入密码。'); return false; }
        var t = (await freshLoginToken()).csrf;
        var fd = new FormData();
        fd.append('password', vals[0]);
        fd.append('_csrf', t);
        var r = await fetch(route('login'), { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-Token': t }, credentials: 'same-origin' });
        var j = await r.json();
        if (j.ok) { setCsrf(j.csrf); return true; }
        setErr(j.message || '密码不对。');
        return false;
      }
    });
    return !!ok;
  }
  async function api(r, body, netMsg) {
    for (var attempt = 0; attempt < 2; attempt++) {
      var res, data;
      var init = { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-Token': csrf() } };
      if (body instanceof FormData) init.body = body;
      else { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(body); }
      try { res = await fetch(route(r), init); } catch (e) {
        return { ok: false, error: 'network', message: netMsg || '网络断了，没有保存。内容还在这页上，联网后再点保存。' };
      }
      try { data = await res.json(); } catch (e) {
        data = { ok: false, error: 'server', message: res.status === 413 ? '内容太大了，服务器没有收下。' : '服务器出错了（' + res.status + '），这次没有完成。内容还在这页上，稍后再试。' };
      }
      if (attempt === 0 && (res.status === 401 || (res.status === 403 && data.error === 'csrf'))) {
        if (await relogin()) continue;
        return { ok: false, error: 'auth', message: '没有重新登录，这次没有保存。内容还在这页上。' };
      }
      data.http = res.status;
      return data;
    }
    return { ok: false, error: 'auth', message: '登录没有成功，这次没有保存。内容还在这页上。' };
  }

  // ---------- unsaved changes ----------
  var guard = { dirty: false, onchange: null };
  function setDirty(v) {
    if (guard.dirty === v) return;
    guard.dirty = v;
    if (guard.onchange) guard.onchange(v);
  }
  window.addEventListener('beforeunload', function (e) {
    if (!guard.dirty) return undefined;
    e.preventDefault();
    e.returnValue = '';
    return '';
  });
  var LEAVE = '有改动还没保存，确定离开吗？';
  d.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('a[href]') : null;
    if (!a || !guard.dirty || e.defaultPrevented) return;
    var href = a.getAttribute('href') || '';
    if (a.target === '_blank' || a.hasAttribute('download') || href.charAt(0) === '#') return;
    e.preventDefault();
    confirmSheet(LEAVE, '离开', true, '还没保存').then(function (ok) {
      if (ok) { setDirty(false); location.href = a.href; }
    });
  });

  // ---------- forms: confirm sheets, guards, double submit ----------
  d.addEventListener('submit', function (e) {
    var f = e.target;
    if (f.dataset.busy === '1') { e.preventDefault(); return; }
    if (f.dataset.needCheck && !f.querySelector('input[name="' + f.dataset.needCheck + '"]:checked')) {
      e.preventDefault();
      confirmSheet('还没有选要删除的邮箱。先勾选左边的方框。', '知道了', false, '没有选中');
      return;
    }
    var own = f.hasAttribute('data-guard');
    var leaving = guard.dirty && !own && f.target !== '_blank';
    if (f.dataset.confirm || leaving) {
      e.preventDefault();
      (async function () {
        if (leaving && !(await confirmSheet(LEAVE, '离开', true, '还没保存'))) return;
        if (f.dataset.confirm && !(await confirmSheet(f.dataset.confirm, f.dataset.confirmOk || '确定', f.dataset.confirmDanger === '1'))) return;
        setDirty(false);
        markBusy(f);
        f.submit();
      })();
      return;
    }
    if (own) setDirty(false);
    markBusy(f);
  });
  function markBusy(f) {
    if (f.target === '_blank') return;
    f.dataset.busy = '1';
    var b = f.querySelector('button[type="submit"], button:not([type])');
    if (b) { b.setAttribute('aria-disabled', 'true'); b.dataset.label = b.textContent; b.textContent = '请稍等…'; }
  }
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) return;
    $$('form[data-busy="1"]').forEach(function (f) {
      f.dataset.busy = '';
      var b = f.querySelector('[aria-disabled="true"]');
      if (b) { b.removeAttribute('aria-disabled'); if (b.dataset.label) b.textContent = b.dataset.label; }
    });
  });
  $$('form[data-guard]').forEach(function (f) {
    f.addEventListener('input', function () { setDirty(true); });
    f.addEventListener('change', function () { setDirty(true); });
  });

  // ---------- menu (phone) ----------
  var menuBtn = $('.menu-btn');
  var menu = $('#menu');
  if (menuBtn && menu) {
    var menuClose = $('.menu-close', menu);
    var setMenu = function (open) {
      menu.classList.toggle('open', open);
      menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      d.body.classList.toggle('menu-open', open);
      if (open) (menuClose || menu).focus(); else menuBtn.focus();
    };
    menuBtn.addEventListener('click', function () { setMenu(true); });
    if (menuClose) menuClose.addEventListener('click', function () { setMenu(false); });
    menu.addEventListener('keydown', function (e) { if (e.key === 'Escape' && menu.classList.contains('open')) setMenu(false); });
  }

  // ---------- fields: counters, auto-grow, one-line textareas ----------
  var SOFT_WARN = '比原来长不少，手机上可能放不下，发布前请预览';
  function grow(t) {
    if (t.tagName !== 'TEXTAREA' || t.offsetParent === null) return;
    t.style.height = 'auto';
    t.style.height = (t.scrollHeight + 2) + 'px';
  }
  function count(inp) {
    var f = inp.closest('.field');
    var out = f ? $('.count', f) : null;
    if (!out) return;
    var n = len(inp.value);
    var max = +inp.dataset.max || 0;
    var soft = +inp.dataset.soft || 0;
    out.textContent = soft ? '已写 ' + n + ' / 建议 ' + soft + ' 以内' : (max ? '已写 ' + n + ' / 最多 ' + max : '');
    out.classList.toggle('warn', !!soft && n > soft && n <= max);
    out.classList.toggle('over', !!max && n > max);
    var w = $('.fwarn', f);
    if (w && soft) { w.hidden = n <= soft; w.textContent = n > soft ? SOFT_WARN : ''; }
  }
  function wireField(inp) {
    if (inp.dataset.wired) return;
    inp.dataset.wired = '1';
    count(inp);
    grow(inp);
    inp.addEventListener('input', function () { count(inp); grow(inp); });
    if (inp.dataset.oneline) {
      inp.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.isComposing && e.keyCode !== 229) e.preventDefault(); });
    }
  }
  d.addEventListener('toggle', function (e) {
    if (e.target.tagName === 'DETAILS' && e.target.open) $$('textarea', e.target).forEach(grow);
  }, true);

  function clearErrors(root) {
    $$('.field.has-err', root).forEach(function (f) { f.classList.remove('has-err'); });
    $$('.ferr', root).forEach(function (p) { p.hidden = true; p.textContent = ''; });
    var se = $('#shelves-err');
    if (se) se.hidden = true;
  }
  function findPath(root, path) {
    var all = $$('[data-path]', root);
    for (var p = path; p; p = p.indexOf('.') > 0 ? p.slice(0, p.lastIndexOf('.')) : '') {
      for (var i = 0; i < all.length; i++) if (all[i].dataset.path === p) return all[i];
    }
    return null;
  }
  function showErrors(fields, root) {
    var first = null;
    Object.keys(fields || {}).forEach(function (path) {
      var f = findPath(root, path);
      if (!f) return;
      f.classList.add('has-err');
      var p = null;
      for (var i = 0; i < f.children.length; i++) if (f.children[i].classList.contains('ferr')) p = f.children[i];
      if (!p && f.id === 'shelves') p = $('#shelves-err');
      if (p) { p.textContent = fields[path]; p.hidden = false; }
      if (!first) first = f;
    });
    if (!first) return;
    for (var det = first.closest('details'); det; det = det.parentElement ? det.parentElement.closest('details') : null) det.open = true;
    var inp = $('input:not([type=hidden]), textarea, select', first);
    first.scrollIntoView({ block: 'center' });
    if (inp) { try { inp.focus({ preventScroll: true }); } catch (e) { /* ignore */ } }
  }

  // ---------- the action bar ----------
  function barButtons() { return $$('#bar [data-act]'); }
  async function busy(btn, text, fn) {
    if (!btn || btn.getAttribute('aria-disabled') === 'true') return undefined;
    var label = btn.textContent;
    barButtons().forEach(function (b) { b.setAttribute('aria-disabled', 'true'); });
    btn.textContent = text;
    barStatus(text, '');
    try { return await fn(); } finally {
      if (btn.textContent === text) btn.textContent = label;
      barButtons().forEach(function (b) { b.removeAttribute('aria-disabled'); });
    }
  }
  function onBar(handlers) {
    barButtons().forEach(function (b) {
      b.addEventListener('click', function () { var h = handlers[b.dataset.act]; if (h) h(b); });
    });
  }

  // ---------- local backup every 5 s while there are unsaved changes ----------
  function backup(key, getState, serverTime) {
    var k = 'pb-admin-' + key;
    function read() { try { var raw = localStorage.getItem(k); return raw ? JSON.parse(raw) : null; } catch (e) { return null; } }
    function write(v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* private mode */ } }
    function clear() { try { localStorage.removeItem(k); } catch (e) { /* ignore */ } }
    function now() { if (guard.dirty) write({ t: Date.now(), s: getState() }); }
    setInterval(now, 5000);
    // also when the page goes away (reload, back, iOS app switch): text typed in the last seconds survives
    window.addEventListener('pagehide', now);
    d.addEventListener('visibilitychange', function () { if (d.visibilityState === 'hidden') now(); });
    return {
      clear: clear,
      now: now,
      offer: function (apply) {
        var b = read();
        if (!b || !b.s) return;
        var serverMs = serverTime ? Date.parse(serverTime) : 0;
        if (!(b.t > serverMs) || JSON.stringify(b.s) === JSON.stringify(getState())) { clear(); return; }
        var box = $('#restore');
        if (!box) return;
        var t = new Date(b.t);
        var pad = function (n) { return (n < 10 ? '0' : '') + n; };
        var same = t.toDateString() === new Date().toDateString();
        var label = (same ? '' : (t.getMonth() + 1) + '月' + t.getDate() + '日 ') + pad(t.getHours()) + ':' + pad(t.getMinutes());
        box.textContent = '';
        box.appendChild(el('p', { text: '发现一份没保存的内容（' + label + '），要恢复吗？' }));
        box.appendChild(el('div', { class: 'row-btns' }, [
          el('button', { type: 'button', class: 'btn btn-primary', text: '恢复', onclick: function () {
            apply(b.s);
            box.hidden = true;
            setDirty(true);
            barStatus('已恢复没保存的内容。检查一下，再点保存。', 'warn');
          } }),
          el('button', { type: 'button', class: 'btn', text: '丢掉', onclick: function () { clear(); box.hidden = true; } })
        ]));
        box.hidden = false;
      }
    };
  }

  // ---------- list editor (FAQ rows, 数据格子) ----------
  function listEditor(box, rows, spec, onChange) {
    var seq = 0;
    rows.forEach(function (r) { r._k = ++seq; });
    function field(row, i, fd) {
      var id = spec.prefix + '-' + row._k + '-' + fd.key;
      var path = spec.path + '.' + i + '.' + fd.key;
      if (fd.type === 'image') {
        return el('div', { class: 'field alb-photo', 'data-path': path }, [
          el('img', { class: 'alb-thumb', src: fd.url(row[fd.key]), alt: '第 ' + (i + 1) + ' 张照片' }),
          el('p', { class: 'ferr', hidden: true })
        ]);
      }
      if (fd.type === 'check') {
        var cb = el('input', { type: 'checkbox', id: id, checked: !!row[fd.key] });
        cb.addEventListener('change', function () { row[fd.key] = cb.checked; onChange(); });
        return el('div', { class: 'field', 'data-path': path }, [el('label', { class: 'check', for: id }, [cb, el('span', { text: fd.label })]), el('p', { class: 'ferr', hidden: true })]);
      }
      var inp = fd.type === 'textarea'
        ? el('textarea', { id: id, rows: 3, 'data-max': fd.max, 'data-oneline': '1' })
        : el('input', { id: id, type: 'text', 'data-max': fd.max, autocapitalize: fd.upper ? 'characters' : null, spellcheck: fd.upper ? 'false' : null, class: fd.mono ? 'mono' : null });
      inp.value = row[fd.key] === null || row[fd.key] === undefined ? '' : row[fd.key];
      inp.addEventListener('input', function () { row[fd.key] = inp.value; onChange(); });
      return el('div', { class: 'field', 'data-path': path }, [
        el('label', { for: id, text: fd.label }),
        fd.help ? el('p', { class: 'where', text: fd.help }) : null,
        inp,
        el('p', { class: 'count', 'aria-hidden': 'true' }),
        el('p', { class: 'ferr', hidden: true })
      ]);
    }
    function btn(label, disabled, fn, act, cls) {
      return el('button', { type: 'button', class: 'btn ' + (cls || ''), text: label, disabled: disabled, 'data-act': act, onclick: fn });
    }
    function render(focusKey, act) {
      box.textContent = '';
      rows.forEach(function (row, i) {
        var item = el('div', { class: 'item', 'data-item': row._k }, [
          el('div', { class: 'item-head' }, [el('p', { class: 'item-t', text: spec.label + ' ' + (i + 1) })])
        ].concat(spec.fields.map(function (fd) { return field(row, i, fd); })).concat([
          el('div', { class: 'item-btns' }, [
            btn('上移', i === 0, function () { move(i, -1); }, 'up'),
            btn('下移', i === rows.length - 1, function () { move(i, 1); }, 'down'),
            btn('删除', rows.length <= spec.min, function () { del(i); }, 'del', 'btn-danger')
          ])
        ]));
        box.appendChild(item);
        $$('[data-max]', item).forEach(wireField);
      });
      box.appendChild(btn(spec.add, rows.length >= spec.max, add, 'add'));
      if (focusKey) {
        var it = $('[data-item="' + focusKey + '"]', box);
        if (!it) return;
        var target = act ? $('[data-act="' + act + '"]', it) : $('input, textarea', it);
        if (target && target.disabled) target = $('.item-btns button:not([disabled])', it);
        if (target) { target.focus(); if (!act) it.scrollIntoView({ block: 'center' }); }
      }
    }
    function move(i, dir) {
      var j = i + dir;
      if (j < 0 || j >= rows.length) return;
      var t = rows[i]; rows[i] = rows[j]; rows[j] = t;
      render(t._k, dir < 0 ? 'up' : 'down');
      onChange();
    }
    function del(i) {
      confirmSheet('删除' + spec.label + ' ' + (i + 1) + '？保存以后才会真的删掉。', '删除', true).then(function (ok) {
        if (!ok) return;
        rows.splice(i, 1);
        render(rows[Math.min(i, rows.length - 1)] ? rows[Math.min(i, rows.length - 1)]._k : null, 'del');
        onChange();
      });
    }
    function add() {
      if (spec.onAdd) {
        spec.onAdd(function (nr) { nr._k = ++seq; rows.push(nr); render(nr._k); onChange(); });
        return;
      }
      var r = spec.blank();
      r._k = ++seq;
      rows.push(r);
      render(r._k);
      onChange();
    }
    render();
    return { render: render, setRows: function (nr) { rows.length = 0; nr.forEach(function (r) { r._k = ++seq; rows.push(r); }); render(); } };
  }
  function cleanRows(rows, keys) {
    return rows.map(function (r) { var o = {}; keys.forEach(function (k) { o[k] = r[k]; }); return o; });
  }

  function moreSheet(actions) {
    return sheet({ title: '更多', actions: actions, cancel: '关闭' });
  }

  // ---------- 网站文字 ----------
  function siteEditor(data) {
    var form = $('#site-form');
    var rev = data.rev;
    var live = data.live;
    var serverDirty = data.dirty;
    var faq = copy(data.faq);
    var facts = copy(data.type_facts);
    var album = copy(data.album || []);
    var FAQ_KEYS = ['nid', 'q', 'r', 'a', 'subscribe_note'];
    var FACT_KEYS = ['k', 'val', 'line'];
    var ALBUM_KEYS = ['src', 'caption', 'alt'];
    var inputs = $$('[data-k]', form);
    function collect() {
      var f = {};
      inputs.forEach(function (i) { f[i.dataset.k] = i.value; });
      return { fields: f, faq: cleanRows(faq, FAQ_KEYS), type_facts: cleanRows(facts, FACT_KEYS), album: cleanRows(album, ALBUM_KEYS) };
    }
    function changed() { setDirty(true); badges(); }
    var faqEd = listEditor($('#faq-list'), faq, {
      prefix: 'faq', path: 'faq', label: '问答', add: '加一条问答', min: 1, max: 12,
      blank: function () { return { nid: '', q: '', r: '', a: '', subscribe_note: false }; },
      fields: [
        { key: 'q', label: '问题', max: 40 },
        { key: 'r', label: '简答', max: 12, help: '问题右边那几个字，比如「在飞」。' },
        { key: 'a', label: '回答', max: 300, type: 'textarea' },
        { key: 'nid', label: '编号（可留空）', max: 12, mono: true, upper: true, help: '像 A0101/26 这样。留空会自动编号。' },
        { key: 'subscribe_note', label: '订阅关闭时，在回答后面加上「订阅关闭时的说明」', type: 'check' }
      ]
    }, changed);
    var factEd = listEditor($('#facts-list'), facts, {
      prefix: 'fact', path: 'type_facts', label: '格子', add: '加一个格子', min: 1, max: 9,
      blank: function () { return { k: '', val: '', line: '' }; },
      fields: [
        { key: 'k', label: '代码（1 到 6 个大写字母或数字）', max: 6, mono: true, upper: true },
        { key: 'val', label: '数值', max: 14 },
        { key: 'line', label: '说明', max: 120, type: 'textarea' }
      ]
    }, changed);
    // 关于 · INSET A photos: upload adds a photo at the end; order, captions and deletions are saved with the rest
    var albumFile = $('#album-file');
    var albumWait = null;
    var albumEd = listEditor($('#album-list'), album, {
      prefix: 'alb', path: 'album', label: '照片', add: '加一张照片', min: 1, max: data.album_max || 8,
      onAdd: function (done) { albumWait = done; albumFile.click(); },
      fields: [
        { key: 'src', type: 'image', url: function (src) { return /^(assets|uploads)\//.test(src || '') ? data.site_base + src : ''; } },
        { key: 'caption', label: '照片下方小字', max: 60, help: '换照片时，这句跟着照片一起换。' },
        { key: 'alt', label: '照片说明（读屏软件读这句，可以空着）', max: 150, type: 'textarea' }
      ]
    }, changed);
    albumFile.addEventListener('change', async function (e) {
      var file = e.target.files && e.target.files[0];
      e.target.value = '';
      if (!file || !albumWait) return;
      var done = albumWait;
      albumWait = null;
      barStatus('正在上传照片…', '');
      var r = await upload(file, 'album');
      if (!r.ok) { barStatus(r.message || '照片没有传上去。', 'err'); return; }
      done({ src: r.src, caption: '', alt: '' });
      barStatus('照片已上传。写上照片下方小字，再点「保存草稿」或「保存并发布」。', 'ok');
    });
    $$('[data-max], textarea', form).forEach(wireField);
    form.addEventListener('input', changed);
    form.addEventListener('change', changed);

    function badges() {
      $$('.grp', form).forEach(function (g) {
        var n = 0;
        $$('[data-k]', g).forEach(function (i) { if (i.value !== live.fields[i.dataset.k]) n++; });
        if (g.dataset.group === 'faq' && JSON.stringify(cleanRows(faq, FAQ_KEYS)) !== JSON.stringify(live.faq)) n++;
        if (g.dataset.group === 'type' && JSON.stringify(cleanRows(facts, FACT_KEYS)) !== JSON.stringify(live.type_facts)) n++;
        if (g.dataset.group === 'about' && JSON.stringify(cleanRows(album, ALBUM_KEYS)) !== JSON.stringify(cleanRows(live.album || [], ALBUM_KEYS))) n++;
        var b = $('.badge', g);
        b.hidden = n === 0;
        b.textContent = '改过 ' + n;
      });
    }
    function status() {
      if (guard.dirty) barStatus('有改动没保存。', 'warn');
      else if (serverDirty) barStatus('草稿已保存，还没发布。网站上还是旧的。', '');
      else barStatus('和网站上的一样。', '');
    }
    guard.onchange = status;
    badges();
    status();

    // search
    var find = $('#find');
    var openBefore = null;   // groups the owner had open before searching; restored when the box is cleared
    find.addEventListener('input', function () {
      var q = find.value.trim().toLowerCase();
      var any = false;
      if (q && !openBefore) openBefore = $$('.grp', form).filter(function (g) { return g.open; });
      if (!q && openBefore) {
        $$('.grp', form).forEach(function (g) { g.open = openBefore.indexOf(g) >= 0; });
        openBefore = null;
      }
      $$('.grp', form).forEach(function (g) {
        var hits = 0;
        $$('.field[data-path^="fields."]', g).forEach(function (f) {
          var inp = $('[data-k]', f);
          var txt = ($('label', f).textContent + ' ' + ($('.where', f) || { textContent: '' }).textContent + ' ' + inp.value + ' ' + inp.dataset.k).toLowerCase();
          var show = !q || txt.indexOf(q) >= 0;
          f.hidden = !show;
          if (show) hits++;
        });
        var lists = $$('.list', g);
        var listHit = lists.length > 0 && !!q && (g.textContent + JSON.stringify(g.dataset.group === 'faq' ? faq : facts)).toLowerCase().indexOf(q) >= 0;
        g.hidden = !!q && hits === 0 && !listHit;
        if (q && (hits > 0 || listHit)) { g.open = true; any = true; }
      });
      $('#find-none').hidden = !q || any;
    });

    async function save(publish, btn) {
      clearErrors(form);
      var doc = collect();
      var r = await busy(btn, publish ? '发布中…' : '保存中…', function () {
        return api(publish ? 'site.publish' : 'site.save', { rev: rev, doc: doc }, publish ? '网络断了，没有发布。内容还在这页上，联网后再点发布。' : null);
      });
      if (!r) return;
      if (r.rev) rev = r.rev;
      if (typeof r.dirty === 'boolean') serverDirty = r.dirty;
      if (r.ok || r.saved) { setDirty(false); bk.clear(); data.updated_at = r.updated_at; }
      if (r.ok && publish) live = copy(doc);
      if (r.warnings) {
        $$('.field[data-path^="fields."]', form).forEach(function (f) {
          var w = $('.fwarn', f);
          var msg = r.warnings[f.dataset.path];
          if (w) { w.hidden = !msg; w.textContent = msg || ''; }
        });
      }
      badges();
      if (r.ok) barStatus(r.message, 'ok');
      else { if (!r.saved) bk.now(); barStatus(r.message || '没有保存。', 'err'); if (r.fields) showErrors(r.fields, form); }
    }
    function preview() {
      var f = $('#pv-form');
      f.querySelector('[name="_csrf"]').value = csrf();
      f.querySelector('[name="site"]').value = JSON.stringify(collect());
      f.submit();
    }
    onBar({
      save: function (b) { save(false, b); },
      publish: function (b) { save(true, b); },
      preview: preview,
      more: function () {
        moreSheet([{ label: '放弃草稿，恢复成网站上的版本', value: 'discard', cls: 'btn-danger' }]).then(async function (v) {
          if (v !== 'discard') return;
          if (!(await confirmSheet('草稿里所有没发布的修改都会丢掉，恢复成网站上现在的文字。', '放弃草稿', true))) return;
          var r = await api('site.discard', { rev: rev });
          if (r.ok) { setDirty(false); bk.clear(); location.reload(); } else barStatus(r.message, 'err');
        });
      }
    });
    var bk = backup('site', collect, data.updated_at);
    bk.offer(function (s) {
      inputs.forEach(function (i) { if (typeof s.fields[i.dataset.k] === 'string') { i.value = s.fields[i.dataset.k]; count(i); grow(i); } });
      faqEd.setRows(s.faq || []);
      factEd.setRows(s.type_facts || []);
      if (s.album && s.album.length) albumEd.setRows(s.album);
      badges();
    });
    if (location.hash && $(location.hash)) { var g = $(location.hash); if (g.tagName === 'DETAILS') g.open = true; }
  }

  // ---------- AI 超市 ----------
  var CN = [['', '还没确认'], ['能直接访问', '能直接访问'], ['需要国际网络', '需要国际网络']];
  function hostOf(url) { var m = /^https?:\/\/([^/?#:]+)/i.exec(url || ''); return m ? m[1].replace(/^www\./, '') : '还没填网址'; }
  function shelfEditor(data) {
    var form = $('#shelf-form');
    var box = $('#shelves');
    var rev = data.rev;
    var serverDirty = data.dirty;
    var seq = 0;
    var shelves = copy(data.shelves);
    var opened = new Set();
    shelves.forEach(function (s) { s._k = ++seq; s.tools.forEach(function (t) { t._k = ++seq; }); });
    function collect() {
      return shelves.map(function (s) {
        return { id: s.id, title: s.title, blurb: s.blurb, tools: s.tools.map(function (t) { return { name: t.name, desc: t.desc, url: t.url, cn_access: t.cn_access || null, note: t.note || '' }; }) };
      });
    }
    function changed() { setDirty(true); }
    function input(obj, key, opt) {
      var id = 'sh-' + obj._k + '-' + key;
      var inp = opt.type === 'textarea'
        ? el('textarea', { id: id, rows: 2, 'data-max': opt.max, 'data-oneline': '1' })
        : el('input', { id: id, type: opt.type || 'text', 'data-max': opt.max, inputmode: opt.inputmode, autocapitalize: opt.inputmode === 'url' ? 'off' : null, spellcheck: opt.inputmode === 'url' ? 'false' : null });
      inp.value = obj[key] || '';
      inp.addEventListener('input', function () { obj[key] = inp.value; changed(); if (opt.onInput) opt.onInput(inp.value); });
      return el('div', { class: 'field', 'data-path': opt.path }, [
        el('label', { for: id, text: opt.label }),
        opt.help ? el('p', { class: 'where', text: opt.help }) : null,
        inp,
        el('p', { class: 'count', 'aria-hidden': 'true' }),
        el('p', { class: 'ferr', hidden: true })
      ]);
    }
    function b(label, fn, opt) {
      opt = opt || {};
      return el('button', { type: 'button', class: 'btn ' + (opt.cls || ''), text: label, disabled: opt.disabled, 'data-act': opt.act, 'aria-label': opt.aria, onclick: fn });
    }
    function render(focusKey, act) {
      box.textContent = '';
      shelves.forEach(function (s, i) {
        var title = el('span', { class: 'shelf-t', text: s.title || '（还没起名的货架）' });
        var n = el('span', { class: 'shelf-n', text: s.tools.length + ' 个工具' });
        var det = el('details', { class: 'shelf', 'data-item': s._k, 'data-path': 'shelves.' + i, open: opened.has(s._k) });
        det.addEventListener('toggle', function () { if (det.open) opened.add(s._k); else opened.delete(s._k); });
        var tools = el('ul', { class: 'tools' });
        s.tools.forEach(function (t, j) { tools.appendChild(toolEl(s, i, t, j)); });
        var body = el('div', { class: 'shelf-body' }, [
          input(s, 'title', { label: '货架名', max: 12, path: 'shelves.' + i + '.title', onInput: function (v) { title.textContent = v || '（还没起名的货架）'; } }),
          input(s, 'blurb', { label: '一句话介绍', max: 60, type: 'textarea', path: 'shelves.' + i + '.blurb' }),
          el('p', { class: 'label', text: '工具' }),
          el('div', { 'data-path': 'shelves.' + i + '.tools' }, [tools, el('p', { class: 'ferr', hidden: true })]),
          el('div', { class: 'row-btns' }, [b('加一个工具', function () { addTool(i); }, { disabled: s.tools.length >= 16, act: 'add-tool' })]),
          el('p', { class: 'label', text: '这个货架' }),
          el('div', { class: 'item-btns' }, [
            b('上移货架', function () { moveShelf(i, -1); }, { disabled: i === 0, act: 'up' }),
            b('下移货架', function () { moveShelf(i, 1); }, { disabled: i === shelves.length - 1, act: 'down' }),
            b('删除货架', function () { delShelf(i); }, { cls: 'btn-danger', act: 'del' })
          ])
        ]);
        det.appendChild(el('summary', {}, [title, n]));
        det.appendChild(body);
        box.appendChild(det);
      });
      $$('[data-max]', box).forEach(wireField);
      $('#shelf-add').disabled = shelves.length >= 8;
      if (focusKey) {
        var it = $('[data-item="' + focusKey + '"]', box);
        if (!it) return;
        for (var det2 = it; det2; det2 = det2.parentElement ? det2.parentElement.closest('details') : null) det2.open = true;
        var target = act ? $('[data-act="' + act + '"]', it) : $('input, textarea', it);
        if (target && target.disabled) target = $('.item-btns button:not([disabled])', it);
        if (target) { target.focus(); if (!act) it.scrollIntoView({ block: 'center' }); }
      }
    }
    function toolEl(s, i, t, j) {
      var base = 'shelves.' + i + '.tools.' + j;
      var name = el('span', { class: 'tool-n', text: t.name || '（还没起名的工具）' });
      var badge = el('span', { class: 'chip' + (t.cn_access === '能直接访问' ? ' chip-ok' : (t.cn_access ? ' chip-warn' : '')), text: t.cn_access || '还没确认' });
      var host = el('span', { class: 'tool-h', text: hostOf(t.url) });
      var det = el('details', { class: 'tool', 'data-item': t._k, 'data-path': base, open: opened.has(t._k) });
      det.addEventListener('toggle', function () { if (det.open) opened.add(t._k); else opened.delete(t._k); });
      var selId = 'sh-' + t._k + '-cn';
      var sel = el('select', { id: selId }, CN.map(function (c) { return el('option', { value: c[0], text: c[1] }); }));
      sel.value = t.cn_access || '';
      sel.addEventListener('change', function () {
        t.cn_access = sel.value || null;
        badge.textContent = t.cn_access || '还没确认';
        badge.className = 'chip' + (t.cn_access === '能直接访问' ? ' chip-ok' : (t.cn_access ? ' chip-warn' : ''));
        changed();
      });
      var others = shelves.map(function (o, k) { return [k, o]; }).filter(function (p) { return p[0] !== i; });
      var moveSel = el('select', { id: 'sh-' + t._k + '-mv', 'aria-label': '移到哪个货架' }, others.map(function (p) { return el('option', { value: String(p[0]), text: p[1].title || ('货架 ' + (p[0] + 1)) }); }));
      var body = el('div', { class: 'tool-body' }, [
        input(t, 'name', { label: '名称', max: 30, path: base + '.name', onInput: function (v) { name.textContent = v || '（还没起名的工具）'; } }),
        input(t, 'desc', { label: '一句话介绍', max: 40, path: base + '.desc' }),
        input(t, 'url', { label: '网址', max: 300, type: 'url', inputmode: 'url', path: base + '.url', help: '以 https:// 开头的完整网址。', onInput: function (v) { host.textContent = hostOf(v); } }),
        el('div', { class: 'field', 'data-path': base + '.cn_access' }, [el('label', { for: selId, text: '国内能不能直接访问' }), sel, el('p', { class: 'ferr', hidden: true })]),
        input(t, 'note', { label: '我的使用心得（可留空）', max: 120, type: 'textarea', path: base + '.note' }),
        el('div', { class: 'item-btns' }, [
          b('上移', function () { moveTool(i, j, -1); }, { disabled: j === 0, act: 'up' }),
          b('下移', function () { moveTool(i, j, 1); }, { disabled: j === s.tools.length - 1, act: 'down' }),
          b('删除', function () { delTool(i, j); }, { cls: 'btn-danger', act: 'del' })
        ]),
        others.length ? el('div', { class: 'move-row' }, [moveSel, b('移过去', function () { moveToShelf(i, j, +moveSel.value); }, { act: 'move', aria: '移到选中的货架' })]) : null
      ]);
      det.appendChild(el('summary', {}, [name, badge, host]));
      det.appendChild(body);
      return el('li', {}, [det]);
    }
    function moveShelf(i, dir) {
      var j = i + dir;
      if (j < 0 || j >= shelves.length) return;
      var t = shelves[i]; shelves[i] = shelves[j]; shelves[j] = t;
      render(t._k, dir < 0 ? 'up' : 'down');
      changed();
    }
    function delShelf(i) {
      var s = shelves[i];
      var go = function () { shelves.splice(i, 1); render(); changed(); barStatus('已删除货架。保存以后才会真的删掉。', 'warn'); };
      if (s.tools.length === 0) { go(); return; }
      confirmSheet('这个货架里还有 ' + s.tools.length + ' 个工具，一起删除吗？', '一起删除', true).then(function (ok) { if (ok) go(); });
    }
    function addShelf() {
      var s = { id: '', title: '', blurb: '', tools: [], _k: ++seq };
      shelves.push(s);
      opened.add(s._k);
      render(s._k);
      changed();
    }
    function addTool(i) {
      var t = { name: '', desc: '', url: 'https://', cn_access: null, note: '', _k: ++seq };
      shelves[i].tools.push(t);
      opened.add(shelves[i]._k);
      opened.add(t._k);
      render(t._k);
      changed();
    }
    function moveTool(i, j, dir) {
      var tools = shelves[i].tools;
      var k = j + dir;
      if (k < 0 || k >= tools.length) return;
      var t = tools[j]; tools[j] = tools[k]; tools[k] = t;
      render(t._k, dir < 0 ? 'up' : 'down');
      changed();
    }
    function delTool(i, j) {
      var t = shelves[i].tools[j];
      confirmSheet('删除「' + (t.name || '这个工具') + '」？保存以后才会真的删掉。', '删除', true).then(function (ok) {
        if (!ok) return;
        shelves[i].tools.splice(j, 1);
        render(shelves[i]._k, 'add-tool');
        changed();
      });
    }
    function moveToShelf(i, j, k) {
      if (!shelves[k] || k === i) return;
      if (shelves[k].tools.length >= 16) { barStatus('那个货架已经有 16 个工具，放不下了。', 'err'); return; }
      var t = shelves[i].tools.splice(j, 1)[0];
      shelves[k].tools.push(t);
      opened.add(shelves[k]._k);
      render(t._k, 'move');
      changed();
      barStatus('已移到「' + (shelves[k].title || '货架 ' + (k + 1)) + '」，放在最后。', 'ok');
    }
    $('#shelf-add').addEventListener('click', addShelf);
    function status() {
      if (guard.dirty) barStatus('有改动没保存。', 'warn');
      else if (serverDirty) barStatus('草稿已保存，还没发布。网站上还是旧的。', '');
      else barStatus('和网站上的一样。', '');
    }
    guard.onchange = status;
    render();
    status();

    async function save(publish, btn) {
      clearErrors(form);
      var doc = { shelves: collect() };
      var r = await busy(btn, publish ? '发布中…' : '保存中…', function () {
        return api(publish ? 'shelf.publish' : 'shelf.save', { rev: rev, doc: doc }, publish ? '网络断了，没有发布。内容还在这页上，联网后再点发布。' : null);
      });
      if (!r) return;
      if (r.rev) rev = r.rev;
      if (typeof r.dirty === 'boolean') serverDirty = r.dirty;
      if (r.ids) r.ids.forEach(function (id, k) { if (shelves[k] && !shelves[k].id) shelves[k].id = id; });
      if (r.ok || r.saved) { setDirty(false); bk.clear(); }
      if (r.ok) barStatus(r.message, 'ok');
      else { if (!r.saved) bk.now(); barStatus(r.message || '没有保存。', 'err'); if (r.fields) showErrors(r.fields, form); }
    }
    onBar({
      save: function (btn) { save(false, btn); },
      publish: function (btn) { save(true, btn); },
      preview: function () {
        var f = $('#pv-form');
        f.querySelector('[name="_csrf"]').value = csrf();
        f.querySelector('[name="shelf"]').value = JSON.stringify({ shelves: collect() });
        f.submit();
      },
      more: function () {
        moreSheet([{ label: '放弃草稿，恢复成网站上的版本', value: 'discard', cls: 'btn-danger' }]).then(async function (v) {
          if (v !== 'discard') return;
          if (!(await confirmSheet('草稿里所有没发布的修改都会丢掉，恢复成网站上现在的货架。', '放弃草稿', true))) return;
          var r = await api('shelf.discard', { rev: rev });
          if (r.ok) { setDirty(false); bk.clear(); location.reload(); } else barStatus(r.message, 'err');
        });
      }
    });
    var bk = backup('shelf', function () { return { shelves: collect() }; }, data.updated_at);
    bk.offer(function (s) {
      shelves = (s.shelves || []).map(function (sh) { sh._k = ++seq; (sh.tools || []).forEach(function (t) { t._k = ++seq; }); return sh; });
      render();
    });
  }

  // ---------- image upload (shrunk on the phone first; the server re-encodes anyway) ----------
  async function shrink(file) {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type || '')) return file;
    try {
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.src = url;
      await img.decode();
      var w = img.naturalWidth, h = img.naturalHeight;
      var s = Math.min(1, 2400 / Math.max(w, h));
      if (s === 1 && file.size <= 3 * 1024 * 1024) { URL.revokeObjectURL(url); return file; }
      var c = d.createElement('canvas');
      c.width = Math.max(1, Math.round(w * s));
      c.height = Math.max(1, Math.round(h * s));
      var ctx = c.getContext('2d');
      ctx.fillStyle = '#0A1733';
      ctx.fillRect(0, 0, c.width, c.height);
      ctx.drawImage(img, 0, 0, c.width, c.height);
      URL.revokeObjectURL(url);
      var blob = await new Promise(function (res) { c.toBlob(res, 'image/jpeg', 0.9); });
      return blob ? new File([blob], 'photo.jpg', { type: 'image/jpeg' }) : file;
    } catch (e) { return file; }
  }
  async function upload(file, purpose) {
    var f = await shrink(file);
    var fd = new FormData();
    fd.append('image', f, f.name || 'image.jpg');
    fd.append('purpose', purpose);
    fd.append('_csrf', csrf());
    return api('upload', fd, '网络断了，图片没有传上去。联网后再试一次。');
  }

  // ---------- 日志编辑器 ----------
  function postEditor(data) {
    var P = data.post;
    var rev = P.rev;
    var form = $('#pane-edit');
    var I = { title: $('#p-title'), body: $('#p-body'), excerpt: $('#p-excerpt'), tags: $('#p-tags'), date: $('#p-date'), minutes: $('#p-minutes'), fix: $('#p-fix'), slug: $('#p-slug'), alt: $('#p-cover-alt') };
    var cover = P.draft.cover ? { src: P.draft.cover.src } : null;
    var slugFollows = !P.slug_locked && (I.slug.value === I.fix.value.toLowerCase());
    var pubBtn = $('#bar [data-act="publish"]');
    function collect() {
      return {
        title: I.title.value, excerpt: I.excerpt.value, tags: I.tags.value, body: I.body.value,
        date: I.date.value, minutes: I.minutes.value.trim(), fix: I.fix.value, slug: I.slug.value,
        cover: cover ? { src: cover.src, alt: I.alt.value } : null
      };
    }
    $$('[data-max], textarea', form).forEach(wireField);
    I.fix.addEventListener('input', function () {
      var v = I.fix.value.toUpperCase();
      if (v !== I.fix.value) I.fix.value = v;
      if (slugFollows) I.slug.value = v.toLowerCase();
    });
    I.slug.addEventListener('input', function () {
      slugFollows = false;
      var v = I.slug.value.toLowerCase();
      if (v !== I.slug.value) I.slug.value = v;
    });
    I.title.addEventListener('input', function () {
      var t = I.title.value.trim() || '新日志';
      $('#post-h').textContent = t;
      var tt = $('.top-title');
      if (tt) tt.textContent = t;
    });
    form.addEventListener('input', function () { setDirty(true); schedule(); });
    form.addEventListener('change', function () { setDirty(true); schedule(); });

    var LABEL = { draft: '草稿', teaser: '预告', published: '已发布' };
    function status() {
      var base = { draft: '草稿：只有你看得到。', teaser: '预告：首页显示标题和摘要。', published: '已发布。' }[P.status];
      if (guard.dirty) barStatus('有改动没保存。', 'warn');
      else if (P.status !== 'draft' && P.dirty) barStatus(base + '有未发布的修改。', 'warn');
      else barStatus(base, '');
    }
    function refreshUi() {
      if (pubBtn && pubBtn.getAttribute('aria-disabled') !== 'true') pubBtn.textContent = P.status === 'published' ? '发布修改' : '发布';
      var crumb = $('.crumb span');
      if (crumb) crumb.textContent = LABEL[P.status];
      if (P.slug_locked && !I.slug.readOnly) {
        I.slug.readOnly = true;
        var help = $('[data-path="slug"] .where');
        if (help) help.textContent = '已经发布过，网址不能再改，否则旧链接会失效。';
      }
    }
    guard.onchange = status;
    status();
    refreshUi();

    async function act(kind, btn, text) {
      clearErrors(form);
      var body = { id: P.id, rev: rev };
      if (guard.dirty || kind === 'save') body.post = collect();
      var net = kind === 'save' ? null : '网络断了，这次没有完成。内容还在这页上，联网后再试。';
      var r = await busy(btn, text, function () { return api('post.' + kind, body, net); });
      if (!r) return;
      if (r.rev) rev = r.rev;
      if (r.post) P = r.post;
      if (r.ok || r.saved) { setDirty(false); bk.clear(); }
      refreshUi();
      if (r.ok) barStatus(r.message, 'ok');
      else { if (!r.saved) bk.now(); barStatus(r.message || '没有保存。', 'err'); if (r.fields) showErrors(r.fields, form); }
    }
    onBar({
      save: function (b) { act('save', b, '保存中…'); },
      publish: function (b) { act('publish', b, '发布中…'); },
      more: function () {
        var acts = [];
        if (P.status === 'published') acts.push({ label: '改回预告', value: 'teaser' });
        else acts.push({ label: P.status === 'teaser' ? '更新预告' : '设为预告', value: 'teaser' });
        if (P.status !== 'draft') acts.push({ label: '撤下', value: 'unpublish' });
        if (P.page_url) acts.push({ label: '打开这篇的页面', value: 'open' });
        acts.push({ label: '删除这篇', value: 'delete', cls: 'btn-danger' });
        moreSheet(acts).then(async function (v) {
          var more = $('#bar [data-act="more"]');
          if (v === 'teaser') {
            if (P.status === 'published' && !(await confirmSheet('这篇的页面会撤下，首页只留标题和摘要。', '改回预告', true))) return;
            act('teaser', more, '请稍等…');
          } else if (v === 'unpublish') {
            var txt = P.status === 'published' ? '撤下以后，首页和日志页面上都不再显示这篇，内容留在草稿里。' : '撤下以后，首页不再显示这条预告，内容留在草稿里。';
            if (await confirmSheet(txt, '撤下', true)) act('unpublish', more, '请稍等…');
          } else if (v === 'open') {
            window.open(P.page_url, '_blank', 'noopener');
          } else if (v === 'delete') {
            if (!(await confirmSheet('删除后这篇草稿也没有了，只能从版本记录里找回已发布过的内容。', '删除', true))) return;
            setDirty(false);
            bk.clear();
            var f = $('#del-form');
            f.querySelector('[name="_csrf"]').value = csrf();
            f.submit();
          }
        });
      }
    });

    // preview: tabs on the phone, side by side on wide screens
    var wide = window.matchMedia('(min-width:1100px)');
    var layout = $('.post-layout');
    var tabs = $$('.tabs [role="tab"]');
    var pvTimer = null;
    function previewShown() { return wide.matches || layout.classList.contains('show-preview'); }
    function refreshPreview() {
      var f = $('#pv-form');
      f.querySelector('[name="_csrf"]').value = csrf();
      f.querySelector('[name="post"]').value = JSON.stringify(collect());
      f.submit();
    }
    function schedule() {
      clearTimeout(pvTimer);
      if (previewShown()) pvTimer = setTimeout(refreshPreview, 800);
    }
    function showTab(which) {
      var pv = which === 'preview';
      layout.classList.toggle('show-preview', pv);
      tabs.forEach(function (t) {
        var on = (t.id === 'tab-preview') === pv;
        t.setAttribute('aria-selected', on ? 'true' : 'false');
        t.tabIndex = on ? 0 : -1;
      });
      if (pv) refreshPreview();
    }
    tabs.forEach(function (t) {
      t.addEventListener('click', function () { showTab(t.id === 'tab-preview' ? 'preview' : 'edit'); });
      t.addEventListener('keydown', function (e) {
        if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
        var other = tabs[tabs[0] === t ? 1 : 0];
        other.focus();
        showTab(other.id === 'tab-preview' ? 'preview' : 'edit');
      });
    });
    if (wide.matches) refreshPreview();

    // toolbar
    var ta = I.body;
    var sel = [ta.value.length, ta.value.length];
    ['select', 'keyup', 'click', 'input', 'blur'].forEach(function (ev) { ta.addEventListener(ev, function () { sel = [ta.selectionStart, ta.selectionEnd]; }); });
    function replace(a, b, text, s1, s2) {
      ta.focus();
      if (ta.setRangeText) ta.setRangeText(text, a, b, 'end');
      else ta.value = ta.value.slice(0, a) + text + ta.value.slice(b);
      ta.setSelectionRange(s1, s2);
      sel = [s1, s2];
      ta.dispatchEvent(new Event('input', { bubbles: true }));
    }
    function ownLine(text) {
      var v = ta.value, a = sel[0], b = sel[1];
      var pre = a > 0 && v.charAt(a - 1) !== '\n' ? '\n' : '';
      var post = b < v.length && v.charAt(b) !== '\n' ? '\n' : '';
      replace(a, b, pre + text + post, a + pre.length + text.length, a + pre.length + text.length);
    }
    var PREFIX = { h2: '## ', ul: '- ', quote: '> ' };
    var SAMPLE = { h2: '小标题', ul: '一条', quote: '一句提醒' };
    function tool(kind) {
      var v = ta.value, a = sel[0], b = sel[1];
      if (kind === 'bold') {
        var mid = v.slice(a, b) || '加粗的字';
        replace(a, b, '**' + mid + '**', a + 2, a + 2 + mid.length);
      } else if (PREFIX[kind]) {
        var pre = PREFIX[kind];
        var ls = v.lastIndexOf('\n', a - 1) + 1;
        var le = v.indexOf('\n', b);
        if (le < 0) le = v.length;
        var block = v.slice(ls, le);
        if (block.trim() === '') {
          replace(ls, le, pre + SAMPLE[kind], ls + pre.length, ls + pre.length + SAMPLE[kind].length);
        } else {
          var nb = block.split('\n').map(function (l) {
            if (l.trim() === '' || l.indexOf(pre) === 0) return l;
            return pre + l.replace(/^\s*(#{1,6}\s+|[-*]\s+|>\s?|\d{1,4}\.\s+)/, '');
          }).join('\n');
          replace(ls, le, nb, ls, ls + nb.length);
        }
      } else if (kind === 'link') {
        sheet({
          title: '插入链接',
          inputs: [{ label: '链接文字', value: v.slice(a, b) }, { label: '网址', type: 'url', inputmode: 'url', value: 'https://', help: '别的网站写完整网址；本站的日志写 posts/网址.html。' }],
          ok: '插入'
        }).then(function (vals) {
          if (!vals) return;
          var text = (vals[0] || '').trim() || '链接';
          var url = (vals[1] || '').trim();
          if (!url || url === 'https://') return;
          var s = '[' + text.replace(/[\[\]]/g, '') + '](' + url.replace(/[\s()]/g, '') + ')';
          replace(a, b, s, a + s.length, a + s.length);
        });
      } else if (kind === 'img') {
        $('#body-file').click();
      } else if (kind === 'help') {
        var node = $('#md-help').cloneNode(true);
        node.removeAttribute('id');
        node.hidden = false;
        sheet({ title: '正文怎么写', node: node, cancel: '知道了' });
      }
    }
    $$('.mdbar [data-md]').forEach(function (bt) {
      bt.addEventListener('mousedown', function (e) { e.preventDefault(); });
      bt.addEventListener('click', function () { tool(bt.dataset.md); });
    });
    $('#body-file').addEventListener('change', async function (e) {
      var file = e.target.files && e.target.files[0];
      e.target.value = '';
      if (!file) return;
      barStatus('正在上传图片…', '');
      var r = await upload(file, 'body');
      if (!r.ok) { barStatus(r.message || '图片没有传上去。', 'err'); return; }
      var vals = await sheet({
        title: '图片传好了',
        inputs: [{ label: '图片说明（读屏软件会读这句，可留空）' }, { label: '图片下方的小字（可留空）' }],
        ok: '插入正文',
        cancel: '不插入'
      });
      if (!vals) { barStatus('图片已上传，但没有插入正文。', 'warn'); return; }
      var alt = (vals[0] || '').replace(/[\[\]\n]/g, '').trim() || '图片';
      var cap = (vals[1] || '').replace(/["\n]/g, '').trim();
      ownLine('![' + alt + '](' + r.src + (cap ? ' "' + cap + '"' : '') + ')');
      barStatus('图片已插入正文。', 'ok');
    });

    // cover
    var img = $('#cover-img'), empty = $('#cover-empty'), pick = $('#cover-pick'), remove = $('#cover-remove');
    function coverUi() {
      img.hidden = !cover;
      empty.hidden = !!cover;
      remove.hidden = !cover;
      pick.textContent = cover ? '换一张' : '上传封面图';
    }
    $('#cover-file').addEventListener('change', async function (e) {
      var file = e.target.files && e.target.files[0];
      e.target.value = '';
      if (!file) return;
      var f = $('[data-path="cover"]');
      f.classList.remove('has-err');
      var errP = $('.ferr', f);
      errP.hidden = true;
      barStatus('正在上传封面图…', '');
      pick.setAttribute('aria-disabled', 'true');
      var r = await upload(file, 'cover');
      pick.removeAttribute('aria-disabled');
      if (!r.ok) {
        barStatus(r.message || '图片没有传上去。', 'err');
        f.classList.add('has-err');
        errP.textContent = r.message || '图片没有传上去。';
        errP.hidden = false;
        return;
      }
      cover = { src: r.src };
      img.src = r.url;
      coverUi();
      setDirty(true);
      schedule();
      barStatus('封面图已上传。点「保存草稿」或「发布」才会用上。', 'ok');
    });
    remove.addEventListener('click', function () {
      cover = null;
      img.removeAttribute('src');
      coverUi();
      setDirty(true);
      schedule();
      barStatus('封面图已去掉。点「保存草稿」或「发布」才会生效。', 'warn');
    });

    var bk = backup('post-' + P.id, collect, P.updated_at);
    bk.offer(function (s) {
      ['title', 'excerpt', 'tags', 'body', 'date', 'minutes', 'fix'].forEach(function (k) { if (typeof s[k] === 'string') { I[k].value = s[k]; count(I[k]); grow(I[k]); } });
      if (!P.slug_locked && typeof s.slug === 'string') I.slug.value = s.slug;
      cover = s.cover && s.cover.src ? { src: s.cover.src } : null;
      if (cover) img.src = (meta('site-base') || '/') + cover.src;
      if (s.cover) I.alt.value = s.cover.alt || '';
      coverUi();
    });
  }

  // ---------- 订阅者: copy all addresses inside the tap (iOS drops clipboard access after an await) ----------
  var copyBtn = $('#copy-all');
  if (copyBtn) {
    copyBtn.addEventListener('click', function () {
      var text = $('#all-emails').value;
      var msg = $('#copy-msg');
      var n = text ? text.split(',').length : 0;
      var done = function () { msg.textContent = '已复制 ' + n + ' 个邮箱。到邮箱里新建邮件，粘贴到「密送」一栏。'; };
      var ok = false;
      try {
        var tmp = el('textarea', { class: 'vh', readonly: true, 'aria-hidden': 'true' });
        tmp.value = text;
        d.body.appendChild(tmp);
        tmp.focus();
        tmp.select();
        tmp.setSelectionRange(0, text.length);
        ok = d.execCommand('copy');
        d.body.removeChild(tmp);
        copyBtn.focus();
      } catch (e) { ok = false; }
      if (ok) { done(); return; }
      var manual = function () {
        sheet({ title: '手动复制', text: '这台设备不让网页直接复制。长按下面的文字，选「全选」，再选「拷贝」。', inputs: [{ label: '全部邮箱（' + n + ' 个）', type: 'textarea', value: text, readonly: true }], cancel: '关闭', selectAll: true });
      };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(done, manual);
      else manual();
    });
  }

  // ---------- AI 助手: test the key ----------
  var testBtn = $('#ai-test');
  if (testBtn) {
    testBtn.addEventListener('click', async function () {
      if (testBtn.getAttribute('aria-disabled') === 'true') return;
      var msg = $('#ai-test-msg');
      var label = testBtn.textContent;
      testBtn.setAttribute('aria-disabled', 'true');
      testBtn.textContent = '测试中…';
      msg.className = 'where';
      msg.textContent = '';
      var r = await api('ai.test', {}, '网络断了，没有测试。');
      testBtn.removeAttribute('aria-disabled');
      testBtn.textContent = label;
      msg.className = r.ok ? 'msg-ok' : 'msg-err';
      msg.textContent = r.message || (r.ok ? '连接正常。' : '测试没有完成。');
    });
  }

  // ---------- file pickers (photos, 收款码, files) upload as soon as a file is chosen ----------
  $$('form[data-autosubmit] input[type="file"]').forEach(function (inp) {
    inp.addEventListener('change', function () {
      if (!inp.files || !inp.files.length) return;
      var f = inp.form;
      if (f.requestSubmit) f.requestSubmit();
      else { markBusy(f); f.submit(); }
    });
  });

  // ---------- category picker: the plain select (大类 and 「大类 / 小类」 options) becomes a 大类 select and a
  // dependent 小类 select; both write into the original, which is what the form submits ----------
  $$('[data-catpick]').forEach(function (box) {
    var orig = $('select[name="cat"]', box);
    if (!orig) return;
    var tops = [], subsOf = {};
    Array.prototype.forEach.call(orig.options, function (o) {
      var top = o.getAttribute('data-top');
      if (!top) return;
      if (o.getAttribute('data-sub')) {
        var label = o.textContent, cut = label.indexOf(' / ');
        (subsOf[top] = subsOf[top] || []).push({ id: o.value, name: cut >= 0 ? label.slice(cut + 3) : label });
      } else tops.push({ id: o.value, name: o.textContent });
    });
    if (!tops.length) return;   // nothing to choose but 不分类: keep the plain select
    var base = orig.id || 'cat';
    var topSel = el('select', { id: base + '-top' }, [el('option', { value: '', text: '不分类' })].concat(tops.map(function (t) { return el('option', { value: t.id, text: t.name }); })));
    var subSel = el('select', { id: base + '-sub' });
    var subWrap = el('div', {}, [el('label', { 'for': base + '-sub', text: '小类' }), subSel]);
    var two = el('div', { class: 'catpick-two' }, [el('div', {}, [el('label', { 'for': base + '-top', text: '大类' }), topSel]), subWrap]);
    function fillSubs(top, chosen) {
      while (subSel.firstChild) subSel.removeChild(subSel.firstChild);
      var list = subsOf[top] || [];
      subWrap.hidden = !list.length;
      subSel.appendChild(el('option', { value: top, text: '不选小类（直接放在大类里）' }));
      list.forEach(function (s) { subSel.appendChild(el('option', { value: s.id, text: s.name })); });
      subSel.value = chosen || top;
    }
    function sync() { orig.value = topSel.value === '' ? '' : (subWrap.hidden ? topSel.value : subSel.value); orig.dispatchEvent(new Event('input', { bubbles: true })); }
    var cur = orig.options[orig.selectedIndex];
    var curTop = cur ? (cur.getAttribute('data-top') || '') : '';
    topSel.value = curTop;
    fillSubs(curTop, cur && cur.getAttribute('data-sub') ? cur.value : curTop);
    topSel.addEventListener('change', function () { fillSubs(topSel.value, topSel.value); sync(); });
    subSel.addEventListener('change', sync);
    var lab = $('label[for="' + orig.id + '"]', box);
    if (lab) lab.hidden = true;
    orig.hidden = true;
    orig.after(two);
  });

  // ---------- page start ----------
  var page = d.body.getAttribute('data-page');
  var dataEl = $('#editor-data');
  var editorData = null;
  if (dataEl) { try { editorData = JSON.parse(dataEl.textContent); } catch (e) { editorData = null; } }
  if (editorData) {
    if (page === 'site') siteEditor(editorData);
    else if (page === 'shelf') shelfEditor(editorData);
    else if (page === 'post_edit') postEditor(editorData);
  }
  $$('[data-max], textarea').forEach(function (t) { if (!t.closest('.sheet') && !t.classList.contains('vh')) wireField(t); });
  var errField = $('.field.has-err input, .field.has-err textarea');
  if (errField) errField.focus();
})();
