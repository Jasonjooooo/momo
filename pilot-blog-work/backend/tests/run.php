<?php
// Backend test runner (never deployed). Each group runs in its own PHP process with fresh temp
// BLOG_PUBLIC_DIR / BLOG_DATA_DIR, so nothing touches pilot-blog/index.html, posts/, uploads/ or blog-data/.
// Usage: php pilot-blog-work/backend/tests/run.php [group ...]
declare(strict_types=1);

const REPO = __DIR__ . '/../../..';
const GROUPS = ['template', 'markdown', 'publish', 'images', 'album', 'subscribers', 'http', 'stats', 'shop', 'admin'];

if (($argv[1] ?? '') !== '--child') {
    $want = array_slice($argv, 1) ?: GROUPS;
    $failed = 0;
    $passed = 0;
    foreach ($want as $g) {
        $tmp = make_site();
        $env = ['BLOG_PUBLIC_DIR' => $tmp . '/pub', 'BLOG_DATA_DIR' => $tmp . '/data', 'TEST_TMP' => $tmp, 'PATH' => getenv('PATH')];
        $p = proc_open([PHP_BINARY, __FILE__, '--child', $g], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        $code = proc_close($p);
        echo $out . $err;
        preg_match_all('/^  ok /m', $out, $ok);
        preg_match_all('/^  FAIL /m', $out, $bad);
        $passed += count($ok[0]);
        $failed += count($bad[0]) + ($code !== 0 && !$bad[0] ? 1 : 0);
        exec('rm -rf ' . escapeshellarg($tmp));
    }
    echo "\n" . ($failed ? "FAILED: $failed failing, $passed passing\n" : "ALL PASS: $passed checks\n");
    exit($failed ? 1 : 0);
}

/** A temp public dir (symlinked code, real posts/ uploads/, copy of index.html) and an empty data dir. */
function make_site(): string
{
    $tmp = sys_get_temp_dir() . '/pbtest-' . bin2hex(random_bytes(4));
    mkdir($tmp . '/pub/posts', 0777, true);
    mkdir($tmp . '/pub/uploads', 0777, true);
    mkdir($tmp . '/data', 0777, true);
    foreach (['assets', 'api', 'app', 'admin'] as $d) {
        symlink(realpath(REPO . '/pilot-blog/' . $d), $tmp . '/pub/' . $d);
    }
    copy(REPO . '/pilot-blog/index.html', $tmp . '/pub/index.html');
    copy(REPO . '/pilot-blog/.htaccess', $tmp . '/pub/.htaccess');
    copy(REPO . '/pilot-blog/posts/.htaccess', $tmp . '/pub/posts/.htaccess');
    copy(REPO . '/pilot-blog/uploads/.htaccess', $tmp . '/pub/uploads/.htaccess');
    return $tmp;
}

// ---------------- child ----------------
define('BLOG_APP', true);
require REPO . '/pilot-blog/app/lib/bootstrap.php';
require REPO . '/pilot-blog/app/bin/lib-checks.php';
date_default_timezone_set('UTC');
$group = $argv[2];
echo "[$group]\n";

function ok(bool $cond, string $name, string $detail = ''): void
{
    echo ($cond ? '  ok ' : '  FAIL ') . $name . ($cond || $detail === '' ? '' : ' -- ' . $detail) . "\n";
}

function throws(callable $fn, string $class = Throwable::class): bool
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e instanceof $class;
    }
    return false;
}

function rand_str(int $n): string
{
    $pool = ['a', 'Z', '0', '9', ' ', '<', '>', '&', '"', "'", '\\', '|', '{', '}', '/', '-', '.', '×', '云', '底', '😀', "\u{2028}", "\u{2029}", '<!--{/t}-->', '</script>', '<!--', '`', '$', '%'];
    $s = '';
    for ($i = 0; $i < $n; $i++) {
        $s .= $pool[random_int(0, count($pool) - 1)];
    }
    return $s;
}

function pub(string $rel): string
{
    return (string) @file_get_contents(PUBLIC_DIR . '/' . $rel);
}

function group_template(): void
{
    $baseline = (string) file_get_contents(APP_DIR . '/seed/baseline-index.html');
    ok(hash('sha256', $baseline) === BASELINE_SHA256, 'baseline-index.html is the frozen baseline (rebaselined 2026-10-17 for the owner\'s shop, SPEC §18 item 26)');
    $r = render_baseline();
    ok($r === $baseline, 'T1 baseline render is byte-identical', $r === $baseline ? '' : first_diff($r, $baseline));
    $launch = render_launch();
    $committed = (string) file_get_contents(REPO . '/pilot-blog/index.html');
    ok($launch === $committed, 'T2 launch render equals committed pilot-blog/index.html', $launch === $committed ? '' : first_diff($launch, $committed));
    ok(hero_compare($baseline) === [], 'T3 reference matches the baseline itself');
    ok(hero_compare($launch) === [], 'T3 launch render hero regions identical');
    ok(hero_compare(render_content(probe_content())) === [], 'T3 probe render (<>&"\'\\ and | in every field) hero regions identical');
    ok(template_hero_markers(tpl_source('home')) === [], 'T3 no markers inside hero regions 1-6 of home.tpl.html');
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_DIR . '/bin/check-template.php'), $out, $code);
    ok($code === 0, 'check-template.php exits 0 (T1, T3, lint)', implode(' | ', $out));

    // T4 encodings round-trip random strings
    $encs = ['text', 'attr', 'js', 'lat', 'paras:3', 'li', 'wbr'];
    $bad = [];
    for ($i = 0; $i < 400; $i++) {
        $v = rand_str(random_int(0, 30));
        foreach ($encs as $enc) {
            $val = $v;
            if ($enc === 'li' || str_starts_with($enc, 'paras')) {
                $val = str_replace(["\n", "\r"], '', $val);
                $val = trim($val, " \t") === '' ? 'x' : trim($val, " \t") . "\n\n" . 'second <line>';
                $val = implode("\n\n", array_map(fn($l) => trim($l, " \t"), explode("\n\n", $val)));
                if ($enc === 'li') {
                    $val = str_replace("\n\n", "\n", $val);
                }
            }
            if ($enc === 'wbr') {
                $val = $v;
            }
            $html = enc_render($enc, $val, '      ');
            if (in_array($enc, ['text', 'attr', 'wbr', 'lat', 'li']) || str_starts_with($enc, 'paras')) {
                $stripped = preg_replace('/<(\/?)(li|wbr|span class="lat"|\/span|p class="rv" style="--i:\d+"|\/p)>/', '', $html);
                if (str_contains((string) $stripped, '<')) {
                    $bad[] = "$enc raw <";
                }
            }
            if ($enc === 'attr' && str_contains($html, '"')) {
                $bad[] = 'attr raw quote';
            }
            if ($enc === 'js' && (preg_match("/(?<!\\\\)'/", str_replace('\\\\', '', $html)) || str_contains($html, '<') || str_contains($html, "\u{2028}"))) {
                $bad[] = 'js raw quote or <';
            }
            if (str_contains($html, '<!--{')) {
                $bad[] = "$enc marker leaked";
            }
            try {
                $back = enc_decode($enc, $html, '      ');
                if (in_array($enc, ['text', 'attr', 'js', 'lat', 'wbr'], true) && $back !== $val) {
                    $bad[] = "$enc round-trip " . json_encode($val, JSON_UNESCAPED_UNICODE);
                }
                if (enc_render($enc, $back, '      ') !== $html) {
                    $bad[] = "$enc not canonical";
                }
            } catch (Throwable $e) {
                $bad[] = "$enc decode threw " . $e->getMessage();
            }
        }
    }
    ok($bad === [], 'T4 encodings round-trip 400 random strings x 7 encodings, no raw < " \' leaks', implode('; ', array_slice(array_unique($bad), 0, 5)));
    $malformed = [
        'unclosed t' => '<p><!--{t site.brand}-->x</p>',
        'unknown key' => '<!--{t nope.nope}-->x<!--{/t}-->',
        'unknown enc' => '<!--{t site.brand|html}-->x<!--{/t}-->',
        'nested if' => '<!--{if subscribe_live}--><!--{if has_teasers}-->x<!--{/if}--><!--{/if}-->',
        'marker in t' => '<!--{t site.brand}--><!--{v chat_live}-->x<!--{/v}--><!--{/t}-->',
        'bad syntax' => '<!--{t  site.brand}-->x<!--{/t}-->',
        'stray close' => 'x<!--{/if}-->',
        'unknown cond' => '<!--{if sunny}-->x<!--{/if}-->',
        'half marker' => '<!--{t site.brand',
    ];
    foreach ($malformed as $name => $src) {
        ok(throws(fn() => tpl_parse($src), TemplateError::class), "T4 malformed template throws: $name");
    }
    ok(throws(fn() => tpl_extract(tpl_parse('<p><!--{t site.brand}-->a &quot; b<!--{/t}--></p>')), TemplateError::class), 'T4 non-canonical default rejected');
    ok(throws(fn() => tpl_extract(tpl_parse('<p><!--{t site.brand}-->a<b>x</b><!--{/t}--></p>')), TemplateError::class), 'T4 markup inside a text default rejected');

    // field validation
    ok(field_problem('site.origin', 'https://DEPLOY-DOMAIN') === null, 'seed origin passes');
    ok(field_problem('site.origin', 'https://example.com/') !== null, 'origin with trailing / rejected');
    ok(field_problem('site.origin', 'javascript:alert(1)') !== null, 'javascript: origin rejected');
    ok(field_problem('log.count_writing', '在写 篇') !== null, 'pattern without {t} rejected');
    ok(field_problem('log.count_writing', '在写 {t} {x} 篇') !== null, 'pattern with unknown placeholder rejected');
    ok(field_problem('about.h2', "a\nb") !== null, 'newline in single-line field rejected');
    ok(field_problem('about.h2', 'a|b') !== null, '| outside wbr fields rejected');
    ok(field_problem('hero.b1.hl', 'a|b') === null, '| allowed in wbr field');
    ok(field_problem('about.h2', str_repeat('长', 500)) !== null, 'over max rejected');
    $site = seed_content(true)['site'];
    ok(site_validate($site) === [], 'launch site content validates');
    $s2 = $site;
    $s2['fields']['bogus.key'] = 'x';
    ok(isset(site_validate($s2)['fields.bogus.key']), 'unknown field rejected');
    $s3 = $site;
    $s3['faq'] = [];
    ok(isset(site_validate($s3)['faq']), 'empty FAQ rejected');
    ok(site_validate(site_normalize(['fields' => [], 'faq' => $site['faq'], 'type_facts' => $site['type_facts']])) === [], 'site_normalize fills missing fields from the seed');
    $copy = json_encode([seed_content(false), json_read(APP_DIR . '/seed/launch.patch.json'), field_registry()], JSON_UNESCAPED_UNICODE);
    $banned = ['—', '赋能', '无缝', '一站式', '解决方案', '助力', '打造', '颠覆', '神器', '极致'];
    $hits = array_filter($banned, fn($w) => str_contains($copy, $w));
    ok($hits === [], 'T11 seed, launch patch and registry contain no banned words or em dash', implode(' ', $hits));
}

function group_markdown(): void
{
    $up = ['uploads/2026/10/0123456789abcdef.webp' => ['w' => 2000, 'h' => 1125]];
    $md = fn($s) => md_render($s, ['base' => '../', 'uploads' => $up]);
    $gold = [
        "第一行\n第二行" => "<p>第一行</p>\n<p>第二行</p>",
        "## 进近简令" => '<h2>进近简令</h2>',
        "# 标题" => '<h2>标题</h2>',
        "### 小节" => '<h3>小节</h3>',
        "- 一\n- 二\n* 三" => '<ul><li>一</li><li>二</li><li>三</li></ul>',
        "1. 一\n2. 二" => '<ol><li>一</li><li>二</li></ol>',
        "> 注意\n> 第二句" => '<aside class="note"><p class="note-k">NOTE</p><p>注意</p><p>第二句</p></aside>',
        "---" => '<hr>',
        "**加粗** 和 `code`" => '<p><strong>加粗</strong> 和 <code>code</code></p>',
        "[官网](https://www.pilatus-aircraft.com/x?a=1&b=2)" => '<p><a href="https://www.pilatus-aircraft.com/x?a=1&amp;b=2" rel="noopener noreferrer" target="_blank">官网</a></p>',
        "[上一篇](posts/minms.html)" => '<p><a href="../posts/minms.html">上一篇</a></p>',
        "[订阅](#subscribe)" => '<p><a href="../#subscribe">订阅</a></p>',
        "\\*不是加粗\\*" => '<p>*不是加粗*</p>',
        '![跑道入口灯](uploads/2026/10/0123456789abcdef.webp "雾里的绿色入口灯")' => '<figure><img src="../uploads/2026/10/0123456789abcdef.webp" width="2000" height="1125" alt="跑道入口灯" loading="lazy" decoding="async"><figcaption>雾里的绿色入口灯</figcaption></figure>',
        "3.5 小时\n2000 米" => "<p>3.5 小时</p>\n<p>2000 米</p>",
    ];
    foreach ($gold as $src => $want) {
        $got = $md($src);
        ok($got === $want, 'T5 golden: ' . str_replace("\n", '⏎', mb_substr($src, 0, 30)), $got);
    }
    $xss = [
        '<script>alert(1)</script>',
        '<img src=x onerror=alert(1)>',
        '[x](javascript:alert(1))',
        '[x](JaVaScRiPt:alert(1))',
        '[x](//evil.example/a)',
        '[x](data:text/html,<script>alert(1)</script>)',
        '![x](../../etc/passwd)',
        '![x](uploads/a.svg)',
        '![x](uploads/2026/10/0123456789abcdef.svg)',
        '"><svg/onload=alert(1)>',
        '**[x](javascript:alert(1))**',
        '[a"onmouseover="alert(1)](https://ok.example)',
        '[x](https://ok.example/"onmouseover="alert(1))',
        "`<script>`",
        '> <iframe src=x>',
        '- <b onclick=alert(1)>x</b>',
        '![<img src=x onerror=alert(1)>](uploads/2026/10/0123456789abcdef.webp "<script>")',
        '[x](https://user:pass@evil.example)',
    ];
    $bad = [];
    foreach ($xss as $src) {
        $h = $md($src);
        $doc = new DOMDocument();
        @$doc->loadHTML('<meta charset="utf-8"><body>' . $h . '</body>');
        $xp = new DOMXPath($doc);
        $evil = $xp->query('//script|//iframe|//svg|//object|//embed|//b|//*[@*[starts-with(name(), "on")]]')->length;
        foreach ($xp->query('//a/@href|//img/@src') as $attr) {
            if (!preg_match('#^(https?://[A-Za-z0-9.-]+|\.\./)#', $attr->value)) {
                $evil++;
            }
        }
        if ($evil) {
            $bad[] = $src . ' => ' . $h;
        }
    }
    ok($bad === [], 'T5 XSS list renders inert (' . count($xss) . ' cases)', implode("\n", $bad));
    $doc = new DOMDocument();
    @$doc->loadHTML('<meta charset="utf-8">' . $md(implode("\n", $xss)));
    $xp = new DOMXPath($doc);
    ok($xp->query('//script|//iframe|//svg|//*[@onerror]|//*[@onload]|//*[@onclick]|//*[@onmouseover]')->length === 0, 'T5 XSS list parsed by DOM: no script, iframe, svg or on* attributes');
    $long = str_repeat("## 小标题\n这是一段正文，**加粗**，`代码`，[链接](https://example.com)。\n- 列表\n> 注意\n", 600);
    $long = mb_substr($long, 0, 60000);
    $t = microtime(true);
    $md($long);
    $ms = (microtime(true) - $t) * 1000;
    ok($ms < 200, sprintf('T5 60 000 chars render in %.0f ms (< 200)', $ms));
    $t = microtime(true);
    $md(str_repeat('[', 30000) . str_repeat('*', 30000));
    $ms = (microtime(true) - $t) * 1000;
    ok($ms < 200, sprintf('T5 pathological 60 000 chars render in %.0f ms (< 200)', $ms));
    ok(md_excerpt("## 标题\n" . str_repeat('云', 100), 80) === '标题 ' . str_repeat('云', 76) . '…', 'md_excerpt cuts at 80 with …');
    ok(md_reading_minutes(str_repeat('云', 2000)) === 5, 'reading minutes: 2000 Han chars = 5');
    ok(md_reading_minutes('short') === 1, 'reading minutes minimum 1');
}

/** Publish a post through the library. */
function make_post(string $title, string $body, string $date, array $extra = []): array
{
    $p = post_create();
    $p = post_save_draft($p['id'], array_merge(['title' => $title, 'body' => $body, 'date' => $date, 'tags' => 'IMC 天气'], $extra), $p['rev']);
    return $p;
}

function group_publish(): void
{
    app_init('cli');
    $r = publish_site('初始内容');
    ok($r['ok'] && $r['version_id'] !== null, 'publish seed content (version 1 created)');
    $committed = (string) file_get_contents(REPO . '/pilot-blog/index.html');
    ok(pub('index.html') === $committed, 'acceptance 1: published seed index.html is byte-identical to the committed page');
    ok(hero_compare(pub('index.html')) === [], 'T3 on the published page');
    ok(is_file(PUBLIC_DIR . '/posts/index.html') && str_contains(pub('posts/index.html'), '还没有发布的日志。'), 'archive exists and says no posts yet');
    $r2 = publish_site('again');
    ok($r2['ok'] && $r2['version_id'] === null, 'republish without content change creates no version');

    // a draft never reaches the public site
    $d = make_post('草稿标题不应该出现XYZ', "正文第一段。\n第二段。", '2026-10-01');
    publish_site('x');
    $all = pub('index.html') . pub('posts/index.html') . implode('', array_map('file_get_contents', glob(PUBLIC_DIR . '/posts/*.html')));
    ok(!str_contains($all, '草稿标题不应该出现XYZ') && !is_file(PUBLIC_DIR . '/posts/' . $d['slug'] . '.html'), 'draft is not published anywhere');
    ok(post_status($d) === 'draft', 'new post status is draft');

    // publish it
    $res = post_publish($d['id'], $d['rev']);
    $p = $res['post'];
    $page = pub('posts/' . $p['slug'] . '.html');
    ok($page !== '' && str_contains($page, '草稿标题不应该出现XYZ'), 'published post page exists');
    ok(str_contains(pub('index.html'), 'href="posts/' . $p['slug'] . '.html"'), 'home leg links to the post');
    ok(str_contains(pub('index.html'), '<time datetime="2026-10-01">2026.10.01</time>'), 'home leg shows the date');
    ok(str_contains(pub('index.html'), '最新 1 篇 · 在写 5 篇'), 'log count shows mixed pattern');
    ok(str_contains(pub('index.html'), 'href="posts/">全部日志</a>'), 'all-posts link appears once something is published');
    ok(str_contains(pub('index.html'), '<style id="extra-css">'), 'extra css emitted');
    ok(str_contains(pub('posts/index.html'), $p['slug'] . '.html'), 'archive lists the post');
    ok(hero_compare(pub('index.html')) === [], 'T3 still passes with a published post');
    ok(!str_contains($page, '<script'), 'post page has no script');
    ok(!preg_match('/\sstyle="/', $page), 'post page has no inline style attributes (CSP)');
    ok(!preg_match('/(src|href)="(https?:)?\/\//', $page) || !preg_match('/(src|href)="(https?:)?\/\/(?!probe)/', str_replace('http://www.w3.org/2000/svg', '', $page)), 'T12 post page references no external URL');
    ok(count(versions_list()) === 2, 'two versions after one content change');

    // edit + save does not change live
    $before = pub('posts/' . $p['slug'] . '.html');
    $p = post_save_draft($p['id'], ['title' => '改过的标题'], $p['rev']);
    publish_site('x');
    ok(pub('posts/' . $p['slug'] . '.html') === $before && post_dirty($p), 'saving a draft of a published post leaves the live page unchanged');
    ok(throws(fn() => post_save_draft($p['id'], ['slug' => 'new-slug'], $p['rev']), ValidationError::class), 'slug locked after first publish');
    ok(throws(fn() => post_save_draft($p['id'], ['title' => 'x'], $p['rev'] - 1), ConflictException::class), 'stale rev rejected (409)');
    ok(post_save_draft($p['id'], ['title' => '改过的标题'], $p['rev'] - 1)['rev'] === $p['rev'], 'the same text with an old rev (retry after a lost answer) is no conflict and saves nothing');
    $p = post_publish($p['id'], $p['rev'])['post'];
    ok(str_contains(pub('posts/' . $p['slug'] . '.html'), '改过的标题'), '发布修改 updates the page');

    // fix rules
    $q = post_create();
    ok(throws(fn() => post_save_draft($q['id'], ['fix' => 'LOGBK'], $q['rev']), ValidationError::class), 'reserved fix rejected');
    ok(throws(fn() => post_save_draft($q['id'], ['fix' => 'MINMS'], $q['rev']), ValidationError::class), 'duplicate fix rejected');
    ok(throws(fn() => post_save_draft($q['id'], ['slug' => '../x'], $q['rev']), ValidationError::class), 'bad slug rejected');
    ok(throws(fn() => post_publish($q['id'], $q['rev']), ValidationError::class), 'publishing an empty draft fails validation');
    ok(throws(fn() => post_get('../../etc/passwd') ?? throw new RuntimeException()), 'path traversal id returns nothing');
    post_delete($q['id']);

    // unpublish, teaser, delete
    $p = post_unpublish($p['id'], $p['rev'])['post'];
    ok(!is_file(PUBLIC_DIR . '/posts/' . $p['slug'] . '.html') && !str_contains(pub('index.html'), 'posts/' . $p['slug'] . '.html'), '撤下 removes the page and the leg link');
    $p = post_save_draft($p['id'], ['excerpt' => '预告摘要', 'minutes' => 4], $p['rev']);
    $p = post_set_teaser($p['id'], $p['rev'])['post'];
    ok(str_contains(pub('index.html'), '预告摘要') && str_contains(pub('index.html'), '在写 6 篇'), '设为预告 shows the teaser leg');
    post_delete($p['id']);
    ok(!str_contains(pub('index.html'), '预告摘要') && post_get($p['id']) === null && glob(DATA_DIR . '/trash/posts/' . $p['id'] . '-*.json'), 'delete removes the leg and keeps a trash copy');

    // settings that change the page republish without a version
    $n = count(versions_list());
    $s = settings_get();
    $res = settings_save(['subscribe' => ['enabled' => false]], $s['rev']);
    ok($res['publish'] !== null && str_contains(pub('index.html'), "const SUBSCRIBE_ENDPOINT = '';") && str_contains(pub('index.html'), 'class="micro mode-a"'), 'subscribe off republishes the Mode A page');
    ok(count(versions_list()) === $n, 'settings republish adds no version');
    $s = settings_get();
    settings_save(['subscribe' => ['enabled' => true], 'chat' => ['enabled' => true]], $s['rev']);
    ok(str_contains(pub('index.html'), 'const CHAT_LIVE = false;'), 'chat on without a key stays CHAT_LIVE = false');
    $key = 'sk-ant-test' . str_repeat('k', 30) . 'WXYZ';
    ok(throws(fn() => chat_key_set('not-a-key'), ValidationError::class), 'malformed key rejected');
    $res = chat_key_set($key);
    ok($res !== null && str_contains(pub('index.html'), 'const CHAT_LIVE = true;'), 'setting a key with chat on republishes CHAT_LIVE = true');
    ok(chat_key_hint() === 'WXYZ' && chat_key_source() === 'admin', 'key hint shows the last 4 only');
    $leak = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(DATA_DIR, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile() && $f->getFilename() !== 'secrets.json' && str_contains((string) file_get_contents($f->getPathname()), $key)) {
            $leak[] = $f->getPathname();
        }
    }
    foreach (glob(PUBLIC_DIR . '/{,posts/}*.html', GLOB_BRACE) as $f) {
        if (str_contains((string) file_get_contents($f), $key)) {
            $leak[] = $f;
        }
    }
    ok($leak === [], 'the key is only in secrets.json', implode(', ', $leak));
    ok((fileperms(DATA_DIR . '/secrets.json') & 0777) === 0600, 'secrets.json is 0600');
    chat_key_remove();
    ok(str_contains(pub('index.html'), 'const CHAT_LIVE = false;'), 'removing the key republishes CHAT_LIVE = false');
    $s = settings_get();
    ok(throws(fn() => settings_save(['home' => ['leg_limit' => 99]], $s['rev']), ValidationError::class), 'leg limit out of range rejected');

    // site copy publish + rollback
    $site = site_live();
    $v1 = versions_list()[0]['id'];
    $site['fields']['about.h2'] = '改版的标题';
    json_write_atomic(data_path('content', 'site.live.json'), $site);
    publish_site('更新网站文字');
    ok(str_contains(pub('index.html'), '改版的标题') && hero_compare(pub('index.html')) === [], 'site copy publish changes the page, hero intact');
    $draftBefore = json_read(data_path('content', 'site.draft.json'));
    $rb = version_rollback($v1);
    ok($rb['ok'] && !str_contains(pub('index.html'), '改版的标题'), 'rollback restores the earlier live content');
    ok(json_read(data_path('content', 'site.draft.json')) === $draftBefore, 'rollback leaves drafts untouched');
    ok(version_file($v1, 'index.html') !== null && version_file($v1, '../secrets.json') === null && version_file('../../x', 'index.html') === null, 'version_file serves only manifest names');

    // site copy and shelf through the draft/live functions the admin uses
    $draft = content_get('site');
    $live0 = pub('index.html');
    $input = $draft;
    $input['fields']['hero.b1.hl'] = '三千英尺，|云层之上。';
    $input['faq'][] = ['nid' => '', 'q' => '新的问题？', 'r' => '新', 'a' => '新的回答。', 'subscribe_note' => false];
    $saved = content_save_draft('site', $input, (int) $draft['rev']);
    ok(pub('index.html') === $live0, 'saving the site draft leaves index.html unchanged');
    ok(in_array('hero.b1.hl', content_changed_fields(), true), 'changed fields reported');
    $other = $input;
    $other['fields']['hero.b1.hl'] = '另一个窗口写的。';
    ok(throws(fn() => content_save_draft('site', $other, (int) $draft['rev']), ConflictException::class), 'stale site draft rev rejected');
    ok(content_save_draft('site', $input, (int) $draft['rev'])['rev'] === $saved['rev'], 'the same text again with the old rev (retry after a lost answer) is no conflict and saves nothing');
    $bad = $input;
    $bad['fields']['about.h2'] = "两行\n标题";
    $bad['fields']['about.h2'] = str_repeat('长', 401);
    ok(throws(fn() => content_save_draft('site', $bad, (int) $saved['rev']), ValidationError::class), 'invalid site draft rejected');
    $html = preview_home($saved, content_get('shelf'));
    ok(str_contains($html, '云层之上') && str_contains($html, '<base href="../">') && str_contains($html, '预览：'), 'preview_home shows the draft with base href and banner');
    $res = content_publish('site', (int) $saved['rev']);
    ok($res['ok'] && str_contains(pub('index.html'), '三千英尺，<wbr>云层之上。') && str_contains(pub('index.html'), '新的问题？'), 'site publish updates the page');
    ok(preg_match('/<span class="nid" aria-hidden="true">A0105\/\d\d<\/span><span class="q">新的问题？/', pub('index.html')) === 1, 'empty NID gets an automatic one');
    ok(hero_compare(pub('index.html')) === [], 'T3 after a site publish');
    $sh = content_get('shelf');
    $sh['shelves'][] = ['id' => '', 'title' => '新货架', 'blurb' => '测试', 'tools' => [['name' => '工具', 'desc' => '一句话', 'url' => 'https://example.com', 'cn_access' => '还没确认', 'note' => '']]];
    array_unshift($sh['shelves'], array_pop($sh['shelves']));
    $sh = content_save_draft('shelf', $sh, (int) $sh['rev']);
    ok(preg_match('/^s[0-9a-f]{6}$/', $sh['shelves'][0]['id']) === 1 && $sh['shelves'][0]['tools'][0]['cn_access'] === null, 'new shelf gets an id; 还没确认 stored as null');
    content_publish('shelf', (int) $sh['rev']);
    ok(!str_contains(pub('index.html'), 'shelf-data') && !str_contains(pub('index.html'), '新货架') && str_contains(pub('index.html'), 'id="shop"'), 'old shelf data still saves and publishes but no longer reaches the page (SPEC §18 item 26)');
    $sh2 = $sh;
    $sh2['shelves'][0]['tools'][0]['url'] = 'javascript:alert(1)';
    ok(throws(fn() => content_save_draft('shelf', $sh2, (int) $sh['rev']), ValidationError::class), 'javascript: tool URL rejected');
    $sh3 = $sh;
    $sh3['shelves'][0]['tools'] = [];
    $sh3 = content_save_draft('shelf', $sh3, (int) $sh['rev']);
    ok(throws(fn() => content_publish('shelf', (int) $sh3['rev']), ValidationError::class), 'an empty shelf cannot be published');
    content_discard('shelf');
    ok(content_get('shelf')['shelves'][0]['tools'] !== [], 'discard restores the live shelf into the draft');
    $o = site_origin_set('https://pilot.example.com');
    ok($o['ok'] && str_contains(pub('index.html'), 'content="https://pilot.example.com/assets/og.jpg"') && !str_contains(pub('index.html'), 'DEPLOY STEP'), 'origin quick-set fills og URLs and drops the deploy note');
    version_rollback(versions_list()[count(versions_list()) - 1]['id']);
    ok(str_contains(pub('index.html'), 'https://pilot.example.com/'), 'rollback keeps the current site address');

    // version pruning
    for ($i = 0; $i < 11; $i++) {
        $site = site_live();
        $site['fields']['footer.tag'] = '落地，关车，写日志。' . $i;
        json_write_atomic(data_path('content', 'site.live.json'), $site);
        publish_site('v' . $i);
    }
    ok(count(versions_list()) === 10 && count(glob(DATA_DIR . '/versions/*', GLOB_ONLYDIR)) === 10, '11 more content publishes keep 10 versions');

    // a broken template aborts without writing
    $before = pub('index.html');
    $site = site_live();
    $site['fields']['about.h2'] = '';
    json_write_atomic(data_path('content', 'site.live.json'), $site);
    $bad = publish_site('broken');
    ok(!$bad['ok'] && pub('index.html') === $before, 'invalid content aborts publishing, files untouched');
    $site['fields']['about.h2'] = '左座上的人。';
    json_write_atomic(data_path('content', 'site.live.json'), $site);

    // status + self heal
    file_put_contents(PUBLIC_DIR . '/index.html', 'tampered');
    $st = site_status();
    ok($st['index_changed'], 'site_status notices a changed index.html');
    $h = site_self_heal();
    ok($h !== null && $h['ok'] && str_starts_with(pub('index.html'), '<!doctype html>'), 'self-heal regenerates the page');
    ok(site_self_heal() === null, 'self-heal is quiet when files match');
    unlink(PUBLIC_DIR . '/posts/.htaccess');
    publish_site('x');
    ok(is_file(PUBLIC_DIR . '/posts/.htaccess'), 'publish restores posts/.htaccess');
    ok(glob(PUBLIC_DIR . '/.*.tmp-*') === [] && glob(PUBLIC_DIR . '/posts/.*.tmp-*') === [], 'no temp files left behind');
    ok(throws(fn() => data_path('..', 'x'), InvalidArgumentException::class) && throws(fn() => public_path('posts', '../../etc'), InvalidArgumentException::class), 'path helpers reject traversal');
}

function jpeg_with_orientation(int $w, int $h, int $orientation): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, intdiv($w, 2), $h, imagecolorallocate($im, 255, 0, 0));
    ob_start();
    imagejpeg($im, null, 90);
    $jpg = (string) ob_get_clean();
    $tiff = 'MM' . "\x00\x2A" . pack('N', 8) . pack('n', 1) . pack('nnN', 0x0112, 3, 1) . pack('n', $orientation) . "\x00\x00" . pack('N', 0);
    $exif = "Exif\x00\x00" . $tiff;
    $app1 = "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif;
    return substr($jpg, 0, 2) . $app1 . substr($jpg, 2);
}

function group_images(): void
{
    app_init('cli');
    $dir = getenv('TEST_TMP') . '/img';
    @mkdir($dir);
    file_put_contents("$dir/rot.jpg", jpeg_with_orientation(40, 20, 6));
    $r = image_store_file("$dir/rot.jpg");
    ok($r['w'] === 20 && $r['h'] === 40, 'JPEG with EXIF orientation 6 is rotated upright', json_encode($r));
    ok(preg_match(UPLOAD_PATH_RE, $r['src']) === 1 && preg_match(UPLOAD_PATH_RE, $r['og']) === 1, 'upload names match the whitelist');
    ok(is_file(DATA_DIR . '/' . $r['src']) && is_file(PUBLIC_DIR . '/' . $r['src']) && is_file(PUBLIC_DIR . '/' . $r['og']), 'master and public copies written');
    ok(isset(uploads_index()['items'][$r['src']]), 'uploads/index.json updated');
    $big = imagecreatetruecolor(3000, 1500);
    imagesavealpha($big, true);
    imagefill($big, 0, 0, imagecolorallocatealpha($big, 0, 0, 0, 127));
    imagepng($big, "$dir/big.png");
    $r2 = image_store_file("$dir/big.png");
    ok($r2['w'] === 2000 && $r2['h'] === 1000, 'PNG resized to 2000 px long edge');
    [$ow] = getimagesize(PUBLIC_DIR . '/' . $r2['og']);
    ok($ow === 1200, 'og JPEG is 1200 px');
    $gif = imagecreate(30, 30);
    imagecolorallocate($gif, 1, 2, 3);
    imagegif($gif, "$dir/a.gif");
    ok(image_store_file("$dir/a.gif")['w'] === 30, 'GIF accepted and re-encoded');
    file_put_contents("$dir/poly.jpg", jpeg_with_orientation(30, 30, 1) . '<?php echo "PWNED"; ?>');
    $r3 = image_store_file("$dir/poly.jpg");
    $bytes = (string) file_get_contents(PUBLIC_DIR . '/' . $r3['src']);
    ok(!str_contains($bytes, '<?php') && !str_contains($bytes, 'PWNED'), 'polyglot JPEG with a PHP payload is re-encoded without it');
    file_put_contents("$dir/shell.jpg", "<?php system(\$_GET['c']); ?>");
    ok(throws(fn() => image_store_file("$dir/shell.jpg"), ValidationError::class), '.php renamed to .jpg refused');
    file_put_contents("$dir/x.svg", '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>');
    ok(throws(fn() => image_store_file("$dir/x.svg"), ValidationError::class), 'SVG refused');
    $fh = fopen("$dir/huge.jpg", 'w');
    fwrite($fh, jpeg_with_orientation(10, 10, 1));
    ftruncate($fh, 13 * 1024 * 1024);
    fclose($fh);
    ok(throws(fn() => image_store_file("$dir/huge.jpg"), ValidationError::class), '13 MB file refused');
    file_put_contents("$dir/x.heic", pack('N', 24) . 'ftypheic' . pack('N', 0) . 'mif1heic' . str_repeat("\0", 64));
    try {
        image_store_file("$dir/x.heic");
        ok(false, 'HEIC refused');
    } catch (ValidationError $e) {
        ok(true, 'HEIC refused: ' . $e->getMessage());
    }
    // a cover makes the post page and og image use it
    $p = make_post('有封面的日志', '正文。', '2026-10-02', ['cover' => ['src' => $r2['src'], 'og' => $r2['og'], 'w' => $r2['w'], 'h' => $r2['h'], 'alt' => '云下的跑道']]);
    $p = post_publish($p['id'], $p['rev'])['post'];
    $page = pub('posts/' . $p['slug'] . '.html');
    ok(str_contains($page, '<img src="../' . $r2['src'] . '" width="2000" height="1000" alt="云下的跑道" loading="eager"') && str_contains($page, $r2['og'] . '">'), 'cover image and og:image on the post page');
    // uploads_sync_public restores only the images live content still references, so a photo the owner
    // takes down by deleting both copies is not quietly re-published (security finding F6).
    unlink(PUBLIC_DIR . '/' . $r2['src']);   // $r2 is the cover of the published post above
    unlink(PUBLIC_DIR . '/' . $r2['og']);
    unlink(PUBLIC_DIR . '/' . $r['src']);    // $r belongs to no live post (an orphan master)
    uploads_sync_public();
    ok(is_file(PUBLIC_DIR . '/' . $r2['src']) && is_file(PUBLIC_DIR . '/' . $r2['og']), 'uploads_sync_public restores an image a live post references');
    ok(!is_file(PUBLIC_DIR . '/' . $r['src']), 'a master nothing live references is not restored (a removed photo stays removed)');
}

/** 关于 · INSET A album and the section reorder migration (SPEC §18 item 24). */
function group_album(): void
{
    app_init('cli');
    ok(publish_site('初始内容')['ok'], 'seed published');
    $home = pub('index.html');
    ok(substr_count($home, '<figure class="about-fig">') === 1 && !str_contains($home, 'data-album') && !str_contains($home, 'function album()'), 'seed: one photo renders as the plain figure, no album markup or script');
    ok(str_contains($home, '<img src="assets/about.webp" width="1920" height="1086" loading="lazy" decoding="async" alt="日落之后'), 'seed: the bundled photo with its old alt');
    $order = [];
    foreach (['id="about"', 'id="type"', 'id="apch"', 'id="log"', 'id="ai"', 'id="faq"', 'id="subscribe"'] as $id) {
        $order[] = strpos($home, '<section class="sec ' . substr($id, 4, -1)) ?: strpos($home, $id);
    }
    $sorted = $order;
    sort($sorted);
    ok($order === $sorted && !in_array(false, $order, true), 'sections in the owner order: about, type, apch, log, ai, faq, subscribe');
    ok(str_contains($home, '02 / TYPE') && str_contains($home, '03 / LOG') && str_contains($home, '落地，滑行到停机位，看看最近飞了什么。'), 'new numbering and post-landing line');
    ok(!str_contains($home, "subSec.classList.add('landed')"), 'the runway is no longer lit by the approach game');

    // content saved before the reorder and the album: old defaults are upgraded, the owner's own caption is kept
    $old = content_get('site', 'live');
    unset($old['album']);
    $old['fields']['log.kicker'] = '02 / LOG';
    $old['fields']['type.kicker'] = '03 / TYPE';
    $old['fields']['apch.done'] = 'MINIMUMS。跑道可见，继续。';
    $old['fields']['about.figcaption'] = '站长自己写的照片小字';
    $old['fields']['about.kicker'] = '01 / 关于我';
    json_write_atomic(data_path('content', 'site.live.json'), $old);
    json_write_atomic(data_path('content', 'site.draft.json'), $old);
    ok(site_validate(site_normalize($old)) === [], 'legacy content without an album still validates');
    publish_site('legacy');
    $home = pub('index.html');
    ok(str_contains($home, '03 / LOG') && str_contains($home, '02 / TYPE') && !str_contains($home, 'MINIMUMS。跑道可见'), 'legacy defaults upgraded on publish');
    ok(str_contains($home, '01 / 关于我') && str_contains($home, '<figcaption>站长自己写的照片小字</figcaption>'), 'owner-written values kept (kicker, caption through the derived album)');
    ok(content_get('site', 'draft')['fields']['log.kicker'] === '03 / LOG', 'the editor sees the upgraded value');
    ok(site_album($old)[0]['src'] === ALBUM_SEED_SRC && site_album($old)[0]['caption'] === '站长自己写的照片小字', 'site_album derives the one photo from the fields');

    // three uploads become an album
    $dir = getenv('TEST_TMP') . '/alb';
    @mkdir($dir);
    $ups = [];
    foreach ([[1600, 900, [200, 40, 40]], [1200, 800, [40, 200, 40]], [900, 1200, [40, 40, 200]]] as $k => [$w, $h, $c]) {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, ...$c));
        imagejpeg($im, "$dir/$k.jpg");
        $ups[] = image_store_file("$dir/$k.jpg");
    }
    $draft = content_get('site', 'draft');
    $doc = ['fields' => $draft['fields'], 'faq' => $draft['faq'], 'type_facts' => $draft['type_facts'], 'album' => [
        ['src' => ALBUM_SEED_SRC, 'caption' => 'ON TOP · 云层上方', 'alt' => '云上的 PC-12'],
        ['src' => $ups[0]['src'], 'caption' => '第二张 <b>小字</b> & "引号"', 'alt' => ''],
        ['src' => $ups[2]['src'], 'caption' => '竖着拍的第三张', 'alt' => '停机坪'],
    ]];
    $saved = content_save_draft('site', $doc, (int) $draft['rev']);
    ok(count($saved['album']) === 3 && $saved['fields']['about.figcaption'] === 'ON TOP · 云层上方' && $saved['fields']['about.img_alt'] === '云上的 PC-12', 'album saved; the first photo is mirrored into the legacy fields');
    ok(pub('index.html') === $home, 'saving the album draft does not change the public page');
    $r = content_publish('site', (int) $saved['rev']);
    ok(($r['ok'] ?? false) === true, 'album published', json_encode($r['errors'] ?? null, JSON_UNESCAPED_UNICODE));
    $home = pub('index.html');
    ok(str_contains($home, '<figure class="about-fig album" data-album>') && substr_count($home, 'class="alb-slide"') === 3 && substr_count($home, 'class="alb-dot"') === 3, 'three slides and three dots');
    ok(str_contains($home, 'aria-label="照片，共 3 张"') && str_contains($home, '<p class="inset-tag" aria-hidden="true">INSET A</p>'), 'carousel label and the INSET A tag');
    ok(str_contains($home, 'data-cap="第二张 &lt;b&gt;小字&lt;/b&gt; &amp; &quot;引号&quot;"') && !str_contains($home, '<b>小字'), 'captions are escaped');
    ok(str_contains($home, 'src="' . $ups[2]['src'] . '" width="' . $ups[2]['w'] . '" height="' . $ups[2]['h'] . '" loading="lazy"'), 'uploaded photo sizes come from the uploads index');
    ok(substr_count($home, 'loading="lazy"') >= 3 && !preg_match('/class="alb-slide"[^>]*>(?:(?!<\/div>).)*loading="eager"/s', $home), 'every slide image is lazy');
    ok(str_contains($home, 'function album()') && str_contains($home, '.album .alb-track{') && str_contains($home, '<style id="extra-css">'), 'album script and css are emitted');
    ok(!preg_match('/setInterval|autoplay/i', (string) substr($home, (int) strpos($home, 'function album()'), 2000)), 'the album never moves on its own');
    ok(hero_compare($home) === [], 'T3 with an album');
    $refs = uploads_referenced();
    ok(isset($refs[$ups[0]['src']], $refs[$ups[2]['src']]) && !isset($refs[$ups[1]['src']]), 'uploads_referenced counts live album photos only');
    unlink(PUBLIC_DIR . '/' . $ups[2]['src']);
    uploads_sync_public();
    ok(is_file(PUBLIC_DIR . '/' . $ups[2]['src']), 'a live album photo is restored by uploads_sync_public');

    // one uploaded photo: the plain figure again, with the new picture
    $d = content_get('site', 'draft');
    $one = ['fields' => $d['fields'], 'faq' => $d['faq'], 'type_facts' => $d['type_facts'], 'album' => [['src' => $ups[1]['src'], 'caption' => '只留一张', 'alt' => '跑道']]];
    $s1 = content_save_draft('site', $one, (int) $d['rev']);
    content_publish('site', (int) $s1['rev']);
    $home = pub('index.html');
    ok(str_contains($home, "<figure class=\"about-fig\">\n      <div class=\"about-art\">\n        <img src=\"" . $ups[1]['src'] . '" width="1200" height="800" loading="lazy" decoding="async" alt="跑道">') && str_contains($home, '<figcaption>只留一张</figcaption>') && !str_contains($home, 'data-album') && !str_contains($home, 'function album()'), 'one uploaded photo renders exactly like the old figure');

    // validation
    $base = site_normalize($one);
    $bad = function (array $album) use ($base) { $b = $base; $b['album'] = $album; return site_validate($b); };
    ok(isset($bad([])['album']), 'an empty album is refused (keep at least one photo)');
    ok(isset($bad(array_fill(0, ALBUM_MAX + 1, ['src' => ALBUM_SEED_SRC, 'caption' => 'x', 'alt' => '']))['album']), 'more than ' . ALBUM_MAX . ' photos refused');
    ok(isset($bad([['src' => '../app/lib/bootstrap.php', 'caption' => 'x', 'alt' => '']])['album.0.src']), 'a path outside uploads refused');
    ok(isset($bad([['src' => 'uploads/2026/10/0123456789abcdef.webp', 'caption' => 'x', 'alt' => '']])['album.0.src']), 'an upload that is not in the index refused');
    ok(isset($bad([['src' => ALBUM_SEED_SRC, 'caption' => '', 'alt' => '']])['album.0.caption']), 'caption is required');
    ok(isset($bad([['src' => ALBUM_SEED_SRC, 'caption' => str_repeat('云', ALBUM_CAPTION_MAX + 1), 'alt' => '']])['album.0.caption']), 'caption over ' . ALBUM_CAPTION_MAX . ' refused');
    ok($bad([['src' => ALBUM_SEED_SRC, 'caption' => 'x', 'alt' => '']]) === [], 'empty alt is allowed');
    require_once APP_DIR . '/lib/admin.php';   // declarations only
    ok(admin_error_label('site', 'album.1.caption') === '第 2 张照片的下方小字', 'error label names the photo');
    try {
        image_accept_upload(['error' => UPLOAD_ERR_NO_FILE], 'album');
        ok(false, 'album is an accepted upload purpose');
    } catch (ValidationError $e) {
        ok(!str_contains(json_encode($e->fields, JSON_UNESCAPED_UNICODE), '用途'), 'album is an accepted upload purpose (only the missing file is reported)');
    }
}

function group_subscribers(): void
{
    app_init('cli');
    ok(email_normalize('  Reader@Example.COM ') === 'reader@example.com', 'email trimmed and lowercased');
    foreach (['a@b', 'not an email', 'a@b.c d', 'x@localhost', '<a@b.com>', str_repeat('a', 250) . '@b.com', 'a@b.com,c@d.com'] as $bad) {
        ok(email_normalize($bad) === null, 'rejects ' . mb_substr($bad, 0, 20));
    }
    ok(sub_add('reader@example.com', '我同意') === 'new', 'new address stored');
    ok(sub_add('READER@example.com', '我同意') === 'exists', 'same address again keeps one record');
    ok(count(sub_all_emails()) === 1, 'one record');
    sub_add('=cmd@example.com', '=HYPERLINK("x")');
    sub_add('b@example.org', '同意');
    $csv = sub_csv();
    ok(str_starts_with($csv, "\u{FEFF}\"邮箱\",\"订阅时间\",\"同意的内容\"\r\n"), 'CSV starts with BOM and quoted header');
    ok(str_contains($csv, "\"'=cmd@example.com\"") && str_contains($csv, "\"'=HYPERLINK(\"\"x\"\")\""), 'formula cells prefixed and quotes doubled');
    ok(substr_count($csv, "\r\n") === 4, 'CRLF line ends');
    $l = sub_list('EXAMPLE.COM');
    ok($l['total'] === 2, 'search is case-insensitive substring');
    ok(sub_delete(['b@example.org']) === 1 && count(sub_all_emails()) === 2, 'delete removes one');
    $items = [];
    for ($i = 0; $i < SUB_CAP; $i++) {
        $items[] = ['email' => "u$i@example.com", 'created_at' => now_iso(), 'consent' => '', 'source' => 'home'];
    }
    json_write_atomic(data_path('subscribers.json'), ['rev' => 1, 'items' => $items]);
    ok(throws(fn() => sub_add('one-more@example.com', ''), SubscribersFull::class), 'cap of 20 000 enforced');
}

// ---------- HTTP (php -S with the dev router) ----------

function http(string $method, string $path, array $opt = []): array
{
    $headers = $opt['headers'] ?? [];
    $content = $opt['body'] ?? null;
    if (isset($opt['form'])) {
        $content = http_build_query($opt['form']);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    if (isset($opt['json'])) {
        $content = json_encode($opt['json']);
        $headers[] = 'Content-Type: application/json';
    }
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $content ?? '', 'ignore_errors' => true, 'timeout' => 15, 'follow_location' => 0]]);
    $body = @file_get_contents('http://127.0.0.1:' . $GLOBALS['PORT'] . $path, false, $ctx);
    $status = 0;
    $hdrs = [];
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) {
            $status = (int) $m[1];
        } elseif (str_contains($h, ':')) {
            [$k, $v] = explode(':', $h, 2);
            $hdrs[strtolower(trim($k))] = trim($v);
        }
    }
    return ['status' => $status, 'body' => (string) $body, 'json' => json_decode((string) $body, true), 'headers' => $hdrs];
}

function usage_today(): array
{
    return json_read(data_path('usage', local_day() . '.json'), []);
}

function clear_rl(): void
{
    exec('rm -rf ' . escapeshellarg(DATA_DIR . '/ratelimit') . '/*');
}

function group_http(): void
{
    app_init('cli');
    publish_site('初始内容');
    $port = 20000 + random_int(0, 9999);
    $GLOBALS['PORT'] = $port;
    $env = ['BLOG_PUBLIC_DIR' => PUBLIC_DIR, 'BLOG_DATA_DIR' => DATA_DIR, 'BLOG_CHAT_FAKE' => '1', 'PATH' => getenv('PATH')];
    $srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', PUBLIC_DIR, APP_DIR . '/dev/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(100000);
    }
    try {
        http_tests();
    } finally {
        proc_terminate($srv);
        proc_close($srv);
    }
}

function http_tests(): void
{
    $form = fn(array $f) => ['form' => $f + ['consent' => '1', 't' => '5000', 'website' => '']];
    // router mirrors the .htaccess denies
    foreach (['/app/lib/bootstrap.php', '/app/seed/baseline-index.html', '/app/templates/home.tpl.html', '/api/composer.json', '/api/vendor/autoload.php', '/uploads/x.php', '/.htaccess', '/admin/x.php', '/api/x.php', '/assets/x.json'] as $u) {
        $r = http('GET', $u);
        ok($r['status'] === 403, "T8 $u is 403", (string) $r['status']);
    }
    $r = http('GET', '/');
    ok($r['status'] === 200 && str_contains($r['body'], 'id="hero-video"'), 'home page served');
    $r = http('GET', '/posts/');
    ok($r['status'] === 200 && str_contains($r['headers']['content-security-policy'] ?? '', "script-src 'none'"), 'posts/ served with strict CSP');

    // subscribe
    $r = http('GET', '/api/subscribe.php');
    ok($r['status'] === 405, 'subscribe GET rejected (405)', (string) $r['status']);
    clear_rl();
    $r = http('POST', '/api/subscribe.php', $form(['email' => 'Pilot@Example.com']));
    ok($r['status'] === 200 && ($r['json']['ok'] ?? false) === true, 'valid email accepted', $r['body']);
    $r = http('POST', '/api/subscribe.php', $form(['email' => 'pilot@example.com']));
    ok($r['status'] === 200 && ($r['json']['ok'] ?? false) === true, 'duplicate gets the same answer');
    $subs = json_read(data_path('subscribers.json'))['items'];
    ok(count($subs) === 1 && $subs[0]['email'] === 'pilot@example.com', 'stored once (dedup)');
    ok($subs[0]['consent'] === '我同意本站保存这个邮箱，只用来给我发飞行日志。' && !isset($subs[0]['ip']), 'consent text recorded, no IP');
    $r = http('POST', '/api/subscribe.php', $form(['email' => 'not-an-email']));
    ok($r['status'] === 400 && ($r['json']['error'] ?? '') === 'invalid_email' && ($r['json']['message'] ?? '') === '邮箱格式不对，请再检查一遍。', 'invalid email rejected (400)', $r['body']);
    clear_rl();
    $r = http('POST', '/api/subscribe.php', ['form' => ['email' => 'c@example.com', 't' => '5000']]);
    ok($r['status'] === 400 && ($r['json']['error'] ?? '') === 'consent_required', 'missing consent rejected (400)');
    $r = http('POST', '/api/subscribe.php', $form(['email' => 'bot@example.com', 'website' => 'http://spam']));
    ok($r['status'] === 200 && ($r['json']['ok'] ?? false) === true, 'honeypot answered like success');
    $r = http('POST', '/api/subscribe.php', $form(['email' => 'fast@example.com', 't' => '300']));
    ok($r['status'] === 200, 'too-fast submit answered like success');
    clear_rl();
    $r = http('POST', '/api/subscribe.php', ['form' => ['email' => 'nojs@example.com', 'consent' => '1']]);
    ok($r['status'] === 200, 'submit without t answered like success');
    $emails = array_column(json_read(data_path('subscribers.json'))['items'], 'email');
    ok($emails === ['pilot@example.com'], 'honeypot, too-fast and no-t submissions not stored', implode(',', $emails));
    $r = http('POST', '/api/subscribe.php', $form(['email' => 'x@example.com']) + ['headers' => ['Origin: https://evil.example']]);
    ok($r['status'] === 403 && ($r['json']['error'] ?? '') === 'forbidden', 'cross-site Origin rejected (403)');
    $r = http('POST', '/api/subscribe.php', $form(['email' => 'same-origin@example.com']) + ['headers' => ['Origin: http://127.0.0.1:' . $GLOBALS['PORT']]]);
    ok($r['status'] === 200, 'same-origin Origin accepted', $r['body']);
    clear_rl();
    $codes = [];
    for ($i = 0; $i < 6; $i++) {
        $codes[] = http('POST', '/api/subscribe.php', $form(['email' => "rl$i@example.com"]))['status'];
    }
    ok($codes === [200, 200, 200, 200, 200, 429], 'rate limit: 5 per hour per IP, then 429', implode(',', $codes));
    $r = http('POST', '/api/subscribe.php', $form(['email' => 'rl9@example.com']));
    ok(($r['json']['message'] ?? '') === '提交得有点频繁，过一会儿再试。', 'rate limit message');
    clear_rl();
    $s = settings_get();
    settings_save(['subscribe' => ['enabled' => false]], $s['rev']);
    $r = http('POST', '/api/subscribe.php', $form(['email' => 'closed@example.com']));
    ok($r['status'] === 503 && ($r['json']['error'] ?? '') === 'closed', 'closed subscribe answers 503 closed');
    $s = settings_get();
    settings_save(['subscribe' => ['enabled' => true]], $s['rev']);
    $u = usage_today()['subscribe'] ?? [];
    ok(($u['ok'] ?? 0) >= 7 && ($u['invalid'] ?? 0) === 2 && ($u['bot_dropped'] ?? 0) === 3 && ($u['rate_limited'] ?? 0) === 2 && ($u['closed'] ?? 0) === 1, 'subscribe usage counted', json_encode($u));

    // chat
    $msg = ['json' => ['messages' => [['role' => 'user', 'content' => '决断高度是什么？']]]];
    $r = http('GET', '/api/chat.php');
    ok($r['status'] === 405, 'chat GET rejected (405)');
    $r = http('POST', '/api/chat.php', $msg);
    ok($r['status'] === 503 && ($r['json']['error'] ?? '') === 'not_configured' && str_contains($r['json']['message'] ?? '', '接入中'), 'chat off and no key: 503 not_configured', $r['body']);
    $s = settings_get();
    settings_save(['chat' => ['enabled' => true]], $s['rev']);
    $r = http('POST', '/api/chat.php', $msg);
    ok($r['status'] === 503 && ($r['json']['error'] ?? '') === 'not_configured' && ($r['json']['message'] ?? '') === '助手还在接入中，这个问题暂时没有回答。接通以后，在这里直接问就行。', 'acceptance 4: chat on but no key still returns the not_configured 503', $r['body']);
    $u = usage_today()['chat'] ?? [];
    ok(($u['requests'] ?? 0) === 2 && ($u['errors']['disabled'] ?? 0) === 1 && ($u['errors']['not_configured'] ?? 0) === 1, 'chat usage logged (requests and error codes)', json_encode($u));
    $key = 'sk-ant-test' . str_repeat('q', 30) . 'WXYZ';
    chat_key_set($key);
    $r = http('POST', '/api/chat.php', $msg);
    ok($r['status'] === 200 && ($r['json']['reply'] ?? '') === '（测试回答）', 'with a key (fake upstream) chat answers', $r['body']);
    $r = http('POST', '/api/chat.php', ['json' => ['messages' => []]]);
    ok($r['status'] === 400 && ($r['json']['error'] ?? '') === 'bad_request', 'empty conversation rejected (400)');
    $u = usage_today()['chat'] ?? [];
    ok(($u['answered'] ?? 0) === 1 && ($u['tokens_in'] ?? 0) === 120 && ($u['tokens_out'] ?? 0) === 40 && ($u['errors']['bad_request'] ?? 0) === 1, 'answered and tokens logged', json_encode($u));
    $raw = (string) file_get_contents(data_path('usage', local_day() . '.json'));
    ok(!str_contains($raw, '决断高度') && !str_contains($raw, $key), 'usage file holds no message text and no key');
    $s = settings_get();
    settings_save(['chat' => ['ip_limit' => 2]], $s['rev']);
    clear_rl();
    $codes = [];
    for ($i = 0; $i < 3; $i++) {
        $codes[] = http('POST', '/api/chat.php', $msg)['status'];
    }
    ok($codes === [200, 200, 429], 'chat per-visitor limit from settings', implode(',', $codes));
    $s = settings_get();
    settings_save(['chat' => ['ip_limit' => 20, 'daily_limit' => 0]], $s['rev']);
    clear_rl();
    $r = http('POST', '/api/chat.php', $msg);
    ok($r['status'] === 429 && ($r['json']['error'] ?? '') === 'daily_limit', 'chat daily limit from settings');
    $s = settings_get();
    settings_save(['chat' => ['enabled' => false]], $s['rev']);
    $r = http('POST', '/api/chat.php', $msg);
    ok($r['status'] === 503 && ($r['json']['message'] ?? '') === '站长暂时关掉了助手，这个问题暂时没有回答。', 'chat switched off with a key: 503 with the off message');
    $all = '';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(DATA_DIR, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile() && $f->getFilename() !== 'secrets.json') {
            $all .= file_get_contents($f->getPathname());
        }
    }
    ok(!str_contains($all, $key) && !str_contains($all, '决断高度是什么'), 'no data file except secrets.json holds the key or a question');
}

// ---------- visitor statistics (api/hit.php, app/lib/stats.php, admin 访问统计) ----------

const UA_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const UA_WECHAT = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 MicroMessenger/8.0.50(0x1800322d) NetType/WIFI Language/zh_CN';
const UA_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

function stats_today_file(): array
{
    return stats_day(local_day());
}

function stats_files_text(): string
{
    $all = '';
    foreach (glob(DATA_DIR . '/stats/*') ?: [] as $f) {
        $all .= (string) file_get_contents($f);
    }
    foreach (glob(DATA_DIR . '/ratelimit/*/*') ?: [] as $f) {
        $all .= basename($f) . (string) file_get_contents($f);
    }
    return $all;
}

function hit(string $q, array $opt = []): array
{
    $ua = $opt['ua'] ?? UA_IPHONE;
    $headers = $opt['headers'] ?? [];
    if ($ua !== '') {
        $headers[] = 'User-Agent: ' . $ua;
    }
    return http($opt['method'] ?? 'GET', '/api/hit.php?' . $q, ['headers' => $headers]);
}

function group_stats(): void
{
    app_init('cli');
    // what counts
    foreach (['' => '', 'curl/8.5.0' => 'curl', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' => 'Googlebot',
        'Mozilla/5.0 (compatible; Baiduspider/2.0; +http://www.baidu.com/search/spider.html)' => 'Baiduspider',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/128.0.0.0 Safari/537.36' => 'HeadlessChrome',
        'python-requests/2.31' => 'python', 'facebookexternalhit/1.1' => 'link preview'] as $ua => $name) {
        ok(stats_is_bot($ua), "bot skipped: $name" . ($ua === '' ? '(missing user agent)' : ''));
    }
    foreach (['iPhone Safari' => UA_IPHONE, 'WeChat' => UA_WECHAT, 'Mac Chrome' => UA_MAC] as $name => $ua) {
        ok(!stats_is_bot($ua), "real browser counted: $name");
    }
    ok(stats_device(UA_IPHONE) === 'phone' && stats_device(UA_MAC) === 'desktop', 'device split by user agent');
    ok(stats_ref_host('www.Google.com', 'hxpilot.com') === 'google.com', 'referrer host lowercased, www. dropped');
    ok(stats_ref_host('https://evil.example/path?q=secret') === null, 'a full URL is refused as a referrer (hostname only)');
    ok(stats_ref_host('www.hxpilot.com', 'hxpilot.com') === null && stats_ref_host('hxpilot.com', 'hxpilot.com:443') === null, 'own site is not a referrer');
    ok(stats_ref_host('203.0.113.9') === null && stats_ref_host('a b.com') === null && stats_ref_host(str_repeat('a.', 140) . 'com') === null, 'IPs and junk hosts refused');
    ok(stats_ref_label('mp.weixin.qq.com') === '微信公众号' && stats_ref_label('m.baidu.com') === '百度' && stats_ref_label('google.com.hk') === '谷歌' && stats_ref_label('cn.bing.com') === '必应' && stats_ref_label('example.org') === null, 'friendly names for common referrers');
    ok(stats_path('/') === '/' && stats_path('/index.html') === '/' && stats_path('/posts/') === '/posts/', 'home and archive paths');
    ok(stats_path('/posts/nope.html') === null && stats_path('/../etc/passwd') === null && stats_path('/admin/') === null, 'unknown or foreign paths refused');

    // counting and unique dedup (library)
    $ip = '203.0.113.77';
    stats_record('/', 'baidu.com', $ip, UA_IPHONE);
    stats_record('/', null, $ip, UA_IPHONE);
    stats_record('/posts/', null, $ip, UA_IPHONE);
    $d = stats_today_file();
    ok($d['views'] === 3 && $d['visitors'] === 1, 'same visitor three times: 3 views, 1 visitor', json_encode($d));
    stats_record('/', null, $ip, UA_MAC);
    stats_record('/', null, '2001:db8:1:2::5/64', UA_WECHAT);
    $d = stats_today_file();
    ok($d['visitors'] === 3 && $d['devices'] === ['phone' => 2, 'desktop' => 1] && $d['wechat'] === 1, 'other browser or network is another visitor; devices and WeChat counted per visitor', json_encode($d));
    ok($d['paths'] === ['/' => 4, '/posts/' => 1] && $d['refs'] === ['baidu.com' => 1], 'per-page and referrer counts');
    for ($i = 0; $i < STATS_REF_KEYS + 5; $i++) {
        stats_record('/', "site$i.example", '198.51.100.' . ($i % 250), UA_MAC);
    }
    $d = stats_today_file();
    ok(count($d['refs']) === STATS_REF_KEYS + 1 && ($d['refs']['*'] ?? 0) === 6, 'distinct referrer hosts capped, the rest under 其他', (string) count($d['refs']));
    $raw = stats_files_text();
    ok(!str_contains($raw, $ip) && !str_contains($raw, '198.51.100.') && !str_contains($raw, '2001:db8') && !str_contains($raw, 'iPhone') && !str_contains($raw, 'Mozilla'), 'no raw IP or user agent stored');
    ok((fileperms(DATA_DIR . '/stats/' . local_day() . '.json') & 0777) === 0600, 'day file is private (0600)');
    // a new day: new salt, yesterday's hash list gone, files older than 400 days pruned
    $saltBefore = json_read(DATA_DIR . '/stats/salt.json')['salt'];
    json_write_atomic(DATA_DIR . '/stats/2020-01-01.json', stats_empty('2020-01-01'));
    $recent = local_day(time() - 300 * 86400);
    json_write_atomic(DATA_DIR . '/stats/' . $recent . '.json', stats_empty($recent));
    $tomorrow = time() + 86400;
    stats_record('/', null, $ip, UA_IPHONE, $tomorrow);
    $salt = json_read(DATA_DIR . '/stats/salt.json');
    ok($salt['day'] === local_day($tomorrow) && $salt['salt'] !== $saltBefore, 'a new day gets a new salt; the old one is discarded');
    ok(!is_file(DATA_DIR . '/stats/seen-' . local_day() . '.txt') && is_file(DATA_DIR . '/stats/seen-' . local_day($tomorrow) . '.txt'), "the previous day's visitor hashes are deleted");
    ok(!is_file(DATA_DIR . '/stats/2020-01-01.json') && is_file(DATA_DIR . '/stats/' . $recent . '.json'), 'days older than 400 days pruned, 300 days kept');
    ok(stats_day(local_day($tomorrow))['visitors'] === 1, 'the same person counts again on a new day');
    $top = stats_top([stats_day(local_day()), stats_day(local_day($tomorrow))], 'paths', 1);
    ok(array_keys($top) === ['/', '*'] && $top['/'] === 109 + 1, 'top pages summed over days', json_encode($top));
    exec('rm -rf ' . escapeshellarg(DATA_DIR . '/stats'));

    // templates: beacon on home, pixel on post and archive, nothing when off, nothing in previews
    $p = make_post('统计像素测试', "正文。", '2026-10-02');
    post_publish($p['id'], $p['rev']);
    $slug = $p['slug'];
    publish_site('x');
    $home = pub('index.html');
    $post = pub('posts/' . $slug . '.html');
    ok(str_contains($home, "navigator.sendBeacon") && str_contains($home, "api/hit.php?p=%2F") && str_contains($home, 'document.referrer') && str_contains($home, 'u.hostname'), 'home page has the beacon (hostname only)');
    ok(str_contains($post, '<img class="hit-px" src="../api/hit.php?p=%2Fposts%2F' . $slug . '.html" alt="" width="1" height="1" decoding="async">'), 'post page has the pixel');
    ok(str_contains(pub('posts/index.html'), '<img class="hit-px" src="../api/hit.php?p=%2Fposts%2F"'), 'archive has the pixel');
    ok(!preg_match('/<script|\sstyle=/i', $post . pub('posts/index.html')), 'post and archive still have no script and no inline style (CSP)');
    ok(str_contains((string) file_get_contents(PUBLIC_DIR . '/assets/post.css'), '.hit-px{display:none'), 'pixel hidden by a class in post.css');
    $prev = preview_post(post_get($p['id']));
    $prevHome = preview_home(content_get('site', 'draft'), content_get('shelf', 'draft'));
    ok(!str_contains($prev, 'hit.php') && !str_contains($prevHome, 'hit.php'), 'previews never count');

    // HTTP
    $port = 20000 + random_int(0, 9999);
    $GLOBALS['PORT'] = $port;
    $env = ['BLOG_PUBLIC_DIR' => PUBLIC_DIR, 'BLOG_DATA_DIR' => DATA_DIR, 'PATH' => getenv('PATH')];
    $srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', PUBLIC_DIR, APP_DIR . '/dev/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(100000);
    }
    try {
        stats_http_tests($slug);
    } finally {
        proc_terminate($srv);
        proc_close($srv);
    }

    // switch off: no beacon, no pixel, nothing counted
    $s = settings_get();
    $res = settings_save(['stats' => ['enabled' => false]], $s['rev']);
    ok(($res['publish']['ok'] ?? false) === true, 'switching stats off republishes the site');
    ok(!str_contains(pub('index.html'), 'hit.php') && !str_contains(pub('posts/' . $slug . '.html'), 'hit.php') && !str_contains(pub('posts/index.html'), 'hit.php'), 'off: no beacon or pixel on any page');
}

function stats_http_tests(string $slug): void
{
    clear_rl();
    $r = hit('p=%2Fposts%2F' . $slug . '.html');
    ok($r['status'] === 200 && ($r['headers']['content-type'] ?? '') === 'image/gif' && str_contains($r['headers']['cache-control'] ?? '', 'no-store'), 'pixel answers a no-store gif', json_encode($r['headers']));
    $img = @getimagesizefromstring($r['body']);
    ok(is_array($img) && $img[0] === 1 && $img[1] === 1, 'the gif is 1x1');
    $r = hit('p=%2F&r=www.baidu.com', ['method' => 'POST']);
    ok($r['status'] === 204 && $r['body'] === '' && str_contains($r['headers']['cache-control'] ?? '', 'no-store'), 'beacon answers 204 no-store', (string) $r['status']);
    hit('p=%2F&r=' . rawurlencode('https://evil.example/path?secret=1'), ['method' => 'POST']);
    hit('p=%2Fposts%2F&r=google.com');
    $d = stats_today_file();
    ok($d['views'] === 4 && $d['visitors'] === 1, 'HTTP: 4 views from one visitor = 1 visitor', json_encode($d));
    ok($d['refs'] === ['baidu.com' => 1], 'only the hostname is kept; a full URL and a referrer on a non-home page are dropped', json_encode($d['refs']));
    ok(($d['paths']['/posts/' . $slug . '.html'] ?? 0) === 1 && ($d['paths']['/posts/'] ?? 0) === 1 && ($d['paths']['/'] ?? 0) === 2, 'per-path counts over HTTP', json_encode($d['paths']));
    // not counted, same answer
    $before = stats_today_file()['views'];
    $codes = [];
    foreach ([
        'missing user agent' => ['p=%2F', ['ua' => '']],
        'bot' => ['p=%2F', ['ua' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)']],
        'owner cookie' => ['p=%2F', ['headers' => ['Cookie: pb_nostat=1']]],
        'cross-site pixel' => ['p=%2F', ['headers' => ['Sec-Fetch-Site: cross-site']]],
        'other Origin' => ['p=%2F', ['method' => 'POST', 'headers' => ['Origin: https://evil.example']]],
        'prefetch' => ['p=%2F', ['headers' => ['Sec-Purpose: prefetch']]],
        'unknown path' => ['p=%2Fetc%2Fpasswd', []],
        'missing post' => ['p=%2Fposts%2Fno-such-post.html', []],
        'array param' => ['p[]=x', []],
    ] as $name => [$q, $opt]) {
        $r = hit($q, $opt);
        $codes[$name] = $r['status'] . (($opt['method'] ?? 'GET') === 'POST' ? '' : ' ' . ($r['headers']['content-type'] ?? ''));
    }
    ok(stats_today_file()['views'] === $before, 'not counted: missing UA, bots, the owner, cross-site, other Origin, prefetch, bad paths', json_encode(stats_today_file()));
    $other = $codes['other Origin'];
    unset($codes['other Origin']);
    ok(array_values(array_unique($codes)) === ['200 image/gif'] && $other === '204', 'every refused request gets the same quiet answer (gif for GET, 204 for POST)', json_encode($codes) . $other);
    $r = hit('p=%2F', ['method' => 'PUT']);
    ok($r['status'] === 204, 'other methods: quiet 204, nothing counted', (string) $r['status']);
    // rate limit per visitor
    clear_rl();
    exec('rm -rf ' . escapeshellarg(DATA_DIR . '/stats'));
    for ($i = 0; $i < STATS_IP_HOUR + 5; $i++) {
        hit('p=%2F', ['method' => 'POST', 'ua' => UA_MAC . " r$i"]);
    }
    $d = stats_today_file();
    ok($d['views'] === STATS_IP_HOUR, 'rate limit: at most ' . STATS_IP_HOUR . ' views per visitor per hour', (string) $d['views']);
    // admin 访问统计 page (logged in through the real login)
    stats_record('/posts/' . $slug . '.html', null, '192.0.2.1', UA_WECHAT);
    stats_record('/', 'mp.weixin.qq.com', '192.0.2.2', UA_IPHONE);
    $pw = '云底下的跑道灯2026春';
    json_write_atomic(data_path('admin.json'), ['version' => 1, 'password_hash' => pw_hash($pw), 'created_at' => now_iso(), 'password_changed_at' => now_iso(), 'session_epoch' => 1]);
    @unlink(DATA_DIR . '/setup-code.txt');
    $r = http('GET', '/admin/?r=login', ['headers' => ['Accept: application/json']]);
    $csrf = (string) ($r['json']['csrf'] ?? '');
    $r = http('POST', '/admin/?r=login', ['form' => ['_csrf' => $csrf, 'password' => $pw]]);
    preg_match('/pb_admin=([^;]+)/', $r['headers']['set-cookie'] ?? '', $m);
    $cookie = 'Cookie: pb_admin=' . ($m[1] ?? '');
    $r = http('GET', '/admin/?r=stats', ['headers' => [$cookie]]);
    $b = $r['body'];
    ok($r['status'] === 200 && str_contains($b, '<h1 class="page-h">访问统计</h1>'), 'admin 访问统计 page renders', (string) $r['status']);
    ok(str_contains($b, '《统计像素测试》') && str_contains($b, '微信公众号') && str_contains($b, '首页') && str_contains($b, '<svg viewBox="0 0 600 150"'), 'page shows post titles, friendly referrer names and the 30-day chart');
    ok(str_contains($b, '不保存访客的 IP 地址') && str_contains($b, '大概'), 'page says the numbers are approximate and nothing personal is kept');
    ok(str_contains($r['headers']['content-security-policy'] ?? '', "script-src 'self'") && !preg_match('/\sstyle=|<script(?![^>]*\bsrc=)/', $b), 'admin CSP kept: no inline style or script');
    ok(str_contains($b, '>访问统计</a>'), '访问统计 is in the menu');
    $r = http('GET', '/admin/?r=dashboard', ['headers' => [$cookie]]);
    ok(str_contains($r['body'], '今天访客') && str_contains($r['body'], '今天浏览'), '概览 has the visitor card');
    $r = http('GET', '/admin/?r=stats', ['headers' => [$cookie]]);
    ok(str_contains($r['headers']['set-cookie'] ?? '', 'pb_nostat=1') && str_contains(strtolower($r['headers']['set-cookie'] ?? ''), 'path=/'), 'admin pages mark the owner device (pb_nostat cookie for the whole site)', $r['headers']['set-cookie'] ?? '');
    // switch from the admin page
    $csrfPage = preg_match('/<meta name="csrf" content="([0-9a-f]+)"/', $r['body'], $mm) ? $mm[1] : '';
    $rev = (string) settings_get()['rev'];
    $r = http('POST', '/admin/?r=stats.toggle', ['form' => ['_csrf' => $csrfPage, 'rev' => $rev, 'enabled' => '0'], 'headers' => [$cookie]]);
    ok(in_array($r['status'], [302, 303], true) && !stats_enabled() && !str_contains(pub('index.html'), 'hit.php'), 'admin switch turns stats off and republishes', (string) $r['status']);
    clear_rl();
    $before = stats_today_file()['views'];
    hit('p=%2F', ['method' => 'POST']);
    ok(stats_today_file()['views'] === $before, 'off: hit.php counts nothing');
    $rev = (string) settings_get()['rev'];
    http('POST', '/admin/?r=stats.toggle', ['form' => ['_csrf' => $csrfPage, 'rev' => $rev, 'enabled' => '1'], 'headers' => [$cookie]]);
    ok(stats_enabled() && str_contains(pub('index.html'), 'hit.php'), 'admin switch turns stats back on');
}

// ---------- 商城 (SPEC §20) ----------

function shop_png(int $w = 600, int $h = 600): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 30, 60, 120));
    $f = sys_get_temp_dir() . '/shopt-' . bin2hex(random_bytes(4)) . '.png';
    imagepng($im, $f);
    return $f;
}

/** A product ready to sell: photo, price, stock. */
function shop_make(string $slug, string $type, string $price, ?int $stock, array $extra = []): array
{
    $p = shop_product_new();
    $tmp = shop_png();
    shop_product_photo($p['id'], 'add', 0, image_store_file($tmp));
    unlink($tmp);
    $in = ['name' => '测试 ' . $slug, 'slug' => $slug, 'type' => $type, 'price' => $price, 'stock' => $stock === null ? '' : (string) $stock,
        'unlimited' => $stock === null ? '1' : '', 'stock_seen' => '0', 'on_sale' => '1', 'summary' => '', 'body' => '',
        'dl_text' => $type === 'digital' ? '提取码 1234' : '', 'dl_link' => ''] + $extra;
    return shop_product_save($p['id'], $in, shop_product_get($p['id'])['rev']);
}

function shop_buyer(string $type, string $phone = '13800138000', string $email = 'buyer@example.com', int $qty = 1): array
{
    return ['qty' => $qty, 'name' => '王五', 'phone' => $type === 'physical' ? $phone : '', 'address' => $type === 'physical' ? '北京市朝阳区某路 1 号' : '', 'email' => $type === 'digital' ? $email : '', 'note' => ''];
}

function group_shop(): void
{
    app_init('cli');
    publish_site('初始内容');
    // money and input
    ok(shop_money(9903) === '99.03' && shop_money(5) === '0.05' && shop_money(100000) === '1000.00', 'money: fen shown as yuan with 2 decimals');
    ok(shop_parse_money('99') === 9900 && shop_parse_money('99.5') === 9950 && shop_parse_money('¥ 1,299.00') === 129900 && shop_parse_money('９９．０３') === 9903, 'money: typed prices parse (¥, commas, full-width)');
    ok(shop_parse_money('99.999') === null && shop_parse_money('-1') === null && shop_parse_money('1e3') === null && shop_parse_money('') === null, 'money: odd input refused');
    ok(shop_phone_normalize('+86 138-0013-8000') === '13800138000' && shop_phone_normalize('１３８００１３８０００') === '13800138000' && shop_phone_normalize('12345') === null && shop_phone_normalize('23456789') === '23456789', 'phone: mainland (with +86, dashes, full width) and HK; junk refused');
    ok(shop_verify_input(' Reader.Pilot@Example.com ') === 'reader.pilot' && shop_verify_input('１２３４') === '1234', 'lookup secret normalized (email part, full width digits)');
    $prodErr = shop_order_input(['type' => 'physical'], ['qty' => '0', 'name' => '', 'phone' => 'abc', 'address' => '北京', 'consent' => ''])[1];
    ok(isset($prodErr['qty'], $prodErr['name'], $prodErr['phone'], $prodErr['address'], $prodErr['consent']), 'order form: every bad field gets its own message');
    [$cl] = shop_order_input(['type' => 'physical'], ['qty' => '2', 'name' => "张\x00三\u{202E}", 'phone' => '13800138000', 'address' => "上海市\n浦东新区 100 号", 'note' => '<b>x</b>', 'consent' => '1']);
    ok($cl['name'] === '张 三' && !str_contains($cl['address'], "\n"), 'buyer text: control and bidi characters and line breaks removed');

    // form tokens
    $t = shop_form_token('order', time() - 10);
    ok(shop_form_token_check('order', $t) === 'ok' && shop_form_token_check('lookup', $t) === 'bad', 'form token works only for its own form');
    ok(shop_form_token_check('order', shop_form_token('order')) === 'fast' && shop_form_token_check('order', shop_form_token('order', time() - 7 * 3600)) === 'bad', 'form token: too fast and too old refused');
    ok(shop_form_token_check('order', substr($t, 0, -1) . (substr($t, -1) === 'a' ? 'b' : 'a')) === 'bad' && shop_form_token_check('order', 'x') === 'bad', 'tampered form token refused');

    // product validation
    $bad = shop_product_new();
    try {
        shop_product_save($bad['id'], ['name' => 'x', 'slug' => '../etc', 'type' => 'physical', 'price' => '0', 'stock' => '-1', 'on_sale' => '1', 'dl_link' => 'javascript:alert(1)'], $bad['rev']);
        ok(false, 'bad product refused');
    } catch (ShopError $e) {
        ok(isset($e->fields['slug'], $e->fields['price'], $e->fields['stock'], $e->fields['dl_link'], $e->fields['on_sale']), 'product: bad slug, price, stock, javascript: link and no photo all refused', json_encode($e->fields, JSON_UNESCAPED_UNICODE));
    }
    shop_product_delete($bad['id']);

    $ph = shop_make('poster', 'physical', '99', 3);
    $dg = shop_make('guide', 'digital', '29.9', null);
    ok(is_file(PUBLIC_DIR . '/shop/poster.html') && is_file(PUBLIC_DIR . '/shop/guide.html') && str_contains(pub('index.html'), 'href="shop/poster.html"'), 'products on sale get static pages and a home card');
    ok(is_file(PUBLIC_DIR . '/shop/.htaccess') && str_contains(pub('shop/.htaccess'), "script-src 'none'"), 'shop/.htaccess with the strict CSP is written');
    ok(isset(uploads_referenced()[$ph['photos'][0]['src']]), 'product photos count as referenced uploads (restored by self-heal)');
    ok(stats_path('/shop/poster.html') === '/shop/poster.html' && stats_path('/shop/nope.html') === null && stats_path('/shop/../index.html') === null, 'stats count existing product pages only');

    // XSS: product text in the pages
    $x = shop_make('xss', 'physical', '1', 1);
    $x = shop_product_save($x['id'], ['name' => '<img src=x onerror=alert(1)>', 'slug' => 'xss', 'type' => 'physical', 'price' => '1', 'stock' => '1', 'stock_seen' => '1', 'on_sale' => '1',
        'summary' => '"><script>alert(1)</script>', 'body' => "[x](javascript:alert(1))\n<script>alert(2)</script>", 'dl_text' => '', 'dl_link' => ''], $x['rev']);
    $pg = pub('shop/xss.html') . pub('index.html');
    ok(!str_contains($pg, '<img src=x') && !str_contains($pg, '<script>alert') && !str_contains($pg, 'href="javascript:'), 'product name, summary and body are escaped on the home grid and product page');

    // ordering, unique amounts, stock
    $o1 = shop_create_order('poster', shop_order_input($ph, ['qty' => '2', 'name' => '王五', 'phone' => '13800138000', 'address' => '北京市朝阳区某路 1 号', 'consent' => '1'])[0]);
    ok($o1['subtotal'] === 19800 && $o1['amount'] === 19800 + $o1['offset'] && $o1['offset'] >= 1 && $o1['offset'] <= 99, 'order: subtotal 2 × 99, payable = total + 1..99 fen');
    ok(preg_match(ORDER_ID_RE, $o1['id']) === 1 && str_starts_with($o1['id'], str_replace('-', '', local_day())), 'order number: Beijing date + 6 random letters');
    ok(shop_product_get($ph['id'])['stock'] === 1 && $o1['held'] === true, 'stock reserved on order (3 → 1)');
    ok(throws(fn() => shop_create_order('poster', shop_buyer('physical', '13800138000', '', 2)), ShopError::class), 'overselling refused (2 wanted, 1 left)');
    ok(throws(fn() => shop_create_order('nope', shop_buyer('physical')), ShopError::class), 'unknown product refused');
    // the amount never comes from the buyer
    $tampered = shop_buyer('physical') + ['price' => 1, 'amount' => 1, 'subtotal' => 1];
    $o2 = shop_create_order('poster', $tampered);
    ok($o2['price'] === 9900 && $o2['amount'] > 9900, 'client price/amount fields are ignored');
    shop_owner_action($o2['id'], 'cancel', ['reason' => '测试']);
    // a hundred unpaid orders with the same total: all amounts distinct
    $big = shop_make('pin', 'physical', '10', 400);
    $amounts = [];
    for ($i = 0; $i < 120; $i++) {
        $amounts[] = shop_create_order('pin', shop_buyer('physical'))['amount'];
    }
    ok(count(array_unique($amounts)) === 120 && max($amounts) <= 1000 + 199, '120 open orders at one price: every payable amount is different (overflow into ¥1.xx)');
    $mx = 0;
    foreach (shop_orders() as $o) {
        if ($o['product_id'] === $big['id'] && $o['status'] === 'pending') {
            shop_owner_action($o['id'], 'cancel', ['reason' => '测试']);
        }
    }
    ok(shop_product_get($big['id'])['stock'] === 400, 'cancelling releases every reserved unit');

    // parallel buyers against the last 5 units (separate processes, one flock)
    $race = shop_make('race', 'physical', '5', 5);
    $code = 'define("BLOG_APP",true);require ' . var_export(REPO . '/pilot-blog/app/lib/bootstrap.php', true) . ';app_init("cli");'
        . 'try{shop_create_order("race",["qty"=>1,"name"=>"并发","phone"=>"13900000000","address"=>"测试地址 1 号","email"=>"","note"=>""]);echo "OK";}catch(ShopError $e){echo "NO";}';
    $procs = [];
    for ($i = 0; $i < 12; $i++) {
        $procs[] = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp, null, ['BLOG_PUBLIC_DIR' => PUBLIC_DIR, 'BLOG_DATA_DIR' => DATA_DIR, 'PATH' => getenv('PATH')]);
        $pipes[] = $pp;
    }
    $res = [];
    foreach ($procs as $i => $pr) {
        $res[] = stream_get_contents($pipes[$i][1]);
        proc_close($pr);
    }
    $okN = count(array_filter($res, fn($r) => $r === 'OK'));
    ok($okN === 5 && shop_product_get($race['id'])['stock'] === 0, '12 buyers at once for 5 units: exactly 5 orders, stock 0 (no overselling)', implode(',', $res));
    shop_republish_if_changed('');   // what api/shop.php does after an order
    ok(str_contains(pub('index.html'), '<li class="good out"><a class="good-a" href="shop/race.html">') && str_contains(pub('shop/race.html'), '售罄') && !str_contains(pub('shop/race.html'), 'buy-btn'), 'a sold-out product shows 售罄 on the home grid and its page has no buy button');

    // expiry, restore, auto-complete, redaction
    $exp = shop_create_order('guide', shop_buyer('digital'), time() - 25 * 3600);
    $claimed = shop_create_order('guide', shop_buyer('digital'), time() - 30 * 3600);
    shop_claim($claimed['id'], '尾号 1234', time() - 29 * 3600);
    shop_maintain();
    ok(shop_order_get($exp['id'])['status'] === 'cancelled' && shop_order_get($exp['id'])['cancel_auto'] === true, 'an unpaid order expires after 24 h (lazy, on the next access)');
    ok(shop_order_get($claimed['id'])['status'] === 'claimed', 'an order the buyer marked 我已付款 never expires on its own');
    $e2 = shop_create_order('poster', shop_buyer('physical'), time() - 25 * 3600);   // stock 1 → 0 then released
    shop_maintain();
    ok(shop_product_get($ph['id'])['stock'] === 1 && shop_order_get($e2['id'])['status'] === 'cancelled', 'expiry releases the reserved stock');
    $back = shop_owner_action($e2['id'], 'confirm');
    ok($back['status'] === 'confirmed' && shop_product_get($ph['id'])['stock'] === 0, 'paid after the automatic cancel: 确认收款 restores the order and takes the stock again');
    ok(throws(fn() => shop_owner_action($o1['id'], 'ship', ['courier' => '顺丰', 'tracking' => 'SF1']), ShopError::class), 'ship before 确认收款 refused');
    shop_owner_action($o1['id'], 'confirm');
    ok(throws(fn() => shop_owner_action($o1['id'], 'ship', ['courier' => '顺丰', 'tracking' => '<script>']), ShopError::class), 'tracking number must be letters and digits');
    shop_owner_action($o1['id'], 'ship', ['courier' => '顺丰', 'tracking' => 'SF1234567890']);
    ok(throws(fn() => shop_owner_action($o1['id'], 'cancel', ['reason' => 'x']), ShopError::class), 'a shipped order cannot be cancelled (refund note instead)');
    shop_owner_action($o1['id'], 'refund', ['note' => '已退 ¥10 差价']);
    ok(count(shop_order_get($o1['id'])['refunds']) === 1, '记录退款 stores a note');
    $d1 = shop_owner_action($claimed['id'], 'confirm');
    ok($d1['status'] === 'completed' && $d1['closed_at'] !== null, 'digital: 确认收款 completes the order (content shows on lookup)');
    // jump 31 days: shipped → completed; 181 more days: redacted
    shop_maintain(time() + 31 * 86400);
    $c1 = shop_order_get($o1['id']);
    ok($c1['status'] === 'completed', 'shipped orders complete by themselves after 30 days');
    shop_maintain(time() + (31 + 181) * 86400);
    $c1 = shop_order_get($o1['id']);
    ok($c1['buyer'] === ['name' => '', 'phone' => '', 'address' => '', 'email' => ''] && $c1['tracking'] === '' && $c1['redacted_at'] !== null && $c1['amount'] === $o1['amount'] && $c1['name'] !== '', '180 days after closing: name, phone, address, email, tracking erased; amounts and items kept');
    ok(hash_equals($c1['verify'], shop_verify_hash($c1['id'], '8000')), 'lookup still works after redaction (only a keyed hash is kept)');
    ok(!str_contains((string) file_get_contents(shop_orders_path()), '北京市朝阳区某路 1 号"') || shop_order_get($o2['id'])['redacted_at'] !== null, 'redacted data is gone from orders.json');

    // CSV
    $csvOrder = ['id' => '20261017-ABCDEF', 'created_at' => now_iso(), 'status' => 'pending', 'name' => '=HYPERLINK("http://x")', 'type' => 'physical', 'price' => 100, 'qty' => 1, 'subtotal' => 100, 'shipping' => 0, 'offset' => 1, 'amount' => 101,
        'buyer' => ['name' => '+cmd|\'/C calc\'!A0', 'phone' => '13800138000', 'address' => "@SUM(1)", 'email' => '-1+1@x.com'], 'note' => "\t=1", 'payer' => ' =2', 'courier' => '', 'tracking' => '', 'cancel_reason' => '', 'refunds' => []];
    $csv = shop_csv([$csvOrder]);
    ok(str_starts_with($csv, "\u{FEFF}\"订单号\"") && str_contains($csv, "\r\n"), 'orders CSV: UTF-8 BOM, CRLF, quoted header');
    ok(str_contains($csv, '"\'=HYPERLINK(""http://x"")"') && str_contains($csv, '"\'+cmd') && str_contains($csv, '"\'@SUM(1)"') && str_contains($csv, '"\'-1+1@x.com"') && str_contains($csv, "\"'\t=1\"") && str_contains($csv, '"\' =2"'), 'orders CSV: formula-looking cells defused with a leading quote (also after spaces)');

    // closed shop
    settings_save(['shop' => ['enabled' => false]], settings_get()['rev']);
    ok(str_contains(pub('index.html'), '暂停营业') && !str_contains(pub('index.html'), 'class="goods"'), 'closed: the home section shows 暂停营业 instead of the grid');
    ok(throws(fn() => shop_create_order('guide', shop_buyer('digital')), ShopError::class), 'closed: no new orders');
    settings_save(['shop' => ['enabled' => true]], settings_get()['rev']);

    // digital file: stored privately, safe names only
    $tmp = tempnam(sys_get_temp_dir(), 'dl');
    file_put_contents($tmp, 'PDFDATA');
    ok(throws(fn() => shop_product_file_set($dg['id'], $tmp, 'evil.php', 7), ShopError::class) && throws(fn() => shop_product_file_set($dg['id'], $tmp, 'page.html', 7), ShopError::class), 'digital file: .php and .html refused');
    $dg = shop_product_file_set($dg['id'], $tmp, '../../教程 "v1".pdf', 7);
    @unlink($tmp);
    $f = $dg['delivery']['file'];
    ok($f['name'] === '教程 v1.pdf' && is_file(DATA_DIR . '/shop/files/' . $f['id'] . '.bin') && !glob(PUBLIC_DIR . '/**/*' . $f['id'] . '*'), 'digital file: name cleaned, stored only in blog-data');

    shop_http(fn() => shop_http_tests($ph, $dg));
}

function shop_http(callable $fn): void
{
    $port = 20000 + random_int(0, 9999);
    $GLOBALS['PORT'] = $port;
    $env = ['BLOG_PUBLIC_DIR' => PUBLIC_DIR, 'BLOG_DATA_DIR' => DATA_DIR, 'PATH' => getenv('PATH')];
    $srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', PUBLIC_DIR, APP_DIR . '/dev/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(100000);
    }
    try {
        $fn();
    } finally {
        proc_terminate($srv);
        proc_close($srv);
    }
}

function shop_post(string $a, array $form, array $headers = []): array
{
    return http('POST', '/api/shop.php?a=' . $a, ['form' => $form + ['tok' => shop_form_token($a === 'paid' ? 'paid' : $a, time() - 5), 'website' => ''], 'headers' => $headers]);
}

function shop_http_tests(array $ph, array $dg): void
{
    clear_rl();
    $r = http('GET', '/api/shop.php?a=order&p=guide');
    ok($r['status'] === 200 && str_contains($r['headers']['content-security-policy'] ?? '', "script-src 'none'") && ($r['headers']['x-frame-options'] ?? '') === 'DENY' && str_contains($r['headers']['cache-control'] ?? '', 'no-store'), 'order page: strict CSP, no framing, no caching');
    ok(!preg_match('/<script/i', $r['body']), 'buyer pages carry no script at all');
    ok(str_contains(http('GET', '/shop/guide.html')['headers']['content-security-policy'] ?? '', "script-src 'none'") && http('GET', '/shop/x.php')['status'] >= 400 && http('GET', '/shop/../app/lib/shop.php')['status'] >= 400, 'shop/ pages served with CSP; no PHP there');
    ok(http('GET', '/api/shop.php?a=order&p=../../etc')['status'] === 404, 'odd product slug: 404');
    // create through HTTP with tampered money fields
    $r = shop_post('order', ['p' => 'guide', 'qty' => '1', 'email' => 'Buyer@Example.com', 'consent' => '1', 'price' => '0.01', 'amount' => '0.01']);
    ok($r['status'] === 303 && preg_match('/a=pay&o=(\d{8}-[A-Z2-9]{6})&k=([0-9a-f]{32})/', $r['headers']['location'] ?? '', $m) === 1, 'order created over HTTP → 303 to the pay page', $r['status'] . ' ' . substr(strip_tags($r['body']), 0, 200));
    $id = $m[1] ?? '';
    $k = $m[2] ?? '';
    ok((shop_order_get($id)['amount'] ?? 0) > 2990, 'posted price/amount ignored: the server computed ¥29.90 + cents');
    $r = shop_post('order', ['p' => 'guide', 'qty' => '1', 'email' => 'x@example.com', 'consent' => '1'], ['Origin: https://evil.example']);
    ok($r['status'] === 403, 'cross-site order POST refused (403)');
    $r = http('POST', '/api/shop.php?a=order', ['form' => ['p' => 'guide', 'qty' => '1', 'email' => 'x@example.com', 'consent' => '1', 'website' => '']]);
    ok($r['status'] === 400, 'order without the form token refused');
    $r = shop_post('order', ['p' => 'guide', 'qty' => '1', 'email' => 'x@example.com', 'consent' => '1', 'website' => 'http://spam']);
    ok($r['status'] === 400 && count(shop_orders()) > 0, 'honeypot filled: refused, nothing stored');
    $r = shop_post('order', ['p' => 'poster', 'qty' => '1', 'name' => '<script>alert(1)</script>', 'phone' => '1380013', 'address' => '"><img src=x onerror=alert(1)>', 'consent' => '1']);
    ok($r['status'] === 400 && str_contains($r['body'], '手机号格式不对') && !str_contains($r['body'], '<script>alert') && !str_contains($r['body'], '<img src=x') && str_contains($r['body'], '&lt;script&gt;'), 'invalid order re-shows the form with the typed values escaped');
    // pay page
    ok(http('GET', "/api/shop.php?a=pay&o=$id&k=$k")['status'] === 200, 'pay page opens with its token');
    $r = http('GET', "/api/shop.php?a=pay&o=$id&k=" . str_repeat('0', 32));
    ok($r['status'] === 404 && !str_contains($r['body'], $id), 'pay page with a wrong token: 404, nothing about the order shown');
    $r = shop_post('paid', ['o' => $id, 'k' => str_repeat('a', 32), 'payer' => 'x']);
    ok($r['status'] === 404 && shop_order_get($id)['status'] === 'pending', '我已付款 needs the pay token');
    $r = shop_post('paid', ['o' => $id, 'k' => $k, 'payer' => '尾号 8888']);
    ok($r['status'] === 303 && shop_order_get($id)['status'] === 'claimed' && shop_order_get($id)['payer'] === '尾号 8888', '我已付款 marks the order (303 back to the pay page)');
    // lookup
    clear_rl();
    $r = shop_post('lookup', ['o' => $id, 'v' => 'buyer']);
    ok($r['status'] === 200 && str_contains($r['body'], '已付款，等站长确认') && !str_contains($r['body'], 'buyer@example.com'), 'lookup with the email part works; the email is not echoed');
    $r1 = shop_post('lookup', ['o' => $id, 'v' => 'nobody']);
    $r2 = shop_post('lookup', ['o' => '20261017-ZZZZZZ', 'v' => 'buyer']);
    ok($r1['status'] === 400 && $r2['status'] === 400 && str_contains($r1['body'], '订单号或验证信息不对') && str_contains($r2['body'], '订单号或验证信息不对'), 'wrong secret and unknown order number get the same answer (no enumeration)');
    clear_rl();
    for ($i = 0; $i < 9; $i++) {
        $last = shop_post('lookup', ['o' => $id, 'v' => sprintf('%04d', $i)]);
        if ($i % 4 === 3) {
            exec('rm -rf ' . escapeshellarg(DATA_DIR . '/ratelimit/shop-lookup') . ' ' . escapeshellarg(DATA_DIR . '/ratelimit/shop-lookup-day'));   // as if from new addresses
        }
    }
    $r = shop_post('lookup', ['o' => $id, 'v' => 'buyer']);
    ok($r['status'] === 429 && str_contains($r['body'], '一小时后再试'), 'guessing one order from many addresses: locked after 8 tries per hour, even with the right answer');
    clear_rl();
    for ($i = 0; $i < 10; $i++) {
        shop_post('lookup', ['o' => '20261017-AAAAA' . $i, 'v' => '1']);
    }
    ok(shop_post('lookup', ['o' => $id, 'v' => 'buyer'])['status'] === 429, 'per-visitor lookup limit: the 11th lookup in 10 minutes is refused');
    clear_rl();
    for ($i = 0; $i < 12; $i++) {
        shop_post('order', ['p' => 'guide', 'qty' => '0', 'email' => 'x', 'consent' => '1']);
    }
    ok(shop_post('order', ['p' => 'guide', 'qty' => '1', 'email' => 'y@example.com', 'consent' => '1'])['status'] === 429, 'per-visitor order limit: 13th order attempt in an hour refused');
    clear_rl();
    // digital download
    shop_owner_action($id, 'confirm');
    $r = shop_post('lookup', ['o' => $id, 'v' => 'BUYER']);
    ok(preg_match('/href="(shop\.php\?a=dl&amp;[^"]+)"/', $r['body'], $mm) === 1 && str_contains($r['body'], '提取码 1234'), 'confirmed digital order: lookup shows the content and a download link');
    $dl = '/api/' . html_entity_decode($mm[1] ?? '');
    $r = http('GET', $dl);
    ok($r['status'] === 200 && $r['body'] === 'PDFDATA' && str_contains($r['headers']['content-disposition'] ?? '', 'attachment') && ($r['headers']['content-type'] ?? '') === 'application/octet-stream', 'download serves the private file as an attachment');
    ok(http('GET', preg_replace('/e=(\d+)/', 'e=' . (time() + 99999), $dl))['status'] === 403, 'download with a changed expiry refused');
    ok(http('GET', preg_replace('/o=[^&]+/', 'o=' . rawurlencode(shop_orders()[0]['id']), $dl))['status'] === 403, 'download link of one order does not work for another');
    ok(http('GET', preg_replace('/e=\d+&s=[0-9a-f]+/', 'e=' . (time() - 5) . '&s=' . shop_dl_sig($id, time() - 5, $dg['delivery']['file']['id']), $dl))['status'] === 403, 'expired download link refused');
    ok(http('GET', '/uploads/shop/')['status'] >= 400 && http('GET', '/blog-data/shop/orders.json')['status'] >= 400, 'buyer data and files are not reachable by URL');
    $r = http('GET', '/api/shop.php?a=pay&o=' . $id . '&k=' . $k);
    ok(!str_contains($r['body'], 'buyer@example.com') && !str_contains($r['body'], '13800138000'), 'pay page shows no buyer data');
    // logs and stats never get buyer data
    $logs = '';
    foreach (array_merge(glob(DATA_DIR . '/log/*') ?: [], glob(DATA_DIR . '/stats/*') ?: [], glob(DATA_DIR . '/usage/*') ?: []) as $f) {
        $logs .= (string) file_get_contents($f);
    }
    ok(!str_contains($logs, 'buyer@example.com') && !str_contains($logs, '13800138000') && !str_contains($logs, '王五'), 'no buyer data in the audit log, stats or usage files');
}

/** Admin code rules that need no browser (SPEC §11.6, §11.9, T11, T12); the flows are in admin.e2e.js. */
function group_admin(): void
{
    app_init('cli');
    $views = glob(APP_DIR . '/views/*.php');
    ok(count($views) >= 12, 'admin views present', (string) count($views));
    $bad = [];
    foreach ($views as $f) {
        $src = (string) file_get_contents($f);
        if (preg_match_all('/<\?=(?!\s*(h|j)\()/', $src, $m)) {
            $bad[] = basename($f);
        }
    }
    ok(!$bad, 'views echo only through h() or j()', implode(', ', $bad));
    $inline = [];
    foreach ($views as $f) {
        $src = (string) file_get_contents($f);
        if (preg_match('/<script(?![^>]*type="application\/json")(?![^>]*\bsrc=)[^>]*>/', $src) || preg_match('/\sstyle\s*=/', $src) || preg_match('/\son[a-z]+\s*=/', $src)) {
            $inline[] = basename($f);
        }
    }
    ok(!$inline, 'views have no inline scripts, style attributes or on* handlers (CSP)', implode(', ', $inline));
    $js = (string) preg_replace('~/\*.*?\*/|(?<![:\'"])//[^\n]*~s', '', (string) file_get_contents(PUBLIC_DIR . '/admin/assets/admin.js'));
    $css = (string) file_get_contents(PUBLIC_DIR . '/admin/assets/admin.css');
    ok(!preg_match('/innerHTML|outerHTML|insertAdjacentHTML|document\.write|eval\(|new Function/', $js), 'admin.js builds DOM without HTML strings');
    ok(!preg_match("/setAttribute\\(\\s*['\"]style/", $js), 'admin.js never sets a style attribute');
    ok(!preg_match('/\bconfirm\(|\balert\(|\bprompt\(/', $js), 'admin.js never uses confirm/alert/prompt (WeChat)');
    $ext = [];
    foreach (array_merge($views, [PUBLIC_DIR . '/admin/assets/admin.js', PUBLIC_DIR . '/admin/assets/admin.css']) as $f) {
        $src = (string) file_get_contents($f);
        if (preg_match_all('/(?:src|href|action)\s*=\s*["\'](?:https?:)?\/\/|url\(\s*["\']?(?:https?:)?\/\/|@import/i', $src, $m)) {
            $ext[] = basename($f);
        }
    }
    ok(!$ext, 'admin pages load nothing from other hosts', implode(', ', $ext));
    ok(str_contains($css, 'url(../../assets/fonts/'), 'admin fonts are the self-hosted site fonts');
    $copy = '';
    foreach (array_merge($views, [PUBLIC_DIR . '/admin/assets/admin.js', APP_DIR . '/lib/admin.php', APP_DIR . '/lib/security.php', APP_DIR . '/lib/view.php']) as $f) {
        $copy .= (string) file_get_contents($f);
    }
    ok(!str_contains($copy, "\u{2014}"), 'admin copy has no em dash');
    $words = array_values(array_filter(['赋能', '无缝', '一站式', '解决方案', '助力', '打造', '颠覆', '神器', '极致'], fn($w) => str_contains($copy, $w)));
    ok(!$words, 'admin copy avoids the banned words', implode(' ', $words));
    ok(!preg_match('/会自动(发|寄)|自动发送|自动通知/u', str_replace('不会自动发邮件', '', $copy)), 'admin copy never promises automatic mail');
    // passwords and the setup code
    ok(password_problem('短密码') !== null, 'password: under 10 characters refused');
    ok(password_problem(str_repeat('啊', 12)) !== null, 'password: one repeated character refused');
    ok(password_problem('Password123') !== null, 'password: common password refused (any case)');
    ok(password_problem('云底下的跑道灯2026春') === null, 'password: a Chinese sentence with digits is fine');
    ok(setup_code_normalize(" k7p2 9qxm\u{2014}4htb ") === 'K7P29QXM4HTB', 'setup code: spaces, dashes and case ignored');
    ok(setup_needed(), 'fresh data dir needs setup');
    setup_code_ensure();
    $f = DATA_DIR . '/setup-code.txt';
    $txt = (string) @file_get_contents($f);
    ok((bool) preg_match('/^[A-HJ-KM-NP-Z2-9]{4}-[A-HJ-KM-NP-Z2-9]{4}-[A-HJ-KM-NP-Z2-9]{4}$/m', $txt), 'setup-code.txt holds a 12-character code without look-alike letters');
    ok((fileperms($f) & 0777) === 0600, 'setup-code.txt is private (0600)', decoct(fileperms($f) & 0777));
    $code = setup_code_current();
    setup_code_ensure();
    ok(setup_code_current() === $code, 'the code stays the same until it expires');
    touch($f, time() - 8 * 86400);
    setup_code_ensure();
    ok(setup_code_current() !== null && setup_code_current() !== $code, 'an expired code is replaced on the next visit');
}

$fn = 'group_' . $group;
try {
    $fn();
} catch (Throwable $e) {
    ok(false, 'group crashed: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
