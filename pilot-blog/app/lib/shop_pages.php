<?php
// 商城 pages: the static product pages (written by the publisher into shop/) and the buyer's live pages served by
// api/shop.php (order form, pay page, order lookup). One template (shop.tpl.html), no scripts: the CSP is
// script-src 'none', so the pages work the same in the WeChat browser. The one exception is the pay page while it shows
// a USDT quote: it loads assets/shop/qrcode.min.js and assets/shop/usdt.js (same origin, CSP script-src 'self') for the
// QR code, copy buttons, countdown and the poll; without them it still shows everything. Every value goes through
// enc_text/enc_attr.
declare(strict_types=1);
defined('BLOG_APP') || exit;

const SHOP_FIX_SVG = '<span class="fixmark" aria-hidden="true"><svg viewBox="-13 -13 26 26" focusable="false"><path d="M0,-11 L11,0 L0,11 L-11,0 Z"/></svg></span>';

function shop_css_href(): string
{
    $css = (string) @file_get_contents(PUBLIC_DIR . '/assets/shop.css');
    return '../assets/shop.css?v=' . substr(hash('sha256', $css), 0, 8);
}

/**
 * The page chrome around $main. $o: title, desc, main (string HTML, already escaped), private (bool: noindex, no
 * share card, no visit pixel), hit (counted path, product pages only), og_image, url.
 */
function render_shop_page(array $site, array $settings, array $o): string
{
    $ctx = page_base_ctx($site, $settings);
    $origin = rtrim($ctx['origin'], '/');
    $private = (bool) ($o['private'] ?? true);
    $main = (string) $o['main'];
    $ctx['values'] += [
        'shop_title' => enc_text((string) $o['title']),
        'shop_title_attr' => enc_attr((string) $o['title']),
        'shop_desc_attr' => enc_attr((string) ($o['desc'] ?? '')),
        'shop_css_href' => enc_attr(shop_css_href()),
        'shop_og_image' => enc_attr($origin . '/' . ($o['og_image'] ?? 'assets/og.jpg')),
        'shop_url' => enc_attr($origin . '/' . ($o['url'] ?? '')),
        'hit_path' => enc_attr(rawurlencode((string) ($o['hit'] ?? '/'))),
    ];
    $ctx['conds']['shop_private'] = $private;
    $ctx['conds']['stats_live'] = !$private && ($o['hit'] ?? null) !== null && $ctx['conds']['stats_live'];
    $ctx['blocks']['shop_main'] = fn(string $ind) => $main;
    return tpl_render(tpl_load('shop'), $ctx);
}

function shop_price_html(int $fen): string
{
    return '<small>¥</small>' . shop_money($fen);
}

/** 运费 line for a product. */
function shop_ship_text(array $p, array $ss): string
{
    if ($p['type'] === 'digital') {
        return '数字商品，不用寄送。确认收款后，在「查订单」里查看。';
    }
    if ($p['type'] === 'supply') {
        return '自动发卡，不用寄送。站长确认收款后系统自动发卡，卡密在「查订单」里查看。';
    }
    if ($ss['shipping'] <= 0) {
        return '包邮，快递寄出。';
    }
    return '运费每单 ¥' . shop_money($ss['shipping']) . ($ss['free_over'] > 0 ? '，满 ¥' . shop_money($ss['free_over']) . ' 包邮' : '') . '。';
}

/** The three plain steps of buying here, shown on product and order pages. */
function shop_how_html(string $type): string
{
    $last = match ($type) {
        'digital' => '我本人核对到账后确认，你在「查订单」里就能看到内容。确认是手工的，可能要等几个小时。',
        'supply' => '我本人核对到账后确认收款，确认以后系统自动发卡，卡密在「查订单」里查看。确认是手工的，可能要等几个小时。',
        default => '我本人核对到账后确认，然后寄快递，快递单号在「查订单」里能看到。确认是手工的，可能要等几个小时。',
    };
    return '<ol class="how"><li>点「购买」，填数量和' . ($type !== 'physical' ? '邮箱' : '收货信息') . '。</li>'
        . '<li>下单后页面会给出付款金额（比价格多几分钱，用来认出你的付款）。用微信或支付宝扫我的收款码，付这个金额，备注订单号。</li>'
        . '<li>' . $last . '</li></ol>';
}

/** Static product page (shop/<slug>.html). $k: its position on the home grid (S-01 …). */
function render_product_page(array $p, int $k, array $site, array $settings, array $shop = []): string
{
    $ss = $settings['shop'];
    $n = count($p['photos']);
    $slides = [];
    foreach ($p['photos'] as $i => $ph) {
        [$w, $h] = album_dims($ph['src']) ?? [1200, 1200];
        $slides[] = '<figure class="gal-slide"><img src="../' . enc_attr($ph['src']) . '" width="' . $w . '" height="' . $h . '" alt="' . enc_attr($ph['alt'] !== '' ? $ph['alt'] : $p['name']) . '" loading="' . ($i < 3 ? 'eager' : 'lazy') . '" decoding="async"></figure>';
    }
    $label = shop_avail_label($p);
    $avail = shop_avail($p);
    if (!$ss['enabled']) {
        $buy = '<p class="buy-note">商城暂停营业，现在不能下单。</p>';
    } elseif ($avail === 'out') {
        $buy = '<p class="buy-note">售罄了。补货以后这里会重新开放购买。</p>';
    } else {
        $buy = '<a class="btn btn-primary buy-btn" href="../api/shop.php?a=order&amp;p=' . enc_attr($p['slug']) . '">购买</a>';
    }
    $notice = $ss['notice'] !== '' ? '<p class="shop-notice">' . enc_text($ss['notice']) . '</p>' : '';
    $body = $p['body'] !== '' ? '<div class="pbody prod-body">' . "\n" . md_render($p['body'], ['base' => '../']) . "\n" . '</div>' : '';
    $main = shop_crumbs_html($p, $shop)
        . '<article class="prod">' . "\n"
        . '<div class="gal">' . "\n"
        . '<div class="gal-track"' . ($n > 1 ? ' tabindex="0" role="region" aria-label="商品照片，共 ' . $n . ' 张，左右滑动"' : '') . '>' . implode('', $slides) . '</div>' . "\n"
        . ($n > 1 ? '<p class="gal-hint" aria-hidden="true">共 ' . $n . ' 张 · 左右滑动</p>' . "\n" : '')
        . '</div>' . "\n"
        . '<div class="prod-info">' . "\n"
        . '<header class="strip prod-head">' . "\n"
        . '<p class="strip-row">' . SHOP_FIX_SVG . '<b class="fix">S-' . sprintf('%02d', $k + 1) . '</b><span class="strip-tag">' . SHOP_TYPES[$p['type']] . '</span>'
        . ($label !== null ? '<span class="ete' . ($avail === 'out' ? ' out' : '') . '">' . enc_text($label) . '</span>' : '') . '</p>' . "\n"
        . '<h1>' . enc_text($p['name']) . '</h1>' . "\n"
        . ($p['summary'] !== '' ? '<p class="lede">' . enc_text($p['summary']) . '</p>' . "\n" : '')
        . '<p class="prod-price">' . shop_price_html($p['price']) . '</p>' . "\n"
        . '<p class="prod-ship">' . enc_text(shop_ship_text($p, $ss)) . '</p>' . "\n"
        . '</header>' . "\n"
        . $notice
        . '<div class="buy">' . $buy . '</div>' . "\n"
        . '<section class="howbox" aria-label="怎么买"><p class="end-k">HOW TO BUY</p>' . shop_how_html($p['type']) . '</section>' . "\n"
        . '</div>' . "\n"
        . $body . "\n"
        . '</article>' . "\n"
        . '<p class="back"><a class="tlink" href="../#ai">回到商城</a></p>';
    $og = $p['photos'] ? (uploads_index()['items'][$p['photos'][0]['src']]['og'] ?? null) : null;
    return render_shop_page($site, $settings, [
        'title' => $p['name'],
        'desc' => $p['summary'] !== '' ? $p['summary'] : $p['name'] . '，¥' . shop_money($p['price']),
        'main' => $main,
        'private' => false,
        'hit' => '/shop/' . $p['slug'] . '.html',
        'og_image' => is_string($og) ? $og : 'assets/og.jpg',
        'url' => 'shop/' . $p['slug'] . '.html',
    ]);
}

/**
 * 商城 / 大类 / 小类 above a product, each part a link to the home shop filtered to it (#shop/c/<id>). Shown only when
 * the home page has category tabs; a product without a category then sits under 其他.
 */
function shop_crumbs_html(array $p, array $shop): string
{
    $tree = $shop['cats'] ?? ['cats' => [], 'other' => 0];
    if (!$tree['cats'] || !($shop['open'] ?? false)) {
        return '';
    }
    $ix = $shop['ix'] ?? [];
    $w = shop_cat_of($p, $ix);
    $parts = ['<a href="../#shop">商城</a>'];
    if ($w['top'] === null) {
        $parts[] = '<a href="../#shop/c/' . SHOP_CAT_OTHER . '">其他</a>';
    } else {
        $parts[] = '<a href="../#shop/c/' . enc_attr($w['top']) . '">' . enc_text($ix[$w['top']]['name']) . '</a>';
        if ($w['sub'] !== null) {
            $parts[] = '<a href="../#shop/c/' . enc_attr($w['sub']) . '">' . enc_text($ix[$w['sub']]['name']) . '</a>';
        }
    }
    $items = array_map(fn($a) => '<li>' . $a . '</li>', $parts);
    return '<nav class="crumbs" aria-label="所在分类"><ol>' . implode('', $items) . '</ol></nav>' . "\n";
}

// ---------- the buyer's live pages (api/shop.php) ----------

/** A form field: label, input HTML, hint, error. */
function shop_field(string $id, string $label, string $input, string $hint = '', ?string $err = null): string
{
    return '<div class="sf' . ($err !== null ? ' has-err' : '') . '"><label for="' . $id . '">' . enc_text($label) . '</label>'
        . ($hint !== '' ? '<p class="sf-hint" id="' . $id . '-h">' . enc_text($hint) . '</p>' : '')
        . $input
        . ($err !== null ? '<p class="sf-err" id="' . $id . '-e">' . enc_text($err) . '</p>' : '') . '</div>';
}

function shop_input(string $id, string $name, string $value, array $attrs = [], ?string $err = null, bool $hint = false): string
{
    $a = '';
    foreach ($attrs as $k => $v) {
        $a .= ' ' . $k . ($v === true ? '' : '="' . enc_attr((string) $v) . '"');
    }
    $desc = trim(($hint ? $id . '-h ' : '') . ($err !== null ? $id . '-e' : ''));
    return '<input id="' . $id . '" name="' . $name . '" value="' . enc_attr($value) . '"' . $a
        . ($desc !== '' ? ' aria-describedby="' . $desc . '"' : '') . ($err !== null ? ' aria-invalid="true"' : '') . '>';
}

/** Honeypot + form token, hidden from people. */
function shop_form_hidden(string $purpose, string $id = 'f-web'): string
{
    return '<input type="hidden" name="tok" value="' . enc_attr(shop_form_token($purpose)) . '">'
        . '<p class="sub-hp" aria-hidden="true"><label for="' . $id . '">网址</label><input id="' . $id . '" name="website" type="text" tabindex="-1" autocomplete="off"></p>';
}

/** The order form for one product. $v: values typed so far; $e: field errors; $top: a message above the form. */
function shop_order_form_html(array $p, array $ss, array $v = [], array $e = [], string $top = ''): string
{
    [$qMin, $qMax] = $p['type'] === 'supply' ? supply_qty_bounds($p) : [1, SHOP_QTY_MAX];
    $max = $p['stock'] === null ? $qMax : min($qMax, max(1, $p['stock']));
    $f = fn(string $k) => (string) ($v[$k] ?? '');
    $fields = [];
    $qHint = $p['stock'] === null ? '' : '现在有 ' . $p['stock'] . ' 件。';
    if ($qMin > 1 || $qMax < SHOP_QTY_MAX) {
        $qHint .= ($qMin === $qMax ? '每单只能买 ' . $qMin . ' 件。' : '每单 ' . $qMin . ' 到 ' . $qMax . ' 件。');
    }
    $fields[] = shop_field('f-qty', '数量', shop_input('f-qty', 'qty', $f('qty') !== '' ? $f('qty') : (string) $qMin, ['type' => 'number', 'inputmode' => 'numeric', 'min' => (string) $qMin, 'max' => (string) max($qMin, $max), 'required' => true], $e['qty'] ?? null, $qHint !== ''),
        $qHint, $e['qty'] ?? null);
    if ($p['type'] === 'physical') {
        $fields[] = shop_field('f-name', '收货人', shop_input('f-name', 'name', $f('name'), ['type' => 'text', 'autocomplete' => 'name', 'maxlength' => '20', 'required' => true], $e['name'] ?? null), '', $e['name'] ?? null);
        $fields[] = shop_field('f-phone', '手机号', shop_input('f-phone', 'phone', $f('phone'), ['type' => 'tel', 'inputmode' => 'tel', 'autocomplete' => 'tel', 'maxlength' => '20', 'required' => true], $e['phone'] ?? null, true),
            '快递员联系你用。查订单时要填它的后 4 位。', $e['phone'] ?? null);
        $fields[] = shop_field('f-addr', '收货地址', '<textarea id="f-addr" name="address" rows="3" maxlength="120" autocomplete="street-address" required' . (isset($e['address']) ? ' aria-invalid="true" aria-describedby="f-addr-e"' : '') . '>' . enc_text($f('address')) . '</textarea>',
            '', $e['address'] ?? null);
    } else {
        $fields[] = shop_field('f-email', '邮箱', shop_input('f-email', 'email', $f('email'), ['type' => 'email', 'inputmode' => 'email', 'autocomplete' => 'email', 'autocapitalize' => 'off', 'spellcheck' => 'false', 'maxlength' => '254', 'required' => true], $e['email'] ?? null, true),
            '查订单时要填邮箱 @ 前面的部分。有问题时我用它联系你，不会发广告。', $e['email'] ?? null);
    }
    $fields[] = shop_field('f-note', '备注（可不填）', shop_input('f-note', 'note', $f('note'), ['type' => 'text', 'maxlength' => '100'], $e['note'] ?? null), '', $e['note'] ?? null);
    $why = match (true) {
        $p['type'] === 'physical' => '收货人、手机号和地址只用来寄快递和联系你，不给别人。订单完成或取消 180 天后，这些信息会自动删除，只留下金额和日期。',
        $p['type'] === 'supply' && ($p['supply']['contact'] ?? 0) === 2 => '邮箱用来查订单和在出问题时联系你，也会作为这张卡的登记邮箱交给发卡的供货方，不作别的用途，也不会自动发邮件。订单完成或取消 180 天后会自动删除。',
        default => '邮箱只用来查订单和在出问题时联系你，不给别人，也不会自动发邮件。订单完成或取消 180 天后会自动删除。',
    };
    $consent = '我同意本站保存这些信息，只用于这个订单。';
    $sum = '<dl class="sum"><div><dt>单价</dt><dd>¥' . shop_money($p['price']) . '</dd></div>'
        . '<div><dt>运费</dt><dd>' . enc_text($p['type'] !== 'physical' ? '不用寄送' : ($ss['shipping'] <= 0 ? '包邮' : '¥' . shop_money($ss['shipping']) . ($ss['free_over'] > 0 ? '（满 ¥' . shop_money($ss['free_over']) . ' 包邮）' : ''))) . '</dd></div></dl>'
        . '';
    return '<div class="scol">' . "\n"
        . '<header class="strip">' . "\n"
        . '<p class="strip-row">' . SHOP_FIX_SVG . '<b class="fix">ORDER</b><span class="strip-tag">' . SHOP_TYPES[$p['type']] . '</span></p>' . "\n"
        . '<h1>' . enc_text($p['name']) . '</h1>' . "\n"
        . '<p class="prod-price">' . shop_price_html($p['price']) . '<span class="per"> / 件</span></p>' . "\n"
        . '</header>' . "\n"
        . ($top !== '' ? '<p class="form-err" role="alert">' . enc_text($top) . '</p>' . "\n" : '')
        . ($ss['notice'] !== '' ? '<p class="shop-notice">' . enc_text($ss['notice']) . '</p>' . "\n" : '')
        . '<form class="sform" method="post" action="shop.php?a=order" accept-charset="utf-8">' . "\n"
        . '<input type="hidden" name="p" value="' . enc_attr($p['slug']) . '">' . shop_form_hidden('order') . "\n"
        . implode("\n", $fields) . "\n"
        . $sum . "\n"
        . '<p class="why"><b>为什么要这些信息：</b>' . enc_text($why) . '</p>' . "\n"
        . '<div class="sf' . (isset($e['consent']) ? ' has-err' : '') . '"><label class="consent"><input id="f-consent" name="consent" type="checkbox" value="1"' . (($v['consent'] ?? '') === '1' ? ' checked' : '') . '><span>' . enc_text($consent) . '</span></label>'
        . (isset($e['consent']) ? '<p class="sf-err">' . enc_text($e['consent']) . '</p>' : '') . '</div>' . "\n"
        . '<button class="btn btn-primary btn-wide" type="submit">提交订单</button>' . "\n"
        . '<p class="sf-hint">提交以后会给出准确的付款金额：合计再加几分钱的识别尾数，方便我认出你的付款。</p>' . "\n"
        . '</form>' . "\n"
        . '<section class="howbox" aria-label="怎么买"><p class="end-k">HOW TO BUY</p>' . shop_how_html($p['type']) . '</section>' . "\n"
        . '<p class="back"><a class="tlink" href="../shop/' . enc_attr($p['slug']) . '.html">回到商品</a></p>' . "\n"
        . '</div>';
}

/** Breakdown of an order's amount. With $q (the USDT quote the buyer is paying), the USDT amount replaces the CNY tail. */
function shop_amount_rows(array $o, ?array $q = null): string
{
    $paid = usdt_paid($o);
    $rows = '<div><dt>' . enc_text($o['name']) . ' × ' . (int) $o['qty'] . '</dt><dd>¥' . shop_money((int) $o['subtotal']) . '</dd></div>'
        . ($o['type'] === 'physical' ? '<div><dt>运费</dt><dd>' . ((int) $o['shipping'] > 0 ? '¥' . shop_money((int) $o['shipping']) : '包邮') . '</dd></div>' : '');
    if ($q !== null) {
        return '<dl class="sum">' . $rows
            . '<div><dt>订单金额</dt><dd>¥' . shop_money((int) $o['subtotal'] + (int) $o['shipping']) . '</dd></div>'
            . '<div class="sum-total"><dt>USDT 应付（' . USDT_CHAINS[$q['chain']]['label'] . '）</dt><dd>' . usdt_amount_text($q['amount4']) . '</dd></div>'
            . '</dl>';
    }
    return '<dl class="sum">' . $rows
        . ($paid === null ? '<div><dt>识别尾数</dt><dd>¥' . shop_money((int) $o['offset']) . '</dd></div>'
            . '<div class="sum-total"><dt>应付</dt><dd>¥' . shop_money((int) $o['amount']) . '</dd></div>'
            : '<div class="sum-total"><dt>订单金额</dt><dd>¥' . shop_money((int) $o['subtotal'] + (int) $o['shipping']) . '</dd></div>'
            . '<div><dt>USDT 实付（' . USDT_CHAINS[$paid['chain']]['label'] . '）</dt><dd>' . enc_text(usdt_units_text((string) $paid['units'], USDT_CHAINS[$paid['chain']]['decimals'])) . '</dd></div>')
        . '</dl>';
}

/** Which tab opens first: the app the page is open in. */
function shop_qr_first(): string
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    return str_contains($ua, 'AlipayClient') ? 'alipay' : 'wechat';
}

function shop_qr_html(array $ss, array $o): string
{
    $qr = array_filter($ss['qr'], fn($v) => is_string($v));
    if (!$qr) {
        return '<p class="form-err" role="alert">站长还没有设置收款码，暂时付不了款。订单先保留，过一会儿再打开这一页看看。</p>';
    }
    $amt = '¥' . shop_money((int) $o['amount']);
    $help = [
        'wechat' => ['微信支付', '在微信里长按收款码，选「识别图中的二维码」；不在微信里的话，先截图，再用微信「扫一扫」从相册选这张图。金额填 ' . $amt . '，备注填订单号。'],
        'alipay' => ['支付宝', '截图保存收款码，打开支付宝「扫一扫」，点右上角的相册选这张图。金额填 ' . $amt . '，备注填订单号。'],
    ];
    $first = shop_qr_first();
    $order = isset($qr[$first]) ? [$first] : [];
    foreach (array_keys($qr) as $k) {
        if (!in_array($k, $order, true)) {
            $order[] = $k;
        }
    }
    $radios = '';
    $labels = '';
    $panels = '';
    foreach ($order as $i => $k) {
        $radios .= '<input class="qr-r" type="radio" name="qrt" id="qr-' . $k . '"' . ($i === 0 ? ' checked' : '') . '>';
        $labels .= '<label class="qr-l qr-l-' . $k . '" for="qr-' . $k . '">' . $help[$k][0] . '</label>';
        $panels .= '<div class="qr-p qr-p-' . $k . '"><img src="../' . enc_attr($qr[$k]) . '" width="300" height="300" alt="' . $help[$k][0] . '收款码" decoding="async"><p>' . enc_text($help[$k][1]) . '</p></div>';
    }
    return '<div class="qr-tabs">' . $radios . (count($order) > 1 ? '<div class="qr-bar">' . $labels . '</div>' : '<p class="qr-one">' . $help[$order[0]][0] . '</p>') . $panels . '</div>';
}

/** The pay page for an order (reached with its pay token). $chains: USDT chains the buyer may choose now. */
function shop_pay_html(array $o, array $ss, string $msg = '', array $chains = [], ?int $now = null): string
{
    $now ??= time();
    $st = $o['status'];
    $k = shop_pay_token($o['id']);
    $lookup = 'shop.php?a=lookup&amp;o=' . enc_attr($o['id']);
    $q = $st === 'pending' ? usdt_current_quote($o) : null;
    $head = '<header class="strip pay-head">' . "\n"
        . '<p class="strip-row">' . SHOP_FIX_SVG . '<b class="fix">PAY</b><span class="strip-tag st-' . $st . '">' . enc_text(shop_status_buyer($o)) . '</span></p>' . "\n"
        . ($q !== null
            ? '<p class="pay-k">订单金额（人民币）</p>' . "\n" . '<p class="pay-amt pay-amt-sm">' . shop_price_html((int) $o['subtotal'] + (int) $o['shipping']) . '</p>' . "\n"
            : '<p class="pay-k">' . ($st === 'pending' ? '请付这个金额' : '订单金额') . '</p>' . "\n"
            . '<p class="pay-amt">' . shop_price_html((int) $o['amount']) . '</p>' . "\n"
            . ($st === 'pending' ? '<p class="pay-why"><b>付款时请付这个金额，备注订单号。</b>金额比合计多 ¥' . shop_money((int) $o['offset']) . '，这是这个订单的识别尾数，我靠它认出你的付款。少付或多付都对不上。</p>' . "\n" : ''))
        . '<p class="pay-no"><span>订单号</span><b>' . enc_text($o['id']) . '</b></p>' . "\n"
        . '</header>';
    $body = '';
    if ($msg !== '') {
        $body .= '<p class="form-ok" role="status">' . enc_text($msg) . '</p>' . "\n";
    }
    switch ($st) {
        case 'pending':
            if ($chains) {
                $body .= shop_usdt_choice_html($o, $ss, $chains, $q) . "\n";
            }
            if ($q !== null) {
                $body .= shop_usdt_panel_html($o, $q, $chains, $now);
                break;
            }
            if (!array_filter($ss['qr'], fn($v) => is_string($v))) {
                $body .= shop_qr_html($ss, $o);
                break;
            }
            $body .= shop_qr_html($ss, $o) . "\n"
                . '<p class="deadline">请在 <b>' . enc_text(local_time((string) $o['expires_at'], 'n月j日 H:i')) . '</b>（北京时间）前付款，超时订单会自动取消，库存放回去。</p>' . "\n"
                . '<form class="sform paid-form" method="post" action="shop.php?a=paid">' . "\n"
                . '<input type="hidden" name="o" value="' . enc_attr($o['id']) . '"><input type="hidden" name="k" value="' . enc_attr($k) . '">' . shop_form_hidden('paid') . "\n"
                . shop_field('f-payer', '付款人名字或付款备注的最后几个字（可不填）', shop_input('f-payer', 'payer', '', ['type' => 'text', 'maxlength' => '20', 'autocomplete' => 'off'], null, true), '方便我在收款记录里找到你这一笔。') . "\n"
                . '<button class="btn btn-primary btn-wide" type="submit">我已付款</button>' . "\n"
                . '<p class="sf-hint">付完款再点。确认收款是我本人对照手机收款记录做的，不是自动到账，可能要等几个小时，飞行的日子会更久。</p>' . "\n"
                . '</form>';
            break;
        case 'claimed':
            $body .= '<p class="state-box">收到你的付款通知了。我核对到账后会确认，确认是手工的，可能要等几个小时。之后在「查订单」里能看到进度。</p>';
            break;
        case 'cancelled':
            $body .= '<p class="state-box state-x">这个订单已经取消：' . enc_text($o['cancel_reason']) . ' 如果你已经付了款，请在「查订单」里看取消原因，或者在首页的店铺客服留言。</p>';
            break;
        default:
            $paid = usdt_paid($o);
            $body .= '<p class="state-box state-ok" id="usdt-done">' . ($paid !== null && $paid['how'] === 'auto'
                    ? '已收到 ' . enc_text(usdt_units_text((string) $paid['units'], USDT_CHAINS[$paid['chain']]['decimals'])) . ' USDT（' . USDT_CHAINS[$paid['chain']]['label'] . '），已自动确认收款，谢谢。'
                    : '已确认收款，谢谢。') . match ($o['type']) {
                'digital' => '内容在「查订单」里查看。',
                'supply' => '卡密自动发出后在「查订单」里查看。',
                default => '发货以后，快递单号会显示在「查订单」里。',
            } . '</p>';
    }
    return '<div class="scol">' . "\n" . $head . "\n" . $body . "\n"
        . shop_amount_rows($o, $q) . "\n"
        . '<section class="savebox"><p class="end-k">SAVE THIS PAGE</p><p>请截图保存这一页，或者记下订单号 <b class="mono">' . enc_text($o['id']) . '</b>。以后在「查订单」里，用订单号和' . ($o['type'] !== 'physical' ? '邮箱 @ 前面的部分' : '手机号后 4 位') . '查看进度。</p>'
        . '<p><a class="tlink tlink-go" href="' . $lookup . '">去查订单</a></p></section>' . "\n"
        . '</div>';
}

/** 付款方式: 收款码 or USDT on a chain. One plain form (works without script); the current choice is marked. */
function shop_usdt_choice_html(array $o, array $ss, array $chains, ?array $q): string
{
    $btn = function (string $value, string $label, string $sub, bool $on): string {
        return '<button class="paym-b' . ($on ? ' on' : '') . '" type="submit" name="c" value="' . enc_attr($value) . '"' . ($on ? ' aria-current="true"' : '') . '>'
            . '<b>' . enc_text($label) . '</b><span>' . enc_text($sub) . '</span></button>';
    };
    $b = '';
    if (array_filter($ss['qr'], fn($v) => is_string($v))) {
        $b .= $btn('qr', '微信 / 支付宝', '扫收款码，人民币', $q === null);
    }
    foreach ($chains as $c) {
        $b .= $btn($c, 'USDT · ' . USDT_CHAINS[$c]['label'], USDT_CHAINS[$c]['net'] . ' 网络', $q !== null && $q['chain'] === $c);
    }
    return '<form class="paym" id="paym" method="post" action="shop.php?a=usdt">'
        . '<p class="paym-k">付款方式</p>'
        . '<input type="hidden" name="o" value="' . enc_attr($o['id']) . '"><input type="hidden" name="k" value="' . enc_attr(shop_pay_token($o['id'])) . '">' . shop_form_hidden('usdt', 'f-web-m')
        . '<div class="paym-row">' . $b . '</div>'
        . '<p class="sf-hint">还没付款之前可以随时换。USDT 是自动确认的，到账后这一页马上变成「已确认收款」。</p>'
        . '</form>';
}

/** The USDT quote: chain, exact amount, address (QR + copy), countdown, steps; polls api/shop.php a=usdt (usdt.js). */
function shop_usdt_panel_html(array $o, array $q, array $chains, int $now): string
{
    $c = USDT_CHAINS[$q['chain']];
    $amt = usdt_amount_text($q['amount4']);
    $k = shop_pay_token($o['id']);
    $self = 'shop.php?a=pay&amp;o=' . enc_attr($o['id']) . '&amp;k=' . enc_attr($k);
    $deadline = local_time(gmdate('Y-m-d\TH:i:s\Z', $q['expires']), 'n月j日 H:i');
    if ($q['expires'] <= $now) {
        $again = in_array($q['chain'], $chains, true)
            ? '<form class="paym-again" method="post" action="shop.php?a=usdt"><input type="hidden" name="o" value="' . enc_attr($o['id']) . '"><input type="hidden" name="k" value="' . enc_attr($k) . '">' . shop_form_hidden('usdt', 'f-web-q')
                . '<button class="btn btn-primary btn-wide" type="submit" name="c" value="' . enc_attr($q['chain']) . '">重新报价（按现在的汇率）</button></form>'
            : '';
        return '<section class="usdt usdt-exp" id="usdt" data-usdt data-poll="shop.php?a=usdt&amp;o=' . enc_attr($o['id']) . '&amp;k=' . enc_attr($k) . '" data-exp="' . $q['expires'] . '" data-now="' . $now . '" data-grace="' . USDT_GRACE . '">'
            . '<p class="usdt-net"><b>USDT · ' . $c['label'] . '</b><span>' . enc_text($c['net']) . '</span></p>'
            . '<p class="state-box">这个报价已经在 ' . enc_text($deadline) . ' 过期了（' . enc_text($amt) . ' USDT）。<b>还没转账</b>的话，点下面按现在的汇率重新报价，按新的金额转。<b>已经转了</b>的话不要再转：过期后 10 分钟内到账的还会自动确认；更晚到账的，我会人工核对后处理。</p>'
            . '<p class="usdt-status" role="status" aria-live="polite" data-status></p>'
            . $again
            . '</section>' . "\n" . shop_usdt_scripts();
    }
    $copy = fn(string $v, string $label) => '<button class="btn btn-ghost usdt-copy" type="button" data-copy="' . enc_attr($v) . '" hidden>' . enc_text($label) . '</button>';
    return '<section class="usdt" id="usdt" data-usdt data-poll="shop.php?a=usdt&amp;o=' . enc_attr($o['id']) . '&amp;k=' . enc_attr($k) . '" data-exp="' . $q['expires'] . '" data-now="' . $now . '" data-grace="' . USDT_GRACE . '" data-addr="' . enc_attr($q['address']) . '">' . "\n"
        . '<p class="usdt-net"><b>USDT · ' . $c['label'] . '</b><span>' . enc_text($c['net']) . ' 网络</span></p>' . "\n"
        . '<div class="usdt-box"><p class="usdt-k">转账金额（一分都不能差）</p><p class="usdt-v"><span class="mono sel" id="usdt-amt">' . enc_text($amt) . '</span> <small>USDT</small></p>' . $copy($amt, '复制金额') . '</div>' . "\n"
        . '<div class="usdt-box"><p class="usdt-k">收款地址（只收 ' . $c['label'] . ' 网络的 USDT）</p>'
        . '<canvas class="usdt-qr" width="232" height="232" hidden role="img" aria-label="收款地址的二维码"></canvas>'
        . '<p class="usdt-a mono sel" id="usdt-addr">' . enc_text($q['address']) . '</p>' . $copy($q['address'], '复制地址') . '</div>' . "\n"
        . '<p class="usdt-time">请在 <b>' . enc_text($deadline) . '</b>（北京时间）前转账<span data-left-wrap hidden>，还剩 <b class="mono" data-left></b></span>。</p>' . "\n"
        . '<p class="usdt-status" role="status" aria-live="polite" data-status>等待到账。到账后这一页会自动变成「已确认收款」，不用点任何按钮。</p>' . "\n"
        . '<ol class="usdt-steps">'
        . '<li>在钱包或交易所的「提币 / 转账」里选 <b>USDT</b>，网络选 <b>' . $c['label'] . '（' . enc_text($c['net']) . '）</b>。</li>'
        . '<li>收款地址填上面这个地址（复制或扫码），金额填 <b class="mono">' . enc_text($amt) . '</b>。交易所会扣手续费的话，请让<b>到账金额</b>正好是这个数。</li>'
        . '<li>转完就好，不用截图。链上确认后自动确认收款，通常 1 到 3 分钟。</li>'
        . '</ol>' . "\n"
        . '<p class="usdt-warn"><b>只能用 ' . $c['label'] . ' 网络转 USDT。</b>换了别的网络、别的币，或者金额不一样（多了少了都算），系统都认不出来，不会自动确认，要人工核对，钱可能找不回来。</p>' . "\n"
        . '<p class="sf-hint">按 1 USDT = ¥' . enc_text(usdt_rate_text($q['rate'])) . ' 换算，最后几位小数是这个订单的识别尾数。没有脚本的浏览器不会自动刷新，转完以后 <a class="tlink-in" href="' . $self . '">刷新这一页</a> 看看。</p>' . "\n"
        . '</section>' . "\n" . shop_usdt_scripts();
}

/** The pay page's two scripts (same origin, cache-busted; the pay page CSP allows script-src 'self' only then). */
function shop_usdt_scripts(): string
{
    $v = function (string $rel): string {
        $b = (string) @file_get_contents(PUBLIC_DIR . '/' . $rel);
        return '../' . $rel . '?v=' . substr(hash('sha256', $b), 0, 8);
    };
    return '<script src="' . enc_attr($v('assets/shop/qrcode.min.js')) . '" defer></script><script src="' . enc_attr($v('assets/shop/usdt.js')) . '" defer></script>';
}

/** Status in the buyer's words. */
function shop_status_buyer(array $o): string
{
    if ($o['type'] === 'supply' && in_array($o['status'], ['confirmed', 'failed'], true)) {
        return '已确认收款，正在发货';   // a failed purchase is the owner's business: the buyer only sees that the card is on its way
    }
    return match ($o['status']) {
        'pending' => '待付款',
        'claimed' => '已付款，等站长确认',
        'confirmed' => '已确认收款',
        'shipped' => '已发货',
        'completed' => '已完成',
        'cancelled' => '已取消',
        default => '',
    };
}

/** The lookup form. */
function shop_lookup_form_html(string $o = '', string $err = ''): string
{
    return '<div class="scol">' . "\n"
        . '<header class="strip">' . "\n"
        . '<p class="strip-row">' . SHOP_FIX_SVG . '<b class="fix">TRACK</b></p>' . "\n"
        . '<h1>查订单</h1>' . "\n"
        . '<p class="lede">填订单号，再填下单时手机号的后 4 位；数字商品填邮箱 @ 前面的部分。</p>' . "\n"
        . '</header>' . "\n"
        . ($err !== '' ? '<p class="form-err" role="alert">' . enc_text($err) . '</p>' . "\n" : '')
        . '<form class="sform" method="post" action="shop.php?a=lookup">' . "\n" . shop_form_hidden('lookup') . "\n"
        . shop_field('f-o', '订单号', shop_input('f-o', 'o', $o, ['type' => 'text', 'autocapitalize' => 'characters', 'autocomplete' => 'off', 'spellcheck' => 'false', 'maxlength' => '30', 'placeholder' => '例如 20261017-K7P2QX', 'required' => true])) . "\n"
        . shop_field('f-v', '手机号后 4 位，或邮箱 @ 前面的部分', shop_input('f-v', 'v', '', ['type' => 'text', 'autocomplete' => 'off', 'autocapitalize' => 'off', 'spellcheck' => 'false', 'maxlength' => '80', 'required' => true])) . "\n"
        . '<button class="btn btn-primary btn-wide" type="submit">查询</button>' . "\n"
        . '</form>' . "\n"
        . '<p class="sf-hint">找不到订单号的话，看看付款页的截图。还是找不到，可以在首页的店铺客服说明情况。</p>' . "\n"
        . '</div>';
}

function shop_mask_name(string $s): string
{
    return $s === '' ? '' : mb_substr($s, 0, 1) . str_repeat('*', max(1, min(3, mb_strlen($s) - 1)));
}

function shop_mask_phone(string $s): string
{
    return strlen($s) >= 7 ? substr($s, 0, 3) . str_repeat('*', strlen($s) - 7) . substr($s, -4) : '****';
}

function shop_mask_address(string $s): string
{
    return $s === '' ? '' : mb_substr($s, 0, 6) . '……';
}

/** Timeline steps for the buyer: [label, time or null, state done|now|todo|x]. */
function shop_timeline(array $o): array
{
    $times = [];
    foreach ($o['history'] as $h) {
        $times[$h['s']] = $h['t'];
    }
    $steps = match ($o['type']) {
        'digital' => ['pending' => '下单，待付款', 'claimed' => '你已付款，等站长确认', 'confirmed' => '已确认收款，可以查看内容', 'completed' => '已完成'],
        'supply' => ['pending' => '下单，待付款', 'claimed' => '你已付款，等站长确认', 'confirmed' => '已确认收款，正在发货', 'completed' => '已发卡，可以查看卡密'],
        default => ['pending' => '下单，待付款', 'claimed' => '你已付款，等站长确认', 'confirmed' => '已确认收款', 'shipped' => '已发货', 'completed' => '已完成'],
    };
    $order = array_keys($steps);
    $cur = $o['status'] === 'failed' ? 'confirmed' : $o['status'];
    $out = [];
    if ($cur === 'cancelled') {
        foreach ($order as $s) {
            if (isset($times[$s])) {
                $out[] = [$steps[$s], $times[$s], 'done'];
            }
        }
        $out[] = ['已取消', $times['cancelled'] ?? null, 'x'];
        return $out;
    }
    $pos = array_search($cur, $order, true);
    foreach ($order as $i => $s) {
        $state = $i < $pos ? 'done' : ($i === $pos ? 'now' : 'todo');
        if ($s === 'claimed' && $state === 'done' && !isset($times['claimed'])) {
            continue;   // the owner confirmed the payment before the buyer tapped 我已付款
        }
        $out[] = [$steps[$s], $times[$s] ?? null, $state];
    }
    return $out;
}

/** The lookup result: status, timeline, shipping, delivery. $dl: a signed download URL or null. */
function shop_lookup_result_html(array $o, ?array $product, ?string $dl): string
{
    $tl = '';
    foreach (shop_timeline($o) as [$label, $t, $state]) {
        $tl .= '<li class="tl-' . $state . '"><span class="tl-dot" aria-hidden="true"></span><span class="tl-l">' . enc_text($label) . '</span>'
            . ($t !== null ? '<time datetime="' . enc_attr($t) . '">' . enc_text(local_time($t, 'n月j日 H:i')) . '</time>' : '')
            . ($state === 'now' ? '<span class="vh">（现在）</span>' : '') . '</li>';
    }
    $more = '';
    if ($o['status'] === 'pending') {
        $more .= '<p class="state-box">还没有付款。<a class="tlink-in" href="shop.php?a=pay&amp;o=' . enc_attr($o['id']) . '&amp;k=' . enc_attr(shop_pay_token($o['id'])) . '">去付款页</a>（' . enc_text(local_time((string) $o['expires_at'], 'n月j日 H:i')) . '前有效）。</p>';
    } elseif ($o['status'] === 'claimed') {
        $more .= '<p class="state-box">收到你的付款通知了。确认收款是我本人对照手机收款记录做的，可能要等几个小时。</p>';
    } elseif ($o['status'] === 'cancelled') {
        $more .= '<p class="state-box state-x">取消原因：' . enc_text($o['cancel_reason']) . '</p>';
    }
    if ($o['type'] === 'physical' && $o['tracking'] !== '' && in_array($o['status'], ['shipped', 'completed'], true)) {
        $more .= '<section class="shipbox"><p class="end-k">SHIPPED</p><dl class="sum"><div><dt>快递公司</dt><dd>' . enc_text($o['courier']) . '</dd></div>'
            . '<div><dt>快递单号</dt><dd class="mono sel">' . enc_text($o['tracking']) . '</dd></div></dl><p class="sf-hint">长按快递单号可以复制，到快递公司的小程序或网站里查物流。</p></section>';
    }
    if ($o['type'] === 'supply' && in_array($o['status'], ['confirmed', 'failed', 'completed'], true)) {
        $sec = (string) ($o['supply']['secret'] ?? '');
        $ready = ($o['supply']['trade_no'] ?? '') !== '' && empty($o['supply']['pending']) && $sec !== '';
        if ($ready) {
            $lines = array_values(array_filter(explode("\n", $sec), fn($l) => trim($l) !== ''));
            $more .= '<section class="dlbox card-box"><p class="end-k">YOUR CARD</p><p class="sf-hint">卡密（长按可以复制）：</p><div class="dl-text card-secret mono">'
                . implode('', array_map(fn($l) => '<p class="sel">' . enc_text($l) . '</p>', $lines)) . '</div>'
                . '<p class="sf-hint">请尽快使用或抄下来。卡密只给查到这个订单的人看，不要截图发给别人。</p></section>';
        } elseif ($o['redacted_at'] !== null) {
            $more .= '<p class="state-box">这个订单已经结束很久了，卡密已经按规定删除。</p>';
        } else {
            $more .= '<p class="state-box">已确认收款，正在发货。卡密一般几分钟内就会出现在这里，稍后再查一次。等了很久还没有的话，在首页的店铺客服说一声。</p>';
        }
    }
    if ($o['type'] === 'digital' && in_array($o['status'], ['confirmed', 'completed'], true) && $product !== null) {
        $d = $product['delivery'];
        $box = '';
        if ($d['text'] !== '') {
            $box .= '<div class="dl-text">' . implode('', array_map(fn($l) => '<p>' . enc_text($l) . '</p>', array_filter(explode("\n", $d['text']), fn($l) => trim($l) !== ''))) . '</div>';
        }
        if ($d['link'] !== '' && shop_url_ok($d['link'])) {
            $box .= '<p><a class="btn btn-ghost dl-link" href="' . enc_attr($d['link']) . '" rel="noopener noreferrer" target="_blank">打开链接</a></p><p class="mono sel dl-url">' . enc_text($d['link']) . '</p>';
        }
        if ($d['file'] !== null && $dl !== null) {
            $box .= '<p><a class="btn btn-primary" href="' . enc_attr($dl) . '">下载文件</a> <span class="sf-hint">' . enc_text($d['file']['name']) . '，' . enc_text(shop_bytes($d['file']['bytes'])) . '。链接 30 分钟内有效，过期了重新查一次。</span></p>'
                . '<p class="sf-hint">在微信里下载不了的话，点右上角「…」，选「在浏览器打开」，再查一次。</p>';
        }
        $more .= '<section class="dlbox"><p class="end-k">YOUR ORDER</p>' . ($box !== '' ? $box : '<p>内容正在准备，请稍后再查。</p>') . '</section>';
    }
    foreach ($o['refunds'] as $r) {
        $more .= '<p class="state-box">退款记录（' . enc_text(local_time($r['t'], 'n月j日')) . '）：' . enc_text($r['note']) . '</p>';
    }
    $who = '';
    if ($o['redacted_at'] === null) {
        $who = $o['type'] === 'physical'
            ? '<dl class="sum who"><div><dt>收货人</dt><dd>' . enc_text(shop_mask_name($o['buyer']['name'])) . '</dd></div><div><dt>手机号</dt><dd class="mono">' . enc_text(shop_mask_phone($o['buyer']['phone'])) . '</dd></div><div><dt>地址</dt><dd>' . enc_text(shop_mask_address($o['buyer']['address'])) . '</dd></div></dl>'
            : '';
    }
    return '<div class="scol">' . "\n"
        . '<header class="strip">' . "\n"
        . '<p class="strip-row">' . SHOP_FIX_SVG . '<b class="fix">' . enc_text($o['id']) . '</b><span class="strip-tag st-' . $o['status'] . '">' . enc_text(shop_status_buyer($o)) . '</span></p>' . "\n"
        . '<h1>' . enc_text($o['name']) . ' × ' . (int) $o['qty'] . '</h1>' . "\n"
        . '<p class="lede">下单时间 ' . enc_text(local_time($o['created_at'], 'Y年n月j日 H:i')) . '</p>' . "\n"
        . '</header>' . "\n"
        . '<ol class="tl">' . $tl . '</ol>' . "\n"
        . $more . "\n"
        . shop_amount_rows($o) . "\n"
        . $who . "\n"
        . '<p class="back"><a class="tlink" href="shop.php?a=lookup">查另一个订单</a></p>' . "\n"
        . '</div>';
}

function shop_bytes(int $b): string
{
    return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, (int) round($b / 1024)) . ' KB';
}

/** A short page with one message (errors, closed shop, not found). */
function shop_message_html(string $title, string $msg, string $backHref = '../#ai', string $backText = '回到商城'): string
{
    return '<div class="scol">' . "\n"
        . '<header class="strip"><p class="strip-row">' . SHOP_FIX_SVG . '<b class="fix">NOTAM</b></p><h1>' . enc_text($title) . '</h1></header>' . "\n"
        . '<p class="state-box">' . enc_text($msg) . '</p>' . "\n"
        . '<p class="back"><a class="tlink" href="' . enc_attr($backHref) . '">' . enc_text($backText) . '</a></p>' . "\n"
        . '</div>';
}
