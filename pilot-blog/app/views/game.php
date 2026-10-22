<?php
defined('BLOG_APP') || exit;
// $items: the newest 100 entries (newest first), $total: all stored entries, $on: game.board.
$decLabel = ['land' => '落地', 'ga' => '复飞', 'none' => '没决断'];
?>
<h1 class="page-h">小游戏排行榜</h1>
<p class="notice notice-info">首页小游戏「盲降挑战」的成绩。天气由服务器随机给出，分数由服务器按同一个公式重新算，玩家改不了。榜单只显示名字、分数、评级、云底和能见度，不显示 IP 或其他个人信息。看到不合适的名字，在下面删掉就行。</p>

<section class="card" aria-labelledby="g-sw">
  <h2 class="card-h" id="g-sw">开关</h2>
  <p><?php if ($on): ?><span class="chip chip-ok">开着</span> 玩家飞完可以留名字上榜。<?php else: ?><span class="chip">关着</span> 游戏还能玩，但不记成绩，也不显示榜单。以前的成绩还在。<?php endif; ?></p>
  <form method="post" action="<?= h(url('game.toggle')) ?>"<?php if ($on): ?> data-confirm="关掉以后，游戏还能玩，但不再记成绩，也不显示榜单。以前的成绩还在。" data-confirm-ok="关掉排行榜"<?php endif; ?>>
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="rev" value="<?= h((string) $settings['rev']) ?>">
    <input type="hidden" name="enabled" value="<?= h($on ? '0' : '1') ?>">
    <button class="btn<?= h($on ? '' : ' btn-primary') ?>" type="submit" id="g-toggle"><?= h($on ? '关掉排行榜' : '打开排行榜') ?></button>
  </form>
  <p class="where">近 8 天的成绩全部保留，更早的只留历史最好的 200 条。</p>
</section>

<section class="card" aria-labelledby="g-list">
  <h2 class="card-h" id="g-list">最新成绩</h2>
<?php if (!$items): ?>
  <p class="muted">还没有人交过成绩。</p>
<?php else: ?>
  <p class="where"><?= h('共 ' . $total . ' 条' . ($total > count($items) ? '，这里显示最新的 ' . count($items) . ' 条' : '') . '。时间按北京时间。') ?></p>
  <div class="table-wrap" tabindex="0" role="region" aria-label="最新成绩">
    <table class="usage gtbl" id="g-table">
      <thead><tr><th scope="col">时间</th><th scope="col">名字</th><th scope="col">分数</th><th scope="col">评级</th><th scope="col">云底 / RVR</th><th scope="col">决断</th><th scope="col">操作</th></tr></thead>
      <tbody>
<?php foreach ($items as $e): ?>
        <tr>
          <td class="mono"><?= h(game_time((int) $e['ts'], 'm-d H:i')) ?></td>
          <th scope="row" class="g-name"><?= h((string) $e['name']) ?></th>
          <td><?= h((string) $e['score']) ?></td>
          <td><?= h((string) ($e['grade'] ?? '')) ?></td>
          <td><?= h((int) ($e['base'] ?? 0) . ' ft / ' . (int) ($e['rvr'] ?? 0) . ' m') ?></td>
          <td class="g-dec"><?= h($decLabel[$e['dec'] ?? ''] ?? '') ?></td>
          <td>
            <form method="post" action="<?= h(url('game.delete')) ?>" data-confirm="<?= h('删除「' . $e['name'] . '」的这条成绩（' . $e['score'] . ' 分）？删除后找不回来。') ?>" data-confirm-ok="删除" data-confirm-danger="1">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="id" value="<?= h((string) $e['id']) ?>">
              <button class="btn btn-danger" type="submit">删除</button>
            </form>
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
</section>

<section class="card" aria-labelledby="g-clear">
  <h2 class="card-h" id="g-clear">清空</h2>
  <p class="where">删掉全部成绩，本周榜和总榜都从零开始。</p>
  <form method="post" action="<?= h(url('game.clear')) ?>" data-confirm="清空排行榜？全部成绩都会删掉，找不回来。" data-confirm-ok="清空排行榜" data-confirm-danger="1">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <button class="btn btn-danger" type="submit" id="g-clear-btn"<?php if ($total === 0): ?> disabled<?php endif; ?>>清空排行榜</button>
  </form>
</section>
