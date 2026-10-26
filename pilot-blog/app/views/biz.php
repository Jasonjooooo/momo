<?php
defined('BLOG_APP') || exit;
// $range: from, to, days, preset, error. $rep: biz_report(). Money in fen.
$nDays = count($range['days']);
$span = $range['from'] === $range['to'] ? biz_day_label($range['from'], $today) : biz_day_label($range['from'], $today) . ' 到 ' . biz_day_label($range['to'], $today);
$pct = $rep['rate'] === null ? '-' : (string) round($rep['rate'] * 100, 1) . '%';
$todo = $rep['todo'];
$lowN = count($todo['low']);
$kpis = [
    ['k-paid', '实付成交额', biz_money($rep['paid_fen']), $rep['paid_n'] . ' 单', true],
    ['k-profit', '商品毛利', biz_money($rep['profit_fen']), $rep['cost_unknown'] > 0 ? $rep['cost_unknown'] . ' 单没有成本，没算进去' : ($rep['paid_n'] > 0 ? '每单都有成本' : '还没有付款的订单'), true],
    ['k-orders', '支付订单数', (string) $rep['paid_n'], '下单 ' . $rep['created'] . ' 单' . ($rep['usdt_n'] > 0 ? '，其中 USDT 付款 ' . $rep['usdt_n'] . ' 单' : ''), false],
    ['k-rate', '支付成功率', $pct, '付款单数 / 下单数', false],
    ['k-aov', '客单价', $rep['aov'] === null ? '-' : biz_money($rep['aov']), '成交额 / 支付订单数', false],
    ['k-visitors', '新增访客', number_format($rep['visitors']), '访问统计里每天访客的合计', false],
    ['k-refund', '退款金额', biz_money($rep['refund_fen']), $rep['refund_n'] . ' 笔' . ($rep['refund_unknown'] > 0 ? '，' . $rep['refund_unknown'] . ' 笔没写金额' : ''), false],
    ['k-selling', '在售商品数', (string) $rep['selling'], '现在首页能买的', false],
    ['k-refsales', '推广成交额', biz_money($rep['ref_sales']), $rep['ref_n'] . ' 单（商品金额）', false],
    ['k-refdue', '待结算佣金', biz_money($todo['ref_due']), '全部推广人，不分日期', false],
];
$maxSales = 0;
foreach ($rep['daily'] as $d) {
    $maxSales = max($maxSales, $d['sales']);
}
$items = [
    ['t-claimed', '待确认收款', $todo['claimed'], '单', url('orders', ['f' => 'claimed']), '去确认'],
    ['t-ship', '待发货', $todo['to_ship'], '单', url('orders', ['f' => 'to_ship']), '去发货'],
    ['t-failed', '进货失败', $todo['failed'], '单', url('orders', ['f' => 'failed']), '去处理'],
    ['t-low', '库存不足（3 件及以下）', $lowN, '件', url('shop', ['cat' => 'low']), '看商品'],
];
if (($uOdd = count(usdt_unmatched_open())) > 0) {
    $items[] = ['t-usdt', '未匹配的 USDT 到账', $uOdd, '笔', url('usdt'), '去核对'];
}
?>
<h1 class="page-h">经营总览</h1>

<form class="card biz-range" method="get" action="<?= h(url('biz')) ?>" aria-labelledby="biz-r-h">
  <h2 class="vh" id="biz-r-h">选日期</h2>
  <input type="hidden" name="r" value="biz">
  <nav class="chips biz-quick" aria-label="快速选择">
<?php foreach (BIZ_PRESETS as $k => $label): $k = (string) $k; /* '7' and '30' are int keys in PHP */ ?>
    <a class="fchip" href="<?= h(url('biz', ['q' => $k])) ?>"<?php if ($range['preset'] === $k): ?> aria-current="page"<?php endif; ?>><?= h($label) ?></a>
<?php endforeach; ?>
  </nav>
  <div class="biz-dates">
    <div class="field">
      <label for="biz-from">从</label>
      <input id="biz-from" name="from" type="date" value="<?= h($range['from']) ?>" max="<?= h($today) ?>">
    </div>
    <div class="field">
      <label for="biz-to">到</label>
      <input id="biz-to" name="to" type="date" value="<?= h($range['to']) ?>" max="<?= h($today) ?>">
    </div>
    <button class="btn" type="submit">查看</button>
  </div>
</form>
<?php if ($range['error'] !== null): ?>
<p class="notice notice-warn"><?= h($range['error']) ?></p>
<?php endif; ?>
<p class="where" id="biz-span"><?= h($span . '（' . $nDays . ' 天），北京时间，人民币。订单算在下单那天；退款算在记录退款那天。') ?></p>

<section class="biz-kpis" aria-label="主要数字">
<?php foreach ($kpis as [$id, $label, $val, $sub, $big]): ?>
  <div class="biz-kpi<?= h($big ? ' biz-kpi-big' : '') ?>" id="<?= h($id) ?>">
    <p class="biz-k"><?= h($label) ?></p>
    <p class="biz-v"><?= h($val) ?></p>
    <p class="biz-s"><?= h($sub) ?></p>
  </div>
<?php endforeach; ?>
</section>
<p class="notice notice-info" id="biz-note">毛利 = 买家实付的钱 减去 商品成本（货源商品按进货实际花的钱，自己的商品按商品里填的「成本价」）。它不是净利润：没有减去退款、运费、包装、手续费和推广佣金。没填成本价的订单不算进毛利，在「商品」里补上成本价，以后的订单就能算了。</p>

<section class="card" aria-labelledby="biz-trend-h">
  <h2 class="card-h" id="biz-trend-h">每天的成交额和毛利</h2>
<?php if ($maxSales === 0): ?>
  <p class="muted">这段时间还没有付款的订单。</p>
<?php else: ?>
  <figure class="chart biz-chart">
    <?php echo biz_chart_svg($rep['daily']); /* built with h() and number_format in biz.php */ ?>
    <figcaption class="chart-cap">
      <span class="mono"><?= h(str_replace('-', '.', substr($range['from'], 5))) ?></span>
      <span><span class="biz-leg biz-leg-bar" aria-hidden="true"></span>成交额 <span class="biz-leg biz-leg-line" aria-hidden="true"></span>毛利</span>
      <span class="mono"><?= h(str_replace('-', '.', substr($range['to'], 5))) ?></span>
    </figcaption>
  </figure>
  <p class="where"><?= h('柱子最高的一天成交 ' . biz_money($maxSales) . '。在电脑上把鼠标停在柱子上能看到当天的数。') ?></p>
<?php endif; ?>
</section>

<section class="card<?= h($todo['claimed'] + $todo['to_ship'] + $todo['failed'] + $lowN > 0 || $todo['ref_due'] > 0 ? ' card-hot' : '') ?>" aria-labelledby="biz-todo-h">
  <h2 class="card-h" id="biz-todo-h">待处理事项（现在）</h2>
  <ul class="biz-todo">
<?php foreach ($items as [$id, $label, $n, $unit, $href, $btn]): ?>
    <li id="<?= h($id) ?>"><span class="biz-todo-t"><?= h($label) ?></span><span class="biz-todo-n<?= h($n > 0 ? ' num-hot' : '') ?>"><?= h($n . ' ' . $unit) ?></span><a class="btn btn-tool<?= h($n > 0 ? ' btn-primary' : '') ?>" href="<?= h($href) ?>"><?= h($btn) ?></a></li>
<?php endforeach; ?>
    <li id="t-ref"><span class="biz-todo-t">待结算佣金<?php if ($todo['ref_due_n'] > 0): ?><span class="biz-todo-sub"><?= h($todo['ref_due_n'] . ' 位推广人') ?></span><?php endif; ?></span><span class="biz-todo-n<?= h($todo['ref_due'] > 0 ? ' num-hot' : '') ?>"><?= h(biz_money($todo['ref_due'])) ?></span><a class="btn btn-tool<?= h($todo['ref_due'] > 0 ? ' btn-primary' : '') ?>" href="<?= h(url('ref')) ?>">去结算</a></li>
  </ul>
<?php if ($lowN > 0): ?>
  <p class="where"><?= h('库存不足：' . implode('、', array_map(fn($p) => $p['name'] . '（' . $p['stock'] . '）', array_slice($todo['low'], 0, 6))) . ($lowN > 6 ? ' 等' : '') . '。货源商品的库存是供货平台的，几分钟更新一次。') ?></p>
<?php endif; ?>
</section>

<section class="card" aria-labelledby="biz-top-h">
  <h2 class="card-h" id="biz-top-h">热销排行（按卖出件数）</h2>
<?php if (!$rep['top']): ?>
  <p class="muted">这段时间还没有卖出商品。</p>
<?php else: ?>
  <ol class="toplist biz-top" id="biz-top">
<?php foreach ($rep['top'] as $i => $t): ?>
    <li><span class="tl-name"><span class="mono biz-rank"><?= h((string) ($i + 1)) ?></span> <?= h($t['name']) ?></span><span class="tl-num biz-num"><?= h($t['qty'] . ' 件 · ' . biz_money($t['fen'])) ?></span></li>
<?php endforeach; ?>
  </ol>
<?php endif; ?>
</section>
<?php if (!$ref_on): ?>
<p class="where">推广现在是关着的。想让别人帮你分享商品、按成交给佣金，到 <a href="<?= h(url('ref')) ?>">推广</a> 里打开。</p>
<?php endif; ?>
