<?php
// run.php group `notify` (微信提醒 via PushPlus: app/lib/notify.php, the hooks in shop.php / supply.php / game.php /
// api/subscribe.php, admin 微信提醒, app/bin/health.php). Loaded by run.php in the child process; uses its helpers (ok,
// throws, http, clear_rl, shop_make, shop_buyer) and biz_admin_login / biz_csrf / game_tok / game_body. Temp dirs only;
// PushPlus is tests/push-mock.php on 127.0.0.1 (BLOG_PUSH_TEST_BASE, honoured only under the CLI and php -S).
declare(strict_types=1);

function push_state(): array
{
    return json_decode((string) file_get_contents($GLOBALS['PUSH_STATE']), true) ?: [];
}

function push_knobs(array $k): void
{
    file_put_contents($GLOBALS['PUSH_STATE'], json_encode(['knobs' => $k, 'requests' => []]));
}

function push_reqs(): array
{
    return push_state()['requests'] ?? [];
}

function push_titles(): array
{
    return array_map(fn($r) => (string) ($r['body']['title'] ?? ''), push_reqs());
}

function notify_reset_log(): void
{
    @unlink(DATA_DIR . '/notify/log.json');
}

/** A self-signed certificate valid for $days days (PEM). */
function notify_cert(int $days): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'hxpilot.test'], $key);
    $x = openssl_csr_sign($csr, null, $key, $days);
    openssl_x509_export($x, $pem);
    return $pem;
}

/** Run app/bin/health.php in a child process. ['code', 'out']. */
function health_run(array $args, array $env = []): array
{
    $p = proc_open(array_merge([PHP_BINARY, APP_DIR . '/bin/health.php'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
        $env + ['BLOG_PUBLIC_DIR' => PUBLIC_DIR, 'BLOG_DATA_DIR' => DATA_DIR, 'BLOG_PUSH_TEST_BASE' => (string) getenv('BLOG_PUSH_TEST_BASE'), 'PATH' => getenv('PATH')]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return ['code' => proc_close($p), 'out' => $out];
}

function group_notify(): void
{
    app_init('cli');
    publish_site('初始内容');
    $tmp = (string) getenv('TEST_TMP');
    $GLOBALS['PUSH_STATE'] = $tmp . '/push-state.json';
    push_knobs([]);
    $mockPort = 30000 + random_int(0, 9999);
    $mock = proc_open([PHP_BINARY, '-S', "127.0.0.1:$mockPort", __DIR__ . '/push-mock.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp, null,
        ['PUSH_STATE' => $GLOBALS['PUSH_STATE'], 'PHP_CLI_SERVER_WORKERS' => '4', 'PATH' => getenv('PATH')]);
    putenv('BLOG_PUSH_TEST_BASE=http://127.0.0.1:' . $mockPort);
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $mockPort) === false; $i++) {
        usleep(100000);
    }
    try {
        notify_lib_tests();
        notify_hook_tests();
        notify_http_group($tmp);
        notify_health_tests($tmp);
    } finally {
        if (is_resource($mock)) {
            proc_terminate($mock);
            proc_close($mock);
        }
    }
}

function notify_lib_tests(): void
{
    // settings: defaults, old data, junk
    ok(settings_get()['notify'] === ['order' => true, 'claim' => true, 'failed' => true, 'sub' => true, 'game' => false], 'switches default: order, claim, failed, sub on; game off');
    $s = json_read(data_path('settings.json'));
    unset($s['notify']);
    json_write_atomic(data_path('settings.json'), $s);
    ok(settings_get()['notify'] === NOTIFY_EVENT_DEFAULTS, 'settings saved before 微信提醒 read as the defaults');
    json_write_atomic(data_path('settings.json'), ['notify' => ['order' => 0, 'evil' => true, 'game' => 'yes']] + $s);
    ok(settings_get()['notify'] === ['order' => false, 'claim' => true, 'failed' => true, 'sub' => true, 'game' => true], 'unknown switch keys dropped, values cast to bool');
    $before = (string) file_get_contents(PUBLIC_DIR . '/index.html');
    $res = settings_save(['notify' => NOTIFY_EVENT_DEFAULTS], settings_get()['rev']);
    ok($res['publish'] === null && (string) file_get_contents(PUBLIC_DIR . '/index.html') === $before, 'switches are not page state: saving them never republishes');

    // token: write-only, validated
    ok(notify_token() === null && !notify_on('order') && !notify_on('health'), 'no token: nothing is on (not even health)');
    foreach (['short', 'has space 1234567890abcdef', str_repeat('a', 65), 'abc-def-0123456789abcd', "0123456789abcdef\x01x", "0123456789abcdef\u{3000}"] as $bad) {
        ok(throws(fn() => notify_token_set($bad), ValidationError::class) && notify_token() === null, 'malformed token refused: ' . json_encode($bad));
    }
    push_knobs([]);
    notify_send('order', '不该发出', 'x');
    ok(push_reqs() === [] && notify_log() === [], 'no token: notify_send sends and logs nothing');
    $tok = '0123456789abcdef0123456789abWXYZ';
    notify_token_set("  $tok\n");
    ok(notify_token() === $tok && notify_token_hint() === 'WXYZ' && notify_token_set_at() !== null, 'token stored trimmed; hint = last 4');
    ok((fileperms(DATA_DIR . '/secrets.json') & 0777) === 0600, 'secrets.json stays 0600');

    // switches respected
    settings_save(['notify' => ['order' => false]], settings_get()['rev']);
    push_knobs([]);
    notify_send('order', '关着的', 'x');
    ok(push_reqs() === [] && notify_log() === [], 'event switched off: nothing sent, nothing logged');
    notify_send('nonsense', '未知事件', 'x');
    ok(push_reqs() === [], 'unknown event: nothing sent');
    settings_save(['notify' => ['order' => true]], settings_get()['rev']);
    notify_send('order', '新订单测试', "第一行\n第二行");
    $r = push_reqs()[0] ?? [];
    ok(count(push_reqs()) === 1 && $r['method'] === 'POST' && $r['path'] === '/send' && str_contains($r['ctype'], 'application/json'), 'switched on: one POST {base}/send with JSON');
    ok(($r['body'] ?? null) === ['token' => $tok, 'title' => '新订单测试', 'content' => "第一行\n第二行", 'template' => 'txt'], 'body {token, title, content, template: txt}', json_encode($r['body'] ?? null, JSON_UNESCAPED_UNICODE));
    $log = notify_log();
    ok(count($log) === 1 && $log[0]['event'] === 'order' && $log[0]['title'] === '新订单测试' && $log[0]['ok'] === true && isset($log[0]['at']), 'log entry: time, event, title, ok');
    ok(!str_contains((string) file_get_contents(DATA_DIR . '/notify/log.json'), $tok) && (fileperms(DATA_DIR . '/notify/log.json') & 0777) === 0600, 'log.json holds no token and is private (0600)');
    ok(notify_on('health') && notify_on('test') && !isset(NOTIFY_EVENTS['health']), 'health and test are always on (not switchable) once a token is set');

    // failures never throw; short reasons in the log
    $cases = [
        [['mode' => 'code', 'code' => 903], 'token 不对'],
        [['mode' => 'code', 'code' => 900], 'PushPlus 限制了这个账号（900）'],
        [['mode' => 'code', 'code' => 999], 'PushPlus 拒绝了（999）：mock refusal 999'],
        [['mode' => 'status', 'status' => 502], 'HTTP 502'],
        [['mode' => 'bad_json'], '看不懂'],
    ];
    foreach ($cases as [$k, $why]) {
        push_knobs($k);
        $thrown = throws(fn() => notify_send('order', '失败测试', 'x'));
        $top = notify_log()[0];
        ok(!$thrown && $top['ok'] === false && str_contains($top['why'], $why), 'PushPlus ' . json_encode($k) . ': no exception, logged as failed: ' . $why, $top['why']);
    }
    push_knobs(['mode' => 'code', 'code' => 903]);
    $r = notify_deliver('test', '测试', 'x');
    ok($r['ok'] === false && str_contains($r['msg'], '没有发出：token 不对'), 'notify_deliver answers the owner in plain Chinese');
    push_knobs(['mode' => 'slow', 'sleep' => 8]);
    $t0 = microtime(true);
    $thrown = throws(fn() => notify_send('order', '慢', 'x'));
    $dt = microtime(true) - $t0;
    ok(!$thrown && $dt < 6.5 && $dt > 4 && str_contains(notify_log()[0]['why'], '连不上 PushPlus'), 'a hanging PushPlus is cut off after 5 s (total timeout), logged as a network failure', round($dt, 2) . ' s');

    // rate limit: 60 per rolling hour, then one 'limited' line counting the rest
    notify_reset_log();
    push_knobs([]);
    $now = time();
    json_write_atomic(DATA_DIR . '/notify/log.json', ['sent' => array_merge(array_fill(0, 5, $now - 4000), array_fill(0, NOTIFY_HOUR_MAX - 1, $now - 60)), 'log' => []]);
    notify_send('order', '第 60 条', 'x');
    ok(count(push_reqs()) === 1, 'sends older than an hour do not count: the 60th in the hour still goes out');
    notify_send('order', '第 61 条', 'x');
    notify_send('claim', '第 62 条', 'x');
    $r = notify_deliver('health', '第 63 条', 'x');
    $log = notify_log();
    ok(count(push_reqs()) === 1 && $r['ok'] === false && str_contains($r['msg'], '一小时内已经发了 60 条'), 'over 60 in an hour: nothing more reaches PushPlus (health included)');
    ok(($log[0]['limited'] ?? false) === true && ($log[0]['n'] ?? 0) === 3 && $log[0]['title'] === '第 61 条' && $log[1]['title'] === '第 60 条', 'limited sends are logged as one line with a count', json_encode(array_slice($log, 0, 2), JSON_UNESCAPED_UNICODE));
    $d = json_read(DATA_DIR . '/notify/log.json');
    ok(count($d['sent']) === NOTIFY_HOUR_MAX, 'only the last hour of send times is kept', (string) count($d['sent']));

    // log trimming
    notify_reset_log();
    for ($i = 1; $i <= 57; $i++) {
        notify_log_add(['at' => now_iso(), 'event' => 'order', 'title' => '条目 ' . $i, 'ok' => true, 'why' => '']);
    }
    $log = notify_log();
    ok(count($log) === NOTIFY_LOG_MAX && $log[0]['title'] === '条目 57' && $log[49]['title'] === '条目 8', 'log keeps the newest 50, newest first');
    notify_reset_log();
    file_put_contents(DATA_DIR . '/notify/log.json', '{broken');
    ok(notify_log() === [] && !throws(fn() => notify_send('order', '坏日志', 'x')), 'a broken log file never breaks a send');
    notify_reset_log();

    // message text
    $o = ['id' => '20261023-ABCDEF', 'name' => 'PC-12 海报', 'qty' => 2, 'amount' => 19837, 'payer' => '尾号 8888 张三',
        'buyer' => ['name' => '王五', 'phone' => '13800138000', 'address' => '北京市朝阳区某路 1 号', 'email' => 'buyer@example.com'],
        'supply' => ['msg' => '余额不足，请联系 supplier@example.com 或 13912345678']];
    foreach (['order', 'claim', 'failed'] as $ev) {
        [$title, $text] = notify_order_message($ev, $o);
        $all = $title . "\n" . $text;
        ok(str_contains($text, '20261023-ABCDEF') && str_contains($text, 'PC-12 海报 × 2') && str_contains($all, '¥198.37') && str_contains($text, '买家：王*')
            && str_contains($text, '/admin/?r=order&id=20261023-ABCDEF'), "$ev message: order id, product, amount, 王*, admin link", $all);
        $leak = array_filter(['13800138000', '8000', '北京市', '朝阳', 'buyer@', 'example.com', '王五', '尾号', '8888', '13912345678'], fn($x) => str_contains($all, $x));
        ok(!$leak, "$ev message: no phone, email, address, full name or payer note", implode(' ', $leak));
    }
    ok(str_starts_with(notify_order_message('claim', $o)[0], '买家说已付款 ¥198.37') && str_contains(notify_order_message('claim', $o)[1], '确认收款'), 'claim message asks the owner to check the payment and 确认收款');
    ok(str_contains(notify_order_message('failed', $o)[1], '原因：余额不足，请联系 [邮箱] 或 [号码]'), 'failed message: supplier reason with contacts scrubbed');
    ok(notify_mask_name('王五') === '王*' && notify_mask_name('A') === 'A*' && notify_mask_name('') === '' && notify_mask_name("\u{202E}李四") === '李*', 'names masked to the first character');
    $site = content_get('site', 'live');
    $site['fields']['site.origin'] = 'https://hxpilot.example';
    json_write_atomic(content_path('site', 'live'), $site);
    ok(notify_admin_url('order', ['id' => '20261023-ABCDEF']) === 'https://hxpilot.example/admin/?r=order&id=20261023-ABCDEF', 'admin link uses the live site address');
    $src = (string) file_get_contents(APP_DIR . '/lib/notify.php');
    ok(NOTIFY_URL === 'https://www.pushplus.plus/send' && str_contains($src, "in_array(PHP_SAPI, ['cli', 'cli-server'], true)") && str_contains($src, '127\.0\.0\.1'), 'the test base override needs the CLI or php -S and a loopback URL');
}

function notify_hook_tests(): void
{
    push_knobs([]);
    notify_reset_log();
    shop_make('n-poster', 'physical', '99', 50);
    $o = shop_create_order('n-poster', shop_buyer('physical', '13800138000', '', 2));
    $titles = push_titles();
    ok(count($titles) === 1 && str_starts_with($titles[0], '新订单 ¥') && str_contains(push_reqs()[0]['body']['content'], $o['id']), 'order created → one 新订单 push with the order id', implode(' | ', $titles));
    push_knobs([]);
    shop_claim($o['id'], '尾号 8888');
    $r = push_reqs();
    ok(count($r) === 1 && str_starts_with($r[0]['body']['title'], '买家说已付款') && !str_contains(json_encode($r[0]['body'], JSON_UNESCAPED_UNICODE), '8888'), 'pending → claimed → one 已付款 push (payer note not in it)');
    push_knobs([]);
    shop_claim($o['id'], '再点一次');
    ok(push_reqs() === [], 'a second 我已付款 on a claimed order sends nothing');
    shop_owner_action($o['id'], 'confirm');
    ok(push_reqs() === [], 'owner actions (确认收款) send nothing');

    // switched off
    settings_save(['notify' => ['order' => false, 'claim' => false]], settings_get()['rev']);
    $o2 = shop_create_order('n-poster', shop_buyer('physical'));
    shop_claim($o2['id'], '');
    ok(push_reqs() === [], 'order and claim switched off: nothing sent');
    settings_save(['notify' => ['order' => true, 'claim' => true]], settings_get()['rev']);

    // a broken PushPlus never breaks the buyer's order or claim
    foreach ([['mode' => 'status', 'status' => 500], ['mode' => 'bad_json'], ['mode' => 'code', 'code' => 903]] as $k) {
        push_knobs($k);
        $x = null;
        $thrown = throws(function () use (&$x) {
            $x = shop_create_order('n-poster', shop_buyer('physical'));
            $x = shop_claim($x['id'], '');
        });
        ok(!$thrown && ($x['status'] ?? '') === 'claimed' && shop_order_get($x['id'])['status'] === 'claimed', 'PushPlus ' . json_encode($k) . ': order created and claimed anyway');
        shop_owner_action($x['id'], 'cancel', ['reason' => 'test']);
    }
    $saved = getenv('BLOG_PUSH_TEST_BASE');
    putenv('BLOG_PUSH_TEST_BASE=http://127.0.0.1:9');   // nothing listens there
    $x = null;
    $thrown = throws(function () use (&$x) {
        $x = shop_create_order('n-poster', shop_buyer('physical'));
    });
    ok(!$thrown && ($x['status'] ?? '') === 'pending' && str_contains(notify_log()[0]['why'], '连不上'), 'PushPlus unreachable: order created anyway, failure logged');
    putenv('BLOG_PUSH_TEST_BASE=' . $saved);
    shop_owner_action($x['id'], 'cancel', ['reason' => 'test']);

    // 进货失败 (货源): only on the move into failed
    push_knobs([]);
    $s = shop_create_order('n-poster', shop_buyer('physical'));
    shop_mutate(function (array &$products, array &$orders) use ($s) {
        foreach ($orders as &$o) {
            if ($o['id'] === $s['id']) {
                $o['type'] = 'supply';
                $o['status'] = 'confirmed';
                $o['held'] = false;
                $o['supply'] = supply_order_normalize(['code' => 'card01', 'cost_seen' => 500]);
            }
        }
        unset($o);
    });
    push_knobs([]);
    $r = supply_fulfill($s['id'], 'confirm');   // 货源 is switched off: blocked → failed
    $req = push_reqs();
    ok($r['ok'] === false && shop_order_get($s['id'])['status'] === 'failed' && count($req) === 1 && $req[0]['body']['title'] === '进货失败：订单 ' . $s['id'], 'supply order → failed: one 进货失败 push', json_encode(push_titles(), JSON_UNESCAPED_UNICODE));
    ok(str_contains($req[0]['body']['content'] ?? '', '原因：货源开关关着') && str_contains($req[0]['body']['content'] ?? '', '/admin/?r=order&id=' . $s['id']), '进货失败 push carries the reason and the admin link');
    push_knobs([]);
    supply_fulfill($s['id'], 'retry');
    ok(shop_order_get($s['id'])['status'] === 'failed' && push_reqs() === [], 'retry that fails again while already failed: no second push');
    settings_save(['notify' => ['failed' => false]], settings_get()['rev']);
    shop_mutate(function (array &$products, array &$orders) use ($s) {
        foreach ($orders as &$o) {
            if ($o['id'] === $s['id']) {
                $o['status'] = 'confirmed';
            }
        }
        unset($o);
    });
    supply_fulfill($s['id'], 'confirm');
    ok(push_reqs() === [], '进货失败 switched off: nothing sent');
    settings_save(['notify' => ['failed' => true]], settings_get()['rev']);

    // 小游戏 周榜第 1: off by default; on → only when a new name takes the top
    exec('rm -rf ' . escapeshellarg(DATA_DIR . '/game') . '/*');
    $ipk = ip_hash('203.0.113.7');
    push_knobs([]);
    game_submit(game_body(game_tok(30), ['name' => '机长']), $ipk);
    ok(push_reqs() === [], 'game switch off by default: a new leader sends nothing');
    settings_save(['notify' => ['game' => true]], settings_get()['rev']);
    exec('rm -rf ' . escapeshellarg(DATA_DIR . '/game') . '/*');
    game_submit(game_body(game_tok(30), ['name' => '机长', 'decision' => 'none']), $ipk);
    ok(push_titles() === ['盲降挑战周榜第 1：机长'], 'game on: the first entry of the week is a new leader', json_encode(push_titles(), JSON_UNESCAPED_UNICODE));
    push_knobs([]);
    game_submit(game_body(game_tok(30), ['name' => '机长']), $ipk);
    ok(push_reqs() === [], 'the same name improving its own top score: no push');
    game_submit(game_body(game_tok(30), ['name' => '路人', 'decision' => 'none']), $ipk);
    ok(push_reqs() === [], 'an entry below the top: no push');
    exec('rm -rf ' . escapeshellarg(DATA_DIR . '/game') . '/*');
    game_submit(game_body(game_tok(30), ['name' => '甲', 'decision' => 'none']), $ipk);
    push_knobs([]);
    $r = game_submit(game_body(game_tok(30), ['name' => '乙']), $ipk);
    ok($r['rank_week'] === 1 && push_titles() === ['盲降挑战周榜第 1：乙'] && str_contains(push_reqs()[0]['body']['content'], '/admin/?r=game'), 'another name takes the top: one push with the admin link');
    settings_save(['notify' => ['game' => false]], settings_get()['rev']);
}

function notify_http_group(string $tmp): void
{
    $port = 20000 + random_int(0, 9999);
    $GLOBALS['PORT'] = $port;
    $srvLog = $tmp . '/php-server.log';
    $env = ['BLOG_PUBLIC_DIR' => PUBLIC_DIR, 'BLOG_DATA_DIR' => DATA_DIR, 'BLOG_PUSH_TEST_BASE' => (string) getenv('BLOG_PUSH_TEST_BASE'), 'PATH' => getenv('PATH')];
    $srv = proc_open([PHP_BINARY, '-d', 'log_errors=1', '-d', 'error_log=' . $srvLog, '-S', "127.0.0.1:$port", '-t', PUBLIC_DIR, APP_DIR . '/dev/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', $srvLog, 'a']], $pipes, null, $env);
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(100000);
    }
    try {
        notify_http_tests($srvLog);
    } finally {
        proc_terminate($srv);
        proc_close($srv);
    }
}

function notify_http_tests(string $srvLog): void
{
    clear_rl();
    notify_reset_log();
    push_knobs([]);
    // buyer: order, 我已付款 (sent from the shutdown function after the answer)
    $r = shop_post('order', ['p' => 'n-poster', 'qty' => '1', 'name' => '赵六', 'phone' => '13700137000', 'address' => '上海市浦东新区 2 号', 'consent' => '1']);
    ok($r['status'] === 303 && preg_match('/a=pay&o=(\d{8}-[A-Z2-9]{6})&k=([0-9a-f]{32})/', $r['headers']['location'] ?? '', $m) === 1, 'HTTP order → 303 to the pay page', (string) $r['status']);
    $id = $m[1] ?? '';
    usleep(300000);
    $req = push_reqs();
    $body = json_encode($req[0]['body'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ok(count($req) === 1 && str_starts_with($req[0]['body']['title'] ?? '', '新订单') && str_contains($body, $id) && str_contains($body, '赵*'), 'HTTP order: one 新订单 push (order id, 赵*)', $body);
    ok(!str_contains($body, '13700137000') && !str_contains($body, '上海') && !str_contains($body, '赵六'), 'HTTP order push: no phone, address or full name');
    ok(str_contains($body, 'http://127.0.0.1:' . $GLOBALS['PORT'] . '/admin/?r=order&id=' . $id) || str_contains($body, 'https://hxpilot.example/admin/?r=order&id=' . $id), 'HTTP order push: absolute admin link', $body);
    push_knobs([]);
    $r = shop_post('paid', ['o' => $id, 'k' => $m[2] ?? '', 'payer' => '尾号 7000']);
    usleep(300000);
    ok($r['status'] === 303 && count(push_reqs()) === 1 && str_starts_with(push_reqs()[0]['body']['title'] ?? '', '买家说已付款'), 'HTTP 我已付款: one push', (string) $r['status']);
    // PushPlus broken: the buyer still gets the normal answers
    push_knobs(['mode' => 'status', 'status' => 500]);
    $r = shop_post('order', ['p' => 'n-poster', 'qty' => '1', 'name' => '钱七', 'phone' => '13700137001', 'address' => '上海市 3 号', 'consent' => '1']);
    ok($r['status'] === 303 && preg_match('/a=pay&o=(\d{8}-[A-Z2-9]{6})&k=([0-9a-f]{32})/', $r['headers']['location'] ?? '', $m2) === 1 && shop_order_get($m2[1]) !== null, 'PushPlus failing (500): HTTP order still 303 and stored');
    $r = shop_post('paid', ['o' => $m2[1] ?? '', 'k' => $m2[2] ?? '', 'payer' => '']);
    ok($r['status'] === 303 && shop_order_get($m2[1] ?? '')['status'] === 'claimed', 'PushPlus failing (500): 我已付款 still recorded');
    // subscribe
    push_knobs([]);
    clear_rl();
    $r = http('POST', '/api/subscribe.php', ['form' => ['email' => 'Reader.Notify@Example.com', 'consent' => '1', 't' => '5000', 'website' => '']]);
    usleep(300000);
    $req = push_reqs();
    ok($r['status'] === 200 && count($req) === 1 && str_starts_with($req[0]['body']['title'] ?? '', '新订阅者') && !str_contains(json_encode($req, JSON_UNESCAPED_UNICODE), 'notify@') && !stripos(json_encode($req), 'reader.notify'), 'new subscriber: one push, without the address', json_encode(push_titles(), JSON_UNESCAPED_UNICODE));
    push_knobs([]);
    $r = http('POST', '/api/subscribe.php', ['form' => ['email' => 'reader.notify@example.com', 'consent' => '1', 't' => '5000', 'website' => '']]);
    usleep(200000);
    ok($r['status'] === 200 && push_reqs() === [], 'the same address again: no push');
    $r = http('POST', '/api/subscribe.php', ['form' => ['email' => 'bot@example.com', 'consent' => '1', 't' => '5000', 'website' => 'http://spam']]);
    usleep(200000);
    ok(push_reqs() === [], 'honeypot (bot) subscribe: no push');

    // admin page
    $tok = notify_token();
    $cookie = biz_admin_login();
    $r = http('GET', '/admin/?r=notify', ['headers' => $cookie]);
    $h = $r['body'];
    $csrf = biz_csrf($h);
    ok($r['status'] === 200 && str_contains($h, '<h1 class="page-h">微信提醒</h1>') && str_contains($h, '已设置</span> 结尾是 <b class="mono">····WXYZ</b>'), 'admin 微信提醒: token shown only as ····WXYZ', (string) $r['status']);
    ok(!str_contains($h, (string) $tok) && !str_contains($h, substr((string) $tok, 0, 12)) && preg_match('/<input id="n-tok" name="token" type="password"[^>]* value="">/', $h) === 1, 'admin page never holds the token; the input is empty');
    ok(str_contains($h, '微信扫码关注') && str_contains($h, '复制你的 token') && str_contains($h, '发一条测试') && str_contains($h, 'href="' . '/admin/?r=notify"'), 'admin page: how-to, test button, menu entry');
    ok(preg_match('/name="ev_order" value="1" checked/', $h) && preg_match('/name="ev_game" value="1">/', $h) && str_contains($h, 'id="n-table"') && str_contains($h, '新订单'), 'admin page: switches and the recent send log');
    $rev = (string) settings_get()['rev'];
    $r = http('POST', '/admin/?r=notify.events', ['form' => ['rev' => $rev, 'ev_order' => '1'], 'headers' => $cookie]);
    ok($r['status'] === 403 && settings_get()['notify']['claim'] === true, 'switches POST without CSRF: 403, nothing changed', (string) $r['status']);
    $r = http('POST', '/admin/?r=notify.events', ['form' => ['_csrf' => $csrf, 'rev' => $rev, 'ev_order' => '1', 'ev_sub' => '1', 'ev_game' => '1'], 'headers' => $cookie]);
    ok(in_array($r['status'], [302, 303], true) && settings_get()['notify'] === ['order' => true, 'claim' => false, 'failed' => false, 'sub' => true, 'game' => true], 'switches saved from the checkboxes (unticked = off)', json_encode(settings_get()['notify']));
    http('POST', '/admin/?r=notify.events', ['form' => ['_csrf' => $csrf, 'rev' => (string) settings_get()['rev'], 'ev_order' => '1', 'ev_claim' => '1', 'ev_failed' => '1', 'ev_sub' => '1'], 'headers' => $cookie]);
    ok(settings_get()['notify'] === NOTIFY_EVENT_DEFAULTS, 'switches back to the defaults');
    $r = http('POST', '/admin/?r=notify.test', ['form' => [], 'headers' => $cookie]);
    ok($r['status'] === 403, '发一条测试 without CSRF: 403');
    push_knobs([]);
    $r = http('POST', '/admin/?r=notify.test', ['form' => ['_csrf' => $csrf], 'headers' => $cookie]);
    $h = http('GET', '/admin/?r=notify', ['headers' => $cookie])['body'];
    ok(count(push_reqs()) === 1 && (push_reqs()[0]['body']['title'] ?? '') === '测试：微信提醒接通了' && str_contains($h, '已发出，看看微信有没有收到。'), '发一条测试: sent right away, result shown');
    push_knobs(['mode' => 'code', 'code' => 903]);
    http('POST', '/admin/?r=notify.test', ['form' => ['_csrf' => $csrf], 'headers' => $cookie]);
    $h = http('GET', '/admin/?r=notify', ['headers' => $cookie])['body'];
    ok(str_contains($h, '没有发出：token 不对或已失效（903）'), '发一条测试 failing: the reason is shown');
    $r = http('POST', '/admin/?r=notify.token', ['form' => ['_csrf' => $csrf, 'token' => 'not a token'], 'headers' => $cookie]);
    ok($r['status'] === 400 && notify_token() === $tok && !str_contains($r['body'], 'not a token') && str_contains($r['body'], '这不像是 PushPlus 的 token'), 'malformed token: 400, not saved, not echoed');
    $new = 'ffffeeeeddddccccbbbbaaaa99998888';
    $r = http('POST', '/admin/?r=notify.token', ['form' => ['token' => $new], 'headers' => $cookie]);
    ok($r['status'] === 403 && notify_token() === $tok, 'token POST without CSRF: 403');
    http('POST', '/admin/?r=notify.token', ['form' => ['_csrf' => $csrf, 'token' => $new], 'headers' => $cookie]);
    $h = http('GET', '/admin/?r=notify', ['headers' => $cookie])['body'];
    ok(notify_token() === $new && str_contains($h, '····8888') && !str_contains($h, $new) && str_contains($h, 'token 结尾是 8888'), 'token replaced; page and flash show only the last 4');
    http('POST', '/admin/?r=notify.token_remove', ['form' => ['_csrf' => $csrf], 'headers' => $cookie]);
    $h = http('GET', '/admin/?r=notify', ['headers' => $cookie])['body'];
    ok(notify_token() === null && !isset(secrets_get()['pushplus_token_set_at']) && str_contains($h, '没有设置') && preg_match('/id="n-test" disabled/', $h) === 1, 'token cleared: nothing sent any more, test button disabled');
    notify_token_set($tok);

    // nothing secret or private in logs or data
    $all = (string) @file_get_contents($srvLog);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(DATA_DIR, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile() && !in_array($f->getFilename(), ['secrets.json', 'orders.json', 'subscribers.json'], true)) {
            $all .= file_get_contents($f->getPathname());
        }
    }
    $hits = array_values(array_filter([$tok, $new, '13700137000', 'reader.notify', '浦东'], fn($x) => str_contains($all, $x)));
    ok(!$hits, 'no log or data file (except secrets/orders/subscribers) holds a token or buyer data', implode(' ', $hits));
}

function notify_health_tests(string $tmp): void
{
    $bk = $tmp . '/backups';
    mkdir($bk);
    file_put_contents($tmp . '/cert-ok.pem', notify_cert(90));
    file_put_contents($tmp . '/cert-soon.pem', notify_cert(10));
    // a Tuesday and a Monday, 00:30 UTC = 08:30 Beijing
    $tue = (new DateTimeImmutable('next tuesday 00:30', new DateTimeZone('UTC')))->getTimestamp();
    $mon = (new DateTimeImmutable('next monday 00:30', new DateTimeZone('UTC')))->getTimestamp();
    $fresh = function (int $now, int $ageH) use ($bk): void {
        exec('rm -f ' . escapeshellarg($bk) . '/*');
        file_put_contents($bk . '/site-x.tar.gz', 'x');
        touch($bk . '/site-x.tar.gz', $now - $ageH * 3600);
        file_put_contents($bk . '/.hidden', 'x');
        touch($bk . '/.hidden', $now);
    };
    $base = fn(int $now, string $cert = 'cert-ok.pem') => ['--backups=' . $bk, '--cert=' . $tmp . '/' . $cert, '--disk=' . $tmp, '--disk-max=100', '--now=' . $now];

    notify_reset_log();
    push_knobs([]);
    $fresh($tue, 3);
    $r = health_run(array_merge($base($tue), ['--dry-run']));
    ok($r['code'] === 0 && str_contains($r['out'], 'no message (all fine, not Monday)') && str_contains($r['out'], '证书还有 ') && str_contains($r['out'], '最新备份 site-x.tar.gz（3 小时前）'), 'health: all fine on a Tuesday → no message', $r['out']);
    $r = health_run($base($tue));
    ok($r['code'] === 0 && push_reqs() === [], 'health (real run): all fine on a Tuesday sends nothing');
    $fresh($mon, 3);
    $r = health_run($base($mon));
    ok($r['code'] === 0 && push_titles() === ['服务器体检：一切正常'] && str_contains(push_reqs()[0]['body']['content'] ?? '', '每周一报一次平安'), 'health: Monday → one short 一切正常', $r['out']);
    ok((notify_log()[0]['event'] ?? '') === 'health' && notify_log()[0]['ok'] === true, 'health push logged as event health');

    // problems, dry run: printed, nothing sent, nothing written
    push_knobs([]);
    notify_reset_log();
    $fresh($tue, 30);
    $r = health_run(array_merge($base($tue, 'cert-soon.pem'), ['--disk-max=0', '--dry-run']));
    ok($r['code'] === 1 && substr_count($r['out'], 'PROBLEM') === 3 && str_contains($r['out'], '最新的备份是 30 小时前的') && str_contains($r['out'], '网站证书 ') && str_contains($r['out'], '天后到期') && str_contains($r['out'], '超过 0%') && str_contains($r['out'], 'would send: 服务器体检：3 个问题'), 'health --dry-run: old backup, cert within 14 days, disk over the limit reported', $r['out']);
    ok(push_reqs() === [] && !is_file(DATA_DIR . '/notify/log.json'), 'health --dry-run sends nothing and writes nothing');
    $r = health_run(array_merge(['--backups=' . $tmp . '/nope', '--cert=' . $tmp . '/nope.pem', '--disk=' . $tmp, '--disk-max=100', '--now=' . $tue, '--dry-run']));
    ok($r['code'] === 1 && str_contains($r['out'], '找不到备份文件夹') && str_contains($r['out'], '读不到网站证书'), 'health: missing backup folder and certificate reported', $r['out']);
    exec('rm -f ' . escapeshellarg($bk) . '/*');
    $r = health_run(array_merge($base($tue), ['--dry-run']));
    ok($r['code'] === 1 && str_contains($r['out'], '里没有备份'), 'health: empty backup folder reported (dotfiles ignored)', $r['out']);
    file_put_contents($tmp . '/cert-old.pem', notify_cert(10));
    $r = health_run(array_merge($base($tue + 20 * 86400, 'cert-old.pem'), ['--dry-run']));
    ok(str_contains($r['out'], '网站证书已经过期'), 'health: expired certificate reported', $r['out']);
    ok(health_run(['--bogus'])['code'] === 64 && health_run(['--domain=../etc'])['code'] === 64, 'health: unknown arguments and a bad domain refused');

    // problems, real run: ONE message even with every switch off
    settings_save(['notify' => array_fill_keys(array_keys(NOTIFY_EVENTS), false)], settings_get()['rev']);
    $fresh($tue, 30);
    $r = health_run(array_merge($base($tue, 'cert-soon.pem'), ['--disk-max=0']));
    $req = push_reqs();
    ok($r['code'] === 1 && count($req) === 1 && ($req[0]['body']['title'] ?? '') === '服务器体检：3 个问题' && str_contains($req[0]['body']['content'] ?? '', '· 最新的备份是 30 小时前的'), 'health: problems → exactly one message, even with every switch off', $r['out']);
    // the data dir not writable
    push_knobs([]);
    $r = health_run(array_merge($base($tue), ['--dry-run']), ['BLOG_DATA_DIR' => $tmp . '/no-such-data']);
    ok(str_contains($r['out'], '网站数据文件夹（blog-data）写不进去'), 'health: a data dir that cannot be written is reported', $r['out']);
    // still rate limited
    $now = time();
    json_write_atomic(DATA_DIR . '/notify/log.json', ['sent' => array_fill(0, NOTIFY_HOUR_MAX, $now - 10), 'log' => []]);
    $r = health_run(array_merge($base($now, 'cert-soon.pem'), ['--disk-max=0']));
    ok($r['code'] === 2 && push_reqs() === [] && str_contains($r['out'], 'not sent: 一小时内已经发了'), 'health: the hourly cap applies to health too (exit 2)', $r['out']);
    notify_reset_log();
    // without a token: nothing sent, exit 2
    $secrets = (string) file_get_contents(DATA_DIR . '/secrets.json');
    notify_token_remove();
    $r = health_run(array_merge($base($tue, 'cert-soon.pem'), ['--disk-max=0']));
    ok($r['code'] === 2 && str_contains($r['out'], 'no PushPlus token') && push_reqs() === [], 'health without a token: not sent, exit 2');
    file_put_contents(DATA_DIR . '/secrets.json', $secrets);
    // PHP error log growth (optional)
    file_put_contents($tmp . '/php.log', str_repeat('x', 1000));
    health_run(array_merge($base($tue), ['--php-log=' . $tmp . '/php.log']));
    file_put_contents($tmp . '/php.log', str_repeat('x', 3 * 1048576));
    $r = health_run(array_merge($base($tue), ['--php-log=' . $tmp . '/php.log', '--dry-run']));
    ok(str_contains($r['out'], 'PHP 错误日志一天里多了 3.0 MB'), 'health --php-log: growth over 1 MB since the last run reported', $r['out']);
    settings_save(['notify' => NOTIFY_EVENT_DEFAULTS], settings_get()['rev']);

    // run as root: switches to the data dir's owner before writing (nothing in blog-data becomes root-owned)
    if (function_exists('posix_geteuid') && posix_geteuid() === 0 && ($nobody = posix_getpwnam('nobody'))) {
        exec('chown -R nobody ' . escapeshellarg(DATA_DIR));
        exec('chmod 755 ' . escapeshellarg($tmp));
        notify_reset_log();
        push_knobs([]);
        $r = health_run(array_merge($base($tue, 'cert-soon.pem'), ['--disk-max=0']));
        $owner = @fileowner(DATA_DIR . '/notify/log.json');
        ok(count(push_reqs()) === 1 && $owner === $nobody['uid'], 'health as root: sent, and log.json belongs to the data dir owner (not root)', $r['out'] . ' owner=' . var_export($owner, true));
        exec('chown -R root ' . escapeshellarg(DATA_DIR));
    } else {
        ok(true, 'health privilege drop not tested (not running as root)');
    }
}
