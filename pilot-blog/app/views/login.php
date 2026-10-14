<?php defined('BLOG_APP') || exit; ?>
<section class="auth-card card">
  <h1 class="auth-h">登录后台</h1>
<?php if ($insecure): ?>
  <p class="notice notice-warn">请用 https:// 打开后台，再登录。</p>
<?php else: ?>
  <form method="post" action="<?= h(url('login')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <div class="field<?php if ($error): ?> has-err<?php endif; ?>">
      <label for="password">密码</label>
      <input id="password" name="password" type="password" autocomplete="current-password" required autofocus<?php if ($error): ?> aria-invalid="true" aria-describedby="password-err"<?php endif; ?>>
<?php if ($error): ?>
      <p class="ferr" id="password-err"><?= h($error) ?></p>
<?php endif; ?>
    </div>
    <button class="btn btn-primary btn-wide" type="submit">登录</button>
  </form>
<?php endif; ?>
  <details class="help">
    <summary>忘记密码？</summary>
    <p>在 Hostinger 文件管理器里，删除和 public_html 同级的 blog-data 文件夹里的 admin.json，再打开后台，按提示用新的设置码重设密码。内容不会丢。</p>
  </details>
</section>
