<?php
// 商品分类 (SPEC §20 「分类」): two levels, 大类 (top) holding optional 小类 (subs). Stored in blog-data/shop/categories.json
// under the shop lock, like products. A product's `cat` is '' (不分类), a 大类 id or a 小类 id. Ids are random and never
// change, so links like /#shop/c/<id> keep working after a rename or a move. Categories are live shop data: edits
// republish the site without a version entry. Names are plain text and escaped wherever they are shown.
declare(strict_types=1);
defined('BLOG_APP') || exit;

const SHOP_CAT_ID_RE = '/^c[0-9a-f]{8}$/';
const SHOP_CATS_MAX = 20;          // 大类
const SHOP_SUBS_MAX = 30;          // 小类 in one 大类
const SHOP_CAT_NAME_MAX = 12;
const SHOP_CAT_OTHER = 'other';    // the public 其他 tab (products without a category)

function shop_cats_path(): string
{
    return shop_dir('categories.json');
}

/** The categories document, normalized: bad ids, duplicate ids and empty names are dropped, limits applied. */
function shop_cats_doc(): array
{
    $d = json_read(shop_cats_path(), []);
    $d = is_array($d) ? $d : [];
    $seen = [];
    $items = [];
    foreach ((is_array($d['items'] ?? null) ? $d['items'] : []) as $c) {
        if (count($items) >= SHOP_CATS_MAX) {
            break;
        }
        $id = is_array($c) ? (string) ($c['id'] ?? '') : '';
        $name = is_array($c) && is_string($c['name'] ?? null) ? $c['name'] : '';
        if (!preg_match(SHOP_CAT_ID_RE, $id) || isset($seen[$id]) || $name === '') {
            continue;
        }
        $seen[$id] = true;
        $subs = [];
        foreach ((is_array($c['subs'] ?? null) ? $c['subs'] : []) as $s) {
            if (count($subs) >= SHOP_SUBS_MAX) {
                break;
            }
            $sid = is_array($s) ? (string) ($s['id'] ?? '') : '';
            $sname = is_array($s) && is_string($s['name'] ?? null) ? $s['name'] : '';
            if (!preg_match(SHOP_CAT_ID_RE, $sid) || isset($seen[$sid]) || $sname === '') {
                continue;
            }
            $seen[$sid] = true;
            $subs[] = ['id' => $sid, 'name' => $sname];
        }
        $items[] = ['id' => $id, 'name' => $name, 'subs' => $subs];
    }
    return ['rev' => (int) ($d['rev'] ?? 0), 'items' => $items];
}

/** 大类 in the owner's order, each with its 小类. */
function shop_cats(): array
{
    return shop_cats_doc()['items'];
}

/** id => ['id', 'name', 'top' => 大类 id, 'top_name', 'sub' => bool] for every 大类 and 小类. */
function shop_cat_index(array $cats): array
{
    $ix = [];
    foreach ($cats as $c) {
        $ix[$c['id']] = ['id' => $c['id'], 'name' => $c['name'], 'top' => $c['id'], 'top_name' => $c['name'], 'sub' => false];
        foreach ($c['subs'] as $s) {
            $ix[$s['id']] = ['id' => $s['id'], 'name' => $s['name'], 'top' => $c['id'], 'top_name' => $c['name'], 'sub' => true];
        }
    }
    return $ix;
}

/** Where a product sits: ['top' => 大类 id|null, 'sub' => 小类 id|null] (null top: 不分类, or its category is gone). */
function shop_cat_of(array $p, array $ix): array
{
    $e = $ix[$p['cat'] ?? ''] ?? null;
    if ($e === null) {
        return ['top' => null, 'sub' => null];
    }
    return ['top' => $e['top'], 'sub' => $e['sub'] ? $e['id'] : null];
}

/** 「大类 / 小类」 label for a category id ('' when none or gone). */
function shop_cat_label(string $id, array $ix): string
{
    $e = $ix[$id] ?? null;
    if ($e === null) {
        return '';
    }
    return $e['sub'] ? $e['top_name'] . ' / ' . $e['name'] : $e['name'];
}

/** Product counts per category id (a product in a 小类 counts for the 小类 and its 大类). */
function shop_cat_counts(array $cats, array $products): array
{
    $ix = shop_cat_index($cats);
    $n = array_fill_keys(array_keys($ix), 0) + ['' => 0];
    foreach ($products as $p) {
        $w = shop_cat_of($p, $ix);
        if ($w['top'] === null) {
            $n['']++;
            continue;
        }
        $n[$w['top']]++;
        if ($w['sub'] !== null) {
            $n[$w['sub']]++;
        }
    }
    return $n;
}

/**
 * The public category tree for the products shown on the site: only categories that hold a shown product, in the
 * owner's order. ['cats' => [[id, name, n, direct, subs => [[id, name, n]]]], 'other' => n of shown products with no
 * category]. Empty 'cats' means the home page shows no tabs at all.
 */
function shop_cats_public(array $cats, array $shown): array
{
    $n = shop_cat_counts($cats, $shown);
    $out = [];
    foreach ($cats as $c) {
        if (($n[$c['id']] ?? 0) === 0) {
            continue;
        }
        $subs = [];
        $inSubs = 0;
        foreach ($c['subs'] as $s) {
            if (($n[$s['id']] ?? 0) > 0) {
                $subs[] = ['id' => $s['id'], 'name' => $s['name'], 'n' => $n[$s['id']]];
                $inSubs += $n[$s['id']];
            }
        }
        $out[] = ['id' => $c['id'], 'name' => $c['name'], 'n' => $n[$c['id']], 'direct' => $n[$c['id']] - $inSubs, 'subs' => $subs];
    }
    return ['cats' => $out, 'other' => $out ? $n[''] : 0];
}

/** A category name as typed: one plain line, at most SHOP_CAT_NAME_MAX characters. Returns [name, error|null]. */
function shop_cat_name_input(mixed $raw): array
{
    $name = shop_clean_line($raw, SHOP_CAT_NAME_MAX);
    if ($name === '') {
        return ['', '请填分类名称。'];
    }
    if (mb_strlen($name) > SHOP_CAT_NAME_MAX) {
        return [$name, '分类名称最多 ' . SHOP_CAT_NAME_MAX . ' 个字。'];
    }
    return [$name, null];
}

/**
 * Change the categories under the shop lock. $fn(array &$cats, array $products) returns any value; the file is
 * written when the categories changed, then the site is regenerated (no version entry) with $reason.
 */
function shop_cats_mutate(string $reason, callable $fn): mixed
{
    shop_ensure_dirs();
    [$res, $changed] = with_lock('shop', function () use ($fn) {
        $doc = shop_cats_doc();
        $cats = $doc['items'];
        $before = $cats;
        $res = $fn($cats, shop_products());
        if ($cats !== $before) {
            json_write_atomic(shop_cats_path(), ['rev' => $doc['rev'] + 1, 'updated_at' => now_iso(), 'items' => array_values($cats)]);
            return [$res, true];
        }
        return [$res, false];
    });
    if ($changed) {
        $r = publish_site($reason, ['version' => false]);
        if (!$r['ok']) {
            error_log('shop cats republish failed');
        }
    }
    return $res;
}

function shop_cat_new_id(array $cats): string
{
    $ix = shop_cat_index($cats);
    do {
        $id = 'c' . bin2hex(random_bytes(4));
    } while (isset($ix[$id]));
    return $id;
}

/** Same name twice at one level would be two tabs nobody can tell apart. */
function shop_cat_name_taken(array $siblings, string $name, string $exceptId = ''): bool
{
    foreach ($siblings as $s) {
        if ($s['id'] !== $exceptId && mb_strtolower($s['name']) === mb_strtolower($name)) {
            return true;
        }
    }
    return false;
}

/** Add a 大类 ($parent '') or a 小类 inside the 大类 $parent. Returns the new entry. */
function shop_cat_add(string $name, string $parent = ''): array
{
    [$name, $err] = shop_cat_name_input($name);
    if ($err !== null) {
        throw new ShopError('invalid', $err, ['name' => $err]);
    }
    return shop_cats_mutate('添加商品分类', function (array &$cats) use ($name, $parent) {
        if ($parent === '') {
            if (count($cats) >= SHOP_CATS_MAX) {
                throw new ShopError('full', '大类最多 ' . SHOP_CATS_MAX . ' 个。');
            }
            if (shop_cat_name_taken($cats, $name)) {
                throw new ShopError('dup', '已经有一个叫「' . $name . '」的大类了。', ['name' => '已经有这个大类了。']);
            }
            $c = ['id' => shop_cat_new_id($cats), 'name' => $name, 'subs' => []];
            $cats[] = $c;
            return $c;
        }
        foreach ($cats as $k => $c) {
            if ($c['id'] !== $parent) {
                continue;
            }
            if (count($c['subs']) >= SHOP_SUBS_MAX) {
                throw new ShopError('full', '每个大类最多 ' . SHOP_SUBS_MAX . ' 个小类。');
            }
            if (shop_cat_name_taken($c['subs'], $name)) {
                throw new ShopError('dup', '「' . $c['name'] . '」里已经有一个叫「' . $name . '」的小类了。', ['name' => '已经有这个小类了。']);
            }
            $s = ['id' => shop_cat_new_id($cats), 'name' => $name];
            $cats[$k]['subs'][] = $s;
            return $s;
        }
        throw new ShopError('gone', '找不到这个大类，可能已经删除了。刷新页面看看。');
    });
}

function shop_cat_rename(string $id, string $name): void
{
    [$name, $err] = shop_cat_name_input($name);
    if ($err !== null) {
        throw new ShopError('invalid', $err, ['name' => $err]);
    }
    shop_cats_mutate('修改商品分类名称', function (array &$cats) use ($id, $name) {
        foreach ($cats as $k => $c) {
            if ($c['id'] === $id) {
                if (shop_cat_name_taken($cats, $name, $id)) {
                    throw new ShopError('dup', '已经有一个叫「' . $name . '」的大类了。');
                }
                $cats[$k]['name'] = $name;
                return;
            }
            foreach ($c['subs'] as $j => $s) {
                if ($s['id'] === $id) {
                    if (shop_cat_name_taken($c['subs'], $name, $id)) {
                        throw new ShopError('dup', '「' . $c['name'] . '」里已经有一个叫「' . $name . '」的小类了。');
                    }
                    $cats[$k]['subs'][$j]['name'] = $name;
                    return;
                }
            }
        }
        throw new ShopError('gone', '找不到这个分类，可能已经删除了。刷新页面看看。');
    });
}

/** Move a 大类 among the 大类, or a 小类 inside its 大类, one place up or down. Returns false at the end of the list. */
function shop_cat_move(string $id, string $dir): bool
{
    return (bool) shop_cats_mutate('调整商品分类顺序', function (array &$cats) use ($id, $dir) {
        $swap = function (array &$list, int $i) use ($dir): bool {
            $j = $dir === 'up' ? $i - 1 : $i + 1;
            if (!isset($list[$j])) {
                return false;
            }
            [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
            return true;
        };
        foreach ($cats as $k => $c) {
            if ($c['id'] === $id) {
                return $swap($cats, $k);
            }
            foreach ($c['subs'] as $j => $s) {
                if ($s['id'] === $id) {
                    return $swap($cats[$k]['subs'], $j);
                }
            }
        }
        throw new ShopError('gone', '找不到这个分类，可能已经删除了。刷新页面看看。');
    });
}

/**
 * Delete a category. Refused while any product (on sale or not) sits in it, or in one of its 小类: the owner moves
 * those products first, so nothing silently changes category. A 大类 whose 小类 are all empty goes with them.
 * Returns the deleted entry.
 */
function shop_cat_delete(string $id): array
{
    return shop_cats_mutate('删除商品分类', function (array &$cats, array $products) use ($id) {
        $n = shop_cat_counts($cats, $products);
        foreach ($cats as $k => $c) {
            if ($c['id'] === $id) {
                if ($n[$id] > 0) {
                    throw new ShopError('state', '「' . $c['name'] . '」' . ($c['subs'] ? '和它的小类' : '') . '里还有 ' . $n[$id] . ' 件商品，不能删除。先打开这些商品，把分类换成别的（或「不分类」），再来删除。');
                }
                array_splice($cats, $k, 1);
                return $c;
            }
            foreach ($c['subs'] as $j => $s) {
                if ($s['id'] === $id) {
                    if ($n[$id] > 0) {
                        throw new ShopError('state', '小类「' . $s['name'] . '」里还有 ' . $n[$id] . ' 件商品，不能删除。先打开这些商品，把分类换成别的（或「不分类」），再来删除。');
                    }
                    array_splice($cats[$k]['subs'], $j, 1);
                    return $s;
                }
            }
        }
        throw new ShopError('gone', '找不到这个分类，可能已经删除了。刷新页面看看。');
    });
}

/** The editor's category field: '' (不分类) or an existing id. Returns [id, error|null]. */
function shop_cat_input(mixed $raw, array $cats): array
{
    $id = is_string($raw) ? trim($raw) : '';
    if ($id === '') {
        return ['', null];
    }
    if (!isset(shop_cat_index($cats)[$id])) {
        return ['', '这个分类不存在了，可能刚被删除。请重新选一个。'];
    }
    return [$id, null];
}

/** Options for the category select: [[id, label, top id, is sub]] with '' first (不分类). */
function shop_cat_options(array $cats): array
{
    $o = [['', '不分类', '', false]];
    foreach ($cats as $c) {
        $o[] = [$c['id'], $c['name'], $c['id'], false];
        foreach ($c['subs'] as $s) {
            $o[] = [$s['id'], $c['name'] . ' / ' . $s['name'], $c['id'], true];
        }
    }
    return $o;
}

/** The shop part of the home context (also used for the product pages): what is shown, and its category tree. */
function shop_home_ctx(array $products, array $settings): array
{
    $shown = array_values(array_filter($products, fn($p) => shop_product_live($p, $settings)));
    $cats = $shown ? shop_cats() : [];
    return [
        'open' => (bool) $settings['shop']['enabled'],
        'notice' => (string) $settings['shop']['notice'],
        'products' => $shown,
        'cats' => shop_cats_public($cats, $shown),
        'ix' => shop_cat_index($cats),
    ];
}
