<?php
// Settings (SPEC §5.5), the write-only Anthropic key (§5.6, §10) and usage counters (§5.9).
declare(strict_types=1);
defined('BLOG_APP') || exit;

const CHAT_KEY_RE = '/^sk-ant-[A-Za-z0-9_\-]{20,200}$/';

function settings_defaults(): array
{
    return [
        'rev' => 0,
        'updated_at' => null,
        'chat' => ['enabled' => false, 'daily_limit' => 300, 'ip_limit' => 20, 'ip_window_min' => 10, 'extra_note' => ''],
        'subscribe' => ['enabled' => true],
        'home' => ['leg_limit' => 6],
        'stats' => ['enabled' => true],
        'shop' => ['enabled' => true, 'shipping' => 0, 'free_over' => 0, 'cancel_hours' => 24, 'notice' => '', 'pay_sub' => false, 'qr' => ['wechat' => null, 'alipay' => null]],
        'supply' => ['enabled' => false, 'base_url' => '', 'app_id' => ''],
    ];
}

function settings_normalize(array $s): array
{
    $d = settings_defaults();
    $out = array_replace_recursive($d, $s);
    $out['chat']['enabled'] = (bool) $out['chat']['enabled'];
    $out['chat']['daily_limit'] = (int) $out['chat']['daily_limit'];
    $out['chat']['ip_limit'] = (int) $out['chat']['ip_limit'];
    $out['chat']['ip_window_min'] = 10;
    $out['chat']['extra_note'] = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], (string) $out['chat']['extra_note']);
    $out['subscribe']['enabled'] = (bool) $out['subscribe']['enabled'];
    $out['home']['leg_limit'] = (int) $out['home']['leg_limit'];
    $out['stats']['enabled'] = (bool) $out['stats']['enabled'];
    $out['shop']['enabled'] = (bool) $out['shop']['enabled'];
    $out['shop']['pay_sub'] = (bool) $out['shop']['pay_sub'];
    foreach (['shipping', 'free_over', 'cancel_hours'] as $k) {
        $out['shop'][$k] = (int) $out['shop'][$k];
    }
    $out['shop']['notice'] = trim(str_replace(["\r\n", "\r", "\n", "\t"], ' ', (string) $out['shop']['notice']));
    if (!is_array($out['shop']['qr'])) {
        $out['shop']['qr'] = [];
    }
    foreach (['wechat', 'alipay'] as $k) {
        $q = $out['shop']['qr'][$k] ?? null;
        $out['shop']['qr'][$k] = is_string($q) && preg_match(SHOP_QR_RE, $q) && str_contains($q, '/' . $k . '-') ? $q : null;
    }
    $out['shop']['qr'] = ['wechat' => $out['shop']['qr']['wechat'], 'alipay' => $out['shop']['qr']['alipay']];
    $out['supply'] = [
        'enabled' => (bool) ($out['supply']['enabled'] ?? false),
        'base_url' => is_string($out['supply']['base_url'] ?? null) ? trim($out['supply']['base_url']) : '',
        'app_id' => is_string($out['supply']['app_id'] ?? null) ? trim($out['supply']['app_id']) : '',
    ];
    $out['rev'] = (int) $out['rev'];
    return $out;
}

function settings_validate(array $s): array
{
    $e = [];
    if ($s['chat']['daily_limit'] < 0 || $s['chat']['daily_limit'] > 5000) {
        $e['chat.daily_limit'] = '每天最多回答次数填 0 到 5000。';
    }
    if ($s['chat']['ip_limit'] < 1 || $s['chat']['ip_limit'] > 200) {
        $e['chat.ip_limit'] = '每位访客 10 分钟内最多次数填 1 到 200。';
    }
    if (mb_strlen($s['chat']['extra_note']) > 1000) {
        $e['chat.extra_note'] = '补充说明最多 1000 个字。';
    } elseif (preg_match('/[\x00-\x09\x0B-\x1F\x7F]/', $s['chat']['extra_note'])) {
        $e['chat.extra_note'] = '补充说明里有看不见的特殊字符，请删掉后再保存。';
    }
    if ($s['home']['leg_limit'] < 3 || $s['home']['leg_limit'] > 12) {
        $e['home.leg_limit'] = '首页显示的日志篇数填 3 到 12。';
    }
    if ($s['shop']['shipping'] < 0 || $s['shop']['shipping'] > SHOP_SHIPPING_MAX) {
        $e['shop.shipping'] = '运费填 0 到 1000 元。';
    }
    if ($s['shop']['free_over'] < 0 || $s['shop']['free_over'] > SHOP_PRICE_MAX) {
        $e['shop.free_over'] = '包邮门槛填 0（不包邮）到 99999 元。';
    }
    if ($s['shop']['cancel_hours'] < 1 || $s['shop']['cancel_hours'] > 168) {
        $e['shop.cancel_hours'] = '自动取消时间填 1 到 168 小时。';
    }
    if (mb_strlen($s['shop']['notice']) > 200) {
        $e['shop.notice'] = '公告最多 200 个字。';
    } elseif (!mb_check_encoding($s['shop']['notice'], 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $s['shop']['notice'])) {
        $e['shop.notice'] = '公告里有看不见的特殊字符，请删掉后再保存。';
    }
    if ($s['supply']['base_url'] !== '' && !($u = supply_url_parse($s['supply']['base_url']))['ok']) {
        $e['supply.base_url'] = $u['msg'];
    }
    if ($s['supply']['app_id'] !== '' && !preg_match(SUPPLY_APP_ID_RE, $s['supply']['app_id'])) {
        $e['supply.app_id'] = 'app_id 只能是字母、数字、短横线和下划线，最多 64 位。';
    }
    return $e;
}

function settings_get(): array
{
    $s = json_read(data_path('settings.json'), []);
    return settings_normalize(is_array($s) ? $s : []);
}

/** Things in the settings that change the public page. */
function settings_page_state(array $s): array
{
    $shop = $s['shop'];
    unset($shop['qr']);   // the 收款码 only appear on the pay page (served live), not on the static pages
    return [$s['subscribe']['enabled'], $s['home']['leg_limit'], $s['chat']['enabled'] && chat_key() !== null, $s['stats']['enabled'], $shop, $s['supply']['enabled']];
}

/**
 * Merge $patch (nested, e.g. ['chat' => ['enabled' => true]]), validate, save, and republish the site
 * (no version entry) when something on the page changed. Returns ['settings' => …, 'publish' => ?array].
 */
function settings_save(array $patch, int $rev): array
{
    return with_lock('publish', function () use ($patch, $rev) {
        $old = settings_get();
        require_rev($old, $rev);
        unset($patch['rev'], $patch['updated_at']);
        $new = settings_normalize(array_replace_recursive($old, $patch));
        $err = settings_validate($new);
        if ($err) {
            throw new ValidationError($err);
        }
        $new['rev'] = $old['rev'] + 1;
        $new['updated_at'] = now_iso();
        json_write_atomic(data_path('settings.json'), $new);
        $pub = null;
        if (settings_page_state($old) !== settings_page_state($new)) {
            $pub = publish_site('设置变更', ['version' => false]);
        }
        return ['settings' => $new, 'publish' => $pub];
    });
}

// ---------- Anthropic key: server side only ----------

function chat_legacy_config(): array
{
    $file = dirname(PUBLIC_DIR) . '/chat-config.php';
    if (!is_file($file)) {
        return [];
    }
    $cfg = (static fn() => require $file)();
    return is_array($cfg) ? $cfg : [];
}

function chat_key_source(): ?string
{
    $s = secrets_get();
    if (is_string($s['anthropic_api_key'] ?? null) && $s['anthropic_api_key'] !== '') {
        return 'admin';
    }
    $legacy = chat_legacy_config()['api_key'] ?? '';
    if (is_string($legacy) && $legacy !== '') {
        return 'legacy';
    }
    $env = getenv('ANTHROPIC_API_KEY');
    return is_string($env) && $env !== '' ? 'env' : null;
}

function chat_key(): ?string
{
    switch (chat_key_source()) {
        case 'admin':
            return secrets_get()['anthropic_api_key'];
        case 'legacy':
            return (string) chat_legacy_config()['api_key'];
        case 'env':
            return (string) getenv('ANTHROPIC_API_KEY');
    }
    return null;
}

/** Last 4 characters, for the admin status line. */
function chat_key_hint(): ?string
{
    $k = chat_key();
    return $k === null ? null : substr($k, -4);
}

function chat_key_set_at(): ?string
{
    return secrets_get()['key_set_at'] ?? null;
}

/** Store a new key (validated). Republishes when the assistant's live state flips. Returns the publish result or null. */
function chat_key_set(string $key): ?array
{
    $key = trim($key);
    if (!preg_match(CHAT_KEY_RE, $key)) {
        throw new ValidationError(['key' => '这不像是 Anthropic 的 API 密钥（应该以 sk-ant- 开头）。']);
    }
    return with_lock('publish', function () use ($key) {
        $before = chat_live();
        secrets_update(function (array $s) use ($key) {
            $s['anthropic_api_key'] = $key;
            $s['key_set_at'] = now_iso();
            return $s;
        });
        @chmod(data_path('secrets.json'), 0600);
        return chat_live() !== $before ? publish_site('设置变更', ['version' => false]) : null;
    });
}

function chat_key_remove(): ?array
{
    return with_lock('publish', function () {
        $before = chat_live();
        secrets_update(function (array $s) {
            unset($s['anthropic_api_key'], $s['key_set_at']);
            return $s;
        });
        return chat_live() !== $before ? publish_site('设置变更', ['version' => false]) : null;
    });
}

function chat_live(): bool
{
    return settings_get()['chat']['enabled'] && chat_key() !== null;
}

// ---------- usage (Beijing days, kept 90 days, never message contents) ----------

function usage_empty(string $day): array
{
    return [
        'date' => $day,
        'chat' => [
            'requests' => 0, 'answered' => 0, 'refused' => 0, 'tokens_in' => 0, 'tokens_out' => 0,
            'errors' => ['disabled' => 0, 'not_configured' => 0, 'bad_request' => 0, 'rate_limited_ip' => 0, 'daily_limit' => 0,
                'upstream_busy' => 0, 'upstream_error' => 0, 'upstream_unreachable' => 0, 'empty_reply' => 0],
        ],
        'subscribe' => ['ok' => 0, 'invalid' => 0, 'rate_limited' => 0, 'bot_dropped' => 0, 'closed' => 0],
    ];
}

/** $delta: dotted paths => increments, e.g. ['chat.requests' => 1, 'chat.errors.bad_request' => 1]. Never throws. */
function usage_add(array $delta, ?string $day = null): void
{
    $day ??= local_day();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
        return;
    }
    try {
        json_update(data_path('usage', $day . '.json'), function ($d) use ($delta, $day) {
            $d = array_replace_recursive(usage_empty($day), is_array($d) ? $d : []);
            foreach ($delta as $path => $inc) {
                if (!preg_match('/^[a-z_]+(\.[a-z_]+){1,2}$/', $path)) {
                    continue;
                }
                $ref = &$d;
                foreach (explode('.', $path) as $part) {
                    if (!isset($ref[$part])) {
                        $ref[$part] = 0;
                    }
                    $ref = &$ref[$part];
                }
                $ref = (int) $ref + (int) $inc;
                unset($ref);
            }
            return $d;
        }, null);
        if (mt_rand(1, 100) === 1) {
            usage_gc();
        }
    } catch (Throwable $e) {
        error_log('usage_add: ' . get_class($e));
    }
}

function usage_gc(): void
{
    $cut = local_day(time() - 90 * 86400);
    foreach (glob(DATA_DIR . '/usage/*.json') ?: [] as $f) {
        if (strcmp(basename($f, '.json'), $cut) < 0) {
            @unlink($f);
        }
    }
}

/** Newest first: the last $days Beijing days, missing days filled with zeros. */
function usage_range(int $days = 30): array
{
    $out = [];
    for ($i = 0; $i < $days; $i++) {
        $day = local_day(time() - $i * 86400);
        $d = json_read(data_path('usage', $day . '.json'));
        $out[] = array_replace_recursive(usage_empty($day), is_array($d) ? $d : []);
    }
    return $out;
}
