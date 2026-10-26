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

<?php
$u = $settings['usdt'];
$uf = $form['usdt'] ?? null;
$uval = fn(string $k, string $fallback) => is_array($uf) && array_key_exists($k, $uf) ? (string) $uf[$k] : $fallback;
$uon = fn(string $c) => is_array($uf) ? !empty($uf[$c . '_on']) : $u[$c]['on'];
?>
<section class="card" aria-labelledby="p-usdt" id="usdt">
  <h2 class="card-h" id="p-usdt">USDT 收款</h2>
  <p><?php if ($usdt_ready): ?><span class="chip chip-ok">已打开</span> <?= h(implode('、', array_map(fn($c) => USDT_CHAINS[$c]['label'], $usdt_ready))) ?>，买家在付款页可以选 USDT，到账后自动确认收款。<?php else: ?><span class="chip">没有打开</span> 买家只能用收款码付款。<?php endif; ?></p>
  <div class="notice notice-warn" id="usdt-warn">
    <p><b>先读这几条：</b></p>
    <ul>
      <li>这里只填<b>收款地址</b>。网站不保管你的钱，也不能转出任何钱。</li>
      <li><b>绝对不要</b>在这里（或任何网站）填私钥、助记词（12 或 24 个英文单词）。谁拿到它们，谁就能拿走你钱包里的全部钱。看起来像私钥或助记词的内容，这一页会直接拒绝。</li>
      <li>地址必须是<b>你自己的钱包</b>（或你自己交易所账户的充值地址），而且要和链对上：TRC20 地址以 T 开头，BEP20 地址以 0x 开头。用交易所充值地址的话，先确认它支持 USDT 的这条链。</li>
      <li>买家必须用同一条链转 USDT，金额一分不差，系统才认得出来；别的链、别的币、金额不对的到账不会自动确认，会放进「未匹配的 USDT 到账」等你处理。</li>
      <li>订单金额仍然按人民币算（经营总览也按人民币统计）；USDT 金额按你填的汇率换算，每个报价只在付款时间内有效。</li>
    </ul>
  </div>
  <form class="stack" method="post" action="<?= h(url('pay.usdt')) ?>" data-guard novalidate>
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="rev" value="<?= h((string) $settings['rev']) ?>">
<?php foreach (USDT_CHAINS as $c => $info): ?>
    <h3 class="sub-h"><?= h('USDT · ' . $info['label'] . '（' . $info['net'] . '）') ?></h3>
    <label class="check"><input type="checkbox" name="<?= h($c . '_on') ?>" value="1"<?php if ($uon($c)): ?> checked<?php endif; ?>> <?= h('打开 ' . $info['label']) ?></label>
    <div class="field<?= h($err('usdt_' . $c) ? ' has-err' : '') ?>">
      <label for="u-<?= h($c) ?>"><?= h($info['label'] . ' 收款地址') ?></label>
      <input id="u-<?= h($c) ?>" name="<?= h($c) ?>" type="text" autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="200" class="mono" value="<?= h($uval($c, $u[$c]['address'])) ?>" placeholder="<?= h($c === 'trc20' ? 'T 开头，34 位' : '0x 开头，42 位') ?>">
      <p class="hint"><?= h($c === 'trc20' ? '从钱包「收款」里复制 TRON / TRC20 的 USDT 地址。会检查校验位，抄错一个字符也会被拒绝。' : '从钱包「收款」里复制 BNB Smart Chain（BSC / BEP20）的地址。有大小写的话会按 EIP-55 校验。') ?></p>
      <p class="ferr"<?php if (!$err('usdt_' . $c)): ?> hidden<?php endif; ?>><?= h((string) $err('usdt_' . $c)) ?></p>
<?php if ($u[$c]['address'] !== ''): ?>
      <p class="hint"><a href="<?= h($info['addr'] . $u[$c]['address']) ?>" target="_blank" rel="noopener noreferrer">在 <?= h($c === 'trc20' ? 'Tronscan' : 'BscScan') ?> 上看这个地址</a>，确认是你自己的钱包。</p>
<?php endif; ?>
    </div>
<?php endforeach; ?>
    <div class="field-row">
      <div class="field<?= h($err('usdt_rate') ? ' has-err' : '') ?>">
        <label for="u-rate">汇率：1 USDT = 多少人民币</label>
        <div class="affix"><span>¥</span><input id="u-rate" name="rate" type="text" inputmode="decimal" value="<?= h($uval('rate', $u['rate'] > 0 ? usdt_rate_text($u['rate']) : '')) ?>" placeholder="7.20"></div>
        <p class="hint">2 到 4 位小数，5.00 到 10.00 之间。比如填 7.20，¥100 的订单要付 13.88 USDT（向下取整，需要错开时再少几位小数，买家不会多付）。汇率变了记得回来改；已经给出的报价不变。</p>
        <p class="ferr"<?php if (!$err('usdt_rate')): ?> hidden<?php endif; ?>><?= h((string) $err('usdt_rate')) ?></p>
      </div>
      <div class="field<?= h($err('usdt_window') ? ' has-err' : '') ?>">
        <label for="u-win">付款时间（分钟）</label>
        <input id="u-win" name="window" type="text" inputmode="numeric" value="<?= h($uval('window', (string) $u['window'])) ?>">
        <p class="hint">报价从生成起多少分钟内有效，<?= h(USDT_WINDOW_MIN . ' 到 ' . USDT_WINDOW_MAX) ?>，一般 30。过期后买家可以重新报价（按当时的汇率）。</p>
        <p class="ferr"<?php if (!$err('usdt_window')): ?> hidden<?php endif; ?>><?= h((string) $err('usdt_window')) ?></p>
      </div>
    </div>
    <div class="bar bar-static">
      <button class="btn btn-primary btn-wide" type="submit">保存 USDT 设置</button>
    </div>
  </form>

  <h3 class="sub-h">TronGrid API Key（可不填）</h3>
<?php if ($usdt_key_hint !== null): ?>
  <p><span class="chip chip-ok">已设置</span> 结尾是 <b class="mono">····<?= h($usdt_key_hint) ?></b></p>
<?php else: ?>
  <p class="where">查 TRC20 到账用的是 TronGrid 的公开接口，不填也能用，只是次数限制更严。到 trongrid.io 免费注册，在 API Keys 里新建一个，复制过来。它只能用来查公开数据，不能动你的钱。</p>
<?php endif; ?>
  <form class="stack" method="post" action="<?= h(url('pay.usdt_key')) ?>" data-guard novalidate>
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <div class="field<?= h($err('trongrid_key') ? ' has-err' : '') ?>">
      <label for="u-key"><?= h($usdt_key_hint !== null ? '换一个 Key（不换就不用填）' : 'TronGrid API Key') ?></label>
      <input id="u-key" name="trongrid_key" type="password" autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="200" value="">
      <p class="hint">保存后这里只显示最后 4 位。</p>
      <p class="ferr"<?php if (!$err('trongrid_key')): ?> hidden<?php endif; ?>><?= h((string) $err('trongrid_key')) ?></p>
    </div>
    <button class="btn" type="submit">保存 Key</button>
  </form>
<?php if ($usdt_key_hint !== null): ?>
  <form method="post" action="<?= h(url('pay.usdt_key_del')) ?>" data-confirm="删除 TronGrid API Key？不用 Key 也能查，只是次数限制更严。" data-confirm-ok="删除" data-confirm-danger="1">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <button class="btn btn-ghost btn-danger" type="submit">删除 Key</button>
  </form>
<?php endif; ?>
  <p class="where"><a href="<?= h(url('usdt')) ?>">未匹配的 USDT 到账<?= h($usdt_open > 0 ? '（' . $usdt_open . ' 笔要处理）' : '') ?></a>：金额、链或时间对不上的到账都在这里，等你手动对账或忽略。</p>
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
  <div class="field">
    <label class="check"><input type="checkbox" name="pay_sub" value="1"<?php if (!empty($ss['pay_sub'])): ?> checked<?php endif; ?>> 付款页用单独的网址（pay. 开头）</label>
    <p class="hint">打开后，买家下单后会跳到 pay.你的域名 上付款。打开前要先在域名解析里加好 pay 这条记录，并在服务器上配好证书，否则买家会打不开付款页。</p>
  </div>
  <div class="bar bar-static">
    <button class="btn btn-primary btn-wide" type="submit">保存</button>
  </div>
</form>
