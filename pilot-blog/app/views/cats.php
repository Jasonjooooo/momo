<?php
defined('BLOG_APP') || exit;
$nTop = count($cats);
// one row per 大类 and per 小类, in display order; 'first'/'last' are within its own level
$rows = [];
foreach ($cats as $i => $c) {
    $rows[] = ['id' => $c['id'], 'name' => $c['name'], 'sub' => false, 'first' => $i === 0, 'last' => $i === $nTop - 1, 'top' => $c];
    foreach ($c['subs'] as $j => $s) {
        $rows[] = ['id' => $s['id'], 'name' => $s['name'], 'sub' => true, 'first' => $j === 0, 'last' => $j === count($c['subs']) - 1, 'top' => $c];
    }
    $rows[] = ['end' => true, 'top' => $c];
}
?>
<p class="crumb"><a href="<?= h(url('shop')) ?>">商品</a> / <span>分类</span></p>
<h1 class="page-h">商品分类</h1>
<p class="where">分两层：大类，和大类下面的小类（可以没有）。首页商城上方按这里的顺序显示大类，点一个大类再显示它的小类。里面没有在卖商品的分类不会显示。商品在「商品」里选分类，没选的显示在「其他」里。</p>
<p class="where"><?= h('最多 ' . SHOP_CATS_MAX . ' 个大类，每个大类最多 ' . SHOP_SUBS_MAX . ' 个小类，名称最多 ' . SHOP_CAT_NAME_MAX . ' 个字。') ?></p>

<section class="card" aria-labelledby="c-new">
  <h2 class="card-h" id="c-new">加一个大类</h2>
<?php if ($nTop >= SHOP_CATS_MAX): ?>
  <p class="muted"><?= h('已经有 ' . SHOP_CATS_MAX . ' 个大类了，先删掉不用的再加。') ?></p>
<?php else: ?>
  <form class="cat-add" method="post" action="<?= h(url('cats.add')) ?>">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <label class="vh" for="c-new-name">大类名称</label>
    <input id="c-new-name" name="name" type="text" maxlength="<?= h((string) SHOP_CAT_NAME_MAX) ?>" data-max="<?= h((string) SHOP_CAT_NAME_MAX) ?>" placeholder="比如：航空周边">
    <button class="btn btn-primary" type="submit">添加大类</button>
  </form>
<?php endif; ?>
</section>

<?php if ($nTop === 0): ?>
<p class="card muted">还没有分类。不加分类也可以，首页商城会像现在这样把商品放在一起。</p>
<?php endif; ?>
<?php foreach ($rows as $r): $c = $r['top']; ?>
<?php if (!empty($r['end'])): ?>
<?php if ($c['subs']): ?>
  </ul>
<?php endif; ?>
<?php if (count($c['subs']) < SHOP_SUBS_MAX): ?>
  <form class="cat-add cat-add-sub" method="post" action="<?= h(url('cats.add')) ?>">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="parent" value="<?= h($c['id']) ?>">
    <label class="vh" for="<?= h('ns-' . $c['id']) ?>"><?= h('在「' . $c['name'] . '」里加小类：名称') ?></label>
    <input id="<?= h('ns-' . $c['id']) ?>" name="name" type="text" maxlength="<?= h((string) SHOP_CAT_NAME_MAX) ?>" data-max="<?= h((string) SHOP_CAT_NAME_MAX) ?>" placeholder="<?= h('在「' . $c['name'] . '」里加小类') ?>">
    <button class="btn" type="submit">加小类</button>
  </form>
<?php else: ?>
  <p class="muted"><?= h('这个大类已经有 ' . SHOP_SUBS_MAX . ' 个小类了。') ?></p>
<?php endif; ?>
</section>
<?php continue; endif; ?>
<?php if (!$r['sub']): ?>
<section class="card cat-card" aria-labelledby="<?= h('h-' . $r['id']) ?>" data-cat-id="<?= h($r['id']) ?>">
<?php else: ?>
<?php if ($r['first']): ?>
  <h3 class="sub-h">小类</h3>
  <ul class="cat-subs">
<?php endif; ?>
    <li class="cat-sub" data-cat-id="<?= h($r['id']) ?>">
<?php endif; ?>
  <div class="cat-row">
    <div class="cat-txt">
<?php if (!$r['sub']): ?>
      <h2 class="card-h cat-name" id="<?= h('h-' . $r['id']) ?>"><span class="cat-k">大类</span><?= h($r['name']) ?></h2>
<?php else: ?>
      <p class="cat-name"><?= h($r['name']) ?></p>
<?php endif; ?>
      <p class="cat-meta"><a href="<?= h(url('shop', ['cat' => $r['id']])) ?>"><?= h($counts[$r['id']] . ' 件商品') ?></a><?= h('，在卖 ' . $live[$r['id']] . ' 件' . ($live[$r['id']] === 0 ? '，首页不显示' : '')) ?></p>
    </div>
    <div class="grow-move">
<?php foreach (['up' => '上移', 'down' => '下移'] as $dir => $label): ?>
      <form method="post" action="<?= h(url('cats.move')) ?>">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= h($r['id']) ?>">
        <input type="hidden" name="dir" value="<?= h($dir) ?>">
        <button class="btn btn-ghost btn-sq" type="submit"<?php if ($dir === 'up' ? $r['first'] : $r['last']): ?> disabled<?php endif; ?> aria-label="<?= h('把「' . $r['name'] . '」' . $label) ?>"><?= h($label) ?></button>
      </form>
<?php endforeach; ?>
    </div>
  </div>
  <details class="cat-more">
    <summary>改名或删除</summary>
    <form class="cat-add" method="post" action="<?= h(url('cats.rename')) ?>">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= h($r['id']) ?>">
      <label class="vh" for="<?= h('rn-' . $r['id']) ?>"><?= h('「' . $r['name'] . '」的新名称') ?></label>
      <input id="<?= h('rn-' . $r['id']) ?>" name="name" type="text" maxlength="<?= h((string) SHOP_CAT_NAME_MAX) ?>" data-max="<?= h((string) SHOP_CAT_NAME_MAX) ?>" value="<?= h($r['name']) ?>">
      <button class="btn" type="submit">保存名称</button>
    </form>
<?php if ($counts[$r['id']] > 0): ?>
    <p class="where"><?= h('里面还有 ' . $counts[$r['id']] . ' 件商品，不能删除。先打开这些商品，把分类换成别的（或「不分类」）。') ?> <a href="<?= h(url('shop', ['cat' => $r['id']])) ?>">看这些商品</a></p>
<?php else: ?>
    <form method="post" action="<?= h(url('cats.delete')) ?>" data-confirm="<?= h('删除' . ($r['sub'] ? '小类' : '大类') . '「' . $r['name'] . '」？' . (!$r['sub'] && $c['subs'] ? '它的 ' . count($c['subs']) . ' 个小类也会一起删除。' : '')) ?>" data-confirm-ok="删除" data-confirm-danger="1">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= h($r['id']) ?>">
      <button class="btn btn-ghost btn-danger" type="submit"><?= h('删除' . ($r['sub'] ? '这个小类' : '这个大类')) ?></button>
    </form>
<?php endif; ?>
  </details>
<?php if ($r['sub']): ?>
    </li>
<?php endif; ?>
<?php endforeach; ?>
