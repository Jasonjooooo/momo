<?php
defined('BLOG_APP') || exit;
$ways = [0 => '自动发卡', 1 => '人工发货（供货方手动发，可能要等）'];
$err = fn(string $k) => $errors[$k] ?? null;
$posted = ($form['_posted'] ?? '') === '1';
$v = fn(string $k, string $fallback) => $posted && is_string($form[$k] ?? null) ? $form[$k] : $fallback;
?>
<p class="crumb"><a href="<?= h(url('supply')) ?>">货源</a> / <span>上架</span></p>
<?php if (!$d['ok']): ?>
<h1 class="page-h">读不到这件商品</h1>
<p class="notice notice-err"><?= h($d['msg']) ?></p>
<p><a class="btn" href="<?= h(url('supply.item', ['code' => $code, 'fresh' => '1'])) ?>">再试一次</a> <a class="btn btn-ghost" href="<?= h(url('supply')) ?>">回到货源</a></p>
<?php else: $it = $d['item']; $cost = supply_item_cost($it); [$qMin, $qMax] = supply_qty_bounds(['supply' => supply_link_normalize(['min' => $it['minimum'], 'max' => $it['maximum']])]); ?>
<h1 class="page-h"><?= h($it['name'] !== '' ? $it['name'] : '（没有名字）') ?></h1>

<section class="card" aria-labelledby="i-info">
  <h2 class="card-h" id="i-info">供货平台上的信息</h2>
  <dl class="kv">
    <div><dt>成本（你的进货价）</dt><dd class="mono"><?= h($cost !== null ? '¥' . shop_money($cost) : '未知') ?></dd></div>
    <div><dt>库存</dt><dd class="mono"><?= h((string) $it['stock']) ?></dd></div>
    <div><dt>发货方式</dt><dd><?= h($ways[$it['delivery_way']] ?? '其他') ?></dd></div>
    <div><dt>每单数量</dt><dd class="mono"><?= h($qMin === $qMax ? $qMin . ' 件' : $qMin . ' 到 ' . $qMax . ' 件') ?></dd></div>
    <div><dt>商品编号</dt><dd class="mono sel"><?= h($it['code']) ?></dd></div>
  </dl>
<?php if ($it['races']): ?>
  <h3 class="sub-h">种类</h3>
  <ul class="vlist">
<?php foreach ($it['races'] as $rn => $rp): ?>
    <li><?= h($rn . ($rp !== null ? '：成本 ¥' . shop_money($rp) : '')) ?></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php if ($it['delivery_way'] === 1): ?>
  <p class="where">这件是人工发货：进货以后要等供货方手动发卡，卡密到了买家才能看到。网站会在你或买家打开订单时再去问一次。</p>
<?php endif; ?>
<?php if ($linked): ?>
  <p class="where"><?= h('这件已经上架过：' . implode('、', array_map(fn($p) => '《' . $p['name'] . '》' . ($p['supply']['race'] !== '' ? '（' . $p['supply']['race'] . '）' : ''), $linked)) . '。同一个种类只能上架一次。') ?></p>
<?php endif; ?>
</section>

<?php if ($why): ?>
<section class="card" aria-labelledby="i-no">
  <h2 class="card-h" id="i-no">暂不支持</h2>
  <p>这件商品现在还不能放进商城，原因：</p>
  <ul class="vlist">
<?php foreach ($why as $w): ?>
    <li><?= h($w . '。') ?></li>
<?php endforeach; ?>
  </ul>
  <p><a class="btn" href="<?= h(url('supply')) ?>">回到货源，换一件</a></p>
</section>
<?php else: ?>
<form class="card editor" method="post" action="<?= h(url('supply.import')) ?>" data-guard novalidate>
  <h2 class="card-h">上架到商城</h2>
  <p class="where">买家看到的是你在这里填的名字、售价和介绍，看不到供货平台的名字、成本和编号。照片先用一张默认的卡片图，上架以后可以在商品里换。</p>
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="code" value="<?= h($it['code']) ?>">
<?php if ($it['races']): ?>
  <div class="field<?= h($err('race') ? ' has-err' : '') ?>">
    <label for="i-race">种类</label>
    <select id="i-race" name="race">
<?php foreach ($it['races'] as $rn => $rp): ?>
      <option value="<?= h($rn) ?>"<?php if ($v('race', '') === $rn): ?> selected<?php endif; ?>><?= h($rn . ($rp !== null ? '（成本 ¥' . shop_money($rp) . '）' : '')) ?></option>
<?php endforeach; ?>
    </select>
    <p class="hint">一个种类上架成一件商品。想卖几个种类，就上架几次。</p>
    <p class="ferr"<?php if (!$err('race')): ?> hidden<?php endif; ?>><?= h((string) $err('race')) ?></p>
  </div>
<?php endif; ?>
  <div class="field<?= h($err('name') ? ' has-err' : '') ?>">
    <label for="i-name">商品名称（买家看到的）</label>
    <input id="i-name" name="name" type="text" maxlength="60" value="<?= h($v('name', mb_substr($it['name'], 0, 40))) ?>">
    <p class="ferr"<?php if (!$err('name')): ?> hidden<?php endif; ?>><?= h((string) $err('name')) ?></p>
  </div>
  <div class="field<?= h($err('price') ? ' has-err' : '') ?>">
    <label for="i-price">售价（元，每件）</label>
    <div class="affix"><span>¥</span><input id="i-price" name="price" type="text" inputmode="decimal" value="<?= h($v('price', $cost !== null ? shop_money((int) ceil($cost * 1.1)) : '')) ?>" placeholder="30.00"></div>
    <p class="hint"><?= h($cost !== null ? '成本 ¥' . shop_money($cost) . '（选了种类的话按种类算）。售价不能低于成本；默认填了成本加一成，按你的想法改。' : '先确认成本，售价不能低于成本。') ?></p>
    <p class="ferr"<?php if (!$err('price')): ?> hidden<?php endif; ?>><?= h((string) $err('price')) ?></p>
  </div>
  <div class="field<?= h($err('body') ? ' has-err' : '') ?>">
    <label for="i-body">介绍（可以改）</label>
    <p class="where">默认是供货平台的介绍转成的纯文字。写法和日志一样，可以加「- 列表」和「**加粗**」。不要写供货平台的名字。</p>
    <textarea id="i-body" name="body" rows="8"><?= h($v('body', $it['description'])) ?></textarea>
    <p class="ferr"<?php if (!$err('body')): ?> hidden<?php endif; ?>><?= h((string) $err('body')) ?></p>
  </div>
  <label class="check check-big"><input type="checkbox" name="on_sale" value="1"<?php if (!$posted || ($form['on_sale'] ?? '') === '1'): ?> checked<?php endif; ?>> 马上上架（在首页商城显示，可以下单）</label>
  <div class="bar bar-static">
    <button class="btn btn-primary btn-wide" type="submit">上架</button>
  </div>
</form>
<?php endif; ?>
<?php endif; ?>
