<?php
defined('BLOG_APP') || exit;
$filters = ['all' => '全部', 'published' => '已发布', 'teaser' => '预告', 'draft' => '草稿'];
$chipClass = ['published' => 'chip-ok', 'teaser' => 'chip-warn', 'draft' => ''];
?>
<h1 class="page-h">飞行日志</h1>
<div class="row-btns">
  <form method="post" action="<?= h(url('post.new')) ?>">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <button class="btn btn-primary" type="submit">写新日志</button>
  </form>
</div>
<p class="where">草稿只有你看得到。预告只在首页显示标题和摘要。已发布的日志有自己的页面。</p>

<nav class="chips" aria-label="按状态筛选">
<?php foreach ($filters as $k => $label): ?>
  <a class="fchip" href="<?= h(url('posts', $k === 'all' ? [] : ['f' => $k])) ?>"<?php if ($filter === $k): ?> aria-current="page"<?php endif; ?>><?= h($label) ?> <span class="mono"><?= h((string) $counts[$k]) ?></span></a>
<?php endforeach; ?>
</nav>

<?php if (!$rows): ?>
<p class="card muted">这里还没有日志。</p>
<?php else: ?>
<ul class="plist">
<?php foreach ($rows as $p):
    $st = post_status($p);
    $title = ($p['draft']['title'] ?? '') !== '' ? $p['draft']['title'] : '（还没有标题）';
    $date = $p['live']['date'] ?? $p['draft']['date'] ?? null;
?>
  <li>
    <a class="prow" href="<?= h(url('post.edit', ['id' => $p['id']])) ?>">
      <span class="prow-t"><?= h($title) ?></span>
      <span class="prow-meta">
        <span class="chip <?= h($chipClass[$st]) ?>"><?= h(POST_STATUS_LABEL[$st]) ?></span>
<?php if (post_dirty($p)): ?>
        <span class="chip chip-line">有未发布的修改</span>
<?php endif; ?>
        <span class="mono fix"><?= h(post_live_fix($p)) ?></span>
        <span class="mono"><?= h($date !== null ? str_replace('-', '.', (string) $date) : '没有日期') ?></span>
      </span>
    </a>
  </li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<section class="card" aria-labelledby="limit-h">
  <h2 class="card-h" id="limit-h">首页显示多少篇</h2>
  <form method="post" action="<?= h(url('posts.limit')) ?>" class="inline-form" data-guard>
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="rev" value="<?= h((string) $settings['rev']) ?>">
    <label for="leg_limit">首页最多显示</label>
    <select id="leg_limit" name="leg_limit">
<?php for ($n = 3; $n <= 12; $n++): ?>
      <option value="<?= h((string) $n) ?>"<?php if ($n === (int) $settings['home']['leg_limit']): ?> selected<?php endif; ?>><?= h($n . ' 篇') ?></option>
<?php endfor; ?>
    </select>
    <button class="btn" type="submit">保存</button>
  </form>
  <p class="where">先放已发布的（新的在前），再放预告。保存后网站马上更新。</p>
</section>
