<?php
// Flight-log posts: storage, validation, lifecycle (SPEC §5.4, §7.1) and the home legs list (§7.4).
declare(strict_types=1);
defined('BLOG_APP') || exit;

const RESERVED_FIXES = ['DEP', 'PILOT', 'LOGBK', 'PC12X', 'AISHP', 'CHKLT', 'FINAL', 'RW24'];
const POST_ID_RE = '/^p[0-9a-f]{12}$/';
const POST_CONTENT_KEYS = ['title', 'excerpt', 'tags', 'body', 'cover', 'date', 'minutes'];

function post_path(string $id): string
{
    if (!preg_match(POST_ID_RE, $id)) {
        throw new InvalidArgumentException('bad post id');
    }
    return data_path('posts', $id . '.json');
}

function posts_all(): array
{
    $out = [];
    foreach (glob(DATA_DIR . '/posts/p*.json') ?: [] as $f) {
        if (preg_match('/^(p[0-9a-f]{12})\.json$/', basename($f))) {
            $p = json_read($f);
            if (is_array($p) && isset($p['id'])) {
                $out[] = $p;
            }
        }
    }
    usort($out, fn($a, $b) => [$b['created_at'] ?? '', $b['id']] <=> [$a['created_at'] ?? '', $a['id']]);
    return $out;
}

function post_get(string $id): ?array
{
    if (!preg_match(POST_ID_RE, $id)) {
        return null;
    }
    $p = json_read(post_path($id));
    return is_array($p) ? $p : null;
}

function post_status(array $post): string
{
    if (($post['live'] ?? null) === null) {
        return 'draft';
    }
    return ($post['live']['mode'] ?? '') === 'full' ? 'published' : 'teaser';
}

function post_live_fix(array $post): string
{
    return (string) ($post['live']['fix'] ?? $post['fix']);
}

function post_dirty(array $post): bool
{
    $live = $post['live'] ?? null;
    if ($live === null) {
        return false;
    }
    foreach (POST_CONTENT_KEYS as $k) {
        if (($post['draft'][$k] ?? null) != ($live[$k] ?? null)) {
            return true;
        }
    }
    return post_live_fix($post) !== $post['fix'];
}

function post_empty_content(): array
{
    return ['title' => '', 'excerpt' => '', 'tags' => [], 'body' => '', 'cover' => null, 'date' => null, 'minutes' => null];
}

/** Clean editor input into the stored draft shape. */
function post_normalize_content(array $in): array
{
    $line = fn($v) => is_string($v) ? trim((string) preg_replace('/\s+/u', ' ', $v)) : '';
    $tags = $in['tags'] ?? [];
    if (is_string($tags)) {
        $tags = preg_split('/[\s,，、]+/u', $tags) ?: [];
    }
    $clean = [];
    foreach ((array) $tags as $t) {
        $t = $line($t);
        if ($t !== '' && !in_array($t, $clean, true)) {
            $clean[] = $t;
        }
    }
    $cover = $in['cover'] ?? null;
    if (is_array($cover) && is_string($cover['src'] ?? null) && $cover['src'] !== '') {
        $cover = [
            'src' => (string) $cover['src'],
            'og' => is_string($cover['og'] ?? null) ? $cover['og'] : '',
            'w' => (int) ($cover['w'] ?? 0),
            'h' => (int) ($cover['h'] ?? 0),
            'alt' => $line($cover['alt'] ?? ''),
        ];
    } else {
        $cover = null;
    }
    $date = $line($in['date'] ?? '');
    $minutes = $in['minutes'] ?? null;
    $minutes = ($minutes === null || $minutes === '') ? null : (is_numeric($minutes) ? (int) $minutes : -1);
    return [
        'title' => $line($in['title'] ?? ''),
        'excerpt' => $line($in['excerpt'] ?? ''),
        'tags' => $clean,
        'body' => is_string($in['body'] ?? null) ? rtrim(str_replace(["\r\n", "\r"], "\n", $in['body'])) : '',
        'cover' => $cover,
        'date' => $date === '' ? null : $date,
        'minutes' => $minutes,
    ];
}

function date_ok(?string $d): bool
{
    if ($d === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) {
        return false;
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

/** path => Chinese message. $mode: 'draft' | 'teaser' | 'full'. */
function post_validate(array $post, string $mode, ?array $all = null): array
{
    $e = [];
    $d = $post['draft'] ?? [];
    $all ??= posts_all();
    $title = (string) ($d['title'] ?? '');
    if (mb_strlen($title) > 60) {
        $e['title'] = '标题最多 60 个字。';
    } elseif ($mode !== 'draft' && $title === '') {
        $e['title'] = '标题不能为空。';
    }
    $excerpt = (string) ($d['excerpt'] ?? '');
    if (mb_strlen($excerpt) > 160) {
        $e['excerpt'] = '摘要最多 160 个字。';
    } elseif ($mode === 'teaser' && $excerpt === '') {
        $e['excerpt'] = '设为预告需要写摘要，首页会显示它。';
    }
    foreach (['title' => $title, 'excerpt' => $excerpt] as $k => $v) {
        if (!isset($e[$k]) && preg_match('/[\x00-\x1F\x7F\x{2028}\x{2029}]/u', $v)) {
            $e[$k] = '这一项里有看不见的特殊字符，请删掉后再保存。';
        }
    }
    $tags = $d['tags'] ?? [];
    if (!is_array($tags) || count($tags) > 5) {
        $e['tags'] = '标签最多 5 个。';
    } else {
        foreach ($tags as $t) {
            if (!is_string($t) || $t === '' || mb_strlen($t) > 16 || preg_match('/[<>{}|\x00-\x1F]/u', $t)) {
                $e['tags'] = '每个标签 1 到 16 个字，不能有 < > { } |。';
                break;
            }
        }
    }
    $body = (string) ($d['body'] ?? '');
    if (mb_strlen($body) > MD_MAX) {
        $e['body'] = '正文最多 ' . MD_MAX . ' 个字。';
    } elseif ($mode === 'full' && trim(md_plain($body)) === '' && !preg_match('/!\[/', $body)) {
        $e['body'] = '正文不能为空。';
    }
    $date = $d['date'] ?? null;
    if ($date !== null && !date_ok($date)) {
        $e['date'] = '日期要写成 2026-10-14 这样。';
    } elseif ($mode === 'full' && $date === null) {
        $e['date'] = '请填日期。';
    }
    $min = $d['minutes'] ?? null;
    if ($min !== null && (!is_int($min) || $min < 1 || $min > 120)) {
        $e['minutes'] = '阅读时间填 1 到 120 之间的分钟数，或者留空自动算。';
    } elseif ($mode === 'teaser' && $min === null) {
        $e['minutes'] = '设为预告需要填预计阅读时间。';
    }
    $cover = $d['cover'] ?? null;
    if ($cover !== null) {
        if (!is_array($cover) || !preg_match(UPLOAD_PATH_RE, (string) ($cover['src'] ?? ''))
            || !preg_match('/^uploads\/\d{4}\/\d{2}\/[0-9a-f]{16}\.jpg$/', (string) ($cover['og'] ?? ''))
            || (int) ($cover['w'] ?? 0) < 1 || (int) ($cover['h'] ?? 0) < 1) {
            $e['cover'] = '封面图的数据不对，请重新上传。';
        } elseif (mb_strlen((string) ($cover['alt'] ?? '')) > 120) {
            $e['cover'] = '图片说明最多 120 个字。';
        }
    }
    $fix = (string) ($post['fix'] ?? '');
    if (!preg_match('/^[A-Z]{5}$/', $fix)) {
        $e['fix'] = '航路点代号是 5 个大写英文字母。';
    } elseif (in_array($fix, RESERVED_FIXES, true)) {
        $e['fix'] = '这个代号航路图上已经用了，换一个。';
    }
    $slug = (string) ($post['slug'] ?? '');
    if (strlen($slug) > 60 || !preg_match(SLUG_RE, $slug)) {
        $e['slug'] = '网址只能用小写英文字母、数字和短横线，最多 60 个，例如 low-cloud。';
    } elseif ($slug === 'index') {
        $e['slug'] = '这个网址留给全部日志页了，换一个。';
    }
    foreach ($all as $o) {
        if (($o['id'] ?? '') === ($post['id'] ?? '')) {
            continue;
        }
        if (!isset($e['fix']) && ($o['fix'] === $fix || post_live_fix($o) === $fix)) {
            $e['fix'] = '另一篇日志已经用了这个代号。';
        }
        if (!isset($e['slug']) && $o['slug'] === $slug) {
            $e['slug'] = '另一篇日志已经用了这个网址。';
        }
    }
    return $e;
}

function fix_suggest(string $slug, array $taken): string
{
    $letters = strtoupper((string) preg_replace('/[^a-z]/', '', strtolower($slug)));
    $fix = str_pad(substr($letters, 0, 5), 5, 'X');
    if ($letters !== '' && !in_array($fix, $taken, true) && !in_array($fix, RESERVED_FIXES, true)) {
        return $fix;
    }
    return fix_random($taken);
}

/** Pronounceable 5-letter ident (consonant-vowel pattern), not taken. */
function fix_random(array $taken): string
{
    $c = 'BDFGKLMNPRSTVZ';
    $v = 'AEIOU';
    for ($i = 0; $i < 200; $i++) {
        $f = $c[random_int(0, 13)] . $v[random_int(0, 4)] . $c[random_int(0, 13)] . $v[random_int(0, 4)] . $c[random_int(0, 13)];
        if (!in_array($f, $taken, true) && !in_array($f, RESERVED_FIXES, true)) {
            return $f;
        }
    }
    throw new RuntimeException('no free fix');
}

function posts_taken_fixes(array $all, string $exceptId = ''): array
{
    $t = [];
    foreach ($all as $p) {
        if ($p['id'] !== $exceptId) {
            $t[] = $p['fix'];
            $t[] = post_live_fix($p);
        }
    }
    return $t;
}

function post_create(): array
{
    return with_lock('posts', function () {
        $all = posts_all();
        do {
            $id = 'p' . bin2hex(random_bytes(6));
        } while (is_file(post_path($id)));
        $fix = fix_random(posts_taken_fixes($all));
        $slug = strtolower($fix);
        $slugs = array_column($all, 'slug');
        for ($n = 2; in_array($slug, $slugs, true); $n++) {
            $slug = strtolower($fix) . '-' . $n;
        }
        $now = now_iso();
        $post = [
            'id' => $id, 'rev' => 1, 'slug' => $slug, 'fix' => $fix,
            'created_at' => $now, 'updated_at' => $now, 'first_published_at' => null, 'teaser_order' => null,
            'draft' => post_empty_content(), 'live' => null,
        ];
        json_write_atomic(post_path($id), $post);
        return $post;
    });
}

/** Apply editor input (content keys plus optional slug/fix) to a post; throws ValidationError. */
function post_apply_input(array $post, array $input): array
{
    $post['draft'] = post_normalize_content(array_merge($post['draft'] ?? [], $input));
    if (array_key_exists('fix', $input)) {
        $post['fix'] = strtoupper(trim((string) $input['fix']));
    }
    if (array_key_exists('slug', $input)) {
        $slug = strtolower(trim((string) $input['slug']));
        if ($post['first_published_at'] !== null && $slug !== $post['slug']) {
            throw new ValidationError(['slug' => '已经发布过，网址不能再改，否则旧链接会失效。']);
        }
        $post['slug'] = $slug;
    }
    return $post;
}

function post_save_draft(string $id, array $draft, int $rev): array
{
    return with_lock('posts', function () use ($id, $draft, $rev) {
        $post = post_get($id) ?? throw new InvalidArgumentException('no such post');
        require_rev($post, $rev);
        $post = post_apply_input($post, $draft);
        $err = post_validate($post, 'draft');
        if ($err) {
            throw new ValidationError($err);
        }
        $post['rev']++;
        $post['updated_at'] = now_iso();
        json_write_atomic(post_path($id), $post);
        return $post;
    });
}

/** Change a post's live state and republish; the post file is restored if publishing fails. */
function post_go_live(string $id, int $rev, callable $change, callable $reason): array
{
    return with_lock('publish', function () use ($id, $rev, $change, $reason) {
        return with_lock('posts', function () use ($id, $rev, $change, $reason) {
            $old = post_get($id) ?? throw new InvalidArgumentException('no such post');
            require_rev($old, $rev);
            $post = $change($old);
            $post['rev']++;
            $post['updated_at'] = now_iso();
            json_write_atomic(post_path($id), $post);
            $res = publish_site($reason($old, $post));
            if (!$res['ok']) {
                json_write_atomic(post_path($id), $old);
                throw new ValidationError($res['errors'] ?? ['publish' => '发布失败。'], '发布失败：' . implode('；', $res['errors'] ?? []) . '。网站没有变化。');
            }
            return ['post' => $post, 'publish' => $res];
        });
    });
}

function post_live_from_draft(array $post, string $mode): array
{
    return ['mode' => $mode, 'published_at' => now_iso(), 'fix' => $post['fix']] + array_intersect_key($post['draft'], array_flip(POST_CONTENT_KEYS));
}

function post_publish(string $id, int $rev): array
{
    return post_go_live($id, $rev, function (array $post) {
        if (($post['draft']['date'] ?? null) === null) {
            $post['draft']['date'] = local_day();
        }
        $err = post_validate($post, 'full');
        if ($err) {
            throw new ValidationError($err);
        }
        $post['live'] = post_live_from_draft($post, 'full');
        $post['first_published_at'] ??= $post['live']['published_at'];
        return $post;
    }, fn($old, $new) => (post_status($old) === 'published' ? '更新日志《' : '发布日志《') . $new['draft']['title'] . '》');
}

function post_set_teaser(string $id, int $rev): array
{
    return post_go_live($id, $rev, function (array $post) {
        $err = post_validate($post, 'teaser');
        if ($err) {
            throw new ValidationError($err);
        }
        if (post_status($post) !== 'teaser' || $post['teaser_order'] === null) {
            $max = 0;
            foreach (posts_all() as $o) {
                if ($o['id'] !== $post['id'] && post_status($o) === 'teaser') {
                    $max = max($max, (int) $o['teaser_order']);
                }
            }
            $post['teaser_order'] = $max + 1;
        }
        $post['live'] = post_live_from_draft($post, 'teaser');
        return $post;
    }, fn($old, $new) => '预告《' . $new['draft']['title'] . '》');
}

function post_unpublish(string $id, int $rev): array
{
    return post_go_live($id, $rev, function (array $post) {
        $post['live'] = null;
        return $post;
    }, fn($old, $new) => '撤下日志《' . ($old['live']['title'] ?? $old['draft']['title']) . '》');
}

function post_delete(string $id): void
{
    with_lock('publish', function () use ($id) {
        with_lock('posts', function () use ($id) {
            $post = post_get($id) ?? throw new InvalidArgumentException('no such post');
            $trash = data_path('trash', 'posts', $id . '-' . gmdate('YmdHis') . '.json');
            json_write_atomic($trash, $post);
            @unlink(post_path($id));
            if ($post['live'] !== null) {
                $res = publish_site('删除日志《' . ($post['live']['title'] ?? $post['draft']['title']) . '》');
                if (!$res['ok']) {
                    json_write_atomic(post_path($id), $post);
                    @unlink($trash);
                    throw new ValidationError($res['errors'] ?? [], '删除失败：网站没有变化。');
                }
            }
        });
    });
    trash_purge();
}

function trash_purge(): void
{
    $cut = time() - 30 * 86400;
    foreach (glob(DATA_DIR . '/trash/posts/*.json') ?: [] as $f) {
        if (@filemtime($f) < $cut) {
            @unlink($f);
        }
    }
}

/** Published posts newest first: live.date desc, then published_at desc. */
function posts_published_sorted(array $posts): array
{
    $pub = array_values(array_filter($posts, fn($p) => post_status($p) === 'published'));
    usort($pub, fn($a, $b) => [$b['live']['date'] ?? '', $b['live']['published_at'] ?? ''] <=> [$a['live']['date'] ?? '', $a['live']['published_at'] ?? '']);
    return $pub;
}

function post_minutes_live(array $post): int
{
    $l = $post['live'];
    if (($l['minutes'] ?? null) !== null) {
        return (int) $l['minutes'];
    }
    return md_reading_minutes((string) ($l['body'] ?? ''));
}

function post_excerpt_live(array $post): string
{
    $l = $post['live'];
    return ($l['excerpt'] ?? '') !== '' ? (string) $l['excerpt'] : md_excerpt((string) ($l['body'] ?? ''), 80);
}

function home_legs(array $posts, int $limit): array
{
    $pub = posts_published_sorted($posts);
    $teasers = array_values(array_filter($posts, fn($p) => post_status($p) === 'teaser'));
    usort($teasers, fn($a, $b) => [(int) ($a['teaser_order'] ?? 0), $a['id']] <=> [(int) ($b['teaser_order'] ?? 0), $b['id']]);
    $list = array_slice(array_merge($pub, $teasers), 0, max(0, $limit));
    $legs = [];
    $p = 0;
    $t = 0;
    foreach ($list as $post) {
        $full = post_status($post) === 'full' || post_status($post) === 'published';
        $full ? $p++ : $t++;
        $l = $post['live'];
        $legs[] = [
            'id' => $post['id'],
            'fix' => post_live_fix($post),
            'title' => (string) $l['title'],
            'excerpt' => $full ? post_excerpt_live($post) : (string) $l['excerpt'],
            'tag' => $l['tags'][0] ?? null,
            'mode' => $full ? 'full' : 'teaser',
            'minutes' => $full ? post_minutes_live($post) : (int) $l['minutes'],
            'date' => $l['date'] ?? null,
            'slug' => $post['slug'],
        ];
    }
    return ['legs' => $legs, 'p' => $p, 't' => $t, 'published_total' => count($pub)];
}
