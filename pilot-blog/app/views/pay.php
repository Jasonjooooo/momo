<?php
defined('BLOG_APP') || exit;
$ss = $settings['shop'];
$on = $ss['enabled'];
$val = fn(string $k, string $fallback) => array_key_exists($k, $form) ? (string) $form[$k] : $fallback;
$err = fn(string $k) => $errors[$k] ?? null;
$kinds = ['wechat' => '微信收款码', 'alipay' => '支付宝收款码'];
?>
<h1 class="page-h">收款设置</h1>

<section class="card" aria-labelledby="p-open">
  <h2 class="card-h" id="p-open">营业</h2>
  <p><?php if ($on): ?><span class="chip chip-ok">营业中</span> 访客可以下单。<?php else: ?><span class="chip chip-warn">暂停营业</span> 首页显示「暂停营业」，不能下新订单；已经下的订单照常处理。<?php endif; ?></p>
  <form method="post" action="<?= h(url('pay.save')) ?>"<?php if ($on): ?> data-confirm="暂停营业？首页会显示「暂停营业」，买家不能再下单。已经下的订单不受影响。" data-confirm-ok="暂停营业"<?php endif; ?>>
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="rev" value="<?= h((string) $settings['rev']) ?>">
    <input type="hidden" name="part" value="switch">
    <input type="hidden" name="enabled" value="<?= h($on ? '0' : '1') ?>">
    <button class="btn<?= h($on ? '' : ' btn-primary') ?>" type="submit"><?= h($on ? '暂停营业' : '开门营业') ?></button>
  </form>
</section>

<section class="card" aria-labelledby="p-qr">
  <h2 class="card-h" id="p-qr">收款码</h2>
  <p class="where">在微信「收付款 → 二维码收款」和支付宝「收钱」里保存收款码图片，传到这里。买家在付款页面看到它们。图片只用来付款，会原样显示，请确认是你自己的收款码。</p>
<?php foreach ($kinds as $k => $label): $cur = $ss['qr'][$k]; ?>
  <div class="qr-row">
    <h3 class="sub-h"><?= h($label) ?></h3>
<?php if ($cur !== null): ?>
    <img class="qr-img" src="<?= h(site_base() . $cur) ?>" alt="<?= h($label) ?>" width="160" height="160">
<?php else: ?>
    <p class="muted">还没有上传。</p>
<?php endif; ?>
    <div class="row-btns">
      <form class="up-form" method="post" action="<?= h(url('pay.qr')) ?>" enctype="multipart/form-data" data-autosubmit>
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="kind" value="<?= h($k) ?>">
        <label class="btn file-btn" for="qr-<?= h($k) ?>"><?= h($cur !== null ? '换一张' : '选择图片') ?></label>
        <input class="vh" id="qr-<?= h($k) ?>" name="image" type="file" accept="image/jpeg,image/png,image/webp">
        <button class="btn btn-primary" type="submit">上传</button>
      </form>
<?php if ($cur !== null): ?>
      <form method="post" action="<?= h(url('pay.qr_del')) ?>" data-confirm="<?= h('删除' . $label . '？买家就不能用它付款了。') ?>" data-confirm-ok="删除" data-confirm-danger="1">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="kind" value="<?= h($k) ?>">
        <button class="btn btn-ghost btn-danger" type="submit">删除</button>
      </form>
<?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</section>

<form class="card editor" method="post" action="<?= h(url('pay.save')) ?>" data-guard>
  <h2 class="card-h">运费和订单</h2>
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="rev" value="<?= h((string) $settings['rev']) ?>">
  <input type="hidden" name="part" value="rules">
  <div class="field-row">
    <div class="field<?= h($err('shipping') ? ' has-err' : '') ?>">
      <label for="p-ship">运费（元，每单，只算实物）</label>
      <div class="affix"><span>¥</span><input id="p-ship" name="shipping" type="text" inputmode="decimal" value="<?= h($val('shipping', shop_money($ss['shipping']))) ?>"></div>
      <p class="hint">不收运费填 0。</p>
      <p class="ferr"<?php if (!$err('shipping')): ?> hidden<?php endif; ?>><?= h((string) $err('shipping')) ?></p>
    </div>
    <div class="field<?= h($err('free_over') ? ' has-err' : '') ?>">
      <label for="p-free">满多少包邮（元）</label>
      <div class="affix"><span>¥</span><input id="p-free" name="free_over" type="text" inputmode="decimal" value="<?= h($val('free_over', shop_money($ss['free_over']))) ?>"></div>
      <p class="hint">商品金额到这个数就不收运费。填 0 表示不包邮。</p>
      <p class="ferr"<?php if (!$err('free_over')): ?> hidden<?php endif; ?>><?= h((string) $err('free_over')) ?></p>
    </div>
  </div>
  <div class="field<?= h($err('cancel_hours') ? ' has-err' : '') ?>">
    <label for="p-hours">没付款的订单多少小时后自动取消</label>
    <input id="p-hours" name="cancel_hours" type="text" inputmode="numeric" value="<?= h($val('cancel_hours', (string) $ss['cancel_hours'])) ?>">
    <p class="hint">取消以后库存会放回去。买家点了「我已付款」的订单不会自动取消，等你处理。</p>
    <p class="ferr"<?php if (!$err('cancel_hours')): ?> hidden<?php endif; ?>><?= h((string) $err('cancel_hours')) ?></p>
  </div>
  <div class="field<?= h($err('notice') ? ' has-err' : '') ?>">
    <label for="p-notice">公告（可留空）</label>
    <input id="p-notice" name="notice" type="text" maxlength="220" value="<?= h($val('notice', $ss['notice'])) ?>" placeholder="例如 10月1日到7日休息，8日统一发货">
    <p class="hint">显示在首页商城、商品页和下单页上面。</p>
    <p class="ferr"<?php if (!$err('notice')): ?> hidden<?php endif; ?>><?= h((string) $err('notice')) ?></p>
  </div>
  <div class="bar bar-static">
    <button class="btn btn-primary btn-wide" type="submit">保存</button>
  </div>
</form>
