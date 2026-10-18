<?php
defined('BLOG_APP') || exit;
$st = $o['status'];
$b = $o['buyer'];
$amount = '¥' . shop_money((int) $o['amount']);
$red = $o['redacted_at'] !== null;
$who = ['buyer' => '买家', 'owner' => '你', 'auto' => '自动'];
$isSupply = $o['type'] === 'supply';
$sp = $isSupply ? supply_order_normalize($o['supply'] ?? null) : null;
$bought = $isSupply && $sp['trade_no'] !== '';
?>
<p class="crumb"><a href="<?= h(url('orders')) ?>">订单</a> / <span class="mono"><?= h($o['id']) ?></span></p>
<h1 class="page-h"><?= h($o['name'] . ' × ' . $o['qty']) ?></h1>

<section class="card card-amt" aria-labelledby="o-amt">
  <h2 class="card-h" id="o-amt">应付金额</h2>
  <p class="amt-big mono"><?= h($amount) ?></p>
  <p class="where"><?= h('商品 ¥' . shop_money((int) $o['subtotal']) . ($o['type'] === 'physical' ? ' + 运费 ¥' . shop_money((int) $o['shipping']) : '') . ' + 识别尾数 ¥' . shop_money((int) $o['offset'])) ?></p>
  <p><span class="chip <?= h(ORDER_CHIP[$st] ?? '') ?>"><?= h(SHOP_STATUS[$st]) ?></span> <span class="chip chip-line"><?= h(SHOP_TYPES[$o['type']]) ?></span> <span class="mono"><?= h($o['id']) ?></span></p>
<?php if ($st === 'pending' || $st === 'claimed'): ?>
  <p class="where"><?= h('核对：微信或支付宝里收到的金额应该正好是 ' . $amount . '。' . ($st === 'claimed' ? '买家已经点了「我已付款」' . ($o['payer'] !== '' ? '，付款备注：' . $o['payer'] : '') . '。' : '买家还没点「我已付款」，但钱可能已经到了。') . ' 没付款的订单 ' . cn_time((string) $o['expires_at']) . ' 自动取消。') ?></p>
<?php endif; ?>
</section>

<section class="card" aria-labelledby="o-act">
  <h2 class="card-h" id="o-act">操作</h2>
<?php if (in_array($st, ['pending', 'claimed'], true) || ($st === 'cancelled' && !empty($o['cancel_auto']) && !$red)): ?>
  <form method="post" action="<?= h(url('order.act')) ?>" data-confirm="<?= h('确认已经在微信或支付宝收到 ' . $amount . '？' . ($o['type'] === 'digital' ? '确认以后买家马上能看到内容。' : '') . ($isSupply ? '确认以后网站马上从货源进货（扣你在供货平台的余额），卡密自动显示给买家。' : '')) ?>" data-confirm-ok="确认收款">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= h($o['id']) ?>">
    <input type="hidden" name="act" value="confirm">
    <button class="btn btn-primary btn-wide" type="submit"><?= h($st === 'cancelled' ? '钱到了：恢复订单并确认收款' : '确认收款') ?></button>
  </form>
<?php if ($st === 'cancelled'): ?>
  <p class="where">这个订单因为超时没付款被自动取消了。如果之后钱还是到了，可以在这里恢复（库存够的话）。</p>
<?php endif; ?>
<?php endif; ?>
<?php if ($o['type'] === 'physical' && in_array($st, ['confirmed', 'shipped'], true)): ?>
  <form class="stack" method="post" action="<?= h(url('order.act')) ?>">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= h($o['id']) ?>">
    <input type="hidden" name="act" value="ship">
    <div class="field">
      <label for="o-courier">快递公司</label>
      <input id="o-courier" name="courier" type="text" maxlength="20" value="<?= h($o['courier']) ?>" placeholder="例如 顺丰" list="couriers">
      <datalist id="couriers"><option value="顺丰"><option value="中通"><option value="圆通"><option value="韵达"><option value="申通"><option value="京东"><option value="EMS"><option value="邮政"></datalist>
    </div>
    <div class="field">
      <label for="o-track">快递单号</label>
      <input id="o-track" name="tracking" type="text" maxlength="40" autocapitalize="characters" spellcheck="false" value="<?= h($o['tracking']) ?>">
    </div>
    <button class="btn btn-primary btn-wide" type="submit"><?= h($st === 'shipped' ? '改快递信息' : '填好了，发货') ?></button>
  </form>
<?php endif; ?>
<?php if ($st === 'shipped'): ?>
  <form method="post" action="<?= h(url('order.act')) ?>" data-confirm="买家已经收到货了？标记完成以后不能再改。" data-confirm-ok="标记完成">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= h($o['id']) ?>">
    <input type="hidden" name="act" value="complete">
    <button class="btn btn-wide" type="submit">完成</button>
  </form>
  <p class="where">发货 <?= h((string) SHOP_AUTOCOMPLETE_DAYS) ?> 天后会自动完成。</p>
<?php endif; ?>
<?php if (in_array($st, ['pending', 'claimed', 'confirmed', 'failed'], true) && !$bought && !($isSupply && $sp['state'] === 'trading')): ?>
  <form class="stack" method="post" action="<?= h(url('order.act')) ?>" data-confirm="取消这个订单？库存会放回去。买家查订单时会看到取消原因。" data-confirm-ok="取消订单" data-confirm-danger="1">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= h($o['id']) ?>">
    <input type="hidden" name="act" value="cancel">
    <div class="field">
      <label for="o-reason">取消原因（买家会看到）</label>
      <input id="o-reason" name="reason" type="text" maxlength="60" placeholder="例如 没有收到付款 / 缺货，已原路退款">
    </div>
    <button class="btn btn-ghost btn-danger" type="submit">取消订单</button>
  </form>
<?php endif; ?>
<?php if ($st === 'completed' || ($st === 'cancelled' && empty($o['cancel_auto']))): ?>
  <p class="muted">这个订单已经结束了。</p>
<?php endif; ?>
</section>

<?php if ($isSupply && !in_array($st, ['pending', 'claimed'], true) && !($st === 'cancelled' && $sp['state'] === 'new')): ?>
<section class="card<?= h($st === 'failed' ? ' card-hot' : '') ?>" aria-labelledby="o-sup">
  <h2 class="card-h" id="o-sup">进货（买家看不到这里）</h2>
  <p><span class="chip <?= h($sp['state'] === 'done' ? 'chip-ok' : ($st === 'failed' ? 'chip-hot' : 'chip-warn')) ?>"><?= h($sp['state'] === 'done' && $sp['pending'] ? '已进货，等卡密' : (SUPPLY_STATE[$sp['state']] ?? $sp['state'])) ?></span></p>
<?php if ($st === 'failed' && $sp['msg'] !== ''): ?>
  <p class="notice notice-err"><?= h($sp['msg']) ?></p>
  <p class="where">买家查订单时看到的是「已确认收款，正在发货」。处理好以后重试；处理不了就取消订单，在微信或支付宝里把钱退给买家，再记录退款。</p>
<?php endif; ?>
  <dl class="kv">
<?php if ($sp['trade_no'] !== ''): ?>
    <div><dt>供货平台订单号</dt><dd class="mono sel"><?= h($sp['trade_no']) ?></dd></div>
    <div><dt>进货花了</dt><dd class="mono"><?= h('¥' . shop_money($sp['amount'])) ?></dd></div>
    <div><dt>这单毛利</dt><dd class="mono"><?= h('¥' . shop_money((int) $o['subtotal'] - $sp['amount'])) ?></dd></div>
<?php elseif ($sp['cost_now'] > 0): ?>
    <div><dt>现在的成本</dt><dd class="mono"><?= h('¥' . shop_money($sp['cost_now']) . '（买家付的商品金额 ¥' . shop_money((int) $o['subtotal']) . '）') ?></dd></div>
<?php endif; ?>
<?php if ($sp['request_no'] !== ''): ?>
    <div><dt>请求号</dt><dd class="mono sel"><?= h($sp['request_no']) ?></dd></div>
<?php endif; ?>
<?php if ($sp['at'] !== null): ?>
    <div><dt>最后一次</dt><dd class="mono"><?= h(cn_time($sp['at'])) ?></dd></div>
<?php endif; ?>
  </dl>
<?php if ($bought && $sp['secret'] !== ''): ?>
  <details class="help">
    <summary><?= h($sp['pending'] ? '供货平台的说明' : '卡密（买家查订单时能看到）') ?></summary>
    <div class="mono sel card-secret-admin"><?php foreach (explode("\n", $sp['secret']) as $line): ?><p><?= h($line) ?></p><?php endforeach; ?></div>
  </details>
<?php endif; ?>
<?php if ($st === 'failed' && $sp['state'] === 'unclear'): ?>
  <form method="post" action="<?= h(url('order.act')) ?>" data-confirm="确定在供货平台上找过了，这一单没有买成？确认以后会用新的请求号再买一次。如果其实买成了，就会买两张。" data-confirm-ok="确认没买成，重新进货" data-confirm-danger="1">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= h($o['id']) ?>">
    <input type="hidden" name="act" value="fresh">
    <button class="btn btn-primary btn-wide" type="submit">确认没买成，重新进货</button>
  </form>
<?php elseif ($st === 'failed' || ($st === 'confirmed' && !$bought && $sp['state'] !== 'trading')): ?>
<?php $canAccept = $sp['state'] === 'blocked' && $sp['cost_now'] > 0 && $sp['cost_now'] <= (int) $o['subtotal'] && $sp['cost_now'] > $sp['accept_cost']; ?>
<?php if ($canAccept): ?>
  <form method="post" action="<?= h(url('order.act')) ?>" data-confirm="<?= h('按新成本 ¥' . shop_money($sp['cost_now']) . ' 进货？这一单的毛利会变成 ¥' . shop_money((int) $o['subtotal'] - $sp['cost_now']) . '。') ?>" data-confirm-ok="按新成本进货">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= h($o['id']) ?>">
    <input type="hidden" name="act" value="accept">
    <input type="hidden" name="cost" value="<?= h((string) $sp['cost_now']) ?>">
    <button class="btn btn-primary btn-wide" type="submit"><?= h('按新成本 ¥' . shop_money($sp['cost_now']) . ' 进货') ?></button>
  </form>
<?php endif; ?>
  <form method="post" action="<?= h(url('order.act')) ?>" data-confirm="重新从货源进货？会扣你在供货平台的余额。" data-confirm-ok="重试进货">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= h($o['id']) ?>">
    <input type="hidden" name="act" value="retry">
    <button class="btn btn-wide<?= h($canAccept ? '' : ' btn-primary') ?>" type="submit">重试进货</button>
  </form>
<?php if ($sp['state'] === 'unknown'): ?>
  <p class="where">重试会用同一个请求号，就算上一次其实买成了，供货平台也不会再扣一次钱。</p>
<?php endif; ?>
<?php elseif ($bought && $sp['pending']): ?>
  <form method="post" action="<?= h(url('order.act')) ?>">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= h($o['id']) ?>">
    <input type="hidden" name="act" value="poll">
    <button class="btn btn-wide" type="submit">刷新卡密</button>
  </form>
  <p class="where">这件是人工发货，供货方发卡以后，你或买家打开订单时会自动取到卡密。</p>
<?php endif; ?>
</section>
<?php endif; ?>

<?php if ($o['type'] === 'physical' && $o['tracking'] !== ''): ?>
<section class="card" aria-labelledby="o-ship">
  <h2 class="card-h" id="o-ship">快递</h2>
  <dl class="kv"><div><dt>快递公司</dt><dd><?= h($o['courier']) ?></dd></div><div><dt>快递单号</dt><dd class="mono sel"><?= h($o['tracking']) ?></dd></div></dl>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="o-buyer">
  <h2 class="card-h" id="o-buyer">买家</h2>
<?php if ($red): ?>
  <p class="muted"><?= h('订单结束 ' . SHOP_REDACT_DAYS . ' 天后，买家的个人信息已经自动删除（' . cn_time((string) $o['redacted_at'], false) . '）。') ?></p>
<?php else: ?>
  <dl class="kv">
<?php if ($o['type'] === 'physical'): ?>
    <div><dt>收货人</dt><dd class="sel"><?= h($b['name']) ?></dd></div>
    <div><dt>手机号</dt><dd><a class="mono" href="<?= h('tel:' . $b['phone']) ?>"><?= h($b['phone']) ?></a></dd></div>
    <div><dt>收货地址</dt><dd class="sel"><?= h($b['address']) ?></dd></div>
<?php else: ?>
    <div><dt>邮箱</dt><dd class="sel mono"><?= h($b['email']) ?></dd></div>
<?php endif; ?>
<?php if ($o['note'] !== ''): ?>
    <div><dt>买家备注</dt><dd><?= h($o['note']) ?></dd></div>
<?php endif; ?>
<?php if ($o['payer'] !== ''): ?>
    <div><dt>付款备注</dt><dd><?= h($o['payer']) ?></dd></div>
<?php endif; ?>
  </dl>
  <p class="where">这些信息只用于这个订单。订单完成或取消 <?= h((string) SHOP_REDACT_DAYS) ?> 天后会自动删除。</p>
<?php endif; ?>
</section>

<section class="card" aria-labelledby="o-hist">
  <h2 class="card-h" id="o-hist">进度</h2>
  <ol class="vlist hist">
<?php foreach ($o['history'] as $h): ?>
    <li><span class="mono"><?= h(cn_time($h['t'])) ?></span> <span><?= h((SHOP_STATUS[$h['s']] ?? $h['s']) . '（' . ($who[$h['by']] ?? $h['by']) . '）' . ($h['note'] !== '' ? '：' . $h['note'] : '')) ?></span></li>
<?php endforeach; ?>
  </ol>
<?php if ($o['cancel_reason'] !== '' && $st === 'cancelled'): ?>
  <p><?= h('取消原因：' . $o['cancel_reason']) ?></p>
<?php endif; ?>
</section>

<section class="card" aria-labelledby="o-ref">
  <h2 class="card-h" id="o-ref">退款记录</h2>
<?php foreach ($o['refunds'] as $r): ?>
  <p><span class="mono"><?= h(cn_time($r['t'])) ?></span> <?= h($r['note']) ?></p>
<?php endforeach; ?>
<?php if ($st !== 'pending'): ?>
  <p class="where">退款要你自己在微信或支付宝里操作。退了以后在这里记一笔，买家查订单时能看到。不要写买家的个人信息。</p>
  <form class="stack" method="post" action="<?= h(url('order.act')) ?>">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= h($o['id']) ?>">
    <input type="hidden" name="act" value="refund">
    <div class="field">
      <label for="o-note">退款记录</label>
      <input id="o-note" name="note" type="text" maxlength="100" placeholder="<?= h('例如 已退 ' . $amount . '，微信原路退回') ?>">
    </div>
    <button class="btn" type="submit">记录退款</button>
  </form>
<?php else: ?>
  <p class="muted">还没付款的订单不用记录退款。</p>
<?php endif; ?>
</section>
<?php if ($product !== null): ?>
<p><a class="btn btn-ghost" href="<?= h(url('shop.edit', ['id' => $product['id']])) ?>">这件商品</a></p>
<?php endif; ?>
