<?php
// A mock PushPlus /send endpoint for the tests: php -S router. Never deployed.
// State lives in the JSON file named by PUSH_STATE: knobs.mode picks the answer, every request is recorded
// (path, method, content type, decoded body) so the tests can check what the blog sent.
// Modes: ok (default, {code:200}), code (knobs.code with a PushPlus-style msg), status (HTTP knobs.status),
// bad_json, slow (sleeps knobs.sleep seconds, default 8, then answers ok).
declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') {
    exit;
}
$file = (string) getenv('PUSH_STATE');
$fp = fopen($file, 'c+');
flock($fp, LOCK_EX);
$s = json_decode((string) stream_get_contents($fp), true) ?: [];
$s['requests'][] = [
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH),
    'ctype' => (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
    'body' => json_decode((string) file_get_contents('php://input'), true),
];
ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($s, JSON_UNESCAPED_UNICODE));
flock($fp, LOCK_UN);
fclose($fp);

$k = $s['knobs'] ?? [];
$mode = (string) ($k['mode'] ?? 'ok');
header('Content-Type: application/json; charset=utf-8');
switch ($mode) {
    case 'code':
        echo json_encode(['code' => (int) $k['code'], 'msg' => 'mock refusal ' . (int) $k['code'], 'data' => null], JSON_UNESCAPED_UNICODE);
        break;
    case 'status':
        http_response_code((int) $k['status']);
        echo '<html>error</html>';
        break;
    case 'bad_json':
        echo '{"code": 200';
        break;
    case 'slow':
        sleep((int) ($k['sleep'] ?? 8));
        echo json_encode(['code' => 200, 'msg' => '请求成功', 'data' => 'late']);
        break;
    default:
        echo json_encode(['code' => 200, 'msg' => '请求成功', 'data' => 'mock-' . count($s['requests'])], JSON_UNESCAPED_UNICODE);
}
return true;
