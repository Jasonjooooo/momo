<?php
// 分享海报 (admin): the pages a poster can be made for — the home page, each published post, each product on sale —
// with what the browser needs to draw one (admin/assets/poster.js): a kind, a title, a line or two, a picture on this
// site and the page's public address for the QR code. Nothing is stored; the poster is drawn and saved in the browser.
declare(strict_types=1);
defined('BLOG_APP') || exit;

/** A picture path the poster may draw (this site's own files only), made root-relative; '' for anything else. */
function poster_img(mixed $src): string
{
    return is_string($src) && preg_match('#^(uploads|assets)/[A-Za-z0-9/_.-]+\.(webp|jpg|jpeg|png)$#', $src) && !str_contains($src, '..')
        ? site_base() . $src : '';
}

/** ['brand', 'tail', 'items' => [{id, group, kind, title, text, img, url, price?, date?}]], home first. */
function poster_targets(): array
{
    $base = ref_site_origin() . site_base();
    $f = site_live()['fields'] ?? [];
    $items = [[
        'id' => 'home', 'group' => '首页', 'kind' => '首页',
        'title' => (string) ($f['site.brand'] ?? ''), 'text' => (string) ($f['site.description'] ?? ''),
        'img' => poster_img('assets/hero-poster-portrait.webp'), 'url' => $base,
    ]];
    foreach (posts_all() as $p) {
        if (post_status($p) !== 'published') {
            continue;
        }
        $l = $p['live'];
        $items[] = [
            'id' => 'p-' . $p['id'], 'group' => '飞行日志', 'kind' => '飞行日志',
            'title' => (string) ($l['title'] ?? ''), 'text' => (string) ($l['excerpt'] ?? ''),
            'img' => poster_img($l['cover']['src'] ?? null), 'date' => (string) ($l['date'] ?? ''),
            'url' => $base . 'posts/' . $p['slug'] . '.html',
        ];
    }
    foreach (shop_products() as $p) {
        if (!shop_product_live($p) || $p['slug'] === '') {
            continue;
        }
        $items[] = [
            'id' => 's-' . $p['id'], 'group' => '商城', 'kind' => SHOP_TYPES[$p['type']] ?? '商品',
            'title' => $p['name'], 'text' => $p['summary'], 'img' => poster_img($p['photos'][0]['src'] ?? null),
            'price' => '¥' . shop_money($p['price']), 'url' => $base . 'shop/' . $p['slug'] . '.html',
        ];
    }
    return ['brand' => (string) ($f['site.brand'] ?? ''), 'tail' => (string) ($f['site.title_tail'] ?? ''), 'items' => $items];
}
