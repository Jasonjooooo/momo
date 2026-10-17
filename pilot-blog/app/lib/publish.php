<?php
// Publisher (SPEC §6, §7.6): renders index.html, post pages and posts/index.html from live snapshots only,
// writes them atomically, keeps the last 10 versions and rolls back.
declare(strict_types=1);
defined('BLOG_APP') || exit;

const VERSION_ID_RE = '/^\d{8}-\d{6}-[0-9a-f]{6}$/';
const VERSIONS_KEEP = 10;

function site_live(): array
{
    // live content saved before a template gained new keys (or restored from an older version) gets
    // them filled on read, like the draft does, so publish and rollback never fail on a missing key
    $d = json_read(data_path('content', 'site.live.json'), []);
    if (is_array($d) && is_array($d['fields'] ?? null)) {
        $d['fields'] = site_upgrade_fields($d['fields']);
    }
    return is_array($d) ? $d : [];
}

function shelf_live(): array
{
    return json_read(data_path('content', 'shelf.live.json'), []);
}

function build_home_ctx(array $site, array $shelf, array $posts, array $settings, bool $chatKey, array $products = []): array
{
    $ctx = [
        'fields' => site_upgrade_fields($site['fields']),
        'faq' => $site['faq'],
        'type_facts' => $site['type_facts'],
        'album' => site_album($site),
        'shelves' => $shelf['shelves'],
        'legs' => home_legs($posts, (int) $settings['home']['leg_limit']),
        'subscribe_live' => (bool) $settings['subscribe']['enabled'],
        'chat_live' => (bool) $settings['chat']['enabled'] && $chatKey,
        'stats_live' => stats_enabled($settings),
        'site_published_at' => (string) ($site['published_at'] ?? $site['updated_at'] ?? now_iso()),
        'extra_css' => (string) file_get_contents(app_path('templates', 'home-extra.css')),
        'shop' => [
            'open' => (bool) $settings['shop']['enabled'],
            'notice' => (string) $settings['shop']['notice'],
            'products' => array_values(array_filter($products, fn($p) => $p['on_sale'] && $p['photos'])),
        ],
    ];
    $base = $ctx;
    foreach (['chat_live', 'subscribe_endpoint', 'log_count'] as $v) {
        $ctx['values'][$v] = value_render($v, $base);
    }
    foreach (['subscribe_live', 'has_teasers', 'has_published', 'origin_placeholder', 'stats_live', 'has_album'] as $c) {
        $ctx['conds'][$c] = cond_eval($c, $base);
    }
    foreach (['legs', 'faq_rows', 'type_facts', 'extra_css', 'about_album', 'shop_status', 'shop_grid'] as $b) {
        $ctx['blocks'][$b] = fn(string $indent) => block_render($b, $base, $indent);
    }
    return $ctx;
}

function render_home(array $ctx): string
{
    return tpl_render(tpl_load('home'), $ctx);
}

function post_css_href(): string
{
    $css = (string) @file_get_contents(PUBLIC_DIR . '/assets/post.css');
    return '../assets/post.css?v=' . substr(hash('sha256', $css), 0, 8);
}

function dots(?string $date): string
{
    return str_replace('-', '.', (string) $date);
}

/** Shared context for post and archive pages. */
function page_base_ctx(array $site, array $settings): array
{
    return [
        'fields' => site_upgrade_fields($site['fields']),
        'values' => ['css_href' => enc_attr(post_css_href())],
        'conds' => ['subscribe_live' => (bool) $settings['subscribe']['enabled'], 'stats_live' => stats_enabled($settings)],
        'blocks' => [],
        'origin' => (string) $site['fields']['site.origin'],
    ];
}

/** $live: the post's live snapshot (or a draft dressed as one for previews). */
function render_post_page(array $post, array $ctx, ?array $older, ?array $newer, array $mdOpt = []): string
{
    $l = $post['live'];
    $f = $ctx['fields'];
    $minutes = post_minutes_live($post);
    $excerpt = post_excerpt_live($post);
    $cover = $l['cover'] ?? null;
    $origin = rtrim($ctx['origin'], '/');
    $ogImage = $cover ? $origin . '/' . $cover['og'] : $origin . '/assets/og.jpg';
    $ctx['values'] += [
        'post_title' => enc_text($l['title']),
        'post_title_attr' => enc_attr($l['title']),
        'post_excerpt' => enc_text($excerpt),
        'post_excerpt_attr' => enc_attr($excerpt),
        'post_date_iso' => enc_attr((string) $l['date']),
        'post_date_dots' => enc_text(dots($l['date'])),
        'post_fix' => enc_text(post_live_fix($post)),
        'post_minutes' => enc_text(pattern_fill($f['log.ete_published'], ['{n}' => (string) $minutes])),
        'post_slug' => enc_attr($post['slug']),
        'post_og_image' => enc_attr($ogImage),
        'post_url' => enc_attr($origin . '/posts/' . $post['slug'] . '.html'),
        'hit_path' => enc_attr(rawurlencode('/posts/' . $post['slug'] . '.html')),
    ];
    $tags = $l['tags'] ?? [];
    $ctx['conds'] += [
        'has_cover' => $cover !== null,
        'has_tags' => count($tags) > 0,
        'has_excerpt' => ($l['excerpt'] ?? '') !== '',
        'has_prevnext' => $older !== null || $newer !== null,
    ];
    $ctx['blocks'] += [
        'post_tag_first' => $tags ? '<span class="strip-tag">' . enc_text($tags[0]) . '</span>' : '',
        'post_cover' => $cover ? '<figure class="cover"><img src="../' . enc_attr($cover['src']) . '" width="' . (int) $cover['w'] . '" height="' . (int) $cover['h'] . '" alt="' . enc_attr((string) $cover['alt']) . '" loading="eager" decoding="async"><span class="inset-tag" aria-hidden="true">INSET</span></figure>' : '',
        'post_body' => md_render((string) $l['body'], ['base' => '../'] + $mdOpt),
        'post_tags' => implode('', array_map(fn($t) => '<li>' . enc_text($t) . '</li>', $tags)),
        'post_prevnext' => function (string $ind) use ($older, $newer, $f) {
            $cards = [];
            foreach ([['older', $older, $f['post.prev']], ['newer', $newer, $f['post.next']]] as [$cls, $p, $label]) {
                if ($p === null) {
                    continue;
                }
                $cards[] = '<a class="pn pn-' . $cls . '" href="' . enc_attr($p['slug']) . '.html"><span class="pn-k">' . enc_text($label) . '</span>'
                    . '<span class="pn-meta"><b>' . enc_text(post_live_fix($p)) . '</b><time datetime="' . enc_attr((string) $p['live']['date']) . '">' . enc_text(dots($p['live']['date'])) . '</time></span>'
                    . '<span class="pn-t">' . enc_text($p['live']['title']) . '</span></a>';
            }
            return implode("\n" . $ind, $cards);
        },
    ];
    return tpl_render(tpl_load('post'), $ctx);
}

function render_archive(array $ctx, array $published = []): string
{
    $f = $ctx['fields'];
    $ctx['blocks']['archive_list'] = function (string $ind) use ($published, $f) {
        if (!$published) {
            return '<p class="empty">' . enc_text($f['archive.empty']) . '</p>';
        }
        $out = [];
        $year = null;
        foreach ($published as $p) {
            $l = $p['live'];
            $y = substr((string) $l['date'], 0, 4);
            if ($y !== $year) {
                if ($year !== null) {
                    $out[] = '</ol>';
                }
                $out[] = '<h2 class="year">' . enc_text($y) . '</h2>';
                $out[] = '<ol class="alist">';
                $year = $y;
            }
            $tag = ($l['tags'][0] ?? '') !== '' ? '<span class="strip-tag">' . enc_text($l['tags'][0]) . '</span>' : '';
            $out[] = '  <li class="acard"><p class="strip-row"><b class="fix">' . enc_text(post_live_fix($p)) . '</b><time datetime="' . enc_attr((string) $l['date']) . '">' . enc_text(dots($l['date'])) . '</time>' . $tag
                . '<span class="ete">ETE ' . enc_text(pattern_fill($f['log.ete_published'], ['{n}' => (string) post_minutes_live($p)])) . '</span></p>'
                . '<h3><a href="' . enc_attr($p['slug']) . '.html">' . enc_text($l['title']) . '</a></h3><p class="ex">' . enc_text(post_excerpt_live($p)) . '</p></li>';
        }
        $out[] = '</ol>';
        return implode("\n" . $ind, $out);
    };
    $ctx['values']['archive_count'] = (string) count($published);
    return tpl_render(tpl_load('archive'), $ctx);
}

/** Render everything from the given snapshots. ['ok' => bool, 'errors' => [], 'files' => [rel => bytes]] */
function render_site(array $site, array $shelf, array $posts, array $settings, bool $chatKey, array $products = []): array
{
    $errors = site_validate($site);
    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }
    try {
        $files = [];
        $published = posts_published_sorted($posts);
        $base = page_base_ctx($site, $settings);
        foreach ($published as $i => $p) {
            if (!preg_match(SLUG_RE, (string) $p['slug']) || $p['slug'] === 'index') {
                return ['ok' => false, 'errors' => ['slug' => '《' . $p['live']['title'] . '》的网址不对。']];
            }
            $files['posts/' . $p['slug'] . '.html'] = render_post_page($p, $base, $published[$i + 1] ?? null, $published[$i - 1] ?? null);
        }
        $files['posts/index.html'] = render_archive($base, $published);
        $shown = array_values(array_filter($products, fn($p) => $p['on_sale'] && $p['photos']));
        foreach ($shown as $k => $p) {
            if (!preg_match(SLUG_RE, $p['slug']) || $p['slug'] === 'index') {
                return ['ok' => false, 'errors' => ['shop' => '商品《' . $p['name'] . '》的网址不对。']];
            }
            $files['shop/' . $p['slug'] . '.html'] = render_product_page($p, $k, $site, $settings);
        }
        $home = render_home(build_home_ctx($site, $shelf, $posts, $settings, $chatKey, $products));
    } catch (TemplateError $e) {
        return ['ok' => false, 'errors' => ['template' => '页面模板出错：' . $e->getMessage()]];
    }
    $problem = home_sanity($home);
    if ($problem !== null) {
        return ['ok' => false, 'errors' => ['home' => $problem]];
    }
    $files['index.html'] = $home;
    return ['ok' => true, 'errors' => [], 'files' => $files];
}

function home_sanity(string $home): ?string
{
    if (!str_starts_with($home, '<!doctype html>') || !str_ends_with($home, "</html>\n")) {
        return '首页的开头或结尾不完整。';
    }
    foreach (['id="hero-video"', 'id="shop"', 'id="chart"'] as $needle) {
        if (!str_contains($home, $needle)) {
            return '首页缺少 ' . $needle . '。';
        }
    }
    if (strlen($home) < 150000) {
        return '首页比正常短很多，没有发布。';
    }
    return null;
}

function content_hash(array $site, array $shelf, array $posts): string
{
    $live = [];
    foreach ($posts as $p) {
        if (($p['live'] ?? null) !== null) {
            $live[$p['id']] = [$p['slug'], $p['fix'], $p['teaser_order'] ?? null, $p['live']];
        }
    }
    ksort($live);
    return hash('sha256', json_encode([
        $site['fields'] ?? [], $site['faq'] ?? [], $site['type_facts'] ?? [], $shelf['shelves'] ?? [], $live, site_album($site),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function publish_site(string $reason, array $opts = []): array
{
    return with_lock('publish', function () use ($reason, $opts) {
        $site = site_live();
        $shelf = shelf_live();
        $posts = posts_all();
        $settings = settings_get();
        $r = render_site($site, $shelf, $posts, $settings, chat_key() !== null, shop_products());
        if (!$r['ok']) {
            return ['ok' => false, 'errors' => $r['errors']];
        }
        uploads_sync_public();
        shop_sync_public();
        $files = $r['files'];
        $order = array_keys($files);
        usort($order, fn($a, $b) => ($a === 'index.html') <=> ($b === 'index.html') ?: (($a === 'posts/index.html') <=> ($b === 'posts/index.html')));
        foreach ($order as $rel) {
            file_write_atomic(PUBLIC_DIR . '/' . $rel, $files[$rel], 0644);
        }
        foreach (['posts', 'shop'] as $dir) {
            foreach (glob(PUBLIC_DIR . '/' . $dir . '/*.html') ?: [] as $f) {
                $name = basename($f);
                if (preg_match('/^[a-z0-9-]+\.html$/', $name) && !isset($files[$dir . '/' . $name])) {
                    @unlink($f);
                }
            }
        }
        $ht = PUBLIC_DIR . '/shop/.htaccess';
        if (!is_file($ht)) {
            file_write_atomic($ht, (string) file_get_contents(app_path('templates', 'shop.htaccess')), 0644);
        }
        $ht = PUBLIC_DIR . '/posts/.htaccess';
        if (!is_file($ht)) {
            file_write_atomic($ht, (string) file_get_contents(app_path('templates', 'posts.htaccess')), 0644);
        }
        $versionId = null;
        // shop product pages follow live stock and settings, not the versioned content: versions never hold them
        $files = array_filter($files, fn($rel) => !str_starts_with($rel, 'shop/'), ARRAY_FILTER_USE_KEY);
        if (($opts['version'] ?? true) !== false) {
            $hash = content_hash($site, $shelf, $posts);
            $newest = versions_list()[0] ?? null;
            if ($newest === null || $newest['content_hash'] !== $hash) {
                $versionId = version_create($reason, $hash, $site, $shelf, $posts, $files);
            } else {
                version_touch_files($files);
            }
        } else {
            // keep the newest manifest's file hashes current so site_status() does not report a mismatch
            version_touch_files($files);
        }
        audit('publish', $reason);
        return ['ok' => true, 'version_id' => $versionId, 'files' => array_keys($r['files'])];
    });
}

// ---------- versions ----------

function versions_list(): array
{
    $out = [];
    foreach (glob(DATA_DIR . '/versions/*/manifest.json') ?: [] as $f) {
        $id = basename(dirname($f));
        if (!preg_match(VERSION_ID_RE, $id)) {
            continue;
        }
        $m = json_read($f);
        if (is_array($m)) {
            $out[] = $m;
        }
    }
    usort($out, fn($a, $b) => ($b['seq'] ?? 0) <=> ($a['seq'] ?? 0));
    return $out;
}

function version_create(string $reason, string $hash, array $site, array $shelf, array $posts, array $files): string
{
    $list = versions_list();
    $seq = ($list[0]['seq'] ?? 0) + 1;
    do {
        $id = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
    } while (is_dir(DATA_DIR . '/versions/' . $id));
    $dir = DATA_DIR . '/versions/' . $id;
    $fileHashes = [];
    foreach ($files as $rel => $bytes) {
        file_write_atomic($dir . '/files/' . $rel, $bytes, 0600);
        $fileHashes[$rel] = hash('sha256', $bytes);
    }
    json_write_atomic($dir . '/live/site.json', $site);
    json_write_atomic($dir . '/live/shelf.json', $shelf);
    foreach ($posts as $p) {
        if (($p['live'] ?? null) !== null) {
            json_write_atomic($dir . '/live/posts/' . $p['id'] . '.json', $p);
        }
    }
    json_write_atomic($dir . '/manifest.json', [
        'id' => $id, 'seq' => $seq, 'created_at' => now_iso(), 'reason' => $reason,
        'content_hash' => $hash, 'template_hash' => template_hash(), 'files' => $fileHashes,
    ]);
    foreach (array_slice(versions_list(), VERSIONS_KEEP) as $old) {
        rrmdir(DATA_DIR . '/versions/' . $old['id']);
    }
    return $id;
}

/** Settings-only republish: record the current file hashes and templates on the newest manifest. */
function version_touch_files(array $files): void
{
    $newest = versions_list()[0] ?? null;
    if ($newest === null) {
        return;
    }
    $path = DATA_DIR . '/versions/' . $newest['id'] . '/manifest.json';
    $m = json_read($path);
    $m['current_files'] = array_map(fn($b) => hash('sha256', $b), $files);
    $m['current_template_hash'] = template_hash();
    json_write_atomic($path, $m);
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir) || is_link($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

function version_get(string $id): ?array
{
    if (!preg_match(VERSION_ID_RE, $id)) {
        return null;
    }
    $m = json_read(DATA_DIR . '/versions/' . $id . '/manifest.json');
    return is_array($m) ? $m : null;
}

/** Archived file bytes; only names listed in that version's manifest are served. */
function version_file(string $id, string $rel): ?string
{
    $m = version_get($id);
    if ($m === null || !isset($m['files'][$rel])) {
        return null;
    }
    $bytes = @file_get_contents(DATA_DIR . '/versions/' . $id . '/files/' . $rel);
    return $bytes === false ? null : $bytes;
}

/** Restore the live snapshots of a version (drafts untouched), then republish. ['ok', 'skipped' => [messages], …] */
function version_rollback(string $id): array
{
    $m = version_get($id) ?? throw new InvalidArgumentException('no such version');
    return with_lock('publish', function () use ($id, $m) {
        return with_lock('posts', function () use ($id, $m) {
            $dir = DATA_DIR . '/versions/' . $id . '/live';
            $site = json_read($dir . '/site.json');
            $shelf = json_read($dir . '/shelf.json');
            $now = now_iso();
            $curSite = json_read(data_path('content', 'site.live.json'), []);
            $site['rev'] = $curSite['rev'] ?? ($site['rev'] ?? 1);
            // the site address behaves like a setting: a rollback never brings back an older one
            if (isset($curSite['fields']['site.origin'])) {
                $site['fields']['site.origin'] = $curSite['fields']['site.origin'];
            }
            json_write_atomic(data_path('content', 'site.live.json'), $site);
            json_write_atomic(data_path('content', 'shelf.live.json'), $shelf);
            $skipped = [];
            $inVersion = [];
            foreach (glob($dir . '/posts/p*.json') ?: [] as $f) {
                $vp = json_read($f);
                if (!is_array($vp) || !preg_match(POST_ID_RE, (string) ($vp['id'] ?? ''))) {
                    continue;
                }
                $inVersion[$vp['id']] = true;
                $cur = post_get($vp['id']);
                if ($cur === null) {
                    $cur = trash_find($vp['id']) ?? array_merge($vp, ['draft' => array_intersect_key($vp['live'], array_flip(POST_CONTENT_KEYS))]);
                }
                foreach (posts_all() as $o) {
                    if ($o['id'] !== $vp['id'] && $o['slug'] === $vp['slug']) {
                        $skipped[] = '《' . $vp['live']['title'] . '》没有恢复：它的网址已经被另一篇用了。';
                        continue 2;
                    }
                }
                $cur['slug'] = $vp['slug'];
                $cur['live'] = $vp['live'];
                $cur['teaser_order'] = $vp['teaser_order'] ?? $cur['teaser_order'] ?? null;
                if (($cur['first_published_at'] ?? null) === null && ($vp['live']['mode'] ?? '') === 'full') {
                    $cur['first_published_at'] = $vp['first_published_at'] ?? $vp['live']['published_at'];
                }
                $cur['rev'] = (int) ($cur['rev'] ?? 0) + 1;
                $cur['updated_at'] = $now;
                json_write_atomic(post_path($vp['id']), $cur);
            }
            foreach (posts_all() as $p) {
                if (!isset($inVersion[$p['id']]) && $p['live'] !== null) {
                    $p['live'] = null;
                    $p['rev']++;
                    $p['updated_at'] = $now;
                    json_write_atomic(post_path($p['id']), $p);
                }
            }
            $res = publish_site('回滚到 ' . local_time($m['created_at'], 'n月j日 H:i') . ' 的版本');
            $res['skipped'] = $skipped;
            return $res;
        });
    });
}

function trash_find(string $id): ?array
{
    $files = glob(DATA_DIR . '/trash/posts/' . $id . '-*.json') ?: [];
    rsort($files);
    return $files ? json_read($files[0]) : null;
}

/** Draft/live differences and whether the public files match the newest version (SPEC §6.5). */
function site_status(): array
{
    $siteD = json_read(data_path('content', 'site.draft.json'), []);
    $siteL = site_live();
    $shelfD = json_read(data_path('content', 'shelf.draft.json'), []);
    $shelfL = shelf_live();
    $strip = fn($d) => array_intersect_key($d, array_flip(['fields', 'faq', 'type_facts', 'album', 'shelves']));
    $newest = versions_list()[0] ?? null;
    $index = PUBLIC_DIR . '/index.html';
    $files = $newest['current_files'] ?? $newest['files'] ?? [];
    $tplHash = $newest['current_template_hash'] ?? $newest['template_hash'] ?? null;
    $posts = posts_all();
    return [
        'site_dirty' => $strip($siteD) != $strip($siteL),
        'shelf_dirty' => $strip($shelfD) != $strip($shelfL),
        'posts_dirty' => count(array_filter($posts, 'post_dirty')),
        'index_missing' => !is_file($index),
        'index_changed' => $newest !== null && is_file($index) && hash_file('sha256', $index) !== ($files['index.html'] ?? ''),
        'template_changed' => $newest !== null && $tplHash !== template_hash(),
        'no_version' => $newest === null,
        'last_publish' => $newest['created_at'] ?? null,
    ];
}

/** Dashboard self-heal: regenerate when files and content disagree. Returns null when nothing was needed. */
function site_self_heal(): ?array
{
    $st = site_status();
    if ($st['no_version']) {
        uploads_sync_public();
        return publish_site('初始内容');
    }
    if ($st['index_missing'] || $st['index_changed'] || $st['template_changed']) {
        uploads_sync_public();
        return publish_site('网站程序更新后重新生成页面', ['version' => false]);
    }
    return null;
}

// ---------- previews (admin) ----------

const PREVIEW_BANNER_HOME = '<div style="position:fixed;left:0;right:0;bottom:0;z-index:2147483000;padding:10px 16px calc(10px + env(safe-area-inset-bottom));background:#E6A948;color:#0A1733;font:600 15px/1.5 system-ui,sans-serif;text-align:center">预览：这是还没发布的草稿，访客看不到。</div>';

function inject_preview(string $html, string $base, string $banner): string
{
    $html = preg_replace('/<head>\n/', "<head>\n<base href=\"" . enc_attr($base) . "\">\n", $html, 1);
    return (string) preg_replace('/<body([^>]*)>/', '<body$1>' . "\n" . $banner, $html, 1);
}

function preview_home(array $siteDraft, array $shelfDraft): string
{
    $site = site_normalize($siteDraft);
    $err = site_validate($site);
    if ($err) {
        throw new ValidationError($err);
    }
    $settings = settings_get();
    $settings['stats']['enabled'] = false;   // a preview is not a visit
    $html = render_home(build_home_ctx($site, $shelfDraft, posts_all(), $settings, chat_key() !== null, shop_products()));
    return inject_preview($html, '../', PREVIEW_BANNER_HOME);
}

function preview_post(array $postWithDraft): string
{
    $p = $postWithDraft;
    $p['draft']['date'] ??= local_day();
    $p['live'] = post_live_from_draft($p, 'full');
    $site = site_live();
    $settings = settings_get();
    $settings['stats']['enabled'] = false;   // a preview is not a visit
    $html = render_post_page($p, page_base_ctx($site, $settings), null, null);
    return inject_preview($html, '../posts/', '<div class="preview-banner">预览：这是还没发布的草稿，访客看不到。</div>');
}
