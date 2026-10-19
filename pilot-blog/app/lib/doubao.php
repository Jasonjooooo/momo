<?php
// 豆包（火山引擎方舟）chat completions for the blog assistant (SPEC §10.3). The base URL is fixed; the
// owner only picks a model name or 接入点 ID. Nothing here logs message text or the key.
declare(strict_types=1);
defined('BLOG_APP') || exit;

const DOUBAO_BASE = 'https://ark.cn-beijing.volces.com/api/v3';
const DOUBAO_MODEL_RE = '/^[A-Za-z0-9._:\-]{1,100}$/D';
const DOUBAO_KEY_RE = '/^[A-Za-z0-9_.\-]{16,200}$/D';
const DOUBAO_TIMEOUT = 20;
const DOUBAO_MAX_BYTES = 1048576;

/** The fixed Ark base, or a loopback mock under the local test flag only (php -S with BLOG_CHAT_FAKE=1). */
function doubao_base(): string
{
    if (PHP_SAPI === 'cli-server' && getenv('BLOG_CHAT_FAKE') === '1') {
        $t = (string) getenv('BLOG_ARK_TEST_BASE');
        if (preg_match('#^http://127\.0\.0\.1:\d{2,5}$#', $t)) {
            return $t;
        }
    }
    return DOUBAO_BASE;
}

function doubao_mock_active(): bool
{
    return doubao_base() !== DOUBAO_BASE;
}

/** Plain Chinese reason for the owner, by failure kind. */
function doubao_reason(string $kind, int $status = 0): string
{
    return match ($kind) {
        'auth' => '豆包拒绝了请求（' . $status . '）：API Key 不对或已失效，或者这个模型还没在火山方舟「开通管理」里开通。',
        'not_found' => '找不到这个模型或接入点（404）：检查「模型 / 接入点 ID」有没有填错。',
        'bad_request' => '豆包说请求有问题（400）：多半是模型名称填错了，或者这个模型不支持对话。',
        'rate' => '豆包限流或额度用完了（429）：稍后再试，或在火山方舟控制台看看用量和余额。',
        'server' => '豆包那边出错了（' . $status . '）：稍后再试。',
        'network' => '连不上豆包：网络不通或超过 ' . DOUBAO_TIMEOUT . ' 秒没有回应。',
        'bad_response' => '豆包返回的内容看不懂（格式不对或太大）。',
        default => '豆包返回了没见过的错误（' . $status . '）。',
    };
}

/**
 * One chat completion. $messages: [{role, content}] with the system message first.
 * Returns ['ok' => true, 'text', 'finish', 'in', 'out'] or ['ok' => false, 'kind', 'status', 'reason'].
 */
function doubao_chat(string $key, string $model, array $messages, int $maxTokens, int $timeout = DOUBAO_TIMEOUT): array
{
    $fail = fn(string $kind, int $status = 0) => ['ok' => false, 'kind' => $kind, 'status' => $status, 'reason' => doubao_reason($kind, $status)];
    if (!function_exists('curl_init')) {
        return $fail('network');
    }
    $body = json_encode([
        'model' => $model,
        'messages' => $messages,
        'max_tokens' => $maxTokens,
        'temperature' => 0.7,
        'stream' => false,
    ], JSON_UNESCAPED_UNICODE);
    $base = doubao_base();
    $proto = $base === DOUBAO_BASE ? CURLPROTO_HTTPS : CURLPROTO_HTTP;
    $buf = '';
    $tooLarge = false;
    $ch = curl_init($base . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json', 'Accept: application/json', 'Expect:'],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => $proto,
        CURLOPT_REDIR_PROTOCOLS => $proto,
        CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'pilot-blog-chat/1',
        CURLOPT_ENCODING => '',
        CURLOPT_HEADER => false,
        CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$buf, &$tooLarge): int {
            if (strlen($buf) + strlen($chunk) > DOUBAO_MAX_BYTES) {
                $tooLarge = true;
                return 0;
            }
            $buf .= $chunk;
            return strlen($chunk);
        },
    ]);
    $okRun = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($tooLarge) {
        return $fail('bad_response', $status);
    }
    if ($okRun === false || $status === 0) {
        return $fail('network');
    }
    if ($status === 401 || $status === 403) {
        return $fail('auth', $status);
    }
    if ($status === 404) {
        return $fail('not_found', $status);
    }
    if ($status === 400) {
        return $fail('bad_request', $status);
    }
    if ($status === 429) {
        return $fail('rate', $status);
    }
    if ($status >= 500) {
        return $fail('server', $status);
    }
    if ($status !== 200) {
        return $fail('other', $status);
    }
    $j = json_decode($buf, true);
    $choice = is_array($j) && is_array($j['choices'][0] ?? null) ? $j['choices'][0] : null;
    if ($choice === null || !is_array($choice['message'] ?? null)) {
        return $fail('bad_response', $status);
    }
    $text = $choice['message']['content'] ?? '';
    $u = is_array($j['usage'] ?? null) ? $j['usage'] : [];
    return [
        'ok' => true,
        'text' => is_string($text) ? trim($text) : '',
        'finish' => is_string($choice['finish_reason'] ?? null) ? $choice['finish_reason'] : '',
        'in' => max(0, (int) ($u['prompt_tokens'] ?? 0)),
        'out' => max(0, (int) ($u['completion_tokens'] ?? 0)),
    ];
}
