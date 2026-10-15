<?php
defined('BLOG_APP') || exit;
// $days: newest first, 30 Beijing days. $pages / $refs: [key => count] over those days, '*' = the rest.
$today = $days[0];
$yesterday = $days[1];
$sum = function (int $n, string $k) use ($days): int {
    $t = 0;
    foreach (array_slice($days, 0, $n) as $d) {
        $t += (int) $d[$k];
    }
    return $t;
};
$num = fn(int $n) => number_format($n, 0, '.', ',');
$phone = 0;
$desktop = 0;
$wechat = 0;
foreach ($days as $d) {
    $phone += $d['devices']['phone'];
    $desktop += $d['devices']['desktop'];
    $wechat += $d['wechat'];
}
$devTotal = $phone + $desktop;
$pct = fn(int $n) => $devTotal > 0 ? (string) round(100 * $n / $devTotal) . '%' : '';
$pageName = function (string $path) use ($titles): string {
    if ($path === '/') {
        return '首页';
    }
    if ($path === '/posts/') {
        return '全部日志';
    }
    if ($path === '*') {
        return '其他页面';
    }
    if (isset($titles[$path])) {
        return '《' . $titles[$path] . '》';
    }
    return '已撤下的日志（' . (string) preg_replace('#^/posts/|\.html$#', '', $path) . '）';
};
// bar chart: oldest on the left, today on the right
$chart = array_reverse($days);
$max = max(1, ...array_map(fn($d) => (int) $d['visitors'], $chart));
$W = 600;
$H = 150;
$top = 18;
$base = 128;
$slot = $W / count($chart);
?>
<h1 class="page-h">访问统计</h1>
<p class="notice notice-info">这里的数字是大概的，用来看个趋势就好。同一个人换了网络或换了手机，会算成两位访客；关掉了图片或脚本的访问记不到。本站不保存访客的 IP 地址、浏览器信息或其他个人信息，只存每天的总数。你登录过后台的手机或电脑，再打开网站不算在里面。</p>

<section class="card" aria-labelledby="st-day">
  <h2 class="card-h" id="st-day">今天和昨天</h2>
  <dl class="kv kv-2">
    <div><dt>今天访客</dt><dd class="num" id="st-today-visitors"><?= h($num($today['visitors'])) ?></dd></div>
    <div><dt>今天浏览</dt><dd class="num" id="st-today-views"><?= h($num($today['views'])) ?></dd></div>
    <div><dt>昨天访客</dt><dd class="num"><?= h($num($yesterday['visitors'])) ?></dd></div>
    <div><dt>昨天浏览</dt><dd class="num"><?= h($num($yesterday['views'])) ?></dd></div>
  </dl>
  <p class="where">访客是打开网站的人数，浏览是打开页面的次数（一个人看了三页算三次）。按北京时间算一天。</p>
</section>

<section class="card" aria-labelledby="st-30">
  <h2 class="card-h" id="st-30">近 30 天</h2>
  <dl class="kv kv-2">
    <div><dt>近 7 天访客</dt><dd class="num"><?= h($num($sum(7, 'visitors'))) ?></dd></div>
    <div><dt>近 7 天浏览</dt><dd class="num"><?= h($num($sum(7, 'views'))) ?></dd></div>
    <div><dt>近 30 天访客</dt><dd class="num"><?= h($num($sum(30, 'visitors'))) ?></dd></div>
    <div><dt>近 30 天浏览</dt><dd class="num"><?= h($num($sum(30, 'views'))) ?></dd></div>
  </dl>
  <figure class="chart">
    <svg viewBox="0 0 <?= h((string) $W) ?> <?= h((string) $H) ?>" role="img" aria-labelledby="st-chart-t" preserveAspectRatio="none">
      <title id="st-chart-t">近 30 天每天的访客，最多一天 <?= h((string) $max) ?> 位</title>
      <line x1="0" y1="<?= h((string) $base) ?>" x2="<?= h((string) $W) ?>" y2="<?= h((string) $base) ?>" stroke="#5E6EA2" stroke-width="1" vector-effect="non-scaling-stroke"/>
      <line x1="0" y1="<?= h((string) $top) ?>" x2="<?= h((string) $W) ?>" y2="<?= h((string) $top) ?>" stroke="#2A3A66" stroke-width="1" stroke-dasharray="4 4" vector-effect="non-scaling-stroke"/>
<?php foreach ($chart as $i => $d):
    $n = (int) $d['visitors'];
    $bh = $n > 0 ? max(2, ($base - $top) * $n / $max) : 0;
    $x = $i * $slot + $slot * 0.18;
    $w = $slot * 0.64;
?>
      <rect x="<?= h(number_format($x, 1, '.', '')) ?>" y="<?= h(number_format($base - $bh, 1, '.', '')) ?>" width="<?= h(number_format($w, 1, '.', '')) ?>" height="<?= h(number_format($bh, 1, '.', '')) ?>" fill="<?= h($i === count($chart) - 1 ? '#EA4C22' : '#4FD08A') ?>"><title><?= h(str_replace('-', '.', $d['date']) . '：' . $n . ' 位访客，' . $d['views'] . ' 次浏览') ?></title></rect>
<?php endforeach; ?>
    </svg>
    <figcaption class="chart-cap">
      <span class="mono"><?= h(str_replace('-', '.', substr($chart[0]['date'], 5))) ?></span>
      <span>每天的访客，最高 <?= h((string) $max) ?> 位，橙色是今天</span>
      <span class="mono"><?= h(str_replace('-', '.', substr($chart[count($chart) - 1]['date'], 5))) ?></span>
    </figcaption>
  </figure>
  <p class="where">访客按天算：同一个人隔天再来，会再算一次。</p>
</section>

<section class="card" aria-labelledby="st-pages">
  <h2 class="card-h" id="st-pages">看得最多的页面（近 30 天）</h2>
<?php if (!$pages): ?>
  <p class="muted">还没有记到浏览。</p>
<?php else: ?>
  <ol class="toplist" id="st-pages-list">
<?php foreach ($pages as $path => $n): ?>
    <li><span class="tl-name"><?= h($pageName((string) $path)) ?></span><span class="tl-num mono"><?= h($num((int) $n) . ' 次') ?></span></li>
<?php endforeach; ?>
  </ol>
<?php endif; ?>
</section>

<section class="card" aria-labelledby="st-refs">
  <h2 class="card-h" id="st-refs">从哪些网站点进来（近 30 天）</h2>
  <p class="where">只统计点进首页时的来源。直接输入网址、从收藏或微信聊天里打开的，一般看不到来源，不算在这里。</p>
<?php if (!$refs): ?>
  <p class="muted">还没有记到来源网站。</p>
<?php else: ?>
  <ol class="toplist" id="st-refs-list">
<?php foreach ($refs as $host => $n): $host = (string) $host; $label = $host === '*' ? '其他网站' : stats_ref_label($host); ?>
    <li><span class="tl-name"><?= h($label ?? $host) ?><?php if ($label !== null && $host !== '*'): ?> <span class="tl-host mono"><?= h($host) ?></span><?php endif; ?></span><span class="tl-num mono"><?= h($num((int) $n) . ' 次') ?></span></li>
<?php endforeach; ?>
  </ol>
<?php endif; ?>
</section>

<section class="card" aria-labelledby="st-dev">
  <h2 class="card-h" id="st-dev">用什么看（近 30 天，按访客）</h2>
<?php if ($devTotal === 0): ?>
  <p class="muted">还没有记到访客。</p>
<?php else: ?>
  <dl class="kv kv-2">
    <div><dt>手机和平板</dt><dd class="num"><?= h($pct($phone)) ?></dd></div>
    <div><dt>电脑</dt><dd class="num"><?= h($pct($desktop)) ?></dd></div>
  </dl>
  <p class="where"><?= h('手机和平板 ' . $num($phone) . ' 位，电脑 ' . $num($desktop) . ' 位；其中在微信里打开的 ' . $num($wechat) . ' 位。') ?></p>
<?php endif; ?>
</section>

<section class="card" aria-labelledby="st-sw">
  <h2 class="card-h" id="st-sw">开关</h2>
  <p><?php if ($on): ?><span class="chip chip-ok">开着</span> 网站正在统计访问。<?php else: ?><span class="chip">关着</span> 现在不统计，以前的数字还在。<?php endif; ?></p>
  <form method="post" action="<?= h(url('stats.toggle')) ?>"<?php if ($on): ?> data-confirm="关掉以后不再记新的访问，以前的数字还在。网站会重新生成一次。" data-confirm-ok="关掉访问统计"<?php endif; ?>>
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="rev" value="<?= h((string) $settings['rev']) ?>">
    <input type="hidden" name="enabled" value="<?= h($on ? '0' : '1') ?>">
    <button class="btn<?= h($on ? '' : ' btn-primary') ?>" type="submit" id="st-toggle"><?= h($on ? '关掉访问统计' : '打开访问统计') ?></button>
  </form>
  <p class="where">数字只保存 400 天，更早的会自动删掉。</p>
</section>
