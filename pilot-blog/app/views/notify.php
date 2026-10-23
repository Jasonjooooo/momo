<?php
defined('BLOG_APP') || exit;
// $events: event => bool, $token_hint: last 4 or null, $log: newest first (time, event, title, ok, why).
$errors = $errors ?? [];
$evLabel = NOTIFY_EVENTS + NOTIFY_FIXED_EVENTS;
?>
<h1 class="page-h">微信提醒</h1>
<p class="where">有新订单、买家点了「我已付款」等事情时，通过 PushPlus（推送加）给你的微信发一条消息。消息里只有订单号、商品、金额和打码的买家称呼（例如 王*），不会有手机号、邮箱、地址或付款信息。一小时最多发 <?= h((string) NOTIFY_HOUR_MAX) ?> 条，防止刷屏。</p>

<section class="card" aria-labelledby="n-token">
  <h2 class="card-h" id="n-token">PushPlus token</h2>
<?php if ($token_hint !== null): ?>
  <p id="n-status"><span class="chip chip-ok">已设置</span> 结尾是 <b class="mono">····<?= h($token_hint) ?></b><?= h($token_set_at ? '（' . cn_time($token_set_at, false) . '设置）' : '') ?></p>
<?php else: ?>
  <p id="n-status"><span class="chip">没有设置</span> 现在不会发任何微信提醒。</p>
<?php endif; ?>
  <ol class="where n-how">
    <li>用微信扫码关注「pushplus 推送加」公众号（官网 www.pushplus.plus 登录时会给出二维码）。</li>
    <li>在公众号菜单或官网「一对一推送」里复制你的 token。</li>
    <li>粘贴到下面，保存，再点「发一条测试」。</li>
  </ol>
  <form class="stack" method="post" action="<?= h(url('notify.token')) ?>" data-guard novalidate>
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <div class="field<?= h(isset($errors['token']) ? ' has-err' : '') ?>">
      <label for="n-tok"><?= h($token_hint !== null ? '换一个 token（不换就不用填）' : 'token') ?></label>
      <input id="n-tok" name="token" type="password" autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="200" value="">
      <p class="hint">保存后这里只显示最后 4 位，谁也看不到完整的 token。</p>
      <p class="ferr"<?php if (!isset($errors['token'])): ?> hidden<?php endif; ?>><?= h((string) ($errors['token'] ?? '')) ?></p>
    </div>
    <button class="btn btn-primary" type="submit">保存 token</button>
  </form>
  <div class="row-btns">
    <form method="post" action="<?= h(url('notify.test')) ?>">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <button class="btn" type="submit" id="n-test"<?php if ($token_hint === null): ?> disabled<?php endif; ?>>发一条测试</button>
    </form>
<?php if ($token_hint !== null): ?>
    <form method="post" action="<?= h(url('notify.token_remove')) ?>" data-confirm="删除 token？以后不再发微信提醒。" data-confirm-ok="删除 token" data-confirm-danger="1">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <button class="btn btn-ghost btn-danger" type="submit">删除 token</button>
    </form>
<?php endif; ?>
  </div>
</section>

<section class="card" aria-labelledby="n-ev">
  <h2 class="card-h" id="n-ev">提醒哪些事</h2>
  <form class="stack" method="post" action="<?= h(url('notify.events')) ?>">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="rev" value="<?= h((string) $settings['rev']) ?>">
<?php foreach (NOTIFY_EVENTS as $k => $label): ?>
    <label class="check"><input type="checkbox" name="<?= h('ev_' . $k) ?>" value="1"<?php if (!empty($events[$k])): ?> checked<?php endif; ?>> <?= h($label) ?></label>
<?php endforeach; ?>
    <p class="where">「买家点了我已付款」最要紧：钱是直接付到你的收款码的，要你自己核对到账后点「确认收款」。服务器每天的体检（磁盘、备份、证书）出问题时也会提醒，这一项不能关。</p>
    <button class="btn btn-primary" type="submit">保存提醒设置</button>
  </form>
</section>

<section class="card" aria-labelledby="n-log">
  <h2 class="card-h" id="n-log">最近发送</h2>
<?php if (!$log): ?>
  <p class="muted">还没有发过。</p>
<?php else: ?>
  <p class="where">最近 <?= h((string) count($log)) ?> 条，时间按北京时间。</p>
  <div class="table-wrap" tabindex="0" role="region" aria-label="最近发送">
    <table class="usage ntbl" id="n-table">
      <thead><tr><th scope="col">时间</th><th scope="col">事情</th><th scope="col">标题</th><th scope="col">结果</th></tr></thead>
      <tbody>
<?php foreach ($log as $e): ?>
        <tr>
          <td class="mono"><?= h(local_time((string) ($e['at'] ?? ''), 'm-d H:i')) ?></td>
          <td><?= h($evLabel[$e['event'] ?? ''] ?? (string) ($e['event'] ?? '')) ?></td>
          <td><?= h((string) ($e['title'] ?? '')) ?></td>
          <td><?php if (!empty($e['ok'])): ?><span class="chip chip-ok">已发出</span><?php else: ?><span class="chip chip-warn">没发出</span> <?= h((string) ($e['why'] ?? '') . (($e['n'] ?? 1) > 1 ? '（' . (int) $e['n'] . ' 条）' : '')) ?><?php endif; ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
</section>
