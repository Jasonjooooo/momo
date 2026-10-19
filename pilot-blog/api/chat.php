<?php
// 博客 AI 助手的后端：把聊天框的消息转给 Claude 或豆包（火山方舟，后台选），API 密钥只留在服务器上。
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
你是一位机长个人博客上的店铺客服。博客作者是一名飞皮拉图斯 PC-12 的短途运输飞行员，博客写飞行日志、机型手记，还有一个「商城」，卖作者自己做的和挑过的东西：实物寄快递，教程、资料这类数字商品在线发送。

商城是这样运作的（照实说，不要添油加醋）：
- 访客在商品页点「购买」，填数量和收货信息（数字商品填邮箱），提交后会得到一个付款金额。金额比合计多几分钱，是这个订单的识别尾数，用来认出是谁付的款。
- 付款是扫站长本人的微信或支付宝收款码，付页面上显示的那个金额，备注订单号，然后点「我已付款」。
- 确认收款是站长本人对照手机收款记录手工做的，不是自动到账，可能要等几个小时，飞行的日子会更久。
- 没付款的订单过一段时间会自动取消，库存放回去。
- 订单进度在「查订单」里看：填订单号和手机号后 4 位（数字商品填邮箱 @ 前面的部分）。数字商品确认收款后就在那里查看或下载。实物发货后那里有快递单号。
- 你看不到任何订单，也不能改订单、退款或改价格。遇到具体订单的问题，请访客去「查订单」，或者等站长处理。

你的任务：
- 回答访客关于商城、商品、下单和付款流程的问题；也可以聊飞行、航空常识、PC-12 这类机型。
- 用简体中文，口吻平实、准确、简短。能三句话说清楚的就不写一大段。
- 你不是博客作者本人，不要以作者身份说话，也不要编造作者的经历、商品细节、发货时间或优惠。不知道的直接说不知道。
- 不要索要或复述访客的电话、地址等个人信息。
- 涉及真实飞行操作、签派、适航、医疗体检等，只讲一般性知识，并提醒以官方手册、局方规章和教员意见为准。
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

// A cross-site form or script: the Origin header names another host. A visitor's browser on another
// site must not be able to spend the owner's API quota from the visitor's own IP.
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    $p = parse_url($origin);
    $scheme = strtolower((string) ($p['scheme'] ?? ''));
    $originHost = strtolower((string) ($p['host'] ?? ''));
    $port = (int) ($p['port'] ?? 0);
    if ($port && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
        $originHost .= ':' . $port;
    }
    $host = (string) preg_replace('/:(80|443)$/', '', strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')));
    if ($originHost === '' || $originHost !== $host) {
        respond(403, ['error' => 'forbidden', 'message' => '这次没有发送成功。请从本站页面提问。']);
    }
}

// Require a JSON content type, so a cross-site "simple" request (text/plain, no preflight) is refused.
$ctype = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
if (!str_contains($ctype, 'application/json')) {
    respond(415, ['error' => 'unsupported_media_type', 'message' => '这次没有发送成功。请从本站页面提问。']);
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
$apiKey = chat_key($settings);
if (!$settings['enabled']) {
    // switched off in the admin; before any key exists the page is still in its "接入中" state
    $msg = $apiKey === null ? MSG_NOT_CONFIGURED : '站长暂时关掉了助手，这个问题暂时没有回答。';
    respond(503, ['error' => 'not_configured', 'message' => $msg], 'chat.errors.disabled');
}
if ($apiKey === null) {
    respond(503, ['error' => 'not_configured', 'message' => MSG_NOT_CONFIGURED], 'chat.errors.not_configured');
}

// 先校验输入，格式不对的请求（{messages: [{role, content}]}，user 开头，user/assistant 交替，最后一条是
// user）直接 400，不占用今天的额度，别人就没法用无效请求刷掉额度。
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

// 限流：每位访客（IP 的加盐哈希，IPv6 按 /64 归并）每 10 分钟有上限，全站每天（北京时间）有上限。
// 计数文件写不了时（比如账户 inode 用光）宁可答 503，也不放行付费的 API 调用。
$ip = rl_hit('chat-ip', ip_hash(client_key()), max(1, (int) $settings['ip_limit']), 60 * (int) $settings['ip_window_min']);
if (!empty($ip['error'])) {
    respond(503, ['error' => 'unavailable', 'message' => '助手暂时忙不过来，稍后再试。'], 'chat.errors.unavailable');
}
if (!$ip['allowed']) {
    respond(429, ['error' => 'rate_limited', 'message' => '问得有点快了，休息几分钟再来。'], 'chat.errors.rate_limited_ip');
}
$day = rl_hit('chat-day', local_day(), (int) $settings['daily_limit'], 2 * 86400);
if (!empty($day['error'])) {
    respond(503, ['error' => 'unavailable', 'message' => '助手暂时忙不过来，稍后再试。'], 'chat.errors.unavailable');
}
if (!$day['allowed']) {
    respond(429, ['error' => 'daily_limit', 'message' => '今天的提问额度用完了，明天再来。'], 'chat.errors.daily_limit');
}

$system = SYSTEM_PROMPT;
$catalog = shop_catalog_text();
if ($catalog !== '') {
    $system .= "\n\n商城现在的情况（自动生成，以网页为准）：\n" . $catalog;
}
$note = trim((string) $settings['extra_note']);
if ($note !== '') {
    $system .= "\n\n站长补充说明（博客作者填写，仅供参考）：\n" . $note;
}

// 本地测试钩子：只在 php -S 开发服务器里、并且设了 BLOG_CHAT_FAKE=1 时生效，不调用 API。
// 同一个开关下，豆包可以改连本机的模拟方舟（BLOG_ARK_TEST_BASE，见 app/lib/doubao.php）。
$doubao = $settings['provider'] === 'doubao';
if (PHP_SAPI === 'cli-server' && getenv('BLOG_CHAT_FAKE') === '1' && !($doubao && doubao_mock_active())) {
    respond(200, ['reply' => '（测试回答）'], 'chat.answered', ['chat.tokens_in' => 120, 'chat.tokens_out' => 40]);
}

if ($doubao) {
    // 豆包（火山方舟）：OpenAI 风格的 chat/completions，系统提示放在第一条 system 消息里。
    $r = doubao_chat($apiKey, (string) $settings['model'], array_merge([['role' => 'system', 'content' => $system]], $messages), min(MAX_TOKENS, 1024));
    if (!$r['ok']) {
        // 给站长看的原因（中文、不含提问内容和密钥）记进今天的用量文件和服务器错误日志
        usage_note_error('doubao', $r['reason']);
        error_log('chat.php doubao ' . $r['kind'] . ' ' . $r['status']);
        [$st, $err, $msg, $count] = match ($r['kind']) {
            'auth' => [503, 'not_configured', MSG_NOT_CONFIGURED, 'chat.errors.not_configured'],
            'bad_request' => [400, 'bad_request', '这条消息发不出去，换个说法试试。', 'chat.errors.bad_request'],
            'rate' => [429, 'upstream_busy', '助手有点忙，稍等一会儿再问。', 'chat.errors.upstream_busy'],
            'network' => [502, 'upstream_unreachable', '助手暂时连不上，稍后再试。', 'chat.errors.upstream_unreachable'],
            default => [502, 'upstream_error', '助手暂时连不上，稍后再试。', 'chat.errors.upstream_error'],
        };
        respond($st, ['error' => $err, 'message' => $msg], $count);
    }
    $tokens = ['chat.tokens_in' => $r['in'], 'chat.tokens_out' => $r['out']];
    if ($r['finish'] === 'content_filter') {
        respond(200, ['reply' => '这个问题我没法回答。可以换个角度问问商城或者飞行相关的事。'], 'chat.refused', $tokens);
    }
    if ($r['text'] === '') {
        respond(502, ['error' => 'empty_reply', 'message' => '助手这次没想好怎么回答，再问一次试试。'], 'chat.errors.empty_reply', $tokens);
    }
    respond(200, ['reply' => $r['text']], 'chat.answered', $tokens);
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
    respond(200, ['reply' => '这个问题我没法回答。可以换个角度问问商城或者飞行相关的事。'], 'chat.refused', $tokens);
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
