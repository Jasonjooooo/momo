<?php
// AI 超市 shelves (SPEC §5.3, §8).
declare(strict_types=1);
defined('BLOG_APP') || exit;

const CN_ACCESS = ['能直接访问', '需要国际网络'];
const SHELF_ID_RE = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

function shelf_url_ok(mixed $url): bool
{
    if (!is_string($url) || strlen($url) > 300 || preg_match('/[\s"\'<>\\\\`]/', $url)) {
        return false;
    }
    $p = parse_url($url);
    return is_array($p) && in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)
        && preg_match('/^[A-Za-z0-9.-]+$/', (string) ($p['host'] ?? '')) && !isset($p['user']);
}

/** path => Chinese message. */
function shelf_validate(array $shelves, bool $forPublish): array
{
    $e = [];
    if (!array_is_list($shelves) || count($shelves) > 8) {
        return ['shelves' => '货架最多 8 个。'];
    }
    if ($forPublish && count($shelves) < 1) {
        return ['shelves' => '至少要有 1 个货架才能发布。'];
    }
    $ids = [];
    foreach ($shelves as $i => $s) {
        if (!is_array($s)) {
            $e["shelves.$i"] = '这个货架的数据坏了。';
            continue;
        }
        $id = $s['id'] ?? '';
        if (!is_string($id) || strlen($id) > 32 || !preg_match(SHELF_ID_RE, $id)) {
            $e["shelves.$i.id"] = '货架代号只能用小写字母、数字和短横线。';
        } elseif (in_array($id, $ids, true)) {
            $e["shelves.$i.id"] = '两个货架的代号重复了。';
        }
        $ids[] = $id;
        foreach (['title' => 12, 'blurb' => 60] as $k => $max) {
            $p = text_problem($s[$k] ?? null, $max);
            if ($p !== null) {
                $e["shelves.$i.$k"] = $p;
            }
        }
        $tools = $s['tools'] ?? null;
        if (!is_array($tools) || !array_is_list($tools) || count($tools) > 16) {
            $e["shelves.$i.tools"] = '每个货架最多 16 个工具。';
            continue;
        }
        if ($forPublish && count($tools) < 1) {
            $e["shelves.$i.tools"] = '这个货架还是空的，至少放 1 个工具才能发布。';
        }
        foreach ($tools as $j => $t) {
            if (!is_array($t)) {
                $e["shelves.$i.tools.$j"] = '这个工具的数据坏了。';
                continue;
            }
            foreach (['name' => [30, true], 'desc' => [40, true], 'note' => [120, false]] as $k => [$max, $req]) {
                $p = text_problem($t[$k] ?? '', $max, $req);
                if ($p !== null) {
                    $e["shelves.$i.tools.$j.$k"] = $p;
                }
            }
            if (!shelf_url_ok($t['url'] ?? null)) {
                $e["shelves.$i.tools.$j.url"] = '网址要以 http:// 或 https:// 开头，比如 https://www.example.com。';
            }
            $cn = $t['cn_access'] ?? null;
            if ($cn !== null && !in_array($cn, CN_ACCESS, true)) {
                $e["shelves.$i.tools.$j.cn_access"] = '请选：能直接访问、需要国际网络，或者还没确认。';
            }
        }
    }
    return $e;
}

function shelf_normalize(array $input): array
{
    $line = fn($v) => is_string($v) ? trim((string) preg_replace('/\s+/u', ' ', $v)) : '';
    $shelves = [];
    foreach ((is_array($input['shelves'] ?? null) ? $input['shelves'] : []) as $s) {
        if (!is_array($s)) {
            continue;
        }
        $id = is_string($s['id'] ?? null) && $s['id'] !== '' ? strtolower(trim($s['id'])) : 's' . bin2hex(random_bytes(3));
        $tools = [];
        foreach ((is_array($s['tools'] ?? null) ? $s['tools'] : []) as $t) {
            if (!is_array($t)) {
                continue;
            }
            $cn = $t['cn_access'] ?? null;
            $tools[] = [
                'name' => $line($t['name'] ?? ''),
                'desc' => $line($t['desc'] ?? ''),
                'url' => is_string($t['url'] ?? null) ? trim($t['url']) : '',
                'cn_access' => in_array($cn, CN_ACCESS, true) ? $cn : ($cn === null || $cn === '' || $cn === '还没确认' ? null : $cn),
                'note' => $line($t['note'] ?? ''),
            ];
        }
        $shelves[] = ['id' => $id, 'title' => $line($s['title'] ?? ''), 'blurb' => $line($s['blurb'] ?? ''), 'tools' => $tools];
    }
    $out = $input;
    $out['shelves'] = $shelves;
    return $out;
}
