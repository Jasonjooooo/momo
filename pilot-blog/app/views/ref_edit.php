<?php
defined('BLOG_APP') || exit;
// $p (推广人), $sum (ref_partner_summary), $settlements (newest first), $products (on sale), $to, $on, $form, $errors
$v = fn(string $k, string $fallback) => is_string($form[$k] ?? null) ? $form[$k] : $fallback;
$err = fn(string $k) => $errors[$k] ?? null;
$enabled = array_key_exists('enabled', $form) ? $form['enabled'] === '1' : $p['enabled'];
$due = array_values(array_filter($sum['lines'], fn($l) => $l['due'] !== 0));
$link = ref_link($p, $to);
?>
<p class="crumb"><a href="<?= h(url('ref')) ?>">推广</a> / <span><?= h($p['enabled'] ? '启用' : '停用') ?></span></p>
<h1 class="page-h"><?= h($p['name']) ?></h1>
<?php if (!$on): ?>
<p class="notice notice-warn">推广现在是关着的，新订单不会记给任何人。<a href="<?= h(url('ref')) ?>">去打开</a></p>
<?php elseif (!$p['enabled']): ?>
<p class="notice notice-warn">这位推广人已停用：链接还能打开，但新订单不再记给 TA。已经算好的佣金照常结算。</p>
<?php endif; ?>

<section class="card" aria-labelledby="re-link-h">
  <h2 class="card-h" id="re-link-h">推广链接</h2>
  <div class="copy-row">
    <label class="vh" for="re-link">推广链接</label>
    <input id="re-link" type="text" readonly value="<?= h($link) ?>" class="mono">
    <button class="btn btn-tool" type="button" data-copy="re-link">复制</button>
  </div>
  <form class="inline-form" method="get" action="<?= h(url('ref.edit')) ?>">
    <input type="hidden" name="r" value="ref.edit">
    <input type="hidden" name="id" value="<?= h($p['id']) ?>">
    <label for="re-to">链接打开</label>
    <select id="re-to" name="to">
      <option value=""<?php if ($to === ''): ?> selected<?php endif; ?>>首页的商城</option>
<?php foreach ($products as $x): $path = 'shop/' . $x['slug'] . '.html'; ?>
      <option value="<?= h($path) ?>"<?php if ($to === $path): ?> selected<?php endif; ?>><?= h('商品：' . $x['name']) ?></option>
<?php endforeach; ?>
    </select>
    <button class="btn btn-tool" type="submit">换链接</button>
  </form>
  <p class="where">推广码 <span class="mono"><?= h($p['code']) ?></span>。朋友点开链接后 30 天内下单，就记到 <?= h($p['name']) ?> 名下（以最后点的链接为准）。</p>

  <h3 class="sub-h">查看页（发给推广人自己）</h3>
  <div class="copy-row">
    <label class="vh" for="re-view">查看页链接</label>
    <input id="re-view" type="text" readonly value="<?= h(ref_view_link($p)) ?>" class="mono">
    <button class="btn btn-tool" type="button" data-copy="re-view">复制</button>
  </div>
  <p class="where">推广人用它看自己的点击、订单和佣金，不需要密码，所以只发给 TA 本人。链接传出去了的话，换一个：</p>
  <form method="post" action="<?= h(url('ref.token')) ?>" data-confirm="换一个新的查看页链接？旧链接马上打不开，要把新链接再发给推广人。" data-confirm-ok="换新链接">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= h($p['id']) ?>">
    <button class="btn btn-ghost" type="submit">换一个查看页链接</button>
  </form>
</section>

<section class="card" aria-labelledby="re-num-h">
  <h2 class="card-h" id="re-num-h">数字</h2>
  <dl class="kv kv-3 ref-kv">
    <div><dt>点击</dt><dd class="num"><?= h((string) $sum['clicks']) ?></dd></div>
    <div><dt>付款订单</dt><dd class="num"><?= h((string) $sum['orders']) ?></dd></div>
    <div><dt>成交额</dt><dd class="num"><?= h(biz_money($sum['sales'])) ?></dd></div>
    <div><dt>待确认</dt><dd class="num"><?= h(biz_money($sum['pending'])) ?></dd></div>
    <div><dt>可结算</dt><dd class="num<?= h($sum['settlable'] > 0 ? ' num-hot' : '') ?>" id="re-due"><?= h(biz_money($sum['settlable'])) ?></dd></div>
    <div><dt>已结算</dt><dd class="num" id="re-settled"><?= h(biz_money($sum['settled'])) ?></dd></div>
  </dl>
  <p class="where">成交额是买家付的商品金额（不含运费），只算已经付款、没取消的订单。</p>
</section>

<section class="card<?= h($sum['settlable'] > 0 ? ' card-hot' : '') ?>" aria-labelledby="re-set-h" id="re-settle">
  <h2 class="card-h" id="re-set-h">结算</h2>
<?php if ($sum['settlable'] > 0): ?>
  <p>可结算 <b class="mono amt-mid"><?= h(biz_money($sum['settlable'])) ?></b>（<?= h(count($due) . ' 单') ?>）</p>
  <ul class="vlist hist ref-due">
<?php foreach ($due as $l): ?>
    <li><a class="mono" href="<?= h(url('order', ['id' => $l['o']['id']])) ?>"><?= h($l['o']['id']) ?></a> <span><?= h($l['o']['name'] . ' × ' . $l['o']['qty']) ?></span> <span class="mono"><?= h(($l['due'] < 0 ? '退款扣回 ' : '') . biz_money($l['due'])) ?></span></li>
<?php endforeach; ?>
  </ul>
  <p class="where">先在微信或支付宝里把这笔钱转给 <?= h($p['name']) ?>，转好了再点下面的按钮记下来。网站不会替你转钱。</p>
  <form class="stack" method="post" action="<?= h(url('ref.settle')) ?>" data-confirm="<?= h('确认已经私下转给 ' . $p['name'] . ' ' . biz_money($sum['settlable']) . '？记下以后这些订单就算结清了。') ?>" data-confirm-ok="标记已结算">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= h($p['id']) ?>">
    <input type="hidden" name="amount" value="<?= h((string) $sum['settlable']) ?>">
    <div class="field">
      <label for="re-note">备注（推广人能看到，可以不填）</label>
      <input id="re-note" name="note" type="text" maxlength="<?= h((string) REF_NOTE_MAX) ?>" placeholder="例如 10月 微信转账">
    </div>
    <button class="btn btn-primary btn-wide" type="submit" id="re-settle-btn"><?= h('标记已结算 ' . biz_money($sum['settlable'])) ?></button>
  </form>
<?php elseif ($sum['settlable'] < 0): ?>
  <p><?= h('现在是 ' . biz_money($sum['settlable']) . '：结算以后有订单退款了，多付的佣金会从下次结算里扣回。') ?></p>
<?php else: ?>
  <p class="muted">现在没有可结算的佣金。订单完成以后，佣金才会变成可结算。</p>
<?php endif; ?>
<?php if ($settlements): ?>
  <h3 class="sub-h">结算记录</h3>
  <ol class="vlist hist" id="re-log">
<?php foreach ($settlements as $s): ?>
    <li><span class="mono"><?= h(cn_time($s['at'])) ?></span> <span class="mono"><?= h(biz_money($s['amount'])) ?></span> <span><?= h(count($s['items']) . ' 单' . ($s['note'] !== '' ? '，' . $s['note'] : '')) ?></span></li>
<?php endforeach; ?>
  </ol>
  <div class="row-btns"><a class="btn btn-ghost" href="<?= h(url('ref.export')) ?>">导出全部结算记录（CSV）</a></div>
<?php endif; ?>
</section>

<section class="card" aria-labelledby="re-ord-h">
  <h2 class="card-h" id="re-ord-h">订单</h2>
<?php if (!$sum['lines']): ?>
  <p class="muted">还没有从这个链接来的订单。</p>
<?php else: ?>
  <ul class="vlist hist" id="re-orders">
<?php foreach (array_slice($sum['lines'], 0, 100) as $l): $c = $l['c']; ?>
    <li><a class="mono" href="<?= h(url('order', ['id' => $l['o']['id']])) ?>"><?= h($l['o']['id']) ?></a> <span><?= h($l['o']['name'] . ' × ' . $l['o']['qty'] . '，¥' . shop_money((int) $l['o']['subtotal'])) ?></span> <span class="chip<?= h($c['state'] === 'ok' ? ' chip-ok' : ($c['state'] === 'pending' ? ' chip-warn' : '')) ?>"><?= h(REF_STATE[$c['state']]) ?></span> <span class="mono"><?= h('佣金 ' . biz_money($c['amount']) . '（' . ref_rate_text($c['rate_bp']) . '）' . ($l['settled'] !== 0 ? '，已结 ' . biz_money($l['settled']) : '')) ?></span></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>

<section class="card" aria-labelledby="re-edit-h">
  <h2 class="card-h" id="re-edit-h">修改</h2>
  <form class="stack" method="post" action="<?= h(url('ref.save')) ?>">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= h($p['id']) ?>">
    <input type="hidden" name="rev" value="<?= h((string) $p['rev']) ?>">
    <div class="field<?= h($err('name') ? ' has-err' : '') ?>">
      <label for="re-name">称呼</label>
      <input id="re-name" name="name" type="text" maxlength="<?= h((string) (REF_NAME_MAX + 10)) ?>" value="<?= h($v('name', $p['name'])) ?>">
      <p class="ferr"<?php if (!$err('name')): ?> hidden<?php endif; ?>><?= h((string) $err('name')) ?></p>
    </div>
    <div class="field<?= h($err('contact') ? ' has-err' : '') ?>">
      <label for="re-contact">联系方式（只有你能看到）</label>
      <input id="re-contact" name="contact" type="text" maxlength="<?= h((string) (REF_CONTACT_MAX + 10)) ?>" value="<?= h($v('contact', $p['contact'])) ?>">
      <p class="ferr"<?php if (!$err('contact')): ?> hidden<?php endif; ?>><?= h((string) $err('contact')) ?></p>
    </div>
    <div class="field<?= h($err('rate') ? ' has-err' : '') ?>">
      <label for="re-rate">佣金比例</label>
      <div class="affix"><input id="re-rate" name="rate" type="text" inputmode="decimal" value="<?= h($v('rate', rtrim(ref_rate_text($p['rate_bp']), '%'))) ?>"><span>%</span></div>
      <p class="hint">0 到 50。改了只对以后的订单有效，已经下的订单还按下单时的比例算。</p>
      <p class="ferr"<?php if (!$err('rate')): ?> hidden<?php endif; ?>><?= h((string) $err('rate')) ?></p>
    </div>
    <label class="check"><input type="checkbox" name="enabled" value="1"<?php if ($enabled): ?> checked<?php endif; ?>> 启用（停用以后链接还能打开，但新订单不记给 TA）</label>
    <button class="btn btn-primary" type="submit">保存</button>
  </form>
</section>
