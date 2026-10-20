<?php
// 推广链接 (SPEC §20 推广): one level only. The owner adds 推广人 (people who share the shop), each gets a code for
// links like api/shop.php?a=ref&c=<code> and a secret view page. A click sets a first-party cookie (30 days, last
// click wins); an order made with that cookie carries the code and the rate at that moment. Commission =
// (goods subtotal - refunds) x rate, payable once the order is completed. The owner pays by hand outside the site
// and records it here (标记已结算). No sub-sites, no wallets, no withdrawals, no 下线.
// Data: blog-data/shop/ref.json {rev, partners, settlements}. Buyer data never leaves the orders file.
declare(strict_types=1);
defined('BLOG_APP') || exit;

const REF_CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';   // no 0/O, 1/I/L
const REF_CODE_RE = '/^[A-HJ-KM-NP-Z2-9]{7}$/';
const REF_ID_RE = '/^r[0-9a-f]{8}$/';
const REF_TOKEN_RE = '/^[0-9a-f]{32}$/';
const REF_COOKIE = 'pb_ref';
const REF_COOKIE_DAYS = 30;
const REF_RATE_MAX_BP = 5000;          // 50 %, in basis points (1 % = 100)
const REF_PARTNERS_MAX = 200;
const REF_SETTLEMENTS_MAX = 5000;
const REF_NAME_MAX = 20;
const REF_CONTACT_MAX = 40;
const REF_NOTE_MAX = 100;
const REF_CLICK_GAP = 1800;            // one counted click per visitor and 推广人 per 30 minutes
const REF_CLICK_HOUR = 20;             // counted clicks per visitor per hour (all 推广人)
const REF_CLICK_SITE_DAY = 20000;
const REF_STATE = ['unpaid' => '还没付款', 'pending' => '待确认', 'ok' => '可结算', 'void' => '不计佣金', 'self' => '本人下单，不计佣金'];

/** A refusal with a plain Chinese message for the owner. */
final class RefError extends RuntimeException
{
    public array $fields;

    public function __construct(string $message, array $fields = [])
    {
        $this->fields = $fields;
        parent::__construct($message);
    }
}

// ---------- settings and storage ----------

function ref_enabled(?array $settings = null): bool
{
    return (bool) (($settings ?? settings_get())['ref']['enabled'] ?? false);
}

function ref_path(): string
{
    return shop_dir('ref.json');
}

function ref_partner_normalize(array $p): ?array
{
    if (!preg_match(REF_ID_RE, (string) ($p['id'] ?? '')) || !preg_match(REF_CODE_RE, (string) ($p['code'] ?? '')) || !preg_match(REF_TOKEN_RE, (string) ($p['token'] ?? ''))) {
        return null;
    }
    $months = [];
    foreach (is_array($p['clicks_m'] ?? null) ? $p['clicks_m'] : [] as $m => $n) {
        if (preg_match('/^\d{4}-\d{2}$/', (string) $m)) {
            $months[(string) $m] = max(0, (int) $n);
        }
    }
    ksort($months);
    return [
        'id' => $p['id'],
        'code' => $p['code'],
        'token' => $p['token'],
        'name' => (string) ($p['name'] ?? ''),
        'contact' => (string) ($p['contact'] ?? ''),
        'rate_bp' => max(0, min(REF_RATE_MAX_BP, (int) ($p['rate_bp'] ?? 0))),
        'enabled' => (bool) ($p['enabled'] ?? true),
        'clicks' => max(0, (int) ($p['clicks'] ?? 0)),
        'clicks_m' => array_slice($months, -24, null, true),
        'rev' => max(1, (int) ($p['rev'] ?? 1)),
        'created_at' => (string) ($p['created_at'] ?? now_iso()),
        'updated_at' => (string) ($p['updated_at'] ?? now_iso()),
    ];
}

function ref_settlement_normalize(array $s): ?array
{
    if (!preg_match('/^s[0-9a-f]{8}$/', (string) ($s['id'] ?? '')) || !preg_match(REF_ID_RE, (string) ($s['pid'] ?? ''))) {
        return null;
    }
    $items = [];
    foreach (is_array($s['items'] ?? null) ? $s['items'] : [] as $it) {
        if (is_array($it) && preg_match(ORDER_ID_RE, (string) ($it[0] ?? '')) && is_int($it[1] ?? null)) {
            $items[] = [$it[0], $it[1]];
        }
    }
    return ['id' => $s['id'], 'pid' => $s['pid'], 'at' => (string) ($s['at'] ?? now_iso()), 'amount' => (int) ($s['amount'] ?? 0),
        'note' => (string) ($s['note'] ?? ''), 'items' => $items];
}

function ref_doc(): array
{
    $d = json_read(ref_path(), []);
    $d = is_array($d) ? $d : [];
    $ps = [];
    foreach (is_array($d['partners'] ?? null) ? $d['partners'] : [] as $p) {
        if (is_array($p) && ($n = ref_partner_normalize($p)) !== null) {
            $ps[] = $n;
        }
    }
    $ss = [];
    foreach (is_array($d['settlements'] ?? null) ? $d['settlements'] : [] as $s) {
        if (is_array($s) && ($n = ref_settlement_normalize($s)) !== null) {
            $ss[] = $n;
        }
    }
    return ['rev' => (int) ($d['rev'] ?? 0), 'partners' => $ps, 'settlements' => $ss];
}

/** Change ref.json under its own lock. $fn(array &$doc) returns any value; the file is written when the doc changed. */
function ref_mutate(callable $fn): mixed
{
    shop_ensure_dirs();
    return with_lock('ref', function () use ($fn) {
        $doc = ref_doc();
        $before = $doc;
        $res = $fn($doc);
        if ($doc !== $before) {
            $doc['rev'] = $before['rev'] + 1;
            json_write_atomic(ref_path(), ['rev' => $doc['rev'], 'updated_at' => now_iso(), 'partners' => array_values($doc['partners']), 'settlements' => array_values($doc['settlements'])]);
        }
        return $res;
    });
}

function ref_partners(): array
{
    return ref_doc()['partners'];
}

function ref_partner_get(string $id): ?array
{
    foreach (ref_partners() as $p) {
        if ($p['id'] === $id) {
            return $p;
        }
    }
    return null;
}

/** The 推广人 a code names (any state), or null. Codes are case-insensitive in links. */
function ref_partner_by_code(string $code): ?array
{
    $code = strtoupper(trim($code));
    if (!preg_match(REF_CODE_RE, $code)) {
        return null;
    }
    foreach (ref_partners() as $p) {
        if ($p['code'] === $code) {
            return $p;
        }
    }
    return null;
}

/** The 推广人 whose view token this is, or null. Every token is compared in constant time. */
function ref_partner_by_token(string $token): ?array
{
    $found = null;
    $ok = preg_match(REF_TOKEN_RE, $token) === 1;
    foreach (ref_partners() as $p) {
        if (hash_equals($p['token'], $ok ? $token : str_repeat('x', 32))) {
            $found = $ok ? $p : null;
        }
    }
    return $found;
}

// ---------- owner input ----------

/** '5', '5.5', '５％', '12.25' → basis points (500, 550, 500, 1225); null when not a plain 0..50 number. */
function ref_rate_parse(string $s): ?int
{
    $s = str_replace(['%', '％', ' '], '', ascii_numbers($s));
    if (!preg_match('/^(\d{1,2})(?:\.(\d{1,2}))?$/', $s, $m)) {
        return null;
    }
    $bp = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    return $bp <= REF_RATE_MAX_BP ? $bp : null;
}

/** 500 → '5%', 550 → '5.5%', 1225 → '12.25%' */
function ref_rate_text(int $bp): string
{
    $s = intdiv($bp, 100) . ($bp % 100 ? '.' . rtrim(str_pad((string) ($bp % 100), 2, '0', STR_PAD_LEFT), '0') : '');
    return $s . '%';
}

/** Validate the 推广人 form. Returns [clean, errors]. The rate is required (owner decision: no site-wide default). */
function ref_partner_input(array $in): array
{
    $e = [];
    $c = [];
    $c['name'] = shop_clean_line($in['name'] ?? '', REF_NAME_MAX);
    if ($c['name'] === '') {
        $e['name'] = '请填推广人的称呼。';
    } elseif (mb_strlen($c['name']) > REF_NAME_MAX) {
        $e['name'] = '称呼最多 ' . REF_NAME_MAX . ' 个字。';
    }
    $c['contact'] = shop_clean_line($in['contact'] ?? '', REF_CONTACT_MAX);
    if (mb_strlen($c['contact']) > REF_CONTACT_MAX) {
        $e['contact'] = '联系方式最多 ' . REF_CONTACT_MAX . ' 个字。';
    }
    $rateRaw = trim(is_string($in['rate'] ?? null) ? $in['rate'] : '');
    $bp = $rateRaw === '' ? null : ref_rate_parse($rateRaw);
    if ($rateRaw === '') {
        $e['rate'] = '请填佣金比例（0 到 50，单位是 %）。';
    } elseif ($bp === null) {
        $e['rate'] = '佣金比例请填 0 到 50 之间的数字，比如 5 或 7.5（单位是 %）。';
    } else {
        $c['rate_bp'] = $bp;
    }
    return [$c, $e];
}

function ref_new_code(array $partners): string
{
    $taken = array_flip(array_column($partners, 'code'));
    do {
        $c = '';
        for ($i = 0; $i < 7; $i++) {
            $c .= REF_CODE_ALPHABET[random_int(0, strlen(REF_CODE_ALPHABET) - 1)];
        }
    } while (isset($taken[$c]));
    return $c;
}

function ref_partner_create(array $in): array
{
    [$c, $e] = ref_partner_input($in);
    if ($e) {
        throw new RefError((string) reset($e), $e);
    }
    return ref_mutate(function (array &$doc) use ($c) {
        if (count($doc['partners']) >= REF_PARTNERS_MAX) {
            throw new RefError('推广人最多 ' . REF_PARTNERS_MAX . ' 位。不再合作的可以停用。');
        }
        $ids = array_flip(array_column($doc['partners'], 'id'));
        do {
            $id = 'r' . bin2hex(random_bytes(4));
        } while (isset($ids[$id]));
        $p = ref_partner_normalize(['id' => $id, 'code' => ref_new_code($doc['partners']), 'token' => bin2hex(random_bytes(16)), 'enabled' => true] + $c);
        $doc['partners'][] = $p;
        return $p;
    });
}

/** Edit name, contact, rate (new orders only) and the on/off state. $rev: the form's copy, so two windows do not overwrite each other. */
function ref_partner_save(string $id, array $in, int $rev): array
{
    [$c, $e] = ref_partner_input($in);
    if ($e) {
        throw new RefError((string) reset($e), $e);
    }
    $c['enabled'] = ($in['enabled'] ?? '') === '1';
    return ref_partner_update($id, function (array $p) use ($c, $rev) {
        if ($p['rev'] !== $rev) {
            throw new RefError('这位推广人在别处改过了（可能是另一个窗口）。刷新页面后再改。');
        }
        return array_replace($p, $c);
    });
}

function ref_partner_update(string $id, callable $fn): array
{
    return ref_mutate(function (array &$doc) use ($id, $fn) {
        foreach ($doc['partners'] as $k => $p) {
            if ($p['id'] === $id) {
                $n = $fn($p);
                $n['rev'] = $p['rev'] + 1;
                $n['updated_at'] = now_iso();
                $doc['partners'][$k] = $n;
                return $n;
            }
        }
        throw new RefError('找不到这位推广人。');
    });
}

/** A new view link: the old one stops working at once (for a link that was passed around). */
function ref_partner_new_token(string $id): array
{
    return ref_partner_update($id, fn(array $p) => ['token' => bin2hex(random_bytes(16))] + $p);
}

// ---------- links ----------

/** https://example.com of the site: the live site address, else this request's host. */
function ref_site_origin(): string
{
    $o = (string) (site_live()['fields']['site.origin'] ?? '');
    if (preg_match('#^https://[a-z0-9.-]+(:\d+)?$#', $o) && !str_contains($o, 'DEPLOY-DOMAIN')) {
        return $o;
    }
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_match('/^[a-z0-9.-]+(:\d+)?$/', $host) ? (string) preg_replace('/^pay\./', '', $host) : 'localhost';
    return (is_https() ? 'https://' : 'http://') . $host;
}

/** The share link. $to: '' (home) or a path ref_target() accepts, e.g. 'shop/pc12-poster.html'. */
function ref_link(array $p, string $to = ''): string
{
    $t = $to !== '' && ref_target($to) !== null ? '&to=' . $to : '';
    return ref_site_origin() . site_base() . 'api/shop.php?a=ref&c=' . $p['code'] . $t;
}

function ref_view_link(array $p): string
{
    return ref_site_origin() . site_base() . 'api/shop.php?a=partner&t=' . $p['token'];
}

/**
 * Where a share link may send the visitor, as a path relative to the site root: '' for the home page, or exactly
 * shop/<slug>.html, posts/ or posts/<slug>.html. Anything else (other hosts, //, backslashes, schemes, dots,
 * encoded characters, query strings) is null, so the redirect can never leave this site.
 */
function ref_target(string $to): ?string
{
    if ($to === '' || $to === '/') {
        return '';
    }
    if (strlen($to) > 80 || !preg_match('#^/?(shop/[a-z0-9-]{1,40}\.html|posts/|posts/[a-z0-9-]{1,60}\.html)$#D', $to, $m)) {
        return null;
    }
    $path = $m[1];
    if (preg_match('#^(?:shop|posts)/([a-z0-9-]+)\.html$#', $path, $s) && (!preg_match(SLUG_RE, $s[1]) || $s[1] === 'index')) {
        return null;
    }
    return $path;
}

// ---------- attribution ----------

/** The enabled 推广人 named by this browser's cookie while the program is on; null otherwise. */
function ref_partner_from_cookie(?array $settings = null): ?array
{
    $raw = $_COOKIE[REF_COOKIE] ?? null;
    if (!is_string($raw) || !ref_enabled($settings)) {
        return null;
    }
    $p = ref_partner_by_code($raw);
    return $p !== null && $p['enabled'] ? $p : null;
}

function ref_set_cookie(string $code): void
{
    if (headers_sent() || !preg_match(REF_CODE_RE, $code)) {
        return;
    }
    setcookie(REF_COOKIE, $code, [
        'expires' => time() + REF_COOKIE_DAYS * 86400,
        'path' => site_base(),
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Contact strings in comparable forms: the mobile number, the email, and the plain lowercase text. */
function ref_contact_keys(string $s): array
{
    $k = [];
    $s = trim($s);
    if ($s === '') {
        return [];
    }
    if (($ph = shop_phone_normalize($s)) !== null) {
        $k[] = 'p:' . $ph;
    }
    if (($em = email_normalize($s)) !== null) {
        $k[] = 'e:' . $em;
    }
    // a contact written as 「微信 138 0013 8000」 or 「邮箱 a@b.com」: the number or address inside it
    $flat = (string) preg_replace('/[\s\-]/u', '', ascii_numbers($s));
    if (preg_match_all('/(?<!\d)1[3-9]\d{9}(?!\d)/', $flat, $m)) {
        foreach ($m[0] as $ph) {
            $k[] = 'p:' . $ph;
        }
    }
    if (preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $s, $m)) {
        foreach ($m[0] as $x) {
            if (($em = email_normalize($x)) !== null) {
                $k[] = 'e:' . $em;
            }
        }
    }
    $k[] = 't:' . mb_strtolower($flat);
    return array_values(array_unique($k));
}

/** The buyer is the 推广人 (same phone or email as the 推广人's contact): no commission on their own orders. */
function ref_is_self(array $partner, array $buyer): bool
{
    $mine = ref_contact_keys($partner['contact']);
    if (!$mine) {
        return false;
    }
    $theirs = array_merge(ref_contact_keys((string) ($buyer['phone'] ?? '')), ref_contact_keys((string) ($buyer['email'] ?? '')));
    return (bool) array_intersect(array_filter($mine, fn($x) => !str_starts_with($x, 't:') || mb_strlen($x) > 6), $theirs);
}

/** What an order keeps about its 推广: who, which code, the rate at order time, and the self-dealing flag. */
function ref_order_stamp(array $partner, array $buyer): array
{
    return ['pid' => $partner['id'], 'code' => $partner['code'], 'rate_bp' => $partner['rate_bp'], 'self' => ref_is_self($partner, $buyer)];
}

/** Count one click on a share link: no bots, prefetches or the owner's browser; once per visitor and 推广人 per 30 min. */
function ref_click(array $partner, string $visitor, string $ua, ?int $now = null): bool
{
    if (stats_is_bot($ua) || stats_is_prefetch() || stats_is_owner()) {
        return false;
    }
    if (!rl_hit('ref-click', $visitor . '|' . $partner['id'], 1, REF_CLICK_GAP)['allowed']
        || !rl_hit('ref-click-ip', $visitor, REF_CLICK_HOUR, 3600)['allowed']
        || !rl_hit('ref-click-site', local_day(), REF_CLICK_SITE_DAY, 2 * 86400)['allowed']) {
        return false;
    }
    $month = substr(local_day($now), 0, 7);
    try {
        ref_mutate(function (array &$doc) use ($partner, $month) {
            foreach ($doc['partners'] as $k => $p) {
                if ($p['id'] === $partner['id']) {
                    $doc['partners'][$k]['clicks']++;
                    $doc['partners'][$k]['clicks_m'][$month] = ($p['clicks_m'][$month] ?? 0) + 1;
                }
            }
        });
    } catch (Throwable $e) {
        error_log('ref click: ' . get_class($e));
        return false;
    }
    return true;
}

// ---------- commission ----------

/**
 * The commission of one order, from the order alone (settlements are separate):
 * ['state' => unpaid|pending|ok|void|self, 'base' => fen, 'amount' => fen, 'rate_bp' => int], or null without 推广.
 * base = goods subtotal (no shipping, no 识别尾数) minus recorded refunds; a refund without an amount voids it.
 * pending: paid, not finished; ok (可结算): completed; void: cancelled.
 */
function ref_commission(array $o): ?array
{
    $r = $o['ref'] ?? null;
    if (!is_array($r) || !preg_match(REF_ID_RE, (string) ($r['pid'] ?? ''))) {
        return null;
    }
    $rate = max(0, min(REF_RATE_MAX_BP, (int) ($r['rate_bp'] ?? 0)));
    $refunded = shop_refunded($o);
    $base = $refunded['unknown'] > 0 ? 0 : max(0, (int) $o['subtotal'] - $refunded['fen']);
    $state = match ($o['status']) {
        'pending', 'claimed' => 'unpaid',
        'cancelled' => 'void',
        'completed' => 'ok',
        default => 'pending',   // confirmed, shipped, failed
    };
    if (!empty($r['self'])) {
        $state = 'self';
    }
    $amount = in_array($state, ['void', 'self'], true) ? 0 : intdiv($base * $rate, 10000);
    return ['state' => $state, 'base' => in_array($state, ['void', 'self'], true) ? 0 : $base, 'amount' => $amount, 'rate_bp' => $rate];
}

/** order id => fen already marked as settled (sum over settlements; adjustments may be negative). */
function ref_settled_map(array $settlements): array
{
    $m = [];
    foreach ($settlements as $s) {
        foreach ($s['items'] as [$oid, $fen]) {
            $m[$oid] = ($m[$oid] ?? 0) + $fen;
        }
    }
    return $m;
}

/**
 * One 推广人's numbers from the orders. 'lines': every order with this code, newest first, each with its
 * commission, what was settled and 'due' (what the next settlement would pay: commission of a completed order
 * minus what was paid; a refund after settlement makes it negative). Unpaid and pending orders are never due.
 */
function ref_partner_summary(array $partner, array $orders, array $settledMap): array
{
    $s = ['clicks' => $partner['clicks'], 'orders' => 0, 'sales' => 0, 'pending' => 0, 'settlable' => 0, 'settled' => 0, 'lines' => []];
    foreach ($orders as $o) {
        if (($o['ref']['pid'] ?? '') !== $partner['id']) {
            continue;
        }
        $c = ref_commission($o);
        if ($c === null) {
            continue;
        }
        $paid = $settledMap[$o['id']] ?? 0;
        $due = in_array($c['state'], ['unpaid', 'pending'], true) ? 0 : $c['amount'] - $paid;
        if (in_array($c['state'], ['pending', 'ok'], true)) {
            $s['orders']++;
            $s['sales'] += (int) $o['subtotal'];
        }
        if ($c['state'] === 'pending') {
            $s['pending'] += $c['amount'];
        }
        $s['settlable'] += $due;
        $s['settled'] += $paid;
        $s['lines'][] = ['o' => $o, 'c' => $c, 'settled' => $paid, 'due' => $due];
    }
    usort($s['lines'], fn($a, $b) => strcmp($b['o']['created_at'], $a['o']['created_at']));
    return $s;
}

/** Summaries of every 推广人, keyed by id, and the totals. */
function ref_overview(?array $orders = null, ?array $doc = null): array
{
    $orders ??= shop_orders();
    $doc ??= ref_doc();
    $map = ref_settled_map($doc['settlements']);
    $out = ['by' => [], 'settlable' => 0, 'pending' => 0, 'due_partners' => 0];
    foreach ($doc['partners'] as $p) {
        $sum = ref_partner_summary($p, $orders, $map);
        $out['by'][$p['id']] = $sum;
        $out['settlable'] += max(0, $sum['settlable']);
        $out['pending'] += $sum['pending'];
        if ($sum['settlable'] > 0) {
            $out['due_partners']++;
        }
    }
    return $out;
}

/**
 * 标记已结算: record that the owner paid $expect fen to the 推广人 outside the site. The amount must equal what is
 * due right now (a second tap, or a change since the page was opened, is refused instead of paying twice).
 */
function ref_settle(string $pid, int $expect, string $note, ?int $now = null): array
{
    $note = shop_clean_line($note, REF_NOTE_MAX);
    if (mb_strlen($note) > REF_NOTE_MAX) {
        throw new RefError('备注最多 ' . REF_NOTE_MAX . ' 个字。', ['note' => '备注太长了。']);
    }
    return ref_mutate(function (array &$doc) use ($pid, $expect, $note, $now) {
        $partner = null;
        foreach ($doc['partners'] as $p) {
            if ($p['id'] === $pid) {
                $partner = $p;
            }
        }
        if ($partner === null) {
            throw new RefError('找不到这位推广人。');
        }
        $sum = ref_partner_summary($partner, shop_orders(), ref_settled_map($doc['settlements']));
        $items = [];
        foreach ($sum['lines'] as $l) {
            if ($l['due'] !== 0) {
                $items[] = [$l['o']['id'], $l['due']];
            }
        }
        $total = array_sum(array_column($items, 1));
        if ($total <= 0) {
            throw new RefError($total < 0 ? '这位推广人现在是负数（结算以后有订单退款），等有新的佣金再一起结。' : '现在没有可结算的佣金，可能刚刚已经结算过了。');
        }
        if ($total !== $expect) {
            throw new RefError('可结算金额刚刚变了（现在是 ¥' . shop_money($total) . '）。刷新页面，核对后再点。');
        }
        if (count($doc['settlements']) >= REF_SETTLEMENTS_MAX) {
            throw new RefError('结算记录太多了，先导出 CSV 备份，再联系网站维护的人清理。');
        }
        $ids = array_flip(array_column($doc['settlements'], 'id'));
        do {
            $id = 's' . bin2hex(random_bytes(4));
        } while (isset($ids[$id]));
        $s = ['id' => $id, 'pid' => $pid, 'at' => gmdate('Y-m-d\TH:i:s\Z', $now ?? time()), 'amount' => $total, 'note' => $note, 'items' => $items];
        $doc['settlements'][] = $s;
        return $s;
    });
}

/** Settlement log as CSV, one row per order in each settlement (UTF-8 BOM, CRLF, quoted, formula-safe). */
function ref_csv(): string
{
    $doc = ref_doc();
    $names = [];
    foreach ($doc['partners'] as $p) {
        $names[$p['id']] = [$p['name'], $p['code']];
    }
    $rows = ["\u{FEFF}" . implode(',', array_map('csv_cell', ['结算时间', '推广人', '推广码', '本次结算合计', '订单号', '这一单结算', '备注']))];
    foreach (array_reverse($doc['settlements']) as $s) {
        [$n, $code] = $names[$s['pid']] ?? ['（已不存在）', ''];
        foreach ($s['items'] ?: [['', 0]] as [$oid, $fen]) {
            $rows[] = implode(',', array_map('csv_cell', [local_time($s['at']), $n, $code, shop_money($s['amount']), (string) $oid, ($fen < 0 ? '-' : '') . shop_money(abs($fen)), $s['note']]));
        }
    }
    return implode("\r\n", $rows) . "\r\n";
}

// ---------- the 推广人's own view page (api/shop.php?a=partner&t=…) ----------

/** 20261020-K7P2QX → 20261020-K7**** */
function ref_mask_order(string $id): string
{
    return preg_match(ORDER_ID_RE, $id) ? substr($id, 0, 11) . '****' : '****';
}

function ref_money_signed(int $fen): string
{
    return ($fen < 0 ? '-¥' : '¥') . shop_money(abs($fen));
}

/** 可结算 / 已结算 / 已结 ¥x，待扣回 ¥y … for one order line of a summary. */
function ref_line_label(array $l): string
{
    $st = $l['c']['state'];
    if ($l['settled'] !== 0 && $l['due'] === 0) {
        return '已结算 ' . ref_money_signed($l['settled']);
    }
    if ($l['settled'] !== 0) {
        return '已结 ' . ref_money_signed($l['settled']) . '，' . ($l['due'] < 0 ? '下次扣回 ' . ref_money_signed(-$l['due']) : '还可结算 ' . ref_money_signed($l['due']));
    }
    return REF_STATE[$st];
}

/** Per month (order month, Beijing): pending, due, settled; newest first. */
function ref_months(array $sum): array
{
    $m = [];
    foreach ($sum['lines'] as $l) {
        $k = substr(local_day((int) strtotime((string) $l['o']['created_at'])), 0, 7);
        $m[$k] ??= ['orders' => 0, 'sales' => 0, 'pending' => 0, 'due' => 0, 'settled' => 0];
        if (in_array($l['c']['state'], ['pending', 'ok'], true)) {
            $m[$k]['orders']++;
            $m[$k]['sales'] += (int) $l['o']['subtotal'];
        }
        if ($l['c']['state'] === 'pending') {
            $m[$k]['pending'] += $l['c']['amount'];
        }
        $m[$k]['due'] += $l['due'];
        $m[$k]['settled'] += $l['settled'];
    }
    krsort($m);
    return $m;
}

/** The view page body: links, numbers, months, recent orders (masked), settlements. No buyer data, no costs. */
function ref_partner_page_html(array $p, array $sum, array $settlements, array $products, bool $programOn): string
{
    $row = fn(string $k, string $v, string $cls = '') => '<div' . ($cls !== '' ? ' class="' . $cls . '"' : '') . '><dt>' . enc_text($k) . '</dt><dd>' . enc_text($v) . '</dd></div>';
    $h = '<div class="scol">' . "\n"
        . '<header class="strip"><p class="strip-row">' . SHOP_FIX_SVG . '<b class="fix">REF ' . enc_text($p['code']) . '</b></p>'
        . '<h1>' . enc_text($p['name']) . ' 的推广</h1>'
        . '<p class="lede">这一页只给你自己看，别把网址发给别人。佣金比例 ' . enc_text(ref_rate_text($p['rate_bp'])) . '（按下单时的比例算）。</p></header>' . "\n";
    if (!$p['enabled'] || !$programOn) {
        $h .= '<p class="state-box">' . enc_text(!$p['enabled'] ? '你的推广链接已经停用，新的订单不再计佣金。已经算好的佣金照常结算。' : '店主暂时关掉了推广，新的订单不再计佣金。已经算好的佣金照常结算。') . '</p>' . "\n";
    }
    $h .= '<section class="howbox"><p class="end-k">YOUR LINK</p><p class="sf-hint">长按下面的网址可以复制，发给朋友。别人点开后 30 天内在本站下单，就算你推广的（以最后点的链接为准）。</p>'
        . '<p class="mono sel ref-url">' . enc_text(ref_link($p)) . '</p>';
    $live = array_values(array_filter($products, fn($x) => shop_product_live($x)));
    if ($live) {
        $h .= '<details class="ref-more"><summary>单个商品的链接（' . count($live) . ' 件）</summary><ul class="ref-links">';
        foreach (array_slice($live, 0, SHOP_PRODUCTS_MAX) as $x) {
            $h .= '<li><span>' . enc_text($x['name']) . '</span><span class="mono sel ref-url">' . enc_text(ref_link($p, 'shop/' . $x['slug'] . '.html')) . '</span></li>';
        }
        $h .= '</ul></details>';
    }
    $h .= '</section>' . "\n";
    $h .= '<dl class="sum">' . $row('点击', (string) $sum['clicks']) . $row('付款订单', (string) $sum['orders']) . $row('成交额（商品金额）', '¥' . shop_money($sum['sales']))
        . $row('待确认佣金', '¥' . shop_money($sum['pending'])) . $row('可结算佣金', ref_money_signed($sum['settlable']), 'sum-total') . $row('已结算', '¥' . shop_money($sum['settled'])) . '</dl>' . "\n";
    $h .= '<p class="sf-hint">待确认：买家已经付款，订单还没完成。订单完成以后变成可结算。退款的订单按退款后的金额算，已经结算过的会在下次结算里扣回。佣金由店主私下转给你，本站不经手钱。</p>' . "\n";
    $months = ref_months($sum);
    if ($months) {
        $h .= '<h2 class="ref-h">按月</h2>' . "\n";
        foreach (array_slice($months, 0, 24, true) as $k => $m) {
            $h .= '<dl class="sum ref-month"><div class="sum-total"><dt>' . enc_text(str_replace('-', ' 年 ', $k) . ' 月') . '</dt><dd>' . enc_text($m['orders'] . ' 单，¥' . shop_money($m['sales'])) . '</dd></div>'
                . $row('待确认', '¥' . shop_money($m['pending'])) . $row('可结算', ref_money_signed($m['due'])) . $row('已结算', ref_money_signed($m['settled'])) . '</dl>' . "\n";
        }
    }
    $lines = array_slice($sum['lines'], 0, 50);
    if ($lines) {
        $h .= '<h2 class="ref-h">最近的订单</h2><p class="sf-hint">订单号只显示一部分，不显示买家的任何信息。</p><ul class="ref-orders">' . "\n";
        foreach ($lines as $l) {
            $o = $l['o'];
            $h .= '<li><span class="mono">' . enc_text(ref_mask_order($o['id'])) . '</span><span>' . enc_text(local_time((string) $o['created_at'], 'n月j日') . ' ' . $o['name'] . ' × ' . (int) $o['qty']) . '</span>'
                . '<span class="mono">' . enc_text('¥' . shop_money((int) $o['subtotal']) . ' → 佣金 ¥' . shop_money($l['c']['amount'])) . '</span>'
                . '<span class="ref-st">' . enc_text(ref_line_label($l)) . '</span></li>' . "\n";
        }
        $h .= '</ul>' . "\n";
    }
    $h .= '<h2 class="ref-h">结算记录</h2>' . "\n";
    if (!$settlements) {
        $h .= '<p class="sf-hint">还没有结算过。</p>' . "\n";
    } else {
        $h .= '<dl class="sum">';
        foreach (array_reverse($settlements) as $s) {
            $h .= $row(local_time($s['at'], 'Y年n月j日') . '（' . count($s['items']) . ' 单）' . ($s['note'] !== '' ? '：' . $s['note'] : ''), '¥' . shop_money($s['amount']));
        }
        $h .= '</dl>' . "\n";
    }
    $h .= '<p class="sf-hint">只有一级推广：你推荐来的人再去推广，不算你的。有疑问直接问店主。</p>' . "\n"
        . '<p class="back"><a class="tlink" href="../">去网站首页</a></p>' . "\n"
        . '</div>';
    return $h;
}
