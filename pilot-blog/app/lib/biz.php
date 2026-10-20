<?php
// 经营总览 (SPEC §20 经营总览): sales, gross profit, refunds, conversion and the owner's to-do list for a date range,
// computed from blog-data/shop/orders.json in one pass (a few thousand orders take a few milliseconds).
// Days are Beijing days; an order belongs to the day it was placed. Money in fen.
declare(strict_types=1);
defined('BLOG_APP') || exit;

const BIZ_RANGE_MAX = 92;                                       // days
const BIZ_PAID = ['confirmed', 'shipped', 'completed', 'failed']; // the owner confirmed the money (failed: 货源 purchase failed after payment)
const BIZ_LOW_STOCK = 3;
const BIZ_PRESETS = ['today' => '今天', '7' => '7天', '30' => '30天', 'month' => '本月'];

/** A Beijing calendar day 'Y-m-d' → the unix time of its 00:00 (Beijing), or null when not a real date. */
function biz_day_ts(string $day): ?int
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[1] < 2000 || (int) $m[1] > 2100) {
        return null;
    }
    return (new DateTimeImmutable($day . ' 00:00:00', new DateTimeZone('Asia/Shanghai')))->getTimestamp();
}

/** 'Y-m-d' plus $n days (calendar days, Beijing has no daylight saving). */
function biz_day_add(string $day, int $n): string
{
    return (new DateTimeImmutable($day . ' 12:00:00', new DateTimeZone('Asia/Shanghai')))->modify(($n >= 0 ? '+' : '') . $n . ' day')->format('Y-m-d');
}

/**
 * The range from the query: q = today | 7 | 30 | month, or from / to (Y-m-d). Default today. Returns
 * ['from', 'to', 'days' => [day, …], 'preset' => key or '', 'error' => owner text or null].
 */
function biz_range(array $q, ?int $now = null): array
{
    $today = local_day($now);
    $preset = is_string($q['q'] ?? null) && isset(BIZ_PRESETS[$q['q']]) ? $q['q'] : '';
    $from = $to = $today;
    $error = null;
    $hasDates = is_string($q['from'] ?? null) && is_string($q['to'] ?? null) && ($q['from'] !== '' || $q['to'] !== '');
    if ($preset === '' && $hasDates) {
        $f = trim(ascii_numbers((string) $q['from']));
        $t = trim(ascii_numbers((string) $q['to']));
        $f = $f === '' ? $t : $f;
        $t = $t === '' ? $f : $t;
        if (biz_day_ts($f) === null || biz_day_ts($t) === null) {
            $error = '日期不对，先显示今天。';
        } else {
            if ($f > $t) {
                [$f, $t] = [$t, $f];
            }
            if (biz_day_add($f, BIZ_RANGE_MAX - 1) < $t) {
                $f = biz_day_add($t, -(BIZ_RANGE_MAX - 1));
                $error = '一次最多看 ' . BIZ_RANGE_MAX . ' 天，已经改成截止日期前的 ' . BIZ_RANGE_MAX . ' 天。';
            }
            [$from, $to] = [$f, $t];
        }
    } elseif ($preset === '7' || $preset === '30') {
        $from = biz_day_add($today, -((int) $preset - 1));
    } elseif ($preset === 'month') {
        $from = substr($today, 0, 8) . '01';
    }
    if ($preset === '' && $from === $today && $to === $today && $error === null) {
        $preset = 'today';
    }
    $days = [];
    for ($d = $from; $d <= $to; $d = biz_day_add($d, 1)) {
        $days[] = $d;
    }
    return ['from' => $from, 'to' => $to, 'days' => $days, 'preset' => $preset, 'error' => $error];
}

/** The Beijing day of an ISO time. */
function biz_day_of(string $iso): string
{
    return local_day((int) strtotime($iso));
}

/**
 * What the goods of an order cost the owner, in fen, or null when unknown:
 * a 货源 card: what the supplier charged (once bought); own goods: the 成本价 saved on the order (orders made before
 * 成本价 existed use the product's current 成本价).
 */
function biz_order_cost(array $o, array $productsById): ?int
{
    if (($o['type'] ?? '') === 'supply') {
        $s = is_array($o['supply'] ?? null) ? $o['supply'] : [];
        return ($s['trade_no'] ?? '') !== '' ? max(0, (int) ($s['amount'] ?? 0)) : null;
    }
    if (array_key_exists('cost_unit', $o)) {
        return is_int($o['cost_unit']) ? $o['cost_unit'] * (int) $o['qty'] : null;
    }
    $p = $productsById[$o['product_id'] ?? ''] ?? null;
    return $p !== null && $p['cost'] !== null ? $p['cost'] * (int) $o['qty'] : null;
}

/**
 * The 经营总览 numbers for [from, to] (Beijing days, inclusive). Orders count on the day they were placed; refunds
 * on the day they were recorded. The to-do list is for right now, whatever the range.
 */
function biz_report(array $range, ?array $orders = null, ?array $products = null, ?array $settings = null, ?array $refDoc = null): array
{
    $orders ??= shop_orders();
    $products ??= shop_products();
    $settings ??= settings_get();
    $byId = [];
    foreach ($products as $p) {
        $byId[$p['id']] = $p;
    }
    $from = $range['from'];
    $to = $range['to'];
    $daily = array_fill_keys($range['days'], ['sales' => 0, 'profit' => 0, 'n' => 0]);
    $r = ['created' => 0, 'paid_n' => 0, 'paid_fen' => 0, 'profit_fen' => 0, 'profit_n' => 0, 'cost_unknown' => 0, 'refund_fen' => 0, 'refund_n' => 0,
        'refund_unknown' => 0, 'ref_sales' => 0, 'ref_n' => 0, 'visitors' => 0, 'rate' => null, 'aov' => null];
    $top = [];
    foreach ($orders as $o) {
        foreach (is_array($o['refunds'] ?? null) ? $o['refunds'] : [] as $x) {
            $rd = biz_day_of((string) ($x['t'] ?? ''));
            if ($rd >= $from && $rd <= $to) {
                $amt = array_key_exists('amount', $x) ? $x['amount'] : shop_refund_amount_in((string) ($x['note'] ?? ''));
                $r['refund_n']++;
                if (is_int($amt) && $amt > 0) {
                    $r['refund_fen'] += $amt;
                } else {
                    $r['refund_unknown']++;
                }
            }
        }
        $day = biz_day_of((string) $o['created_at']);
        if ($day < $from || $day > $to) {
            continue;
        }
        $r['created']++;
        if (!in_array($o['status'], BIZ_PAID, true)) {
            continue;
        }
        $amount = (int) $o['amount'];
        $r['paid_n']++;
        $r['paid_fen'] += $amount;
        $daily[$day]['sales'] += $amount;
        $daily[$day]['n']++;
        $cost = biz_order_cost($o, $byId);
        if ($cost === null) {
            $r['cost_unknown']++;
        } else {
            $r['profit_fen'] += $amount - $cost;
            $r['profit_n']++;
            $daily[$day]['profit'] += $amount - $cost;
        }
        $c = ref_commission($o);
        if ($c !== null && $c['state'] !== 'self') {
            $r['ref_sales'] += (int) $o['subtotal'];
            $r['ref_n']++;
        }
        $k = (string) $o['product_id'];
        $top[$k] ??= ['name' => (string) $o['name'], 'qty' => 0, 'fen' => 0, 'id' => $k];
        $top[$k]['qty'] += (int) $o['qty'];
        $top[$k]['fen'] += $amount;
        if (isset($byId[$k]) && $byId[$k]['name'] !== '') {
            $top[$k]['name'] = $byId[$k]['name'];
        }
    }
    usort($top, fn($a, $b) => [$b['qty'], $b['fen']] <=> [$a['qty'], $a['fen']]);
    $r['top'] = array_slice($top, 0, 5);
    $r['rate'] = $r['created'] > 0 ? $r['paid_n'] / $r['created'] : null;
    $r['aov'] = $r['paid_n'] > 0 ? intdiv($r['paid_fen'], $r['paid_n']) : null;
    foreach ($range['days'] as $d) {
        $r['visitors'] += stats_day($d)['visitors'];
    }
    $r['daily'] = $daily;
    $r['selling'] = count(array_filter($products, fn($p) => shop_product_live($p, $settings)));
    // right now: the owner's to-do list
    $c = shop_counts($orders);
    $low = array_values(array_filter($products, fn($p) => shop_product_live($p, $settings) && $p['stock'] !== null && $p['stock'] <= BIZ_LOW_STOCK));
    $ov = ref_overview($orders, $refDoc);
    $r['todo'] = ['claimed' => $c['claimed'], 'to_ship' => $c['to_ship'], 'failed' => $c['failed'], 'low' => $low, 'ref_due' => $ov['settlable'], 'ref_due_n' => $ov['due_partners'], 'ref_pending' => $ov['pending']];
    return $r;
}

/**
 * The daily trend as inline SVG (no script): 成交额 bars, 毛利 as a line of short level marks (readable for 1 day or 92).
 * Returns the markup, every number escaped. Negative 毛利 goes below the zero line.
 */
function biz_chart_svg(array $daily): string
{
    $days = array_keys($daily);
    $n = max(1, count($days));
    $W = 600;
    $H = 180;
    $top = 14;
    $bottom = 150;
    $max = 0;
    $min = 0;
    foreach ($daily as $d) {
        $max = max($max, $d['sales'], $d['profit']);
        $min = min($min, $d['profit']);
    }
    $max = $max > 0 ? $max : 100;
    $span = $max - $min;
    $y = fn(int $v) => $top + ($bottom - $top) * ($max - $v) / $span;
    $zero = $y(0);
    $slot = $W / $n;
    $f = fn(float $v) => number_format($v, 1, '.', '');
    $out = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-labelledby="biz-chart-t" preserveAspectRatio="none" class="biz-svg">'
        . '<title id="biz-chart-t">' . h('每天的成交额（柱）和毛利（橙线），最高 ¥' . shop_money($max)) . '</title>'
        . '<line x1="0" y1="' . $f($y($max)) . '" x2="' . $W . '" y2="' . $f($y($max)) . '" stroke="#2A3A66" stroke-width="1" stroke-dasharray="4 4" vector-effect="non-scaling-stroke"/>'
        . '<line x1="0" y1="' . $f($zero) . '" x2="' . $W . '" y2="' . $f($zero) . '" stroke="#5E6EA2" stroke-width="1" vector-effect="non-scaling-stroke"/>';
    $pts = [];
    foreach ($days as $i => $day) {
        $d = $daily[$day];
        $x = $i * $slot;
        $label = str_replace('-', '.', substr($day, 5)) . '：成交 ¥' . shop_money($d['sales']) . '（' . $d['n'] . ' 单），毛利 ' . ($d['profit'] < 0 ? '-¥' : '¥') . shop_money(abs($d['profit']));
        $bh = $d['sales'] > 0 ? max(1.5, $zero - $y($d['sales'])) : 0;
        $out .= '<g><title>' . h($label) . '</title>'
            . '<rect x="' . $f($x + $slot * 0.14) . '" y="' . $f($zero - $bh) . '" width="' . $f($slot * 0.72) . '" height="' . $f($bh) . '" fill="#3E5FA8"/>'
            . '<rect x="' . $f($x) . '" y="' . $top . '" width="' . $f($slot) . '" height="' . ($bottom - $top) . '" fill="transparent"/></g>';
        $py = $y($d['profit']);
        $pts[] = [$x + $slot * 0.1, $x + $slot * 0.9, $py];
    }
    $line = [];
    foreach ($pts as [$x1, $x2, $py]) {
        $line[] = $f($x1) . ',' . $f($py) . ' ' . $f($x2) . ',' . $f($py);
    }
    $out .= '<polyline points="' . implode(' ', $line) . '" fill="none" stroke="#EA4C22" stroke-width="2.5" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>';
    return $out . '</svg>';
}

/** 1234567 → '¥12,345.67'; negative with a minus sign. */
function biz_money(int $fen): string
{
    $a = abs($fen);
    return ($fen < 0 ? '-' : '') . '¥' . number_format(intdiv($a, 100), 0, '.', ',') . '.' . str_pad((string) ($a % 100), 2, '0', STR_PAD_LEFT);
}

/** '10月14日' (with the year when it is not this year). */
function biz_day_label(string $day, ?string $today = null): string
{
    $today ??= local_day();
    [$y, $m, $d] = array_map('intval', explode('-', $day));
    return ($y !== (int) substr($today, 0, 4) ? $y . '年' : '') . $m . '月' . $d . '日';
}
