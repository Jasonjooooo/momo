<?php
// Builds a test site in temp dirs for the Playwright checks. Never touches the repo's public files.
// Usage: BLOG_PUBLIC_DIR=… BLOG_DATA_DIR=… php fixture.php <scenario>
//   scenarios: launch | pub0 (no legs at all) | pub1 | pub3 | pub8   (each adds one private draft too)
// Prints JSON: {legs, published: [{slug, title}], draft_title}
declare(strict_types=1);
define('BLOG_APP', true);
require __DIR__ . '/../../../pilot-blog/app/lib/bootstrap.php';
app_init('cli');

$scenario = $argv[1] ?? 'launch';
$published = ['launch' => 0, 'pub0' => 0, 'pub1' => 1, 'pub3' => 3, 'pub8' => 8][$scenario] ?? 0;

$body = <<<'MD'
落地前二十分钟，我把进近简令过一遍：天气、最低标准、复飞程序，还有今天跑道上的风。
## 进近简令
云底 300 英尺，能见度 2000 米。决断高度 200 英尺，**看不见灯就复飞**，这句话在简令里说出口。
### 三件事
- 航向道和下滑道都截获以后，再放最后一级襟翼。
- 1000 英尺稳定，不稳定就复飞。
- 决断高度前两秒，眼睛开始找进近灯。
1. 先看灯
2. 再看跑道
3. 最后看接地区
> 这里写的是个人经验，不是训练资料。以飞机飞行手册和公司程序为准。
---
参考：[Pilatus 官网](https://www.pilatus-aircraft.com) 的公开资料，以及上一篇 [日志](posts/) 里的 `DA 200`。
MD;

$cover = null;
if ($published > 0) {
    $im = imagecreatetruecolor(1600, 900);
    imagefill($im, 0, 0, imagecolorallocate($im, 19, 45, 97));
    imagefilledrectangle($im, 0, 600, 1600, 900, imagecolorallocate($im, 61, 68, 107));
    $tmp = sys_get_temp_dir() . '/fx-' . bin2hex(random_bytes(4)) . '.png';
    imagepng($im, $tmp);
    $up = image_store_file($tmp);
    unlink($tmp);
    $cover = ['src' => $up['src'], 'og' => $up['og'], 'w' => $up['w'], 'h' => $up['h'], 'alt' => '云底下的跑道'];
    $body .= "\n![跑道入口灯](" . $up['src'] . ' "雾里的绿色入口灯")';
}

if ($scenario === 'pub0') {
    foreach (posts_all() as $p) {
        post_unpublish($p['id'], $p['rev']);
    }
}

$titles = [
    '低云进近：决断高度前的那两秒，我在看什么',
    '短跑道上的 PC-12：先算数，再上跑道',
    '结冰季：除冰靴、惯性分离器，和我不碰的那条线，以及一个很长很长的标题测试换行会不会撑破手机屏幕',
    '单发涡桨的一天',
    '绕机一圈：我的航前检查顺序',
    '侧风落地：PC-12 的方向舵和副翼怎么配合',
    'MEL 和 CDL：带着故障能不能飞',
    '夜航：在没有灯光的平原上，怎么找到跑道',
];
$out = ['legs' => 0, 'published' => [], 'draft_title' => '这一篇是私人草稿不应出现'];
for ($i = 0; $i < $published; $i++) {
    $p = post_create();
    $p = post_save_draft($p['id'], [
        'title' => $titles[$i],
        'body' => $body,
        'excerpt' => $i % 2 ? '' : '云底 300 英尺，能见度 2000 米。从最后进近点到决断高度，注意力怎么分配。',
        'tags' => $i % 3 === 2 ? '' : ['IMC', 'PERF', 'WX'][$i % 3],
        'date' => sprintf('2026-%02d-%02d', 10 - intdiv($i, 3), 14 - $i),
        'cover' => $i === 0 ? $cover : null,
    ], $p['rev']);
    $p = post_publish($p['id'], $p['rev'])['post'];
    $out['published'][] = ['slug' => $p['slug'], 'title' => $p['live']['title'], 'fix' => $p['fix']];
}
$d = post_create();
post_save_draft($d['id'], ['title' => $out['draft_title'], 'body' => '私人内容。', 'excerpt' => '私人摘要不应出现'], $d['rev']);

$limit = ['pub3' => 8, 'pub8' => 12][$scenario] ?? 6;
$s = settings_get();
settings_save(['home' => ['leg_limit' => $limit]], $s['rev']);
$r = publish_site('fixture ' . $scenario);
if (!$r['ok']) {
    fwrite(STDERR, json_encode($r['errors'], JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}
$out['legs'] = count(home_legs(posts_all(), $limit)['legs']);
echo json_encode($out, JSON_UNESCAPED_UNICODE) . "\n";
