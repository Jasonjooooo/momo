<?php
// Home page blocks, computed values and conditions (SPEC §4.3-4.5), plus the extractors that read them back from the template.
declare(strict_types=1);
defined('BLOG_APP') || exit;

const LEG_FXSVG = '<svg class="fx" viewBox="-13 -13 26 26"><circle class="ring" r="14"/><path class="f0" d="M0,-8.5 L7.4,4.8 L-7.4,4.8 Z"/><path class="f1" d="M0,-4 L3.6,2.4 L-3.6,2.4 Z"/><path class="f2" d="M0,-4 L3.6,2.4 L-3.6,2.4 Z"/></svg>';
const SEED_TIME = '2026-10-14T00:00:00Z';

/**
 * Home context keys used here: fields, faq, type_facts, shelves, legs (home_legs() result), subscribe_live (bool),
 * chat_live (bool), site_published_at (iso), extra_css (string).
 */
function block_render(string $name, array $ctx, string $indent): string
{
    switch ($name) {
        case 'legs':
            return render_legs($ctx['legs']['legs'], $ctx['fields'], $indent);
        case 'faq_rows':
            return render_faq_rows($ctx['faq'], $ctx['fields'], (bool) $ctx['subscribe_live'], (string) ($ctx['site_published_at'] ?? ''), $indent);
        case 'type_facts':
            return render_type_facts($ctx['type_facts'], $indent);
        case 'shelf_json':
            return render_shelf_json($ctx['shelves']);
        case 'about_album':
            return render_about_album($ctx['album'], $indent);
        case 'shop_status':
            return render_shop_status($ctx['shop'] ?? shop_ctx_empty());
        case 'shop_grid':
            return render_shop_grid($ctx['shop'] ?? shop_ctx_empty(), $indent);
        case 'extra_css':
            if (!cond_eval('subscribe_live', $ctx) && !cond_eval('has_published', $ctx) && !cond_eval('has_album', $ctx) && !cond_eval('has_shop_cats', $ctx)) {
                return '';
            }
            return '<style id="extra-css">' . "\n" . trim((string) $ctx['extra_css']) . "\n</style>\n";
    }
    throw new TemplateError('unknown block ' . $name);
}

function value_render(string $name, array $ctx): string
{
    switch ($name) {
        case 'chat_live':
            return $ctx['chat_live'] ? 'true' : 'false';
        case 'subscribe_endpoint':
            return $ctx['subscribe_live'] ? "'api/subscribe.php'" : "''";
        case 'log_count':
            return enc_text(log_count_text($ctx['fields'], (int) $ctx['legs']['p'], (int) $ctx['legs']['t']));
        case 'radio_act':
            return enc_text('A1 ' . ($ctx['shelves'][0]['title'] ?? ''));
        case 'radio_sby':
            $n = count($ctx['shelves']);
            $k = max(0, min(1, $n - 1));
            return enc_text('A' . ($k + 1) . ' ' . ($ctx['shelves'][$k]['title'] ?? ''));
    }
    throw new TemplateError('unknown value ' . $name);
}

function cond_eval(string $cond, array $ctx): bool
{
    switch ($cond) {
        case 'subscribe_live':
            return (bool) $ctx['subscribe_live'];
        case 'has_teasers':
            return (int) $ctx['legs']['t'] > 0;
        case 'has_published':
            return (int) $ctx['legs']['published_total'] > 0;
        case 'origin_placeholder':
            return str_contains((string) $ctx['fields']['site.origin'], 'DEPLOY-DOMAIN');
        case 'stats_live':
            return (bool) ($ctx['stats_live'] ?? false);
        case 'has_album':
            return count($ctx['album'] ?? []) > 1;
        case 'has_shop_cats':
            return (bool) (($ctx['shop'] ?? null)['cats']['cats'] ?? []) && (bool) ($ctx['shop']['open'] ?? false);
    }
    throw new TemplateError('unknown condition ' . $cond);
}

function pattern_fill(string $pattern, array $vars): string
{
    return strtr($pattern, $vars);
}

function log_count_text(array $fields, int $p, int $t): string
{
    if ($p === 0 && $t > 0) {
        return pattern_fill($fields['log.count_writing'], ['{t}' => (string) $t]);
    }
    if ($p > 0 && $t === 0) {
        return pattern_fill($fields['log.count_latest'], ['{p}' => (string) $p]);
    }
    if ($p > 0 && $t > 0) {
        return pattern_fill($fields['log.count_mixed'], ['{p}' => (string) $p, '{t}' => (string) $t]);
    }
    return $fields['log.count_none'];
}

// ---------- legs ----------

/** $legs: [['fix','title','excerpt','tag','mode','minutes','date','slug'], …] */
function render_legs(array $legs, array $fields, string $ind): string
{
    $ind2 = $ind . '  ';
    $items = [];
    foreach ($legs as $k => $leg) {
        $fix = enc_attr($leg['fix']);
        $prev = $k === 0 ? 'LOGBK' : enc_attr($legs[$k - 1]['fix']);
        if ($leg['mode'] === 'full') {
            $date = '<time datetime="' . enc_attr($leg['date']) . '">' . enc_text(str_replace('-', '.', $leg['date'])) . '</time>';
            $ete = enc_text(pattern_fill($fields['log.ete_published'], ['{n}' => (string) $leg['minutes']]));
            $title = '<a href="posts/' . enc_attr($leg['slug']) . '.html">' . enc_text($leg['title']) . '</a>';
        } else {
            $date = enc_text($fields['log.teaser_date']);
            $ete = enc_text(pattern_fill($fields['log.ete_teaser'], ['{n}' => (string) $leg['minutes']]));
            $title = enc_text($leg['title']);
        }
        $tag = ($leg['tag'] ?? '') !== '' ? '<p class="strip-tag">' . enc_text($leg['tag']) . '</p>' : '';
        $items[] = '<li class="rv" style="--i:' . min($k, 5) . '"><article class="post">' . "\n"
            . $ind2 . '<span class="fix rp" data-route="' . $fix . '" aria-hidden="true">' . LEG_FXSVG . '<span class="fx-tag"><b>' . $fix . '</b></span></span>' . "\n"
            . $ind2 . '<div class="leg-row"><span class="ln" aria-hidden="true">LEG ' . ($k + 1) . '</span><span aria-hidden="true">' . $prev . '<i class="to"></i>' . $fix . '</span><span class="lv" aria-hidden="true" data-crs="' . $fix . '">---°</span><span class="lv" aria-hidden="true" data-dis="' . $fix . '">-- NM</span><p class="strip-time"><span class="ete" aria-hidden="true">ETE</span>' . $ete . '</p></div>' . "\n"
            . $ind2 . '<div class="strip-meta"><p class="strip-date">' . $date . '</p>' . $tag . '</div>' . "\n"
            . $ind2 . '<h3>' . $title . '</h3><p class="ex">' . enc_text($leg['excerpt']) . '</p>' . "\n"
            . $ind . '</article></li>';
    }
    return implode("\n" . $ind, $items);
}

function pattern_regex(string $pattern, string $var): string
{
    $parts = explode($var, $pattern);
    return '/^' . implode('(\d+)', array_map(fn($p) => preg_quote(enc_text($p), '/'), $parts)) . '$/u';
}

/** Teaser posts from today's legs (SPEC §5.4 seed teasers). */
function extract_legs(string $inner, string $ind, array $fields): array
{
    $re = '/<li class="rv" style="--i:\d+"><article class="post">\n *<span class="fix rp" data-route="([A-Z]{5})".*?<p class="strip-time"><span class="ete" aria-hidden="true">ETE<\/span>([^<]*)<\/p><\/div>\n *<div class="strip-meta"><p class="strip-date">([^<]*)<\/p>(?:<p class="strip-tag">([^<]*)<\/p>)?<\/div>\n *<h3>([^<]*)<\/h3><p class="ex">([^<]*)<\/p>\n *<\/article><\/li>/s';
    preg_match_all($re, $inner, $m, PREG_SET_ORDER);
    $eteRe = pattern_regex($fields['log.ete_teaser'], '{n}');
    $posts = [];
    $legs = [];
    foreach ($m as $i => $row) {
        if (!preg_match($eteRe, $row[2], $em)) {
            throw new TemplateError('leg ETE does not match log.ete_teaser');
        }
        if (dec_text($row[3]) !== $fields['log.teaser_date']) {
            throw new TemplateError('leg date does not match log.teaser_date');
        }
        $n = $i + 1;
        $tag = isset($row[4]) && $row[4] !== '' ? dec_text($row[4]) : null;
        $content = [
            'title' => dec_text($row[5]),
            'excerpt' => dec_text($row[6]),
            'tags' => $tag === null ? [] : [$tag],
            'body' => '',
            'cover' => null,
            'date' => null,
            'minutes' => (int) $em[1],
        ];
        $posts[] = [
            'id' => sprintf('p%012d', $n),
            'rev' => 1,
            'slug' => strtolower($row[1]),
            'fix' => $row[1],
            'created_at' => SEED_TIME,
            'updated_at' => SEED_TIME,
            'first_published_at' => null,
            'teaser_order' => $n,
            'draft' => $content,
            'live' => ['mode' => 'teaser', 'published_at' => SEED_TIME] + $content,
        ];
        $legs[] = ['fix' => $row[1], 'title' => $content['title'], 'excerpt' => $content['excerpt'], 'tag' => $tag, 'mode' => 'teaser', 'minutes' => $content['minutes'], 'date' => null, 'slug' => strtolower($row[1])];
    }
    if (render_legs($legs, $fields, $ind) !== $inner) {
        throw new TemplateError('legs block does not round-trip');
    }
    return $posts;
}

// ---------- FAQ ----------

function render_faq_rows(array $rows, array $fields, bool $subscribeLive, string $publishedAt, string $ind): string
{
    $yy = preg_match('/^\d{2}(\d{2})-/', $publishedAt, $ym) ? $ym[1] : gmdate('y');
    $out = [];
    foreach ($rows as $k => $row) {
        $nid = $row['nid'] !== '' ? $row['nid'] : 'A01' . sprintf('%02d', $k + 1) . '/' . $yy;
        $note = (!empty($row['subscribe_note']) && !$subscribeLive) ? '<span class="mode-a">' . enc_text($fields['sub.off_note']) . '</span>' : '';
        $out[] = '<div class="cl-row" style="--i:' . min($k, 8) . '"><dt><span class="nid" aria-hidden="true">' . enc_text($nid) . '</span><span class="q">' . enc_text($row['q']) . '</span><span class="lead" aria-hidden="true"></span><span class="r">' . enc_text($row['r']) . '</span></dt><dd><p><span class="e" aria-hidden="true">E)</span>' . enc_text($row['a']) . $note . '</p></dd></div>';
    }
    return implode("\n" . $ind, $out);
}

function extract_faq_rows(string $inner, string $ind, array $fields): array
{
    $re = '/<div class="cl-row" style="--i:\d+"><dt><span class="nid" aria-hidden="true">([^<]*)<\/span><span class="q">([^<]*)<\/span><span class="lead" aria-hidden="true"><\/span><span class="r">([^<]*)<\/span><\/dt><dd><p><span class="e" aria-hidden="true">E\)<\/span>([^<]*)(<span class="mode-a">([^<]*)<\/span>)?<\/p><\/dd><\/div>/';
    preg_match_all($re, $inner, $m, PREG_SET_ORDER);
    $rows = [];
    foreach ($m as $row) {
        $note = isset($row[5]) && $row[5] !== '';
        if ($note && dec_text($row[6]) !== $fields['sub.off_note']) {
            throw new TemplateError('FAQ mode-a text differs from sub.off_note');
        }
        $rows[] = ['nid' => dec_text($row[1]), 'q' => dec_text($row[2]), 'r' => dec_text($row[3]), 'a' => dec_text($row[4]), 'subscribe_note' => $note];
    }
    if (render_faq_rows($rows, $fields, false, SEED_TIME, $ind) !== $inner) {
        throw new TemplateError('faq_rows block does not round-trip');
    }
    return $rows;
}

// ---------- type facts ----------

function render_type_facts(array $rows, string $ind): string
{
    $out = [];
    foreach ($rows as $row) {
        $out[] = '<div><dt>' . enc_text($row['k']) . '</dt><dd><span class="val">' . enc_text($row['val']) . '</span><span class="line">' . enc_text($row['line']) . '</span></dd></div>';
    }
    return implode("\n" . $ind, $out);
}

function extract_type_facts(string $inner, string $ind): array
{
    preg_match_all('/<div><dt>([^<]*)<\/dt><dd><span class="val">([^<]*)<\/span><span class="line">([^<]*)<\/span><\/dd><\/div>/', $inner, $m, PREG_SET_ORDER);
    $rows = [];
    foreach ($m as $row) {
        $rows[] = ['k' => dec_text($row[1]), 'val' => dec_text($row[2]), 'line' => dec_text($row[3])];
    }
    if (render_type_facts($rows, $ind) !== $inner) {
        throw new TemplateError('type_facts block does not round-trip');
    }
    return $rows;
}

// ---------- 关于 · INSET A (one photo exactly as the page always had it; 2+ photos a swipeable strip in the same frame) ----------

function render_about_album(array $album, string $ind): string
{
    $i2 = $ind . '  ';
    $i4 = $ind . '    ';
    $i6 = $ind . '      ';
    $img = function (array $p): string {
        [$w, $h] = album_dims((string) $p['src']) ?? [1600, 900];
        return '<img src="' . enc_attr((string) $p['src']) . '" width="' . $w . '" height="' . $h . '" loading="lazy" decoding="async" alt="' . enc_attr((string) $p['alt']) . '">';
    };
    $tag = '<p class="inset-tag" aria-hidden="true">INSET A</p>';
    if (count($album) <= 1) {
        $p = $album[0] ?? ['src' => ALBUM_SEED_SRC, 'caption' => '', 'alt' => ''];
        return '<figure class="about-fig">' . "\n"
            . $i2 . '<div class="about-art">' . "\n"
            . $i4 . $img($p) . "\n"
            . $i4 . $tag . "\n"
            . $i2 . '</div>' . "\n"
            . $i2 . '<figcaption>' . enc_text((string) $p['caption']) . '</figcaption>' . "\n"
            . $ind . '</figure>';
    }
    $n = count($album);
    $slides = [];
    $dots = [];
    foreach ($album as $k => $p) {
        $slides[] = $i6 . '<div class="alb-slide" role="group" aria-roledescription="slide" aria-label="' . ($k + 1) . ' / ' . $n . '" data-cap="' . enc_attr((string) $p['caption']) . '">' . $img($p) . '</div>';
        $dots[] = '<button class="alb-dot" type="button" aria-label="第 ' . ($k + 1) . ' 张"' . ($k === 0 ? ' aria-current="true"' : '') . '></button>';
    }
    $arrow = fn(string $d) => '<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="' . $d . '" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    return '<figure class="about-fig album" data-album>' . "\n"
        . $i2 . '<div class="about-art" role="region" aria-roledescription="carousel" aria-label="照片，共 ' . $n . ' 张">' . "\n"
        . $i4 . '<div class="alb-track" tabindex="0" aria-label="左右滑动，或用方向键换一张">' . "\n"
        . implode("\n", $slides) . "\n"
        . $i4 . '</div>' . "\n"
        . $i4 . $tag . "\n"
        . $i4 . '<button class="alb-btn alb-prev" type="button" aria-label="上一张">' . $arrow('M12.5 4 6.5 10l6 6') . '</button>'
        . '<button class="alb-btn alb-next" type="button" aria-label="下一张">' . $arrow('M7.5 4l6 6-6 6') . '</button>' . "\n"
        . $i2 . '</div>' . "\n"
        . $i2 . '<figcaption><span class="alb-cap" aria-live="polite">' . enc_text((string) $album[0]['caption']) . '</span><span class="alb-dots">' . implode('', $dots) . '</span></figcaption>' . "\n"
        . $ind . '</figure>';
}

/** Today's one photo from the template default: [['src', 'caption', 'alt']]. */
function extract_about_album(string $inner, string $ind): array
{
    $re = '/^<figure class="about-fig">\n *<div class="about-art">\n *<img src="([^"]*)" width="(\d+)" height="(\d+)" loading="lazy" decoding="async" alt="([^"<]*)">\n *<p class="inset-tag" aria-hidden="true">INSET A<\/p>\n *<\/div>\n *<figcaption>([^<]*)<\/figcaption>\n *<\/figure>$/';
    if (!preg_match($re, $inner, $m)) {
        throw new TemplateError('about_album default is not the one-photo figure');
    }
    if ($m[1] !== ALBUM_SEED_SRC || [(int) $m[2], (int) $m[3]] !== ALBUM_SEED_DIMS) {
        throw new TemplateError('about_album default photo differs from ALBUM_SEED_SRC / ALBUM_SEED_DIMS');
    }
    $album = [['src' => $m[1], 'caption' => dec_text($m[5]), 'alt' => enc_decode('attr', $m[4])]];
    if (render_about_album($album, $ind) !== $inner) {
        throw new TemplateError('about_album block does not round-trip');
    }
    return $album;
}

// ---------- 商城 (the home counter; product pages are rendered in publish.php) ----------

/** The shop part of the home context: open/closed, notice, the products on sale (owner order), shipping. */
function shop_ctx_empty(): array
{
    return ['open' => true, 'notice' => '', 'products' => [], 'cats' => ['cats' => [], 'other' => 0], 'ix' => []];
}

function render_shop_status(array $shop): string
{
    $n = count($shop['products']);
    if (!$shop['open']) {
        return '<p class="lamp shop-lamp" data-state="clsd"><span class="lamp-dot" aria-hidden="true"></span><span class="lamp-code">CLSD</span><span>暂停营业</span></p>';
    }
    return '<p class="lamp shop-lamp" data-state="on"><span class="lamp-dot" aria-hidden="true"></span><span class="lamp-code">OPEN</span><span>营业中</span></p>'
        . ($n > 0 ? '<span class="shop-n">' . $n . ' 件在卖</span>' : '');
}

function render_shop_grid(array $shop, string $ind): string
{
    $notice = $shop['notice'] !== '' ? '<p class="shop-notice">' . enc_text($shop['notice']) . '</p>' . "\n" . $ind : '';
    if (!$shop['open']) {
        return $notice . '<p class="shop-closed"><b>CLSD · 暂停营业</b>商城暂时不接新订单。已经下的订单照常处理，可以在「查订单」里看进度。</p>';
    }
    if (!$shop['products']) {
        return $notice . '<p class="shop-empty">还没有上架的商品，过几天再来看看。</p>';
    }
    $ind2 = $ind . '  ';
    $items = [];
    $tree = $shop['cats'] ?? ['cats' => [], 'other' => 0];
    $ix = $shop['ix'] ?? [];
    $withCats = (bool) $tree['cats'];
    foreach ($shop['products'] as $k => $p) {
        $ph = $p['photos'][0] ?? null;
        $dims = $ph ? album_dims($ph['src']) : null;
        [$w, $h] = $dims ?? [800, 800];
        $avail = shop_avail($p);
        $label = shop_avail_label($p);
        $img = $ph ? '<img src="' . enc_attr($ph['src']) . '" width="' . $w . '" height="' . $h . '" loading="lazy" decoding="async" alt="' . enc_attr($ph['alt'] !== '' ? $ph['alt'] : $p['name']) . '">' : '';
        $where = $withCats ? shop_cat_of($p, $ix) : null;
        $data = $where === null ? '' : ' data-cat="' . enc_attr($where['top'] ?? SHOP_CAT_OTHER) . '" data-sub="' . enc_attr((string) $where['sub']) . '"';
        $catLine = $where === null ? '' : '<span class="good-c">' . enc_text($where['top'] === null ? '其他' : shop_cat_label($p['cat'], $ix)) . '</span>';
        $items[] = '<li class="good' . ($avail === 'out' ? ' out' : '') . '"' . $data . '><a class="good-a" href="shop/' . enc_attr($p['slug']) . '.html">'
            . '<span class="good-ph">' . $img . '<span class="good-ix" aria-hidden="true">S-' . sprintf('%02d', $k + 1) . '</span></span>'
            . '<div class="good-body"><span class="good-tag' . ($p['type'] === 'digital' ? ' dg' : '') . '">' . SHOP_TYPES[$p['type']] . '</span>' . $catLine
            . '<h3 class="good-n">' . enc_text($p['name']) . '</h3>'
            . ($p['summary'] !== '' ? '<p class="good-sum">' . enc_text($p['summary']) . '</p>' : '')
            . '<p class="good-row"><b class="good-p"><small>¥</small>' . shop_money($p['price']) . '</b>'
            . ($label !== null ? '<span class="good-s' . ($avail === 'low' ? ' low' : '') . '">' . enc_text($label) . '</span>' : '') . '</p></div></a></li>';
    }
    $tabs = $withCats ? render_shop_cat_tabs($tree, count($shop['products']), $ind) . "\n" . $ind : '';
    return $notice . $tabs . '<ul class="goods">' . "\n" . $ind2 . implode("\n" . $ind2, $items) . "\n" . $ind . '</ul>';
}

/**
 * The category filter above the grid: 大类 tabs (全部 first, 其他 last when some products have no category) and, per
 * 大类 with 小类, a row of 小类 chips shown when that 大类 is picked. Buttons with aria-pressed; the home script does the
 * filtering and keeps the choice in the address (#shop/c/<id>). Without script the rows stay hidden (CSS) and every
 * product shows.
 */
function render_shop_cat_tabs(array $tree, int $total, string $ind): string
{
    $ind2 = $ind . '  ';
    $tab = fn(string $id, string $name, int $n, bool $on) => '<button type="button" class="cat-tab" data-cat="' . enc_attr($id) . '" aria-pressed="' . ($on ? 'true' : 'false') . '">'
        . enc_text($name) . '<span class="cat-n"><span class="cat-vh">（</span>' . $n . '<span class="cat-vh"> 件）</span></span></button>';
    $tabs = [$tab('', '全部', $total, true)];
    $rows = [];
    foreach ($tree['cats'] as $c) {
        $tabs[] = $tab($c['id'], $c['name'], $c['n'], false);
        if (!$c['subs']) {
            continue;
        }
        $chips = ['<button type="button" class="cat-chip" data-sub="" aria-pressed="true">全部</button>'];
        foreach ($c['subs'] as $s) {
            $chips[] = '<button type="button" class="cat-chip" data-sub="' . enc_attr($s['id']) . '" aria-pressed="false">' . enc_text($s['name']) . '</button>';
        }
        $rows[] = '<div class="cat-chips" role="group" aria-label="' . enc_attr('「' . $c['name'] . '」的小类') . '" data-subs-of="' . enc_attr($c['id']) . '" hidden>' . implode('', $chips) . '</div>';
    }
    if ($tree['other'] > 0) {
        $tabs[] = $tab(SHOP_CAT_OTHER, '其他', $tree['other'], false);
    }
    return '<div class="shop-cats" data-shop-cats>' . "\n"
        . $ind2 . '<div class="cat-tabs" role="group" aria-label="按大类筛选">' . implode('', $tabs) . '</div>' . "\n"
        . ($rows ? $ind2 . implode("\n" . $ind2, $rows) . "\n" : '')
        . $ind2 . '<p class="cat-live" data-cat-status aria-live="polite"></p>' . "\n"
        . $ind . '</div>';
}

// ---------- shelf JSON ----------

function render_shelf_json(array $shelves): string
{
    $d = ['shelves' => []];
    foreach ($shelves as $s) {
        $tools = [];
        foreach ($s['tools'] as $t) {
            $tool = ['name' => $t['name'], 'category' => $s['title'], 'desc' => $t['desc'], 'url' => $t['url'], 'cn_access' => $t['cn_access']];
            if (($t['note'] ?? '') !== '') {
                $tool['note'] = $t['note'];
            }
            $tools[] = $tool;
        }
        $d['shelves'][] = ['id' => $s['id'], 'title' => $s['title'], 'blurb' => $s['blurb'], 'tools' => $tools];
    }
    $json = json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_THROW_ON_ERROR);
    return (string) preg_replace_callback('/^(?: {4})+/m', fn($mm) => str_repeat(' ', intdiv(strlen($mm[0]), 2)), $json);
}

function extract_shelf_json(string $inner): array
{
    $d = json_decode($inner, true);
    if (!is_array($d) || !is_array($d['shelves'] ?? null)) {
        throw new TemplateError('shelf JSON does not parse');
    }
    $shelves = [];
    foreach ($d['shelves'] as $s) {
        $tools = [];
        foreach ($s['tools'] as $t) {
            if (($t['category'] ?? null) !== $s['title']) {
                throw new TemplateError('tool category differs from its shelf title: ' . ($t['name'] ?? '?'));
            }
            $tools[] = ['name' => $t['name'], 'desc' => $t['desc'], 'url' => $t['url'], 'cn_access' => $t['cn_access'], 'note' => $t['note'] ?? ''];
        }
        $shelves[] = ['id' => $s['id'], 'title' => $s['title'], 'blurb' => $s['blurb'], 'tools' => $tools];
    }
    if (render_shelf_json($shelves) !== $inner) {
        throw new TemplateError('shelf JSON does not round-trip');
    }
    return $shelves;
}

/** Everything the extractor reads from home.tpl.html: [site, shelf, posts]. */
function block_extract_all(array $nodes): array
{
    $ex = tpl_extract($nodes);
    if (!isset($ex['blocks']['about_album'])) {
        throw new TemplateError('block missing: about_album');
    }
    $album = extract_about_album($ex['blocks']['about_album'][0], $ex['blocks']['about_album'][1]);
    $ex['fields']['about.figcaption'] = $album[0]['caption'];   // the legacy mirrors of the first photo (fields.php)
    $ex['fields']['about.img_alt'] = $album[0]['alt'];
    $reg = field_registry();
    $fields = [];
    foreach ($reg as $k => $f) {
        $fields[$k] = $ex['fields'][$k] ?? $f['default'];
        if ($fields[$k] === null) {
            throw new TemplateError('no default for ' . $k);
        }
    }
    foreach (['legs', 'faq_rows', 'type_facts', 'extra_css', 'shop_status', 'shop_grid'] as $b) {
        if (!isset($ex['blocks'][$b])) {
            throw new TemplateError('block missing: ' . $b);
        }
    }
    if ($ex['blocks']['extra_css'][0] !== '') {
        throw new TemplateError('extra_css default must be empty');
    }
    $posts = extract_legs($ex['blocks']['legs'][0], $ex['blocks']['legs'][1], $fields);
    $site = [
        'fields' => $fields,
        'faq' => extract_faq_rows($ex['blocks']['faq_rows'][0], $ex['blocks']['faq_rows'][1], $fields),
        'type_facts' => extract_type_facts($ex['blocks']['type_facts'][0], $ex['blocks']['type_facts'][1]),
        'album' => $album,
    ];
    // The AI shelf left the page for the shop (owner request 2026-10-17, SPEC §18 item 26). Its seed file stays as it
    // was (unused, kept for old versions and rollbacks); nothing is extracted for it any more.
    $shelf = json_read(APP_DIR . '/seed/shelf.baseline.json', ['shelves' => []]);
    return ['site' => $site, 'shelf' => $shelf, 'posts' => $posts, 'values' => $ex['values']];
}

/** Single-block form of the extractors (SPEC §13 signature). */
function block_extract(string $name, string $inner, string $indent, array $fields): mixed
{
    return match ($name) {
        'legs' => extract_legs($inner, $indent, $fields),
        'faq_rows' => extract_faq_rows($inner, $indent, $fields),
        'type_facts' => extract_type_facts($inner, $indent),
        'shelf_json' => extract_shelf_json($inner),
        'about_album' => extract_about_album($inner, $indent),
        'extra_css' => $inner,
        default => throw new TemplateError('unknown block ' . $name),
    };
}
