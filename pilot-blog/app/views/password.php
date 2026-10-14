<?php defined('BLOG_APP') || exit; ?>
<h1 class="page-h">修改密码</h1>
<section class="card">
  <form method="post" action="<?= h(url('password')) ?>" class="stack" novalidate data-guard>
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
<?php foreach ([
    ['old', '当前密码', 'current-password', ''],
    ['new1', '新密码', 'new-password', '至少 10 个字符。改好以后，其他手机和电脑上的登录都会退出。'],
    ['new2', '再输一遍', 'new-password', ''],
] as [$name, $label, $ac, $help]): $bad = $error && $field === $name; ?>
    <div class="field<?php if ($bad): ?> has-err<?php endif; ?>">
      <label for="<?= h($name) ?>"><?= h($label) ?></label>
<?php if ($help !== ''): ?>
      <p class="where"><?= h($help) ?></p>
<?php endif; ?>
      <input id="<?= h($name) ?>" name="<?= h($name) ?>" type="password" autocomplete="<?= h($ac) ?>" required<?php if ($bad): ?> aria-invalid="true"<?php endif; ?>>
<?php if ($bad): ?>
      <p class="ferr"><?= h($error) ?></p>
<?php endif; ?>
    </div>
<?php endforeach; ?>
<?php if ($error && $field === null): ?>
    <p class="notice notice-err"><?= h($error) ?></p>
<?php endif; ?>
    <button class="btn btn-primary" type="submit">修改密码</button>
  </form>
</section>
