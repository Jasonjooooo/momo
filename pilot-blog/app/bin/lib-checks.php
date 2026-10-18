<?php
// Shared by the CLI tools and the tests: hero regions (T3), T1/T2 renders, the probe content.
declare(strict_types=1);
defined('BLOG_APP') || exit;

// T1 reference. The 08da7c1 page (3401b7f4…) was the baseline until the owner-approved section reorder of 2026-10-15
// (SPEC §18 item 24), rebaselined with check-template.php --rebaseline (see app/seed/rebaseline.log); again on 2026-10-17
// when the AI shelf became the shop (§18 item 26). T3 (hero) never moves.
const BASELINE_SHA256 = '28657bec2d9b7e56445a5ba22b479481d2473d2065e85514c72a757f5b0e9413';   // hero fix 2026-10-18 (WeChat blob video), see rebaseline.log
const BASELINE_08DA7C1_SHA256 = '3401b7f4d1c8692dee06a383831474447733558bfba68efc96aab63ba850c90f';

/** Byte regions of the hero (SPEC §15 T3). Throws when a landmark is missing. */
function hero_regions(string $html): array
{
    $find = function (string $needle, int $from = 0) use ($html): int {
        $p = strpos($html, $needle, $from);
        if ($p === false) {
            throw new RuntimeException('hero landmark missing: ' . $needle);
        }
        return $p;
    };
    $eol = function (int $p) use ($html): int {
        $n = strpos($html, "\n", $p);
        return $n === false ? strlen($html) : $n + 1;
    };
    $r = [];
    $a = $find('<link rel="icon"');
    $b = $eol($find("<script>(function(d){", $a));
    $r['1-head-loader'] = substr($html, $a, $b - $a);
    $a = $find("<style>\n");
    $b = $find('/* ============ THE CHART: everything after the hero', $a);
    $r['2-hero-css'] = substr($html, $a, $b - $a);
    $a = $find('/* hero diagnostics panel (hidden');
    $b = $find('</style>', $a);
    $r['3-diag-css'] = substr($html, $a, $b - $a);
    $a = $find("<script>\n(() => {") ;
    $b = $eol($find('const POSTER_END_URL_PORTRAIT', $a));
    $r['4-script-head'] = substr($html, $a, $b - $a);
    $a = $find('/* ---------- helpers ---------- */');
    $b = $find('/* ============ APPROACH PLATE', $a);
    $r['5-hero-js'] = substr($html, $a, $b - $a);
    $a = $find('/* ---------- hero diagnostics panel ---------- */');
    $r['6-diag-js-start'] = substr($html, $a);
    $a = $find('<nav class="nav" id="nav"');
    $b = $find('</nav>', $a) + 6;
    $c = $find('<header class="hero" id="top">');
    $d = $find('</header>', $c) + 9;
    $r['7-skeleton'] = hero_skeleton(substr($html, $a, $b - $a)) . "\n--\n" . hero_skeleton(substr($html, $c, $d - $c));
    return $r;
}

function hero_skeleton(string $html): string
{
    preg_match_all('/<[^>]+>/', $html, $m);
    return implode("\n", array_filter($m[0], fn($t) => strtolower($t) !== '<wbr>'));
}

function hero_reference_from(string $html): array
{
    $out = [];
    foreach (hero_regions($html) as $k => $bytes) {
        $out[$k] = ['sha256' => hash('sha256', $bytes), 'length' => strlen($bytes)];
    }
    return $out;
}

/** List of problems ([] = T3 pass) for one rendered page. */
function hero_compare(string $html): array
{
    $ref = json_read(app_path('seed', 'hero-reference.json'));
    if (!is_array($ref)) {
        return ['hero-reference.json missing'];
    }
    $now = hero_reference_from($html);
    $bad = [];
    foreach ($ref as $k => $v) {
        if (($now[$k] ?? null) !== $v) {
            $bad[] = $k;
        }
    }
    return $bad;
}

/** Markers must stay out of hero regions 1-6 of the template source. */
function template_hero_markers(string $tpl): array
{
    $bad = [];
    foreach (hero_regions($tpl) as $k => $bytes) {
        if ($k !== '7-skeleton' && str_contains($bytes, '<!--{')) {
            $bad[] = $k;
        }
    }
    return $bad;
}

function render_content(array $c, bool $chatKey = false): string
{
    $site = $c['site'];
    $site['published_at'] ??= SEED_TIME;
    return render_home(build_home_ctx($site, $c['shelf'], $c['posts'], $c['settings'], $chatKey));
}

function render_baseline(): string
{
    return render_content(seed_content(false));
}

function render_launch(): string
{
    return render_content(seed_content(true));
}

/** Seed content with every text field replaced by a valid but different value containing <>&"'\ and a 40-char Chinese run. */
function probe_content(): array
{
    $c = seed_content(true);
    $han = str_repeat('云底之下航路图测试文字', 4);   // 40 Chinese characters
    foreach (field_registry() as $k => $f) {
        $special = '<>&"\'\\';
        if ($f['kind'] === 'url') {
            $v = 'https://probe.example';
        } elseif ($f['kind'] === 'year') {
            $v = '2031';
        } elseif ($f['kind'] === 'pattern') {
            $v = $special . implode('', $f['vars']) . mb_substr($han, 0, max(1, $f['max'] - 6 - 3 * count($f['vars'])));
        } else {
            $sep = in_array('wbr', $f['enc'], true) ? '|' : '';
            $room = $f['max'] - mb_strlen($special) - mb_strlen($sep);
            $v = $special . $sep . mb_substr($han, 0, max(1, $room));
            if ($f['multiline']) {
                $v = mb_substr($v, 0, max(3, intdiv($f['max'], 2))) . "\n" . '第二行' . $special;
            }
        }
        if (mb_strlen($v) > $f['max']) {
            $v = mb_substr($v, 0, $f['max']);
        }
        $p = field_problem($k, $v);
        if ($p !== null) {
            throw new RuntimeException("probe value for $k invalid: $p");
        }
        $c['site']['fields'][$k] = $v;
    }
    return $c;
}

/** First differing byte with 80 bytes of context from both. */
function first_diff(string $a, string $b): string
{
    $n = min(strlen($a), strlen($b));
    for ($i = 0; $i < $n && $a[$i] === $b[$i]; $i++);
    $from = max(0, $i - 40);
    return "first difference at byte $i (lengths " . strlen($a) . ' vs ' . strlen($b) . ")\n--- render:\n" . substr($a, $from, 80) . "\n--- baseline:\n" . substr($b, $from, 80) . "\n";
}
