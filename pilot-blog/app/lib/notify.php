<?php
// 微信提醒 (SPEC §21): short WeChat messages to the owner through PushPlus (https://www.pushplus.plus/send).
// The PushPlus token lives in secrets.json (write-only: the page shows only its last 4 characters, never logged).
// notify_send() never throws and never slows a visitor down: in a web request the message is queued and sent
// from a shutdown function after the response went out (fastcgi_finish_request); under the CLI it is sent at
// once. Every send is capped (NOTIFY_HOUR_MAX per rolling hour for the whole site) and written to a small log
// (blog-data/notify/log.json: time, event, title, ok / reason). Messages never carry a buyer's phone, email,
// address or payment details; names are masked (王*).
declare(strict_types=1);
defined('BLOG_APP') || exit;

const NOTIFY_URL = 'https://www.pushplus.plus/send';
const NOTIFY_TOKEN_RE = '/^[A-Za-z0-9]{16,64}$/D';
const NOTIFY_CONNECT_TIMEOUT = 3;
const NOTIFY_TIMEOUT = 5;
const NOTIFY_MAX_BYTES = 65536;
const NOTIFY_HOUR_MAX = 60;           // pushes per rolling hour, all events together (health and test included)
const NOTIFY_LOG_MAX = 50;
// switchable events => owner label; health (server check) and test (the admin button) are always on
const NOTIFY_EVENTS = [
    'order' => '新订单（下单待付款）',
    'claim' => '买家点了「我已付款」',
    'failed' => '进货失败（货源）',
    'sub' => '新订阅者',
    'game' => '小游戏有人上了周榜第 1',
];
const NOTIFY_EVENT_DEFAULTS = ['order' => true, 'claim' => true, 'failed' => true, 'sub' => true, 'game' => false];
const NOTIFY_FIXED_EVENTS = ['health' => '服务器体检', 'test' => '测试'];

// ---------- settings and the write-only token ----------

function notify_token(): ?string
{
    $t = secrets_get()['pushplus_token'] ?? null;
    return is_string($t) && preg_match(NOTIFY_TOKEN_RE, $t) ? $t : null;
}

function notify_token_hint(): ?string
{
    $t = notify_token();
    return $t === null ? null : substr($t, -4);
}

function notify_token_set_at(): ?string
{
    $t = secrets_get()['pushplus_token_set_at'] ?? null;
    return is_string($t) ? $t : null;
}

function notify_token_set(string $token): void
{
    $token = trim($token);
    if (!preg_match(NOTIFY_TOKEN_RE, $token)) {
        throw new ValidationError(['token' => '这不像是 PushPlus 的 token（一串字母和数字，通常 32 位）。请在 PushPlus 公众号或官网「一对一推送」里复制完整的 token。']);
    }
    secrets_update(function (array $s) use ($token) {
        $s['pushplus_token'] = $token;
        $s['pushplus_token_set_at'] = now_iso();
        return $s;
    });
    @chmod(data_path('secrets.json'), 0600);
}

function notify_token_remove(): void
{
    secrets_update(function (array $s) {
        unset($s['pushplus_token'], $s['pushplus_token_set_at']);
        return $s;
    });
}

/** The switches, event => bool (settings.notify). */
function notify_events(?array $settings = null): array
{
    return ($settings ?? settings_get())['notify'];
}

/** Whether $event would be sent now (token set; the event switched on, or health/test). */
function notify_on(string $event, ?array $settings = null): bool
{
    if (notify_token() === null) {
        return false;
    }
    if (isset(NOTIFY_FIXED_EVENTS[$event])) {
        return true;
    }
    return isset(NOTIFY_EVENTS[$event]) && !empty(notify_events($settings)[$event]);
}

// ---------- sending ----------

/** Tests only: a php -S mock on 127.0.0.1 over plain http (CLI or the dev server; never under PHP-FPM). */
function notify_url(): string
{
    if (in_array(PHP_SAPI, ['cli', 'cli-server'], true)) {
        $t = (string) getenv('BLOG_PUSH_TEST_BASE');
        if (preg_match('#^http://127\.0\.0\.1:\d{2,5}$#D', $t)) {
            return $t . '/send';
        }
    }
    return NOTIFY_URL;
}

/**
 * Queue (web) or send (CLI) one message. Does nothing without a token or when the event is switched off.
 * Never throws: every failure goes to error_log and the send log.
 */
function notify_send(string $event, string $title, string $text): void
{
    try {
        if (!notify_on($event)) {
            return;
        }
        if (PHP_SAPI === 'cli') {
            notify_deliver($event, $title, $text);
            return;
        }
        notify_queue([$event, $title, $text]);
    } catch (Throwable $e) {
        error_log('notify ' . $event . ': ' . get_class($e) . ' ' . $e->getMessage());
    }
}

/** Web requests: keep the message and send it after the response (one shutdown function per request). */
function notify_queue(?array $msg = null): array
{
    static $q = [];
    static $registered = false;
    if ($msg === null) {
        $out = $q;
        $q = [];
        return $out;
    }
    $q[] = $msg;
    if (!$registered) {
        $registered = true;
        register_shutdown_function('notify_flush');
    }
    return [];
}

/** Shutdown: finish the visitor's response first, then send what was queued. */
function notify_flush(): void
{
    $q = notify_queue();
    if (!$q) {
        return;
    }
    try {
        ignore_user_abort(true);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();   // the next admin page must not wait for this request's session lock
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            while (ob_get_level() > 0 && @ob_end_flush()) {
            }
            @flush();
        }
    } catch (Throwable $e) {
        error_log('notify flush: ' . get_class($e));
    }
    foreach ($q as [$event, $title, $text]) {
        try {
            notify_deliver($event, $title, $text);
        } catch (Throwable $e) {
            error_log('notify ' . $event . ': ' . get_class($e) . ' ' . $e->getMessage());
        }
    }
}

/**
 * Send now (rate limited, logged). Returns ['ok' => bool, 'msg' => plain Chinese for the owner].
 * Used directly by the admin test button and the health check; notify_send() for everything else.
 */
function notify_deliver(string $event, string $title, string $text, ?int $now = null): array
{
    $now ??= time();
    $title = notify_line($title, 100);
    $token = notify_token();
    if ($token === null) {
        return ['ok' => false, 'msg' => '还没有设置 PushPlus token。'];
    }
    if (!notify_reserve($event, $title, $now)) {
        return ['ok' => false, 'msg' => '一小时内已经发了 ' . NOTIFY_HOUR_MAX . ' 条，先不发了（防止刷屏）。过一会儿再试。'];
    }
    $r = notify_post($token, $title, mb_substr($text, 0, 2000));
    notify_log_add(['at' => gmdate('Y-m-d\TH:i:s\Z', $now), 'event' => $event, 'title' => mb_substr($title, 0, 60), 'ok' => $r['ok'], 'why' => $r['ok'] ? '' : $r['reason']]);
    if (!$r['ok']) {
        error_log('notify ' . $event . ': ' . $r['kind']);
    }
    return ['ok' => $r['ok'], 'msg' => $r['ok'] ? '已发出，看看微信有没有收到。' : '没有发出：' . $r['reason']];
}

/**
 * One POST to PushPlus. ['ok' => true] or ['ok' => false, 'kind', 'reason'] (reason: short, Chinese, no token).
 */
function notify_post(string $token, string $title, string $content): array
{
    $fail = fn(string $kind, string $reason) => ['ok' => false, 'kind' => $kind, 'reason' => $reason];
    if (!function_exists('curl_init')) {
        return $fail('network', '服务器的 PHP 没有 curl 扩展。');
    }
    $url = notify_url();
    $proto = str_starts_with($url, 'https://') ? CURLPROTO_HTTPS : CURLPROTO_HTTP;
    $body = json_encode(['token' => $token, 'title' => $title, 'content' => $content, 'template' => 'txt'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $buf = '';
    $tooLarge = false;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Expect:'],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => $proto,
        CURLOPT_REDIR_PROTOCOLS => $proto,
        CURLOPT_CONNECTTIMEOUT => NOTIFY_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => NOTIFY_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'pilot-blog-notify/1',
        CURLOPT_HEADER => false,
        CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$buf, &$tooLarge): int {
            if (strlen($buf) + strlen($chunk) > NOTIFY_MAX_BYTES) {
                $tooLarge = true;
                return 0;
            }
            $buf .= $chunk;
            return strlen($chunk);
        },
    ]);
    $okRun = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($tooLarge) {
        return $fail('bad_response', 'PushPlus 的回答太长，看不懂。');
    }
    if ($okRun === false || $status === 0) {
        return $fail('network', '连不上 PushPlus（' . NOTIFY_CONNECT_TIMEOUT . ' 秒连不上或 ' . NOTIFY_TIMEOUT . ' 秒没回应）。');
    }
    if ($status !== 200) {
        return $fail('http', 'PushPlus 出错了（HTTP ' . $status . '）。');
    }
    $j = json_decode($buf, true);
    if (!is_array($j) || !isset($j['code'])) {
        return $fail('bad_response', 'PushPlus 的回答看不懂。');
    }
    $code = (int) $j['code'];
    if ($code === 200) {
        return ['ok' => true, 'kind' => 'ok', 'reason' => ''];
    }
    $msg = is_string($j['msg'] ?? null) ? notify_line($j['msg'], 40) : '';
    return match ($code) {
        903 => $fail('token', 'token 不对或已失效（903）。重新复制 token 粘贴一次。'),
        900 => $fail('limited', 'PushPlus 限制了这个账号（900）' . ($msg !== '' ? '：' . $msg : '') . '。'),
        default => $fail('refused', 'PushPlus 拒绝了（' . $code . '）' . ($msg !== '' ? '：' . $msg : '') . '。'),
    };
}

// ---------- rate limit and log (blog-data/notify/log.json: {sent: [unix times, last hour], log: [newest first]}) ----------

function notify_log_path(): string
{
    return data_path('notify', 'log.json');
}

function notify_doc(mixed $d): array
{
    $d = is_array($d) ? $d : [];
    return [
        'sent' => array_values(array_filter(is_array($d['sent'] ?? null) ? $d['sent'] : [], 'is_int')),
        'log' => array_values(array_filter(is_array($d['log'] ?? null) ? $d['log'] : [], 'is_array')),
    ];
}

/** Take one slot of the hourly cap. False (and a 'limited' log line) when the cap is reached. */
function notify_reserve(string $event, string $title, int $now): bool
{
    $ok = false;
    json_update(notify_log_path(), function ($d) use ($event, $title, $now, &$ok) {
        $d = notify_doc($d);
        $d['sent'] = array_values(array_filter($d['sent'], fn(int $t) => $t > $now - 3600 && $t <= $now));
        if (count($d['sent']) < NOTIFY_HOUR_MAX) {
            $d['sent'][] = $now;
            $ok = true;
            return $d;
        }
        // one line per hour of flooding: the newest 'limited' line counts the rest
        $top = $d['log'][0] ?? null;
        if (is_array($top) && ($top['limited'] ?? false) && strtotime((string) $top['at']) > $now - 3600) {
            $d['log'][0]['n'] = (int) ($top['n'] ?? 1) + 1;
        } else {
            array_unshift($d['log'], ['at' => gmdate('Y-m-d\TH:i:s\Z', $now), 'event' => $event, 'title' => mb_substr($title, 0, 60), 'ok' => false,
                'why' => '一小时超过 ' . NOTIFY_HOUR_MAX . ' 条，没有发', 'limited' => true, 'n' => 1]);
            $d['log'] = array_slice($d['log'], 0, NOTIFY_LOG_MAX);
        }
        return $d;
    }, []);
    return $ok;
}

function notify_log_add(array $entry): void
{
    try {
        json_update(notify_log_path(), function ($d) use ($entry) {
            $d = notify_doc($d);
            array_unshift($d['log'], $entry);
            $d['log'] = array_slice($d['log'], 0, NOTIFY_LOG_MAX);
            return $d;
        }, []);
    } catch (Throwable $e) {
        error_log('notify log: ' . get_class($e));
    }
}

/** Newest first. */
function notify_log(): array
{
    try {
        return notify_doc(json_read(notify_log_path(), []))['log'];
    } catch (Throwable $e) {
        return [];
    }
}

// ---------- message text ----------

/** One line: control characters out, length capped. */
function notify_line(mixed $s, int $max): string
{
    $s = is_scalar($s) ? (string) $s : '';
    $s = mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    $s = trim((string) preg_replace('/[\x00-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', ' ', $s));
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
}

/** 王五 → 王*, '' → ''. */
function notify_mask_name(string $name): string
{
    $name = notify_line($name, 40);
    return $name === '' ? '' : mb_substr($name, 0, 1) . '*';
}

/** Contact details out of free text (supplier messages): emails and long digit runs. */
function notify_scrub(string $s): string
{
    $s = (string) preg_replace('/[^\s@]+@[^\s@]+/u', '[邮箱]', $s);
    return (string) preg_replace('/\d[\d\s\-]{5,}\d/', '[号码]', $s);
}

/** https://example.com of the site (live site address; else this request's host). */
function notify_origin(): string
{
    return ref_site_origin();
}

function notify_admin_url(string $route, array $q = []): string
{
    $base = PHP_SAPI === 'cli' ? '/' : site_base();
    return notify_origin() . $base . 'admin/?' . http_build_query(['r' => $route] + $q, '', '&', PHP_QUERY_RFC3986);
}

/** [title, text] for an order event: order | claim | failed. Never the buyer's phone, email, address or payer note. */
function notify_order_message(string $event, array $o): array
{
    $amount = '¥' . shop_money((int) $o['amount']);
    $lines = ['订单号：' . $o['id'], '商品：' . notify_line($o['name'], 40) . ' × ' . (int) $o['qty'], '金额：' . $amount];
    $buyer = notify_mask_name((string) ($o['buyer']['name'] ?? ''));
    if ($buyer !== '') {
        $lines[] = '买家：' . $buyer;
    }
    $lines[] = '时间：' . local_time(now_iso(), 'n月j日 H:i');
    switch ($event) {
        case 'claim':
            $title = '买家说已付款 ' . $amount . '，请核对';
            $lines[] = '去微信或支付宝的收款记录里核对 ' . $amount . ' 有没有到账，到账了在后台点「确认收款」。';
            break;
        case 'failed':
            $title = '进货失败：订单 ' . $o['id'];
            $why = notify_line(notify_scrub((string) ($o['supply']['msg'] ?? '')), 80);
            if ($why !== '') {
                $lines[] = '原因：' . $why;
            }
            $lines[] = '买家已经付款，请在后台重试进货或退款。';
            break;
        default:
            $title = '新订单 ' . $amount . '，等买家付款';
    }
    $lines[] = '后台：' . notify_admin_url('order', ['id' => $o['id']]);
    return [$title, implode("\n", $lines)];
}

/** Hook for shop.php / supply.php. Never throws. */
function notify_order(string $event, array $o): void
{
    try {
        if (!notify_on($event)) {
            return;
        }
        [$title, $text] = notify_order_message($event, $o);
        notify_send($event, $title, $text);
    } catch (Throwable $e) {
        error_log('notify ' . $event . ': ' . get_class($e));
    }
}

/** Hook for api/subscribe.php (a new address only). No email in the message. */
function notify_new_subscriber(): void
{
    try {
        if (!notify_on('sub')) {
            return;
        }
        $n = count(subs_read()['items']);
        notify_send('sub', '新订阅者（共 ' . $n . ' 位）', "又有一位读者订阅了你的飞行日志，现在共 $n 位。\n名单在后台「订阅者」：" . notify_admin_url('subs'));
    } catch (Throwable $e) {
        error_log('notify sub: ' . get_class($e));
    }
}

/** Hook for game_submit(): a new name on top of the weekly board. */
function notify_game_top(array $entry): void
{
    try {
        if (!notify_on('game')) {
            return;
        }
        notify_send('game', '盲降挑战周榜第 1：' . notify_line($entry['name'], 12), '「' . notify_line($entry['name'], 12) . '」以 ' . (int) $entry['score'] . ' 分（' . notify_line($entry['grade'] ?? '', 8) . '）登上本周第 1。'
            . "\n排行榜：" . notify_admin_url('game'));
    } catch (Throwable $e) {
        error_log('notify game: ' . get_class($e));
    }
}
