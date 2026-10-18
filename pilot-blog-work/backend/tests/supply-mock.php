<?php
// A mock supplier store (发卡系统「店铺共享」API) for the tests: php -S router. Never deployed.
// State and knobs live in the JSON file named by MOCK_STATE (flock'd, so PHP_CLI_SERVER_WORKERS > 1 is fine).
// Every request is checked against the supplier's signature rule with the mock's app_id/app_key.
// Knobs (state.knobs): ip_refuse, balance (fen), price_bump (fen per unit), stock_override, break_after_trade (one shot:
// trade goes through, the answer is broken), dup_refuse (a repeated request_no is refused instead of answered again),
// manual_release (manual-delivery cards become available on /query), trade_delay_ms.
declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') {
    exit;
}
$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$stateFile = (string) getenv('MOCK_STATE');

function out(array $j, int $status = 200): bool
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($j, JSON_UNESCAPED_UNICODE);
    return true;
}

/** The supplier's PHP reference, written out again on purpose (not shared with the app). */
function mock_sign(array $data, string $key): string
{
    unset($data['sign']);
    ksort($data);
    $data = array_filter($data, fn($v) => $v !== '');
    return md5(urldecode(http_build_query($data) . '&key=' . $key));
}

function with_state(callable $fn): mixed
{
    global $stateFile;
    $fp = fopen($stateFile, 'c+');
    flock($fp, LOCK_EX);
    $s = json_decode((string) stream_get_contents($fp), true) ?: [];
    $res = $fn($s);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $res;
}

// test-only odd answers by path prefix
if (str_starts_with($path, '/redir/')) {
    header('Location: http://169.254.169.254/latest/meta-data/', true, 302);
    return true;
}
if (str_starts_with($path, '/big/')) {
    header('Content-Type: application/json');
    echo '{"code":200,"data":"' . str_repeat('x', 3 * 1024 * 1024) . '"}';
    return true;
}
if (str_starts_with($path, '/html/')) {
    header('Content-Type: text/html');
    echo '<html><body>not the api</body></html>';
    return true;
}
if ($path === '/_state') {   // the tests read the mock's books
    echo (string) @file_get_contents($stateFile);
    return true;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !str_starts_with($path, '/shared/')) {
    return out(['code' => 0, 'msg' => 'not found'], 404);
}
$p = $_POST;
return with_state(function (array &$s) use ($path, $p) {
    $k = $s['knobs'] ?? [];
    $s['calls'][] = ['path' => $path, 'keys' => array_keys($p), 'contact' => $p['contact'] ?? null, 'race' => $p['race'] ?? null, 'request_no' => $p['request_no'] ?? null];
    if (!empty($k['ip_refuse'])) {
        return out(['code' => 0, 'msg' => 'IP地址(127.0.0.1)不在对接白名单内']);
    }
    if (($p['app_id'] ?? '') !== $s['app_id']) {
        return out(['code' => 0, 'msg' => '商户不存在']);
    }
    if (!is_string($p['sign'] ?? null) || !hash_equals(mock_sign($p, $s['app_key']), $p['sign'])) {
        $s['bad_signs'] = ($s['bad_signs'] ?? 0) + 1;
        return out(['code' => 0, 'msg' => '签名错误']);
    }
    $items = $s['items'];
    $find = function (string $code) use ($items) {
        foreach ($items as $c) {
            foreach ($c['children'] as $i) {
                if ($i['code'] === $code) {
                    return $i;
                }
            }
        }
        return null;
    };
    $unit = function (array $i, string $race) use ($k): float {
        $base = $race !== '' && isset($i['config']['category'][$race]) ? (float) $i['config']['category'][$race] : (float) $i['user_price'];
        return $base + ($k['price_bump'] ?? 0) / 100;
    };
    switch (substr($path, 7)) {
        case '/authentication/connect':
            return out(['code' => 200, 'msg' => 'success', 'data' => []]);
        case '/commodity/items':
            $list = [];
            foreach ($items as $c) {
                $list[] = ['name' => $c['name'], 'children' => array_map(fn($i) => array_intersect_key($i, array_flip(['id', 'name', 'code', 'price', 'user_price', 'stock', 'delivery_way', 'draft_status'])), $c['children'])];
            }
            return out(['code' => 200, 'data' => $list]);
        case '/commodity/item':
            $i = $find((string) ($p['code'] ?? ''));
            return $i ? out(['code' => 200, 'data' => $i]) : out(['code' => 0, 'msg' => '商品不存在']);
        case '/commodity/stock':
            $i = $find((string) ($p['code'] ?? ''));
            return $i ? out(['code' => 200, 'data' => ['stock' => $k['stock_override'] ?? $i['stock']]]) : out(['code' => 0, 'msg' => '商品不存在']);
        case '/commodity/valuation':
            $i = $find((string) ($p['code'] ?? ''));
            if (!$i) {
                return out(['code' => 0, 'msg' => '商品不存在']);
            }
            return out(['code' => 200, 'data' => ['price' => number_format($unit($i, (string) ($p['race'] ?? '')) * max(1, (int) ($p['num'] ?? 1)), 2, '.', '')]]);
        case '/commodity/trade':
            $req = (string) ($p['request_no'] ?? '');
            $i = $find((string) ($p['shared_code'] ?? ''));
            if (!$i || $req === '') {
                return out(['code' => 0, 'msg' => '参数错误']);
            }
            $s['trade_calls'] = ($s['trade_calls'] ?? 0) + 1;
            if (!empty($k['trade_delay_ms'])) {
                usleep((int) $k['trade_delay_ms'] * 1000);
            }
            if (isset($s['trades'][$req])) {
                if (!empty($k['dup_refuse'])) {
                    return out(['code' => 0, 'msg' => '订单号重复']);
                }
                return out(['code' => 200, 'data' => $s['trades'][$req]]);
            }
            $num = max(1, (int) ($p['num'] ?? 1));
            $amount = (int) round($unit($i, (string) ($p['race'] ?? '')) * $num * 100);
            $stock = $k['stock_override'] ?? $i['stock'];
            if ($stock < $num) {
                return out(['code' => 0, 'msg' => '库存不足']);
            }
            if (($s['balance'] ?? 0) < $amount) {
                return out(['code' => 0, 'msg' => '余额不足，请充值']);
            }
            $s['balance'] -= $amount;
            $s['seq'] = ($s['seq'] ?? 1000) + 1;
            $tradeNo = 'T' . $s['seq'];
            $secret = $i['delivery_way'] === 1 ? '卡密正在发货中，请耐心等待' : implode("\n", array_map(fn($n) => 'CARD-' . $s['seq'] . '-' . $n . ' 密码 ' . substr(md5($tradeNo . $n), 0, 10), range(1, $num)));
            if (isset($k['secret_override'])) {
                $secret = (string) $k['secret_override'];
            }
            $res = ['tradeNo' => $tradeNo, 'amount' => number_format($amount / 100, 2, '.', ''), 'secret' => $secret, 'stock' => $stock - $num];
            $s['trades'][$req] = $res;
            $s['by_trade'][$tradeNo] = ['manual' => $i['delivery_way'] === 1, 'num' => $num];
            if (!empty($k['break_after_trade'])) {
                $s['knobs']['break_after_trade'] = false;
                http_response_code(502);
                echo '<html>Bad Gateway</html>';
                return true;
            }
            return out(['code' => 200, 'data' => $res]);
        case '/commodity/query':
            $t = (string) ($p['tradeNo'] ?? '');
            $b = $s['by_trade'][$t] ?? null;
            if (!$b) {
                return out(['code' => 0, 'msg' => '订单不存在']);
            }
            $s['queries'] = ($s['queries'] ?? 0) + 1;
            $secret = $b['manual'] && empty($k['manual_release']) ? '卡密正在发货中，请耐心等待' : 'MANUAL-' . $t . ' 兑换码 ' . substr(md5($t), 0, 12);
            return out(['code' => 200, 'data' => ['secret' => $secret, 'widget' => [], 'status' => 1]]);
    }
    return out(['code' => 0, 'msg' => '接口不存在']);
});
