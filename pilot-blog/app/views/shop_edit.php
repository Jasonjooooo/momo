<?php
defined('BLOG_APP') || exit;
$v = function (string $k, string $fallback) use ($form): string {
    return array_key_exists($k, $form) && is_string($form[$k]) ? $form[$k] : $fallback;
};
$err = fn(string $k) => $errors[$k] ?? null;
$type = $v('type', $p['type']);
$onSale = array_key_exists('on_sale', $form) ? $form['on_sale'] === '1' : $p['on_sale'];
$unlimited = array_key_exists('unlimited', $form) ? $form['unlimited'] === '1' : $p['stock'] === null;
$stock = $p['stock'] === null ? '' : (string) $p['stock'];
$file = $p['delivery']['file'];
$pageUrl = site_base() . 'shop/' . $p['slug'] . '.html';
$isSupply = $p['type'] === 'supply';
?>
<p class="crumb"><a href="<?= h(url('shop')) ?>">商品</a> / <span><?= h($p['on_sale'] ? '在卖' : '没上架') ?></span></p>
<h1 class="page-h"><?= h($p['name'] !== '' ? $p['name'] : '新商品') ?></h1>
<?php if (shop_product_live($p)): ?>
<p><a class="btn btn-ghost" href="<?= h($pageUrl) ?>" target="_blank" rel="noopener">看商品页</a></p>
<?php endif; ?>
<?php if ($isSupply): $sp = $p['supply']; $margin = $p['price'] - $sp['cost']; [$qMin, $qMax] = supply_qty_bounds($p); ?>
<section class="card<?= h($margin < 0 ? ' card-hot' : '') ?>" aria-labelledby="g-supply">
  <h2 class="card-h" id="g-supply">货源（只有你看得到）</h2>
  <p class="where">这件是从货源进的卡。买家付款、你确认收款以后，网站自动从供货平台买一张，卡密显示在买家的「查订单」里。</p>
  <dl class="kv">
    <div><dt>供货平台的商品</dt><dd><?= h($sp['src_name'] !== '' ? $sp['src_name'] : $sp['code']) ?></dd></div>
    <div><dt>商品编号</dt><dd class="mono sel"><?= h($sp['code'] . ($sp['race'] !== '' ? ' / ' . $sp['race'] : '')) ?></dd></div>
    <div><dt>成本（每件）</dt><dd class="mono"><?= h('¥' . shop_money($sp['cost'])) ?></dd></div>
    <div><dt>售价（每件）</dt><dd class="mono"><?= h('¥' . shop_money($p['price'])) ?></dd></div>
    <div><dt>毛利（每件）</dt><dd class="mono<?= h($margin < 0 ? ' num-hot' : '') ?>"><?= h(($margin < 0 ? '亏 ¥' . shop_money(-$margin) : '¥' . shop_money($margin)) . ($p['price'] > 0 ? '（' . round($margin * 100 / $p['price']) . '%）' : '')) ?></dd></div>
    <div><dt>供货平台库存</dt><dd class="mono"><?= h((string) (int) $p['stock']) ?></dd></div>
    <div><dt>每单数量</dt><dd class="mono"><?= h($qMin === $qMax ? $qMin . ' 件' : $qMin . ' 到 ' . $qMax . ' 件') ?></dd></div>
    <div><dt>发货方式</dt><dd><?= h($sp['manual'] ? '人工发货（要等供货方发卡）' : '自动发卡') ?></dd></div>
  </dl>
  <p class="where"><?= h('成本 ' . cn_time($sp['cost_at']) . ' 读取，库存 ' . cn_time($sp['stock_at']) . ' 读取。库存每 5 分钟跟供货平台对一次，买家下单前也会再对一次。') ?></p>
<?php if ($margin < 0): ?>
  <p class="notice notice-warn">售价低于成本，卖一单亏一单。调高售价以后才能上架。</p>
<?php endif; ?>
  <form method="post" action="<?= h(url('supply.product')) ?>">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= h($p['id']) ?>">
    <button class="btn" type="submit">刷新成本和库存</button>
  </form>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="g-photos">
  <h2 class="card-h" id="g-photos">照片</h2>
  <p class="where">第一张显示在首页。照片的改动马上保存，不用再点下面的「保存」。最多 <?= h((string) SHOP_PHOTOS_MAX) ?> 张，每张最大 <?= h((string) $upload_mb) ?> MB。</p>
<?php if (!$p['photos']): ?>
  <p class="muted">还没有照片。上架前至少要传一张。</p>
<?php endif; ?>
  <ul class="ph-list">
<?php foreach ($p['photos'] as $i => $ph): ?>
    <li class="ph-item">
      <img src="<?= h(site_base() . $ph['src']) ?>" alt="" width="96" height="96" loading="lazy">
      <div class="ph-btns">
        <span class="mono"><?= h('第 ' . ($i + 1) . ' 张') ?></span>
<?php foreach (['up' => '上移', 'down' => '下移', 'del' => '删除'] as $op => $label):
    if (($op === 'up' && $i === 0) || ($op === 'down' && $i === count($p['photos']) - 1)) { continue; } ?>
        <form method="post" action="<?= h(url('shop.photo_op')) ?>"<?php if ($op === 'del'): ?> data-confirm="删除这张照片？" data-confirm-ok="删除" data-confirm-danger="1"<?php endif; ?>>
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="id" value="<?= h($p['id']) ?>">
          <input type="hidden" name="i" value="<?= h((string) $i) ?>">
          <input type="hidden" name="op" value="<?= h($op) ?>">
          <button class="btn btn-ghost<?= h($op === 'del' ? ' btn-danger' : '') ?>" type="submit"><?= h($label) ?></button>
        </form>
<?php endforeach; ?>
      </div>
    </li>
<?php endforeach; ?>
  </ul>
<?php if (count($p['photos']) < SHOP_PHOTOS_MAX): ?>
  <form class="up-form" method="post" action="<?= h(url('shop.photo')) ?>" enctype="multipart/form-data" data-autosubmit>
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= h($p['id']) ?>">
    <label class="btn file-btn" for="ph-file">选一张照片</label>
    <input class="vh" id="ph-file" name="image" type="file" accept="image/jpeg,image/png,image/webp,image/gif">
    <button class="btn btn-primary" type="submit">上传照片</button>
  </form>
<?php endif; ?>
</section>

<form class="card editor" method="post" action="<?= h(url('shop.save')) ?>" data-guard novalidate>
  <h2 class="card-h">商品信息</h2>
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="id" value="<?= h($p['id']) ?>">
  <input type="hidden" name="rev" value="<?= h((string) $p['rev']) ?>">
  <input type="hidden" name="stock_seen" value="<?= h($stock) ?>">

  <div class="field<?= h($err('name') ? ' has-err' : '') ?>">
    <label for="s-name">名称</label>
    <input id="s-name" name="name" type="text" maxlength="60" value="<?= h($v('name', $p['name'])) ?>">
    <p class="ferr"<?php if (!$err('name')): ?> hidden<?php endif; ?>><?= h((string) $err('name')) ?></p>
  </div>

<?php echo admin_cat_select('s-cat', $v('cat', $p['cat']), $err('cat')); /* built with h() in admin_shop.php */ ?>

<?php if ($isSupply): ?>
  <input type="hidden" name="type" value="supply">
  <input type="hidden" name="stock" value="<?= h($stock) ?>">
<?php else: ?>
  <fieldset class="field">
    <legend class="label">类型</legend>
    <label class="check"><input type="radio" name="type" value="physical"<?php if ($type !== 'digital'): ?> checked<?php endif; ?>> 实物（你寄快递，买家填收货地址）</label>
    <label class="check"><input type="radio" name="type" value="digital"<?php if ($type === 'digital'): ?> checked<?php endif; ?>> 数字（教程、资料、文件或链接，买家填邮箱）</label>
  </fieldset>
<?php endif; ?>

  <div class="field-row">
    <div class="field<?= h($err('price') ? ' has-err' : '') ?>">
      <label for="s-price"><?= h($isSupply ? '售价（元，每件）' : '价格（元）') ?></label>
      <div class="affix"><span>¥</span><input id="s-price" name="price" type="text" inputmode="decimal" value="<?= h($v('price', $p['price'] > 0 ? shop_money($p['price']) : '')) ?>" placeholder="99.00"></div>
<?php if ($isSupply): ?>
      <p class="hint"><?= h('成本 ¥' . shop_money($p['supply']['cost']) . '，售价不能低于它。') ?></p>
<?php endif; ?>
      <p class="ferr"<?php if (!$err('price')): ?> hidden<?php endif; ?>><?= h((string) $err('price')) ?></p>
    </div>
<?php if (!$isSupply): ?>
    <div class="field<?= h($err('stock') ? ' has-err' : '') ?>">
      <label for="s-stock">库存（件）</label>
      <input id="s-stock" name="stock" type="text" inputmode="numeric" value="<?= h($v('stock', $stock)) ?>">
      <p class="hint">有人下单会先扣掉；没付款被取消会放回来。</p>
      <p class="ferr"<?php if (!$err('stock')): ?> hidden<?php endif; ?>><?= h((string) $err('stock')) ?></p>
    </div>
<?php endif; ?>
  </div>
<?php if (!$isSupply): ?>
  <label class="check"><input type="checkbox" name="unlimited" value="1"<?php if ($unlimited): ?> checked<?php endif; ?>> 数字商品不限量（库存不用填）</label>
<?php endif; ?>

  <div class="field<?= h($err('on_sale') ? ' has-err' : '') ?>">
    <label class="check check-big"><input type="checkbox" name="on_sale" value="1"<?php if ($onSale): ?> checked<?php endif; ?>> 上架（在首页商城显示，可以下单）</label>
    <p class="ferr"<?php if (!$err('on_sale')): ?> hidden<?php endif; ?>><?= h((string) $err('on_sale')) ?></p>
  </div>

  <div class="field<?= h($err('summary') ? ' has-err' : '') ?>">
    <label for="s-sum">一句话介绍（可留空）</label>
    <input id="s-sum" name="summary" type="text" maxlength="80" value="<?= h($v('summary', $p['summary'])) ?>">
    <p class="hint">也是微信分享卡片上的那句简介。留空时，卡片上显示「商品名，¥价格」。卡片小图用第一张照片。</p>
    <p class="ferr"<?php if (!$err('summary')): ?> hidden<?php endif; ?>><?= h((string) $err('summary')) ?></p>
  </div>

  <div class="field<?= h($err('body') ? ' has-err' : '') ?>">
    <label for="s-body">详细介绍（可留空）</label>
    <p class="where">写法和日志一样：每按一次换行就是新的一段；「## 小标题」「- 列表」「**加粗**」「[文字](https://网址)」。</p>
    <textarea id="s-body" name="body" rows="8"><?= h($v('body', $p['body'])) ?></textarea>
    <p class="ferr"<?php if (!$err('body')): ?> hidden<?php endif; ?>><?= h((string) $err('body')) ?></p>
  </div>

  <div class="field<?= h($err('slug') ? ' has-err' : '') ?>">
    <label for="s-slug">网址</label>
    <div class="affix"><span>shop/</span><input id="s-slug" name="slug" type="text" autocapitalize="off" spellcheck="false" value="<?= h($v('slug', $p['slug'])) ?>"><span>.html</span></div>
    <p class="hint">小写字母、数字和短横线。上架以后最好别改，不然别人收藏的链接会失效。</p>
    <p class="ferr"<?php if (!$err('slug')): ?> hidden<?php endif; ?>><?= h((string) $err('slug')) ?></p>
  </div>

<?php foreach ($p['photos'] as $i => $ph): ?>
  <div class="field">
    <label for="s-alt-<?= h((string) $i) ?>"><?= h('第 ' . ($i + 1) . ' 张照片的说明（读屏软件读这句，可留空）') ?></label>
    <input id="s-alt-<?= h((string) $i) ?>" name="photo_alt[<?= h((string) $i) ?>]" type="text" maxlength="80" value="<?= h(is_string($form['photo_alt'][$i] ?? null) ? $form['photo_alt'][$i] : $ph['alt']) ?>">
  </div>
<?php endforeach; ?>

<?php if (!$isSupply): ?>
  <h3 class="sub-h">数字商品：买家付款后看到的内容</h3>
  <p class="where">只有数字商品用得到。你确认收款以后，买家在「查订单」里能看到下面的文字和链接，也能下载你传的文件。实物商品不用填。</p>
  <div class="field<?= h($err('dl_text') ? ' has-err' : '') ?>">
    <label for="s-dlt">文字（比如网盘提取码、使用说明）</label>
    <textarea id="s-dlt" name="dl_text" rows="4"><?= h($v('dl_text', $p['delivery']['text'])) ?></textarea>
    <p class="ferr"<?php if (!$err('dl_text')): ?> hidden<?php endif; ?>><?= h((string) $err('dl_text')) ?></p>
  </div>
  <div class="field<?= h($err('dl_link') ? ' has-err' : '') ?>">
    <label for="s-dll">链接（可留空）</label>
    <input id="s-dll" name="dl_link" type="url" inputmode="url" autocapitalize="off" spellcheck="false" placeholder="https://pan.baidu.com/s/…" value="<?= h($v('dl_link', $p['delivery']['link'])) ?>">
    <p class="ferr"<?php if (!$err('dl_link')): ?> hidden<?php endif; ?>><?= h((string) $err('dl_link')) ?></p>
  </div>
<?php endif; ?>

  <div class="bar bar-static">
    <button class="btn btn-primary btn-wide" type="submit">保存</button>
  </div>
</form>

<?php if (!$isSupply): ?>
<section class="card" aria-labelledby="g-file">
  <h2 class="card-h" id="g-file">数字商品：文件</h2>
  <p class="where">可以传一个文件（最大 <?= h((string) $file_mb) ?> MB），比如 PDF 或压缩包。文件放在网站后台的私有文件夹里，买家只能用「查订单」里 30 分钟有效的链接下载。更大的文件请放网盘，填上面的链接。</p>
<?php if ($file !== null): ?>
  <p><span class="chip chip-ok">已上传</span> <span class="mono"><?= h($file['name']) ?></span> <span class="muted"><?= h(shop_bytes((int) $file['bytes'])) ?></span></p>
  <form method="post" action="<?= h(url('shop.file_del')) ?>" data-confirm="删除这个文件？已经付款的买家也就下载不了了。" data-confirm-ok="删除" data-confirm-danger="1">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= h($p['id']) ?>">
    <button class="btn btn-ghost btn-danger" type="submit">删除文件</button>
  </form>
<?php endif; ?>
  <form class="up-form" method="post" action="<?= h(url('shop.file')) ?>" enctype="multipart/form-data" data-autosubmit>
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= h($p['id']) ?>">
    <label class="btn file-btn" for="dl-file"><?= h($file !== null ? '换一个文件' : '选一个文件') ?></label>
    <input class="vh" id="dl-file" name="file" type="file" accept="<?= h('.' . implode(',.', SHOP_FILE_EXT)) ?>">
    <button class="btn btn-primary" type="submit">上传文件</button>
  </form>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="g-del">
  <h2 class="card-h" id="g-del">删除</h2>
<?php if ($has_orders): ?>
  <p class="where">这件商品已经有订单了，不能删除（买家还要查订单）。不想卖了，取消勾选「上架」再保存。</p>
<?php else: ?>
  <form method="post" action="<?= h(url('shop.delete')) ?>" data-confirm="删除这件商品？照片说明和内容都会删掉，找不回来。" data-confirm-ok="删除" data-confirm-danger="1">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= h($p['id']) ?>">
    <button class="btn btn-ghost btn-danger" type="submit">删除这件商品</button>
  </form>
<?php endif; ?>
</section>
