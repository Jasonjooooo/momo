<?php
defined('BLOG_APP') || exit;
$byGroup = [];
foreach ($reg as $key => $f) {
    $byGroup[$f['group']][$key] = $f;
}
$fields = $draft['fields'];
?>
<h1 class="page-h">网站文字</h1>
<p class="lede">首页和日志页面上的文字都在这里。改完先点「保存草稿」，网站不会变；点「保存并发布」网站才更新。图上的航空代码和数字是装饰，不在这里改。</p>
<div class="restore" id="restore" hidden></div>
<div class="field find">
  <label for="find">找文字</label>
  <input id="find" type="search" placeholder="找文字（比如：订阅）" autocomplete="off" enterkeyhint="search">
  <p class="where" id="find-none" hidden>没有找到。换个词试试。</p>
</div>
<form class="editor" id="site-form" novalidate>
<?php foreach ($groups as $g => $gTitle): if (empty($byGroup[$g])) { continue; } ?>
  <details class="grp" id="g-<?= h($g) ?>" data-group="<?= h($g) ?>">
    <summary><span class="grp-t"><?= h($gTitle) ?></span><span class="badge" hidden></span></summary>
    <div class="grp-body">
<?php if ($g === 'hero'): ?>
      <p class="where">开场动画会随着手指滑动播放，每一段的大字和小字依次出现。字太长在手机上会挤成好几行，改完请点「预览」看一看。</p>
<?php elseif ($g === 'post'): ?>
      <p class="where">每篇日志的页面和「全部日志」页面上用到的文字。</p>
<?php endif; ?>
<?php foreach ($byGroup[$g] as $key => $f):
    if (in_array($key, ['about.img_alt', 'about.figcaption'], true)) { continue; }   // edited per photo in the album below
    $id = 'f-' . str_replace('.', '-', $key);
    $val = (string) ($fields[$key] ?? '');
    $long = $f['multiline'] || $f['max'] > 90 || mb_strlen($val) > 34;
    $warn = $warnings['fields.' . $key] ?? null;
?>
      <div class="field" data-path="fields.<?= h($key) ?>">
        <label for="<?= h($id) ?>"><?= h($f['label']) ?></label>
        <p class="where">在哪里：<?= h($f['where']) ?></p>
<?php if ($long): ?>
        <textarea id="<?= h($id) ?>" data-k="<?= h($key) ?>" rows="<?= h($f['multiline'] ? '5' : '2') ?>" data-max="<?= h((string) $f['max']) ?>"<?php if ($f['soft_max']): ?> data-soft="<?= h((string) $f['soft_max']) ?>"<?php endif; ?><?php if (!$f['multiline']): ?> data-oneline="1"<?php endif; ?>><?= h($val) ?></textarea>
<?php else: ?>
        <input id="<?= h($id) ?>" data-k="<?= h($key) ?>" type="<?= h($f['kind'] === 'url' ? 'url' : 'text') ?>" value="<?= h($val) ?>" data-max="<?= h((string) $f['max']) ?>"<?php if ($f['soft_max']): ?> data-soft="<?= h((string) $f['soft_max']) ?>"<?php endif; ?><?php if ($f['kind'] === 'url'): ?> inputmode="url" autocapitalize="off" spellcheck="false"<?php elseif ($f['kind'] === 'year'): ?> inputmode="numeric"<?php endif; ?>>
<?php endif; ?>
<?php if ($f['hint']): ?>
        <p class="hint"><?= h($f['hint']) ?></p>
<?php elseif ($key === 'about.body'): ?>
        <p class="hint">每一段写成一行，段和段之间空一行。</p>
<?php elseif ($key === 'type.icao'): ?>
        <p class="hint">一行一个代码。</p>
<?php elseif ($f['kind'] === 'pattern'): ?>
        <p class="hint">花括号里的 <?= h(implode(' ', $f['vars'])) ?> 会自动换成数字，请保留。</p>
<?php endif; ?>
        <p class="count" aria-hidden="true"></p>
        <p class="fwarn"<?php if (!$warn): ?> hidden<?php endif; ?>><?= h((string) $warn) ?></p>
        <p class="ferr" hidden></p>
      </div>
<?php endforeach; ?>
<?php if ($g === 'about'): ?>
      <h3 class="sub-h" id="album-h">照片（INSET A）</h3>
      <p class="where">「关于」右下角的照片框。只有 1 张时和原来一样；2 张以上会变成可以左右切换的小相册，手机上用手指滑。至少留 1 张，最多 <?= h((string) ALBUM_MAX) ?> 张。第一张最先显示。</p>
      <div class="list album-list" id="album-list" data-path="album"></div>
      <input type="file" id="album-file" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
      <p class="hint">照片会自动缩小并重新保存，最大 <?= h((string) intdiv(upload_limit_bytes(), 1048576)) ?> MB。横着拍的照片最合适，框是 16:9。</p>
<?php elseif ($g === 'type'): ?>
      <h3 class="sub-h" id="facts-h">数据格子</h3>
      <p class="where">机型线图下面的小格子，3 条或 6 条最整齐，最多 9 条。</p>
      <div class="list" id="facts-list" data-path="type_facts"></div>
<?php elseif ($g === 'faq'): ?>
      <h3 class="sub-h" id="faq-h">问答条目</h3>
      <p class="where">问答卡片里的每一条，最多 12 条。</p>
      <div class="list" id="faq-list" data-path="faq"></div>
<?php endif; ?>
    </div>
  </details>
<?php endforeach; ?>
</form>

<div class="bar" id="bar">
  <p class="bar-status" id="bar-status" role="status" aria-live="polite"></p>
  <div class="bar-btns">
    <button type="button" class="btn" data-act="save">保存草稿</button>
    <button type="button" class="btn" data-act="preview">预览</button>
    <button type="button" class="btn btn-primary" data-act="publish">保存并发布</button>
    <button type="button" class="btn btn-ghost" data-act="more" aria-haspopup="dialog">更多</button>
  </div>
</div>
<form id="pv-form" method="post" action="<?= h(url('preview.home')) ?>" target="_blank" hidden>
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="site" value="">
</form>
<script type="application/json" id="editor-data"><?= j([
    'unit' => 'site',
    'rev' => $state['rev'],
    'updated_at' => $state['updated_at'],
    'dirty' => $state['dirty'],
    'faq' => $draft['faq'],
    'type_facts' => $draft['type_facts'],
    'album' => $draft['album'],
    'site_base' => site_base(),
    'album_max' => ALBUM_MAX,
    'live' => $live,
]) ?></script>
