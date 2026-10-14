/* ============ FPL: hold to fly the glide slope on the VSD (the one designed interaction) ============ */
const fly = $('#fly');
const logSec = $('#log');
const holdBtn = $('#hold');
const holdLabel = $('#hold-label');
const holdFill = $('#hold-fill');
const flyStatus = $('#fly-status');
const flyRead = $('#fly-read');
const glyph = $('#glyph');
// VSD geometry (viewBox 1000x280): FAP 1500 ft at (120,50), ground y 240, 0.1267 px/ft, so DA 200 sits at y 214.7 on the path
const GS0 = [120, 50], GS1 = [726.7, 214.7], GS_ROT = 15.2, FULL_MS = 2800;
let prog = 0, holding = false, landed = false, hRaf = null, hLast = 0, rel = null, lastReadAt = 0, lastRead = '', lastStatus = '';
function setStatus(s) { if (s !== lastStatus) { flyStatus.textContent = s; lastStatus = s; } }
function renderFly(now, force) {
  const x = GS0[0] + (GS1[0] - GS0[0]) * prog, y = GS0[1] + (GS1[1] - GS0[1]) * prog;
  glyph.setAttribute('transform', 'translate(' + x.toFixed(1) + ' ' + y.toFixed(1) + ') rotate(' + GS_ROT + ')');
  holdFill.style.transform = 'scaleX(' + prog.toFixed(4) + ')';
  if (force || now - lastReadAt >= 100) {
    const r = 'ALT ' + Math.round((1500 - 1300 * prog) / 10) * 10;
    if (r !== lastRead) { flyRead.textContent = r; lastRead = r; lastReadAt = now; }
  }
}
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
  logSec.classList.add('landed');
}
function resetFly() {
  landed = false; prog = 0; rel = null;
  fly.classList.remove('done');
  logSec.classList.remove('landed');
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

/* ============ FPL: legs (select a leg: it turns magenta, its panel opens) ============ */
const legs = $$('#legs .leg');
const legBtns = legs.map(l => $('.leg-btn', l));
const legPanels = legs.map(l => $('.leg-x', l));
const WIDE = matchMedia('(min-width: 900px)');
let legSel = 0;
const mapLeg = $('#map-leg'), mapRo = $('#map-ro');
function showLeg(i) {                    // i = -1 closes all (phones only)
  legs.forEach((l, j) => {
    const on = j === i;
    l.classList.toggle('on', on);
    legBtns[j].setAttribute('aria-expanded', String(on));
    legPanels[j].hidden = !on;
  });
  if (i >= 0) {
    legSel = i;
    const b = legBtns[i];                 // the pane's map turns the leg to its course
    mapLeg.style.setProperty('--crs', (+b.dataset.crs % 360) + 'deg');
    mapRo.textContent = b.dataset.crs + '° · ' + b.dataset.nm + ' NM';
  }
}
legBtns.forEach((b, i) => {
  b.addEventListener('click', () => {
    const open = b.getAttribute('aria-expanded') === 'true';
    showLeg(open && !WIDE.matches ? -1 : i);   // the wide pane always shows one leg; on phones a second tap folds it
  });
  b.addEventListener('keydown', e => {
    const k = e.key === 'ArrowDown' ? 1 : e.key === 'ArrowUp' ? -1 : 0;
    if (!k) return;
    e.preventDefault();
    legBtns[(i + k + legBtns.length) % legBtns.length].focus();
  });
});
WIDE.addEventListener('change', () => { if (WIDE.matches && !legs.some(l => l.classList.contains('on'))) showLeg(legSel); });

/* ============ CHECKLIST: items tick when read ============ */
// The next open item is boxed in cyan (the checklist cursor). Once its challenge line has sat in the visible part of the page
// for READ_MS, it ticks green and the cursor moves on. Polls only while the checklist page is on screen. Tapping a box ticks
// or clears it by hand, which also hands the checklist over to the reader (no more automatic ticks).
const clBox = $('#checklist');
const clRows = $$('.cl-row', clBox);
const clTicks = clRows.map(r => $('.cl-tick', r));
const clN = $('#cl-n');
const READ_MS = 1200;
let clTimer = 0, clSince = 0, clManual = false, clPinned = false;
clRows.forEach((r, i) => clTicks[i].setAttribute('aria-label', '已读：' + $('.q', r).textContent));
function clNext() { return clRows.findIndex(r => !r.classList.contains('ok')); }
function clSet(i, ok) {
  clRows[i].classList.toggle('ok', ok);
  clTicks[i].setAttribute('aria-pressed', String(ok));
  clCursor();
}
function clCursor() {
  const n = clNext();
  clRows.forEach((r, i) => r.classList.toggle('cur', i === n));
  clBox.classList.toggle('done', n === -1);
  clN.textContent = String(clRows.filter(r => r.classList.contains('ok')).length);
}
clTicks.forEach((b, i) => b.addEventListener('click', () => { clManual = true; clSet(i, !clRows[i].classList.contains('ok')); clSync(); }));
function clOnScreen() { return ckDesk ? (ckLive && cur === 4) : pgVis[4]; }
function clReadable(row) {
  const dt = row.firstElementChild.getBoundingClientRect();
  let top, bot;
  if (ckDesk) { const v = PG_VIEW[4].getBoundingClientRect(); top = v.top; bot = v.bottom; }
  else { top = NAV_H + 40; bot = innerHeight - 64; }
  return dt.top >= top - 2 && dt.bottom + 24 <= bot;
}
function clPoll() {
  if (document.hidden) return;           // hidden tab: no reading happens
  const n = clNext();
  if (n === -1 || !clOnScreen()) { clSync(); return; }
  if (!clReadable(clRows[n])) { clSince = 0; return; }
  const now = performance.now();
  if (!clSince) clSince = now;
  else if (now - clSince >= READ_MS) { clSet(n, true); clSince = 0; }
}
function clSync() {
  const run = clOnScreen() && clNext() !== -1 && !clManual && !RM.matches;
  if (run && !clTimer) { clSince = 0; clTimer = setInterval(clPoll, 250); }
  else if (!run && clTimer) { clearInterval(clTimer); clTimer = 0; }
}
function clPin() { if (clManual) return; clPinned = true; clRows.forEach((r, i) => { if (!r.classList.contains('ok')) clSet(i, true); }); clSync(); }
function clUnpin() { if (!clPinned) return; clPinned = false; if (!clManual) clRows.forEach((r, i) => clSet(i, false)); clSync(); }
clCursor();

/* ============ COCKPIT: six pages on one display ============ */
const ck = $('#cockpit');
const ckFrame = $('.ck-frame', ck);
const ckTrack = $('.ck-track', ck);
const ckScan = $('.ck-scan', ck);
const PG = $$('.pg', ck);
const PG_IN = PG.map(p => $('.pg-in', p));
const PG_VIEW = PG.map(p => $('.pg-view', p));
const PG_IDS = PG.map(p => p.id);
const SK = $$('.sk', ck);
const CK_GATE = matchMedia('(min-width: 960px) and (min-height: 620px)');
const NAV_H = 64;
let ckDesk = null, cur = -1, ckLead = 0, ckLive = false, ckPowered = false, ckRaf = null, jumping = false, jumpFrom = -1, jumpT = 0;
const starts = [], ranges = [], over = [], lastT = [], pgVis = PG.map(() => false);

/* ranges: each page owns lead + its overflow (moved 1:1 with the scroll) + a tail to dwell on, and never less than 80vh */
function ckMeasure() {
  if (!ckDesk) return;
  const vh = innerHeight;
  ckLead = Math.round(vh * 0.12);
  const tail = Math.round(vh * 0.36), min = Math.round(vh * 0.8);
  let acc = 0;
  PG.forEach((p, i) => {
    over[i] = Math.max(0, Math.ceil(PG_IN[i].offsetHeight - PG_VIEW[i].clientHeight));
    ranges[i] = Math.max(min, ckLead + over[i] + tail);
    starts[i] = acc; acc += ranges[i];
  });
  ckTrack.style.height = acc + 'px';
}
const ckS = () => NAV_H - ck.getBoundingClientRect().top;     // scroll distance into the pinned range
function pageAt(s) { let k = 0; for (let i = 1; i < PG.length; i++) if (s >= starts[i]) k = i; return k; }
function ckUpdate() {
  ckRaf = null;
  if (!ckDesk) return;
  const s = ckS();
  const k = pageAt(s);
  if (k !== cur) setPage(k, !jumping);
  for (let i = 0; i < PG.length; i++) {
    const t = i < k ? over[i] : i > k ? 0 : Math.round(clamp(s - starts[i] - ckLead, 0, over[i]));
    if (t !== lastT[i]) { PG_IN[i].style.transform = t ? 'translate3d(0,' + -t + 'px,0)' : ''; lastT[i] = t; }
  }
}
const ckKick = () => { if (ckRaf === null) ckRaf = requestAnimationFrame(ckUpdate); };
/* the soft keys are one tab stop (roving tabindex, like a tab list): arrows walk the row, Tab goes straight into the page */
function rove(k) { SK.forEach((a, i) => { a.tabIndex = i === k ? 0 : -1; }); }
function markCurrent(k) {
  SK.forEach((a, i) => { if (i === k) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current'); });
  if (!ck.contains(document.activeElement) || !document.activeElement.classList.contains('sk')) rove(k < 0 ? 0 : k);
}
function seePage(k) {
  const p = PG[k];
  if (!p || p.classList.contains('seen')) return;
  p.classList.add('seen');
  setTimeout(() => p.classList.add('settled'), 900);   // retire the populate stagger, so later hovers never lag
}
function setPage(k, animate) {
  const prev = cur;
  cur = k;
  markCurrent(k);
  if (ckDesk) {
    PG.forEach((p, i) => { const on = i === k; p.classList.toggle('on', on); p.inert = !on; });
    if (ckLive) seePage(k);
    if (animate && prev !== -1 && prev !== k) wipe(PG[k], PG[prev], k > prev ? 1 : -1);
  }
  syncRun(); clSync();
}
/* living elements run only on the page in view: desktop = the current page while the display is on screen */
function syncRun() { PG.forEach((p, i) => p.classList.toggle('run', ckDesk ? (ckLive && i === cur) : pgVis[i])); }

/* the scan-line wipe (desktop): the incoming page is revealed behind a bright raster line, the outgoing one stays under it */
let wA = null, wS = null, wOut = null;
function finishWipe() {
  if (wA) { wA.onfinish = null; wA.cancel(); wA = null; }
  if (wS) { wS.cancel(); wS = null; }
  if (wOut) { wOut.classList.remove('out'); wOut = null; }
}
function wipe(inEl, outEl, dir, dur) {
  finishWipe();
  if (RM.matches || !inEl.animate) return;
  dur = dur || 300;
  if (outEl) { outEl.classList.add('out'); wOut = outEl; }
  const h = inEl.offsetHeight, ease = 'cubic-bezier(.5,0,.2,1)';
  wA = inEl.animate([{ clipPath: dir > 0 ? 'inset(0 0 100% 0)' : 'inset(100% 0 0 0)' }, { clipPath: 'inset(0 0 0 0)' }], { duration: dur, easing: ease });
  wS = ckScan.animate([{ transform: 'translate3d(0,' + (dir > 0 ? 0 : h) + 'px,0) scaleY(' + dir + ')', opacity: 1 }, { transform: 'translate3d(0,' + (dir > 0 ? h : 0) + 'px,0) scaleY(' + dir + ')', opacity: 1 }], { duration: dur, easing: ease });
  wA.onfinish = finishWipe;
}
/* stacked: each page wipes in once, the first time it rises into view (only the first screen of it is swept) */
function stackWipe(p) {
  p.classList.add('seen');
  setTimeout(() => p.classList.add('settled'), 900);
  if (RM.matches || !p.animate || p.getBoundingClientRect().top < NAV_H) return;
  const h = innerHeight, ease = 'cubic-bezier(.45,0,.2,1)', o = { duration: 520, easing: ease };
  p.animate([{ clipPath: 'inset(0 0 calc(100% - 0px) 0)' }, { clipPath: 'inset(0 0 calc(100% - ' + h + 'px) 0)' }], o);
  const sc = $('.pg-scan', p);
  sc.animate([{ transform: 'translate3d(0,0,0)', opacity: 1 }, { transform: 'translate3d(0,' + h + 'px,0)', opacity: 1 }], o);
}

/* soft keys and every in-page link to a page: jump straight to that page's range (instant inside the display, smooth from
   the hero), then focus the page for keyboard users who came from outside the display */
function goPage(k, fromOutside) {
  if (ckDesk && ck.getBoundingClientRect().top - NAV_H <= innerHeight * 0.25) powerUp(false);   // already at the display: no slow sweep on top of the jump
  if (!ckDesk) { PG[k].scrollIntoView({ behavior: RM.matches ? 'auto' : 'smooth' }); return; }
  ckMeasure();
  const base = scrollY + ck.getBoundingClientRect().top - NAV_H;
  const target = Math.round(base + starts[k] + (k ? 1 : 0));
  const smooth = !RM.matches && scrollY < base - innerHeight * 0.25;
  if (smooth) {
    jumping = true; jumpFrom = cur;
    clearTimeout(jumpT); jumpT = setTimeout(endJump, 1600);
    window.scrollTo({ top: target, behavior: 'smooth' });
  } else {
    window.scrollTo({ top: target, behavior: 'instant' });
    ckUpdate();
  }
  if (fromOutside) PG[k].focus({ preventScroll: true });
}
function endJump() {
  clearTimeout(jumpT);
  if (!jumping) return;
  jumping = false;
  const from = jumpFrom; jumpFrom = -1;
  ckUpdate();
  if (ckDesk && from !== -1 && from !== cur) wipe(PG[cur], PG[from], cur > from ? 1 : -1);
}
document.addEventListener('click', e => {
  const a = e.target.closest ? e.target.closest('a[href^="#"]') : null;
  if (!a || !ckDesk || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey) return;
  const k = PG_IDS.indexOf(a.getAttribute('href').slice(1));
  if (k < 0) return;
  e.preventDefault();
  goPage(k, !a.classList.contains('sk'));
  try { window.history.replaceState(null, '', '#' + PG_IDS[k]); } catch (_) { /* sandboxed previews */ }
});
/* keyboard inside a tall page: keep the focused control inside the glass by moving the document, never the page itself */
ck.addEventListener('focusin', e => {
  if (!ckDesk) return;
  const el = e.target, pg = el.closest('.pg');
  if (!pg || el === pg) return;
  const k = PG.indexOf(pg);
  if (!PG_IN[k].contains(el)) return;
  const r = el.getBoundingClientRect(), v = PG_VIEW[k].getBoundingClientRect();
  if (k === cur && r.top >= v.top + 8 && r.bottom <= v.bottom - 8) return;
  const off = r.top - PG_IN[k].getBoundingClientRect().top;
  const want = clamp(Math.round(off - v.height * 0.3), 0, over[k]);
  const base = scrollY + ck.getBoundingClientRect().top - NAV_H;
  window.scrollTo({ top: Math.round(base + starts[k] + (over[k] ? ckLead + want : (k ? 1 : 0))), behavior: 'instant' });
  ckUpdate();
});
SK.forEach((a, i) => a.addEventListener('keydown', e => {      // arrows walk the key row, like a tab list
  const d = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
  if (!d) return;
  e.preventDefault();
  const n = (i + d + SK.length) % SK.length;
  rove(n); SK[n].focus();
}));
SK.forEach((a, i) => a.addEventListener('click', () => rove(i)));
rove(0);

/* observers */
const ioSeen = new IntersectionObserver(es => {
  for (const e of es) if (e.isIntersecting && !ckDesk && !e.target.classList.contains('seen')) stackWipe(e.target);
}, { rootMargin: '0px 0px -28% 0px' });
const ioVis = new IntersectionObserver(es => {
  for (const e of es) pgVis[PG.indexOf(e.target)] = e.isIntersecting;
  syncRun(); clSync();
}, { rootMargin: '5% 0px' });
const ioAct = new IntersectionObserver(es => {
  if (ckDesk) return;
  for (const e of es) if (e.isIntersecting) { cur = PG.indexOf(e.target); markCurrent(cur); clSync(); }
}, { rootMargin: '-45% 0px -54% 0px' });
PG.forEach(p => { ioSeen.observe(p); ioVis.observe(p); ioAct.observe(p); });
new IntersectionObserver(es => {
  for (const e of es) ckLive = e.isIntersecting;
  ck.classList.toggle('inview', ckLive);
  if (!ckLive && !ckDesk) markCurrent(-1);
  if (ckLive && ckDesk && cur >= 0) seePage(cur);
  syncRun(); clSync();
}, { rootMargin: '0px 0px -22% 0px' }).observe(ck);
/* power-up: the first time the display rises into view, the dark glass comes on with one slow sweep */
function powerUp(sweep) {
  if (ckPowered) return;
  ckPowered = true;
  ck.classList.add('powered');
  if (cur >= 0) { seePage(cur); if (sweep) wipe(PG[cur], null, 1, 560); }
}
new IntersectionObserver(es => {
  for (const e of es) {
    if (!ckDesk || ckPowered || e.intersectionRatio < 0.4) continue;
    powerUp(true);
  }
}, { threshold: [0, 0.4] }).observe(ckFrame);

/* layout mode: desktop display or stacked pages, live on resize and rotation */
function applyCkMode() {
  const desk = CK_GATE.matches;
  if (desk !== ckDesk) {
    ckDesk = desk;
    finishWipe();
    ck.classList.toggle('ck-desk', desk);
    ck.classList.toggle('ck-stack', !desk);
    cur = -1;
    PG.forEach((p, i) => { p.inert = false; p.classList.remove('on', 'out'); PG_IN[i].style.transform = ''; lastT[i] = 0; });
    if (desk) {
      ckMeasure(); ckUpdate();
      const r = ckFrame.getBoundingClientRect();   // switched into desktop with the display already on screen: no threshold will fire
      if (r.top < innerHeight * 0.6 && r.bottom > innerHeight * 0.4) powerUp(false);
    }
    else {
      ckTrack.style.height = '';
      PG.forEach(p => { ioSeen.unobserve(p); ioSeen.observe(p); ioAct.unobserve(p); ioAct.observe(p); });
    }
  } else if (desk) { ckMeasure(); ckUpdate(); }
  syncRun(); clSync();
}
let ckRO = null, roRaf = null;
function ckStart() {
  applyCkMode();
  CK_GATE.addEventListener('change', applyCkMode);
  addEventListener('resize', () => { if (ckDesk) { ckMeasure(); ckKick(); } }, { passive: true });
  addEventListener('scroll', () => {
    if (jumping) { clearTimeout(jumpT); jumpT = setTimeout(endJump, 140); }
    if (ckDesk) ckKick();
  }, { passive: true });
  if ('onscrollend' in window) addEventListener('scrollend', () => { if (jumping) endJump(); });
  if (window.ResizeObserver) {          // content that grows (a leg opened, a chat reply, the logon result) re-cuts the ranges
    ckRO = new ResizeObserver(() => { if (!ckDesk || roRaf !== null) return; roRaf = requestAnimationFrame(() => { roRaf = null; ckMeasure(); ckUpdate(); }); });
    PG_IN.forEach(el => ckRO.observe(el));
    PG_VIEW.forEach(el => ckRO.observe(el));
  }
  const fromHash = () => { const k = PG_IDS.indexOf(location.hash.slice(1)); if (k >= 0 && ckDesk) goPage(k, false); };
  addEventListener('hashchange', fromHash);
  // the browser's own fragment scroll lands on the sticky frame (every page sits there): correct it once layout has settled
  const atLoad = () => { if (ckDesk) { ckMeasure(); ckUpdate(); } setTimeout(fromHash, 60); };
  if (document.readyState === 'complete') atLoad(); else addEventListener('load', atLoad, { once: true });
}

/* ---------- reduced motion: pin final states, and undo them ---------- */
let pinned = false;
function pinToFinalStates() {
  pinned = true;
  disableScrub();
  land();                                  // hold module complete, drives stopped
  powerUp(false);
  PG.forEach((p, i) => seePage(i));        // gauges at their values, lines drawn, pages populated
  finishWipe();
  clPin();                                 // checklist complete
}
function unpinFinalStates() {
  if (!pinned) return;
  pinned = false;
  resetFly();
  fly.classList.remove('touched');
  setStatus(T.flyIdle);
  clUnpin();
}
RM.addEventListener('change', e => { if (e.matches) pinToFinalStates(); else { unpinFinalStates(); applyHeroMode(); } });

/* ============ LOGON: runway lights in the synthetic view (generated once) ============ */
(function buildRunway() {
  const ns = 'http://www.w3.org/2000/svg';
  const edges = $('#rw-edges'), bars = $('#rw-bars');
  const CX = 500, VPY = -200, HALF = 500, NEAR = 640;  // vanishing point above the box; half-width 500 at y = 440
  const sc = y => (y - VPY) / NEAR;
  const dot = (g, x, y, r, cls) => { const c = document.createElementNS(ns, 'circle'); c.setAttribute('cx', x.toFixed(1)); c.setAttribute('cy', y.toFixed(1)); c.setAttribute('r', r.toFixed(2)); c.setAttribute('class', cls); g.appendChild(c); return c; };
  for (const y of [300, 232, 180, 140, 108, 82, 60, 42, 27, 14, 3]) {
    const s = sc(y);
    for (const side of [-1, 1]) dot(edges, CX + side * (HALF * s + 6 * s), y, 1.1 + 3.4 * s, 'edge');
  }
  const th = sc(300);
  for (let j = 0; j <= 16; j++) dot(edges, CX - HALF * th + j * (2 * HALF * th / 16), 300, 1.2 + 3 * th, 'thl');
  [420, 380, 348, 322].forEach((y, i) => {          // nearest bar first: the rabbit runs toward the threshold
    const s = sc(y);
    const n = y === 380 ? 11 : 5, gap = (y === 380 ? 26 : 13) * s;
    for (let j = 0; j < n; j++) dot(bars, CX + (j - (n - 1) / 2) * gap, y, 1.2 + 2.6 * s, 'bar');
    dot(bars, CX, y, 3 + 3.5 * s, 'rab').style.setProperty('--r', i);
  });
})();

/* ============ DATALINK: the AI shelf as a menu (from the inline JSON): one tab per shelf, one line per tool ============ */
(function renderShelves() {
  const host = $('#shelves');
  const note = $('.shelf-note', host);
  let data;
  try { data = JSON.parse($('#shelf-data').textContent); } catch (_) { return; }
  const mk = (tag, cls, text) => { const el = document.createElement(tag); if (cls) el.className = cls; if (text != null) el.textContent = text; return el; };
  const list = mk('div', 'tabs');
  list.setAttribute('role', 'tablist');
  list.setAttribute('aria-label', 'AI 超市货架');
  const tabs = [], panels = [];
  data.shelves.forEach((shelf, i) => {
    const tab = mk('button', 'tab');
    tab.type = 'button';
    tab.id = 'tab-' + shelf.id;
    tab.setAttribute('role', 'tab');
    tab.setAttribute('aria-controls', 'shelf-' + shelf.id);
    tab.append(mk('b', null, 'A' + (i + 1)), mk('span', null, shelf.title));
    const pnl = mk('section', 'shelf');
    pnl.id = 'shelf-' + shelf.id;
    pnl.setAttribute('role', 'tabpanel');
    pnl.setAttribute('aria-labelledby', tab.id);
    const h = mk('h3', 'sr', shelf.title);
    pnl.append(h, mk('p', 'shelf-blurb', shelf.blurb));
    const ul = mk('ul', 'lines');
    shelf.tools.forEach((tool, j) => {
      const li = mk('li', 'tool');
      li.append(mk('span', 'ln-i', String(j + 1).padStart(2, '0')));
      const top = mk('div', 'ln-top');
      top.append(mk('h4', null, tool.name));
      if (tool.cn_access === '能直接访问' || tool.cn_access === '需要国际网络') top.append(mk('span', 'badge' + (tool.cn_access === '能直接访问' ? ' ok' : ''), tool.cn_access));
      li.append(top, mk('p', 'desc', tool.desc));
      if (tool.note) li.append(mk('p', 'note', tool.note));
      const a = mk('a', 'open');
      a.href = tool.url; a.target = '_blank'; a.rel = 'noopener noreferrer';
      let host2 = tool.url;
      try { host2 = new URL(tool.url).hostname.replace(/^www\./, ''); } catch (_) { /* keep raw */ }
      a.append(document.createTextNode('打开'), mk('span', 'dom', host2));
      li.append(a);
      ul.append(li);
    });
    pnl.append(ul);
    tabs.push(tab); panels.push(pnl);
    list.append(tab);
  });
  host.insertBefore(list, note);
  panels.forEach(p => host.insertBefore(p, note));
  function select(i, focus) {
    tabs.forEach((t, j) => {
      const on = j === i;
      t.setAttribute('aria-selected', String(on));
      t.tabIndex = on ? 0 : -1;
      panels[j].hidden = !on;
    });
    if (focus) tabs[i].focus();
    const t = tabs[i], L = list;              // keep the selected tab in the swipe strip's view
    if (t.offsetLeft < L.scrollLeft || t.offsetLeft + t.offsetWidth > L.scrollLeft + L.clientWidth) L.scrollTo({ left: t.offsetLeft - 16, behavior: RM.matches ? 'auto' : 'smooth' });
  }
  tabs.forEach((t, i) => {
    t.addEventListener('click', () => select(i, false));
    t.addEventListener('keydown', e => {
      const n = tabs.length;
      const k = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: n - 1 }[e.key];
      if (k === undefined) return;
      e.preventDefault();
      select((k + n) % n, true);
    });
  });
  select(0, false);
})();

/* ============ DATALINK: AI assistant as a CPDLC window (front end only, honest; same api/chat.php contract) ============ */
const chatLog = $('#chat-log');
const chatForm = $('#chat-form');
const chatIn = $('#chat-in');
const chatSend = $('#chat-send');
const lamp = $('#lamp'), lampCode = $('#lamp-code'), lampTxt = $('#lamp-txt');
const history = [];
let chatPending = false;
const restingLamp = () => (CHAT_LIVE ? 'stby' : 'inop');
const utcNow = () => { const d = new Date(); return String(d.getUTCHours()).padStart(2, '0') + String(d.getUTCMinutes()).padStart(2, '0') + 'Z'; };
function setLamp(state) {
  if (lamp.dataset.state === state) return;
  lamp.dataset.state = state;
  lampCode.textContent = LAMP[state][0];
  lampTxt.textContent = LAMP[state][1];
}
function bubble(role, text, opts = {}) {
  const d = document.createElement('div');
  d.className = 'msg ' + role + (opts.sr ? ' sr' : '');
  if (!opts.sr) {                       // CPDLC heading: the assistant uplinks, the reader downlinks
    const h = document.createElement('span');
    h.className = 'msg-h'; h.setAttribute('aria-hidden', 'true');
    h.textContent = (role === 'user' ? 'DNLINK · ' : 'UPLINK · ') + utcNow();
    d.append(h);
    if (role === 'assistant' && chatLog.children.length) d.classList.add('new');   // a new uplink flashes twice, like the ATC MSG light
  }
  const p = document.createElement('p');
  if (opts.inop) { const pl = document.createElement('span'); pl.className = 'placard'; pl.textContent = 'INOP'; pl.setAttribute('aria-hidden', 'true'); p.append(pl); }
  p.append(document.createTextNode(text));
  d.append(p);
  chatLog.append(d);
  if (role === 'assistant' && !opts.sr && d.offsetHeight > chatLog.clientHeight - 24) {
    // a reply taller than the log opens at its first line, not its last
    chatLog.scrollTop += d.getBoundingClientRect().top - chatLog.getBoundingClientRect().top - 12;
  } else {
    chatLog.scrollTop = chatLog.scrollHeight;
  }
  return d;
}
setLamp(restingLamp());
bubble('assistant', CHAT_LIVE ? T.welcomeLive : T.welcomeInop);
const chatLimit = $('#chat-limit');
const chips = $$('#chips button');
let limitShown = false;
function setLimit(on) { if (on !== limitShown) { chatLimit.textContent = on ? T.chatLimit : ''; limitShown = on; } }
chatIn.addEventListener('input', () => setLimit(chatIn.value.length >= 500));   // maxlength cuts pastes silently; say so
function setPending(on) {
  chatPending = on;
  // aria-disabled plus the chatPending guard, never the disabled attribute: disabling the focused 发送 button drops focus to <body>
  chatSend.setAttribute('aria-disabled', String(on));
  chatSend.textContent = on ? T.sending : T.send;
  chips.forEach(b => b.setAttribute('aria-disabled', String(on)));
}
async function sendChat(raw) {
  if (chatPending) return;
  const text = String(raw || '').trim().slice(0, 500);
  if (!text) return;
  setPending(true);
  bubble('user', text);
  const turn = { role: 'user', content: text };   // joins the history only together with its answer, so a failed turn is never re-sent
  chatIn.value = ''; setLimit(false);
  setLamp('tx');
  const srNote = bubble('assistant', T.answering, { sr: true });
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), 60000);   // generous: a long answer can take tens of seconds server-side
  let state = restingLamp();
  try {
    const res = await fetch(CHAT_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      // the last 11 messages always start on a user turn; api/chat.php keeps up to 12
      body: JSON.stringify({ messages: history.concat([turn]).slice(-11) }),
      signal: ctrl.signal
    });
    if (!res.ok) {
      // api/chat.php answers errors as {error, message}; message is user-facing Chinese and wins when present.
      let err = null;
      try { err = await res.json(); } catch (_) { err = null; }
      const msg = err && typeof err.message === 'string' ? err.message.trim() : '';
      const notConfigured = res.status === 503 || (err && err.error === 'not_configured');
      if (msg) {
        bubble('assistant', msg, { inop: notConfigured });
        if (notConfigured) state = 'inop';
      } else if (res.status === 429) { bubble('assistant', T.chatRate); }
      else if (res.status === 404 || res.status === 405) { bubble('assistant', T.chatInop, { inop: true }); state = 'inop'; }   // the endpoint is not there yet
      else { bubble('assistant', T.chatDown); }   // a host 5xx page or a PHP fatal: transient, not INOP
    }
    else {
      let data = null;
      try { data = await res.json(); } catch (_) { data = null; }
      if (data && typeof data.reply === 'string' && data.reply.trim()) {
        bubble('assistant', data.reply);
        history.push(turn, { role: 'assistant', content: data.reply });
        state = 'on';
      } else { bubble('assistant', T.chatInop, { inop: true }); state = 'inop'; }   // 200 without JSON: the host serves chat.php as a file (no PHP)
    }
  } catch (err) {
    if (err && err.name === 'AbortError') bubble('assistant', T.chatTimeout);
    else bubble('assistant', T.chatDown);   // offline, or the connection dropped
  } finally {
    clearTimeout(timer);
    srNote.remove();
    setPending(false);
    setLamp(state);
  }
}
chatForm.addEventListener('submit', e => { e.preventDefault(); sendChat(chatIn.value); });
chatIn.addEventListener('keydown', e => {
  if (e.key !== 'Enter' || e.shiftKey) return;
  if (e.isComposing || e.keyCode === 229) return;       // IME is composing: Enter confirms pinyin, never sends
  e.preventDefault();
  sendChat(chatIn.value);
});
chips.forEach(b => b.addEventListener('click', () => sendChat(b.textContent)));

/* ============ LOGON: subscribe (Mode A: nothing stored or sent) ============ */
const subForm = $('#sub-form');
const subEmail = $('#sub-email');
const subErr = $('#sub-err');
const subBtn = $('#sub-btn');
const subDone = $('#sub-done');
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
if (SUBSCRIBE_ENDPOINT) $$('.mode-a').forEach(el => el.remove());
function subError(msg) {
  subErr.textContent = msg;
  subEmail.setAttribute('aria-invalid', msg ? 'true' : 'false');
}
function subShow(text, inop) {
  subDone.textContent = '';
  const p = document.createElement('p');
  if (inop) { const pl = document.createElement('span'); pl.className = 'placard'; pl.textContent = 'INOP'; pl.setAttribute('aria-hidden', 'true'); p.append(pl); }
  p.append(document.createTextNode(text));
  subDone.append(p);
  subForm.hidden = true;
  subDone.hidden = false;
  subDone.focus({ preventScroll: true });
}
subEmail.addEventListener('keydown', e => { if (e.key === 'Enter' && (e.isComposing || e.keyCode === 229)) e.preventDefault(); });
subEmail.addEventListener('input', () => { if (subErr.textContent) subError(''); });
subForm.addEventListener('submit', async e => {
  e.preventDefault();
  const v = subEmail.value.trim();
  if (!v) { subError(T.subEmpty); subEmail.focus(); return; }
  if (!EMAIL_RE.test(v)) { subError(T.subFormat); subEmail.focus(); return; }
  subError('');
  if (!SUBSCRIBE_ENDPOINT) { subShow(T.subModeA, true); return; }   // Mode A: nothing is stored or sent
  subBtn.disabled = true; subBtn.textContent = T.subBusy;
  try {
    const fd = new FormData(); fd.append('email', v);
    const res = await fetch(SUBSCRIBE_ENDPOINT, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
    if (!res.ok) throw new Error('http ' + res.status);
    subShow(T.subOkPre + v + T.subOkPost, false);
  } catch (_) {
    subError(T.subFail);
  } finally {
    subBtn.disabled = false; subBtn.textContent = '订阅飞行日志';
  }
});
