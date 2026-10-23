<?php
// 盲降挑战 (ILS approach mini game): server-rolled weather, signed one-time runs, server-side scoring and
// the leaderboard. The game itself runs in the visitor's browser; this file never trusts its score.
// Data: blog-data/game/scores.json (entries) and blog-data/game/used.json (run ids already submitted).
// Stored per entry: run id, name, score, grade, cloud base, RVR, decision, time, and a keyed hash of the
// visitor's network (ip_hash, the same one the rate limits use). Never a raw IP or a user agent.
declare(strict_types=1);
defined('BLOG_APP') || exit;

const GAME_RUN_MIN_SEC = 14;          // a real approach takes longer than this
const GAME_RUN_MAX_SEC = 900;         // a run token is good for 15 minutes
const GAME_USED_KEEP_SEC = 3600;      // submitted run ids are remembered for 1 hour (longer than a token lives)
const GAME_START_HOUR = 60;           // new runs per visitor (/64 or IPv4) per hour
const GAME_SUBMIT_HOUR = 20;          // submits per visitor per hour
const GAME_BODY_MAX = 2048;           // submit body, bytes
const GAME_NAME_MAX = 12;             // characters
const GAME_KEEP_DAYS = 8;             // every entry of the last 8 days is kept ...
const GAME_KEEP_BEST = 200;           // ... plus the best 200 of all time
const GAME_BOARD_N = 20;              // rows per board
const GAME_WEEK_SEC = 7 * 86400;      // 本周 board: the last 7 days, rolling
const GAME_DECISIONS = ['land', 'ga', 'none'];
const GAME_ID_RE = '/^[0-9a-f]{16}$/';
// links and contact details in a name (kept simple: the board is not a place to advertise)
const GAME_NAME_BAD_RE = '/https?|www\.|\.com|\.cn|@|＠|微信|vx|qq\s*\d/iu';

// What the visitor sees for each refusal (short, Chinese; never the reason behind a server error).
const GAME_MSG = [
    'bad' => '这一局的数据不对，重新开始一局。',
    'expired' => '这一局超过 15 分钟了，重新开始一局。',
    'fast' => '这一局飞得太快了，没有记成绩。',
    'used' => '这一局的成绩已经交过了。',
    'name' => '名字填 1 到 12 个字，不能带网址或联系方式。',
    'off' => '排行榜已关闭',
    'busy' => '提交得有点频繁，过一会儿再试。',
    'busy_start' => '开局太频繁了，过一会儿再来。',
    'origin' => '请从本站页面提交。',
    'type' => '提交的格式不对。',
    'large' => '提交的内容太长了。',
    'method' => '这个操作不能这样打开。',
    'action' => '没有这个操作。',
    'server' => '服务器忙，稍后再试。',
];

function game_board_on(?array $settings = null): bool
{
    $s = $settings ?? settings_get();
    return (bool) ($s['game']['board'] ?? true);
}

// ---------- weather and run tokens ----------

/** A random approach: below minimums about 35% of the time. Units: base ft, rvr m, wind dir deg / spd kt. */
function game_weather(): array
{
    $below = random_int(0, 999999) < 350000;
    return [
        'base' => $below ? random_int(110, 185) : 5 * random_int(43, 59),   // 110..185 or 215..295 step 5
        'rvr' => 50 * random_int(11, 32),                                   // 550..1600 step 50
        'dir' => 10 * random_int(11, 25),                                   // 110..250 step 10
        'spd' => random_int(6, 22),
        'turb' => round(random_int(35, 100) / 100, 2),                      // 0.35..1.00, always a float
    ];
}

/** True when $wx is weather game_weather() could have rolled. */
function game_weather_ok(mixed $wx): bool
{
    if (!is_array($wx)) {
        return false;
    }
    foreach (['base', 'rvr', 'dir', 'spd'] as $k) {
        if (!is_int($wx[$k] ?? null)) {
            return false;
        }
    }
    $turb = $wx['turb'] ?? null;
    return (($wx['base'] >= 110 && $wx['base'] <= 185) || ($wx['base'] >= 215 && $wx['base'] <= 295 && $wx['base'] % 5 === 0))
        && $wx['rvr'] >= 550 && $wx['rvr'] <= 1600 && $wx['rvr'] % 50 === 0
        && $wx['dir'] >= 110 && $wx['dir'] <= 250 && $wx['dir'] % 10 === 0
        && $wx['spd'] >= 6 && $wx['spd'] <= 22
        && (is_int($turb) || is_float($turb)) && $turb >= 0.35 && $turb <= 1.0;
}

function game_b64u(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function game_b64u_decode(string $s): ?string
{
    if ($s === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $s)) {
        return null;
    }
    $bin = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    return $bin === false ? null : $bin;
}

function game_sign(string $payload): string
{
    return game_b64u(hash_hmac('sha256', $payload, secret_value('game'), true));
}

/** A signed run token: base64url(JSON {id, t, base, rvr, dir, spd, turb}) . '.' . base64url(HMAC-SHA256). */
function game_token_make(array $wx, ?int $now = null): string
{
    $run = ['id' => bin2hex(random_bytes(8)), 't' => $now ?? time()] + array_intersect_key($wx, array_flip(['base', 'rvr', 'dir', 'spd', 'turb']));
    $payload = game_b64u(json_encode($run, JSON_THROW_ON_ERROR));
    return $payload . '.' . game_sign($payload);
}

/**
 * A challenge code for a run's weather, so a friend can fly the same approach: base64url(JSON wx) . '.' .
 * 16 characters of an HMAC kept apart from the run tokens' (a code is never a run, a run never a code).
 */
function game_code_make(array $wx): string
{
    $payload = game_b64u(json_encode(array_intersect_key($wx, array_flip(['base', 'rvr', 'dir', 'spd', 'turb'])), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    return $payload . '.' . substr(game_sign('wx|' . $payload), 0, 16);
}

/** The weather in a challenge code, or null when the code is not one this server made. */
function game_code_read(mixed $code): ?array
{
    if (!is_string($code) || strlen($code) > 200 || substr_count($code, '.') !== 1) {
        return null;
    }
    [$payload, $sig] = explode('.', $code);
    if ($payload === '' || strlen($sig) !== 16 || !hash_equals(substr(game_sign('wx|' . $payload), 0, 16), $sig)) {
        return null;
    }
    $json = game_b64u_decode($payload);
    $wx = $json === null ? null : json_decode($json, true);
    if (!is_array($wx)) {
        return null;
    }
    $wx = array_intersect_key($wx, array_flip(['base', 'rvr', 'dir', 'spd', 'turb']));
    return game_weather_ok($wx) ? $wx : null;
}

/**
 * Check a run token. ['ok' => true, 'run' => {id, t, wx}] or ['ok' => false, 'error' => 'bad' | 'fast' | 'expired'].
 * Does not mark it used (game_run_claim does, once the whole submit is valid).
 */
function game_token_read(mixed $tok, ?int $now = null): array
{
    $now ??= time();
    $bad = ['ok' => false, 'error' => 'bad'];
    if (!is_string($tok) || strlen($tok) > 400 || substr_count($tok, '.') !== 1) {
        return $bad;
    }
    [$payload, $sig] = explode('.', $tok);
    if ($payload === '' || !hash_equals(game_sign($payload), $sig)) {
        return $bad;
    }
    $json = game_b64u_decode($payload);
    $run = $json === null ? null : json_decode($json, true);
    if (!is_array($run) || !is_string($run['id'] ?? null) || !preg_match(GAME_ID_RE, $run['id']) || !is_int($run['t'] ?? null)) {
        return $bad;
    }
    $wx = array_intersect_key($run, array_flip(['base', 'rvr', 'dir', 'spd', 'turb']));
    if (!game_weather_ok($wx)) {
        return $bad;
    }
    $age = $now - $run['t'];
    if ($age < GAME_RUN_MIN_SEC) {
        return ['ok' => false, 'error' => $age < -60 ? 'bad' : 'fast'];
    }
    if ($age > GAME_RUN_MAX_SEC) {
        return ['ok' => false, 'error' => 'expired'];
    }
    return ['ok' => true, 'run' => ['id' => $run['id'], 't' => $run['t'], 'wx' => $wx]];
}

// ---------- names and scoring ----------

/** The name as shown on the board, or null when it is empty, too long or looks like a link or contact. */
function game_name_clean(mixed $raw): ?string
{
    if (!is_string($raw) || strlen($raw) > 400 || !mb_check_encoding($raw, 'UTF-8')) {
        return null;
    }
    $s = (string) preg_replace('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]+/u', ' ', $raw);   // control and invisible format characters
    $s = str_replace(['<', '>', '"', "'", '&'], '', $s);
    $s = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $s));
    $n = mb_strlen($s);
    if ($n < 1 || $n > GAME_NAME_MAX || preg_match(GAME_NAME_BAD_RE, $s)) {
        return null;
    }
    return $s;
}

/** A JSON number clamped to [$min, $max]; null for anything that is not a finite number. */
function game_num(mixed $v, float $min, float $max): ?float
{
    if (!is_int($v) && !is_float($v)) {
        return null;
    }
    $f = (float) $v;
    return is_finite($f) ? max($min, min($max, $f)) : null;
}

function game_grade(int $score): string
{
    return $score >= 92 ? 'S' : ($score >= 82 ? 'A' : ($score >= 68 ? 'B' : 'C'));
}

/**
 * Score one approach (the browser shows the same formula; this one counts).
 * $base: cloud base (ft). $rx/$ry: tracking error 0..3; $dax/$day: deviation at the decision -2.6..2.6;
 * $tdx: touchdown offset -2.6..2.6 or null; $fl: radio altitude (ft) when the flare was called, null for none
 * (22 ft is right). Returns ['score' => 0..100, 'grade' => S|A|B|C|F].
 */
function game_score(int $base, string $decision, float $rx, float $ry, float $dax, float $day, ?float $tdx, ?float $fl = null): array
{
    $shouldLand = $base >= 200 && abs($dax) <= 1 && abs($day) <= 1;
    $track = max(0.0, min(100.0, 100 - ($rx + $ry) * 45));
    if (($decision !== 'land' && $decision !== 'ga') || ($decision === 'land' && !$shouldLand)) {   // 'none' (or anything else): F
        return ['score' => 0, 'grade' => 'F'];
    }
    if ($decision === 'ga' && $shouldLand) {
        // a go-around that was not needed: safe, but capped at B
        $score = (int) round($track * 0.7);
        return ['score' => $score, 'grade' => $score >= 60 ? 'B' : 'C'];
    }
    if ($decision === 'ga') {
        $score = (int) round(40 + $track * 0.6);
        return ['score' => $score, 'grade' => game_grade($score)];
    }
    $tdm = abs($tdx ?? 0.0) * 9;
    $td = max(0.0, min(100.0, 100 - $tdm * 6));
    $flare = $fl === null ? 0.0 : max(0.0, 100 - abs($fl - 22) * 5);
    $score = (int) round(20 + $track * 0.4 + $td * 0.2 + $flare * 0.2);
    return ['score' => $score, 'grade' => game_grade($score)];
}

// ---------- storage ----------

function game_dir(): string
{
    return DATA_DIR . '/game';
}

/** All stored entries, oldest first. Unreadable or broken rows are skipped. */
function game_entries(): array
{
    $d = json_read(game_dir() . '/scores.json', ['items' => []]);
    $out = [];
    foreach (is_array($d['items'] ?? null) ? $d['items'] : [] as $e) {
        if (is_array($e) && is_string($e['id'] ?? null) && is_string($e['name'] ?? null) && is_int($e['score'] ?? null) && is_int($e['ts'] ?? null)) {
            $out[] = $e;
        }
    }
    return $out;
}

function game_entries_write(array $items): void
{
    json_write_atomic(game_dir() . '/scores.json', ['items' => array_values($items)]);
}

/** Best first: score high to low, the earlier run first on a tie. */
function game_sort(array $items): array
{
    usort($items, fn($a, $b) => [$b['score'], $a['ts']] <=> [$a['score'], $b['ts']]);
    return $items;
}

/** Keep the entries of the last GAME_KEEP_DAYS days plus the best GAME_KEEP_BEST of all time (oldest first). */
function game_prune(array $items, ?int $now = null): array
{
    $cut = ($now ?? time()) - GAME_KEEP_DAYS * 86400;
    $best = array_flip(array_column(array_slice(game_sort($items), 0, GAME_KEEP_BEST), 'id'));
    $keep = array_values(array_filter($items, fn($e) => $e['ts'] >= $cut || isset($best[$e['id']])));
    usort($keep, fn($a, $b) => $a['ts'] <=> $b['ts']);
    return $keep;
}

/**
 * Mark a run id as submitted. False when it was already used. Ids older than an hour are forgotten
 * (their tokens expired long before).
 */
function game_run_claim(string $id, ?int $now = null): bool
{
    $now ??= time();
    return (bool) with_lock('game', function () use ($id, $now): bool {
        $file = game_dir() . '/used.json';
        $used = json_read($file, []);
        $used = is_array($used) ? $used : [];
        $used = array_filter($used, fn($t) => is_int($t) && $t >= $now - GAME_USED_KEEP_SEC);
        if (isset($used[$id])) {
            return false;
        }
        $used[$id] = $now;
        json_write_atomic($file, $used);
        return true;
    });
}

/** Store one entry (pruning the rest). Returns all entries after the write. */
function game_record(array $entry, ?int $now = null): array
{
    return with_lock('game', function () use ($entry, $now): array {
        $items = game_entries();
        $items[] = $entry;
        $items = game_prune($items, $now);
        game_entries_write($items);
        return $items;
    });
}

/** Admin: delete one entry by id. True when it was there. */
function game_delete(string $id): bool
{
    return (bool) with_lock('game', function () use ($id): bool {
        $items = game_entries();
        $left = array_values(array_filter($items, fn($e) => $e['id'] !== $id));
        if (count($left) === count($items)) {
            return false;
        }
        game_entries_write($left);
        return true;
    });
}

/** Admin: empty the leaderboard. Returns how many entries were removed. */
function game_clear(): int
{
    return (int) with_lock('game', function (): int {
        $n = count(game_entries());
        game_entries_write([]);
        return $n;
    });
}

// ---------- boards ----------

/**
 * One row per name (its best run; on a tie the earlier one), best first, top GAME_BOARD_N.
 * Names are compared without regard to letter case. $since: only runs at or after this time.
 */
function game_board(array $items, ?int $since = null): array
{
    $best = [];
    foreach (game_sort($items) as $e) {
        if ($since !== null && $e['ts'] < $since) {
            continue;
        }
        $k = mb_strtolower($e['name']);
        if (!isset($best[$k])) {
            $best[$k] = $e;
        }
    }
    return array_slice(array_values($best), 0, GAME_BOARD_N);
}

/** 1-based place of run $id on a board, or null when it is not on it. */
function game_rank(array $board, string $id): ?int
{
    foreach ($board as $i => $e) {
        if ($e['id'] === $id) {
            return $i + 1;
        }
    }
    return null;
}

/** What the public board shows of an entry (no id, no network hash). Date in Beijing time. */
function game_public(array $e): array
{
    return [
        'name' => $e['name'],
        'score' => $e['score'],
        'grade' => (string) ($e['grade'] ?? ''),
        'base' => (int) ($e['base'] ?? 0),
        'rvr' => (int) ($e['rvr'] ?? 0),
        'date' => game_time($e['ts'], 'm-d'),
    ];
}

/** ['week' => [...], 'all' => [...]] for the public board. */
function game_boards(?array $items = null, ?int $now = null): array
{
    $items ??= game_entries();
    $now ??= time();
    return [
        'week' => array_map('game_public', game_board($items, $now - GAME_WEEK_SEC)),
        'all' => array_map('game_public', game_board($items)),
    ];
}

function game_time(int $ts, string $fmt = 'Y-m-d H:i'): string
{
    return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format($fmt);
}

// ---------- submit ----------

/**
 * Check and store one submitted run. $body: the decoded JSON. $ipk: ip_hash(client_key()).
 * ['ok' => true, 'score', 'grade', 'rank_week', 'rank_all'] or ['ok' => false, 'error' => key of GAME_MSG].
 * Throws on storage errors (the endpoint logs them and answers 'server').
 */
function game_submit(array $body, string $ipk, ?int $now = null): array
{
    $now ??= time();
    $tok = game_token_read($body['run'] ?? null, $now);
    if (!$tok['ok']) {
        return $tok;
    }
    $decision = $body['decision'] ?? null;
    $rx = game_num($body['rx'] ?? null, 0, 3);
    $ry = game_num($body['ry'] ?? null, 0, 3);
    $dax = game_num($body['dax'] ?? null, -2.6, 2.6);
    $day = game_num($body['day'] ?? null, -2.6, 2.6);
    $tdxRaw = $body['tdx'] ?? null;
    $tdx = $tdxRaw === null ? null : game_num($tdxRaw, -2.6, 2.6);
    $flRaw = $body['fl'] ?? null;
    $fl = $flRaw === null ? null : game_num($flRaw, 0, 80);
    if (!in_array($decision, GAME_DECISIONS, true) || $rx === null || $ry === null || $dax === null || $day === null
        || ($tdxRaw !== null && $tdx === null) || ($flRaw !== null && $fl === null)) {
        return ['ok' => false, 'error' => 'bad'];
    }
    $name = game_name_clean($body['name'] ?? null);
    if ($name === null) {
        return ['ok' => false, 'error' => 'name'];
    }
    $run = $tok['run'];
    $res = game_score($run['wx']['base'], $decision, $rx, $ry, $dax, $day, $tdx, $fl);
    return with_lock('game', function () use ($run, $name, $res, $decision, $ipk, $now): array {
        // single use: only now, once everything else passed, so a refused name can be fixed and sent again
        if (!game_run_claim($run['id'], $now)) {
            return ['ok' => false, 'error' => 'used'];
        }
        $leader = game_board(game_entries(), $now - GAME_WEEK_SEC)[0]['name'] ?? null;
        $items = game_record([
            'id' => $run['id'], 'name' => $name, 'score' => $res['score'], 'grade' => $res['grade'],
            'base' => $run['wx']['base'], 'rvr' => $run['wx']['rvr'], 'dec' => $decision, 'ts' => $now, 'ipk' => $ipk,
        ], $now);
        $rankWeek = game_rank(game_board($items, $now - GAME_WEEK_SEC), $run['id']);
        if ($rankWeek === 1 && ($leader === null || mb_strtolower($leader) !== mb_strtolower($name))) {
            notify_game_top(['name' => $name, 'score' => $res['score'], 'grade' => $res['grade']]);   // a new name on top (switch off by default)
        }
        return [
            'ok' => true,
            'score' => $res['score'],
            'grade' => $res['grade'],
            'rank_week' => $rankWeek,
            'rank_all' => game_rank(game_board($items), $run['id']),
        ];
    });
}
