<?php
defined('BLOG_APP') || exit;
$ss = $settings['shop'];
$all = count($products);
$products = array_values(array_filter($products, fn($p) => admin_shop_cat_match($p, $cat_filter, $cat_ix)));
$n = count($products);
$filtered = $cat_filter !== '';
?>
<h1 class="page-h">商品</h1>
<div class="row-btns">
  <form method="post" action="<?= h(url('shop.new')) ?>">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <button class="btn btn-primary" type="submit">加一件商品</button>
  </form>
  <a class="btn" href="<?= h(url('cats')) ?>">商品分类</a>
  <a class="btn" href="<?= h(url('orders')) ?>">订单</a>
  <a class="btn" href="<?= h(url('pay')) ?>">收款设置</a>
</div>
<?php if (!$ss['enabled']): ?>
<p class="notice notice-warn">商城现在暂停营业，首页显示「暂停营业」，买家不能下单。<a href="<?= h(url('pay')) ?>">去开门</a></p>
<?php endif; ?>
<?php if (!array_filter($ss['qr'])): ?>
<p class="notice notice-warn">还没有上传收款码，买家下单以后付不了款。<a href="<?= h(url('pay')) ?>">上传收款码</a></p>
<?php endif; ?>
<p class="where">首页「商城」按这里的顺序显示在卖的商品。实物由你寄快递；数字商品在买家付款、你确认收款以后，买家在「查订单」里看到你填的内容。</p>

<?php if ($cats && $all > 0): ?>
<nav class="chips" aria-label="按分类筛选">
  <a class="fchip" href="<?= h(url('shop')) ?>"<?php if (!$filtered): ?> aria-current="page"<?php endif; ?>>全部</a>
<?php foreach ($cats as $c): ?>
  <a class="fchip" href="<?= h(url('shop', ['cat' => $c['id']])) ?>"<?php if ($cat_filter === $c['id']): ?> aria-current="page"<?php endif; ?>><?= h($c['name']) ?></a>
<?php if (($cat_ix[$cat_filter]['top'] ?? '') === $c['id'] && $cat_ix[$cat_filter]['sub']): ?>
  <a class="fchip" href="<?= h(url('shop', ['cat' => $cat_filter])) ?>" aria-current="page"><?= h($c['name'] . ' / ' . $cat_ix[$cat_filter]['name']) ?></a>
<?php endif; ?>
<?php endforeach; ?>
  <a class="fchip" href="<?= h(url('shop', ['cat' => 'none'])) ?>"<?php if ($cat_filter === 'none'): ?> aria-current="page"<?php endif; ?>>不分类</a>
</nav>
<?php endif; ?>
<?php if ($cat_filter === 'low'): ?>
<p class="notice notice-warn" id="shop-low">只显示在卖、库存 <?= h((string) BIZ_LOW_STOCK) ?> 件及以下的商品。自己的商品点进去改库存；货源商品的库存是供货平台的。<a href="<?= h(url('shop')) ?>">看全部商品</a></p>
<?php elseif ($filtered): ?>
<p class="where">只显示这个分类里的商品。调整顺序请回到「全部」。</p>
<?php endif; ?>

<?php if ($n === 0 && $cat_filter === 'low'): ?>
<p class="card muted">没有库存不足的商品。</p>
<?php elseif ($n === 0 && $filtered): ?>
<p class="card muted">这个分类里没有商品。</p>
<?php elseif ($n === 0): ?>
<p class="card muted">还没有商品。点「加一件商品」开始。</p>
<?php else: ?>
<ul class="plist glist">
<?php foreach ($products as $i => $p):
    $label = shop_avail_label($p);
    $ph = $p['photos'][0]['src'] ?? null;
?>
  <li class="grow">
    <a class="prow grow-a" href="<?= h(url('shop.edit', ['id' => $p['id']])) ?>">
<?php if ($ph !== null): ?>
      <img class="grow-img" src="<?= h(site_base() . $ph) ?>" alt="" width="64" height="64" loading="lazy">
<?php else: ?>
      <span class="grow-img grow-noimg" aria-hidden="true">无图</span>
<?php endif; ?>
      <span class="grow-txt">
        <span class="prow-t"><?= h($p['name'] !== '' ? $p['name'] : '（还没有名字）') ?></span>
        <span class="prow-meta">
          <span class="chip <?= h($p['on_sale'] ? 'chip-ok' : '') ?>"><?= h($p['on_sale'] ? '在卖' : '没上架') ?></span>
          <span class="chip chip-line"><?= h(SHOP_TYPES[$p['type']]) ?></span>
          <span class="mono">¥<?= h(shop_money($p['price'])) ?></span>
          <span class="mono"><?= h($p['stock'] === null ? '不限量' : '库存 ' . $p['stock']) ?></span>
<?php if (($cl = shop_cat_label($p['cat'], $cat_ix)) !== ''): ?>
          <span class="prow-cat"><?= h($cl) ?></span>
<?php endif; ?>
<?php if ($label === '售罄'): ?>
          <span class="chip chip-warn">售罄</span>
<?php endif; ?>
        </span>
      </span>
    </a>
<?php if (!$filtered): ?>
    <div class="grow-move">
      <form method="post" action="<?= h(url('shop.move')) ?>">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= h($p['id']) ?>">
        <input type="hidden" name="dir" value="up">
        <button class="btn btn-ghost btn-sq" type="submit"<?php if ($i === 0): ?> disabled<?php endif; ?> aria-label="<?= h('把「' . $p['name'] . '」上移') ?>">上移</button>
      </form>
      <form method="post" action="<?= h(url('shop.move')) ?>">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= h($p['id']) ?>">
        <input type="hidden" name="dir" value="down">
        <button class="btn btn-ghost btn-sq" type="submit"<?php if ($i === $n - 1): ?> disabled<?php endif; ?> aria-label="<?= h('把「' . $p['name'] . '」下移') ?>">下移</button>
      </form>
    </div>
<?php endif; ?>
  </li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
