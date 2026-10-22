(() => {
'use strict';
const $ = s => document.getElementById(s);
const root = $('ils'), stage = $('ils-stage'), vid = $('ils-v'), cv = $('ils-fx'), ctx = cv.getContext('2d');
const hud = $('ils-hud'), altEl = $('ils-alt'), wxEl = $('ils-wx'), dloc = $('ils-dloc'), dgs = $('ils-dgs');
const callEl = $('ils-call'), hintEl = $('ils-hint'), stick = $('ils-stick'), knob = $('ils-knob');
const decEl = $('ils-dec'), tmEl = $('ils-tm'), briefEl = $('ils-brief'), resEl = $('ils-result'), boardEl = $('ils-board');
const tutEl = $('ils-tut'), flareEl = $('ils-flare'), goBtn = $('ils-go');
const API = root.dataset.api || 'api/game.php';
const store = { get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }, set(k, v) { try { localStorage.setItem(k, v); } catch (e) {} } };

// footage: video time (s) -> radio altitude (ft), read off the callouts in the source
const ALT_MAP = [[0, 300], [8.2, 200], [16.9, 100], [21.6, 50], [23, 40], [24.5, 30], [26.2, 20], [29.2, 0]];
const T_DA = 8.2, T_TD = 29.2, ALT_START = 1000, CLOUD_FPS = 88;   // above 300 ft the footage holds its first frame under cloud (~8 s)
const DEC_MS = 2500;                                               // real time to decide at DA (footage runs slow meanwhile)
const FLARE_ALT = 45, FLARE_BEST = 22;                             // the flare prompt shows from 45 ft; 22 ft is right
const MODES = { ranked: { wind: 1, turb: 1, sens: 1 }, practice: { wind: 0.45, turb: 0.5, sens: 0.75 } };
let mode = MODES[store.get('ils-mode')] ? store.get('ils-mode') : 'ranked';

function altAt(t) {
  if (t <= 0) return 300;
  for (let i = 1; i < ALT_MAP.length; i++) {
    const [t1, a1] = ALT_MAP[i], [t0, a0] = ALT_MAP[i - 1];
    if (t <= t1) return a0 + (a1 - a0) * (t - t0) / (t1 - t0);
  }
  return 0;
}
const clamp = (x, a, b) => Math.max(a, Math.min(b, x));
const lerp = (a, b, k) => a + (b - a) * k;
const rnd = (a, b) => a + Math.random() * (b - a);
const pad3 = n => String(n).padStart(3, '0');

/* ---------- weather: the server rolls it and signs the run; offline (preview) rolls it here ---------- */
let wx, run = null, code = null, online = true;
const chal = (() => { const q = new URLSearchParams(location.search); const c = q.get('ils'); if (!c || c.length > 200) return null;
  const g = (q.get('g') || '').slice(0, 1), sc = parseInt(q.get('s'), 10);
  return { code: c, name: (q.get('n') || '').slice(0, 12), grade: /^[SABC]$/.test(g) ? g : '', score: sc >= 0 && sc <= 100 ? sc : null }; })();
function localWx() {
  const below = Math.random() < 0.35;
  return {
    base: below ? Math.round(rnd(110, 185)) : Math.round(rnd(215, 295) / 5) * 5,
    rvr: Math.round(rnd(550, 1600) / 50) * 50, dir: Math.round(rnd(110, 250) / 10) * 10,
    spd: Math.round(rnd(6, 22)), turb: Math.round(rnd(0.35, 1) * 100) / 100
  };
}
function setWx(w) {
  wx = w;
  wx.xw = wx.spd * Math.sin((wx.dir - 160) * Math.PI / 180);   // runway 16 ≈ 160°; + = from the right, pushes the aircraft left
  $('ils-brief-wx').innerHTML = `<div><dt>CLOUD BASE</dt><dd>${wx.base} FT</dd></div><div><dt>RVR</dt><dd>${wx.rvr} M</dd></div><div><dt>WIND</dt><dd>${pad3(wx.dir)}/${wx.spd}KT</dd></div>`;
  wxEl.innerHTML = `CLOUD <b>${wx.base}</b> FT<br>RVR <b>${wx.rvr}</b> M<br>WIND <b>${pad3(wx.dir)}/${wx.spd}</b>`;
}
// $useChal: fly the friend's weather (their link's code) instead of a new roll
function rollWx(useChal) {
  run = null; code = null;
  if (!useChal) setWx(localWx());
  if (!online) return Promise.resolve();
  const c = useChal && chal ? '&c=' + encodeURIComponent(chal.code) : '';
  return fetch(API + '?a=start' + c, { credentials: 'same-origin', cache: 'no-store' }).then(r => r.json()).then(d => {
    if (d && d.ok && d.run && d.wx) { run = d.run; code = d.code || null; setWx(d.wx); }
  }).catch(() => { online = false; if (!wx) setWx(localWx()); });
}

/* ---------- sound: cabin drone, rain, middle marker, synthetic callouts (all WebAudio, started by a tap) ---------- */
const CALL_SPRITE = { c1000: [0, .82], c500: [1.17, .805], c100a: [2.325, .984], cmin: [3.659, .552], c100: [4.561, .682], c50: [5.593, .443], c40: [6.386, .382], c30: [7.119, .35], c20: [7.818, .407], c10: [8.575, .375], cga: [9.301, .652] };
const snd = { on: store.get('ils-snd') !== '0', ac: null, master: null, eng: null, osc: null, rain: null, mm: null, buf: null };
function noiseBuf(ac, brown) {
  const b = ac.createBuffer(1, ac.sampleRate * 2, ac.sampleRate), d = b.getChannelData(0);
  let l = 0;
  for (let i = 0; i < d.length; i++) { const w = Math.random() * 2 - 1; if (brown) { l = (l + 0.02 * w) / 1.02; d[i] = l * 3.5; } else d[i] = w; }
  return b;
}
function sndInit() {
  if (!snd.on) return;
  const AC = window.AudioContext || window.webkitAudioContext;
  if (!AC) return;
  if (!snd.ac) {
    const ac = snd.ac = new AC(), m = snd.master = ac.createGain(); m.gain.value = 0; m.connect(ac.destination);
    const loop = (buf, filt, f, g) => { const s = ac.createBufferSource(); s.buffer = buf; s.loop = true; const fl = ac.createBiquadFilter(); fl.type = filt; fl.frequency.value = f; const gn = ac.createGain(); gn.gain.value = g; s.connect(fl); fl.connect(gn); gn.connect(m); s.start(); return gn; };
    snd.eng = loop(noiseBuf(ac, true), 'lowpass', 420, 0.5);
    snd.rain = loop(noiseBuf(ac, false), 'highpass', 2200, 0);
    const o = snd.osc = ac.createOscillator(); o.type = 'sawtooth'; o.frequency.value = 86;
    const of = ac.createBiquadFilter(); of.type = 'lowpass'; of.frequency.value = 240; const og = ac.createGain(); og.gain.value = 0.05;
    o.connect(of); of.connect(og); og.connect(m); o.start();
    const mo = ac.createOscillator(); mo.frequency.value = 1300; const mg = snd.mm = ac.createGain(); mg.gain.value = 0; mo.connect(mg); mg.connect(m); mo.start();
    fetch(root.dataset.callouts || 'callouts.mp3').then(r => r.arrayBuffer()).then(a => new Promise((ok, no) => ac.decodeAudioData(a, ok, no))).then(b => { snd.buf = b; }).catch(() => {});
  }
  if (snd.ac.state === 'suspended') snd.ac.resume();
}
function sndLevel(v, sec) { if (snd.ac) { const t = snd.ac.currentTime; snd.master.gain.cancelScheduledValues(t); snd.master.gain.setTargetAtTime(v, t, sec || 0.3); } }
function sndSay(k) {
  if (!snd.ac || !snd.buf || !snd.on) return;
  const [o, d] = CALL_SPRITE[k], s = snd.ac.createBufferSource(), g = snd.ac.createGain(); g.gain.value = 1.4;
  s.buffer = snd.buf; s.connect(g); g.connect(snd.master); s.start(0, o + 0.02, d + 0.06);
}
function sndMarker() {   // middle marker: amber, dot-dash at 1300 Hz, for a couple of seconds around DA
  if (!snd.ac) return;
  const g = snd.mm.gain, t0 = snd.ac.currentTime;
  for (let t = 0; t < 2.6; t += 0.5) { g.setValueAtTime(0.06, t0 + t); g.setValueAtTime(0, t0 + t + 0.09); g.setValueAtTime(0.06, t0 + t + 0.18); g.setValueAtTime(0, t0 + t + 0.42); }
}
function sndThump(v) {
  if (!snd.ac) return;
  const ac = snd.ac, s = ac.createBufferSource(); s.buffer = noiseBuf(ac, true);
  const f = ac.createBiquadFilter(); f.type = 'lowpass'; f.frequency.value = 180; const g = ac.createGain(), t = ac.currentTime;
  g.gain.setValueAtTime(v || 2.2, t); g.gain.exponentialRampToValueAtTime(0.001, t + 0.6);
  s.connect(f); f.connect(g); g.connect(snd.master); s.start(t); s.stop(t + 0.7);
}
function sndPower(up) { if (snd.ac) { const t = snd.ac.currentTime; snd.osc.frequency.setTargetAtTime(up ? 112 : 86, t, 0.6); snd.eng.gain.setTargetAtTime(up ? 0.85 : 0.5, t, 0.6); } }
function sndToggle() {
  snd.on = !snd.on; store.set('ils-snd', snd.on ? '1' : '0'); paintSnd();
  if (snd.on) { sndInit(); if (phase !== 'brief' && phase !== 'done') sndLevel(0.9); } else sndLevel(0, 0.05);
}
function paintSnd() { $('ils-snd').setAttribute('aria-pressed', snd.on); $('ils-snd').textContent = snd.on ? '声音 开' : '声音 关'; $('ils-snd-hud').textContent = snd.on ? 'SOUND ON' : 'SOUND OFF'; }

/* ---------- state ---------- */
let phase = 'brief';      // brief | cloud | video | decide | land | ga | done
let alt = ALT_START, px = 0, py = 0, vx = 0, vy = 0, inX = 0, inY = 0, roll = 0, pitchOff = 0;
let tPhase = 0, decStart = 0, decision = null, samples = [], tdX = null, daX = 0, daY = 0, shouldLand = false, gaAlt = 0, flAlt = null, fogFloor = 0;
let last = 0, gustT = rnd(0, 100), callShown = '', callUntil = 0, hintGone = false, callIdx = 0, markerDone = false;
const keys = {};

function reset() {
  alt = ALT_START; px = rnd(-0.6, 0.6); py = rnd(-0.5, 0.5); vx = vy = inX = inY = roll = pitchOff = 0;
  decision = null; samples = []; tdX = null; flAlt = null; fogFloor = 0; flareEl.hidden = true; callShown = ''; callIdx = 0; markerDone = false; hintGone = false; hintEl.style.opacity = 1;
  tilt.x0 = null; vid.pause(); vid.playbackRate = 1; try { vid.currentTime = 0; } catch (e) {}
}

/* ---------- input 1: drag like a joystick (the finger's offset from where it went down) ---------- */
let drag = null;
stage.addEventListener('pointerdown', e => {
  if (!root.classList.contains('playing') || e.target.closest('button')) return;
  if (phase === 'land' && alt <= FLARE_ALT && flAlt === null) flare();
  const r = stage.getBoundingClientRect();
  drag = { id: e.pointerId, x: e.clientX, y: e.clientY };
  stage.setPointerCapture(e.pointerId);
  stick.style.left = (e.clientX - r.left) + 'px'; stick.style.top = (e.clientY - r.top) + 'px';
  stick.classList.add('on'); knob.style.transform = '';
});
stage.addEventListener('pointermove', e => {
  if (!drag || e.pointerId !== drag.id) return;
  const R = 48, dx = clamp(e.clientX - drag.x, -R, R), dy = clamp(e.clientY - drag.y, -R, R);
  inX = dx / R; inY = -dy / R;
  knob.style.transform = `translate(${dx}px,${dy}px)`;
  if (!hintGone) { hintGone = true; hintEl.style.opacity = 0; }
});
const endDrag = e => { if (drag && e.pointerId === drag.id) { drag = null; inX = inY = 0; stick.classList.remove('on'); } };
stage.addEventListener('pointerup', endDrag); stage.addEventListener('pointercancel', endDrag);
addEventListener('keydown', e => {
  if (!root.classList.contains('playing')) return;
  if (e.key.startsWith('Arrow')) { keys[e.key] = 1; e.preventDefault(); }
  if (e.key === ' ') { e.preventDefault(); if (phase === 'land' && alt <= FLARE_ALT && flAlt === null) flare(); }
});
addEventListener('keyup', e => { delete keys[e.key]; });

/* ---------- input 2: tilt the phone (the attitude at the start of the run is neutral) ---------- */
const coarse = matchMedia('(pointer: coarse)').matches && 'DeviceOrientationEvent' in window;
const tilt = { mode: coarse && store.get('ils-ctl') === 'tilt', x0: null, y0: null, x: 0, y: 0, seen: false };
function tiltAxes(e) {
  const a = (screen.orientation && screen.orientation.angle) || window.orientation || 0;
  if (a === 90) return [e.beta, -e.gamma];
  if (a === -90 || a === 270) return [-e.beta, e.gamma];
  return [e.gamma, e.beta];
}
addEventListener('deviceorientation', e => {
  if (!tilt.mode || e.gamma == null || e.beta == null) return;
  tilt.seen = true;
  const [x, y] = tiltAxes(e);
  if (tilt.x0 === null) { tilt.x0 = x; tilt.y0 = y; }
  const dz = v => Math.sign(v) * Math.max(0, Math.abs(v) - 2);       // 2° dead zone, full deflection at 18°
  tilt.x = clamp(dz(x - tilt.x0) / 16, -1, 1); tilt.y = clamp(dz(y - tilt.y0) / 16, -1, 1);
});
function setCtl(m) {
  tilt.mode = m === 'tilt'; store.set('ils-ctl', m);
  document.querySelectorAll('#ils-ctl button').forEach(b => b.setAttribute('aria-pressed', b.dataset.ctl === m));
}
// iOS asks for motion access, and only from inside a tap: call this first thing in the start handler
function tiltPermission() {
  const D = window.DeviceOrientationEvent;
  if (!tilt.mode || !D || typeof D.requestPermission !== 'function') return Promise.resolve(true);
  return D.requestPermission().then(r => r === 'granted').catch(() => false);
}

/* ---------- fog, cloud and rain (canvas) ---------- */
let W = 0, H = 0, puff = null;
function resize() {
  const dpr = Math.min(devicePixelRatio || 1, 2);
  W = stage.clientWidth; H = stage.clientHeight;
  cv.width = Math.round(W * dpr); cv.height = Math.round(H * dpr);
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
}
function makePuff() {
  const c = document.createElement('canvas'); c.width = c.height = 128;
  const g = c.getContext('2d'), gr = g.createRadialGradient(64, 64, 0, 64, 64, 64);
  gr.addColorStop(0, 'rgba(120,130,150,.55)'); gr.addColorStop(.5, 'rgba(96,106,126,.28)'); gr.addColorStop(1, 'rgba(80,90,110,0)');
  g.fillStyle = gr; g.fillRect(0, 0, 128, 128); return c;
}
const PUFFS = [], DROPS = [];
function seedFx() {
  for (let i = 0; i < 46; i++) PUFFS.push({ x: rnd(-1.6, 1.6), y: rnd(-1, 1), z: rnd(0.15, 3), s: rnd(0.5, 1.2) });
  for (let i = 0; i < 140; i++) DROPS.push({ x: rnd(-1, 1), y: rnd(-1, 1), z: rnd(0.2, 2) });
}
// how much of the scene the weather hides at this altitude: 1 = solid cloud
function fogAt(a) {
  const clear = lerp(0.08, 0.42, clamp((1600 - wx.rvr) / 1050, 0, 1));   // haze under the base, from the RVR
  if (a >= wx.base + 40) return 1;
  if (a <= wx.base - 15) return clear;
  return lerp(clear, 1, clamp((a - (wx.base - 15)) / 55, 0, 1));
}
let fxClear = false;   // the brief card covers the stage: clear the canvas once, not on every frame (no repaint while idle)
function drawFx(dt, speed) {
  if (phase === 'brief') { if (!fxClear) { ctx.clearRect(0, 0, W, H); fxClear = true; } return; }
  fxClear = false;
  ctx.clearRect(0, 0, W, H);
  const fog = Math.max(fogAt(alt), fogFloor), cx = W / 2, cy = H * 0.45, f = Math.max(W, H) * 0.5;
  ctx.fillStyle = `rgba(38,46,62,${fog * 0.9})`; ctx.fillRect(0, 0, W, H);
  const pa = clamp((fog - 0.5) / 0.5, 0, 1);   // cloud puffs streaming past: thick in cloud, thinning out through the base
  if (pa > 0.01) {
    for (const p of PUFFS) {
      p.z -= dt * speed * 0.9;
      if (p.z < 0.12) { p.z += 3; p.x = rnd(-1.6, 1.6); p.y = rnd(-1, 1); }
      const sx = cx + p.x / p.z * f * 0.5, sy = cy + p.y / p.z * f * 0.5, sz = p.s / p.z * f * 0.6;
      const a = pa * clamp(p.z < 0.5 ? (p.z - 0.12) / 0.38 : (3 - p.z) / 1.5, 0, 1);
      if (a <= 0) continue;
      ctx.globalAlpha = a; ctx.drawImage(puff, sx - sz, sy - sz, sz * 2, sz * 2);
    }
    ctx.globalAlpha = 1;
  }
  ctx.strokeStyle = 'rgba(200,210,230,.28)'; ctx.lineWidth = 1; ctx.beginPath();
  for (const d of DROPS) {
    const z0 = d.z; d.z -= dt * speed * 1.6;
    if (d.z < 0.15) { d.z += 2; d.x = rnd(-1, 1); d.y = rnd(-1, 1); continue; }
    const k = f * 0.35;
    ctx.moveTo(cx + d.x / z0 * k, cy + d.y / z0 * k); ctx.lineTo(cx + d.x / d.z * k, cy + d.y / d.z * k);
  }
  ctx.stroke();
  if (snd.rain) snd.rain.gain.value = 0.05 + fog * 0.12;
}

/* ---------- HUD ---------- */
function callout(s, ms, warn, say) {
  if (say) sndSay(say);
  if (callShown === s) return;
  callShown = s; callEl.textContent = s; callEl.classList.toggle('warn', !!warn); callEl.classList.add('on'); callUntil = performance.now() + (ms || 1400);
}
const CALLS = [[1000, '1000', 'c1000'], [500, '500', 'c500'], [300, '100 ABOVE', 'c100a'], [100, '100', 'c100'], [50, '50', 'c50'], [40, '40', 'c40'], [30, '30', 'c30'], [20, '20', 'c20'], [10, '10', 'c10']];
function hudTick(now) {
  altEl.textContent = Math.max(0, Math.round(alt / 10) * 10);
  dloc.style.transform = `translateX(${(clamp(-px, -2.2, 2.2) * 0.25 * dloc.parentNode.clientWidth).toFixed(1)}px) rotate(45deg)`;
  dgs.style.transform = `translateY(${(clamp(py, -2.2, 2.2) * 0.25 * dgs.parentNode.clientHeight).toFixed(1)}px) rotate(45deg)`;
  while (callIdx < CALLS.length && alt <= CALLS[callIdx][0]) { if (phase !== 'ga') callout(CALLS[callIdx][1], 0, false, CALLS[callIdx][2]); callIdx++; }
  if (!markerDone && alt <= 260 && phase !== 'ga') { markerDone = true; sndMarker(); }
  if (callUntil && now > callUntil) { callEl.classList.remove('on'); callUntil = 0; }
}

/* ---------- the view answers the controls: off the beam, the runway slides off centre ---------- */
// Off the localizer the ground shears about the horizon (near things move, the horizon stays); above the
// glideslope the ground stretches away from the horizon, below it flattens toward it.
function applyView() {
  const sk = clamp(-px * 6, -10, 10), sy = 1.26 * (1 + clamp(py, -2.6, 2.6) * 0.07);
  vid.style.transform = `translate(${(-px * W * 0.012).toFixed(1)}px,${pitchOff.toFixed(1)}px) rotate(${roll.toFixed(2)}deg) skewX(${sk.toFixed(2)}deg) scale(1.26,${sy.toFixed(4)})`;
}

/* ---------- flight model (in ILS dots; sensitivity grows as the beam narrows) ---------- */
function fly(dt) {
  gustT += dt;
  const m = MODES[mode], sens = (1 + clamp((1000 - alt) / 500, 0, 1.4)) * m.sens;
  const kx = (keys.ArrowRight ? 1 : 0) - (keys.ArrowLeft ? 1 : 0), ky = (keys.ArrowUp ? 1 : 0) - (keys.ArrowDown ? 1 : 0);
  const tx = tilt.mode ? tilt.x : 0, ty = tilt.mode ? tilt.y : 0;
  const ix = clamp(inX + kx + tx, -1, 1), iy = clamp(inY + ky + ty, -1, 1);
  if (!hintGone && (Math.abs(tx) > 0.3 || Math.abs(ty) > 0.3 || kx || ky)) { hintGone = true; hintEl.style.opacity = 0; }
  const gx = wx.turb * m.turb * (Math.sin(gustT * 0.9) * 0.5 + Math.sin(gustT * 2.3 + 1.7) * 0.3 + Math.sin(gustT * 0.37 + 4) * 0.4);
  const gy = wx.turb * m.turb * (Math.sin(gustT * 1.3 + 2) * 0.35 + Math.sin(gustT * 3.1) * 0.2);
  const tvx = ix * 0.85 - wx.xw * m.wind * 0.022 + gx * 0.28, tvy = iy * 0.6 + gy * 0.25;
  vx = lerp(vx, tvx, clamp(dt * 1.8, 0, 1)); vy = lerp(vy, tvy, clamp(dt * 2.2, 0, 1));
  px = clamp(px + vx * dt * sens, -2.6, 2.6); py = clamp(py + vy * dt * sens, -2.6, 2.6);
  roll = lerp(roll, -ix * 4 + gx * 0.8, clamp(dt * 3, 0, 1));
}

/* ---------- main loop ---------- */
function loop(now) {
  requestAnimationFrame(loop);
  const dt = Math.min(0.05, (now - (last || now)) / 1000); last = now;
  let speed = 1;
  if (phase === 'tutor') {
    tPhase += dt; const k = (tPhase % 3.6) / 3.6, e = k < 0.15 ? 0 : k > 0.8 ? 1 : (k - 0.15) / 0.65;
    px = lerp(1.3, 0, e); py = lerp(-0.9, 0, e);
  } else if (phase === 'cloud') {
    alt -= CLOUD_FPS * dt; fly(dt); samples.push([px, py]);
    if (alt <= 300) {
      alt = 300;
      if (vidPlayable()) { phase = 'video'; vid.play().catch(() => {}); }
      else if (!hintGone) hintEl.textContent = '画面还在加载，先稳住仪表…';
    }
  } else if (phase === 'video') {
    alt = altAt(vid.currentTime); fly(dt); samples.push([px, py]);
    if (vid.currentTime >= T_DA) startDecision(now);
  } else if (phase === 'decide') {
    alt = altAt(vid.currentTime); fly(dt); speed = 0.35;
    const k = (now - decStart) / (DEC_MS * (mode === 'practice' ? 1.4 : 1)); tmEl.style.transform = `scaleX(${clamp(1 - k, 0, 1)})`;
    if (k >= 1) decide(null);
  } else if (phase === 'land') {
    alt = altAt(vid.currentTime); fly(dt);
    if (flAlt === null && alt <= FLARE_ALT) {
      flareEl.hidden = false;
      if (tilt.mode && tilt.y > 0.75) flare();          // a firm pull back on the phone flares too
    }
    if (flAlt !== null) pitchOff = lerp(pitchOff, H * 0.035, clamp(dt * 3, 0, 1));
    if (vid.currentTime >= T_TD - 0.05 || vid.ended) touchdown();
  } else if (phase === 'ga') {
    // TOGA: nose up, climb back into the cloud until the windscreen is white, then the instruments only
    tPhase += dt; alt = gaAlt + tPhase * 65; speed = 1.6;
    fogFloor = clamp((tPhase - 0.3) / 1.1, 0, 1);
    pitchOff = lerp(pitchOff, H * 0.22, clamp(dt * 2.2, 0, 1)); roll = lerp(roll, 0, dt * 2);
    if (tPhase > 1.6 && callShown === 'GO AROUND') callout('POSITIVE CLIMB', 1400);
    if (tPhase > 3.4) finish();
  }
  drawFx(dt, speed);
  if (phase !== 'brief' && phase !== 'done') { applyView(); if (phase !== 'tutor') hudTick(now); else altEl.textContent = '1000'; }
}

function startDecision(now) {
  phase = 'decide'; decStart = now; vid.playbackRate = 0.25;
  daX = px; daY = py;
  shouldLand = wx.base >= 200 && Math.abs(px) <= 1 && Math.abs(py) <= 1;
  callout('MINIMUMS', DEC_MS, true, 'cmin');
  decEl.hidden = false; tmEl.style.transform = 'scaleX(1)'; hintGone = true; hintEl.style.opacity = 0;
}
function decide(d) {
  if (phase !== 'decide') return;
  decision = d || 'none'; decEl.hidden = true; callEl.classList.remove('on'); callUntil = 0;
  if (d === 'land') { phase = 'land'; vid.playbackRate = 1; }
  else { phase = 'ga'; gaAlt = alt; tPhase = 0; vid.pause(); sndPower(true); callout('GO AROUND', 2600, true, 'cga'); }
}
function flare() {
  flAlt = alt; flareEl.hidden = true;
  callout(flAlt > 35 ? 'FLARE · 早了' : flAlt < 12 ? 'FLARE · 晚了' : 'FLARE', 1000, flAlt > 35 || flAlt < 12);
}
function touchdown() {
  tdX = px; vid.pause(); flareEl.hidden = true;
  const hard = flAlt === null || flAlt < 12;
  sndThump(hard ? 3.4 : 1.6);
  if (hard) { root.classList.remove('shake'); void root.offsetWidth; root.classList.add('shake'); }
  finish();
}

/* ---------- scoring (the server recomputes the same thing before anything goes on the board) ---------- */
let result = null;
function score() {
  const n = samples.length || 1;
  const rx = Math.sqrt(samples.reduce((s, p) => s + p[0] * p[0], 0) / n), ry = Math.sqrt(samples.reduce((s, p) => s + p[1] * p[1], 0) / n);
  const track = clamp(100 - (rx + ry) * 45, 0, 100);
  const byScore = s => s >= 92 ? 'S' : s >= 82 ? 'A' : s >= 68 ? 'B' : 'C';
  const vis = wx.base >= 200 ? `云底 ${wx.base} 英尺，决断高度上看得见进近灯。` : `云底 ${wx.base} 英尺，低于决断高度，200 英尺时什么也看不见。`;
  let g, s, h, p;
  if (decision === 'land' && !shouldLand) {
    g = 'F'; s = 0;
    h = wx.base < 200 ? '低于标准落地' : '不稳定进近还落地';
    p = wx.base < 200 ? vis + '这时候必须复飞。真实飞行里，这是事故链的开始。' : '决断高度上偏差超过一个点，应该复飞重来。';
  } else if (decision === 'none') {
    g = 'F'; s = 0; h = '没有决断'; p = '到了决断高度要立刻做决定。犹豫本身就是错误。';
  } else if (decision === 'ga' && shouldLand) {
    s = Math.round(track * 0.7); g = s >= 60 ? 'B' : 'C'; h = '复飞了，其实能落'; p = vis + '复飞永远安全，只是这一次可以落地。';
  } else if (decision === 'ga') {
    s = Math.round(40 + track * 0.6); g = byScore(s); h = '正确复飞'; p = vis + (wx.base >= 200 ? '但偏差太大，复飞是对的。' : '复飞是唯一正确的选择。');
  } else {
    const tdm = Math.abs(tdX || 0) * 9, td = clamp(100 - tdm * 6, 0, 100);    // ~9 m off the centreline per dot this close in
    const fl = flAlt === null ? 0 : Math.max(0, 100 - Math.abs(flAlt - FLARE_BEST) * 5);
    s = Math.round(20 + track * 0.4 + td * 0.2 + fl * 0.2); g = byScore(s);
    h = flAlt === null || flAlt < 12 ? '落地了，但是重着陆' : flAlt > 35 ? '落地了，平飘很长' : '安全落地';
    p = vis + `接地点偏离中线约 ${tdm.toFixed(1)} 米。` + (flAlt === null ? '没有拉平，直接砸在跑道上。' : `在 ${Math.round(flAlt)} 英尺拉平${Math.abs(flAlt - FLARE_BEST) <= 4 ? '，时机正好。' : '，最好在 20 英尺左右。'}`);
  }
  return { grade: g, score: s, h, p, rx, ry, fl: decision === 'land' && flAlt !== null ? Math.round(flAlt * 10) / 10 : null };
}
function finish() {
  phase = 'done'; callEl.classList.remove('on'); callUntil = 0; root.classList.remove('playing'); document.body.classList.remove('ils-on'); stick.classList.remove('on'); drag = null; sndPower(false); sndLevel(0, 0.8);
  result = score();
  const gEl = $('ils-grade');
  gEl.textContent = result.grade;
  gEl.style.color = result.grade === 'F' ? 'var(--warn)' : result.grade === 'S' || result.grade === 'A' ? 'var(--threshold)' : 'var(--accent)';
  $('ils-res-k').textContent = `SCORE ${result.score} · CLOUD ${wx.base} FT · RVR ${wx.rvr} M`;
  $('ils-res-h').textContent = result.h; $('ils-res-p').textContent = result.p;
  $('ils-res-dl').innerHTML = `<div><dt>LOC 偏差</dt><dd>${result.rx.toFixed(2)} DOT</dd></div><div><dt>G/S 偏差</dt><dd>${result.ry.toFixed(2)} DOT</dd></div><div><dt>决断</dt><dd>${decision === 'land' ? '落地' : decision === 'ga' ? '复飞' : '无'}</dd></div>`;
  const ranked = mode === 'ranked';
  $('ils-sub').hidden = !ranked || result.score <= 0 || boardOff;
  $('ils-msg').textContent = ranked ? '' : '练习模式不计入排行榜。';
  $('ils-dare').hidden = !code;
  $('ils-name').value = store.get('ils-name') || '';
  setTimeout(() => { resEl.hidden = false; }, 500);
}

/* ---------- leaderboard (server; a local stand-in when the page runs without one) ---------- */
let boardOff = false, boardTab = 'week', boardData = null, myName = '';
function localBoard() { try { return JSON.parse(store.get('ils-board') || '[]'); } catch (e) { return []; } }
function boardFromLocal() {
  const all = localBoard(), wk = Date.now() / 1000 - 7 * 86400;
  const top = list => { const best = {}; list.forEach(e => { if (!best[e.name] || e.score > best[e.name].score) best[e.name] = e; }); return Object.values(best).sort((a, b) => b.score - a.score || a.ts - b.ts).slice(0, 20); };
  return { week: top(all.filter(e => e.ts >= wk)), all: top(all) };
}
function loadBoard() {
  if (!online) { boardData = boardFromLocal(); return Promise.resolve(); }
  return fetch(API + '?a=board', { credentials: 'same-origin' }).then(r => r.json()).then(d => {
    if (d && d.off) { boardOff = true; boardData = { week: [], all: [] }; }
    else if (d && d.ok) boardData = d;
  }).catch(() => { online = false; boardData = boardFromLocal(); });
}
function paintBoard() {
  document.querySelectorAll('#ils-board [data-tab]').forEach(b => b.setAttribute('aria-pressed', b.dataset.tab === boardTab));
  const ol = $('ils-list'); ol.textContent = '';
  const rows = boardData ? boardData[boardTab] || [] : null;
  if (!rows || !rows.length) {
    const li = document.createElement('li'); li.className = 'empty';
    li.textContent = boardOff ? '排行榜暂时关闭。' : !rows ? '加载中…' : '还没有人上榜，来当第一个。';
    ol.appendChild(li); return;
  }
  rows.forEach((e, i) => {
    const li = document.createElement('li'); if (e.name === myName) li.className = 'me';
    [['r', i + 1], ['n', e.name], ['g', e.grade], ['s', e.score]].forEach(([c, v]) => { const sp = document.createElement('span'); sp.className = c; sp.textContent = v; li.appendChild(sp); });
    li.title = `CLOUD ${e.base} FT · RVR ${e.rvr} M${e.date ? ' · ' + e.date : ''}`;
    ol.appendChild(li);
  });
}
function openBoard() { boardEl.hidden = false; boardData = null; paintBoard(); loadBoard().then(paintBoard); }
$('ils-sub').addEventListener('submit', e => {
  e.preventDefault();
  const name = $('ils-name').value.trim().replace(/\s+/g, ' ');
  const msg = $('ils-msg');
  if (!name) { msg.textContent = '先写个昵称。'; return; }
  store.set('ils-name', name); myName = name;
  const body = { run, name, decision, rx: result.rx, ry: result.ry, dax: daX, day: daY, tdx: tdX, fl: result.fl };
  if (!online || !run) {
    const all = localBoard(); all.push({ name, score: result.score, grade: result.grade, base: wx.base, rvr: wx.rvr, ts: Date.now() / 1000 });
    store.set('ils-board', JSON.stringify(all.slice(-300)));
    $('ils-sub').hidden = true; msg.textContent = '已记到本机排行榜（预览模式）。'; openBoard(); return;
  }
  msg.textContent = '提交中…';
  fetch(API + '?a=submit', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
    .then(r => r.json()).then(d => {
      if (!d || !d.ok) { msg.textContent = (d && d.error) || '没提交上，稍后再试。'; return; }
      $('ils-sub').hidden = true; run = null;
      msg.textContent = d.rank_week ? `上榜了：本周第 ${d.rank_week} 名${d.rank_all ? '，总榜第 ' + d.rank_all + ' 名' : ''}。` : `已提交，${d.score} 分。本周前 20 还差一点。`;
      openBoard();
    }).catch(() => { msg.textContent = '网络不稳，没提交上。'; });
});

/* ---------- share card ---------- */
let qrLoad = null;
function loadQr() {
  if (window.qrcode) return Promise.resolve(window.qrcode);
  return qrLoad || (qrLoad = new Promise((ok, no) => {
    const sc = document.createElement('script'); sc.src = root.dataset.qr || 'qrcode.min.js';
    sc.onload = () => window.qrcode ? ok(window.qrcode) : no(new Error('qr')); sc.onerror = no; document.head.appendChild(sc);
  }));
}
function shareCard() {
  if (!code) { drawCard(null); return; }
  loadQr().then(q => { const qr = q(0, 'M'); qr.addData(dareUrl()); qr.make(); drawCard(qr); }, () => drawCard(null));
}
function drawCard(qr) {
  const c = document.createElement('canvas'); c.width = 1080; c.height = 1350;
  const g = c.getContext('2d');
  g.fillStyle = '#0A1733'; g.fillRect(0, 0, 1080, 1350);
  try {   // the frame the run ended on, cropped to the top of the card
    const vw = vid.videoWidth, vh = vid.videoHeight, sw = vh * 1080 / 760;
    g.drawImage(vid, (vw - sw) / 2, 0, sw, vh, 0, 0, 1080, 760);
    const fade = g.createLinearGradient(0, 420, 0, 760); fade.addColorStop(0, 'rgba(10,23,51,0)'); fade.addColorStop(1, 'rgba(10,23,51,1)');
    g.fillStyle = fade; g.fillRect(0, 420, 1080, 340);
  } catch (e) {}
  const mono = '"B612 Mono",Menlo,Consolas,monospace', cjk = '"PingFang SC","Noto Sans CJK SC","Microsoft YaHei",sans-serif';
  g.fillStyle = '#A9A8C0'; g.font = `400 26px ${mono}`; g.fillText('盲降挑战 · ILS APPROACH · NIGHT', 72, 820);
  g.fillStyle = result.grade === 'F' ? '#FF5A4F' : result.grade === 'S' || result.grade === 'A' ? '#4FD08A' : '#EA4C22';
  g.font = `700 220px ${mono}`; g.fillText(result.grade, 64, 1060);
  g.fillStyle = '#ECE9F1'; g.font = `600 ${result.h.length > 7 ? 46 : 56}px ${cjk}`; g.fillText(result.h, 330, 950);
  g.fillStyle = '#A9A8C0'; g.font = `400 30px ${mono}`;
  g.fillText(`SCORE ${result.score}`, 332, 1010);
  g.fillText(`CLOUD ${wx.base} FT · RVR ${wx.rvr} M`, 332, 1056);
  g.strokeStyle = '#2A3A66'; g.lineWidth = 2; g.beginPath(); g.moveTo(72, 1150); g.lineTo(1008, 1150); g.stroke();
  g.fillStyle = '#ECE9F1'; g.font = `600 40px ${cjk}`; g.fillText('破雾追光', 72, 1230);
  g.fillStyle = '#A9A8C0'; g.font = `400 26px ${cjk}`; g.fillText('PC-12 机长的飞行日志 · hxpilot.com', 72, 1280);
  if (qr) {   // the friend's link: same weather (WeChat reads it with a long press)
    const n = qr.getModuleCount(), cell = Math.floor(184 / (n + 4)), sz = cell * (n + 4), x0 = 1008 - sz, y0 = 880;
    g.fillStyle = '#fff'; g.fillRect(x0, y0, sz, sz); g.fillStyle = '#0A1733';
    for (let r = 0; r < n; r++) for (let k = 0; k < n; k++) if (qr.isDark(r, k)) g.fillRect(x0 + (k + 2) * cell, y0 + (r + 2) * cell, cell, cell);
    g.fillStyle = '#EA4C22'; g.font = `700 28px ${cjk}`; g.textAlign = 'right'; g.fillText('扫码 · 同样天气挑战我', 1008, 1230);
    g.fillStyle = '#A9A8C0'; g.font = `400 24px ${cjk}`; g.fillText('你敢来飞吗？', 1008, 1272);
  } else { g.fillStyle = '#EA4C22'; g.font = `700 30px ${cjk}`; g.textAlign = 'right'; g.fillText('你敢来飞吗？', 1008, 1230); }
  let url;
  try { url = c.toDataURL('image/jpeg', 0.9); } catch (e) { return; }
  const ov = document.createElement('div'); ov.className = 'ils-shot';
  ov.innerHTML = `<img alt="成绩卡" src="${url}"><p>长按图片保存，或发给朋友</p><div style="display:flex;gap:10px"><a class="btn btn-a" download="ils-${result.grade}.jpg" href="${url}">下载</a><button class="btn" type="button">关闭</button></div>`;
  ov.querySelector('button').onclick = () => ov.remove();
  ov.addEventListener('click', e => { if (e.target === ov) ov.remove(); });
  document.body.appendChild(ov);
}

/* ---------- footage loading: fetched whole (with progress) where that works, direct in WeChat ---------- */
// WeChat on iOS never plays a blob: video and ignores preload, so there the source is set directly and the
// download starts with the tap on 开始进近; the cloud phase hides it, and holds at 300 ft until the footage can play.
let vidState = 'idle';
function setGo(t, dis) { goBtn.textContent = t; goBtn.disabled = !!dis; }
function vidReady() { if (vidState !== 'ready') { vidState = 'ready'; setGo('开始进近'); } }
function loadVideo() {
  if (vidState !== 'idle') return;
  vidState = 'loading';
  const url = vid.dataset.src || '';
  const direct = () => { if (vid.getAttribute('src')) return; vid.preload = 'auto'; vid.src = url; try { vid.load(); } catch (e) {} vidReady(); };
  if (!url || url.startsWith('data:') || /MicroMessenger/i.test(navigator.userAgent) || !window.fetch || !window.ReadableStream || !window.AbortController) { direct(); return; }
  setGo('加载画面…', true);
  const ac = new AbortController(), give = setTimeout(() => { ac.abort(); direct(); }, 12000);
  fetch(url, { signal: ac.signal }).then(r => {
    if (!r.ok || !r.body) throw new Error('http ' + r.status);
    const total = +r.headers.get('content-length') || 0, rd = r.body.getReader(), parts = [];
    let got = 0;
    const pump = () => rd.read().then(({ done, value }) => {
      if (done) return new Blob(parts, { type: r.headers.get('content-type') || 'video/mp4' });
      parts.push(value); got += value.length;
      if (total) setGo(`加载画面 ${Math.min(99, Math.round(got / total * 100))}%`, true);
      return pump();
    });
    return pump();
  }).then(b => {
    clearTimeout(give);
    if (vid.getAttribute('src')) return;
    vid.preload = 'auto'; vid.src = URL.createObjectURL(b); vidReady();
  }).catch(() => { clearTimeout(give); direct(); });
}
function vidPlayable() { return vid.readyState >= 3 || (vid.readyState >= 2 && vid.buffered.length && vid.buffered.end(0) > 9); }

/* ---------- challenge a friend: a link that flies the same weather ---------- */
function dareUrl() {
  const q = new URLSearchParams({ ils: code });
  if (myName) q.set('n', myName);
  if (result && result.grade !== 'F') { q.set('g', result.grade); q.set('s', result.score); }
  return (root.dataset.share || location.origin + location.pathname) + '?' + q + '#' + (root.dataset.anchor || root.id);
}
function copyText(t) {
  if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(t);
  const ta = document.createElement('textarea'); ta.value = t; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
  document.body.appendChild(ta); ta.select(); const ok = document.execCommand('copy'); ta.remove();
  return ok ? Promise.resolve() : Promise.reject(new Error('copy'));
}
function dare() {
  myName = myName || ($('ils-name').value || '').trim().slice(0, 12);
  const url = dareUrl(), text = `我在云底 ${wx.base} 英尺的夜里盲降${result.grade === 'F' ? '翻车了' : '拿了 ' + result.grade}，同样的天气，你敢来飞吗？`;
  const msg = $('ils-msg');
  if (navigator.share && !/MicroMessenger/i.test(navigator.userAgent)) { navigator.share({ title: '盲降挑战', text, url }).catch(() => {}); return; }
  copyText(text + ' ' + url).then(() => { msg.textContent = '挑战链接已复制，发给朋友吧。'; }, () => { msg.textContent = '复制不了，长按复制：' + url; });
}
function paintChal() {
  const el = $('ils-chal');
  if (!chal || !online) { el.hidden = true; return; }
  el.hidden = false;
  el.textContent = `${chal.name || '朋友'}在这个天气${chal.grade ? '拿了 ' + chal.grade + (chal.score !== null ? `（${chal.score} 分）` : '') : '飞过一次'}。同样的天气，你来飞。`;
}

/* ---------- modes and the how-to ---------- */
function setMode(m) {
  mode = m; store.set('ils-mode', m);
  document.querySelectorAll('#ils-mode button').forEach(b => b.setAttribute('aria-pressed', b.dataset.mode === m));
}
function showTutor() {
  phase = 'tutor'; tPhase = 0; tutEl.hidden = false; hintEl.style.opacity = 0;
  $('ils-tut-how').textContent = tilt.mode ? '把手机往那个方向倾斜（开局时的姿势是中立位）。' : '按住屏幕往那个方向拖，像一个摇杆。';
  const r = stage.getBoundingClientRect();
  stick.style.left = r.width / 2 + 'px'; stick.style.top = r.height * 0.74 + 'px';
  stick.style.setProperty('--gx', '-30px'); stick.style.setProperty('--gy', '-24px');
  stick.classList.add('on', 'ghost');
}
function endTutor() {
  store.set('ils-tut', '1'); tutEl.hidden = true; stick.classList.remove('on', 'ghost');
  px = rnd(-0.6, 0.6); py = rnd(-0.5, 0.5); hintEl.style.opacity = 1; tilt.x0 = null;
  phase = 'cloud'; last = 0;
}

/* ---------- wiring ---------- */
let forceTut = false;
function start() {
  const perm = tiltPermission();     // must run inside the tap on iOS
  sndInit();
  if (vidState === 'idle') loadVideo();
  // iOS only plays muted video started from a gesture: prime it now, hold the first frame under the cloud
  const p = vid.play(); if (p && p.then) p.then(() => { if (phase === 'cloud' || phase === 'tutor') { vid.pause(); vid.currentTime = 0; } }).catch(() => {});
  reset(); briefEl.hidden = true; resEl.hidden = true; boardEl.hidden = true; hud.hidden = false; decEl.hidden = true;
  hintEl.textContent = tilt.mode ? '倾斜手机操纵：开局时的姿势就是中立位。把两个紫色菱形保持在中线上。' : '拖动屏幕操纵：往哪拖，飞机往哪去。把两个紫色菱形保持在中线上。';
  perm.then(ok => { if (!ok && tilt.mode) { setCtl('drag'); hintEl.textContent = '没拿到重力感应权限，改用拖动操纵。'; } });
  setTimeout(() => { if (tilt.mode && !tilt.seen) hintEl.textContent = '这台设备读不到重力感应，用拖动操纵。'; }, 1500);
  root.classList.add('playing'); document.body.classList.add('ils-on'); last = 0; sndPower(false); sndLevel(0.9, 0.5);
  // bring the whole stage into view, clear of a sticky page header (the page hides its own floating chrome meanwhile)
  const r = stage.getBoundingClientRect(), top = 76;
  if (r.top < top || r.bottom > innerHeight) window.scrollBy({ top: r.top - Math.max(top, (innerHeight - r.height) / 2), behavior: 'smooth' });
  if (forceTut || !store.get('ils-tut')) { forceTut = false; showTutor(); } else phase = 'cloud';
}
function toBrief() { resEl.hidden = true; boardEl.hidden = true; briefEl.hidden = false; hud.hidden = true; phase = 'brief'; reset(); applyView(); $('ils-chal').hidden = true; rollWx(); }
goBtn.onclick = start;
$('ils-reroll').onclick = () => { $('ils-chal').hidden = true; rollWx(); };
$('ils-again').onclick = toBrief;
$('ils-how').onclick = () => { forceTut = true; start(); };
$('ils-tut-go').onclick = endTutor;
$('ils-land').onclick = () => decide('land');
$('ils-ga').onclick = () => decide('ga');
$('ils-card-btn').onclick = shareCard;
$('ils-dare').onclick = dare;
$('ils-snd').onclick = sndToggle; $('ils-snd-hud').onclick = sndToggle;
document.querySelectorAll('[data-board]').forEach(b => { b.onclick = openBoard; });
document.querySelectorAll('#ils-board [data-tab]').forEach(b => { b.onclick = () => { boardTab = b.dataset.tab; paintBoard(); }; });
$('ils-board-close').onclick = () => { boardEl.hidden = true; };
document.querySelectorAll('#ils-ctl button').forEach(b => { b.onclick = () => setCtl(b.dataset.ctl); });
document.querySelectorAll('#ils-mode button').forEach(b => { b.onclick = () => setMode(b.dataset.mode); });
if (coarse) { $('ils-ctl').hidden = false; setCtl(tilt.mode ? 'tilt' : 'drag'); }
setMode(mode);
myName = store.get('ils-name') || '';
if (location.protocol === 'file:' || root.dataset.offline) online = false;
addEventListener('resize', resize);
puff = makePuff(); seedFx(); resize(); paintSnd(); reset(); applyView();
if (chal && online) { setWx(localWx()); paintChal(); rollWx(true).then(paintChal); } else rollWx();
// start fetching the footage when the game is about to scroll into view
if ('IntersectionObserver' in window) {
  const io = new IntersectionObserver(es => { if (es.some(e => e.isIntersecting)) { io.disconnect(); loadVideo(); } }, { rootMargin: '600px 0px' });
  io.observe(root);
} else loadVideo();
requestAnimationFrame(loop);
window.__ils = { get phase() { return phase; }, get alt() { return alt; }, get wx() { return wx; }, get code() { return code; }, setWx(o) { setWx(Object.assign({}, wx, o)); }, decide, flare: () => flare(), get result() { return result; }, set px(v) { px = v; }, set py(v) { py = v; }, dareUrl: () => dareUrl() };
})();
