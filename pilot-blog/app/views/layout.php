<?php
defined('BLOG_APP') || exit;
/** Admin page shell. $content is the already-rendered (escaped) view. */
$active = admin_menu_active((string) $page);
$brand = admin_brand();
?><!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<meta name="referrer" content="same-origin">
<meta name="theme-color" content="#0A1733">
<meta name="color-scheme" content="dark">
<meta name="csrf" content="<?= h(csrf_token()) ?>">
<meta name="admin-base" content="<?= h(admin_base()) ?>">
<meta name="site-base" content="<?= h(site_base()) ?>">
<title><?= h(($title !== '' ? $title . ' · ' : '') . $brand . ' 后台') ?></title>
<link rel="icon" type="image/svg+xml" href="<?= h(ADMIN_FAVICON) ?>">
<link rel="stylesheet" href="<?= h(asset_url('admin.css')) ?>">
<script src="<?= h(asset_url('admin.js')) ?>" defer></script>
</head>
<body class="<?= h($bare ? 'is-bare' : 'is-app') ?>" data-page="<?= h((string) $page) ?>">
<a class="skip" href="#main">跳到正文</a>
<?php if ($bare): ?>
<header class="bare-top">
  <p class="brand"><span class="brand-name"><?= h($brand) ?></span><span class="brand-sub">后台</span></p>
</header>
<?php else: ?>
<header class="top">
  <a class="brand" href="<?= h(url('dashboard')) ?>"><span class="brand-name"><?= h($brand) ?></span><span class="brand-sub">后台</span></a>
  <p class="top-title"><?= h($title) ?></p>
  <button class="menu-btn" type="button" aria-controls="menu" aria-expanded="false">菜单</button>
</header>
<nav class="menu" id="menu" aria-label="后台菜单">
  <div class="menu-head">
    <p class="menu-title">菜单</p>
    <button class="menu-close btn btn-ghost" type="button">关闭</button>
  </div>
  <ul class="menu-list">
<?php foreach (ADMIN_MENU as $r => $label): ?>
    <li><a href="<?= h(url($r)) ?>"<?php if ($active === $r): ?> aria-current="page"<?php endif; ?>><?= h($label) ?></a></li>
<?php endforeach; ?>
  </ul>
  <a class="menu-site" href="<?= h(site_base()) ?>" target="_blank" rel="noopener">查看网站</a>
  <form class="menu-logout" method="post" action="<?= h(url('logout')) ?>">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <button class="btn btn-ghost" type="submit">退出</button>
  </form>
</nav>
<?php endif; ?>
<main class="main" id="main" tabindex="-1">
  <div class="flashes" id="flashes" role="status" aria-live="polite">
<?php foreach ($flashes as [$type, $msg]): ?>
    <p class="flash flash-<?= h((string) $type) ?>"><?= h((string) $msg) ?></p>
<?php endforeach; ?>
  </div>
<?php echo $content; /* rendered view: every value in it went through h() or j() */ ?>
</main>
</body>
</html>
