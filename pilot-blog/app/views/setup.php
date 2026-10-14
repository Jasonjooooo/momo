<?php defined('BLOG_APP') || exit; ?>
<section class="auth-card card">
  <h1 class="auth-h">第一次使用</h1>
  <p>请在 Hostinger 文件管理器里，打开和 public_html 同级的 blog-data 文件夹，找到 setup-code.txt，把里面的设置码填到下面。</p>
<?php if ($insecure): ?>
  <p class="notice notice-warn">请用 https:// 打开后台，再设置密码。</p>
<?php else: ?>
  <form method="post" action="<?= h(url('setup')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
<?php foreach ([
    ['code', '设置码', 'text', 'one-time-code', '例如 K7P2-9QXM-4HTB，大小写和短横线都可以不管。'],
    ['password', '新密码', 'password', 'new-password', '至少 10 个字符。可以用一句好记的中文，比如一句诗加几个数字。'],
    ['password2', '再输一遍', 'password', 'new-password', ''],
] as [$name, $label, $type, $ac, $help]): $bad = $error && $field === $name; ?>
    <div class="field<?php if ($bad): ?> has-err<?php endif; ?>">
      <label for="<?= h($name) ?>"><?= h($label) ?></label>
<?php if ($help !== ''): ?>
      <p class="where" id="<?= h($name) ?>-help"><?= h($help) ?></p>
<?php endif; ?>
      <input id="<?= h($name) ?>" name="<?= h($name) ?>" type="<?= h($type) ?>" autocomplete="<?= h($ac) ?>" required<?php if ($name === 'code'): ?> autocapitalize="characters" spellcheck="false"<?php endif; ?><?php if ($bad): ?> aria-invalid="true"<?php endif; ?>>
<?php if ($bad): ?>
      <p class="ferr"><?= h($error) ?></p>
<?php endif; ?>
    </div>
<?php endforeach; ?>
<?php if ($error && $field === null): ?>
    <p class="notice notice-err"><?= h($error) ?></p>
<?php endif; ?>
    <button class="btn btn-primary btn-wide" type="submit">设好密码，进入后台</button>
  </form>
<?php endif; ?>
</section>
