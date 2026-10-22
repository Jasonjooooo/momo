<?php
// run.php group `game` (盲降挑战 leaderboard: api/game.php, app/lib/game.php, admin 小游戏排行榜). Loaded by run.php in the
// child process; uses its helpers (ok, throws, http, clear_rl, shop_http) and biz_admin_login / biz_csrf from
// biz-ref-tests.php. Temp dirs only.
declare(strict_types=1);

/** A game entry as game_submit() stores it (for the board, prune and admin tests). */
function game_fake(string $name, int $score, int $ts, array $extra = []): array
{
    static $n = 0;
    $n++;
    return $extra + ['id' => str_pad(dechex($n), 16, '0', STR_PAD_LEFT), 'name' => $name, 'score' => $score, 'grade' => game_grade($score),
        'base' => 250, 'rvr' => 800, 'dec' => 'land', 'ts' => $ts, 'ipk' => str_repeat('a', 64)];
}

/** A valid submit body for $run; $over replaces fields. */
function game_body(string $run, array $over = []): array
{
    return $over + ['run' => $run, 'name' => '小飞', 'decision' => 'ga', 'rx' => 0.1, 'ry' => 0.1, 'dax' => 0.5, 'day' => 0.5, 'tdx' => null];
}

function game_post(array|string $body, array $headers = []): array
{
    $opt = is_array($body) ? ['json' => $body] : ['body' => $body, 'headers' => ['Content-Type: application/json']];
    $opt['headers'] = array_merge($opt['headers'] ?? [], $headers);
    return http('POST', '/api/game.php?a=submit', $opt);
}

/** A run token minted $age seconds ago with fixed weather (base 150 = below minimums unless given). */
function game_tok(int $age = 20, array $wx = []): string
{
    return game_token_make($wx + ['base' => 150, 'rvr' => 800, 'dir' => 200, 'spd' => 10, 'turb' => 0.5], time() - $age);
}

function group_game(): void
{
    app_init('cli');
    $now = time();

    // ---- weather ----
    $below = 0;
    $okAll = true;
    for ($i = 0; $i < 3000; $i++) {
        $wx = game_weather();
        $okAll = $okAll && game_weather_ok($wx) && array_keys($wx) === ['base', 'rvr', 'dir', 'spd', 'turb'] && round($wx['turb'], 2) === $wx['turb'];
        $below += $wx['base'] < 200 ? 1 : 0;
    }
    ok($okAll, 'weather: 3000 rolls all in range (base 110..185 or 215..295 step 5, rvr step 50, dir step 10, spd 6..22, turb 2 decimals)');
    ok($below > 3000 * 0.29 && $below < 3000 * 0.41, 'weather: about 35% below minimums', (string) $below);
    ok(!game_weather_ok(['base' => 200, 'rvr' => 800, 'dir' => 200, 'spd' => 10, 'turb' => 0.5]) && !game_weather_ok(['base' => 217, 'rvr' => 800, 'dir' => 200, 'spd' => 10, 'turb' => 0.5])
        && !game_weather_ok(['base' => 250, 'rvr' => 825, 'dir' => 200, 'spd' => 10, 'turb' => 0.5]) && !game_weather_ok(['base' => 250, 'rvr' => 800, 'dir' => 200, 'spd' => 10, 'turb' => 1.5])
        && !game_weather_ok(['base' => '250', 'rvr' => 800, 'dir' => 200, 'spd' => 10, 'turb' => 0.5]), 'weather check refuses 200 ft, off-step values, out-of-range turbulence and strings');

    // ---- run tokens ----
    $wx = ['base' => 250, 'rvr' => 800, 'dir' => 200, 'spd' => 10, 'turb' => 1.0];
    $tok = game_token_make($wx, $now - 20);
    [$payload, $sig] = explode('.', $tok);
    $run = json_decode((string) game_b64u_decode($payload), true);
    ok((bool) preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]{43}$/', $tok) && preg_match(GAME_ID_RE, $run['id']) && $run['t'] === $now - 20 && $run['base'] === 250 && (float) $run['turb'] === 1.0,
        'token = base64url(JSON {id: 16 hex, t, weather}) . base64url(HMAC-SHA256)', $tok);
    $r = game_token_read($tok, $now);
    ok($r['ok'] && $r['run']['id'] === $run['id'] && $r['run']['wx']['base'] === 250 && $r['run']['wx']['rvr'] === 800, 'a 20 s old token reads back with its weather');
    $forged = game_b64u(json_encode(['base' => 290] + $run));
    ok(game_token_read($forged . '.' . $sig, $now) === ['ok' => false, 'error' => 'bad'], 'tamper: changed payload with the old signature refused');
    $flip = $sig;
    $flip[5] = $flip[5] === 'A' ? 'B' : 'A';
    ok(game_token_read($payload . '.' . $flip, $now)['error'] === 'bad', 'tamper: changed signature refused');
    $other = game_b64u(hash_hmac('sha256', $forged, str_repeat('0', 64), true));
    ok(game_token_read($forged . '.' . $other, $now)['error'] === 'bad', 'tamper: signed with another key refused');
    $bad = true;
    foreach ([null, '', '.', $payload, $tok . '.x', $tok . 'x', ' ' . $tok, ['x'], 12, str_repeat('a', 500) . '.' . $sig] as $junk) {
        $bad = $bad && game_token_read($junk, $now)['error'] === 'bad';
    }
    ok($bad, 'junk tokens (empty, no signature, extra dot, arrays, numbers, too long) refused');
    $wild = game_b64u(json_encode(['id' => 'abcdabcdabcdabcd', 't' => $now - 20, 'base' => 999, 'rvr' => 800, 'dir' => 200, 'spd' => 10, 'turb' => 0.5]));
    ok(game_token_read($wild . '.' . game_sign($wild), $now)['error'] === 'bad', 'a correctly signed token with impossible weather is still refused');
    ok(game_token_read(game_token_make($wx, $now - 13), $now)['error'] === 'fast' && game_token_read(game_token_make($wx, $now - 14), $now)['ok'], 'too fast: 13 s refused, 14 s accepted');
    ok(game_token_read(game_token_make($wx, $now - 900), $now)['ok'] && game_token_read(game_token_make($wx, $now - 901), $now)['error'] === 'expired', 'expiry: 15 min accepted, 15 min + 1 s refused');
    ok(game_token_read(game_token_make($wx, $now + 3600), $now)['error'] === 'bad', 'a token from the future refused');

    // ---- single use ----
    $id = 'feedfacefeedface';
    ok(game_run_claim($id, $now) && !game_run_claim($id, $now + 5), 'reuse: a run id is accepted once, then refused');
    json_write_atomic(DATA_DIR . '/game/used.json', ['0000000000000001' => $now - 3700, '0000000000000002' => $now - 100]);
    game_run_claim('0000000000000003', $now);
    $used = json_read(DATA_DIR . '/game/used.json');
    ok(array_keys($used) === ['0000000000000002', '0000000000000003'], 'used ids older than 1 hour pruned', json_encode($used));
    ok((fileperms(DATA_DIR . '/game/used.json') & 0777) === 0600, 'used.json is private (0600)');

    // ---- names ----
    $names = ['  小飞  ' => '小飞', "A\tB\n C" => 'A B C', '<b>Ace</b>' => 'bAce/b', "零\u{200B}宽" => '零 宽', "全角\u{3000}空格" => '全角 空格', 'Tom & "Jerry"' => 'Tom Jerry',
        '一二三四五六七八九十一二' => '一二三四五六七八九十一二', 'Captain_737' => 'Captain_737'];
    $bad = [];
    foreach ($names as $in => $want) {
        if (game_name_clean($in) !== $want) {
            $bad[] = json_encode($in, JSON_UNESCAPED_UNICODE) . ' → ' . json_encode(game_name_clean($in), JSON_UNESCAPED_UNICODE);
        }
    }
    ok(!$bad, 'name: trimmed, control and invisible characters and <>"\'& removed, spaces collapsed, 12 characters allowed', implode('; ', $bad));
    $bad = [];
    foreach (['', '   ', "\x00\x01", '<>&', '一二三四五六七八九十一二三', 'http://x.io', 'HTTPS', 'www.abc', 'abc.com', 'abc.CN', 'me@mail', '加微信 abc', 'VX123', 'qq 12345', 'QQ88', "\xff\xfe", ['a'], 12] as $in) {
        if (game_name_clean($in) !== null) {
            $bad[] = json_encode($in, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }
    }
    ok(!$bad, 'name: empty, 13 characters, links, @, 微信, vx, qq + digits, broken UTF-8 and non-strings refused', implode(' ', $bad));
    ok(game_name_clean('QQ飞行') === 'QQ飞行', 'name: qq without digits is fine');

    // ---- scoring (every branch) ----
    $sc = fn(int $base, string $d, float $rx, float $ry, float $dax, float $day, ?float $tdx) => game_score($base, $d, $rx, $ry, $dax, $day, $tdx);
    ok($sc(150, 'land', 0, 0, 0, 0, 0) === ['score' => 0, 'grade' => 'F'], 'land below minimums: F 0');
    ok($sc(250, 'land', 0, 0, 1.2, 0, 0) === ['score' => 0, 'grade' => 'F'] && $sc(250, 'land', 0, 0, 0, -1.01, 0) === ['score' => 0, 'grade' => 'F'], 'land off the localizer or glideslope (|dev| > 1): F 0');
    ok($sc(250, 'none', 0, 0, 0, 0, 0) === ['score' => 0, 'grade' => 'F'] && $sc(150, 'none', 0, 0, 0, 0, null) === ['score' => 0, 'grade' => 'F'], 'no decision: F 0');
    ok($sc(250, 'ga', 0, 0, 0, 0, null) === ['score' => 70, 'grade' => 'B'], 'unneeded go-around, perfect tracking: round(100*0.7) = 70, B');
    ok($sc(250, 'ga', 0.2, 0.2, 1, -1, null) === ['score' => 57, 'grade' => 'C'], 'unneeded go-around (|dev| = 1 still should land): track 82 → 57, C');
    ok($sc(250, 'ga', 0.6, 0.0, 0, 0, null) === ['score' => 51, 'grade' => 'C'] && $sc(250, 'ga', 0.1, 0.0, 0, 0, null) === ['score' => 67, 'grade' => 'B'], 'unneeded go-around: B at >= 60 else C');
    ok($sc(150, 'ga', 0.1, 0.1, 0, 0, null) === ['score' => 95, 'grade' => 'S'], 'correct go-around: round(40 + 91*0.6) = 95, S');
    ok($sc(250, 'ga', 3, 3, 2, 0, null) === ['score' => 40, 'grade' => 'C'], 'correct go-around (off course at 250 ft), track 0: 40, C');
    ok($sc(250, 'land', 0, 0, 0.5, -0.5, 0) === ['score' => 100, 'grade' => 'S'] && $sc(250, 'land', 0, 0, 0.5, -0.5, null) === ['score' => 100, 'grade' => 'S'], 'perfect landing: 30 + 45 + 25 = 100, S (tdx null counts as 0)');
    ok($sc(250, 'land', 0, 0, 0, 0, -1) === ['score' => 87, 'grade' => 'A'], 'landing 1 off the touchdown zone: td 46 → round(86.5) = 87, A');
    ok($sc(200, 'land', 1, 0.5, 0, 0, 0) === ['score' => 70, 'grade' => 'B'], 'landing at exactly 200 ft, track 32.5: round(69.625) = 70, B');
    ok($sc(250, 'land', 3, 3, 0, 0, 2.6) === ['score' => 30, 'grade' => 'C'], 'worst correct landing: 30, C');
    ok([game_grade(92), game_grade(91), game_grade(82), game_grade(81), game_grade(68), game_grade(67), game_grade(0)] === ['S', 'A', 'A', 'B', 'B', 'C', 'C'], 'grade thresholds S >= 92, A >= 82, B >= 68, else C');
    ok(game_num(5, 0, 3) === 3.0 && game_num(-9.5, -2.6, 2.6) === -2.6 && game_num(1, 0, 3) === 1.0 && game_num('1', 0, 3) === null && game_num(null, 0, 3) === null && game_num(true, 0, 3) === null, 'numbers clamped; strings, null, booleans refused');

    // ---- boards: dedupe, sort, week, top 20 ----
    $items = [
        game_fake('Ace', 80, $now - 100),
        game_fake('Bob', 90, $now - 90),
        game_fake('Cat', 90, $now - 80),
        game_fake('Bob', 70, $now - 70),
        game_fake('ace', 90, $now - 60),
        game_fake('Eve', 50, $now - 50),
        game_fake('Eve', 50, $now - 40),
        game_fake('Old', 99, $now - 10 * 86400),
    ];
    $all = game_board($items);
    ok(array_map(fn($e) => $e['name'] . $e['score'], $all) === ['Old99', 'Bob90', 'Cat90', 'ace90', 'Eve50'], 'board: one row per name (case-insensitive, its best), score desc then earlier first', json_encode(array_column($all, 'name')));
    ok($all[4]['ts'] === $now - 50, 'board: same name and score, the earlier run wins');
    $week = game_board($items, $now - GAME_WEEK_SEC);
    ok(array_column($week, 'name') === ['Bob', 'Cat', 'ace', 'Eve'], 'week board: last 7 days only');
    ok(game_rank($all, $all[2]['id']) === 3 && game_rank($all, $items[0]['id']) === null, 'rank is 1-based; a deduped-away run has none');
    $many = [];
    for ($i = 0; $i < 25; $i++) {
        $many[] = game_fake('p' . $i, $i, $now - 10);
    }
    $b = game_board($many);
    ok(count($b) === 20 && $b[0]['name'] === 'p24' && game_rank($b, $many[0]['id']) === null, 'board: top 20 only; 21st and lower have no rank');
    $pub = game_public(game_fake('Pub', 77, (int) strtotime('2026-03-04 20:00:00 UTC')));
    ok($pub === ['name' => 'Pub', 'score' => 77, 'grade' => 'B', 'base' => 250, 'rvr' => 800, 'date' => '03-05'], 'public row: name, score, grade, base, rvr, Beijing MM-DD; no id or network hash', json_encode($pub));

    // ---- prune ----
    $old = [];
    for ($i = 0; $i < 250; $i++) {
        $old[] = game_fake('o' . $i, $i % 101, $now - 9 * 86400 - $i);
    }
    $recent = [game_fake('r1', 1, $now - 7 * 86400), game_fake('r2', 0, $now - 100)];
    $kept = game_prune(array_merge($recent, $old), $now);
    $keptIds = array_column($kept, 'id');
    $bestOld = array_column(array_slice(game_sort(array_merge($recent, $old)), 0, GAME_KEEP_BEST), 'id');
    ok(count($kept) === 202 && in_array($recent[0]['id'], $keptIds, true) && in_array($recent[1]['id'], $keptIds, true), 'prune: everything of the last 8 days plus the best 200', (string) count($kept));
    $dropped = array_filter($old, fn($e) => !in_array($e['id'], $keptIds, true));
    $keptOld = array_filter($kept, fn($e) => $e['ts'] < $now - 8 * 86400);
    ok(!array_diff($bestOld, $keptIds) && count($dropped) === 50 && max(array_column($dropped, 'score')) <= min(array_column($keptOld, 'score')), 'prune: the 50 lowest old runs are gone');
    $ts = array_column($kept, 'ts');
    $sorted = $ts;
    sort($sorted);
    ok($ts === $sorted, 'prune: stored oldest first');

    // ---- submit (library) ----
    exec('rm -rf ' . escapeshellarg(DATA_DIR . '/game') . '/*');
    $ipk = ip_hash('203.0.113.9');
    $t1 = game_tok(30);
    $r = game_submit(game_body($t1, ['name' => '  机长 ']), $ipk);
    ok($r === ['ok' => true, 'score' => 95, 'grade' => 'S', 'rank_week' => 1, 'rank_all' => 1], 'submit: correct go-around scored on the server, ranked 1', json_encode($r));
    $e = game_entries()[0];
    ok(array_keys($e) === ['id', 'name', 'score', 'grade', 'base', 'rvr', 'dec', 'ts', 'ipk'] && $e['name'] === '机长' && $e['ipk'] === $ipk && $e['dec'] === 'ga' && $e['base'] === 150, 'stored entry {id, name, score, grade, base, rvr, dec, ts, ipk}', json_encode($e, JSON_UNESCAPED_UNICODE));
    ok(!str_contains((string) file_get_contents(DATA_DIR . '/game/scores.json'), '203.0.113.9') && (fileperms(DATA_DIR . '/game/scores.json') & 0777) === 0600, 'scores.json holds no raw IP and is private (0600)');
    ok(game_submit(game_body($t1), $ipk) === ['ok' => false, 'error' => 'used'], 'submit: the same run twice is refused');
    $t2 = game_tok(30);
    ok(game_submit(game_body($t2, ['name' => 'www.spam']), $ipk)['error'] === 'name', 'submit: a bad name is refused ...');
    ok(game_submit(game_body($t2, ['name' => '副驾']), $ipk)['ok'], '... and the same run can be sent again with a good name');
    $bad = [];
    foreach (['decision' => 'flare', 'rx' => '0.1', 'dax' => null, 'tdx' => 'x', 'name' => ['a']] as $k => $v) {
        $res = game_submit(game_body(game_tok(30), [$k => $v]), $ipk);
        if ($res['ok']) {
            $bad[] = $k;
        }
    }
    ok(!$bad, 'submit: unknown decision, string or missing numbers, string tdx, array name refused', implode(',', $bad));
    $r = game_submit(game_body(game_tok(30, ['base' => 250]), ['decision' => 'land', 'rx' => 99, 'ry' => -5, 'dax' => 0, 'day' => 0, 'tdx' => -40]), $ipk);
    ok($r['ok'] && $r['score'] === 30 && $r['grade'] === 'C', 'submit: rx/ry clamped to [0,3], tdx to [-2.6,2.6] (rx 99 → 3, ry -5 → 0: track 0, not 100)', json_encode($r));
    $low = [];
    for ($i = 0; $i < 22; $i++) {
        $low[] = game_fake('高手' . $i, 99, $now - 60);
    }
    game_entries_write(array_merge(game_entries(), $low));
    $r = game_submit(game_body(game_tok(30), ['name' => '新手', 'decision' => 'none']), $ipk);
    ok($r['ok'] && $r['score'] === 0 && $r['grade'] === 'F' && $r['rank_week'] === null && $r['rank_all'] === null, 'submit: outside the top 20 → rank null', json_encode($r));

    // ---- settings ----
    ok(settings_get()['game'] === ['board' => true] && game_board_on(), 'game.board defaults to on');
    ok(settings_normalize(['game' => 'x'])['game'] === ['board' => true] && settings_normalize(['game' => ['board' => 0]])['game'] === ['board' => false], 'game.board normalized to a boolean');

    shop_http('game_http_tests');
}

function game_http_tests(): void
{
    exec('rm -rf ' . escapeshellarg(DATA_DIR . '/game') . '/*');
    clear_rl();
    $origin = 'http://127.0.0.1:' . $GLOBALS['PORT'];
    // start
    $r = http('GET', '/api/game.php?a=start');
    $j = $r['json'];
    ok($r['status'] === 200 && ($j['ok'] ?? null) === true && is_string($j['run'] ?? null) && game_weather_ok($j['wx'] ?? null) && array_keys($j['wx']) === ['base', 'rvr', 'dir', 'spd', 'turb'], 'start: {ok, run, wx {base, rvr, dir, spd, turb}}', $r['body']);
    ok(str_contains($r['headers']['cache-control'] ?? '', 'no-store') && str_starts_with($r['headers']['content-type'] ?? '', 'application/json') && ($r['headers']['x-content-type-options'] ?? '') === 'nosniff', 'start: JSON, no-store, nosniff');
    $tr = game_token_read($j['run'], time() + 20);
    ok($tr['ok'] && $tr['run']['wx'] == $j['wx'], 'start: the token carries the weather it answered');
    $r2 = game_post(game_body($j['run']), ['Origin: ' . $origin]);
    ok($r2['status'] === 400 && $r2['json'] === ['ok' => false, 'error' => GAME_MSG['fast']], 'submit right after start: too fast', $r2['body']);
    // method, action
    ok(http('POST', '/api/game.php?a=start')['status'] === 405 && http('GET', '/api/game.php?a=submit')['status'] === 405, 'start is GET only, submit POST only (405)');
    $r = http('GET', '/api/game.php?a=nope');
    ok($r['status'] === 400 && ($r['json']['ok'] ?? null) === false && ($r['json']['error'] ?? '') === '没有这个操作。', 'unknown action: 400 {ok:false, error}');
    ok(http('GET', '/api/game.php?a[]=board')['status'] === 400, 'array action: 400');
    // submit
    $tok = game_tok(25, ['base' => 260]);
    $r = game_post(game_body($tok, ['name' => '云下', 'decision' => 'land', 'rx' => 0, 'ry' => 0, 'dax' => 0, 'day' => 0, 'tdx' => 0, 'score' => 1, 'grade' => 'F']), ['Origin: ' . $origin]);
    ok($r['status'] === 200 && $r['json'] === ['ok' => true, 'score' => 100, 'grade' => 'S', 'rank_week' => 1, 'rank_all' => 1], 'submit: {ok, score, grade, rank_week, rank_all}; a score sent by the browser is ignored', $r['body']);
    ok(str_contains($r['headers']['cache-control'] ?? '', 'no-store'), 'submit: no-store');
    $r = game_post(game_body($tok, ['name' => '云下']));
    ok($r['status'] === 409 && $r['json'] === ['ok' => false, 'error' => GAME_MSG['used']], 'submit: reuse refused (409)', $r['body']);
    $r = game_post(game_body(game_tok(30)), ['Origin: https://evil.example']);
    ok($r['status'] === 403 && ($r['json']['error'] ?? '') === GAME_MSG['origin'], 'submit: other Origin refused (403)');
    $r = game_post(game_body(game_tok(30)), ['Referer: https://evil.example/x']);
    ok($r['status'] === 403, 'submit: other Referer refused (403)');
    $r = http('POST', '/api/game.php?a=submit', ['body' => json_encode(game_body(game_tok(30))), 'headers' => ['Content-Type: text/plain']]);
    ok($r['status'] === 415 && ($r['json']['ok'] ?? null) === false, 'submit: not application/json refused (415)');
    $r = game_post(game_body(game_tok(30), ['pad' => str_repeat('x', 2100)]));
    ok($r['status'] === 413 && ($r['json']['error'] ?? '') === GAME_MSG['large'], 'submit: body over 2 KB refused (413)');
    $r = game_post('{"run":');
    ok($r['status'] === 400 && ($r['json']['error'] ?? '') === GAME_MSG['bad'], 'submit: broken JSON refused (400)');
    $r = game_post(game_body(game_tok(30), ['name' => '加我vx']));
    ok($r['status'] === 400 && ($r['json']['error'] ?? '') === GAME_MSG['name'], 'submit: contact in the name refused with the name message');
    $r = game_post(game_body(game_tok(901)));
    ok($r['status'] === 400 && ($r['json']['error'] ?? '') === GAME_MSG['expired'], 'submit: expired run refused');
    // board
    game_entries_write(array_merge(game_entries(), [game_fake('<b>x</b>', 10, time() - 9 * 86400), game_fake('云下', 50, time() - 5)]));
    $r = http('GET', '/api/game.php?a=board');
    $j = $r['json'];
    ok($r['status'] === 200 && ($j['ok'] ?? null) === true && ($r['headers']['cache-control'] ?? '') === 'public, max-age=30', 'board: ok, Cache-Control public, max-age=30', json_encode($r['headers']));
    ok(array_column($j['week'] ?? [], 'name') === ['云下'] && array_column($j['all'] ?? [], 'name') === ['云下', '<b>x</b>'] && $j['all'][0]['score'] === 100, 'board: week (7 days) and all, deduped by name', $r['body']);
    ok(array_keys($j['all'][0]) === ['name', 'score', 'grade', 'base', 'rvr', 'date'] && preg_match('/^\d{2}-\d{2}$/', $j['all'][0]['date']) && !str_contains($r['body'], 'ipk'), 'board rows: {name, score, grade, base, rvr, date MM-DD}, no id or network hash');
    // rate limits
    clear_rl();
    $codes = [];
    for ($i = 0; $i < GAME_START_HOUR + 1; $i++) {
        $codes[] = http('GET', '/api/game.php?a=start')['status'];
    }
    ok(count(array_filter($codes, fn($c) => $c === 200)) === GAME_START_HOUR && end($codes) === 429, 'start: 60 per hour per visitor, then 429', implode(',', array_count_values(array_map('strval', $codes))));
    $r = http('GET', '/api/game.php?a=start');
    ok(($r['json']['error'] ?? '') === GAME_MSG['busy_start'], 'start: rate limit message');
    clear_rl();
    $codes = [];
    for ($i = 0; $i < GAME_SUBMIT_HOUR + 1; $i++) {
        $codes[] = game_post(game_body('x.y'))['status'];
    }
    ok(count(array_filter($codes, fn($c) => $c === 400)) === GAME_SUBMIT_HOUR && end($codes) === 429, 'submit: 20 per hour per visitor (bad ones count too), then 429', implode(',', $codes));
    clear_rl();
    // off switch
    $s = settings_get();
    $res = settings_save(['game' => ['board' => false]], $s['rev']);
    ok($res['publish'] === null, 'switching the board does not republish the site');
    $r = http('GET', '/api/game.php?a=board');
    ok($r['status'] === 200 && $r['json'] === ['ok' => true, 'off' => true], 'off: board answers {ok:true, off:true}', $r['body']);
    $r = game_post(game_body(game_tok(30)));
    ok(($r['json'] ?? null) === ['ok' => false, 'error' => '排行榜已关闭'], 'off: submit answers {ok:false, error:"排行榜已关闭"}', $r['body']);
    $r = http('GET', '/api/game.php?a=start');
    ok($r['status'] === 200 && ($r['json']['ok'] ?? null) === true, 'off: start still works');
    $s = settings_get();
    settings_save(['game' => ['board' => true]], $s['rev']);

    // admin 小游戏排行榜
    $h = biz_admin_login();
    $r = http('GET', '/admin/?r=game', ['headers' => $h]);
    $b = $r['body'];
    ok($r['status'] === 200 && str_contains($b, '<h1 class="page-h">小游戏排行榜</h1>') && str_contains($b, '>小游戏排行榜</a>'), 'admin page renders and is in the menu', (string) $r['status']);
    ok(str_contains($b, '&lt;b&gt;x&lt;/b&gt;') && !str_contains($b, '<b>x</b>'), 'admin: names escaped with h()');
    ok(str_contains($b, '260 ft / 800 m') && str_contains($b, '>落地</td>') && str_contains($b, '清空排行榜'), 'admin: table shows base / RVR, decision, and the clear button');
    $menu = (string) substr($b, (int) strpos($b, 'menu-list'), 6000);
    ok(strpos($menu, '访问统计') < strpos($menu, '小游戏排行榜') && strpos($menu, '小游戏排行榜') < strpos($menu, 'AI 助手'), 'menu: right after 访问统计');
    ok(!preg_match('/\sstyle=|<script(?![^>]*\bsrc=)/', $b), 'admin CSP kept: no inline style or script');
    $csrf = biz_csrf($b);
    $victim = game_entries()[0];
    $r = http('POST', '/admin/?r=game.delete', ['form' => ['id' => $victim['id']], 'headers' => $h]);
    ok($r['status'] === 403 && count(array_filter(game_entries(), fn($e) => $e['id'] === $victim['id'])) === 1, 'admin: delete without CSRF refused (403)');
    $r = http('POST', '/admin/?r=game.delete', ['form' => ['_csrf' => $csrf, 'id' => $victim['id']], 'headers' => $h]);
    ok($r['status'] === 303 && !array_filter(game_entries(), fn($e) => $e['id'] === $victim['id']), 'admin: delete one entry', (string) $r['status']);
    $rev = (string) settings_get()['rev'];
    $r = http('POST', '/admin/?r=game.toggle', ['form' => ['_csrf' => $csrf, 'rev' => $rev, 'enabled' => '0'], 'headers' => $h]);
    ok($r['status'] === 303 && !game_board_on(), 'admin: switch the board off');
    $r = http('GET', '/admin/?r=game', ['headers' => $h]);
    ok(str_contains($r['body'], '打开排行榜'), 'admin: off state offers 打开排行榜');
    $rev = (string) settings_get()['rev'];
    http('POST', '/admin/?r=game.toggle', ['form' => ['_csrf' => $csrf, 'rev' => $rev, 'enabled' => '1'], 'headers' => $h]);
    ok(game_board_on(), 'admin: switch the board back on');
    $r = http('POST', '/admin/?r=game.clear', ['form' => ['_csrf' => $csrf], 'headers' => array_merge($h, ['Origin: https://evil.example'])]);
    ok($r['status'] === 403 && game_entries() !== [], 'admin: clear from another origin refused');
    $r = http('POST', '/admin/?r=game.clear', ['form' => ['_csrf' => $csrf], 'headers' => $h]);
    ok($r['status'] === 303 && game_entries() === [], 'admin: 清空排行榜 empties it');
    $r = http('GET', '/api/game.php?a=board');
    ok(($r['json'] ?? null) === ['ok' => true, 'week' => [], 'all' => []], 'board empty after clearing');
    $r = http('GET', '/admin/?r=game.clear', ['headers' => $h]);
    ok($r['status'] === 405, 'admin: clear is POST only');
}
