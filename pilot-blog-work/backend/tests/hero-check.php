<?php
// T3 for a page the admin just published: the hero regions must still match hero-reference.json.
// Usage: php hero-check.php /path/to/index.html   -> prints HERO OK, or HERO FAIL: regions
declare(strict_types=1);
PHP_SAPI === 'cli' || exit;
define('BLOG_APP', true);
require __DIR__ . '/../../../pilot-blog/app/lib/bootstrap.php';
require __DIR__ . '/../../../pilot-blog/app/bin/lib-checks.php';
$html = (string) file_get_contents($argv[1] ?? '');
$bad = hero_compare($html);
echo $bad ? 'HERO FAIL: ' . implode(', ', $bad) . "\n" : "HERO OK\n";
exit($bad ? 1 : 0);
