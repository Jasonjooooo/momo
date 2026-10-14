<?php
defined('BLOG_APP') || exit;
$on = $settings['subscribe']['enabled'];
?>
<h1 class="page-h">订阅者</h1>
<p class="notice notice-info">本站不会自动发邮件。发了新日志以后，复制全部邮箱，用你自己的邮箱以密送（BCC）方式发出，别放在收件人里，免得读者互相看到邮箱。有人回信要退订，就在这里把他删掉。</p>

<section class="card" aria-labelledby="s-sum">
  <h2 class="card-h" id="s-sum">名单</h2>
  <dl class="kv kv-2">
    <div><dt>总数</dt><dd class="num"><?= h((string) $total) ?></dd></div>
    <div><dt>近 7 天新增</dt><dd class="num"><?= h((string) $week) ?></dd></div>
  </dl>
  <div class="row-btns">
    <button type="button" class="btn btn-primary" id="copy-all"<?php if ($total === 0): ?> disabled<?php endif; ?>>复制全部邮箱（密送用）</button>
    <a class="btn" href="<?= h(url('subs.export')) ?>" download>导出表格（CSV）</a>
  </div>
  <textarea id="all-emails" class="vh" readonly tabindex="-1" aria-hidden="true"><?= h($all_emails) ?></textarea>
  <p class="where" id="copy-msg" role="status" aria-live="polite"></p>
</section>

<section class="card" aria-labelledby="s-form">
  <h2 class="card-h" id="s-form">订阅表单</h2>
  <p><?php if ($on): ?><span class="chip chip-ok">开着</span> 访客可以在首页留邮箱。<?php else: ?><span class="chip">关着</span> 首页显示订阅暂时关闭，不保存邮箱。<?php endif; ?></p>
  <form method="post" action="<?= h(url('subs.toggle')) ?>"<?php if ($on): ?> data-confirm="关掉以后，首页的订阅表单不再保存邮箱，已有的名单不受影响。" data-confirm-ok="关掉订阅表单"<?php endif; ?>>
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="rev" value="<?= h((string) $settings['rev']) ?>">
    <input type="hidden" name="enabled" value="<?= h($on ? '0' : '1') ?>">
    <button class="btn" type="submit"><?= h($on ? '关掉订阅表单' : '打开订阅表单') ?></button>
  </form>
</section>

<form class="find-form" method="get" action="<?= h(admin_base()) ?>" role="search">
  <input type="hidden" name="r" value="subs">
  <label for="q">搜索邮箱</label>
  <div class="find-row">
    <input id="q" name="q" type="search" value="<?= h($q) ?>" placeholder="输入邮箱的一部分" autocapitalize="off" spellcheck="false" enterkeyhint="search">
    <button class="btn" type="submit">搜索</button>
  </div>
</form>

<?php if ($list['total'] === 0): ?>
<p class="card muted"><?= h($q !== '' ? '没有找到包含「' . $q . '」的邮箱。' : '还没有人订阅。') ?></p>
<?php else: ?>
<form method="post" action="<?= h(url('subs.delete')) ?>" id="subs-del" data-confirm="删除选中的邮箱？删除后找不回来。" data-confirm-ok="删除" data-confirm-danger="1" data-need-check="emails[]">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="q" value="<?= h($q) ?>">
  <p class="where"><?= h(($q !== '' ? '找到 ' : '共 ') . $list['total'] . ' 个' . ($list['pages'] > 1 ? '，第 ' . $list['page'] . ' / ' . $list['pages'] . ' 页' : '') . '。新订阅的在上面。') ?></p>
  <ul class="slist">
<?php foreach ($list['items'] as $i => $it): $cid = 'sub-' . $i; ?>
    <li>
      <label class="srow" for="<?= h($cid) ?>">
        <input type="checkbox" id="<?= h($cid) ?>" name="emails[]" value="<?= h($it['email']) ?>">
        <span class="srow-e"><?= h($it['email']) ?></span>
        <span class="srow-t mono"><?= h(local_time($it['created_at'])) ?></span>
      </label>
    </li>
<?php endforeach; ?>
  </ul>
  <div class="row-btns">
    <button class="btn btn-danger" type="submit">删除所选</button>
  </div>
</form>
<?php if ($list['pages'] > 1): ?>
<nav class="pager" aria-label="翻页">
<?php if ($list['page'] > 1): ?>
  <a class="btn" href="<?= h(url('subs', array_filter(['q' => $q, 'p' => (string) ($list['page'] - 1)]))) ?>">上一页</a>
<?php endif; ?>
<?php if ($list['page'] < $list['pages']): ?>
  <a class="btn" href="<?= h(url('subs', array_filter(['q' => $q, 'p' => (string) ($list['page'] + 1)]))) ?>">下一页</a>
<?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
