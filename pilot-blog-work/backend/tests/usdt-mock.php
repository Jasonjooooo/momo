<?php
// A fake TronGrid and a fake BNB Smart Chain JSON-RPC for the USDT tests: php -S router. Never deployed.
// State lives in the JSON file named by USDT_STATE:
//   tron: {down: bool, status: int (answer this HTTP status), items: [TronGrid trc20 items]}
//   bsc:  {down: bool, head: int, range_limit: int, logs: [eth_getLogs entries], times: {block: unix}}
//   requests: every request (path, method, query, api_key header, rpc method, params)
// TronGrid: GET /v1/accounts/{address}/transactions/trc20 → {data, success, meta}; items whose `to` is the address,
// newer than min_timestamp, newest first (the real API also filters by contract_address; here the items are
// returned as stored so the tests can check that the blog checks the contract itself).
// BSC: POST / with eth_blockNumber, eth_getLogs (range limit like public RPCs, topic filter on `to`),
// eth_getBlockByNumber (timestamp from times, else head time − 0.75 s per block).
declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') {
    exit;
}
$file = (string) getenv('USDT_STATE');
$fp = fopen($file, 'c+');
flock($fp, LOCK_EX);
$s = json_decode((string) stream_get_contents($fp), true) ?: [];
$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_QUERY), $q);
$body = json_decode((string) file_get_contents('php://input'), true);
$s['requests'][] = [
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => $path,
    'query' => $q,
    'api_key' => (string) ($_SERVER['HTTP_TRON_PRO_API_KEY'] ?? ''),
    'rpc' => is_array($body) ? ($body['method'] ?? null) : null,
    'params' => is_array($body) ? ($body['params'] ?? null) : null,
    'body_raw' => is_array($body) ? null : substr((string) file_get_contents('php://input'), 0, 200),
];
ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
flock($fp, LOCK_UN);
fclose($fp);

header('Content-Type: application/json');
if (preg_match('#^/v1/accounts/([^/]+)/transactions/trc20$#', $path, $m)) {
    $t = $s['tron'] ?? [];
    if (!empty($t['down'])) {
        http_response_code(503);
        echo '<html>down</html>';
        return true;
    }
    if (!empty($t['status'])) {
        http_response_code((int) $t['status']);
        echo json_encode(['success' => false, 'error' => 'mock']);
        return true;
    }
    if (!empty($t['sleep'])) {
        sleep((int) $t['sleep']);
    }
    $min = (int) ($q['min_timestamp'] ?? 0);
    $items = array_values(array_filter($t['items'] ?? [], fn($x) => ($x['to'] ?? '') === rawurldecode($m[1]) && (int) ($x['block_timestamp'] ?? 0) >= $min));
    usort($items, fn($a, $b) => $b['block_timestamp'] <=> $a['block_timestamp']);
    echo json_encode(['data' => array_slice($items, 0, (int) ($q['limit'] ?? 20)), 'success' => true, 'meta' => ['at' => time() * 1000, 'page_size' => count($items)]]);
    return true;
}
if ($path === '/' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = $s['bsc'] ?? [];
    if (!empty($b['down'])) {
        http_response_code(503);
        echo 'down';
        return true;
    }
    $id = $body['id'] ?? 1;
    $out = fn($r) => print(json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $r]));
    $err = fn(int $code, string $msg) => print(json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $msg]]));
    $head = (int) ($b['head'] ?? 1000);
    switch ($body['method'] ?? '') {
        case 'eth_blockNumber':
            $out('0x' . dechex($head));
            break;
        case 'eth_getLogs':
            $f = $body['params'][0] ?? [];
            $from = hexdec((string) ($f['fromBlock'] ?? '0x0'));
            $to = hexdec((string) ($f['toBlock'] ?? '0x0'));
            if ($to - $from + 1 > (int) ($b['range_limit'] ?? 5000)) {
                $err(-32005, 'exceed maximum block range: ' . (int) ($b['range_limit'] ?? 5000));
                break;
            }
            $want = strtolower((string) ($f['topics'][2] ?? ''));
            $logs = array_values(array_filter($b['logs'] ?? [], fn($l) => hexdec($l['blockNumber']) >= $from && hexdec($l['blockNumber']) <= $to
                && strtolower((string) $l['address']) === strtolower((string) ($f['address'] ?? '')) && ($want === '' || strtolower($l['topics'][2]) === $want)));
            $out($logs);
            break;
        case 'eth_getBlockByNumber':
            $n = hexdec((string) ($body['params'][0] ?? '0x0'));
            $ts = $b['times'][(string) $n] ?? (int) (($b['head_time'] ?? time()) - ($head - $n) * 0.75);
            $out(['number' => '0x' . dechex($n), 'timestamp' => '0x' . dechex((int) $ts)]);
            break;
        default:
            $err(-32601, 'method not found');
    }
    return true;
}
http_response_code(404);
echo '{"error":"not found"}';
return true;
