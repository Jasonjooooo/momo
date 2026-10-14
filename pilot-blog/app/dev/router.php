<?php
// php -S router for local development and tests only. Mirrors the .htaccess denies with 403s and adds the
// posts/ and uploads/ security headers, so local tests see what Apache/LiteSpeed would serve.
// Usage: php -S 127.0.0.1:8977 -t pilot-blog pilot-blog/app/dev/router.php
declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$root = rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/');
$path = rawurldecode((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH));

function deny(int $code = 403): bool
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $code === 404 ? "Not Found\n" : "Forbidden\n";
    return true;
}

function serve(string $file, string $type, array $headers = []): bool
{
    header('Content-Type: ' . $type);
    header('X-Content-Type-Options: nosniff');
    foreach ($headers as $h) {
        header($h);
    }
    header('Content-Length: ' . filesize($file));
    readfile($file);
    return true;
}

if ($path === '' || str_contains($path, "\0") || preg_match('#(^|/)\.\.(/|$)#', $path)) {
    return deny(400);
}
$segments = array_values(array_filter(explode('/', $path), 'strlen'));
$name = (string) end($segments);

// root .htaccess: dotfiles and data/notes/templates
foreach ($segments as $seg) {
    if ($seg[0] === '.') {
        return deny();
    }
}
if (preg_match('/\.(bak|orig|swp|log|lock|md|tpl\.html|json|ini|sh|sql)$/i', $name)) {
    return deny();
}
$top = $segments[0] ?? '';

if ($top === 'app') {
    return deny();
}
if ($top === 'api') {
    if (count($segments) === 2 && in_array($name, ['chat.php', 'subscribe.php'], true)) {
        $_SERVER['SCRIPT_NAME'] = $path;
        $_SERVER['SCRIPT_FILENAME'] = $root . '/api/' . $name;
        require $root . '/api/' . $name;
        return true;
    }
    return deny();
}
if ($top === 'admin') {
    if (count($segments) === 1 || (count($segments) === 2 && $name === 'index.php')) {
        if (!is_file($root . '/admin/index.php')) {
            return deny(404);
        }
        $_SERVER['SCRIPT_NAME'] = '/admin/index.php';
        $_SERVER['SCRIPT_FILENAME'] = $root . '/admin/index.php';
        require $root . '/admin/index.php';
        return true;
    }
    if (preg_match('/\.php$/i', $name)) {
        return deny();
    }
    return false;
}
if ($top === 'uploads') {
    if (preg_match('/\.(php\d*|phtml|phar|pht|cgi|pl|py|sh|shtml|html?|svg|js)$/i', $name)) {
        return deny();
    }
    $file = $root . $path;
    if (!is_file($file)) {
        return deny(404);
    }
    $types = ['webp' => 'image/webp', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif'];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    return serve($file, $types[$ext] ?? 'application/octet-stream', ["Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox"]);
}
if ($top === 'posts') {
    $file = $root . '/posts/' . (count($segments) === 1 ? 'index.html' : implode('/', array_slice($segments, 1)));
    if (count($segments) === 1 && !str_ends_with($path, '/')) {
        header('Location: /posts/');
        http_response_code(301);
        return true;
    }
    if (!is_file($file)) {
        return deny(404);
    }
    return serve($file, 'text/html; charset=utf-8', [
        'Cache-Control: no-cache',
        "Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; font-src 'self'; script-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'; object-src 'none'",
    ]);
}
if (preg_match('/\.php$/i', $name)) {
    return deny();   // no other PHP is reachable
}
if ($path === '/' || $path === '/index.html') {
    return serve($root . '/index.html', 'text/html; charset=utf-8', ['Cache-Control: no-cache']);
}
return false;
