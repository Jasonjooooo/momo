<?php
defined('BLOG_APP') || exit;
// $open / $done: unmatched USDT transfers (usdt.php), newest first. $scan: last check per chain. $ready: chains switched on.
$amt = fn(array $x) => usdt_units_text((string) $x['units'], USDT_CHAINS[$x['chain']]['decimals']);
$when = fn(?int $t) => $t ? cn_time(gmdate('Y-m-d\TH:i:s\Z', $t)) : '-';
$short = fn(string $s) => strlen($s) > 20 ? substr($s, 0, 10) . '…' . substr($s, -8) : $s;
?>
<p class="crumb"><a href="<?= h(url('orders')) ?>">订单</a> / <span>USDT 到账</span></p>
<h1 class="page-h">USDT 到账</h1>
<p class="where">金额、链或时间和订单对不上的 USDT 到账都放在这里，网站<b>不会</b>拿它们自动确认任何订单。核对后，可以手动记到某个订单上（订单就确认收款），或者标记为「不是订单付款」。要退款的话，在你自己的钱包里转回去。</p>

<section class="card" aria-labelledby="u-st">
  <h2 class="card-h" id="u-st">自动核对</h2>
<?php if (!$ready): ?>
  <p><span class="chip">没有打开</span> 在 <a href="<?= h(url('pay')) ?>">收款设置</a> 里打开 USDT 收款。</p>
<?php endif; ?>
  <dl class="kv">
<?php foreach (USDT_CHAINS as $c => $info): $s = is_array($scan[$c] ?? null) ? $scan[$c] : []; ?>
    <div><dt><?= h($info['label']) ?></dt><dd><?= h(isset($s['ok_at']) ? '上次查到 ' . $when((int) $s['ok_at']) : '还没查过') ?><?php if (isset($s['err_at']) && (int) $s['err_at'] >= (int) ($s['ok_at'] ?? 0)): ?> <span class="chip chip-warn"><?= h('最近一次失败：' . $when((int) $s['err_at'])) ?></span><?php endif; ?></dd></div>
<?php endforeach; ?>
  </dl>
  <p class="where">有买家选了 USDT 时，付款页开着就每隔十几秒查一次；服务器上的定时任务每分钟也查一次（见部署说明）。</p>
  <form method="post" action="<?= h(url('usdt.check')) ?>">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <button class="btn" type="submit" id="u-check">现在查一次</button>
  </form>
</section>

<section class="card<?= h($open ? ' card-hot' : '') ?>" aria-labelledby="u-open">
  <h2 class="card-h" id="u-open">未匹配的 USDT 到账<?= h($open ? '（' . count($open) . '）' : '') ?></h2>
<?php if (!$open): ?>
  <p class="muted">没有要处理的到账。</p>
<?php endif; ?>
<?php foreach ($open as $x): $info = USDT_CHAINS[$x['chain']]; ?>
  <div class="u-item" id="<?= h('u-' . $x['id']) ?>">
    <p><b class="mono"><?= h($amt($x) . ' USDT') ?></b> <span class="chip chip-line"><?= h($info['label']) ?></span> <span class="mono"><?= h($when(is_int($x['ts'] ?? null) ? $x['ts'] : null)) ?></span></p>
    <p class="where"><?= h(USDT_WHY[$x['why']] ?? $x['why']) ?><?php if (is_string($x['near'] ?? null) && $x['near'] !== ''): ?>（<a href="<?= h(url('order', ['id' => $x['near']])) ?>"><?= h('相关订单 ' . $x['near']) ?></a>）<?php endif; ?></p>
    <dl class="kv">
      <div><dt>交易</dt><dd><a class="mono" href="<?= h($info['tx'] . $x['tx']) ?>" target="_blank" rel="noopener noreferrer"><?= h($short($x['tx'])) ?></a></dd></div>
<?php if ($x['from'] !== ''): ?>
      <div><dt>付款地址</dt><dd><a class="mono" href="<?= h($info['addr'] . $x['from']) ?>" target="_blank" rel="noopener noreferrer"><?= h($short($x['from'])) ?></a></dd></div>
<?php endif; ?>
    </dl>
    <form class="stack" method="post" action="<?= h(url('usdt.attach')) ?>" data-confirm="<?= h('把这笔 ' . $amt($x) . ' USDT 记到这个订单上？订单会马上确认收款（数字商品马上给买家看内容，货源商品马上进货）。请先在区块浏览器里核对过。') ?>" data-confirm-ok="记到订单上">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= h($x['id']) ?>">
      <div class="field">
        <label for="<?= h('u-o-' . $x['id']) ?>">记到哪个订单（订单号）</label>
        <input id="<?= h('u-o-' . $x['id']) ?>" name="order" type="text" class="mono" autocapitalize="characters" spellcheck="false" maxlength="30" value="<?= h(is_string($x['near'] ?? null) ? $x['near'] : '') ?>" placeholder="20261026-K7P2QX">
      </div>
      <button class="btn btn-primary" type="submit">手动对账：记到这个订单</button>
    </form>
    <form method="post" action="<?= h(url('usdt.dismiss')) ?>" data-confirm="标记为「不是订单付款」？不会改动任何订单。" data-confirm-ok="标记">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= h($x['id']) ?>">
      <button class="btn btn-ghost" type="submit">不是订单付款，忽略</button>
    </form>
  </div>
<?php endforeach; ?>
</section>

<?php if ($done): ?>
<section class="card" aria-labelledby="u-done">
  <h2 class="card-h" id="u-done">已处理</h2>
  <ul class="vlist">
<?php foreach ($done as $x): $info = USDT_CHAINS[$x['chain']]; ?>
    <li><span class="mono"><?= h($amt($x) . ' USDT') ?></span> <span class="chip chip-line"><?= h($info['label']) ?></span>
      <?php if ($x['state'] === 'attached' && is_string($x['order'] ?? null)): ?><a href="<?= h(url('order', ['id' => $x['order']])) ?>"><?= h('记到 ' . $x['order']) ?></a><?php else: ?><span class="muted">已忽略</span><?php endif; ?>
      <a class="mono" href="<?= h($info['tx'] . $x['tx']) ?>" target="_blank" rel="noopener noreferrer"><?= h($short($x['tx'])) ?></a></li>
<?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
