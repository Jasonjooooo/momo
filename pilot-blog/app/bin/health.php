<?php
// 服务器体检 (SPEC §21): run once a day by root's cron (pilot-blog-work/deploy/hxpilot-health.cron). Checks the disk,
// the newest backup, the TLS certificate and that the data dir can be written, and sends ONE 微信提醒 (PushPlus,
// event "health": always on, still rate limited) only when something is wrong, plus a short 「一切正常」 on Mondays
// (Beijing time) so the owner knows the check itself is alive.
// Root is needed only to read the backups and the certificate: before touching the data dir the script switches to
// the user that owns it (the web server's user), so nothing in blog-data ever becomes root-owned.
//
// Usage: php app/bin/health.php [--dry-run] [--backups=/root/hxpilot-backups] [--domain=hxpilot.com]
//        [--cert=/etc/letsencrypt/live/<domain>/fullchain.pem] [--disk=/] [--disk-max=85] [--backup-hours=26]
//        [--cert-days=14] [--php-log=/var/log/php8.3-fpm.log] [--now=<unix time, tests only>]
// --dry-run prints the report and what would be sent, sends nothing and writes nothing.
// Exit code: 0 all fine, 1 problems found (message sent, or --dry-run), 2 problems found but the message was not sent.
declare(strict_types=1);
PHP_SAPI === 'cli' || exit;
define('BLOG_APP', true);
require dirname(__DIR__) . '/lib/bootstrap.php';
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

$opt = ['dry-run' => false, 'backups' => '/root/hxpilot-backups', 'domain' => 'hxpilot.com', 'cert' => '', 'disk' => '/', 'disk-max' => '85',
    'backup-hours' => '26', 'cert-days' => '14', 'php-log' => '', 'now' => ''];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry-run') {
        $opt['dry-run'] = true;
    } elseif (preg_match('/^--([a-z-]+)=(.*)$/s', $a, $m) && array_key_exists($m[1], $opt) && $m[1] !== 'dry-run') {
        $opt[$m[1]] = $m[2];
    } else {
        fwrite(STDERR, "unknown argument: $a\n");
        exit(64);
    }
}
if (!preg_match('/^[a-z0-9.-]{1,200}$/', $opt['domain'])) {
    fwrite(STDERR, "bad --domain\n");
    exit(64);
}
$now = $opt['now'] !== '' ? (int) $opt['now'] : time();
$certFile = $opt['cert'] !== '' ? $opt['cert'] : '/etc/letsencrypt/live/' . $opt['domain'] . '/fullchain.pem';

$problems = [];
$facts = [];

// 1. disk
$total = @disk_total_space($opt['disk']);
$free = @disk_free_space($opt['disk']);
if ($total === false || $free === false || $total <= 0) {
    $problems[] = '读不到磁盘 ' . $opt['disk'] . ' 的用量。';
} else {
    $pct = (int) round(($total - $free) / $total * 100);
    $facts[] = '磁盘 ' . $opt['disk'] . ' 用了 ' . $pct . '%（剩 ' . number_format($free / 1073741824, 1) . ' GB）';
    if ($pct > (int) $opt['disk-max']) {
        $problems[] = '磁盘 ' . $opt['disk'] . ' 用了 ' . $pct . '%（超过 ' . (int) $opt['disk-max'] . '%），只剩 ' . number_format($free / 1073741824, 1) . ' GB。清理旧备份或日志。';
    }
}

// 2. newest backup
if (!is_dir($opt['backups'])) {
    $problems[] = '找不到备份文件夹 ' . $opt['backups'] . '。';
} else {
    $newest = null;
    foreach (scandir($opt['backups']) ?: [] as $f) {
        $p = $opt['backups'] . '/' . $f;
        if ($f[0] !== '.' && is_file($p) && ($newest === null || filemtime($p) > $newest[1])) {
            $newest = [$f, (int) filemtime($p)];
        }
    }
    if ($newest === null) {
        $problems[] = '备份文件夹 ' . $opt['backups'] . ' 里没有备份。';
    } else {
        $hours = (int) floor(($now - $newest[1]) / 3600);
        $facts[] = '最新备份 ' . $newest[0] . '（' . $hours . ' 小时前）';
        if ($now - $newest[1] > (int) $opt['backup-hours'] * 3600) {
            $problems[] = '最新的备份是 ' . $hours . ' 小时前的（' . $newest[0] . '），每天的备份可能没跑。';
        }
    }
}

// 3. TLS certificate
$pem = is_readable($certFile) ? (string) @file_get_contents($certFile) : '';
$cert = $pem !== '' ? @openssl_x509_parse($pem) : false;
if (!is_array($cert) || !isset($cert['validTo_time_t'])) {
    $problems[] = '读不到网站证书 ' . $certFile . '。';
} else {
    $days = (int) floor(((int) $cert['validTo_time_t'] - $now) / 86400);
    $until = local_time(gmdate('Y-m-d\TH:i:s\Z', (int) $cert['validTo_time_t']), 'Y-m-d');
    $facts[] = '证书还有 ' . $days . ' 天（到 ' . $until . '）';
    if ($days < 0) {
        $problems[] = '网站证书已经过期（' . $until . '）。访客会看到「不安全」。在服务器上运行 certbot renew。';
    } elseif ($days < (int) $opt['cert-days']) {
        $problems[] = '网站证书 ' . $days . ' 天后到期（' . $until . '），自动续期可能没跑。在服务器上运行 certbot renew。';
    }
}

// From here on: the data dir's owner, never root (files written below must stay writable by PHP-FPM).
$writer = function_exists('posix_geteuid') && posix_geteuid() !== 0;
if (!$writer && function_exists('posix_geteuid') && is_dir(DATA_DIR)) {
    $uid = (int) fileowner(DATA_DIR);
    $gid = (int) filegroup(DATA_DIR);
    if ($uid === 0) {
        $writer = true;   // the data dir itself belongs to root: root-owned files match it
    } elseif ((($pw = @posix_getpwuid($uid)) === false || @posix_initgroups($pw['name'], $gid)) && @posix_setgid($gid) && @posix_setuid($uid)) {
        $writer = true;
    }
}

// 4. data dir writable (checked as the web server's user)
$probe = DATA_DIR . '/.health-' . bin2hex(random_bytes(4));
if (!is_dir(DATA_DIR) || !is_writable(DATA_DIR) || !is_dir(DATA_DIR . '/locks') || !is_writable(DATA_DIR . '/locks') || ($writer && @file_put_contents($probe, 'x') !== 1)) {
    $problems[] = '网站数据文件夹（blog-data）写不进去：后台保存、下单都会失败。检查磁盘和文件夹权限。';
} else {
    $facts[] = '数据文件夹可以写';
}
@unlink($probe);

// 5. PHP error log growth (optional, only with --php-log)
if ($opt['php-log'] !== '' && is_file($opt['php-log'])) {
    $size = (int) filesize($opt['php-log']);
    $stateFile = DATA_DIR . '/notify/health.json';
    $prev = is_file($stateFile) ? json_decode((string) @file_get_contents($stateFile), true) : null;
    $last = is_array($prev) && isset($prev['php_log_size']) ? (int) $prev['php_log_size'] : null;
    if ($last !== null && $size > $last && $size - $last > 1048576) {
        $problems[] = 'PHP 错误日志一天里多了 ' . number_format(($size - $last) / 1048576, 1) . ' MB，网站可能在反复出错。';
    }
    $facts[] = 'PHP 错误日志 ' . number_format($size / 1048576, 1) . ' MB';
    if ($writer && !$opt['dry-run']) {
        try {
            json_write_atomic($stateFile, ['php_log_size' => $size, 'at' => gmdate('Y-m-d\TH:i:s\Z', $now)]);
        } catch (Throwable $e) {
            fwrite(STDERR, 'health state not saved: ' . get_class($e) . "\n");
        }
    }
}

// the message
$when = local_time(gmdate('Y-m-d\TH:i:s\Z', $now), 'n月j日 H:i');
$monday = local_time(gmdate('Y-m-d\TH:i:s\Z', $now), 'N') === '1';
$title = null;
if ($problems) {
    $title = '服务器体检：' . count($problems) . ' 个问题';
    $text = implode("\n", array_map(fn($p) => '· ' . $p, $problems)) . "\n\n其他：" . implode('；', $facts) . "\n（" . $when . ' 北京时间检查）';
} elseif ($monday) {
    $title = '服务器体检：一切正常';
    $text = implode("\n", array_map(fn($f) => '· ' . $f, $facts)) . "\n（每周一报一次平安；有问题会每天提醒。" . $when . ' 北京时间）';
}

echo '[' . gmdate('Y-m-d H:i:s', $now) . ' UTC] ' . ($problems ? count($problems) . ' problem(s)' : 'ok') . "\n";
foreach ($problems as $p) {
    echo '  PROBLEM ' . $p . "\n";
}
foreach ($facts as $f) {
    echo '  ' . $f . "\n";
}
if ($title === null) {
    echo "  no message (all fine, not Monday)\n";
    exit(0);
}
if ($opt['dry-run']) {
    echo "  would send: $title\n" . preg_replace('/^/m', '    | ', $text) . "\n";
    exit($problems ? 1 : 0);
}
try {
    if (notify_token() === null) {
        echo "  not sent: no PushPlus token (admin 微信提醒)\n";
        exit($problems ? 2 : 0);
    }
    // logged and rate limited like every push; still root (posix missing): send without touching blog-data
    $r = $writer ? notify_deliver('health', $title, $text, $now) : notify_post((string) notify_token(), $title, $text) + ['msg' => ''];
    echo '  ' . ($r['ok'] ? 'sent: ' . $title : 'not sent: ' . ($r['msg'] !== '' ? $r['msg'] : (string) ($r['reason'] ?? ''))) . "\n";
    exit($r['ok'] ? ($problems ? 1 : 0) : 2);
} catch (Throwable $e) {
    fwrite(STDERR, 'health send: ' . get_class($e) . ' ' . $e->getMessage() . "\n");
    exit(2);
}
