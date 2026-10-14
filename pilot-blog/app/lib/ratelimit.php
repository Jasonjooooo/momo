<?php
// Fixed-window counters in blog-data/ratelimit/<bucket>/<hash>.json.
declare(strict_types=1);
defined('BLOG_APP') || exit;

function rl_file(string $bucket, string $key): string
{
    if (!preg_match('/^[a-z0-9-]{1,40}$/', $bucket)) {
        throw new InvalidArgumentException('bad bucket');
    }
    return DATA_DIR . '/ratelimit/' . $bucket . '/' . hash('sha256', ip_salt() . '|' . $key) . '.json';
}

/** Count one hit. ['allowed' => bool, 'count' => int, 'retry_after' => seconds] */
function rl_hit(string $bucket, string $key, int $limit, int $windowSec): array
{
    $file = rl_file($bucket, $key);
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $now = time();
    $fp = @fopen($file, 'c+');
    if ($fp === false) {
        return ['allowed' => true, 'count' => 0, 'retry_after' => 0];   // never lock visitors out because of a disk hiccup
    }
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $d = json_decode((string) $raw, true);
    if (!is_array($d) || !isset($d['start'], $d['count']) || $now - (int) $d['start'] >= $windowSec || (int) $d['start'] > $now) {
        $d = ['start' => $now, 'count' => 0];
    }
    $d['count'] = (int) $d['count'] + 1;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($d));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    if (mt_rand(1, 100) === 1) {
        rl_gc();
    }
    return ['allowed' => $d['count'] <= $limit, 'count' => $d['count'], 'retry_after' => max(0, (int) $d['start'] + $windowSec - $now)];
}

/** Peek without counting. */
function rl_count(string $bucket, string $key, int $windowSec): int
{
    $d = json_read(rl_file($bucket, $key));
    if (!is_array($d) || time() - (int) ($d['start'] ?? 0) >= $windowSec) {
        return 0;
    }
    return (int) ($d['count'] ?? 0);
}

function rl_clear(string $bucket, string $key): void
{
    @unlink(rl_file($bucket, $key));
}

/** Delete windows older than 2 days. */
function rl_gc(): void
{
    $cut = time() - 2 * 86400;
    foreach (glob(DATA_DIR . '/ratelimit/*/*.json') ?: [] as $f) {
        if (@filemtime($f) < $cut) {
            @unlink($f);
        }
    }
}
