<?php
// Extra history for biz-ref.e2e.js: paid, unpaid and cancelled orders on the last 30 Beijing days (with and without a
// 成本价) and visitor counts, so the 经营总览 trend has something to draw. Temp dirs only.
// Usage: BLOG_PUBLIC_DIR=… BLOG_DATA_DIR=… php biz-fixture.php
declare(strict_types=1);
define('BLOG_APP', true);
require __DIR__ . '/../../../pilot-blog/app/lib/bootstrap.php';
app_init('cli');

$products = shop_products();
mt_srand(20261020);
shop_mutate(function (array &$ps, array &$orders) use ($products) {
    $now = time();
    for ($day = 1; $day <= 29; $day++) {
        $n = mt_rand(0, 4);
        for ($k = 0; $k < $n; $k++) {
            $p = $products[mt_rand(0, 1)];
            $qty = mt_rand(1, 3);
            $at = $now - $day * 86400 - mt_rand(0, 20000);
            $status = ['completed', 'completed', 'completed', 'shipped', 'cancelled', 'pending'][mt_rand(0, 5)];
            $sub = $p['price'] * $qty;
            $ship = $p['type'] === 'physical' ? 1000 : 0;
            $id = shop_new_order_id($orders, $at);
            $orders[] = [
                'id' => $id, 'created_at' => gmdate('Y-m-d\TH:i:s\Z', $at), 'status' => $status, 'product_id' => $p['id'], 'slug' => $p['slug'], 'name' => $p['name'],
                'type' => $p['type'], 'price' => $p['price'], 'cost_unit' => $p['type'] === 'physical' ? 4000 : null, 'qty' => $qty, 'subtotal' => $sub, 'shipping' => $ship,
                'offset' => 1 + $k, 'amount' => $sub + $ship + 1 + $k, 'buyer' => ['name' => '', 'phone' => '', 'address' => '', 'email' => ''], 'note' => '', 'payer' => '',
                'verify' => str_repeat('0', 64), 'held' => false, 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $at + 86400), 'history' => [], 'courier' => '', 'tracking' => '',
                'cancel_reason' => $status === 'cancelled' ? '测试' : '', 'cancel_auto' => false, 'refunds' => [], 'closed_at' => in_array($status, ['completed', 'cancelled'], true) ? gmdate('Y-m-d\TH:i:s\Z', $at + 3600) : null,
                'redacted_at' => null,
            ];
        }
    }
    usort($orders, fn($a, $b) => strcmp($a['created_at'], $b['created_at']));
    return null;
});
@mkdir(stats_dir(), 0700, true);
for ($day = 0; $day < 30; $day++) {
    $d = local_day(time() - $day * 86400);
    $f = stats_dir() . '/' . $d . '.json';
    $cur = stats_normalize(json_read($f, []), $d);
    $cur['visitors'] += mt_rand(5, 60);
    $cur['views'] += $cur['visitors'] * 2;
    json_write_atomic($f, $cur);
}
echo "ok\n";
