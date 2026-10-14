<?php defined('BLOG_APP') || exit; ?>
<section class="card">
  <h1 class="page-h"><?= h($title) ?></h1>
  <p><?= h($message) ?></p>
<?php if (!empty($back)): ?>
  <p><a class="btn" href="<?= h($back) ?>">返回</a></p>
<?php elseif (empty($bare)): ?>
  <p><a class="btn" href="<?= h(url('dashboard')) ?>">回到概览</a></p>
<?php else: ?>
  <p><a class="btn" href="<?= h(url('login')) ?>">回到后台</a></p>
<?php endif; ?>
</section>
