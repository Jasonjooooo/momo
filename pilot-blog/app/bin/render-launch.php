<?php
// CLI: prints the home page rendered from the launch content (baseline seed + launch.patch.json), SPEC §9.6 / T2.
// Usage: php pilot-blog/app/bin/render-launch.php > pilot-blog/index.html
declare(strict_types=1);
PHP_SAPI === 'cli' || exit;
define('BLOG_APP', true);
require dirname(__DIR__) . '/lib/bootstrap.php';
require __DIR__ . '/lib-checks.php';
date_default_timezone_set('UTC');
echo render_launch();
