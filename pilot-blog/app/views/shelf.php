<?php defined('BLOG_APP') || exit; ?>
<h1 class="page-h">AI 超市</h1>
<p class="lede">首页「AI 超市」栏的货架和工具。改完先点「保存草稿」，网站不会变；点「保存并发布」网站才更新。发布时每个货架至少要有 1 个工具。</p>
<div class="restore" id="restore" hidden></div>
<form class="editor" id="shelf-form" novalidate>
  <div id="shelves" class="shelves" data-path="shelves"></div>
  <p class="ferr" id="shelves-err" hidden></p>
  <div class="row-btns">
    <button type="button" class="btn" id="shelf-add">加一个货架</button>
  </div>
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
  <input type="hidden" name="shelf" value="">
</form>
<script type="application/json" id="editor-data"><?= j([
    'unit' => 'shelf',
    'rev' => $state['rev'],
    'updated_at' => $state['updated_at'],
    'dirty' => $state['dirty'],
    'shelves' => $draft['shelves'],
    'live' => $live,
]) ?></script>
