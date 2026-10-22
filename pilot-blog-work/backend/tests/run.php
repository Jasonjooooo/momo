<?php
// Backend test runner (never deployed). Each group runs in its own PHP process with fresh temp
// BLOG_PUBLIC_DIR / BLOG_DATA_DIR, so nothing touches pilot-blog/index.html, posts/, uploads/ or blog-data/.
// Usage: php pilot-blog-work/backend/tests/run.php [group ...]
declare(strict_types=1);

const REPO = __DIR__ . '/../../..';
const GROUPS = ['template', 'markdown', 'publish', 'images', 'album', 'subscribers', 'http', 'stats', 'shop', 'cats', 'supply', 'doubao', 'biz', 'ref', 'game', 'admin'];

if (($argv[1] ?? '') !== '--child') {
    $want = array_slice($argv, 1) ?: GROUPS;
    $failed = 0;
    $passed = 0;
    foreach ($want as $g) {
        $tmp = make_site();
        $env = ['BLOG_PUBLIC_DIR' => $tmp . '/pub', 'BLOG_DATA_DIR' => $tmp . '/data', 'TEST_TMP' => $tmp, 'PATH' => getenv('PATH')]
            + ($g === 'supply' ? ['BLOG_SUPPLY_TEST_LOOPBACK' => '1'] : []);
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
    ok(hash('sha256', $baseline) === BASELINE_SHA256, 'baseline-index.html is the frozen baseline (re-frozen 2026-10-19, see rebaseline.log)');
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
    ok(in_array('8000', shop_verify_candidates('138 0013 8000'), true) && shop_verify_candidates('12345678@qq.com')[0] === '12345678' && shop_verify_candidates('  ') === [], 'lookup accepts the whole phone number; an all-digit QQ mailbox still matches itself');
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
    $r = http('GET', "/api/shop.php?a=paid&o=$id&k=$k&tok=" . shop_form_token('paid', time() - 5) . '&website=');
    ok($r['status'] === 405 && shop_order_get($id)['status'] === 'pending', '我已付款 cannot be triggered by a URL (GET → 405)');
    $r = shop_post('paid', ['o' => $id, 'k' => $k, 'payer' => '尾号 8888']);
    ok($r['status'] === 303 && shop_order_get($id)['status'] === 'claimed' && shop_order_get($id)['payer'] === '尾号 8888', '我已付款 marks the order (303 back to the pay page)');
    // lookup
    clear_rl();
    $r = shop_post('lookup', ['o' => $id, 'v' => 'buyer']);
    ok($r['status'] === 200 && str_contains($r['body'], '已付款，等站长确认') && !str_contains($r['body'], 'buyer@example.com'), 'lookup with the email part works; the email is not echoed');
    $r0 = shop_post('lookup', ['o' => '20261017-ZZZZZZ', 'v' => 'x']);
    for ($i = 0; $i < 8; $i++) {
        $r0 = shop_post('lookup', ['o' => '20261017-ZZZZZZ', 'v' => 'x' . $i]);
        exec('rm -rf ' . escapeshellarg(DATA_DIR . '/ratelimit/shop-lookup') . ' ' . escapeshellarg(DATA_DIR . '/ratelimit/shop-lookup-day'));
    }
    ok($r0['status'] === 429, 'an order number that does not exist locks the same way (the lock reveals nothing)');
    clear_rl();
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

// ---------- 货源 (SPEC §20 货源) ----------

/** Start the mock supplier; returns [proc, port, stateFile]. */
function group_cats(): void
{
    app_init('cli');
    publish_site('初始内容');
    ok(shop_cats() === [], 'no categories file: no categories');
    // migration: products saved before categories have no `cat` key and read as 不分类; a hand-broken value too
    shop_ensure_dirs();
    $tmp = shop_png();
    $src = image_store_file($tmp)['src'];
    unlink($tmp);
    json_write_atomic(shop_products_path(), ['rev' => 3, 'items' => [
        ['id' => 'g00000000a1', 'slug' => 'old-one', 'name' => '老商品', 'type' => 'physical', 'price' => 1000, 'stock' => 5, 'on_sale' => true, 'photos' => [['src' => $src, 'alt' => '']]],
        ['id' => 'g00000000a2', 'slug' => 'old-two', 'name' => '老商品二', 'type' => 'physical', 'price' => 2000, 'stock' => 5, 'on_sale' => true, 'photos' => [['src' => $src, 'alt' => '']], 'cat' => '"><script>'],
    ]]);
    $old = shop_products();
    ok($old[0]['cat'] === '' && $old[1]['cat'] === '', 'migration: products without a category (or with a broken one) read as 不分类, no file edit needed');
    publish_site('迁移', ['version' => false]);
    ok(!str_contains(pub('index.html'), 'data-shop-cats') && !str_contains(pub('index.html'), 'data-cat=') && !str_contains(pub('shop/old-one.html'), 'class="crumbs"'), 'no categories yet: the home grid and product pages look as before (no tabs, no breadcrumb)');

    // names and limits
    ok(throws(fn() => shop_cat_add('   '), ShopError::class), 'empty name refused');
    ok(throws(fn() => shop_cat_add(str_repeat('长', 13)), ShopError::class), 'name over 12 characters refused');
    $a = shop_cat_add(str_repeat('长', 12));
    ok(preg_match(SHOP_CAT_ID_RE, $a['id']) === 1 && $a['name'] === str_repeat('长', 12), '12 characters accepted, id c + 8 hex');
    $b = shop_cat_add("航空\x00周边\u{202E}");
    ok($b['name'] === '航空 周边', 'name is one plain line: control and bidi characters removed', $b['name']);
    ok(throws(fn() => shop_cat_add('航空 周边'), ShopError::class), 'a second 大类 with the same name refused');
    $s1 = shop_cat_add('海报', $b['id']);
    $s2 = shop_cat_add('徽章', $b['id']);
    ok(throws(fn() => shop_cat_add('海报', $b['id']), ShopError::class) && shop_cat_add('海报', $a['id'])['name'] === '海报', 'same 小类 name refused inside one 大类, fine in another');
    ok(throws(fn() => shop_cat_add('x', 'c00000000'), ShopError::class), '小类 in a missing 大类 refused');
    $ids = array_keys(shop_cat_index(shop_cats()));
    ok(count($ids) === count(array_unique($ids)) && count($ids) === 5, 'ids are unique across both levels');
    for ($i = count(shop_cats()); $i < SHOP_CATS_MAX; $i++) {
        shop_cat_add('类' . $i);
    }
    ok(count(shop_cats()) === 20 && throws(fn() => shop_cat_add('第二十一'), ShopError::class), 'at most 20 大类');
    $many = shop_cat_add('子' . 0, $a['id']);
    for ($i = 2; $i < SHOP_SUBS_MAX; $i++) {
        shop_cat_add('子' . $i, $a['id']);
    }
    ok(count(shop_cats()[0]['subs']) === 30 && throws(fn() => shop_cat_add('子满', $a['id']), ShopError::class), 'at most 30 小类 in one 大类');
    // delete the empty filler (a 大类 with 30 empty 小类 goes with them)
    shop_cat_delete($a['id']);
    foreach (shop_cats() as $c) {
        if (str_starts_with($c['name'], '类')) {
            shop_cat_delete($c['id']);
        }
    }
    ok(array_column(shop_cats(), 'name') === ['航空 周边'] && !isset(shop_cat_index(shop_cats())[$many['id']]), 'deleting an empty 大类 removes its empty 小类 too');

    // rename, order
    shop_cat_rename($b['id'], '航空周边');
    shop_cat_rename($s2['id'], '徽章贴纸');
    ok(shop_cats()[0]['name'] === '航空周边' && shop_cats()[0]['subs'][1]['name'] === '徽章贴纸' && shop_cats()[0]['id'] === $b['id'], 'rename keeps the id');
    ok(throws(fn() => shop_cat_rename($s2['id'], '海报'), ShopError::class) && throws(fn() => shop_cat_rename($b['id'], ''), ShopError::class), 'rename to a taken or empty name refused');
    $c = shop_cat_add('书和资料');
    ok(shop_cat_move($c['id'], 'up') && array_column(shop_cats(), 'id') === [$c['id'], $b['id']], '大类 move up');
    ok(!shop_cat_move($c['id'], 'up'), 'the first 大类 cannot move further up');
    ok(shop_cat_move($s1['id'], 'down') && array_column(shop_cats()[1]['subs'], 'id') === [$s2['id'], $s1['id']], '小类 move down inside its 大类');
    ok(!shop_cat_move($s1['id'], 'down'), 'the last 小类 cannot move further down');

    // products in categories
    $poster = shop_make('poster', 'physical', '99', 3, ['cat' => $s1['id']]);
    $book = shop_make('book', 'digital', '19', null, ['cat' => $c['id']]);
    ok($poster['cat'] === $s1['id'] && $book['cat'] === $c['id'], 'a product sits in a 小类 or directly in a 大类');
    try {
        shop_product_save($poster['id'], ['name' => 'x', 'slug' => 'poster', 'type' => 'physical', 'price' => '99', 'stock' => '3', 'stock_seen' => '3', 'on_sale' => '1', 'cat' => 'c00000000'], shop_product_get($poster['id'])['rev']);
        ok(false, 'unknown category refused');
    } catch (ShopError $e) {
        ok(isset($e->fields['cat']), 'saving a product into a category that does not exist is refused');
    }
    $p = shop_product_get($poster['id']);
    shop_product_save($p['id'], ['name' => $p['name'], 'slug' => 'poster', 'type' => 'physical', 'price' => '99', 'stock' => '3', 'stock_seen' => '3', 'on_sale' => '1'], $p['rev']);
    ok(shop_product_get($poster['id'])['cat'] === $s1['id'], 'a save that does not send the category keeps it');
    $badge = shop_make('badge', 'physical', '15', 9, ['cat' => $b['id']]);
    $off = shop_make('offsale', 'physical', '5', 9, ['cat' => $s2['id']]);
    shop_product_save($off['id'], ['name' => $off['name'], 'slug' => 'offsale', 'type' => 'physical', 'price' => '5', 'stock' => '9', 'stock_seen' => '9', 'on_sale' => ''], $off['rev']);

    // delete with products: refused, with the count
    try {
        shop_cat_delete($s1['id']);
        ok(false, '小类 with a product refused');
    } catch (ShopError $e) {
        ok($e->code_ === 'state' && str_contains($e->getMessage(), '还有 1 件商品'), 'deleting a 小类 that holds a product is refused with the count', $e->getMessage());
    }
    try {
        shop_cat_delete($b['id']);
        ok(false, '大类 with products refused');
    } catch (ShopError $e) {
        ok(str_contains($e->getMessage(), '还有 3 件商品'), 'deleting a 大类 counts the products in it and its 小类 (an off-sale one too)', $e->getMessage());
    }
    ok(throws(fn() => shop_cat_delete($s2['id']), ShopError::class), 'a 小类 holding only an off-sale product cannot be deleted either');

    // the public tree and the published pages
    $settings = settings_get();
    $tree = shop_cats_public(shop_cats(), array_values(array_filter(shop_products(), fn($x) => shop_product_live($x, $settings))));
    ok(array_column($tree['cats'], 'id') === [$c['id'], $b['id']] && array_column($tree['cats'][1]['subs'], 'id') === [$s1['id']] && $tree['cats'][1]['n'] === 2 && $tree['cats'][1]['direct'] === 1 && $tree['other'] === 2,
        'public tree: owner order, only 小类 with a product on sale (徽章贴纸 holds only an off-sale one), counts, 其他 = 2 old products', json_encode($tree, JSON_UNESCAPED_UNICODE));
    $home = pub('index.html');
    ok(str_contains($home, '<div class="shop-cats" data-shop-cats>') && str_contains($home, '#shop/c/') && str_contains($home, 'shopCats()'), 'home: tabs block and its script are published');
    $tabsAt = strpos($home, 'class="cat-tabs"');
    $order = [strpos($home, '>全部<', $tabsAt), strpos($home, '>书和资料<', $tabsAt), strpos($home, '>航空周边<', $tabsAt), strpos($home, '>其他<', $tabsAt)];
    ok(!in_array(false, $order, true) && $order === array_values(array_unique($order)) && $order[0] < $order[1] && $order[1] < $order[2] && $order[2] < $order[3], 'tabs: 全部 first, 大类 in owner order, 其他 last');
    ok(str_contains($home, 'data-subs-of="' . $b['id'] . '" hidden><button type="button" class="cat-chip" data-sub="" aria-pressed="true">全部</button><button type="button" class="cat-chip" data-sub="' . $s1['id'] . '" aria-pressed="false">海报</button></div>')
        && !str_contains($home, '徽章贴纸') && !str_contains($home, 'data-subs-of="' . $c['id'] . '"'), 'chips: one row per 大类 with 小类, 全部 first; empty 小类 and rows for a 大类 without 小类 left out');
    ok(str_contains($home, 'data-cat="' . $b['id'] . '" data-sub="' . $s1['id'] . '"><a class="good-a" href="shop/poster.html"') && str_contains($home, 'data-cat="' . $b['id'] . '" data-sub=""><a class="good-a" href="shop/badge.html"')
        && str_contains($home, 'data-cat="other" data-sub=""><a class="good-a" href="shop/old-one.html"'), 'goods carry their 大类 / 小类 (or other) for the filter');
    ok(str_contains($home, 'type="button" class="cat-tab" data-cat="" aria-pressed="true">全部') && substr_count($home, 'class="cat-tab"') === 4, 'tabs are buttons with aria-pressed (全部 pressed)');
    ok(!str_contains($home, ' hidden><a class="good-a"') && !preg_match('/<li class="good[^"]*"[^>]*\shidden/', $home), 'every product is in the published grid without script (nothing hidden server-side)');
    ok(str_contains($home, '<span class="good-c">航空周边 / 海报</span>') && str_contains($home, '<span class="good-c">其他</span>'), 'cards name their category (visible without script)');
    $pp = pub('shop/poster.html');
    ok(str_contains($pp, '<nav class="crumbs" aria-label="所在分类"><ol><li><a href="../#shop">商城</a></li><li><a href="../#shop/c/' . $b['id'] . '">航空周边</a></li><li><a href="../#shop/c/' . $s1['id'] . '">海报</a></li></ol></nav>'), 'product page breadcrumb 商城 / 大类 / 小类 links to the filtered home shop');
    ok(str_contains(pub('shop/book.html'), '<li><a href="../#shop/c/' . $c['id'] . '">书和资料</a></li></ol>') && str_contains(pub('shop/old-one.html'), '<li><a href="../#shop/c/other">其他</a></li></ol>'), 'breadcrumb: a product directly in a 大类 stops there; no category: 其他');
    ok(str_contains($home, '<style id="extra-css">') && str_contains($home, '.cat-tab[aria-pressed="true"]'), 'the category styles are published with the extra CSS');

    // empty categories are hidden; moving products out then deleting works
    $bk = shop_product_get($book['id']);
    shop_product_save($bk['id'], ['name' => $bk['name'], 'slug' => 'book', 'type' => 'digital', 'price' => '19', 'stock' => '', 'unlimited' => '1', 'stock_seen' => '', 'on_sale' => '', 'dl_text' => '提取码 1234', 'cat' => $c['id']], $bk['rev']);
    ok(!str_contains(pub('index.html'), '>书和资料<'), 'a 大类 whose only product went off sale disappears from the home tabs');
    $bk = shop_product_get($book['id']);
    shop_product_save($bk['id'], ['name' => $bk['name'], 'slug' => 'book', 'type' => 'digital', 'price' => '19', 'stock' => '', 'unlimited' => '1', 'stock_seen' => '', 'on_sale' => '', 'dl_text' => '提取码 1234', 'cat' => ''], $bk['rev']);
    shop_cat_delete($c['id']);
    ok(!isset(shop_cat_index(shop_cats())[$c['id']]), 'after its product moved to 不分类, the 大类 can be deleted');
    shop_cat_rename($b['id'], '新名字');
    ok(str_contains(pub('index.html'), '>新名字<') && str_contains(pub('shop/poster.html'), '>新名字</a>'), 'a rename republishes the home tabs and the product pages');

    // closed shop: no tabs
    settings_save(['shop' => ['enabled' => false]], (int) settings_get()['rev']);
    ok(!str_contains(pub('index.html'), 'data-shop-cats') && !str_contains(pub('shop/poster.html'), 'class="crumbs"'), 'shop closed: no tabs and no breadcrumb');
    settings_save(['shop' => ['enabled' => true]], (int) settings_get()['rev']);

    // escaping: hostile names and a hand-edited file
    $x = shop_cat_add('<zz>"&\'</zz>', $b['id']);
    $pp = shop_product_get($poster['id']);
    shop_product_save($pp['id'], ['name' => $pp['name'], 'slug' => 'poster', 'type' => 'physical', 'price' => '99', 'stock' => '3', 'stock_seen' => '3', 'on_sale' => '1', 'cat' => $x['id']], $pp['rev']);
    $all = pub('index.html') . pub('shop/poster.html');
    ok(!str_contains($all, '<zz>') && substr_count($all, '&lt;zz&gt;') === 3, 'category names are escaped on the home chips, cards and the breadcrumb', (string) substr_count($all, '&lt;zz&gt;'));
    shop_cat_rename($b['id'], '"><b>x');
    ok(!str_contains(pub('index.html') . pub('shop/poster.html'), '"><b>x') && str_contains(pub('index.html'), 'aria-label="「&quot;&gt;&lt;b&gt;x」的小类"'), 'a 大类 name is escaped in attributes (aria-label) too');
    $doc = json_read(shop_cats_path(), []);
    $doc['items'][] = ['id' => 'c1234567"><script>alert(1)</script>', 'name' => 'bad', 'subs' => []];
    $doc['items'][] = ['id' => $b['id'], 'name' => '重复', 'subs' => []];
    $doc['items'][] = ['id' => 'cabcdef01', 'name' => '', 'subs' => []];
    json_write_atomic(shop_cats_path(), $doc);
    ok(array_column(shop_cats(), 'id') === [$b['id']], 'a hand-edited file: bad ids, duplicate ids and empty names are dropped on read');
    publish_site('检查', ['version' => false]);
    ok(!str_contains(pub('index.html'), '<script>alert(1)'), 'nothing from a broken categories file reaches the page');
    ok((fileperms(shop_cats_path()) & 0777) === 0600 || (fileperms(shop_cats_path()) & 0077) === 0, 'categories.json is private', decoct(fileperms(shop_cats_path()) & 0777));
}

function mock_start(): array
{
    require_once __DIR__ . '/supply-mock-data.php';
    $state = getenv('TEST_TMP') . '/mock-state.json';
    file_put_contents($state, json_encode(supply_mock_state(), JSON_UNESCAPED_UNICODE));
    $port = 30000 + random_int(0, 9999);
    $env = ['MOCK_STATE' => $state, 'PATH' => getenv('PATH'), 'PHP_CLI_SERVER_WORKERS' => '4'];
    $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/supply-mock.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(100000);
    }
    return [$proc, $port, $state];
}

function mock_state(): array
{
    return json_decode((string) file_get_contents($GLOBALS['MOCK_STATE']), true);
}

function mock_knobs(array $k): void
{
    $s = mock_state();
    $s['knobs'] = $k + ($s['knobs'] ?? []);
    file_put_contents($GLOBALS['MOCK_STATE'], json_encode($s, JSON_UNESCAPED_UNICODE));
}

function mock_set(string $key, mixed $v): void
{
    $s = mock_state();
    $s[$key] = $v;
    file_put_contents($GLOBALS['MOCK_STATE'], json_encode($s, JSON_UNESCAPED_UNICODE));
}

/** A paid supply order: created, then 确认收款 by the owner (status confirmed, nothing bought yet). */
function supply_paid_order(string $slug, int $qty = 1, string $email = 'gamer@example.com'): array
{
    $p = shop_product_by_slug($slug);
    [$c, $e] = shop_order_input($p, ['qty' => (string) $qty, 'email' => $email, 'consent' => '1']);
    if ($e) {
        throw new RuntimeException('order input: ' . json_encode($e, JSON_UNESCAPED_UNICODE));
    }
    $o = shop_create_order($slug, $c);
    return shop_owner_action($o['id'], 'confirm');
}

function group_supply(): void
{
    app_init('cli');
    publish_site('初始内容');

    // signing: the supplier's PHP reference (ksort, drop '', urldecode(http_build_query) . &key=)
    $d = ['b' => 'x y', 'app_id' => '7', 'a' => '', 'sku' => ['颜色' => '红', '尺码' => 'L'], 'z' => '0', 'sign' => 'old'];
    $want = md5('app_id=7&b=x y&sku[颜色]=红&sku[尺码]=L&z=0&key=K&1');
    ok(supply_sign($d, 'K&1') === $want, 'sign: ksort, empty values dropped, "0" kept, arrays as sku[k]=v, sign itself excluded, urldecoded');
    ok(supply_sign(['app_id' => '7', 'q' => 'a+b&c=d%20'], 'k') === md5(urldecode('app_id=7&q=' . urlencode('a+b&c=d%20') . '&key=k')), 'sign: special characters go through http_build_query then urldecode, like the reference');
    ok(supply_sign(['b' => '1', 'a' => '2'], 'k') === supply_sign(['a' => '2', 'b' => '1'], 'k'), 'sign: parameter order does not matter');

    // SSRF: the address the owner types
    $no = ['http://shop.example-card.com', 'https://127.0.0.1', 'https://10.1.2.3', 'https://192.168.1.1/x', 'https://172.16.0.9', 'https://169.254.169.254',
        'https://100.64.0.1', 'https://[::1]', 'https://[fd00::1]', 'https://[::ffff:127.0.0.1]', 'https://0.0.0.0', 'https://localhost', 'https://foo.internal',
        'https://router.lan', 'https://2130706433', 'https://0x7f000001', 'https://user:pw@shop.example-card.com', 'https://shop.example-card.com/?a=1',
        'https://shop.example-card.com#x', 'https://shop.example-card.com:22', 'https://shop.example-card.com:6379', 'ftp://shop.example-card.com', 'file:///etc/passwd',
        'gopher://shop.example-card.com', 'https://shop.example-card.com/../x', "https://shop.example-card.com\n.evil", 'https://shop.example-card.com\\@evil.com', '//shop.example-card.com', ''];
    $passed = array_values(array_filter($no, fn($u) => supply_url_parse($u, false)['ok']));
    ok($passed === [], 'SSRF: http, private/loopback/link-local/CGNAT/mapped IPs, internal names, numeric hosts, odd ports, userinfo, query, other schemes refused', implode(' | ', $passed));
    $yes = supply_url_parse('https://Shop.Example-Card.com/api/', false);
    ok($yes['ok'] && $yes['url'] === 'https://shop.example-card.com/api' && supply_url_parse('https://shop.example-card.cn:8443', false)['ok'], 'a normal https address (with a path or port 8443) is accepted and normalized');
    ok(!supply_resolve('127.0.0.1', false)['ok'] && !supply_resolve('10.0.0.8', false)['ok'] && !supply_resolve('::1', false)['ok'] && supply_resolve('8.8.8.8', false)['ok'], 'DNS check: a host whose address is internal is refused (every answer must be public)');
    ok(!supply_ip_public('100.100.1.1') && !supply_ip_public('198.18.0.1') && !supply_ip_public('224.0.0.1') && !supply_ip_public('fe80::1') && !supply_ip_public('64:ff9b::7f00:1') && supply_ip_public('45.152.64.62'), 'blocked ranges include CGNAT, benchmark, multicast, link-local and NAT64 of loopback');
    ok(throws(fn() => settings_save(['supply' => ['base_url' => 'https://192.168.0.1']], settings_get()['rev']), ValidationError::class), 'settings refuse an internal supplier address');

    [$mock, $mport, $GLOBALS['MOCK_STATE']] = mock_start();
    $base = 'http://127.0.0.1:' . $mport;
    try {
        $cfg = ['base_url' => $base, 'app_id' => MOCK_APP_ID, 'app_key' => MOCK_APP_KEY];
        ok(supply_test_connection($cfg)['ok'], '测试连接 against the mock: signature accepted');
        $bad = supply_test_connection(['app_key' => 'wrong-key-123'] + $cfg);
        ok(!$bad['ok'] && str_contains($bad['msg'], '签名错误') && str_contains($bad['msg'], SUPPLY_SERVER_IP), 'wrong key: plain message with the supplier\'s words and the whitelist hint (' . SUPPLY_SERVER_IP . ')', $bad['msg']);
        ok(!str_contains($bad['msg'], 'wrong-key-123') && !str_contains($bad['msg'], MOCK_APP_KEY), 'the key never appears in a message');
        mock_knobs(['ip_refuse' => true]);
        $ip = supply_test_connection($cfg);
        ok(!$ip['ok'] && str_contains($ip['msg'], '白名单') && str_contains($ip['msg'], '45.152.64.62'), 'IP refused: the owner is told to add 45.152.64.62 to the 对接白名单', $ip['msg']);
        mock_knobs(['ip_refuse' => false]);
        $r = supply_call('/shared/authentication/connect', [], ['base_url' => $base . '/redir'] + $cfg);
        ok($r['kind'] === 'bad' && str_contains($r['msg'], '跳转'), 'a redirect (to the cloud metadata address) is not followed', $r['kind']);
        $r = supply_call('/shared/authentication/connect', [], ['base_url' => $base . '/big'] + $cfg);
        ok($r['kind'] === 'net' && str_contains($r['msg'], '太大'), 'an answer over 2 MB is cut off', $r['kind']);
        $r = supply_call('/shared/authentication/connect', [], ['base_url' => $base . '/html'] + $cfg);
        ok($r['kind'] === 'bad', 'an answer that is not the API\'s JSON is refused', $r['kind']);
        $r = supply_call('/shared/authentication/connect', [], ['base_url' => 'http://127.0.0.1:' . ($mport === 39999 ? 39998 : $mport + 1)] + $cfg);
        ok($r['kind'] === 'net', 'no server: a network failure, not a crash');
        $r = supply_call('/shared/../../etc', [], $cfg);
        ok($r['kind'] === 'config', 'only API paths can be called');

        // settings: write-only key
        settings_save(['supply' => ['base_url' => $base, 'app_id' => MOCK_APP_ID, 'enabled' => true]], settings_get()['rev']);
        supply_key_set(MOCK_APP_KEY);
        ok(supply_ready() && supply_key_hint() === substr(MOCK_APP_KEY, -4) && !str_contains((string) file_get_contents(data_path('settings.json')), MOCK_APP_KEY), 'key stored in secrets.json only; hint is the last 4');
        ok((fileperms(data_path('secrets.json')) & 0777) === 0600, 'secrets.json stays 0600');

        // catalog and import rules
        $cat = supply_catalog(true);
        ok($cat['ok'] && count($cat['cats']) === 3 && count($cat['cats'][0]['items']) === 2 && $cat['cats'][0]['items'][0]['user_price'] === 580, 'catalog: categories and products with this account\'s cost', json_encode($cat['cats'][0]['items'][0] ?? null, JSON_UNESCAPED_UNICODE));
        $calls = count(mock_state()['calls']);
        supply_catalog(false);
        ok(count(mock_state()['calls']) === $calls, 'catalog is cached (no second call within 5 minutes)');
        $why = fn(string $code) => supply_unsupported(supply_item($code)['item']);
        ok($why('WZRY60') === [] && $why('YSRACE') === [] && $why('WZRYMAN') === [] && $why('MAIL01') === [], 'plain, 种类, manual-delivery and email-contact products can be sold');
        ok(str_contains(implode('', $why('SKU01')), '规格') && str_contains(implode('', $why('WIDGET01')), '额外信息') && str_contains(implode('', $why('PHONE01')), '手机号') && str_contains(implode('', $why('PASS01')), '密码'), 'sku, widget, phone contact and password products are 暂不支持 with the reason');
        $it = supply_item('WZRY60')['item'];
        ok(!str_contains($it['description'], '<') && !str_contains($it['description'], 'alert(1)') && str_contains($it['description'], '官方 60 点券兑换码。') && str_contains($it['description'], '- 不退不换'), 'supplier HTML description becomes plain text (no tags, no script text)', $it['description']);
        ok(array_keys(supply_item('YSRACE')['item']['races']) === ['空月祝福', '648 创世结晶'] && supply_item('YSRACE')['item']['races']['空月祝福'] === 2500, '种类 come from config.category with their costs');
        ok(throws(fn() => supply_import('SKU01', ['name' => 'x', 'price' => '99', 'on_sale' => '1']), ShopError::class), 'importing an unsupported product is refused');
        try {
            supply_import('WZRY60', ['name' => '王者 60 点券', 'price' => '5.00', 'body' => 'x', 'on_sale' => '1']);
            ok(false, 'price below cost refused');
        } catch (ShopError $e) {
            ok(isset($e->fields['price']) && str_contains($e->getMessage(), '低于成本'), 'import: a price below cost (5.00 < 5.80) is refused', $e->getMessage());
        }
        ok(throws(fn() => supply_import('YSRACE', ['name' => '原神', 'price' => '30', 'race' => '不存在', 'on_sale' => '1']), ShopError::class), 'import: a 种类 the supplier does not have is refused');
        $p1 = supply_import('WZRY60', ['name' => '王者荣耀 60 点券', 'price' => '6.80', 'body' => $it['description'], 'on_sale' => '1']);
        ok($p1['type'] === 'supply' && $p1['supply']['cost'] === 580 && $p1['stock'] === 500 && $p1['price'] === 680 && count($p1['photos']) === 1, 'import: a supply product with cost, supplier stock, price and a default card photo');
        ok(throws(fn() => supply_import('WZRY60', ['name' => 'again', 'price' => '9', 'on_sale' => '1']), ShopError::class), 'the same product and 种类 cannot be imported twice');
        $p2 = supply_import('YSRACE', ['name' => '原神 空月祝福', 'price' => '28', 'race' => '空月祝福', 'body' => '', 'on_sale' => '1']);
        ok($p2['supply']['race'] === '空月祝福' && $p2['supply']['cost'] === 2500, 'import with a 种类: cost of that 种类');
        $gameCat = shop_cat_add('游戏点卡');
        $gameSub = shop_cat_add('王者荣耀', $gameCat['id']);
        try {
            supply_import('WZRYMAN', ['name' => '王者 皮肤礼包', 'price' => '35', 'body' => '', 'on_sale' => '1', 'cat' => 'c00000000']);
            ok(false, 'import into a missing category refused');
        } catch (ShopError $e) {
            ok(isset($e->fields['cat']), 'import: a category that does not exist is refused');
        }
        $p3 = supply_import('WZRYMAN', ['name' => '王者 皮肤礼包', 'price' => '35', 'body' => '', 'on_sale' => '1', 'cat' => $gameSub['id']]);
        ok($p3['cat'] === $gameSub['id'] && shop_product_get($p3['id'])['cat'] === $gameSub['id'] && $p1['cat'] === '', 'import: the category chosen at import is stored (none chosen: 不分类)');
        ok(str_contains(pub('index.html'), 'data-cat="' . $gameCat['id'] . '" data-sub="' . $gameSub['id'] . '"') && str_contains(pub('shop/' . $p3['slug'] . '.html'), 'href="../#shop/c/' . $gameSub['id'] . '"'), 'an imported card shows under its category on the home grid and in its breadcrumb');
        $p4 = supply_import('MAIL01', ['name' => '邮箱卡', 'price' => '4', 'body' => '', 'on_sale' => '1']);
        $px = supply_import('XSSNAME', ['name' => '<img src=x onerror=alert(1)>点卡', 'price' => '10', 'body' => supply_item('XSSNAME')['item']['description'], 'on_sale' => '1']);
        $home = pub('index.html');
        $page = pub('shop/' . $p1['slug'] . '.html');
        $xpage = pub('shop/' . $px['slug'] . '.html');
        ok(str_contains($home, 'href="shop/' . $p1['slug'] . '.html"') && str_contains($page, '自动发卡') && str_contains($page, '确认收款后系统自动发卡'), 'imported product is on the home grid and its page says cards are sent automatically after the owner confirms payment');
        $leak = array_filter(['WZRY60', 'YSRACE', '5.80', '¥5.80', '王者荣耀 60 点券（自动发卡）', '127.0.0.1:' . $mport, MOCK_APP_ID . '"', 'src_name'], fn($n) => str_contains($home . $page . pub('shop/' . $p2['slug'] . '.html'), $n));
        ok($leak === [], 'buyers never see the supplier\'s code, cost, product name or address', implode(' | ', $leak));
        ok(!str_contains($xpage . $home, '<img src=x') && !str_contains($xpage, 'href="javascript:') && !str_contains($xpage, '<iframe') && !str_contains($xpage, '<script'), 'supplier-provided names and descriptions are escaped on public pages');
        try {
            shop_product_save($p1['id'], ['name' => $p1['name'], 'slug' => $p1['slug'], 'type' => 'physical', 'price' => '5', 'stock' => '9999', 'on_sale' => '1', 'summary' => '', 'body' => '', 'dl_text' => 'x', 'dl_link' => ''], shop_product_get($p1['id'])['rev']);
            ok(false, 'editor price below cost refused');
        } catch (ShopError $e) {
            ok(isset($e->fields['price']), 'editor: lowering the price below cost while on sale is refused');
        }
        $p1b = shop_product_save($p1['id'], ['name' => $p1['name'], 'slug' => $p1['slug'], 'type' => 'physical', 'price' => '7', 'stock' => '9999', 'on_sale' => '1', 'summary' => '', 'body' => '', 'dl_text' => 'x', 'dl_link' => ''], shop_product_get($p1['id'])['rev']);
        ok($p1b['type'] === 'supply' && $p1b['stock'] === 500 && $p1b['delivery']['text'] === '' && $p1b['price'] === 700, 'editor: type, stock and delivery of a supply product cannot be changed by the form');

        // ordering: quantity bounds, stock from the supplier
        ok(isset(shop_order_input($p4, ['qty' => '1', 'email' => 'a@b.cn', 'consent' => '1'])[1]['qty']) && isset(shop_order_input($p4, ['qty' => '6', 'email' => 'a@b.cn', 'consent' => '1'])[1]['qty']) && !shop_order_input($p4, ['qty' => '3', 'email' => 'a@b.cn', 'consent' => '1'])[1], 'per-order quantity bounds come from the supplier\'s minimum/maximum (2..5)');
        mock_knobs(['stock_override' => 0]);
        supply_refresh_product($p1['id'], 0);
        ok(shop_product_get($p1['id'])['stock'] === 0 && str_contains(pub('shop/' . $p1['slug'] . '.html'), '售罄') && throws(fn() => supply_paid_order($p1['slug'])), 'supplier stock 0: product shows 售罄 and orders are refused');
        mock_knobs(['stock_override' => null]);
        supply_refresh_product($p1['id'], 0);
        ok(shop_product_get($p1['id'])['stock'] === 500, 'stock comes back after a refresh');

        // never before payment is confirmed
        $o = shop_create_order($p1['slug'], shop_order_input(shop_product_get($p1['id']), ['qty' => '2', 'email' => 'early@example.com', 'consent' => '1'])[0]);
        $r = supply_fulfill($o['id'], 'retry');
        ok(!$r['ok'] && (mock_state()['trade_calls'] ?? 0) === 0 && $o['held'] === false && shop_product_get($p1['id'])['stock'] === 500, 'an unpaid order never trades (and does not reserve the supplier\'s stock)');
        shop_claim($o['id'], '');
        ok(!supply_fulfill($o['id'], 'retry')['ok'] && (mock_state()['trade_calls'] ?? 0) === 0, 'a claimed (我已付款) order never trades either');

        // confirm → trade once, deterministic request_no, secret stored, completed
        $o = shop_owner_action($o['id'], 'confirm');
        ok($o['status'] === 'confirmed', '确认收款 on a supply order: confirmed, waiting for the purchase');
        $bal = mock_state()['balance'];
        $r = supply_fulfill($o['id'], 'confirm');
        $o = shop_order_get($o['id']);
        $ms = mock_state();
        ok($r['ok'] && $o['status'] === 'completed' && $o['supply']['trade_no'] !== '' && str_starts_with($o['supply']['secret'], 'CARD-') && substr_count($o['supply']['secret'], "\n") === 1, 'trade: card content stored on the order, order completed', json_encode($o['supply'], JSON_UNESCAPED_UNICODE));
        ok($o['supply']['request_no'] === supply_request_no($o['id'], 0) && $ms['calls'][count($ms['calls']) - 1]['request_no'] === 'PB' . str_replace('-', '', $o['id']) . 'T0', 'request_no is derived from our order number');
        ok($bal - $ms['balance'] === 1160 && $o['supply']['amount'] === 1160, 'the supplier charged 2 × ¥5.80 once');
        ok($ms['calls'][count($ms['calls']) - 1]['contact'] === $o['id'] && !in_array('early@example.com', array_column($ms['calls'], 'contact'), true), 'contact type 任意: the supplier gets the order number, not the buyer\'s email');
        $n = $ms['trade_calls'];
        supply_fulfill($o['id'], 'retry');
        supply_fulfill($o['id'], 'confirm');
        ok(mock_state()['trade_calls'] === $n && mock_state()['balance'] === $ms['balance'], 'retry or a second confirm on a bought order never trades again');
        ok(throws(fn() => shop_owner_action($o['id'], 'confirm'), ShopError::class), 'double 确认收款 is refused by the order state');
        ok(throws(fn() => shop_owner_action($o['id'], 'cancel', ['reason' => 'x']), ShopError::class), 'a bought order cannot be cancelled (refund note instead)');

        // parallel: 8 processes try to buy the same paid order at once
        $o2 = supply_paid_order($p1['slug']);
        mock_knobs(['trade_delay_ms' => 400]);
        $before = mock_state()['trade_calls'];
        $code = 'define("BLOG_APP",true);require ' . var_export(REPO . '/pilot-blog/app/lib/bootstrap.php', true) . ';app_init("cli");'
            . '$r=supply_fulfill(' . var_export($o2['id'], true) . ',"retry");echo $r["ok"]?"OK":"NO";';
        $procs = [];
        $pp = [];
        for ($i = 0; $i < 8; $i++) {
            $procs[] = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipesI, null, ['BLOG_PUBLIC_DIR' => PUBLIC_DIR, 'BLOG_DATA_DIR' => DATA_DIR, 'PATH' => getenv('PATH'), 'BLOG_SUPPLY_TEST_LOOPBACK' => '1']);
            $pp[] = $pipesI;
        }
        $res = [];
        foreach ($procs as $i => $pr) {
            $res[] = stream_get_contents($pp[$i][1]) . stream_get_contents($pp[$i][2]);
            proc_close($pr);
        }
        mock_knobs(['trade_delay_ms' => 0]);
        ok(mock_state()['trade_calls'] - $before === 1 && shop_order_get($o2['id'])['status'] === 'completed', '8 concurrent purchases of one paid order: exactly one trade call', implode(',', $res) . ' trades=' . (mock_state()['trade_calls'] - $before));

        // failures: balance, then retry; IP refused; stock
        mock_set('balance', 100);
        $o3 = supply_paid_order($p1['slug']);
        $r = supply_fulfill($o3['id'], 'confirm');
        $o3 = shop_order_get($o3['id']);
        ok(!$r['ok'] && $o3['status'] === 'failed' && str_contains($o3['supply']['msg'], '余额不足') && $o3['supply']['state'] === 'refused' && $o3['supply']['attempt'] === 1, 'insufficient balance: order goes to 进货失败 with the supplier\'s message for the owner', $o3['supply']['msg']);
        $lk = shop_lookup_result_html($o3, shop_product_get($o3['product_id']), null);
        ok(str_contains($lk, '已确认收款，正在发货') && !str_contains($lk, '余额') && !str_contains($lk, '进货') && !str_contains($lk, 'CARD-'), 'the buyer sees 已确认收款，正在发货, never the supplier\'s message');
        require_once APP_DIR . '/lib/admin_shop.php';
        ok(shop_counts(shop_orders())['failed'] === 1 && shop_attention_count() >= 1, '进货失败 counts for the admin badge');
        mock_set('balance', 100000);
        $r = supply_fulfill($o3['id'], 'retry');
        $o3 = shop_order_get($o3['id']);
        ok($r['ok'] && $o3['status'] === 'completed' && $o3['supply']['request_no'] === supply_request_no($o3['id'], 1), '重试进货 after a definite refusal: new attempt number, bought');
        mock_knobs(['ip_refuse' => true]);
        $o4 = supply_paid_order($p1['slug']);
        supply_fulfill($o4['id'], 'confirm');
        $o4 = shop_order_get($o4['id']);
        ok($o4['status'] === 'failed' && str_contains($o4['supply']['msg'], SUPPLY_SERVER_IP), 'IP refused at trade: 进货失败 with the whitelist hint');
        mock_knobs(['ip_refuse' => false]);
        $o4 = shop_owner_action($o4['id'], 'cancel', ['reason' => '缺货，已原路退款']);
        ok($o4['status'] === 'cancelled', 'a 进货失败 order can be cancelled (nothing was bought)');
        ok(!supply_fulfill($o4['id'], 'retry')['ok'] && shop_order_get($o4['id'])['supply']['trade_no'] === '', 'a cancelled order is never bought');

        // unknown outcome: the trade went through but the answer broke → retry with the same request_no, no second charge
        $o5 = supply_paid_order($p1['slug']);
        mock_knobs(['break_after_trade' => true]);
        $bal = mock_state()['balance'];
        supply_fulfill($o5['id'], 'confirm');
        $o5 = shop_order_get($o5['id']);
        ok($o5['status'] === 'failed' && $o5['supply']['state'] === 'unknown' && str_contains($o5['supply']['msg'], '同一个请求号'), 'broken answer after the purchase: 结果不确定, retry promises the same request number');
        $req = $o5['supply']['request_no'];
        supply_fulfill($o5['id'], 'retry');
        $o5 = shop_order_get($o5['id']);
        ok($o5['status'] === 'completed' && $o5['supply']['request_no'] === $req && $bal - mock_state()['balance'] === 580, 'retry after an unknown outcome reuses the request_no: delivered, charged once');
        // the supplier refuses a repeated request_no → the owner must check; nothing is bought twice by itself
        $o6 = supply_paid_order($p1['slug']);
        mock_knobs(['break_after_trade' => true, 'dup_refuse' => true]);
        supply_fulfill($o6['id'], 'confirm');
        $bal = mock_state()['balance'];
        supply_fulfill($o6['id'], 'retry');
        $o6 = shop_order_get($o6['id']);
        ok($o6['supply']['state'] === 'unclear' && $o6['status'] === 'failed' && mock_state()['balance'] === $bal, 'unknown, then the supplier refuses the same request_no: 需要你核对, no automatic new purchase');
        ok(!supply_fulfill($o6['id'], 'retry')['ok'] && mock_state()['balance'] === $bal, 'plain 重试进货 stays blocked until the owner checks');
        mock_knobs(['dup_refuse' => false]);
        $r = supply_fulfill($o6['id'], 'fresh');
        ok($r['ok'] && shop_order_get($o6['id'])['supply']['request_no'] === supply_request_no($o6['id'], 1), 'after the owner confirms nothing was bought: a new request_no, bought');

        // cost rose
        $o7 = supply_paid_order($p1['slug']);
        mock_knobs(['price_bump' => 100]);   // 5.80 → 6.80 (+17%)
        supply_fulfill($o7['id'], 'confirm');
        $o7 = shop_order_get($o7['id']);
        ok($o7['status'] === 'failed' && $o7['supply']['state'] === 'blocked' && $o7['supply']['cost_now'] === 680 && (mock_state()['trades'][$o7['supply']['request_no']] ?? null) === null, 'cost rose more than 10%: no trade, clear admin warning', $o7['supply']['msg']);
        $r = supply_fulfill($o7['id'], 'accept', 680);
        ok($r['ok'] && shop_order_get($o7['id'])['status'] === 'completed', '按新成本进货 (owner accepted ¥6.80) buys it');
        $o8 = supply_paid_order($p1['slug']);
        mock_knobs(['price_bump' => 300]);   // 8.80 > what the buyer paid (7.00)
        $r = supply_fulfill($o8['id'], 'accept', 880);
        $o8 = shop_order_get($o8['id']);
        ok(!$r['ok'] && $o8['status'] === 'failed' && str_contains($o8['supply']['msg'], '比买家付的'), 'a cost above what the buyer paid is never bought, even when accepted');
        mock_knobs(['price_bump' => 0]);
        shop_owner_action($o8['id'], 'cancel', ['reason' => '涨价了，已退款']);

        // hostile card content from the supplier: shown as text, never as markup
        $ox = supply_paid_order($p1['slug']);
        mock_knobs(['secret_override' => "<script>alert(1)</script>\n<img src=x onerror=alert(2)>\u{202E}KEY-1\x07"]);
        supply_fulfill($ox['id'], 'confirm');
        mock_knobs(['secret_override' => null]);
        $ox = shop_order_get($ox['id']);
        $lk = shop_lookup_result_html($ox, shop_product_get($ox['product_id']), null);
        ok($ox['status'] === 'completed' && str_contains($lk, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($lk, '<script>') && !str_contains($lk, '<img src=x') && !str_contains($ox['supply']['secret'], "\u{202E}") && !str_contains($ox['supply']['secret'], "\x07"), 'hostile card content is stored without control/bidi characters and shown escaped');
        // the order form refreshes the supplier's stock at most every 30 s per product, even when the supplier fails
        $calls0 = count(mock_state()['calls']);
        for ($i = 0; $i < 5; $i++) {
            supply_refresh_product($p1['id'], 0 + 1);
        }
        ok(count(mock_state()['calls']) - $calls0 <= 1, 'public stock refreshes are throttled (one outbound call for five order-form views)', (string) (count(mock_state()['calls']) - $calls0));

        // email contact type
        $o9 = supply_paid_order($p4['slug'], 3, 'Mail.Buyer@Example.com');
        supply_fulfill($o9['id'], 'confirm');
        $ms = mock_state();
        ok($ms['calls'][count($ms['calls']) - 1]['contact'] === 'mail.buyer@example.com' && shop_order_get($o9['id'])['status'] === 'completed', 'contact type 邮箱: the buyer\'s email is sent as contact');

        // 种类
        $o10 = supply_paid_order($p2['slug']);
        supply_fulfill($o10['id'], 'confirm');
        $ms = mock_state();
        ok($ms['calls'][count($ms['calls']) - 1]['race'] === '空月祝福' && shop_order_get($o10['id'])['supply']['amount'] === 2500, 'the 种类 is sent with valuation and trade');

        // manual delivery: pending message, polled (rate-limited) until the card arrives
        $o11 = supply_paid_order($p3['slug']);
        supply_fulfill($o11['id'], 'confirm');
        $o11 = shop_order_get($o11['id']);
        ok($o11['status'] === 'confirmed' && $o11['supply']['pending'] === true && $o11['supply']['trade_no'] !== '', 'manual delivery: bought, card not out yet, order stays 已确认收款，正在发货');
        $q0 = mock_state()['queries'] ?? 0;
        supply_poll($o11['id']);
        supply_poll($o11['id']);
        supply_poll($o11['id']);
        ok((mock_state()['queries'] ?? 0) - $q0 === 1 && shop_order_get($o11['id'])['supply']['pending'], 'polling /query is limited to once a minute per order');
        mock_knobs(['manual_release' => true]);
        $o11x = shop_order_get($o11['id']);
        $o11x['supply']['polled_at'] = gmdate('Y-m-d\TH:i:s\Z', time() - 120);
        shop_mutate(function (array &$pr, array &$orders) use ($o11x) {
            foreach ($orders as $k => $oo) {
                if ($oo['id'] === $o11x['id']) {
                    $orders[$k] = $o11x;
                }
            }
        });

        // buyer lookup over HTTP polls and reveals
        $port = 20000 + random_int(0, 9999);
        $GLOBALS['PORT'] = $port;
        $srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', PUBLIC_DIR, APP_DIR . '/dev/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null,
            ['BLOG_PUBLIC_DIR' => PUBLIC_DIR, 'BLOG_DATA_DIR' => DATA_DIR, 'PATH' => getenv('PATH'), 'BLOG_SUPPLY_TEST_LOOPBACK' => '1']);
        for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
            usleep(100000);
        }
        try {
            clear_rl();
            $r = shop_post('lookup', ['o' => $o11['id'], 'v' => 'gamer']);
            ok($r['status'] === 200 && str_contains($r['body'], 'MANUAL-') && shop_order_get($o11['id'])['status'] === 'completed', 'buyer lookup asks /query again and shows the card when it arrived');
            $r = shop_post('lookup', ['o' => $o['id'], 'v' => 'early']);
            ok($r['status'] === 200 && str_contains($r['body'], 'card-secret') && str_contains($r['body'], 'CARD-') && str_contains($r['body'], '已发卡，可以查看卡密'), 'buyer lookup reveals the card content like digital delivery');
            ok(!str_contains($r['body'], $o['supply']['trade_no'] . '<') && !str_contains($r['body'], 'PB' . str_replace('-', '', $o['id'])) && !str_contains($r['body'], 'WZRY60'), 'lookup shows no supplier order number, request number or code');
            $r = http('GET', '/api/shop.php?a=order&p=' . $p1['slug']);
            ok($r['status'] === 200 && str_contains($r['body'], '确认收款') && !preg_match('/<script/i', $r['body']), 'order form for a card renders (email, no script)');
            $cid = shop_orders()[0]['id'];
            $r = shop_post('order', ['p' => $p1['slug'], 'qty' => '1', 'email' => 'web@example.com', 'consent' => '1', 'price' => '0.01']);
            ok($r['status'] === 303 && preg_match('/o=(\d{8}-[A-Z2-9]{6})/', $r['headers']['location'] ?? '', $mm) === 1 && shop_order_get($mm[1])['amount'] > 700 && shop_order_get($mm[1])['status'] === 'pending', 'buyer order over HTTP: server price, pending, nothing bought', $r['status'] . '');
            ok((mock_state()['trades'][supply_request_no($mm[1] ?? '', 0)] ?? null) === null, 'creating or viewing an order never trades');
            // admin pages
            $pw = '云底下的跑道灯2026春';
            json_write_atomic(data_path('admin.json'), ['version' => 1, 'password_hash' => pw_hash($pw), 'created_at' => now_iso(), 'password_changed_at' => now_iso(), 'session_epoch' => 1]);
            @unlink(DATA_DIR . '/setup-code.txt');
            $r = http('GET', '/admin/?r=login', ['headers' => ['Accept: application/json']]);
            $r = http('POST', '/admin/?r=login', ['form' => ['_csrf' => (string) ($r['json']['csrf'] ?? ''), 'password' => $pw]]);
            preg_match('/pb_admin=([^;]+)/', $r['headers']['set-cookie'] ?? '', $m);
            $cookie = 'Cookie: pb_admin=' . ($m[1] ?? '');
            $r = http('GET', '/admin/?r=supply', ['headers' => [$cookie]]);
            $b = $r['body'];
            ok($r['status'] === 200 && str_contains($b, '<h1 class="page-h">货源</h1>') && str_contains($b, '王者荣耀 60 点券（自动发卡）') && str_contains($b, '45.152.64.62'), 'admin 货源 page lists the supplier\'s products and the whitelist IP');
            ok(!str_contains($b, MOCK_APP_KEY) && !str_contains($b, substr(MOCK_APP_KEY, 0, 8)) && str_contains($b, substr(MOCK_APP_KEY, -4)), 'the key is never in the page (only its last 4)');
            ok(!str_contains($b, '<img src=x') && !str_contains($b, '<script>alert') && str_contains($b, '&lt;img src=x'), 'supplier names are escaped in the admin');
            $csrf = preg_match('/<meta name="csrf" content="([0-9a-f]+)"/', $b, $mm2) ? $mm2[1] : '';
            $r = http('GET', '/admin/?r=supply.item&code=XSSNAME', ['headers' => [$cookie]]);
            ok($r['status'] === 200 && !str_contains($r['body'], '<img src=x') && !str_contains($r['body'], '<iframe') && !str_contains($r['body'], '<a href="javascript'), 'supply item page escapes supplier HTML');
            $r = http('GET', '/admin/?r=supply.item&code=SKU01', ['headers' => [$cookie]]);
            ok(str_contains($r['body'], '暂不支持') && str_contains($r['body'], '规格') && !str_contains($r['body'], 'supply.import'), '暂不支持 page shows the reason and no import form');
            $r = http('GET', '/admin/?r=supply.item&code=..%2F..%2Fetc', ['headers' => [$cookie]]);
            ok($r['status'] === 404, 'odd product code: 404');
            $rev = (string) settings_get()['rev'];
            $r = http('POST', '/admin/?r=supply.save', ['form' => ['_csrf' => $csrf, 'rev' => $rev, 'part' => 'conn', 'base_url' => 'https://169.254.169.254', 'app_id' => 'x', 'app_key' => ''], 'headers' => [$cookie]]);
            ok($r['status'] === 400 && str_contains($r['body'], '内网') && settings_get()['supply']['base_url'] === $base, 'admin refuses an internal supplier address (cloud metadata)');
            $r = http('POST', '/admin/?r=supply.test', ['form' => ['_csrf' => $csrf], 'headers' => [$cookie]]);
            $r = http('GET', '/admin/?r=supply', ['headers' => [$cookie]]);
            ok(str_contains($r['body'], '连接正常'), '测试连接 button: plain Chinese result');
            $r = http('POST', '/admin/?r=order.act', ['form' => ['_csrf' => $csrf, 'id' => $o3['id'], 'act' => 'retry'], 'headers' => [$cookie]]);
            ok(in_array($r['status'], [302, 303], true) && mock_state()['trade_calls'] > 0, 'admin 重试进货 route works');
            $r = http('GET', '/admin/?r=order&id=' . $o['id'], ['headers' => [$cookie]]);
            ok(str_contains($r['body'], '进货（买家看不到这里）') && str_contains($r['body'], 'CARD-'), 'admin order page shows the purchase and the card');
            $r = http('GET', '/admin/?r=dashboard', ['headers' => [$cookie]]);
            ok(str_contains($r['body'], '进货失败') && str_contains($r['body'], '余额'), '概览 shows 进货失败 and the balance reminder');
            // double confirm through HTTP
            $o12 = shop_create_order($p1['slug'], shop_order_input(shop_product_get($p1['id']), ['qty' => '1', 'email' => 'twice@example.com', 'consent' => '1'])[0]);
            $tc = mock_state()['trade_calls'];
            $mh = curl_multi_init();
            $hs = [];
            for ($i = 0; $i < 4; $i++) {
                $h = curl_init('http://127.0.0.1:' . $port . '/admin/?r=order.act');
                curl_setopt_array($h, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['_csrf' => $csrf, 'id' => $o12['id'], 'act' => 'confirm']), CURLOPT_HTTPHEADER => [$cookie], CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '']);
                curl_multi_add_handle($mh, $h);
                $hs[] = $h;
            }
            do {
                curl_multi_exec($mh, $run);
                curl_multi_select($mh, 0.1);
            } while ($run > 0);
            ok(mock_state()['trade_calls'] - $tc === 1 && shop_order_get($o12['id'])['status'] === 'completed', 'four 确认收款 clicks at once: one purchase', (string) (mock_state()['trade_calls'] - $tc));
        } finally {
            proc_terminate($srv);
            proc_close($srv);
        }

        // nothing secret in logs, stats or the CSV
        $all = '';
        foreach (array_merge(glob(DATA_DIR . '/log/*') ?: [], glob(DATA_DIR . '/stats/*') ?: [], glob(DATA_DIR . '/usage/*') ?: [], glob(DATA_DIR . '/supply/cache/*') ?: []) as $f) {
            $all .= (string) file_get_contents($f);
        }
        $csv = shop_csv(shop_orders());
        ok(!str_contains($all . $csv, 'CARD-') && !str_contains($all . $csv, 'MANUAL-') && !str_contains($all, MOCK_APP_KEY), 'card content and the key are never in logs, stats, cache or the CSV');
        ok(!str_contains(json_encode(settings_get()), MOCK_APP_KEY), 'the key is not in settings');
        // redaction erases the card
        shop_maintain(time() + 200 * 86400);
        $red = shop_order_get($o['id']);
        ok($red['redacted_at'] !== null && $red['supply']['secret'] === '' && !str_contains((string) file_get_contents(shop_orders_path()), 'CARD-' . substr($o['supply']['secret'], 5, 4)), '180 days after closing the card content is erased with the buyer data');
        // supply switched off: products leave the shop
        settings_save(['supply' => ['enabled' => false]], settings_get()['rev']);
        ok(!str_contains(pub('index.html'), 'shop/' . $p1['slug'] . '.html') && !is_file(PUBLIC_DIR . '/shop/' . $p1['slug'] . '.html') && throws(fn() => supply_paid_order($p1['slug'])), '货源 off: its products leave the home grid and cannot be ordered');
    } finally {
        proc_terminate($mock);
        proc_close($mock);
    }
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
    foreach (array_merge($views, [PUBLIC_DIR . '/admin/assets/admin.js', APP_DIR . '/lib/admin.php', APP_DIR . '/lib/security.php', APP_DIR . '/lib/view.php',
        APP_DIR . '/lib/admin_shop.php', APP_DIR . '/lib/shop.php', APP_DIR . '/lib/shop_cats.php', APP_DIR . '/lib/supply.php', APP_DIR . '/lib/admin_supply.php', APP_DIR . '/lib/admin_biz.php', APP_DIR . '/lib/biz.php', APP_DIR . '/lib/referral.php', APP_DIR . '/lib/shop_pages.php', APP_DIR . '/lib/doubao.php', APP_DIR . '/lib/settings.php', APP_DIR . '/lib/game.php', PUBLIC_DIR . '/api/game.php', PUBLIC_DIR . '/api/chat.php', PUBLIC_DIR . '/api/shop.php', APP_DIR . '/templates/shop.tpl.html']) as $f) {
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

// ---------- 豆包 (火山方舟) provider: settings, keys, api/chat.php and 测试连接 against a local mock Ark ----------

function ark_state(): array
{
    return json_decode((string) file_get_contents($GLOBALS['ARK_STATE']), true) ?: [];
}

function ark_knobs(array $k): void
{
    $s = ark_state();
    $s['knobs'] = $k;
    $s['requests'] = [];
    file_put_contents($GLOBALS['ARK_STATE'], json_encode($s));
}

function group_doubao(): void
{
    app_init('cli');
    publish_site('初始内容');
    $tmp = (string) getenv('TEST_TMP');

    // settings: old data has no provider (= anthropic), model rules
    json_write_atomic(data_path('settings.json'), ['rev' => 3, 'chat' => ['enabled' => false, 'daily_limit' => 300, 'ip_limit' => 20, 'ip_window_min' => 10, 'extra_note' => '']]);
    $c = settings_get()['chat'];
    ok($c['provider'] === 'anthropic' && $c['model'] === '', 'settings saved before 豆包 read as provider anthropic, empty model');
    json_write_atomic(data_path('settings.json'), ['rev' => 3, 'chat' => ['provider' => 'openai', 'model' => 7]]);
    ok(settings_get()['chat']['provider'] === 'anthropic' && settings_get()['chat']['model'] === '', 'unknown provider or non-string model normalized away');
    $save = fn(array $chat) => settings_save(['chat' => $chat], settings_get()['rev']);
    foreach (['doubao-lite-32k', 'ep-20250101123456-abcde', 'doubao-1.5-lite-32k-250115', 'a', 'x:y_z.1', str_repeat('m', 100)] as $m) {
        ok(!throws(fn() => $save(['provider' => 'doubao', 'model' => $m])) && settings_get()['chat']['model'] === $m, "model accepted: $m");
    }
    foreach (['', 'a b', 'x/y', '模型', str_repeat('m', 101), "ep-1\nx", 'ep-<x>', 'https://evil.example/v1'] as $m) {
        $e = null;
        try {
            $save(['provider' => 'doubao', 'model' => $m]);
        } catch (ValidationError $ex) {
            $e = $ex->fields['chat.model'] ?? null;
        }
        ok(is_string($e) && !str_contains($e, "\u{2014}"), 'model refused in plain Chinese: ' . json_encode($m), (string) $e);
    }
    ok(!throws(fn() => $save(['provider' => 'anthropic', 'model' => ''])), 'anthropic needs no model');

    // keys: write-only, separate, kept across provider switches
    $akey = 'sk-ant-test' . str_repeat('k', 30) . 'WXYZ';
    $dkey = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3bQRST';
    ok(throws(fn() => doubao_key_set('short'), ValidationError::class) && throws(fn() => doubao_key_set('has space in it 1234567'), ValidationError::class) && throws(fn() => doubao_key_set("abcdefghijklmnop\n"), ValidationError::class) === false, 'malformed 豆包 keys refused (trailing newline trimmed)');
    doubao_key_remove();
    chat_key_set($akey);
    doubao_key_set($dkey);
    $sec = secrets_get();
    ok(($sec['anthropic_api_key'] ?? '') === $akey && ($sec['doubao_api_key'] ?? '') === $dkey && isset($sec['doubao_key_set_at']), 'both keys stored side by side in secrets.json');
    ok(doubao_key_hint() === 'QRST' && chat_key_hint() === 'WXYZ', 'hints are the last 4 characters of each key');
    ok(chat_key() === $akey, 'provider anthropic uses the Anthropic key');
    $save(['provider' => 'doubao', 'model' => 'doubao-lite-32k']);
    ok(chat_key() === $dkey && secrets_get()['anthropic_api_key'] === $akey, 'switch to 豆包: uses the 豆包 key, Anthropic key kept');
    $save(['provider' => 'anthropic']);
    ok(chat_key() === $akey && secrets_get()['doubao_api_key'] === $dkey, 'switch back: Anthropic key used, 豆包 key kept');
    ok((fileperms(DATA_DIR . '/secrets.json') & 0777) === 0600, 'secrets.json stays 0600');
    $save(['enabled' => true]);
    ok(str_contains(pub('index.html'), 'const CHAT_LIVE = true;'), 'anthropic + key + on: CHAT_LIVE true');
    doubao_key_remove();
    $res = $save(['provider' => 'doubao', 'model' => 'doubao-lite-32k']);
    ok($res['publish'] !== null && str_contains(pub('index.html'), 'const CHAT_LIVE = false;') && chat_key() === null, 'switch to 豆包 without its key republishes CHAT_LIVE false (not configured)');
    $res = doubao_key_set($dkey);
    ok($res !== null && str_contains(pub('index.html'), 'const CHAT_LIVE = true;'), 'saving the 豆包 key flips CHAT_LIVE back to true');

    // HTTP: the mock Ark and the site, with the test-only base override
    $GLOBALS['ARK_STATE'] = $tmp . '/ark-state.json';
    file_put_contents($GLOBALS['ARK_STATE'], '{}');
    $arkPort = 30000 + random_int(0, 9999);
    $ark = proc_open([PHP_BINARY, '-S', "127.0.0.1:$arkPort", __DIR__ . '/ark-mock.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp, null, ['ARK_STATE' => $GLOBALS['ARK_STATE'], 'PATH' => getenv('PATH')]);
    $port = 20000 + random_int(0, 9999);
    $GLOBALS['PORT'] = $port;
    $srvLog = $tmp . '/php-server.log';
    $env = ['BLOG_PUBLIC_DIR' => PUBLIC_DIR, 'BLOG_DATA_DIR' => DATA_DIR, 'BLOG_CHAT_FAKE' => '1', 'BLOG_ARK_TEST_BASE' => "http://127.0.0.1:$arkPort", 'PATH' => getenv('PATH')];
    $srv = proc_open([PHP_BINARY, '-d', 'log_errors=1', '-d', 'error_log=' . $srvLog, '-S', "127.0.0.1:$port", '-t', PUBLIC_DIR, APP_DIR . '/dev/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', $srvLog, 'a']], $pipes, null, $env);
    for ($i = 0; $i < 50 && (@fsockopen('127.0.0.1', $port) === false || @fsockopen('127.0.0.1', $arkPort) === false); $i++) {
        usleep(100000);
    }
    try {
        doubao_http_tests($akey, $dkey, $ark, $srvLog);
    } finally {
        proc_terminate($srv);
        proc_close($srv);
        if (is_resource($ark)) {
            proc_terminate($ark);
            proc_close($ark);
        }
    }
    ok(DOUBAO_BASE === 'https://ark.cn-beijing.volces.com/api/v3' && doubao_base() === DOUBAO_BASE, 'outside php -S with the test flag the base is the fixed Ark URL');
    $src = (string) file_get_contents(APP_DIR . '/lib/doubao.php');
    ok(str_contains($src, "PHP_SAPI === 'cli-server' && getenv('BLOG_CHAT_FAKE') === '1'") && str_contains($src, '127\.0\.0\.1'), 'the base override needs php -S, BLOG_CHAT_FAKE=1 and a loopback URL');
}

function doubao_http_tests(string $akey, string $dkey, $ark, string $srvLog): void
{
    $q = '豆包测试问题甲乙丙是什么？';
    $msg = ['json' => ['messages' => [['role' => 'user', 'content' => $q], ['role' => 'assistant', 'content' => '上一条回答'], ['role' => 'user', 'content' => '再说说复飞测试丁戊']]]];
    $chat = fn() => http('POST', '/api/chat.php', $msg);
    settings_save(['chat' => ['extra_note' => '站长备注：最近在改装备。']], settings_get()['rev']);
    clear_rl();
    ark_knobs([]);
    $before = usage_today()['chat'] ?? [];
    $r = $chat();
    ok($r['status'] === 200 && ($r['json']['reply'] ?? '') === '豆包模拟回答：决断高度是进近中必须决定复飞或继续着陆的高度。', '豆包 answer: reply is choices[0].message.content (trimmed)', $r['body']);
    $req = ark_state()['requests'][0] ?? [];
    $b = $req['body'] ?? [];
    ok(($req['method'] ?? '') === 'POST' && ($req['path'] ?? '') === '/chat/completions' && str_contains($req['ctype'] ?? '', 'application/json'), 'POST {base}/chat/completions with JSON');
    ok(($req['auth'] ?? '') === 'Bearer ' . $dkey, 'Authorization: Bearer <豆包 key> (not the Anthropic key)');
    ok(($b['model'] ?? '') === 'doubao-lite-32k' && ($b['stream'] ?? null) === false && (float) ($b['temperature'] ?? 0) === 0.7 && ($b['max_tokens'] ?? 0) === 1024, 'body: model, stream false, temperature 0.7, max_tokens 1024', json_encode(array_diff_key($b, ['messages' => 1])));
    $m = $b['messages'] ?? [];
    ok(count($m) === 4 && $m[0]['role'] === 'system' && str_starts_with($m[0]['content'], '你是一位机长个人博客上的店铺客服') && str_contains($m[0]['content'], "站长补充说明（博客作者填写，仅供参考）：\n站长备注：最近在改装备。"), 'messages[0] is the system prompt plus the owner note');
    ok(array_map(fn($x) => $x['role'] . ':' . $x['content'], array_slice($m, 1)) === ['user:' . $q, 'assistant:上一条回答', 'user:再说说复飞测试丁戊'], 'history follows in order');
    $u = usage_today()['chat'] ?? [];
    ok(($u['answered'] ?? 0) === ($before['answered'] ?? 0) + 1 && ($u['tokens_in'] ?? 0) === ($before['tokens_in'] ?? 0) + 77 && ($u['tokens_out'] ?? 0) === ($before['tokens_out'] ?? 0) + 33, 'usage: answered + prompt_tokens / completion_tokens', json_encode($u));

    // error mapping: visitor message, usage counter, owner reason
    $cases = [
        [['mode' => 'status', 'status' => 401], 503, 'not_configured', 'not_configured', 'API Key 不对'],
        [['mode' => 'status', 'status' => 403], 503, 'not_configured', 'not_configured', '还没在火山方舟「开通管理」里开通'],
        [['mode' => 'status', 'status' => 404], 502, 'upstream_error', 'upstream_error', '找不到这个模型或接入点'],
        [['mode' => 'status', 'status' => 400], 400, 'bad_request', 'bad_request', '模型名称填错'],
        [['mode' => 'status', 'status' => 429], 429, 'upstream_busy', 'upstream_busy', '限流或额度用完'],
        [['mode' => 'status', 'status' => 500], 502, 'upstream_error', 'upstream_error', '豆包那边出错了（500）'],
        [['mode' => 'status', 'status' => 503], 502, 'upstream_error', 'upstream_error', '豆包那边出错了（503）'],
        [['mode' => 'bad_json'], 502, 'upstream_error', 'upstream_error', '看不懂'],
        [['mode' => 'huge'], 502, 'upstream_error', 'upstream_error', '太大'],
    ];
    $visitor = ['not_configured' => '助手还在接入中，这个问题暂时没有回答。接通以后，在这里直接问就行。', 'upstream_error' => '助手暂时连不上，稍后再试。', 'bad_request' => '这条消息发不出去，换个说法试试。', 'upstream_busy' => '助手有点忙，稍等一会儿再问。'];
    foreach ($cases as [$knobs, $st, $err, $counter, $reason]) {
        clear_rl();
        ark_knobs($knobs);
        $n = (int) ((usage_today()['chat']['errors'][$counter] ?? 0));
        $r = $chat();
        $label = json_encode($knobs);
        ok($r['status'] === $st && ($r['json']['error'] ?? '') === $err && ($r['json']['message'] ?? '') === $visitor[$err], "Ark $label: $st $err with the usual visitor message", $r['body']);
        $u = usage_today()['chat'];
        ok((int) $u['errors'][$counter] === $n + 1 && str_contains((string) ($u['last_error']['reason'] ?? ''), $reason) && ($u['last_error']['provider'] ?? '') === 'doubao', "Ark $label: counted, owner reason recorded", json_encode($u['last_error'] ?? null, JSON_UNESCAPED_UNICODE));
        ok(!str_contains($r['body'], 'mock error') && !str_contains($r['body'], 'MockError'), "Ark $label: upstream text never reaches the visitor");
    }
    clear_rl();
    ark_knobs(['mode' => 'filter']);
    $r = $chat();
    ok($r['status'] === 200 && str_contains($r['json']['reply'] ?? '', '这个问题我没法回答') && (usage_today()['chat']['refused'] ?? 0) >= 1, 'finish_reason content_filter: the refusal answer, counted as refused');
    clear_rl();
    ark_knobs(['mode' => 'empty']);
    $r = $chat();
    ok($r['status'] === 502 && ($r['json']['error'] ?? '') === 'empty_reply', 'blank content: empty_reply 502');

    // admin page and 测试连接 (logged in through the real login)
    $pw = '云底下的跑道灯2026春';
    json_write_atomic(data_path('admin.json'), ['version' => 1, 'password_hash' => pw_hash($pw), 'created_at' => now_iso(), 'password_changed_at' => now_iso(), 'session_epoch' => 1]);
    @unlink(DATA_DIR . '/setup-code.txt');
    $r = http('GET', '/admin/?r=login', ['headers' => ['Accept: application/json']]);
    $r = http('POST', '/admin/?r=login', ['form' => ['_csrf' => (string) ($r['json']['csrf'] ?? ''), 'password' => $pw]]);
    preg_match('/pb_admin=([^;]+)/', $r['headers']['set-cookie'] ?? '', $mm);
    $cookie = 'Cookie: pb_admin=' . ($mm[1] ?? '');
    $r = http('GET', '/admin/?r=ai', ['headers' => [$cookie]]);
    $h = $r['body'];
    $csrf = preg_match('/<meta name="csrf" content="([0-9a-f]+)"/', $h, $mm) ? $mm[1] : '';
    ok($r['status'] === 200 && str_contains($h, '<option value="doubao" selected>豆包（火山引擎），推荐</option>') && str_contains($h, '香港服务器上通常用不了') && str_contains($h, 'value="doubao-lite-32k"'), 'AI page: provider select (豆包 recommended, Claude flagged) and the model field', (string) $r['status']);
    ok(str_contains($h, '「开通管理」开通模型（例如 Doubao 轻量版 / lite）') && str_contains($h, '接入点 ID（ep- 开头）填这里'), 'AI page: the 开通管理 / 接入点 hint');
    ok(str_contains($h, '结尾是 <b class="mono">QRST</b>') && str_contains($h, '结尾是 <b class="mono">WXYZ</b>') && preg_match('/<input id="dbkey" name="dbkey" type="password"[^>]* value="">/', $h) === 1, 'AI page: both keys shown by their last 4 only, inputs empty');
    ok(preg_match('#<p class="where" id="ai-last-err">最近一次出错（[^）]+）：豆包#u', $h) === 1, 'AI page: shows the latest owner-facing reason');
    $leak = fn(string $s) => str_contains($s, $dkey) || str_contains($s, substr($dkey, 0, 16)) || str_contains($s, $akey) || str_contains($s, substr($akey, 0, 20));
    ok(!$leak($h), 'AI page HTML holds neither key');
    $t = fn() => http('POST', '/admin/?r=ai.test', ['headers' => [$cookie, 'X-CSRF-Token: ' . $csrf, 'Accept: application/json', 'Content-Type: application/json'], 'body' => '{}']);
    $r = http('POST', '/admin/?r=ai.test', ['headers' => [$cookie, 'Accept: application/json', 'Content-Type: application/json'], 'body' => '{}']);
    ok($r['status'] === 403 && ($r['json']['ok'] ?? null) !== true, '测试连接 without the CSRF token is refused', (string) $r['status']);
    ark_knobs([]);
    $r = $t();
    $req = ark_state()['requests'][0] ?? [];
    ok(($r['json']['ok'] ?? null) === true && ($r['json']['message'] ?? '') === '连接正常，豆包可以用。' && ($req['auth'] ?? '') === 'Bearer ' . $dkey && ($req['body']['model'] ?? '') === 'doubao-lite-32k' && count($req['body']['messages'] ?? []) === 1, '测试连接 sends a tiny prompt to the chosen 豆包 model and says OK', $r['body']);
    ark_knobs(['mode' => 'status', 'status' => 404]);
    $r = $t();
    ok(($r['json']['ok'] ?? null) === false && str_contains($r['json']['message'] ?? '', '找不到这个模型或接入点') && !$leak($r['body']), '测试连接 404: plain Chinese reason', $r['body']);
    ark_knobs(['mode' => 'status', 'status' => 401]);
    $r = $t();
    ok(($r['json']['ok'] ?? null) === false && str_contains($r['json']['message'] ?? '', 'API Key 不对'), '测试连接 401: bad key or model not enabled');
    // provider switch through the admin form keeps both keys
    $r = http('POST', '/admin/?r=ai.save', ['form' => ['_csrf' => $csrf, 'rev' => (string) settings_get()['rev'], 'part' => 'provider', 'provider' => 'doubao', 'model' => 'ep bad id'], 'headers' => [$cookie]]);
    ok(settings_get()['chat']['model'] === 'doubao-lite-32k', 'admin: a bad model id is not saved');
    $r = http('POST', '/admin/?r=ai.save', ['form' => ['_csrf' => $csrf, 'rev' => (string) settings_get()['rev'], 'part' => 'provider', 'provider' => 'anthropic', 'model' => 'ep-20250101123456-abcde'], 'headers' => [$cookie]]);
    $s = secrets_get();
    ok(in_array($r['status'], [302, 303], true) && settings_get()['chat']['provider'] === 'anthropic' && settings_get()['chat']['model'] === 'ep-20250101123456-abcde' && $s['doubao_api_key'] === $dkey && $s['anthropic_api_key'] === $akey, 'admin: switching to Claude keeps both keys and the model', (string) $r['status']);
    ark_knobs([]);
    $r = $t();
    ok(($r['json']['ok'] ?? null) === true && ark_state()['requests'] === [], '测试连接 follows the chosen provider (Claude: fake hook, Ark not called)', $r['body']);
    http('POST', '/admin/?r=ai.save', ['form' => ['_csrf' => $csrf, 'rev' => (string) settings_get()['rev'], 'part' => 'provider', 'provider' => 'doubao', 'model' => 'doubao-lite-32k'], 'headers' => [$cookie]]);
    $r = http('POST', '/admin/?r=ai.dbkey', ['form' => ['_csrf' => $csrf, 'dbkey' => 'bad key'], 'headers' => [$cookie]]);
    ok(secrets_get()['doubao_api_key'] === $dkey, 'admin: a malformed 豆包 key is not saved');
    $new = 'b2c3d4e5-f6a7-4b8c-9d0e-1f2a3b4cZZ99';
    http('POST', '/admin/?r=ai.dbkey', ['form' => ['_csrf' => $csrf, 'dbkey' => $new], 'headers' => [$cookie]]);
    $r = http('GET', '/admin/?r=ai', ['headers' => [$cookie]]);
    ok(secrets_get()['doubao_api_key'] === $new && str_contains($r['body'], '结尾是 <b class="mono">ZZ99</b>') && !str_contains($r['body'], $new) && str_contains($r['body'], '豆包 API Key 已保存，结尾是 ZZ99'), 'admin: new 豆包 key saved, page and flash show the last 4 only');
    $r = http('POST', '/admin/?r=ai.dbkey_remove', ['form' => ['_csrf' => $csrf], 'headers' => [$cookie]]);
    ok(!isset(secrets_get()['doubao_api_key']) && secrets_get()['anthropic_api_key'] === $akey, 'admin: 删除 API Key removes only the 豆包 key');
    clear_rl();
    ark_knobs([]);
    $r = $chat();
    ok($r['status'] === 503 && ($r['json']['message'] ?? '') === '助手还在接入中，这个问题暂时没有回答。接通以后，在这里直接问就行。' && ark_state()['requests'] === [], 'provider 豆包 without its key: the not-configured 503, even with an Anthropic key', $r['body']);
    doubao_key_set($dkey);

    // network failure: the mock is gone
    proc_terminate($ark);
    proc_close($ark);
    usleep(200000);
    clear_rl();
    $r = $chat();
    ok($r['status'] === 502 && ($r['json']['error'] ?? '') === 'upstream_unreachable' && str_contains((string) (usage_today()['chat']['last_error']['reason'] ?? ''), '连不上豆包'), 'Ark unreachable: upstream_unreachable 502, reason 连不上豆包', $r['body']);

    // nothing secret or private in logs or data
    $all = (string) @file_get_contents($srvLog);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(DATA_DIR, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile() && $f->getFilename() !== 'secrets.json') {
            $all .= file_get_contents($f->getPathname());
        }
    }
    ok(str_contains((string) @file_get_contents($srvLog), 'chat.php doubao'), 'server error log has the doubao failure lines');
    $hits = array_values(array_filter([$dkey, $new, $akey, '豆包测试问题甲乙丙', '再说说复飞测试丁戊', '豆包模拟回答'], fn($x) => str_contains($all, $x)));
    ok(!$hits, 'no log or data file (except secrets.json) holds a key or a question', implode(' ', $hits));
    foreach (glob(PUBLIC_DIR . '/{,posts/,shop/}*.html', GLOB_BRACE) as $f) {
        if ($leak((string) file_get_contents($f))) {
            ok(false, 'public page holds a key: ' . basename($f));
        }
    }
}

require __DIR__ . '/biz-ref-tests.php';   // groups biz (经营总览) and ref (推广链接)
require __DIR__ . '/game-tests.php';      // group game (盲降挑战 leaderboard)

$fn = 'group_' . $group;
try {
    $fn();
} catch (Throwable $e) {
    ok(false, 'group crashed: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
