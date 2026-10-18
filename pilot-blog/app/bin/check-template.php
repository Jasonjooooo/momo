<?php
// CLI: T1 (baseline byte identity), T3 (hero untouched) and the template lint.
// Usage: php pilot-blog/app/bin/check-template.php
//        php pilot-blog/app/bin/check-template.php --rebaseline "reason"   (deliberate design change outside the hero)
//        php pilot-blog/app/bin/check-template.php --init-hero-reference    (one time; refuses to overwrite)
//        php pilot-blog/app/bin/check-template.php --hero-fix "reason"      (a deliberate, tested hero bug fix: re-freezes both)
declare(strict_types=1);
PHP_SAPI === 'cli' || exit;
define('BLOG_APP', true);
require dirname(__DIR__) . '/lib/bootstrap.php';
require __DIR__ . '/lib-checks.php';
date_default_timezone_set('UTC');

$args = array_slice($argv, 1);
$baselineFile = app_path('seed', 'baseline-index.html');

if (($args[0] ?? '') === '--init-hero-reference') {
    $ref = app_path('seed', 'hero-reference.json');
    if (is_file($ref)) {
        fwrite(STDERR, "hero-reference.json exists; it is frozen and cannot be rewritten\n");
        exit(1);
    }
    $base = (string) file_get_contents($baselineFile);
    if (hash('sha256', $base) !== BASELINE_08DA7C1_SHA256) {
        fwrite(STDERR, "baseline-index.html is not the 08da7c1 page (the hero reference comes only from that page)\n");
        exit(1);
    }
    json_write_atomic($ref, hero_reference_from($base), 0644);
    echo "hero-reference.json written\n";
    exit(0);
}

if (($args[0] ?? '') === '--hero-fix') {
    // The hero stays frozen against accidents; a real bug found on a device is fixed on purpose, logged with its reason,
    // and the reference and baseline are re-frozen from the fixed template so T1/T3 guard the new state.
    $reason = trim((string) ($args[1] ?? ''));
    if ($reason === '') {
        fwrite(STDERR, "give a reason: --hero-fix \"why\"\n");
        exit(1);
    }
    $html = render_baseline();
    json_write_atomic(app_path('seed', 'hero-reference.json'), hero_reference_from($html), 0644);
    file_put_contents($baselineFile, $html);
    file_put_contents(app_path('seed', 'rebaseline.log'), now_iso() . ' sha256 ' . hash('sha256', $html) . ' HERO FIX: ' . $reason . "\n", FILE_APPEND);
    echo "hero reference and baseline re-frozen: $reason\n";
    exit(0);
}

if (($args[0] ?? '') === '--rebaseline') {
    $reason = trim((string) ($args[1] ?? ''));
    if ($reason === '') {
        fwrite(STDERR, "give a reason: --rebaseline \"why\"\n");
        exit(1);
    }
    $html = render_baseline();
    $bad = hero_compare($html);
    if ($bad) {
        fwrite(STDERR, 'T3 FAIL, refusing to rebaseline: ' . implode(', ', $bad) . "\n");
        exit(1);
    }
    file_put_contents($baselineFile, $html);
    file_put_contents(app_path('seed', 'rebaseline.log'), now_iso() . ' sha256 ' . hash('sha256', $html) . ' ' . $reason . "\n", FILE_APPEND);
    echo "baseline rewritten: $reason\n";
    exit(0);
}

$fail = 0;
$baseline = (string) file_get_contents($baselineFile);

// T1
$render = render_baseline();
if (hash('sha256', $render) === hash('sha256', $baseline)) {
    echo "T1 PASS (sha256 " . hash('sha256', $render) . ")\n";
} else {
    echo "T1 FAIL\n" . first_diff($render, $baseline);
    $fail++;
}

// T3 on the baseline, launch and probe renders, plus no markers inside hero regions of the template
$t3 = 0;
foreach (['baseline' => fn() => $render, 'launch' => 'render_launch', 'probe' => fn() => render_content(probe_content())] as $name => $fn) {
    $bad = hero_compare($fn());
    if ($bad) {
        echo "T3 FAIL ($name): " . implode(', ', $bad) . "\n";
        $t3++;
    }
}
$tplBad = template_hero_markers(tpl_source('home'));
if ($tplBad) {
    echo 'T3 FAIL: markers inside hero regions ' . implode(', ', $tplBad) . "\n";
    $t3++;
}
if (!$t3) {
    echo "T3 PASS (baseline, launch and probe renders)\n";
}
$fail += $t3;
$lint = 0;

// lint: registry keys used with registered encodings, every encoded key appears in a template
$reg = field_registry();
$seen = [];
foreach (['home', 'post', 'archive'] as $t) {
    if (!is_file(APP_DIR . '/templates/' . $t . '.tpl.html')) {
        echo "LINT FAIL: template $t missing\n";
        $lint++;
        continue;
    }
    foreach (tpl_field_uses(tpl_load($t)) as [$key, $enc]) {
        $seen[$key] = true;
        if (!in_array($enc, $reg[$key]['enc'], true)) {
            echo "LINT FAIL: $key used as $enc in $t but the registry lists " . implode(',', $reg[$key]['enc']) . "\n";
            $lint++;
        }
    }
}
foreach ($reg as $key => $f) {
    if ($f['enc'] && !isset($seen[$key])) {
        echo "LINT FAIL: $key is in the registry but in no template\n";
        $lint++;
    }
}
if (!$lint) {
    echo "LINT PASS\n";
}
$fail += $lint;
exit($fail ? 1 : 0);
