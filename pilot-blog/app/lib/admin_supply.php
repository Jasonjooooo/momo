<?php
// Admin controllers for the 货源 (SPEC §20 货源): connection settings with a write-only key, 测试连接, the supplier's catalog
// and 上架 one product at a time. Plain server-rendered forms like the rest of the shop admin. The key never reaches a
// page: only its last 4 characters are shown.
declare(strict_types=1);
defined('BLOG_APP') || exit;

function admin_supply(): void
{
    $settings = settings_get();
    $ready = supply_ready($settings);
    $catalog = null;
    if ($ready) {
        try {
            $catalog = supply_catalog(false);
        } catch (Throwable $e) {
            error_log('admin supply catalog: ' . get_class($e));
            $catalog = ['ok' => false, 'msg' => '读取货源商品时服务器出错了，稍后再试。', 'cats' => []];
        }
    }
    $linked = [];
    foreach (supply_products() as $p) {
        $linked[$p['supply']['code']][] = $p;
    }
    view('supply', [
        'title' => '货源',
        'settings' => $settings,
        'ready' => $ready,
        'key_hint' => supply_key_hint(),
        'key_set_at' => supply_key_set_at(),
        'products' => supply_products(),
        'linked' => $linked,
        'catalog' => $catalog,
    ]);
}

function admin_supply_save(): void
{
    $rev = (int) post_str('rev', 20);
    $part = post_str('part', 10);
    try {
        if ($part === 'switch') {
            $on = post_str('enabled', 2) === '1';
            $s = settings_get()['supply'];
            if ($on && ($s['base_url'] === '' || $s['app_id'] === '' || supply_key() === null)) {
                throw new ShopError('invalid', '先填好供货平台网址、app_id 和密钥并保存，再打开货源。');
            }
            settings_save(['supply' => ['enabled' => $on]], $rev);
            audit('supply_switch', $on ? 'on' : 'off');
            flash('ok', $on ? '货源已打开。上架的货源商品会显示在商城里。' : '货源已关闭。货源商品不在商城显示，也不能下单；已经付款的订单仍可以在订单里重试进货。');
            redirect('supply');
        }
        $url = trim(post_str('base_url', 400));
        $appId = trim(post_str('app_id', 200));
        $key = trim(post_str('app_key', 400));
        $errors = [];
        $u = supply_url_parse($url);
        if (!$u['ok']) {
            $errors['base_url'] = $u['msg'];
        } else {
            $dns = supply_resolve($u['host']);
            if (!$dns['ok']) {
                $errors['base_url'] = $dns['msg'];
            }
            $url = $u['url'];
        }
        if (!preg_match(SUPPLY_APP_ID_RE, $appId)) {
            $errors['app_id'] = 'app_id 只能是字母、数字、短横线和下划线，最多 64 位。从供货平台的「店铺共享」或对接页面复制。';
        }
        if ($key !== '' && !preg_match(SUPPLY_KEY_RE, $key)) {
            $errors['app_key'] = '密钥只能是字母、数字和符号，6 到 200 位，不能有空格。';
        }
        if ($key === '' && supply_key() === null) {
            $errors['app_key'] = '请填对接密钥（app_key）。';
        }
        if ($errors) {
            http_response_code(400);
            flash('error', '没有保存：' . reset($errors));
            admin_supply_form_errors($errors, ['base_url' => $url, 'app_id' => $appId]);
            return;
        }
        settings_save(['supply' => ['base_url' => $url, 'app_id' => $appId]], $rev);
        if ($key !== '') {
            supply_key_set($key);
            audit('supply_key_set');
        }
        supply_cache_clear();
        flash('ok', '已保存。' . ($key !== '' ? '密钥结尾是 ' . (string) supply_key_hint() . '。' : '') . '点「测试连接」看看能不能连上。');
    } catch (ValidationError $e) {
        flash('error', '没有保存：' . (string) reset($e->fields));
    } catch (ConflictException $e) {
        flash('error', '没有保存：设置在别处改过了，刷新页面再试。');
    } catch (Throwable $e) {
        shop_flash_error($e);
    }
    redirect('supply');
}

/** Re-show the page with field errors (and the typed address and app_id, never the key). */
function admin_supply_form_errors(array $errors, array $form): void
{
    $settings = settings_get();
    view('supply', [
        'title' => '货源',
        'settings' => $settings,
        'ready' => supply_ready($settings),
        'key_hint' => supply_key_hint(),
        'key_set_at' => supply_key_set_at(),
        'products' => supply_products(),
        'linked' => [],
        'catalog' => null,
        'errors' => $errors,
        'form' => $form,
    ]);
}

function admin_supply_key_remove(): void
{
    try {
        supply_key_remove();
        audit('supply_key_removed');
        $s = settings_get();
        if ($s['supply']['enabled']) {
            settings_save(['supply' => ['enabled' => false]], $s['rev']);
        }
        supply_cache_clear();
        flash('ok', '密钥已删除，货源已关闭。');
    } catch (Throwable $e) {
        shop_flash_error($e, '没有删除：');
    }
    redirect('supply');
}

function admin_supply_test(): void
{
    try {
        $r = supply_test_connection();
        audit('supply_test', $r['ok'] ? 'ok' : 'failed');
        flash($r['ok'] ? 'ok' : 'error', $r['msg']);
    } catch (Throwable $e) {
        shop_flash_error($e, '测试失败：');
    }
    redirect('supply');
}

function admin_supply_refresh(): void
{
    supply_cache_clear();
    flash('ok', '已经重新读取供货平台的商品。');
    redirect('supply');
}

function admin_supply_item(array $form = [], array $errors = []): void
{
    $code = is_string($_GET['code'] ?? null) ? $_GET['code'] : post_str('code', 80);
    if (!preg_match(SUPPLY_CODE_RE, $code)) {
        http_response_code(404);
        view('error', ['title' => '找不到这件商品', 'message' => '商品编号不对。', 'back' => url('supply')]);
        return;
    }
    if (!supply_ready()) {
        flash('error', '货源还没打开或没设置好。');
        redirect('supply');
    }
    try {
        $d = supply_item($code, $form === [] && ($_GET['fresh'] ?? '') === '1');
    } catch (Throwable $e) {
        error_log('admin supply item: ' . get_class($e));
        $d = ['ok' => false, 'msg' => '服务器出错了，稍后再试。', 'item' => null];
    }
    $linked = array_values(array_filter(supply_products(), fn($p) => $p['supply']['code'] === $code));
    view('supply_item', [
        'title' => $d['ok'] ? $d['item']['name'] : '货源商品',
        'code' => $code,
        'd' => $d,
        'why' => $d['ok'] ? supply_unsupported($d['item']) : [],
        'linked' => $linked,
        'form' => $form,
        'errors' => $errors,
    ]);
}

function admin_supply_import(): void
{
    $code = post_str('code', 80);
    $in = ['race' => post_str('race', 400), 'name' => post_str('name', 400), 'price' => post_str('price', 40), 'body' => post_str('body', 20000), 'on_sale' => post_str('on_sale', 2), 'cat' => post_str('cat', 40)];
    try {
        $p = supply_import($code, $in);
        flash('ok', $p['on_sale'] ? '已上架《' . $p['name'] . '》，首页商城已经更新。可以在这里换照片、改介绍。' : '已加到商品里（没上架）。改好以后勾选「上架」保存。');
        redirect('shop.edit', ['id' => $p['id']]);
    } catch (ShopError $e) {
        http_response_code(400);
        flash('error', '没有上架：' . $e->getMessage());
        $_GET['code'] = $code;
        admin_supply_item($in + ['_posted' => '1'], $e->fields);
        return;
    } catch (Throwable $e) {
        shop_flash_error($e, '没有上架：');
    }
    redirect('supply.item', ['code' => $code]);
}

/** 刷新成本和库存 on a supply product's editor. */
function admin_supply_product(): void
{
    $id = post_id(PRODUCT_ID_RE);
    try {
        $p = shop_product_get($id);
        if ($p === null || $p['type'] !== 'supply') {
            throw new ShopError('gone', '找不到这件货源商品。');
        }
        if (!supply_ready()) {
            throw new ShopError('config', '货源还没打开或没设置好。');
        }
        $before = $p['supply']['cost_at'];
        $p = supply_refresh_product($id, 0, true);
        if ($p === null || $p['supply']['cost_at'] === $before) {
            throw new ShopError('supplier', '没有从供货平台读到成本，稍后再试。');
        }
        $msg = '成本 ¥' . shop_money($p['supply']['cost']) . '，库存 ' . (int) $p['stock'] . '。';
        if ($p['price'] < $p['supply']['cost']) {
            flash('error', $msg . '现在售价低于成本，卖一单亏一单。请调高售价，或者先下架。');
        } else {
            flash('ok', '已刷新：' . $msg);
        }
    } catch (Throwable $e) {
        shop_flash_error($e, '没有刷新：');
    }
    redirect('shop.edit', ['id' => $id]);
}
