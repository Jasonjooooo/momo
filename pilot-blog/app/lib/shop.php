<?php
// 商城 (SPEC §20): products, orders matched to transfers by a unique payable amount, stock reserved under
// one flock, lazy expiry / auto-complete / redaction, tokens for the pay page and digital downloads, the
// owner's 收款码 images and the orders CSV. Buyer data and digital files live only in blog-data/shop/.
declare(strict_types=1);
defined('BLOG_APP') || exit;

const SHOP_TYPES = ['physical' => '实物', 'digital' => '数字', 'supply' => '自动发卡'];   // supply: a card bought from the 货源 (supply.php)
const SHOP_STATUS = ['pending' => '待付款', 'claimed' => '买家已付款，待确认', 'confirmed' => '已确认收款', 'shipped' => '已发货', 'completed' => '已完成', 'cancelled' => '已取消', 'failed' => '进货失败'];
const SHOP_UNPAID = ['pending', 'claimed'];       // open orders whose payable amount must be unique
const ORDER_ID_RE = '/^\d{8}-[A-HJ-KM-NP-Z2-9]{6}$/';
const PRODUCT_ID_RE = '/^g[0-9a-f]{10}$/';
const SHOP_FILE_ID_RE = '/^[0-9a-f]{32}$/';
const SHOP_QR_RE = '#^uploads/shop/(wechat|alipay)-[0-9a-f]{16}\.png$#';
const SHOP_ID_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
const SHOP_PRICE_MAX = 9999999;       // ¥99 999.99, in fen
const SHOP_SHIPPING_MAX = 100000;     // ¥1 000
const SHOP_QTY_MAX = 20;
const SHOP_STOCK_MAX = 99999;
const SHOP_PRODUCTS_MAX = 60;
const SHOP_PHOTOS_MAX = 8;
const SHOP_OPEN_CAP = 200;            // unpaid orders site-wide (a flood cannot reserve everything forever)
const SHOP_ORDERS_CAP = 20000;
const SHOP_REDACT_DAYS = 180;
const SHOP_AUTOCOMPLETE_DAYS = 30;
const SHOP_LOW_STOCK = 5;
const SHOP_FILE_MAX = 20 * 1024 * 1024;
const SHOP_FILE_EXT = ['pdf', 'zip', 'epub', 'txt', 'png', 'jpg', 'jpeg', 'webp', 'mp3', 'm4a', 'mp4', 'docx', 'xlsx', 'pptx', 'csv'];
const SHOP_DL_TTL = 1800;             // a download link works for 30 minutes

/** A buyer- or owner-facing refusal with a plain Chinese message. */
final class ShopError extends RuntimeException
{
    public string $code_;
    public array $fields;

    public function __construct(string $code, string $message, array $fields = [])
    {
        $this->code_ = $code;
        $this->fields = $fields;
        parent::__construct($message);
    }
}

// ---------- money ----------

/** 9903 → '99.03' */
function shop_money(int $fen): string
{
    return intdiv($fen, 100) . '.' . str_pad((string) ($fen % 100), 2, '0', STR_PAD_LEFT);
}

/** '99', '99.5', '99.03', '９９.０３' → fen; null when not a plain amount. */
function shop_parse_money(string $s): ?int
{
    $s = str_replace(['¥', '￥', '元', ' ', '，', ','], '', ascii_numbers($s));
    $s = str_replace('。', '.', $s);
    if (!preg_match('/^(\d{1,7})(?:\.(\d{1,2}))?$/', $s, $m)) {
        return null;
    }
    return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
}

/** Full-width digits and dots (typed with a Chinese IME) as ASCII. */
function ascii_numbers(string $s): string
{
    return trim((string) preg_replace_callback('/[\x{FF10}-\x{FF19}\x{FF0E}]/u', fn($m) => $m[0] === '．' ? '.' : (string) (mb_ord($m[0]) - 0xFF10), $s));
}

// ---------- storage ----------

function shop_dir(string ...$parts): string
{
    return data_path('shop', ...$parts);
}

function shop_products_path(): string
{
    return shop_dir('products.json');
}

function shop_orders_path(): string
{
    return shop_dir('orders.json');
}

function shop_ensure_dirs(): void
{
    foreach (['shop', 'shop/files', 'shop/qr'] as $d) {
        $p = DATA_DIR . '/' . $d;
        if (!is_dir($p) && !@mkdir($p, 0700, true) && !is_dir($p)) {
            throw new RuntimeException('cannot create ' . $d);
        }
    }
}

function shop_products_doc(): array
{
    $d = json_read(shop_products_path(), []);
    $d = is_array($d) ? $d : [];
    $items = [];
    foreach ((is_array($d['items'] ?? null) ? $d['items'] : []) as $p) {
        if (is_array($p) && preg_match(PRODUCT_ID_RE, (string) ($p['id'] ?? ''))) {
            $items[] = shop_product_normalize($p);
        }
    }
    return ['rev' => (int) ($d['rev'] ?? 0), 'items' => $items];
}

/** Products in the owner's order. */
function shop_products(): array
{
    return shop_products_doc()['items'];
}

/** Canonical product shape (migrate on read: missing keys get defaults). */
function shop_product_normalize(array $p): array
{
    $type = in_array($p['type'] ?? '', ['digital', 'supply'], true) ? $p['type'] : 'physical';
    $photos = [];
    foreach ((is_array($p['photos'] ?? null) ? $p['photos'] : []) as $ph) {
        if (is_array($ph) && is_string($ph['src'] ?? null) && preg_match(UPLOAD_REL_RE, $ph['src'])) {
            $photos[] = ['src' => $ph['src'], 'alt' => is_string($ph['alt'] ?? null) ? $ph['alt'] : ''];
        }
    }
    $dl = is_array($p['delivery'] ?? null) ? $p['delivery'] : [];
    $file = is_array($dl['file'] ?? null) && preg_match(SHOP_FILE_ID_RE, (string) ($dl['file']['id'] ?? '')) ? [
        'id' => $dl['file']['id'],
        'name' => (string) ($dl['file']['name'] ?? 'file'),
        'bytes' => (int) ($dl['file']['bytes'] ?? 0),
        'at' => (string) ($dl['file']['at'] ?? ''),
    ] : null;
    $stock = $p['stock'] ?? null;
    return [
        'id' => (string) $p['id'],
        'slug' => (string) ($p['slug'] ?? ''),
        'name' => (string) ($p['name'] ?? ''),
        'type' => $type,
        'price' => max(0, (int) ($p['price'] ?? 0)),
        'cost' => $type !== 'supply' && is_int($p['cost'] ?? null) && $p['cost'] >= 0 ? $p['cost'] : null,   // 成本价 (owner's, optional; null = not filled in)
        'stock' => $stock === null ? null : max(0, (int) $stock),
        'on_sale' => (bool) ($p['on_sale'] ?? false),
        'summary' => (string) ($p['summary'] ?? ''),
        'body' => (string) ($p['body'] ?? ''),
        'photos' => $photos,
        'delivery' => ['text' => (string) ($dl['text'] ?? ''), 'link' => (string) ($dl['link'] ?? ''), 'file' => $file],
        'supply' => $type === 'supply' ? supply_link_normalize($p['supply'] ?? null) : null,
        'cat' => is_string($p['cat'] ?? null) && preg_match(SHOP_CAT_ID_RE, $p['cat']) ? $p['cat'] : '',   // '' = 不分类 (products saved before categories)
        'rev' => (int) ($p['rev'] ?? 1),
        'created_at' => (string) ($p['created_at'] ?? now_iso()),
        'updated_at' => (string) ($p['updated_at'] ?? now_iso()),
    ];
}

function shop_product_get(string $id): ?array
{
    foreach (shop_products() as $p) {
        if ($p['id'] === $id) {
            return $p;
        }
    }
    return null;
}

function shop_product_by_slug(string $slug): ?array
{
    foreach (shop_products() as $p) {
        if ($p['slug'] === $slug) {
            return $p;
        }
    }
    return null;
}

/** Shown on the site and orderable: on sale, with a photo, and (cards from the 货源) the 货源 switched on. */
function shop_product_live(array $p, ?array $settings = null): bool
{
    return $p['on_sale'] && $p['photos'] && ($p['type'] !== 'supply' || supply_settings($settings)['enabled']);
}

function shop_orders(): array
{
    $d = json_read(shop_orders_path(), []);
    $items = is_array($d['items'] ?? null) ? $d['items'] : [];
    return array_values(array_filter($items, fn($o) => is_array($o) && preg_match(ORDER_ID_RE, (string) ($o['id'] ?? ''))));
}

function shop_order_get(string $id): ?array
{
    if (!preg_match(ORDER_ID_RE, $id)) {
        return null;
    }
    foreach (shop_orders() as $o) {
        if ($o['id'] === $id) {
            return $o;
        }
    }
    return null;
}

/**
 * Change products and orders together under the one shop lock (no overselling, no duplicate amounts).
 * $fn(array &$products, array &$orders) returns any value; both files are written when changed.
 * Products are written first: if the second write fails, a unit of stock is lost, never sold twice.
 */
function shop_mutate(callable $fn): mixed
{
    shop_ensure_dirs();
    return with_lock('shop', function () use ($fn) {
        $pdoc = shop_products_doc();
        $products = $pdoc['items'];
        $orders = shop_orders();
        $p0 = $products;
        $o0 = $orders;
        $res = $fn($products, $orders);
        if ($products !== $p0) {
            json_write_atomic(shop_products_path(), ['rev' => $pdoc['rev'] + 1, 'updated_at' => now_iso(), 'items' => array_values($products)]);
        }
        if ($orders !== $o0) {
            json_write_atomic(shop_orders_path(), ['updated_at' => now_iso(), 'items' => array_values($orders)]);
        }
        return $res;
    });
}

// ---------- availability (what the static pages show) ----------

/** 'unlimited' | 'in' | 'low' | 'out' for a product as shown on the home grid and product page. */
function shop_avail(array $p): string
{
    if ($p['stock'] === null) {
        return 'unlimited';
    }
    if ($p['stock'] <= 0) {
        return 'out';
    }
    return $p['stock'] <= SHOP_LOW_STOCK ? 'low' : 'in';
}

/** Short label: 有货 / 仅剩 3 件 / 售罄 (null for an unlimited digital product). */
function shop_avail_label(array $p): ?string
{
    return match (shop_avail($p)) {
        'unlimited' => null,
        'in' => '有货',
        'low' => '仅剩 ' . $p['stock'] . ' 件',
        'out' => '售罄',
    };
}

/** What the published pages show for every product; when it changes, the pages are regenerated. */
function shop_page_signature(array $products): string
{
    $sig = [];
    foreach ($products as $p) {
        $sig[] = [$p['id'], $p['on_sale'], shop_avail_label($p)];
    }
    return hash('sha256', json_encode($sig, JSON_UNESCAPED_UNICODE));
}

/** Regenerate the site (no version entry) when stock moved a label on the published pages. */
function shop_republish_if_changed(string $before): void
{
    if (shop_page_signature(shop_products()) === $before) {
        return;
    }
    try {
        $r = publish_site('商城库存变化', ['version' => false]);
        if (!$r['ok']) {
            error_log('shop republish failed');
        }
    } catch (Throwable $e) {
        error_log('shop republish: ' . get_class($e));
    }
}

// ---------- settings ----------

function shop_settings(?array $settings = null): array
{
    return ($settings ?? settings_get())['shop'];
}

function shop_open(?array $settings = null): bool
{
    return (bool) shop_settings($settings)['enabled'];
}

/** Shipping for an order: flat per order for physical goods, free over a threshold when set. */
function shop_shipping_for(string $type, int $subtotal, array $ss): int
{
    if ($type !== 'physical') {
        return 0;
    }
    if ($ss['free_over'] > 0 && $subtotal >= $ss['free_over']) {
        return 0;
    }
    return (int) $ss['shipping'];
}

// ---------- secrets, tokens ----------

function shop_hmac(string $msg): string
{
    return hash_hmac('sha256', $msg, secret_value('shop_secret'));
}

/** The pay page link carries this; without it nobody can open another buyer's pay page. */
function shop_pay_token(string $orderId): string
{
    return substr(shop_hmac('pay|' . $orderId), 0, 32);
}

function shop_pay_token_ok(string $orderId, string $k): bool
{
    return strlen($k) === 32 && hash_equals(shop_pay_token($orderId), $k);
}

/** Lookup secret: last 4 phone digits (physical) or the email part before @ (digital), case-folded. */
function shop_verify_hash(string $orderId, string $secret): string
{
    return shop_hmac('verify|' . $orderId . '|' . mb_strtolower(trim($secret)));
}

/** Normalize what the buyer typed on the lookup page. */
function shop_verify_input(string $s): string
{
    $s = mb_strtolower(trim(ascii_numbers($s)));
    if (str_contains($s, '@')) {
        $s = substr($s, 0, (int) strpos($s, '@'));   // a whole email works too
    }
    return $s;
}

/** What the typed lookup secret may stand for: itself, and the last 4 digits when a whole phone number was typed
 *  (a QQ mailbox like 12345678@qq.com keeps its all-digit local part as the first candidate). */
function shop_verify_candidates(string $typed): array
{
    $s = shop_verify_input($typed);
    $c = [$s];
    $digits = (string) preg_replace('/[\s\-]/', '', $s);
    if (preg_match('/^\+?\d{5,15}$/', $digits)) {
        $c[] = substr($digits, -4);
    }
    return array_values(array_unique(array_filter($c, fn($v) => $v !== '')));
}

/** A signed, short-lived download link for an order's file. */
function shop_dl_sig(string $orderId, int $exp, string $fileId): string
{
    return substr(shop_hmac('dl|' . $orderId . '|' . $exp . '|' . $fileId), 0, 40);
}

/** Stateless token for the public forms: issue time + HMAC. Valid from 2 s to 6 h after it was issued. */
function shop_form_token(string $purpose, ?int $now = null): string
{
    $t = (string) ($now ?? time());
    return $t . '.' . substr(shop_hmac('form|' . $purpose . '|' . $t), 0, 32);
}

/** 'ok' | 'fast' | 'bad' */
function shop_form_token_check(string $purpose, string $tok, ?int $now = null): string
{
    $now ??= time();
    if (!preg_match('/^(\d{9,11})\.([0-9a-f]{32})$/', $tok, $m)) {
        return 'bad';
    }
    $t = (int) $m[1];
    if (!hash_equals(substr(shop_hmac('form|' . $purpose . '|' . $m[1]), 0, 32), $m[2]) || $t > $now + 5 || $now - $t > 6 * 3600) {
        return 'bad';
    }
    return $now - $t < 2 ? 'fast' : 'ok';
}

// ---------- buyer input ----------

/** Mainland mobile (11 digits starting 1) or a Hong Kong / Macau number; digits only, or null. */
function shop_phone_normalize(string $raw): ?string
{
    $d = (string) preg_replace('/[\s\-()（）]/u', '', ascii_numbers($raw));
    $d = (string) preg_replace('/^(\+|00)/', '', $d);
    if (preg_match('/^(?:86)?(1[3-9]\d{9})$/', $d, $m)) {
        return $m[1];
    }
    if (preg_match('/^(85[23]\d{8})$/', $d, $m)) {
        return $m[1];
    }
    if (preg_match('/^([2-9]\d{7})$/', $d, $m)) {
        return $m[1];
    }
    return null;
}

/** One line of buyer text: trimmed, control characters and line breaks removed. */
function shop_clean_line(mixed $v, int $max): string
{
    if (!is_string($v) || !mb_check_encoding($v, 'UTF-8')) {
        return '';
    }
    $v = (string) preg_replace('/[\x00-\x1F\x7F\x{2028}\x{2029}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', ' ', $v);
    $v = trim((string) preg_replace('/\s{2,}/u', ' ', $v));
    return mb_substr($v, 0, $max + 1);   // one over the limit, so the length check still fires
}

/**
 * Validate the order form. Returns [clean, errors]. $in: qty, name, phone, address, email, note, consent.
 */
function shop_order_input(array $product, array $in): array
{
    $e = [];
    [$qMin, $qMax] = ($product['type'] ?? '') === 'supply' ? supply_qty_bounds($product) : [1, SHOP_QTY_MAX];
    $qtyRaw = ascii_numbers(is_string($in['qty'] ?? null) ? $in['qty'] : (string) $qMin);
    $qty = ctype_digit($qtyRaw) ? (int) $qtyRaw : 0;
    if ($qty < $qMin || $qty > $qMax) {
        $e['qty'] = $qMin === $qMax ? '这件商品每单只能买 ' . $qMin . ' 件。' : '数量请填 ' . $qMin . ' 到 ' . $qMax . '。';
    }
    $c = ['qty' => $qty, 'name' => '', 'phone' => '', 'address' => '', 'email' => '', 'note' => shop_clean_line($in['note'] ?? '', 100)];
    if ($product['type'] === 'physical') {
        $c['name'] = shop_clean_line($in['name'] ?? '', 20);
        $c['address'] = shop_clean_line($in['address'] ?? '', 120);
        $phone = shop_phone_normalize(is_string($in['phone'] ?? null) ? $in['phone'] : '');
        if ($c['name'] === '') {
            $e['name'] = '请填收货人。';
        } elseif (mb_strlen($c['name']) > 20) {
            $e['name'] = '收货人最多 20 个字。';
        }
        if ($phone === null) {
            $e['phone'] = '手机号格式不对，请填 11 位手机号。';
        } else {
            $c['phone'] = $phone;
        }
        if (mb_strlen($c['address']) < 5) {
            $e['address'] = '请填完整的收货地址（省市区、街道和门牌号）。';
        } elseif (mb_strlen($c['address']) > 120) {
            $e['address'] = '地址最多 120 个字。';
        }
    } else {
        $email = email_normalize(is_string($in['email'] ?? null) ? mb_substr($in['email'], 0, 300) : '');
        if ($email === null) {
            $e['email'] = '邮箱格式不对，请再检查一遍。';
        } else {
            $c['email'] = $email;
        }
    }
    if (mb_strlen($c['note']) > 100) {
        $e['note'] = '备注最多 100 个字。';
    }
    if (($in['consent'] ?? '') !== '1') {
        $e['consent'] = '请先勾选同意，再提交订单。';
    }
    return [$c, $e];
}

// ---------- orders ----------

function shop_new_order_id(array $orders, int $now): string
{
    $taken = array_flip(array_map(fn($o) => $o['id'], $orders));
    do {
        $r = '';
        for ($i = 0; $i < 6; $i++) {
            $r .= SHOP_ID_ALPHABET[random_int(0, strlen(SHOP_ID_ALPHABET) - 1)];
        }
        $id = str_replace('-', '', local_day($now)) . '-' . $r;
    } while (isset($taken[$id]));
    return $id;
}

/**
 * The few fen added to an order's total so that its payable amount differs from every other unpaid order:
 * the owner matches a transfer to an order by its exact amount. Random among the free values 1..99 fen,
 * then 1.00..1.99 when a hundred unpaid orders share one total. Null when nothing is free.
 */
function shop_pick_offset(int $base, array $orders): ?int
{
    $used = [];
    foreach ($orders as $o) {
        if (in_array($o['status'], SHOP_UNPAID, true)) {
            $used[(int) $o['amount']] = true;
        }
    }
    foreach ([[1, 99], [100, 199]] as [$lo, $hi]) {
        $free = [];
        for ($k = $lo; $k <= $hi; $k++) {
            if (!isset($used[$base + $k])) {
                $free[] = $k;
            }
        }
        if ($free) {
            return $free[random_int(0, count($free) - 1)];
        }
    }
    return null;
}

function shop_hist(array &$o, string $status, string $by, string $note = '', ?int $now = null): void
{
    $o['history'][] = ['t' => gmdate('Y-m-d\TH:i:s\Z', $now ?? time()), 's' => $status, 'by' => $by, 'note' => $note];
}

/** Put an order's held stock back (cancel). */
function shop_release(array &$o, array &$products): void
{
    if (empty($o['held'])) {
        return;
    }
    foreach ($products as &$p) {
        if ($p['id'] === $o['product_id'] && $p['stock'] !== null) {
            $p['stock'] = min(SHOP_STOCK_MAX, $p['stock'] + (int) $o['qty']);
        }
    }
    unset($p);
    $o['held'] = false;
}

/** The amount written in a refund note (「已退 ¥10 差价」「退了 99.03 元」), for refunds recorded without the amount field. */
function shop_refund_amount_in(string $note): ?int
{
    $n = ascii_numbers($note);
    if (preg_match('/[¥￥]\s*(\d{1,7}(?:\.\d{1,2})?)/u', $n, $m) || preg_match('/(\d{1,7}(?:\.\d{1,2})?)\s*元/u', $n, $m)) {
        $v = shop_parse_money($m[1]);
        return $v !== null && $v > 0 ? $v : null;
    }
    return null;
}

/** Refunds recorded on an order: ['fen' => sum of known amounts, 'n' => count, 'unknown' => count without an amount]. */
function shop_refunded(array $o): array
{
    $r = ['fen' => 0, 'n' => 0, 'unknown' => 0];
    foreach (is_array($o['refunds'] ?? null) ? $o['refunds'] : [] as $x) {
        $r['n']++;
        $amt = array_key_exists('amount', $x) ? $x['amount'] : shop_refund_amount_in((string) ($x['note'] ?? ''));
        if (is_int($amt) && $amt > 0) {
            $r['fen'] += $amt;
        } else {
            $r['unknown']++;
        }
    }
    return $r;
}

/**
 * Create an order: server-side price, shipping and payable amount, stock reserved under the shop lock.
 * $c: the cleaned form (shop_order_input). Returns the order. Throws ShopError.
 */
function shop_create_order(string $slug, array $c, ?int $now = null, ?array $partner = null): array
{
    $now ??= time();
    $settings = settings_get();
    $ss = $settings['shop'];
    $o = shop_mutate(function (array &$products, array &$orders) use ($slug, $c, $now, $ss, $settings, $partner) {
        shop_maintain_in($products, $orders, $now, $ss);
        if (!$ss['enabled']) {
            throw new ShopError('closed', '商城暂停营业，这次没有下单。');
        }
        $idx = null;
        foreach ($products as $i => $p) {
            if ($p['slug'] === $slug && shop_product_live($p, $settings)) {
                $idx = $i;
            }
        }
        if ($idx === null) {
            throw new ShopError('gone', '这件商品已经下架了。');
        }
        $p = $products[$idx];
        $supply = $p['type'] === 'supply';
        if ($supply && ($p['supply']['code'] === '' || !supply_ready($settings))) {
            throw new ShopError('closed', '这件商品暂时不能买，过一会儿再来看看。');
        }
        if ($p['stock'] !== null && $p['stock'] < $c['qty']) {
            throw new ShopError('stock', $p['stock'] <= 0 ? '这件商品刚刚卖完了。' : '库存不够了，现在最多能买 ' . $p['stock'] . ' 件。', ['qty' => '现在最多能买 ' . $p['stock'] . ' 件。']);
        }
        $open = count(array_filter($orders, fn($o) => in_array($o['status'], SHOP_UNPAID, true)));
        if ($open >= SHOP_OPEN_CAP || count($orders) >= SHOP_ORDERS_CAP) {
            throw new ShopError('busy', '现在等待付款的订单太多了，请过一会儿再下单。');
        }
        $subtotal = $p['price'] * $c['qty'];
        $shipping = shop_shipping_for($p['type'], $subtotal, $ss);
        $offset = shop_pick_offset($subtotal + $shipping, $orders);
        if ($offset === null) {
            throw new ShopError('busy', '现在等待付款的订单太多了，请过一会儿再下单。');
        }
        $id = shop_new_order_id($orders, $now);
        $secret = $p['type'] === 'physical' ? substr($c['phone'], -4) : substr($c['email'], 0, (int) strpos($c['email'], '@'));
        $o = [
            'id' => $id,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
            'status' => 'pending',
            'product_id' => $p['id'],
            'slug' => $p['slug'],
            'name' => $p['name'],
            'type' => $p['type'],
            'price' => $p['price'],
            'cost_unit' => $supply ? null : $p['cost'],   // 成本价 at order time (经营总览 毛利); a card's cost is what the 货源 charged
            'qty' => $c['qty'],
            'subtotal' => $subtotal,
            'shipping' => $shipping,
            'offset' => $offset,
            'amount' => $subtotal + $shipping + $offset,
            'buyer' => ['name' => $c['name'], 'phone' => $c['phone'], 'address' => $c['address'], 'email' => $c['email']],
            'note' => $c['note'],
            'payer' => '',
            'verify' => shop_verify_hash($id, $secret),
            'held' => $p['stock'] !== null && !$supply,   // a card's stock is the supplier's: nothing to reserve here
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $now + 3600 * (int) $ss['cancel_hours']),
            'history' => [],
            'courier' => '',
            'tracking' => '',
            'cancel_reason' => '',
            'cancel_auto' => false,
            'refunds' => [],
            'closed_at' => null,
            'redacted_at' => null,
        ];
        if ($supply) {
            $o['supply'] = supply_order_init($p, $c['email']);
        }
        if ($partner !== null) {
            $o['ref'] = ref_order_stamp($partner, $c);   // 推广: the code from the buyer's cookie, the rate as it is now
        }
        shop_hist($o, 'pending', 'buyer', '', $now);
        if ($p['stock'] !== null && !$supply) {
            $products[$idx]['stock'] = $p['stock'] - $c['qty'];
        }
        $orders[] = $o;
        return $o;
    });
    notify_order('order', $o);   // 微信提醒 (never throws; sent after the response)
    return $o;
}

/** The buyer taps 我已付款 (pending → claimed). Returns the order. */
function shop_claim(string $id, string $payer, ?int $now = null): array
{
    $now ??= time();
    $ss = shop_settings();
    $claimed = false;
    $o = shop_mutate(function (array &$products, array &$orders) use ($id, $payer, $now, $ss, &$claimed) {
        shop_maintain_in($products, $orders, $now, $ss);
        foreach ($orders as &$o) {
            if ($o['id'] !== $id) {
                continue;
            }
            if ($o['status'] === 'pending') {
                $o['status'] = 'claimed';
                $o['payer'] = shop_clean_line($payer, 20);
                shop_hist($o, 'claimed', 'buyer', '', $now);
                $claimed = true;
            }
            return $o;
        }
        unset($o);
        throw new ShopError('gone', '找不到这个订单。');
    });
    if ($claimed) {
        notify_order('claim', $o);   // the owner must check the payment by hand
    }
    return $o;
}

/**
 * Owner actions. $act: confirm | ship | complete | cancel | refund. $args: courier, tracking, reason, note.
 * Returns the order. Throws ShopError with a Chinese message for an action that does not fit the status.
 */
function shop_owner_action(string $id, string $act, array $args = [], ?int $now = null): array
{
    $now ??= time();
    $ss = shop_settings();
    return shop_mutate(function (array &$products, array &$orders) use ($id, $act, $args, $now, $ss) {
        shop_maintain_in($products, $orders, $now, $ss);
        $i = null;
        foreach ($orders as $k => $o) {
            if ($o['id'] === $id) {
                $i = $k;
            }
        }
        if ($i === null) {
            throw new ShopError('gone', '找不到这个订单。');
        }
        $o = $orders[$i];
        $st = $o['status'];
        $stamp = gmdate('Y-m-d\TH:i:s\Z', $now);
        switch ($act) {
            case 'confirm':
                if ($st === 'cancelled' && !empty($o['cancel_auto']) && $o['redacted_at'] === null) {
                    // paid after the automatic cancel: take the stock again if it is still there
                    foreach ($products as &$p) {
                        if ($p['id'] === $o['product_id'] && $p['stock'] !== null && $o['type'] !== 'supply') {
                            if ($p['stock'] < $o['qty']) {
                                throw new ShopError('stock', '库存不够，没法恢复这个订单。先在「商品」里把库存加上，再来确认收款。');
                            }
                            $p['stock'] -= $o['qty'];
                            $o['held'] = true;
                        }
                    }
                    unset($p);
                    $o['cancel_auto'] = false;
                    $o['cancel_reason'] = '';
                    $o['closed_at'] = null;
                } elseif (!in_array($st, SHOP_UNPAID, true)) {
                    throw new ShopError('state', '这个订单现在是「' . SHOP_STATUS[$st] . '」，不能再确认收款。');
                }
                $o['status'] = 'confirmed';
                shop_hist($o, 'confirmed', 'owner', '', $now);
                // a card from the 货源 stays 'confirmed' here: supply_fulfill() buys it right after and completes it
                if ($o['type'] === 'digital') {
                    // nothing to ship: the lookup page shows the content from now on
                    $o['status'] = 'completed';
                    $o['closed_at'] = $stamp;
                    shop_hist($o, 'completed', 'owner', '', $now);
                }
                break;
            case 'ship':
                if ($o['type'] !== 'physical' || !in_array($st, ['confirmed', 'shipped'], true)) {
                    throw new ShopError('state', $o['type'] !== 'physical' ? ($o['type'] === 'supply' ? '自动发卡的订单不用发快递。' : '数字商品不用发货。') : '先确认收款，再发货。');
                }
                $courier = shop_clean_line($args['courier'] ?? '', 20);
                $tracking = shop_clean_line($args['tracking'] ?? '', 40);
                if ($courier === '' || mb_strlen($courier) > 20) {
                    throw new ShopError('invalid', '请填快递公司（20 个字以内）。', ['courier' => '请填快递公司。']);
                }
                if (!preg_match('/^[A-Za-z0-9\-]{4,40}$/', $tracking)) {
                    throw new ShopError('invalid', '快递单号只能是字母、数字和短横线，4 到 40 位。', ['tracking' => '快递单号格式不对。']);
                }
                $o['courier'] = $courier;
                $o['tracking'] = $tracking;
                if ($st === 'confirmed') {
                    $o['status'] = 'shipped';
                    shop_hist($o, 'shipped', 'owner', '', $now);
                } else {
                    shop_hist($o, 'shipped', 'owner', '改了快递信息', $now);
                }
                break;
            case 'complete':
                if (!in_array($st, ['shipped', 'confirmed'], true)) {
                    throw new ShopError('state', '这个订单现在是「' . SHOP_STATUS[$st] . '」，不能标记完成。');
                }
                if ($st === 'confirmed' && $o['type'] === 'physical') {
                    throw new ShopError('state', '实物订单先填快递并发货，再标记完成。');
                }
                if ($o['type'] === 'supply') {
                    throw new ShopError('state', '自动发卡的订单在卡密发出以后自己完成。');
                }
                $o['status'] = 'completed';
                $o['closed_at'] = $stamp;
                shop_hist($o, 'completed', 'owner', '', $now);
                break;
            case 'cancel':
                if ($o['type'] === 'supply' && (($o['supply']['trade_no'] ?? '') !== '' || ($o['supply']['state'] ?? '') === 'trading')) {
                    throw new ShopError('state', ($o['supply']['state'] ?? '') === 'trading'
                        ? '这个订单正在进货，等一会儿刷新页面再看。'
                        : '这个订单已经从货源买到了卡（花了你的货源余额），不能取消。要退款的话，在微信或支付宝里退，再用「记录退款」写下来。');
                }
                if (!in_array($st, ['pending', 'claimed', 'confirmed', 'failed'], true)) {
                    throw new ShopError('state', $st === 'shipped' ? '已经发货的订单不能取消。需要退款的话，用「记录退款」写下来。' : '这个订单现在是「' . SHOP_STATUS[$st] . '」，不能取消。');
                }
                $reason = shop_clean_line($args['reason'] ?? '', 60);
                if ($reason === '' || mb_strlen($reason) > 60) {
                    throw new ShopError('invalid', '请写一句取消原因（60 个字以内），买家查订单时会看到。', ['reason' => '请写取消原因。']);
                }
                shop_release($o, $products);
                $o['status'] = 'cancelled';
                $o['cancel_reason'] = $reason;
                $o['cancel_auto'] = false;
                $o['closed_at'] = $stamp;
                shop_hist($o, 'cancelled', 'owner', $reason, $now);
                break;
            case 'refund':
                $note = shop_clean_line($args['note'] ?? '', 100);
                if ($note === '' || mb_strlen($note) > 100) {
                    throw new ShopError('invalid', '请写退款记录（100 个字以内），比如「已退 ¥99.03，微信原路退回」。', ['note' => '请写退款记录。']);
                }
                if ($st === 'pending') {
                    throw new ShopError('state', '还没付款的订单不用记录退款，直接取消就行。');
                }
                $amtRaw = trim((string) ($args['amount'] ?? ''));
                $amt = $amtRaw !== '' ? shop_parse_money($amtRaw) : shop_refund_amount_in($note);
                $left = (int) $o['amount'] - shop_refunded($o)['fen'];
                if ($amtRaw !== '' && ($amt === null || $amt < 1)) {
                    throw new ShopError('invalid', '退款金额请填像 99.03 这样的数字（元）。', ['amount' => '退款金额格式不对。']);
                }
                if ($amt !== null && $amt > $left) {
                    throw new ShopError('invalid', '退款金额比这个订单还没退的钱（¥' . shop_money(max(0, $left)) . '）还多，请再核对一下。', ['amount' => '退款金额太大了。']);
                }
                $o['refunds'][] = ['t' => $stamp, 'note' => $note, 'amount' => $amt];
                break;
            default:
                throw new ShopError('invalid', '没有这个操作。');
        }
        $orders[$i] = $o;
        return $o;
    });
}

// ---------- lazy maintenance: expiry, auto-complete, redaction ----------

/** Inside shop_mutate(): expire unpaid orders, auto-complete old shipments, redact closed orders. */
function shop_maintain_in(array &$products, array &$orders, int $now, array $ss): void
{
    foreach ($orders as &$o) {
        if ($o['status'] === 'pending' && strtotime((string) $o['expires_at']) <= $now) {
            shop_release($o, $products);
            $o['status'] = 'cancelled';
            $o['cancel_reason'] = '超过 ' . (int) round((strtotime((string) $o['expires_at']) - strtotime((string) $o['created_at'])) / 3600) . ' 小时没有付款，订单自动取消。';
            $o['cancel_auto'] = true;
            $o['closed_at'] = $o['expires_at'];
            shop_hist($o, 'cancelled', 'auto', $o['cancel_reason'], strtotime((string) $o['expires_at']));
        }
        if ($o['status'] === 'shipped') {
            $last = 0;
            foreach ($o['history'] as $h) {
                if ($h['s'] === 'shipped') {
                    $last = max($last, (int) strtotime((string) $h['t']));
                }
            }
            if ($last > 0 && $now - $last >= SHOP_AUTOCOMPLETE_DAYS * 86400) {
                $o['status'] = 'completed';
                $o['closed_at'] = gmdate('Y-m-d\TH:i:s\Z', $last + SHOP_AUTOCOMPLETE_DAYS * 86400);
                shop_hist($o, 'completed', 'auto', '发货 ' . SHOP_AUTOCOMPLETE_DAYS . ' 天后自动完成', $last + SHOP_AUTOCOMPLETE_DAYS * 86400);
            }
        }
        if (in_array($o['status'], ['completed', 'cancelled'], true) && $o['redacted_at'] === null && $o['closed_at'] !== null
            && $now - (int) strtotime((string) $o['closed_at']) >= SHOP_REDACT_DAYS * 86400) {
            shop_redact($o, $now);
        }
    }
    unset($o);
}

/** Remove the buyer's personal data; keep amounts, items, dates and the lookup hash. */
function shop_redact(array &$o, int $now): void
{
    $o['buyer'] = ['name' => '', 'phone' => '', 'address' => '', 'email' => ''];
    $o['note'] = '';
    $o['payer'] = '';
    $o['tracking'] = '';
    if (isset($o['supply']) && is_array($o['supply'])) {
        $o['supply']['secret'] = '';
        $o['supply']['contact'] = '';
    }
    $o['redacted_at'] = gmdate('Y-m-d\TH:i:s\Z', $now);
}

/** Run the lazy jobs; republish when stock released by an expiry flips a label. */
function shop_maintain(?int $now = null): void
{
    $now ??= time();
    if (!is_file(shop_orders_path())) {
        return;
    }
    $before = shop_page_signature(shop_products());
    $ss = shop_settings();
    shop_mutate(function (array &$products, array &$orders) use ($now, $ss) {
        shop_maintain_in($products, $orders, $now, $ss);
        return null;
    });
    shop_republish_if_changed($before);
}

// ---------- products (owner) ----------

/** Validate the product editor's fields. Returns [clean, errors]; $cur is the stored product. */
function shop_product_input(array $cur, array $in, array $all): array
{
    $e = [];
    $c = $cur;
    $c['name'] = shop_clean_line($in['name'] ?? '', 40);
    if ($c['name'] === '') {
        $e['name'] = '请填商品名称。';
    } elseif (mb_strlen($c['name']) > 40) {
        $e['name'] = '名称最多 40 个字。';
    }
    $slug = strtolower(trim(is_string($in['slug'] ?? null) ? $in['slug'] : ''));
    if (!preg_match(SLUG_RE, $slug) || strlen($slug) > 40 || $slug === 'index') {
        $e['slug'] = '网址只能用小写字母、数字和短横线，比如 pc12-poster。';
    } else {
        foreach ($all as $o) {
            if ($o['id'] !== $cur['id'] && $o['slug'] === $slug) {
                $e['slug'] = '这个网址已经被另一件商品用了。';
            }
        }
    }
    $c['slug'] = $slug;
    $supply = $cur['type'] === 'supply';
    $c['type'] = $supply ? 'supply' : (($in['type'] ?? '') === 'digital' ? 'digital' : 'physical');
    $price = shop_parse_money(is_string($in['price'] ?? null) ? $in['price'] : '');
    if ($price === null || $price < 1 || $price > SHOP_PRICE_MAX) {
        $e['price'] = '价格请填像 99 或 99.50 这样的数字（元），最多两位小数。';
    } else {
        $c['price'] = $price;
    }
    if (!$supply && array_key_exists('cost', $in)) {   // 成本价: optional; a caller that does not send it keeps it
        $costRaw = trim(is_string($in['cost']) ? $in['cost'] : '');
        $cost = $costRaw === '' ? null : shop_parse_money($costRaw);
        if ($costRaw !== '' && ($cost === null || $cost > SHOP_PRICE_MAX)) {
            $e['cost'] = '成本价请填像 35 或 35.50 这样的数字（元），不知道就空着。';
        } else {
            $c['cost'] = $cost;
        }
    }
    $unlimited = ($in['unlimited'] ?? '') === '1' && $c['type'] === 'digital';
    $stockRaw = ascii_numbers(is_string($in['stock'] ?? null) ? $in['stock'] : '');
    if ($supply) {
        $c['stock'] = $cur['stock'];   // the supplier's stock, refreshed from the 货源
    } elseif ($unlimited) {
        $c['stock'] = null;
    } elseif (!ctype_digit($stockRaw) || (int) $stockRaw > SHOP_STOCK_MAX) {
        $e['stock'] = '库存请填 0 到 ' . SHOP_STOCK_MAX . ' 的整数。';
    } else {
        $c['stock'] = (int) $stockRaw;
    }
    $c['on_sale'] = ($in['on_sale'] ?? '') === '1';
    if (array_key_exists('cat', $in)) {   // a caller that does not send the field keeps the category
        [$c['cat'], $catErr] = shop_cat_input($in['cat'], shop_cats());
        if ($catErr !== null) {
            $e['cat'] = $catErr;
        }
    }
    $c['summary'] = shop_clean_line($in['summary'] ?? '', 60);
    if (mb_strlen($c['summary']) > 60) {
        $e['summary'] = '一句话介绍最多 60 个字。';
    }
    $body = is_string($in['body'] ?? null) ? str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $in['body']) : '';
    if (!mb_check_encoding($body, 'UTF-8') || preg_match('/[\x00-\x09\x0B-\x1F\x7F]/', $body)) {
        $e['body'] = '介绍里有看不见的特殊字符，请删掉后再保存。';
    } elseif (mb_strlen($body) > 5000) {
        $e['body'] = '详细介绍最多 5000 个字。';
    }
    $c['body'] = trim($body);
    $text = is_string($in['dl_text'] ?? null) ? trim(str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $in['dl_text'])) : '';
    if (!mb_check_encoding($text, 'UTF-8') || preg_match('/[\x00-\x09\x0B-\x1F\x7F]/', $text)) {
        $e['dl_text'] = '发货内容里有看不见的特殊字符，请删掉后再保存。';
    } elseif (mb_strlen($text) > 2000) {
        $e['dl_text'] = '发货内容最多 2000 个字。';
    }
    $link = trim(is_string($in['dl_link'] ?? null) ? $in['dl_link'] : '');
    if ($link !== '' && (!shop_url_ok($link) || strlen($link) > 500)) {
        $e['dl_link'] = '链接要以 https:// 或 http:// 开头，像 https://pan.baidu.com/s/xxxx 这样。';
    }
    if ($supply) {
        unset($e['dl_text'], $e['dl_link']);   // the card itself is the delivery
        $text = '';
        $link = '';
    }
    $c['delivery']['text'] = $text;
    $c['delivery']['link'] = $link;
    foreach ($c['photos'] as $k => $ph) {
        $alt = shop_clean_line($in['photo_alt'][$k] ?? $ph['alt'], 80);
        $c['photos'][$k]['alt'] = mb_substr($alt, 0, 80);
    }
    if ($c['on_sale']) {
        if (!$c['photos']) {
            $e['on_sale'] = '上架前先传至少一张照片。';
        }
        if ($c['type'] === 'digital' && $text === '' && $link === '' && $c['delivery']['file'] === null) {
            $e['on_sale'] = '数字商品上架前，先填好买家付款后能看到的内容（文字、链接或文件）。';
        }
        if ($supply && !isset($e['price']) && $c['price'] < $c['supply']['cost']) {
            $e['price'] = '售价 ¥' . shop_money($c['price']) . ' 低于成本 ¥' . shop_money($c['supply']['cost']) . '，卖一单亏一单，不能上架。把售价调到成本以上，或者先不上架。';
        }
    }
    return [$c, $e];
}

function shop_url_ok(string $u): bool
{
    $p = parse_url($u);
    return is_array($p) && in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true) && ($p['host'] ?? '') !== ''
        && !preg_match('/[\s<>"\'`]/', $u);
}

function shop_product_new(): array
{
    return shop_mutate(function (array &$products) {
        if (count($products) >= SHOP_PRODUCTS_MAX) {
            throw new ShopError('full', '商品最多 ' . SHOP_PRODUCTS_MAX . ' 件，先删掉不卖的再加。');
        }
        $taken = array_flip(array_map(fn($p) => $p['slug'], $products));
        do {
            $id = 'g' . bin2hex(random_bytes(5));
            $slug = 'item-' . substr($id, 1, 6);
        } while (isset($taken[$slug]));
        $p = shop_product_normalize(['id' => $id, 'slug' => $slug, 'name' => '', 'type' => 'physical', 'price' => 0, 'stock' => 0, 'on_sale' => false]);
        $products[] = $p;
        return $p;
    });
}

/**
 * Save the editor. $stockSeen is the stock the form showed: if orders changed it meanwhile and the owner
 * left the field alone, the current stock is kept; if the owner typed a new value over a changed one, refuse.
 */
function shop_product_save(string $id, array $in, int $rev): array
{
    $before = shop_page_signature(shop_products());
    $res = shop_mutate(function (array &$products) use ($id, $in, $rev) {
        foreach ($products as $k => $cur) {
            if ($cur['id'] !== $id) {
                continue;
            }
            if ($cur['rev'] !== $rev) {
                throw new ShopError('conflict', '这件商品在别处改过了（可能是另一个窗口）。刷新页面后再改。');
            }
            [$c, $e] = shop_product_input($cur, $in, $products);
            $seenRaw = ascii_numbers(is_string($in['stock_seen'] ?? null) ? $in['stock_seen'] : '');
            $seen = $seenRaw === '' ? null : (int) $seenRaw;
            if (!$e && $c['type'] !== 'supply' && $c['stock'] !== null && $cur['stock'] !== null && $seen !== null && $cur['stock'] !== $seen) {
                if ($c['stock'] === $seen) {
                    $c['stock'] = $cur['stock'];   // untouched field: keep what orders left
                } else {
                    $e['stock'] = '库存刚刚因为有人下单或取消变成了 ' . $cur['stock'] . '。请按现在的数重新填。';
                }
            }
            if ($e) {
                throw new ShopError('invalid', (string) reset($e), $e);
            }
            $c['rev'] = $cur['rev'] + 1;
            $c['updated_at'] = now_iso();
            $products[$k] = $c;
            return $c;
        }
        throw new ShopError('gone', '找不到这件商品，可能已经删除了。');
    });
    shop_publish_after_edit('更新商品《' . $res['name'] . '》', $before, true);
    return $res;
}

/** Product edits change the static pages: regenerate (no version entry; the shop is live data). */
function shop_publish_after_edit(string $reason, string $before, bool $always = false): ?array
{
    if (!$always && shop_page_signature(shop_products()) === $before) {
        return null;
    }
    return publish_site($reason, ['version' => false]);
}

/** Photo list edits: add (from an upload result), up, down, del. */
function shop_product_photo(string $id, string $op, int $i = 0, ?array $upload = null): array
{
    $res = shop_mutate(function (array &$products) use ($id, $op, $i, $upload) {
        foreach ($products as $k => $p) {
            if ($p['id'] !== $id) {
                continue;
            }
            $ph = $p['photos'];
            if ($op === 'add') {
                if (count($ph) >= SHOP_PHOTOS_MAX) {
                    throw new ShopError('full', '每件商品最多 ' . SHOP_PHOTOS_MAX . ' 张照片。');
                }
                $ph[] = ['src' => (string) $upload['src'], 'alt' => ''];
            } elseif (!isset($ph[$i])) {
                throw new ShopError('gone', '找不到这张照片，刷新页面看看。');
            } elseif ($op === 'del') {
                if (count($ph) === 1 && $p['on_sale']) {
                    throw new ShopError('state', '商品在卖的时候至少要留一张照片。先下架，或者先传一张新的。');
                }
                array_splice($ph, $i, 1);
            } elseif ($op === 'up' || $op === 'down') {
                $j = $op === 'up' ? $i - 1 : $i + 1;
                if (isset($ph[$j])) {
                    [$ph[$i], $ph[$j]] = [$ph[$j], $ph[$i]];
                }
            }
            $products[$k]['photos'] = array_values($ph);
            $products[$k]['rev']++;
            $products[$k]['updated_at'] = now_iso();
            return $products[$k];
        }
        throw new ShopError('gone', '找不到这件商品，可能已经删除了。');
    });
    if ($res['on_sale']) {
        publish_site('更新商品照片', ['version' => false]);
    }
    return $res;
}

/** Move a product up or down in the shop. */
function shop_product_move(string $id, string $dir): void
{
    $moved = shop_mutate(function (array &$products) use ($id, $dir) {
        foreach ($products as $k => $p) {
            if ($p['id'] === $id) {
                $j = $dir === 'up' ? $k - 1 : $k + 1;
                if (isset($products[$j])) {
                    [$products[$k], $products[$j]] = [$products[$j], $products[$k]];
                    return true;
                }
                return false;
            }
        }
        return false;
    });
    if ($moved) {
        publish_site('调整商品顺序', ['version' => false]);
    }
}

/** Delete a product that no order refers to (otherwise the owner takes it off sale). */
function shop_product_delete(string $id): array
{
    $res = shop_mutate(function (array &$products, array &$orders) use ($id) {
        foreach ($orders as $o) {
            if ($o['product_id'] === $id) {
                throw new ShopError('state', '这件商品已经有订单了，不能删除（买家还要查订单）。不想卖了就把它下架。');
            }
        }
        foreach ($products as $k => $p) {
            if ($p['id'] === $id) {
                array_splice($products, $k, 1);
                return $p;
            }
        }
        throw new ShopError('gone', '找不到这件商品，可能已经删除了。');
    });
    if ($res['delivery']['file'] !== null) {
        @unlink(shop_file_path($res['delivery']['file']['id']));
    }
    publish_site('删除商品《' . ($res['name'] !== '' ? $res['name'] : '没有名字') . '》', ['version' => false]);
    return $res;
}

// ---------- digital files ----------

function shop_file_path(string $fileId): string
{
    if (!preg_match(SHOP_FILE_ID_RE, $fileId)) {
        throw new InvalidArgumentException('bad file id');
    }
    return DATA_DIR . '/shop/files/' . $fileId . '.bin';
}

/** A safe display/download name: no path, no control characters, a known extension. */
function shop_file_name(string $name): ?string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = trim((string) preg_replace('/[\x00-\x1F\x7F"\/\\\\<>:|?*]+/u', '', $name));
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($name === '' || !in_array($ext, SHOP_FILE_EXT, true)) {
        return null;
    }
    if (mb_strlen($name) > 80) {
        $name = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 70) . '.' . $ext;
    }
    return $name;
}

function shop_file_limit(): int
{
    return min(SHOP_FILE_MAX, upload_limit_raw());
}

/** PHP's own upload limits (without the 12 MB image cap). */
function upload_limit_raw(): int
{
    $ini = function (string $k): int {
        $v = trim((string) ini_get($k));
        if ($v === '' || $v === '0' || $v === '-1') {
            return PHP_INT_MAX;
        }
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
    };
    return min($ini('upload_max_filesize'), $ini('post_max_size'));
}

/** Store the owner's upload as the product's file (replacing any old one). */
function shop_product_file_set(string $id, string $tmp, string $origName, int $size): array
{
    $name = shop_file_name($origName);
    if ($name === null) {
        throw new ShopError('invalid', '只能上传这些格式：' . implode('、', SHOP_FILE_EXT) . '。');
    }
    if ($size < 1 || $size > shop_file_limit()) {
        throw new ShopError('too_large', '文件太大了，最大 ' . intdiv(shop_file_limit(), 1048576) . ' MB。大文件请放网盘，填链接。');
    }
    shop_ensure_dirs();
    $fid = bin2hex(random_bytes(16));
    $dest = shop_file_path($fid);
    if (!@move_uploaded_file($tmp, $dest) && !(PHP_SAPI === 'cli' && @copy($tmp, $dest))) {
        throw new RuntimeException('cannot store file');
    }
    @chmod($dest, 0600);
    $old = null;
    try {
        $res = shop_mutate(function (array &$products) use ($id, $fid, $name, $size, &$old) {
            foreach ($products as $k => $p) {
                if ($p['id'] === $id) {
                    $old = $p['delivery']['file'];
                    $products[$k]['delivery']['file'] = ['id' => $fid, 'name' => $name, 'bytes' => $size, 'at' => now_iso()];
                    $products[$k]['rev']++;
                    $products[$k]['updated_at'] = now_iso();
                    return $products[$k];
                }
            }
            throw new ShopError('gone', '找不到这件商品，可能已经删除了。');
        });
    } catch (Throwable $e) {
        @unlink($dest);
        throw $e;
    }
    if ($old !== null) {
        @unlink(shop_file_path($old['id']));
    }
    return $res;
}

function shop_product_file_remove(string $id): array
{
    $old = null;
    $res = shop_mutate(function (array &$products) use ($id, &$old) {
        foreach ($products as $k => $p) {
            if ($p['id'] === $id) {
                $old = $p['delivery']['file'];
                if ($p['on_sale'] && $p['type'] === 'digital' && $p['delivery']['text'] === '' && $p['delivery']['link'] === '') {
                    throw new ShopError('state', '这件数字商品在卖，删掉文件以后买家就收不到东西了。先填文字或链接，或者先下架。');
                }
                $products[$k]['delivery']['file'] = null;
                $products[$k]['rev']++;
                $products[$k]['updated_at'] = now_iso();
                return $products[$k];
            }
        }
        throw new ShopError('gone', '找不到这件商品，可能已经删除了。');
    });
    if ($old !== null) {
        @unlink(shop_file_path($old['id']));
    }
    return $res;
}

// ---------- 收款码 images ----------

/** Re-encode an uploaded QR image to PNG (lossless, so the code stays scannable). Returns the public rel path. */
function shop_qr_store(string $tmp, string $kind): string
{
    if (!in_array($kind, ['wechat', 'alipay'], true)) {
        throw new ShopError('invalid', '收款码类型不对。');
    }
    $size = (int) @filesize($tmp);
    if ($size < 1 || $size > UPLOAD_MAX_BYTES) {
        throw new ShopError('too_large', '图片太大了，最大 ' . intdiv(UPLOAD_MAX_BYTES, 1048576) . ' MB。');
    }
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (in_array($mime, ['image/heic', 'image/heif'], true)) {
        throw new ShopError('invalid', '这张是 HEIC 格式。请截个图再传。');
    }
    $info = @getimagesize($tmp);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true) || $info === false
        || $info[0] < 1 || $info[0] > IMG_MAX_SIDE || $info[1] > IMG_MAX_SIDE || $info[0] * $info[1] > IMG_MAX_PIXELS) {
        throw new ShopError('invalid', '只能上传 JPG、PNG 或 WebP 格式的收款码截图。');
    }
    @ini_set('memory_limit', '512M');
    $img = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($tmp),
        'image/png' => @imagecreatefrompng($tmp),
        'image/webp' => @imagecreatefromwebp($tmp),
        'image/gif' => @imagecreatefromgif($tmp),
    };
    if (!$img) {
        throw new ShopError('invalid', '这张图片打不开，换一张试试。');
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $s = min(1, 1200 / max($w, $h));
    $out = imagecreatetruecolor(max(1, (int) round($w * $s)), max(1, (int) round($h * $s)));
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagecopyresampled($out, $img, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
    imagedestroy($img);
    ob_start();
    imagepng($out, null, 9);
    $bytes = (string) ob_get_clean();
    imagedestroy($out);
    if ($bytes === '') {
        throw new ShopError('invalid', '图片处理失败，换一张试试。');
    }
    shop_ensure_dirs();
    $rel = 'uploads/shop/' . $kind . '-' . bin2hex(random_bytes(8)) . '.png';
    file_write_atomic(DATA_DIR . '/shop/qr/' . basename($rel), $bytes, 0600);
    file_write_atomic(PUBLIC_DIR . '/' . $rel, $bytes, 0644);
    return $rel;
}

/** Delete an old QR image (both copies). */
function shop_qr_delete(?string $rel): void
{
    if ($rel === null || !preg_match(SHOP_QR_RE, $rel)) {
        return;
    }
    @unlink(DATA_DIR . '/shop/qr/' . basename($rel));
    @unlink(PUBLIC_DIR . '/' . $rel);
}

/** Self-heal: restore the public copies of the current QR images. */
function shop_sync_public(): int
{
    $n = 0;
    try {
        $qr = shop_settings()['qr'];
    } catch (Throwable $e) {
        return 0;
    }
    foreach ($qr as $rel) {
        if (is_string($rel) && preg_match(SHOP_QR_RE, $rel)) {
            $src = DATA_DIR . '/shop/qr/' . basename($rel);
            if (is_file($src) && !is_file(PUBLIC_DIR . '/' . $rel)) {
                file_write_atomic(PUBLIC_DIR . '/' . $rel, (string) file_get_contents($src), 0644);
                $n++;
            }
        }
    }
    return $n;
}

// ---------- owner views ----------

/** Counts per status, plus 待发货 (confirmed physical). */
function shop_counts(array $orders): array
{
    $c = array_fill_keys(array_keys(SHOP_STATUS), 0) + ['to_ship' => 0, 'all' => count($orders)];
    foreach ($orders as $o) {
        $c[$o['status']]++;
        if ($o['status'] === 'confirmed' && $o['type'] === 'physical') {
            $c['to_ship']++;
        }
    }
    return $c;
}

/** Search by order number, phone, name or email (owner only). */
function shop_order_matches(array $o, string $q): bool
{
    if ($q === '') {
        return true;
    }
    $q = mb_strtolower($q);
    foreach ([$o['id'], $o['buyer']['phone'], $o['buyer']['name'], $o['buyer']['email'], $o['name'], shop_money((int) $o['amount'])] as $v) {
        if ($v !== '' && str_contains(mb_strtolower((string) $v), $q)) {
            return true;
        }
    }
    return false;
}

/** All orders as CSV: UTF-8 with BOM, CRLF, every cell quoted, formula-looking cells defused. */
function shop_csv(array $orders): string
{
    $head = ['订单号', '下单时间', '状态', '商品', '类型', '单价', '数量', '商品金额', '运费', '识别尾数', '应付金额', '收货人', '手机号', '收货地址', '邮箱', '买家备注', '付款备注', '快递公司', '快递单号', '取消原因', '退款记录', '推广码'];
    $rows = ["\u{FEFF}" . implode(',', array_map('csv_cell', $head))];
    foreach ($orders as $o) {
        $rows[] = implode(',', array_map('csv_cell', [
            $o['id'], local_time($o['created_at']), SHOP_STATUS[$o['status']] ?? $o['status'], $o['name'], SHOP_TYPES[$o['type']] ?? '',
            shop_money((int) $o['price']), (string) $o['qty'], shop_money((int) $o['subtotal']), shop_money((int) $o['shipping']),
            shop_money((int) $o['offset']), shop_money((int) $o['amount']), $o['buyer']['name'], $o['buyer']['phone'], $o['buyer']['address'],
            $o['buyer']['email'], $o['note'], $o['payer'], $o['courier'], $o['tracking'], $o['cancel_reason'],
            implode('；', array_map(fn($r) => local_time($r['t']) . ' ' . $r['note'], $o['refunds'])),
            (string) ($o['ref']['code'] ?? ''),
        ]));
    }
    return implode("\r\n", $rows) . "\r\n";
}

// ---------- the product catalog for the shop assistant (AI) ----------

/** A few plain words from a product description (Markdown stripped) for the assistant; '' when there is none. */
function shop_ai_excerpt(string $md, int $max = 80): string
{
    $t = (string) preg_replace('/!\[[^\]]*\]\([^)]*\)/u', '', $md);            // pictures
    $t = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $t);           // links keep their words
    $t = (string) preg_replace('/^\s*(#{1,6}|>|[-*+]|\d+\.)\s+/mu', '', $t);   // headings, quotes, list marks
    $t = str_replace(['**', '__', '`', '|'], '', $t);
    $t = trim((string) preg_replace('/\s+/u', ' ', $t));
    return mb_strlen($t) > $max ? mb_substr($t, 0, $max) . '…' : $t;
}

/**
 * What the shop assistant knows about the shop, rebuilt for every question from the live data: open or not, the
 * owner's notice, which 收款码 are set up, how long an unpaid order waits, shipping, how each kind of product is
 * delivered, where to look up an order, then the products on sale by category with price, stock, a few words of
 * the description and the page link. Capped (40 products, ~6000 characters) so the prompt stays cheap.
 */
function shop_catalog_text(): string
{
    try {
        $ss = shop_settings();
        $base = ref_site_origin() . site_base();
        $ix = shop_cat_index(shop_cats());
        $byCat = [];
        $types = [];
        $n = 0;
        foreach (shop_products() as $p) {
            if (!shop_product_live($p) || $n >= 40) {
                continue;
            }
            $n++;
            $types[$p['type']] = true;
            $desc = shop_ai_excerpt($p['body']);
            $byCat[shop_cat_label($p['cat'], $ix) ?: '未分类'][] = '- ' . $p['name'] . '（' . SHOP_TYPES[$p['type']] . '，¥' . shop_money($p['price']) . '，' . (shop_avail_label($p) ?? '有货') . '）'
                . ($p['summary'] !== '' ? '：' . $p['summary'] : '')
                . ($desc !== '' && $desc !== $p['summary'] ? ' 介绍：' . $desc : '')
                . ($p['slug'] !== '' ? ' 链接：' . $base . 'shop/' . $p['slug'] . '.html' : '');
        }
        $qr = is_array($ss['qr'] ?? null) ? $ss['qr'] : [];
        $pay = array_keys(array_filter(['微信' => $qr['wechat'] ?? null, '支付宝' => $qr['alipay'] ?? null]));
        $out = [($ss['enabled'] ? '商城营业中。' : '商城暂停营业，现在不能下单。')];
        $notice = trim((string) ($ss['notice'] ?? ''));
        if ($notice !== '') {
            $out[] = '商城公告：' . $notice;
        }
        $out[] = $pay ? '收款方式：' . implode('或', $pay) . '扫码付款。' : '收款码还没设置好，暂时付不了款，请访客晚点再来。';
        if ((int) ($ss['cancel_hours'] ?? 0) > 0) {
            $out[] = '下单后 ' . (int) $ss['cancel_hours'] . ' 小时内没付款的订单会自动取消。';
        }
        $out[] = ($ss['shipping'] > 0 ? '实物运费每单 ¥' . shop_money($ss['shipping']) . ($ss['free_over'] > 0 ? '，满 ¥' . shop_money($ss['free_over']) . ' 包邮' : '') : '实物包邮') . '。';
        if (isset($types['supply'])) {
            $out[] = '「自动发卡」商品：站长确认收款以后系统自动发卡，卡密在「查订单」里查看，不用等站长手动发。';
        }
        $out[] = '查订单：' . $base . 'api/shop.php?a=lookup （填订单号和手机号后 4 位，数字商品和自动发卡填邮箱 @ 前面的部分）。';
        if ($byCat) {
            $out[] = '在卖的商品（按分类）：';
            foreach ($byCat as $cat => $lines) {
                $out[] = '【' . $cat . '】';
                array_push($out, ...$lines);
            }
        } else {
            $out[] = '现在没有在卖的商品。';
        }
        $text = implode("\n", $out);
        return mb_strlen($text) > 6000 ? mb_substr($text, 0, 6000) . '…' : $text;
    } catch (Throwable $e) {
        return '';
    }
}
