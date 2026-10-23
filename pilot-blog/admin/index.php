<?php
// 云底之下 后台入口。所有后台页面都从这里进：/admin/?r=页面名。程序在 ../app/ 里（网址访问不到）。
declare(strict_types=1);

define('BLOG_APP', true);
require __DIR__ . '/../app/lib/bootstrap.php';
require __DIR__ . '/../app/lib/admin.php';
require __DIR__ . '/../app/lib/admin_shop.php';
require __DIR__ . '/../app/lib/admin_supply.php';
require __DIR__ . '/../app/lib/admin_biz.php';
require __DIR__ . '/../app/lib/admin_notify.php';

try {
    app_init('admin');
} catch (DataDirError $e) {
    // The absolute path (which reveals the hosting account layout) goes to the server log only;
    // the visitor sees a relative hint.
    error_log('admin: data dir not writable: ' . $e->getMessage());
    send_headers('admin');
    http_response_code(503);
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>后台</title></head><body><p>'
        . h('和 public_html 同级的 blog-data 文件夹没法写入。请在 Hostinger 文件管理器里检查这个文件夹的权限。') . '</p></body></html>';
    exit;
}

send_headers('admin');
admin_session_start_if_needed();
try {
    admin_dispatch();
} catch (Throwable $e) {
    $route = is_string($_GET['r'] ?? null) ? $_GET['r'] : '';
    error_log('admin ' . $route . ': ' . get_class($e) . ' ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    if (admin_wants_json() || (ADMIN_ROUTES[$route][3] ?? false)) {
        json_out(500, ['ok' => false, 'error' => 'server', 'message' => MSG_SERVER]);
    }
    if (!headers_sent()) {
        send_headers('admin');
        http_response_code(500);
    }
    view('error', ['title' => '出错了', 'message' => '服务器出错了，刚才的操作可能没有完成。返回上一页，刷新以后再试一次。', 'bare' => true]);
}
