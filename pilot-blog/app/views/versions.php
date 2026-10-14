<?php defined('BLOG_APP') || exit; ?>
<h1 class="page-h">版本记录</h1>
<p class="lede">每次发布都会留一份网站的样子，保留最近 10 次。回滚只改网站上显示的内容，草稿不受影响，之后还可以再回滚回来。</p>
<?php if (!$versions): ?>
<p class="card muted">还没有发布记录。打开「概览」一次，或者发布一次内容，就会有第一条。</p>
<?php else: ?>
<ol class="vlist">
<?php foreach ($versions as $i => $v): $when = cn_time($v['created_at']); ?>
  <li class="card vrow">
    <p class="vrow-t"><span class="mono"><?= h($when) ?></span><?php if ($i === 0): ?> <span class="chip chip-ok">当前</span><?php endif; ?></p>
    <p class="vrow-r"><?= h((string) $v['reason']) ?></p>
    <div class="row-btns">
      <a class="btn" href="<?= h(url('versions.view', ['id' => $v['id']])) ?>" target="_blank" rel="noopener">看一看</a>
<?php if ($i > 0): ?>
      <form method="post" action="<?= h(url('versions.rollback')) ?>" data-confirm="<?= h('网站会变回 ' . $when . ' 的样子。草稿不受影响，之后还可以再回滚回来。') ?>" data-confirm-ok="回滚">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= h($v['id']) ?>">
        <button class="btn" type="submit">回滚到这个版本</button>
      </form>
<?php endif; ?>
    </div>
  </li>
<?php endforeach; ?>
</ol>
<?php endif; ?>
