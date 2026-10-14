<?php
defined('BLOG_APP') || exit;
$d = $post['draft'];
$cover = $d['cover'] ?? null;
$locked = $client['slug_locked'];
?>
<p class="crumb"><a href="<?= h(url('posts')) ?>">飞行日志</a> / <span><?= h($client['status_label']) ?></span></p>
<h1 class="page-h" id="post-h"><?= h(($d['title'] ?? '') !== '' ? $d['title'] : '新日志') ?></h1>
<div class="restore" id="restore" hidden></div>

<div class="tabs" role="tablist" aria-label="编辑或预览">
  <button type="button" role="tab" id="tab-edit" aria-controls="pane-edit" aria-selected="true">编辑</button>
  <button type="button" role="tab" id="tab-preview" aria-controls="pane-preview" aria-selected="false" tabindex="-1">预览</button>
</div>

<div class="post-layout">
<form class="editor post-edit" id="pane-edit" role="tabpanel" aria-labelledby="tab-edit" novalidate>
  <div class="field" data-path="title">
    <label for="p-title">标题</label>
    <input id="p-title" type="text" value="<?= h((string) $d['title']) ?>" data-max="60" data-soft="30" enterkeyhint="next">
    <p class="count" aria-hidden="true"></p>
    <p class="ferr" hidden></p>
  </div>

  <div class="field" data-path="body">
    <label for="p-body">正文</label>
    <div class="mdbar" role="toolbar" aria-label="正文格式" aria-controls="p-body">
      <button type="button" class="btn btn-tool" data-md="h2">小标题</button>
      <button type="button" class="btn btn-tool" data-md="bold">加粗</button>
      <button type="button" class="btn btn-tool" data-md="ul">列表</button>
      <button type="button" class="btn btn-tool" data-md="quote">引用</button>
      <button type="button" class="btn btn-tool" data-md="img">插入图片</button>
      <button type="button" class="btn btn-tool" data-md="link">链接</button>
      <button type="button" class="btn btn-tool btn-ghost" data-md="help">怎么写</button>
    </div>
    <textarea id="p-body" rows="14" data-max="60000" class="body-ta" spellcheck="false"><?= h((string) $d['body']) ?></textarea>
    <p class="hint">每按一次换行就是新的一段。</p>
    <p class="count" aria-hidden="true"></p>
    <p class="ferr" hidden></p>
    <input type="file" id="body-file" accept="image/*" hidden>
  </div>

  <div class="field" data-path="excerpt">
    <label for="p-excerpt">摘要（可留空）</label>
    <p class="where">首页和全部日志页显示的一两句。留空就自动取正文开头。设为预告时必须写。</p>
    <textarea id="p-excerpt" rows="3" data-max="160" data-soft="80" data-oneline="1"><?= h((string) $d['excerpt']) ?></textarea>
    <p class="count" aria-hidden="true"></p>
    <p class="ferr" hidden></p>
  </div>

  <div class="field" data-path="tags">
    <label for="p-tags">标签</label>
    <p class="where">用空格或逗号隔开，最多 5 个。第一个会显示在首页。比如：IMC 天气</p>
    <input id="p-tags" type="text" value="<?= h(implode(' ', $d['tags'] ?? [])) ?>" autocapitalize="characters" enterkeyhint="next">
    <p class="ferr" hidden></p>
  </div>

  <div class="field" data-path="cover">
    <p class="label" id="cover-l">封面图</p>
    <div class="cover" id="cover-box">
      <img id="cover-img" alt=""<?php if ($cover): ?> src="<?= h(site_base() . $cover['src']) ?>"<?php else: ?> hidden<?php endif; ?>>
      <p class="where" id="cover-empty"<?php if ($cover): ?> hidden<?php endif; ?>>还没有封面图。</p>
    </div>
    <div class="row-btns">
      <label class="btn file-btn" for="cover-file" id="cover-pick"><?= h($cover ? '换一张' : '上传封面图') ?></label>
      <input type="file" id="cover-file" accept="image/*" class="vh">
      <button type="button" class="btn btn-ghost" id="cover-remove"<?php if (!$cover): ?> hidden<?php endif; ?>>去掉封面图</button>
    </div>
    <p class="where">JPG、PNG、WebP 或 GIF，最大 <?= h((string) $upload_mb) ?> MB。手机上会先缩小再上传。还没发布的草稿里的图片，只有知道完整网址的人才看得到。</p>
    <p class="ferr" hidden></p>
    <label for="p-cover-alt">图片说明（读屏软件会读这句）</label>
    <input id="p-cover-alt" type="text" value="<?= h((string) ($cover['alt'] ?? '')) ?>" data-max="120">
  </div>

  <div class="field-row">
    <div class="field" data-path="date">
      <label for="p-date">日期</label>
      <input id="p-date" type="date" value="<?= h((string) ($d['date'] ?? '')) ?>">
      <p class="where">留空的话，发布那天自动填上。</p>
      <p class="ferr" hidden></p>
    </div>
    <div class="field" data-path="minutes">
      <label for="p-minutes">阅读时间（分钟）</label>
      <input id="p-minutes" type="text" inputmode="numeric" pattern="[0-9]*" value="<?= h($d['minutes'] === null ? '' : (string) $d['minutes']) ?>" placeholder="留空自动算">
      <p class="ferr" hidden></p>
    </div>
  </div>

  <div class="field" data-path="fix">
    <label for="p-fix">航路点代号（5 个大写字母）</label>
    <p class="where">首页航路图上这一段的名字，每篇不能重复。</p>
    <input id="p-fix" type="text" value="<?= h($client['fix']) ?>" maxlength="5" autocapitalize="characters" autocomplete="off" spellcheck="false" class="mono">
    <p class="ferr" hidden></p>
  </div>

  <div class="field" data-path="slug">
    <label for="p-slug">网址</label>
    <div class="affix"><span class="mono" aria-hidden="true">posts/</span><input id="p-slug" type="text" value="<?= h($client['slug']) ?>" autocapitalize="off" autocomplete="off" spellcheck="false" class="mono"<?php if ($locked): ?> readonly aria-describedby="slug-lock"<?php endif; ?>><span class="mono" aria-hidden="true">.html</span></div>
<?php if ($locked): ?>
    <p class="where" id="slug-lock">已经发布过，网址不能再改，否则旧链接会失效。</p>
<?php else: ?>
    <p class="where">只能用小写英文字母、数字和短横线。默认跟着航路点代号走，发布以后就不能再改。</p>
<?php endif; ?>
    <p class="ferr" hidden></p>
  </div>
</form>

<section class="post-preview" id="pane-preview" role="tabpanel" aria-labelledby="tab-preview">
  <p class="where pv-note">预览按日志页面的样子显示，停笔一会儿会自动刷新。</p>
  <iframe name="pv" id="pv" title="日志预览" sandbox="allow-same-origin"></iframe>
</section>
</div>

<div class="bar" id="bar">
  <p class="bar-status" id="bar-status" role="status" aria-live="polite"></p>
  <div class="bar-btns bar-3">
    <button type="button" class="btn" data-act="save">保存草稿</button>
    <button type="button" class="btn btn-primary" data-act="publish">发布</button>
    <button type="button" class="btn btn-ghost" data-act="more" aria-haspopup="dialog">更多</button>
  </div>
</div>

<div id="md-help" hidden>
  <p>每按一次换行，就是新的一段。空行会被忽略。</p>
  <ul class="help-list">
    <li><code>## 小标题</code>：一行开头写两个井号和空格。</li>
    <li><code>**加粗**</code>：两边各两个星号。</li>
    <li><code>- 一条</code>：一行开头写短横线和空格，连着几行就是一个列表；<code>1. 第一步</code> 是带数字的列表。</li>
    <li><code>&gt; 一句提醒</code>：一行开头写大于号，会显示成提示框。</li>
    <li><code>[文字](https://网址)</code>：链接。</li>
    <li><code>---</code>：一行只写三个短横线，是分隔线。</li>
    <li>图片用「插入图片」按钮，会自动写好一行 <code>![说明](uploads/…)</code>，方括号里的说明可以改。</li>
  </ul>
</div>
<form id="pv-form" method="post" action="<?= h(url('preview.post')) ?>" target="pv" hidden>
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="id" value="<?= h($client['id']) ?>">
  <input type="hidden" name="post" value="">
</form>
<form id="del-form" method="post" action="<?= h(url('post.delete')) ?>" hidden>
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="id" value="<?= h($client['id']) ?>">
</form>
<script type="application/json" id="editor-data"><?= j(['unit' => 'post', 'post' => $client, 'upload_mb' => $upload_mb]) ?></script>
