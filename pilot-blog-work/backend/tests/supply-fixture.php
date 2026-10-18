<?php
// 货源 test site for supply.e2e.js: an owner password, both 收款码, and the 货源 connected to the mock supplier
// (MOCK_BASE). Temp dirs only. Usage: BLOG_PUBLIC_DIR=… BLOG_DATA_DIR=… MOCK_BASE=http://127.0.0.1:port php supply-fixture.php <pw>
declare(strict_types=1);
define('BLOG_APP', true);
require __DIR__ . '/../../../pilot-blog/app/lib/bootstrap.php';
require __DIR__ . '/supply-mock-data.php';
app_init('cli');

$pw = $argv[1] ?? 'test-password-2026';
json_write_atomic(data_path('admin.json'), ['version' => 1, 'password_hash' => pw_hash($pw), 'created_at' => now_iso(), 'password_changed_at' => now_iso(), 'session_epoch' => 1]);
@unlink(data_path('setup-code.txt'));

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
            if (mt_rand(0, 1) === 1 || ($x < 7 && $y < 7)) {
                imagefilledrectangle($im, ($x + 4) * $s, ($y + 4) * $s, ($x + 5) * $s - 1, ($y + 5) * $s - 1, $k);
            }
        }
    }
    $tmp = sys_get_temp_dir() . '/supqr-' . bin2hex(random_bytes(4)) . '.png';
    imagepng($im, $tmp);
    $rel = shop_qr_store($tmp, $kind);
    unlink($tmp);
    return $rel;
}

$s = settings_get();
settings_save(['shop' => ['qr' => ['wechat' => fx_qr('wechat'), 'alipay' => fx_qr('alipay')]], 'supply' => ['enabled' => true, 'base_url' => (string) getenv('MOCK_BASE'), 'app_id' => MOCK_APP_ID]], $s['rev']);
supply_key_set(MOCK_APP_KEY);
$r = publish_site('货源测试');
if (!$r['ok']) {
    fwrite(STDERR, json_encode($r['errors'], JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}
echo json_encode(['ok' => true]) . "\n";
