<?php
// JSON files guarded by flock, written atomically (temp file + rename).
declare(strict_types=1);
defined('BLOG_APP') || exit;

final class ConflictException extends RuntimeException {}

/** Validation failure with per-field Chinese messages: ['fields.site.brand' => '…']. */
final class ValidationError extends RuntimeException
{
    public array $fields;

    public function __construct(array $fields, string $message = '')
    {
        $this->fields = $fields;
        parent::__construct($message !== '' ? $message : (string) (reset($fields) ?: '内容没有通过检查。'));
    }
}

const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

function json_read(string $path, mixed $default = null): mixed
{
    if (!is_file($path)) {
        return $default;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('cannot read ' . basename($path));
    }
    if ($raw === '') {
        return $default;
    }
    try {
        return json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('broken JSON in ' . basename($path));
    }
}

function json_encode_file(mixed $data): string
{
    return json_encode($data, JSON_FLAGS | JSON_THROW_ON_ERROR) . "\n";
}

function json_write_atomic(string $path, mixed $data, int $mode = 0600): void
{
    file_write_atomic($path, json_encode_file($data), $mode);
}

function file_write_atomic(string $path, string $bytes, int $mode = 0644): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        // public output (served by the web server) needs readable dirs; everything else stays private
        $dirMode = defined('PUBLIC_DIR') && str_starts_with($dir . '/', PUBLIC_DIR . '/') ? 0755 : 0700;
        if (!@mkdir($dir, $dirMode, true) && !is_dir($dir)) {
            throw new RuntimeException('cannot create dir for ' . basename($path));
        }
        @chmod($dir, $dirMode);
    }
    $tmp = $dir . '/.' . basename($path) . '.tmp-' . bin2hex(random_bytes(4));
    $fp = @fopen($tmp, 'xb');
    if ($fp === false) {
        throw new RuntimeException('cannot write ' . basename($path));
    }
    try {
        $len = strlen($bytes);
        $off = 0;
        while ($off < $len) {
            $n = fwrite($fp, substr($bytes, $off, 1 << 20));
            if ($n === false || $n === 0) {
                throw new RuntimeException('short write ' . basename($path));
            }
            $off += $n;
        }
        fflush($fp);
        if (function_exists('fsync')) {
            @fsync($fp);
        }
        fclose($fp);
        $fp = null;
        @chmod($tmp, $mode);
        if (!@rename($tmp, $path)) {
            throw new RuntimeException('cannot replace ' . basename($path));
        }
    } catch (Throwable $e) {
        if ($fp) {
            fclose($fp);
        }
        @unlink($tmp);
        throw $e;
    }
}

/** Exclusive named lock (re-entrant within one request). */
function with_lock(string $name, callable $fn, int $timeoutSec = 10): mixed
{
    static $held = [];
    if (!preg_match('/^[a-z0-9_-]{1,64}$/', $name)) {
        throw new InvalidArgumentException('bad lock name');
    }
    if (isset($held[$name])) {
        return $fn();
    }
    $file = DATA_DIR . '/locks/' . $name . '.lock';
    $fp = @fopen($file, 'c');
    if ($fp === false) {
        throw new RuntimeException('cannot open lock ' . $name);
    }
    $deadline = microtime(true) + $timeoutSec;
    while (!flock($fp, LOCK_EX | LOCK_NB)) {
        if (microtime(true) > $deadline) {
            fclose($fp);
            throw new RuntimeException('lock timeout ' . $name);
        }
        usleep(50000);
    }
    $held[$name] = true;
    try {
        return $fn();
    } finally {
        unset($held[$name]);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/** Lock + read + mutate + write. $mutate receives the current data and returns the new data. */
function json_update(string $path, callable $mutate, mixed $default = []): mixed
{
    $lock = 'f-' . substr(hash('sha256', $path), 0, 24);
    return with_lock($lock, function () use ($path, $mutate, $default) {
        $data = json_read($path, $default);
        $new = $mutate($data);
        json_write_atomic($path, $new);
        return $new;
    });
}

function require_rev(array $doc, int $expected): void
{
    if ((int) ($doc['rev'] ?? 0) !== $expected) {
        throw new ConflictException('这页内容在别处改过了（可能是另一个窗口）。先把你的修改复制下来，刷新页面后再保存。');
    }
}
