<?php
// 云底之下 core bootstrap: paths, data dir, seed install. Entry points define BLOG_APP before requiring this file.
declare(strict_types=1);
defined('BLOG_APP') || exit;

if (!defined('APP_DIR')) {
    define('APP_DIR', dirname(__DIR__));
    define('PUBLIC_DIR', rtrim((string) (getenv('BLOG_PUBLIC_DIR') ?: dirname(__DIR__, 2)), '/'));
    define('DATA_DIR', rtrim((string) (getenv('BLOG_DATA_DIR') ?: dirname(PUBLIC_DIR) . '/blog-data'), '/'));
}

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/ratelimit.php';
require_once __DIR__ . '/template.php';
require_once __DIR__ . '/fields.php';
require_once __DIR__ . '/markdown.php';
require_once __DIR__ . '/blocks.php';
require_once __DIR__ . '/posts.php';
require_once __DIR__ . '/shelf.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/doubao.php';
require_once __DIR__ . '/images.php';
require_once __DIR__ . '/subscribers.php';
require_once __DIR__ . '/stats.php';
require_once __DIR__ . '/game.php';
require_once __DIR__ . '/shop.php';
require_once __DIR__ . '/shop_cats.php';
require_once __DIR__ . '/shop_pages.php';
require_once __DIR__ . '/supply.php';
require_once __DIR__ . '/referral.php';
require_once __DIR__ . '/biz.php';
require_once __DIR__ . '/publish.php';
require_once __DIR__ . '/content.php';
require_once __DIR__ . '/view.php';

const DATA_SUBDIRS = ['content', 'posts', 'trash', 'trash/posts', 'uploads', 'usage', 'ratelimit', 'sessions', 'versions', 'locks', 'log', 'stats', 'game', 'shop', 'shop/files', 'shop/qr'];

final class DataDirError extends RuntimeException {}

/**
 * Prepare the data dir and install the seed on first run.
 * $context: 'admin' | 'api' | 'cli'. Throws DataDirError when the data dir cannot be written.
 */
function app_init(string $context): void
{
    static $done = false;
    if ($done) {
        return;
    }
    date_default_timezone_set('UTC');
    error_reporting(E_ALL);
    if ($context !== 'cli') {
        ini_set('display_errors', '0');
    }
    mb_internal_encoding('UTF-8');
    data_dir_ensure();
    if (!is_file(data_path('content', 'site.draft.json'))) {
        with_lock('install', function (): void {
            if (!is_file(data_path('content', 'site.draft.json'))) {
                seed_install();
            }
        });
    }
    if ($context === 'admin') {
        setup_code_ensure();   // first run: setup-code.txt in the data dir (never shown over HTTP)
    }
    $done = true;
}

function data_dir_ensure(): void
{
    foreach (array_merge([''], DATA_SUBDIRS) as $sub) {
        $dir = DATA_DIR . ($sub === '' ? '' : '/' . $sub);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new DataDirError(DATA_DIR);
        }
    }
    if (!is_writable(DATA_DIR) || !is_writable(DATA_DIR . '/locks')) {
        throw new DataDirError(DATA_DIR);
    }
    $ht = DATA_DIR . '/.htaccess';
    if (!is_file($ht)) {
        if (@file_put_contents($ht, "Require all denied\n") === false) {
            throw new DataDirError(DATA_DIR);
        }
        @chmod($ht, 0644);
    }
}

/** First run: launch content (baseline seed + launch patch) as draft and live, seed posts, default settings. */
function seed_install(): void
{
    $launch = seed_launch_content();
    $now = now_iso();
    $site = $launch['site'];
    $site['rev'] = 1;
    $site['updated_at'] = $now;
    json_write_atomic(data_path('content', 'site.draft.json'), $site);
    json_write_atomic(data_path('content', 'site.live.json'), $site + ['published_at' => $now, 'from_rev' => 1]);
    $shelf = $launch['shelf'];
    $shelf['rev'] = 1;
    $shelf['updated_at'] = $now;
    json_write_atomic(data_path('content', 'shelf.draft.json'), $shelf);
    json_write_atomic(data_path('content', 'shelf.live.json'), $shelf + ['published_at' => $now, 'from_rev' => 1]);
    foreach ($launch['posts'] as $post) {
        $path = data_path('posts', $post['id'] . '.json');
        if (!is_file($path)) {
            json_write_atomic($path, $post);
        }
    }
    if (!is_file(data_path('settings.json'))) {
        $settings = $launch['settings'];
        $settings['rev'] = 1;
        $settings['updated_at'] = $now;
        json_write_atomic(data_path('settings.json'), $settings);
    }
}

/** Seed content as data: ['site','shelf','posts','settings']; $launch applies app/seed/launch.patch.json. */
function seed_content(bool $launch): array
{
    $site = json_read(app_path('seed', 'site.baseline.json'));
    $shelf = json_read(app_path('seed', 'shelf.baseline.json'));
    $settings = json_read(app_path('seed', 'settings.baseline.json'));
    if (!is_array($site) || !is_array($shelf) || !is_array($settings)) {
        throw new RuntimeException('seed files missing: run app/bin/extract-seed.php');
    }
    $posts = [];
    foreach (glob(app_path('seed', 'posts') . '/p*.json') ?: [] as $f) {
        $posts[] = json_read($f);
    }
    usort($posts, fn($a, $b) => strcmp($a['id'], $b['id']));
    if ($launch) {
        $patch = json_read(app_path('seed', 'launch.patch.json'), []);
        foreach ($patch['fields'] ?? [] as $k => $v) {
            if (!array_key_exists($k, $site['fields'])) {
                throw new RuntimeException('launch patch: unknown field ' . $k);
            }
            $site['fields'][$k] = $v;
        }
        foreach ($patch['faq_patch'] ?? [] as $row) {
            $hit = false;
            foreach ($site['faq'] as $i => $r) {
                if ($r['nid'] === $row['nid']) {
                    $site['faq'][$i] = array_merge($r, $row);
                    $hit = true;
                }
            }
            if (!$hit) {
                throw new RuntimeException('launch patch: unknown faq row ' . $row['nid']);
            }
        }
        $settings = array_replace_recursive($settings, $patch['settings'] ?? []);
    }
    return ['site' => $site, 'shelf' => $shelf, 'posts' => $posts, 'settings' => settings_normalize($settings)];
}

function seed_launch_content(): array
{
    return seed_content(true);
}

// ---------- paths ----------

function path_part_ok(string $p): bool
{
    return $p !== '.' && $p !== '..' && preg_match('/^[A-Za-z0-9._-]+$/', $p) === 1;
}

function safe_join(string $base, array $parts): string
{
    foreach ($parts as $p) {
        if (!path_part_ok($p)) {
            throw new InvalidArgumentException('bad path part');
        }
    }
    $path = $base . ($parts ? '/' . implode('/', $parts) : '');
    // the deepest existing ancestor must resolve inside the base dir
    $baseReal = realpath($base);
    if ($baseReal !== false) {
        $probe = dirname($path);
        while (!file_exists($probe) && strlen($probe) > strlen($base)) {
            $probe = dirname($probe);
        }
        $real = realpath($probe);
        if ($real === false || ($real !== $baseReal && !str_starts_with($real . '/', $baseReal . '/'))) {
            throw new InvalidArgumentException('path escapes base');
        }
    }
    return $path;
}

function public_path(string ...$parts): string
{
    return safe_join(PUBLIC_DIR, $parts);
}

function data_path(string ...$parts): string
{
    return safe_join(DATA_DIR, $parts);
}

function app_path(string ...$parts): string
{
    return safe_join(APP_DIR, $parts);
}

// ---------- time ----------

function now_iso(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function local_day(?int $ts = null): string
{
    $d = new DateTimeImmutable('@' . ($ts ?? time()));
    return $d->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
}

function local_time(string $iso, string $fmt = 'Y-m-d H:i'): string
{
    try {
        $d = new DateTimeImmutable($iso);
    } catch (Exception $e) {
        return '';
    }
    return $d->setTimezone(new DateTimeZone('Asia/Shanghai'))->format($fmt);
}
