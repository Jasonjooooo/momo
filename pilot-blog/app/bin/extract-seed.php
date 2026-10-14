<?php
// CLI: home.tpl.html -> app/seed/{site,shelf,settings}.baseline.json and app/seed/posts/*.json (SPEC §4.7 step 6).
// Usage: php pilot-blog/app/bin/extract-seed.php
declare(strict_types=1);
PHP_SAPI === 'cli' || exit;
define('BLOG_APP', true);
require dirname(__DIR__) . '/lib/bootstrap.php';
date_default_timezone_set('UTC');

$ex = block_extract_all(tpl_parse(tpl_source('home')));
$seed = APP_DIR . '/seed';
json_write_atomic($seed . '/site.baseline.json', $ex['site'], 0644);
json_write_atomic($seed . '/shelf.baseline.json', $ex['shelf'], 0644);
foreach (glob($seed . '/posts/*.json') ?: [] as $f) {
    unlink($f);
}
foreach ($ex['posts'] as $p) {
    json_write_atomic($seed . '/posts/' . $p['id'] . '.json', $p, 0644);
}
$settings = settings_defaults();
unset($settings['rev'], $settings['updated_at']);
$settings['subscribe']['enabled'] = false;   // baseline = today's page: Mode A, assistant INOP
$settings['chat']['enabled'] = false;
json_write_atomic($seed . '/settings.baseline.json', $settings, 0644);
printf("seed written: %d fields, %d faq rows, %d type facts, %d shelves, %d posts\n",
    count($ex['site']['fields']), count($ex['site']['faq']), count($ex['site']['type_facts']), count($ex['shelf']['shelves']), count($ex['posts']));
