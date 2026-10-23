<?php
// 货源 (SPEC §20 货源): the owner resells game top-up cards from a supplier store (发卡系统「店铺共享」API).
// Only the admin and the owner's 确认收款 make outbound calls; buyers never reach the supplier directly. The app key
// lives in secrets.json (never sent to a browser, never logged). Every call goes to a validated https host whose DNS
// answers are all public addresses, pinned for the connection (no SSRF into the hosting network, no DNS rebinding),
// with no redirects, a 10 s timeout and a 2 MB answer cap. A paid order is bought at most once: one global trade
// lock, a request_no derived from the order number, and a state machine that never re-buys an unknown outcome.
declare(strict_types=1);
defined('BLOG_APP') || exit;

const SUPPLY_SERVER_IP = '45.152.64.62';      // the hosting server; the supplier's 对接白名单 needs it
const SUPPLY_TIMEOUT = 10;
const SUPPLY_CONNECT_TIMEOUT = 5;
const SUPPLY_MAX_BYTES = 2 * 1024 * 1024;
const SUPPLY_CACHE_TTL = 300;                 // catalog, details and stock: 5 minutes
const SUPPLY_POLL_GAP = 60;                   // /query for a card that is not out yet: once a minute per order
const SUPPLY_COST_RISE = 10;                  // percent the cost may rise over what the order saw before we refuse
const SUPPLY_CODE_RE = '/^[A-Za-z0-9_\-]{1,64}$/';
const SUPPLY_APP_ID_RE = '/^[A-Za-z0-9_\-]{1,64}$/';
const SUPPLY_KEY_RE = '/^[\x21-\x7E]{6,200}$/';
const SUPPLY_SECRET_MAX = 5000;
const SUPPLY_PORTS = [443, 8443];
const SUPPLY_CONTACT = [0 => '任意', 1 => '手机号', 2 => '邮箱', 3 => 'QQ 号'];
const SUPPLY_STATE = [
    'new' => '还没进货',
    'trading' => '正在进货',
    'done' => '进货成功',
    'refused' => '供货平台拒绝了',
    'unknown' => '结果不确定',
    'unclear' => '需要你核对',
    'blocked' => '没有进货',
];

// ---------- settings and the write-only key ----------

function supply_settings(?array $settings = null): array
{
    return ($settings ?? settings_get())['supply'];
}

function supply_key(): ?string
{
    $k = secrets_get()['supply_app_key'] ?? null;
    return is_string($k) && preg_match(SUPPLY_KEY_RE, $k) ? $k : null;
}

function supply_key_hint(): ?string
{
    $k = supply_key();
    return $k === null ? null : substr($k, -4);
}

function supply_key_set_at(): ?string
{
    $t = secrets_get()['supply_key_set_at'] ?? null;
    return is_string($t) ? $t : null;
}

function supply_key_set(string $key): void
{
    $key = trim($key);
    if (!preg_match(SUPPLY_KEY_RE, $key)) {
        throw new ValidationError(['app_key' => '密钥只能是字母、数字和符号，6 到 200 位，不能有空格。请从供货平台复制完整的对接密钥。']);
    }
    secrets_update(function (array $s) use ($key) {
        $s['supply_app_key'] = $key;
        $s['supply_key_set_at'] = now_iso();
        return $s;
    });
    @chmod(data_path('secrets.json'), 0600);
}

function supply_key_remove(): void
{
    secrets_update(function (array $s) {
        unset($s['supply_app_key'], $s['supply_key_set_at']);
        return $s;
    });
}

/** Switched on, address, app_id and key all set. */
function supply_ready(?array $settings = null): bool
{
    $s = supply_settings($settings);
    return $s['enabled'] && $s['base_url'] !== '' && $s['app_id'] !== '' && supply_key() !== null;
}

/** Tests only: a php -S mock on 127.0.0.1 over plain http. Never on the web server (its SAPI is not cli). */
function supply_test_loopback(): bool
{
    return in_array(PHP_SAPI, ['cli', 'cli-server'], true) && getenv('BLOG_SUPPLY_TEST_LOOPBACK') === '1';
}

// ---------- signing ----------

/**
 * The supplier's signature (its PHP reference): drop sign, ksort, drop values that are exactly '', then
 * md5(urldecode(http_build_query($data) . '&key=' . $appKey)). Array values (sku) are signed as submitted.
 */
function supply_sign(array $data, string $appKey): string
{
    unset($data['sign']);
    ksort($data);
    foreach ($data as $k => $v) {
        if ($v === '') {
            unset($data[$k]);
        }
    }
    return md5(urldecode(http_build_query($data) . '&key=' . $appKey));
}

// ---------- the address (SSRF) ----------

/** Extra ranges that filter_var does not treat as private or reserved. */
const SUPPLY_BLOCKED_CIDRS = ['0.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15',
    '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/3', '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '100::/64', '2001:db8::/32',
    '2002::/16', 'fc00::/7', 'fe80::/10', 'ff00::/8'];

function supply_ip_in(string $ip, string $cidr): bool
{
    [$net, $bits] = explode('/', $cidr);
    $a = @inet_pton($ip);
    $b = @inet_pton($net);
    if ($a === false || $b === false || strlen($a) !== strlen($b)) {
        return false;
    }
    $bits = (int) $bits;
    $bytes = intdiv($bits, 8);
    if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
        return false;
    }
    if ($bits % 8 === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $bits % 8)) & 0xFF;
    return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
}

/** A public unicast address (not private, loopback, link-local, CGNAT, documentation, multicast, mapped …). */
function supply_ip_public(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return false;
    }
    foreach (SUPPLY_BLOCKED_CIDRS as $c) {
        if (supply_ip_in($ip, $c)) {
            return false;
        }
    }
    return true;
}

/**
 * Check the address the owner typed. Returns ['ok' => true, 'url' (normalized, no trailing /), 'host', 'port', 'path',
 * 'scheme'] or ['ok' => false, 'msg']. No DNS here (see supply_resolve()).
 */
function supply_url_parse(string $raw, ?bool $loopback = null): array
{
    $loopback ??= supply_test_loopback();
    $bad = fn(string $m) => ['ok' => false, 'msg' => $m];
    $u = trim($raw);
    if ($u === '' || strlen($u) > 200 || preg_match('/[\x00-\x20\x7F\\\\]/', $u)) {
        return $bad('请填供货平台的网址，像 https://example.com 这样。');
    }
    $p = parse_url($u);
    if (!is_array($p) || !isset($p['scheme'], $p['host'])) {
        return $bad('网址格式不对，要以 https:// 开头，像 https://example.com 这样。');
    }
    $scheme = strtolower($p['scheme']);
    if ($scheme !== 'https' && !($loopback && $scheme === 'http')) {
        return $bad('只能用 https:// 开头的网址（加密连接），这样密钥和卡密在路上不会被看到。');
    }
    if (isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) {
        return $bad('网址里不要带账号、问号后面的参数或 # 号，只填到域名（和路径）。');
    }
    $host = strtolower(rtrim($p['host'], '.'));
    $port = isset($p['port']) ? (int) $p['port'] : null;
    $path = rtrim((string) ($p['path'] ?? ''), '/');
    if ($path !== '' && !preg_match('#^(/[A-Za-z0-9._~\-]+)+$#', $path)) {
        return $bad('网址的路径部分只能有字母、数字和 . _ ~ -。');
    }
    if ($path !== '' && preg_match('#/\.\.?(/|$)#', $path)) {
        return $bad('网址的路径部分不对。');
    }
    $ipHost = trim($host, '[]');
    if (filter_var($ipHost, FILTER_VALIDATE_IP) !== false) {
        if (!supply_ip_public($ipHost) && !($loopback && in_array($ipHost, ['127.0.0.1', '::1'], true))) {
            return $bad('这个地址是内网或本机地址，不能用。请填供货平台的公网域名。');
        }
    } elseif (!preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9\-]{0,61}[a-z0-9]$/', $host)
        || preg_match('/(^|\.)(localhost|local|internal|intranet|lan|home|corp|localdomain|home\.arpa|in-addr\.arpa|ip6\.arpa|test|invalid|example)$/', $host)) {
        return $bad('域名格式不对，或者是内网域名。请填供货平台的公网域名，像 https://example.com 这样。');
    }
    if ($port !== null && !in_array($port, SUPPLY_PORTS, true) && !$loopback) {
        return $bad('端口只能是 443 或 8443（一般不用填端口）。');
    }
    $hostOut = str_contains($ipHost, ':') ? '[' . $ipHost . ']' : $host;
    return ['ok' => true, 'scheme' => $scheme, 'host' => $ipHost, 'port' => $port ?? ($scheme === 'https' ? 443 : 80), 'path' => $path,
        'url' => $scheme . '://' . $hostOut . ($port !== null ? ':' . $port : '') . $path];
}

/** DNS answers for the host; every one must be public. Returns ['ok', 'ips' => [...], 'msg']. */
function supply_resolve(string $host, ?bool $loopback = null): array
{
    $loopback ??= supply_test_loopback();
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        $ips = [$host];
    } else {
        $ips = [];
        $v4 = @gethostbynamel($host);
        foreach (is_array($v4) ? $v4 : [] as $ip) {
            $ips[] = $ip;
        }
        $v6 = @dns_get_record($host, DNS_AAAA);
        foreach (is_array($v6) ? $v6 : [] as $r) {
            if (isset($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
        $ips = array_values(array_unique($ips));
    }
    if (!$ips) {
        return ['ok' => false, 'ips' => [], 'msg' => '找不到这个域名（DNS 查不到地址）。请核对供货平台的网址。'];
    }
    foreach ($ips as $ip) {
        $lb = $loopback && in_array($ip, ['127.0.0.1', '::1'], true);
        if (!supply_ip_public($ip) && !$lb) {
            return ['ok' => false, 'ips' => [], 'msg' => '这个域名指向内网或本机地址，不能用。'];
        }
    }
    usort($ips, fn($a, $b) => (int) str_contains($a, ':') <=> (int) str_contains($b, ':'));   // IPv4 first
    return ['ok' => true, 'ips' => $ips, 'msg' => ''];
}

// ---------- one call ----------

/**
 * POST one API path. Returns ['ok' => bool, 'kind' => ok|refused|net|bad|config, 'code' => ?int, 'msg' => string,
 * 'data' => mixed]. kind: ok = code 200; refused = the supplier answered with another code (a definite no);
 * net = no answer or a broken one (the outcome is unknown); bad = an answer that is not the API's JSON (also unknown);
 * config = nothing was sent. msg is plain Chinese for the owner. Nothing about the request or answer is logged.
 * $cfg (tests): base_url, app_id, app_key instead of the saved ones.
 */
function supply_call(string $path, array $params = [], ?array $cfg = null): array
{
    $fail = fn(string $kind, string $msg, ?int $code = null) => ['ok' => false, 'kind' => $kind, 'code' => $code, 'msg' => $msg, 'data' => null];
    if ($cfg === null) {
        $s = supply_settings();
        $cfg = ['base_url' => $s['base_url'], 'app_id' => $s['app_id'], 'app_key' => supply_key()];
    }
    if (($cfg['base_url'] ?? '') === '' || ($cfg['app_id'] ?? '') === '' || !is_string($cfg['app_key'] ?? null)) {
        return $fail('config', '货源还没设置好：网址、app_id 和密钥都要填。');
    }
    if (!preg_match('#^/shared/[a-zA-Z/]{3,60}$#', $path)) {
        return $fail('config', '接口地址不对。');
    }
    $u = supply_url_parse((string) $cfg['base_url']);
    if (!$u['ok']) {
        return $fail('config', $u['msg']);
    }
    $dns = supply_resolve($u['host']);
    if (!$dns['ok']) {
        return $fail('config', $dns['msg']);
    }
    $data = ['app_id' => (string) $cfg['app_id']];
    foreach ($params as $k => $v) {
        if ($v === null || $v === '') {
            continue;
        }
        $data[$k] = is_array($v) ? $v : (string) $v;
    }
    $data['sign'] = supply_sign($data, (string) $cfg['app_key']);
    $body = http_build_query($data);
    $r = function_exists('curl_init') ? supply_post_curl($u, $dns['ips'][0], $path, $body) : supply_post_stream($u, $dns['ips'][0], $path, $body);
    if ($r['error'] !== null) {
        error_log('supply ' . $path . ': ' . $r['error']);
        return $fail('net', $r['error'] === 'too_large' ? '供货平台返回的内容太大，没有处理。' : ($r['error'] === 'timeout' ? '供货平台 ' . SUPPLY_TIMEOUT . ' 秒内没有回应。' : '连不上供货平台（网络或证书问题）。'));
    }
    if ($r['status'] >= 300 && $r['status'] < 400) {
        error_log('supply ' . $path . ': redirect ' . $r['status']);
        return $fail('bad', '供货平台要求跳转到别的地址，没有跟着跳。请核对网址（比如要不要带 www）。');
    }
    $j = json_decode($r['body'], true, 32);
    if (!is_array($j) || !array_key_exists('code', $j) || !is_numeric($j['code'])) {
        error_log('supply ' . $path . ': not json, http ' . $r['status']);
        return $fail('bad', $r['status'] === 404 ? '供货平台上没有这个接口（404）。请核对网址是不是店铺的主域名。' : '供货平台返回的不是接口数据（HTTP ' . $r['status'] . '）。请核对网址。');
    }
    $code = (int) $j['code'];
    $msg = supply_clean_msg($j['msg'] ?? '');
    if ($code !== 200) {
        return ['ok' => false, 'kind' => 'refused', 'code' => $code, 'msg' => $msg !== '' ? $msg : '供货平台拒绝了这次请求（代码 ' . $code . '）。', 'data' => $j['data'] ?? null];
    }
    return ['ok' => true, 'kind' => 'ok', 'code' => 200, 'msg' => $msg, 'data' => $j['data'] ?? null];
}

/** A supplier message for the owner's eyes: one line, no control characters, at most 200 characters. */
function supply_clean_msg(mixed $m): string
{
    if (!is_string($m) && !is_int($m) && !is_float($m)) {
        return '';
    }
    $m = (string) $m;
    if (!mb_check_encoding($m, 'UTF-8')) {
        return '';
    }
    $m = trim((string) preg_replace('/[\x00-\x1F\x7F\x{2028}\x{2029}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\s]+/u', ' ', strip_tags($m)));
    return mb_substr($m, 0, 200);
}

/** @return array{status:int, body:string, error:?string} */
function supply_post_curl(array $u, string $ip, string $path, string $body): array
{
    $buf = '';
    $tooLarge = false;
    $ch = curl_init($u['url'] . $path);
    $proto = $u['scheme'] === 'https' ? CURLPROTO_HTTPS : CURLPROTO_HTTP;
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json', 'Expect:'],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_PROTOCOLS => $proto,
        CURLOPT_REDIR_PROTOCOLS => $proto,
        CURLOPT_CONNECTTIMEOUT => SUPPLY_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => SUPPLY_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROXY => '',                     // never through an environment proxy: the pinned address is the one used
        CURLOPT_RESOLVE => [$u['host'] . ':' . $u['port'] . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],
        CURLOPT_USERAGENT => 'pilot-blog-shop/1',
        CURLOPT_ENCODING => '',
        CURLOPT_HEADER => false,
        CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$buf, &$tooLarge): int {
            if (strlen($buf) + strlen($chunk) > SUPPLY_MAX_BYTES) {
                $tooLarge = true;
                return 0;
            }
            $buf .= $chunk;
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($ch);
    $errno = curl_errno($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($tooLarge) {
        return ['status' => $status, 'body' => '', 'error' => 'too_large'];
    }
    if ($ok === false || $errno !== 0) {
        return ['status' => $status, 'body' => '', 'error' => $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'curl ' . $errno];
    }
    return ['status' => $status, 'body' => $buf, 'error' => null];
}

/** Fallback without the curl extension: the same rules over PHP streams (pinned address, SNI and peer name = host). */
function supply_post_stream(array $u, string $ip, string $path, string $body): array
{
    $hostHdr = $u['host'] . (in_array($u['port'], [443, 80], true) ? '' : ':' . $u['port']);
    $target = $u['scheme'] . '://' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip) . ':' . $u['port'] . $path;
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Host: $hostHdr\r\nContent-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\nUser-Agent: pilot-blog-shop/1\r\nConnection: close\r\n",
            'content' => $body,
            'timeout' => SUPPLY_TIMEOUT,
            'follow_location' => 0,
            'max_redirects' => 0,
            'ignore_errors' => true,
            'protocol_version' => 1.1,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $u['host'], 'SNI_enabled' => true, 'allow_self_signed' => false],
    ]);
    $t0 = microtime(true);
    $fp = @fopen($target, 'r', false, $ctx);
    if ($fp === false) {
        return ['status' => 0, 'body' => '', 'error' => microtime(true) - $t0 >= SUPPLY_TIMEOUT - 0.5 ? 'timeout' : 'stream'];
    }
    $meta = stream_get_meta_data($fp);
    $buf = (string) stream_get_contents($fp, SUPPLY_MAX_BYTES + 1);
    fclose($fp);
    $status = 0;
    foreach ((array) ($meta['wrapper_data'] ?? []) as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $h, $m)) {
            $status = (int) $m[1];
        }
    }
    if (strlen($buf) > SUPPLY_MAX_BYTES) {
        return ['status' => $status, 'body' => '', 'error' => 'too_large'];
    }
    return ['status' => $status, 'body' => $buf, 'error' => null];
}

/** Plain Chinese result of 测试连接. */
function supply_test_connection(?array $cfg = null): array
{
    $r = supply_call('/shared/authentication/connect', [], $cfg);
    if ($r['ok']) {
        return ['ok' => true, 'msg' => '连接正常：网址、app_id 和密钥都对。'];
    }
    return ['ok' => false, 'msg' => supply_fail_text($r)];
}

/** A failed call in the owner's words, with the whitelist hint when the supplier may be refusing this server. */
function supply_fail_text(array $r): string
{
    $t = match ($r['kind']) {
        'config' => $r['msg'],
        'refused' => '供货平台拒绝了：' . strip_full_stop_cn($r['msg']) . '。',
        default => $r['msg'],
    };
    if ($r['kind'] === 'refused' || $r['kind'] === 'net') {
        $t .= ' ' . supply_whitelist_hint();
    }
    return $t;
}

function supply_whitelist_hint(): string
{
    return '如果提示 IP 不对或不在白名单，请在供货平台的「对接白名单」里加上本站服务器的 IP：' . SUPPLY_SERVER_IP . '。也请核对 app_id 和密钥是不是从同一个店铺复制的。';
}

function strip_full_stop_cn(string $s): string
{
    return (string) preg_replace('/[。．.！!\s]+$/u', '', $s);   // not rtrim: it works on bytes and would cut a UTF-8 character
}

// ---------- reading the supplier's answers ----------

/** '10.5', 10.5, '10' → fen; null for anything that is not a plain non-negative amount. */
function supply_fen(mixed $v): ?int
{
    if (is_int($v) || is_float($v)) {
        $v = number_format((float) $v, 2, '.', '');
    }
    if (!is_string($v) || !preg_match('/^\d{1,9}(\.\d{1,6})?$/', trim($v))) {
        return null;
    }
    $f = (float) trim($v);
    return is_finite($f) ? (int) round($f * 100) : null;
}

function supply_int(mixed $v, int $default = 0): int
{
    return is_numeric($v) ? (int) $v : $default;
}

/** One line of supplier text (names), cleaned like buyer text. */
function supply_line(mixed $v, int $max): string
{
    return mb_substr(shop_clean_line(is_scalar($v) ? html_entity_decode(strip_tags((string) $v), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '', $max), 0, $max);
}

/** The supplier's HTML description as plain text paragraphs (never raw HTML). */
function supply_html_to_text(mixed $html, int $max = 3000): string
{
    if (!is_string($html) || !mb_check_encoding($html, 'UTF-8')) {
        return '';
    }
    $s = (string) preg_replace('#<(script|style|iframe|object|svg|template)\b[^>]*>.*?</\1\s*>#is', '', $html);
    $s = (string) preg_replace('#<\s*(br|/p|/div|/li|/h[1-6]|/tr|hr)\b[^>]*>#i', "\n", $s);
    $s = (string) preg_replace('#<\s*li\b[^>]*>#i', "\n- ", $s);
    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = str_replace(["\r\n", "\r", "\t", "\u{00A0}"], ["\n", "\n", ' ', ' '], $s);
    $s = (string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F\x{2028}\x{2029}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $s);
    $lines = [];
    foreach (explode("\n", $s) as $l) {
        $l = trim((string) preg_replace('/ {2,}/', ' ', $l));
        if ($l !== '') {
            $lines[] = $l;
        }
    }
    return mb_substr(implode("\n", $lines), 0, $max);
}

/** Card content from the supplier: lines kept, control characters removed, capped. */
function supply_secret_clean(mixed $s): string
{
    if (is_array($s)) {
        $s = implode("\n", array_map(fn($v) => is_scalar($v) ? (string) $v : '', $s));
    }
    if (!is_scalar($s)) {
        return '';
    }
    $s = (string) $s;
    if (!mb_check_encoding($s, 'UTF-8')) {
        $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    }
    $s = (string) preg_replace('#<br\s*/?>#i', "\n", $s);
    $s = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $s);
    $s = (string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $s);
    return trim(mb_substr($s, 0, SUPPLY_SECRET_MAX));
}

/** Empty, or a short note that the card is not out yet (manual delivery). */
function supply_secret_pending(string $s): bool
{
    $t = trim($s);
    return $t === '' || (mb_strlen($t) <= 200 && (bool) preg_match('/(等待|稍后|稍等|人工|手动|正在发货|发货中|处理中|待发货|未发货|补货中|waiting|pending|processing)/iu', $t));
}

/** A product as listed by /items or detailed by /item, in one shape. */
function supply_item_norm(array $i): ?array
{
    $code = is_scalar($i['code'] ?? null) ? (string) $i['code'] : '';
    if (!preg_match(SUPPLY_CODE_RE, $code)) {
        return null;
    }
    $cfg = is_array($i['config'] ?? null) ? $i['config'] : [];
    $races = [];
    $cat = $cfg['category'] ?? null;
    if (is_array($cat)) {
        foreach ($cat as $k => $v) {
            // the name goes back to the supplier as `race` exactly as it came, so it is kept as is (only checked)
            $name = is_string($k) ? $k : (is_scalar($v) ? (string) $v : '');
            if ($name !== '' && mb_check_encoding($name, 'UTF-8') && mb_strlen($name) <= 60 && trim($name) === $name
                && !preg_match('/[\x00-\x1F\x7F\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $name) && count($races) < 60) {
                $races[$name] = is_string($k) ? supply_fen($v) : null;
            }
        }
    }
    $sku = $cfg['sku'] ?? null;
    $widget = $i['widget'] ?? null;
    if (is_string($widget)) {
        $wd = json_decode($widget, true);
        $widget = is_array($wd) ? $wd : (trim($widget) === '' || trim($widget) === '[]' ? null : [$widget]);
    }
    return [
        'id' => supply_int($i['id'] ?? 0),
        'code' => $code,
        'name' => supply_line($i['name'] ?? '', 80),
        'price' => supply_fen($i['price'] ?? null),
        'user_price' => supply_fen($i['user_price'] ?? null),
        'stock' => supply_int($i['stock'] ?? 0),
        'delivery_way' => supply_int($i['delivery_way'] ?? 0),
        'draft_status' => supply_int($i['draft_status'] ?? 0),
        'contact_type' => supply_int($i['contact_type'] ?? 0),
        'password_status' => supply_int($i['password_status'] ?? 0),
        'seckill_status' => supply_int($i['seckill_status'] ?? 0),
        'minimum' => max(0, supply_int($i['minimum'] ?? 0)),
        'maximum' => max(0, supply_int($i['maximum'] ?? 0)),
        'races' => $races,
        'has_sku' => is_array($sku) ? count($sku) > 0 : (is_string($sku) && trim($sku) !== ''),
        'has_widget' => is_array($widget) && count($widget) > 0,
        'description' => supply_html_to_text($i['description'] ?? ''),
        'detail' => array_key_exists('contact_type', $i),
    ];
}

/** Unit cost for this account: user_price, else price. */
function supply_item_cost(array $it, string $race = ''): ?int
{
    if ($race !== '' && isset($it['races'][$race]) && $it['races'][$race] !== null) {
        return $it['races'][$race];
    }
    return $it['user_price'] ?? $it['price'];
}

/** Why a product cannot be sold here (empty list = it can). Needs the detail (/item). */
function supply_unsupported(array $it): array
{
    $r = [];
    if ($it['has_sku']) {
        $r[] = '要选规格（SKU），商城的下单页不支持';
    }
    if ($it['has_widget']) {
        $r[] = '下单时要填额外信息（比如游戏账号、区服），商城的下单页不支持';
    }
    if ($it['password_status'] === 1) {
        $r[] = '下单要设查询密码，暂不支持';
    }
    if (in_array($it['contact_type'], [1, 3], true)) {
        $r[] = '要买家的' . SUPPLY_CONTACT[$it['contact_type']] . '，商城只收邮箱';
    }
    if ($it['seckill_status'] === 1) {
        $r[] = '是限时秒杀商品，暂不支持';
    }
    if ($it['minimum'] > SHOP_QTY_MAX) {
        $r[] = '起购数量是 ' . $it['minimum'] . ' 件，超过商城每单最多 ' . SHOP_QTY_MAX . ' 件';
    }
    if ($it['maximum'] > 0 && $it['maximum'] < max(1, $it['minimum'])) {
        $r[] = '起购和限购数量对不上';
    }
    if (supply_item_cost($it) === null) {
        $r[] = '供货平台没有给出价格';
    }
    return $r;
}

// ---------- cache (blog-data/supply/cache, 5 minutes) ----------

function supply_cache_path(string $name): string
{
    return data_path('supply', 'cache', substr(hash('sha256', $name), 0, 32) . '.json');
}

function supply_ensure_dirs(): void
{
    foreach (['supply', 'supply/cache'] as $d) {
        $p = DATA_DIR . '/' . $d;
        if (!is_dir($p) && !@mkdir($p, 0700, true) && !is_dir($p)) {
            throw new RuntimeException('cannot create ' . $d);
        }
    }
}

/** A successful answer cached for $ttl seconds; failures are never cached. */
function supply_cached(string $name, bool $fresh, callable $fetch, int $ttl = SUPPLY_CACHE_TTL): array
{
    supply_ensure_dirs();
    $f = supply_cache_path($name);
    if (!$fresh) {
        $c = json_read($f, null);
        if (is_array($c) && isset($c['at'], $c['r']) && time() - (int) $c['at'] < $ttl) {
            return $c['r'] + ['cached_at' => (int) $c['at']];
        }
    }
    $r = $fetch();
    if ($r['ok']) {
        json_write_atomic($f, ['at' => time(), 'r' => $r]);
    }
    return $r + ['cached_at' => time()];
}

function supply_cache_clear(): void
{
    foreach (glob(DATA_DIR . '/supply/cache/*.json') ?: [] as $f) {
        @unlink($f);
    }
}

/** Categories with their products: ['ok', 'msg', 'cats' => [['name', 'items' => [...]]]]. */
function supply_catalog(bool $fresh = false): array
{
    $s = supply_settings();
    return supply_cached('items|' . $s['base_url'] . '|' . $s['app_id'], $fresh, function () {
        $r = supply_call('/shared/commodity/items');
        if (!$r['ok']) {
            return ['ok' => false, 'msg' => supply_fail_text($r), 'cats' => []];
        }
        $cats = [];
        foreach (is_array($r['data']) ? array_slice($r['data'], 0, 200) : [] as $c) {
            if (!is_array($c)) {
                continue;
            }
            $items = [];
            foreach (is_array($c['children'] ?? null) ? array_slice($c['children'], 0, 500) : [] as $i) {
                $n = is_array($i) ? supply_item_norm($i) : null;
                if ($n !== null) {
                    $items[] = $n;
                }
            }
            $cats[] = ['name' => supply_line($c['name'] ?? '', 40) ?: '未分类', 'items' => $items];
        }
        return ['ok' => true, 'msg' => '', 'cats' => $cats];
    });
}

/** One product's detail: ['ok', 'msg', 'item' => normalized|null]. */
function supply_item(string $code, bool $fresh = false): array
{
    if (!preg_match(SUPPLY_CODE_RE, $code)) {
        return ['ok' => false, 'msg' => '商品编号不对。', 'item' => null];
    }
    $s = supply_settings();
    return supply_cached('item|' . $s['base_url'] . '|' . $code, $fresh, function () use ($code) {
        $r = supply_call('/shared/commodity/item', ['code' => $code]);
        if (!$r['ok']) {
            return ['ok' => false, 'msg' => supply_fail_text($r), 'item' => null];
        }
        $n = is_array($r['data']) ? supply_item_norm($r['data'] + ['contact_type' => $r['data']['contact_type'] ?? 0]) : null;
        if ($n === null || $n['code'] !== $code) {
            return ['ok' => false, 'msg' => '供货平台返回的商品信息不完整。', 'item' => null];
        }
        return ['ok' => true, 'msg' => '', 'item' => $n];
    });
}

/** Total cost of $num for this account: ['ok', 'price' => fen, 'r' => call]. */
function supply_valuation(string $code, string $race, int $num): array
{
    $r = supply_call('/shared/commodity/valuation', ['code' => $code, 'num' => $num, 'race' => $race]);
    $p = $r['ok'] && is_array($r['data']) ? supply_fen($r['data']['price'] ?? null) : null;
    return ['ok' => $r['ok'] && $p !== null, 'price' => $p, 'r' => $r['ok'] && $p === null ? ['kind' => 'bad', 'msg' => '供货平台没有给出价格。'] + $r : $r];
}

/** Stock now: ['ok', 'stock' => int]. */
function supply_stock(string $code, string $race): array
{
    $r = supply_call('/shared/commodity/stock', ['code' => $code, 'race' => $race]);
    return ['ok' => $r['ok'] && is_array($r['data']) && is_numeric($r['data']['stock'] ?? null), 'stock' => $r['ok'] && is_array($r['data']) ? max(0, supply_int($r['data']['stock'] ?? 0)) : 0, 'r' => $r];
}

// ---------- shop products linked to a supplier product ----------

function supply_link_normalize(mixed $s): array
{
    $s = is_array($s) ? $s : [];
    return [
        'code' => is_string($s['code'] ?? null) && preg_match(SUPPLY_CODE_RE, $s['code']) ? $s['code'] : '',
        'race' => is_string($s['race'] ?? null) ? $s['race'] : '',
        'src_name' => is_string($s['src_name'] ?? null) ? $s['src_name'] : '',
        'cost' => max(0, (int) ($s['cost'] ?? 0)),
        'cost_at' => is_string($s['cost_at'] ?? null) ? $s['cost_at'] : null,
        'stock_at' => is_string($s['stock_at'] ?? null) ? $s['stock_at'] : null,
        'min' => max(1, (int) ($s['min'] ?? 1)),
        'max' => max(0, (int) ($s['max'] ?? 0)),
        'contact' => (int) ($s['contact'] ?? 0) === 2 ? 2 : 0,
        'manual' => (bool) ($s['manual'] ?? false),
    ];
}

/** Per-order quantity bounds for a supply product: [min, max]. */
function supply_qty_bounds(array $p): array
{
    $s = $p['supply'] ?? supply_link_normalize([]);
    $min = max(1, min(SHOP_QTY_MAX, $s['min']));
    $max = $s['max'] > 0 ? min(SHOP_QTY_MAX, $s['max']) : SHOP_QTY_MAX;
    return [$min, max($min, $max)];
}

/** A default product photo for a card (GD, no text from the supplier). */
function supply_default_photo(): array
{
    $w = 1200;
    $h = 900;
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 10, 23, 51));
    $card = imagecolorallocate($im, 27, 46, 96);
    $edge = imagecolorallocate($im, 94, 110, 162);
    $chip = imagecolorallocate($im, 230, 169, 72);
    $txt = imagecolorallocate($im, 236, 233, 241);
    $accent = imagecolorallocate($im, 234, 76, 34);
    imagefilledrectangle($im, 170, 170, 1030, 730, $card);
    imagerectangle($im, 170, 170, 1030, 730, $edge);
    imagerectangle($im, 171, 171, 1029, 729, $edge);
    imagefilledrectangle($im, 250, 300, 380, 400, $chip);
    for ($i = 0; $i < 4; $i++) {
        imageline($im, 250, 320 + $i * 22, 380, 320 + $i * 22, $card);
    }
    imagefilledellipse($im, 900, 290, 90, 90, $accent);
    for ($i = 0; $i < 4; $i++) {
        imagefilledrectangle($im, 250 + $i * 170, 560, 390 + $i * 170, 600, $edge);
    }
    imagestring($im, 5, 252, 640, 'GAME CARD  ****  ****  ****', $txt);
    $tmp = sys_get_temp_dir() . '/pbcard-' . bin2hex(random_bytes(4)) . '.png';
    imagepng($im, $tmp);
    imagedestroy($im);
    try {
        return image_store_file($tmp);
    } finally {
        @unlink($tmp);
    }
}

/**
 * 上架 one supplier product as a shop product. $in: name, price (yuan text), body, on_sale ('1'), race.
 * Returns the new product. Throws ShopError (field errors in ->fields).
 */
function supply_import(string $code, array $in): array
{
    if (!supply_ready()) {
        throw new ShopError('config', '货源还没打开或没设置好，先在上面填好网址、app_id 和密钥，并打开开关。');
    }
    $d = supply_item($code, true);
    if (!$d['ok']) {
        throw new ShopError('supplier', $d['msg']);
    }
    $it = $d['item'];
    $why = supply_unsupported($it);
    if ($why) {
        throw new ShopError('unsupported', '这件商品暂不支持：' . implode('；', $why) . '。');
    }
    $e = [];
    $race = is_string($in['race'] ?? null) ? $in['race'] : '';
    if ($it['races']) {
        if (!array_key_exists($race, $it['races'])) {
            $e['race'] = '请选一个种类。';
        }
    } else {
        $race = '';
    }
    $name = shop_clean_line($in['name'] ?? '', 40);
    if ($name === '') {
        $e['name'] = '请填商品名称（买家看到的名字）。';
    } elseif (mb_strlen($name) > 40) {
        $e['name'] = '名称最多 40 个字。';
    }
    $price = shop_parse_money(is_string($in['price'] ?? null) ? $in['price'] : '');
    if ($price === null || $price < 1 || $price > SHOP_PRICE_MAX) {
        $e['price'] = '售价请填像 30 或 29.90 这样的数字（元），最多两位小数。';
    }
    $body = is_string($in['body'] ?? null) ? trim(str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $in['body'])) : '';
    if (!mb_check_encoding($body, 'UTF-8') || preg_match('/[\x00-\x09\x0B-\x1F\x7F]/', $body)) {
        $e['body'] = '介绍里有看不见的特殊字符，请删掉后再保存。';
    } elseif (mb_strlen($body) > 5000) {
        $e['body'] = '介绍最多 5000 个字。';
    }
    [$cat, $catErr] = shop_cat_input($in['cat'] ?? '', shop_cats());
    if ($catErr !== null) {
        $e['cat'] = $catErr;
    }
    if ($e) {
        throw new ShopError('invalid', (string) reset($e), $e);
    }
    foreach (shop_products() as $p) {
        if ($p['type'] === 'supply' && $p['supply']['code'] === $code && $p['supply']['race'] === $race) {
            throw new ShopError('dup', '这件商品' . ($race !== '' ? '（' . $race . '）' : '') . '已经上架过了：《' . $p['name'] . '》。在「商品」里改它就行。');
        }
    }
    [$min] = supply_qty_bounds(['supply' => supply_link_normalize(['min' => $it['minimum'], 'max' => $it['maximum']])]);
    $v = supply_valuation($code, $race, $min);
    if (!$v['ok']) {
        throw new ShopError('supplier', '查不到成本：' . supply_fail_text($v['r']));
    }
    $cost = (int) ceil($v['price'] / $min);
    if ($price < $cost) {
        throw new ShopError('invalid', '售价 ¥' . shop_money($price) . ' 低于成本 ¥' . shop_money($cost) . '，卖一单亏一单。请把售价定在成本以上。', ['price' => '售价不能低于成本 ¥' . shop_money($cost) . '。']);
    }
    $st = supply_stock($code, $race);
    $stock = min(SHOP_STOCK_MAX, $st['ok'] ? $st['stock'] : max(0, $it['stock']));
    $photo = supply_default_photo();
    $p = shop_mutate(function (array &$products) use ($it, $code, $race, $name, $price, $body, $cost, $stock, $photo, $in, $cat) {
        if (count($products) >= SHOP_PRODUCTS_MAX) {
            throw new ShopError('full', '商品最多 ' . SHOP_PRODUCTS_MAX . ' 件，先删掉不卖的再加。');
        }
        $taken = array_flip(array_map(fn($p) => $p['slug'], $products));
        do {
            $id = 'g' . bin2hex(random_bytes(5));
            $slug = 'card-' . substr($id, 1, 6);
        } while (isset($taken[$slug]));
        $p = shop_product_normalize([
            'id' => $id, 'slug' => $slug, 'name' => $name, 'type' => 'supply', 'price' => $price, 'stock' => $stock,
            'on_sale' => ($in['on_sale'] ?? '') === '1', 'summary' => '', 'body' => $body, 'cat' => $cat,
            'photos' => [['src' => $photo['src'], 'alt' => $name]],
            'supply' => ['code' => $code, 'race' => $race, 'src_name' => $it['name'] . ($race !== '' ? '（' . $race . '）' : ''), 'cost' => $cost, 'cost_at' => now_iso(),
                'stock_at' => now_iso(), 'min' => $it['minimum'], 'max' => $it['maximum'], 'contact' => $it['contact_type'] === 2 ? 2 : 0, 'manual' => $it['delivery_way'] === 1],
        ]);
        $products[] = $p;
        return $p;
    });
    audit('supply_import', $p['id']);
    if ($p['on_sale']) {
        publish_site('上架货源商品《' . $p['name'] . '》', ['version' => false]);
    }
    return $p;
}

/**
 * Refresh a supply product's stock (and, with $cost, its unit cost) when older than $maxAge seconds.
 * Returns the product (fresh or as it was). Republishes when the label on the pages changed.
 */
function supply_refresh_product(string $id, int $maxAge = SUPPLY_CACHE_TTL, bool $cost = false): ?array
{
    $p = shop_product_get($id);
    if ($p === null || $p['type'] !== 'supply' || $p['supply']['code'] === '' || !supply_ready()) {
        return $p;
    }
    $s = $p['supply'];
    $stale = $s['stock_at'] === null || time() - (int) strtotime($s['stock_at']) >= $maxAge;
    if (!$stale && !$cost) {
        return $p;
    }
    // public pages refresh at most once every 30 s per product, whatever the outcome: a slow or broken supplier
    // must not turn every visit to the order form into a 10-second outbound call
    if (!$cost && $maxAge > 0 && !rl_hit('supply-stock', $id, 1, 30, true)['allowed']) {
        return $p;
    }
    $st = supply_stock($s['code'], $s['race']);
    $newCost = null;
    if ($cost) {
        [$min] = supply_qty_bounds($p);
        $v = supply_valuation($s['code'], $s['race'], $min);
        $newCost = $v['ok'] ? (int) ceil($v['price'] / $min) : null;
    }
    if (!$st['ok'] && $newCost === null) {
        return $p;
    }
    $before = shop_page_signature(shop_products());
    $res = shop_mutate(function (array &$products) use ($id, $st, $newCost) {
        foreach ($products as $k => $q) {
            if ($q['id'] === $id) {
                if ($st['ok']) {
                    $products[$k]['stock'] = min(SHOP_STOCK_MAX, $st['stock']);
                    $products[$k]['supply']['stock_at'] = now_iso();
                }
                if ($newCost !== null) {
                    $products[$k]['supply']['cost'] = $newCost;
                    $products[$k]['supply']['cost_at'] = now_iso();
                }
                return $products[$k];
            }
        }
        return null;
    });
    shop_republish_if_changed($before);
    return $res;
}

// ---------- buying for a paid order ----------

function supply_order_init(array $p, string $email): array
{
    $s = $p['supply'];
    return [
        'code' => $s['code'], 'race' => $s['race'], 'cost_seen' => $s['cost'], 'contact' => $s['contact'] === 2 ? $email : '',
        'manual' => $s['manual'], 'state' => 'new', 'attempt' => 0, 'sent_unknown' => false, 'request_no' => '', 'trade_no' => '',
        'amount' => 0, 'secret' => '', 'pending' => false, 'msg' => '', 'cost_now' => 0, 'accept_cost' => 0, 'at' => null, 'polled_at' => null,
    ];
}

function supply_order_normalize(mixed $s): array
{
    $d = supply_order_init(['supply' => supply_link_normalize([])], '');
    return array_replace($d, is_array($s) ? array_intersect_key($s, $d) : []);
}

/** The idempotency number for an order's n-th purchase attempt: the same order and attempt always give the same one. */
function supply_request_no(string $orderId, int $attempt): string
{
    return 'PB' . str_replace('-', '', $orderId) . 'T' . $attempt;
}

/** Write the supply part (and maybe the status) of one order under the shop lock. Returns the order. */
function supply_order_update(string $id, callable $fn): ?array
{
    return shop_mutate(function (array &$products, array &$orders) use ($id, $fn) {
        foreach ($orders as $k => $o) {
            if ($o['id'] === $id) {
                $o['supply'] = supply_order_normalize($o['supply'] ?? null);
                $fn($o, $products);
                $orders[$k] = $o;
                return $o;
            }
        }
        return null;
    });
}

/**
 * Buy the card for a paid supply order. Never before the owner confirmed the payment; at most one purchase per order:
 * one global lock around the whole buy, the state read again inside it, the request_no fixed before the call and kept
 * whenever the outcome is unknown. $mode: 'confirm' (right after 确认收款), 'retry' (重试进货), 'accept' (the owner
 * accepts the new cost $acceptCost), 'fresh' (the owner checked the supplier: the unknown attempt did not buy anything).
 * Returns ['ok' => bool, 'order' => array, 'msg' => owner text].
 */
function supply_fulfill(string $id, string $mode = 'confirm', int $acceptCost = 0): array
{
    @set_time_limit(90);
    ignore_user_abort(true);
    return with_lock('supply-trade', function () use ($id, $mode, $acceptCost) {
        $o = shop_order_get($id);
        if ($o === null || ($o['type'] ?? '') !== 'supply') {
            return ['ok' => false, 'order' => $o, 'msg' => '这不是货源订单。'];
        }
        $s = supply_order_normalize($o['supply'] ?? null);
        if ($s['trade_no'] !== '' || $s['state'] === 'done') {
            return ['ok' => true, 'order' => $o, 'msg' => '这个订单已经进过货了，没有重复购买。'];
        }
        $okStatus = $mode === 'confirm' ? ['confirmed'] : ['confirmed', 'failed'];
        if (!in_array($o['status'], $okStatus, true)) {
            return ['ok' => false, 'order' => $o, 'msg' => '订单现在是「' . (SHOP_STATUS[$o['status']] ?? $o['status']) . '」，没有进货。'];
        }
        if ($mode === 'retry' && $s['state'] === 'unclear') {
            return ['ok' => false, 'order' => $o, 'msg' => '上一次进货的结果还没核对。先去供货平台确认那笔有没有买成。'];
        }
        $fail = function (string $state, string $msg, array $extra = []) use ($id): array {
            $became = false;
            $o = supply_order_update($id, function (array &$o) use ($state, $msg, $extra, &$became) {
                $o['supply'] = array_replace($o['supply'], ['state' => $state, 'msg' => $msg, 'at' => now_iso()], $extra);
                if ($o['status'] !== 'cancelled') {
                    $became = $o['status'] !== 'failed';
                    $o['status'] = 'failed';
                    shop_hist($o, 'failed', 'auto', '');
                }
            });
            if ($became && $o !== null) {
                notify_order('failed', $o);   // 微信提醒 on the move into 进货失败 (not on a repeat while already failed)
            }
            return ['ok' => false, 'order' => $o, 'msg' => '进货失败：' . $msg];
        };
        if ($mode === 'fresh') {
            if ($s['state'] !== 'unclear' && $s['state'] !== 'unknown') {
                return ['ok' => false, 'order' => $o, 'msg' => '这个订单没有结果不确定的进货。'];
            }
            $s['attempt']++;
            $s['sent_unknown'] = false;
            supply_order_update($id, function (array &$o) use ($s) {
                $o['supply']['attempt'] = $s['attempt'];
                $o['supply']['sent_unknown'] = false;
                $o['supply']['state'] = 'refused';
            });
        }
        if (!supply_ready()) {
            return $fail('blocked', '货源开关关着，或者网址、app_id、密钥没设置好。在「货源」页面打开以后点「重试进货」。');
        }
        // 1. the cost now, against what the buyer paid and what the order saw
        $v = supply_valuation($s['code'], $s['race'], (int) $o['qty']);
        if (!$v['ok']) {
            return $fail('blocked', '查成本失败，没有下单。' . supply_fail_text($v['r']));
        }
        $cost = (int) $v['price'];
        $limit = (int) floor($s['cost_seen'] * (int) $o['qty'] * (100 + SUPPLY_COST_RISE) / 100);
        if ($mode === 'accept' && $acceptCost > 0) {
            supply_order_update($id, function (array &$o) use ($acceptCost) {
                $o['supply']['accept_cost'] = $acceptCost;
            });
            $s['accept_cost'] = $acceptCost;
        }
        if ($cost > (int) $o['subtotal']) {
            return $fail('blocked', '成本涨到了 ¥' . shop_money($cost) . '，比买家付的商品金额 ¥' . shop_money((int) $o['subtotal']) . ' 还高，没有下单。可以取消订单并退款。', ['cost_now' => $cost]);
        }
        if ($cost > $limit && $cost > $s['accept_cost']) {
            return $fail('blocked', '成本从 ¥' . shop_money($s['cost_seen'] * (int) $o['qty']) . ' 涨到了 ¥' . shop_money($cost) . '（超过 ' . SUPPLY_COST_RISE . '%），没有下单。确认能接受的话，点「按新成本进货」。', ['cost_now' => $cost]);
        }
        // 2. fix the request number before the call: a crash or timeout retries with the same one
        $req = supply_request_no($id, $s['attempt']);
        $wasUnknown = $s['sent_unknown'];
        supply_order_update($id, function (array &$o) use ($req, $cost) {
            $o['supply'] = array_replace($o['supply'], ['state' => 'trading', 'request_no' => $req, 'sent_unknown' => true, 'cost_now' => $cost, 'at' => now_iso(), 'msg' => '']);
            if ($o['status'] === 'failed') {
                $o['status'] = 'confirmed';
            }
        });
        $r = supply_call('/shared/commodity/trade', [
            'shared_code' => $s['code'], 'num' => (int) $o['qty'], 'request_no' => $req, 'contact' => $s['contact'] !== '' ? $s['contact'] : $id,
            'race' => $s['race'], 'device' => 0,
        ]);
        if ($r['kind'] === 'net' || $r['kind'] === 'bad' || $r['kind'] === 'config') {
            if ($r['kind'] === 'config') {
                return $fail('blocked', supply_fail_text($r), ['sent_unknown' => $wasUnknown]);
            }
            return $fail('unknown', '不知道这次有没有买成：' . strip_full_stop_cn($r['msg']) . '。点「重试进货」会用同一个请求号 ' . $req . '，供货平台不会重复扣款。');
        }
        if (!$r['ok']) {
            if ($wasUnknown) {
                return $fail('unclear', '上一次进货结果不确定，用同一个请求号 ' . $req . ' 重试时供货平台说：' . strip_full_stop_cn($r['msg'])
                    . '。请先登录供货平台，按请求号或时间找这笔订单：买成了就把卡密复制给买家，没买成就点「确认没买成，重新进货」。');
            }
            return $fail('refused', supply_fail_text($r), ['attempt' => $s['attempt'] + 1, 'sent_unknown' => false]);
        }
        $d = is_array($r['data']) ? $r['data'] : [];
        $tradeNo = is_scalar($d['tradeNo'] ?? null) ? mb_substr(supply_line($d['tradeNo'], 64), 0, 64) : '';
        $secret = supply_secret_clean($d['secret'] ?? '');
        $amount = supply_fen($d['amount'] ?? null) ?? $cost;
        $pending = supply_secret_pending($secret);
        $o = supply_order_update($id, function (array &$o, array &$products) use ($tradeNo, $secret, $amount, $pending, $d, $r) {
            $o['supply'] = array_replace($o['supply'], ['state' => 'done', 'trade_no' => $tradeNo !== '' ? $tradeNo : $o['supply']['request_no'], 'amount' => $amount,
                'secret' => $secret, 'pending' => $pending, 'sent_unknown' => false, 'msg' => '', 'at' => now_iso()]);
            if (is_numeric($d['stock'] ?? null)) {
                foreach ($products as &$p) {
                    if ($p['id'] === $o['product_id']) {
                        $p['stock'] = min(SHOP_STOCK_MAX, max(0, (int) $d['stock']));
                        $p['supply']['stock_at'] = now_iso();
                    }
                }
                unset($p);
            }
            if ($o['status'] !== 'cancelled' && !$pending) {
                $o['status'] = 'completed';
                $o['closed_at'] = now_iso();
                shop_hist($o, 'completed', 'auto', '');
            }
        });
        return ['ok' => true, 'order' => $o, 'msg' => $pending ? '已进货（订单号 ' . $o['supply']['trade_no'] . '），供货平台还没发出卡密（人工发货）。买家查订单时会自动再问，你也可以点「刷新卡密」。' : '已进货，卡密已经显示给买家。'];
    }, 60);
}

/**
 * For a bought order whose card is not out yet (manual delivery): ask /query, at most once a minute per order.
 * Returns the order (updated when the card arrived).
 */
function supply_poll(string $id, bool $force = false): ?array
{
    $o = shop_order_get($id);
    if ($o === null || ($o['type'] ?? '') !== 'supply') {
        return $o;
    }
    $s = supply_order_normalize($o['supply'] ?? null);
    if (!$s['pending'] || $s['trade_no'] === '' || !supply_ready()) {
        return $o;
    }
    $gap = $force ? 10 : SUPPLY_POLL_GAP;
    $go = false;
    supply_order_update($id, function (array &$o) use ($gap, &$go) {
        $t = $o['supply']['polled_at'];
        if ($t === null || time() - (int) strtotime($t) >= $gap) {
            $o['supply']['polled_at'] = now_iso();
            $go = true;
        }
    });
    if (!$go) {
        return shop_order_get($id);
    }
    $r = supply_call('/shared/commodity/query', ['tradeNo' => $s['trade_no']]);
    if (!$r['ok'] || !is_array($r['data'])) {
        return shop_order_get($id);
    }
    $secret = supply_secret_clean($r['data']['secret'] ?? '');
    if (supply_secret_pending($secret)) {
        return shop_order_get($id);
    }
    return supply_order_update($id, function (array &$o) use ($secret) {
        $o['supply']['secret'] = $secret;
        $o['supply']['pending'] = false;
        if (in_array($o['status'], ['confirmed', 'failed'], true)) {
            $o['status'] = 'completed';
            $o['closed_at'] = now_iso();
            shop_hist($o, 'completed', 'auto', '');
        }
    });
}

/** Products imported from the supplier, for the 货源 page. */
function supply_products(): array
{
    return array_values(array_filter(shop_products(), fn($p) => $p['type'] === 'supply'));
}
