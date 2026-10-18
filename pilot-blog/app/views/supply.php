<?php
defined('BLOG_APP') || exit;
$s = $settings['supply'];
$errors = $errors ?? [];
$form = $form ?? [];
$val = fn(string $k, string $fallback) => array_key_exists($k, $form) ? (string) $form[$k] : $fallback;
$err = fn(string $k) => $errors[$k] ?? null;
$ways = [0 => '自动发卡', 1 => '人工发货'];
?>
<h1 class="page-h">货源</h1>
<p class="where">从供货平台（发卡系统的「店铺共享」）进游戏点卡来卖。买家付款、你确认收款以后，网站自动从供货平台买一张卡，扣你在供货平台的余额，卡密显示在买家的「查订单」里。买家看不到供货平台的名字、成本和商品编号。</p>

<section class="card" aria-labelledby="s-sw">
  <h2 class="card-h" id="s-sw">开关</h2>
<?php if ($ready): ?>
  <p><span class="chip chip-ok">已打开</span> 上架的货源商品显示在商城里，可以下单。</p>
<?php elseif ($s['enabled']): ?>
  <p><span class="chip chip-warn">没设置好</span> 开关是开的，但网址、app_id 或密钥还缺，货源商品暂时不能下单。</p>
<?php else: ?>
  <p><span class="chip">已关闭</span> 货源商品不在商城显示。</p>
<?php endif; ?>
  <form method="post" action="<?= h(url('supply.save')) ?>"<?php if ($s['enabled']): ?> data-confirm="关闭货源？货源商品会从商城里消失，不能再下单。已经付款的订单还可以在订单里处理。" data-confirm-ok="关闭货源"<?php endif; ?>>
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="rev" value="<?= h((string) $settings['rev']) ?>">
    <input type="hidden" name="part" value="switch">
    <input type="hidden" name="enabled" value="<?= h($s['enabled'] ? '0' : '1') ?>">
    <button class="btn<?= h($s['enabled'] ? '' : ' btn-primary') ?>" type="submit"><?= h($s['enabled'] ? '关闭货源' : '打开货源') ?></button>
  </form>
</section>

<section class="card" aria-labelledby="s-conn">
  <h2 class="card-h" id="s-conn">连接设置</h2>
  <p class="where">在供货平台登录你的账号，打开「店铺共享」或「对接」页面，复制店铺网址、app_id 和对接密钥。密钥保存后这里只显示最后 4 位，谁也看不到完整的密钥。</p>
  <form class="stack" method="post" action="<?= h(url('supply.save')) ?>" data-guard novalidate>
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="rev" value="<?= h((string) $settings['rev']) ?>">
    <input type="hidden" name="part" value="conn">
    <div class="field<?= h($err('base_url') ? ' has-err' : '') ?>">
      <label for="s-url">供货平台网址</label>
      <input id="s-url" name="base_url" type="url" inputmode="url" autocapitalize="off" spellcheck="false" placeholder="https://example.com" value="<?= h($val('base_url', $s['base_url'])) ?>">
      <p class="hint">只能用 https:// 开头的公网地址。</p>
      <p class="ferr"<?php if (!$err('base_url')): ?> hidden<?php endif; ?>><?= h((string) $err('base_url')) ?></p>
    </div>
    <div class="field<?= h($err('app_id') ? ' has-err' : '') ?>">
      <label for="s-appid">app_id</label>
      <input id="s-appid" name="app_id" type="text" autocapitalize="off" spellcheck="false" autocomplete="off" value="<?= h($val('app_id', $s['app_id'])) ?>">
      <p class="ferr"<?php if (!$err('app_id')): ?> hidden<?php endif; ?>><?= h((string) $err('app_id')) ?></p>
    </div>
    <div class="field<?= h($err('app_key') ? ' has-err' : '') ?>">
      <label for="s-key"><?= h($key_hint !== null ? '对接密钥（已设置，结尾 ' . $key_hint . '；不换就留空）' : '对接密钥（app_key）') ?></label>
      <input id="s-key" name="app_key" type="password" autocomplete="off" autocapitalize="off" spellcheck="false" value="">
<?php if ($key_set_at !== null): ?>
      <p class="hint"><?= h(cn_time($key_set_at, false) . '设置。') ?></p>
<?php endif; ?>
      <p class="ferr"<?php if (!$err('app_key')): ?> hidden<?php endif; ?>><?= h((string) $err('app_key')) ?></p>
    </div>
    <button class="btn btn-primary" type="submit">保存连接设置</button>
  </form>
  <div class="row-btns">
    <form method="post" action="<?= h(url('supply.test')) ?>">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <button class="btn" type="submit"<?php if ($s['base_url'] === '' || $key_hint === null): ?> disabled<?php endif; ?>>测试连接</button>
    </form>
<?php if ($key_hint !== null): ?>
    <form method="post" action="<?= h(url('supply.key_remove')) ?>" data-confirm="删除密钥？货源会一起关闭，货源商品不能再下单。" data-confirm-ok="删除密钥" data-confirm-danger="1">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <button class="btn btn-ghost btn-danger" type="submit">删除密钥</button>
    </form>
<?php endif; ?>
  </div>
  <p class="notice notice-info"><?= h('供货平台如果开了「对接白名单」，要把本站服务器的 IP ' . SUPPLY_SERVER_IP . ' 加进去，不然测试连接和进货会被拒绝。进货从你在供货平台的余额里扣，这里查不到余额，记得保持余额充足。') ?></p>
</section>

<?php if ($products): ?>
<section class="card" aria-labelledby="s-mine">
  <h2 class="card-h" id="s-mine">已经上架的货源商品</h2>
  <ul class="plist">
<?php foreach ($products as $p): $m = $p['price'] - $p['supply']['cost']; ?>
    <li>
      <a class="prow" href="<?= h(url('shop.edit', ['id' => $p['id']])) ?>">
        <span class="prow-t"><?= h($p['name']) ?></span>
        <span class="prow-meta">
          <span class="chip <?= h($p['on_sale'] ? 'chip-ok' : '') ?>"><?= h($p['on_sale'] ? '在卖' : '没上架') ?></span>
          <span class="mono"><?= h('售价 ¥' . shop_money($p['price'])) ?></span>
          <span class="mono"><?= h('成本 ¥' . shop_money($p['supply']['cost'])) ?></span>
<?php if ($m < 0): ?>
          <span class="chip chip-warn">低于成本</span>
<?php else: ?>
          <span class="mono"><?= h('毛利 ¥' . shop_money($m)) ?></span>
<?php endif; ?>
          <span class="mono"><?= h('库存 ' . (int) $p['stock']) ?></span>
        </span>
      </a>
    </li>
<?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="s-cat">
  <h2 class="card-h" id="s-cat">供货平台的商品</h2>
<?php if (!$ready): ?>
  <p class="muted">填好连接设置并打开货源以后，这里列出供货平台上的商品。</p>
<?php elseif ($catalog === null): ?>
  <p class="muted">保存以后刷新这一页看商品。</p>
<?php elseif (!$catalog['ok']): ?>
  <p class="notice notice-err"><?= h('读不到商品：' . $catalog['msg']) ?></p>
<?php else: ?>
  <p class="where"><?= h('成本是你这个账号的进货价。列表每 5 分钟更新一次（上次读取：' . cn_time(gmdate('Y-m-d\TH:i:s\Z', (int) ($catalog['cached_at'] ?? time()))) . '）。点「上架」看详情、定售价。') ?></p>
  <form method="post" action="<?= h(url('supply.refresh')) ?>">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <button class="btn btn-ghost" type="submit">重新读取</button>
  </form>
<?php if (!$catalog['cats']): ?>
  <p class="muted">供货平台上还没有共享的商品。</p>
<?php endif; ?>
<?php foreach ($catalog['cats'] as $ci => $c): ?>
  <details class="grp"<?php if ($ci === 0): ?> open<?php endif; ?>>
    <summary><?= h($c['name'] . '（' . count($c['items']) . '）') ?></summary>
    <div class="grp-body">
      <ul class="plist sup-list">
<?php foreach ($c['items'] as $it): $cost = supply_item_cost($it); $on = $linked[$it['code']] ?? []; ?>
        <li class="sup-row">
          <span class="sup-txt">
            <span class="prow-t"><?= h($it['name'] !== '' ? $it['name'] : '（没有名字）') ?></span>
            <span class="prow-meta">
              <span class="mono"><?= h($cost !== null ? '成本 ¥' . shop_money($cost) : '成本未知') ?></span>
              <span class="mono"><?= h('库存 ' . $it['stock']) ?></span>
              <span class="chip chip-line"><?= h($ways[$it['delivery_way']] ?? '其他') ?></span>
<?php if ($on): ?>
              <span class="chip chip-ok"><?= h('已上架 ' . count($on) . ' 件') ?></span>
<?php endif; ?>
            </span>
          </span>
          <a class="btn<?= h($on ? '' : ' btn-primary') ?>" href="<?= h(url('supply.item', ['code' => $it['code']])) ?>" aria-label="<?= h('上架「' . $it['name'] . '」') ?>"><?= h($on ? '再上架一个' : '上架') ?></a>
        </li>
<?php endforeach; ?>
      </ul>
    </div>
  </details>
<?php endforeach; ?>
<?php endif; ?>
</section>
