<?php
// Visitor statistics (privacy-friendly): one small JSON per Beijing day in blog-data/stats/.
// Stored: page views, unique visitors per day, views per page, referrer host names (hostname only),
// phone/computer split. Never stored: IP addresses, user agents, full URLs, cookies.
// Unique visitors are counted with a keyed hash of (IPv6 /64 or IPv4, user agent) under a random salt
// that lives for one day only; the salt and the day's hash list are deleted when the day is over, so
// the hashes cannot be linked across days or reversed afterwards.
declare(strict_types=1);
defined('BLOG_APP') || exit;

const STATS_KEEP_DAYS = 400;          // day files older than this are deleted
const STATS_PATH_KEYS = 300;          // distinct pages per day (more go to '*')
const STATS_REF_KEYS = 100;           // distinct referrer hosts per day (more go to '*')
const STATS_DAY_VIEWS_MAX = 200000;   // after this many views in one day nothing more is written
const STATS_SEEN_MAX = 40000;         // visitor hashes kept per day (17 bytes each, about 680 KB)
const STATS_IP_HOUR = 120;            // counted views per visitor (/64 or IPv4) per hour
const STATS_OWNER_COOKIE = 'pb_nostat';
const STATS_DAY_RE = '/^\d{4}-\d{2}-\d{2}$/';

const STATS_BOT_RE = '/bot|crawl|spider|slurp|scrap|curl|wget|python|httpclient|http-client|java\/|go-http|okhttp|axios|node-fetch|undici|libwww|perl|ruby|php\/|headless|phantom|puppeteer|playwright|selenium|lighthouse|pagespeed|gtmetrix|pingdom|uptime|monitor|statuscake|preview|facebookexternalhit|embedly|whatsapp|telegram|discord|slack|skype|feed|rss|validator|checker|scan|archive\.org|bytespider|yisou|petal|sogou web|360spider|baiduspider|yandex|semrush|ahrefs|mj12|dotbot|applebot|google-inspection|chrome-lighthouse/i';

function stats_empty(string $day): array
{
    return ['date' => $day, 'views' => 0, 'visitors' => 0, 'paths' => [], 'refs' => [], 'devices' => ['phone' => 0, 'desktop' => 0], 'wechat' => 0];
}

function stats_normalize(mixed $d, string $day): array
{
    $out = stats_empty($day);
    if (!is_array($d)) {
        return $out;
    }
    foreach (['views', 'visitors', 'wechat'] as $k) {
        $out[$k] = max(0, (int) ($d[$k] ?? 0));
    }
    foreach (['phone', 'desktop'] as $k) {
        $out['devices'][$k] = max(0, (int) ($d['devices'][$k] ?? 0));
    }
    foreach (['paths', 'refs'] as $k) {
        foreach (is_array($d[$k] ?? null) ? $d[$k] : [] as $key => $n) {
            $out[$k][(string) $key] = max(0, (int) $n);
        }
    }
    return $out;
}

function stats_enabled(?array $settings = null): bool
{
    $s = $settings ?? settings_get();
    return (bool) ($s['stats']['enabled'] ?? false);
}

// ---------- what to count ----------

/** True for crawlers, link previewers, scripts and requests without a user agent. */
function stats_is_bot(string $ua): bool
{
    $ua = trim($ua);
    if ($ua === '' || strlen($ua) < 20 || strlen($ua) > 1000) {
        return true;
    }
    if (!str_starts_with($ua, 'Mozilla/') && !str_starts_with($ua, 'Opera/')) {
        return true;
    }
    return preg_match(STATS_BOT_RE, $ua) === 1;
}

/** A prefetch or a preview render is not a visit. */
function stats_is_prefetch(): bool
{
    foreach (['HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_PURPOSE', 'HTTP_X_MOZ'] as $h) {
        $v = strtolower((string) ($_SERVER[$h] ?? ''));
        if ($v !== '' && (str_contains($v, 'prefetch') || str_contains($v, 'preview') || str_contains($v, 'prerender'))) {
            return true;
        }
    }
    return false;
}

function stats_device(string $ua): string
{
    return preg_match('/Mobi|Android|iPhone|iPod|iPad|Windows Phone|HarmonyOS|OpenHarmony|Tablet/i', $ua) ? 'phone' : 'desktop';
}

function stats_is_wechat(string $ua): bool
{
    return stripos($ua, 'MicroMessenger') !== false;
}

/** '/', '/posts/' or '/posts/<slug>.html' of a page that exists; anything else null. */
function stats_path(string $p): ?string
{
    if ($p === '' || strlen($p) > 100) {
        return null;
    }
    if ($p === '/' || $p === '/index.html') {
        return '/';
    }
    if ($p === '/posts/' || $p === '/posts/index.html') {
        return '/posts/';
    }
    if (preg_match('#^/posts/([a-z0-9-]{1,60})\.html$#', $p, $m) && preg_match(SLUG_RE, $m[1]) && $m[1] !== 'index'
        && is_file(PUBLIC_DIR . '/posts/' . $m[1] . '.html')) {
        return $p;
    }
    return null;
}

/** A referrer host name, lowercased, without 'www.'; null for empty, invalid or this site itself. */
function stats_ref_host(string $r, string $ownHost = ''): ?string
{
    $h = strtolower(trim($r));
    $h = rtrim($h, '.');
    if ($h === '' || strlen($h) > 253 || !preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $h)) {
        return null;
    }
    if (preg_match('/^\d+(\.\d+){3}$/', $h)) {
        return null;   // a bare IP address is never shown
    }
    $own = strtolower((string) preg_replace('/:\d+$/', '', $ownHost));
    $strip = fn(string $x) => str_starts_with($x, 'www.') ? substr($x, 4) : $x;
    if ($own !== '' && $strip($h) === $strip($own)) {
        return null;
    }
    return $strip($h);
}

// ---------- storage ----------

function stats_dir(): string
{
    return DATA_DIR . '/stats';
}

/** Today's salt; a new day gets a new random salt and the old salt and hash lists are deleted. Call under the stats lock. */
function stats_salt(string $day): string
{
    $file = stats_dir() . '/salt.json';
    $s = json_read($file, []);
    if (is_array($s) && ($s['day'] ?? '') === $day && preg_match('/^[0-9a-f]{64}$/', (string) ($s['salt'] ?? ''))) {
        return $s['salt'];
    }
    $salt = bin2hex(random_bytes(32));
    json_write_atomic($file, ['day' => $day, 'salt' => $salt]);
    stats_rollover($day);
    return $salt;
}

/** Once a day: drop the visitor-hash lists of past days and day files older than STATS_KEEP_DAYS. */
function stats_rollover(string $today): void
{
    foreach (glob(stats_dir() . '/seen-*.txt') ?: [] as $f) {
        if (basename($f) !== 'seen-' . $today . '.txt') {
            @unlink($f);
        }
    }
    $cut = (new DateTimeImmutable($today . ' 00:00:00', new DateTimeZone('Asia/Shanghai')))
        ->modify('-' . STATS_KEEP_DAYS . ' days')->format('Y-m-d');
    foreach (glob(stats_dir() . '/*.json') ?: [] as $f) {
        $d = basename($f, '.json');
        if (preg_match(STATS_DAY_RE, $d) && strcmp($d, $cut) < 0) {
            @unlink($f);
        }
    }
}

/**
 * Count one page view. $visitor: the per-visitor key (client_key()); $ua: the user agent (hashed only).
 * Returns true when it was written. Throws on storage errors (the endpoint swallows them).
 */
function stats_record(string $path, ?string $ref, string $visitor, string $ua, ?int $ts = null): bool
{
    $day = local_day($ts);
    $dir = stats_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('cannot create stats dir');
    }
    return (bool) with_lock('stats', function () use ($day, $dir, $path, $ref, $visitor, $ua): bool {
        $salt = stats_salt($day);
        $file = $dir . '/' . $day . '.json';
        $d = stats_normalize(json_read($file, []), $day);
        if ($d['views'] >= STATS_DAY_VIEWS_MAX) {
            return false;
        }
        $d['views']++;
        $bump = function (array &$map, string $key, int $cap): void {
            if (!isset($map[$key]) && count($map) >= $cap) {
                $key = '*';
            }
            $map[$key] = ($map[$key] ?? 0) + 1;
        };
        $bump($d['paths'], $path, STATS_PATH_KEYS);
        if ($ref !== null) {
            $bump($d['refs'], $ref, STATS_REF_KEYS);
        }
        // unique visitors: a 64-bit keyed hash, valid for this day only
        $hash = substr(hash_hmac('sha256', $visitor . "\n" . $ua, $salt), 0, 16);
        $seenFile = $dir . '/seen-' . $day . '.txt';
        $seen = is_file($seenFile) ? (string) file_get_contents($seenFile) : '';
        if (!str_contains("\n" . $seen, "\n" . $hash . "\n") && strlen($seen) < STATS_SEEN_MAX * 17) {
            if (@file_put_contents($seenFile, $hash . "\n", FILE_APPEND) === false) {
                throw new RuntimeException('cannot write stats seen list');
            }
            @chmod($seenFile, 0600);
            $d['visitors']++;
            $d['devices'][stats_device($ua)]++;
            if (stats_is_wechat($ua)) {
                $d['wechat']++;
            }
        }
        json_write_atomic($file, $d);
        return true;
    }, 3);
}

function stats_day(string $day): array
{
    if (!preg_match(STATS_DAY_RE, $day)) {
        return stats_empty($day);
    }
    try {
        return stats_normalize(json_read(stats_dir() . '/' . $day . '.json', []), $day);
    } catch (Throwable $e) {
        return stats_empty($day);
    }
}

/** Newest first: the last $days Beijing days, missing days as zeros. */
function stats_range(int $days = 30, ?int $now = null): array
{
    $now ??= time();
    $out = [];
    for ($i = 0; $i < $days; $i++) {
        $out[] = stats_day(local_day($now - $i * 86400));
    }
    return $out;
}

/** Sum a map ('paths' | 'refs') over days; top $n as [key => count], '*' (others) always last. */
function stats_top(array $days, string $map, int $n = 10): array
{
    $sum = [];
    foreach ($days as $d) {
        foreach ($d[$map] as $k => $v) {
            $sum[$k] = ($sum[$k] ?? 0) + $v;
        }
    }
    $other = $sum['*'] ?? 0;
    unset($sum['*']);
    arsort($sum);
    $top = array_slice($sum, 0, $n, true);
    $other += array_sum(array_slice($sum, $n, null, true));
    if ($other > 0) {
        $top['*'] = $other;
    }
    return $top;
}

/** Friendly Chinese names for common referrers; null when there is no obvious one. */
function stats_ref_label(string $host): ?string
{
    $map = [
        'mp.weixin.qq.com' => '微信公众号',
        'weixin.qq.com' => '微信',
        'wx.qq.com' => '微信',
        'work.weixin.qq.com' => '企业微信',
        'qq.com' => 'QQ',
        'baidu.com' => '百度',
        'm.baidu.com' => '百度',
        'google.com' => '谷歌',
        'bing.com' => '必应',
        'cn.bing.com' => '必应',
        'sogou.com' => '搜狗',
        'm.sogou.com' => '搜狗',
        'so.com' => '360 搜索',
        'm.so.com' => '360 搜索',
        'sm.cn' => '神马搜索',
        'zhihu.com' => '知乎',
        'weibo.com' => '微博',
        'weibo.cn' => '微博',
        'm.weibo.cn' => '微博',
        'xiaohongshu.com' => '小红书',
        'douban.com' => '豆瓣',
        'bilibili.com' => '哔哩哔哩',
        'douyin.com' => '抖音',
        'toutiao.com' => '今日头条',
        'duckduckgo.com' => 'DuckDuckGo',
        'yandex.com' => 'Yandex',
        't.co' => 'X（推特）',
        'x.com' => 'X（推特）',
        'twitter.com' => 'X（推特）',
        'facebook.com' => 'Facebook',
        'm.facebook.com' => 'Facebook',
        'linkedin.com' => '领英',
        'github.com' => 'GitHub',
    ];
    if (isset($map[$host])) {
        return $map[$host];
    }
    if (preg_match('/(^|\.)google\.[a-z.]{2,8}$/', $host)) {
        return '谷歌';
    }
    if (preg_match('/(^|\.)baidu\.com$/', $host)) {
        return '百度';
    }
    if (preg_match('/(^|\.)bing\.com$/', $host)) {
        return '必应';
    }
    if (preg_match('/(^|\.)weixin\.qq\.com$/', $host)) {
        return '微信';
    }
    return null;
}

// ---------- the owner's own visits ----------

/** Mark this browser as the owner's (set on every admin page), so hit.php skips its visits. */
function stats_owner_mark(): void
{
    if (headers_sent()) {
        return;
    }
    setcookie(STATS_OWNER_COOKIE, '1', [
        'expires' => time() + 400 * 86400,
        'path' => site_base(),
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function stats_is_owner(): bool
{
    return ($_COOKIE[STATS_OWNER_COOKIE] ?? '') === '1';
}
