<?php
// 小游戏「盲降挑战」的排行榜接口。游戏在访客的浏览器里跑；天气由这里随机给出，成绩由这里按同一个公式重新算，
// 不相信浏览器发来的分数。数据存在 public_html 同级的 blog-data/game/，不存 IP、不存浏览器信息。
// GET  ?a=start    开一局：{ok, run: <签名令牌>, wx: {base, rvr, dir, spd, turb}, code: <挑战码>}（每位访客每小时 60 局）
//                  带 &c=<挑战码> 时用挑战码里的天气（好友挑战：同样的天气再飞一次）；挑战码不对就随机
// POST ?a=submit   交成绩（JSON，最多 2 KB）：{run, name, decision: land|ga|none, rx, ry, dax, day, tdx, fl}
//                  → {ok, score, grade, rank_week, rank_all}（每位访客每小时 20 次；一局只能交一次）
// GET  ?a=board    排行榜：{ok, week: [...], all: [...]}，每行 {name, score, grade, base, rvr, date: "MM-DD"}
// Every answer is JSON. Errors are {ok: false, error: <short Chinese message>}; the reason goes to error_log only.

declare(strict_types=1);

ini_set('display_errors', '0');
define('BLOG_APP', true);

/** Send one JSON answer and stop. $cache: the Cache-Control value. */
function game_out(int $status, array $body, string $cache = 'no-store'): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: ' . $cache);
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex');
    }
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

/** {ok: false, error: <message>} for a key of GAME_MSG. */
function game_fail(int $status, string $key): never
{
    game_out($status, ['ok' => false, 'error' => GAME_MSG[$key] ?? GAME_MSG['server']]);
}

/** A POST from another site (Origin, else Referer, names another host): refuse. */
function game_same_origin(): bool
{
    $src = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($src === '' || $src === 'null') {
        $src = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        if ($src === '') {
            return true;
        }
    }
    $want = origin_normalize(request_origin());
    return $want !== null && origin_normalize($src) === $want;
}

try {
    require __DIR__ . '/../app/lib/bootstrap.php';
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $a = $_GET['a'] ?? '';
    $a = is_string($a) ? $a : '';
    $want = ['start' => 'GET', 'board' => 'GET', 'submit' => 'POST'][$a] ?? null;
    if ($want === null) {
        game_fail(400, 'action');
    }
    if ($method !== $want) {
        header('Allow: ' . $want);
        game_fail(405, 'method');
    }
    if ($a === 'submit') {
        // cheap checks before anything is read or written
        if (!game_same_origin()) {
            game_fail(403, 'origin');
        }
        if (!str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
            game_fail(415, 'type');
        }
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > GAME_BODY_MAX) {
            game_fail(413, 'large');
        }
    }
    app_init('api');
    $settings = settings_get();
    $ipk = ip_hash(client_key());

    if ($a === 'board') {
        if (!game_board_on($settings)) {
            game_out(200, ['ok' => true, 'off' => true], 'public, max-age=30');
        }
        game_out(200, ['ok' => true] + game_boards(), 'public, max-age=30');
    }

    if ($a === 'start') {
        // per visitor (IPv6 /64 or IPv4); the board switch does not stop the game itself
        if (!rl_hit('game-start', $ipk, GAME_START_HOUR, 3600)['allowed']) {
            game_fail(429, 'busy_start');
        }
        $c = $_GET['c'] ?? null;
        $wx = game_code_read($c) ?? game_weather();
        game_out(200, ['ok' => true, 'run' => game_token_make($wx), 'wx' => $wx, 'code' => game_code_make($wx)]);
    }

    // submit
    if (!game_board_on($settings)) {
        game_fail(503, 'off');
    }
    if (!rl_hit('game-submit', $ipk, GAME_SUBMIT_HOUR, 3600)['allowed']) {
        game_fail(429, 'busy');
    }
    $raw = (string) file_get_contents('php://input', false, null, 0, GAME_BODY_MAX + 1);
    if (strlen($raw) > GAME_BODY_MAX) {
        game_fail(413, 'large');
    }
    $body = json_decode($raw, true, 8);
    if (!is_array($body) || array_is_list($body)) {
        game_fail(400, 'bad');
    }
    $res = game_submit($body, $ipk);
    if (!$res['ok']) {
        game_fail($res['error'] === 'used' ? 409 : 400, $res['error']);
    }
    game_out(200, $res);
} catch (Throwable $e) {
    error_log('game.php: ' . get_class($e) . ' ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    game_out(503, ['ok' => false, 'error' => defined('GAME_MSG') ? GAME_MSG['server'] : '服务器忙，稍后再试。']);
}
