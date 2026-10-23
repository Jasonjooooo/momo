<?php
defined('BLOG_APP') || exit;
// $data: poster_targets(). The browser draws the poster (admin/assets/poster.js); nothing is uploaded or stored.
$groups = [];
foreach ($data['items'] as $it) {
    $groups[$it['group']][] = $it;
}
?>
<h1 class="page-h">分享海报</h1>
<p class="notice notice-info">选一个页面，生成一张竖版海报：上面是图和标题，下面是二维码。保存到手机后可以发朋友圈或者发给朋友，别人长按二维码就能打开这个页面。只有已经发布的日志和在卖的商品才能做海报。</p>

<section class="card" aria-labelledby="pst-h" id="poster" data-poster="<?= h(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>">
  <h2 class="card-h" id="pst-h">做一张海报</h2>
  <div class="field">
    <label for="pst-target">海报内容</label>
    <select id="pst-target">
<?php foreach ($groups as $g => $list): ?>
      <optgroup label="<?= h($g) ?>">
<?php foreach ($list as $it): ?>
        <option value="<?= h($it['id']) ?>"><?= h($it['group'] === '首页' ? '首页：' . $it['title'] : $it['title'] . (isset($it['price']) ? '（' . $it['price'] . '）' : '')) ?></option>
<?php endforeach; ?>
      </optgroup>
<?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="pst-line">海报上的一句话（可以不改）</label>
    <textarea id="pst-line" rows="3" maxlength="80"></textarea>
    <p class="where">默认是日志摘要或商品的一句话介绍。最多 80 个字，太长的会在第三行截断。</p>
  </div>
  <div class="row-btns"><button class="btn btn-primary" type="button" id="pst-make">生成海报</button><a class="btn" id="pst-save" hidden download="poster.jpg">保存图片</a></div>
  <p class="where" id="pst-msg" aria-live="polite"></p>
  <div class="pst-out" id="pst-out" hidden><img id="pst-img" alt="海报预览"></div>
  <p class="where" id="pst-tip" hidden>手机上：长按上面的图片，选「保存图片」或「发送给朋友」。</p>
</section>
<script src="<?= h(asset_url('qrcode.min.js')) ?>" defer></script>
<script src="<?= h(asset_url('poster.js')) ?>" defer></script>
