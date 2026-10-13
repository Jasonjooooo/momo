<?php
// 博客 AI 助手的后端：把聊天框的消息转给 Claude，API 密钥只留在服务器上。
//
// 密钥放在网站根目录的上一层（public_html 的同级），文件名 chat-config.php：
//   <?php return ['api_key' => 'sk-ant-...', 'daily_limit' => 300];
// 没有这个文件时，接口返回 503，页面会显示「助手正在接入中」。

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const MODEL = 'claude-opus-5-5';
const MAX_TOKENS = 4096;
const MAX_TURNS = 12;          // 只带最近 12 条消息
const MAX_CHARS_PER_MSG = 2000;
const IP_LIMIT = 20;           // 每个 IP 每 10 分钟最多 20 次
const IP_WINDOW = 600;

const SYSTEM_PROMPT = <<<'TXT'
你是一位机长个人博客上的 AI 助手。博客作者是一名飞皮拉图斯 PC-12 的短途运输飞行员，博客写飞行日志、机型手记，还有一个「AI 超市」栏目，推荐好用的 AI 工具。

你的任务：
- 回答访客关于飞行、航空常识、飞行员日常、PC-12 这类机型的问题，以及怎么挑选和使用 AI 工具。
- 用简体中文，口吻平实、准确、简短。能三句话说清楚的就不写一大段。
- 你不是博客作者本人，不要以作者身份说话，也不要编造作者的经历、航线或公司。被问到作者的具体情况时，请访客去看博客文章或通过订阅联系作者。
- 涉及真实飞行操作、签派、适航、医疗体检等，只讲一般性知识，并提醒以官方手册、局方规章和教员意见为准。
- 不确定的事直接说不确定，不要猜。
TXT;

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['error' => 'method_not_allowed']);
}

$configFile = dirname(__DIR__, 2) . '/chat-config.php';
$config = is_file($configFile) ? require $configFile : [];
$apiKey = (string) ($config['api_key'] ?? getenv('ANTHROPIC_API_KEY') ?: '');
if ($apiKey === '') {
    respond(503, ['error' => 'not_configured', 'message' => '助手正在接入中，过几天再来试试。']);
}

// 简单限流：按 IP 和按天各一个计数文件，放在系统临时目录。
function bump(string $key, int $window): int
{
    $file = sys_get_temp_dir() . '/pilotblog-chat-' . hash('sha256', $key);
    $fp = fopen($file, 'c+');
    if ($fp === false) {
        return 0;
    }
    flock($fp, LOCK_EX);
    $data = json_decode((string) stream_get_contents($fp), true) ?: ['start' => time(), 'count' => 0];
    if (time() - $data['start'] > $window) {
        $data = ['start' => time(), 'count' => 0];
    }
    $data['count']++;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);
    return $data['count'];
}

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (bump('ip:' . $ip, IP_WINDOW) > IP_LIMIT) {
    respond(429, ['error' => 'rate_limited', 'message' => '问得有点快了，休息几分钟再来。']);
}
$dailyLimit = (int) ($config['daily_limit'] ?? 300);
if (bump('day:' . gmdate('Y-m-d'), 86400) > $dailyLimit) {
    respond(429, ['error' => 'daily_limit', 'message' => '今天的提问额度用完了，明天再来。']);
}

// 校验输入：{messages: [{role, content}]}，user 开头，user/assistant 交替，最后一条是 user。
$input = json_decode((string) file_get_contents('php://input'), true);
$raw = is_array($input['messages'] ?? null) ? $input['messages'] : [];
$raw = array_slice($raw, -MAX_TURNS);
$messages = [];
foreach ($raw as $m) {
    $role = $m['role'] ?? '';
    $content = is_string($m['content'] ?? null) ? trim($m['content']) : '';
    if (!in_array($role, ['user', 'assistant'], true) || $content === '') {
        continue;
    }
    $content = mb_substr($content, 0, MAX_CHARS_PER_MSG);
    if ($messages && end($messages)['role'] === $role) {
        $messages[count($messages) - 1]['content'] .= "\n\n" . $content;
    } else {
        $messages[] = ['role' => $role, 'content' => $content];
    }
}
while ($messages && $messages[0]['role'] !== 'user') {
    array_shift($messages);
}
if (!$messages || end($messages)['role'] !== 'user') {
    respond(400, ['error' => 'bad_request', 'message' => '没收到问题，请再输入一次。']);
}

require __DIR__ . '/vendor/autoload.php';

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;

$client = new Client(apiKey: $apiKey);

try {
    // fallbacks: "default" —— 模型因安全策略拒答时，服务端自动换推荐的备用模型再答一次。
    $message = $client->beta->messages->create(
        maxTokens: MAX_TOKENS,
        messages: $messages,
        model: MODEL,
        system: SYSTEM_PROMPT,
        outputConfig: ['effort' => 'low'],
        fallbacks: 'default',
        betas: ['server-side-fallback-2026-07-01'],
    );
} catch (AuthenticationException | PermissionDeniedException $e) {
    error_log('chat.php auth: ' . $e->getMessage());
    respond(503, ['error' => 'not_configured', 'message' => '助手正在接入中，过几天再来试试。']);
} catch (BadRequestException $e) {
    error_log('chat.php bad request: ' . $e->getMessage());
    respond(400, ['error' => 'bad_request', 'message' => '这条消息发不出去，换个说法试试。']);
} catch (RateLimitException $e) {
    respond(429, ['error' => 'upstream_busy', 'message' => '助手有点忙，稍等一会儿再问。']);
} catch (APIStatusException $e) {
    error_log('chat.php api: ' . $e->getMessage());
    respond(502, ['error' => 'upstream_error', 'message' => '助手暂时连不上，稍后再试。']);
} catch (APIConnectionException $e) {
    error_log('chat.php connection: ' . $e->getMessage());
    respond(502, ['error' => 'upstream_unreachable', 'message' => '助手暂时连不上，稍后再试。']);
}

if ($message->stopReason === 'refusal') {
    respond(200, ['reply' => '这个问题我没法回答。可以换个角度问问飞行或者 AI 工具相关的事。']);
}

$reply = '';
foreach ($message->content as $block) {
    if ($block->type === 'text') {
        $reply .= $block->text;
    }
}
$reply = trim($reply);
if ($reply === '') {
    respond(502, ['error' => 'empty_reply', 'message' => '助手这次没想好怎么回答，再问一次试试。']);
}

respond(200, ['reply' => $reply]);
