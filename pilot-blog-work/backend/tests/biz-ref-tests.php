<?php
// run.php groups `biz` (经营总览 math) and `ref` (推广链接). Loaded by run.php in the child process; uses its helpers
// (ok, throws, http, shop_make, shop_buyer, shop_http, shop_post, clear_rl, pub). Temp dirs only.
declare(strict_types=1);

/** A synthetic order for the math tests (fields the report reads). $at: ISO UTC creation time. */
function biz_fake_order(string $at, string $status, int $amount, array $extra = []): array
{
    static $n = 0;
    $n++;
    $id = str_replace('-', '', local_day((int) strtotime($at))) . '-' . substr(str_pad(strtoupper(base_convert((string) (100000 + $n), 10, 36)), 6, 'A', STR_PAD_LEFT), -6);
    $id = strtr($id, ['0' => 'A', '1' => 'B', 'I' => 'C', 'L' => 'D', 'O' => 'E']);
    return $extra + ['id' => $id, 'created_at' => $at, 'status' => $status, 'product_id' => 'g0000000001', 'slug' => 'x', 'name' => '甲', 'type' => 'physical',
        'price' => $amount, 'cost_unit' => null, 'qty' => 1, 'subtotal' => $amount, 'shipping' => 0, 'offset' => 0, 'amount' => $amount,
        'buyer' => ['name' => '', 'phone' => '', 'address' => '', 'email' => ''], 'refunds' => [], 'history' => []];
}

function biz_range_of(string $from, string $to): array
{
    return biz_range(['from' => $from, 'to' => $to]);
}

function group_biz(): void
{
    app_init('cli');
    publish_site('初始内容');

    // ---- 成本价 in the product editor ----
    $ph = shop_make('poster', 'physical', '99', 10, ['cost' => '30']);
    ok($ph['cost'] === 3000, '成本价 saved in fen (30 → 3000)');
    $ph = shop_product_save($ph['id'], ['cost' => '35.5'] + biz_edit_in($ph), $ph['rev']);
    ok($ph['cost'] === 3550, '成本价 35.5 → 3550');
    $ph2 = shop_product_save($ph['id'], biz_edit_in($ph), $ph['rev']);
    ok($ph2['cost'] === 3550, 'a save without the cost field keeps the 成本价');
    ok(throws(fn() => shop_product_save($ph['id'], ['cost' => '三十'] + biz_edit_in($ph2), $ph2['rev']), ShopError::class), 'a 成本价 that is not a number is refused');
    $ph = shop_product_save($ph['id'], ['cost' => ''] + biz_edit_in($ph2), $ph2['rev']);
    ok($ph['cost'] === null, 'an empty 成本价 means not known (null)');
    $ph = shop_product_save($ph['id'], ['cost' => '30'] + biz_edit_in($ph), $ph['rev']);
    $dg = shop_make('guide', 'digital', '29.9', null);   // no 成本价

    // ---- real orders: paid, unpaid, cancelled after payment, refunds ----
    $a = shop_create_order('poster', shop_buyer('physical', '13800138000', '', 2));
    ok($a['cost_unit'] === 3000 && $a['subtotal'] === 19800, 'the order keeps the 成本价 of order time');
    shop_owner_action($a['id'], 'confirm');
    shop_owner_action($a['id'], 'ship', ['courier' => '顺丰', 'tracking' => 'SF100200300']);
    shop_owner_action($a['id'], 'complete');
    $b = shop_create_order('poster', shop_buyer('physical'));                 // unpaid
    $c = shop_create_order('poster', shop_buyer('physical'));
    shop_owner_action($c['id'], 'confirm');
    shop_owner_action($c['id'], 'cancel', ['reason' => '缺货，已原路退款']);   // paid, then cancelled
    $d = shop_create_order('guide', shop_buyer('digital'));
    shop_owner_action($d['id'], 'confirm');                                   // digital: completed, no cost
    shop_product_save($ph['id'], ['cost' => '50'] + biz_edit_in(shop_product_get($ph['id'])), shop_product_get($ph['id'])['rev']);
    ok(shop_order_get($a['id'])['cost_unit'] === 3000, 'changing the 成本价 later does not change old orders');

    // refunds: amount field, note-only (amount read from the note), unknown, too much
    shop_owner_action($a['id'], 'refund', ['note' => '退差价', 'amount' => '10']);
    shop_owner_action($a['id'], 'refund', ['note' => '又退了 ¥5.50 运费']);
    shop_owner_action($d['id'], 'refund', ['note' => '退了一部分']);
    ok(shop_refunded(shop_order_get($a['id'])) === ['fen' => 1550, 'n' => 2, 'unknown' => 0], 'refunds: the amount field and an amount written in the note (¥5.50) both count');
    ok(shop_refunded(shop_order_get($d['id']))['unknown'] === 1, 'a refund without any amount is counted as unknown');
    ok(throws(fn() => shop_owner_action($a['id'], 'refund', ['note' => 'x', 'amount' => '9999']), ShopError::class), 'a refund larger than what is left of the order is refused');
    ok(throws(fn() => shop_owner_action($a['id'], 'refund', ['note' => 'x', 'amount' => 'abc']), ShopError::class), 'a refund amount that is not a number is refused');
    ok(shop_refund_amount_in('已退 ¥10 差价') === 1000 && shop_refund_amount_in('退了 99.03 元') === 9903 && shop_refund_amount_in('退回') === null && shop_refund_amount_in('退 ￥１２') === 1200, 'refund amounts are read from notes (¥, 元, full width)');

    $today = local_day();
    $rep = biz_report(biz_range([]));
    $A = shop_order_get($a['id']);
    $D = shop_order_get($d['id']);
    ok($rep['created'] === 4, 'created orders today: 4', (string) $rep['created']);
    ok($rep['paid_n'] === 2 && $rep['paid_fen'] === $A['amount'] + $D['amount'], '实付成交额: paid orders only (unpaid and cancelled left out), at what the buyer paid', json_encode([$rep['paid_n'], $rep['paid_fen']]));
    ok($rep['profit_fen'] === $A['amount'] - 6000 && $rep['profit_n'] === 1 && $rep['cost_unknown'] === 1, '毛利 = paid - cost for orders with a cost; the order without a 成本价 is counted as unknown', json_encode([$rep['profit_fen'], $rep['cost_unknown']]));
    ok($rep['refund_fen'] === 1550 && $rep['refund_n'] === 3 && $rep['refund_unknown'] === 1, '退款金额: recorded refunds of the range, with the count of refunds without an amount');
    ok(abs($rep['rate'] - 0.5) < 1e-9 && $rep['aov'] === intdiv($rep['paid_fen'], 2), '支付成功率 = paid / created (2 / 4); 客单价 = 成交额 / paid orders');
    ok($rep['selling'] === 2 && $rep['daily'][$today]['sales'] === $rep['paid_fen'] && $rep['daily'][$today]['n'] === 2, '在售商品数 and the daily trend agree with the totals');
    ok($rep['top'][0]['qty'] === 2 && str_contains($rep['top'][0]['name'], 'poster') && count($rep['top']) === 2, '热销排行 by quantity');

    // ---- supply cost, legacy orders, synthetic data ----
    $byId = [$ph['id'] => shop_product_get($ph['id'])];
    $sup = biz_fake_order('2026-10-20T03:00:00Z', 'completed', 1200, ['type' => 'supply', 'supply' => ['trade_no' => 'T1', 'amount' => 1000]]);
    $supFail = biz_fake_order('2026-10-20T03:00:00Z', 'failed', 1200, ['type' => 'supply', 'supply' => ['trade_no' => '', 'amount' => 0]]);
    $legacy = biz_fake_order('2026-10-20T03:00:00Z', 'completed', 9900, ['product_id' => $ph['id'], 'qty' => 2]);
    unset($legacy['cost_unit']);
    ok(biz_order_cost($sup, $byId) === 1000 && biz_order_cost($supFail, $byId) === null, 'a 货源 card costs what the supplier charged; a card not bought yet has no cost');
    ok(biz_order_cost($legacy, $byId) === 10000, 'an order made before 成本价 existed uses the product\'s current 成本价 × quantity');
    ok(biz_order_cost(biz_fake_order('2026-10-20T03:00:00Z', 'completed', 100, ['cost_unit' => 40, 'qty' => 3]), []) === 120, 'own goods: 成本价 of order time × quantity');
    $fx = [$sup, $supFail, $legacy, biz_fake_order('2026-10-20T03:00:00Z', 'cancelled', 5000), biz_fake_order('2026-10-20T03:00:00Z', 'claimed', 7000)];
    $r = biz_report(biz_range_of('2026-10-20', '2026-10-20'), $fx, [], null, ['rev' => 0, 'partners' => [], 'settlements' => []]);
    ok($r['paid_n'] === 3 && $r['paid_fen'] === 1200 + 1200 + 9900 && $r['created'] === 5, '进货失败 counts as paid (the money came in); claimed and cancelled do not');
    ok($r['profit_fen'] === 200 && $r['cost_unknown'] === 2, 'supply profit = paid - supplier charge; unknown costs are left out of 毛利 (legacy without a product)', json_encode([$r['profit_fen'], $r['cost_unknown']]));

    // Beijing day boundaries: 16:00 UTC is midnight in Beijing
    $late = biz_fake_order('2026-10-19T15:59:59Z', 'completed', 100);   // 10-19 23:59:59 Beijing
    $early = biz_fake_order('2026-10-19T16:00:00Z', 'completed', 200);  // 10-20 00:00:00 Beijing
    $r19 = biz_report(biz_range_of('2026-10-19', '2026-10-19'), [$late, $early], [], null, ['rev' => 0, 'partners' => [], 'settlements' => []]);
    $r20 = biz_report(biz_range_of('2026-10-20', '2026-10-20'), [$late, $early], [], null, ['rev' => 0, 'partners' => [], 'settlements' => []]);
    ok($r19['paid_fen'] === 100 && $r20['paid_fen'] === 200, 'Beijing day boundary: 15:59:59 UTC is still the 19th, 16:00 UTC is the 20th');
    $rf = biz_fake_order('2026-10-18T03:00:00Z', 'completed', 900, ['refunds' => [['t' => '2026-10-19T16:30:00Z', 'note' => 'x', 'amount' => 300]]]);
    $r = biz_report(biz_range_of('2026-10-20', '2026-10-20'), [$rf], [], null, ['rev' => 0, 'partners' => [], 'settlements' => []]);
    ok($r['refund_fen'] === 300 && $r['paid_n'] === 0, 'refunds count on the (Beijing) day they were recorded, not on the order day');
    $r = biz_report(biz_range_of('2026-10-18', '2026-10-20'), [$rf, $late, $early], [], null, ['rev' => 0, 'partners' => [], 'settlements' => []]);
    ok(array_keys($r['daily']) === ['2026-10-18', '2026-10-19', '2026-10-20'] && $r['daily']['2026-10-18']['sales'] === 900 && $r['daily']['2026-10-19']['sales'] === 100, 'daily trend: one entry per day of the range, each order on its Beijing day');
    ok(biz_report(biz_range_of('2026-10-01', '2026-10-17'), [$rf], [], null, ['rev' => 0, 'partners' => [], 'settlements' => []])['paid_n'] === 0, 'orders outside the range are left out');

    // ---- ranges ----
    $now = (int) strtotime('2026-10-20T08:00:00Z');   // 16:00 Beijing
    $r = biz_range([], $now);
    ok($r['from'] === '2026-10-20' && $r['to'] === '2026-10-20' && $r['preset'] === 'today' && count($r['days']) === 1, 'default range: today (Beijing)');
    ok(biz_range([], (int) strtotime('2026-10-19T16:30:00Z'))['from'] === '2026-10-20', 'today follows Beijing time (00:30 on the 20th is already the 20th)');
    ok(biz_range(['q' => '7'], $now)['from'] === '2026-10-14' && count(biz_range(['q' => '7'], $now)['days']) === 7, '7天: the last 7 days including today');
    ok(count(biz_range(['q' => '30'], $now)['days']) === 30 && biz_range(['q' => 'month'], $now)['from'] === '2026-10-01', '30天 and 本月');
    $r = biz_range(['from' => '2026-01-01', 'to' => '2026-10-20'], $now);
    ok(count($r['days']) === BIZ_RANGE_MAX && $r['to'] === '2026-10-20' && $r['error'] !== null, 'a range over 92 days is cut to the 92 days before the end date, with a note');
    $r = biz_range(['from' => '2026-10-20', 'to' => '2026-10-10'], $now);
    ok($r['from'] === '2026-10-10' && $r['to'] === '2026-10-20' && $r['error'] === null, 'swapped dates are put in order');
    ok(biz_range(['from' => '2026-02-29', 'to' => '2026-03-01'], $now)['error'] !== null && biz_range(['from' => '<script>', 'to' => '1'], $now)['from'] === '2026-10-20', 'impossible dates and junk fall back to today with a note');
    ok(count(biz_range(['from' => '2026-03-01', 'to' => '2026-03-31'], $now)['days']) === 31 && count(biz_range(['from' => '２０２６-１０-２０', 'to' => ''], $now)['days']) === 1, '31 days of March; full-width digits and a single date work');

    // ---- visitors, to-do, chart ----
    @mkdir(stats_dir(), 0700, true);
    json_write_atomic(stats_dir() . '/2026-10-19.json', ['date' => '2026-10-19', 'views' => 50, 'visitors' => 12]);
    json_write_atomic(stats_dir() . '/2026-10-20.json', ['date' => '2026-10-20', 'views' => 20, 'visitors' => 7]);
    ok(biz_report(biz_range_of('2026-10-19', '2026-10-20'), [], [], null, ['rev' => 0, 'partners' => [], 'settlements' => []])['visitors'] === 19, '新增访客 = the daily visitors of 访问统计 added up');
    $low = shop_make('lowone', 'physical', '10', 2);
    $r = biz_report(biz_range([]));
    ok(in_array($low['id'], array_column($r['todo']['low'], 'id'), true) && !in_array($dg['id'], array_column($r['todo']['low'], 'id'), true), '库存不足: on-sale products with 3 or fewer left (unlimited digital never)');
    $c2 = shop_create_order('poster', shop_buyer('physical'));
    shop_claim($c2['id'], '');
    $c3 = shop_create_order('poster', shop_buyer('physical'));
    shop_owner_action($c3['id'], 'confirm');
    $t = biz_report(biz_range([]))['todo'];
    ok($t['claimed'] === 1 && $t['to_ship'] === 1 && $t['failed'] === 0, '待处理: 待确认收款 and 待发货 counts');
    $one = biz_chart_svg(['2026-10-20' => ['sales' => 1000, 'profit' => -200, 'n' => 1]]);
    $many = [];
    for ($i = 0; $i < 92; $i++) {
        $many[biz_day_add('2026-07-21', $i)] = ['sales' => $i * 37, 'profit' => $i * 11 - 300, 'n' => 1];
    }
    $svg = biz_chart_svg($many);
    ok(substr_count($one, '<rect') === 2 && !str_contains($one . $svg, 'NAN') && !str_contains($one . $svg, 'INF') && substr_count($svg, '<g>') === 92, 'trend chart: 1 day and 92 days, finite coordinates, negative 毛利 below zero');
    ok(!preg_match('/<script|on[a-z]+=/i', $svg), 'trend chart carries no script');

    // ---- speed on a few thousand orders ----
    $big = [];
    for ($i = 0; $i < 5000; $i++) {
        $big[] = biz_fake_order(gmdate('Y-m-d\TH:i:s\Z', $now - $i * 1500), ['completed', 'pending', 'cancelled', 'shipped'][$i % 4], 1000 + $i, ['cost_unit' => $i % 3 ? 500 : null]);
    }
    $t0 = microtime(true);
    $r = biz_report(biz_range(['q' => '30'], $now), $big, [], null, ['rev' => 0, 'partners' => [], 'settlements' => []]);
    $ms = (microtime(true) - $t0) * 1000;
    ok($ms < 600 && $r['created'] > 1000, '5000 orders, 30 days: report in ' . round($ms) . ' ms');

    // ---- the admin page renders ----
    biz_admin_http(function () use ($today) {
        $h = biz_admin_login();
        $r = http('GET', '/admin/?r=biz', ['headers' => $h]);
        ok($r['status'] === 200 && str_contains($r['body'], '经营总览') && str_contains($r['body'], 'id="k-paid"') && str_contains($r['body'], '不是净利润'), 'admin 经营总览 opens with the cards and the 毛利 note');
        $r = http('GET', '/admin/?r=biz&from=2026-01-01&to=2026-12-31', ['headers' => $h]);
        ok($r['status'] === 200 && str_contains($r['body'], '最多看 92 天'), 'a too-long range shows the note');
        $r = http('GET', '/admin/?r=biz&from=%22%3E%3Cscript%3E&to=x', ['headers' => $h]);
        ok($r['status'] === 200 && !str_contains($r['body'], '"><script>'), 'typed dates are never echoed raw');
        $r = http('GET', '/admin/?r=shop&cat=low', ['headers' => $h]);
        ok($r['status'] === 200 && str_contains($r['body'], 'id="shop-low"') && str_contains($r['body'], '测试 lowone') && !str_contains($r['body'], '测试 guide'), '库存不足 opens the filtered 商品 list');
        ok(http('GET', '/admin/?r=biz')['status'] !== 200 || !str_contains(http('GET', '/admin/?r=biz')['body'], 'k-paid'), 'the page needs the owner\'s login');
    });
}

/** The editor fields of a product as they stand (for saves that change one thing). */
function biz_edit_in(array $p): array
{
    return ['name' => $p['name'], 'slug' => $p['slug'], 'type' => $p['type'], 'price' => shop_money($p['price']), 'stock' => $p['stock'] === null ? '' : (string) $p['stock'],
        'unlimited' => $p['stock'] === null ? '1' : '', 'stock_seen' => $p['stock'] === null ? '' : (string) $p['stock'], 'on_sale' => $p['on_sale'] ? '1' : '',
        'summary' => $p['summary'], 'body' => $p['body'], 'dl_text' => $p['delivery']['text'], 'dl_link' => $p['delivery']['link']];
}

/** Run $fn against php -S with the dev router (admin pages and api/shop.php). */
function biz_admin_http(callable $fn): void
{
    shop_http($fn);
}

/** Log in as the owner over HTTP; returns the headers (cookie) for the next requests. */
function biz_admin_login(): array
{
    if (!is_file(data_path('admin.json'))) {
        json_write_atomic(data_path('admin.json'), ['version' => 1, 'password_hash' => pw_hash('test-pass-2026-ok'), 'created_at' => now_iso(), 'password_changed_at' => now_iso(), 'session_epoch' => 1]);
        @unlink(data_path('setup-code.txt'));
    }
    $r = http('GET', '/admin/?r=login', ['headers' => ['Accept: application/json']]);
    $r = http('POST', '/admin/?r=login', ['form' => ['_csrf' => (string) ($r['json']['csrf'] ?? ''), 'password' => 'test-pass-2026-ok']]);
    preg_match('/pb_admin=([^;]+)/', $r['headers']['set-cookie'] ?? '', $c);
    return ['Cookie: pb_admin=' . ($c[1] ?? '')];
}

/** The admin CSRF token from a page. */
function biz_csrf(string $body): string
{
    return preg_match('/name="csrf" content="([^"]+)"/', $body, $m) ? html_entity_decode($m[1]) : '';
}

const UA_PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

function ref_get(string $qs, array $headers = []): array
{
    return http('GET', '/api/shop.php?a=ref' . $qs, ['headers' => array_merge(['User-Agent: ' . UA_PHONE], $headers)]);
}

function group_ref(): void
{
    app_init('cli');
    publish_site('初始内容');

    // ---- rates, names, codes, tokens ----
    ok(ref_rate_parse('5') === 500 && ref_rate_parse('7.5') === 750 && ref_rate_parse('５０') === 5000 && ref_rate_parse('0') === 0 && ref_rate_parse('12.25%') === 1225, 'rate: 5, 7.5, full-width 50, 0, 12.25% parse to basis points');
    ok(ref_rate_parse('50.01') === null && ref_rate_parse('-1') === null && ref_rate_parse('51') === null && ref_rate_parse('abc') === null && ref_rate_parse('1e1') === null, 'rate: over 50, negative and junk refused');
    ok(ref_rate_text(500) === '5%' && ref_rate_text(750) === '7.5%' && ref_rate_text(1225) === '12.25%', 'rate shown as 5% / 7.5% / 12.25%');
    $e = ref_partner_input(['name' => '', 'contact' => '', 'rate' => ''])[1];
    ok(isset($e['name'], $e['rate']), 'a 推广人 needs a name and a rate (no site-wide default rate)');
    ok(isset(ref_partner_input(['name' => str_repeat('长', 21), 'contact' => str_repeat('x', 41), 'rate' => '5'])[1]['name'], ref_partner_input(['name' => str_repeat('长', 21), 'contact' => str_repeat('x', 41), 'rate' => '5'])[1]['contact']), 'name over 20 and contact over 40 refused');
    ok(!isset(settings_get()['ref']['rate']) && settings_get()['ref']['enabled'] === false, 'settings: 推广 is off by default and has no default rate');
    $codes = [];
    for ($i = 0; $i < 30; $i++) {
        $codes[] = ref_partner_create(['name' => '人' . $i, 'contact' => '', 'rate' => '3'])['code'];
    }
    ok(count(array_unique($codes)) === 30 && !array_filter($codes, fn($c) => !preg_match(REF_CODE_RE, $c) || preg_match('/[01ILO]/', $c)), 'codes: 7 characters, no look-alikes (0 O 1 I L), all different');
    $toks = array_column(ref_partners(), 'token');
    ok(count(array_unique($toks)) === 30 && !array_filter($toks, fn($t) => !preg_match('/^[0-9a-f]{32}$/', $t)), 'view tokens: 128-bit random hex, all different');
    $pA = ref_partner_create(['name' => '"><svg/onload=a()>', 'contact' => '138 0013 8000', 'rate' => '10']);
    $pB = ref_partner_create(['name' => '=HYPERLINK("x")', 'contact' => 'wx: reader 13900139000', 'rate' => '5']);
    $pC = ref_partner_create(['name' => '停用的', 'contact' => 'c@example.com', 'rate' => '20']);
    ref_partner_save($pC['id'], ['name' => '停用的', 'contact' => 'c@example.com', 'rate' => '20', 'enabled' => ''], ref_partner_get($pC['id'])['rev']);
    ok(ref_partner_get($pC['id'])['enabled'] === false && throws(fn() => ref_partner_save($pC['id'], ['name' => 'x', 'contact' => '', 'rate' => '5', 'enabled' => '1'], 1), RefError::class), 'disable a 推广人; an edit from an old page (rev) is refused');
    ok(ref_partner_by_code(strtolower($pA['code']))['id'] === $pA['id'] && ref_partner_by_code($pA['code'] . 'X') === null && ref_partner_by_code("A' OR 1") === null, 'codes are case-insensitive in links; anything else is unknown');
    ok(ref_partner_by_token($pA['token'])['id'] === $pA['id'] && ref_partner_by_token(strtoupper($pA['token'])) === null && ref_partner_by_token(substr($pA['token'], 0, 31)) === null && ref_partner_by_token('') === null, 'view tokens must match exactly');

    // ---- redirect targets: only pages of this site ----
    $good = ['' => '', '/' => '', 'shop/poster.html' => 'shop/poster.html', '/shop/poster.html' => 'shop/poster.html', 'posts/' => 'posts/', 'posts/first-flight.html' => 'posts/first-flight.html'];
    $badT = [];
    foreach ($good as $in => $want) {
        if (ref_target($in) !== $want) {
            $badT[] = $in;
        }
    }
    ok(!$badT, 'redirect targets: home, shop/<slug>.html, posts/ and posts/<slug>.html allowed', implode(' | ', $badT));
    $evil = ['//evil.com', 'https://evil.com', '/\\evil.com', '\\\\evil.com', 'shop/../admin/', 'shop/x.html?a=1', 'shop/X.html', '%2F%2Fevil.com', 'javascript:alert(1)', "shop/x.html\n", "shop/x.html\r\nSet-Cookie: a=b",
        'posts/index.html', ' shop/x.html', 'shop/x.html#a', 'shop/-x.html', 'admin/', 'api/shop.php', 'shop//evil.com', '/.//evil.com', 'shop/x.html/', 'http:/evil.com', 'shop/' . str_repeat('a', 41) . '.html', 'shop/x.htm', 'uploads/a.png', '@evil.com'];
    $leaked = array_values(array_filter($evil, fn($t) => ref_target($t) !== null));
    ok(!$leaked, 'open redirect attempts all refused (other hosts, //, backslashes, schemes, dots, encoded, CRLF, odd paths)', json_encode($leaked));

    // ---- self-dealing guard ----
    ok(ref_is_self($pA, ['phone' => '13800138000', 'email' => '']) && ref_is_self($pB, ['phone' => '13900139000', 'email' => '']) && ref_is_self($pC, ['phone' => '', 'email' => 'C@Example.com']), 'self-dealing: same phone (also written inside the contact) or same email is the 推广人');
    ok(!ref_is_self($pA, ['phone' => '13700137000', 'email' => '']) && !ref_is_self(ref_partner_get($codes ? ref_partners()[0]['id'] : '') ?? $pA, ['phone' => '13800138000', 'email' => '']), 'another buyer, or a 推广人 without a contact, is not self-dealing');

    $ph = shop_make('poster', 'physical', '100', 50);
    $dg = shop_make('guide', 'digital', '50', null);
    shop_owner_action(shop_create_order('poster', shop_buyer('physical'))['id'], 'cancel', ['reason' => 'x']);   // warm up the order file

    shop_http(function () use ($pA, $pB, $pC, $ph) {
        clear_rl();
        // ---- the program is off: links still work, nothing is attributed ----
        $r = ref_get('&c=' . $pA['code']);
        ok($r['status'] === 302 && ($r['headers']['location'] ?? '') === '../#ai' && !isset($r['headers']['set-cookie']), 'off: the link still redirects to the home page 商城, no cookie');
        settings_save(['ref' => ['enabled' => true]], settings_get()['rev']);
        // ---- on: cookie and redirect ----
        $r = ref_get('&c=' . strtolower($pA['code']));
        $ck = $r['headers']['set-cookie'] ?? '';
        ok($r['status'] === 302 && ($r['headers']['location'] ?? '') === '../#ai', 'a share link redirects (302) to ../#ai');
        ok(str_starts_with($ck, 'pb_ref=' . $pA['code'] . ';') && stripos($ck, 'httponly') !== false && stripos($ck, 'samesite=lax') !== false && stripos($ck, 'path=/') !== false && preg_match('/max-age=(\d+)/i', $ck, $mm) && (int) $mm[1] === 30 * 86400, 'cookie: the code, HttpOnly, SameSite=Lax, path /, 30 days', $ck);
        ok(stripos($ck, 'secure') === false, 'no Secure flag over plain http (set when https)');
        $r = ref_get('&c=' . $pA['code'] . '&to=shop/poster.html');
        ok(($r['headers']['location'] ?? '') === '../shop/poster.html', 'to=shop/<slug>.html redirects to the product page');
        $bad = [];
        foreach (['//evil.com', 'https%3A%2F%2Fevil.com', '%2F%2Fevil.com', '/%5Cevil.com', 'shop/x.html%0D%0ASet-Cookie:%20a=b', '..%2Fadmin%2F', 'javascript:alert(1)'] as $to) {
            $loc = ref_get('&c=' . $pA['code'] . '&to=' . $to)['headers']['location'] ?? '';
            if ($loc !== '../#ai') {
                $bad[] = $to . ' → ' . $loc;
            }
        }
        ok(!$bad, 'open redirect attempts over HTTP all land on ../#ai', implode(' | ', $bad));
        ok(!isset(ref_get('&c=ZZZZZZZ')['headers']['set-cookie']) && !isset(ref_get('&c=' . $pC['code'])['headers']['set-cookie']) && !isset(ref_get('&c=%0D%0Ax')['headers']['set-cookie']), 'unknown, disabled or junk codes set no cookie (and still redirect)');
        ok(http('POST', '/api/shop.php?a=ref&c=' . $pA['code'], ['form' => []])['status'] === 405, 'a=ref by POST: 405');
        // ---- clicks: once per visitor per 30 min, bots / owner / HEAD skipped ----
        clear_rl();
        $c0 = ref_partner_get($pA['id'])['clicks'];
        ref_get('&c=' . $pA['code']);
        ref_get('&c=' . $pA['code']);
        ref_get('&c=' . $pA['code'], ['User-Agent: Googlebot/2.1 (+http://www.google.com/bot.html)']);
        http('GET', '/api/shop.php?a=ref&c=' . $pA['code'], ['headers' => ['User-Agent: curl/8.0']]);
        ref_get('&c=' . $pA['code'], ['Cookie: pb_nostat=1']);
        http('HEAD', '/api/shop.php?a=ref&c=' . $pA['code'], ['headers' => ['User-Agent: ' . UA_PHONE]]);
        ok(ref_partner_get($pA['id'])['clicks'] === $c0 + 1, 'clicks: counted once per visitor (30 min); bots, curl, the owner\'s browser and HEAD not counted', (string) (ref_partner_get($pA['id'])['clicks'] - $c0));
        $mon = substr(local_day(), 0, 7);
        ok((ref_partner_get($pA['id'])['clicks_m'][$mon] ?? 0) >= 1, 'clicks are also kept per month');
        clear_rl();
        for ($i = 0; $i < 30; $i++) {   // one visitor clicking many 推广人 links: capped per hour
            ref_get('&c=' . ref_partners()[$i]['code']);
        }
        ok(array_sum(array_column(array_slice(ref_partners(), 0, 30), 'clicks')) === REF_CLICK_HOUR, 'one visitor is counted at most ' . REF_CLICK_HOUR . ' times an hour across all 推广人');

        // ---- orders: cookie attribution, last click, injection, disabled, self ----
        clear_rl();
        $order = function (array $cookieHdr, string $phone = '13700137000', string $slug = 'poster'): ?array {
            $form = $slug === 'poster' ? ['p' => 'poster', 'qty' => '2', 'name' => '买家', 'phone' => $phone, 'address' => '北京市海淀区某路 9 号', 'consent' => '1'] : ['p' => $slug, 'qty' => '1', 'email' => $phone, 'consent' => '1'];
            $r = shop_post('order', $form, $cookieHdr);
            if (!preg_match('/o=(\d{8}-[A-Z2-9]{6})/', $r['headers']['location'] ?? '', $m)) {
                return null;
            }
            rl_clear('shop-order-made', ip_hash('127.0.0.1'));
            rl_clear('shop-order-try', ip_hash('127.0.0.1'));
            return shop_order_get($m[1]);
        };
        $o1 = $order(['Cookie: pb_ref=' . $pA['code']]);
        ok(($o1['ref']['pid'] ?? '') === $pA['id'] && $o1['ref']['rate_bp'] === 1000 && $o1['ref']['self'] === false, 'an order with the cookie carries the code and the rate of order time');
        $last = ref_get('&c=' . $pB['code'], ['Cookie: pb_ref=' . $pA['code']]);
        ok(str_starts_with($last['headers']['set-cookie'] ?? '', 'pb_ref=' . $pB['code']), 'last click wins: a second link replaces the cookie');
        $o2 = $order(['Cookie: pb_ref=' . $pB['code']]);
        ok(($o2['ref']['pid'] ?? '') === $pB['id'], 'the order goes to the last link clicked');
        $inj = [];
        foreach (['pb_ref=' . $pA['code'] . 'x', 'pb_ref=%22%3E%3Cscript%3E', 'pb_ref=' . str_repeat('A', 5000), 'pb_ref[]=' . $pA['code'], 'pb_ref=' . $pC['code'], 'pb_ref=ZZZZZZZ'] as $c) {
            $o = $order(['Cookie: ' . $c]);
            if ($o === null || isset($o['ref'])) {
                $inj[] = $c;
            }
        }
        ok(!$inj, 'forged, oversized, array, disabled or unknown cookie codes are ignored (order made, no 推广)', implode(' | ', array_map(fn($x) => substr($x, 0, 30), $inj)));
        $self = $order(['Cookie: pb_ref=' . $pA['code']], '13800138000');
        ok(($self['ref']['self'] ?? false) === true && ref_commission($self)['amount'] === 0, 'self-dealing: the 推广人\'s own phone gets no commission');
        // rate change: new orders only
        $p = ref_partner_get($pA['id']);
        ref_partner_save($pA['id'], ['name' => $p['name'], 'contact' => $p['contact'], 'rate' => '20', 'enabled' => '1'], $p['rev']);
        $o3 = $order(['Cookie: pb_ref=' . $pA['code']]);
        ok($o3['ref']['rate_bp'] === 2000 && shop_order_get($o1['id'])['ref']['rate_bp'] === 1000, 'a new rate applies to new orders only');
        // program switched off: cookie ignored
        settings_save(['ref' => ['enabled' => false]], settings_get()['rev']);
        $off = $order(['Cookie: pb_ref=' . $pA['code']]);
        ok($off !== null && !isset($off['ref']), 'program off: orders are not attributed');
        settings_save(['ref' => ['enabled' => true]], settings_get()['rev']);

        // ---- commission states ----
        $c = fn(array $o) => ref_commission(shop_order_get($o['id']));
        ok($c($o1)['state'] === 'unpaid', 'unpaid order: 还没付款');
        shop_owner_action($o1['id'], 'confirm');
        ok($c($o1)['state'] === 'pending' && $c($o1)['amount'] === 2000, 'paid: 待确认, 10% of the goods subtotal ¥200 (shipping and 识别尾数 excluded)');
        shop_owner_action($o1['id'], 'ship', ['courier' => '顺丰', 'tracking' => 'SF9988776655']);
        ok($c($o1)['state'] === 'pending', 'shipped: still 待确认');
        shop_owner_action($o1['id'], 'complete');
        ok($c($o1)['state'] === 'ok' && $c($o1)['amount'] === 2000, 'completed: 可结算 ¥20.00');
        shop_owner_action($o2['id'], 'confirm');
        shop_owner_action($o2['id'], 'cancel', ['reason' => '缺货，已退款']);
        ok($c($o2)['state'] === 'void' && $c($o2)['amount'] === 0, 'cancelled after payment: no commission');
        $dgo = $order(['Cookie: pb_ref=' . $pA['code']], 'buyer2@example.com', 'guide');
        shop_owner_action($dgo['id'], 'confirm');
        ok($c($dgo)['state'] === 'ok' && $c($dgo)['amount'] === 1000, 'digital: 可结算 as soon as it is delivered (确认收款 completes it), 20% of ¥50');
        ok(ref_commission(['ref' => ['pid' => 'r00000001', 'rate_bp' => 333], 'status' => 'completed', 'subtotal' => 999, 'refunds' => []])['amount'] === 33, 'commission rounds down to the fen');

        // ---- settlement, refund after settlement, log ----
        $sum = ref_partner_summary(ref_partner_get($pA['id']), shop_orders(), ref_settled_map(ref_doc()['settlements']));
        ok($sum['settlable'] === 3000 && $sum['pending'] === 0 && $sum['orders'] === 2 && $sum['sales'] === 25000, 'summary: 2 paid orders, ¥250 成交额, ¥30 可结算', json_encode([$sum['settlable'], $sum['orders'], $sum['sales']]));
        ok(throws(fn() => ref_settle($pA['id'], 2999, ''), RefError::class), 'settling a different amount than what is due is refused (page out of date)');
        $s1 = ref_settle($pA['id'], 3000, '=cmd|\'/C calc\'!A0');
        ok($s1['amount'] === 3000 && count($s1['items']) === 2, '标记已结算 records the amount, time and orders');
        ok(throws(fn() => ref_settle($pA['id'], 3000, ''), RefError::class), 'a second tap pays nothing twice (nothing due)');
        shop_owner_action($o1['id'], 'refund', ['note' => '退货退款', 'amount' => shop_money(shop_order_get($o1['id'])['amount'])]);
        $sum = ref_partner_summary(ref_partner_get($pA['id']), shop_orders(), ref_settled_map(ref_doc()['settlements']));
        ok($sum['settlable'] === -2000 && $c($o1)['amount'] === 0, 'refund after settlement: the commission becomes a negative adjustment (-¥20)');
        ok(throws(fn() => ref_settle($pA['id'], 0, ''), RefError::class), 'a negative balance cannot be settled');
        $o4 = $order(['Cookie: pb_ref=' . $pA['code']]);
        shop_owner_action($o4['id'], 'confirm');
        shop_owner_action($o4['id'], 'ship', ['courier' => '中通', 'tracking' => 'ZT12345678']);
        shop_owner_action($o4['id'], 'complete');
        $sum = ref_partner_summary(ref_partner_get($pA['id']), shop_orders(), ref_settled_map(ref_doc()['settlements']));
        ok($sum['settlable'] === 4000 - 2000, 'the next order (20% of ¥200) nets against the refund: ¥20 due');
        $s2 = ref_settle($pA['id'], 2000, '十月');
        ok(in_array([$o1['id'], -2000], $s2['items'], true) && in_array([$o4['id'], 4000], $s2['items'], true), 'the settlement lists the negative adjustment and the new order');
        $csv = ref_csv();
        ok(str_starts_with($csv, "\u{FEFF}") && str_contains($csv, "\r\n") && str_contains($csv, '"\'=cmd') && !str_contains($csv, '"=cmd'), 'settlement CSV: BOM, CRLF, a formula in the note is defused');
        ok(str_contains($csv, '"\'-20.00"') && str_contains($csv, $o4['id']), 'settlement CSV: one row per order, negative adjustments marked');
        ok(!str_contains($csv, '13700137000') && !str_contains($csv, '买家'), 'settlement CSV holds no buyer data');

        // ---- the 推广人 view page ----
        clear_rl();
        $r = http('GET', '/api/shop.php?a=partner&t=' . ref_partner_get($pA['id'])['token']);
        $b = $r['body'];
        ok($r['status'] === 200 && str_contains($r['headers']['content-security-policy'] ?? '', "script-src 'none'") && str_contains($r['headers']['x-robots-tag'] ?? '', 'noindex')
            && str_contains($r['headers']['cache-control'] ?? '', 'no-store') && ($r['headers']['referrer-policy'] ?? '') === 'no-referrer' && !preg_match('/<script/i', $b), 'view page: no script, strict CSP, noindex, no-store, no-referrer');
        ok(str_contains($b, '&gt;&lt;svg/onload=a()&gt;') && !str_contains($b, '<svg/onload'), 'a hostile 推广人 name is escaped');
        ok(str_contains($b, ref_mask_order($o4['id'])) && !str_contains($b, $o4['id']) && !str_contains($b, $o1['id']), 'order numbers are masked');
        ok(!str_contains($b, '买家') || !preg_match('/13700137000|1370|北京市海淀区|buyer2@example\.com|buyer2/', $b), 'no buyer name, phone, address or email');
        ok(!str_contains($b, '成本') && !str_contains($b, '3000.00'), 'no costs');
        ok(str_contains($b, 'a=ref&amp;c=' . $pA['code']) && str_contains($b, '已结算') && str_contains($b, '十月') && str_contains($b, '按月'), 'shows the link, settlement history and the months');
        $bad = http('GET', '/api/shop.php?a=partner&t=' . str_repeat('0', 32));
        ok($bad['status'] === 404 && !str_contains($bad['body'], 'onload') && !str_contains($bad['body'], 'HYPERLINK'), 'unknown token: the generic page, nothing about any 推广人');
        $old = ref_partner_get($pA['id'])['token'];
        ref_partner_new_token($pA['id']);
        ok(http('GET', '/api/shop.php?a=partner&t=' . $old)['status'] === 404, 'after 换一个查看页链接 the old link stops working');
        clear_rl();
        for ($i = 0; $i < REF_PARTNER_BAD_HOUR_T; $i++) {
            http('GET', '/api/shop.php?a=partner&t=' . bin2hex(random_bytes(16)));
        }
        ok(http('GET', '/api/shop.php?a=partner&t=' . ref_partner_get($pA['id'])['token'])['status'] === 429, 'token guessing: after 20 wrong tokens in an hour the visitor is refused');
        clear_rl();
        for ($i = 0; $i < 30; $i++) {
            http('GET', '/api/shop.php?a=partner&t=' . ref_partner_get($pB['id'])['token']);
        }
        ok(http('GET', '/api/shop.php?a=partner&t=' . ref_partner_get($pB['id'])['token'])['status'] === 429, 'view page: 30 opens per 10 minutes per visitor');
        clear_rl();

        // ---- admin pages ----
        $h = biz_admin_login();
        $r = http('GET', '/admin/?r=ref', ['headers' => $h]);
        ok($r['status'] === 200 && str_contains($r['body'], '只有一级') && str_contains($r['body'], '&quot;&gt;&lt;svg/onload=a()&gt;') && !str_contains($r['body'], '<svg/onload'), 'admin 推广: rules copy, names escaped');
        $r2 = http('GET', '/admin/?r=ref.edit&id=' . $pA['id'], ['headers' => $h]);
        ok($r2['status'] === 200 && str_contains($r2['body'], 'id="re-log"') && str_contains($r2['body'], 'a=partner&amp;t='), 'admin 推广人 page: settlement log and the view link');
        $r3 = http('GET', '/admin/?r=ref.edit&id=' . $pA['id'] . '&to=' . rawurlencode('//evil.com'), ['headers' => $h]);
        ok(!str_contains($r3['body'], 'evil.com'), 'the admin link builder ignores a foreign target');
        $tok = biz_csrf($r['body']);
        $add = http('POST', '/admin/?r=ref.add', ['headers' => $h, 'form' => ['_csrf' => $tok, 'name' => '新人', 'contact' => '', 'rate' => '']]);
        ok($add['status'] === 400 && str_contains($add['body'], '请填佣金比例'), 'admin: adding without a rate is refused with a message');
        $add = http('POST', '/admin/?r=ref.add', ['headers' => $h, 'form' => ['_csrf' => 'bad', 'name' => '新人', 'contact' => '', 'rate' => '5']]);
        ok($add['status'] === 403 && !in_array('新人', array_column(ref_partners(), 'name'), true), 'admin: forms need the CSRF token');
        $exp = http('GET', '/admin/?r=ref.export', ['headers' => $h]);
        ok($exp['status'] === 200 && str_contains($exp['headers']['content-disposition'] ?? '', 'attachment'), 'settlement CSV download');
        $ord = http('GET', '/admin/?r=order&id=' . $self['id'], ['headers' => $h]);
        ok(str_contains($ord['body'], 'id="o-ref"') && str_contains($ord['body'], '本人下单'), 'admin order page shows the 推广 card (and the self-dealing note)');
        $biz = http('GET', '/admin/?r=biz', ['headers' => $h]);
        ok(preg_match('/id="k-refsales".*?biz-v mono">([^<]+)</s', $biz['body'], $mm) === 1 && $mm[1] !== '¥0.00', '经营总览 shows 推广成交额', $mm[1] ?? '');
    });
    // privacy: no buyer data in ref.json
    $raw = (string) file_get_contents(ref_path());
    ok(!str_contains($raw, '13700137000') && !str_contains($raw, '买家') && !str_contains($raw, 'buyer2'), 'ref.json holds no buyer data');
}

const REF_PARTNER_BAD_HOUR_T = 20;
