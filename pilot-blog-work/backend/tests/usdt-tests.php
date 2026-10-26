<?php
// run.php group `usdt` (USDT 收款: app/lib/usdt.php, the pay page choice and poll in api/shop.php, admin 收款设置 →
// USDT 收款 and ?r=usdt, app/bin/usdt-watch.php). Loaded by run.php in the child process; uses its helpers (ok, throws,
// http, clear_rl, shop_make, shop_buyer, shop_post, mock_start for the 货源 mock) and biz_admin_login / biz_csrf.
// TronGrid and the BNB Chain RPC are tests/usdt-mock.php on 127.0.0.1 (BLOG_USDT_TEST_TRON / BLOG_USDT_TEST_BSC,
// honoured only under the CLI and php -S). Temp dirs only.
declare(strict_types=1);

function usdt_state(): array
{
    return json_decode((string) file_get_contents($GLOBALS['USDT_STATE']), true) ?: [];
}

function usdt_state_set(array $patch): void
{
    $s = usdt_state();
    foreach ($patch as $k => $v) {
        $s[$k] = is_array($v) && is_array($s[$k] ?? null) ? array_replace($s[$k], $v) : $v;
    }
    file_put_contents($GLOBALS['USDT_STATE'], json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function usdt_reqs(string $kind): array
{
    return array_values(array_filter(usdt_state()['requests'] ?? [], fn($r) => $kind === 'tron' ? str_starts_with($r['path'], '/v1/') : $r['path'] === '/'));
}

function usdt_rand_tron(): string
{
    return usdt_tron_encode("\x41" . random_bytes(20));
}

function usdt_rand_bsc(): string
{
    return usdt_eip55('0x' . bin2hex(random_bytes(20)));
}

/** Decimal string → 0x hex (any size). */
function usdt_t_dec_hex(string $dec): string
{
    $hex = '';
    while ($dec !== '0' && $dec !== '') {
        $q = '';
        $r = 0;
        foreach (str_split($dec) as $d) {
            $r = $r * 10 + (int) $d;
            $q .= (string) intdiv($r, 16);
            $r %= 16;
        }
        $hex = dechex($r) . $hex;
        $dec = ltrim($q, '0');
    }
    return '0x' . ($hex === '' ? '0' : $hex);
}

/** A TronGrid trc20 item. */
function tron_item(string $to, string $units, int $ts, array $over = []): array
{
    return $over + [
        'transaction_id' => bin2hex(random_bytes(32)),
        'token_info' => ['symbol' => 'USDT', 'address' => USDT_CHAINS['trc20']['contract'], 'decimals' => 6, 'name' => 'Tether USD'],
        'block_timestamp' => $ts * 1000,
        'from' => usdt_rand_tron(),
        'to' => $to,
        'type' => 'Transfer',
        'value' => $units,
    ];
}

/** An eth_getLogs entry for a BEP20 USDT transfer. */
function bsc_log(string $to, string $units, int $block, array $over = []): array
{
    return $over + [
        'address' => strtolower(USDT_CHAINS['bep20']['contract']),
        'topics' => [USDT_TRANSFER_TOPIC, '0x' . str_repeat('0', 24) . substr(strtolower(usdt_rand_bsc()), 2), '0x' . str_repeat('0', 24) . strtolower(substr($to, 2))],
        'data' => '0x' . str_pad(substr(usdt_t_dec_hex($units), 2), 64, '0', STR_PAD_LEFT),
        'blockNumber' => '0x' . dechex($block),
        'transactionHash' => '0x' . bin2hex(random_bytes(32)),
        'logIndex' => '0x' . dechex(random_int(0, 40)),
        'removed' => false,
    ];
}

function usdt_add_tron(array $item): void
{
    $s = usdt_state();
    $s['tron']['items'][] = $item;
    file_put_contents($GLOBALS['USDT_STATE'], json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function usdt_add_bsc(array $log, int $ts): void
{
    $s = usdt_state();
    $s['bsc']['logs'][] = $log;
    $s['bsc']['times'][(string) hexdec($log['blockNumber'])] = $ts;
    file_put_contents($GLOBALS['USDT_STATE'], json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function usdt_order_status(string $id): string
{
    return (string) shop_order_get($id)['status'];
}

function group_usdt(): void
{
    app_init('cli');
    publish_site('初始内容');
    usdt_unit_tests();
    $tmp = (string) getenv('TEST_TMP');
    $GLOBALS['USDT_STATE'] = $tmp . '/usdt-state.json';
    file_put_contents($GLOBALS['USDT_STATE'], json_encode(['tron' => ['items' => []], 'bsc' => ['head' => 50000, 'range_limit' => 1000, 'logs' => [], 'times' => []], 'requests' => []]));
    $port = 30000 + random_int(0, 9999);
    $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/usdt-mock.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp, null,
        ['USDT_STATE' => $GLOBALS['USDT_STATE'], 'PHP_CLI_SERVER_WORKERS' => '4', 'PATH' => getenv('PATH')]);
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(100000);
    }
    $dead = 40000 + random_int(0, 9999);
    putenv('BLOG_USDT_TEST_TRON=http://127.0.0.1:' . $port);
    putenv('BLOG_USDT_TEST_BSC=http://127.0.0.1:' . $dead . ',http://127.0.0.1:' . $port);   // the first RPC is down: the fallback answers
    [$smock, $sport, $GLOBALS['MOCK_STATE']] = mock_start();
    try {
        usdt_flow_tests($sport);
        usdt_http_group($tmp, $port, $dead);
    } finally {
        foreach ([$proc, $smock] as $p) {
            if (is_resource($p)) {
                proc_terminate($p);
                proc_close($p);
            }
        }
    }
}

function usdt_unit_tests(): void
{
    // hashing
    ok(bin2hex(usdt_keccak256('')) === 'c5d2460186f7233c927e7db2dcc703c0e500b653ca82273b7bfad8045d85a470', 'keccak256("") (Ethereum Keccak, not SHA3-256)');
    ok(bin2hex(usdt_keccak256('Transfer(address,address,uint256)')) === substr(USDT_TRANSFER_TOPIC, 2), 'keccak256 of the Transfer signature is the log topic');
    ok(bin2hex(usdt_keccak256(str_repeat('a', 300))) !== bin2hex(usdt_keccak256(str_repeat('a', 299))) && strlen(usdt_keccak256(str_repeat('x', 1000))) === 32, 'keccak256 over several blocks');
    $eip = ['0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed', '0xfB6916095ca1df60bB79Ce92cE3Ea74c37c5d359', '0xdbF03B407c01E7cD3CBea99509d93f8DDDC8C6FB', '0xD1220A0cf47c7B9Be7A2E6BA89F429762e7b9aDb'];
    ok(array_map(fn($a) => usdt_eip55(strtolower($a)), $eip) === $eip, 'EIP-55 checksums match the reference vectors');

    // addresses
    $t = usdt_rand_tron();
    $r = usdt_address_check('trc20', "  $t\n");
    ok($r['ok'] && $r['address'] === $t, 'TRC20: a valid base58check T-address passes (trimmed)');
    ok(usdt_address_check('trc20', 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t')['ok'] === false, 'TRC20: the USDT contract itself is refused as a receiving address');
    $flip = $t;
    $flip[10] = $flip[10] === 'a' ? 'b' : 'a';
    ok(!usdt_address_check('trc20', $flip)['ok'] && str_contains(usdt_address_check('trc20', $flip)['msg'], '校验位'), 'TRC20: one changed character fails the checksum');
    ok(!usdt_address_check('trc20', substr($t, 0, 33))['ok'] && !usdt_address_check('trc20', 'A' . substr($t, 1))['ok'] && !usdt_address_check('trc20', str_replace(substr($t, 5, 1), '0', $t))['ok'], 'TRC20: short, wrong first letter, non-base58 characters refused');
    $other = usdt_tron_encode("\x42" . random_bytes(20));
    ok(!usdt_address_check('trc20', $other)['ok'], 'TRC20: a base58check address with another version byte is refused');
    $b = usdt_rand_bsc();
    ok(usdt_address_check('bep20', strtolower($b))['ok'] && usdt_address_check('bep20', strtolower($b))['address'] === $b, 'BEP20: all lower case passes and is stored EIP-55 checksummed');
    ok(usdt_address_check('bep20', '0x' . strtoupper(substr($b, 2)))['ok'], 'BEP20: all upper case passes (no checksum to check)');
    $bad = $b;
    for ($i = 2; $i < 42; $i++) {
        if (ctype_alpha($bad[$i])) {
            $bad[$i] = ctype_upper($bad[$i]) ? strtolower($bad[$i]) : strtoupper($bad[$i]);
            break;
        }
    }
    ok(!usdt_address_check('bep20', $bad)['ok'] && str_contains(usdt_address_check('bep20', $bad)['msg'], '大小写'), 'BEP20: mixed case with a wrong EIP-55 checksum refused');
    ok(!usdt_address_check('bep20', substr($b, 0, 41))['ok'] && !usdt_address_check('bep20', '0x' . str_repeat('g', 40))['ok'] && !usdt_address_check('bep20', $t)['ok'] && !usdt_address_check('trc20', $b)['ok'], 'wrong length, non-hex, and the other chain\'s address refused');
    ok(!usdt_address_check('bep20', USDT_CHAINS['bep20']['contract'])['ok'], 'BEP20: the USDT contract itself is refused');
    $pk = bin2hex(random_bytes(32));
    $words12 = 'abandon ability able about above absent absorb abstract absurd abuse access accident';
    $secrets = [$pk, '0x' . $pk, strtoupper($pk), '5HueCGU8rMjxEXxiPuD5BDku4MkFqeZyd4dZ1jvhTVqvbTLvyTJ', 'KwDiBf89QgGbjEhKnhXJuH7LrciVrZi3qYjgd9M7rFU73sVHnoWn', $words12,
        $words12 . ' ' . $words12, "abandon\nability\nable\nabout\nabove\nabsent\nabsorb\nabstract\nabsurd\nabuse\naccess\naccident", '的 一 是 在 不 了 有 和 人 这 中 大', '的一是在不了有和人这中大',
        'xprv9s21ZrQH143K3QTDL4LXw2F7HEK3wJUD2nW2nRk4stbPy6cq3jPPqjiChkVvvNKmPGJxWUtg6LnF5kejMRNNU3TGtRBeJgk33yuGBxrMPHi'];
    foreach (['trc20', 'bep20'] as $c) {
        $through = array_filter($secrets, fn($s) => usdt_address_check($c, $s)['ok'] || empty(usdt_address_check($c, $s)['secret']));
        ok($through === [], $c . ': private keys (hex, 0x, WIF, xprv) and 12/24-word phrases (English, Chinese) are refused as secrets', implode(' | ', array_map(fn($s) => substr($s, 0, 12), $through)));
    }
    ok(str_contains((string) usdt_secret_like($pk), '私钥') && str_contains((string) usdt_secret_like($words12), '助记词'), 'the refusal says what it looks like (私钥 / 助记词)');

    // rate and settings
    ok(usdt_parse_rate('7.2') === 72000 && usdt_parse_rate('7.20') === 72000 && usdt_parse_rate('7.2050') === 72050 && usdt_parse_rate('７.２５') === 72500 && usdt_parse_rate('¥7.10') === 71000, 'rate: 7.2, 7.20, 7.2050, full width, ¥ parse');
    ok(usdt_parse_rate('7.20501') === null && usdt_parse_rate('-7') === null && usdt_parse_rate('abc') === null && usdt_parse_rate('720') === null, 'rate: 5 decimals, negative, junk, 720 refused');
    ok(usdt_rate_text(72000) === '7.20' && usdt_rate_text(72050) === '7.205' && usdt_rate_text(71234) === '7.1234', 'rate shown with 2 to 4 decimals');
    ok(settings_get()['usdt'] === usdt_defaults() && usdt_chains_ready() === [], 'defaults: both chains off, no rate, window 30 minutes');
    $before = (string) file_get_contents(PUBLIC_DIR . '/index.html');
    $e = fn(array $u) => array_keys(usdt_validate(usdt_normalize($u)));
    ok($e(['trc20' => ['on' => true, 'address' => ''], 'rate' => 72000]) === ['usdt.trc20'], 'switching a chain on needs its address');
    ok($e(['trc20' => ['on' => true, 'address' => $t], 'rate' => 0]) === ['usdt.rate'], 'switching a chain on needs a rate');
    ok($e(['rate' => 49999]) === ['usdt.rate'] && $e(['rate' => 100001]) === ['usdt.rate'] && $e(['rate' => 50000]) === [] && $e(['rate' => 100000]) === [], 'rate bounds ¥5.00 .. ¥10.00');
    ok($e(['window' => 9]) === ['usdt.window'] && $e(['window' => 121]) === ['usdt.window'] && $e(['window' => 10]) === [] && $e(['window' => 120]) === [], 'window bounds 10 .. 120 minutes');
    ok(throws(fn() => settings_save(['usdt' => ['trc20' => ['on' => true, 'address' => $pk], 'rate' => 72000]], settings_get()['rev']), ValidationError::class)
        && !str_contains((string) file_get_contents(data_path('settings.json')), $pk), 'a private key in the settings is refused and never written');
    $res = settings_save(['usdt' => ['trc20' => ['on' => true, 'address' => $t], 'bep20' => ['on' => true, 'address' => $b], 'rate' => 72000, 'window' => 30]], settings_get()['rev']);
    ok($res['publish'] === null && (string) file_get_contents(PUBLIC_DIR . '/index.html') === $before, 'USDT settings are not page state: saving never republishes');
    ok(usdt_chains_ready() === ['trc20', 'bep20'], 'both chains ready once address and rate are set');

    // TronGrid key: write-only
    ok(throws(fn() => usdt_trongrid_key_set($pk), ValidationError::class) && usdt_trongrid_key() === null, 'TronGrid key: a private key pasted there is refused');
    ok(throws(fn() => usdt_trongrid_key_set('not-a-key'), ValidationError::class), 'TronGrid key: junk refused');
    usdt_trongrid_key_set(' 1A2B3C4D-1234-5678-9ABC-1234567890AB ');
    ok(usdt_trongrid_key() === '1a2b3c4d-1234-5678-9abc-1234567890ab' && usdt_trongrid_key_hint() === '90ab' && !str_contains((string) file_get_contents(data_path('settings.json')), '1234567890ab'), 'TronGrid key stored in secrets.json only; hint = last 4');
    ok((fileperms(data_path('secrets.json')) & 0777) === 0600, 'secrets.json stays 0600');

    // amounts
    ok(usdt_base_amount4(10000, 72000) === 138900 && usdt_base_amount4(7200, 72000) === 100000 && usdt_base_amount4(7201, 72000) === 100100 && usdt_base_amount4(1, 72000) === 100, 'base: ceil(CNY / rate) to 0.01 USDT (¥100 → 13.89, ¥72 → 10.00, ¥72.01 → 10.01, ¥0.01 → 0.01)');
    ok(usdt_units(138957, 6) === '13895700' && usdt_units(138957, 18) === '13895700000000000000' && usdt_amount_text(138957) === '13.8957' && usdt_amount_text(5) === '0.0005', 'base units: 6 decimals (TRC20) and 18 decimals (BEP20) as exact strings');
    ok(usdt_units_text('13895700', 6) === '13.8957' && usdt_units_text('13895700000000000000', 18) === '13.8957' && usdt_units_text('1000000', 6) === '1.00' && usdt_units_text('1', 18) === '0.000000000000000001', 'base units shown back as USDT');
    ok(usdt_hex_to_dec('0x' . str_pad('c0bf3239e0cdb0000', 64, '0', STR_PAD_LEFT)) === '222222000000000000000' && usdt_hex_to_dec('0x0') === '0' && usdt_hex_to_dec('0xzz') === null, 'hex log data → exact decimal (beyond 64-bit)');
    $taken = [];
    $seen = [];
    for ($i = 0; $i < 99; $i++) {
        $a = usdt_pick_amount4(10000, 72000, $taken);
        $taken[$a] = true;
        $seen[] = $a;
    }
    ok(count(array_unique($seen)) === 99 && min($seen) === 138901 && max($seen) === 138999, '99 quotes at one price: every amount different, tails 0.0001 .. 0.0099');
    ok(usdt_pick_amount4(10000, 72000, $taken) === null, 'the 100th open quote at the same price is refused (busy), never a duplicate');
}

/** One quote row for the pure matching tests. */
function q_row(string $order, string $chain, string $addr, int $a4, int $created, int $window = 1800, bool $payable = true): array
{
    return ['order' => $order, 'chain' => $chain, 'address' => $addr, 'amount4' => $a4, 'units' => usdt_units($a4, USDT_CHAINS[$chain]['decimals']), 'created' => $created, 'expires' => $created + $window, 'payable' => $payable, 'rate' => 72000];
}

function usdt_flow_tests(int $sport): void
{
    $now = time();
    $T = settings_get()['usdt']['trc20']['address'];
    $B = settings_get()['usdt']['bep20']['address'];

    // ---- matching, as a pure function ----
    $qs = [q_row('A', 'trc20', $T, 138957, $now - 300), q_row('B', 'bep20', $B, 138957, $now - 300), q_row('C', 'trc20', $T, 70011, $now - 300, 1800, false)];
    $tr = fn(array $o = []) => $o + ['chain' => 'trc20', 'key' => 'trc20:aa', 'tx' => 'aa', 'to' => $T, 'contract' => USDT_CHAINS['trc20']['contract'], 'decimals' => 6, 'units' => '13895700', 'ts' => $now - 60];
    $bs = fn(array $o = []) => $o + ['chain' => 'bep20', 'key' => 'bep20:0xbb:3', 'tx' => '0xbb', 'to' => strtolower($B), 'contract' => strtolower(USDT_CHAINS['bep20']['contract']), 'decimals' => 18, 'units' => '13895700000000000000', 'ts' => $now - 60, 'conf' => 20];
    $m = usdt_match($tr(), $T, $qs, []);
    ok($m['r'] === 'match' && $m['order'] === 'A', 'match: exact amount, chain, address, inside the window');
    ok(usdt_match($tr(['units' => '13895701']), $T, $qs, [])['r'] === 'unmatched' && usdt_match($tr(['units' => '13895701']), $T, $qs, [])['why'] === 'amount', 'one base unit more (overpaid): unmatched, amount');
    ok(usdt_match($tr(['units' => '13895699']), $T, $qs, [])['why'] === 'amount' && usdt_match($tr(['units' => '13890000']), $T, $qs, [])['why'] === 'amount', 'underpaid: unmatched, amount');
    ok(usdt_match($tr(['units' => '013895700']), $T, $qs, [])['r'] === 'match', 'leading zeros in the value do not matter');
    ok(usdt_match($tr(['units' => '1']), $T, $qs, [])['why'] === 'dust' && usdt_match($tr(['units' => '0']), $T, $qs, [])['why'] === 'dust' && usdt_match($tr(['units' => '9999']), $T, $qs, [])['why'] === 'dust'
        && usdt_match($tr(['units' => '10000']), $T, $qs, [])['r'] === 'unmatched', 'address-poisoning dust (under 0.01 USDT, zero included) is skipped silently; 0.01 itself is judged');
    $late = usdt_match($tr(['ts' => $now - 300 + 1800 + 601]), $T, $qs, []);
    ok($late['r'] === 'unmatched' && $late['why'] === 'late' && $late['near'] === 'A', 'late: after expiry + 10 min grace → unmatched, late (the order is named for the owner)');
    ok(usdt_match($tr(['ts' => $now - 300 + 1800 + 600]), $T, $qs, [])['r'] === 'match', 'inside the 10 minute grace after expiry: still a match');
    ok(usdt_match($tr(['ts' => $now - 300 - 121]), $T, $qs, [])['why'] === 'early' && usdt_match($tr(['ts' => $now - 300 - 120]), $T, $qs, [])['r'] === 'match', 'early: more than 2 minutes before the quote → unmatched; 2 minutes before → match');
    ok(usdt_match($tr(['contract' => 'TXLAQ63Xg1NAzckPwKHvzw7CSEmLMEqcdj']), $T, $qs, [])['why'] === 'token', 'another token contract (a fake "USDT"): skipped, never listed');
    ok(usdt_match($tr(['decimals' => 18]), $T, $qs, [])['why'] === 'token', 'wrong decimals for the chain: skipped');
    ok(usdt_match($tr(['to' => usdt_rand_tron()]), $T, $qs, [])['why'] === 'recipient', 'a transfer to another address: skipped');
    ok(usdt_match($tr(), $T, $qs, ['trc20:aa' => true])['why'] === 'dup', 'a transaction already used: skipped (never applied twice)');
    ok(usdt_match($tr(['units' => '7001100']), $T, $qs, [])['why'] === 'closed', 'the amount of a quote whose order cannot be paid any more: unmatched, closed');
    ok(usdt_match($bs(), $B, $qs, [])['order'] === 'B', 'BEP20: the same 4-decimal amount at 18 decimals matches the BEP20 quote, not the TRC20 one');
    ok(usdt_match($tr(['units' => '13895700000000000000']), $T, $qs, [])['why'] === 'amount', 'TRC20 value with 18-decimal scaling is a different amount (6 vs 18 decimals)');
    ok(usdt_match($bs(['units' => '13895700']), $B, $qs, [])['r'] !== 'match', 'BEP20 value with 6-decimal scaling is a different amount (at 18 decimals it is dust)');
    ok(usdt_match($bs(['conf' => 14]), $B, $qs, [])['r'] === 'wait' && usdt_match($bs(['conf' => 15]), $B, $qs, [])['r'] === 'match', 'BEP20: fewer than 15 confirmations → wait; 15 → match');
    ok(usdt_match($bs(['to' => strtoupper($B)]), $B, $qs, [])['r'] === 'match', 'BEP20 addresses compare case-insensitively');
    ok(usdt_match($tr(['to' => strtolower($T)]), $T, $qs, [])['why'] === 'recipient', 'TRON addresses compare exactly (base58 is case-sensitive)');
    ok(usdt_match($tr(['chain' => 'erc20']), $T, $qs, [])['why'] === 'bad' && usdt_match($tr(['units' => '-5']), $T, $qs, [])['why'] === 'bad' && usdt_match($tr(['ts' => null]), $T, $qs, [])['why'] === 'bad', 'malformed transfers skipped');
    $two = [q_row('OLD', 'trc20', $T, 138957, $now - 4000), q_row('NEW', 'trc20', $T, 138957, $now - 100)];
    ok(usdt_match($tr(['ts' => $now - 50]), $T, $two, [])['order'] === 'NEW', 'an amount reused after the old quote ran out: the quote whose window holds the transfer wins');

    // ---- nothing waiting: no network at all ----
    $res = usdt_check_all(true);
    ok(usdt_reqs('tron') === [] && usdt_reqs('bsc') === [] && !$res['trc20']['asked'] && !$res['bep20']['asked'] && !usdt_any_watched(), 'no quote waiting: no chain is asked at all');

    // ---- quotes on real orders ----
    $dg = shop_make('u-guide', 'digital', '100', null);
    $ph = shop_make('u-poster', 'physical', '72', 5);
    $o1 = shop_create_order('u-guide', shop_buyer('digital'));
    $q1 = usdt_current_quote(usdt_choose($o1['id'], 'trc20', $now));
    ok($q1 !== null && $q1['chain'] === 'trc20' && $q1['address'] === $T && $q1['amount4'] > 138900 && $q1['amount4'] < 139000 && $q1['expires'] === $now + 1800 && $q1['rate'] === 72000 && $q1['cny'] === 10000,
        'quote: ¥100 at 7.20 → 13.89 + tail, the owner\'s TRC20 address, 30 minutes, rate snapshot', json_encode($q1));
    ok(usdt_current_quote(usdt_choose($o1['id'], 'trc20', $now + 60)) === $q1, 'choosing the same chain again keeps the same quote (no new tail used)');
    $q1b = usdt_current_quote(usdt_choose($o1['id'], 'bep20', $now + 61));
    ok($q1b['chain'] === 'bep20' && $q1b['address'] === $B && count(usdt_order_quotes(shop_order_get($o1['id']))) === 2, 'switching chain: a BEP20 quote (the TRC20 one stays matchable until it runs out)');
    ok(usdt_current_quote(usdt_choose($o1['id'], 'trc20', $now + 62))['amount4'] === $q1['amount4'] && count(usdt_order_quotes(shop_order_get($o1['id']))) === 2, 'switching back to TRC20 while its quote is valid: the same amount again, no new quote');
    ok(usdt_current_quote(usdt_choose($o1['id'], 'qr', $now + 62)) === null && shop_order_get($o1['id'])['usdt']['method'] === 'qr', 'back to 收款码: no USDT shown, quotes kept');
    $o2 = shop_create_order('u-guide', shop_buyer('digital', '', 'b2@example.com'));
    $q2 = usdt_current_quote(usdt_choose($o2['id'], 'trc20', $now + 63));
    ok($q2['amount4'] !== $q1['amount4'], 'two open orders with the same total get different TRC20 amounts');
    settings_save(['usdt' => ['rate' => 71000]], settings_get()['rev']);
    $q1c = usdt_current_quote(usdt_choose($o1['id'], 'trc20', $now + 1800));
    ok($q1c['rate'] === 71000 && $q1c['created'] === $now + 1800 && intdiv($q1c['amount4'], 100) * 100 === usdt_base_amount4(10000, 71000), 'an expired quote is replaced by a new one at the new rate (new snapshot)');
    settings_save(['usdt' => ['rate' => 72000]], settings_get()['rev']);
    ok(throws(fn() => usdt_choose($o1['id'], 'erc20', $now)) && throws(fn() => usdt_choose('20261026-ZZZZZZ', 'trc20', $now)), 'unknown chain or order refused');
    settings_save(['usdt' => ['bep20' => ['on' => false]]], settings_get()['rev']);
    ok(throws(fn() => usdt_choose($o2['id'], 'bep20', $now), ShopError::class), 'a chain the owner switched off cannot be chosen');
    settings_save(['usdt' => ['bep20' => ['on' => true]]], settings_get()['rev']);
    usdt_choose($o1['id'], 'qr', $now);
    shop_owner_action($o1['id'], 'cancel', ['reason' => '测试']);
    shop_owner_action($o2['id'], 'cancel', ['reason' => '测试']);

    // ---- TRC20 end to end: digital delivery on an automatic match ----
    file_put_contents($GLOBALS['USDT_STATE'], json_encode(['tron' => ['items' => []], 'bsc' => ['head' => 50000, 'range_limit' => 1000, 'logs' => [], 'times' => [], 'head_time' => $now], 'requests' => []]));
    $o = shop_create_order('u-guide', shop_buyer('digital', '', 'reader@example.com'));
    $q = usdt_current_quote(usdt_choose($o['id'], 'trc20'));
    usdt_add_tron(tron_item($T, usdt_units($q['amount4'] + 1, 6), time() - 20));                                   // 0.0001 too much
    usdt_add_tron(tron_item($T, $q['units'] ?? usdt_units($q['amount4'], 6), time() - 10, ['token_info' => ['address' => 'TXLAQ63Xg1NAzckPwKHvzw7CSEmLMEqcdj', 'decimals' => 6]]));   // fake token
    usdt_add_tron(tron_item($T, usdt_units($q['amount4'], 6), time() - 86400 * 3));                              // the owner's own old history
    $good = tron_item($T, usdt_units($q['amount4'], 6), time() - 5);
    usdt_add_tron($good);
    $r = usdt_check_chain('trc20', true);
    $after = shop_order_get($o['id']);
    ok($r['asked'] && $r['ok'] && count($r['paid']) === 1 && $r['odd'] === 1, 'one check: one match, one unmatched (wrong amount); fake token and old history ignored', json_encode($r));
    ok($after['status'] === 'completed' && usdt_paid($after)['tx'] === $good['transaction_id'] && usdt_paid($after)['how'] === 'auto' && usdt_paid($after)['units'] === usdt_units($q['amount4'], 6), 'digital order: paid in USDT → confirmed and completed at once, tx stored');
    $h = $after['history'];
    ok($h[count($h) - 2]['s'] === 'confirmed' && $h[count($h) - 2]['by'] === 'usdt' && str_contains($h[count($h) - 2]['note'], 'TRC20 ' . $good['transaction_id']), 'history: confirmed by usdt with chain + tx hash (the same transition as 确认收款)');
    $look = shop_lookup_result_html($after, shop_product_get($after['product_id']), null);
    ok(str_contains($look, '提取码 1234') && str_contains($look, 'USDT 实付') && !str_contains($look, 'tronscan'), 'lookup shows the digital content and the USDT paid, no explorer link for the buyer');
    $tronReq = usdt_reqs('tron')[0] ?? [];
    ok(($tronReq['query']['only_confirmed'] ?? '') === 'true' && ($tronReq['query']['only_to'] ?? '') === 'true' && ($tronReq['query']['limit'] ?? '') === '50'
        && ($tronReq['query']['contract_address'] ?? '') === USDT_CHAINS['trc20']['contract'] && (int) ($tronReq['query']['min_timestamp'] ?? 0) > 0 && $tronReq['api_key'] === '1a2b3c4d-1234-5678-9abc-1234567890ab',
        'TronGrid query: only_confirmed, only_to, limit 50, contract, min_timestamp, TRON-PRO-API-KEY header', json_encode($tronReq));
    ok(!str_contains(json_encode(usdt_state()['requests']), 'reader@example.com') && !str_contains(json_encode(usdt_state()['requests']), $o['id']), 'nothing about the buyer or the order is sent to the chain API');
    $n = count(usdt_reqs('tron'));
    usdt_check_chain('trc20');
    ok(count(usdt_reqs('tron')) === $n, 'a second check within 15 seconds does not ask TronGrid again (cache)');
    $odd = usdt_unmatched_open();
    ok(count($odd) === 1 && $odd[0]['why'] === 'amount' && $odd[0]['units'] === usdt_units($q['amount4'] + 1, 6), 'the overpayment is listed for the owner (unmatched, amount)');
    // a second order gets the same tail later? the used registry stops the same tx from paying it
    $o3 = shop_create_order('u-guide', shop_buyer('digital', '', 'c3@example.com'));
    $q3 = usdt_current_quote(usdt_choose($o3['id'], 'trc20'));
    usdt_add_tron(['transaction_id' => $good['transaction_id']] + tron_item($T, usdt_units($q3['amount4'], 6), time() - 1));
    $r = usdt_check_chain('trc20', true);
    ok($r['paid'] === [] && usdt_order_status($o3['id']) === 'pending', 'the same tx hash again (even with another amount): never applied twice');
    ok(isset(usdt_used()['trc20:' . $good['transaction_id']]) && (fileperms(DATA_DIR . '/shop/usdt/used.json') & 0777) === 0600, 'used-tx registry under blog-data (0600)');

    // ---- late, early: listed, never confirmed ----
    $o4 = shop_create_order('u-poster', shop_buyer('physical'));
    $t0 = time();
    $q4 = usdt_current_quote(usdt_choose($o4['id'], 'trc20', $t0 - 3000));   // quote 50 minutes ago, ran out 20 minutes ago
    usdt_add_tron(tron_item($T, usdt_units($q4['amount4'], 6), $t0 - 3000 + 1800 + 900));
    $r = usdt_check_chain('trc20', true);
    $lateItem = usdt_unmatched_open()[0];
    ok($r['paid'] === [] && usdt_order_status($o4['id']) === 'pending' && $lateItem['why'] === 'late' && $lateItem['near'] === $o4['id'], 'paid 15 minutes after the quote ran out: not confirmed, listed as late with the order');

    // ---- TRON down: the check fails quietly ----
    usdt_state_set(['tron' => ['down' => true]]);
    $o5 = shop_create_order('u-poster', shop_buyer('physical', '13900139000'));
    usdt_choose($o5['id'], 'trc20');
    $r = usdt_check_chain('trc20', true);
    ok(!$r['ok'] && str_contains($r['err'], 'TronGrid') && usdt_order_status($o5['id']) === 'pending' && isset(usdt_scan_state()['trc20']['err_at']), 'TronGrid down: no exception, order unchanged, failure noted for the admin page');
    usdt_state_set(['tron' => ['down' => false, 'status' => 429]]);
    ok(!usdt_check_chain('trc20', true)['ok'], 'TronGrid 429 (rate limited): a failure, nothing changes');
    usdt_state_set(['tron' => ['status' => 0]]);

    // ---- BEP20: confirmations, chunked eth_getLogs, next block remembered, RPC fallback ----
    $o6 = shop_create_order('u-poster', shop_buyer('physical', '13700137000'));
    $q6 = usdt_current_quote(usdt_choose($o6['id'], 'bep20', time() - 900));   // 15 minutes ago: ~1800 blocks back at 0.5 s
    $head = 50000;
    usdt_state_set(['bsc' => ['head' => $head, 'head_time' => time()]]);
    $log = bsc_log($B, usdt_units($q6['amount4'], 18), $head - 5);
    usdt_add_bsc($log, time() - 4);
    $r = usdt_check_chain('bep20', true);
    $getLogs = array_values(array_filter(usdt_reqs('bsc'), fn($x) => $x['rpc'] === 'eth_getLogs'));
    $ranges = array_map(fn($x) => [hexdec($x['params'][0]['fromBlock']), hexdec($x['params'][0]['toBlock'])], $getLogs);
    ok($r['ok'] && $r['paid'] === [] && usdt_order_status($o6['id']) === 'pending', 'BEP20 transfer with 5 confirmations: not confirmed yet', json_encode($r));
    ok(count($ranges) >= 2 && max(array_map(fn($x) => $x[1] - $x[0] + 1, $ranges)) <= 1000 && end($ranges)[1] === $head - 15, 'eth_getLogs paged in chunks of ≤ 1000 blocks, up to head − 15', json_encode($ranges));
    $g = $getLogs[0]['params'][0];
    ok(strtolower($g['address']) === strtolower(USDT_CHAINS['bep20']['contract']) && $g['topics'][0] === USDT_TRANSFER_TOPIC && $g['topics'][1] === null && $g['topics'][2] === '0x' . str_repeat('0', 24) . strtolower(substr($B, 2)), 'eth_getLogs filter: USDT contract, Transfer topic, `to` = our address');
    ok((usdt_scan_state()['bep20']['next'][strtolower($B)] ?? 0) === $head - 14, 'the next block to scan is remembered');
    usdt_state_set(['bsc' => ['head' => $head + 20]]);
    $s = usdt_state();
    $s['requests'] = [];
    file_put_contents($GLOBALS['USDT_STATE'], json_encode($s));
    $r = usdt_check_chain('bep20', true);
    $getLogs = array_values(array_filter(usdt_reqs('bsc'), fn($x) => $x['rpc'] === 'eth_getLogs'));
    $after = shop_order_get($o6['id']);
    ok($r['ok'] && count($r['paid']) === 1 && $after['status'] === 'confirmed' && usdt_paid($after)['chain'] === 'bep20' && usdt_paid($after)['tx'] === $log['transactionHash'], 'BEP20 transfer with 25 confirmations: physical order confirmed (waits for shipping)', json_encode($r));
    ok(count($getLogs) === 1 && hexdec($getLogs[0]['params'][0]['fromBlock']) === $head - 14, 'the next check starts at the remembered block (no rescan)');
    ok(usdt_paid($after)['units'] === usdt_units($q6['amount4'], 18), 'BEP20 amount compared at 18 decimals');
    usdt_state_set(['bsc' => ['range_limit' => 10, 'head' => $head + 140]]);
    $o7 = shop_create_order('u-poster', shop_buyer('physical', '13600136000'));
    usdt_choose($o7['id'], 'bep20', time() - 60);
    $r = usdt_check_chain('bep20', true);
    ok(!$r['ok'] && str_contains($r['err'], 'BSC RPC') && usdt_order_status($o7['id']) === 'pending', 'an RPC refusing the range (both endpoints): a logged failure, nothing changes');
    usdt_state_set(['bsc' => ['range_limit' => 1000, 'down' => true]]);
    ok(!usdt_check_chain('bep20', true)['ok'], 'BSC RPC down: a failure, no exception');
    usdt_state_set(['bsc' => ['down' => false]]);
    shop_owner_action($o7['id'], 'cancel', ['reason' => '测试']);

    // ---- 货源: a USDT payment buys the card ----
    settings_save(['supply' => ['base_url' => 'http://127.0.0.1:' . $sport, 'app_id' => MOCK_APP_ID, 'enabled' => true]], settings_get()['rev']);
    supply_key_set(MOCK_APP_KEY);
    $sp = supply_import('WZRY60', ['name' => '王者荣耀 60 点券', 'price' => '6.80', 'body' => '', 'on_sale' => '1']);
    [$c] = shop_order_input($sp, ['qty' => '1', 'email' => 'gamer@example.com', 'consent' => '1']);
    $o8 = shop_create_order($sp['slug'], $c);
    $q8 = usdt_current_quote(usdt_choose($o8['id'], 'trc20'));
    ok($q8['cny'] === 680 && intdiv($q8['amount4'], 100) === 95, '¥6.80 at 7.20 → 0.95 USDT + tail');
    usdt_add_tron(tron_item($T, usdt_units($q8['amount4'], 6), time()));
    $r = usdt_check_chain('trc20', true);
    $after = shop_order_get($o8['id']);
    ok(count($r['paid']) === 1 && $after['status'] === 'completed' && ($after['supply']['trade_no'] ?? '') !== '' && str_contains((string) $after['supply']['secret'], 'CARD-'), '货源 order paid in USDT: confirmed, the card bought from the supplier, completed', json_encode([$after['status'], $after['supply'] ?? null], JSON_UNESCAPED_UNICODE));

    // ---- the owner attaches an unmatched transfer by hand, or dismisses it ----
    $item = null;
    foreach (usdt_unmatched_open() as $x) {
        if ($x['why'] === 'late') {
            $item = $x;
        }
    }
    $a = usdt_attach($item['id'], $o4['id']);
    $hh = end($a['history']);
    ok($a['status'] === 'confirmed' && usdt_paid($a)['how'] === 'manual' && $hh['by'] === 'owner' && str_contains($hh['note'], 'USDT 手动对账 TRC20'), 'manual attach: the order is confirmed by the owner, the transfer recorded on it');
    ok(usdt_unmatched_get($item['id'])['state'] === 'attached' && usdt_unmatched_get($item['id'])['order'] === $o4['id'], 'the item is marked attached to that order');
    ok(throws(fn() => usdt_attach($item['id'], $o5['id']), ShopError::class), 'the same transfer cannot be attached twice');
    $other = usdt_unmatched_open()[0];
    ok(throws(fn() => usdt_attach($other['id'], $o4['id']), ShopError::class), 'an order already paid in USDT refuses a second transfer');
    usdt_dismiss($other['id']);
    ok(usdt_unmatched_get($other['id'])['state'] === 'dismissed' && usdt_unmatched_open() === [], 'dismiss: moves to handled, no order touched');

    // ---- an unpaid order with a quote is held open until expiry + grace ----
    $o9 = shop_create_order('u-poster', shop_buyer('physical', '13500135000'));
    $q9 = usdt_current_quote(usdt_choose($o9['id'], 'trc20'));
    $exp = strtotime($o9['expires_at']);
    $orders = json_read(shop_orders_path());
    foreach ($orders['items'] as &$x) {
        if ($x['id'] === $o9['id']) {
            $x['expires_at'] = gmdate('Y-m-d\TH:i:s\Z', time() - 10);   // the CNY deadline passed while the buyer is paying in USDT
        }
    }
    unset($x);
    json_write_atomic(shop_orders_path(), $orders);
    shop_maintain();
    ok(usdt_order_status($o9['id']) === 'pending', 'auto-cancel waits while a USDT quote (plus 10 min grace) is open');
    shop_maintain($q9['expires'] + USDT_GRACE + 1);
    ok(usdt_order_status($o9['id']) === 'cancelled', 'after the quote and grace ran out the unpaid order is cancelled as usual');

    // ---- the cron script ----
    $run = function (array $args = []) {
        $p = proc_open(array_merge([PHP_BINARY, APP_DIR . '/bin/usdt-watch.php'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
            ['BLOG_PUBLIC_DIR' => PUBLIC_DIR, 'BLOG_DATA_DIR' => DATA_DIR, 'BLOG_USDT_TEST_TRON' => (string) getenv('BLOG_USDT_TEST_TRON'), 'BLOG_USDT_TEST_BSC' => (string) getenv('BLOG_USDT_TEST_BSC'), 'BLOG_SUPPLY_TEST_LOOPBACK' => '1', 'PATH' => getenv('PATH')]);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out];
    };
    ok($run(['--bogus'])['code'] === 64, 'usdt-watch: unknown argument → exit 64');
    $o10 = shop_create_order('u-guide', shop_buyer('digital', '', 'cron@example.com'));
    $q10 = usdt_current_quote(usdt_choose($o10['id'], 'trc20'));
    usdt_add_tron(tron_item($T, usdt_units($q10['amount4'], 6), time()));
    $c1 = $run(['--force']);
    ok($c1['code'] === 0 && str_contains($c1['out'], 'TRC20: 1 paid') && usdt_order_status($o10['id']) === 'completed', 'usdt-watch --force: finds the payment and completes the order', $c1['out']);
    foreach (shop_orders() as $x) {
        if (in_array($x['status'], ['pending', 'claimed'], true)) {
            shop_owner_action($x['id'], 'cancel', ['reason' => '测试']);
        }
    }
    $s = usdt_state();
    $s['requests'] = [];
    file_put_contents($GLOBALS['USDT_STATE'], json_encode($s));
    $t1 = microtime(true);
    $c2 = $run(['--quiet']);
    ok($c2['code'] === 0 && trim($c2['out']) === '' && (usdt_any_watched() || usdt_reqs('tron') === []) && microtime(true) - $t1 < 3, 'usdt-watch with nothing new to report: quiet, quick', $c2['out']);
}

// ---------- HTTP: the pay page, the poll, the admin pages (php -S with the dev router) ----------

function usdt_http_group(string $tmp, int $mport, int $dead): void
{
    $port = 20000 + random_int(0, 9999);
    $GLOBALS['PORT'] = $port;
    $env = ['BLOG_PUBLIC_DIR' => PUBLIC_DIR, 'BLOG_DATA_DIR' => DATA_DIR, 'BLOG_USDT_TEST_TRON' => 'http://127.0.0.1:' . $mport,
        'BLOG_USDT_TEST_BSC' => 'http://127.0.0.1:' . $mport, 'BLOG_SUPPLY_TEST_LOOPBACK' => '1', 'PATH' => getenv('PATH')];
    $srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', PUBLIC_DIR, APP_DIR . '/dev/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', $tmp . '/srv.log', 'a']], $pipes, null, $env);
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(100000);
    }
    try {
        usdt_http_tests();
    } finally {
        proc_terminate($srv);
        proc_close($srv);
    }
}

function usdt_http_tests(): void
{
    clear_rl();
    $T = settings_get()['usdt']['trc20']['address'];
    $r = shop_post('order', ['p' => 'u-guide', 'qty' => '1', 'email' => 'web@example.com', 'consent' => '1']);
    preg_match('/a=pay&o=(\d{8}-[A-Z2-9]{6})&k=([0-9a-f]{32})/', $r['headers']['location'] ?? '', $m);
    [$id, $k] = [$m[1] ?? '', $m[2] ?? ''];
    $pay = http('GET', '/api/shop.php?a=pay&o=' . $id . '&k=' . $k);
    ok($pay['status'] === 200 && str_contains($pay['body'], 'id="paym"') && str_contains($pay['body'], 'value="trc20"') && str_contains($pay['body'], 'value="bep20"'), 'pay page: 付款方式 with USDT · TRC20 and BEP20 next to the 收款码');
    ok(str_contains($pay['headers']['content-security-policy'] ?? '', "script-src 'none'") && !preg_match('/<script/i', $pay['body']), 'before choosing USDT the pay page has no script (CSP script-src none)');
    // another buyer's quote must never show on this page
    $other = shop_create_order('u-guide', shop_buyer('digital', '', 'other@example.com'));
    $oq = usdt_current_quote(usdt_choose($other['id'], 'trc20'));
    $r = shop_post('usdt', ['o' => $id, 'k' => $k, 'c' => 'trc20']);
    ok($r['status'] === 303 && str_contains($r['headers']['location'] ?? '', 'a=pay&o=' . $id) && str_contains($r['headers']['location'] ?? '', '#usdt'), 'choosing TRC20: 303 back to the pay page', (string) $r['status']);
    $q = usdt_current_quote(shop_order_get($id));
    $pay = http('GET', '/api/shop.php?a=pay&o=' . $id . '&k=' . $k);
    $b = $pay['body'];
    ok(str_contains($b, '>' . usdt_amount_text($q['amount4']) . '<') && str_contains($b, $T) && str_contains($b, 'USDT · TRC20') && str_contains($b, 'data-copy="' . $T . '"') && str_contains($b, 'class="usdt-qr"'), 'USDT panel: exact amount, address, chain name, copy buttons, QR canvas');
    ok(str_contains($b, 'data-exp="' . $q['expires'] . '"') && str_contains($b, '只能用 TRC20 网络转 USDT'), 'countdown data and the plain warning about the chain and amount');
    ok(str_contains($pay['headers']['content-security-policy'] ?? '', "script-src 'self'") && !str_contains($pay['headers']['content-security-policy'] ?? '', 'unsafe') && preg_match_all('/<script src="\.\.\/assets\/shop\/(qrcode\.min|usdt)\.js\?v=[0-9a-f]{8}" defer><\/script>/', $b) === 2 && !preg_match('/<script>/', $b),
        'with a quote: only the two same-origin scripts, CSP script-src self (no inline script)');
    ok(!str_contains($b, usdt_amount_text($oq['amount4'])) && !str_contains($b, $other['id']), 'the page never shows another order\'s amount or number');
    ok(!str_contains($b, 'tronscan') && !str_contains($b, 'bscscan'), 'no explorer links on the buyer\'s page');
    ok(http('GET', '/assets/shop/usdt.js')['status'] === 200 && http('GET', '/assets/shop/qrcode.min.js')['status'] === 200, 'the two scripts are served');
    $bad = shop_post('usdt', ['o' => $id, 'k' => str_repeat('0', 32), 'c' => 'trc20']);
    ok($bad['status'] === 404, 'choosing without the pay token: 404');
    ok(shop_post('usdt', ['o' => $id, 'k' => $k, 'c' => 'erc20'])['status'] === 400 && http('POST', '/api/shop.php?a=usdt', ['form' => ['o' => $id, 'k' => $k, 'c' => 'trc20', 'tok' => 'x', 'website' => '']])['status'] === 400, 'unknown chain or a bad form token: 400');
    ok(http('POST', '/api/shop.php?a=usdt', ['form' => ['o' => $id, 'k' => $k, 'c' => 'trc20', 'tok' => shop_form_token('usdt', time() - 5), 'website' => ''], 'headers' => ['Origin: https://evil.example']])['status'] === 403, 'choosing from another site: 403');

    // the poll
    $poll = fn(string $kk = '') => http('GET', '/api/shop.php?a=usdt&o=' . $id . '&k=' . ($kk !== '' ? $kk : $k));
    $p = $poll();
    ok($p['status'] === 200 && ($p['json']['state'] ?? '') === 'waiting' && ($p['json']['left'] ?? 0) > 1700 && str_contains($p['headers']['content-type'] ?? '', 'application/json') && str_contains($p['headers']['cache-control'] ?? '', 'no-store'), 'poll: JSON waiting, seconds left, no-store', $p['body']);
    ok(!str_contains($p['body'], $T) && !str_contains($p['body'], 'amount'), 'poll answer carries no address or amount');
    ok($poll(str_repeat('a', 32))['status'] === 404 && http('GET', '/api/shop.php?a=usdt&o=nope&k=' . $k)['status'] === 404, 'poll with a wrong token or order: 404');
    usdt_state_set(['tron' => ['down' => true]]);
    sleep(1);
    $json = json_decode((string) file_get_contents(DATA_DIR . '/shop/usdt/scan.json'), true);
    $json['trc20']['at'] = 0;
    file_put_contents(DATA_DIR . '/shop/usdt/scan.json', json_encode($json));
    $p = $poll();
    $pg = http('GET', '/api/shop.php?a=pay&o=' . $id . '&k=' . $k);
    ok($p['status'] === 200 && ($p['json']['state'] ?? '') === 'waiting' && $pg['status'] === 200 && str_contains($pg['body'], 'USDT · TRC20'), 'TronGrid down: the poll still answers waiting and the pay page still works', $p['body']);
    usdt_state_set(['tron' => ['down' => false]]);
    usdt_add_tron(tron_item($T, usdt_units($q['amount4'], 6), time()));
    $json = json_decode((string) file_get_contents(DATA_DIR . '/shop/usdt/scan.json'), true);
    $json['trc20']['at'] = 0;
    file_put_contents(DATA_DIR . '/shop/usdt/scan.json', json_encode($json));
    $p = $poll();
    ok(($p['json']['state'] ?? '') === 'paid' && usdt_order_status($id) === 'completed', 'poll after the transfer: paid (the server found it on chain)', $p['body']);
    $pg = http('GET', '/api/shop.php?a=pay&o=' . $id . '&k=' . $k);
    ok(str_contains($pg['body'], '已自动确认收款') && !preg_match('/<script/i', $pg['body']) && str_contains($pg['headers']['content-security-policy'] ?? '', "script-src 'none'"), 'paid page: 已收到 … 已自动确认收款, no script any more');
    clear_rl();
    $codes = [];
    for ($i = 0; $i < 125; $i++) {
        $codes[] = $poll()['status'];
    }
    ok(in_array(429, $codes, true) && $codes[0] === 200, 'polls are rate limited per visitor (429 after ~120 in 10 minutes)');
    clear_rl();

    // admin
    $cookie = biz_admin_login();
    $pg = http('GET', '/admin/?r=pay', ['headers' => $cookie]);
    $csrf = biz_csrf($pg['body']);
    ok($pg['status'] === 200 && str_contains($pg['body'], 'USDT 收款') && str_contains($pg['body'], '绝对不要') && str_contains($pg['body'], 'value="' . $T . '"') && str_contains($pg['body'], '····90ab') && !str_contains($pg['body'], '1234567890ab'),
        'admin 收款设置: USDT section with the warning, the address, the key hint only');
    $pk = bin2hex(random_bytes(32));
    $rev = (string) settings_get()['rev'];
    $r = http('POST', '/admin/?r=pay.usdt', ['form' => ['_csrf' => $csrf, 'rev' => $rev, 'trc20_on' => '1', 'trc20' => $pk, 'bep20' => '', 'rate' => '7.20', 'window' => '30'], 'headers' => $cookie]);
    ok($r['status'] === 400 && str_contains($r['body'], '私钥') && !str_contains($r['body'], $pk) && !str_contains((string) file_get_contents(data_path('settings.json')), $pk), 'a private key pasted as the address: refused, never echoed back, never stored');
    $words = 'legal winner thank year wave sausage worth useful legal winner thank yellow';
    $r = http('POST', '/admin/?r=pay.usdt', ['form' => ['_csrf' => $csrf, 'rev' => $rev, 'bep20_on' => '1', 'bep20' => $words, 'trc20' => $T, 'trc20_on' => '1', 'rate' => '7.20', 'window' => '30'], 'headers' => $cookie]);
    ok($r['status'] === 400 && str_contains($r['body'], '助记词') && !str_contains($r['body'], 'sausage'), 'a recovery phrase: refused and not echoed');
    ok(http('POST', '/admin/?r=pay.usdt', ['form' => ['rev' => $rev, 'trc20' => $T, 'rate' => '7.3', 'window' => '30'], 'headers' => $cookie])['status'] === 403, 'USDT settings POST without CSRF: 403');
    $r = http('POST', '/admin/?r=pay.usdt', ['form' => ['_csrf' => $csrf, 'rev' => $rev, 'trc20_on' => '1', 'trc20' => $T, 'bep20_on' => '1', 'bep20' => strtolower(settings_get()['usdt']['bep20']['address']), 'rate' => '7.25', 'window' => '45'], 'headers' => $cookie]);
    $u = settings_get()['usdt'];
    ok(in_array($r['status'], [302, 303], true) && $u['rate'] === 72500 && $u['window'] === 45 && $u['bep20']['address'] === usdt_eip55($u['bep20']['address']), 'valid settings saved (rate 7.25, 45 minutes, BEP20 stored checksummed)');
    // an unmatched transfer → the owner's list → attach with CSRF
    $o = shop_create_order('u-poster', shop_buyer('physical', '13300133000'));
    $oq = usdt_current_quote(usdt_choose($o['id'], 'trc20'));
    usdt_add_tron(tron_item($T, usdt_units($oq['amount4'] - 50, 6), time()));
    usdt_check_chain('trc20', true);
    $odd = usdt_unmatched_open()[0] ?? null;
    $pg = http('GET', '/admin/?r=usdt', ['headers' => $cookie]);
    ok($odd !== null && $pg['status'] === 200 && str_contains($pg['body'], usdt_units_text($odd['units'], 6) . ' USDT') && str_contains($pg['body'], 'https://tronscan.org/#/transaction/' . $odd['tx']) && str_contains($pg['body'], 'data-confirm='), 'admin USDT 到账: the unmatched transfer with a Tronscan link and a confirm sheet');
    $ol = http('GET', '/admin/?r=orders', ['headers' => $cookie]);
    ok(str_contains($ol['body'], 'id="usdt-odd"') && str_contains($ol['body'], '>USDT</span>') && str_contains($ol['body'], '选了 USDT'), 'orders list: the unmatched notice, USDT-paid and USDT-chosen orders marked');
    ok(http('POST', '/admin/?r=usdt.attach', ['form' => ['id' => $odd['id'], 'order' => $o['id']], 'headers' => $cookie])['status'] === 403 && usdt_order_status($o['id']) === 'pending', 'attach without CSRF: 403, nothing changed');
    $r = http('POST', '/admin/?r=usdt.attach', ['form' => ['_csrf' => $csrf, 'id' => $odd['id'], 'order' => strtolower($o['id'])], 'headers' => $cookie]);
    ok(in_array($r['status'], [302, 303], true) && usdt_order_status($o['id']) === 'confirmed' && usdt_unmatched_get($odd['id'])['state'] === 'attached', 'attach with CSRF: the order is confirmed (order number case-insensitive)');
    $od = http('GET', '/admin/?r=order&id=' . $o['id'], ['headers' => $cookie]);
    ok(str_contains($od['body'], 'USDT 手动对账') && str_contains($od['body'], 'https://tronscan.org/#/transaction/' . $odd['tx']), 'order page: the USDT card with the tx and the explorer link');
    $od = http('GET', '/admin/?r=order&id=' . $id, ['headers' => $cookie]);
    ok(str_contains($od['body'], 'USDT 已到账，自动确认') && str_contains($od['body'], 'USDT 自动核对'), 'order page of the auto-confirmed order: the USDT card and history by USDT 自动核对');
    $bz = http('GET', '/admin/?r=biz', ['headers' => $cookie]);
    ok($bz['status'] === 200 && str_contains($bz['body'], '其中 USDT 付款'), '经营总览 counts the orders paid in USDT (revenue stays in CNY)');
    $r = http('POST', '/admin/?r=usdt.check', ['form' => ['_csrf' => $csrf], 'headers' => $cookie]);
    ok(in_array($r['status'], [302, 303], true), '现在查一次 runs and returns to the page');
}
