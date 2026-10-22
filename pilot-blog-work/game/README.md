# 盲降挑战 (ILS approach game) — front-end prototype

- `ils.html` + `ils.js`: the game (night ILS approach, fog/rain canvas, drag or tilt control, DA 200 decision, scoring, share card, leaderboard UI against `api/game.php`).
- `qrcode.min.js`: qrcode-generator 1.4.4 (Kazuhiko Arase, MIT), minified; loaded only when a share card is drawn.
- Gameplay: optional first-run tutorial, 挑战/练习 modes (practice: lighter wind, no board), tap-to-flare below 45 ft (22 ft best, scored server-side as `fl`), challenge links (`?ils=<code>&n=&g=&s=`, the code is server-signed weather from `?a=start`), go-around climbs back into cloud, the ground shears about the horizon off the localizer.
- Loading: footage fetched whole with progress (blob) except in WeChat (direct src, starts on the tap); the cloud phase holds at 300 ft until it can play.
- `callouts.mp3`: synthetic GPWS-style callouts (flite, voice rms); sprite offsets are in `ils.js` (`CALL_SPRITE`).
- The footage (`approach-night.mp4`, 960x540, 30 s, 300 ft to touchdown) is cut from a third-party X-Plane recording (抖音 @737大佬, X-Plane 12); the owner placed it at `pilot-blog/assets/game/approach-night.mp4` on 2026-10-22 with the author's permission. It may be replaced by the owner's own recording. If replaced, re-measure `ALT_MAP` (video time -> radio altitude) from the new clip's callouts.
- Wired into the home page on 2026-10-22 (`#apch` in `app/templates/home.tpl.html`, script at `pilot-blog/assets/game/ils.js` (this copy matches it; one idle-repaint tweak on 2026-10-22: the brief card clears the canvas once, not every frame); SPEC §18 item 33). Keep the two copies of `ils.js` in step: edit `pilot-blog/assets/game/ils.js`, then copy it back here.
