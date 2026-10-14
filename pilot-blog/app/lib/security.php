<?php
// Escaping, headers, client identity, secrets and the audit log.
// Also the admin login, sessions, first-run setup code and CSRF checks (SPEC §11).
declare(strict_types=1);
defined('BLOG_APP') || exit;

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** JSON for <script type="application/json"> blocks. */
function j(mixed $v): string
{
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    // Hostinger terminates TLS in front of LiteSpeed/Apache and forwards the scheme
    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

const CSP_ADMIN = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'; object-src 'none'";
const CSP_PREVIEW_HOME = "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; media-src 'self' blob:; font-src 'self'; connect-src 'self'; frame-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'";
const CSP_PREVIEW_POST = "default-src 'self'; script-src 'none'; style-src 'self'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'";

/** $kind: 'admin' | 'preview-home' | 'preview-post' | 'json' | 'csv' */
function send_headers(string $kind): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    header('Referrer-Policy: same-origin');
    header('X-Robots-Tag: noindex, nofollow');
    switch ($kind) {
        case 'json':
            header('Content-Type: application/json; charset=utf-8');
            return;
        case 'csv':
            header('Content-Type: text/csv; charset=utf-8');
            return;
        case 'preview-home':
            header('Content-Type: text/html; charset=utf-8');
            header('Content-Security-Policy: ' . CSP_PREVIEW_HOME);
            header('X-Frame-Options: SAMEORIGIN');
            return;
        case 'preview-post':
            header('Content-Type: text/html; charset=utf-8');
            header('Content-Security-Policy: ' . CSP_PREVIEW_POST);
            header('X-Frame-Options: SAMEORIGIN');
            return;
        default: // admin
            header('Content-Type: text/html; charset=utf-8');
            header('Content-Security-Policy: ' . CSP_ADMIN);
            header('X-Frame-Options: DENY');
            header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
            if (is_https()) {
                header('Strict-Transport-Security: max-age=15552000');
            }
    }
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

// ---------- secrets.json (0600, server side only, never logged) ----------

function secrets_get(): array
{
    $s = json_read(data_path('secrets.json'), []);
    return is_array($s) ? $s : [];
}

function secrets_update(callable $mutate): array
{
    return json_update(data_path('secrets.json'), function ($s) use ($mutate) {
        return $mutate(is_array($s) ? $s : []);
    }, []);
}

function ip_salt(): string
{
    $s = secrets_get();
    if (isset($s['ip_salt']) && preg_match('/^[0-9a-f]{64}$/', (string) $s['ip_salt'])) {
        return $s['ip_salt'];
    }
    $s = secrets_update(function (array $s) {
        if (!isset($s['ip_salt']) || !preg_match('/^[0-9a-f]{64}$/', (string) $s['ip_salt'])) {
            $s['ip_salt'] = bin2hex(random_bytes(32));
        }
        return $s;
    });
    return $s['ip_salt'];
}

function ip_hash(string $ip): string
{
    return hash_hmac('sha256', $ip, ip_salt());
}

/** Audit line in blog-data/log/admin.log (JSON lines, rotated at 1 MB, 2 kept). Never secrets or bulk emails. */
function audit(string $event, string $detail = ''): void
{
    $file = DATA_DIR . '/log/admin.log';
    $line = json_encode(['t' => now_iso(), 'event' => $event, 'ip' => substr(ip_hash(client_ip()), 0, 16), 'detail' => mb_substr($detail, 0, 300)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    with_lock('audit', function () use ($file, $line): void {
        if (is_file($file) && filesize($file) > 1048576) {
            @rename($file . '.1', $file . '.2');
            @rename($file, $file . '.1');
        }
        @file_put_contents($file, $line, FILE_APPEND);
        @chmod($file, 0600);
    });
}

// ---------- admin login, sessions, setup code, CSRF (SPEC §11) ----------

const SESSION_IDLE_SEC = 7200;          // 120 min without a request
const SESSION_ABSOLUTE_SEC = 43200;     // 12 h after login
const SETUP_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
const SETUP_VALID_SEC = 7 * 86400;
const LOGIN_IP_MAX = 5;                 // failures per IP hash ...
const LOGIN_IP_WINDOW = 900;            // ... in 15 min
const LOGIN_GLOBAL_MAX = 30;            // failures for everyone per hour
const LOGIN_GLOBAL_LOCK = 1800;         // then all logins pause 30 min
const MSG_LOGIN_LOCKED_IP = '尝试次数太多，请 15 分钟后再试。';
const MSG_LOGIN_LOCKED_ALL = '登录尝试太多，后台暂停登录 30 分钟。急用的话，在文件管理器里删除 blog-data/ratelimit/login-global 文件夹。';

/** URL path of the admin directory, e.g. '/admin/'. */
function admin_base(): string
{
    $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php')));
    return rtrim($dir, '/') . '/';
}

/** URL path of the public site root, e.g. '/'. */
function site_base(): string
{
    $dir = str_replace('\\', '/', dirname(rtrim(admin_base(), '/')));
    return rtrim($dir, '/') . '/';
}

function admin_read(): ?array
{
    $a = json_read(data_path('admin.json'));
    return is_array($a) && is_string($a['password_hash'] ?? null) && $a['password_hash'] !== '' ? $a : null;
}

function admin_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    ini_set('session.gc_maxlifetime', (string) SESSION_ABSOLUTE_SEC);
    session_save_path(DATA_DIR . '/sessions');
    session_name('pb_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => admin_base(),
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_cache_limiter('');
    session_start();
}

/** True when a valid owner session exists (idle and absolute timeouts, password-change epoch). */
function auth_check(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE || ($_SESSION['uid'] ?? null) !== 'owner') {
        return false;
    }
    $now = time();
    $admin = admin_read();
    $ok = $admin !== null
        && (int) ($_SESSION['epoch'] ?? -1) === (int) ($admin['session_epoch'] ?? 0)
        && $now - (int) ($_SESSION['last_seen'] ?? 0) <= SESSION_IDLE_SEC
        && $now - (int) ($_SESSION['login_at'] ?? 0) <= SESSION_ABSOLUTE_SEC;
    if (!$ok) {
        unset($_SESSION['uid'], $_SESSION['login_at'], $_SESSION['last_seen'], $_SESSION['epoch']);
        $_SESSION['expired'] = true;
        return false;
    }
    $_SESSION['last_seen'] = $now;
    return true;
}

/** Stop the request unless logged in: JSON 401 for fetch calls, otherwise a redirect to the login page. */
function auth_require(bool $json = false): void
{
    if (auth_check()) {
        return;
    }
    if ($json) {
        json_out(401, ['ok' => false, 'error' => 'auth_required', 'message' => '登录已经过期。输入密码继续，你写的内容还在。']);
    }
    if (!empty($_SESSION['expired'])) {
        unset($_SESSION['expired']);
        flash('info', '登录已经过期，请重新登录。');
    }
    redirect('login');
}

function auth_set_session(array $admin): void
{
    session_regenerate_id(true);
    $now = time();
    $_SESSION = [
        'uid' => 'owner',
        'login_at' => $now,
        'last_seen' => $now,
        'csrf' => bin2hex(random_bytes(32)),
        'epoch' => (int) ($admin['session_epoch'] ?? 0),
    ];
}

/** Null when logins are allowed, else the Chinese message to show. */
function login_locked(): ?string
{
    $lock = json_read(DATA_DIR . '/ratelimit/login-global/lock.json');
    if (is_array($lock) && (int) ($lock['until'] ?? 0) > time()) {
        return MSG_LOGIN_LOCKED_ALL;
    }
    if (rl_count('login-ip', ip_hash(client_ip()), LOGIN_IP_WINDOW) >= LOGIN_IP_MAX) {
        return MSG_LOGIN_LOCKED_IP;
    }
    return null;
}

/** Count one failed password or setup code; returns the lock message when this failure triggered a lock. */
function login_failed(string $what = 'login_fail'): ?string
{
    $ip = rl_hit('login-ip', ip_hash(client_ip()), LOGIN_IP_MAX, LOGIN_IP_WINDOW);
    $all = rl_hit('login-global', 'all', LOGIN_GLOBAL_MAX, 3600);
    if ($all['count'] >= LOGIN_GLOBAL_MAX) {
        json_write_atomic(DATA_DIR . '/ratelimit/login-global/lock.json', ['until' => time() + LOGIN_GLOBAL_LOCK]);
    }
    audit($what);
    if (PHP_SAPI !== 'cli') {
        sleep(1);
    }
    if ($all['count'] >= LOGIN_GLOBAL_MAX) {
        return MSG_LOGIN_LOCKED_ALL;
    }
    return $ip['count'] >= LOGIN_IP_MAX ? MSG_LOGIN_LOCKED_IP : null;
}

/** ['ok' => bool, 'error' => ?string] */
function auth_login(string $password): array
{
    $admin = admin_read();
    if ($admin === null) {
        return ['ok' => false, 'error' => '还没有设置后台密码。'];
    }
    $locked = login_locked();
    if ($locked !== null) {
        return ['ok' => false, 'error' => $locked];
    }
    if ($password === '' || strlen($password) > 1000 || !password_verify($password, $admin['password_hash'])) {
        return ['ok' => false, 'error' => login_failed() ?? '密码不对。'];
    }
    rl_clear('login-ip', ip_hash(client_ip()));
    if (password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT, ['cost' => 12])) {
        $admin = admin_update(function (array $a) use ($password) {
            $a['password_hash'] = password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]);
            return $a;
        });
    }
    auth_set_session($admin);
    audit('login_ok');
    return ['ok' => true, 'error' => null];
}

function auth_logout(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function admin_update(callable $mutate): array
{
    return json_update(data_path('admin.json'), function ($a) use ($mutate) {
        return $mutate(is_array($a) ? $a : []);
    }, []);
}

// ---------- first run ----------

function setup_needed(): bool
{
    return admin_read() === null;
}

function setup_code_path(): string
{
    return data_path('setup-code.txt');
}

/** The current code without dashes (12 chars), or null when there is no valid code file. */
function setup_code_current(): ?string
{
    $f = setup_code_path();
    if (!is_file($f) || (int) @filemtime($f) + SETUP_VALID_SEC < time()) {
        return null;
    }
    $raw = (string) @file_get_contents($f);
    if (!preg_match('/^([A-Z2-9]{4})-([A-Z2-9]{4})-([A-Z2-9]{4})$/m', $raw, $m)) {
        return null;
    }
    return $m[1] . $m[2] . $m[3];
}

/** While no password exists: make sure a valid setup-code.txt exists (new code after 7 days). Never shown over HTTP. */
function setup_code_ensure(): void
{
    if (!setup_needed() || setup_code_current() !== null) {
        return;
    }
    with_lock('setup', function (): void {
        if (!setup_needed() || setup_code_current() !== null) {
            return;
        }
        $code = '';
        for ($i = 0; $i < 12; $i++) {
            $code .= SETUP_ALPHABET[random_int(0, strlen(SETUP_ALPHABET) - 1)];
        }
        $shown = substr($code, 0, 4) . '-' . substr($code, 4, 4) . '-' . substr($code, 8, 4);
        $until = local_day(time() + SETUP_VALID_SEC);
        $text = "云底之下 后台设置码（只用一次）\n\n" . $shown . "\n\n"
            . "用法：打开 https://你的网址/admin/ ，在「设置码」里填上面这一串，再设一个后台密码。\n"
            . "设置成功后，这个文件会自动删除。\n"
            . '有效期到 ' . $until . "。过期以后再打开后台，会生成新的设置码。\n";
        $f = setup_code_path();
        @unlink($f);
        file_write_atomic($f, $text, 0600);
    });
}

function setup_code_normalize(string $s): string
{
    return strtoupper((string) preg_replace('/[\s\-\x{2010}-\x{2015}\x{FF0D}]+/u', '', $s));
}

/** ['ok' => bool, 'error' => ?string, 'field' => ?string] */
function setup_complete(string $code, string $pw1, string $pw2): array
{
    if (!setup_needed()) {
        return ['ok' => false, 'error' => '后台密码已经设置过了，请直接登录。', 'field' => null];
    }
    $locked = login_locked();
    if ($locked !== null) {
        return ['ok' => false, 'error' => $locked, 'field' => 'code'];
    }
    $want = setup_code_current();
    $got = setup_code_normalize($code);
    if ($want === null || $got === '' || !hash_equals($want, $got)) {
        $lock = login_failed('setup_fail');
        return ['ok' => false, 'error' => $lock ?? '设置码不对。请对照 setup-code.txt 再填一遍。', 'field' => 'code'];
    }
    if ($pw1 !== $pw2) {
        return ['ok' => false, 'error' => '两次输入的密码不一样。', 'field' => 'password2'];
    }
    $p = password_problem($pw1);
    if ($p !== null) {
        return ['ok' => false, 'error' => $p, 'field' => 'password'];
    }
    $admin = with_lock('setup', function () use ($pw1) {
        if (!setup_needed()) {
            return null;
        }
        $now = now_iso();
        $a = ['version' => 1, 'password_hash' => password_hash($pw1, PASSWORD_DEFAULT, ['cost' => 12]), 'created_at' => $now, 'password_changed_at' => $now, 'session_epoch' => 1];
        json_write_atomic(data_path('admin.json'), $a);
        @unlink(setup_code_path());
        return $a;
    });
    if ($admin === null) {
        return ['ok' => false, 'error' => '后台密码已经设置过了，请直接登录。', 'field' => null];
    }
    rl_clear('login-ip', ip_hash(client_ip()));
    auth_set_session($admin);
    audit('setup_done');
    return ['ok' => true, 'error' => null, 'field' => null];
}

const COMMON_PASSWORDS = [
    'password', 'password1', 'password12', 'password123', 'passw0rd123', 'p@ssw0rd123', '1234567890', '12345678910', '0123456789',
    '9876543210', '1234512345', '123123123123', '1472583690', '147258369', '1357924680', '123456789a', 'a123456789', 'a1b2c3d4e5',
    'abc1234567', 'abcd123456', 'abc123abc123', 'aa12345678', 'abcdefghij', 'qwertyuiop', 'qwerty123456', 'qwerty1234', '1qaz2wsx3edc',
    'qazwsxedc123', 'zxcvbnm123', 'asdfghjkl1', 'asdfghjkl123', 'iloveyou12', 'iloveyou1314', 'woaini1314', 'woaini520', '5201314520',
    'qq123456789', 'admin12345', 'admin123456', 'administrator', 'welcome123', 'changeme123', 'letmein123', 'football123', 'sunshine123',
    'princess123', 'dragon1234', 'monkey1234', 'pilot12345', 'aviation123', 'captain123', 'pc12pc12pc12', 'flying1234',
];

/** Chinese message when the password is not acceptable, else null. */
function password_problem(string $pw): ?string
{
    if (!mb_check_encoding($pw, 'UTF-8')) {
        return '密码里有无法识别的字符。';
    }
    $n = mb_strlen($pw);
    if ($n < 10) {
        return '密码至少要 10 个字符。';
    }
    if ($n > 200) {
        return '密码最多 200 个字符。';
    }
    if (preg_match('/^(.)\1*$/us', $pw)) {
        return '密码不能是同一个字符重复。';
    }
    if (in_array(mb_strtolower($pw), COMMON_PASSWORDS, true)) {
        return '这个密码太常见了，容易被猜到，换一个。';
    }
    $brand = '';
    try {
        $brand = (string) (json_read(data_path('content', 'site.live.json'), [])['fields']['site.brand'] ?? '');
    } catch (Throwable $e) {
    }
    foreach (array_filter([$brand, '云底之下']) as $b) {
        if (mb_strtolower(trim($pw)) === mb_strtolower($b)) {
            return '密码不能和博客名一样。';
        }
    }
    return null;
}

/** ['ok' => bool, 'error' => ?string, 'field' => ?string]; signs out every other session. */
function password_change(string $old, string $new1, string $new2): array
{
    $admin = admin_read();
    if ($admin === null) {
        return ['ok' => false, 'error' => '还没有设置后台密码。', 'field' => null];
    }
    $locked = login_locked();
    if ($locked !== null) {
        return ['ok' => false, 'error' => $locked, 'field' => 'old'];
    }
    if ($old === '' || !password_verify($old, $admin['password_hash'])) {
        return ['ok' => false, 'error' => login_failed() ?? '当前密码不对。', 'field' => 'old'];
    }
    if ($new1 !== $new2) {
        return ['ok' => false, 'error' => '两次输入的新密码不一样。', 'field' => 'new2'];
    }
    $p = password_problem($new1);
    if ($p !== null) {
        return ['ok' => false, 'error' => $p, 'field' => 'new1'];
    }
    if ($new1 === $old) {
        return ['ok' => false, 'error' => '新密码和现在的一样，换一个。', 'field' => 'new1'];
    }
    $admin = admin_update(function (array $a) use ($new1) {
        $a['password_hash'] = password_hash($new1, PASSWORD_DEFAULT, ['cost' => 12]);
        $a['password_changed_at'] = now_iso();
        $a['session_epoch'] = (int) ($a['session_epoch'] ?? 0) + 1;
        return $a;
    });
    auth_set_session($admin);
    audit('password_changed');
    return ['ok' => true, 'error' => null, 'field' => null];
}

// ---------- CSRF and same-origin checks ----------

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    if (!is_string($_SESSION['csrf'] ?? null) || strlen($_SESSION['csrf']) !== 64) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** 403 unless the request carries this session's token (form field _csrf or header X-CSRF-Token). */
function csrf_verify(bool $json = false): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
    $want = session_status() === PHP_SESSION_ACTIVE ? ($_SESSION['csrf'] ?? '') : '';
    if (is_string($sent) && is_string($want) && strlen($want) === 64 && hash_equals($want, $sent)) {
        return;
    }
    if ($json) {
        json_out(403, ['ok' => false, 'error' => 'csrf', 'message' => '登录已经过期。输入密码继续，你写的内容还在。']);
    }
    http_response_code(403);
    view('error', ['title' => '没有保存', 'message' => '这个页面放得太久，安全校验过期了，刚才的操作没有执行。请返回上一页，刷新以后再试一次。', 'bare' => !auth_check()]);
    exit;
}

/** scheme://host[:port] of this request. */
function request_origin(): string
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    return (is_https() ? 'https' : 'http') . '://' . $host;
}

function origin_normalize(string $url): ?string
{
    $p = parse_url($url);
    if (!is_array($p) || !isset($p['scheme'], $p['host'])) {
        return null;
    }
    $scheme = strtolower($p['scheme']);
    $port = isset($p['port']) ? (int) $p['port'] : null;
    if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
        $port = null;
    }
    return $scheme . '://' . strtolower($p['host']) . ($port ? ':' . $port : '');
}

/** If Origin (else Referer) is present it must be this site; 403 otherwise. */
function origin_verify(bool $json = false): void
{
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    $src = $origin !== '' && $origin !== 'null' ? $origin : (string) ($_SERVER['HTTP_REFERER'] ?? '');
    if ($src === '') {
        return;
    }
    $want = origin_normalize(request_origin());
    if ($want !== null && origin_normalize($src) === $want) {
        return;
    }
    if ($json) {
        json_out(403, ['ok' => false, 'error' => 'forbidden', 'message' => '请求不是从本站后台发出的，没有执行。']);
    }
    http_response_code(403);
    view('error', ['title' => '没有执行', 'message' => '请求不是从本站后台发出的，没有执行。', 'bare' => true]);
    exit;
}

/** Login and setup forms are hidden on plain HTTP except on this machine (passwords never travel unencrypted). */
function insecure_transport(): bool
{
    if (is_https()) {
        return false;
    }
    $host = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    return !in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
}
