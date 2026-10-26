<?php
// Shop test site for shop.e2e.js: an owner password, three products (physical, digital with a file, sold out),
// both 收款码 images, a shipping fee, then a publish. Temp dirs only (BLOG_PUBLIC_DIR / BLOG_DATA_DIR).
// Usage: BLOG_PUBLIC_DIR=… BLOG_DATA_DIR=… php shop-fixture.php <owner password> [more|usdt]
// Prints JSON: {physical, digital, soldout, file_sha256}
declare(strict_types=1);
define('BLOG_APP', true);
require __DIR__ . '/../../../pilot-blog/app/lib/bootstrap.php';
app_init('cli');

$pw = $argv[1] ?? 'test-password-2026';
json_write_atomic(data_path('admin.json'), ['version' => 1, 'password_hash' => pw_hash($pw), 'created_at' => now_iso(), 'password_changed_at' => now_iso(), 'session_epoch' => 1]);
@unlink(data_path('setup-code.txt'));

function fx_image(int $w, int $h, array $rgb, string $label): array
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, ...$rgb));
    $line = imagecolorallocate($im, 236, 233, 241);
    for ($i = 0; $i < 6; $i++) {
        imageline($im, 0, (int) ($h * (0.2 + $i * 0.12)), $w, (int) ($h * (0.1 + $i * 0.13)), $line);
    }
    imagefilledellipse($im, (int) ($w * 0.7), (int) ($h * 0.3), (int) ($w * 0.18), (int) ($w * 0.18), imagecolorallocate($im, 234, 76, 34));
    imagestring($im, 5, 24, $h - 40, $label, $line);
    $tmp = sys_get_temp_dir() . '/shopfx-' . bin2hex(random_bytes(4)) . '.png';
    imagepng($im, $tmp);
    $r = image_store_file($tmp);
    unlink($tmp);
    return $r;
}

/** A QR-like black and white grid (not a real code; enough to see on the page). */
function fx_qr(string $kind): string
{
    $n = 29;
    $s = 10;
    $im = imagecreatetruecolor(($n + 8) * $s, ($n + 8) * $s);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    $k = imagecolorallocate($im, 0, 0, 0);
    mt_srand($kind === 'wechat' ? 7 : 11);
    for ($y = 0; $y < $n; $y++) {
        for ($x = 0; $x < $n; $x++) {
            $finder = ($x < 7 && $y < 7) || ($x >= $n - 7 && $y < 7) || ($x < 7 && $y >= $n - 7);
            $on = $finder ? (min($x % ($n - 7), $y % ($n - 7)) % 6 === 0 || (($x % ($n - 7)) >= 2 && ($x % ($n - 7)) <= 4 && ($y % ($n - 7)) >= 2 && ($y % ($n - 7)) <= 4) || max($x % ($n - 7), $y % ($n - 7)) === 6) : mt_rand(0, 1) === 1;
            if ($on) {
                imagefilledrectangle($im, ($x + 4) * $s, ($y + 4) * $s, ($x + 5) * $s - 1, ($y + 5) * $s - 1, $k);
            }
        }
    }
    $tmp = sys_get_temp_dir() . '/shopqr-' . bin2hex(random_bytes(4)) . '.png';
    imagepng($im, $tmp);
    $rel = shop_qr_store($tmp, $kind);
    unlink($tmp);
    return $rel;
}

$a = fx_image(1200, 1200, [19, 45, 97], 'PC-12 CHART POSTER');
$b = fx_image(1200, 900, [33, 52, 110], 'POSTER DETAIL');
$c = fx_image(1000, 1400, [12, 27, 64], 'LOW CLOUD GUIDE');
$d = fx_image(1200, 1200, [61, 68, 107], 'PATCH');

$phys = shop_product_new();
shop_product_photo($phys['id'], 'add', 0, $a);
shop_product_photo($phys['id'], 'add', 0, $b);
$phys = shop_product_save($phys['id'], [
    'name' => 'PC-12 夜航航路图海报', 'slug' => 'pc12-chart-poster', 'type' => 'physical', 'price' => '99', 'stock' => '12', 'stock_seen' => '0', 'on_sale' => '1',
    'summary' => '按博客首页的航路图做的 A2 海报，哑光纸。',
    'body' => "## 尺寸和纸\n- A2，420 × 594 毫米\n- **哑光艺术纸**，不反光\n卷起来装在纸筒里寄出，顺丰或中通。\n> 每一张都是我自己核对过再寄的。",
    'dl_text' => '', 'dl_link' => '', 'photo_alt' => ['夜航航路图海报的正面', '海报的局部'],
], shop_product_get($phys['id'])['rev']);

$dig = shop_product_new();
shop_product_photo($dig['id'], 'add', 0, $c);
$tmp = sys_get_temp_dir() . '/shopfile-' . bin2hex(random_bytes(4)) . '.pdf';
file_put_contents($tmp, "%PDF-1.4\n% test file for the shop e2e\n" . str_repeat("低云进近 DA 200\n", 200));
$sha = hash_file('sha256', $tmp);
shop_product_file_set($dig['id'], $tmp, '低云进近教程.pdf', (int) filesize($tmp));
@unlink($tmp);
$dig = shop_product_save($dig['id'], [
    'name' => '低云进近教程（PDF）', 'slug' => 'low-cloud-guide', 'type' => 'digital', 'price' => '29.90', 'stock' => '', 'unlimited' => '1', 'stock_seen' => '', 'on_sale' => '1',
    'summary' => '决断高度前两秒做什么，32 页。',
    'body' => "一份讲低云进近的笔记，配图。\n- 简令怎么说\n- 决断高度前的两秒",
    'dl_text' => "网盘提取码：ab12\n文件在下面也能直接下载。", 'dl_link' => 'https://pan.example.com/s/low-cloud',
], shop_product_get($dig['id'])['rev']);

$out = shop_product_new();
shop_product_photo($out['id'], 'add', 0, $d);
$out = shop_product_save($out['id'], [
    'name' => '机组臂章', 'slug' => 'crew-patch', 'type' => 'physical', 'price' => '35', 'stock' => '0', 'stock_seen' => '0', 'on_sale' => '1',
    'summary' => '刺绣臂章，背面魔术贴。', 'body' => '', 'dl_text' => '', 'dl_link' => '',
], shop_product_get($out['id'])['rev']);

// `more`: three extra products (no category yet) for the category tests in shop.e2e.js
$extra = [];
if (($argv[2] ?? '') === 'more') {
    foreach ([['钥匙扣', 'tag-keyring', 'physical', '25', [92, 60, 40], 'KEYRING'], ['飞行笔记模板（PDF）', 'logbook-pdf', 'digital', '12', [20, 70, 60], 'LOGBOOK'], ['反光贴纸', 'reflect-sticker', 'physical', '8', [70, 30, 60], 'STICKER']] as [$n, $slug, $type, $price, $rgb, $label]) {
        $x = shop_product_new();
        shop_product_photo($x['id'], 'add', 0, fx_image(1000, 1000, $rgb, $label));
        $x = shop_product_save($x['id'], ['name' => $n, 'slug' => $slug, 'type' => $type, 'price' => $price, 'stock' => $type === 'digital' ? '' : '30', 'unlimited' => $type === 'digital' ? '1' : '',
            'stock_seen' => '0', 'on_sale' => '1', 'summary' => '', 'body' => '', 'dl_text' => $type === 'digital' ? '下载码 77' : '', 'dl_link' => ''], shop_product_get($x['id'])['rev']);
        $extra[] = $x['slug'];
    }
}

$s = settings_get();
settings_save(['shop' => ['shipping' => 1000, 'free_over' => 29900, 'qr' => ['wechat' => fx_qr('wechat'), 'alipay' => fx_qr('alipay')]]], $s['rev']);
$usdt = null;
if (($argv[2] ?? '') === 'usdt') {   // the USDT stage: both chains on, owner addresses, rate 7.20
    $usdt = ['trc20' => usdt_tron_encode("\x41" . random_bytes(20)), 'bep20' => usdt_eip55('0x' . bin2hex(random_bytes(20)))];
    settings_save(['usdt' => ['trc20' => ['on' => true, 'address' => $usdt['trc20']], 'bep20' => ['on' => true, 'address' => $usdt['bep20']], 'rate' => 72000, 'window' => 30]], settings_get()['rev']);
}
$r = publish_site('商城测试');
if (!$r['ok']) {
    fwrite(STDERR, json_encode($r['errors'], JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}
echo json_encode(['physical' => $phys['slug'], 'digital' => $dig['slug'], 'soldout' => $out['slug'], 'file_sha256' => $sha, 'extra' => $extra, 'usdt' => $usdt], JSON_UNESCAPED_UNICODE) . "\n";
