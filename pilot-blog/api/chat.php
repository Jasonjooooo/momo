<?php
// 博客 AI 助手的后端：把聊天框的消息转给 Claude，API 密钥只留在服务器上。
//
// 开关、密钥、限额和补充说明在后台「AI 助手」页面设置，保存在 public_html 同级的 blog-data 文件夹里。
// 旧的配置文件 chat-config.php（同样放在 public_html 的同级）还认：后台没有保存密钥时，用它里面的 api_key。
// 没有密钥或助手关着时，接口返回 503，页面会显示「助手还在接入中」。
// 这里只按天记次数和 token 用量，从不记录访客问了什么。

declare(strict_types=1);

define('BLOG_APP', true);
require __DIR__ . '/../app/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const MODEL = 'claude-opus-5-5';
const MAX_TOKENS = 4096;
const MAX_TURNS = 12;          // 只带最近 12 条消息
const MAX_CHARS_PER_MSG = 2000;

const SYSTEM_PROMPT = <<<'TXT'
你是一位机长个人博客上的 AI 助手。博客作者是一名飞皮拉图斯 PC-12 的短途运输飞行员，博客写飞行日志、机型手记，还有一个「AI 超市」栏目，推荐好用的 AI 工具。

你的任务：
- 回答访客关于飞行、航空常识、飞行员日常、PC-12 这类机型的问题，以及怎么挑选和使用 AI 工具。
- 用简体中文，口吻平实、准确、简短。能三句话说清楚的就不写一大段。
- 你不是博客作者本人，不要以作者身份说话，也不要编造作者的经历、航线或公司。被问到作者的具体情况时，直接说你不知道，请访客去看博客文章。
- 涉及真实飞行操作、签派、适航、医疗体检等，只讲一般性知识，并提醒以官方手册、局方规章和教员意见为准。
- 不确定的事直接说不确定，不要猜。
TXT;

const MSG_NOT_CONFIGURED = '助手还在接入中，这个问题暂时没有回答。接通以后，在这里直接问就行。';

$usageReady = false;

/** Answer and count the outcome ($count: a dotted usage path, or null). */
function respond(int $status, array $body, ?string $count = null, array $extra = []): never
{
    global $usageReady;
    if ($usageReady && $count !== null) {
        usage_add([$count => 1] + $extra);
    }
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, ['error' => 'method_not_allowed']);
}

try {
    app_init('api');
    $usageReady = true;
} catch (Throwable $e) {
    error_log('chat.php init: ' . get_class($e));
    respond(503, ['error' => 'not_configured', 'message' => MSG_NOT_CONFIGURED]);
}
usage_add(['chat.requests' => 1]);

$settings = settings_get()['chat'];
$apiKey = chat_key();
if (!$settings['enabled']) {
    // switched off in the admin; before any key exists the page is still in its "接入中" state
    $msg = $apiKey === null ? MSG_NOT_CONFIGURED : '站长暂时关掉了助手，这个问题暂时没有回答。';
    respond(503, ['error' => 'not_configured', 'message' => $msg], 'chat.errors.disabled');
}
if ($apiKey === null) {
    respond(503, ['error' => 'not_configured', 'message' => MSG_NOT_CONFIGURED], 'chat.errors.not_configured');
}

// 限流：每位访客（IP 的加盐哈希）每 10 分钟有上限，全站每天（北京时间）有上限。
$ip = rl_hit('chat-ip', ip_hash(client_ip()), max(1, (int) $settings['ip_limit']), 60 * (int) $settings['ip_window_min']);
if (!$ip['allowed']) {
    respond(429, ['error' => 'rate_limited', 'message' => '问得有点快了，休息几分钟再来。'], 'chat.errors.rate_limited_ip');
}
$day = rl_hit('chat-day', local_day(), (int) $settings['daily_limit'], 2 * 86400);
if (!$day['allowed']) {
    respond(429, ['error' => 'daily_limit', 'message' => '今天的提问额度用完了，明天再来。'], 'chat.errors.daily_limit');
}

// 校验输入：{messages: [{role, content}]}，user 开头，user/assistant 交替，最后一条是 user。
$input = json_decode((string) file_get_contents('php://input'), true);
$raw = is_array($input['messages'] ?? null) ? $input['messages'] : [];
$raw = array_slice($raw, -MAX_TURNS);
$messages = [];
foreach ($raw as $m) {
    $role = is_array($m) ? ($m['role'] ?? '') : '';
    $content = is_array($m) && is_string($m['content'] ?? null) ? trim($m['content']) : '';
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
    respond(400, ['error' => 'bad_request', 'message' => '没收到问题，请再输入一次。'], 'chat.errors.bad_request');
}

$system = SYSTEM_PROMPT;
$note = trim((string) $settings['extra_note']);
if ($note !== '') {
    $system .= "\n\n站长补充说明（博客作者填写，仅供参考）：\n" . $note;
}

// 本地测试钩子：只在 php -S 开发服务器里、并且设了 BLOG_CHAT_FAKE=1 时生效，不调用 API。
if (PHP_SAPI === 'cli-server' && getenv('BLOG_CHAT_FAKE') === '1') {
    respond(200, ['reply' => '（测试回答）'], 'chat.answered', ['chat.tokens_in' => 120, 'chat.tokens_out' => 40]);
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

/** Exception class and HTTP status only: never the message text or the request. */
function log_api_error(string $what, Throwable $e): void
{
    $status = $e instanceof APIStatusException ? (string) $e->status : '-';
    error_log('chat.php ' . $what . ': ' . get_class($e) . ' ' . $status);
}

try {
    // fallbacks: "default"：模型因安全策略拒答时，服务端自动换推荐的备用模型再答一次。
    $message = $client->beta->messages->create(
        maxTokens: MAX_TOKENS,
        messages: $messages,
        model: MODEL,
        system: $system,
        outputConfig: ['effort' => 'low'],
        fallbacks: 'default',
        betas: ['server-side-fallback-2026-07-01'],
    );
} catch (AuthenticationException | PermissionDeniedException $e) {
    log_api_error('auth', $e);
    respond(503, ['error' => 'not_configured', 'message' => MSG_NOT_CONFIGURED], 'chat.errors.not_configured');
} catch (BadRequestException $e) {
    log_api_error('bad request', $e);
    respond(400, ['error' => 'bad_request', 'message' => '这条消息发不出去，换个说法试试。'], 'chat.errors.bad_request');
} catch (RateLimitException $e) {
    respond(429, ['error' => 'upstream_busy', 'message' => '助手有点忙，稍等一会儿再问。'], 'chat.errors.upstream_busy');
} catch (APIStatusException $e) {
    log_api_error('api', $e);
    respond(502, ['error' => 'upstream_error', 'message' => '助手暂时连不上，稍后再试。'], 'chat.errors.upstream_error');
} catch (APIConnectionException $e) {
    log_api_error('connection', $e);
    respond(502, ['error' => 'upstream_unreachable', 'message' => '助手暂时连不上，稍后再试。'], 'chat.errors.upstream_unreachable');
}

$u = $message->usage;
$tokens = [
    'chat.tokens_in' => (int) $u->inputTokens + (int) ($u->cacheCreationInputTokens ?? 0) + (int) ($u->cacheReadInputTokens ?? 0),
    'chat.tokens_out' => (int) $u->outputTokens,
];

if ($message->stopReason === 'refusal') {
    respond(200, ['reply' => '这个问题我没法回答。可以换个角度问问飞行或者 AI 工具相关的事。'], 'chat.refused', $tokens);
}

$reply = '';
foreach ($message->content as $block) {
    if ($block->type === 'text') {
        $reply .= $block->text;
    }
}
$reply = trim($reply);
if ($reply === '') {
    respond(502, ['error' => 'empty_reply', 'message' => '助手这次没想好怎么回答，再问一次试试。'], 'chat.errors.empty_reply', $tokens);
}

respond(200, ['reply' => $reply], 'chat.answered', $tokens);
