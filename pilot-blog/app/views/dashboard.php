<?php
defined('BLOG_APP') || exit;
$chatOn = $settings['chat']['enabled'];
$limit = (int) $settings['chat']['daily_limit'];
$asked = (int) $today['chat']['requests'];
?>
<h1 class="page-h">概览</h1>
<?php if ($heal === true): ?>
<p class="notice notice-ok">网站文件和后台内容不一致（多半是网站程序刚更新过），已经重新生成了页面。</p>
<?php elseif ($heal === false): ?>
<p class="notice notice-err"><?= h((string) $heal_error) ?></p>
<?php endif; ?>
<?php if ($origin_missing): ?>
<div class="notice notice-warn">
  <p>网站地址还没填，分享卡片会用不了。</p>
<?php if ($origin_suggest !== null): ?>
  <form method="post" action="<?= h(url('site.origin')) ?>">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <button class="btn btn-primary" type="submit">用 <?= h($origin_suggest) ?> 作为网站地址</button>
  </form>
<?php else: ?>
  <p><a href="<?= h(url('site')) ?>#g-site">到「网站文字 → 基本信息」里填网站地址</a></p>
<?php endif; ?>
</div>
<?php endif; ?>
<?php if ($chatOn && !$has_key): ?>
<p class="notice notice-warn">AI 助手开着，但还没有密钥。<a href="<?= h(url('ai')) ?>">去填密钥</a></p>
<?php endif; ?>

<section class="card" aria-labelledby="c-site">
  <h2 class="card-h" id="c-site">网站</h2>
  <dl class="kv">
    <div><dt>上次发布</dt><dd class="mono"><?= h($st['last_publish'] ? cn_time($st['last_publish']) : '还没有发布过') ?></dd></div>
  </dl>
<?php $pending = []; if ($st['site_dirty']) { $pending[] = ['site', '网站文字有没发布的修改']; } if ($st['posts_dirty'] > 0) { $pending[] = ['posts', $st['posts_dirty'] . ' 篇日志有没发布的修改']; } ?>
<?php if ($pending): ?>
  <ul class="pending">
<?php foreach ($pending as [$r, $label]): ?>
    <li><a href="<?= h(url($r)) ?>"><?= h($label) ?></a></li>
<?php endforeach; ?>
  </ul>
<?php else: ?>
  <p class="muted">后台的内容都已经发布了。</p>
<?php endif; ?>
  <div class="row-btns">
    <a class="btn" href="<?= h(site_base()) ?>" target="_blank" rel="noopener">查看网站</a>
    <form method="post" action="<?= h(url('publish.all')) ?>" data-confirm="按现在的内容重新生成网站的全部页面。内容不会变，一般用不到，网站显示不对时再点。" data-confirm-ok="重新生成">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <button class="btn btn-ghost" type="submit">重新发布全部</button>
    </form>
  </div>
</section>

<section class="card<?php if ($shop['claimed'] + $shop['to_ship'] + $shop['failed'] > 0): ?> card-hot<?php endif; ?>" aria-labelledby="c-shop">
  <h2 class="card-h" id="c-shop">商城</h2>
  <dl class="kv kv-3">
    <div><dt>待确认收款</dt><dd class="num<?php if ($shop['claimed'] > 0): ?> num-hot<?php endif; ?>"><?= h((string) $shop['claimed']) ?></dd></div>
    <div><dt>待发货</dt><dd class="num<?php if ($shop['to_ship'] > 0): ?> num-hot<?php endif; ?>"><?= h((string) $shop['to_ship']) ?></dd></div>
    <div><dt>待付款</dt><dd class="num"><?= h((string) $shop['pending']) ?></dd></div>
<?php if ($supply_n > 0 || $shop['failed'] > 0): ?>
    <div><dt>进货失败</dt><dd class="num<?php if ($shop['failed'] > 0): ?> num-hot<?php endif; ?>"><?= h((string) $shop['failed']) ?></dd></div>
<?php endif; ?>
  </dl>
<?php if ($shop['failed'] > 0): ?>
  <p class="where">有订单已经确认收款，但从货源进货没成功（比如货源余额不够、缺货或网络问题）。打开订单看原因，处理好以后点「重试进货」，或者取消并退款。</p>
<?php endif; ?>
<?php if ($supply_n > 0): ?>
  <p class="where"><?= h('有 ' . $supply_n . ' 件货源商品' . ($supply_on ? '' : '（货源开关关着，首页不显示它们）') . '。进货从你在供货平台的余额里扣，这里查不到余额，记得定期去供货平台看一眼，保持余额充足。') ?></p>
<?php endif; ?>
<?php if ($shop['claimed'] > 0): ?>
  <p class="where">有买家说已经付款了。打开微信或支付宝的收款记录，按金额对一下，再在订单里点「确认收款」。</p>
<?php endif; ?>
  <p class="muted"><?= h(($shop_open ? '营业中' : '暂停营业') . '，' . $shop_selling . ' 件商品在卖' . ($shop_qr === 0 ? '。还没有上传收款码，买家付不了款。' : '。')) ?></p>
  <div class="row-btns">
    <a class="btn btn-primary" href="<?= h(url('orders')) ?>">处理订单</a>
    <a class="btn" href="<?= h(url('shop')) ?>">商品</a>
<?php if ($supply_n > 0 || $supply_on): ?>
    <a class="btn" href="<?= h(url('supply')) ?>">货源</a>
<?php endif; ?>
<?php if ($shop_qr === 0): ?>
    <a class="btn" href="<?= h(url('pay')) ?>">上传收款码</a>
<?php endif; ?>
  </div>
</section>

<section class="card" aria-labelledby="c-log">
  <h2 class="card-h" id="c-log">飞行日志</h2>
  <dl class="kv kv-3">
    <div><dt>已发布</dt><dd class="num"><?= h((string) $counts['published']) ?></dd></div>
    <div><dt>预告</dt><dd class="num"><?= h((string) $counts['teaser']) ?></dd></div>
    <div><dt>草稿</dt><dd class="num"><?= h((string) $counts['draft']) ?></dd></div>
  </dl>
  <div class="row-btns">
    <form method="post" action="<?= h(url('post.new')) ?>">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <button class="btn btn-primary" type="submit">写新日志</button>
    </form>
    <a class="btn" href="<?= h(url('posts')) ?>">全部日志</a>
  </div>
</section>

<section class="card" aria-labelledby="c-subs">
  <h2 class="card-h" id="c-subs">订阅者</h2>
  <dl class="kv kv-2">
    <div><dt>总数</dt><dd class="num"><?= h((string) $subs_total) ?></dd></div>
    <div><dt>近 7 天新增</dt><dd class="num"><?= h((string) $subs_week) ?></dd></div>
  </dl>
<?php if (!$settings['subscribe']['enabled']): ?>
  <p class="muted">订阅表单现在是关着的。</p>
<?php endif; ?>
  <div class="row-btns"><a class="btn" href="<?= h(url('subs')) ?>">查看订阅者</a></div>
</section>

<section class="card" aria-labelledby="c-stats">
  <h2 class="card-h" id="c-stats">访问统计</h2>
  <dl class="kv kv-2">
    <div><dt>今天访客</dt><dd class="num"><?= h((string) $stats_today['visitors']) ?></dd></div>
    <div><dt>今天浏览</dt><dd class="num"><?= h((string) $stats_today['views']) ?></dd></div>
  </dl>
<?php if (!$stats_on): ?>
  <p class="muted">访问统计现在是关着的。</p>
<?php endif; ?>
  <div class="row-btns"><a class="btn" href="<?= h(url('stats')) ?>">查看访问统计</a></div>
</section>

<section class="card" aria-labelledby="c-ai">
  <h2 class="card-h" id="c-ai">AI 助手</h2>
  <dl class="kv kv-2">
    <div><dt>状态</dt><dd><?php if ($chat_live): ?><span class="chip chip-ok">在线</span><?php elseif ($chatOn): ?><span class="chip chip-warn">缺少密钥</span><?php else: ?><span class="chip">已关闭</span><?php endif; ?></dd></div>
    <div><dt>今天提问</dt><dd class="num"><?= h($asked . ' / ' . $limit) ?></dd></div>
  </dl>
  <div class="row-btns"><a class="btn" href="<?= h(url('ai')) ?>">AI 助手设置</a></div>
</section>
