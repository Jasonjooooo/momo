<?php
defined('BLOG_APP') || exit;
// $partners, $ov (ref_overview), $on, $settings, $form/$errors (add form after a refusal)
$v = fn(string $k) => is_string($form[$k] ?? null) ? $form[$k] : '';
$err = fn(string $k) => $errors[$k] ?? null;
?>
<h1 class="page-h">推广</h1>
<div class="notice notice-info" id="ref-rules">
  <p>推广人把你的推广链接发给朋友，朋友点开后 30 天内在本站下单（以最后点的链接为准），这一单就记到推广人名下，按 TA 的佣金比例算佣金。</p>
  <p>只有一级：推广人再找来的人不算，没有「下线」。佣金只在订单完成以后才能结算，由你自己私下转给推广人，网站不经手钱，没有钱包和提现。推广人自己下单（手机号或邮箱和 TA 的联系方式一样）不计佣金。</p>
  <p>推广人的查看页只显示点击、订单数、金额和佣金，不显示买家的姓名、手机、地址和邮箱，订单号只显示一部分。</p>
</div>

<section class="card" aria-labelledby="ref-sw-h">
  <h2 class="card-h" id="ref-sw-h">开关</h2>
  <p><?php if ($on): ?><span class="chip chip-ok">开着</span> 从推广链接进来的订单会记到推广人名下。<?php else: ?><span class="chip">关着</span> 推广链接还能打开（会直接进网站），但新订单不记给任何人。<?php endif; ?></p>
  <form method="post" action="<?= h(url('ref.toggle')) ?>"<?php if ($on): ?> data-confirm="关掉以后，新订单不再记到推广人名下。已经算好的佣金照常结算。" data-confirm-ok="关掉推广"<?php endif; ?>>
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="rev" value="<?= h((string) $settings['rev']) ?>">
    <input type="hidden" name="enabled" value="<?= h($on ? '0' : '1') ?>">
    <button class="btn<?= h($on ? '' : ' btn-primary') ?>" type="submit" id="ref-toggle"><?= h($on ? '关掉推广' : '打开推广') ?></button>
  </form>
</section>

<section class="card<?= h($ov['settlable'] > 0 ? ' card-hot' : '') ?>" aria-labelledby="ref-sum-h">
  <h2 class="card-h" id="ref-sum-h">佣金</h2>
  <dl class="kv kv-2">
    <div><dt>待结算（订单已完成）</dt><dd class="num<?= h($ov['settlable'] > 0 ? ' num-hot' : '') ?>" id="ref-due"><?= h(biz_money($ov['settlable'])) ?></dd></div>
    <div><dt>待确认（已付款，订单没完成）</dt><dd class="num"><?= h(biz_money($ov['pending'])) ?></dd></div>
  </dl>
  <p class="where">结算：先在微信或支付宝里把钱转给推广人，再打开这位推广人，点「标记已结算」记下来。</p>
<?php if ($n_settlements > 0): ?>
  <div class="row-btns"><a class="btn" href="<?= h(url('ref.export')) ?>">导出结算记录（CSV）</a></div>
<?php endif; ?>
</section>

<h2 class="sub-h">推广人</h2>
<?php if (!$partners): ?>
<p class="card muted">还没有推广人。在下面添加第一位。</p>
<?php else: ?>
<ul class="plist ref-list">
<?php foreach ($partners as $p): $s = $ov['by'][$p['id']]; $lid = 'rl-' . $p['id']; ?>
  <li class="card ref-card" id="<?= h('rp-' . $p['code']) ?>">
    <div class="ref-head">
      <a class="ref-name" href="<?= h(url('ref.edit', ['id' => $p['id']])) ?>"><?= h($p['name']) ?></a>
      <span class="chip <?= h($p['enabled'] ? 'chip-ok' : '') ?>"><?= h($p['enabled'] ? '启用' : '停用') ?></span>
      <span class="chip chip-line"><?= h('佣金 ' . ref_rate_text($p['rate_bp'])) ?></span>
    </div>
    <div class="copy-row">
      <label class="vh" for="<?= h($lid) ?>"><?= h($p['name'] . ' 的推广链接') ?></label>
      <input id="<?= h($lid) ?>" type="text" readonly value="<?= h(ref_link($p)) ?>" class="mono">
      <button class="btn btn-tool" type="button" data-copy="<?= h($lid) ?>">复制</button>
    </div>
    <dl class="kv kv-3 ref-kv">
      <div><dt>点击</dt><dd class="num"><?= h((string) $s['clicks']) ?></dd></div>
      <div><dt>订单</dt><dd class="num"><?= h((string) $s['orders']) ?></dd></div>
      <div><dt>成交额</dt><dd class="num"><?= h(biz_money($s['sales'])) ?></dd></div>
      <div><dt>待确认</dt><dd class="num"><?= h(biz_money($s['pending'])) ?></dd></div>
      <div><dt>可结算</dt><dd class="num<?= h($s['settlable'] > 0 ? ' num-hot' : '') ?>"><?= h(biz_money($s['settlable'])) ?></dd></div>
      <div><dt>已结算</dt><dd class="num"><?= h(biz_money($s['settled'])) ?></dd></div>
    </dl>
    <div class="row-btns">
      <a class="btn<?= h($s['settlable'] > 0 ? ' btn-primary' : '') ?>" href="<?= h(url('ref.edit', ['id' => $p['id']])) ?>"><?= h($s['settlable'] > 0 ? '结算和管理' : '管理') ?></a>
    </div>
  </li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<section class="card" aria-labelledby="ref-add-h" id="ref-add">
  <h2 class="card-h" id="ref-add-h">添加推广人</h2>
  <form class="stack" method="post" action="<?= h(url('ref.add')) ?>">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <div class="field<?= h($err('name') ? ' has-err' : '') ?>">
      <label for="ra-name">称呼</label>
      <input id="ra-name" name="name" type="text" maxlength="<?= h((string) (REF_NAME_MAX + 10)) ?>" value="<?= h($v('name')) ?>" placeholder="例如 老张" autocomplete="off">
      <p class="hint">推广人自己的查看页上会显示这个称呼，最多 <?= h((string) REF_NAME_MAX) ?> 个字。</p>
      <p class="ferr"<?php if (!$err('name')): ?> hidden<?php endif; ?>><?= h((string) $err('name')) ?></p>
    </div>
    <div class="field<?= h($err('contact') ? ' has-err' : '') ?>">
      <label for="ra-contact">联系方式（只有你能看到）</label>
      <input id="ra-contact" name="contact" type="text" maxlength="<?= h((string) (REF_CONTACT_MAX + 10)) ?>" value="<?= h($v('contact')) ?>" placeholder="手机号、微信号或邮箱" autocomplete="off">
      <p class="hint">填手机号或邮箱的话，TA 用这个手机号或邮箱自己下单不计佣金。</p>
      <p class="ferr"<?php if (!$err('contact')): ?> hidden<?php endif; ?>><?= h((string) $err('contact')) ?></p>
    </div>
    <div class="field<?= h($err('rate') ? ' has-err' : '') ?>">
      <label for="ra-rate">佣金比例</label>
      <div class="affix"><input id="ra-rate" name="rate" type="text" inputmode="decimal" value="<?= h($v('rate')) ?>" placeholder="例如 5"><span>%</span></div>
      <p class="hint">0 到 50。按买家付的商品金额算（不含运费），以后可以改，改了只对新订单有效。</p>
      <p class="ferr"<?php if (!$err('rate')): ?> hidden<?php endif; ?>><?= h((string) $err('rate')) ?></p>
    </div>
    <button class="btn btn-primary" type="submit">添加推广人</button>
  </form>
</section>
