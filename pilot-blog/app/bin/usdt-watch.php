<?php
// USDT 到账核对 (SPEC §22): run every minute by cron as the web server's user (pilot-blog-work/deploy/hxpilot-usdt.cron).
// Looks for incoming USDT on every chain that has a quote waiting (an order whose buyer chose USDT, until 6 hours after
// the quote expired), marks matching orders paid exactly like the owner's 确认收款, and lists everything else for the
// owner. Exits at once, without any network call, when no quote is waiting. Short timeouts (3 s connect, 8 s total);
// failures go to stderr / the cron log and never change an order.
//
// Usage: php app/bin/usdt-watch.php [--force] [--quiet]
//   --force  ask the chains even if a pay page asked less than 15 seconds ago
//   --quiet  print nothing unless something happened or failed
// Exit code: 0 fine (or nothing to do), 1 a chain could not be asked, 64 bad argument.
// If started as root it switches to the owner of the data dir first, so no file in blog-data becomes root-owned.
declare(strict_types=1);
PHP_SAPI === 'cli' || exit;
define('BLOG_APP', true);
require dirname(__DIR__) . '/lib/bootstrap.php';
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

$force = false;
$quiet = false;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--force') {
        $force = true;
    } elseif ($a === '--quiet') {
        $quiet = true;
    } else {
        fwrite(STDERR, "unknown argument: $a\n");
        exit(64);
    }
}

if (function_exists('posix_geteuid') && posix_geteuid() === 0 && is_dir(DATA_DIR)) {
    $uid = (int) fileowner(DATA_DIR);
    $gid = (int) filegroup(DATA_DIR);
    if ($uid !== 0 && !((($pw = @posix_getpwuid($uid)) === false || @posix_initgroups($pw['name'], $gid)) && @posix_setgid($gid) && @posix_setuid($uid))) {
        fwrite(STDERR, "usdt-watch: cannot switch to the data dir owner; run it as www-data\n");
        exit(1);
    }
}

$lock = @fopen(DATA_DIR . '/locks/usdt-watch.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);   // the previous run is still going
}

try {
    app_init('cli');
    if (!is_file(shop_orders_path()) || !usdt_any_watched()) {
        exit(0);
    }
    $before = shop_page_signature(shop_products());
    $failed = false;
    foreach (usdt_check_all($force) as $chain => $r) {
        $label = USDT_CHAINS[$chain]['label'];
        if (!$r['ok']) {
            $failed = true;
            fwrite(STDERR, gmdate('c') . " $label: " . $r['err'] . "\n");
        } elseif ($r['asked'] && (!$quiet || $r['paid'] || $r['odd'])) {
            echo gmdate('c') . " $label: " . count($r['paid']) . ' paid, ' . $r['odd'] . " unmatched\n";
        }
    }
    shop_republish_if_changed($before);
    exit($failed ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, gmdate('c') . ' usdt-watch: ' . get_class($e) . ' ' . $e->getMessage() . "\n");
    exit(1);
}
