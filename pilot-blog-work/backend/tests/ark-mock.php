<?php
// A mock 火山方舟 (Ark) chat/completions endpoint for the tests: php -S router. Never deployed.
// State lives in the JSON file named by ARK_STATE: knobs.mode picks the answer, every request is recorded
// (path, method, Authorization header, decoded body) so the tests can check what the blog sent.
// Modes: ok (default), status (knobs.status with an Ark-style error body), bad_json, huge (over 1 MB),
// empty (content ""), filter (finish_reason content_filter).
declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') {
    exit;
}
$file = (string) getenv('ARK_STATE');
$fp = fopen($file, 'c+');
flock($fp, LOCK_EX);
$s = json_decode((string) stream_get_contents($fp), true) ?: [];
$s['requests'][] = [
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH),
    'auth' => (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''),
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
$answer = fn(string $content, string $finish = 'stop') => json_encode([
    'id' => 'mock-1', 'object' => 'chat.completion', 'model' => 'doubao-lite-32k-mock',
    'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => $finish]],
    'usage' => ['prompt_tokens' => 77, 'completion_tokens' => 33, 'total_tokens' => 110],
], JSON_UNESCAPED_UNICODE);
switch ($mode) {
    case 'status':
        http_response_code((int) $k['status']);
        echo json_encode(['error' => ['code' => 'MockError', 'message' => 'mock error ' . (int) $k['status'], 'type' => 'mock']]);
        break;
    case 'bad_json':
        echo '{"choices": [ not json';
        break;
    case 'huge':
        echo '{"choices":[{"message":{"role":"assistant","content":"' . str_repeat('a', 1100000) . '"}}]}';
        break;
    case 'empty':
        echo $answer('   ');
        break;
    case 'filter':
        echo $answer('', 'content_filter');
        break;
    default:
        echo $answer('  豆包模拟回答：决断高度是进近中必须决定复飞或继续着陆的高度。  ');
}
return true;
