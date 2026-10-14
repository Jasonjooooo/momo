<?php
// Markdown subset for flight-log posts (SPEC §7.2). Own parser, no raw HTML: every text token is escaped.
declare(strict_types=1);
defined('BLOG_APP') || exit;

const MD_MAX = 60000;
const UPLOAD_PATH_RE = '/^uploads\/\d{4}\/\d{2}\/[0-9a-f]{16}\.(webp|jpg)$/';
const SLUG_RE = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

function md_clean(string $src): string
{
    if (!mb_check_encoding($src, 'UTF-8')) {
        $src = mb_convert_encoding($src, 'UTF-8', 'UTF-8');
    }
    $src = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $src);
    $src = (string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F\x{2028}\x{2029}]/u', '', $src);
    return mb_substr($src, 0, MD_MAX);
}

/** Split into blocks: [type, payload]; types h2 h3 ul ol note hr img p. */
function md_blocks(string $src): array
{
    $blocks = [];
    $group = null;   // ['ul'|'ol'|'note', [lines]]
    $flush = function () use (&$group, &$blocks): void {
        if ($group !== null) {
            $blocks[] = $group;
            $group = null;
        }
    };
    foreach (explode("\n", md_clean($src)) as $line) {
        $line = rtrim($line);
        $t = ltrim($line);
        if ($t === '') {
            $flush();
            continue;
        }
        if (preg_match('/^-{3,}$/', $t)) {
            $flush();
            $blocks[] = ['hr', null];
        } elseif (preg_match('/^#{1,2}\s+(.+)$/u', $t, $m)) {
            $flush();
            $blocks[] = ['h2', $m[1]];
        } elseif (preg_match('/^#{3,6}\s+(.+)$/u', $t, $m)) {
            $flush();
            $blocks[] = ['h3', $m[1]];
        } elseif (preg_match('/^[-*]\s+(.+)$/u', $t, $m)) {
            if ($group === null || $group[0] !== 'ul') {
                $flush();
                $group = ['ul', []];
            }
            $group[1][] = $m[1];
        } elseif (preg_match('/^\d{1,4}\.\s+(.+)$/u', $t, $m)) {
            if ($group === null || $group[0] !== 'ol') {
                $flush();
                $group = ['ol', []];
            }
            $group[1][] = $m[1];
        } elseif (preg_match('/^>\s?(.*)$/u', $t, $m)) {
            if ($group === null || $group[0] !== 'note') {
                $flush();
                $group = ['note', []];
            }
            if (trim($m[1]) !== '') {
                $group[1][] = $m[1];
            }
        } elseif (preg_match('/^!\[([^\]\n]{0,300})\]\(([^)\s]{1,200})(?:\s+"([^"\n]{0,300})")?\)$/u', $t, $m)) {
            $flush();
            $blocks[] = ['img', ['alt' => $m[1], 'src' => $m[2], 'caption' => $m[3] ?? '', 'raw' => $t]];
        } else {
            $flush();
            $blocks[] = ['p', $t];
        }
    }
    $flush();
    return $blocks;
}

/** $opt: base (prefix for site links and uploads, e.g. '../'), uploads (uploads index items; null = read blog-data). */
function md_render(string $src, array $opt = []): string
{
    $base = (string) ($opt['base'] ?? '');
    $uploads = $opt['uploads'] ?? null;
    if ($uploads === null) {
        $uploads = uploads_index()['items'] ?? [];
    }
    $out = [];
    foreach (md_blocks($src) as [$type, $p]) {
        switch ($type) {
            case 'hr':
                $out[] = '<hr>';
                break;
            case 'h2':
            case 'h3':
                $out[] = '<' . $type . '>' . md_inline($p, $base) . '</' . $type . '>';
                break;
            case 'ul':
            case 'ol':
                $out[] = '<' . $type . '>' . implode('', array_map(fn($l) => '<li>' . md_inline($l, $base) . '</li>', $p)) . '</' . $type . '>';
                break;
            case 'note':
                if ($p) {
                    $out[] = '<aside class="note"><p class="note-k">NOTE</p>' . implode('', array_map(fn($l) => '<p>' . md_inline($l, $base) . '</p>', $p)) . '</aside>';
                }
                break;
            case 'img':
                $info = preg_match(UPLOAD_PATH_RE, $p['src']) ? ($uploads[$p['src']] ?? null) : null;
                if ($info === null) {
                    $out[] = '<p>' . enc_text($p['raw']) . '</p>';
                    break;
                }
                $alt = md_unescape($p['alt']);
                $cap = $p['caption'] !== '' ? '<figcaption>' . enc_text($p['caption']) . '</figcaption>' : '';
                $out[] = '<figure><img src="' . enc_attr($base . $p['src']) . '" width="' . (int) $info['w'] . '" height="' . (int) $info['h'] . '" alt="' . enc_attr($alt) . '" loading="lazy" decoding="async">' . $cap . '</figure>';
                break;
            default:
                $out[] = '<p>' . md_inline($p, $base) . '</p>';
        }
    }
    return implode("\n", $out);
}

function md_unescape(string $s): string
{
    return (string) preg_replace('/\\\\([*\[\]`\\\\])/', '$1', $s);
}

/** [href, external] or null when the URL is not allowed. */
function md_link_href(string $url, string $base): ?array
{
    if (preg_match('#^https?://#i', $url)) {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        if ($parts === false || $host === '' || isset($parts['user']) || isset($parts['pass'])
            || !preg_match('/^[A-Za-z0-9.-]+$/', $host) || preg_match('/["\'<>\\\\`]/', $url)) {
            return null;
        }
        return [$url, true];
    }
    if (preg_match('/^posts\/([a-z0-9]+(?:-[a-z0-9]+)*)\.html$/', $url) || $url === 'posts/') {
        return [$base . $url, false];
    }
    if (preg_match('/^#[a-z0-9-]+$/', $url)) {
        return [$base . $url, false];
    }
    return null;
}

function md_inline(string $s, string $base, bool $inBold = false): string
{
    $out = '';
    $buf = '';
    $n = strlen($s);
    $i = 0;
    while ($i < $n) {
        $c = $s[$i];
        if ($c === '\\' && $i + 1 < $n && str_contains('*[]`\\', $s[$i + 1])) {
            $buf .= $s[$i + 1];
            $i += 2;
            continue;
        }
        if ($c === '`') {
            $j = strpos($s, '`', $i + 1);
            if ($j !== false && $j > $i + 1) {
                $out .= enc_text($buf) . '<code>' . enc_text(substr($s, $i + 1, $j - $i - 1)) . '</code>';
                $buf = '';
                $i = $j + 1;
                continue;
            }
        }
        if (!$inBold && $c === '*' && ($s[$i + 1] ?? '') === '*') {
            $j = strpos($s, '**', $i + 2);
            if ($j !== false && $j > $i + 2) {
                $out .= enc_text($buf) . '<strong>' . md_inline(substr($s, $i + 2, $j - $i - 2), $base, true) . '</strong>';
                $buf = '';
                $i = $j + 2;
                continue;
            }
        }
        if ($c === '[' && preg_match('/\G\[([^\]\n]{1,300})\]\(([^)\s]{1,500})\)/', $s, $m, 0, $i)) {
            $href = md_link_href($m[2], $base);
            if ($href !== null) {
                $attrs = $href[1] ? ' rel="noopener noreferrer" target="_blank"' : '';
                $out .= enc_text($buf) . '<a href="' . enc_attr($href[0]) . '"' . $attrs . '>' . enc_text(md_unescape($m[1])) . '</a>';
                $buf = '';
            } else {
                $buf .= $m[0];   // not an allowed link: shown as typed, inert
            }
            $i += strlen($m[0]);
            continue;
        }
        $buf .= $c;
        $i++;
    }
    return $out . enc_text($buf);
}

function md_inline_plain(string $s): string
{
    $s = (string) preg_replace('/\[([^\]\n]{1,300})\]\(([^)\s]{1,500})\)/', '$1', $s);
    $s = str_replace(['**', '`'], '', $s);
    return md_unescape($s);
}

/** Text only (no markup), one line per block. */
function md_plain(string $src): string
{
    $lines = [];
    foreach (md_blocks($src) as [$type, $p]) {
        if ($type === 'hr' || $type === 'img') {
            continue;
        }
        foreach ((array) $p as $l) {
            $lines[] = md_inline_plain((string) $l);
        }
    }
    return implode("\n", $lines);
}

function md_excerpt(string $src, int $max = 80): string
{
    $t = trim((string) preg_replace('/\s+/u', ' ', md_plain($src)));
    if (mb_strlen($t) <= $max) {
        return $t;
    }
    return rtrim(mb_substr($t, 0, $max - 1)) . '…';
}

function md_reading_minutes(string $src): int
{
    $plain = md_plain($src);
    $han = preg_match_all('/\p{Han}/u', $plain);
    $words = preg_match_all('/[A-Za-z0-9]+(?:[\'’.-][A-Za-z0-9]+)*/u', $plain);
    return max(1, (int) round($han / 400 + $words / 200));
}
