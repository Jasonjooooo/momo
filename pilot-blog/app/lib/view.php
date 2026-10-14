<?php
// Admin page rendering helpers (SPEC §13 view.php). Views live in app/views and echo only through h() and j().
declare(strict_types=1);
defined('BLOG_APP') || exit;

/** Render app/views/{name}.php inside app/views/layout.php. */
function view(string $name, array $vars = []): void
{
    if (!preg_match('/^[a-z_]+$/', $name) || !is_file(APP_DIR . '/views/' . $name . '.php')) {
        throw new InvalidArgumentException('no such view');
    }
    $vars += ['title' => '', 'page' => $name, 'bare' => false, 'flashes' => flash_take()];
    $render = static function (string $__file, array $__vars): string {
        extract($__vars, EXTR_SKIP);
        ob_start();
        try {
            require $__file;
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    };
    $vars['content'] = $render(APP_DIR . '/views/' . $name . '.php', $vars);
    echo $render(APP_DIR . '/views/layout.php', $vars);
}

/** Admin URL for a route: '/admin/?r=site&id=…'. */
function url(string $route, array $q = []): string
{
    $q = ($route === '' || $route === 'dashboard') ? $q : ['r' => $route] + $q;
    return admin_base() . ($q ? '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986) : '');
}

function redirect(string $route, array $q = []): never
{
    header('Location: ' . url($route, $q), true, 303);
    exit;
}

/** $type: 'ok' | 'error' | 'info' */
function flash(string $type, string $msg): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['flash'][] = [$type, $msg];
    }
}

function flash_take(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return [];
    }
    $f = is_array($_SESSION['flash'] ?? null) ? $_SESSION['flash'] : [];
    unset($_SESSION['flash']);
    return $f;
}

function json_out(int $status, array $body): never
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** Cache-busting URL of an admin asset. */
function asset_url(string $file): string
{
    $path = PUBLIC_DIR . '/admin/assets/' . $file;
    $v = is_file($path) ? substr(hash_file('sha256', $path), 0, 8) : '0';
    return admin_base() . 'assets/' . $file . '?v=' . $v;
}

/** Beijing time for display: '10月14日 16:05' (with the year when it is not this year). */
function cn_time(?string $iso, bool $withTime = true): string
{
    if ($iso === null || $iso === '') {
        return '';
    }
    $y = local_time($iso, 'Y');
    $fmt = ($y === local_time(now_iso(), 'Y') ? '' : 'Y年') . 'n月j日' . ($withTime ? ' H:i' : '');
    return local_time($iso, $fmt);
}
