<?php
// CLI: publish the site from the data dir (dev, or over SSH on the host). First run installs the launch seed.
// Usage: php pilot-blog/app/bin/publish.php ["reason"]
//        BLOG_DATA_DIR=/tmp/d BLOG_PUBLIC_DIR=/tmp/p php pilot-blog/app/bin/publish.php
declare(strict_types=1);
PHP_SAPI === 'cli' || exit;
define('BLOG_APP', true);
require dirname(__DIR__) . '/lib/bootstrap.php';
app_init('cli');
$res = publish_site(trim((string) ($argv[1] ?? '')) ?: '重新生成全部页面');
if (!$res['ok']) {
    fwrite(STDERR, "publish failed:\n");
    foreach ($res['errors'] as $k => $v) {
        fwrite(STDERR, "  $k: $v\n");
    }
    exit(1);
}
echo 'published: ' . implode(', ', $res['files']) . "\n" . ($res['version_id'] ? 'version ' . $res['version_id'] . "\n" : "no new version (content unchanged)\n");
