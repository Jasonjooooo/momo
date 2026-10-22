# 盲降挑战 (ILS approach game) — front-end prototype

- `ils.html` + `ils.js`: the game (night ILS approach, fog/rain canvas, drag or tilt control, DA 200 decision, scoring, share card, leaderboard UI against `api/game.php`).
- `qrcode.min.js`: qrcode-generator 1.4.4 (Kazuhiko Arase, MIT), minified; loaded only when a share card is drawn.
- Gameplay: optional first-run tutorial, 挑战/练习 modes (practice: lighter wind, no board), tap-to-flare below 45 ft (22 ft best, scored server-side as `fl`), challenge links (`?ils=<code>&n=&g=&s=`, the code is server-signed weather from `?a=start`), go-around climbs back into cloud, the ground shears about the horizon off the localizer.
- Loading: footage fetched whole with progress (blob) except in WeChat (direct src, starts on the tap); the cloud phase holds at 300 ft until it can play.
- `callouts.mp3`: synthetic GPWS-style callouts (flite, voice rms); sprite offsets are in `ils.js` (`CALL_SPRITE`).
- The footage (`approach-night.mp4`, 960x540, 30 s, 300 ft to touchdown) is NOT in the repo: it is cut from a third-party X-Plane recording and may only go live with the author's permission or be replaced by the owner's own recording. If replaced, re-measure `ALT_MAP` (video time -> radio altitude) from the new clip's callouts.
- Not yet wired into `app/templates/home.tpl.html`.
