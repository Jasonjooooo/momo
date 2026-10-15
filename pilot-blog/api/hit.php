<?php
// 访问统计接口：每个公开页面打开时来一次，记一次浏览。
// 首页用一小段脚本（sendBeacon）发来页面和来源网站的域名；日志页和全部日志页不能跑脚本，用一个 1x1 的小图。
// 只在 public_html 同级的 blog-data/stats/ 里存每天的总数，不存 IP、不存浏览器信息、不存完整网址。
// GET ?p=/posts/x.html          (image pixel, answers a 1x1 transparent gif)
// POST ?p=/&r=<referrer host>   (beacon, answers 204)
// Every answer is the same whatever happened, never an error page, never cached.

declare(strict_types=1);

ini_set('display_errors', '0');
define('BLOG_APP', true);

const HIT_GIF = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\x00\x00\x00!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;";

/** Answer now; counting (if any) happens after the visitor already has the response. */
function hit_answer(): void
{
    if (headers_sent()) {
        return;
    }
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        header('Content-Type: image/gif');
        header('Content-Length: ' . strlen(HIT_GIF));
        echo HIT_GIF;
    } else {
        http_response_code(204);
    }
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_flush();
        @flush();
    }
}

/** What to count, or null when this request is not a visit worth counting. Cheap checks only. */
function hit_request(): ?array
{
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method !== 'GET' && $method !== 'POST') {
        return null;
    }
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (stats_is_bot($ua) || stats_is_prefetch() || stats_is_owner()) {
        return null;
    }
    // only this site's own pages: a cross-site page cannot count views by embedding the pixel or beacon
    $site = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($site === 'cross-site') {
        return null;
    }
    $host = (string) preg_replace('/:(80|443)$/', '', strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')));
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && $origin !== 'null') {
        $o = parse_url($origin);
        $oh = strtolower((string) ($o['host'] ?? ''));
        if (isset($o['port']) && !in_array((int) $o['port'], [80, 443], true)) {
            $oh .= ':' . $o['port'];
        }
        if ($oh !== $host) {
            return null;
        }
    }
    $p = $_GET['p'] ?? '';
    $path = is_string($p) ? stats_path($p) : null;
    if ($path === null) {
        return null;
    }
    $r = $_GET['r'] ?? '';
    $ref = is_string($r) && $r !== '' && $path === '/' ? stats_ref_host($r, $host) : null;
    return ['path' => $path, 'ref' => $ref, 'ua' => $ua];
}

try {
    require __DIR__ . '/../app/lib/bootstrap.php';
    $hit = hit_request();
    hit_answer();
    if ($hit !== null) {
        app_init('api');
        if (stats_enabled()) {
            $key = client_key();
            // per visitor (IPv6 /64 or IPv4): a reload loop or a script cannot pile up views
            if (rl_hit('hit-ip', ip_hash($key), STATS_IP_HOUR, 3600)['allowed']) {
                stats_record($hit['path'], $hit['ref'], $key, $hit['ua']);
            }
        }
    }
} catch (Throwable $e) {
    error_log('hit.php: ' . get_class($e) . ' ' . $e->getMessage());
    hit_answer();
}
