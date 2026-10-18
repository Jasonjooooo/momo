<?php
// Admin controllers for the shop (SPEC §20): 商品, 订单, 收款设置. Plain server-rendered forms (no fetch), so a slow
// phone connection never leaves a half-done action; destructive steps use the confirm sheet (data-confirm).
declare(strict_types=1);
defined('BLOG_APP') || exit;

const ORDER_FILTERS = [
    'todo' => '要处理',
    'claimed' => '买家已付款',
    'pending' => '待付款',
    'to_ship' => '待发货',
    'failed' => '进货失败',
    'shipped' => '已发货',
    'completed' => '已完成',
    'cancelled' => '已取消',
    'all' => '全部',
];
const ORDER_CHIP = ['pending' => 'chip-warn', 'claimed' => 'chip-hot', 'confirmed' => 'chip-ok', 'shipped' => 'chip-ok', 'completed' => '', 'cancelled' => 'chip-line', 'failed' => 'chip-hot'];

function admin_shop_maintain(): void
{
    try {
        shop_maintain();
    } catch (Throwable $e) {
        error_log('admin shop maintain: ' . get_class($e));
    }
}

/** Orders that need the owner: the buyer says they paid, or a paid physical order waits for shipping. */
function shop_attention_count(): int
{
    try {
        $c = shop_counts(shop_orders());
        return $c['claimed'] + $c['to_ship'] + $c['failed'];
    } catch (Throwable $e) {
        return 0;
    }
}

function shop_flash_error(Throwable $e, string $prefix = '没有保存：'): void
{
    if ($e instanceof ShopError || $e instanceof ValidationError) {
        $msg = $e instanceof ValidationError ? (string) reset($e->fields) : $e->getMessage();
        flash('error', $prefix . $msg);
        return;
    }
    error_log('admin shop ' . ($_GET['r'] ?? '') . ': ' . get_class($e) . ' ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    flash('error', $prefix . '服务器出错了，稍后再试。');
}

function post_id(string $re, string $k = 'id'): string
{
    $v = post_str($k, 40);
    return preg_match($re, $v) ? $v : '';
}

// ---------- 商品 ----------

function admin_shop(): void
{
    admin_shop_maintain();
    $cats = shop_cats();
    $ix = shop_cat_index($cats);
    $f = is_string($_GET['cat'] ?? null) ? $_GET['cat'] : '';
    $f = $f === 'none' || isset($ix[$f]) ? $f : '';
    view('shop', ['title' => '商品', 'products' => shop_products(), 'settings' => settings_get(), 'cats' => $cats, 'cat_ix' => $ix, 'cat_filter' => $f]);
}

/** Does product $p belong in the 商品 list filtered by $f ('' all, 'none' 不分类, or a category id)? */
function admin_shop_cat_match(array $p, string $f, array $ix): bool
{
    if ($f === '') {
        return true;
    }
    $w = shop_cat_of($p, $ix);
    return $f === 'none' ? $w['top'] === null : ($w['top'] === $f || $w['sub'] === $f);
}

/** The category picker of the product editor and the 货源 import: one plain select (works without script);
 *  admin.js turns it into a 大类 select and a dependent 小类 select that write into it. */
function admin_cat_select(string $id, string $selected, ?string $err = null): string
{
    $cats = shop_cats();
    $opts = '';
    foreach (shop_cat_options($cats) as [$v, $label, $top, $sub]) {
        $opts .= '<option value="' . h($v) . '"' . ($v !== '' ? ' data-top="' . h($top) . '"' . ($sub ? ' data-sub="1"' : '') : '')
            . ($v === $selected ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return '<div class="field catpick' . ($err !== null ? ' has-err' : '') . '" data-catpick>'
        . '<label for="' . h($id) . '">分类</label>'
        . '<select id="' . h($id) . '" name="cat">' . $opts . '</select>'
        . '<p class="hint">' . ($cats ? '可以放在大类里，也可以放在大类下面的小类里。首页商城按分类筛选。' : '还没有分类，商品都在「全部」里。') . ' <a href="' . h(url('cats')) . '">管理分类</a></p>'
        . '<p class="ferr"' . ($err === null ? ' hidden' : '') . '>' . h((string) $err) . '</p></div>';
}

// ---------- 商品分类 ----------

function admin_cats(): void
{
    $cats = shop_cats();
    view('cats', ['title' => '商品分类', 'cats' => $cats, 'counts' => shop_cat_counts($cats, shop_products()), 'live' => shop_cat_counts($cats, array_filter(shop_products(), fn($p) => shop_product_live($p)))]);
}

function post_cat_id(string $k = 'id'): string
{
    $v = post_str($k, 20);
    return preg_match(SHOP_CAT_ID_RE, $v) ? $v : '';
}

function admin_cats_add(): void
{
    $parent = post_cat_id('parent');
    try {
        $c = shop_cat_add(post_str('name', 400), $parent);
        audit('shop_cat_added', $c['id']);
        flash('ok', ($parent === '' ? '已添加大类「' : '已添加小类「') . $c['name'] . '」。');
    } catch (Throwable $e) {
        shop_flash_error($e, '没有添加：');
    }
    redirect('cats');
}

function admin_cats_rename(): void
{
    try {
        shop_cat_rename(post_cat_id(), post_str('name', 400));
        flash('ok', '名称已保存，网站已经更新。');
    } catch (Throwable $e) {
        shop_flash_error($e, '没有改名：');
    }
    redirect('cats');
}

function admin_cats_move(): void
{
    try {
        shop_cat_move(post_cat_id(), post_str('dir', 5) === 'up' ? 'up' : 'down');
    } catch (Throwable $e) {
        shop_flash_error($e, '没有调整：');
    }
    redirect('cats');
}

function admin_cats_delete(): void
{
    $id = post_cat_id();
    try {
        $c = shop_cat_delete($id);
        audit('shop_cat_deleted', $id);
        flash('ok', '已删除「' . $c['name'] . '」。');
    } catch (Throwable $e) {
        shop_flash_error($e, '没有删除：');
    }
    redirect('cats');
}

function admin_shop_new(): void
{
    try {
        $p = shop_product_new();
    } catch (Throwable $e) {
        shop_flash_error($e, '没有新建：');
        redirect('shop');
    }
    redirect('shop.edit', ['id' => $p['id']]);
}

function admin_shop_edit(array $form = [], array $errors = []): void
{
    $id = is_string($_GET['id'] ?? null) ? $_GET['id'] : post_str('id', 40);
    $p = preg_match(PRODUCT_ID_RE, $id) ? shop_product_get($id) : null;
    if ($p !== null && $p['type'] === 'supply' && $form === []) {
        try {
            $p = supply_refresh_product($p['id']) ?? $p;   // the supplier's stock, at most 5 minutes old
        } catch (Throwable $e) {
            error_log('admin shop edit refresh: ' . get_class($e));
        }
    }
    if ($p === null) {
        http_response_code(404);
        view('error', ['title' => '找不到这件商品', 'message' => '这件商品不存在，可能已经删除了。', 'back' => url('shop')]);
        return;
    }
    $has = false;
    foreach (shop_orders() as $o) {
        if ($o['product_id'] === $p['id']) {
            $has = true;
            break;
        }
    }
    view('shop_edit', [
        'title' => $p['name'] !== '' ? $p['name'] : '新商品',
        'p' => $p,
        'form' => $form,
        'errors' => $errors,
        'has_orders' => $has,
        'upload_mb' => intdiv(upload_limit_bytes(), 1048576),
        'file_mb' => intdiv(shop_file_limit(), 1048576),
    ]);
}

function admin_shop_save(): void
{
    $id = post_id(PRODUCT_ID_RE);
    $in = [];
    foreach (['name', 'slug', 'type', 'price', 'stock', 'stock_seen', 'unlimited', 'on_sale', 'summary', 'body', 'dl_text', 'dl_link', 'cat'] as $k) {
        $in[$k] = post_str($k, 20000);
    }
    $alts = $_POST['photo_alt'] ?? [];
    $in['photo_alt'] = is_array($alts) ? array_map(fn($v) => is_string($v) ? mb_substr($v, 0, 200) : '', array_slice($alts, 0, SHOP_PHOTOS_MAX, true)) : [];
    try {
        $p = shop_product_save($id, $in, (int) post_str('rev', 20));
    } catch (ShopError $e) {
        if ($e->code_ === 'invalid') {
            http_response_code(400);
            flash('error', '没有保存：' . $e->getMessage());
            $_GET['id'] = $id;
            admin_shop_edit($in, $e->fields);
            return;
        }
        shop_flash_error($e);
        redirect($id !== '' ? 'shop.edit' : 'shop', $id !== '' ? ['id' => $id] : []);
    } catch (Throwable $e) {
        shop_flash_error($e);
        redirect('shop.edit', ['id' => $id]);
    }
    flash('ok', $p['on_sale'] ? '已保存。商品在卖，网站已经更新。' : '已保存。商品没有上架，访客看不到。');
    redirect('shop.edit', ['id' => $id]);
}

function admin_shop_photo(): void
{
    $id = post_id(PRODUCT_ID_RE);
    $file = $_FILES['image'] ?? null;
    try {
        if (shop_product_get($id) === null) {
            throw new ShopError('gone', '找不到这件商品，可能已经删除了。');
        }
        if (!is_array($file) || is_array($file['name'] ?? null) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
            throw new ShopError('invalid', $len > 0 && empty($_POST) ? '图片太大了，最大 ' . intdiv(upload_limit_bytes(), 1048576) . ' MB。' : '没有收到图片，先选一张再点上传。');
        }
        if (count(shop_product_get($id)['photos']) >= SHOP_PHOTOS_MAX) {
            throw new ShopError('full', '每件商品最多 ' . SHOP_PHOTOS_MAX . ' 张照片。');
        }
        $r = image_accept_upload($file, 'product');
        shop_product_photo($id, 'add', 0, $r);
        flash('ok', '照片已上传。');
    } catch (Throwable $e) {
        shop_flash_error($e, '没有上传：');
    }
    redirect('shop.edit', ['id' => $id]);
}

function admin_shop_photo_op(): void
{
    $id = post_id(PRODUCT_ID_RE);
    $op = post_str('op', 10);
    try {
        if (!in_array($op, ['up', 'down', 'del'], true)) {
            throw new ShopError('invalid', '没有这个操作。');
        }
        shop_product_photo($id, $op, (int) post_str('i', 4));
        flash('ok', $op === 'del' ? '照片已删除。' : '照片顺序已调整。');
    } catch (Throwable $e) {
        shop_flash_error($e, '没有完成：');
    }
    redirect('shop.edit', ['id' => $id]);
}

function admin_shop_file(): void
{
    $id = post_id(PRODUCT_ID_RE);
    $file = $_FILES['file'] ?? null;
    try {
        if (!is_array($file) || is_array($file['name'] ?? null) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
            throw new ShopError('invalid', $len > 0 && empty($_POST) ? '文件太大了，最大 ' . intdiv(shop_file_limit(), 1048576) . ' MB。大文件请放网盘，填链接。' : '没有收到文件，先选一个再点上传。');
        }
        $err = (int) $file['error'];
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new ShopError('too_large', '文件太大了，最大 ' . intdiv(shop_file_limit(), 1048576) . ' MB。大文件请放网盘，填链接。');
        }
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new ShopError('invalid', '文件没有传上来，再试一次。');
        }
        shop_product_file_set($id, (string) $file['tmp_name'], (string) $file['name'], (int) $file['size']);
        audit('shop_file', $id);
        flash('ok', '文件已上传。买家确认收款后可以在「查订单」里下载。');
    } catch (Throwable $e) {
        shop_flash_error($e, '没有上传：');
    }
    redirect('shop.edit', ['id' => $id]);
}

function admin_shop_file_del(): void
{
    $id = post_id(PRODUCT_ID_RE);
    try {
        shop_product_file_remove($id);
        flash('ok', '文件已删除。');
    } catch (Throwable $e) {
        shop_flash_error($e, '没有删除：');
    }
    redirect('shop.edit', ['id' => $id]);
}

function admin_shop_move(): void
{
    try {
        shop_product_move(post_id(PRODUCT_ID_RE), post_str('dir', 5) === 'up' ? 'up' : 'down');
    } catch (Throwable $e) {
        shop_flash_error($e, '没有调整：');
    }
    redirect('shop');
}

function admin_shop_delete(): void
{
    $id = post_id(PRODUCT_ID_RE);
    try {
        $p = shop_product_delete($id);
        audit('shop_product_deleted', $id);
        flash('ok', '已删除商品' . ($p['name'] !== '' ? '《' . $p['name'] . '》' : '') . '。');
    } catch (Throwable $e) {
        shop_flash_error($e, '没有删除：');
        redirect('shop.edit', ['id' => $id]);
    }
    redirect('shop');
}

// ---------- 订单 ----------

function admin_orders(): void
{
    admin_shop_maintain();
    $f = is_string($_GET['f'] ?? null) && isset(ORDER_FILTERS[$_GET['f']]) ? $_GET['f'] : 'todo';
    $q = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 40) : '';
    $page = is_string($_GET['p'] ?? null) ? max(1, (int) $_GET['p']) : 1;
    $all = shop_orders();
    $counts = shop_counts($all);
    $counts['todo'] = $counts['claimed'] + $counts['pending'] + $counts['to_ship'] + $counts['failed'];
    $rows = array_values(array_filter(array_reverse($all), function ($o) use ($f, $q) {
        $ok = match ($f) {
            'all' => true,
            'todo' => in_array($o['status'], ['claimed', 'pending', 'failed'], true) || ($o['status'] === 'confirmed' && $o['type'] === 'physical'),
            'to_ship' => $o['status'] === 'confirmed' && $o['type'] === 'physical',
            default => $o['status'] === $f,
        };
        return $ok && shop_order_matches($o, $q);
    }));
    if ($f === 'todo') {
        $rank = ['failed' => 0, 'claimed' => 1, 'confirmed' => 2, 'pending' => 3];
        usort($rows, fn($a, $b) => ($rank[$a['status']] ?? 3) <=> ($rank[$b['status']] ?? 3) ?: strcmp($b['created_at'], $a['created_at']));
    }
    $per = 30;
    $pages = max(1, (int) ceil(count($rows) / $per));
    $page = min($page, $pages);
    view('orders', [
        'title' => '订单',
        'filter' => $f,
        'q' => $q,
        'counts' => $counts,
        'rows' => array_slice($rows, ($page - 1) * $per, $per),
        'total' => count($rows),
        'page' => $page,
        'pages' => $pages,
    ]);
}

function admin_order(): void
{
    admin_shop_maintain();
    $id = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
    $o = shop_order_get($id);
    if ($o === null) {
        http_response_code(404);
        view('error', ['title' => '找不到这个订单', 'message' => '订单号不对，或者订单不存在。', 'back' => url('orders')]);
        return;
    }
    if ($o['type'] === 'supply' && !empty($o['supply']['pending'])) {
        try {
            $o = supply_poll($o['id']) ?? $o;   // the card was not out yet: ask again (once a minute)
        } catch (Throwable $e) {
            error_log('admin order poll: ' . get_class($e));
        }
    }
    view('order', ['title' => '订单 ' . $o['id'], 'o' => $o, 'product' => shop_product_get($o['product_id'])]);
}

function admin_order_act(): void
{
    $id = post_id(ORDER_ID_RE);
    $act = post_str('act', 10);
    $before = shop_page_signature(shop_products());
    if (in_array($act, ['retry', 'accept', 'fresh', 'poll'], true)) {
        admin_order_supply($id, $act);
        return;
    }
    try {
        $o = shop_owner_action($id, $act, ['courier' => post_str('courier', 200), 'tracking' => post_str('tracking', 200), 'reason' => post_str('reason', 500), 'note' => post_str('note', 500)]);
        audit('order_' . $act, $id);
        if ($act === 'confirm' && $o['type'] === 'supply') {
            // paid and confirmed by the owner: now buy the card (once; see supply_fulfill)
            $r = supply_fulfill($id, 'confirm');
            audit('supply_trade', $id . ($r['ok'] ? ' ok' : ' failed'));
            shop_republish_if_changed($before);
            flash($r['ok'] ? 'ok' : 'error', '已确认收款。' . $r['msg']);
            redirect('order', ['id' => $id]);
        }
        shop_republish_if_changed($before);
        flash('ok', match ($act) {
            'confirm' => $o['type'] === 'digital' ? '已确认收款。买家现在可以在「查订单」里看到内容。' : '已确认收款。寄出以后记得填快递单号。',
            'ship' => '已记下快递信息，买家在「查订单」里能看到。',
            'complete' => '订单已完成。',
            'cancel' => '订单已取消，库存已经放回。',
            'refund' => '已记下退款。',
            default => '已完成。',
        });
    } catch (Throwable $e) {
        shop_flash_error($e, '没有完成：');
    }
    redirect('order', ['id' => $id]);
}

function admin_orders_export(): void
{
    admin_shop_maintain();
    $orders = shop_orders();
    $csv = shop_csv(array_reverse($orders));
    audit('orders_exported', (string) count($orders));
    send_headers('csv');
    header('Content-Disposition: attachment; filename="orders-' . str_replace('-', '', local_day()) . '.csv"');
    header('Content-Length: ' . strlen($csv));
    echo $csv;
}

/** 重试进货 / 按新成本进货 / 确认没买成，重新进货 / 刷新卡密 on a supply order. */
function admin_order_supply(string $id, string $act): void
{
    $before = shop_page_signature(shop_products());
    try {
        if ($act === 'poll') {
            $o = supply_poll($id, true);
            $pending = $o !== null && !empty($o['supply']['pending']);
            flash($pending ? 'info' : 'ok', $pending ? '供货平台还没发出卡密，过一会儿再刷新。' : '卡密已经到了，买家查订单时能看到。');
        } else {
            $accept = $act === 'accept' ? (int) post_str('cost', 12) : 0;
            $r = supply_fulfill($id, $act === 'accept' ? 'accept' : ($act === 'fresh' ? 'fresh' : 'retry'), $accept);
            audit('supply_' . $act, $id . ($r['ok'] ? ' ok' : ' failed'));
            flash($r['ok'] ? 'ok' : 'error', $r['msg']);
        }
        shop_republish_if_changed($before);
    } catch (Throwable $e) {
        shop_flash_error($e, '没有完成：');
    }
    redirect('order', ['id' => $id]);
}

// ---------- 收款设置 ----------

function admin_pay(array $errors = [], array $form = []): void
{
    view('pay', ['title' => '收款设置', 'settings' => settings_get(), 'errors' => $errors, 'form' => $form]);
}

function admin_pay_save(): void
{
    $rev = (int) post_str('rev', 20);
    $part = post_str('part', 10);
    if ($part === 'switch') {
        $patch = ['shop' => ['enabled' => post_str('enabled', 2) === '1']];
    } else {
        $form = ['shipping' => post_str('shipping', 40), 'free_over' => post_str('free_over', 40), 'cancel_hours' => post_str('cancel_hours', 40), 'notice' => post_str('notice', 2000)];
        $errors = [];
        $ship = shop_parse_money($form['shipping'] === '' ? '0' : $form['shipping']);
        $free = shop_parse_money($form['free_over'] === '' ? '0' : $form['free_over']);
        $hours = ascii_numbers($form['cancel_hours']);
        if ($ship === null) {
            $errors['shipping'] = '运费请填像 10 或 8.50 这样的数字，不收运费填 0。';
        }
        if ($free === null) {
            $errors['free_over'] = '包邮门槛请填数字，不包邮填 0。';
        }
        if (!ctype_digit($hours)) {
            $errors['cancel_hours'] = '请填整数小时，比如 24。';
        }
        if ($errors) {
            http_response_code(400);
            flash('error', '没有保存：' . reset($errors));
            admin_pay($errors, $form);
            return;
        }
        $patch = ['shop' => ['shipping' => $ship, 'free_over' => $free, 'cancel_hours' => (int) $hours, 'notice' => $form['notice'], 'pay_sub' => post_str('pay_sub', 2) === '1']];
    }
    try {
        settings_save($patch, $rev);
        flash('ok', $part === 'switch'
            ? ($patch['shop']['enabled'] ? '商城已开门，网站已经更新。' : '商城已暂停营业，网站已经更新。已经下的订单照常处理。')
            : '已保存收款设置，网站已经更新。');
    } catch (ValidationError $e) {
        http_response_code(400);
        flash('error', '没有保存：' . (string) reset($e->fields));
        admin_pay(array_combine(array_map(fn($k) => str_replace('shop.', '', (string) $k), array_keys($e->fields)), array_values($e->fields)), $form ?? []);
        return;
    } catch (ConflictException $e) {
        flash('error', '没有保存：设置在别处改过了，刷新页面再试。');
    } catch (Throwable $e) {
        shop_flash_error($e);
    }
    redirect('pay');
}

function admin_pay_qr(): void
{
    $kind = post_str('kind', 10);
    $file = $_FILES['image'] ?? null;
    try {
        if (!in_array($kind, ['wechat', 'alipay'], true)) {
            throw new ShopError('invalid', '收款码类型不对。');
        }
        if (!is_array($file) || is_array($file['name'] ?? null) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            $err = is_array($file) ? (int) ($file['error'] ?? 0) : 0;
            throw new ShopError('invalid', in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? '图片太大了。' : '没有收到图片，先选一张再点上传。');
        }
        $rel = shop_qr_store((string) $file['tmp_name'], $kind);
        $old = settings_get()['shop']['qr'][$kind];
        try {
            settings_save(['shop' => ['qr' => [$kind => $rel]]], (int) settings_get()['rev']);
        } catch (Throwable $e) {
            shop_qr_delete($rel);
            throw $e;
        }
        shop_qr_delete($old);
        audit('shop_qr', $kind);
        flash('ok', ($kind === 'wechat' ? '微信' : '支付宝') . '收款码已更新。请在付款页面用另一台手机扫一下，确认能付款。');
    } catch (Throwable $e) {
        shop_flash_error($e, '没有上传：');
    }
    redirect('pay');
}

function admin_pay_qr_del(): void
{
    $kind = post_str('kind', 10);
    try {
        if (!in_array($kind, ['wechat', 'alipay'], true)) {
            throw new ShopError('invalid', '收款码类型不对。');
        }
        $old = settings_get()['shop']['qr'][$kind];
        settings_save(['shop' => ['qr' => [$kind => null]]], (int) settings_get()['rev']);
        shop_qr_delete($old);
        flash('ok', '收款码已删除。');
    } catch (Throwable $e) {
        shop_flash_error($e, '没有删除：');
    }
    redirect('pay');
}
