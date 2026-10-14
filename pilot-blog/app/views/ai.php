<?php
defined('BLOG_APP') || exit;
$c = $settings['chat'];
$errSum = fn(array $e) => (int) $e['disabled'] + (int) $e['not_configured'] + (int) $e['bad_request'] + (int) $e['upstream_busy'] + (int) $e['upstream_error'] + (int) $e['upstream_unreachable'] + (int) $e['empty_reply'];
$rlSum = fn(array $e) => (int) $e['rate_limited_ip'] + (int) $e['daily_limit'];
$tot = ['requests' => 0, 'answered' => 0, 'err' => 0, 'rl' => 0, 'in' => 0, 'out' => 0];
foreach ($usage as $u) {
    $tot['requests'] += (int) $u['chat']['requests'];
    $tot['answered'] += (int) $u['chat']['answered'];
    $tot['err'] += $errSum($u['chat']['errors']);
    $tot['rl'] += $rlSum($u['chat']['errors']);
    $tot['in'] += (int) $u['chat']['tokens_in'];
    $tot['out'] += (int) $u['chat']['tokens_out'];
}
// bar chart: oldest on the left, today on the right
$days = array_reverse($usage);
$max = max(1, ...array_map(fn($u) => (int) $u['chat']['requests'], $days));
$W = 600;
$H = 150;
$top = 18;
$base = 128;
$slot = $W / count($days);
$num = fn(int $n) => number_format($n, 0, '.', ',');
?>
<h1 class="page-h">AI 助手</h1>

<section class="card" aria-labelledby="ai-sw">
  <h2 class="card-h" id="ai-sw">开关</h2>
<?php if ($chat_live): ?>
  <p><span class="chip chip-ok">在线</span> 在线：访客现在能用。</p>
<?php elseif ($c['enabled']): ?>
  <p><span class="chip chip-warn">缺少密钥</span> 缺少 API 密钥：先在下面填好密钥。</p>
<?php else: ?>
  <p><span class="chip">已关闭</span> 已关闭：对话框显示接入中，不会调用 AI。</p>
<?php endif; ?>
  <form method="post" action="<?= h(url('ai.save')) ?>">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="rev" value="<?= h((string) $settings['rev']) ?>">
    <input type="hidden" name="part" value="switch">
    <input type="hidden" name="enabled" value="<?= h($c['enabled'] ? '0' : '1') ?>">
    <button class="btn<?= h($c['enabled'] ? '' : ' btn-primary') ?>" type="submit"><?= h($c['enabled'] ? '关闭助手' : '打开助手') ?></button>
  </form>
</section>

<section class="card" aria-labelledby="ai-key">
  <h2 class="card-h" id="ai-key">API 密钥</h2>
  <p id="key-status">
<?php if ($key_source === 'admin'): ?>
    已设置，结尾是 <b class="mono"><?= h((string) $key_hint) ?></b><?= h($key_set_at ? '（' . cn_time($key_set_at, false) . '设置）' : '') ?>
<?php elseif ($key_source === 'legacy'): ?>
    正在用旧配置文件 chat-config.php 里的密钥（结尾 <b class="mono"><?= h((string) $key_hint) ?></b>）。在这里保存新密钥后会优先用新的。
<?php elseif ($key_source === 'env'): ?>
    正在用服务器环境变量里的密钥（结尾 <b class="mono"><?= h((string) $key_hint) ?></b>）。在这里保存新密钥后会优先用新的。
<?php else: ?>
    还没有设置
<?php endif; ?>
  </p>
  <form method="post" action="<?= h(url('ai.key')) ?>" class="stack" data-guard>
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <div class="field">
      <label for="key"><?= h($key_source === 'admin' ? '换一个新密钥' : '填写密钥') ?></label>
      <p class="where">在 Anthropic 控制台创建，以 sk-ant- 开头。保存后这里只显示最后 4 位，谁也看不到完整的密钥。</p>
      <input id="key" name="key" type="password" autocomplete="off" autocapitalize="off" spellcheck="false" value="">
    </div>
    <button class="btn btn-primary" type="submit">保存密钥</button>
  </form>
  <div class="row-btns">
    <button type="button" class="btn" id="ai-test"<?php if ($key_source === null): ?> disabled<?php endif; ?>>测试连接</button>
<?php if ($key_source === 'admin'): ?>
    <form method="post" action="<?= h(url('ai.key_remove')) ?>" data-confirm="删除后助手就用不了了（除非还有旧配置文件里的密钥）。确定删除密钥？" data-confirm-ok="删除密钥" data-confirm-danger="1">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <button class="btn btn-danger" type="submit">删除密钥</button>
    </form>
<?php endif; ?>
  </div>
  <p class="where" id="ai-test-msg" role="status" aria-live="polite"></p>
</section>

<section class="card" aria-labelledby="ai-lim">
  <h2 class="card-h" id="ai-lim">限额和补充说明</h2>
  <form method="post" action="<?= h(url('ai.save')) ?>" class="stack" data-guard novalidate>
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="rev" value="<?= h((string) $settings['rev']) ?>">
    <input type="hidden" name="part" value="limits">
    <div class="field">
      <label for="daily_limit">每天最多回答多少次（全站）</label>
      <p class="where">0 到 5000。按北京时间算一天，用完了访客会看到「今天的提问额度用完了」。</p>
      <input id="daily_limit" name="daily_limit" type="text" inputmode="numeric" pattern="[0-9]*" value="<?= h((string) $c['daily_limit']) ?>">
    </div>
    <div class="field">
      <label for="ip_limit">每位访客每 10 分钟最多问几次</label>
      <p class="where">1 到 200。</p>
      <input id="ip_limit" name="ip_limit" type="text" inputmode="numeric" pattern="[0-9]*" value="<?= h((string) $c['ip_limit']) ?>">
    </div>
    <div class="field">
      <label for="extra_note">补充说明（可留空）</label>
      <p class="where">会加在助手的系统提示后面，比如说明最近不方便透露的事。不要写密码或私人信息。</p>
      <textarea id="extra_note" name="extra_note" rows="4" data-max="1000"><?= h($c['extra_note']) ?></textarea>
      <p class="count" aria-hidden="true"></p>
    </div>
    <button class="btn btn-primary" type="submit">保存限额和说明</button>
  </form>
</section>

<section class="card" aria-labelledby="ai-use">
  <h2 class="card-h" id="ai-use">用量（近 30 天）</h2>
  <p class="where">这里只记次数和用量，不记访客问了什么。</p>
  <dl class="kv kv-3">
    <div><dt>提问</dt><dd class="num"><?= h($num($tot['requests'])) ?></dd></div>
    <div><dt>回答</dt><dd class="num"><?= h($num($tot['answered'])) ?></dd></div>
    <div><dt>tokens</dt><dd class="num"><?= h($num($tot['in'] + $tot['out'])) ?></dd></div>
  </dl>
  <figure class="chart">
    <svg viewBox="0 0 <?= h((string) $W) ?> <?= h((string) $H) ?>" role="img" aria-labelledby="chart-t" preserveAspectRatio="none">
      <title id="chart-t">近 30 天每天的提问次数，最多一天 <?= h((string) $max) ?> 次</title>
      <line x1="0" y1="<?= h((string) $base) ?>" x2="<?= h((string) $W) ?>" y2="<?= h((string) $base) ?>" stroke="#5E6EA2" stroke-width="1" vector-effect="non-scaling-stroke"/>
      <line x1="0" y1="<?= h((string) $top) ?>" x2="<?= h((string) $W) ?>" y2="<?= h((string) $top) ?>" stroke="#2A3A66" stroke-width="1" stroke-dasharray="4 4" vector-effect="non-scaling-stroke"/>
<?php foreach ($days as $i => $u):
    $n = (int) $u['chat']['requests'];
    $bh = $n > 0 ? max(2, ($base - $top) * $n / $max) : 0;
    $x = $i * $slot + $slot * 0.18;
    $w = $slot * 0.64;
?>
      <rect x="<?= h(number_format($x, 1, '.', '')) ?>" y="<?= h(number_format($base - $bh, 1, '.', '')) ?>" width="<?= h(number_format($w, 1, '.', '')) ?>" height="<?= h(number_format($bh, 1, '.', '')) ?>" fill="<?= h($i === count($days) - 1 ? '#EA4C22' : '#E6A948') ?>"><title><?= h(str_replace('-', '.', $u['date']) . '：' . $n . ' 次') ?></title></rect>
<?php endforeach; ?>
    </svg>
    <figcaption class="chart-cap">
      <span class="mono"><?= h(str_replace('-', '.', substr($days[0]['date'], 5))) ?></span>
      <span>最高 <?= h((string) $max) ?> 次 / 天，橙色是今天</span>
      <span class="mono"><?= h(str_replace('-', '.', substr($days[count($days) - 1]['date'], 5))) ?></span>
    </figcaption>
  </figure>
  <div class="table-wrap" tabindex="0" role="region" aria-label="每天的用量">
    <table class="usage">
      <thead><tr><th scope="col">日期</th><th scope="col">提问</th><th scope="col">回答</th><th scope="col">出错</th><th scope="col">被限流</th><th scope="col">输入 tokens</th><th scope="col">输出 tokens</th></tr></thead>
      <tbody>
<?php foreach ($usage as $u): $e = $u['chat']['errors']; $zero = (int) $u['chat']['requests'] === 0; ?>
        <tr<?php if ($zero): ?> class="zero"<?php endif; ?>><th scope="row" class="mono"><?= h(str_replace('-', '.', substr($u['date'], 5))) ?></th><td><?= h($num((int) $u['chat']['requests'])) ?></td><td><?= h($num((int) $u['chat']['answered'])) ?></td><td><?= h($num($errSum($e))) ?></td><td><?= h($num($rlSum($e))) ?></td><td><?= h($num((int) $u['chat']['tokens_in'])) ?></td><td><?= h($num((int) $u['chat']['tokens_out'])) ?></td></tr>
<?php endforeach; ?>
      </tbody>
      <tfoot><tr><th scope="row">合计</th><td><?= h($num($tot['requests'])) ?></td><td><?= h($num($tot['answered'])) ?></td><td><?= h($num($tot['err'])) ?></td><td><?= h($num($tot['rl'])) ?></td><td><?= h($num($tot['in'])) ?></td><td><?= h($num($tot['out'])) ?></td></tr></tfoot>
    </table>
  </div>
</section>
