/* ============ APPROACH PLATE: hold to fly the glide slope ============ */
/* The plate's lines stretch with the box (preserveAspectRatio none, non-scaling strokes); the glyph, lamps and labels are
   HTML on percentages, so the profile can be taller on phones without distorting anything. */
const fly = $('#fly');
const subSec = $('#subscribe');
const holdBtn = $('#hold');
const holdLabel = $('#hold-label');
const holdFill = $('#hold-fill');
const flyStatus = $('#fly-status');
const flyRead = $('#fly-read');
const glyph = $('#glyph');
const plateBox = $('#fly-plate');
const GS0 = [150, 70], GS1 = [678.7, 252], FULL_MS = 2800;
let prog = 0, holding = false, landed = false, hRaf = null, hLast = 0, rel = null, lastReadAt = 0, lastRead = '', lastStatus = '';
let pw = 1000, ph = 320, gsAng = 19;
function measurePlate() {
  pw = plateBox.clientWidth || pw; ph = plateBox.clientHeight || ph;
  gsAng = Math.atan2((GS1[1] - GS0[1]) / 320 * ph, (GS1[0] - GS0[0]) / 1000 * pw) * 180 / Math.PI;
  fly.style.setProperty('--gsa', gsAng.toFixed(2) + 'deg');
}
function setStatus(s) { if (s !== lastStatus) { flyStatus.textContent = s; lastStatus = s; } }
function renderFly(now, force) {
  const x = (GS0[0] + (GS1[0] - GS0[0]) * prog) / 1000 * pw, y = (GS0[1] + (GS1[1] - GS0[1]) * prog) / 320 * ph;
  glyph.style.transform = 'translate3d(' + x.toFixed(1) + 'px,' + y.toFixed(1) + 'px,0) rotate(' + gsAng.toFixed(2) + 'deg)';
  holdFill.style.transform = 'scaleX(' + prog.toFixed(4) + ')';
  if (force || now - lastReadAt >= 100) {
    const r = 'ALT ' + Math.round((1500 - 1300 * prog) / 10) * 10;
    if (r !== lastRead) { flyRead.textContent = r; lastRead = r; lastReadAt = now; }
  }
}
if (window.ResizeObserver) new ResizeObserver(() => { measurePlate(); renderFly(performance.now(), true); }).observe(plateBox);
function flyLoop(now) {
  const dt = Math.min(100, now - (hLast || now)); hLast = now;
  if (holding) {
    prog = Math.min(1, prog + dt / FULL_MS);
    if (prog >= 1) { land(); return; }
  } else if (rel) {
    const t = clamp((now - rel.t0) / rel.dur, 0, 1);
    prog = rel.p0 * (1 - easeOut3(t));                  // half speed on average, ease-out climb
    if (t >= 1) { prog = 0; rel = null; }
  }
  renderFly(now, !holding && !rel);
  if (holding || rel) hRaf = requestAnimationFrame(flyLoop); else { hRaf = null; hLast = 0; }
}
function kick() { if (hRaf === null) hRaf = requestAnimationFrame(flyLoop); }
function press() {
  if (RM.matches) { land(); return; }
  fly.classList.add('touched');
  if (landed) resetFly();
  holding = true; rel = null;
  setStatus(T.flyHold);
  kick();
}
function release() {
  if (!holding) return;
  holding = false;
  if (landed) return;
  if (prog > 0) { rel = { t0: performance.now(), p0: prog, dur: prog * FULL_MS * 2 }; setStatus(T.flyGa); kick(); }
}
function land() {
  holding = false; rel = null; prog = 1; landed = true;
  if (hRaf !== null) { cancelAnimationFrame(hRaf); hRaf = null; hLast = 0; }
  renderFly(performance.now(), true);
  setStatus(T.flyDone);
  holdLabel.textContent = T.holdAgain;
  fly.classList.add('done');
  subSec.classList.add('landed');           // completion lights the runway the chart lands on: approach lights, threshold, edges
}
function resetFly() {
  landed = false; prog = 0; rel = null;
  fly.classList.remove('done');
  subSec.classList.remove('landed');
  holdLabel.textContent = T.holdBtn;
  renderFly(performance.now(), true);
}
holdBtn.addEventListener('pointerdown', e => {
  if (e.button !== 0) return;
  e.preventDefault();
  try { holdBtn.setPointerCapture(e.pointerId); } catch (_) { /* capture is optional */ }
  holdBtn.focus({ preventScroll: true });
  press();
});
['pointerup', 'pointercancel', 'lostpointercapture'].forEach(t => holdBtn.addEventListener(t, release));
holdBtn.addEventListener('contextmenu', e => e.preventDefault());
holdBtn.addEventListener('keydown', e => {
  if (e.key !== ' ' && e.key !== 'Enter') return;
  e.preventDefault();
  if (!e.repeat) press();
});
holdBtn.addEventListener('keyup', e => { if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); release(); } });
holdBtn.addEventListener('blur', release);

/* ---------- reduced motion: pin final states, and undo them ---------- */
let pinned = false;
function pinToFinalStates() {
  pinned = true;
  disableScrub();
  land();                                  // hold module complete, drives stopped
  chartRedraw();                           // route drawn to the runway, every leg label plotted
}
function unpinFinalStates() {
  if (!pinned) return;
  pinned = false;
  resetFly();
  fly.classList.remove('touched');
  setStatus(T.flyIdle);
  chartRedraw();                           // the route goes back to following the scroll
}
RM.addEventListener('change', e => { if (e.matches) pinToFinalStates(); else { unpinFinalStates(); applyHeroMode(); } });

/* ============ 06: runway lights (generated once) ============ */
(function buildRunway() {
  const mk = (host, n, cls) => { for (let i = 0; i < n; i++) { const d = document.createElement('i'); d.style.setProperty('--i', i); if (cls) d.className = cls; host.append(d); } };
  mk($('#rw-edge-l'), 15); mk($('#rw-edge-r'), 15);
  mk($('#rw-thr'), 14); mk($('#rw-end'), 10);
  // approach lights: four centreline barrettes (nearest first, so the rabbit runs toward the threshold) and a crossbar
  const als = $('#rw-als');
  [[0, 5], [26, 5], [52, 11], [78, 5]].forEach(([y, n], r) => {
    const row = document.createElement('div'); row.className = 'als-row'; row.style.top = y + 'px';
    mk(row, n);
    const rab = document.createElement('i'); rab.className = 'rab'; rab.style.setProperty('--r', r); row.append(rab);
    als.append(row);
  });
})();

/* ============ 04: AI shelf as a COM radio, one frequency per shelf (from the inline JSON) ============ */
/* Tabs (role=tablist) tune a shelf: the tuned frequency goes to ACT, the one before it drops to STBY, and the swap button
   flips back, like a real COM head. Every card stays in the DOM; inactive shelves are hidden panels. */
const FREQ = ['118.250', '121.650', '124.350', '127.850', '132.450'];
let tuneTo = () => {};
(function renderShelves() {
  const host = $('#shelves');
  const note = $('.shelf-note', host);
  const bar = $('#tune');
  const fAct = $('#f-act'), fSby = $('#f-sby'), fActN = $('#f-act-n'), fSbyN = $('#f-sby-n'), swap = $('#f-swap');
  let data;
  try { data = JSON.parse($('#shelf-data').textContent); } catch (_) { return; }
  const mk = (tag, cls, text) => { const el = document.createElement(tag); if (cls) el.className = cls; if (text != null) el.textContent = text; return el; };
  const tabs = [], panels = [], names = [];
  data.shelves.forEach((shelf, i) => {
    const code = 'A' + (i + 1), fq = FREQ[i % FREQ.length];
    names.push(code + ' ' + shelf.title);
    const tab = mk('button', 'tab');
    tab.type = 'button'; tab.id = 'tab-' + shelf.id;
    tab.setAttribute('role', 'tab'); tab.setAttribute('aria-controls', 'panel-' + shelf.id); tab.setAttribute('aria-selected', 'false'); tab.tabIndex = -1;
    tab.append(mk('span', 'tc', code), document.createTextNode(' '), mk('span', 'tf', fq.slice(0, 6)), document.createTextNode(' '), mk('span', 'tn', shelf.title));
    bar.append(tab); tabs.push(tab);

    const sec = mk('section', 'shelf');
    sec.id = 'panel-' + shelf.id;
    sec.setAttribute('role', 'tabpanel'); sec.setAttribute('aria-labelledby', 'shelf-' + shelf.id);
    sec.hidden = true;
    const inner = mk('div', 'shelf-in');
    const aisle = mk('div', 'aisle');
    aisle.append(mk('b', null, code));
    const h = mk('h3', null, shelf.title); h.id = 'shelf-' + shelf.id;
    const af = mk('span', 'af', fq); af.setAttribute('aria-hidden', 'true');
    aisle.append(h, af);
    inner.append(aisle, mk('p', 'shelf-blurb', shelf.blurb));
    const ul = mk('ul', 'cards');
    shelf.tools.forEach((tool, j) => {
      const li = mk('li', 'card');
      const ix = mk('span', 'ix', code + '-' + String(j + 1).padStart(2, '0')); ix.setAttribute('aria-hidden', 'true');
      li.append(ix, mk('h4', null, tool.name), mk('p', 'desc', tool.desc));
      if (tool.cn_access === '能直接访问' || tool.cn_access === '需要国际网络') li.append(mk('span', 'badge', tool.cn_access));
      if (tool.note) li.append(mk('p', 'note', tool.note));
      const a = mk('a', 'open');
      a.href = tool.url; a.target = '_blank'; a.rel = 'noopener noreferrer';
      let host2 = tool.url;
      try { host2 = new URL(tool.url).hostname.replace(/^www\./, ''); } catch (_) { /* keep raw */ }
      a.append(document.createTextNode('打开'), mk('span', 'dom', host2));
      li.append(a);
      ul.append(li);
    });
    inner.append(ul);
    sec.append(inner);
    host.insertBefore(sec, note);
    panels.push(sec);
  });
  if (!tabs.length) return;
  let cur = -1, sby = Math.min(1, tabs.length - 1);
  function select(i, o) {
    o = o || {};
    if (i === cur) return;
    if (cur >= 0) sby = cur;
    cur = i;
    tabs.forEach((t, k) => { const on = k === i; t.setAttribute('aria-selected', String(on)); t.tabIndex = on ? 0 : -1; });
    panels.forEach((p, k) => { p.hidden = k !== i; p.classList.toggle('tuned', k === i && !!o.user); });
    fAct.textContent = FREQ[i % FREQ.length]; fActN.textContent = names[i];
    fSby.textContent = FREQ[sby % FREQ.length]; fSbyN.textContent = names[sby];
    swap.setAttribute('aria-label', '切换到备用频率：' + names[sby]);
    if (o.user) {
      fAct.classList.remove('flip'); void fAct.offsetWidth; fAct.classList.add('flip');
      const t = tabs[i], bl = bar.scrollLeft, bw = bar.clientWidth;   // keep the tuned tab in view inside the bar, never scroll the page sideways
      if (t.offsetLeft < bl + 8 || t.offsetLeft + t.offsetWidth > bl + bw - 8) bar.scrollTo({ left: t.offsetLeft - 14, behavior: RM.matches ? 'auto' : 'smooth' });
      const top = panels[i].getBoundingClientRect().top;          // phones: a shorter shelf must not leave the reader below it
      if (top < 72) scrollTo({ top: scrollY + top - 88, behavior: RM.matches ? 'auto' : 'smooth' });
    }
    if (o.focus) tabs[i].focus();
  }
  tuneTo = select;
  tabs.forEach((t, i) => t.addEventListener('click', () => select(i, { user: true })));
  bar.addEventListener('keydown', e => {
    const n = tabs.length;
    let k = null;
    if (e.key === 'ArrowRight' || e.key === 'ArrowDown') k = (cur + 1) % n;
    else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') k = (cur - 1 + n) % n;
    else if (e.key === 'Home') k = 0;
    else if (e.key === 'End') k = n - 1;
    if (k === null) return;
    e.preventDefault();
    select(k, { user: true, focus: true });
  });
  swap.addEventListener('click', () => select(sby, { user: true }));
  select(0);
})();

/* ============ THE CHART: one route, drawn as you scroll ============ */
/* Geometry: the route is a polyline through the [data-route] anchors in DOM order (each anchor a 0x0 element that CSS places
   per layout), with fly-by corners. It always descends the page, so the reading line (55% of the viewport) maps to one point
   on it: the magenta route is revealed down to that point by a clipping wrapper moved with transforms (the wrapper slides
   up, its content slides back down by the same amount), and the ownship sits on the point, turned to the local track.
   Scale: 32px = 1' of latitude = 1 NM; 277px = 10' of longitude at 30°N. Courses are magnetic (VAR 6°W). */
const chart = $('#chart');
const airwaySvg = $('#airway-svg'), airwayPath = $('#airway-path');
const routeClip = $('#route-clip'), routeInner = $('#route-inner'), routeSvg = $('#route-svg');
const routePath = $('#route-path'), routeGlow = $('#route-glow');
const legHost = $('#leg-lbls'), own = $('#ownship');
const hud = $('#hud'), hudTo = $('#hud-to'), hudDtk = $('#hud-dtk'), hudDis = $('#hud-dis');
const PX_NM = 32, PX_LON = 27.7, VAR = 6, LAT0 = 30 * 60, LON0 = 106 * 60 + 30, REVEAL = 0.55;
const MORSE = { A: '.-', B: '-...', C: '-.-.', D: '-..', E: '.', F: '..-.', G: '--.', H: '....', I: '..', J: '.---', K: '-.-', L: '.-..', M: '--', N: '-.', O: '---', P: '.--.', Q: '--.-', R: '.-.', S: '...', T: '-', U: '..-', V: '...-', W: '.--', X: '-..-', Y: '-.--', Z: '--..' };
const svgNS = 'http://www.w3.org/2000/svg';
const bearing = (dx, dy) => ((Math.atan2(dx, -dy) * 180 / Math.PI) + 360 + VAR) % 360;   // page up = true north; magnetic = true + 6
const crs3 = b => String((Math.round(b) % 360) || 360).padStart(3, '0') + '°';
const fmtLat = min => { const d = Math.floor(min / 60); return 'N' + d + '°' + (min - d * 60).toFixed(1).padStart(4, '0') + "'"; };
const fmtLon = min => { const d = Math.floor(min / 60); return 'E' + String(d).padStart(3, '0') + '°' + (min - d * 60).toFixed(1).padStart(4, '0') + "'"; };
const dm = min => { const d = Math.floor(min / 60), m = Math.round(min - d * 60); return d + '°' + String(m).padStart(2, '0') + "'"; };

/* printed once: the compass rose (oriented to magnetic north, so turned 6° west) and the morse idents */
(function printRose() {
  const rose = $('#rose');
  let s = '<g transform="rotate(' + (-VAR) + ')"><circle class="rc" r="200"/><circle class="rc2" r="140"/>';
  for (let d = 0; d < 360; d += 5) {
    const mj = d % 30 === 0, len = mj ? 16 : d % 10 === 0 ? 10 : 6;
    s += '<line class="tk' + (mj ? ' mj' : '') + '" x1="0" y1="-200" x2="0" y2="' + (-200 + len) + '" transform="rotate(' + d + ')"/>';
    if (mj) s += d === 0
      ? '<path class="mn" d="M0,-228 L6,-212 L-6,-212 Z"/>'
      : '<text x="0" y="-170" transform="rotate(' + d + ')">' + (d / 10) + '</text>';
  }
  s += '<line class="rad" x1="0" y1="0" x2="0" y2="-200" transform="rotate(300)"/><text class="radl" x="0" y="-122" transform="rotate(300)">R-300</text>';
  s += '</g>';
  rose.innerHTML = s;
  $$('[data-morse]').forEach(el => {
    el.setAttribute('aria-hidden', 'true');
    [...el.dataset.morse].forEach((ch, k) => {
      if (k) el.append(Object.assign(document.createElement('i'), { className: 'gap' }));
      for (const sym of MORSE[ch] || '') el.append(Object.assign(document.createElement('i'), { className: sym === '.' ? 'dit' : 'dah' }));
    });
  });
})();

let RT = null, chartVis = false, chartRaf = 0, lastYv = -1, hudOn = null, hudTxt = '', ownHome = null;
function chartBuild() {
  const cr = chart.getBoundingClientRect();
  const W = chart.clientWidth, H = chart.offsetHeight;
  if (!W || !H) return;
  const P = [];
  for (const el of $$('[data-route]', chart)) {
    if (!el.getClientRects().length) continue;            // anchors a layout hides (collinear bends on phones)
    const r = el.getBoundingClientRect();
    const p = { el, name: el.dataset.route, leg: el.dataset.leg || '', x: r.left - cr.left + r.width / 2, y: r.top - cr.top + r.height / 2 };
    const q = P[P.length - 1];
    if (q && Math.hypot(p.x - q.x, p.y - q.y) < 2) continue;
    if (q && p.y < q.y + 1) p.y = q.y + 1;                // the route only ever descends the page
    P.push(p);
  }
  if (P.length < 2) { RT = null; return; }

  // path and sample table, built together: straight legs, quadratic fly-by corners (tight at named fixes, wide at bends)
  const xs = [P[0].x], ys = [P[0].y], ls = [0];
  let L = 0, cx0 = P[0].x, cy0 = P[0].y;
  const put = (x, y) => { L += Math.hypot(x - cx0, y - cy0); cx0 = x; cy0 = y; xs.push(x); ys.push(y); ls.push(L); };
  const seg = (x, y) => { const x0 = cx0, y0 = cy0, n = Math.max(1, Math.ceil(Math.hypot(x - x0, y - y0) / 5)); for (let i = 1; i <= n; i++) put(x0 + (x - x0) * i / n, y0 + (y - y0) * i / n); };
  const quad = (qx, qy, x, y) => { const x0 = cx0, y0 = cy0; for (let i = 1; i <= 18; i++) { const t = i / 18, u = 1 - t; put(u * u * x0 + 2 * u * t * qx + t * t * x, u * u * y0 + 2 * u * t * qy + t * t * y); } };
  const f = v => v.toFixed(1);
  let d = 'M' + f(P[0].x) + ' ' + f(P[0].y);
  P[0].si = 0;
  for (let i = 1; i < P.length; i++) {
    const a = P[i - 1], b = P[i], c = P[i + 1];
    if (!c) { seg(b.x, b.y); d += 'L' + f(b.x) + ' ' + f(b.y); b.si = xs.length - 1; break; }
    const l1 = Math.hypot(b.x - a.x, b.y - a.y), l2 = Math.hypot(c.x - b.x, c.y - b.y);
    const r = Math.min(b.name ? 26 : 120, l1 * 0.45, l2 * 0.45);
    const ax = b.x - (b.x - a.x) / l1 * r, ay = b.y - (b.y - a.y) / l1 * r;
    const bx = b.x + (c.x - b.x) / l2 * r, by = b.y + (c.y - b.y) / l2 * r;
    seg(ax, ay); d += 'L' + f(ax) + ' ' + f(ay);
    b.si = xs.length + 8;
    quad(b.x, b.y, bx, by); d += 'Q' + f(b.x) + ' ' + f(b.y) + ' ' + f(bx) + ' ' + f(by);
  }
  for (const s of [airwaySvg, routeSvg]) { s.setAttribute('width', W); s.setAttribute('height', H); s.setAttribute('viewBox', '0 0 ' + W + ' ' + H); }
  airwayPath.setAttribute('d', d); routePath.setAttribute('d', d); routeGlow.setAttribute('d', d);

  const named = P.filter(p => p.name);
  const lat = p => LAT0 - p.y / PX_NM, lon = p => LON0 + (p.x - W / 2) / PX_LON;
  named.forEach(p => { const co = $('[data-co="' + p.name + '"]'); if (co) co.textContent = fmtLat(lat(p)) + ' ' + fmtLon(lon(p)); });

  // leg data: section legs get a plotted label on their most crossing segment (phones: beside the rail, above the fix);
  // the five draft legs carry their own course and distance in their header row
  legHost.textContent = '';
  const labels = [];
  for (let j = 1; j < P.length; j++) {
    const p = P[j];
    if (!p.name) continue;
    let k = j - 1; while (k > 0 && !P[k].name) k--;
    const dist = (ls[p.si] - ls[P[k].si]) / PX_NM;
    const crsEl = $('[data-crs="' + p.name + '"]'), disEl = $('[data-dis="' + p.name + '"]');
    if (crsEl) crsEl.textContent = crs3(bearing(p.x - P[j - 1].x, p.y - P[j - 1].y));
    if (disEl) disEl.textContent = dist.toFixed(1) + ' NM';
    if (!p.leg) continue;
    const [awy, mea, moca] = p.leg.split('|');
    let best = j - 1, bdx = -1;
    for (let m = k; m < j; m++) { const dx = Math.abs(P[m + 1].x - P[m].x); if (dx > bdx) { bdx = dx; best = m; } }
    const s0 = P[best], s1 = P[best + 1], el = document.createElement('div');
    const along = bdx >= 160;
    const crs = crs3(bearing(s1.x - s0.x, s1.y - s0.y));
    el.className = 'leg-lbl ' + (along ? 'along' : 'side');
    const add = (cls, t) => { const sp = document.createElement('span'); sp.className = cls; sp.textContent = t; el.append(sp); };
    add('crs', crs); add('awy', awy); add('dis', Math.round(dist) + ' NM'); add('mea', 'MEA ' + mea); add('moca', moca);
    legHost.append(el);
    let lx, ly, tr;
    if (along) {
      lx = (s0.x + s1.x) / 2; ly = (s0.y + s1.y) / 2;
      let ang = Math.atan2(s1.y - s0.y, s1.x - s0.x) * 180 / Math.PI;
      if (ang > 90) ang -= 180; else if (ang < -90) ang += 180;
      tr = 'translate(' + f(lx) + 'px,' + f(ly) + 'px) rotate(' + ang.toFixed(2) + 'deg) translate(-50%,-50%)';
    } else {
      lx = p.x + 18; ly = p.y - 62;
      tr = 'translate(' + f(lx) + 'px,' + f(ly) + 'px) translateY(-50%)';
    }
    el.style.transform = tr;
    labels.push({ el, y: ly, on: false });
  }

  printChartBg(W, H, P);
  RT = { W, H, xs, ys, ls, n: xs.length, end: ys[ys.length - 1], named, labels, top0: cr.top + scrollY };
  lastYv = -1;
  chartFrame();
}

/* the printed sheet around the route: graticule labels, Grid MORA figures, an isogonic line, two crossing airways and the
   terminal-area boundary around the runway. Rebuilt with the route (positions depend on the layout). */
function printChartBg(W, H, P) {
  const bg = $('#bg-svg'), host = $('#bg-lbls');
  const phone = W <= 900;
  bg.setAttribute('width', W); bg.setAttribute('height', H); bg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);
  host.textContent = '';
  const lbl = (cls, text, x, y, extra) => { const p = document.createElement('p'); p.className = 'bg-lbl ' + cls; p.textContent = text; p.style.transform = 'translate(' + x.toFixed(1) + 'px,' + y.toFixed(1) + 'px)' + (extra || ''); host.append(p); return p; };
  // content blocks, measured once: nothing printed on the sheet may sit on top of them
  const cr = chart.getBoundingClientRect();
  const raw = $$('.chh, .about-text, .about-fig, .rose-wrap, .log-intro, .post, .hold-fig, .legend, .comm, .checklist, .msa, .fly, .rwy-zone, .faq .after').map(e => {
    const r = e.getBoundingClientRect();
    let t = r.top, bt = r.bottom;
    if (e.classList.contains('log-intro')) { const g = e.parentElement.getBoundingClientRect(); t = g.top; bt = g.bottom; }   // sticky: it can sit anywhere in its column
    return [r.left - cr.left, t - cr.top, r.right - cr.left, bt - cr.top];
  });
  const solids = raw.map(b => [b[0] - 30, b[1] - 40, b[2] + 30, b[3] + 40]);
  const hits = (x0, y0, x1, y1) => raw.some(b => x1 > b[0] - 8 && x0 < b[2] + 8 && y1 > b[1] - 6 && y0 < b[3] + 6);
  // latitude down the right neatline every 10' (skipped where a text column reaches the neatline), longitude along the top edge
  for (let y = 320; y < H - 40; y += 320) if (!hits(W - 92, y - 8, W - 26, y + 8)) lbl('lat', dm(LAT0 - y / PX_NM) + 'N', W - 26, y, ' translate(-100%,-50%)');
  for (let k = -6; k <= 6; k++) { const x = W / 2 + k * 277; if (x > 30 && x < W - 90) lbl('lon', 'E' + dm(LON0 + k * 10), x + 4, 8); }
  // Grid MORA: one figure per graticule cell, kept off every content block
  const clear = (x, y) => x > 50 && x < W - 50 && y > 20 && y < H - 20 && !solids.some(b => x > b[0] && x < b[2] && y > b[1] && y < b[3]);
  const r = rng(4021);
  for (let y = 0; y < H; y += 320) for (let x = W / 2 - 277 * 6; x < W; x += 277) {
    const cx = x + 138.5, cy = y + 160;
    if (cx < 40 || cx > W - 40 || r() < 0.25) continue;
    if (solids.some(b => cx > b[0] - 40 && cx < b[2] + 40 && cy > b[1] - 30 && cy < b[3] + 30)) continue;
    const big = 3 + Math.floor(r() * 6), small = Math.floor(r() * 10);
    const m = document.createElement('p'); m.className = 'mora'; m.textContent = big;
    const sup = document.createElement('sup'); sup.textContent = small; m.append(sup);
    m.style.left = cx.toFixed(0) + 'px'; m.style.top = cy.toFixed(0) + 'px';
    host.append(m);
  }
  // vector furniture
  const byName = n => P.find(p => p.name === n);
  let s = '';
  s += '<path class="iso" d="M' + (W * 0.2).toFixed(0) + ',0 L' + (W * 0.78).toFixed(0) + ',' + H + '"/>';
  const isoAt = t => [W * 0.2 + (W * 0.58) * t, H * t];
  const isoAng = Math.atan2(H, W * 0.58) * 180 / Math.PI - 90;
  [0.16, 0.62].forEach(t => { const [x, y] = isoAt(t); lbl('iso', 'VAR 6°W', x, y, ' rotate(' + (isoAng + 90).toFixed(1) + 'deg) translate(-50%,-140%)'); });
  [['LOGBK', 64, 'W77'], ['AISHP', -58, 'V21']].forEach(([n, ang, des]) => {
    const p = byName(n); if (!p) return;
    const a = ang * Math.PI / 180, dx = Math.cos(a), dy = Math.sin(a), len = Math.max(W, 1400);
    s += '<path class="awy2" d="M' + (p.x - dx * len).toFixed(0) + ',' + (p.y - dy * len).toFixed(0) + ' L' + (p.x + dx * len).toFixed(0) + ',' + (p.y + dy * len).toFixed(0) + '"/>';
    const t = [300, -300, 460, -460, 620, -620, 180, -180].map(v => phone ? v / 2 : v).find(v => clear(p.x + dx * v, p.y + dy * v));
    if (t !== undefined) lbl('box', des, p.x + dx * t, p.y + dy * t, ' translate(-50%,-50%)');
  });
  const rw = byName('RW24');
  if (rw) {
    const R = phone ? 980 : 1500;
    s += '<circle class="tma-h" cx="' + rw.x.toFixed(0) + '" cy="' + rw.y.toFixed(0) + '" r="' + R + '"/><circle class="tma" cx="' + rw.x.toFixed(0) + '" cy="' + rw.y.toFixed(0) + '" r="' + (R - 5) + '"/>';
    // the boundary label rides the arc where it is on the sheet and clear of content (the arc is wider than a phone)
    for (const deg of [-112, -68, -104, -76, -120, -60, -96, -84, -128, -52]) {
      const a = deg * Math.PI / 180, lx = rw.x + Math.cos(a) * (R + 22), ly = rw.y + Math.sin(a) * (R + 22);
      if (lx < 130 || lx > W - 130 || ly < 40 || hits(lx - 110, ly - 40, lx + 110, ly + 40)) continue;
      lbl('tma', '云底 TMA · 1000 / 9500', lx, ly, ' translate(-50%,-50%) rotate(' + (deg + 90) + 'deg)');
      break;
    }
  }
  bg.innerHTML = s;
}

function chartFrame() {
  chartRaf = 0;
  if (!RT) return;
  const top = chart.getBoundingClientRect().top;
  const yv = RM.matches ? RT.end : clamp(innerHeight * REVEAL - top, RT.ys[0], RT.end);
  if (Math.abs(yv - lastYv) < 0.4) return;
  lastYv = yv;
  const { xs, ys, ls, n, H } = RT;
  let lo = 0, hi = n - 1;                                   // last sample at or above the reading line
  while (lo < hi) { const mid = (lo + hi + 1) >> 1; if (ys[mid] <= yv) lo = mid; else hi = mid - 1; }
  const i = lo, j = Math.min(n - 1, i + 1);
  const t = ys[j] > ys[i] ? (yv - ys[i]) / (ys[j] - ys[i]) : 0;
  const x = xs[i] + (xs[j] - xs[i]) * t;
  const a = Math.max(0, i - 3), b = Math.min(n - 1, i + 4);
  const brg = Math.atan2(xs[b] - xs[a], -(ys[b] - ys[a])) * 180 / Math.PI;
  routeClip.style.transform = 'translate3d(0,' + (yv - H).toFixed(1) + 'px,0)';
  routeInner.style.transform = 'translate3d(0,' + (H - yv).toFixed(1) + 'px,0)';
  own.style.transform = 'translate3d(' + x.toFixed(1) + 'px,' + yv.toFixed(1) + 'px,0) rotate(' + brg.toFixed(1) + 'deg)';
  const home = yv <= ys[0] + 1 || yv >= RT.end - 1;      // parked at the start or stopped on the runway: no track vector
  if (home !== ownHome) { own.classList.toggle('home', home); ownHome = home; }
  for (const l of RT.labels) if (!l.on && yv >= l.y - 40) { l.on = true; l.el.classList.add('on'); }
  let next = null;
  for (const p of RT.named) {
    const past = p.y <= yv + 0.5;
    if (!past && !next) next = p;
    if (p.past !== past) { p.el.classList.toggle('past', past); p.past = past; }
    const act = p === next;
    if (p.act !== act) { p.el.classList.toggle('act', act); p.act = act; }
  }
  // the active-leg line: next fix, desired track, distance along the route
  const on = chartVis && !RM.matches && !!next && yv > ys[0] + 4 && performance.now() - lastScrollAt < 1800;
  if (on) {
    const txt = next.name + '|' + crs3(bearing(xs[b] - xs[a], ys[b] - ys[a])) + '|' + Math.max(0, (ls[next.si] - (ls[i] + (ls[j] - ls[i]) * t)) / PX_NM).toFixed(1);
    if (txt !== hudTxt) { const [nm, dtk, dis] = txt.split('|'); hudTo.textContent = nm; hudDtk.textContent = dtk; hudDis.textContent = dis; hudTxt = txt; }
  }
  if (on !== hudOn) { hud.classList.toggle('on', on); hudOn = on; }
}
// the readout wakes while the page moves and sleeps 1.8s after it stops, so it never sits on top of what is being read
let lastScrollAt = -1e9, hudSleep = 0;
function chartScroll() {
  lastScrollAt = performance.now();
  clearTimeout(hudSleep);
  hudSleep = setTimeout(() => { lastYv = -1; chartFrame(); }, 1850);
  if (chartVis && !chartRaf) chartRaf = requestAnimationFrame(chartFrame);
}
function chartRedraw() { lastYv = -1; if (RT) { if (RM.matches) RT.labels.forEach(l => { l.on = true; l.el.classList.add('on'); }); chartFrame(); } }
addEventListener('scroll', chartScroll, { passive: true });
new IntersectionObserver(es => { chartVis = es[0].isIntersecting; lastYv = -1; chartFrame(); }).observe(chart);
let buildT = 0;
const rebuild = () => { clearTimeout(buildT); buildT = setTimeout(chartBuild, 120); };
if (window.ResizeObserver) new ResizeObserver(rebuild).observe(chart); else addEventListener('resize', rebuild);
if (document.fonts && document.fonts.ready) document.fonts.ready.then(rebuild);
addEventListener('load', rebuild);
