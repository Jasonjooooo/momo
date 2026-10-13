# Design Package: 破云五边 (pilot blog, Tier 1)

Single input for the Phase 8 build. Every Chinese line in this file ships verbatim. Band ranges, scrim alphas and the tape keyframes are labeled starting points: the flick test and the worst-frame audit validate them after the real video arrives.

Working blog name (placeholder): **云底之下**. It appears only inside `<span class="brand-name">云底之下</span>` (nav, footer wordmark, footer copyright), in `<title>`, `og:title`, `og:site_name` and `<meta name="application-name">`: seven spots. No other copy line contains this string, so renaming the blog is one find-and-replace of `云底之下` in `index.html`. Alternatives if the user wants one: 五边日志, 决断高度.

Rules that hold across the whole site: Simplified Chinese only (`<html lang="zh-CN">`); no employer airline name, no registration, no real airport code; the author is 机长 or 我, never a personal name; no face photos.

---

## 1. The brand premise

**One word from the cockpit: MINIMUMS (最低标准, 决断高度).** Every approach carries a number the pilot sets on the ground, in calm, before departure. At that number, in the grey, the decision is already made: runway in sight, continue; no runway, go around. The blog is the record of those numbers and the reasons behind them. The hero flies one real approach down to minimums and the PFD tape calls it. 关于我 is the person in the left seat who sets the numbers. 飞行日志 explains one limit per post, and its interactive moment lets the visitor fly to minimums and learn that letting go is a go-around. 机型手记 gives the aircraft's own numbers. AI 超市 is equipment with honest status: anything not yet working wears an **INOP** placard, the way a pilot tags an inoperative instrument, and the assistant never makes the decision for you. The FAQ is a challenge-and-response checklist. The page lands on one runway: 订阅飞行日志. Closing line, in the footer: 落地，关车，写日志。

The INOP placard is the site's honesty device. It covers the two things that are not live yet (the subscription channel and the AI assistant) and is the only place besides MINIMUMS where signal yellow appears.

---

## 2. The palette as CSS tokens

Sampled with Python from `review/start-frame.png` (2000x1131). Sampled source values are given in the comments. Contrast ratios are WCAG, computed against the token they sit on.

```css
:root{
  --canvas:#0A1733;        /* instrument-night navy. Top-left sky sampled #132D61 (hue 220, sat 80%), same hue taken to 20% value. Never black. */
  --panel:#122452;         /* strips, cards, chat panel, form card. Same hue at 32% value. */
  --accent:#E8461E;        /* livery orange-red. Brightest lit lower-fuselage pixels #C23B19 (hue 12, sat 87%), lifted to daylight value 91%. The dusk frame renders the paint darker than it is. */
  --accent-hover:#FF6A3D;  /* hover and pressed glow. Canvas-colored label on it: 6.24:1. */
  --accent-muted:#48242D;  /* accent at 28% over canvas: borders, glows, the rabbit trail, the hold-progress track. */
  --text-secondary:#A9A8C0;/* cloud lavender, lifted from cloud body #495071 / cloud tops #66657D. 7.65:1 on canvas, 6.46:1 on panel. */
  --text-primary:#ECE9F1;  /* fuselage white, sampled #C1BDCA (hue 258) and lifted. 14.78:1 on canvas, 12.50:1 on panel. */
}
```

World tokens (same sampling run):

```css
:root{
  --signal:#E6A948;        /* golden cheatline, brightest 2% of cheatline pixels, used as sampled (hue 37). 8.56:1 on canvas.
                              ONLY for: MINIMUMS callout, the MIN bug on the tape, BARO MIN label, INOP placards. */
  --sky:#132D61;           /* sampled top-left sky. Environment layer drift, hero nav scrim fade. */
  --sky-low:#3D446B;       /* sampled sky at 30% frame height. Gradient stop in the environment layer. */
  --cloud:#495071;         /* sampled cloud body median. Mist motes, panel inner glow. */
  --ember:#75485B;         /* sampled horizon ember at the left edge. About-image caption rule only. */
  --afterglow:#F48B45;     /* sampled afterglow median behind the tail. Reference only: never a UI color (the accent stays single). */
  --line:#2A3A66;          /* decorative hairlines and plate rules. 1.60:1: never on anything interactive. */
  --line-strong:#5E6EA2;   /* interactive borders (inputs, chips, ghost button, chat box). 3.57:1 on canvas, 3.02:1 on panel. */
  --threshold:#4FD08A;     /* PROVISIONAL green threshold bar. Re-sample from hero-ending.jpg after the video gate. */
  --edge-light:#F2C98A;    /* PROVISIONAL warm runway edge light. Re-sample from hero-ending.jpg after the video gate. */
}
```

Usage rules:
- Primary button: `--accent` fill, label in `--canvas` at weight 600 (4.50:1). Hover `--accent-hover` (6.24:1). Never white text on the accent (3.94:1).
- Accent as text only on `--canvas`, 16px and up at weight 600 (4.50:1). Never accent text on `--panel` (3.80:1) below 24px.
- Accent appears in: the CTA buttons, focus rings, the hold-to-fly progress, the aircraft glyph in the approach plate, the favicon dot. Nowhere else.
- Scrims and text shadows use the canvas navy `rgba(10,23,51,a)`, never `rgba(5,5,10,a)`, so dimmed footage stays in the navy grade.
- Re-sample `--threshold` and `--edge-light` from the ending frame after the video gate; re-check that the ending frame's cloud base still matches `--sky-low` within a few points and say so if it drifted.

---

## 3. The type trio

Google Fonts is blocked in mainland China: no `fonts.googleapis.com`, no CDN of any kind. Latin faces are self-hosted woff2 files copied from npm `@fontsource` packages into `assets/fonts/` (`npm pack <pkg>`, take only the files below). Chinese text uses the system stack. Total webfont payload about 95 KB, five files.

| Role | Face and weights | npm package (verified on registry) | Files to copy | Why |
|---|---|---|---|---|
| Display (Latin, numerals) | **Barlow Condensed 600** | `@fontsource/barlow-condensed@5.3.0` | `files/barlow-condensed-latin-600-normal.woff2` (22.3 KB) | Condensed engineered grotesque that reads like approach-plate headers and cockpit placards. Fact values (758 M, 67 KIAS), section numerals, the PC-12 NGX title line. |
| Body (Latin in running text) | **B612 400, 700** | `@fontsource/b612@5.3.0` | `files/b612-latin-400-normal.woff2` (21.2 KB), `files/b612-latin-700-normal.woff2` (13.2 KB) | Designed by Intactile DESIGN with Airbus for cockpit display legibility. Every "PC-12", "PT6", "ILS" in body copy renders in it. |
| Mono (labels, tape, METAR) | **B612 Mono 400, 700** | `@fontsource/b612-mono@5.3.0` | `files/b612-mono-latin-400-normal.woff2` (19.0 KB), `files/b612-mono-latin-700-normal.woff2` (19.0 KB) | The same cockpit family in fixed width: METAR lines, flight strips, tape digits, kickers. 700 only for MINIMUMS, INOP and the tape readout. |
| Chinese (all CJK) | System: PingFang SC, Hiragino Sans GB, Microsoft YaHei, Noto Sans CJK SC, Source Han Sans SC | none | none | Fast everywhere in China. 400 body, 600 headings (PingFang Semibold; YaHei resolves 600 to its Bold, accepted). |

```css
@font-face{font-family:"Barlow Condensed";src:url(assets/fonts/barlow-condensed-latin-600-normal.woff2) format("woff2");font-weight:600;font-display:swap;unicode-range:U+0000-00FF,U+2000-206F,U+20AC,U+2122,U+2190-2193,U+2212}
@font-face{font-family:"B612";src:url(assets/fonts/b612-latin-400-normal.woff2) format("woff2");font-weight:400;font-display:swap;unicode-range:U+0000-00FF,U+2000-206F}
@font-face{font-family:"B612";src:url(assets/fonts/b612-latin-700-normal.woff2) format("woff2");font-weight:700;font-display:swap;unicode-range:U+0000-00FF,U+2000-206F}
@font-face{font-family:"B612 Mono";src:url(assets/fonts/b612-mono-latin-400-normal.woff2) format("woff2");font-weight:400;font-display:swap;unicode-range:U+0000-00FF,U+2000-206F}
@font-face{font-family:"B612 Mono";src:url(assets/fonts/b612-mono-latin-700-normal.woff2) format("woff2");font-weight:700;font-display:swap;unicode-range:U+0000-00FF,U+2000-206F}
:root{
  --cjk:"PingFang SC","Hiragino Sans GB","Microsoft YaHei","Noto Sans CJK SC","Source Han Sans SC";
  --font-head:var(--cjk),sans-serif;                          /* Chinese headlines: system CJK first so Latin inside a headline matches it */
  --font-display:"Barlow Condensed",var(--cjk),sans-serif;    /* standalone Latin/numeric display */
  --font-body:"B612",var(--cjk),sans-serif;
  --font-mono:"B612 Mono",ui-monospace,Menlo,Consolas,var(--cjk),monospace;
}
```

Preload only the two files the first screen uses: B612 Mono 400 (METAR chip, tape) and Barlow Condensed 600. `<link rel="preload" as="font" type="font/woff2" crossorigin>`. No preconnect (nothing is external).

Scale (starting points):
- Hero headline: `--font-head` 600, `clamp(30px,3.4vw,54px)`, line-height 1.25, letter-spacing .02em, `text-wrap:balance`.
- Hero subline: `--font-body` 400, `clamp(16px,1.25vw,19px)`, line-height 1.75, `max-inline-size:30ic` (fallback `max-width:30em`).
- Section H2: `--font-head` 600, `clamp(28px,3vw,44px)`.
- Body: 17px, line-height 1.85, `max-inline-size:34ic`, `line-break:strict`, `text-wrap:pretty`, `text-spacing-trim:trim-start` where supported.
- Mono labels: 12 to 13px, letter-spacing .08em, Latin uppercase.
- Fact values: `--font-display` 600, `clamp(36px,4vw,60px)`, `font-variant-numeric:tabular-nums`.
- Tape readout: `--font-mono` 700 22px; tape scale labels 12px 400.

Measure Chinese blocks in `ic` (one ideograph advance) on the text element itself, the CJK equivalent of the `ch` rule. `ch` measures the Latin "0" and undercounts Chinese by about half.

---

## 4. The band map

Hero: **520vh** pinned, so the scroll range is 420vh (deviation from the 400vh Tier 1 default, said out loud: four beats need about 80vh plateaus each). Video: one 6-second 16:9 shot, scrubbed by progress. Every caption lives in the **upper-left calm region** (top 35% of frame, left 45%): calm navy sky at the start, calm cloud base at the end. The aircraft lane (x 12% to 92%, y 22% to 70%) stays clear, the lower-left belongs to the runway lights at the end, the right edge belongs to the PFD tape.

Caption block position: `left:6vw; top:calc(64px + 5vh); max-width:min(760px,52vw)`. The block must end above 34% of the viewport height at 1440x900 (the propeller disc's top edge sits near 35% after cover-crop). Settle block is laid out wide and short for that reason.

| Band | Range (starting point) | Footage moment | Copy (verbatim) | Entrance |
|---|---|---|---|---|
| 1 | 0.00 to 0.23 (0 to 97vh, plateau about 88vh) | Start frame: blue hour above the stratus deck, afterglow behind the tail, the PC-12 in full left profile sinking gently toward the cloud tops. Tape 3,000 to 2,870 ft. | Kicker chip (mono): `METAR ZZZZ 131050Z 24003MPS 2000 BR OVC003 08/07 Q1016 NOSIG`<br>H1: 三千英尺，云顶之上。<br>Sub: 我是 PC-12 机长，飞短途支线。跟我飞完这一次进近。 | **Drift-down** (menu f): phrase spans sink 24px into place as the aircraft sinks toward the deck. One-time load ramp (`k = max(scrollK, loadK)`, loadK over 900ms) so the hero opens settled. No ease-in. Split points: [三千英尺，][云顶之上。] / [我是 PC-12 机长，][飞短途支线。][跟我飞完这一次进近。] |
| 2 | 0.25 to 0.49 (105 to 206vh, plateau about 84vh) | Entering the cloud: dim blue-grey interior, mist streams between lens and aircraft, moisture beads on the lens corners for one beat. The aircraft stays sharp. Tape rolls fastest, 2,780 to 1,080 ft. | Kicker chip: `IMC · ILS RWY 24`<br>H: 进云之后，只信仪表。<br>Sub: 窗外只剩灰色。姿态、空速、航向道、下滑道，交叉检查，一遍又一遍。 | **Drum-roll** (invented): every character rolls up into its own clipped slot from 0.9em below, in reading order, like the altimeter drum digits spinning on the tape beside it. `--th = index/total * 0.5 + rng()*0.04`, translateY plus opacity off `--kc`. Slot clip gets `padding:.12em 0 .25em; margin:-.12em 0 -.25em` so nothing is cut. `data-spread="0.5"`. |
| 3 | 0.51 to 0.74 (214 to 311vh, plateau about 80vh) | Breakout: the cloud thins into shreds rising overhead, the lens beads clear in the airflow, white approach lights, the green threshold bar and warm edge lights appear low-left ahead of the nose. Breakout near 0.60 at about 320 ft. Tape 920 to 270 ft. | Kicker chip: `BREAKOUT · 云底 300 英尺`<br>H: 穿出云底，灯光亮着。<br>Sub: 白色进近灯，绿色入口灯，两排边灯一直铺进雾里。 | **Blur-to-sharp** (menu c): a soft copy with a static `filter:blur(10px)` crossfades out as the sharp copy fades in, the same moment the mist clears off the lens. `data-ramp="0.036"` to savor it. |
| 4 (settle) | 0.76 to 1.00 (319 to 420vh, plateau about 92vh, then the page continues) | Short final under a flat dim cloud base: approach-light bars lead to the green threshold and two rows of warm edge lights, lower-left; only the prop disc and slow mist move. Tape 260 ft, reaches 200 at 0.92 and holds: **MINIMUMS** lights on the tape. | Kicker chip: `SHORT FINAL · 短五边`<br>H: 最低标准，起飞前就定好。<br>Sub: 到了决断高度：看得见，继续；看不见，复飞。这里写的，是每个数字背后的理由。<br>CTA primary: 订阅飞行日志 → `#subscribe`<br>CTA secondary (ghost): 先看日志 → `#log` | **Word-by-word rise into a staged settle** (menu e): headline phrases rise in order, the subline fades at `--ks` (k 0.66), the CTA row at `--kb` (k 0.78). `data-ramp="0.06"` so the whole settle lands by about 0.82, before the MINIMUMS callout at 0.92. No ease-out. Split points: [最低标准，][起飞前][就定好。] |

Scroll cue at the very start (bottom-left, mono, fades out by 0.05): 向下滚动，开始进近

Per-band legibility starting points (all four layers from `scrub-pipeline.md`, scrim color navy `rgba(10,23,51,a)`, each band's scrim ellipse centered on its own text block, never the frame center):

| Band | Scrim peak alpha | Reason | Audit note |
|---|---|---|---|
| 1 | 0.62 | Calm navy sky | Pre-audited on the start frame: text zone worst pixel 5.86:1 raw, 11.0:1 with the 0.62 scrim. |
| 2 | 0.72 | Cloud interior is the lightest field of the shot; bead highlights may sit in the upper-left lens corner | Audit the bead beat's worst frame specifically. |
| 3 | 0.68 | Bright shreds and the first lights | Audit frames at 0.55, 0.60, 0.68. |
| 4 | 0.66 | Calm cloud base, CTA row present | Buttons get `text-shadow:none`. |

Plus a top nav scrim, always on: `linear-gradient(180deg, rgba(10,23,51,.55), rgba(10,23,51,0) 22%)`.

**Tape sync (the signature element's drive).** Altitude is a piecewise-linear lookup on the lerped `shown` progress, never the raw scroll target, so the tape and the footage always agree:

| Progress | 0.00 | 0.22 | 0.30 | 0.50 | 0.60 | 0.76 | 0.92 | 1.00 |
|---|---|---|---|---|---|---|---|---|
| Altitude (ft) | 3000 | 2870 | 2500 | 1000 | 320 | 260 | 200 | 200 |

The tape crawls above the deck, spins in the cloud (where the footage is blank grey and the instrument becomes the show), slows after breakout and creeps onto the MIN bug. MINIMUMS shows at `shown >= 0.915` and hides below 0.905 (hysteresis, no flicker). The story matches the METAR: overcast 300 ft, breakout near 320 ft, decision at 200 ft.

---

## 5. The static-hero copy block

For phones, portrait tablets, coarse-pointer portrait, phones held sideways and reduced motion (the five gates). Image: `assets/hero-poster.jpg` (the start frame). Portrait crop `object-fit:cover; object-position:30% 50%` keeps the spinner, nose and cockpit in the middle third, navy sky above, cloud deck below. Text at the top over sky, buttons at the bottom over cloud, aircraft between them.

- Kicker chip (mono): `METAR ZZZZ 131050Z 24003MPS 2000 BR OVC003 08/07 Q1016 NOSIG`
- H1: 我飞 PC-12，短途支线。
- Sub: 低云进近、短跑道、结冰天气。每一个最低标准，我都写下它背后的理由。
- CTA primary: 订阅飞行日志 → `#subscribe`
- CTA secondary (ghost): 先看日志 → `#log`
- Static instrument chip (top-right over sky, mono, aria-hidden): big `3000`, below it `MIN 200` in `--signal` and `QNH 1016` in `--text-secondary`. The signature stays present even without the scrub.

Only one H1 is ever rendered: band 1's headline in scrub mode, this one in static mode (the other is `display:none`).

---

## 6. The below-fold outline

Order: nav, hero, 01 关于我, 02 飞行日志, 03 机型手记, 04 AI 超市, 05 问答, 06 订阅, footer. Every section funnels to one anchor: **`#subscribe`**. The nav's 订阅 button is always visible. No two adjacent sections share a skeleton (noted per section).

### Nav (fixed, 64px, transparent over the hero, `--canvas` at 85% with blur after it)
- Wordmark: `<span class="brand-name">云底之下</span>` plus mono tag `PC12/L`
- Links: 关于 · 日志 · 机型 · AI 超市 · 问答
- Button (accent): 订阅
- Phone menu toggle: 目录
- Skip link: 跳到正文

### 01 关于我 `#about`
Skeleton: asymmetric split. Supporting still (PC-12 parked on a wet apron at blue hour, 4:3 crop on the right two-thirds of the image) bleeds to the left page edge across 7/12; text column 5/12 on the right; logbook strip under the text.

- Kicker (mono): `01 / ABOUT`
- H2: 左座上的人。
- P1: 我是机长，飞 PC-12 NGX，跑短途支线。航段短，起降多，一天要飞好几段。
- P2: 这些航线没有长巡航可以喘口气。起飞、爬升、进近、落地，然后再来一遍。小机场跑道短，备降场远，天气说变就变，很多决定要在地面上做完。
- P3: 我写这个博客，想把驾驶舱里的事讲清楚：一个数字从哪里来，一条程序为什么这样写，一次进近里我在看什么。写给同行，也写给对飞行好奇的人。
- P4 (planned staccato, keep): 不露脸，不写公司，只写飞行。
- Logbook strip (mono label over Chinese value, four cells): `TYPE` PC-12 NGX · `SEAT` 左座 · `ROUTE` 短途支线 · `WX` 低云 · 侧风 · 结冰季
- Image caption (mono, under a 1px `--ember` rule): `APRON · 雨后 · 蓝调时刻`
- Image alt: 雨后的停机坪上，一架 PC-12 停在蓝调时刻的灯光里，登机梯放下，舱内亮着暖光。
- Living element: the floodlight glow on the image's top-right corner breathes (pseudo-element opacity, 7s cycle, delay -2.4s).

The user may later add a flight-hours line to the strip in one sentence; none is invented here.

### 02 飞行日志 `#log`
Skeleton: a full-width approach-plate header box, then the interactive banner, then a single column of full-width flight strips.

- Plate header box (three ruled cells): `02 / LOG` · H2 飞行日志 · `最新 5 篇`
- Lede: 落地之后写。每篇只讲一件事，写完先对一遍手册。

**The one interactive moment: 换你来飞 (hold to fly the glide slope).** Lives here, as the section's banner: the wing-above-the-deck still, 21:9 crop, with the hand-drawn approach-plate profile (section 7) over its calm upper half.

- Label (mono): `ILS 示意 · 按住下降`
- H3: 换你来飞。
- Instruction: 按住按钮，沿下滑道降到决断高度。中途松手，就是复飞。
- Button: 按住下降
- Status line, idle: 已截获下滑道，等你按住。
- Status line, holding: 下降中。盯住下滑道。
- Status line, released early: 复飞是正常操作。再按住，再飞一次。
- Status line, complete: MINIMUMS。跑道可见，继续。
- Button after completion: 再飞一次
- Readout (mono, live while holding): `ALT 1500` counting to `ALT 200`

Mechanics: a full descent takes about 2.8s of holding. Progress builds only while held (pointer, or Space/Enter on the focused button). Releasing early eases the glyph back up the glide slope at half speed with an ease-out climb: never a snap. At completion the MINIMUMS tag blinks twice in `--signal`, the plate's approach-light bars light white, the threshold bar lights green, the edge lights light warm, then the five strips below light their left holder bar one by one (90ms apart) like sequenced approach lights. The content is readable before and without the interaction; completion only adds light. Reduced motion: the completed state is shown at once, status reads the complete line, button reads 再飞一次. `touch-action:none` and no context menu on the button.

**Post strips** (one `<article>` each; a code comment above the list marks them as SAMPLE POSTS, placeholder content to replace; titles are not links in v1, the comment says where `posts/<slug>.html` links go later). Each strip: date (mono) · tag (mono, ruled box) · title · excerpt · read time.

1. `2026.10.08` · `IMC` · **低云进近：决断高度上的那两秒** · 云底 300 英尺，能见度 2000 米。从最后进近定位点到决断高度，我的注意力怎么分配，又在哪一刻把眼睛交给窗外。 · 8 分钟
2. `2026.09.21` · `PERF` · **短跑道上的 PC-12：先算数，再上跑道** · 起飞距离、着陆距离、湿跑道修正、温度和坡度。在一条短跑道上，性能图表比手感可靠。这篇把一次完整的计算从头走一遍。 · 7 分钟
3. `2026.09.03` · `WX` · **结冰季：除冰靴、惯性分离器，和我不碰的那条线** · PC-12 获准在已知结冰条件下飞行。获准飞，和今天该不该飞，是两个问题。这篇讲我怎么看云、看温度，什么情况下直接改天。 · 9 分钟
4. `2026.08.17` · `OPS` · **单发涡桨的一天：一台发动机，好几段航程** · 一台 PT6，一副五叶螺旋桨。单发的意思是，每一段起飞前都要想好：如果它停了，我往哪里去。 · 6 分钟
5. `2026.07.30` · `PRE` · **绕机一圈：我的航前检查顺序** · 从登机门出发，绕飞机走一圈。桨叶、滑油、进气道、除冰靴、静压孔，每一项被写进检查单，都有它的理由。 · 5 分钟

- List end line: 新的日志写完，先发给订阅的人。
- Link after it (text link, underlined, `--text-primary`): 订阅飞行日志 → `#subscribe`
- Living element: the glide-slope diamond on the plate breathes (opacity, 5s, delay -1.2s) until the visitor first holds.

### 03 机型手记 `#type`
Skeleton: centered, full-width ICAO strip as the header, the hand-drawn PC-12 side outline spanning the content width with numbered callouts, then a 3x2 fact grid. (Differs from 02's stacked strips and 04's banner plus two columns.)

- ICAO strip (mono, one ruled row): `TYP PC12` │ `L1T` │ `WTC L` │ `SINGLE PILOT IFR` │ `FIKI` │ `MAX CRZ 290 KTAS`
- Strip gloss (small, `--text-secondary`): 单发涡桨 · 轻型 · 可单人驾驶仪表飞行 · 获准在已知结冰条件下飞行
- Kicker (mono): `03 / TYPE`
- H2: 机型手记：PC-12 NGX
- Lede (planned triplet, keep): 一台发动机，五片桨叶，一个 T 型尾翼。我每天坐进左座的，就是它。
- Outline callouts (mono numerals, Chinese labels): ① 五叶螺旋桨 ② PT6E-67XP ③ 货舱门 1.35 × 1.32 米 ④ T 型尾翼

Fact cells (mono code, Barlow value, Chinese line):

| Code | Value | Line |
|---|---|---|
| `ENG` | PT6E-67XP | 单台普惠加拿大涡桨发动机，起飞功率 1,200 轴马力。电子发动机与螺旋桨控制，一根功率手柄。 |
| `PROP` | 5 BLD | 五叶复合材料螺旋桨，可以反桨。落地后用反推减速。 |
| `TAIL` | T-TAIL | 水平尾翼装在垂尾顶端，远离机翼下洗和螺旋桨滑流。 |
| `TO` | 758 M | 最大起飞重量下，越过 15 米障碍物的起飞距离。 |
| `LDG` | 661 M | 最大着陆重量下，越过 15 米障碍物的着陆距离。着陆形态失速速度 67 节，进近可以飞得又慢又稳。 |
| `RWY` | UNPAVED | 起落架按非铺装跑道设计，砂石和草地跑道也能起降。 |

- Footnote: 数据来自 Pilatus 公开资料，条件为海平面、标准大气、干的铺装跑道。实际运行以飞机飞行手册为准。
- Living element: a faint five-blade ghost on the outline's propeller turns once every 12s (transform rotate).

Fact sources checked for this package: Pilatus PC-12 NGX factsheet (takeoff over 15 m at MTOW 758 m; landing over 15 m at MLW 661 m / 2,170 ft; stall 67 KIAS landing configuration; max cruise 290 KTAS; cargo door 1.35 x 1.32 m; certified single pilot and for flight into known icing), Aviation Week overview (PT6E-67XP 1,200 shp takeoff; 2,485 ft over 50 ft), Hartzell (five-blade composite propeller), Pilatus marketing (gear designed for unpaved fields), ICAO Doc 8643 (PC12, L1T, WTC L). Propeller reverse is standard PC-12 knowledge, not re-checked online: the author should confirm in one glance.

### 04 AI 超市 `#ai`
Skeleton: banner header (rain-on-glass bokeh still, 21:6, H2 over its calm navy upper half, whisper drizzle streaks), then two columns: shelves 7/12 left, sticky chat panel 5/12 right. Phones: shelves first, chat below.

- Kicker (mono): `04 / AI 超市`
- H2: AI 超市
- Lede: 国内能直接打开的 AI 工具，按用途上架，每样写清它擅长什么。旁边是本站的 AI 助手。

**(a) AI 工具货架.** Shelf tags styled like aisle labels (mono code plus Chinese). Card: name, one line, link text 打开 plus the domain in mono. Links open in a new tab with `rel="noopener noreferrer"`. The data lives in one array in `index.html` so the user can restock it in one sentence. Links are canonical official domains; verify each from a mainland connection before launch (this sandbox cannot reach them).

| Shelf | Name | Line (verbatim) | URL |
|---|---|---|---|
| `A1` 对话问答 | DeepSeek | 会把推理过程写出来，适合一步步想清楚一个问题。 | https://chat.deepseek.com |
| `A1` 对话问答 | Kimi | 能一次读很长的文档，适合在整本资料里找答案。 | https://www.kimi.com |
| `A1` 对话问答 | 豆包 | 字节跳动出品，电脑和手机都好用，日常问答顺手。 | https://www.doubao.com |
| `A2` 搜索查证 | 秘塔 AI 搜索 | 每个回答都附出处，方便回到原文核对。 | https://metaso.cn |
| `A2` 搜索查证 | 腾讯元宝 | 能搜到微信公众号文章，中文资讯覆盖广。 | https://yuanbao.tencent.com |
| `A2` 搜索查证 | 文心一言 | 百度出品，联网检索中文网页。 | https://yiyan.baidu.com |
| `A3` 语音与翻译 | 通义听悟 | 录音转文字，自动分段、提炼要点。 | https://tingwu.aliyun.com |
| `A3` 语音与翻译 | 讯飞听见 | 中文语音转写准确，支持多种方言。 | https://www.iflyrec.com |
| `A3` 语音与翻译 | DeepL | 译文读起来自然，适合对照阅读英文资料。 | https://www.deepl.com/zh/translator |
| `A4` 图像与视频 | 即梦 | 用一句话生成图片和短视频。 | https://jimeng.jianying.com |
| `A4` 图像与视频 | 可灵 AI | 快手出品，擅长生成带镜头运动的短视频。 | https://klingai.kuaishou.com |
| `A4` 图像与视频 | 通义万相 | 阿里出品的图像生成，也能做视频。 | https://tongyi.aliyun.com/wanxiang |

- Shelf note: 货架上的工具与本站没有合作关系，链接直达官网。

**(b) AI 助手 (chat panel).**
- H3: AI 助手
- Lede: 问飞行常识、PC-12，或者货架上哪样工具适合你。
- Caution line: 它会答错。涉及运行的数字，请以飞机飞行手册和公司程序为准。
- Status lamp (mono) states: `STBY` 待命 · `TX` 发送中 · `ON` 在线 · `INOP` 接入中 (INOP in `--signal`)
- Welcome bubble (assistant): 你好。我可以聊飞行常识、PC-12，也能帮你在货架上挑工具。先说一句：我不替任何人做飞行决断。
- Suggestion chips: 决断高度和决断高有什么区别？ · PC-12 为什么用 T 型尾翼？ · 整理录音用哪个工具？
- Input label (visually hidden): 你的问题
- Placeholder: 输入你的问题
- Send button: 发送
- Pending (button text and SR-only bubble): 发送中 / 正在回答
- INOP message (inline, assistant side, with INOP placard): 助手正在接入中（INOP），这条问题没有得到处理。接通以后，这里会直接回答。
- Timeout message: 等了 20 秒没有回应。稍后再试一次。
- Rate-limit message (HTTP 429): 问得有点快。请过一会儿再问。
- Privacy line under the input: 对话会发送到本站服务器处理，请不要输入个人信息。
- Living element: the status lamp breathes (opacity, 4s) in STBY.

### 05 问答 `#faq`
Skeleton: one narrow centered checklist card (max 760px), a header bar like a printed checklist, challenge-and-response rows with dotted leaders running from the question to a short response, the explanation under each row. (Differs from 04's two columns and 06's full-bleed image.)

- Header bar (mono): `CHECKLIST · 读之前`
- Kicker (mono): `05 / FAQ`
- H2: 读之前，先过一遍检查单。

| Challenge | Response | Explanation |
|---|---|---|
| 作者真的在飞吗？ | 在飞 | 我是现役机长，飞 PC-12 NGX 短途支线。不写公司，不露脸，技术问题才能写得直接。 |
| 能当训练资料用吗？ | 不能 | 这里写的是个人经验和理解。训练和运行，以飞机飞行手册、公司程序和你的教员为准。文中引用的数字会注明出处。 |
| 单发飞机，安全吗？ | 按单发计划 | PC-12 用的是 PT6 系列发动机，这个系列从上世纪六十年代一直飞到今天。单发也意味着计划方式不同：每一段都先想好发动机停车后往哪里滑翔、在哪里落地，航路、高度和天气按这个来定。 |
| 订阅之后会收到什么？ | 只有日志 | 新的一篇写完，发一封邮件。不发广告，不转让邮箱，随时退订。 |

- Mode A only, appended to the last explanation while the subscription is not wired: 订阅通道还在接入，开通之前提交的邮箱不会被保存。
- Link under the card: 订阅飞行日志 → `#subscribe`
- Living element: a whisper-level scan bar runs down the checklist every 10s, like a finger following the list.

**Testimonials: none, on purpose.** A new personal blog has no real reader quotes yet, and invented ones would be fake reviews. Reserved slot: when real reader emails arrive, up to three short quotes go above the checklist card, with the reader's permission.

### 06 订阅 `#subscribe` (the one CTA)
Skeleton: full-bleed ending frame (`assets/hero-ending.jpg`, dimmed with a navy gradient) with the perspective runway drawn over it (section 7), centered form card at the runway's far end. Until the ending frame exists: canvas gradient plus the runway SVG alone.

- Kicker (mono): `06 / SUBSCRIBE`
- H2: 订阅飞行日志
- Lede (planned parallel, keep): 最低标准写在起飞前，日志写在落地后。
- Sub: 新的一篇写完，发到你的邮箱。
- Field label: 邮箱
- Placeholder: name@example.com
- Button (accent): 订阅飞行日志
- Microcopy under the button: 只发飞行日志。不发广告，随时退订。
- Empty error: 请先填写邮箱。
- Format error: 邮箱格式不对，请再检查一遍。

Form handling: **JS-only success state for now (Mode A), said honestly.** Submissions go nowhere; the success state says exactly that. Swappable later for a form service (Mode B) by setting one constant (section 8).

- Mode A success (replaces the form, with an INOP placard): 订阅通道还没接通（INOP）。这次提交没有保存你的邮箱，也不会发邮件给你。接通以后，这里会换成正式订阅。
- Mode B submitting (button text): 提交中
- Mode B success: 已订阅。下一篇日志写完，会发到 {email}。 (`{email}` inserted with `textContent`)
- Mode B failure: 这次没有提交成功。稍后再试一次。
- Living element: the rabbit, a single bright dot running the approach-light bars toward the threshold every 5s.

### Footer
- Wordmark: `<span class="brand-name">云底之下</span>` plus mono `PC12/L · L1T`
- Tagline (closing line): 落地，关车，写日志。
- Links: 关于 · 日志 · 机型 · AI 超市 · 问答 · 订阅
- AI disclosure: 首页和各栏目中的飞机影像由 AI 生成，不对应任何真实航空器。
- Disclaimer: 本站内容是个人飞行经验分享，不能代替飞机飞行手册、公司程序和教员的指导。
- Copyright: © 2026 `<span class="brand-name">云底之下</span>`

### Head copy
- `<title>`: 云底之下 · 一位 PC-12 机长的飞行日志
- Meta description and og:description: PC-12 NGX 机长的飞行日志：低云进近、短跑道、结冰天气、单发涡桨的日常，和每个最低标准背后的理由。
- og:title: same as `<title>`. og:site_name and application-name: 云底之下
- `theme-color`: `#0A1733`

---

## 7. The vector layer plan

Everything is hand-drawn inline SVG in `--line`, `--line-strong` or `--text-secondary` strokes at 1 to 1.25px, `vector-effect:non-scaling-stroke`, self-drawing via `pathLength="1"` with `stroke-dasharray:1` and `stroke-dashoffset` from 1 to 0 on entry (IntersectionObserver `.in` class, eased). All decorative SVG is `aria-hidden="true"`.

1. **PFD altitude tape (the signature, hero only).** Inside the sticky stage, fixed to the right edge: `right:clamp(12px,1.6vw,28px)`, vertically centered, 76px wide, `height:min(56vh,480px)`. Chip: `rgba(10,23,51,.60)` with `backdrop-filter:blur(8px)` (pre-audited on the start frame: tape-zone worst pixel 6.39:1, the winglet tip stays visible underneath). Contents:
   - One pre-drawn SVG strip from -400 to 3,400 ft, 100 ft = 40px: minor tick every 20 ft, major tick and B612 Mono label every 100 ft. It moves by `translateY` only, written when the change exceeds 0.5px.
   - Readout box at mid-height with a pointer notch on its left: hundreds-and-up in B612 Mono 700 22px, tens on a rolling drum in 20 ft steps (shows e.g. 40 / 60 / 80 stacked, translated fractionally), the way a real PFD rolls its last two digits.
   - Above the tape: metric box `914 M` (feet x 0.3048, rounded), a nod to China's metric altitudes.
   - Below the tape: `BARO MIN 200` in `--signal`, `QNH 1016` in `--text-secondary` (matches the METAR).
   - MIN bug: a `--signal` bar and small triangle on the strip at 200 ft.
   - **MINIMUMS** annunciation: box left of the readout, B612 Mono 700 14px, `--signal` text and 1px border on navy; two quick blinks on first lighting, then steady; removed when progress drops back (hysteresis in section 4).
   - Text readouts update at about 10Hz and only on change. Hidden on short screens `(max-height:560px)`; never on the static hero (which gets the static chip from section 5).
   - Loudness test: remove the tape and the hero is a pretty flight; with it, every visitor reads the descent the way a pilot does and the settle lands on the premise word. It stays.
2. **Approach-plate profile (02, the interactive banner).** Profile view over the wing still's calm upper half: a ground line, the runway bar at right labeled `RWY 24`, a 3.0° glide-slope line labeled `GS 3.0°`, a Maltese cross at the final approach fix labeled `FAF 1500`, a dashed horizontal at `DA 200`, the aircraft glyph (small accent chevron) riding the line, approach-light bars, a green threshold bar (`--threshold`) and warm edge dots (`--edge-light`) that light at completion. Glide-slope diamond for the idle living element.
3. **Glide-slope dividers (between sections).** A single descending hairline per divider, high at left and low at right, with a mono station label at its left end: `GS 3.0°` (01 to 02), `LOC 24` (02 to 03), `OM` (03 to 04), `MM` (04 to 05). The last divider (05 to 06) ends in a five-dot green threshold bar: the page itself flies an approach down to the CTA. Draws on entry.
4. **Runway centerline (06).** Perspective runway over the ending frame: two edge-light rows (`--edge-light` dots, converging), a dashed centerline running up the middle to the form card, approach-light bars below the card's lower edge, the five-dot threshold just above the button. Lines draw on entry; the rabbit runs the bars every 5s.
5. **PC-12 side outline (03).** One continuous hand-drawn stroke of the left profile (spinner, cowling with exhaust stack, cockpit, cabin windows, cargo door, low wing, T-tail with ventral fins, gear down), drawn on entry in about 1.6s, then numbered callout leaders draw to ① prop ② engine ③ cargo door ④ T-tail. Five-blade ghost on the prop as the living element.
6. **Approach-plate header box (02 only)** and the **ICAO flight strip (03 only)**: ruled boxes in `--line`, mono fields.
7. **Environment layer (whole page, fixed, behind everything).** `--canvas` base; two very large blurred ellipses (`--sky` at 50%, `--cloud` at 10%) drifting by transform on 90s and 120s cycles; a static grain tile (128px PNG generated at build, opacity .035). Scrolling should feel like moving through one night sky.
8. **Whisper particles.** Drizzle in the 04 banner: 10 thin streaks in `--text-secondary` at 10 to 18% opacity falling on 6 to 9s cycles with negative delays. Nothing else floats.

Reduced motion: every line drawn, the rabbit stopped with all lights steady, ghost prop static, drizzle hidden, hold module in its completed state, environment drift stopped. Off-screen loops pause through an IntersectionObserver class; hidden tabs pause everything with `body.paused *, body.paused *::before, body.paused *::after{animation-play-state:paused!important}`.

---

## 8. The engineering list

The full standard from `scrub-pipeline.md` plus the whole-site-animated standard from Phase 8, and the specifics this site adds:

- **Architecture:** one `index.html` plus `assets/` (`fonts/`, images, `hero-scrub.mp4`). Plain HTML, CSS, vanilla JS. No frameworks, no build step. Raw and review files stay in `pilot-blog-work/review/`, never in `pilot-blog/`.
- **Zero external requests.** Mainland China audience: no Google Fonts, no CDNs, no analytics, no embeds, no reCAPTCHA. Fonts self-hosted per section 3.
- **Blob fetch with the loading ring:** the streamed Blob loader with the progress ring and the 20s per-chunk watchdog (the audience may be far from the host, so the ring and watchdog matter even for a 6 to 8 MB clip); `hardcode VIDEO_BYTES`; poster painted first from JS inside the gated path; `failVideo()` swaps the ring for a scroll chevron. Ring `aria-label`: 影像加载中
- **The dt-normalized lerp** in a rAF loop that rests when converged and when the hero is off-screen (IntersectionObserver).
- **Gated seeks:** one seek in flight, coalesce to newest, one follow-up on `seeked`, reset on `error`.
- **Delta-gated DOM writes:** captions, scrims, classes, `--k` (0.008 threshold), the tape transform (0.5px), tape text at 10Hz and only on change.
- **Band pacing with the flick test:** 520vh hero, bands per section 4, ramps `min(0.02,(b-a)/3)`, `data-ramp` and `data-spread` overrides, band 1 load ramp, band 1 no ease-in, band 4 no ease-out. Flick at 120, 240, 360px; every band readable for 5 to 6 normal flicks, none skippable at 360px; check on a 760px-tall window too.
- **The four-layer legibility system:** global base scrim (navy), per-band scrim on each text block, the `--tshadow` token in navy (`0 1px 2px rgba(6,14,32,.95),0 3px 12px rgba(6,14,32,.78),0 10px 44px rgba(6,14,32,.8)`), chip scrims for kickers and the tape. Worst-frame audit at 3.5:1 or better for every band once the video exists; band 1 and the tape are pre-audited on the start frame.
- **The five static-hero gates,** character-identical in CSS and JS, live via change listeners on all five, `enableScrub`/`disableScrub`/`applyHeroMode`, poster and Blob only inside the gated path.
- **Complete without the video:** video `error` hides the element over the poster; every caption, the tape and both CTAs work over the still.
- **Reduced motion honored completely and live in both directions:** `pinToFinalStates()` (lines drawn, rabbit steady, hold module complete, drives stopped) and the undo path back through `applyHeroMode()`.
- **Signature tape:** piecewise-linear lookup from section 4 on `shown`; MINIMUMS hysteresis 0.915 / 0.905; transform-only motion.
- **Hold-to-fly:** pointer events plus Space/Enter keydown/keyup on the focused button; progress rises while held, eases back at half speed on release; completion lights the plate and the five strips in sequence; `touch-action:none`; reduced motion shows the final state.
- **Chat (front end only, honest):** `POST api/chat.php` (relative path), `Content-Type: application/json`, body `{"messages":[{"role":"user"|"assistant","content":"..."}]}` with the last 10 turns, expects `200` with `{"reply":"..."}`. 404, 405, network failure or non-JSON → INOP message and lamp `INOP`; 20s `AbortController` timeout → timeout message; 429 → rate-limit message. Input capped at 500 characters; send disabled while pending; replies rendered with `textContent` only, never `innerHTML`. Enter sends, Shift+Enter is a newline, and **Enter is ignored while an IME is composing** (`e.isComposing || e.keyCode === 229`), or Chinese pinyin input sends half-typed questions. No API key, token or provider URL anywhere in the front end, ever; the future PHP endpoint holds the key server-side.
- **Subscribe form:** `const SUBSCRIBE_ENDPOINT = '';` empty means Mode A (JS-only, honest INOP success, nothing stored or sent). Setting it to a form service URL switches to Mode B (POST with `Accept: application/json`, Mode B copy, Mode A's FAQ sentence removed). Pick a service that is reachable from mainland China when the time comes. `type="email"`, `inputmode="email"`, `autocomplete="email"`, IME-safe Enter.
- **Brand-name swap:** `云底之下` only in the seven spots listed at the top of this file; the copy gate counts them.
- **Sample posts:** marked as samples in an HTML comment only, never on screen.
- **Typography for Chinese:** `lang="zh-CN"`, measures in `ic`, `line-break:strict`, `text-wrap:balance` on headings and `pretty` on paragraphs, spaces kept between Chinese and Latin as written in this package.
- **Assets (Phase 7):** `hero-scrub.mp4` (scrub encode, `-g 8`), `hero-poster.jpg` (start frame; the approved `review/start-frame.webp` at 97 KB is the stand-in until the video exists), `hero-ending.jpg` (re-used in 06), `about.webp`, `log-wing.webp`, `ai-banner.webp`, each web-sized with one compression pass.
- **The quality floor:** fonts trimmed to the five files; contrast computed (section 2); landmarks (`<nav>`, `<main id="main" tabindex="-1">`, `<footer>`, skip link 跳到正文); video `aria-hidden`, `tabindex="-1"`, no controls; `:focus-visible` in the accent; 44px touch targets under `(pointer:coarse)` without changing layout; real `<title>`, description, `theme-color`, inline SVG favicon (a navy square, a glide-slope stroke meeting a short runway bar, one accent dot for the aircraft); `og:image`/`og:url` left as `<!-- DEPLOY STEP -->`; decorations reserve space and hide on short screens; baseline-aligned rows; `overflow-x:clip` on `html` and `body` with `hidden` first; nothing snaps, two easing tokens; entrance stagger retired after it plays; no dynamic style on an element with a `forwards` animation; one living element per section, negative delays, paused off-screen and on hidden tabs.
- **Self-test before anyone sees it:** the full Phase 9 checklist, headless Chrome where possible (touch emulation for the coarse-pointer gates, live reduced-motion flips, the video URL blocked, a real press-and-hold on 换你来飞, a real failed POST to `api/chat.php` showing INOP), phone widths 375x812 and 375x667, desktop 1280x800 and 1440x900, the settle block ending above the propeller disc at 1440x900, zero console errors, then the fresh-eyes pass.

---

## 9. The copy gate line

Every viewer-facing line in this package ships verbatim; build passes wire it in and never paraphrase it. Before anyone sees the page, `index.html` must pass the Phase 9 gate: zero em dashes (`—` and `——`), zero stock words (leverage, seamless, empower, unlock, robust, actionable, data-driven, solutions, and 赋能, 无缝, 一站式, 解决方案, 助力, 打造), then the body-copy sweep for AI tells (not-X-but-Y constructions, false ranges, vague attributions, big-finish conclusions, testament, landscape, delve, elevate), re-grepped until clean, plus a count check that `云底之下` appears only in its seven brand spots. The deliberate devices written here are craft and stay: 不露脸，不写公司，只写飞行。 / 一台发动机，五片桨叶，一个 T 型尾翼。 / 看得见，继续；看不见，复飞。 / 最低标准写在起飞前，日志写在落地后。 / 落地，关车，写日志。 The sweep hunts only what drifts in uninvited.
