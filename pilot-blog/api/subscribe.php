<?php
// 订阅接口：只保存邮箱、时间和当时同意的那句话，存在 public_html 同级的 blog-data/subscribers.json。
// 这里不发任何邮件。新日志由站长在后台复制名单，用自己的邮箱密送。
// POST: email, consent ("1"), website (honeypot, must stay empty), t (ms since page load).

declare(strict_types=1);

define('BLOG_APP', true);
require __DIR__ . '/../app/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');

const SUB_IP_HOUR = 5;
const SUB_IP_DAY = 20;
const SUB_SITE_DAY = 300;

$usageReady = false;

function respond(int $status, array $body, ?string $count = null): never
{
    global $usageReady;
    if ($usageReady && $count !== null) {
        usage_add([$count => 1]);
    }
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, ['error' => 'method_not_allowed', 'message' => '这次没有提交成功。请从本站页面提交。']);
}

// a cross-site form or script: the Origin header names another host
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    $p = parse_url($origin);
    $scheme = strtolower((string) ($p['scheme'] ?? ''));
    $originHost = strtolower((string) ($p['host'] ?? ''));
    $port = (int) ($p['port'] ?? 0);
    if ($port && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
        $originHost .= ':' . $port;
    }
    $host = (string) preg_replace('/:(80|443)$/', '', strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')));
    if ($originHost === '' || $originHost !== $host) {
        respond(403, ['error' => 'forbidden', 'message' => '这次没有提交成功。请从本站页面提交。']);
    }
}

try {
    app_init('api');
    $usageReady = true;
} catch (Throwable $e) {
    error_log('subscribe.php init: ' . get_class($e));
    respond(503, ['error' => 'unavailable', 'message' => '这次没有提交成功。稍后再试一次。']);
}

if (!settings_get()['subscribe']['enabled']) {
    respond(503, ['error' => 'closed', 'message' => '订阅暂时关闭了，这次没有保存你的邮箱。'], 'subscribe.closed');
}

// The site-wide daily cap is read first without counting, so a request that is about to be blocked by
// it (and a flood of junk) never creates per-IP counter files or inflates the site-wide bucket.
if (rl_count('sub-site-day', local_day(), 2 * 86400) >= SUB_SITE_DAY) {
    respond(429, ['error' => 'rate_limited', 'message' => '提交得有点频繁，过一会儿再试。'], 'subscribe.rate_limited');
}

// Per-visitor limits (IPv6 grouped by /64, so address rotation gets no fresh quota).
$ipKey = ip_hash(client_key());
if (!rl_hit('sub-ip-hour', $ipKey, SUB_IP_HOUR, 3600)['allowed']
    || !rl_hit('sub-ip-day', $ipKey, SUB_IP_DAY, 86400)['allowed']) {
    respond(429, ['error' => 'rate_limited', 'message' => '提交得有点频繁，过一会儿再试。'], 'subscribe.rate_limited');
}

$honeypot = $_POST['website'] ?? '';
$honeypot = is_string($honeypot) ? $honeypot : 'x';
$t = is_string($_POST['t'] ?? null) && ctype_digit($_POST['t']) ? (int) $_POST['t'] : 0;
if ($honeypot !== '' || $t < 1500) {
    // filled the hidden field, or submitted faster than a person can type: answer like a success, store nothing
    respond(200, ['ok' => true], 'subscribe.bot_dropped');
}

$raw = $_POST['email'] ?? '';
$email = is_string($raw) && strlen($raw) <= 1000 ? email_normalize($raw) : null;
if ($email === null) {
    respond(400, ['error' => 'invalid_email', 'message' => '邮箱格式不对，请再检查一遍。'], 'subscribe.invalid');
}
if (($_POST['consent'] ?? '') !== '1') {
    respond(400, ['error' => 'consent_required', 'message' => '请先勾选同意，再提交。'], 'subscribe.invalid');
}

$consent = (string) (site_live()['fields']['sub.consent'] ?? '');
try {
    $result = sub_add($email, $consent, 'home');
} catch (SubscribersFull $e) {
    respond(503, ['error' => 'full', 'message' => '订阅名单满了，这次没有保存。']);
} catch (Throwable $e) {
    error_log('subscribe.php store: ' . get_class($e));
    respond(503, ['error' => 'unavailable', 'message' => '这次没有提交成功。稍后再试一次。']);
}
// Count the site-wide bucket only when a genuinely new address was stored, so duplicates and junk
// cannot push the whole site to its daily cap.
if ($result === 'new') {
    rl_hit('sub-site-day', local_day(), SUB_SITE_DAY, 2 * 86400);
}
// the same answer for a new and a repeated address, so nobody can probe who is on the list
respond(200, ['ok' => true], 'subscribe.ok');
