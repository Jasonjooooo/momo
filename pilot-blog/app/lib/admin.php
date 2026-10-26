<?php
// Admin controllers (SPEC §12, §13.1). Loaded by admin/index.php only; storage and publishing live in the core lib.
declare(strict_types=1);
defined('BLOG_APP') || exit;

/** route => [handler, methods, access ('setup' | 'public' | 'auth'), answers JSON] */
const ADMIN_ROUTES = [
    'setup' => ['admin_setup', ['GET', 'POST'], 'setup', false],
    'login' => ['admin_login', ['GET', 'POST'], 'public', false],
    'logout' => ['admin_logout', ['POST'], 'auth', false],
    'password' => ['admin_password', ['GET', 'POST'], 'auth', false],
    'dashboard' => ['admin_dashboard', ['GET'], 'auth', false],
    'site' => ['admin_site', ['GET'], 'auth', false],
    'site.save' => ['admin_unit_save', ['POST'], 'auth', true],
    'site.publish' => ['admin_unit_publish', ['POST'], 'auth', true],
    'site.discard' => ['admin_unit_discard', ['POST'], 'auth', true],
    'site.origin' => ['admin_site_origin', ['POST'], 'auth', false],
    'preview.home' => ['admin_preview_home', ['POST'], 'auth', false],
    'posts' => ['admin_posts', ['GET'], 'auth', false],
    'posts.limit' => ['admin_posts_limit', ['POST'], 'auth', false],
    'post.new' => ['admin_post_new', ['POST'], 'auth', false],
    'post.edit' => ['admin_post_edit', ['GET'], 'auth', false],
    'post.save' => ['admin_post_action', ['POST'], 'auth', true],
    'post.publish' => ['admin_post_action', ['POST'], 'auth', true],
    'post.teaser' => ['admin_post_action', ['POST'], 'auth', true],
    'post.unpublish' => ['admin_post_action', ['POST'], 'auth', true],
    'post.delete' => ['admin_post_delete', ['POST'], 'auth', false],
    'preview.post' => ['admin_preview_post', ['POST'], 'auth', false],
    'upload' => ['admin_upload', ['POST'], 'auth', true],
    'biz' => ['admin_biz', ['GET'], 'auth', false],
    'shop' => ['admin_shop', ['GET'], 'auth', false],
    'shop.new' => ['admin_shop_new', ['POST'], 'auth', false],
    'shop.edit' => ['admin_shop_edit', ['GET'], 'auth', false],
    'shop.save' => ['admin_shop_save', ['POST'], 'auth', false],
    'shop.photo' => ['admin_shop_photo', ['POST'], 'auth', false],
    'shop.photo_op' => ['admin_shop_photo_op', ['POST'], 'auth', false],
    'shop.file' => ['admin_shop_file', ['POST'], 'auth', false],
    'shop.file_del' => ['admin_shop_file_del', ['POST'], 'auth', false],
    'shop.move' => ['admin_shop_move', ['POST'], 'auth', false],
    'shop.delete' => ['admin_shop_delete', ['POST'], 'auth', false],
    'cats' => ['admin_cats', ['GET'], 'auth', false],
    'cats.add' => ['admin_cats_add', ['POST'], 'auth', false],
    'cats.rename' => ['admin_cats_rename', ['POST'], 'auth', false],
    'cats.move' => ['admin_cats_move', ['POST'], 'auth', false],
    'cats.delete' => ['admin_cats_delete', ['POST'], 'auth', false],
    'orders' => ['admin_orders', ['GET'], 'auth', false],
    'order' => ['admin_order', ['GET'], 'auth', false],
    'order.act' => ['admin_order_act', ['POST'], 'auth', false],
    'orders.export' => ['admin_orders_export', ['GET'], 'auth', false],
    'pay' => ['admin_pay', ['GET'], 'auth', false],
    'pay.save' => ['admin_pay_save', ['POST'], 'auth', false],
    'pay.qr' => ['admin_pay_qr', ['POST'], 'auth', false],
    'pay.qr_del' => ['admin_pay_qr_del', ['POST'], 'auth', false],
    'pay.usdt' => ['admin_pay_usdt', ['POST'], 'auth', false],
    'pay.usdt_key' => ['admin_pay_usdt_key', ['POST'], 'auth', false],
    'pay.usdt_key_del' => ['admin_pay_usdt_key_del', ['POST'], 'auth', false],
    'usdt' => ['admin_usdt', ['GET'], 'auth', false],
    'usdt.check' => ['admin_usdt_check', ['POST'], 'auth', false],
    'usdt.attach' => ['admin_usdt_attach', ['POST'], 'auth', false],
    'usdt.dismiss' => ['admin_usdt_dismiss', ['POST'], 'auth', false],
    'supply' => ['admin_supply', ['GET'], 'auth', false],
    'supply.save' => ['admin_supply_save', ['POST'], 'auth', false],
    'supply.key_remove' => ['admin_supply_key_remove', ['POST'], 'auth', false],
    'supply.test' => ['admin_supply_test', ['POST'], 'auth', false],
    'supply.refresh' => ['admin_supply_refresh', ['POST'], 'auth', false],
    'supply.item' => ['admin_supply_item', ['GET'], 'auth', false],
    'supply.import' => ['admin_supply_import', ['POST'], 'auth', false],
    'supply.product' => ['admin_supply_product', ['POST'], 'auth', false],
    'ref' => ['admin_ref', ['GET'], 'auth', false],
    'ref.toggle' => ['admin_ref_toggle', ['POST'], 'auth', false],
    'ref.add' => ['admin_ref_add', ['POST'], 'auth', false],
    'ref.edit' => ['admin_ref_edit', ['GET'], 'auth', false],
    'ref.save' => ['admin_ref_save', ['POST'], 'auth', false],
    'ref.token' => ['admin_ref_token', ['POST'], 'auth', false],
    'ref.settle' => ['admin_ref_settle', ['POST'], 'auth', false],
    'ref.export' => ['admin_ref_export', ['GET'], 'auth', false],
    'subs' => ['admin_subs', ['GET'], 'auth', false],
    'subs.delete' => ['admin_subs_delete', ['POST'], 'auth', false],
    'subs.toggle' => ['admin_subs_toggle', ['POST'], 'auth', false],
    'subs.export' => ['admin_subs_export', ['GET'], 'auth', false],
    'stats' => ['admin_stats', ['GET'], 'auth', false],
    'stats.toggle' => ['admin_stats_toggle', ['POST'], 'auth', false],
    'game' => ['admin_game', ['GET'], 'auth', false],
    'game.toggle' => ['admin_game_toggle', ['POST'], 'auth', false],
    'game.delete' => ['admin_game_delete', ['POST'], 'auth', false],
    'game.clear' => ['admin_game_clear', ['POST'], 'auth', false],
    'poster' => ['admin_poster', ['GET'], 'auth', false],
    'notify' => ['admin_notify', ['GET'], 'auth', false],
    'notify.token' => ['admin_notify_token', ['POST'], 'auth', false],
    'notify.token_remove' => ['admin_notify_token_remove', ['POST'], 'auth', false],
    'notify.events' => ['admin_notify_events', ['POST'], 'auth', false],
    'notify.test' => ['admin_notify_test', ['POST'], 'auth', false],
    'ai' => ['admin_ai', ['GET'], 'auth', false],
    'ai.save' => ['admin_ai_save', ['POST'], 'auth', false],
    'ai.key' => ['admin_ai_key', ['POST'], 'auth', false],
    'ai.key_remove' => ['admin_ai_key_remove', ['POST'], 'auth', false],
    'ai.dbkey' => ['admin_ai_dbkey', ['POST'], 'auth', false],
    'ai.dbkey_remove' => ['admin_ai_dbkey_remove', ['POST'], 'auth', false],
    'ai.test' => ['admin_ai_test', ['POST'], 'auth', true],
    'versions' => ['admin_versions', ['GET'], 'auth', false],
    'versions.view' => ['admin_versions_view', ['GET'], 'auth', false],
    'versions.rollback' => ['admin_versions_rollback', ['POST'], 'auth', false],
    'publish.all' => ['admin_publish_all', ['POST'], 'auth', false],
];

/** Menu order (SPEC §12.2); 退出 is a POST form after these. */
const ADMIN_MENU = [
    'dashboard' => '概览',
    'site' => '网站文字',
    'posts' => '飞行日志',
    'biz' => '经营总览',
    'shop' => '商品',
    'cats' => '商品分类',
    'orders' => '订单',
    'pay' => '收款设置',
    'supply' => '货源',
    'ref' => '推广',
    'subs' => '订阅者',
    'stats' => '访问统计',
    'game' => '小游戏排行榜',
    'notify' => '微信提醒',
    'poster' => '分享海报',
    'ai' => 'AI 助手',
    'versions' => '版本记录',
    'password' => '修改密码',
];

const ADMIN_FAVICON = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='6' fill='%230A1733'/%3E%3Cg transform='translate%282 12.56%29 scale%280.01697%29 translate%28-245 -258%29' fill='none' stroke='%23ECE9F1' stroke-width='70' stroke-linejoin='round'%3E%3Cpath d='M250,566 C262,550 292,534 334,531 C420,518 510,503 578,496 L636,460 C660,446 690,440 730,438 C900,436 1100,446 1236,458 C1310,452 1390,440 1438,427 L1518,292 L1606,292 L1573,462 L1548,530 C1500,562 1420,592 1360,604 C1240,616 1060,632 880,650 C760,654 560,652 470,646 C420,644 370,642 352,638 C340,628 336,612 334,600 C300,597 268,584 250,566 Z'/%3E%3Cpath d='M880,612 C1100,608 1500,603 1745,601 L1862,553 L1882,554 L1787,618 C1500,626 1150,636 990,648 C940,652 900,650 880,640 Z'/%3E%3Cpath d='M1372,284 C1420,276 1480,272 1520,270 L1790,262 L1840,267 L1790,276 L1560,282 C1480,286 1410,290 1372,284 Z'/%3E%3C/g%3E%3C/svg%3E";

const MSG_SAVED_SITE = '已保存草稿。网站还没变，点「保存并发布」才会更新。';
const MSG_SAVED_POST = '已保存草稿。网站还没变，点「发布」才会更新。';
const MSG_PUBLISHED = '已发布。网站已经更新，打开时如果还是旧的，刷新一下。';
const MSG_SERVER = '服务器出错了，这次没有保存。内容还在这页上，稍后再试。';

function admin_dispatch(): void
{
    $r = $_GET['r'] ?? 'dashboard';
    if (!is_string($r) || $r === '') {
        $r = 'dashboard';
    }
    $def = ADMIN_ROUTES[$r] ?? null;
    if ($def === null) {
        http_response_code(404);
        view('error', ['title' => '找不到这一页', 'message' => '后台没有这个页面。', 'bare' => !auth_check()]);
        return;
    }
    [$handler, $methods, $access, $json] = $def;
    $json = $json || admin_wants_json();
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($method, $methods, true)) {
        header('Allow: ' . implode(', ', $methods));
        if ($json) {
            json_out(405, ['ok' => false, 'error' => 'method', 'message' => '这个操作不能这样打开。']);
        }
        http_response_code(405);
        view('error', ['title' => '打不开', 'message' => '这个地址只能从后台的按钮提交，不能直接打开。', 'bare' => !auth_check()]);
        return;
    }
    if ($access === 'setup' && !setup_needed()) {
        redirect('login');
    }
    if ($access !== 'setup' && setup_needed()) {
        if ($json) {
            json_out(401, ['ok' => false, 'error' => 'auth_required', 'message' => '后台还没有设置密码。']);
        }
        redirect('setup');
    }
    // the post preview lives in an iframe: admin pages refuse to be framed, so say it there in words
    if ($r === 'preview.post' && $access === 'auth' && !auth_check()) {
        admin_frame_note(401, '登录已经过期，预览暂时看不了。点「保存草稿」，输入密码以后就能接着写，写的内容还在。');
        return;
    }
    if ($access === 'auth') {
        auth_require($json);
        stats_owner_mark();   // the owner's own visits to the site are not counted
    }
    if ($method === 'POST') {
        origin_verify($json);
        if ($r === 'preview.post' && !admin_csrf_ok()) {
            admin_frame_note(403, '这一页放得太久了，预览暂时看不了。点一次「保存草稿」就会恢复。');
            return;
        }
        csrf_verify($json);
    }
    $_GET['r'] = $r;
    $handler();
}

function admin_csrf_ok(): bool
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
    return is_string($sent) && csrf_token_ok($sent);
}

/** A short note shown inside the post preview iframe (framing allowed, no script). */
function admin_frame_note(int $status, string $msg): void
{
    send_headers('preview-post');
    http_response_code($status);
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow"><title>预览</title><link rel="stylesheet" href="' . h(asset_url('admin.css')) . '"></head>'
        . '<body class="is-bare"><main class="main"><p class="notice notice-warn">' . h($msg) . '</p></main></body></html>';
}

function admin_wants_json(): bool
{
    return str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
}

function admin_menu_active(string $page): string
{
    return match ($page) {
        'post_edit' => 'posts',
        'shop_edit' => 'shop',
        'order' => 'orders',
        'usdt' => 'orders',
        'supply_item' => 'supply',
        'ref_edit' => 'ref',
        default => $page,
    };
}

function admin_brand(): string
{
    try {
        $b = (string) (content_get('site', 'live')['fields']['site.brand'] ?? '');
    } catch (Throwable $e) {
        $b = '';
    }
    return $b !== '' ? $b : '云底之下';
}

function post_str(string $k, int $max = 100000): string
{
    $v = $_POST[$k] ?? '';
    return is_string($v) ? substr($v, 0, $max) : '';
}

/** Numbers typed with a Chinese input method in full-width mode (１２０) are ordinary numbers. */
function ascii_digits(string $s): string
{
    return trim((string) preg_replace_callback('/[\x{FF10}-\x{FF19}]/u', fn($m) => (string) (mb_ord($m[0]) - 0xFF10), $s));
}

function admin_json_body(): array
{
    $raw = (string) file_get_contents('php://input', false, null, 0, 3000001);
    if (strlen($raw) > 3000000) {
        json_out(413, ['ok' => false, 'error' => 'too_large', 'message' => '内容太长了，没有保存。']);
    }
    $d = json_decode($raw, true);
    if (!is_array($d)) {
        json_out(400, ['ok' => false, 'error' => 'invalid', 'message' => '没有收到内容，再点一次试试。']);
    }
    return $d;
}

/** Human label for an error path, so the status line can say which field failed. */
function admin_error_label(string $unit, string $path, array $doc = []): string
{
    $reg = field_registry();
    $groups = field_groups();
    if (preg_match('/^fields\.(.+)$/', $path, $m) && isset($reg[$m[1]])) {
        return ($groups[$reg[$m[1]]['group']] ?? '') . '：' . $reg[$m[1]]['label'];
    }
    $names = ['q' => '问题', 'r' => '简答', 'a' => '回答', 'nid' => '编号', 'k' => '代码', 'val' => '数值', 'line' => '说明',
        'title' => '名字', 'blurb' => '介绍', 'name' => '名称', 'desc' => '一句话介绍', 'url' => '网址', 'note' => '使用心得', 'cn_access' => '国内能不能直接访问', 'id' => '代号'];
    if (preg_match('/^faq\.(\d+)\.(\w+)$/', $path, $m)) {
        return '问答第 ' . ($m[1] + 1) . ' 条的' . ($names[$m[2]] ?? $m[2]);
    }
    if (preg_match('/^album\.(\d+)(?:\.(\w+))?$/', $path, $m)) {
        return '第 ' . ($m[1] + 1) . ' 张照片' . (isset($m[2]) ? '的' . (['src' => '图片', 'caption' => '下方小字', 'alt' => '照片说明'][$m[2]] ?? $m[2]) : '');
    }
    if ($path === 'album') {
        return '关于：照片';
    }
    if (preg_match('/^type_facts\.(\d+)\.(\w+)$/', $path, $m)) {
        return '数据格子第 ' . ($m[1] + 1) . ' 条的' . ($names[$m[2]] ?? $m[2]);
    }
    if (preg_match('/^shelves\.(\d+)\.tools\.(\d+)\.(\w+)$/', $path, $m)) {
        $tool = (string) ($doc['shelves'][(int) $m[1]]['tools'][(int) $m[2]]['name'] ?? '');
        return '第 ' . ($m[1] + 1) . ' 个货架的' . ($tool !== '' ? '「' . $tool . '」' : '第 ' . ($m[2] + 1) . ' 个工具') . '：' . ($names[$m[3]] ?? $m[3]);
    }
    if (preg_match('/^shelves\.(\d+)\.(\w+)$/', $path, $m)) {
        return '第 ' . ($m[1] + 1) . ' 个货架的' . ($names[$m[2]] ?? $m[2]);
    }
    return '';
}

/** One plain sentence for the status line from a ValidationError's field map. */
function admin_error_sentence(string $unit, array $fields, array $doc = []): string
{
    $path = (string) array_key_first($fields);
    $msg = (string) ($fields[$path] ?? '内容没有通过检查。');
    $label = $unit === 'post' ? '' : admin_error_label($unit, $path, $doc);
    if ($label === '') {
        return $msg;
    }
    return '「' . $label . '」' . (str_starts_with($msg, '这一项') ? mb_substr($msg, 3) : $msg);
}

function strip_full_stop(string $s): string
{
    return (string) preg_replace('/[。．.]+$/u', '', $s);
}

/** Map an exception from a save or publish to a JSON answer. $prefix: '保存失败：' or '发布失败：'. */
function admin_json_fail(Throwable $e, string $prefix, string $unit, array $extra = [], array $doc = []): never
{
    if ($e instanceof ConflictException) {
        json_out(409, ['ok' => false, 'error' => 'conflict', 'message' => $e->getMessage()] + $extra);
    }
    if ($e instanceof ValidationError) {
        $raw = $e->getMessage();
        if (str_starts_with($raw, '发布失败') || str_starts_with($raw, '删除失败')) {
            $msg = $raw;
        } else {
            $sentence = admin_error_sentence($unit, $e->fields, $doc);
            $msg = $prefix === '发布失败：' ? $prefix . strip_full_stop($sentence) . '。网站没有变化。' : $prefix . $sentence;
        }
        json_out(400, ['ok' => false, 'error' => 'invalid', 'message' => $msg, 'fields' => $e->fields] + $extra);
    }
    if ($e instanceof InvalidArgumentException) {
        json_out(404, ['ok' => false, 'error' => 'invalid', 'message' => '找不到这一项，可能已经删除了。刷新页面看看。'] + $extra);
    }
    error_log('admin ' . ($_GET['r'] ?? '') . ': ' . get_class($e) . ' ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    json_out(500, ['ok' => false, 'error' => 'server', 'message' => $prefix === '发布失败：' ? '发布失败：服务器出错了。网站没有变化。' : MSG_SERVER] + $extra);
}

function publish_fail_message(array $res): string
{
    $first = (string) (reset($res['errors']) ?: '页面没有生成成功');
    return '发布失败：' . strip_full_stop($first) . '。网站没有变化。';
}

// ---------- setup, login, logout, password ----------

function admin_setup(): void
{
    $vars = ['title' => '第一次使用', 'bare' => true, 'error' => null, 'field' => null, 'insecure' => insecure_transport()];
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$vars['insecure']) {
        $r = setup_complete(post_str('code', 200), post_str('password', 1000), post_str('password2', 1000));
        if ($r['ok']) {
            flash('ok', '后台密码设好了。以后用这个密码登录。');
            redirect('dashboard');
        }
        $vars['error'] = $r['error'];
        $vars['field'] = $r['field'];
        http_response_code(400);
    }
    view('setup', $vars);
}

function admin_login(): void
{
    $json = admin_wants_json();
    $insecure = insecure_transport();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($json) {
            json_out(200, ['ok' => true, 'csrf' => csrf_token(), 'logged_in' => auth_check()]);
        }
        if (auth_check()) {
            redirect('dashboard');
        }
        view('login', ['title' => '登录', 'bare' => true, 'error' => null, 'insecure' => $insecure]);
        return;
    }
    if ($insecure) {
        if ($json) {
            json_out(403, ['ok' => false, 'error' => 'https', 'message' => '请用 https:// 打开后台，再登录。']);
        }
        view('login', ['title' => '登录', 'bare' => true, 'error' => null, 'insecure' => true]);
        return;
    }
    $r = auth_login(post_str('password', 1000));
    if ($json) {
        if ($r['ok']) {
            json_out(200, ['ok' => true, 'csrf' => csrf_token()]);
        }
        json_out(401, ['ok' => false, 'error' => 'login', 'message' => $r['error']]);
    }
    if ($r['ok']) {
        redirect('dashboard');
    }
    http_response_code(401);
    view('login', ['title' => '登录', 'bare' => true, 'error' => $r['error'], 'insecure' => false]);
}

function admin_logout(): void
{
    audit('logout');
    auth_logout();
    flash('ok', '已退出。');
    redirect('login');
}

function admin_password(): void
{
    $vars = ['title' => '修改密码', 'error' => null, 'field' => null];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $r = password_change(post_str('old', 1000), post_str('new1', 1000), post_str('new2', 1000));
        if ($r['ok']) {
            flash('ok', '密码已经改好。其他设备上的登录都已退出，下次用新密码登录。');
            redirect('password');
        }
        $vars['error'] = $r['error'];
        $vars['field'] = $r['field'];
        http_response_code(400);
    }
    view('password', $vars);
}

// ---------- 概览 ----------

function admin_dashboard(): void
{
    admin_shop_maintain();
    $heal = null;
    $healError = null;
    try {
        $before = site_status();
        $res = site_self_heal();
        if ($res !== null && !$before['no_version']) {
            $heal = $res['ok'];
            if (!$res['ok']) {
                $healError = publish_fail_message($res);
            }
        }
    } catch (Throwable $e) {
        error_log('admin self-heal: ' . get_class($e) . ' ' . $e->getMessage());
        $heal = false;
        $healError = '网站文件和后台内容不一致，重新生成页面时出错了。稍后再打开这一页试试。';
    }
    $st = site_status();
    $posts = posts_all();
    $counts = ['published' => 0, 'teaser' => 0, 'draft' => 0];
    foreach ($posts as $p) {
        $counts[post_status($p)]++;
    }
    $settings = settings_get();
    $today = usage_range(1)[0];
    $origin = (string) (content_get('site', 'live')['fields']['site.origin'] ?? '');
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $suggest = 'https://' . $host;
    view('dashboard', [
        'title' => '概览',
        'st' => $st,
        'heal' => $heal,
        'heal_error' => $healError,
        'counts' => $counts,
        'subs_total' => count(sub_all_emails()),
        'subs_week' => sub_count_since(time() - 7 * 86400),
        'settings' => $settings,
        'chat_live' => chat_live(),
        'has_key' => chat_key($settings['chat']) !== null,
        'today' => $today,
        'stats_on' => stats_enabled($settings),
        'stats_today' => stats_day(local_day()),
        'origin_missing' => str_contains($origin, 'DEPLOY-DOMAIN'),
        'shop' => shop_counts(shop_orders()),
        'shop_open' => (bool) $settings['shop']['enabled'],
        'shop_selling' => count(array_filter(shop_products(), fn($p) => shop_product_live($p, $settings))),
        'supply_on' => (bool) $settings['supply']['enabled'],
        'supply_n' => count(supply_products()),
        'shop_qr' => count(array_filter($settings['shop']['qr'])),
        'origin_suggest' => field_problem('site.origin', $suggest) === null ? $suggest : null,
    ]);
}

function admin_publish_all(): void
{
    try {
        $res = publish_site('重新生成全部页面');
        $res['ok'] ? flash('ok', '已重新生成全部页面。') : flash('error', publish_fail_message($res));
    } catch (Throwable $e) {
        error_log('admin publish.all: ' . get_class($e) . ' ' . $e->getMessage());
        flash('error', '重新生成失败：服务器出错了。网站没有变化。');
    }
    redirect('dashboard');
}

function admin_site_origin(): void
{
    $origin = 'https://' . strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    try {
        $res = site_origin_set($origin);
        $res['ok'] ? flash('ok', '网站地址已经设成 ' . $origin . '，分享卡片可以用了。') : flash('error', publish_fail_message($res));
    } catch (ValidationError $e) {
        flash('error', '没有设置：' . (string) reset($e->fields));
    } catch (Throwable $e) {
        error_log('admin site.origin: ' . get_class($e));
        flash('error', '没有设置：服务器出错了。');
    }
    redirect('dashboard');
}

// ---------- 网站文字 (draft/live unit; the AI 超市 unit left the admin with the shop, SPEC §18 item 26) ----------

function unit_strip(array $doc, string $unit): array
{
    $keys = $unit === 'site' ? ['fields', 'faq', 'type_facts', 'album'] : ['shelves'];
    $out = array_intersect_key($doc, array_flip($keys));
    if ($unit === 'site' && $doc) {
        $out['album'] = site_album($doc);   // content saved before the album: its one photo
    }
    return $out;
}

function unit_state(string $unit): array
{
    $d = content_get($unit, 'draft');
    $l = content_get($unit, 'live');
    return [
        'rev' => (int) ($d['rev'] ?? 0),
        'updated_at' => $d['updated_at'] ?? null,
        'dirty' => unit_strip($d, $unit) != unit_strip($l, $unit),
        'published_at' => $l['published_at'] ?? null,
    ];
}

function admin_site(): void
{
    $draft = content_get('site', 'draft');
    $live = content_get('site', 'live');
    view('site', [
        'title' => '网站文字',
        'draft' => site_normalize($draft),
        'live' => unit_strip($live, 'site'),
        'state' => unit_state('site'),
        'reg' => field_registry(),
        'groups' => field_groups(),
        'warnings' => site_warnings(site_normalize($draft)),
    ]);
}

function admin_unit_from_route(): string
{
    return str_starts_with((string) $_GET['r'], 'site.') ? 'site' : 'shelf';
}

function admin_unit_input(string $unit, array $in): ?array
{
    $doc = $in['doc'] ?? null;
    if (!is_array($doc)) {
        return null;
    }
    return $unit === 'site'
        ? ['fields' => is_array($doc['fields'] ?? null) ? $doc['fields'] : [], 'faq' => is_array($doc['faq'] ?? null) ? $doc['faq'] : [], 'type_facts' => is_array($doc['type_facts'] ?? null) ? $doc['type_facts'] : []]
            + (is_array($doc['album'] ?? null) ? ['album' => $doc['album']] : [])
        : ['shelves' => is_array($doc['shelves'] ?? null) ? $doc['shelves'] : []];
}

function admin_unit_answer(string $unit, array $doc, array $extra = []): array
{
    $st = unit_state($unit);
    $out = ['ok' => true, 'rev' => $st['rev'], 'updated_at' => $st['updated_at'], 'dirty' => $st['dirty']] + $extra;
    if ($unit === 'site') {
        $out['warnings'] = site_warnings(site_normalize($doc));
    } else {
        $out['ids'] = array_map(fn($s) => $s['id'], $doc['shelves'] ?? []);
    }
    return $out;
}

function admin_unit_save(): void
{
    $unit = admin_unit_from_route();
    $in = admin_json_body();
    $doc = admin_unit_input($unit, $in);
    if ($doc === null) {
        json_out(400, ['ok' => false, 'error' => 'invalid', 'message' => '没有收到内容，再点一次试试。']);
    }
    try {
        $saved = content_save_draft($unit, $doc, (int) ($in['rev'] ?? -1));
    } catch (Throwable $e) {
        admin_json_fail($e, '保存失败：', $unit, [], $doc);
    }
    json_out(200, admin_unit_answer($unit, $saved, ['message' => MSG_SAVED_SITE]));
}

function admin_unit_publish(): void
{
    $unit = admin_unit_from_route();
    $in = admin_json_body();
    $rev = (int) ($in['rev'] ?? -1);
    $doc = admin_unit_input($unit, $in);
    if ($doc !== null) {
        try {
            $saved = content_save_draft($unit, $doc, $rev);
            $rev = (int) $saved['rev'];
        } catch (Throwable $e) {
            admin_json_fail($e, '保存失败：', $unit, [], $doc);
        }
    }
    $draft = content_get($unit, 'draft');
    $extra = admin_unit_answer($unit, $draft);
    unset($extra['ok']);
    try {
        $res = content_publish($unit, $rev);
    } catch (Throwable $e) {
        admin_json_fail($e, '发布失败：', $unit, $extra + ['saved' => true], $draft);
    }
    if (!$res['ok']) {
        json_out(500, ['ok' => false, 'error' => 'server', 'message' => publish_fail_message($res), 'saved' => true] + $extra);
    }
    json_out(200, admin_unit_answer($unit, $draft, ['message' => MSG_PUBLISHED, 'version_id' => $res['version_id'] ?? null]));
}

function admin_unit_discard(): void
{
    $unit = admin_unit_from_route();
    try {
        $doc = content_discard($unit);
    } catch (Throwable $e) {
        admin_json_fail($e, '没有恢复：', $unit);
    }
    flash('ok', '已放弃草稿，内容恢复成网站上的版本。');
    json_out(200, admin_unit_answer($unit, $doc, ['reload' => true]));
}

function admin_preview_fail(string $msg): void
{
    send_headers('admin');
    http_response_code(400);
    view('error', ['title' => '预览不了', 'message' => $msg, 'bare' => true]);
}

function admin_preview_home(): void
{
    $site = content_get('site', 'draft');
    $shelf = content_get('shelf', 'draft');
    if (isset($_POST['site'])) {
        $d = json_decode(post_str('site', 3000000), true);
        if (is_array($d)) {
            $site = $d;
        }
    }
    if (isset($_POST['shelf'])) {
        $d = json_decode(post_str('shelf', 3000000), true);
        if (is_array($d)) {
            $shelf = shelf_normalize($d);
        }
    }
    try {
        $html = preview_home($site, $shelf);
    } catch (ValidationError $e) {
        $unit = isset($_POST['shelf']) ? 'shelf' : 'site';
        admin_preview_fail('预览不了：' . admin_error_sentence($unit, $e->fields, $unit === 'shelf' ? $shelf : $site) . ' 改好以后再点预览。');
        return;
    }
    send_headers('preview-home');
    echo $html;
}

// ---------- 飞行日志 ----------

const POST_STATUS_LABEL = ['draft' => '草稿', 'teaser' => '预告', 'published' => '已发布'];

function admin_posts(): void
{
    $f = is_string($_GET['f'] ?? null) && in_array($_GET['f'], ['all', 'published', 'teaser', 'draft'], true) ? $_GET['f'] : 'all';
    $all = posts_all();
    $counts = ['all' => count($all), 'published' => 0, 'teaser' => 0, 'draft' => 0];
    foreach ($all as $p) {
        $counts[post_status($p)]++;
    }
    $rank = ['published' => 0, 'teaser' => 1, 'draft' => 2];
    usort($all, function ($a, $b) use ($rank) {
        $sa = post_status($a);
        $sb = post_status($b);
        if ($sa !== $sb) {
            return $rank[$sa] <=> $rank[$sb];
        }
        if ($sa === 'teaser') {
            return (int) ($a['teaser_order'] ?? 0) <=> (int) ($b['teaser_order'] ?? 0);
        }
        $da = ($a['live']['date'] ?? $a['draft']['date'] ?? '') . ($a['updated_at'] ?? '');
        $db = ($b['live']['date'] ?? $b['draft']['date'] ?? '') . ($b['updated_at'] ?? '');
        return strcmp($db, $da);
    });
    $rows = array_values(array_filter($all, fn($p) => $f === 'all' || post_status($p) === $f));
    view('posts', ['title' => '飞行日志', 'filter' => $f, 'counts' => $counts, 'rows' => $rows, 'settings' => settings_get()]);
}

function admin_posts_limit(): void
{
    $n = (int) post_str('leg_limit', 10);
    try {
        settings_save(['home' => ['leg_limit' => $n]], (int) post_str('rev', 20));
        flash('ok', '已保存。首页最多显示 ' . $n . ' 篇，网站已经更新。');
    } catch (ValidationError $e) {
        flash('error', '没有保存：' . (string) reset($e->fields));
    } catch (ConflictException $e) {
        flash('error', '没有保存：设置在别处改过了，刷新页面再试。');
    }
    redirect('posts');
}

function admin_post_new(): void
{
    $p = post_create();
    redirect('post.edit', ['id' => $p['id']]);
}

/** The editor's view of a post (no live body copy). */
function admin_post_client(array $p): array
{
    $status = post_status($p);
    return [
        'id' => $p['id'],
        'rev' => (int) $p['rev'],
        'slug' => $p['slug'],
        'fix' => $p['fix'],
        'status' => $status,
        'status_label' => POST_STATUS_LABEL[$status],
        'dirty' => post_dirty($p),
        'slug_locked' => ($p['first_published_at'] ?? null) !== null,
        'updated_at' => $p['updated_at'] ?? null,
        'page_url' => $status === 'published' ? site_base() . 'posts/' . $p['slug'] . '.html' : null,
        'draft' => $p['draft'],
    ];
}

function admin_post_edit(): void
{
    $id = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
    $p = post_get($id);
    if ($p === null) {
        http_response_code(404);
        view('error', ['title' => '找不到这篇日志', 'message' => '这篇日志不存在，可能已经删除了。', 'back' => url('posts')]);
        return;
    }
    view('post_edit', [
        'title' => ($p['draft']['title'] ?? '') !== '' ? $p['draft']['title'] : '新日志',
        'post' => $p,
        'client' => admin_post_client($p),
        'upload_mb' => intdiv(upload_limit_bytes(), 1048576),
    ]);
}

/** Whitelist the editor's fields; cover sizes come from the uploads index, never from the browser. */
function admin_post_input(array $in): array
{
    $out = [];
    foreach (['title', 'excerpt', 'body', 'date', 'fix', 'slug'] as $k) {
        if (array_key_exists($k, $in)) {
            $out[$k] = is_string($in[$k]) ? $in[$k] : '';
        }
    }
    if (array_key_exists('tags', $in)) {
        $out['tags'] = is_string($in['tags']) ? $in['tags'] : (is_array($in['tags']) ? array_values(array_filter($in['tags'], 'is_string')) : '');
    }
    if (array_key_exists('minutes', $in)) {
        $m = is_string($in['minutes']) ? ascii_digits($in['minutes']) : $in['minutes'];
        $out['minutes'] = ($m === null || $m === '') ? null : (is_numeric($m) && (string) (int) $m === trim((string) $m) ? (int) $m : -1);
    }
    if (array_key_exists('cover', $in)) {
        $c = $in['cover'];
        if (is_array($c) && is_string($c['src'] ?? null) && $c['src'] !== '') {
            $info = uploads_index()['items'][$c['src']] ?? null;
            $out['cover'] = [
                'src' => $c['src'],
                'og' => (string) ($info['og'] ?? ''),
                'w' => (int) ($info['w'] ?? 0),
                'h' => (int) ($info['h'] ?? 0),
                'alt' => is_string($c['alt'] ?? null) ? $c['alt'] : '',
            ];
        } else {
            $out['cover'] = null;
        }
    }
    return $out;
}

function admin_post_action(): void
{
    $r = (string) $_GET['r'];
    $in = admin_json_body();
    $id = is_string($in['id'] ?? null) ? $in['id'] : '';
    $rev = (int) ($in['rev'] ?? -1);
    if (post_get($id) === null) {
        json_out(404, ['ok' => false, 'error' => 'invalid', 'message' => '找不到这篇日志，可能已经删除了。']);
    }
    $saved = false;
    if (is_array($in['post'] ?? null)) {
        try {
            $p = post_save_draft($id, admin_post_input($in['post']), $rev);
            $rev = (int) $p['rev'];
            $saved = true;
        } catch (Throwable $e) {
            admin_json_fail($e, '保存失败：', 'post');
        }
    }
    if ($r === 'post.save') {
        $p = post_get($id);
        json_out(200, ['ok' => true, 'rev' => (int) $p['rev'], 'post' => admin_post_client($p), 'message' => MSG_SAVED_POST]);
    }
    $current = post_get($id);
    $extra = ['saved' => $saved, 'rev' => (int) $current['rev'], 'post' => admin_post_client($current)];
    try {
        $res = match ($r) {
            'post.publish' => post_publish($id, $rev),
            'post.teaser' => post_set_teaser($id, $rev),
            'post.unpublish' => post_unpublish($id, $rev),
        };
    } catch (Throwable $e) {
        admin_json_fail($e, $r === 'post.unpublish' ? '撤下失败：' : '发布失败：', 'post', $extra);
    }
    $p = $res['post'];
    $msg = match ($r) {
        'post.publish' => MSG_PUBLISHED,
        'post.teaser' => '已设为预告。首页会显示标题和摘要，没有单独的页面。',
        'post.unpublish' => '已撤下。首页和日志页面上都不再显示，内容留在草稿里。',
    };
    json_out(200, ['ok' => true, 'rev' => (int) $p['rev'], 'post' => admin_post_client($p), 'message' => $msg]);
}

function admin_post_delete(): void
{
    $id = post_str('id', 40);
    $p = post_get($id);
    if ($p === null) {
        flash('error', '找不到这篇日志，可能已经删除了。');
        redirect('posts');
    }
    $title = ($p['draft']['title'] ?? '') !== '' ? $p['draft']['title'] : '没有标题的草稿';
    try {
        post_delete($id);
        flash('ok', '已删除《' . $title . '》。' . ($p['live'] !== null ? '网站已经更新。' : ''));
    } catch (ValidationError $e) {
        flash('error', $e->getMessage());
        redirect('post.edit', ['id' => $id]);
    }
    redirect('posts');
}

function admin_preview_post(): void
{
    $p = post_get(post_str('id', 40));
    if ($p === null) {
        admin_frame_note(404, '找不到这篇日志，可能已经删除了。');
        return;
    }
    $in = json_decode(post_str('post', 3000000), true);
    if (is_array($in)) {
        $input = admin_post_input($in);
        unset($input['slug']);
        $p['draft'] = post_normalize_content(array_merge($p['draft'], $input));
        if (isset($input['fix']) && preg_match('/^[A-Z]{5}$/', strtoupper(trim($input['fix'])))) {
            $p['fix'] = strtoupper(trim($input['fix']));
        }
        if (($p['draft']['minutes'] ?? null) !== null && ($p['draft']['minutes'] < 1 || $p['draft']['minutes'] > 120)) {
            $p['draft']['minutes'] = null;
        }
        if (!date_ok($p['draft']['date'] ?? null)) {
            $p['draft']['date'] = null;
        }
        $c = $p['draft']['cover'];
        if ($c !== null && ($c['w'] < 1 || !preg_match(UPLOAD_PATH_RE, $c['src']))) {
            $p['draft']['cover'] = null;
        }
    }
    if (($p['draft']['title'] ?? '') === '') {
        $p['draft']['title'] = '（还没有标题）';
    }
    send_headers('preview-post');
    echo preview_post($p);
}

function admin_upload(): void
{
    $file = $_FILES['image'] ?? null;
    if (!is_array($file) || is_array($file['name'] ?? null)) {
        $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($len > 0 && ($len >= upload_limit_bytes() || empty($_POST))) {
            json_out(413, ['ok' => false, 'error' => 'too_large', 'message' => '图片太大了，最大 ' . intdiv(upload_limit_bytes(), 1048576) . ' MB。']);
        }
        json_out(400, ['ok' => false, 'error' => 'invalid', 'message' => '没有收到图片，再选一次试试。']);
    }
    try {
        $purpose = post_str('purpose', 10);
        $r = image_accept_upload($file, in_array($purpose, ['body', 'album'], true) ? $purpose : 'cover');
    } catch (ValidationError $e) {
        $msg = (string) reset($e->fields);
        json_out(str_contains($msg, '太大') ? 413 : 400, ['ok' => false, 'error' => str_contains($msg, '太大') ? 'too_large' : 'invalid', 'message' => $msg]);
    } catch (Throwable $e) {
        error_log('admin upload: ' . get_class($e) . ' ' . $e->getMessage());
        json_out(500, ['ok' => false, 'error' => 'server', 'message' => '图片没有保存成功，换一张或稍后再试。']);
    }
    json_out(200, ['ok' => true, 'src' => $r['src'], 'og' => $r['og'], 'w' => $r['w'], 'h' => $r['h'], 'url' => site_base() . $r['src']]);
}

// ---------- 订阅者 ----------

function admin_subs(): void
{
    $q = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 100) : '';
    $page = is_string($_GET['p'] ?? null) ? max(1, (int) $_GET['p']) : 1;
    $all = sub_all_emails();
    view('subs', [
        'title' => '订阅者',
        'q' => $q,
        'list' => sub_list($q, $page, 50),
        'total' => count($all),
        'week' => sub_count_since(time() - 7 * 86400),
        'all_emails' => implode(', ', $all),
        'settings' => settings_get(),
    ]);
}

function admin_subs_delete(): void
{
    $emails = $_POST['emails'] ?? [];
    $emails = is_array($emails) ? array_values(array_filter($emails, 'is_string')) : [];
    $back = ['q' => post_str('q', 100)];
    if (!$emails) {
        flash('error', '还没有选要删除的邮箱。');
        redirect('subs', array_filter($back));
    }
    $n = sub_delete($emails);
    flash('ok', $n > 0 ? '已删除 ' . $n . ' 个邮箱。' : '没有删除：这些邮箱已经不在名单里了。');
    redirect('subs', array_filter($back));
}

function admin_subs_toggle(): void
{
    $on = post_str('enabled', 2) === '1';
    try {
        settings_save(['subscribe' => ['enabled' => $on]], (int) post_str('rev', 20));
        flash('ok', $on ? '订阅表单已打开，网站已经更新。' : '订阅表单已关闭，网站已经更新。访客现在不能留邮箱。');
    } catch (ConflictException $e) {
        flash('error', '没有保存：设置在别处改过了，刷新页面再试。');
    } catch (Throwable $e) {
        error_log('admin subs.toggle: ' . get_class($e));
        flash('error', '没有保存：服务器出错了。');
    }
    redirect('subs');
}

function admin_subs_export(): void
{
    $csv = sub_csv();
    audit('subs_exported', (string) count(sub_all_emails()));
    send_headers('csv');
    header('Content-Disposition: attachment; filename="subscribers-' . str_replace('-', '', local_day()) . '.csv"');
    header('Content-Length: ' . strlen($csv));
    echo $csv;
}

// ---------- 访问统计 ----------

function admin_stats(): void
{
    $days = stats_range(30);
    $titles = [];
    foreach (posts_all() as $p) {
        if (($p['live']['mode'] ?? null) === 'full') {
            $titles['/posts/' . $p['slug'] . '.html'] = (string) $p['live']['title'];
        }
    }
    foreach (shop_products() as $p) {
        $titles['/shop/' . $p['slug'] . '.html'] = '商品：' . $p['name'];
    }
    $settings = settings_get();
    view('stats', [
        'title' => '访问统计',
        'settings' => $settings,
        'on' => stats_enabled($settings),
        'days' => $days,
        'pages' => stats_top($days, 'paths', 10),
        'refs' => stats_top($days, 'refs', 10),
        'titles' => $titles,
    ]);
}

function admin_stats_toggle(): void
{
    $on = post_str('enabled', 2) === '1';
    try {
        settings_save(['stats' => ['enabled' => $on]], (int) post_str('rev', 20));
        flash('ok', $on ? '访问统计已打开，网站已经更新。' : '访问统计已关闭，网站已经更新。以前的数字还在。');
    } catch (ConflictException $e) {
        flash('error', '没有保存：设置在别处改过了，刷新页面再试。');
    } catch (Throwable $e) {
        error_log('admin stats.toggle: ' . get_class($e));
        flash('error', '没有保存：服务器出错了。');
    }
    redirect('stats');
}

// ---------- 分享海报 (drawn in the browser by admin/assets/poster.js; nothing stored) ----------

function admin_poster(): void
{
    view('poster', ['title' => '分享海报', 'data' => poster_targets()]);
}

// ---------- 小游戏排行榜 (盲降挑战, api/game.php) ----------

function admin_game(): void
{
    $settings = settings_get();
    $items = game_entries();
    view('game', [
        'title' => '小游戏排行榜',
        'settings' => $settings,
        'on' => game_board_on($settings),
        'total' => count($items),
        'items' => array_slice(array_reverse($items), 0, 100),   // newest first
    ]);
}

function admin_game_toggle(): void
{
    $on = post_str('enabled', 2) === '1';
    try {
        settings_save(['game' => ['board' => $on]], (int) post_str('rev', 20));
        flash('ok', $on ? '排行榜已打开，玩家可以交成绩了。' : '排行榜已关闭。游戏还能玩，成绩不再记录，榜单不再显示。');
    } catch (ConflictException $e) {
        flash('error', '没有保存：设置在别处改过了，刷新页面再试。');
    } catch (Throwable $e) {
        error_log('admin game.toggle: ' . get_class($e));
        flash('error', '没有保存：服务器出错了。');
    }
    redirect('game');
}

function admin_game_delete(): void
{
    $id = post_str('id', 40);
    if (!preg_match(GAME_ID_RE, $id)) {
        flash('error', '没有删除：找不到这条成绩。');
        redirect('game');
    }
    flash('ok', game_delete($id) ? '已删除这条成绩。' : '没有删除：这条成绩已经不在了。');
    redirect('game');
}

function admin_game_clear(): void
{
    $n = game_clear();
    audit('game_cleared', (string) $n);
    flash('ok', $n > 0 ? '排行榜已清空，删除了 ' . $n . ' 条成绩。' : '排行榜本来就是空的。');
    redirect('game');
}

// ---------- AI 助手 ----------

function admin_ai(): void
{
    $s = settings_get();
    $usage = usage_range(30);
    view('ai', [
        'title' => 'AI 助手',
        'settings' => $s,
        'chat_live' => chat_live(),
        'key_source' => chat_key_source(),
        'key_hint' => chat_key_hint(),
        'key_set_at' => chat_key_set_at(),
        'db_hint' => doubao_key_hint(),
        'db_set_at' => doubao_key_set_at(),
        'active_key' => chat_key($s['chat']) !== null,
        'usage' => $usage,
        'last_error' => usage_last_error($usage),
    ]);
}

function admin_ai_save(): void
{
    $rev = (int) post_str('rev', 20);
    $part = post_str('part', 10);
    $patch = match ($part) {
        'switch' => ['chat' => ['enabled' => post_str('enabled', 2) === '1']],
        'provider' => ['chat' => [
            'provider' => post_str('provider', 20) === 'doubao' ? 'doubao' : 'anthropic',
            'model' => trim(post_str('model', 400)),
        ]],
        default => ['chat' => [
            'daily_limit' => ctype_digit(ascii_digits(post_str('daily_limit', 40))) ? (int) ascii_digits(post_str('daily_limit', 40)) : -1,
            'ip_limit' => ctype_digit(ascii_digits(post_str('ip_limit', 40))) ? (int) ascii_digits(post_str('ip_limit', 40)) : -1,
            'extra_note' => trim(str_replace(["\r\n", "\r"], "\n", post_str('extra_note', 20000))),
        ]],
    };
    try {
        settings_save($patch, $rev);
        if ($part === 'switch') {
            $on = $patch['chat']['enabled'];
            flash('ok', $on ? (chat_key() === null ? '助手已打开，但还没有密钥，访客那边仍显示接入中。先在下面填好密钥。' : '助手已打开，网站已经更新。') : '助手已关闭，网站已经更新。');
        } elseif ($part === 'provider') {
            $db = $patch['chat']['provider'] === 'doubao';
            flash('ok', '已改用' . ($db ? '豆包' : 'Claude') . '。' . (chat_key() === null ? ($db ? '还没有豆包的 API Key，先在下面填好。' : '还没有 Anthropic 密钥，先在下面填好。') : '可以点「测试连接」试一下。'));
        } else {
            flash('ok', '已保存限额和补充说明。');
        }
    } catch (ValidationError $e) {
        flash('error', '没有保存：' . (string) reset($e->fields));
    } catch (ConflictException $e) {
        flash('error', '没有保存：设置在别处改过了，刷新页面再试。');
    }
    redirect('ai');
}

function admin_ai_key(): void
{
    $key = post_str('key', 1000);
    try {
        chat_key_set($key);
        audit('key_set');
        flash('ok', '密钥已保存，结尾是 ' . (string) chat_key_hint() . '。');
    } catch (ValidationError $e) {
        flash('error', '没有保存：' . (string) reset($e->fields));
    } catch (Throwable $e) {
        error_log('admin ai.key: ' . get_class($e));
        flash('error', '没有保存：服务器出错了。');
    }
    redirect('ai');
}

function admin_ai_key_remove(): void
{
    try {
        chat_key_remove();
        audit('key_removed');
        flash('ok', chat_key_source() === null ? '密钥已删除。助手现在用不了，访客那边显示接入中。' : '后台保存的密钥已删除，现在用的是旧配置里的密钥。');
    } catch (Throwable $e) {
        error_log('admin ai.key_remove: ' . get_class($e));
        flash('error', '没有删除：服务器出错了。');
    }
    redirect('ai');
}

function admin_ai_dbkey(): void
{
    $key = post_str('dbkey', 1000);
    try {
        doubao_key_set($key);
        audit('doubao_key_set');
        flash('ok', '豆包 API Key 已保存，结尾是 ' . (string) doubao_key_hint() . '。');
    } catch (ValidationError $e) {
        flash('error', '没有保存：' . (string) reset($e->fields));
    } catch (Throwable $e) {
        error_log('admin ai.dbkey: ' . get_class($e));
        flash('error', '没有保存：服务器出错了。');
    }
    redirect('ai');
}

function admin_ai_dbkey_remove(): void
{
    try {
        doubao_key_remove();
        audit('doubao_key_removed');
        flash('ok', '豆包 API Key 已删除。' . (settings_get()['chat']['provider'] === 'doubao' ? '助手现在用不了，访客那边显示接入中。' : ''));
    } catch (Throwable $e) {
        error_log('admin ai.dbkey_remove: ' . get_class($e));
        flash('error', '没有删除：服务器出错了。');
    }
    redirect('ai');
}

/** 测试连接: the provider chosen in the settings, with a tiny prompt (豆包) or a model lookup (Anthropic). */
function admin_ai_test(): void
{
    $chat = settings_get()['chat'];
    if ($chat['provider'] === 'doubao') {
        $key = doubao_key();
        if ($chat['model'] === '') {
            json_out(200, ['ok' => false, 'message' => '还没有填「模型 / 接入点 ID」，先填好再测试。']);
        }
        if ($key === null) {
            json_out(200, ['ok' => false, 'message' => '还没有豆包的 API Key，先保存再测试。']);
        }
        if (PHP_SAPI === 'cli-server' && getenv('BLOG_CHAT_FAKE') === '1' && !doubao_mock_active()) {
            json_out(200, ['ok' => true, 'message' => '连接正常，豆包可以用。']);
        }
        $r = doubao_chat($key, $chat['model'], [['role' => 'user', 'content' => '你好，请只回复「收到」两个字。']], 16, 15);
        if ($r['ok']) {
            json_out(200, ['ok' => true, 'message' => '连接正常，豆包可以用。']);
        }
        error_log('admin ai.test doubao ' . $r['kind'] . ' ' . $r['status']);
        json_out(200, ['ok' => false, 'message' => $r['reason']]);
    }
    $key = anthropic_key();
    if ($key === null) {
        json_out(200, ['ok' => false, 'message' => '还没有密钥，先保存密钥再测试。']);
    }
    if (PHP_SAPI === 'cli-server' && getenv('BLOG_CHAT_FAKE') === '1') {
        json_out(200, ['ok' => true, 'message' => '连接正常，密钥可以用。']);
    }
    $autoload = PUBLIC_DIR . '/api/vendor/autoload.php';
    if (!is_file($autoload)) {
        json_out(200, ['ok' => false, 'message' => '网站上缺少 AI 助手的程序文件（api/vendor），测试不了。']);
    }
    require_once $autoload;
    try {
        $opts = ['timeout' => 15.0, 'maxRetries' => 0];
        if (class_exists('GuzzleHttp\\Client')) {
            $opts['transporter'] = new GuzzleHttp\Client(['timeout' => 15, 'connect_timeout' => 8]);
        }
        $client = new Anthropic\Client(apiKey: $key, requestOptions: $opts);
        $client->models->retrieve(modelID: 'claude-opus-5-5');
        json_out(200, ['ok' => true, 'message' => '连接正常，密钥可以用。']);
    } catch (Anthropic\Core\Exceptions\AuthenticationException | Anthropic\Core\Exceptions\PermissionDeniedException $e) {
        json_out(200, ['ok' => false, 'message' => '密钥不对或已经失效。']);
    } catch (Anthropic\Core\Exceptions\APIStatusException $e) {
        error_log('admin ai.test: ' . get_class($e) . ' ' . $e->status);
        json_out(200, ['ok' => false, 'message' => '连上了 Anthropic，但它返回了错误（代码 ' . (int) $e->status . '），稍后再试。']);
    } catch (Throwable $e) {
        error_log('admin ai.test: ' . get_class($e));
        json_out(200, ['ok' => false, 'message' => '连不上 Anthropic，稍后再试。']);
    }
}

// ---------- 版本记录 ----------

function admin_versions(): void
{
    view('versions', ['title' => '版本记录', 'versions' => versions_list()]);
}

function admin_versions_view(): void
{
    $id = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
    $f = is_string($_GET['f'] ?? null) ? $_GET['f'] : 'index.html';
    $m = version_get($id);
    $bytes = $m === null ? null : version_file($id, $f);
    if ($bytes === null) {
        http_response_code(404);
        view('error', ['title' => '找不到这个版本', 'message' => '这个版本不存在，或者已经被更新的版本挤掉了（只保留最近 10 个）。', 'back' => url('versions')]);
        return;
    }
    $label = '这是 ' . cn_time($m['created_at']) . ' 的版本，只是看一看，网站上现在不一定是这样。';
    if ($f === 'index.html') {
        $banner = str_replace('预览：这是还没发布的草稿，访客看不到。', enc_text($label), PREVIEW_BANNER_HOME);
        $html = inject_preview($bytes, site_base(), $banner);
    } else {
        $html = inject_preview($bytes, site_base() . 'posts/', '<div class="preview-banner">' . enc_text($label) . '</div>');
    }
    send_headers($f === 'index.html' ? 'preview-home' : 'preview-post');
    echo $html;
}

function admin_versions_rollback(): void
{
    $id = post_str('id', 40);
    $m = version_get($id);
    if ($m === null) {
        flash('error', '找不到这个版本，可能已经被更新的版本挤掉了。');
        redirect('versions');
    }
    try {
        $res = version_rollback($id);
        if ($res['ok']) {
            audit('rollback', $id);
            flash('ok', '已回滚到 ' . cn_time($m['created_at']) . ' 的版本，网站已经更新。草稿没有变。');
            foreach ($res['skipped'] ?? [] as $s) {
                flash('error', $s);
            }
        } else {
            flash('error', '回滚失败：' . strip_full_stop((string) (reset($res['errors']) ?: '页面没有生成成功')) . '。网站没有变化。');
        }
    } catch (Throwable $e) {
        error_log('admin rollback: ' . get_class($e) . ' ' . $e->getMessage());
        flash('error', '回滚失败：服务器出错了。');
    }
    redirect('versions');
}
