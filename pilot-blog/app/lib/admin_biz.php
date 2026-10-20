<?php
// Admin controllers for 经营总览 (biz.php) and 推广 (referral.php). Plain server-rendered forms, like the shop pages.
declare(strict_types=1);
defined('BLOG_APP') || exit;

// ---------- 经营总览 ----------

function admin_biz(): void
{
    admin_shop_maintain();
    $range = biz_range($_GET);
    $settings = settings_get();
    view('biz', [
        'title' => '经营总览',
        'range' => $range,
        'rep' => biz_report($range, null, null, $settings),
        'today' => local_day(),
        'ref_on' => ref_enabled($settings),
    ]);
}

// ---------- 推广 ----------

function ref_flash_error(Throwable $e, string $prefix = '没有保存：'): void
{
    if ($e instanceof RefError || $e instanceof ShopError) {
        flash('error', $prefix . $e->getMessage());
        return;
    }
    error_log('admin ref ' . ($_GET['r'] ?? '') . ': ' . get_class($e) . ' ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    flash('error', $prefix . '服务器出错了，稍后再试。');
}

function admin_ref(array $form = [], array $errors = []): void
{
    admin_shop_maintain();
    $doc = ref_doc();
    $settings = settings_get();
    view('ref', [
        'title' => '推广',
        'settings' => $settings,
        'on' => ref_enabled($settings),
        'partners' => $doc['partners'],
        'ov' => ref_overview(null, $doc),
        'n_settlements' => count($doc['settlements']),
        'form' => $form,
        'errors' => $errors,
    ]);
}

function admin_ref_toggle(): void
{
    $on = post_str('enabled', 2) === '1';
    try {
        settings_save(['ref' => ['enabled' => $on]], (int) post_str('rev', 20));
        audit('ref_' . ($on ? 'on' : 'off'));
        flash('ok', $on ? '推广已打开：从推广链接进来的订单会记到推广人名下。' : '推广已关闭：推广链接还能打开，但新订单不再记到推广人名下。已经算好的佣金照常结算。');
    } catch (ConflictException $e) {
        flash('error', '没有保存：设置在别处改过了，刷新页面再试。');
    } catch (Throwable $e) {
        error_log('admin ref.toggle: ' . get_class($e));
        flash('error', '没有保存：服务器出错了。');
    }
    redirect('ref');
}

function admin_ref_add(): void
{
    $in = ['name' => post_str('name', 400), 'contact' => post_str('contact', 400), 'rate' => post_str('rate', 40)];
    try {
        $p = ref_partner_create($in);
        audit('ref_partner_add', $p['id']);
    } catch (RefError $e) {
        http_response_code(400);
        flash('error', '没有添加：' . $e->getMessage());
        admin_ref($in, $e->fields);
        return;
    } catch (Throwable $e) {
        ref_flash_error($e, '没有添加：');
        redirect('ref');
    }
    flash('ok', '已添加推广人。把下面的推广链接和查看页发给 TA。');
    redirect('ref.edit', ['id' => $p['id']]);
}

function admin_ref_partner_id(): string
{
    $id = is_string($_GET['id'] ?? null) ? $_GET['id'] : post_str('id', 20);
    return preg_match(REF_ID_RE, $id) ? $id : '';
}

function admin_ref_edit(array $form = [], array $errors = []): void
{
    admin_shop_maintain();
    $id = admin_ref_partner_id();
    $doc = ref_doc();
    $p = null;
    foreach ($doc['partners'] as $x) {
        if ($x['id'] === $id) {
            $p = $x;
        }
    }
    if ($p === null) {
        http_response_code(404);
        view('error', ['title' => '找不到这位推广人', 'message' => '这位推广人不存在。', 'back' => url('ref')]);
        return;
    }
    $sum = ref_partner_summary($p, shop_orders(), ref_settled_map($doc['settlements']));
    $to = is_string($_GET['to'] ?? null) ? $_GET['to'] : '';
    view('ref_edit', [
        'title' => $p['name'],
        'p' => $p,
        'sum' => $sum,
        'settlements' => array_reverse(array_values(array_filter($doc['settlements'], fn($s) => $s['pid'] === $p['id']))),
        'products' => array_values(array_filter(shop_products(), fn($x) => shop_product_live($x))),
        'to' => ref_target($to) ?? '',
        'on' => ref_enabled(),
        'form' => $form,
        'errors' => $errors,
    ]);
}

function admin_ref_save(): void
{
    $id = admin_ref_partner_id();
    $in = ['name' => post_str('name', 400), 'contact' => post_str('contact', 400), 'rate' => post_str('rate', 40), 'enabled' => post_str('enabled', 2)];
    try {
        $p = ref_partner_save($id, $in, (int) post_str('rev', 20));
        audit('ref_partner_save', $id);
    } catch (RefError $e) {
        if ($e->fields) {
            http_response_code(400);
            flash('error', '没有保存：' . $e->getMessage());
            $_GET['id'] = $id;
            admin_ref_edit($in, $e->fields);
            return;
        }
        ref_flash_error($e);
        redirect($id !== '' ? 'ref.edit' : 'ref', $id !== '' ? ['id' => $id] : []);
    } catch (Throwable $e) {
        ref_flash_error($e);
        redirect('ref');
    }
    flash('ok', $p['enabled'] ? '已保存。新的佣金比例只对以后的订单有效。' : '已保存。这位推广人已停用，链接还能打开，但新订单不再记给 TA。');
    redirect('ref.edit', ['id' => $id]);
}

function admin_ref_token(): void
{
    $id = admin_ref_partner_id();
    try {
        ref_partner_new_token($id);
        audit('ref_partner_token', $id);
        flash('ok', '已换了新的查看页链接，旧链接马上失效。把新链接发给推广人。');
    } catch (Throwable $e) {
        ref_flash_error($e, '没有换：');
    }
    redirect('ref.edit', ['id' => $id]);
}

function admin_ref_settle(): void
{
    $id = admin_ref_partner_id();
    $expect = ctype_digit(post_str('amount', 12)) ? (int) post_str('amount', 12) : -1;
    try {
        $s = ref_settle($id, $expect, post_str('note', 400));
        audit('ref_settle', $id . ' ' . $s['amount']);
        flash('ok', '已记下结算 ¥' . shop_money($s['amount']) . '（' . count($s['items']) . ' 单）。推广人的查看页里也能看到。');
    } catch (Throwable $e) {
        ref_flash_error($e, '没有结算：');
    }
    redirect('ref.edit', ['id' => $id]);
}

function admin_ref_export(): void
{
    $csv = ref_csv();
    audit('ref_exported');
    send_headers('csv');
    header('Content-Disposition: attachment; filename="ref-settlements-' . str_replace('-', '', local_day()) . '.csv"');
    header('Content-Length: ' . strlen($csv));
    echo $csv;
}
