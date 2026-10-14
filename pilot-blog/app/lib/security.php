<?php
// Escaping, headers, client identity, secrets and the audit log.
// The admin login, sessions, setup code and CSRF functions of SPEC §13 are added by the admin phase.
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
