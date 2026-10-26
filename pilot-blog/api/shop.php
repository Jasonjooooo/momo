<?php
// 商城的买家页面：下单、付款、查订单、下载数字商品。页面全部在服务器上生成，没有脚本（CSP: script-src 'none'）；
// 只有显示 USDT 报价的付款页带本站的两个脚本（二维码、复制、倒计时、自动查到账），CSP 为 script-src 'self'。
// 价格、运费、付款金额、库存都在这里按 blog-data 里的数据计算，从不相信浏览器发来的金额。
// 买家信息和数字商品文件只存在 public_html 同级的 blog-data/shop/ 里。日志里不写任何买家信息。
//
// GET  ?a=order&p=<slug>               下单表单
// POST ?a=order                        创建订单，303 到付款页
// GET  ?a=pay&o=<订单号>&k=<令牌>        付款页（收款码、金额、我已付款）
// POST ?a=paid                         买家点「我已付款」
// POST ?a=usdt                         付款方式：收款码，或 USDT（TRC20 / BEP20）报价
// GET  ?a=usdt&o=<订单号>&k=<令牌>       付款页轮询（JSON）：查一次链上到账（每条链最多 15 秒一次），回答订单状态
// GET  ?a=lookup[&o=<订单号>]           查订单表单
// POST ?a=lookup                       订单号 + 手机号后 4 位（或邮箱 @ 前面的部分）→ 进度、快递、数字内容
// GET  ?a=dl&o=…&e=…&s=…               数字商品文件（30 分钟有效的签名链接）
// GET  ?a=ref&c=<推广码>[&to=shop/<slug>.html]   推广链接：记下推广码（30 天的 cookie，最后一次点击为准），302 到本站页面
// GET  ?a=partner&t=<查看令牌>          推广人自己的查看页（点击、订单、佣金、结算记录；不显示买家信息）
// 自动发卡（货源）商品：下单前向供货平台刷新库存（5 分钟缓存）；卡密在站长确认收款、后台进货以后显示在「查订单」里。
// 买家页面从不显示供货平台的名字、成本、商品编号或错误信息。

declare(strict_types=1);

define('BLOG_APP', true);
require __DIR__ . '/../app/lib/bootstrap.php';

const SHOP_CSP = "default-src 'self'; img-src 'self' data:; style-src 'self'; font-src 'self'; script-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; object-src 'none'";
const RL_ORDER_TRY_HOUR = 12;    // order form posts per visitor per hour (typos included)
const RL_ORDER_MADE_DAY = 10;    // orders created per visitor per day
const RL_ORDER_SITE_DAY = 300;   // orders created site-wide per day
const RL_LOOKUP_10MIN = 10;
const RL_LOOKUP_DAY = 60;
const RL_LOOKUP_ORDER_HOUR = 8;  // guesses against one order number per hour, from anywhere
const RL_PAID_HOUR = 20;
const RL_USDT_HOUR = 30;         // 付款方式 changes per visitor per hour
const RL_USDT_POLL_10MIN = 120;  // pay page polls per visitor per 10 minutes (one every ~8 s from two tabs)
const RL_USDT_ORDER_10MIN = 150; // polls per order per 10 minutes, from anywhere
const RL_DL_HOUR = 30;
const RL_PARTNER_10MIN = 30;     // 推广人 view page opens per visitor
const RL_PARTNER_BAD_HOUR = 20;  // wrong view tokens per visitor per hour
const MSG_BUSY = '提交得有点频繁，过一会儿再试。';
const MSG_RETRY = '这次没有提交成功。请回到上一页，刷新以后再试一次。';
const MSG_LOOKUP_BAD = '订单号或验证信息不对。请检查后再试；数字商品请填邮箱 @ 前面的部分。';

const SHOP_CSP_USDT = "default-src 'self'; img-src 'self' data:; style-src 'self'; font-src 'self'; script-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; object-src 'none'";

function shop_headers(?string $csp = null): void
{
    if (headers_sent()) {
        return;
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    header('Content-Security-Policy: ' . ($csp ?? SHOP_CSP));
}

/** The page; the USDT pay page (and only it) may load this site's own scripts. */
function shop_out(int $status, string $title, string $main): never
{
    shop_headers(str_contains($main, '<script src="../assets/shop/') ? SHOP_CSP_USDT : null);
    http_response_code($status);
    try {
        echo render_shop_page(site_live(), settings_get(), ['title' => $title, 'desc' => '', 'main' => $main, 'private' => true]);
    } catch (Throwable $e) {
        error_log('shop.php render: ' . get_class($e));
        echo '<!doctype html><meta charset="utf-8"><title>商城</title><p>' . enc_text($title) . '</p>';
    }
    exit;
}

function shop_msg(int $status, string $title, string $msg, string $back = '../#ai', string $backText = '回到商城'): never
{
    shop_out($status, $title, shop_message_html($title, $msg, $back, $backText));
}

/** JSON for the pay page's poll. */
function json_answer(int $status, array $data): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function see_other(string $url): never
{
    header('Cache-Control: no-store');
    header('Location: ' . $url, true, 303);
    exit;
}

/** A request field: from the form body on POST, from the URL on GET (never mixed, so no state change by URL). */
function in_str(string $k, int $max = 2000): string
{
    $v = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? $_POST : $_GET)[$k] ?? '';
    return is_string($v) ? substr($v, 0, $max) : '';
}

/** Visitor key for the limits: IPv6 /64 or IPv4, hashed with the install's salt (nothing readable is stored). */
function visitor(): string
{
    return ip_hash(client_key());
}

function limit(string $bucket, string $key, int $max, int $window): void
{
    if (!rl_hit($bucket, $key, $max, $window)['allowed']) {
        shop_msg(429, '请稍后再试', MSG_BUSY, 'shop.php?a=lookup', '查订单');
    }
}

/** POST from another site (Origin names another host): refuse. */
function same_origin_or_die(): void
{
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '' || $origin === 'null') {
        $origin = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        if ($origin === '') {
            return;
        }
    }
    $want = origin_normalize(request_origin());
    if ($want === null || origin_normalize($origin) !== $want) {
        shop_msg(403, '没有提交成功', '请从本站的页面提交。');
    }
}

/** Form token and honeypot; a bot gets the same plain refusal as an expired page. */
function form_ok(string $purpose): void
{
    $hp = $_POST['website'] ?? '';
    $check = shop_form_token_check($purpose, in_str('tok', 80));
    if (!is_string($hp) || $hp !== '' || $check !== 'ok') {
        $back = $purpose === 'lookup' ? ['shop.php?a=lookup', '查订单'] : ['../#ai', '回到商城'];
        shop_msg(400, '没有提交成功', $check === 'fast' ? '提交得太快了，请回到上一页再点一次。' : MSG_RETRY, $back[0], $back[1]);
    }
}

/** https://example.com from the live site address, or null while it is still the placeholder. */
function shop_main_origin(): ?string
{
    $o = (string) (site_live()['fields']['site.origin'] ?? '');
    return preg_match('#^https://[a-z0-9.-]+$#', $o) && !str_contains($o, 'DEPLOY-DOMAIN') ? $o : null;
}

/** https://pay.example.com when the owner turned on the separate pay address, else null. */
function shop_pay_origin(array $ss): ?string
{
    $main = shop_main_origin();
    return !empty($ss['pay_sub']) && $main !== null ? 'https://pay.' . substr($main, 8) : null;
}

function order_id_input(string $raw): string
{
    $s = strtoupper(str_replace([' ', "\u{3000}", "\u{2014}", "\u{FF0D}", '_'], ['', '', '-', '-', '-'], ascii_numbers($raw)));
    if (preg_match('/^(\d{8})-?([A-Z0-9]{6})$/', $s, $m)) {
        return $m[1] . '-' . $m[2];
    }
    return $s;
}

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$a = is_string($_GET['a'] ?? null) ? $_GET['a'] : '';
if (!in_array($method, ['GET', 'POST', 'HEAD'], true)) {
    header('Allow: GET, POST');
    shop_msg(405, '打不开', '这个地址不能这样打开。');
}

try {
    app_init('api');
} catch (Throwable $e) {
    error_log('shop.php init: ' . get_class($e));
    shop_headers();
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><title>商城</title><p>商城暂时打不开，请稍后再试。</p>';
    exit;
}

if ($method === 'POST') {
    same_origin_or_die();
}

if ($a !== 'ref') {   // a share-link click only sets a cookie and redirects: no order maintenance on that path
    try {
        shop_maintain();
    } catch (Throwable $e) {
        error_log('shop.php maintain: ' . get_class($e));
    }
}

try {
    $settings = settings_get();
    $ss = $settings['shop'];

    // ---------- separate pay address (pay.<domain>) ----------
    // The pay host only serves the pay page and 我已付款; anything else goes back to the main site.
    // With the feature on, the pay page on the main host moves to the pay host so there is one address.
    $mainOrigin = shop_main_origin();
    $payOrigin = shop_pay_origin($ss);
    $onPayHost = str_starts_with(strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')), 'pay.');
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/api/shop.php');
    if ($onPayHost && $mainOrigin !== null && ($payOrigin === null || !in_array($a, ['pay', 'paid', 'usdt'], true)) && $method !== 'POST') {
        see_other($mainOrigin . $uri);
    }
    if (!$onPayHost && $payOrigin !== null && $a === 'pay' && $method !== 'POST') {
        see_other($payOrigin . $uri);
    }

    // ---------- 推广链接: remember the code, count the click, send the visitor to a page of this site ----------
    if ($a === 'ref') {
        if ($method === 'POST') {
            header('Allow: GET');
            shop_msg(405, '打不开', '这个地址不能这样打开。');
        }
        $target = ref_target(in_str('to', 200));   // strictly a path on this site, never another host
        $p = ref_partner_by_code(in_str('c', 20));
        if ($p !== null && $p['enabled'] && ref_enabled($settings) && $method === 'GET') {
            ref_set_cookie($p['code']);   // last click wins
            ref_click($p, visitor(), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        }
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
        header('Location: ../' . ($target === null || $target === '' ? '#ai' : $target), true, 302);
        exit;
    }

    // ---------- 推广人 view page ----------
    if ($a === 'partner' && $method !== 'POST') {
        $v = visitor();
        limit('shop-partner', $v, RL_PARTNER_10MIN, 600);
        if (rl_count('shop-partner-bad', $v, 3600) >= RL_PARTNER_BAD_HOUR) {
            shop_msg(429, '请稍后再试', MSG_BUSY, '../', '去网站首页');
        }
        $p = ref_partner_by_token(in_str('t', 64));
        if ($p === null) {
            rl_hit('shop-partner-bad', $v, RL_PARTNER_BAD_HOUR, 3600);
            shop_msg(404, '找不到这一页', '这个链接不对，或者已经换了新的。请向店主要新的链接。', '../', '去网站首页');
        }
        $doc = ref_doc();
        $sum = ref_partner_summary($p, shop_orders(), ref_settled_map($doc['settlements']));
        $mine = array_values(array_filter($doc['settlements'], fn($x) => $x['pid'] === $p['id']));
        shop_out(200, '我的推广', ref_partner_page_html($p, $sum, $mine, shop_products(), ref_enabled($settings)));
    }

    // ---------- order form / create ----------
    if ($a === 'order' && $method !== 'POST') {
        $p = shop_product_by_slug(in_str('p', 60));
        if ($p === null || !shop_product_live($p, $settings)) {
            shop_msg(404, '找不到这件商品', '这件商品已经下架了，或者网址不对。');
        }
        if (!$ss['enabled']) {
            shop_msg(503, '暂停营业', '商城暂停营业，现在不能下单。已经下的订单照常处理，可以在「查订单」里看进度。');
        }
        if ($p['type'] === 'supply') {
            if (!supply_ready($settings)) {
                shop_msg(503, '暂时不能买', '这件商品暂时不能买，过一会儿再来看看。');
            }
            $p = supply_refresh_product($p['id']) ?? $p;   // the supplier's stock, at most 5 minutes old
        }
        if ($p['stock'] !== null && $p['stock'] <= 0) {
            shop_msg(410, '售罄了', '这件商品已经卖完了。补货以后会重新开放购买。');
        }
        shop_out(200, '下单：' . $p['name'], shop_order_form_html($p, $ss));
    }
    if ($a === 'order') {
        $v = visitor();
        $tok = in_str('tok', 80);
        $payUrl = fn(string $id) => ($payOrigin !== null ? $payOrigin . '/api/' : '') . 'shop.php?a=pay&o=' . rawurlencode($id) . '&k=' . shop_pay_token($id);
        // the same form sent again (a second tap on a slow network): back to the order it already made, not counted
        $seen = shop_form_token_check('order', $tok) === 'ok' ? shop_order_form_seen($tok) : null;
        for ($i = 0; $seen === '' && $i < 20; $i++) {   // its twin is still making the order: wait for it
            usleep(500000);
            $seen = shop_order_form_seen($tok);
        }
        if ($seen !== null && $seen !== '' && shop_order_get($seen) !== null) {
            see_other($payUrl($seen));
        }
        limit('shop-order-try', $v, RL_ORDER_TRY_HOUR, 3600);
        if (rl_count('shop-order-site', local_day(), 2 * 86400) >= RL_ORDER_SITE_DAY || rl_count('shop-order-made', $v, 86400) >= RL_ORDER_MADE_DAY) {
            shop_msg(429, '请稍后再试', '今天的订单有点多，请明天再来，或者先在首页的店铺客服留言。');
        }
        form_ok('order');
        $p = shop_product_by_slug(in_str('p', 60));
        if ($p === null || !shop_product_live($p, $settings)) {
            shop_msg(404, '找不到这件商品', '这件商品已经下架了，这次没有下单。');
        }
        if ($p['type'] === 'supply') {
            $p = supply_refresh_product($p['id'], 60) ?? $p;   // check the supplier's stock again right before the order
        }
        $in = [];
        foreach (['qty', 'name', 'phone', 'address', 'email', 'note', 'consent'] as $k) {
            $in[$k] = in_str($k, 1000);
        }
        [$clean, $err] = shop_order_input($p, $in);
        if ($err) {
            shop_out(400, '下单：' . $p['name'], shop_order_form_html($p, $ss, $in, $err, '有几项要改一下：' . reset($err)));
        }
        $twin = shop_order_form_claim($tok);
        if ($twin !== null) {   // a twin of this request got here first
            if ($twin !== '' && shop_order_get($twin) !== null) {
                see_other($payUrl($twin));
            }
            shop_msg(409, '订单正在提交', '这张订单刚刚已经提交过，正在处理。请过一分钟在「查订单」里查看，不用再点提交。', 'shop.php?a=lookup', '查订单');
        }
        $before = shop_page_signature(shop_products());
        try {
            // 推广: the code this browser's cookie holds (a disabled or unknown code, or the program switched off, is ignored)
            $o = shop_create_order($p['slug'], $clean, null, ref_partner_from_cookie($settings));
            shop_order_form_done($tok, $o['id']);
        } catch (ShopError $e) {
            shop_order_form_done($tok, null);
            $fresh = shop_product_by_slug($p['slug']);
            if ($fresh !== null && shop_product_live($fresh, $settings) && $e->code_ === 'stock' && ($fresh['stock'] ?? 1) > 0) {
                shop_out(409, '下单：' . $p['name'], shop_order_form_html($fresh, $ss, $in, $e->fields, $e->getMessage()));
            }
            shop_msg($e->code_ === 'busy' ? 503 : 409, '没有下单', $e->getMessage());
        }
        rl_hit('shop-order-made', $v, RL_ORDER_MADE_DAY, 86400);
        rl_hit('shop-order-site', local_day(), RL_ORDER_SITE_DAY, 2 * 86400, true);
        shop_republish_if_changed($before);
        see_other($payUrl($o['id']));
    }

    // ---------- pay page / 我已付款 ----------
    if ($a === 'pay' && $method !== 'POST') {
        $id = in_str('o', 40);
        $o = shop_order_get($id);
        if ($o === null || !shop_pay_token_ok($id, in_str('k', 64))) {
            shop_msg(404, '找不到这个订单', '链接不完整，或者订单号不对。可以用「查订单」查看。', 'shop.php?a=lookup', '查订单');
        }
        $msg = in_str('m', 10) === 'claimed' && $o['status'] === 'claimed' ? '已记下：你付了款。我核对到账后会确认。' : '';
        shop_out(200, '付款 ' . $o['id'], shop_pay_html($o, $ss, $msg, usdt_chains_ready($settings)));
    }

    // ---------- USDT: choose how to pay (POST), the pay page's poll (GET, JSON) ----------
    if ($a === 'usdt' && $method === 'POST') {
        limit('shop-usdt', visitor(), RL_USDT_HOUR, 3600);
        $hp = $_POST['website'] ?? '';
        if (!is_string($hp) || $hp !== '' || shop_form_token_check('usdt', in_str('tok', 80)) === 'bad') {
            shop_msg(400, '没有提交成功', MSG_RETRY, 'shop.php?a=lookup', '查订单');   // a quick tap is fine here: the pay token is the real gate
        }
        $id = in_str('o', 40);
        $k = in_str('k', 64);
        if (shop_order_get($id) === null || !shop_pay_token_ok($id, $k)) {
            shop_msg(404, '找不到这个订单', '链接不完整，或者订单号不对。可以用「查订单」查看。', 'shop.php?a=lookup', '查订单');
        }
        $c = in_str('c', 10);
        if ($c !== 'qr' && !isset(USDT_CHAINS[$c])) {
            shop_msg(400, '没有提交成功', MSG_RETRY, 'shop.php?a=pay&o=' . rawurlencode($id) . '&k=' . $k, '回到付款页');
        }
        try {
            usdt_choose($id, $c);
        } catch (ShopError $e) {
            shop_msg(409, '没有换成', $e->getMessage(), 'shop.php?a=pay&o=' . rawurlencode($id) . '&k=' . $k, '回到付款页');
        }
        see_other('shop.php?a=pay&o=' . rawurlencode($id) . '&k=' . $k . ($c === 'qr' ? '' : '#usdt'));
    }
    if ($a === 'usdt') {
        $id = in_str('o', 40);
        if (!rl_hit('shop-usdt-poll', visitor(), RL_USDT_POLL_10MIN, 600)['allowed'] || (preg_match(ORDER_ID_RE, $id) && !rl_hit('shop-usdt-order', $id, RL_USDT_ORDER_10MIN, 600)['allowed'])) {
            json_answer(429, ['ok' => false, 'error' => 'busy', 'retry' => 30]);
        }
        $o = shop_order_get($id);
        if ($o === null || !shop_pay_token_ok($id, in_str('k', 64))) {
            json_answer(404, ['ok' => false, 'error' => 'not_found']);
        }
        $checked = false;
        if (usdt_order_payable($o) && usdt_paid($o) === null) {
            $now = time();
            foreach (array_unique(array_map(fn($q) => $q['chain'], array_filter(usdt_order_quotes($o), fn($q) => $now <= $q['expires'] + USDT_GRACE))) as $chain) {
                $r = usdt_check_chain($chain);   // cached: the chain is asked at most every 15 s; failures are logged, never shown
                $checked = $checked || $r['asked'];
            }
            $o = shop_order_get($id) ?? $o;
        }
        $q = usdt_current_quote($o);
        $paid = !in_array($o['status'], ['pending', 'claimed', 'cancelled'], true);
        $state = $paid ? 'paid' : ($o['status'] === 'pending' ? ($q === null ? 'qr' : (time() > $q['expires'] ? 'expired' : 'waiting')) : $o['status']);
        json_answer(200, ['ok' => true, 'state' => $state, 'left' => $q !== null ? max(0, $q['expires'] - time()) : 0, 'checked' => $checked]);
    }
    if ($a === 'paid' && $method !== 'POST') {
        header('Allow: POST');
        shop_msg(405, '打不开', '这个地址只能从付款页的按钮提交。', 'shop.php?a=lookup', '查订单');
    }
    if ($a === 'paid') {
        limit('shop-paid', visitor(), RL_PAID_HOUR, 3600);
        form_ok('paid');
        $id = in_str('o', 40);
        $k = in_str('k', 64);
        if (shop_order_get($id) === null || !shop_pay_token_ok($id, $k)) {
            shop_msg(404, '找不到这个订单', '链接不完整，或者订单号不对。可以用「查订单」查看。', 'shop.php?a=lookup', '查订单');
        }
        $o = shop_claim($id, in_str('payer', 200));
        see_other('shop.php?a=pay&o=' . rawurlencode($o['id']) . '&k=' . $k . '&m=claimed');
    }

    // ---------- lookup ----------
    if ($a === 'lookup' && $method !== 'POST') {
        shop_out(200, '查订单', shop_lookup_form_html(order_id_input(in_str('o', 40))));
    }
    if ($a === 'lookup') {
        $v = visitor();
        limit('shop-lookup', $v, RL_LOOKUP_10MIN, 600);
        limit('shop-lookup-day', $v, RL_LOOKUP_DAY, 86400);
        form_ok('lookup');
        $id = order_id_input(in_str('o', 40));
        $cands = shop_verify_candidates(in_str('v', 200));
        $o = preg_match(ORDER_ID_RE, $id) ? shop_order_get($id) : null;
        if (preg_match(ORDER_ID_RE, $id)) {
            // every guess against one order number counts, from any address: four digits are not much of a
            // secret. Numbers that do not exist are counted the same way, so the lock reveals nothing.
            $gate = rl_hit('shop-lookup-order', $id, RL_LOOKUP_ORDER_HOUR, 3600);
            if (!$gate['allowed']) {
                shop_out(429, '查订单', shop_lookup_form_html($id, '这个订单号查询的次数太多了，请一小时后再试。'));
            }
        }
        $want = $o !== null ? (string) $o['verify'] : str_repeat('0', 64);
        $match = false;
        foreach (array_pad($cands, 2, '') as $c) {   // always two hashes: same work whether or not the order exists
            $match = (hash_equals($want, shop_verify_hash($id, $c)) && $c !== '') || $match;
        }
        if ($o === null || !$match) {
            shop_out(400, '查订单', shop_lookup_form_html($id, MSG_LOOKUP_BAD));
        }
        rl_clear('shop-lookup-order', $id);
        if ($o['type'] === 'supply' && !empty($o['supply']['pending'])) {
            try {
                $o = supply_poll($o['id']) ?? $o;   // a card that was not out yet: ask the 货源 again (once a minute per order)
            } catch (Throwable $e) {
                error_log('shop.php poll: ' . get_class($e));
            }
        }
        $product = shop_product_get($o['product_id']);
        $dl = null;
        if ($o['type'] === 'digital' && in_array($o['status'], ['confirmed', 'completed'], true) && $product !== null && $product['delivery']['file'] !== null) {
            $exp = time() + SHOP_DL_TTL;
            $fid = $product['delivery']['file']['id'];
            $dl = 'shop.php?a=dl&o=' . rawurlencode($o['id']) . '&e=' . $exp . '&s=' . shop_dl_sig($o['id'], $exp, $fid);
        }
        shop_out(200, '订单 ' . $o['id'], shop_lookup_result_html($o, $product, $dl));
    }

    // ---------- digital download ----------
    if ($a === 'dl' && $method !== 'POST') {
        limit('shop-dl', visitor(), RL_DL_HOUR, 3600);
        $id = in_str('o', 40);
        $exp = ctype_digit(in_str('e', 12)) ? (int) in_str('e', 12) : 0;
        $sig = in_str('s', 64);
        $o = shop_order_get($id);
        $p = $o !== null ? shop_product_get($o['product_id']) : null;
        $f = $p['delivery']['file'] ?? null;
        $now = time();
        if ($o === null || $f === null || $exp < $now || $exp > $now + SHOP_DL_TTL + 60
            || !hash_equals(shop_dl_sig($id, $exp, $f['id']), $sig)
            || $o['type'] !== 'digital' || !in_array($o['status'], ['confirmed', 'completed'], true)) {
            shop_msg(403, '下载链接失效了', '下载链接只在 30 分钟内有效。请回到「查订单」重新查一次，再点下载。', 'shop.php?a=lookup&o=' . rawurlencode(order_id_input($id)), '查订单');
        }
        $path = shop_file_path($f['id']);
        if (!is_file($path)) {
            shop_msg(404, '文件不见了', '这个文件暂时下载不了。请在首页的店铺客服留言，我会补发。');
        }
        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $f['name']);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . ($ascii !== '' ? $ascii : 'download') . '"; filename*=UTF-8\'\'' . rawurlencode($f['name']));
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        if ($method !== 'HEAD') {
            readfile($path);
        }
        exit;
    }

    shop_msg(404, '找不到这一页', '商城没有这个页面。');
} catch (ShopError $e) {
    shop_msg(409, '没有完成', $e->getMessage());
} catch (Throwable $e) {
    error_log('shop.php ' . $a . ': ' . get_class($e) . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    shop_msg(500, '出错了', '服务器出错了，这次没有完成。请稍后再试。');
}
