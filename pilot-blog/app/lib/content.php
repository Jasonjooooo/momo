<?php
// Draft and live copies of the two site-wide units (SPEC §1, §5.1, §5.3, §6.2): 网站文字 (site) and AI 超市 (shelf).
// Saving a draft never changes the public site; publishing copies draft -> live and regenerates the pages.
declare(strict_types=1);
defined('BLOG_APP') || exit;

const CONTENT_UNITS = ['site' => '更新网站文字', 'shelf' => '更新 AI 超市'];

function content_path(string $unit, string $which): string
{
    if (!isset(CONTENT_UNITS[$unit]) || !in_array($which, ['draft', 'live'], true)) {
        throw new InvalidArgumentException('bad content unit');
    }
    return data_path('content', $unit . '.' . $which . '.json');
}

function content_get(string $unit, string $which = 'draft'): array
{
    $d = json_read(content_path($unit, $which), []);
    return is_array($d) ? $d : [];
}

/** Normalize + validate one unit; returns [doc, errors]. */
function content_check(string $unit, array $input, bool $forPublish): array
{
    if ($unit === 'site') {
        $doc = site_normalize($input);
        return [$doc, site_validate($doc)];
    }
    $doc = shelf_normalize($input);
    return [$doc, shelf_validate($doc['shelves'], $forPublish)];
}

/** Save the editor's draft. Throws ConflictException (stale rev) or ValidationError. Returns the saved draft. */
function content_save_draft(string $unit, array $input, int $rev): array
{
    return with_lock('content-' . $unit, function () use ($unit, $input, $rev) {
        $cur = content_get($unit, 'draft');
        [$doc, $err] = content_check($unit, $input, false);
        $keep = $unit === 'site' ? ['fields', 'faq', 'type_facts'] : ['shelves'];
        if ((int) ($cur['rev'] ?? 0) !== $rev && !$err && content_same($unit, $doc, $cur)) {
            // the same text sent again with an older rev: a retry after the answer was lost on a bad
            // mobile connection (the first try was saved). Nothing to save and nothing to warn about.
            return $cur;
        }
        require_rev($cur, $rev);
        if ($err) {
            throw new ValidationError($err);
        }
        $out = ['rev' => (int) ($cur['rev'] ?? 0) + 1, 'updated_at' => now_iso()] + array_intersect_key($doc, array_flip($keep));
        json_write_atomic(content_path($unit, 'draft'), $out);
        return $out;
    });
}

/** True when two versions of a unit hold the same content (shelf ids aside: new shelves get a random id on save). */
function content_same(string $unit, array $a, array $b): bool
{
    if ($unit === 'site') {
        foreach (['fields', 'faq', 'type_facts'] as $k) {
            if (($a[$k] ?? null) !== ($b[$k] ?? null)) {
                return false;
            }
        }
        return true;
    }
    $strip = fn(array $doc) => array_map(fn($s) => is_array($s) ? array_diff_key($s, ['id' => 1]) : $s, $doc['shelves'] ?? []);
    return $strip($a) === $strip($b);
}

/** Publish the saved draft (the editor saves first). Returns publish_site()'s result; live is restored on failure. */
function content_publish(string $unit, int $rev): array
{
    return with_lock('publish', function () use ($unit, $rev) {
        return with_lock('content-' . $unit, function () use ($unit, $rev) {
            $draft = content_get($unit, 'draft');
            require_rev($draft, $rev);
            [, $err] = content_check($unit, $draft, true);
            if ($err) {
                throw new ValidationError($err);
            }
            $oldLive = content_get($unit, 'live');
            $live = $draft;
            $live['published_at'] = now_iso();
            $live['from_rev'] = (int) $draft['rev'];
            json_write_atomic(content_path($unit, 'live'), $live);
            $res = publish_site(CONTENT_UNITS[$unit]);
            if (!$res['ok']) {
                json_write_atomic(content_path($unit, 'live'), $oldLive);
            }
            return $res;
        });
    });
}

/** 放弃草稿：the draft becomes a copy of the live version again. */
function content_discard(string $unit): array
{
    return with_lock('content-' . $unit, function () use ($unit) {
        $cur = content_get($unit, 'draft');
        $live = content_get($unit, 'live');
        unset($live['published_at'], $live['from_rev']);
        $live['rev'] = (int) ($cur['rev'] ?? 0) + 1;
        $live['updated_at'] = now_iso();
        json_write_atomic(content_path($unit, 'draft'), $live);
        return $live;
    });
}

/** Fields that differ between draft and live (for the 改过 badges). */
function content_changed_fields(): array
{
    $d = content_get('site', 'draft')['fields'] ?? [];
    $l = content_get('site', 'live')['fields'] ?? [];
    return array_keys(array_filter($d, fn($v, $k) => ($l[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));
}

/** Dashboard one-tap: set site.origin in draft and live, republish without a version entry. */
function site_origin_set(string $origin): array
{
    $origin = rtrim(trim($origin), '/');
    $p = field_problem('site.origin', $origin);
    if ($p !== null) {
        throw new ValidationError(['fields.site.origin' => $p]);
    }
    return with_lock('publish', function () use ($origin) {
        foreach (['draft', 'live'] as $which) {
            with_lock('content-site', function () use ($which, $origin) {
                $doc = content_get('site', $which);
                $doc['fields']['site.origin'] = $origin;
                if ($which === 'draft') {
                    $doc['rev'] = (int) ($doc['rev'] ?? 0) + 1;
                    $doc['updated_at'] = now_iso();
                }
                json_write_atomic(content_path('site', $which), $doc);
            });
        }
        return publish_site('设置网站地址', ['version' => false]);
    });
}
