<?php
// Template engine: comment markers (<!--{t KEY|ENC}-->default<!--{/t}--> and friends, SPEC §4) and the field encodings.
declare(strict_types=1);
defined('BLOG_APP') || exit;

final class TemplateError extends RuntimeException {}

const TPL_ENCS = ['text', 'attr', 'js', 'wbr', 'lat', 'li'];
const TPL_VALUES = ['chat_live', 'subscribe_endpoint', 'log_count', 'radio_act', 'radio_sby',
    'post_title', 'post_title_attr', 'post_excerpt', 'post_excerpt_attr', 'post_date_iso', 'post_date_dots', 'post_fix',
    'post_minutes', 'post_slug', 'post_og_image', 'post_url', 'css_href', 'archive_count', 'hit_path',
    'shop_title', 'shop_title_attr', 'shop_desc_attr', 'shop_css_href', 'shop_og_image', 'shop_url'];
const TPL_BLOCKS = ['legs', 'faq_rows', 'type_facts', 'shelf_json', 'extra_css', 'about_album',
    'post_cover', 'post_body', 'post_tags', 'post_prevnext', 'post_tag_first', 'archive_list', 'shop_status', 'shop_grid', 'shop_main'];
const TPL_CONDS = ['subscribe_live', 'has_teasers', 'has_published', 'origin_placeholder', 'has_cover', 'has_tags', 'has_prevnext', 'has_excerpt', 'stats_live', 'has_album', 'shop_private'];

const LAT_RE = '/[A-Za-z0-9](?:[A-Za-z0-9.×\- ]*[A-Za-z0-9])?/u';

function enc_valid(string $enc): bool
{
    return in_array($enc, TPL_ENCS, true) || preg_match('/^paras:\d{1,2}$/', $enc) === 1;
}

/** Parse a template into nodes. lit: ['lit', s]; t: ['t', key, enc, default, indent]; v: ['v', name, default];
 *  block: ['block', name, default, indent]; if: ['if', cond, neg, thenNodes, elseNodes]. */
function tpl_parse(string $src): array
{
    $n = preg_match_all('/<!--\{([^}\n]{0,80})\}-->/', $src, $m, PREG_OFFSET_CAPTURE);
    if ($n !== substr_count($src, '<!--{')) {
        throw new TemplateError('malformed marker');
    }
    $registry = field_registry();
    $root = [];
    $stack = [];            // frames: ['node' => if-node, 'branch' => 3|4]
    $cur = &$root;
    $pos = 0;
    $open = null;           // pending t / v / block: [type, name, enc, startOfDefault, indent]
    for ($i = 0; $i < $n; $i++) {
        $inner = $m[1][$i][0];
        $at = $m[0][$i][1];
        $end = $at + strlen($m[0][$i][0]);
        if ($open !== null) {
            $close = '/' . $open[0];
            if ($inner !== $close) {
                throw new TemplateError('marker inside ' . $open[0] . ' ' . $open[1] . ' or missing ' . $close);
            }
            $default = substr($src, $open[3], $at - $open[3]);
            if ($open[0] === 't') {
                $cur[] = ['t', $open[1], $open[2], $default, $open[4]];
            } elseif ($open[0] === 'v') {
                $cur[] = ['v', $open[1], $default];
            } else {
                $cur[] = ['block', $open[1], $default, $open[4]];
            }
            $open = null;
            $pos = $end;
            continue;
        }
        if ($at > $pos) {
            $cur[] = ['lit', substr($src, $pos, $at - $pos)];
        }
        $pos = $end;
        if (preg_match('/^t ([a-z0-9_]+(?:\.[a-z0-9_]+)*)(?:\|([a-z]+(?::\d+)?))?$/', $inner, $mm)) {
            $enc = $mm[2] ?? 'text';
            if ($enc === '') {
                $enc = 'text';
            }
            if (!enc_valid($enc)) {
                throw new TemplateError('unknown encoding ' . $enc);
            }
            if (!isset($registry[$mm[1]])) {
                throw new TemplateError('unknown field ' . $mm[1]);
            }
            $open = ['t', $mm[1], $enc, $end, tpl_indent($src, $at)];
        } elseif (preg_match('/^v ([a-z_]+)$/', $inner, $mm)) {
            if (!in_array($mm[1], TPL_VALUES, true)) {
                throw new TemplateError('unknown value ' . $mm[1]);
            }
            $open = ['v', $mm[1], null, $end, ''];
        } elseif (preg_match('/^block ([a-z_]+)$/', $inner, $mm)) {
            if (!in_array($mm[1], TPL_BLOCKS, true)) {
                throw new TemplateError('unknown block ' . $mm[1]);
            }
            $open = ['block', $mm[1], null, $end, tpl_indent($src, $at)];
        } elseif (preg_match('/^if (!?)([a-z_]+)$/', $inner, $mm)) {
            if ($stack) {
                throw new TemplateError('nested if');
            }
            if (!in_array($mm[2], TPL_CONDS, true)) {
                throw new TemplateError('unknown condition ' . $mm[2]);
            }
            $cur[] = ['if', $mm[2], $mm[1] === '!', [], [], false];
            $idx = count($cur) - 1;
            $stack[] = $idx;
            $cur = &$root[$idx][3];
        } elseif ($inner === 'else') {
            if (!$stack || $root[end($stack)][5]) {
                throw new TemplateError('else without if');
            }
            $root[end($stack)][5] = true;
            unset($cur);
            $cur = &$root[end($stack)][4];
        } elseif ($inner === '/if') {
            if (!$stack) {
                throw new TemplateError('/if without if');
            }
            array_pop($stack);
            unset($cur);
            $cur = &$root;
        } else {
            throw new TemplateError('bad marker <!--{' . $inner . '}-->');
        }
    }
    if ($open !== null) {
        throw new TemplateError('unclosed ' . $open[0] . ' ' . $open[1]);
    }
    if ($stack) {
        throw new TemplateError('unclosed if');
    }
    if ($pos < strlen($src)) {
        $cur[] = ['lit', substr($src, $pos)];
    }
    unset($cur);
    foreach ($root as $k => $node) {
        if ($node[0] === 'if') {
            unset($root[$k][5]);
        }
    }
    return $root;
}

/** Spaces between the previous newline and $at, when only spaces precede the marker on its line. */
function tpl_indent(string $src, int $at): string
{
    $nl = strrpos(substr($src, 0, $at), "\n");
    $start = $nl === false ? 0 : $nl + 1;
    $seg = substr($src, $start, $at - $start);
    return ($seg !== '' && trim($seg, ' ') === '') ? $seg : '';
}

/**
 * Render nodes. $ctx: fields => [key => value], values => [name => html], conds => [name => bool],
 * blocks => [name => string | callable(string $indent): string].
 */
function tpl_render(array $nodes, array $ctx): string
{
    $out = '';
    foreach ($nodes as $node) {
        switch ($node[0]) {
            case 'lit':
                $out .= $node[1];
                break;
            case 't':
                if (!array_key_exists($node[1], $ctx['fields'] ?? [])) {
                    throw new TemplateError('no value for field ' . $node[1]);
                }
                $out .= enc_render($node[2], (string) $ctx['fields'][$node[1]], $node[4]);
                break;
            case 'v':
                if (!array_key_exists($node[1], $ctx['values'] ?? [])) {
                    throw new TemplateError('no value ' . $node[1]);
                }
                $out .= (string) $ctx['values'][$node[1]];
                break;
            case 'block':
                if (!array_key_exists($node[1], $ctx['blocks'] ?? [])) {
                    throw new TemplateError('no block ' . $node[1]);
                }
                $b = $ctx['blocks'][$node[1]];
                $out .= $b instanceof Closure ? (string) $b($node[3]) : (string) $b;   // never call a string (it could name a function)
                break;
            case 'if':
                if (!array_key_exists($node[1], $ctx['conds'] ?? [])) {
                    throw new TemplateError('no condition ' . $node[1]);
                }
                $on = (bool) $ctx['conds'][$node[1]];
                if ($node[2]) {
                    $on = !$on;
                }
                $out .= tpl_render($on ? $node[3] : $node[4], $ctx);
                break;
        }
    }
    return $out;
}

/** Defaults from a parsed template: fields (decoded), blocks (raw inner + indent), values (raw). */
function tpl_extract(array $nodes): array
{
    $res = ['fields' => [], 'blocks' => [], 'values' => []];
    $walk = function (array $nodes) use (&$walk, &$res): void {
        foreach ($nodes as $node) {
            if ($node[0] === 't') {
                $val = enc_decode($node[2], $node[3], $node[4]);
                if (enc_render($node[2], $val, $node[4]) !== $node[3]) {
                    throw new TemplateError('non-canonical default for ' . $node[1]);
                }
                if (array_key_exists($node[1], $res['fields']) && $res['fields'][$node[1]] !== $val) {
                    throw new TemplateError('different defaults for ' . $node[1]);
                }
                $res['fields'][$node[1]] = $val;
            } elseif ($node[0] === 'v') {
                $res['values'][$node[1]] = $node[2];
            } elseif ($node[0] === 'block') {
                $res['blocks'][$node[1]] = [$node[2], $node[3]];
            } elseif ($node[0] === 'if') {
                $walk($node[3]);
                $walk($node[4]);
            }
        }
    };
    $walk($nodes);
    return $res;
}

/** Every [key, enc] pair used by the nodes. */
function tpl_field_uses(array $nodes): array
{
    $uses = [];
    foreach ($nodes as $node) {
        if ($node[0] === 't') {
            $uses[] = [$node[1], $node[2]];
        } elseif ($node[0] === 'if') {
            $uses = array_merge($uses, tpl_field_uses($node[3]), tpl_field_uses($node[4]));
        }
    }
    return $uses;
}

// ---------- encodings (SPEC §4.2) ----------

function enc_text(string $v): string
{
    return htmlspecialchars($v, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

function enc_attr(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

function enc_js(string $v): string
{
    return strtr($v, [
        '\\' => '\\\\', "'" => "\\'", '<' => '\\x3C', '>' => '\\x3E', "\n" => '\\n', "\r" => '\\r',
        "\u{2028}" => '\\u2028', "\u{2029}" => '\\u2029',
    ]);
}

function enc_lat(string $v): string
{
    return (string) preg_replace_callback(LAT_RE, function ($m) {
        return preg_match('/[0-9]/', $m[0]) ? '<span class="lat">' . $m[0] . '</span>' : $m[0];
    }, enc_text($v));
}

function enc_lines(string $v): array
{
    $out = [];
    foreach (explode("\n", str_replace("\r", '', $v)) as $line) {
        $line = trim($line, " \t");
        if ($line !== '') {
            $out[] = $line;
        }
    }
    return $out;
}

function enc_render(string $enc, string $value, string $indent = ''): string
{
    switch ($enc) {
        case 'text':
            return enc_text($value);
        case 'attr':
            return enc_attr($value);
        case 'js':
            return enc_js($value);
        case 'wbr':
            return implode('<wbr>', array_map('enc_text', explode('|', $value)));
        case 'lat':
            return enc_lat($value);
        case 'li':
            return implode('', array_map(fn($l) => '<li>' . enc_text($l) . '</li>', enc_lines($value)));
    }
    if (preg_match('/^paras:(\d+)$/', $enc, $m)) {
        $n = (int) $m[1];
        $parts = [];
        foreach (enc_lines($value) as $k => $line) {
            $parts[] = '<p class="rv" style="--i:' . ($n + $k) . '">' . enc_text($line) . '</p>';
        }
        return implode("\n" . $indent, $parts);
    }
    throw new TemplateError('unknown encoding ' . $enc);
}

function dec_text(string $html): string
{
    if (str_contains($html, '<')) {
        throw new TemplateError('markup inside a text default: ' . mb_substr($html, 0, 40));
    }
    return htmlspecialchars_decode($html, ENT_NOQUOTES | ENT_HTML5);
}

/** Inverse of enc_render for defaults; the caller checks render(decode(x)) === x. */
function enc_decode(string $enc, string $html, string $indent = ''): string
{
    switch ($enc) {
        case 'text':
            return dec_text($html);
        case 'attr':
            if (str_contains($html, '"') || str_contains($html, '<')) {
                throw new TemplateError('raw quote or < in attr default');
            }
            return htmlspecialchars_decode($html, ENT_QUOTES | ENT_HTML5);
        case 'js':
            return dec_js($html);
        case 'wbr':
            return implode('|', array_map('dec_text', explode('<wbr>', $html)));
        case 'lat':
            return dec_text(str_replace(['<span class="lat">', '</span>'], '', $html));
        case 'li':
            if (!preg_match('/^(<li>[^<]*<\/li>)*$/', $html)) {
                throw new TemplateError('bad li default');
            }
            preg_match_all('/<li>([^<]*)<\/li>/', $html, $m);
            return implode("\n", array_map('dec_text', $m[1]));
    }
    if (preg_match('/^paras:(\d+)$/', $enc, $m)) {
        $n = (int) $m[1];
        $out = [];
        foreach (explode("\n" . $indent, $html) as $k => $part) {
            $open = '<p class="rv" style="--i:' . ($n + $k) . '">';
            if (!str_starts_with($part, $open) || !str_ends_with($part, '</p>')) {
                throw new TemplateError('bad paragraph default');
            }
            $out[] = dec_text(substr($part, strlen($open), -4));
        }
        return implode("\n\n", $out);
    }
    throw new TemplateError('unknown encoding ' . $enc);
}

function dec_js(string $s): string
{
    $out = '';
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $c = $s[$i];
        if ($c === "'" || $c === "\n" || $c === "\r") {
            throw new TemplateError('raw quote or newline in js default');
        }
        if ($c !== '\\') {
            $out .= $c;
            continue;
        }
        $rest = substr($s, $i + 1, 5);
        if ($rest !== '' && ($rest[0] === '\\' || $rest[0] === "'")) {
            $out .= $rest[0];
            $i += 1;
        } elseif ($rest !== '' && $rest[0] === 'n') {
            $out .= "\n";
            $i += 1;
        } elseif ($rest !== '' && $rest[0] === 'r') {
            $out .= "\r";
            $i += 1;
        } elseif (str_starts_with($rest, 'x3C')) {
            $out .= '<';
            $i += 3;
        } elseif (str_starts_with($rest, 'x3E')) {
            $out .= '>';
            $i += 3;
        } elseif (str_starts_with($rest, 'u2028')) {
            $out .= "\u{2028}";
            $i += 5;
        } elseif (str_starts_with($rest, 'u2029')) {
            $out .= "\u{2029}";
            $i += 5;
        } else {
            throw new TemplateError('unsupported js escape');
        }
    }
    return $out;
}

// ---------- loading ----------

function tpl_source(string $name): string
{
    if (!preg_match('/^(home|post|archive|shop)$/', $name)) {
        throw new TemplateError('unknown template ' . $name);
    }
    $src = file_get_contents(app_path('templates', $name . '.tpl.html'));
    if ($src === false) {
        throw new TemplateError('cannot read template ' . $name);
    }
    return $src;
}

function tpl_load(string $name): array
{
    static $cache = [];
    if (!isset($cache[$name])) {
        $cache[$name] = tpl_parse(tpl_source($name));
    }
    return $cache[$name];
}

function template_hash(): string
{
    $h = hash_init('sha256');
    foreach (['home.tpl.html', 'post.tpl.html', 'archive.tpl.html', 'shop.tpl.html', 'home-extra.css'] as $f) {
        hash_update($h, $f . "\0" . (string) @file_get_contents(app_path('templates', $f)) . "\0");
    }
    hash_update($h, 'post.css' . "\0" . (string) @file_get_contents(PUBLIC_DIR . '/assets/post.css'));
    hash_update($h, 'shop.css' . "\0" . (string) @file_get_contents(PUBLIC_DIR . '/assets/shop.css'));
    return hash_final($h);
}
