<?php
// USDT 收款 (SPEC §22): the buyer may pay an order in USDT on TRC20 (TRON) or BEP20 (BNB Smart Chain) to the owner's
// own receiving address; the payment is confirmed automatically from public chain data. Non-custodial: the site stores
// receiving ADDRESSES only (never a private key or a seed phrase), never sends funds, has no wallet or balance feature
// and uses no payment aggregator. The CNY order total stays the price of record (经营总览 counts it in CNY).
//
// Each quote (stored on the order: chain, address, rate, amount, created, expires) is unique per chain among the quotes
// that can still match: base = ceil(CNY total / rate) to 0.01 USDT, plus a tail of 0.0001..0.0099. Amounts are kept as
// integers of 0.0001 USDT (amount4) and as base-unit strings (TRC20 USDT 6 decimals, BEP20 USDT 18 decimals).
//
// Matching (usdt_match) is a pure function. Checks run while the buyer's pay page is open (api/shop.php a=usdt, cached
// so each chain is asked at most every USDT_CACHE_GAP seconds) and from cron (app/bin/usdt-watch.php, every minute).
// A match marks the order paid exactly like the owner's 确认收款 (shop_owner_action confirm, history by 'usdt'), so
// 货源 buying, digital delivery and 微信提醒 follow as usual. Anything that arrives and does not match goes to the
// 未匹配的 USDT 到账 list for the owner; it is never confirmed automatically. No buyer data is sent to any chain API.
declare(strict_types=1);
defined('BLOG_APP') || exit;

const USDT_CHAINS = [
    'trc20' => [
        'label' => 'TRC20',
        'net' => 'TRON（波场）',
        'decimals' => 6,
        'contract' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
        'tx' => 'https://tronscan.org/#/transaction/',
        'addr' => 'https://tronscan.org/#/address/',
    ],
    'bep20' => [
        'label' => 'BEP20',
        'net' => 'BNB Smart Chain（BSC，币安智能链）',
        'decimals' => 18,
        'contract' => '0x55d398326f99059fF775485246999027B3197955',
        'tx' => 'https://bscscan.com/tx/',
        'addr' => 'https://bscscan.com/address/',
    ],
];
const USDT_TRONGRID = 'https://api.trongrid.io';
const USDT_BSC_RPC = ['https://bsc-dataseed.bnbchain.org', 'https://bsc-dataseed1.defibit.io', 'https://bsc-rpc.publicnode.com'];
const USDT_TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';   // keccak256("Transfer(address,address,uint256)")
const USDT_RATE_MIN = 50000;          // ¥5.0000 per USDT (rate stored ×10000)
const USDT_RATE_MAX = 100000;         // ¥10.0000 per USDT
const USDT_WINDOW_DEFAULT = 30;       // minutes a quote stays payable
const USDT_WINDOW_MIN = 10;
const USDT_WINDOW_MAX = 120;
const USDT_EARLY = 120;               // a transfer may be stamped up to 2 minutes before the quote (clock skew)
const USDT_GRACE = 600;               // and up to 10 minutes after it expired
const USDT_LATE_WATCH = 6 * 3600;     // keep looking this long after expiry, so late payments reach the owner's list
const USDT_CACHE_GAP = 15;            // seconds between chain queries for one chain
const USDT_BSC_CONFIRM = 15;          // blocks on top before a BEP20 transfer counts
const USDT_BSC_CHUNK = 1000;          // blocks per eth_getLogs call (public RPC range limits)
const USDT_BSC_CHUNKS_PER_RUN = 8;
const USDT_BSC_SEC_PER_BLOCK = 0.5;   // conservative (BSC is ~0.75 s): estimates reach a little further back
const USDT_TRON_PAGES = 4;
const USDT_CONNECT_TIMEOUT = 3;
const USDT_TIMEOUT = 8;
const USDT_MAX_BYTES = 2 * 1024 * 1024;
const USDT_BUDGET = 20;               // seconds one chain check may spend on the network in total (pages, chunks, fallbacks)
const USDT_QUOTES_PER_ORDER = 20;
const USDT_DUST4 = 100;               // transfers under 0.0100 USDT are ignored: address-poisoning dust, never a real quote
                                      // (the smallest possible quote is 0.01 + a tail of at least 0.0001)
const USDT_UNMATCHED_MAX = 500;
const USDT_TRONGRID_KEY_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';
const USDT_B58 = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
const USDT_WHY = [
    'amount' => '金额对不上任何订单',
    'late' => '金额对得上，但超过了付款时间',
    'early' => '金额对得上，但在报价之前就到了',
    'closed' => '金额对得上，但订单已经不能付款（已取消、已付款或已结束）',
    'stock' => '金额对得上，但订单超时取消后库存不够，没能恢复',
];

// ---------- hashing: Keccak-256 (Ethereum, not SHA3-256), base58check ----------

/** Keccak-256 of $msg (raw 32 bytes). Pure PHP, 64-bit integers. */
function usdt_keccak256(string $msg): string
{
    static $rc = null;
    static $rot = [0, 1, 62, 28, 27, 36, 44, 6, 55, 20, 3, 10, 43, 25, 39, 41, 45, 15, 21, 8, 18, 2, 61, 56, 14];
    if ($rc === null) {
        $rc = [];
        foreach (['0000000000000001', '0000000000008082', '800000000000808a', '8000000080008000', '000000000000808b', '0000000080000001',
            '8000000080008081', '8000000000008009', '000000000000008a', '0000000000000088', '0000000080008009', '000000008000000a',
            '000000008000808b', '800000000000008b', '8000000000008089', '8000000000008003', '8000000000008002', '8000000000000080',
            '000000000000800a', '800000008000000a', '8000000080008081', '8000000000008080', '0000000080000001', '8000000080008008'] as $h) {
            $rc[] = unpack('J', (string) hex2bin($h))[1];
        }
    }
    $rotl = static fn(int $x, int $n): int => $n === 0 ? $x : (($x << $n) | (($x >> (64 - $n)) & ~(-1 << $n)));
    $rate = 136;
    $msg .= "\x01";
    $msg .= str_repeat("\0", ($rate - strlen($msg) % $rate) % $rate);
    $msg[strlen($msg) - 1] = chr(ord($msg[strlen($msg) - 1]) | 0x80);
    $a = array_fill(0, 25, 0);
    foreach (str_split($msg, $rate) as $block) {
        for ($i = 0; $i < 17; $i++) {
            $a[$i] ^= unpack('P', substr($block, $i * 8, 8))[1];
        }
        for ($r = 0; $r < 24; $r++) {
            $c = [];
            for ($x = 0; $x < 5; $x++) {
                $c[$x] = $a[$x] ^ $a[$x + 5] ^ $a[$x + 10] ^ $a[$x + 15] ^ $a[$x + 20];
            }
            for ($x = 0; $x < 5; $x++) {
                $d = $c[($x + 4) % 5] ^ $rotl($c[($x + 1) % 5], 1);
                for ($y = 0; $y < 25; $y += 5) {
                    $a[$y + $x] ^= $d;
                }
            }
            $b = [];
            for ($x = 0; $x < 5; $x++) {
                for ($y = 0; $y < 5; $y++) {
                    $b[$y + 5 * ((2 * $x + 3 * $y) % 5)] = $rotl($a[$x + 5 * $y], $rot[$x + 5 * $y]);
                }
            }
            for ($y = 0; $y < 25; $y += 5) {
                for ($x = 0; $x < 5; $x++) {
                    $a[$y + $x] = $b[$y + $x] ^ (~$b[$y + ($x + 1) % 5] & $b[$y + ($x + 2) % 5]);
                }
            }
            $a[0] ^= $rc[$r];
        }
    }
    $out = '';
    for ($i = 0; $i < 4; $i++) {
        $out .= pack('P', $a[$i]);
    }
    return $out;
}

/** Base58 → bytes, or null on a character outside the alphabet. */
function usdt_b58_decode(string $s): ?string
{
    $bytes = [];
    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        $v = strpos(USDT_B58, $s[$i]);
        if ($v === false) {
            return null;
        }
        $carry = $v;
        for ($j = count($bytes) - 1; $j >= 0; $j--) {
            $carry += $bytes[$j] * 58;
            $bytes[$j] = $carry & 0xff;
            $carry >>= 8;
        }
        while ($carry > 0) {
            array_unshift($bytes, $carry & 0xff);
            $carry >>= 8;
        }
    }
    $lead = strspn($s, '1');
    return str_repeat("\0", $lead) . ($bytes ? pack('C*', ...$bytes) : '');
}

function usdt_b58_encode(string $bin): string
{
    $digits = [];
    foreach (unpack('C*', $bin) ?: [] as $byte) {
        $carry = $byte;
        for ($j = count($digits) - 1; $j >= 0; $j--) {
            $carry += $digits[$j] << 8;
            $digits[$j] = $carry % 58;
            $carry = intdiv($carry, 58);
        }
        while ($carry > 0) {
            array_unshift($digits, $carry % 58);
            $carry = intdiv($carry, 58);
        }
    }
    $lead = strspn($bin, "\0");
    return str_repeat('1', $lead) . implode('', array_map(fn($d) => USDT_B58[$d], $digits));
}

/** A TRON address from its 21 raw bytes (0x41 + 20). */
function usdt_tron_encode(string $raw21): string
{
    return usdt_b58_encode($raw21 . substr(hash('sha256', hash('sha256', $raw21, true), true), 0, 4));
}

/** 'T…' base58check address → '41' + 40 hex, or null. TronGrid may answer either form. */
function usdt_tron_hex(string $addr): ?string
{
    if (preg_match('/^41[0-9a-fA-F]{40}$/D', $addr)) {
        return strtolower($addr);
    }
    if (!preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/D', $addr)) {
        return null;
    }
    $raw = usdt_b58_decode($addr);
    if ($raw === null || strlen($raw) !== 25 || $raw[0] !== "\x41") {
        return null;
    }
    $chk = substr(hash('sha256', hash('sha256', substr($raw, 0, 21), true), true), 0, 4);
    return hash_equals($chk, substr($raw, 21)) ? bin2hex(substr($raw, 0, 21)) : null;
}

/** EIP-55 checksummed form of a 0x address (any case in). */
function usdt_eip55(string $addr): string
{
    $lower = strtolower(substr($addr, 2));
    $hash = bin2hex(usdt_keccak256($lower));
    $out = '0x';
    for ($i = 0; $i < 40; $i++) {
        $ch = $lower[$i];
        $out .= ctype_alpha($ch) && hexdec($hash[$i]) >= 8 ? strtoupper($ch) : $ch;
    }
    return $out;
}

// ---------- owner input: addresses, the TronGrid key, things that must never be pasted ----------

/** A plain reason when $s looks like a private key or a recovery phrase (never stored, never logged), else null. */
function usdt_secret_like(string $s): ?string
{
    $t = trim($s);
    $words = preg_split('/[\s\x{3000},，]+/u', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($words) >= 12 || (count($words) >= 2 && preg_match('/^\p{L}+$/u', implode('', $words)) && count($words) >= 6)) {
        return '这像是钱包的助记词（12 或 24 个单词）。助记词等于你的钱，绝对不要发给任何人、不要填到任何网站。这里只填收款地址。';
    }
    if (preg_match('/^(0x)?[0-9a-fA-F]{64}$/D', $t) || preg_match('/^[5KL][1-9A-HJ-NP-Za-km-z]{50,51}$/D', $t) || preg_match('/^[xyztuv]prv[1-9A-HJ-NP-Za-km-z]{20,}$/D', $t)) {
        return '这像是钱包的私钥。私钥等于你的钱，绝对不要发给任何人、不要填到任何网站。这里只填收款地址（TRC20 以 T 开头，BEP20 以 0x 开头、共 42 位）。';
    }
    if (preg_match('/^\p{Han}{12,}$/u', str_replace(' ', '', $t))) {
        return '这像是中文助记词。助记词等于你的钱，绝对不要填到任何网站。这里只填收款地址。';
    }
    return null;
}

/**
 * Check a receiving address. ['ok' => true, 'address' => canonical] or ['ok' => false, 'msg' => Chinese reason].
 * TRC20: base58check, version 0x41 (starts with T), checksum verified. BEP20: 0x + 40 hex, EIP-55 checked when mixed case.
 */
function usdt_address_check(string $chain, string $raw): array
{
    $s = trim($raw);
    if (($why = usdt_secret_like($s)) !== null) {
        return ['ok' => false, 'msg' => $why, 'secret' => true];
    }
    if ($chain === 'trc20') {
        if (preg_match('/^0x[0-9a-fA-F]{40}$/D', $s)) {
            return ['ok' => false, 'msg' => '这是 0x 开头的地址（BEP20 / 以太坊格式），不能用在 TRC20。TRC20 地址以大写字母 T 开头，共 34 位。'];
        }
        if (!preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/D', $s)) {
            return ['ok' => false, 'msg' => 'TRC20 地址以大写字母 T 开头，共 34 位（没有 0、O、I、l）。请从钱包的「收款」里复制完整的地址。'];
        }
        if (usdt_tron_hex($s) === null) {
            return ['ok' => false, 'msg' => '这个 TRC20 地址的校验位不对，可能少复制或多复制了字符。请从钱包的「收款」里重新复制。'];
        }
        if ($s === USDT_CHAINS['trc20']['contract']) {
            return ['ok' => false, 'msg' => '这是 USDT 合约本身的地址，不是你的收款地址。'];
        }
        return ['ok' => true, 'address' => $s];
    }
    if ($chain === 'bep20') {
        if (preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/D', $s)) {
            return ['ok' => false, 'msg' => '这是 T 开头的 TRC20 地址，不能用在 BEP20。BEP20 地址以 0x 开头，共 42 位。'];
        }
        if (!preg_match('/^0x[0-9a-fA-F]{40}$/D', $s)) {
            return ['ok' => false, 'msg' => 'BEP20 地址以 0x 开头，后面是 40 位数字和字母 a 到 f。请从钱包的「收款」里复制完整的地址。'];
        }
        $hex = substr($s, 2);
        if ($hex !== strtolower($hex) && $hex !== strtoupper($hex) && usdt_eip55($s) !== $s) {
            return ['ok' => false, 'msg' => '这个 BEP20 地址的大小写校验不对，可能抄错了字符。请从钱包里直接复制，不要手打。'];
        }
        if (strtolower($s) === strtolower(USDT_CHAINS['bep20']['contract']) || preg_match('/^0x0{40}$/D', $s)) {
            return ['ok' => false, 'msg' => '这不是你的收款地址。'];
        }
        return ['ok' => true, 'address' => usdt_eip55($s)];
    }
    return ['ok' => false, 'msg' => '没有这条链。'];
}

/** '7.2', '7.20', '7.2050', '７.２０' → rate ×10000 (72000), or null. 2 to 4 decimals (one is accepted too). */
function usdt_parse_rate(string $s): ?int
{
    $s = str_replace(['¥', '￥', '元', ' ', '。'], ['', '', '', '', '.'], ascii_numbers($s));
    if (!preg_match('/^(\d{1,2})(?:\.(\d{1,4}))?$/D', $s, $m)) {
        return null;
    }
    return (int) $m[1] * 10000 + (int) str_pad($m[2] ?? '0', 4, '0');
}

function usdt_rate_text(int $rate4): string
{
    $frac = rtrim(str_pad((string) ($rate4 % 10000), 4, '0', STR_PAD_LEFT), '0');
    return intdiv($rate4, 10000) . '.' . str_pad($frac, 2, '0');   // 7.2000 → 7.20, 7.2050 → 7.205
}

// ---------- settings (settings.usdt) and the write-only TronGrid key ----------

function usdt_defaults(): array
{
    return ['trc20' => ['on' => false, 'address' => ''], 'bep20' => ['on' => false, 'address' => ''], 'rate' => 0, 'window' => USDT_WINDOW_DEFAULT];
}

function usdt_normalize(mixed $u): array
{
    $u = is_array($u) ? $u : [];
    $out = usdt_defaults();
    foreach (array_keys(USDT_CHAINS) as $c) {
        $x = is_array($u[$c] ?? null) ? $u[$c] : [];
        $out[$c] = ['on' => (bool) ($x['on'] ?? false), 'address' => is_string($x['address'] ?? null) ? trim($x['address']) : ''];
    }
    $out['rate'] = is_int($u['rate'] ?? null) ? $u['rate'] : (int) ($u['rate'] ?? 0);
    $out['window'] = is_int($u['window'] ?? null) ? $u['window'] : (int) ($u['window'] ?? USDT_WINDOW_DEFAULT);
    return $out;
}

/** Errors keyed usdt.<field>. */
function usdt_validate(array $u): array
{
    $e = [];
    foreach (USDT_CHAINS as $c => $info) {
        $a = $u[$c]['address'];
        if ($a !== '') {
            $r = usdt_address_check($c, $a);
            if (!$r['ok']) {
                $e['usdt.' . $c] = $r['msg'];
            }
        } elseif ($u[$c]['on']) {
            $e['usdt.' . $c] = '要打开 ' . $info['label'] . '，先填你自己的 ' . $info['label'] . ' 收款地址。';
        }
    }
    if ($u['rate'] !== 0 && ($u['rate'] < USDT_RATE_MIN || $u['rate'] > USDT_RATE_MAX)) {
        $e['usdt.rate'] = '汇率请填 5.00 到 10.00 之间（1 USDT 换多少人民币），比如 7.20。';
    } elseif ($u['rate'] === 0 && ($u['trc20']['on'] || $u['bep20']['on'])) {
        $e['usdt.rate'] = '要打开 USDT 收款，先填汇率（1 USDT 换多少人民币），比如 7.20。';
    }
    if ($u['window'] < USDT_WINDOW_MIN || $u['window'] > USDT_WINDOW_MAX) {
        $e['usdt.window'] = '付款时间填 ' . USDT_WINDOW_MIN . ' 到 ' . USDT_WINDOW_MAX . ' 分钟。';
    }
    return $e;
}

function usdt_settings(?array $settings = null): array
{
    return ($settings ?? settings_get())['usdt'];
}

/** Chains the buyer can choose right now: switched on, a valid address, a rate. */
function usdt_chains_ready(?array $settings = null): array
{
    $u = usdt_settings($settings);
    $out = [];
    foreach (array_keys(USDT_CHAINS) as $c) {
        if ($u[$c]['on'] && $u['rate'] > 0 && usdt_address_check($c, $u[$c]['address'])['ok']) {
            $out[] = $c;
        }
    }
    return $out;
}

function usdt_trongrid_key(): ?string
{
    $k = secrets_get()['trongrid_key'] ?? null;
    return is_string($k) && preg_match(USDT_TRONGRID_KEY_RE, $k) ? $k : null;
}

function usdt_trongrid_key_hint(): ?string
{
    $k = usdt_trongrid_key();
    return $k === null ? null : substr($k, -4);
}

function usdt_trongrid_key_set(string $key): void
{
    $key = strtolower(trim($key));
    if (usdt_secret_like($key) !== null) {
        throw new ValidationError(['trongrid_key' => (string) usdt_secret_like($key)]);
    }
    if (!preg_match(USDT_TRONGRID_KEY_RE, $key)) {
        throw new ValidationError(['trongrid_key' => 'TronGrid 的 API Key 是 36 位，形如 1a2b3c4d-1234-5678-9abc-1234567890ab。请在 trongrid.io 登录后，在 API Keys 里复制。']);
    }
    secrets_update(function (array $s) use ($key) {
        $s['trongrid_key'] = $key;
        $s['trongrid_key_set_at'] = now_iso();
        return $s;
    });
    @chmod(data_path('secrets.json'), 0600);
}

function usdt_trongrid_key_remove(): void
{
    secrets_update(function (array $s) {
        unset($s['trongrid_key'], $s['trongrid_key_set_at']);
        return $s;
    });
}

// ---------- amounts ----------

/** amount4 (0.0001 USDT) → '12.3456'. */
function usdt_amount_text(int $amount4): string
{
    return intdiv($amount4, 10000) . '.' . str_pad((string) ($amount4 % 10000), 4, '0', STR_PAD_LEFT);
}

/** amount4 → base units as a decimal string (6 or 18 decimals). */
function usdt_units(int $amount4, int $decimals): string
{
    return (string) $amount4 . str_repeat('0', $decimals - 4);
}

/** Base units (decimal string) → '12.345678' (trailing zeros cut, at least 2 decimals). */
function usdt_units_text(string $units, int $decimals): string
{
    $units = ltrim($units, '0');
    $units = str_pad($units, $decimals + 1, '0', STR_PAD_LEFT);
    $int = substr($units, 0, -$decimals);
    $frac = rtrim(substr($units, -$decimals), '0');
    return $int . '.' . str_pad($frac, 2, '0');
}

/** Hex (0x…) → decimal string, any size. */
function usdt_hex_to_dec(string $hex): ?string
{
    $hex = strtolower((string) preg_replace('/^0x/', '', $hex));
    if ($hex === '' || !ctype_xdigit($hex)) {
        return null;
    }
    $dec = [0];   // little-endian base-1e9 limbs
    foreach (str_split($hex) as $h) {
        $carry = hexdec($h);
        foreach ($dec as $i => $limb) {
            $v = $limb * 16 + $carry;
            $dec[$i] = $v % 1000000000;
            $carry = intdiv($v, 1000000000);
        }
        if ($carry > 0) {
            $dec[] = $carry;
        }
    }
    $s = (string) array_pop($dec);
    while ($dec) {
        $s .= str_pad((string) array_pop($dec), 9, '0', STR_PAD_LEFT);
    }
    return $s;
}

/** A plain decimal string without leading zeros ('0' for zero), or null. */
function usdt_dec(mixed $v): ?string
{
    if (is_int($v) && $v >= 0) {
        return (string) $v;
    }
    if (!is_string($v) || !preg_match('/^\d{1,78}$/D', $v)) {
        return null;
    }
    $t = ltrim($v, '0');
    return $t === '' ? '0' : $t;
}

/** The USDT price before the tail: ceil(cny fen / rate) to 0.01 USDT, as amount4. */
function usdt_base_amount4(int $fen, int $rate4): int
{
    $cents = intdiv($fen * 10000 + $rate4 - 1, $rate4);   // fen/100 ÷ rate4/10000 USDT = fen×100/rate4; ×100 for cents
    return $cents * 100;
}

/** Tails (1..99) taken on $chain by quotes that can still match at $now. */
function usdt_taken(array $orders, string $chain, int $now): array
{
    $used = [];
    foreach ($orders as $o) {
        foreach (usdt_order_quotes($o) as $q) {
            if ($q['chain'] === $chain && $q['expires'] + USDT_GRACE >= $now) {
                $used[$q['amount4']] = true;
            }
        }
    }
    return $used;
}

/** The unique payable amount4 for a CNY total on a chain, or null when all 99 tails are taken. */
function usdt_pick_amount4(int $fen, int $rate4, array $taken): ?int
{
    $base = usdt_base_amount4($fen, $rate4);
    $free = [];
    for ($t = 1; $t <= 99; $t++) {
        if (!isset($taken[$base + $t])) {
            $free[] = $base + $t;
        }
    }
    return $free ? $free[random_int(0, count($free) - 1)] : null;
}

// ---------- quotes on the order (order.usdt = {method, quotes: [...], paid}) ----------

function usdt_order_quotes(array $o): array
{
    $out = [];
    foreach (is_array($o['usdt']['quotes'] ?? null) ? $o['usdt']['quotes'] : [] as $q) {
        if (is_array($q) && isset(USDT_CHAINS[$q['chain'] ?? '']) && is_int($q['amount4'] ?? null) && is_int($q['created'] ?? null) && is_int($q['expires'] ?? null)) {
            $out[] = $q;
        }
    }
    return $out;
}

/** The quote the pay page shows (the last one) while the buyer chose USDT, else null. */
function usdt_current_quote(array $o): ?array
{
    if (($o['usdt']['method'] ?? '') !== 'usdt') {
        return null;
    }
    $q = usdt_order_quotes($o);
    return $q ? end($q) : null;
}

function usdt_paid(array $o): ?array
{
    $p = $o['usdt']['paid'] ?? null;
    return is_array($p) && isset(USDT_CHAINS[$p['chain'] ?? '']) ? $p : null;
}

/** Can a payment still be applied to this order (the owner's 确认收款 would accept it)? */
function usdt_order_payable(array $o): bool
{
    return in_array($o['status'], SHOP_UNPAID, true) || ($o['status'] === 'cancelled' && !empty($o['cancel_auto']) && $o['redacted_at'] === null);
}

/** Latest time a USDT quote keeps an unpaid order from expiring (expiry + grace), or 0. */
function usdt_order_hold(array $o): int
{
    $h = 0;
    foreach (usdt_order_quotes($o) as $q) {
        $h = max($h, $q['expires'] + USDT_GRACE);
    }
    return $h;
}

/**
 * The buyer picks how to pay: 'qr' (收款码) or a chain. A chain gets a quote: the current one when it is for the same
 * chain and still valid, else a new one at today's rate. Returns the order. Throws ShopError.
 */
function usdt_choose(string $id, string $choice, ?int $now = null): array
{
    $now ??= time();
    $settings = settings_get();
    $u = usdt_settings($settings);
    if ($choice !== 'qr' && !in_array($choice, usdt_chains_ready($settings), true)) {
        throw new ShopError('closed', '这种付款方式现在没有开放，请用收款码付款。');
    }
    return shop_mutate(function (array &$products, array &$orders) use ($id, $choice, $now, $u) {
        foreach ($orders as &$o) {
            if ($o['id'] !== $id) {
                continue;
            }
            if ($o['status'] !== 'pending') {
                throw new ShopError('state', '这个订单现在不能换付款方式。');
            }
            $o['usdt'] = is_array($o['usdt'] ?? null) ? $o['usdt'] : [];
            $o['usdt'] += ['method' => 'qr', 'quotes' => [], 'paid' => null];
            if ($choice === 'qr') {
                $o['usdt']['method'] = 'qr';
                return $o;
            }
            $quotes = usdt_order_quotes($o);
            foreach (array_reverse(array_keys($quotes)) as $i) {
                $q = $quotes[$i];
                if ($q['chain'] === $choice && $q['expires'] > $now + 60 && $q['address'] === $u[$choice]['address']) {
                    // a quote for this chain is still valid (the buyer switched away and back): show it again, same amount
                    unset($quotes[$i]);
                    $o['usdt']['quotes'] = array_merge(array_values($quotes), [$q]);
                    $o['usdt']['method'] = 'usdt';
                    return $o;
                }
            }
            if (count($quotes) >= USDT_QUOTES_PER_ORDER) {
                throw new ShopError('busy', '这个订单换报价的次数太多了。请用收款码付款，或者重新下单。');
            }
            $fen = (int) $o['subtotal'] + (int) $o['shipping'];   // the price of record; the CNY 识别尾数 is not part of it
            $a4 = usdt_pick_amount4($fen, $u['rate'], usdt_taken($orders, $choice, $now));
            if ($a4 === null) {
                throw new ShopError('busy', '现在用 USDT 付款的人太多了，请过几分钟再试，或者用收款码付款。');
            }
            $o['usdt']['quotes'] = array_merge($quotes, [[
                'chain' => $choice,
                'address' => $u[$choice]['address'],
                'rate' => $u['rate'],
                'cny' => $fen,
                'amount4' => $a4,
                'units' => usdt_units($a4, USDT_CHAINS[$choice]['decimals']),
                'created' => $now,
                'expires' => $now + 60 * $u['window'],
            ]]);
            $o['usdt']['method'] = 'usdt';
            return $o;
        }
        unset($o);
        throw new ShopError('gone', '找不到这个订单。');
    });
}

// ---------- matching (pure) ----------

/**
 * Decide what one incoming transfer means. Never trusts the browser: everything comes from chain data and the orders.
 * $t: chain, key (tx hash + log index), to, contract, units (decimal string, base units), ts (unix), decimals, conf (BEP20).
 * $ours: our receiving address on that chain. $quotes: rows of usdt_quote_rows(). $used: [key => true].
 * Returns ['r' => 'match', 'order' => id, 'quote' => q] | ['r' => 'unmatched', 'why' => USDT_WHY key, 'near' => ?id]
 *       | ['r' => 'skip', 'why' => token|recipient|dup|bad] | ['r' => 'wait'] (not confirmed enough yet).
 */
function usdt_match(array $t, string $ours, array $quotes, array $used): array
{
    $chain = (string) ($t['chain'] ?? '');
    $info = USDT_CHAINS[$chain] ?? null;
    $units = usdt_dec($t['units'] ?? null);
    if ($info === null || $units === null || !is_int($t['ts'] ?? null) || !is_string($t['key'] ?? null) || $t['key'] === '') {
        return ['r' => 'skip', 'why' => 'bad'];
    }
    $same = fn(string $a, string $b) => $chain === 'bep20' ? strtolower($a) === strtolower($b) : $a === $b;
    if (!$same((string) ($t['contract'] ?? ''), $info['contract']) || (int) ($t['decimals'] ?? -1) !== $info['decimals']) {
        return ['r' => 'skip', 'why' => 'token'];   // another token (fake "USDT" included): not ours to judge
    }
    if (!$same((string) ($t['to'] ?? ''), $ours)) {
        return ['r' => 'skip', 'why' => 'recipient'];
    }
    if (isset($used[$t['key']])) {
        return ['r' => 'skip', 'why' => 'dup'];
    }
    $dust = usdt_units(USDT_DUST4, $info['decimals']);
    if (strlen($units) < strlen($dust) || (strlen($units) === strlen($dust) && strcmp($units, $dust) < 0)) {
        return ['r' => 'skip', 'why' => 'dust'];   // poisoning spam: not worth the owner's list or a WeChat message
    }
    if ($chain === 'bep20' && (!is_int($t['conf'] ?? null) || $t['conf'] < USDT_BSC_CONFIRM)) {
        return ['r' => 'wait'];
    }
    $why = 'amount';
    $near = null;
    $best = null;
    foreach ($quotes as $q) {
        if ($q['chain'] !== $chain || !$same($q['address'], $ours) || $q['units'] !== $units) {
            continue;
        }
        if ($t['ts'] < $q['created'] - USDT_EARLY) {
            [$why, $near] = $why === 'amount' ? ['early', $q['order']] : [$why, $near];
            continue;
        }
        if ($t['ts'] > $q['expires'] + USDT_GRACE) {
            [$why, $near] = in_array($why, ['amount', 'early'], true) ? ['late', $q['order']] : [$why, $near];
            continue;
        }
        if (!$q['payable']) {
            [$why, $near] = ['closed', $q['order']];
            continue;
        }
        if ($best === null || $q['created'] > $best['created']) {
            $best = $q;
        }
    }
    if ($best !== null) {
        return ['r' => 'match', 'order' => $best['order'], 'quote' => $best];
    }
    return ['r' => 'unmatched', 'why' => $why, 'near' => $near];
}

/** Flat quote rows for matching: every quote of every order not already paid in USDT. */
function usdt_quote_rows(array $orders): array
{
    $rows = [];
    foreach ($orders as $o) {
        $payable = usdt_order_payable($o) && usdt_paid($o) === null;
        foreach (usdt_order_quotes($o) as $q) {
            $rows[] = $q + ['order' => $o['id'], 'payable' => $payable, 'units' => usdt_units($q['amount4'], USDT_CHAINS[$q['chain']]['decimals'])];
        }
    }
    return $rows;
}

/** Quotes still worth looking at on $chain at $now: not paid in USDT, within expiry + the late watch. */
function usdt_watched(array $orders, string $chain, int $now): array
{
    $out = [];
    foreach ($orders as $o) {
        if (usdt_paid($o) !== null) {
            continue;
        }
        foreach (usdt_order_quotes($o) as $q) {
            if ($q['chain'] === $chain && $now <= $q['expires'] + USDT_LATE_WATCH) {
                $out[] = $q + ['order' => $o['id']];
            }
        }
    }
    return $out;
}

// ---------- storage: used transactions, unmatched list, scan state (blog-data/shop/usdt/) ----------

function usdt_dir(string $file): string
{
    $d = DATA_DIR . '/shop/usdt';
    if (!is_dir($d) && !@mkdir($d, 0700, true) && !is_dir($d)) {
        throw new RuntimeException('cannot create shop/usdt');
    }
    return $d . '/' . $file;
}

function usdt_used(): array
{
    $d = json_read(usdt_dir('used.json'), []);
    return is_array($d['keys'] ?? null) ? $d['keys'] : [];
}

function usdt_unmatched_doc(): array
{
    $d = json_read(usdt_dir('unmatched.json'), []);
    $items = array_values(array_filter(is_array($d['items'] ?? null) ? $d['items'] : [], fn($x) => is_array($x) && is_string($x['id'] ?? null)));
    return ['items' => $items];
}

/** Open (not attached, not dismissed) unmatched transfers, newest first. */
function usdt_unmatched_open(): array
{
    try {
        return array_values(array_filter(usdt_unmatched_doc()['items'], fn($x) => ($x['state'] ?? '') === 'open'));
    } catch (Throwable $e) {
        return [];
    }
}

function usdt_scan_state(): array
{
    $d = json_read(usdt_dir('scan.json'), []);
    return is_array($d) ? $d : [];
}

function usdt_scan_note(string $chain, array $patch): void
{
    try {
        json_update(usdt_dir('scan.json'), function ($d) use ($chain, $patch) {
            $d = is_array($d) ? $d : [];
            $d[$chain] = array_replace(is_array($d[$chain] ?? null) ? $d[$chain] : [], $patch);
            return $d;
        }, []);
    } catch (Throwable $e) {
        error_log('usdt scan note: ' . get_class($e));
    }
}

// ---------- outbound calls (fixed hosts; tests may point them at 127.0.0.1 under the CLI / php -S only) ----------

function usdt_test_base(string $which): ?string
{
    if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) {
        return null;
    }
    $t = (string) getenv($which === 'tron' ? 'BLOG_USDT_TEST_TRON' : 'BLOG_USDT_TEST_BSC');
    return preg_match('#^http://127\.0\.0\.1:\d{2,5}(,http://127\.0\.0\.1:\d{2,5})*$#D', $t) ? $t : null;
}

function usdt_tron_base(): string
{
    return usdt_test_base('tron') ?? USDT_TRONGRID;
}

function usdt_bsc_rpcs(): array
{
    $t = usdt_test_base('bsc');
    return $t !== null ? explode(',', $t) : USDT_BSC_RPC;
}

/** The end of the current check's network budget (microtime), set by usdt_check_chain(); null = none. */
function usdt_deadline(?float $set = null, bool $clear = false): ?float
{
    static $d = null;
    if ($clear) {
        $d = null;
    } elseif ($set !== null) {
        $d = $set;
    }
    return $d;
}

/** One HTTP call. ['ok' => bool, 'status' => int, 'json' => mixed, 'err' => short kind]. Never throws. */
function usdt_http(string $url, ?string $jsonBody = null, array $headers = []): array
{
    $left = USDT_TIMEOUT;
    if (($d = usdt_deadline()) !== null) {
        $left = (int) floor($d - microtime(true));
        if ($left < 2) {
            return ['ok' => false, 'status' => 0, 'json' => null, 'err' => 'time budget used up'];
        }
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'json' => null, 'err' => 'no curl'];
    }
    $proto = str_starts_with($url, 'https://') ? CURLPROTO_HTTPS : CURLPROTO_HTTP;
    $buf = '';
    $big = false;
    $ch = curl_init($url);
    $opts = [
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json', 'Expect:'], $jsonBody !== null ? ['Content-Type: application/json'] : [], $headers),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => $proto,
        CURLOPT_REDIR_PROTOCOLS => $proto,
        CURLOPT_CONNECTTIMEOUT => USDT_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => min(USDT_TIMEOUT, $left),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'pilot-blog-usdt/1',
        CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$buf, &$big): int {
            if (strlen($buf) + strlen($chunk) > USDT_MAX_BYTES) {
                $big = true;
                return 0;
            }
            $buf .= $chunk;
            return strlen($chunk);
        },
    ];
    if ($jsonBody !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $jsonBody;
    }
    curl_setopt_array($ch, $opts);
    $ran = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($big) {
        return ['ok' => false, 'status' => $status, 'json' => null, 'err' => 'too large'];
    }
    if ($ran === false || $status === 0) {
        return ['ok' => false, 'status' => 0, 'json' => null, 'err' => 'network'];
    }
    $j = json_decode($buf, true);
    if ($status !== 200) {
        return ['ok' => false, 'status' => $status, 'json' => $j, 'err' => 'http ' . $status];
    }
    return is_array($j) ? ['ok' => true, 'status' => 200, 'json' => $j, 'err' => ''] : ['ok' => false, 'status' => 200, 'json' => null, 'err' => 'bad json'];
}

/**
 * TRC20 USDT transfers to $address since $sinceTs (TronGrid, confirmed only). ['ok' => bool, 'items' => [...], 'err'].
 * Items are normalized for usdt_match (contract, decimals and recipient as TronGrid reports them).
 */
function usdt_tron_transfers(string $address, int $sinceTs): array
{
    $c = USDT_CHAINS['trc20'];
    $q = ['only_confirmed' => 'true', 'only_to' => 'true', 'limit' => '50', 'contract_address' => $c['contract'], 'min_timestamp' => (string) ($sinceTs * 1000)];
    $headers = ($k = usdt_trongrid_key()) !== null ? ['TRON-PRO-API-KEY: ' . $k] : [];
    $items = [];
    $fingerprint = null;
    for ($page = 0; $page < USDT_TRON_PAGES; $page++) {
        $url = usdt_tron_base() . '/v1/accounts/' . rawurlencode($address) . '/transactions/trc20?' . http_build_query($q + ($fingerprint !== null ? ['fingerprint' => $fingerprint] : []));
        $r = usdt_http($url, null, $headers);
        if (!$r['ok'] || !is_array($r['json']['data'] ?? null) || ($r['json']['success'] ?? true) === false) {
            return ['ok' => false, 'items' => $items, 'err' => 'TronGrid ' . ($r['err'] !== '' ? $r['err'] : 'bad answer')];
        }
        foreach ($r['json']['data'] as $x) {
            if (!is_array($x) || !is_string($x['transaction_id'] ?? null) || !preg_match('/^[0-9a-fA-F]{64}$/D', $x['transaction_id'])) {
                continue;
            }
            if (($x['type'] ?? 'Transfer') !== 'Transfer') {
                continue;
            }
            $to = (string) ($x['to'] ?? '');
            if (preg_match('/^41[0-9a-fA-F]{40}$/D', $to)) {
                $to = usdt_tron_encode((string) hex2bin($to));
            }
            $from = (string) ($x['from'] ?? '');
            $items[] = [
                'chain' => 'trc20',
                'key' => 'trc20:' . strtolower($x['transaction_id']),
                'tx' => strtolower($x['transaction_id']),
                'to' => $to,
                'from' => preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/D', $from) ? $from : '',
                'contract' => (string) ($x['token_info']['address'] ?? ''),
                'decimals' => is_numeric($x['token_info']['decimals'] ?? null) ? (int) $x['token_info']['decimals'] : -1,
                'units' => usdt_dec($x['value'] ?? null) ?? '',
                'ts' => is_numeric($x['block_timestamp'] ?? null) ? intdiv((int) $x['block_timestamp'], 1000) : null,
            ];
        }
        $fingerprint = is_string($r['json']['meta']['fingerprint'] ?? null) && count($r['json']['data']) >= 50 ? $r['json']['meta']['fingerprint'] : null;
        if ($fingerprint === null) {
            break;
        }
    }
    return ['ok' => true, 'items' => $items, 'err' => ''];
}

/** One JSON-RPC call against the BSC endpoints in turn (first answer wins). ['ok', 'result', 'err']. */
function usdt_bsc_rpc(string $method, array $params): array
{
    $err = '';
    foreach (usdt_bsc_rpcs() as $url) {
        $r = usdt_http($url, (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]));
        if ($r['ok'] && is_array($r['json']) && array_key_exists('result', $r['json']) && !isset($r['json']['error'])) {
            return ['ok' => true, 'result' => $r['json']['result'], 'err' => ''];
        }
        $err = $r['ok'] ? 'rpc error' : $r['err'];
    }
    return ['ok' => false, 'result' => null, 'err' => 'BSC RPC ' . $err];
}

/**
 * BEP20 USDT Transfer logs to $address in blocks [from block for $sinceTs or the remembered next block, head − 15].
 * ['ok', 'items', 'err', 'next' => next block to scan]. Items carry conf (head − block) and the block timestamp.
 */
function usdt_bsc_transfers(string $address, int $sinceTs, int $now, ?int $nextBlock): array
{
    $c = USDT_CHAINS['bep20'];
    $h = usdt_bsc_rpc('eth_blockNumber', []);
    if (!$h['ok'] || !is_string($h['result']) || !preg_match('/^0x[0-9a-f]{1,16}$/i', $h['result'])) {
        return ['ok' => false, 'items' => [], 'err' => $h['err'] ?: 'BSC RPC bad block number', 'next' => $nextBlock];
    }
    $head = (int) hexdec($h['result']);
    $to = $head - USDT_BSC_CONFIRM;
    $est = max(0, $head - (int) ceil(max(0, $now - $sinceTs) / USDT_BSC_SEC_PER_BLOCK));
    $from = $nextBlock !== null ? max($nextBlock, $est) : $est;
    $topicTo = '0x' . str_repeat('0', 24) . strtolower(substr($address, 2));
    $items = [];
    $times = [];
    $chunks = 0;
    while ($from <= $to && $chunks < USDT_BSC_CHUNKS_PER_RUN) {
        $end = min($from + USDT_BSC_CHUNK - 1, $to);
        $r = usdt_bsc_rpc('eth_getLogs', [['fromBlock' => '0x' . dechex($from), 'toBlock' => '0x' . dechex($end), 'address' => $c['contract'], 'topics' => [USDT_TRANSFER_TOPIC, null, $topicTo]]]);
        if (!$r['ok'] || !is_array($r['result'])) {
            return ['ok' => false, 'items' => $items, 'err' => $r['err'] ?: 'BSC RPC bad logs', 'next' => $from];
        }
        foreach ($r['result'] as $l) {
            if (!is_array($l) || !empty($l['removed']) || !is_array($l['topics'] ?? null) || count($l['topics']) < 3) {
                continue;
            }
            if (strtolower((string) $l['topics'][0]) !== USDT_TRANSFER_TOPIC || !is_string($l['transactionHash'] ?? null) || !preg_match('/^0x[0-9a-f]{64}$/i', $l['transactionHash'])) {
                continue;
            }
            $bn = is_string($l['blockNumber'] ?? null) ? (int) hexdec($l['blockNumber']) : -1;
            $li = is_string($l['logIndex'] ?? null) ? (int) hexdec($l['logIndex']) : -1;
            if ($bn < 0 || $li < 0) {
                continue;
            }
            if (!isset($times[$bn]) && count($times) < 50) {
                $b = usdt_bsc_rpc('eth_getBlockByNumber', ['0x' . dechex($bn), false]);
                $times[$bn] = $b['ok'] && is_string($b['result']['timestamp'] ?? null) ? (int) hexdec($b['result']['timestamp']) : null;
                if ($times[$bn] === null) {
                    return ['ok' => false, 'items' => $items, 'err' => 'BSC RPC no block time', 'next' => $from];
                }
            }
            $items[] = [
                'chain' => 'bep20',
                'key' => 'bep20:' . strtolower($l['transactionHash']) . ':' . $li,
                'tx' => strtolower($l['transactionHash']),
                'to' => '0x' . substr(strtolower((string) $l['topics'][2]), -40),
                'from' => '0x' . substr(strtolower((string) $l['topics'][1]), -40),
                'contract' => (string) ($l['address'] ?? ''),
                'decimals' => 18,   // the contract is fixed: BSC-USD (Binance-Peg USDT) has 18 decimals
                'units' => usdt_hex_to_dec((string) ($l['data'] ?? '')) ?? '',
                'ts' => $times[$bn] ?? null,
                'conf' => $head - $bn,
            ];
        }
        $from = $end + 1;
        $chunks++;
    }
    return ['ok' => true, 'items' => $items, 'err' => '', 'next' => $from];
}

// ---------- the check ----------

/**
 * Look at one chain: fetch transfers to our address (at most every USDT_CACHE_GAP s unless $force), match them, mark
 * orders paid, list what does not match. Returns ['asked' => bool, 'ok' => bool, 'paid' => [order ids], 'odd' => n,
 * 'err' => string]. Never throws.
 */
function usdt_check_chain(string $chain, bool $force = false, ?int $now = null): array
{
    $now ??= time();
    $res = ['asked' => false, 'ok' => true, 'paid' => [], 'odd' => 0, 'err' => ''];
    try {
        $ours = usdt_settings()[$chain]['address'] ?? '';
        if (!isset(USDT_CHAINS[$chain]) || $ours === '' || !usdt_address_check($chain, $ours)['ok']) {
            return $res;
        }
        $watched = array_values(array_filter(usdt_watched(shop_orders(), $chain, $now), fn($q) => ($chain === 'bep20' ? strtolower($q['address']) === strtolower($ours) : $q['address'] === $ours)));
        if (!$watched) {
            return $res;
        }
        return with_lock('usdt-scan-' . $chain, function () use ($chain, $force, $now, $ours, $watched, $res) {
            $st = usdt_scan_state()[$chain] ?? [];
            if (!$force && $now - (int) ($st['at'] ?? 0) < USDT_CACHE_GAP && $now >= (int) ($st['at'] ?? 0)) {
                return $res;   // asked a moment ago (by another page or the cron): the answer is still fresh
            }
            $since = min(array_map(fn($q) => $q['created'], $watched)) - USDT_EARLY;
            $res['asked'] = true;
            usdt_deadline(microtime(true) + USDT_BUDGET);
            $next = is_array($st['next'] ?? null) && is_int($st['next'][strtolower($ours)] ?? null) ? $st['next'][strtolower($ours)] : null;
            try {
                $got = $chain === 'trc20' ? usdt_tron_transfers($ours, $since) : usdt_bsc_transfers($ours, $since, $now, $next);
            } finally {
                usdt_deadline(null, true);
            }
            $patch = ['at' => $now];
            if ($chain === 'bep20' && is_int($got['next'] ?? null)) {
                $patch['next'] = [strtolower($ours) => $got['next']];
            }
            if (!$got['ok']) {
                $res['ok'] = false;
                $res['err'] = $got['err'];
                $patch += ['err' => $got['err'], 'err_at' => $now];
                error_log('usdt ' . $chain . ': ' . $got['err']);
            } else {
                $patch += ['ok_at' => $now];
            }
            usdt_scan_note($chain, $patch);
            foreach ($got['items'] as $t) {
                if (!is_int($t['ts'] ?? null) || $t['ts'] < $since) {
                    continue;   // older than anything we wait for (the owner's own history): not our business
                }
                $r = usdt_apply($t, $ours, $now);
                if ($r === 'paid') {
                    $res['paid'][] = $t['key'];
                } elseif ($r === 'odd') {
                    $res['odd']++;
                }
            }
            return $res;
        }, 2);
    } catch (Throwable $e) {
        if (str_starts_with($e->getMessage(), 'lock timeout')) {
            return $res;   // another request is asking the chain right now
        }
        error_log('usdt check ' . $chain . ': ' . get_class($e) . ' ' . $e->getMessage());
        return ['asked' => false, 'ok' => false, 'paid' => [], 'odd' => 0, 'err' => 'error'];
    }
}

/**
 * Apply one transfer under the usdt lock (the used-tx registry): 'paid' | 'odd' (listed for the owner) | 'skip' | 'wait'.
 */
function usdt_apply(array $t, string $ours, int $now): string
{
    $after = null;
    $out = with_lock('usdt', function () use ($t, $ours, $now, &$after) {
        $used = usdt_used();
        $m = usdt_match($t, $ours, usdt_quote_rows(shop_orders()), $used);
        if ($m['r'] === 'skip' || $m['r'] === 'wait') {
            return $m['r'];
        }
        $paid = ['chain' => $t['chain'], 'tx' => $t['tx'], 'key' => $t['key'], 'units' => usdt_dec($t['units']), 'from' => (string) ($t['from'] ?? ''),
            'ts' => $t['ts'], 'at' => $now, 'how' => 'auto'];
        if ($m['r'] === 'match') {
            $paid['amount4'] = $m['quote']['amount4'];
            $paid['rate'] = $m['quote']['rate'];
            try {
                $before = shop_page_signature(shop_products());
                $o = shop_owner_action($m['order'], 'confirm', ['by' => 'usdt', 'note' => USDT_CHAINS[$t['chain']]['label'] . ' ' . $t['tx'], 'usdt_paid' => $paid], $now);
                usdt_mark_used($t['key'], $m['order'], $now);
                $after = ['order' => $o, 'before' => $before];
                return 'paid';
            } catch (ShopError $e) {
                $m = ['r' => 'unmatched', 'why' => $e->code_ === 'stock' ? 'stock' : 'closed', 'near' => $m['order']];
            }
        }
        usdt_mark_used($t['key'], null, $now);
        usdt_unmatched_add($t, $m['why'], $m['near'] ?? null, $now);
        $after = ['odd' => $t, 'why' => $m['why']];
        return 'odd';
    });
    if ($out === 'paid' && is_array($after) && isset($after['order'])) {
        usdt_after_paid($after['order'], $after['before']);
    } elseif ($out === 'odd' && is_array($after)) {
        notify_usdt_odd($after['odd'], $after['why']);
    }
    return $out;
}

function usdt_mark_used(string $key, ?string $order, int $now): void
{
    json_update(usdt_dir('used.json'), function ($d) use ($key, $order, $now) {
        $keys = is_array($d['keys'] ?? null) ? $d['keys'] : [];
        $keys = array_filter($keys, fn($v) => is_array($v) && (int) ($v['at'] ?? 0) > $now - 60 * 86400);   // older ones can no longer fall in any window
        $keys[$key] = ['at' => $now, 'order' => $order];
        return ['keys' => $keys];
    }, []);
}

function usdt_unmatched_add(array $t, string $why, ?string $near, int $now): void
{
    json_update(usdt_dir('unmatched.json'), function ($d) use ($t, $why, $near, $now) {
        $items = is_array($d['items'] ?? null) ? $d['items'] : [];
        array_unshift($items, [
            'id' => bin2hex(random_bytes(6)),
            'key' => $t['key'],
            'chain' => $t['chain'],
            'tx' => $t['tx'],
            'from' => (string) ($t['from'] ?? ''),
            'to' => (string) $t['to'],
            'units' => usdt_dec($t['units']),
            'ts' => $t['ts'],
            'seen' => $now,
            'why' => $why,
            'near' => $near,
            'state' => 'open',
            'order' => null,
            'done_at' => null,
        ]);
        // keep every open one; drop the oldest handled ones past the cap
        $open = array_filter($items, fn($x) => ($x['state'] ?? '') === 'open');
        $done = array_slice(array_values(array_filter($items, fn($x) => ($x['state'] ?? '') !== 'open')), 0, max(0, USDT_UNMATCHED_MAX - count($open)));
        $keep = array_flip(array_map(fn($x) => $x['id'], array_merge($open, $done)));
        return ['items' => array_values(array_filter($items, fn($x) => isset($keep[$x['id']])))];
    }, []);
}

/** After an order was marked paid in USDT: 货源 buying (after the response in a web request), republish, 微信提醒. */
function usdt_after_paid(array $o, string $before): void
{
    notify_usdt_paid($o);
    $run = function () use ($o, $before): void {
        try {
            if ($o['type'] === 'supply') {
                $r = supply_fulfill($o['id'], 'confirm');
                audit('supply_trade', $o['id'] . ($r['ok'] ? ' ok' : ' failed') . ' usdt');
            }
        } catch (Throwable $e) {
            error_log('usdt fulfil: ' . get_class($e));
        }
        try {
            shop_republish_if_changed($before);
        } catch (Throwable $e) {
            error_log('usdt republish: ' . get_class($e));
        }
    };
    if (PHP_SAPI === 'cli') {
        $run();
        return;
    }
    register_shutdown_function(function () use ($run): void {
        ignore_user_abort(true);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        $run();
    });
}

/** Check every chain that has a quote waiting (the cron, the admin 现在检查). */
function usdt_check_all(bool $force = false, ?int $now = null): array
{
    $out = [];
    foreach (array_keys(USDT_CHAINS) as $c) {
        $out[$c] = usdt_check_chain($c, $force, $now);
    }
    return $out;
}

/** Any quote waiting on any chain? (the cron exits at once when not) */
function usdt_any_watched(?int $now = null): bool
{
    $now ??= time();
    $orders = shop_orders();
    foreach (array_keys(USDT_CHAINS) as $c) {
        if (usdt_watched($orders, $c, $now)) {
            return true;
        }
    }
    return false;
}

// ---------- the owner: attach an unmatched transfer to an order by hand, or dismiss it ----------

function usdt_unmatched_get(string $id): ?array
{
    foreach (usdt_unmatched_doc()['items'] as $x) {
        if ($x['id'] === $id) {
            return $x;
        }
    }
    return null;
}

/** Mark $orderId paid with the unmatched transfer $itemId (owner's decision). Returns the order. Throws ShopError. */
function usdt_attach(string $itemId, string $orderId, ?int $now = null): array
{
    $now ??= time();
    $o = with_lock('usdt', function () use ($itemId, $orderId, $now) {
        $x = usdt_unmatched_get($itemId);
        if ($x === null || $x['state'] !== 'open') {
            throw new ShopError('state', '这笔到账已经处理过了，刷新页面看看。');
        }
        $o = shop_order_get($orderId);
        if ($o === null) {
            throw new ShopError('gone', '找不到订单 ' . $orderId . '。');
        }
        if (usdt_paid($o) !== null) {
            throw new ShopError('state', '这个订单已经用 USDT 付过了。');
        }
        $paid = ['chain' => $x['chain'], 'tx' => $x['tx'], 'key' => $x['key'], 'units' => $x['units'], 'from' => $x['from'], 'ts' => $x['ts'], 'at' => $now, 'how' => 'manual'];
        $o = shop_owner_action($orderId, 'confirm', ['by' => 'owner', 'note' => 'USDT 手动对账 ' . USDT_CHAINS[$x['chain']]['label'] . ' ' . $x['tx'], 'usdt_paid' => $paid], $now);
        json_update(usdt_dir('unmatched.json'), function ($d) use ($itemId, $orderId, $now) {
            foreach ($d['items'] as &$it) {
                if ($it['id'] === $itemId) {
                    $it['state'] = 'attached';
                    $it['order'] = $orderId;
                    $it['done_at'] = $now;
                }
            }
            unset($it);
            return $d;
        }, []);
        return $o;
    });
    return $o;
}

function usdt_dismiss(string $itemId, ?int $now = null): void
{
    $now ??= time();
    with_lock('usdt', function () use ($itemId, $now) {
        $x = usdt_unmatched_get($itemId);
        if ($x === null || $x['state'] !== 'open') {
            throw new ShopError('state', '这笔到账已经处理过了，刷新页面看看。');
        }
        json_update(usdt_dir('unmatched.json'), function ($d) use ($itemId, $now) {
            foreach ($d['items'] as &$it) {
                if ($it['id'] === $itemId) {
                    $it['state'] = 'dismissed';
                    $it['done_at'] = $now;
                }
            }
            unset($it);
            return $d;
        }, []);
    });
}

// ---------- 微信提醒 ----------

function notify_usdt_paid(array $o): void
{
    try {
        if (!notify_on('usdt')) {
            return;
        }
        $p = usdt_paid($o);
        $c = USDT_CHAINS[$p['chain']];
        notify_send('usdt', 'USDT 到账，已自动确认 ¥' . shop_money((int) $o['amount']), implode("\n", [
            '订单号：' . $o['id'],
            '商品：' . notify_line($o['name'], 40) . ' × ' . (int) $o['qty'],
            '收到：' . usdt_units_text((string) $p['units'], $c['decimals']) . ' USDT（' . $c['label'] . '）',
            '时间：' . local_time(now_iso(), 'n月j日 H:i'),
            $o['type'] === 'physical' ? '记得发货，寄出后在后台填快递单号。' : '不用你操作，买家已经能在「查订单」里看到内容。',
            '后台：' . notify_admin_url('order', ['id' => $o['id']]),
        ]));
    } catch (Throwable $e) {
        error_log('notify usdt: ' . get_class($e));
    }
}

function notify_usdt_odd(array $t, string $why): void
{
    try {
        if (!notify_on('usdt')) {
            return;
        }
        $c = USDT_CHAINS[$t['chain']];
        $amt = usdt_units_text((string) usdt_dec($t['units']), $c['decimals']);
        notify_send('usdt', 'USDT 到账没对上：' . $amt . ' USDT', implode("\n", [
            '收到：' . $amt . ' USDT（' . $c['label'] . '）',
            '原因：' . (USDT_WHY[$why] ?? $why),
            '没有自动确认任何订单。请在后台核对，需要的话手动对账或退回。',
            '后台：' . notify_admin_url('usdt'),
        ]));
    } catch (Throwable $e) {
        error_log('notify usdt odd: ' . get_class($e));
    }
}
