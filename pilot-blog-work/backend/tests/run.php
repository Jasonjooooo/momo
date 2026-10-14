<?php
// Backend test runner (never deployed). Each group runs in its own PHP process with fresh temp
// BLOG_PUBLIC_DIR / BLOG_DATA_DIR, so nothing touches pilot-blog/index.html, posts/, uploads/ or blog-data/.
// Usage: php pilot-blog-work/backend/tests/run.php [group ...]
declare(strict_types=1);

const REPO = __DIR__ . '/../../..';
const GROUPS = ['template', 'markdown', 'publish', 'images', 'subscribers', 'http', 'admin'];

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
    ok(hash('sha256', $baseline) === BASELINE_SHA256, 'baseline-index.html is the 08da7c1 page');
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
    preg_match('/<script type="application\/json" id="shelf-data">\n(.*?)\n<\/script>/s', pub('index.html'), $m);
    $j = json_decode($m[1] ?? '', true);
    ok(($j['shelves'][0]['title'] ?? '') === '新货架' && str_contains(pub('index.html'), '<span class="nm" id="f-act-n">A1 新货架</span>'), 'shelf publish: JSON valid, radio shows the new shelf');
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
    // public copies are re-synced from the masters
    unlink(PUBLIC_DIR . '/' . $r['src']);
    ok(uploads_sync_public() >= 1 && is_file(PUBLIC_DIR . '/' . $r['src']), 'uploads_sync_public restores a missing public copy');
    // a cover makes the post page and og image use it
    $p = make_post('有封面的日志', '正文。', '2026-10-02', ['cover' => ['src' => $r2['src'], 'og' => $r2['og'], 'w' => $r2['w'], 'h' => $r2['h'], 'alt' => '云下的跑道']]);
    $p = post_publish($p['id'], $p['rev'])['post'];
    $page = pub('posts/' . $p['slug'] . '.html');
    ok(str_contains($page, '<img src="../' . $r2['src'] . '" width="2000" height="1000" alt="云下的跑道" loading="eager"') && str_contains($page, $r2['og'] . '">'), 'cover image and og:image on the post page');
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
