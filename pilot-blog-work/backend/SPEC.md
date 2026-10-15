# 云底之下: admin backend and publisher, build spec v1

Status: final spec for the build agents. Follow it literally. Where it says MUST, a test checks it.
Written 2026-10-14 against commit 08da7c1 (`pilot-blog/index.html`, 207 010 bytes, 3058 lines, LF line ends,
sha256 `3401b7f4d1c8692dee06a383831474447733558bfba68efc96aab63ba850c90f`).

Reading order for a build agent: §0, §17 (build order), then the section for your phase. Keep
`pilot-blog-work/backend/PROGRESS.md` updated (one line per meaningful step) and work resumably.

---

## 0. Decisions in one screen

| Topic | Decision |
|---|---|
| Public site | Stays **static**. Visitors only ever get files: `index.html`, `posts/*.html`, `uploads/*`, `assets/*`. Two small PHP endpoints remain: `api/chat.php` (changed) and `api/subscribe.php` (new). |
| How edits reach the site | Admin saves content as JSON in a private data dir. A PHP **publisher** renders `index.html` and the post pages from templates, writes each file atomically (temp file + rename) and records a version (last 10 kept, one-click rollback). |
| Source of truth for the page | `pilot-blog/app/templates/home.tpl.html` = today's `index.html` with comment markers around every editable text and list. `build.py` and the B-chart `parts/` files become **obsolete** for the live site (keep them for history, do not run them). |
| Core acceptance test (T1) | Rendering the template with the **baseline** content extracted from it MUST reproduce today's `index.html` byte for byte, forever (it is the "subscribe off, no published posts" state). |
| Hero | Hero CSS, markup skeleton and JS stay byte-identical to commit 08da7c1 (test T3). Only hero caption text is editable. |
| Storage | JSON files guarded by `flock`, atomic writes. No SQLite, no MySQL. (`php -m` here shows pdo_sqlite, but JSON needs no extension, is certain to work on any Hostinger PHP plan, backs up and rolls back by plain file copy, and the data is small: hundreds of posts, thousands of subscribers at most.) |
| Private data | `blog-data/` one level above `public_html` (local dev: `/home/user/momo/blog-data`, gitignored). |
| Code location | Everything deployable lives inside `pilot-blog/` (the Hostinger deploy only reaches `public_html`): `admin/` (front controller + assets), `app/` (lib, templates, views, seed, CLI; denied over HTTP). |
| Admin | Server-rendered PHP pages + one small `admin.js`, Chinese, phone-first, no external assets, same palette and fonts. Password login; first run needs a one-time setup code that only exists as a file in `blog-data/`. |
| Posts | Markdown subset with our own renderer (no raw HTML). Statuses: 草稿 (private), 预告 (title and excerpt shown on the home chart, no page, this is what the 5 current sample legs are), 已发布 (own page `posts/<slug>.html`). |
| Subscribers | Real endpoint stores email + time + consent text. **No mail is sent by any code.** Public copy says the author sends new posts by hand. Admin can copy all emails for BCC and export CSV. |
| AI assistant | Key, on/off, limits and an extra prompt note set in admin. `chat.php` logs per-day counts and tokens, never message contents. |

Out of scope for v1 (do not build): sending email, RSS, comments, tag pages, site search, scheduled
publishing, multiple admin users, image library/deletion UI, replacing the home page photos or hero
media, editing chart furniture (codes, frequencies, numbers drawn as part of a graphic).

---

## 1. Architecture

```
 owner (iPhone Safari)                         visitor
        │ https /admin/?r=…                        │ https /, /posts/x.html, /uploads/…
        ▼                                          ▼
 pilot-blog/admin/index.php ──uses──▶ pilot-blog/app/lib/*.php      static files (Apache/LiteSpeed)
        │ save (draft JSON)                        ▲
        ▼                                          │ publish_site(): render + atomic write
 blog-data/content, posts, settings ───────────────┘
        ▲                                   api/chat.php ──▶ Anthropic API
        │ subscribers.json, usage/*.json ◀── api/subscribe.php
```

- Draft and live are separate. Saving never changes the public site. Publishing copies the draft to
  the live snapshot and regenerates files from **live snapshots only**.
- Granularity: three independent publishable units: 网站文字 (site copy, FAQ, type facts), AI 超市
  (shelf), and each post. Publishing one never publishes the drafts of the others.
- Settings that change the page (assistant on/off, key set/removed, subscribe on/off, home leg limit,
  site address) save immediately and republish automatically (no version entry, see §6).

---

## 2. Directory layout

### 2.1 Repo (`/home/user/momo`)

```
pilot-blog/                          = public_html
  index.html                         generated; committed copy = render of the LAUNCH content (T2)
  .htaccess                          NEW root rules (§14)
  assets/                            unchanged, plus NEW assets/post.css (post + archive pages)
  posts/.htaccess                    NEW static (CSP for post pages); posts/*.html generated, gitignored
  uploads/.htaccess                  NEW static (no script execution); uploads/** generated, gitignored
  api/chat.php                       CHANGED (§10)
  api/subscribe.php                  NEW (§9)
  api/.htaccess                      CHANGED: allow chat.php and subscribe.php only
  admin/index.php                    NEW front controller (only PHP file reachable in admin/)
  admin/.htaccess                    NEW
  admin/assets/admin.css             NEW
  admin/assets/admin.js              NEW
  app/.htaccess                      NEW: Require all denied
  app/lib/                           core library (§13)
  app/views/                         admin page views (PHP, escaped output only)
  app/templates/home.tpl.html        the home page template (source of truth)
  app/templates/post.tpl.html        post page template
  app/templates/archive.tpl.html     posts/index.html template
  app/templates/home-extra.css       CSS for new home features, emitted only when needed (§4.6)
  app/seed/baseline-index.html       frozen copy of index.html at 08da7c1 (T1 reference)
  app/seed/hero-reference.json       frozen hero region hashes (T3 reference)
  app/seed/site.baseline.json        extracted site copy (T1 content)
  app/seed/shelf.baseline.json       extracted shelf
  app/seed/posts/p00000000000{1..5}.json   the 5 teaser legs
  app/seed/settings.baseline.json    {subscribe off, chat off}
  app/seed/launch.patch.json         copy + settings changes applied for go-live (§9.6)
  app/bin/extract-seed.php           CLI: template -> seed files
  app/bin/check-template.php         CLI: T1 + T3 + template lint
  app/bin/render-launch.php          CLI: prints render(launch content) (used to refresh index.html)
  app/bin/publish.php                CLI: publish from a data dir (dev, SSH)
  app/dev/router.php                 php -S router that mimics the .htaccess denies
pilot-blog-work/backend/
  SPEC.md, PROGRESS.md
  tests/                             all tests (never deployed): php unit tests + Playwright admin tests
blog-data/                           local dev data dir, gitignored
```

`.gitignore` additions (root): `/blog-data/`, `/pilot-blog/posts/*` with `!/pilot-blog/posts/.htaccess`,
`/pilot-blog/uploads/*` with `!/pilot-blog/uploads/.htaccess`, `chat-config.php`, `*.tmp-*`.

### 2.2 Hostinger

```
/home/uXXXX/domains/<domain>/
  public_html/      ← contents of pilot-blog/ (deployed zip)
  blog-data/        ← created by the app on first request (mkdir 0700); never deployed
  chat-config.php   ← legacy key file, still honoured as a fallback (§10)
```

### 2.3 Paths in code

`app/lib/bootstrap.php` resolves:
- `PUBLIC_DIR` = `getenv('BLOG_PUBLIC_DIR') ?: dirname(__DIR__, 2)` (app/lib → pilot-blog)
- `DATA_DIR` = `getenv('BLOG_DATA_DIR') ?: dirname(PUBLIC_DIR) . '/blog-data'`
- `APP_DIR` = `dirname(__DIR__)`

The env overrides exist for tests (temp dirs) and are never set on Hostinger. Tests MUST NOT write into
the repo's `pilot-blog/index.html`, `posts/`, `uploads/` or `/home/user/momo/blog-data`.

### 2.4 Data dir layout

```
blog-data/
  .htaccess                  "Require all denied" (defence if the dir is ever under a web root)
  setup-code.txt             exists only until setup is done
  admin.json                 password hash, session epoch
  secrets.json               anthropic key, ip salt (0600)
  settings.json
  content/site.draft.json    content/site.live.json
  content/shelf.draft.json   content/shelf.live.json
  posts/<id>.json            one file per post (draft + live inside)
  trash/posts/<id>-<ts>.json deleted posts, purged after 30 days
  uploads/YYYY/MM/<hex>.webp|.jpg   master copies of every upload
  uploads/index.json         sizes of every upload
  subscribers.json
  usage/YYYY-MM-DD.json      Beijing-time days, kept 90 days
  ratelimit/<bucket>/<hash>.json
  sessions/                  PHP session files
  versions/<id>/             last 10 publishes (§6)
  locks/*.lock
  log/admin.log              JSON lines, audit events, rotated at 1 MB (keep 2)
```

All dirs 0700, files 0600 (data) / 0644 (public output). Every id or name that becomes part of a path
is validated against a whitelist regex first (§11.7).

---

## 3. Bootstrap and install

On every admin request and every api request, `app_init()`:
1. Creates `DATA_DIR` and its subdirs if missing, writes `blog-data/.htaccess`. If the dir cannot be
   created or written, admin shows one plain page: 「数据目录没法写入：{path}。请在 Hostinger 文件管理器里检查这个文件夹的权限。」 and api endpoints answer 503.
2. If `content/site.draft.json` is missing: installs the seed = baseline seed + `launch.patch.json`
   applied, as both draft and live, plus seed posts and default settings (`subscribe.enabled = true`,
   chat off). Does not publish (the deployed `index.html` already equals the launch render, T2).
3. Admin only: if `admin.json` is missing and `setup-code.txt` is missing or expired, creates
   `setup-code.txt` (§11.1).
4. Admin dashboard only: self-heal (§6.5).

---

## 4. Template system

### 4.1 Marker syntax

Markers are HTML comments with a brace right after `<!--`. No existing comment in the page uses that
form. Grammar (whitespace inside the braces is exactly one space between parts):

```
<!--{t KEY}-->default<!--{/t}-->              editable text field, encoding "text"
<!--{t KEY|ENC}-->default<!--{/t}-->          editable text field with encoding ENC
<!--{v NAME}-->default<!--{/v}-->             computed value (PHP function per NAME)
<!--{block NAME}-->default<!--{/block}-->     list block rendered by a PHP function
<!--{if COND}-->…<!--{/if}-->                 conditional (COND may be prefixed with !)
<!--{if COND}-->…<!--{else}-->…<!--{/if}-->
```

- `KEY` matches `^[a-z0-9_]+(\.[a-z0-9_]+)*$`, `NAME`/`COND` match `^[a-z_]+$`.
- `ENC` ∈ `text`, `attr`, `js`, `wbr`, `lat`, `li`, `paras:N` (N = integer).
- `t`, `v`, `block` contain no markers. `if` may contain `t`, `v`, `block` but not another `if`.
- Conditional **lines**: when an `if` guards whole lines, `<!--{if …}-->` sits at column 0 of the first
  guarded line (before its indentation) and `<!--{/if}-->` sits right after the last guarded line's `\n`,
  so a false condition removes the lines completely and leaves today's bytes.
- The text between an opening and closing marker is the **default** (what the page shows today). It is
  read only by the extractor; the renderer always replaces the whole marker span (markers included)
  with the rendered value. Defaults never reach the output directly.
- `indent` of a `t` or `block` node = the run of spaces between the previous `\n` and the opening
  marker, if the marker is preceded on its line by spaces only; otherwise `''`. `paras` and blocks use
  it as the joiner (`"\n" . indent`).
- In `post.tpl.html` and `archive.tpl.html`, `t` markers have an empty default
  (`<!--{t site.brand}--><!--{/t}-->`). Only `home.tpl.html` carries defaults.
- Unbalanced or malformed markers, an unknown KEY/NAME/COND/ENC, or nested `if` → `TemplateError`
  (publish aborts, nothing is written).

### 4.2 Encodings (per occurrence; one field can appear with several encodings)

| ENC | Render (value → bytes) | Decode (default → value) |
|---|---|---|
| `text` | `htmlspecialchars(v, ENT_NOQUOTES\|ENT_SUBSTITUTE\|ENT_HTML5, 'UTF-8')` (escapes `& < >`) | reject any `<`; `htmlspecialchars_decode(ENT_NOQUOTES)` |
| `attr` | `htmlspecialchars(v, ENT_QUOTES\|ENT_SUBSTITUTE\|ENT_HTML5, 'UTF-8')` | reject raw `"` and `<`; decode |
| `js` | body of a single-quoted JS string: `\`→`\\`, `'`→`\'`, `<`→`\x3C`, `>`→`\x3E`, LF→`\n`, CR→`\r`, U+2028→`\u2028`, U+2029→`\u2029` | inverse; reject raw `'` |
| `wbr` | value split on `\|`; each segment `text`-encoded; joined with `<wbr>` | split on `<wbr>`, decode each as `text`, join with `\|` |
| `lat` | `text`-encode, then wrap every match of `/[A-Za-z0-9](?:[A-Za-z0-9.×\- ]*[A-Za-z0-9])?/u` **that contains a digit** in `<span class="lat">…</span>` | remove `<span class="lat">`/`</span>` pairs, decode as `text` |
| `li` | value split on `\n`, empty lines dropped, each line → `<li>` + text + `</li>`, joined with `''` | must match `^(<li>[^<]*</li>)*$` |
| `paras:N` | every non-empty line of the value is one paragraph (blank lines ignored); paragraph k (0-based) → `<p class="rv" style="--i:{N+k}">` + text + `</p>`; joined with `"\n" . indent` | split on `"\n" . indent`, each part must match that exact `<p …>` with the right index; paragraphs are joined with a blank line (`\n\n`) in the stored value |

(In these tables `\|` is a Markdown-escaped `|`: the wbr separator is a plain `|`.)

Decode MUST be followed by a canonical check: `render(decode(default)) === default`, else
`TemplateError("non-canonical default for KEY")`. The lat regex was verified on all seed strings
(`PC-12 NGX`, `PT6E-67XP`, `货舱门 1.35 × 1.32 米` wrap; `T 型尾翼` and Chinese-only strings do not).

Field values are validated (§5.2) before rendering; encoders still escape everything, so no value can
break out of its context or inject markers (output is never re-parsed).

### 4.3 Computed values (`v`)

| NAME | Output | Baseline output |
|---|---|---|
| `chat_live` | `true` if `settings.chat.enabled` and a key is available, else `false` | `false` |
| `subscribe_endpoint` | `'api/subscribe.php'` if subscribe live, else `''` (includes the quotes) | `''` |
| `log_count` | text-encoded count line from patterns (§7.4) | `在写 5 篇` |
| `radio_act` | `'A1 ' . shelves[0].title`, text-encoded | `A1 对话与写作` |
| `radio_sby` | `'A' . (k+1) . ' ' . shelves[k].title`, k = min(1, n-1), text-encoded | `A2 图像与视频` |

Post and archive templates have their own values (`post_title`, `post_date_iso`, … see §7.6).

### 4.4 Conditions (`if`)

| COND | True when | Baseline |
|---|---|---|
| `subscribe_live` | `settings.subscribe.enabled` | false |
| `has_teasers` | at least one teaser leg is shown on the home page | true |
| `has_published` | at least one published post exists | false |
| `origin_placeholder` | `site.origin` contains `DEPLOY-DOMAIN` | true |

### 4.5 Blocks

| NAME | Location (inner content of the block in today's page) | Renders |
|---|---|---|
| `legs` | inside `<ol class="legs" data-io>`: from the first `<li` to the last `</li>` | home legs (§7.4) |
| `faq_rows` | inside `<dl class="cl-rows">`: from the first `<div class="cl-row"` to the last `</div>` of the 4th row | FAQ rows |
| `type_facts` | inside `<dl class="facts">`: the 6 `<div>…</div>` rows | 机型 facts |
| `shelf_json` | inside `<script type="application/json" id="shelf-data">\n` … `\n</script>`: the JSON text from `{` to `}` | shelf JSON |
| `extra_css` | empty block placed right before `</head>`: `</style>\n<!--{block extra_css}--><!--{/block}--></head>` | `''`, or `<style id="extra-css">` + home-extra.css + `</style>\n` when `subscribe_live` or `has_published` |

Exact render rules (these reproduce today's bytes; `ind` = block indent, `ind2` = `ind . '  '`):

**legs**, item k (0-based), `FIX`, `PREV` (= `LOGBK` for k = 0, else previous item's FIX):
```
'<li class="rv" style="--i:' . min(k,5) . '"><article class="post">' . "\n"
ind2 . '<span class="fix rp" data-route="' FIX '" aria-hidden="true">' FXSVG '<span class="fx-tag"><b>' FIX '</b></span></span>' . "\n"
ind2 . '<div class="leg-row"><span class="ln" aria-hidden="true">LEG ' (k+1) '</span><span aria-hidden="true">' PREV '<i class="to"></i>' FIX '</span><span class="lv" aria-hidden="true" data-crs="' FIX '">---°</span><span class="lv" aria-hidden="true" data-dis="' FIX '">-- NM</span><p class="strip-time"><span class="ete" aria-hidden="true">ETE</span>' ETE '</p></div>' . "\n"
ind2 . '<div class="strip-meta"><p class="strip-date">' DATE '</p>' [ '<p class="strip-tag">' TAG '</p>' ] '</div>' . "\n"
ind2 . '<h3>' TITLE_OR_LINK '</h3><p class="ex">' EXCERPT '</p>' . "\n"
ind . '</article></li>'
```
items joined with `"\n" . ind`; zero items render `''`. `FXSVG` is the literal triangle SVG from the
current page (`<svg class="fx" viewBox="-13 -13 26 26"><circle class="ring" r="14"/>…</svg>`, copy it
byte for byte from the first leg). Teaser: `DATE` = text(`log.teaser_date`), `ETE` = pattern
`log.ete_teaser`, `TITLE_OR_LINK` = text(title). Published: `DATE` =
`<time datetime="2026-10-14">2026.10.14</time>`, `ETE` = pattern `log.ete_published`, `TITLE_OR_LINK`
= `<a href="posts/{slug}.html">{text(title)}</a>`. `TAG` = first tag; the `<p class="strip-tag">` is
omitted when the post has no tag.

**faq_rows**, row k:
```
'<div class="cl-row" style="--i:' min(k,8) '"><dt><span class="nid" aria-hidden="true">' NID '</span><span class="q">' Q '</span><span class="lead" aria-hidden="true"></span><span class="r">' R '</span></dt><dd><p><span class="e" aria-hidden="true">E)</span>' A [ '<span class="mode-a">' text(sub.off_note) '</span>' ] '</p></dd></div>'
```
joined with `"\n" . ind`. The `mode-a` span is appended only when the row has `subscribe_note: true`
and subscribe is NOT live. The extractor sets `subscribe_note: true` on rows that carry the span and
asserts the span text equals `sub.off_note`. `NID` empty in data → auto `A01` + 2-digit (k+1) + `/` + 2-digit year of
the live site publish date.

**type_facts**, row: `'<div><dt>' K '</dt><dd><span class="val">' VAL '</span><span class="line">' LINE '</span></dd></div>'` joined with `"\n" . ind`.

**shelf_json**: build `{"shelves":[{"id","title","blurb","tools":[{"name","category","desc","url","cn_access"[, "note"]}]}]}` with
`category` = the shelf title and `note` only when non-empty, then
`json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG)` and
re-indent leading runs of 4 spaces to 2 spaces (`preg_replace_callback('/^(?: {4})+/m', …)`).
Verified: this reproduces today's 4 813-byte JSON exactly. The extractor asserts every tool's
`category` equals its shelf title.

All texts inside blocks use the `text` encoding (lat is not used in blocks).

### 4.6 home-extra.css

Small stylesheet for markup that only exists in non-baseline states. Must contain at least:
- stretched link on published legs: `.post h3 a{color:inherit;text-decoration:none}`,
  `.post h3 a::after{content:"";position:absolute;inset:0;z-index:1}`, visible hover underline,
  `.post:has(h3 a:focus-visible)` orange outline (the `.post` is already `position:relative`).
- `.sub-consent` (flex row, min-height 44px, 14px/1.6 secondary text, 20px checkbox with
  `accent-color:var(--accent)`), `.sub-hp` (off-screen: `position:absolute!important;left:-9999px;width:1px;height:1px;overflow:hidden`).
- Nothing that touches hero selectors (`.hero`, `.stage`, `.band`, `.tape`, `.static-hero`, `.nav`, `.poster*`, `.cue`, `.sky`, `.scrim`).

### 4.7 Building the template from today's page (one-time, Phase 0)

1. `cp pilot-blog/index.html app/seed/baseline-index.html` (from commit 08da7c1; record its sha256 in
   PROGRESS.md and in `check-template.php`).
2. Write `app/seed/hero-reference.json` (§15, T3) from the baseline.
3. Copy the baseline to `app/templates/home.tpl.html` and insert markers exactly as in §4.8, with a
   script (never by hand-retyping text). Default text between markers stays exactly as it is.
4. Insert the new conditional regions of §9.4 and the `extra_css` block. Because every new region is
   guarded by a condition that is false in the baseline, T1 keeps passing.
5. Register every key in `app/lib/fields.php` (§5.2).
6. `php app/bin/extract-seed.php` writes the seed files; `php app/bin/check-template.php` must print
   `T1 PASS` (sha256 of render == sha256 of baseline) and `T3 PASS`.

The template file starts with no extra header (that would change bytes). Put the explanation of the
template system in `app/templates/README.txt` instead. Developer comments already in the page stay
verbatim even where they are now stale (they are part of the T1 baseline).

**Changing the baseline later.** A deliberate design change outside the hero that alters the baseline
output is allowed only via `php app/bin/check-template.php --rebaseline "<reason>"`, which rewrites
`baseline-index.html` and logs the reason; T3 always compares against the frozen hero reference and
cannot be rebaselined.

### 4.8 Field registry and marker placement

Every row below is one marker occurrence group. "same key" = the same field rendered in several
places. Labels are what the owner sees. Limits: unless stated, `max` = max(20, 3 × seed length,
capped at 400) and `soft_max` (warning only: 「比原来长不少，手机上可能放不下，发布前请预览」) = ceil(1.5 ×
seed length) for headings, buttons, chips, kickers and all `hero.*` fields.

Group `site` 基本信息

| key | enc | where in the page | label |
|---|---|---|---|
| `site.brand` | text, attr | `<title>` before ` · `; `application-name`; `og:title` before ` · `; `og:site_name`; nav `.brand-name`; footer `.foot-mark .brand-name`; footer `© 2026 <span class="brand-name">` | 博客名 |
| `site.title_tail` | text, attr | `<title>` and `og:title` after ` · ` | 标题后半句（浏览器标签和分享卡片） |
| `site.description` | attr | `meta description`, `og:description` | 网站简介（搜索结果和分享卡片） |
| `site.origin` | attr | `og:image` and `og:url` content before `/assets/og.jpg` and `/` | 网站地址（例如 https://example.com，不要以 / 结尾） |
| `site.footer_year` | text | `<p>© <!--{t site.footer_year}-->2026<!--{/t}--> <span class="brand-name">` | 版权年份 |

The DEPLOY STEP comment line (line 16, including its trailing newline) is wrapped in `<!--{if origin_placeholder}-->…<!--{/if}-->`.

Group `nav` 导航

| key | enc | where | label |
|---|---|---|---|
| `nav.skip` | text | `.skip` link | 跳到正文（键盘用户才看得到） |
| `nav.about` `nav.log` `nav.type` `nav.ai` `nav.faq` | text | nav `#nav-links` items and the same footer `.foot-links` items (same key twice) | 导航：关于 / 日志 / 机型 / AI 超市 / 问答 |
| `nav.subscribe` | text | nav `.nav-cta` and footer link `订阅` | 导航：订阅按钮 |
| `nav.menu` | text | `.nav-toggle` | 手机菜单按钮 |

Group `hero` 开场动画（首页第一屏）. Hint for `wbr` fields: 「竖线 | 是手机上允许换行的位置，可以不加。」

| key | enc | where | label |
|---|---|---|---|
| `hero.metar` | text | `.b1 .chip` and `.static-hero .chip` (same key) | 天气报文字条 |
| `hero.b1.hl` / `hero.b1.sub` | wbr | band b1 `h1.hl`, `p.sub` | 第 1 段大字 / 小字 |
| `hero.b2.chip` | text | band b2 chip | 第 2 段字条 |
| `hero.b2.hl` / `hero.b2.sub` | wbr | band b2 | 第 2 段大字 / 小字 |
| `hero.b3.chip` | text | band b3 chip | 第 3 段字条 |
| `hero.b3.hl` / `hero.b3.sub` | text | b3 `p.sr` (both keys adjacent, hl then sub), `.copy.soft` and `.copy.sharp` (`p.hl`, `p.sub`) | 第 3 段大字 / 小字 |
| `hero.b4.hl` | wbr | band b4 `p.hl` | 第 4 段大字 |
| `hero.b4.sub` | text | band b4 `p.sub.ks` | 第 4 段小字 |
| `hero.cta_primary` / `hero.cta_secondary` | text | b4 `.cta-row` and `.static-hero .cta-row` (same keys) | 开场按钮：订阅 / 看日志 |
| `hero.cue` | text | `.cue span` | 向下滚动提示 |
| `hero.static.h1` / `hero.static.sub` | text | `.static-hero .st-top` | 静态版标题 / 小字（省流量模式和不能播视频时显示） |

Not editable: the tape, `.st-strip`, every attribute in the hero.

Group `chart` 航路图标题

| key | enc | where | label |
|---|---|---|---|
| `chart.title` | text | `.chh-t` | 航路图标题 |
| `chart.go` | text | `.chh-go` | 航路图提示 |
| `chart.disclaimer` | text | `.chh-cells dd.cn` and footer `.foot-chart` after `<br>` (same key) | 示意图声明 |

Group `about` 关于

| key | enc | where | label |
|---|---|---|---|
| `about.kicker` | text | `.about-text .kicker` | 小标签 |
| `about.h2` | text | `#about-h` | 标题 |
| `about.body` | paras:3 | the three `<p class="rv" style="--i:3..5">` (marker before the first `<p`, after the last `</p>`) | 正文（每段一行） |
| `about.staccato` | text | `.staccato` | 结尾短句 |
| `about.fact1`…`about.fact4` | lat | the four `.logbook dd` (contents, including the `.lat` span of fact1) | 机型 / 座位 / 航线 / 天气 |
| `about.img_alt` | (none) | mirror of `album[0].alt` since §18 item 25 (was the `.about-art img` alt) | 照片说明（读屏软件读这句） |
| `about.figcaption` | (none) | mirror of `album[0].caption` since §18 item 25 (was `.about-fig figcaption`) | 照片下方小字 |

Group `type` 机型

| key | enc | where | label |
|---|---|---|---|
| `type.icao` | li | `ul.icao` contents | 代码条（一行一个） |
| `type.gloss` | text | `.icao-gloss` | 代码解释 |
| `type.kicker` `type.h2` `type.lede` | text | `.lg-body` | 小标签 / 标题 / 导语 |
| `type.callout1`…`type.callout4` | lat | each `.callouts li`, between `</svg>` and `<span class="lk"` | 图注 1-4（对应线图上的 1-4 号，固定 4 条） |
| (block) `type_facts` | | `dl.facts` | 数据格子（3 或 6 条最整齐） |
| `type.fnote` | text | `.fnote` after the `SOURCE` span | 数据来源说明 |

Group `apch` 进近小游戏

| key | enc | where | label |
|---|---|---|---|
| `apch.kicker` `apch.h2` `apch.ins` | text | `.fly-ctl` | 小标签 / 标题 / 玩法说明 |
| `apch.hold_btn` | text, js | `#hold-label` and `T.holdBtn` | 按钮文字 |
| `apch.idle` | text, js | `#fly-status` and `T.flyIdle` | 等待时的提示 |
| `apch.holding` `apch.go_around` `apch.done` `apch.again` | js | `T.flyHold`, `T.flyGa`, `T.flyDone`, `T.holdAgain` | 下降中 / 复飞 / 完成 / 再飞一次 |

Group `log` 飞行日志栏

| key | enc | where | label |
|---|---|---|---|
| `log.kicker` `log.h2` | text | `.plate-head` first kicker, `#log-h` | 小标签 / 标题 |
| `log.lede` | text | `.log-intro .lede` | 导语 |
| `log.list_end` | text | `.list-end`; the whole `<p class="list-end …">…</p>` line is wrapped in `<!--{if has_teasers}-->…<!--{/if}-->` (the `if` spans from the line's indentation to the trailing newline) | 有预告时显示的一句 |
| `log.sub_link` | text | `.log-intro .tlink` | 订阅链接文字 |
| `log.all_link` | text | NEW line after the subscribe link, inside `<!--{if has_published}-->`: `<p class="rv" style="--i:4"><a class="tlink" href="posts/">…</a></p>`; default `全部日志` | 全部日志链接 |
| `log.hold_word` | text | `.hold-lbl` between `<b>HOLD</b> · ` and `<br>` | 等待航线旁的小字 |
| `log.count_writing` | pattern `{t}` | via `v log_count`; default `在写 {t} 篇` | 计数：只有预告 |
| `log.count_latest` | pattern `{p}` | default `最新 {p} 篇` | 计数：只有已发布 |
| `log.count_mixed` | pattern `{p}` `{t}` | default `最新 {p} 篇 · 在写 {t} 篇` | 计数：两种都有 |
| `log.count_none` | text | default `还没有日志` | 计数：一篇都没有 |
| `log.teaser_date` | text | legs block, default `DRAFT` | 预告的日期位置显示 |
| `log.ete_teaser` | pattern `{n}` | legs block, default `预计 {n} 分钟` | 预告的阅读时间 |
| `log.ete_published` | pattern `{n}` | legs block and post pages, default `{n} 分钟` | 已发布的阅读时间 |

The count kicker becomes `<p class="kicker"><!--{v log_count}-->在写 5 篇<!--{/v}--></p>`.

Group `ai` AI 超市栏

| key | enc | where | label |
|---|---|---|---|
| `ai.kicker` `ai.h2` `ai.lede` | text | `.comm-head` kicker, `#ai-h`, `.comm > .lede` | 小标签 / 标题 / 导语 |
| `ai.shelf_note` | text | `.shelf-note` | 货架底部声明 |
| `ai.card_open` | js | shelf script: `document.createTextNode('打开')` | 工具卡片上的「打开」 |
| (values) `radio_act`, `radio_sby` | | `#f-act-n`, `#f-sby-n` | computed |

Group `chat` AI 助手对话框

| key | enc | where | label |
|---|---|---|---|
| `chat.twr` | text | `.twr` text after `<b>TWR</b> ` | 塔台频率字样 |
| `chat.h3` `chat.lede` `chat.caution` `chat.privacy` | text | `#chat-h`, `.chat-lede`, `.chat-caution`, `.chat-privacy` | 标题 / 说明 / 提醒 / 隐私说明 |
| `chat.chip1`…`chat.chip3` | text | the three `#chips button` | 示例问题 1-3 |
| `chat.label` | text | `label[for=chat-in]` | 输入框说明（读屏） |
| `chat.placeholder` | attr | `#chat-in` placeholder | 输入框提示 |
| `chat.send` | text, js | `#chat-send` and `T.send` | 发送按钮 |
| `chat.lamp_inop` | text, js | `#lamp-txt` initial text and `LAMP.inop[1]` | 状态灯：接入中 |
| `chat.lamp_stby` `chat.lamp_tx` `chat.lamp_on` | js | `LAMP.stby[1]`, `LAMP.tx[1]`, `LAMP.on[1]` | 状态灯：待命 / 发送中 / 在线 |
| `chat.welcome_inop` `chat.welcome_live` `chat.msg_inop` `chat.msg_down` `chat.msg_timeout` `chat.msg_rate` `chat.msg_limit` `chat.sending` `chat.answering` | js | `T.welcomeInop`, `T.welcomeLive`, `T.chatInop`, `T.chatDown`, `T.chatTimeout`, `T.chatRate`, `T.chatLimit`, `T.sending`, `T.answering` | 对话提示语 |

Group `faq` 问答

| key | enc | where | label |
|---|---|---|---|
| `faq.card_head` | text | `.cl-head` | 卡片标题 |
| `faq.kicker` `faq.h2` | text | `.cl-body` | 小标签 / 标题 |
| (block) `faq_rows` | | `dl.cl-rows` | 问答条目 |
| `faq.sub_link` | text | `.faq .after .tlink` | 订阅链接文字 |

Group `sub` 订阅

| key | enc | where | label |
|---|---|---|---|
| `sub.kicker` `sub.h2` `sub.lede` `sub.sub2` | text | `.sub-card` | 小标签 / 标题 / 导语 / 说明 |
| `sub.label` | text | `label[for=sub-email]` | 输入框标题 |
| `sub.placeholder` | attr | `#sub-email` placeholder | 输入框提示 |
| `sub.button` | text, js | `#sub-btn` and the `subBtn.textContent = '…'` reset in BOTH subscribe scripts | 订阅按钮 |
| `sub.micro` | text | first `.micro` | 按钮下方小字 |
| `sub.off_note` | text | `.micro.mode-a` (line wrapped in `<!--{if !subscribe_live}-->`) and the FAQ `mode-a` span (rendered by `faq_rows`) | 订阅关闭时的说明 |
| `sub.consent` | text | NEW, inside `<!--{if subscribe_live}-->` (§9.4) | 同意勾选框文字 |
| `sub.msg_empty` `sub.msg_format` `sub.msg_off` `sub.msg_busy` `sub.ok_pre` `sub.ok_post` `sub.msg_fail` | js | `T.subEmpty`, `T.subFormat`, `T.subModeA`, `T.subBusy`, `T.subOkPre`, `T.subOkPost`, `T.subFail` | 订阅提示语 |
| `sub.msg_consent` `sub.msg_rate` | js | NEW `T.subConsent`, `T.subRate` inside `<!--{if subscribe_live}-->` (§9.4) | 没勾同意 / 提交太频繁 |

Group `footer` 页脚

| key | enc | where | label |
|---|---|---|---|
| `footer.tag` | text | `.foot-tag` | 页脚短句 |
| `footer.note1` `footer.note2` | text | first two `.foot-notes p` | 页脚说明 1 / 2 |

Group `post` 日志页面 (registry defaults; used by post and archive templates only)

| key | default |
|---|---|
| `post.back` | 回到航路图 |
| `post.all` | 全部日志 |
| `post.prev` / `post.next` | 上一篇 / 下一篇 |
| `post.end_kicker` | ON BLOCKS |
| `post.end_line` | 这一段飞完了。 |
| `post.sub_line` | 想读下一篇，可以订阅。写完以后，我会从订阅名单里发到你的邮箱。 |
| `post.sub_btn` | 订阅飞行日志 |
| `post.tags` | 标签 |
| `archive.kicker` | LOGBOOK |
| `archive.title` | 全部日志 |
| `archive.lede` | 按发布时间排列，新的在上面。 |
| `archive.empty` | 还没有发布的日志。 |

Deliberately NOT editable (chart furniture; leave as literal text): wordmark/footer mono codes
(`PC12/L`, `PC12/L · L1T`), `ENROUTE CHART · NIGHT`, chart cells, every fix ident, navbox, `INSET A`,
hold racetrack label codes, leg row codes, `LEGEND · AIRCRAFT`, `.lk` codes, `SOURCE`, the outline SVG,
radio frequencies, `TWR`, MSA, approach plate brief and labels, mins strip, runway, `NOTAM · CHKLT`,
`E)`, `CHART 10-1 · ENROUTE · NIGHT`, aria-labels, chart.js generated labels, `api/chat.php` error
messages. The 网站文字 page says once: 「图上的航空代码和数字是装饰，不在这里改。」

---

## 5. Content model and JSON schemas

All JSON files are UTF-8, `JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES`. Times are
UTC ISO 8601 (`2026-10-14T08:05:00Z`); shown to the owner in Asia/Shanghai.

### 5.1 `content/site.draft.json` and `content/site.live.json`

```json
{
  "rev": 7,
  "updated_at": "2026-10-14T08:05:00Z",
  "fields": {
    "site.brand": "云底之下",
    "site.title_tail": "一位 PC-12 机长的飞行日志",
    "site.origin": "https://DEPLOY-DOMAIN",
    "hero.b1.hl": "三千英尺，|云顶之上。",
    "about.body": "我是机长，飞 PC-12 NGX，跑短途支线。航段短，一天要起降好几次。\n\n这些航线……\n\n我写这个博客……",
    "log.count_writing": "在写 {t} 篇"
  },
  "faq": [
    { "nid": "A0101/26", "q": "作者真的在飞吗？", "r": "在飞", "a": "我是现役机长，……", "subscribe_note": false },
    { "nid": "A0104/26", "q": "订阅之后会收到什么？", "r": "只有日志", "a": "每篇一封邮件。……", "subscribe_note": true }
  ],
  "type_facts": [
    { "k": "ENG", "val": "PT6E-67XP", "line": "单台普惠加拿大涡桨发动机，……" }
  ]
}
```
The live file has the same shape plus `"published_at"` and `"from_rev"`.

### 5.2 Validation (`fields.php`)

- `fields` must contain exactly the registry keys (missing → registry default or seed value; unknown →
  rejected). Values are strings; no control characters except `\n` in multiline fields (`about.body`,
  `type.icao`); single-line fields reject newlines with 「这一项只能写一行。」.
- `|` is allowed only in `wbr` fields. Pattern fields must contain their placeholders
  (「这一项里要保留 {t}，它会换成数字。」) and no other `{…}`.
- `site.origin`: `^https?://[A-Za-z0-9.-]+(:[0-9]+)?$` (the seed value `https://DEPLOY-DOMAIN` must pass). `site.footer_year`: `^[0-9]{4}$`.
- FAQ: 1..12 rows; `nid` `^[A-Z0-9/]{0,12}$`; `q` ≤ 40; `r` ≤ 12; `a` ≤ 300; `subscribe_note` bool.
- type_facts: 1..9 rows; `k` `^[A-Z0-9]{1,6}$`; `val` ≤ 14; `line` ≤ 120.
- Owner text is never blocked for style (no copy gate on owner text).

### 5.3 `content/shelf.draft.json` / `shelf.live.json`

```json
{
  "rev": 3,
  "updated_at": "2026-10-14T08:05:00Z",
  "shelves": [
    {
      "id": "aviation",
      "title": "航空工具",
      "blurb": "查天气、看航迹、翻航图的几个网站。不算 AI，顺手放在这里。",
      "tools": [
        { "name": "Windy", "desc": "风、云、降水预报图，一眼看清趋势。", "url": "https://www.windy.com", "cn_access": null, "note": "飞之前我先在这里看风和云的走势，……" },
        { "name": "飞常准", "desc": "国内航班动态，还能看 ADS-B 航迹。", "url": "https://www.variflight.com", "cn_access": "能直接访问", "note": "" }
      ]
    }
  ]
}
```
Rules: 1..8 shelves (publish needs ≥ 1 shelf and ≥ 1 tool per shelf); ≤ 16 tools per shelf; `id`
`^[a-z0-9]+(-[a-z0-9]+)*$` ≤ 32, unique (new shelves get `s` + 6 random hex); `title` ≤ 12; `blurb` ≤ 60;
`name` ≤ 30; `desc` ≤ 40; `url` must parse with scheme http/https and a host, ≤ 300; `note` ≤ 120,
empty string = no note; `cn_access` ∈ `"能直接访问"`, `"需要国际网络"`, `null` (shown as 还没确认, card gets no
badge, matching the current JS).

### 5.4 `posts/<id>.json`

```json
{
  "id": "p3f9a1c0b7e21",
  "rev": 4,
  "slug": "minms",
  "fix": "MINMS",
  "created_at": "2026-10-14T08:00:00Z",
  "updated_at": "2026-10-14T09:10:00Z",
  "first_published_at": null,
  "teaser_order": 1,
  "draft": {
    "title": "低云进近：决断高度前的那两秒",
    "excerpt": "云底 300 英尺，能见度 2000 米。……",
    "tags": ["IMC"],
    "body": "## 进近简令\n落地前二十分钟……\n\n![跑道入口灯](uploads/2026/10/9c1d2e3f4a5b6c7d.webp \"雾里的绿色入口灯\")",
    "cover": { "src": "uploads/2026/10/0a1b2c3d4e5f6071.webp", "og": "uploads/2026/10/0a1b2c3d4e5f6071.jpg", "w": 2000, "h": 1125, "alt": "云底下的跑道" },
    "date": "2026-10-14",
    "minutes": 8
  },
  "live": {
    "mode": "teaser",
    "published_at": "2026-10-13T00:00:01Z",
    "title": "低云进近：决断高度前的那两秒",
    "excerpt": "……", "tags": ["IMC"], "body": "", "cover": null, "date": "2026-10-13", "minutes": 8
  }
}
```
- `id` `^p[0-9a-f]{12}$` (seed ids `p000000000001`…`p000000000005`, in leg order).
- Status shown to the owner: `live == null` → 草稿; `live.mode == "teaser"` → 预告; `"full"` → 已发布.
  `有未发布的修改` when the draft differs from `live` (compare the draft fields).
- `slug` `^[a-z0-9]+(-[a-z0-9]+)*$` ≤ 60, unique; default = lowercase fix. Locked once
  `first_published_at` is set (field read-only with 「已经发布过，网址不能再改，否则旧链接会失效。」).
- `fix` `^[A-Z]{5}$`, unique across all posts, not in `DEP PILOT LOGBK PC12X AISHP CHKLT FINAL RW24`.
  Suggestion for a new post: first 5 letters of the slug uppercased, padded with `X`; on collision,
  5 random letters.
- `title` 1..60; `excerpt` ≤ 160 (required for teaser; for full, empty = first 80 chars of the body's
  plain text); `tags` 0..5, each 1..16 chars, no `<>{}|`; `body` ≤ 60 000 chars; `date` valid
  `YYYY-MM-DD` (default: today in Beijing time when first published); `minutes` 1..120 or null (full:
  null = computed, §7.3; teaser: required).
- Seed teasers: the five legs in order, `teaser_order` 1..5, `live.mode = "teaser"`, empty body,
  `minutes` 8, 7, 9, 6, 5, tags `IMC`, `PERF`, `WX`, `OPS`, `PREFLT`.

### 5.5 `settings.json`

```json
{
  "rev": 5,
  "updated_at": "2026-10-14T08:05:00Z",
  "chat": { "enabled": false, "daily_limit": 300, "ip_limit": 20, "ip_window_min": 10, "extra_note": "" },
  "subscribe": { "enabled": true },
  "home": { "leg_limit": 6 }
}
```
Ranges: `daily_limit` 0..5000, `ip_limit` 1..200, `ip_window_min` fixed 10 (not shown), `extra_note`
≤ 1000 chars, `leg_limit` 3..12.

### 5.6 `secrets.json` (0600, never sent to any browser, never logged)

```json
{ "anthropic_api_key": "sk-ant-…", "key_set_at": "2026-10-14T08:05:00Z", "ip_salt": "64 hex chars" }
```

### 5.7 `admin.json`

```json
{ "version": 1, "password_hash": "$2y$12$…", "created_at": "…", "password_changed_at": "…", "session_epoch": 3 }
```

### 5.8 `subscribers.json`

```json
{
  "rev": 128,
  "items": [
    { "email": "reader@example.com", "created_at": "2026-10-14T08:05:00Z", "consent": "我同意本站保存这个邮箱，只用来给我发飞行日志。", "source": "home" }
  ]
}
```
No IP, no user agent. Hard cap 20 000 items.

### 5.9 `usage/2026-10-14.json` (day in Asia/Shanghai)

```json
{
  "date": "2026-10-14",
  "chat": {
    "requests": 12, "answered": 10, "refused": 0, "tokens_in": 5230, "tokens_out": 1890,
    "errors": { "disabled": 0, "not_configured": 0, "bad_request": 1, "rate_limited_ip": 1, "daily_limit": 0,
                "upstream_busy": 0, "upstream_error": 0, "upstream_unreachable": 0, "empty_reply": 0 }
  },
  "subscribe": { "ok": 3, "invalid": 1, "rate_limited": 0, "bot_dropped": 2, "closed": 0 }
}
```

### 5.10 Others

- `ratelimit/<bucket>/<sha256(salt+key)>.json`: `{"start": 1760429100, "count": 3}`.
- `uploads/index.json`: `{"items": {"uploads/2026/10/0a1b2c3d4e5f6071.webp": {"w": 2000, "h": 1125, "og": "uploads/2026/10/0a1b2c3d4e5f6071.jpg", "bytes": 183552, "created_at": "…"}}}`.
- `versions/<id>/manifest.json` (id `^\d{8}-\d{6}-[0-9a-f]{6}$`):
```json
{ "id": "20261014-160501-a1b2c3", "created_at": "…", "reason": "发布日志《低云进近：决断高度前的那两秒》",
  "content_hash": "sha256 of the live snapshot set", "template_hash": "sha256 of the three templates + home-extra.css",
  "files": { "index.html": "sha256…", "posts/index.html": "sha256…", "posts/minms.html": "sha256…" } }
```
  plus `versions/<id>/live/{site.json, shelf.json, posts/<id>.json}` and `versions/<id>/files/…`.
- `log/admin.log`: `{"t":"…","event":"login_ok|login_fail|setup_done|publish|rollback|key_set|key_removed|password_changed|subs_deleted|subs_exported","ip":"<ip hash>","detail":"…"}`. Never secrets, never passwords, never emails in bulk (a delete logs the count).

---

## 6. Publishing, versions, rollback

### 6.1 `publish_site(string $reason, array $opts = []): array`

1. `with_lock('publish', …)`.
2. Load live snapshots: `site.live`, `shelf.live`, all posts with `live != null`, settings, key presence.
3. Build the home context (§13) and render `index.html`; render every `full` post page; render
   `posts/index.html`. Any `TemplateError` or validation error → abort, return `{ok:false, errors}`;
   nothing is written.
4. Sanity check the home output: starts with `<!doctype html>`, ends with `</html>\n`, contains
   `id="hero-video"`, `id="shelf-data"`, `id="chart"`, length > 150 000 bytes, the shelf JSON
   `json_decode`s. Fail → abort.
5. `uploads_sync_public()` (copy any master upload missing from `public/uploads/`).
6. Write files with `file_write_atomic()` in this order: post pages, archive, `index.html`. Then delete
   `posts/*.html` files that are not in the new set (only names matching `^[a-z0-9-]+\.html$`; never
   `.htaccess`). Ensure `posts/.htaccess` exists (rewrite it from `app/templates` if missing).
7. Version: compute `content_hash`. If `$opts['version'] !== false` and the hash differs from the
   newest version's, create `versions/<id>/` (manifest, live snapshots, copies of written files) and
   prune to the newest 10. Settings-only republishes pass `version => false`.
8. Append `publish` to the audit log. Return `{ok:true, version_id, files:[…]}`.

### 6.2 Who calls it

| Action | reason text | version |
|---|---|---|
| 网站文字 发布 | 更新网站文字 | yes |
| AI 超市 发布 | 更新 AI 超市 | yes |
| post publish / 发布修改 / 设为预告 / 撤下 / delete of a live post | 发布日志《…》 / 更新日志《…》 / 预告《…》 / 撤下日志《…》 / 删除日志《…》 | yes |
| assistant on/off, key set/removed (when `chat_live` flips), subscribe on/off, leg limit, site address quick-set | (none) | no |
| 重新发布全部 | 重新生成全部页面 | only if content changed |
| rollback | 回滚到 {时间} 的版本 | yes |

### 6.3 Rollback `version_rollback(string $id): array`

Restores the live snapshots from the version, never touches drafts:
- `site.live`, `shelf.live` ← version copies.
- For each post in the version: if the post file exists, set its `live` (and `first_published_at` if
  it was null) from the version; if it was deleted, recreate it from the trash copy or, if purged, from
  the version snapshot (draft = live). If the slug is now used by another post, skip it and report
  「《…》没有恢复：它的网址已经被另一篇用了。」.
- For each post live now but absent from the version: set `live = null` (back to 草稿, content kept).
- Then `publish_site('回滚到 …')` with the current templates and settings. The stored HTML in the
  version is used only for 「看一看」 previews.

### 6.4 Atomic write

`file_write_atomic($path, $bytes, $mode)`: write to `dirname($path)/.` . basename . `.tmp-` . 8 hex
(dot-prefixed, so the root `.htaccess` denies it), `fflush` + `fsync`, `chmod`, `rename` over the target.
On failure, unlink the temp file and throw.

### 6.5 Status and self-heal

`site_status()` returns: per unit whether draft ≠ live; whether `public/index.html` is missing or its
sha256 differs from the newest manifest's; whether `template_hash` differs from the newest manifest's;
whether no manifest exists. When the dashboard loads and any of the last three is true, it runs
`uploads_sync_public()` + `publish_site(…)` once and shows 「网站文件和后台内容不一致（多半是网站程序刚
更新过），已经重新生成了页面。」 (or the error). When no version exists yet (fresh install) it calls
`publish_site('初始内容')` (creates version 1, no message); otherwise
`publish_site('网站程序更新后重新生成页面', ['version' => false])`.

---

## 7. Flight-log posts

### 7.1 Lifecycle and actions

| Action | Allowed from | Effect |
|---|---|---|
| 写新日志 | (any time) | creates a draft with suggested fix and slug, opens the editor |
| 保存草稿 | any | writes `draft`, bumps `rev`; public site unchanged |
| 发布 / 发布修改 | any | validates for `full` (title, body ≥ 1 paragraph, fix, slug, date); `live = draft + mode full`; sets `first_published_at` and locks slug; publishes |
| 设为预告 | any | validates for teaser (title, excerpt, minutes); `live = draft + mode teaser`; publishes. For a published post the button reads 改回预告 and confirms 「这篇的页面会撤下，首页只留标题和摘要。」 |
| 撤下 | teaser or published | `live = null`; publishes; page file removed |
| 删除 | any | confirm 「删除后这篇草稿也没有了，只能从版本记录里找回已发布过的内容。」; moves file to trash; publishes if it was live |

### 7.2 Markdown subset (`markdown.php`, own parser, no library)

Input: `\r\n`→`\n`, control chars except `\n` removed, max 60 000 chars. Line-based blocks:

| Source | Output |
|---|---|
| any other non-empty line | `<p>…</p>` (**every line is its own paragraph**; this matches how people type on a phone; blank lines are ignored) |
| `# x` or `## x` | `<h2>x</h2>` (the title is the page's only h1) |
| `### x` | `<h3>x</h3>` |
| consecutive `- x` / `* x` lines | `<ul><li>…</li></ul>` |
| consecutive `1. x` lines | `<ol><li>…</li></ol>` |
| consecutive `> x` lines | `<aside class="note"><p class="note-k">NOTE</p><p>…</p>…</aside>` |
| `---` (3+ dashes alone) | `<hr>` |
| a line that is only `![alt](uploads/… "caption")` | `<figure><img src="{base}uploads/…" width height alt loading="lazy" decoding="async">[<figcaption>caption</figcaption>]</figure>`; the path MUST match `^uploads/\d{4}/\d{2}/[0-9a-f]{16}\.(webp\|jpg)$` (else the line renders as plain text); width/height from `uploads/index.json` |

Inline, inside any text: `**x**` → `<strong>`, `` `x` `` → `<code>`, `[text](url)` → link. URL rules:
`https?://` with a host → `<a href rel="noopener noreferrer" target="_blank">`; site paths
`posts/<slug>.html`, `posts/`, `#<id>` (`[a-z0-9-]+`) → same-tab link with the right relative prefix;
anything else (including `javascript:`, `data:`, `//host`) renders as plain text. `\*`, `\[`, `` \` ``,
`\\` escape literally. Implementation: tokenize into text/markup tokens, `text`-encode every text token,
attribute-encode every URL; raw `<` from the source always ends up as `&lt;`.

Helpers: `md_plain()` (text only, for excerpts and counting), `md_excerpt($src, 80)`,
`md_reading_minutes($src)` = max(1, round(Han chars / 400 + Latin words / 200)).

### 7.3 Reading time

Published leg and page: `minutes` if set, else `md_reading_minutes(body)`. Teaser: `minutes` (required).

### 7.4 Home legs

`home_legs(posts, leg_limit)`: list = published (`live.mode == full`) sorted by `live.date` desc then
`published_at` desc, followed by teasers sorted by `teaser_order` asc; keep the first `leg_limit`.
`log_count`: p = published legs shown, t = teaser legs shown → `count_writing` (p = 0, t > 0),
`count_latest` (p > 0, t = 0), `count_mixed` (both), `count_none` (neither). Placeholders replaced
before `text` encoding. `has_teasers` = t > 0; `has_published` = any published post exists (the 全部日志
link shows even when every post fits on the home page).

### 7.5 chart.js with 0..N legs

Reviewed: `chartBuild()` walks every `[data-route]` anchor in DOM order, skips hidden ones and
near-duplicates, and finds course/distance targets by `data-crs`/`data-dis` = fix name; nothing counts
legs. CSS uses `:nth-child(even)` only. So no change is needed, with two constraints the publisher
enforces: fix idents are unique and never reuse a section fix (a duplicate would make
`$('[data-crs="X"]')` fill the wrong leg), and the rv stagger index is capped (`min(k,5)`). Test T6
proves 0, 1, 2, 5, 7 and 12 legs at 390 and 1440 wide. If T6 finds a real problem, fix it in
`home.tpl.html` outside the hero regions and say so in PROGRESS.md.

### 7.6 Post page (`posts/<slug>.html`) and archive (`posts/index.html`)

Static HTML, no JavaScript, styles from `assets/post.css?v=<first 8 hex of its sha256>`, fonts preloaded
from `../assets/fonts/` exactly like the home page, same favicon data URI. All links relative
(`../`, `./`, `../#subscribe`, `../uploads/…`). Head: `<html lang="zh-CN">`, title
`{post title} · {site.brand}`, meta description = excerpt, `og:type article`, `og:title`,
`og:description`, `og:image` = origin + `/` + cover `og` jpg (else `/assets/og.jpg`), `og:url` = origin +
`/posts/{slug}.html`, `theme-color #0A1733`, `color-scheme dark`.

Visual language (same chart family, calm for reading):
- Body background `--canvas` with a faint graticule: two `repeating-linear-gradient`s, 1px lines in
  `rgba(94,110,162,.12)` every 160px; no grain image, no animation.
- Top bar: wordmark (brand + `PC12/L`) linking to `../`, links 全部日志 (`./`) and an orange-outline
  订阅 button (`../#subscribe`, only when subscribe is live). Height 56px, sticky, solid
  `rgba(10,23,51,.92)`.
- Head card ("flight strip"): bordered `1px var(--line-strong)` panel `rgba(12,27,64,.88)`, radius 3px.
  Row 1 mono 12px: the fix diamond SVG (route magenta `#E25AD9`) + FIX, `<time>` date `2026.10.14`,
  first tag in the `.strip-tag` style, `ETE {n} 分钟`. Then `<h1>` (26px phone / 34px desktop, 1.4) and
  the excerpt as lede (secondary text).
- Desktop ≥ 900px: a 3px magenta route rail runs down the left edge of the article column from the
  head card's fix symbol to the end card; phone: no rail, the head card gets a 3px magenta top border.
- Cover: `<figure>` full column width, `width`/`height` attributes, `loading="eager"`, alt from data;
  small mono tag `INSET` top-left like the home figure.
- Body: column max-width 38em; `p` 18px/1.95 (phone 17px/1.9), margin `0 0 1.1em`, `max-inline-size:none`,
  `line-break:strict`, `text-wrap:pretty`, text-align start; `h2` 22px, `h3` 19px, both preceded by a
  12px mono magenta tick; `strong` primary text; `code` mono 0.92em on a panel chip; `ul/ol` 1.4em
  indent; `aside.note` panel with a 3px `--signal` left border and a mono `NOTE` label; `hr` = a dashed
  1px `--line-strong` line with a small centred diamond; `figure img` radius 3px, `figcaption` mono
  12px secondary; links underlined `--line-strong`, primary text.
- End card: `post.end_kicker` mono + `post.end_line`; tag list; then (subscribe live only) a subscribe
  box with `post.sub_line` and an orange button `post.sub_btn` → `../#subscribe`.
- Prev/next: two strip cards (`post.prev` older / `post.next` newer) showing fix, date, title; omit the
  missing side.
- Footer: same copy as the home footer (`site.brand`, `footer.tag`, notes, `© year brand`,
  `chart.disclaimer`), simplified layout.
- Phone: 16px side gutter minimum, no horizontal scroll at 360–430px, tap targets ≥ 44px.

Archive: same chrome; `archive.kicker`, `archive.title` h1, `archive.lede`; posts grouped by year
(`<h2>2026</h2>`) as strip cards (fix, date, tag, ETE, linked title, excerpt); `archive.empty` when none.
Teasers do not appear in the archive.

Post/archive template values: `post_title`, `post_excerpt`, `post_date_iso`, `post_date_dots`,
`post_fix`, `post_minutes`, `post_slug`, `post_og_image`, `post_url`, `css_href`; blocks: `post_cover`,
`post_body`, `post_tags`, `post_prevnext`, `archive_list`; conditions: `subscribe_live`, `has_cover`.

---

## 8. AI 超市 shelf

Editor operations: add/edit/delete/reorder shelves; add/edit/delete/reorder tools; move a tool to
another shelf; set `cn_access` (能直接访问 / 需要国际网络 / 还没确认). Save, preview, publish like 网站文字.
Rendering: `shelf_json` block + `radio_act`/`radio_sby` values. The radio cycles 5 frequencies; with
more than 5 shelves frequencies repeat (accepted). Deleting a shelf with tools asks
「这个货架里还有 N 个工具，一起删除吗？」.

---

## 9. Subscribers

### 9.1 `api/subscribe.php`

- POST only (405 otherwise). Accepts `application/x-www-form-urlencoded` or `multipart/form-data`:
  `email`, `consent` (`"1"`), `website` (honeypot), `t` (ms since page load).
- Always JSON, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`.
- If an `Origin` header is present and its host differs from `HTTP_HOST` → 403
  `{error:'forbidden', message:'这次没有提交成功。请从本站页面提交。'}`.
- `settings.subscribe.enabled` false → 503 `{error:'closed', message:'订阅暂时关闭了，这次没有保存你的邮箱。'}`.
- Rate limits (`rl_hit`): per IP 5/hour and 20/day, global 300/day → 429
  `{error:'rate_limited', message:'提交得有点频繁，过一会儿再试。'}`.
- Honeypot non-empty, or `t` < 1500 → 200 `{ok:true}` without storing (count `bot_dropped`).
- Email: trim, lowercase, length ≤ 254, `filter_var(FILTER_VALIDATE_EMAIL)`, domain contains a dot →
  else 400 `{error:'invalid_email', message:'邮箱格式不对，请再检查一遍。'}`.
- `consent !== "1"` → 400 `{error:'consent_required', message:'请先勾选同意，再提交。'}`.
- Store under lock; if the email exists keep the original record. New and existing get the same
  answer: 200 `{ok:true}` (no enumeration). Over the 20 000 cap → 503
  `{error:'full', message:'订阅名单满了，这次没有保存。'}`.
- Record `consent` = the current live `sub.consent` text. Never sends mail.

### 9.2 Admin 订阅者 page

Count, switch 订阅表单 开/关 (republishes home), 复制全部邮箱（密送用）: the page embeds the comma-separated
list in a hidden `<textarea readonly>`; the button copies it synchronously inside the tap (iOS Safari
drops clipboard access after an `await`), and if copying fails it opens a sheet with the text selected
for a manual long-press copy,
导出表格（CSV）, search (substring, case-insensitive), list 50 per page (email, 订阅时间 Beijing time),
select + 删除所选 (confirm). Standing note: 「本站不会自动发邮件。发了新日志以后，复制全部邮箱，用你自己的邮箱以
密送（BCC）方式发出，别放在收件人里，免得读者互相看到邮箱。有人回信要退订，就在这里把他删掉。」

CSV: UTF-8 with BOM, CRLF, header `邮箱,订阅时间,同意的内容`, time `2026-10-14 16:05` (Beijing), every cell
quoted, cells starting with `= + - @` or tab prefixed with `'`. Filename `subscribers-YYYYMMDD.csv`.

### 9.3 Public copy rules

When subscribe is live the page MUST NOT say or imply automatic mail. When it is off the page shows
today's Mode A copy (nothing stored).

### 9.4 Template changes for the live state (all inside conditions false in the baseline)

Form, after `<p class="sub-err" …></p>\n`:
```
<!--{if subscribe_live}-->          <label class="sub-consent"><input id="sub-consent" name="consent" type="checkbox" value="1"><span><!--{t sub.consent}-->我同意本站保存这个邮箱，只用来给我发飞行日志。<!--{/t}--></span></label>
          <p class="sub-hp" aria-hidden="true"><label for="sub-web">网址</label><input id="sub-web" name="website" type="text" tabindex="-1" autocomplete="off"></p>
<!--{/if}-->          <div class="thr5" aria-hidden="true">…
```
The Mode A micro line: `<!--{if !subscribe_live}-->          <p class="micro mode-a"><!--{t sub.off_note}-->…<!--{/t}--></p>\n<!--{/if}-->        </form>`.

Constants: `const SUBSCRIBE_ENDPOINT = <!--{v subscribe_endpoint}-->''<!--{/v}-->;` and
`const CHAT_LIVE = <!--{v chat_live}-->false<!--{/v}-->;` (rest of each line unchanged).

T object: after `subFail: '…'` insert
`<!--{if subscribe_live}-->,\n  subConsent: '<!--{t sub.msg_consent|js}-->请先勾选同意，再提交。<!--{/t}-->',\n  subRate: '<!--{t sub.msg_rate|js}-->提交得有点频繁，过一会儿再试。<!--{/t}-->'<!--{/if}-->` before `\n};`.

Script section `/* ============ 06: subscribe ============ */` up to (not including)
`/* ---------- hero diagnostics panel ---------- */`: wrap as
`<!--{if subscribe_live}-->NEW<!--{else}-->OLD (today's code, with the sub.button js marker)<!--{/if}-->`.
NEW keeps the same element lookups, `EMAIL_RE`, `subError`, `subShow`, IME guard and input clearing,
and on submit: empty → `T.subEmpty`; format → `T.subFormat`; consent unchecked → `T.subConsent` and
focus the checkbox; then `fetch(SUBSCRIBE_ENDPOINT, {method:'POST', body: fd, headers:{Accept:'application/json'}, signal})`
with `fd = new FormData(subForm)` plus `t = Math.round(performance.now())`, 20 s timeout. 200 with
`ok:true` → `subShow(T.subOkPre + v + T.subOkPost, false)`. Otherwise a JSON `message` string wins
(`subError(message)`), else 429 → `T.subRate`, else `T.subFail`; network error → `T.subFail`.
Button shows `T.subBusy` while pending and is restored to the `sub.button` text.

### 9.5 Honest copy (launch defaults, all checked against the copy rules)

| key | launch value |
|---|---|
| `sub.sub2` | 新的一篇写完，我会从订阅名单里手动发到你的邮箱。 |
| `sub.micro` | 只发飞行日志，不发广告。想退订，回复任意一封邮件就行。 |
| `sub.consent` | 我同意本站保存这个邮箱，只用来给我发飞行日志。 |
| `sub.ok_pre` / `sub.ok_post` | 已订阅。下一篇日志写完，我会发到 / 。 |
| `sub.msg_consent` | 请先勾选同意，再提交。 |
| `sub.msg_rate` | 提交得有点频繁，过一会儿再试。 |
| FAQ row A0104 `a` | 每写完一篇，我从订阅名单里手动发一封邮件给你。不发广告，不转让邮箱。想退订，回复任意一封邮件，我会把你的邮箱删掉。 |

### 9.6 `app/seed/launch.patch.json`

```json
{
  "settings": { "subscribe": { "enabled": true } },
  "fields": { "sub.sub2": "…", "sub.micro": "…", "sub.ok_pre": "…" },
  "faq_patch": [ { "nid": "A0104/26", "a": "…" } ]
}
```
(`sub.consent`, `sub.msg_consent`, `sub.msg_rate` already have these defaults in the template.) The
launch content = baseline + this patch. `pilot-blog/index.html` in the repo is regenerated from it with
`php app/bin/render-launch.php > pilot-blog/index.html` (T2).

---

## 10. AI assistant

### 10.1 Admin 设置 (page AI 助手)

- 开关: 打开 / 关闭. Status line: 「在线：访客现在能用。」, 「已关闭：对话框显示接入中，不会调用 AI。」 or
  「缺少 API 密钥：先在下面填好密钥。」.
- 密钥 (write-only): status 「已设置，结尾是 ab3F（10月14日设置）」 / 「还没有设置」 / 「正在用旧配置文件
  chat-config.php 里的密钥（结尾 ab3F）。在这里保存新密钥后会优先用新的。」. Input `type=password`,
  `autocomplete=off`, `spellcheck=false`, never prefilled; validate `^sk-ant-[A-Za-z0-9_\-]{20,200}$`
  → else 「这不像是 Anthropic 的 API 密钥（应该以 sk-ant- 开头）。」. Buttons 保存密钥, 删除密钥 (confirm), 测试连接.
- 测试连接 (`ai.test`): `$client->models->retrieve(modelID: 'claude-opus-5-5')` with a 15 s timeout.
  OK → 「连接正常，密钥可以用。」; auth error → 「密钥不对或已经失效。」; network → 「连不上 Anthropic，稍后再试。」.
  The key never appears in responses, logs, flash messages or HTML (T8 greps for it).
- 限额: 每天最多回答 N 次 (0..5000), 每位访客每 10 分钟最多 N 次 (1..200).
- 补充说明 (≤ 1000 chars), help: 「会加在助手的系统提示后面，比如说明最近不方便透露的事。不要写密码或私人信息。」
- 用量（近 30 天）: table per day (日期, 提问, 回答, 出错, 被限流, 输入 tokens, 输出 tokens) + 30-day totals +
  a server-rendered inline SVG bar chart of requests per day (presentation attributes only, no inline
  styles, no JS). Note: 「这里只记次数和用量，不记访客问了什么。」

### 10.2 `api/chat.php` changes

Keep the request validation, model (`claude-opus-5-5`), effort, fallbacks, the user-facing messages and
the response shape exactly as today. Change:
1. `require __DIR__ . '/../app/lib/bootstrap.php'`; `app_init('api')`.
2. After the method check: `usage_add(['chat.requests' => 1])`.
3. `settings.chat.enabled` false → usage `errors.disabled` → 503
   `{error:'not_configured', message:'站长暂时关掉了助手，这个问题暂时没有回答。'}` (503 makes the page show INOP).
4. Key: `secrets.json` → legacy `dirname(PUBLIC_DIR)/chat-config.php` `api_key` → `ANTHROPIC_API_KEY`
   env. Missing → `errors.not_configured`, today's 503.
5. Rate limits move to `blog-data/ratelimit` via `rl_hit()`: IP key `ip_hash(REMOTE_ADDR)` with
   `ip_limit` per `ip_window_min`; daily bucket keyed by the Beijing date with `daily_limit` (legacy
   `chat-config.php` `daily_limit` is ignored once settings exist). Record `errors.rate_limited_ip` /
   `errors.daily_limit`.
6. System prompt = `SYSTEM_PROMPT` + (note non-empty ? "\n\n站长补充说明（博客作者填写，仅供参考）：\n" . note : '').
7. Map every outcome to a usage counter (`answered`, `refused`, each error code of today's catch
   blocks). On success add `tokens_in` = `usage->inputTokens` + `cacheCreationInputTokens` +
   `cacheReadInputTokens` (null → 0) and `tokens_out` = `usage->outputTokens`.
8. Store nothing of the conversation. `error_log` lines keep the exception class and HTTP status only.
9. Test hook: if `PHP_SAPI === 'cli-server' && getenv('BLOG_CHAT_FAKE') === '1'`, skip the API call and
   answer `{reply:'（测试回答）'}` with usage 120 in / 40 out. This cannot trigger on Hostinger.

---

## 11. Security model

### 11.1 First run (no first-visitor takeover)

- While `admin.json` is missing, every admin route except `setup` and static assets redirects to
  `setup`. `setup` needs the **setup code** from `blog-data/setup-code.txt`, which the app creates
  (12 chars from `ABCDEFGHJKMNPQRSTUVWXYZ23456789`, shown as `XXXX-XXXX-XXXX`, valid 7 days, regenerated
  on the next admin request after expiry). The code is never shown in any HTTP response.
- File content:
```
云底之下 后台设置码（只用一次）

K7P2-9QXM-4HTB

用法：打开 https://你的网址/admin/ ，在「设置码」里填上面这一串，再设一个后台密码。
设置成功后，这个文件会自动删除。
有效期到 2026-10-21。过期以后再打开后台，会生成新的设置码。
```
- Setup form: 设置码, 新密码, 再输一遍. Code compared case-insensitively ignoring spaces and dashes with
  `hash_equals`; throttled like login. Success: write `admin.json`, delete the code file, log in, audit
  `setup_done`.
- Forgotten password (shown in a 忘记密码？ details on the login page): 「在 Hostinger 文件管理器里，删除和
  public_html 同级的 blog-data 文件夹里的 admin.json，再打开后台，按提示用新的设置码重设密码。内容不会丢。」

### 11.2 Passwords

`password_hash($pw, PASSWORD_DEFAULT, ['cost' => 12])`, `password_verify`, `password_needs_rehash` on
login. Rules: 10..200 characters (any script), not one repeated character, not in a built-in list of
~50 common passwords, not equal to the brand. Change password requires the current one and increments
`session_epoch` (signs out every other session).

### 11.3 Sessions

`session_name('pb_admin')`, save path `blog-data/sessions`, `use_strict_mode=1`, `use_only_cookies=1`,
`use_trans_sid=0`, cookie: path = the admin dir URL path (`/admin/`), `httponly`, `secure` when HTTPS,
`samesite=Strict`, lifetime 0. `session_regenerate_id(true)` on login, setup and password change.
Session holds `uid`, `login_at`, `last_seen`, `csrf`, `epoch`. Idle timeout 120 min, absolute 12 h,
epoch mismatch → logged out. On a non-HTTPS request whose host is not `localhost`/`127.0.0.1`, the login
and setup forms are replaced with 「请用 https:// 打开后台，再登录。」 (passwords never travel over plain HTTP).

### 11.4 Login throttling

`login-ip`: 5 failures per 15 min per IP key → locked 15 min. The key is the IPv6 /64 (or the exact
IPv4 address), counted once per attempt **before** the password is checked, under the counter's own
lock, so a burst of parallel requests cannot slip past it. Each failure sleeps 1 s. Messages: 「密码不对。」,
「尝试次数太多，请 15 分钟后再试。」. Success clears the IP bucket. There is no site-wide lock (see §18 item
14: it only let anyone lock the owner out of their own login/setup/reset); setup and password change use
the same per-IP gate. When the counter cannot be written the attempt is refused with
「后台暂时登录不了，过一会儿再试。」 (fail closed).

### 11.5 CSRF and request rules

- Every state change is a POST; GET never changes state.
- Token: 32 random bytes hex per session, rotated on login. Forms carry `_csrf`; fetch sends
  `X-CSRF-Token` (read from `<meta name="csrf">`). `hash_equals` check before any work.
- If `Origin` (else `Referer`) is present, its scheme+host must equal the admin's; mismatch → 403.
- JSON endpoints answer `{ok:false, error:'auth_required'}` 401 or `{ok:false, error:'csrf'}` 403; the
  editors then open the re-login sheet (§12.3) and retry once with the new token.
- Optimistic concurrency: every save sends the `rev` it loaded; mismatch → 409 「这页内容在别处改过了（可能是另
  一个窗口）。先把你的修改复制下来，刷新页面后再保存。」.

### 11.6 Output escaping

- Admin views echo only through `h()` (`htmlspecialchars` ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5) or
  `json_encode` with `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT` for data embedded in
  `<script type="application/json">`. A test greps `app/views` for `<?=` not followed by `h(` or `j(`.
- Public output only through the encoders of §4.2 and the Markdown renderer of §7.2. No raw HTML from
  the owner anywhere.

### 11.7 Paths and ids

Whitelists: post id `^p[0-9a-f]{12}$`, slug, fix, shelf id (§5), version id `^\d{8}-\d{6}-[0-9a-f]{6}$`,
upload path regex (§7.2), day `^\d{4}-\d{2}-\d{2}$`. `data_path()`/`public_path()` reject any part
outside `^[A-Za-z0-9._-]+$` or equal to `.`/`..`, then assert the realpath of the parent stays under
the base dir. `versions.view` serves only file names listed in that version's manifest.

### 11.8 Uploads

`POST upload` (multipart field `image`, `purpose` = `cover` | `body`):
1. `UPLOAD_ERR_OK`, `is_uploaded_file`, size ≤ 12 MB (and ≤ PHP's `upload_max_filesize`; the editor
   shows the effective limit).
2. `finfo` MIME ∈ jpeg, png, webp, gif; `getimagesize` OK; each side ≤ 8000 px and ≤ 30 megapixels.
   HEIC → 「这张是 HEIC 格式。请在 iPhone 设置 → 相机 → 格式 里选「兼容性最佳」，或者截个图再传。」.
3. Decode with GD (first frame for GIF); JPEG EXIF orientation applied; `ini_set('memory_limit','512M')`.
4. Resize long edge to ≤ 2000 px; encode WebP quality 80 (fallback JPEG 85 when `imagewebp` is
   missing); also a JPEG q 82 with long edge ≤ 1200 px flattened on `#0A1733` for `og:image`.
5. Names `bin2hex(random_bytes(8))`, dir `YYYY/MM`; write to `blog-data/uploads/` then copy to
   `public/uploads/` atomically; update `uploads/index.json`. Original bytes are discarded.
6. Return `{ok:true, src, og, w, h}`.
The editor first resizes on the phone (canvas, long edge 2400, JPEG 0.9) to save mobile data; the
server re-encodes anyway. Images uploaded for unpublished drafts are reachable only by their random
URL; the editor says so in the image help.

### 11.9 Headers

- Admin (from PHP): `Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'; object-src 'none'`,
  `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`,
  `Permissions-Policy: camera=(), microphone=(), geolocation=()`, `Cache-Control: no-store`,
  `X-Robots-Tag: noindex, nofollow`, and `Strict-Transport-Security: max-age=15552000` when HTTPS.
  Views use no inline `<script>`, no inline `style=""` (CSP blocks them; admin.js sets styles only through
  `el.style.prop` or classes, never `setAttribute('style')`); `<meta name="robots"
  content="noindex,nofollow">` on every admin page.
- Home preview and version view: same but `script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; media-src 'self' blob:; frame-ancestors 'self'; base-uri 'self'`.
- Post preview: `script-src 'none'`, `frame-ancestors 'self'`, `X-Frame-Options: SAMEORIGIN`.
- Public pages: headers from `.htaccess` (§14). No CSP on `index.html` in v1 (its inline scripts and
  WeChat's injected bridges must keep working); strict CSP on `posts/` and `uploads/`.

---

## 12. Admin UX

### 12.1 Look

- Self-hosted only: `admin.css`, `admin.js`, fonts from `../assets/fonts/` (B612, B612 Mono), CJK from
  system fonts (`--cjk` stack of the site). No CDN, no Google, no icon fonts (inline SVG icons).
- Palette tokens copied from the site: canvas `#0A1733`, panel `#122452`, line `#2A3A66`, line-strong
  `#5E6EA2`, text `#ECE9F1`, secondary `#A9A8C0`, accent `#EA4C22` (primary buttons, focus), signal
  `#E6A948` (预告, warnings), threshold `#4FD08A` (已发布, success), route `#E25AD9` (fix idents only).
  Error text `#FF8A66`. No grain, no animation beyond 150 ms transitions; `prefers-reduced-motion` respected.
- Calm practical layout: UI text in the system CJK font (16px/1.7), codes/times/fix idents in B612 Mono.
- Phone first (390 × 844 is the reference): one column, 16px gutters, cards with 8px radius; top bar
  56px with brand, page title and a 菜单 button opening a full-height menu sheet; ≥ 1024px: left sidebar
  220px + content max 760px (post editor: editor + preview side by side ≥ 1100px).
- Every input, select and textarea font-size ≥ 16px (stops iOS zoom); tap targets ≥ 44 × 44px; sticky
  bottom action bar with `env(safe-area-inset-bottom)` padding on editor pages; textareas auto-grow;
  character counters show `已写 12 / 建议 18 以内` when a soft max exists.
- No horizontal scroll at 360–430px on any admin page.

### 12.2 Pages

Menu order: 概览, 网站文字, 飞行日志, AI 超市, 订阅者, AI 助手, 版本记录, 修改密码, 退出.

- **登录**: brand + 「后台」, 密码 field, 登录, 忘记密码？ details (§11.1).
- **设置**（first run）: explanation 「第一次使用：请在 Hostinger 文件管理器里，打开和 public_html 同级的
  blog-data 文件夹，找到 setup-code.txt，把里面的设置码填到下面。」 + form.
- **概览**: cards: 网站 (上次发布时间; which units have unpublished changes, each linking to its page;
  查看网站; 重新发布全部), 日志 (已发布 / 预告 / 草稿 counts; 写新日志), 订阅者 (总数, 近 7 天新增),
  AI 助手 (状态, 今天提问 n / 每天上限). Banners when relevant: self-heal result (§6.5);
  「网站地址还没填，分享卡片会用不了。」 with a one-tap 「用 https://{HTTP_HOST} 作为网站地址」 (saves and publishes
  `site.origin` in both draft and live); 「AI 助手开着，但还没有密钥。」.
- **网站文字**: search box 「找文字（比如：订阅）」 filtering fields by label and value (client-side); groups as
  `<details>` sections (§4.8 order) with a 改过 badge counting fields that differ from live; each field:
  label, where it appears (small secondary text), input, counter. FAQ and 数据格子 list editors (cards with
  上移, 下移, 删除, and 加一条). Action bar: status text, 保存草稿, 预览首页 (opens the draft home page in a
  new tab), 发布. More menu: 放弃草稿，恢复成网站上的版本 (confirm).
- **飞行日志** list: filter chips 全部 / 已发布 / 预告 / 草稿; rows with title, status chip, date, fix; a
  写新日志 primary button; setting 「首页最多显示 [6] 篇」 (saves and republishes).
- **日志编辑器**: fields 标题; 正文 (toolbar: 小标题, 加粗, 列表, 引用, 插入图片, 链接; each button inserts the
  syntax at the cursor; a 怎么写 help sheet explains 「每按一次换行就是新的一段」 and the syntax);
  摘要 (可留空); 标签 (comma or space separated); 封面图 (upload, replace, remove, 图片说明); 日期; 阅读时间
  (留空自动算); 航路点代号 (5 个大写字母); 网址 (`posts/xxx.html`, locked after first publish). Phone: tabs
  编辑 | 预览; desktop: side by side. Preview: debounced 800 ms, a hidden form POSTs the draft to
  `preview.post` targeting the iframe. Action bar: status (草稿 / 预告 / 已发布 / 有未发布的修改 / 有改动没保存),
  保存草稿, 发布 (or 发布修改); more menu: 设为预告 / 改回预告, 撤下, 删除.
- **AI 超市**: shelves as collapsible cards (title, blurb, tools); tool rows collapsed to name + badge +
  host, expand to edit (名称, 一句话介绍, 网址, 国内能不能直接访问, 我的使用心得（可留空）); 上移/下移/移到别的货架/删除;
  加一个货架, 加一个工具. Save / preview / publish bar.
- **订阅者**: §9.2.
- **AI 助手**: §10.1.
- **版本记录**: up to 10 rows (time, reason, 当前 chip on the newest); 看一看 (archived home page in a new
  tab), 回滚到这个版本 (confirm 「网站会变回 {时间} 的样子。草稿不受影响，之后还可以再回滚回来。」).
- **修改密码**: 当前密码, 新密码, 再输一遍.

### 12.3 Behaviour

- Save and publish on the three editors use fetch + JSON (not page reloads) so a session expiry never
  loses text. On 401/403-csrf: a bottom sheet 「登录已经过期。输入密码继续，你写的内容还在。」 with a password
  field; it GETs `login` as JSON for a fresh pre-login token (the old session is gone), POSTs the
  password with it, receives the new session's CSRF token, updates `<meta name="csrf">`, then retries.
- Unsaved changes: track input events; `beforeunload` warning; in-app links and the menu ask
  「有改动还没保存，确定离开吗？」.
- Local backup: editors copy their state to `localStorage` every 5 s (key per page/post id, wrapped in
  try/catch); on load, if a backup is newer than the server `updated_at` and differs, offer
  「发现一份没保存的内容（16:05），要恢复吗？」 恢复 / 丢掉.
- Messages are plain Chinese, shown in a status region (`aria-live="polite"`): success
  「已保存草稿。网站还没变，点「发布」才会更新。」, 「已发布。网站已经更新，打开时如果还是旧的，刷新一下。」, 「已撤下。」,
  errors 「保存失败：标题不能为空。」, 「发布失败：{原因}。网站没有变化。」, 「网络断了，没有保存。内容还在这页上，联网后再点保存。」.
- Field errors appear under the field and the page scrolls to the first one.
- Buttons show 保存中… / 发布中… and are `aria-disabled` while pending (never lose focus).
- Destructive actions use a confirm sheet, never the browser `confirm()` (WeChat in-app browser).

---

## 13. Core library (file list and signatures)

PHP ≥ 8.1 compatible (no 8.2+ only syntax). `declare(strict_types=1);` everywhere. Each lib file starts
with `defined('BLOG_APP') || exit;`. No Composer for the admin (only `api/vendor` for the Anthropic SDK,
loaded by `chat.php` and `ai.test`).

```php
// bootstrap.php
// entry points (admin/index.php, api/*.php, app/bin/*.php) call define('BLOG_APP', true) before requiring bootstrap
function app_init(string $context): void;    // 'admin' | 'api' | 'cli'; dirs, seed install, error handling (UTC, no display_errors)
function public_path(string ...$parts): string;
function data_path(string ...$parts): string;
function app_path(string ...$parts): string;
function now_iso(): string;
function local_day(?int $ts = null): string;              // Asia/Shanghai Y-m-d
function local_time(string $iso, string $fmt = 'Y-m-d H:i'): string;

// store.php
function json_read(string $path, mixed $default = null): mixed;
function json_write_atomic(string $path, mixed $data, int $mode = 0600): void;
function file_write_atomic(string $path, string $bytes, int $mode = 0644): void;
function with_lock(string $name, callable $fn, int $timeoutSec = 10): mixed;
function json_update(string $path, callable $mutate, mixed $default = []): mixed;  // lock + read + mutate + write
final class ConflictException extends RuntimeException {}
function require_rev(array $doc, int $expected): void;    // throws ConflictException

// security.php
function h(?string $s): string;
function j(mixed $v): string;                              // json for <script type="application/json">
function is_https(): bool;
function send_headers(string $kind): void;                 // 'admin' | 'preview-home' | 'preview-post' | 'json' | 'csv'
function admin_session_start(): void;
function auth_check(): bool;                               // timeouts + epoch
function auth_require(bool $json = false): void;
function auth_login(string $password): array;              // ['ok'=>bool, 'error'=>?string]
function auth_logout(): void;
function setup_needed(): bool;
function setup_code_ensure(): void;
function setup_complete(string $code, string $pw1, string $pw2): array;
function password_change(string $old, string $new1, string $new2): array;
function password_problem(string $pw): ?string;            // Chinese message or null
function csrf_token(): string;
function csrf_verify(): void;                              // 403 on failure
function origin_verify(): void;
function client_ip(): string;                              // REMOTE_ADDR only
function ip_hash(string $ip): string;                      // hmac-sha256 with secrets.ip_salt
function audit(string $event, string $detail = ''): void;

// ratelimit.php
function rl_hit(string $bucket, string $key, int $limit, int $windowSec): array; // ['allowed'=>bool,'count'=>int,'retry_after'=>int]
function rl_clear(string $bucket, string $key): void;
function rl_gc(): void;                                    // 1% of calls: delete windows older than 2 days

// template.php
final class TemplateError extends RuntimeException {}
function tpl_parse(string $src): array;                    // nodes: lit | t(key,enc,default,indent) | v | block | if
function tpl_render(array $nodes, array $ctx): string;
function tpl_extract(array $nodes): array;                 // ['fields'=>[key=>value], 'blocks'=>[name=>[inner, indent]], 'values'=>[name=>default]]
function enc_render(string $enc, string $value, string $indent = ''): string;
function enc_decode(string $enc, string $html, string $indent = ''): string; // throws on non-canonical input
function tpl_load(string $name): array;                    // parsed + cached per request
function template_hash(): string;

// fields.php
function field_registry(): array;                          // key => ['label','group','where','enc'=>[],'multiline'=>bool,'max'=>int,'soft_max'=>?int,'default'=>?string,'vars'=>[],'kind'=>'text|pattern|url|year']
function field_groups(): array;                            // ordered group id => Chinese title
function site_validate(array $site): array;                // path => Chinese error
function site_normalize(array $input): array;

// blocks.php
function block_render(string $name, array $ctx, string $indent): string;
function block_extract(string $name, string $inner, string $indent, array $fields): mixed;
function value_render(string $name, array $ctx): string;
function cond_eval(string $cond, array $ctx): bool;

// markdown.php
function md_render(string $src, array $opt = []): string; // $opt: base ('../'), uploads (index items)
function md_plain(string $src): string;
function md_excerpt(string $src, int $max = 80): string;
function md_reading_minutes(string $src): int;

// posts.php
const RESERVED_FIXES = ['DEP','PILOT','LOGBK','PC12X','AISHP','CHKLT','FINAL','RW24'];
function posts_all(): array;
function post_get(string $id): ?array;
function post_create(): array;
function post_save_draft(string $id, array $draft, int $rev): array;
function post_publish(string $id, int $rev): array;
function post_set_teaser(string $id, int $rev): array;
function post_unpublish(string $id, int $rev): array;
function post_delete(string $id): void;
function post_validate(array $post, string $mode): array;  // 'draft' | 'teaser' | 'full'
function post_status(array $post): string;                 // draft | teaser | published
function post_dirty(array $post): bool;
function fix_suggest(string $slug, array $taken): string;
function home_legs(array $posts, int $limit): array;       // ['legs'=>[…view models], 'p'=>int, 't'=>int, 'published_total'=>int]

// shelf.php
const CN_ACCESS = ['能直接访问', '需要国际网络'];
function shelf_validate(array $shelves, bool $forPublish): array;
function shelf_normalize(array $input): array;

// publish.php
function build_home_ctx(array $site, array $shelf, array $posts, array $settings, bool $chatKey): array;
function render_home(array $ctx): string;
function render_post_page(array $post, array $ctx, ?array $older, ?array $newer): string;
function render_archive(array $ctx): string;
function publish_site(string $reason, array $opts = []): array;
function preview_home(array $siteDraft, array $shelfDraft): string;  // with <base href> + 预览 banner
function preview_post(array $postWithDraft): string;
function versions_list(): array;
function version_rollback(string $id): array;
function version_file(string $id, string $rel): ?string;
function site_status(): array;
function content_hash(array $site, array $shelf, array $posts): string;

// images.php
function image_accept_upload(array $file, string $purpose): array;
function uploads_sync_public(): int;

// subscribers.php
function email_normalize(string $raw): ?string;
function sub_add(string $email, string $consent): string;  // 'new' | 'exists'
function sub_list(string $q = '', int $page = 1, int $per = 50): array;
function sub_delete(array $emails): int;
function sub_all_emails(): array;
function sub_csv(): string;

// settings.php
function settings_get(): array;
function settings_save(array $patch, int $rev): array;
function chat_key(): ?string;                              // secrets → legacy file → env; server-side only
function chat_key_source(): ?string;                       // 'admin' | 'legacy' | 'env' | null
function chat_key_hint(): ?string;                         // last 4 chars
function chat_key_set(string $key): void;
function chat_key_remove(): void;
function chat_live(): bool;
function usage_add(array $delta, ?string $day = null): void; // dotted paths: ['chat.requests'=>1,'chat.errors.bad_request'=>1]
function usage_range(int $days = 30): array;

// view.php
function view(string $name, array $vars = []): void;       // app/views/{name}.php inside layout.php
function url(string $route, array $q = []): string;        // '?r=' . route
function redirect(string $route, array $q = []): never;
function flash(string $type, string $msg): void;
function flash_take(): array;
function json_out(int $status, array $body): never;
```

`admin/index.php`: defines `BLOG_APP`, requires bootstrap, `app_init('admin')`, `send_headers`,
starts the session, dispatches `$_GET['r']` through a fixed route table (unknown → 404 view).

### 13.1 Admin routes (`/admin/?r=…`)

| r | method | auth | csrf | response |
|---|---|---|---|---|
| `setup` | GET, POST | only while setup needed | POST | page / redirect |
| `login` | GET, POST | no | POST | page; with `Accept: application/json` GET returns `{csrf}` (a fresh pre-login token) and POST returns `{ok, csrf}` |
| `logout` | POST | yes | yes | redirect login |
| `password` | GET, POST | yes | POST | page |
| `` / `dashboard` | GET | yes | | page (+ self-heal) |
| `site` | GET | yes | | editor |
| `site.save` / `site.publish` / `site.discard` | POST JSON | yes | yes | `{ok, rev, status}` |
| `site.origin` | POST | yes | yes | dashboard one-tap: sets `site.origin` to `https://{HTTP_HOST}` in draft and live, republishes, redirect |
| `preview.home` | POST form (`target=_blank`) | yes | yes | HTML (draft site + draft shelf) |
| `posts` | GET `f=` | yes | | list |
| `posts.limit` | POST | yes | yes | redirect |
| `post.new` | POST | yes | yes | redirect to `post.edit` |
| `post.edit` | GET `id=` | yes | | editor |
| `post.save` / `post.publish` / `post.teaser` / `post.unpublish` | POST JSON | yes | yes | `{ok, rev, status, post}` |
| `post.delete` | POST | yes | yes | redirect |
| `preview.post` | POST form (iframe target) | yes | yes | HTML |
| `upload` | POST multipart | yes | yes | JSON |
| `shelf` | GET | yes | | editor |
| `shelf.save` / `shelf.publish` / `shelf.discard` | POST JSON | yes | yes | JSON |
| `subs` | GET `q=`, `p=` | yes | | page |
| `subs.delete` / `subs.toggle` | POST | yes | yes | redirect |
| `subs.export` | GET | yes | | CSV download |
| `ai` | GET | yes | | page |
| `ai.save` / `ai.key` / `ai.key_remove` | POST | yes | yes | redirect |
| `ai.test` | POST JSON | yes | yes | `{ok, message}` |
| `versions` | GET | yes | | page |
| `versions.view` | GET `id=`, `f=` | yes | | archived HTML with `<base href>` to the site root and the 预览 banner injected (preview-home headers) |
| `versions.rollback` | POST | yes | yes | redirect |
| `publish.all` | POST | yes | yes | redirect |

JSON errors: `{ok:false, error:'auth_required'|'csrf'|'conflict'|'invalid'|'too_large'|'server', message:'中文', fields?:{path:'中文'}}`
with 401/403/409/400/413/500.

---

## 14. Public endpoints and `.htaccess`

Root `pilot-blog/.htaccess` (new):
```
Options -Indexes
DirectoryIndex index.html index.php
<IfModule mod_headers.c>
  Header always set X-Content-Type-Options "nosniff"
  Header always set Referrer-Policy "strict-origin-when-cross-origin"
  Header always set X-Frame-Options "SAMEORIGIN"
  Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"
  <FilesMatch "\.html$">
    Header set Cache-Control "no-cache"
  </FilesMatch>
</IfModule>
<FilesMatch "(^\.|\.(bak|orig|swp|log|lock|md|tpl\.html|json|ini|sh|sql)$)">
  Require all denied
</FilesMatch>
```
(`.json` is denied site-wide; the public site serves no JSON file. Temp files from atomic writes start
with a dot and are denied while they exist.)

`api/.htaccess`: deny everything except `chat.php` and `subscribe.php`:
`<FilesMatch "^(?!(chat|subscribe)\.php$).*$"> Require all denied </FilesMatch>` + `Options -Indexes`.

`admin/.htaccess`:
```
Options -Indexes
DirectoryIndex index.php
<FilesMatch "\.php$">
  Require all denied
</FilesMatch>
<Files "index.php">
  Require all granted
</Files>
<IfModule mod_headers.c>
  Header always set X-Robots-Tag "noindex, nofollow"
</IfModule>
```
`app/.htaccess`: `Require all denied`.

`uploads/.htaccess`:
```
Options -Indexes -ExecCGI
RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phar .pht .cgi .pl .py
RemoveType .php .phtml .php3 .php4 .php5 .php7 .php8 .phar .pht
<FilesMatch "(?i)\.(php\d*|phtml|phar|pht|cgi|pl|py|sh|shtml|html?|svg|js)$">
  Require all denied
</FilesMatch>
<IfModule mod_headers.c>
  Header always set Content-Security-Policy "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox"
  Header always set X-Content-Type-Options "nosniff"
</IfModule>
<IfModule mod_php.c>
  php_flag engine off
</IfModule>
```
`posts/.htaccess`: `Header always set Content-Security-Policy "default-src 'self'; img-src 'self' data:; style-src 'self'; font-src 'self'; script-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'; object-src 'none'"` (inside `IfModule mod_headers.c`).

`app/dev/router.php` (php -S only) mirrors these denies with 403s so local tests can check them; it
routes `/admin/` to `admin/index.php`, runs `api/*.php`, and returns `false` for static files.

---

## 15. Test plan (all must pass before a phase is reported done)

Runner: `php pilot-blog-work/backend/tests/run.php` (unit) and
`node pilot-blog-work/backend/tests/admin.e2e.js` (Playwright, `require('/opt/node-tools/node_modules/playwright')`,
preinstalled Chromium, never `playwright install`). Servers: `php -S 127.0.0.1:<port> -t <tmp public> pilot-blog/app/dev/router.php`
with `BLOG_PUBLIC_DIR`/`BLOG_DATA_DIR` pointing at temp copies (symlink `assets/`, `api/`, `admin/`,
`app/`; real dirs for `posts/`, `uploads/`, copy of `index.html`). Stop with
`kill $(pgrep -f "^php -S 127.0.0.1:<port>")`. Headless Chromium may not decode H.264; that is fine.

- **T1 baseline byte identity**: render `home.tpl.html` with the baseline seed (subscribe off, chat off,
  5 teasers, no published) → sha256 equals `app/seed/baseline-index.html` (= 08da7c1 `index.html` until the owner-approved
  reorder of 2026-10-15, now `0f206a8a…`, see §18 item 24). On
  failure print the first differing byte offset with 80 bytes of context from both.
- **T2 launch render**: `render-launch.php` output equals the committed `pilot-blog/index.html`.
- **T3 hero untouched**, for the baseline render, the launch render and a "probe" render where every
  text field is replaced by a valid but different string containing `<>&"'\` (plus `|` in `wbr` fields)
  and a 40-char Chinese run:
  regions compared byte for byte with `hero-reference.json` (store the bytes' sha256 and lengths):
  1. from `<link rel="icon"` through the loader `<script>(function(d){…</script>` line;
  2. from `<style>` up to (excluding) `/* ============ THE CHART: everything after the hero`;
  3. from `/* hero diagnostics panel (hidden` up to (excluding) `</style>`;
  4. main script from its `<script>` line through the `const POSTER_END_URL_PORTRAIT` line;
  5. from `/* ---------- helpers ---------- */` up to (excluding) `/* ============ APPROACH PLATE`;
  6. from `/* ---------- hero diagnostics panel ---------- */` to the end of the file;
  7. skeleton of `<header class="hero" id="top">…</header>` and `<nav class="nav" …>…</nav>`: the
     sequence of tags with all attributes, ignoring `<wbr>` and text, must be identical.
  Also: `home.tpl.html` contains no marker inside regions 1–6.
- **T4 template engine**: every encoding round-trips random strings (including `<!--{/t}-->`,
  `</script>`, `<!--`, U+2028, `|`, quotes, backslashes, emoji); output never contains an unescaped `<`
  inside text/attr encodings or an unescaped `'`/`<` inside js; malformed templates throw; lint: every
  `t` key exists in the registry, every registry key with an encoding occurs in a template.
- **T5 Markdown**: golden cases for every construct; XSS list (`<script>`, `<img onerror>`,
  `[x](javascript:…)`, `[x](//evil)`, `![x](../../etc/passwd)`, `![x](uploads/a.svg)`, `"><svg/onload=…>`,
  nested `**[`…) all render inert; long input performance < 200 ms for 60 000 chars.
- **T6 legs 0..N**: render with 0, 1, 2, 5, 7, 12 legs (published and teaser mixes, long titles) and
  load each at 390 × 844 and 1440 × 900: no console errors, no horizontal overflow, every `[data-crs]`
  filled (not `---°`) after `chartBuild`, the route path `d` is not `M0 0`, HUD shows a post fix while
  scrolling the legs, published titles navigate to their post pages.
- **T7 admin flows** (Playwright at 390 × 844, and the main flows again at 1280 × 800):
  setup (wrong code refused, right code works, `setup-code.txt` deleted, second setup refused); login,
  wrong password ×5 → locked message; logout; CSRF missing → 403; forced session expiry while editing
  → re-login sheet → save succeeds and text kept; 网站文字: edit `hero.b1.hl`, `about.h2`, add a FAQ row →
  save (public `index.html` unchanged) → preview shows changes → publish (index changed, T3 still passes,
  version added); posts: new draft not public (no file, not in index) → cover upload (JPEG with EXIF
  rotation, PNG, 13 MB file refused, `.php` renamed to `.jpg` refused, polyglot JPEG with PHP payload
  re-encoded so the payload is gone) → publish (page exists, home leg links to it, archive lists it) →
  edit + save (live unchanged) → 发布修改 → 撤下 (page 404, leg gone) → 设为预告 → delete; shelf: add
  shelf, add tool with 还没确认, reorder, move, delete, publish → page JSON valid, radio shows the new
  shelf; subscribe endpoint: valid, invalid email, no consent, honeypot, `t` too small, duplicate (same
  response), rate limit, closed; admin list/search/delete/export (BOM, quoting, formula cell
  prefixed)/copy; AI: set fake key `sk-ant-test…WXYZ` → page shows only `WXYZ`; no response body, HTML,
  log or data file except `secrets.json` contains the full key; remove; limits save; switch on →
  published `CHAT_LIVE = true`; `BLOG_CHAT_FAKE=1` chat requests increment usage (requests, answered,
  tokens 120/40) and the usage table shows them; versions: 3 publishes → rollback to the first →
  content restored, drafts untouched; 11 content publishes → 10 versions kept.
- **T8 security**: path traversal attempts on every id/slug/version/file parameter → 400/404;
  `/app/lib/bootstrap.php`, `/app/seed/baseline-index.html`, `/api/composer.json`, `/api/vendor/autoload.php`,
  `/uploads/x.php`, `/admin/x.php` → 403 via the dev router; admin responses carry the §11.9 headers and
  noindex; inject `<img src=x onerror=alert(1)>` and `';alert(1)//` into every field, FAQ row, shelf
  field, post field and tag → published pages and admin pages contain no element with an `onerror`
  attribute and no script execution (Playwright dialog listener stays silent); views lint (§11.6).
- **T9 phone layout**: every admin page at 390 × 844 and 360 × 740: `scrollWidth <= clientWidth`, every
  input/select/textarea computed font-size ≥ 16px, every button/link in forms ≥ 44px tall; screenshots
  of each page saved to `pilot-blog-work/screens/admin/` (gitignored by `screens/`).
- **T10 public page regression**: the existing `pilot-blog-work/mockups/B-chart/_src/test.js both noshots`
  against the launch render: zero console errors, no external requests, no overflow; post pages and
  archive: no JS, no external requests, no overflow at 390.
- **T11 copy gate**: seed, launch patch, registry defaults, admin views/JS strings and post templates
  contain no U+2014 (em dash) and none of 赋能 无缝 一站式 解决方案 助力 打造 颠覆 神器 极致; no Chinese UI string promises
  automatic email.
- **T12 no external assets**: admin HTML/CSS/JS, post and archive pages reference no `http(s)://` or
  `//` URL in `src`, `href` (except owner content links), `url()` or `@import`.

Post-deploy live checklist (curl on the real domain, done by the deploy agent): `/` 200 with `no-cache`;
`/admin/` 200 with `X-Robots-Tag`; `/admin/index.php` 200; `/admin/assets/admin.js` 200;
`/app/lib/bootstrap.php`, `/api/composer.json`, `/uploads/test.php` (upload a harmless test file via File
Manager, then delete it), `/.htaccess` → 403; `blog-data` not reachable by any URL; `/posts/` 200;
security headers present.

---

## 16. Deploy notes (Phase 7 writes these into `pilot-blog-work/backend/DEPLOY-NOTES.md`; the repo-root `DEPLOY.md` belongs to another project, do not touch it)

- First deploy: zip the contents of `pilot-blog/` (as today) including `admin/`, `app/`, `api/` with
  `vendor/`, `posts/.htaccess`, `uploads/.htaccess`, `index.html`. Exclude nothing else from the site.
  Tests and `pilot-blog-work/` never ship.
- Then open `https://<domain>/admin/` once: the app creates `blog-data/` and `setup-code.txt`. Tell the
  owner: 「在 Hostinger 文件管理器里打开 blog-data/setup-code.txt，把设置码填进后台。」. Then the owner sets
  网站地址 (one tap on the dashboard banner), the API key and switches the assistant on.
- Later code deploys: exclude `index.html`, `posts/*.html` and `uploads/` content (keep the two
  `.htaccess` files). If the Hostinger deploy wipes `public_html` anyway, nothing is lost: content and
  upload masters are in `blog-data/`, and the next dashboard visit self-heals (§6.5). After any deploy
  that changes `app/templates/`, ask the owner to open 概览 once (or run `php app/bin/publish.php` over
  SSH).
- If Hostinger CDN or LiteSpeed page cache is on, HTML must bypass it (or be purged after publishing);
  the `.html` `no-cache` header covers browsers.
- `chat-config.php` above `public_html` keeps working as a key fallback; once the owner saves a key in
  the admin it can be deleted.

---

## 17. Build order and checkpoints

Each phase ends with its tests green, a PROGRESS.md line, and a commit + push
(`git push -u origin claude/funny-fermat-v958zd`; messages end with the two attribution lines). Commit
only files you own; never commit `blog-data/`, sessions, subscribers, usage, uploads or secrets.

| Phase | Deliverables | Tests |
|---|---|---|
| 0 Core | bootstrap, store, template engine, encodings, fields registry, blocks + extractors, `home.tpl.html` with all markers and conditional regions, seed files, `check-template.php`, `.gitignore` | T1, T3, T4 |
| 1 Publisher | posts lib, Markdown, legs, `post.tpl.html`, `archive.tpl.html`, `assets/post.css`, `home-extra.css`, publish/versions/rollback, CLI publish, `render-launch.php`, launch patch, regenerated `pilot-blog/index.html` | T2, T5, T6, T10, T11 |
| 2 Admin shell | security (setup, login, sessions, CSRF, throttling, headers), layout, admin.css/js base, 概览, 网站文字, 版本记录, 修改密码, dev router, `.htaccess` files | T7 (setup/login/site/versions parts), T8 partial, T9 partial |
| 3 Posts admin | list, editor, uploads, preview | T7 posts, T8 uploads |
| 4 Shelf admin | AI 超市 editor | T7 shelf |
| 5 Subscribe | `api/subscribe.php`, live form/JS branch, 订阅者 page | T7 subscribe, T8 |
| 6 Assistant | `chat.php` changes, usage, AI 助手 page | T7 AI |
| 7 Finish | full suite, phone screenshots, copy and asset gates, deploy notes, mark `build.py` obsolete in `pilot-blog-work/mockups/B-chart/_src/PROGRESS.txt` | T1–T12 |

If a requirement here turns out to be impossible as written (for example a byte-identity detail),
stop, write the finding and the smallest deviation in PROGRESS.md, and keep T1 and T3 intact.

---

## 18. Deviations (core build, 2026-10-14)

Recorded by the core build agent. Everything else in §0–§15 is implemented as written.

1. **chat.php when switched off.** §10.2 orders "enabled false → 站长暂时关掉了助手" before the key check.
   On a fresh install (assistant off, no key ever set) that sentence would be untrue, so the message
   depends on the key: off and no key → today's 「助手还在接入中…」; off with a key → 「站长暂时关掉了助手…」.
   Both are `503 not_configured`, both count `errors.disabled`.
2. **Live post snapshots carry `fix`.** `live.fix` is written on every publish/teaser so that changing a
   published post's 航路点代号 in its draft does not move the home leg until 发布修改. Seed teasers have no
   `live.fix` and fall back to the top-level `fix`.
3. **Extra template names.** Post/archive templates use values `post_title_attr`, `post_excerpt_attr`,
   `archive_count`, blocks `post_tag_first`, and conditions `has_tags`, `has_excerpt`, `has_prevnext`
   (a value has one encoding, so the title and excerpt needed attribute variants). The lede under a post
   title shows only an excerpt the owner wrote; the derived 80-character excerpt is used for the home leg,
   the archive and the meta description.
4. **Registry encodings.** `post.prev`, `post.next` and `archive.empty` are rendered inside blocks, so
   their registry `enc` list is empty (like the pattern keys); `archive.title` is also used as `attr`.
5. **Rollback keeps the site address.** `site.origin` behaves like a setting (its quick-set makes no
   version), so `version_rollback()` keeps the current live value instead of restoring an older one.
6. **New posts.** `post_create()` suggests a pronounceable random fix (consonant-vowel, e.g. `BAKOR`) and
   slug = lowercase fix; `fix_suggest(slug)` implements the §5.4 rule for when the owner types a slug.
7. **Content helpers for the admin.** `app/lib/content.php` (not listed in §13) holds
   `content_get/save_draft/publish/discard('site'|'shelf')`, `content_changed_fields()` and
   `site_origin_set()`. `settings_save()` returns `['settings' => …, 'publish' => ?array]` and republishes
   itself when the page changes; `chat_key_set()`/`chat_key_remove()` return the republish result or null.
   `site_self_heal()` implements §6.5 for the dashboard.
8. **Not built here (admin phase).** `security.php` has `h`, `j`, `is_https`, `send_headers`, `client_ip`,
   `ip_hash`, `audit` and the secrets helpers; the login, session, setup-code and CSRF functions of §13,
   `view.php`, `admin/index.php`, `admin/assets/*` and `app/views/` are left to the admin agent, as is
   step 3 of `app_init()` (setup code). `admin/.htaccess` and the router's `/admin/` route exist already.

Added by the QA pass (owner end-to-end test `pilot-blog-work/backend/e2e.js`):

9. **A retry is not a conflict (§11.5).** A save whose `rev` is old but whose content equals the stored
   draft (the first try was saved, its answer was lost on a bad mobile connection) returns the current
   draft instead of 409. Different content with an old `rev` still gets the 409.
10. **Pasted whitespace (§5.2).** Tabs become spaces and CRLF becomes one line break in site fields, FAQ and
   数据格子 rows and the assistant note, instead of 「看不见的特殊字符」. Full-width digits (１２０) count as
   digits in 阅读时间 and the assistant limits.
11. **Local backup (§12.3).** Besides every 5 s, the editors write their local copy when the page is hidden
   or left (`pagehide`, `visibilitychange`; iOS Safari has no `beforeunload`) and when a save fails.
12. **Post preview pane.** `preview.post` answers a short framed note (preview headers, no script) when the
   session or its token has expired or the post is gone, instead of a redirect the iframe may not show.

Added by the security-fix pass (2026-10-15; each item re-verified with an exploit, fixed, and the exploit
re-run; harness in `pilot-blog-work/` scratch, not committed):

13. **Reset signs out every device (§11.1/§11.3).** `setup_complete()` (used by 忘记密码) now starts the new
   `session_epoch` past any value the old `admin.json` left (or a random one), and deletes every file in
   `sessions/`, so a session made before a reset is never valid again. `password_change()` also clears the
   session files (the epoch already invalidated them).
14. **Login throttling counts before it checks (§11.4).** One `rl_hit('login-ip', …)` is recorded under the
   counter's own lock before `password_verify`, so parallel requests cannot all pass the check before any is
   counted. The per-IP key is the IPv6 /64 (or the exact IPv4), so one /64 no longer gets a fresh five tries
   per address. The site-wide 30-per-hour lock is **removed**: it only ever let anyone shut the owner out of
   their own login, setup and 忘记密码 reset; per-IP throttling plus the 1 s delay remain. `MSG_LOGIN_LOCKED_ALL`
   and the `login-global` bucket are gone.
15. **Passwords are pre-hashed (§11.2).** `pw_hash()`/`pw_verify()` fold the password with HMAC-SHA256 (keyed by
   a per-install `pw_pepper` in `secrets.json`) before bcrypt, so the whole password counts, not only the first
   72 bytes (24 Chinese characters). A one-time path still verifies and upgrades any old raw-bcrypt hash.
16. **No session file for anonymous or forged requests (§11.3).** `admin_session_start_if_needed()` starts the
   PHP session only when the `pb_admin` cookie names an existing session file; login and setup use a stateless
   CSRF token (`stateless_csrf()`, HMAC of a 30-minute slot keyed by `csrf_secret` in `secrets.json`), so a
   cookieless or bogus-cookie request writes nothing to `sessions/`. This closes the inode-exhaustion path.
17. **Rate-limit counters fail closed (§10.2).** `rl_hit()` returns `allowed=false, error=true` when it cannot
   create or write the counter file (default; pass `$failOpen` to keep a bucket open). `api/chat.php` answers
   503 when `chat-ip`/`chat-day` cannot be written, so a full disk never turns into an uncapped spend on the
   owner's API key.
18. **Subscribe and chat ordering (§9.1/§10.2).** `subscribe.php` reads the site-wide daily cap first without
   counting (so a flood leaves no per-IP files and does not inflate the global bucket), checks the per-IP
   buckets (keyed by /64), then the honeypot/validation, and counts `sub-site-day` only when a genuinely new
   address is stored. `chat.php` validates the request body before any `rl_hit` (invalid bodies spend no quota),
   adds the same Origin check `subscribe.php` has, and requires `Content-Type: application/json` (415 otherwise),
   so another site cannot spend the owner's quota from a visitor's browser.
19. **Full-list reply is uniform (§5.8).** `sub_add()` checks the cap before the duplicate scan, so a full list
   answers the same 503 for an address already on it and a new one; membership can no longer be probed.
20. **Upload resync restores only live-referenced images (§11.8).** `uploads_sync_public()` restores a public
   copy only for upload paths that live posts (cover + body) or the live site/shelf still reference, so deleting
   both copies of a photo takes it down for good instead of being undone by the next publish. The image help
   tells the owner to delete both `blog-data/uploads/…` and `public_html/uploads/…`.
21. **Data-dir error hides the path (admin).** `admin/index.php` logs the absolute `blog-data` path to
   `error_log` and shows the visitor only a relative hint, so the hosting account layout is not disclosed.
22. **HTTPS is forced (§14).** `pilot-blog/.htaccess` redirects plain HTTP to HTTPS (skipping when TLS is on or
   the host proxy forwarded `X-Forwarded-Proto: https`) and sends HSTS over HTTPS, so readers' emails and the
   home page never travel in clear text. Deploy note: also turn on hPanel's "Force HTTPS".

Added by the visitor-statistics pass (2026-10-15):

23. **Visitor statistics.** New public endpoint `api/hit.php` (allowed in `api/.htaccess`, the dev router and both Nginx
   files exactly like `subscribe.php`), `app/lib/stats.php`, admin route `stats` / `stats.toggle` (menu 访问统计, 概览
   card). Home: an inline `sendBeacon` script inside `<!--{if stats_live}-->` right before `</head>` (false in the
   baseline, so T1 holds; T3 regions untouched); post and archive pages: a `<img class="hit-px">` pixel (hidden by
   `.hit-px` in `assets/post.css`, allowed by the post CSP `img-src 'self'`). Setting `stats.enabled` (default true;
   baseline seed false, launch patch true) republishes on change. Data: `blog-data/stats/YYYY-MM-DD.json` (views,
   visitors, paths, referrer hostnames capped at 100, phone/desktop, WeChat), `seen-<day>.txt` (16-hex HMAC of
   /64-or-IPv4 + UA under a per-day random salt in `salt.json`; both deleted when the day changes), days older than
   400 kept nowhere. Skipped: bots and missing UA, prefetch, cross-site, the owner's browser (`pb_nostat` cookie set
   by every admin page), unknown paths, previews; 120 counted views per visitor per hour, 200 000 per day. Referrers
   come only from the home page (post pages run no script).

Added by the section-reorder pass (2026-10-15, owner request):

24. **Section order and a deliberate T1 rebaseline.** The owner approved a new home order below the hero: 关于 (01) → 机型 (02)
   → 进近小游戏 (unnumbered) → 飞行日志 (03) → AI 超市 (04) → 问答 (05) → 订阅 (06) → footer. `home.tpl.html` was edited
   directly (sections moved, kickers renumbered, chart furniture: `FIX` cell `PILOT · PC12X · LOGBK · AISHP · CHKLT`, missed
   approach `DIRECT LOGBK · HOLD`, the `IAF` tag moved to PC12X, MEA/MOCA leg figures swapped so they descend; new route
   bends: two after the approach plate (right lane, then over the LOGBK hold, clear of the log plate) and one after the FAQ;
   all three hidden on phones where the route is the left rail). After the landing the plate says
   「落地，滑行到停机位，看看最近飞了什么。」 and the route carries on to LOGBK. The 订阅 runway is no longer lit by the game:
   `setLanded()` in the chart script lights it when the route reaches RW24 (and unlights it when the reader scrolls back up);
   reduced motion shows it lit from the start. Go-around is unchanged.
   Because the old order was frozen by T1, the baseline was regenerated on purpose with
   `php app/bin/check-template.php --rebaseline "…"` (logged in `app/seed/rebaseline.log`); `lib-checks.php` now holds
   `BASELINE_SHA256 = 0f206a8a44a744f5c5ccabb510c5270ad8c6135a5a5bb1e6191e559952e5d075` and keeps the 08da7c1 hash as
   `BASELINE_08DA7C1_SHA256` (only `--init-hero-reference` uses it). T3 is unchanged and passes: the hero regions and the
   nav skeleton are byte-identical. Because the nav markup is part of the T3 skeleton, the nav order (关于 机型 日志 AI 超市 问答)
   is set by a CSS `order` rule outside the hero CSS and the chart script moves the 机型 item before 日志 for keyboard and
   screen-reader order; the footer links were reordered in the markup.
   Field keys are unchanged. `field_groups()` and the registry rows follow the new order (admin 网站文字: … 关于, 机型,
   进近小游戏, 飞行日志栏, AI 超市栏 …). `site_upgrade_fields()` (fields.php) turns saved values that still equal an old
   default into the new one (`log.kicker` 02 / LOG → 03 / LOG, `type.kicker` 03 / TYPE → 02 / TYPE, `apch.done`), applied
   by `content_get('site')`, `site_normalize()` and the render contexts, so the live site's `blog-data` needs no hand edit;
   a value the owner wrote is left alone. The §4.8 group tables above are listed in the new order.

25. **关于 photo album (INSET A).** New site-content key `album`: a list (1 to 8) of `{src, caption, alt}`; `src` is the
   bundled `assets/about.webp` (1920 × 1086) or an upload in `uploads/index.json` (sizes always come from the index). New
   block `about_album` (the whole `<figure class="about-fig">`) and condition `has_album` (2+ photos). One photo renders
   exactly the old figure (so T1 holds and an owner with one photo sees no change); 2+ photos render a native scroll-snap
   strip in the same frame (swipe on phones), with prev/next buttons (desktop), dots, arrow/Home/End keys on the focusable
   strip, the caption following the photo, every image `loading="lazy"`, no autoplay, label still `INSET A`. Its CSS is in
   `home-extra.css` (emitted when `has_album`), its script sits inside `<!--{if has_album}-->` outside the hero regions.
   Content saved before the album (no `album` key) renders one photo built from `about.figcaption` / `about.img_alt`
   (`site_album()`); those two keys stay in the registry with no template encoding and mirror the first photo, so old
   versions roll back cleanly. Admin: 网站文字 → 关于 → 照片（INSET A）: 加一张照片 (upload purpose `album`, the same GD
   re-encode and limits as covers), caption (required, ≤ 60) and alt (optional, ≤ 150) per photo, 上移 / 下移 / 删除, the last
   photo cannot be deleted. `uploads_referenced()` counts live album photos so `uploads_sync_public()` restores them.
   Tests: run.php group `album`, public.e2e.js scenarios `album1` / `album3` (390 and 1440, screenshots in
   `pilot-blog-work/screens/reorder/`), admin.e2e.js album flow, public.e2e.js `runway-*` (go-around, landing copy, runway
   lit by the route, reduced motion).

## 19. How to run locally

Everything below runs from the repo root (`/home/user/momo`). Nothing here needs a real API key.

```bash
# T1 + T3 + template lint (prints T1 PASS, T3 PASS, LINT PASS)
php pilot-blog/app/bin/check-template.php

# re-extract the seed after editing defaults in home.tpl.html (then run the check again)
php pilot-blog/app/bin/extract-seed.php

# the committed index.html is the launch render (T2)
php pilot-blog/app/bin/render-launch.php > pilot-blog/index.html

# unit + HTTP tests (temp dirs only; ~10 s): template, markdown, publish, images, subscribers, http
php pilot-blog-work/backend/tests/run.php            # or name groups: ... run.php http publish

# public pages in Chromium: launch page + browser subscribe flow, then 0, 1, 3 and 8 published posts at 1440 and 390
node pilot-blog-work/backend/tests/public.e2e.js     # or: ... public.e2e.js pub3

# the owner's end-to-end QA: every admin flow at 1440 and 390 on a throwaway site (~2.5 min)
PORT=9300 node pilot-blog-work/backend/e2e.js         # or: ... e2e.js 390   (add keep to leave the temp dirs)

# a local site with its own data dir (never the repo's pilot-blog/ or /home/user/momo/blog-data)
S=$(mktemp -d); mkdir -p $S/pub/posts $S/pub/uploads $S/data
for d in assets api app admin; do ln -s $PWD/pilot-blog/$d $S/pub/$d; done
cp pilot-blog/index.html $S/pub/; cp pilot-blog/posts/.htaccess $S/pub/posts/
export BLOG_PUBLIC_DIR=$S/pub BLOG_DATA_DIR=$S/data
php pilot-blog/app/bin/publish.php                   # installs the launch seed on first run, then publishes
php -S 127.0.0.1:8977 -t $S/pub pilot-blog/app/dev/router.php   # add BLOG_CHAT_FAKE=1 for a fake chat answer
# stop it with: kill $(pgrep -f "^php -S 127.0.0.1:8977")

# the existing page regression (expects the server above on the same port; chat answers 503)
cd pilot-blog-work/mockups/B-chart/_src && PORT=8977 node test.js both noshots
```

Notes: `BLOG_PUBLIC_DIR` / `BLOG_DATA_DIR` exist only for tests and local runs; on Hostinger the app uses
`public_html` and `../blog-data`. The dev router mirrors the `.htaccess` denies (403) and sends the
`posts/` and `uploads/` CSP headers, so CSP mistakes show up as console errors in the Playwright tests.
`pilot-blog-work/backend/tools/make-template.php` is the one-time builder that produced `home.tpl.html`
from the 08da7c1 page; keep it for history, edit the template directly from now on.
