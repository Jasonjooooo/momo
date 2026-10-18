<?php
defined('BLOG_APP') || exit;
$countOf = ['todo' => $counts['todo'], 'claimed' => $counts['claimed'], 'pending' => $counts['pending'], 'to_ship' => $counts['to_ship'], 'failed' => $counts['failed'], 'shipped' => $counts['shipped'], 'completed' => $counts['completed'], 'cancelled' => $counts['cancelled'], 'all' => $counts['all']];
?>
<h1 class="page-h">订单</h1>
<p class="notice notice-info">核对付款：打开微信或支付宝的收款记录，看到一笔钱，就按<b>金额</b>找订单（每个没付款的订单金额都不一样，尾数是用来区分的），再点进去「确认收款」。买家备注里一般也有订单号。</p>

<nav class="chips" aria-label="按状态筛选">
<?php foreach (ORDER_FILTERS as $k => $label): ?>
  <a class="fchip<?= h(in_array($k, ['claimed', 'to_ship', 'failed'], true) && $countOf[$k] > 0 ? ' fchip-hot' : '') ?>" href="<?= h(url('orders', ($k === 'todo' ? [] : ['f' => $k]) + ($q !== '' ? ['q' => $q] : []))) ?>"<?php if ($filter === $k): ?> aria-current="page"<?php endif; ?>><?= h($label) ?> <span class="mono"><?= h((string) $countOf[$k]) ?></span></a>
<?php endforeach; ?>
</nav>

<form class="find-form" method="get" action="<?= h(admin_base()) ?>" role="search">
  <input type="hidden" name="r" value="orders">
  <input type="hidden" name="f" value="<?= h($filter) ?>">
  <label for="oq">搜索订单号、手机号、收货人或金额</label>
  <div class="find-row">
    <input id="oq" name="q" type="search" value="<?= h($q) ?>" placeholder="例如 K7P2 或 1380 或 99.03" autocapitalize="off" spellcheck="false" enterkeyhint="search">
    <button class="btn" type="submit">搜索</button>
  </div>
</form>

<?php if (!$rows): ?>
<p class="card muted"><?= h($q !== '' ? '没有找到包含「' . $q . '」的订单。' : ($filter === 'todo' ? '现在没有要处理的订单。' : '这里还没有订单。')) ?></p>
<?php else: ?>
<p class="where"><?= h('共 ' . $total . ' 个' . ($pages > 1 ? '，第 ' . $page . ' / ' . $pages . ' 页' : '') . '。') ?></p>
<ul class="plist olist">
<?php foreach ($rows as $o):
    $label = $o['status'] === 'confirmed' && $o['type'] === 'physical' ? '待发货' : ($o['status'] === 'confirmed' && $o['type'] === 'supply' ? '等卡密' : SHOP_STATUS[$o['status']]);
?>
  <li>
    <a class="prow orow" href="<?= h(url('order', ['id' => $o['id']])) ?>">
      <span class="orow-top">
        <span class="orow-amt mono">¥<?= h(shop_money((int) $o['amount'])) ?></span>
        <span class="chip <?= h(ORDER_CHIP[$o['status']] ?? '') ?>"><?= h($label) ?></span>
      </span>
      <span class="prow-t"><?= h($o['name'] . ' × ' . $o['qty']) ?></span>
      <span class="prow-meta">
        <span class="mono"><?= h($o['id']) ?></span>
        <span><?= h($o['redacted_at'] !== null ? '（个人信息已删除）' : ($o['type'] === 'physical' ? $o['buyer']['name'] : $o['buyer']['email'])) ?></span>
        <span class="mono"><?= h(cn_time($o['created_at'])) ?></span>
      </span>
    </a>
  </li>
<?php endforeach; ?>
</ul>
<?php if ($pages > 1): ?>
<nav class="pager" aria-label="翻页">
<?php if ($page > 1): ?>
  <a class="btn" href="<?= h(url('orders', ['f' => $filter, 'q' => $q, 'p' => $page - 1])) ?>">上一页</a>
<?php endif; ?>
<?php if ($page < $pages): ?>
  <a class="btn" href="<?= h(url('orders', ['f' => $filter, 'q' => $q, 'p' => $page + 1])) ?>">下一页</a>
<?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>

<section class="card" aria-labelledby="o-exp">
  <h2 class="card-h" id="o-exp">导出</h2>
  <p class="where">全部订单导出成表格（CSV，Excel 和 WPS 都能打开）。里面有买家的电话和地址，用完请删掉，不要转发。</p>
  <a class="btn" href="<?= h(url('orders.export')) ?>" download>导出表格（CSV）</a>
</section>
