// X 权重参考分:纯计算函数(插件和测试共用)。
// 说明:X 的权重乘的是"某个动作的预测概率"。这里用 互动数 ÷ 浏览数 近似概率,
// 得到"每千次曝光的加权互动分",只反映页面上公开可见的回复、转发、点赞,不是 X 内部真实分数。
(function (root, factory) {
  if (typeof module === 'object' && module.exports) module.exports = factory();
  else root.XW = factory();
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';
  // 默认权重:xai-org/x-algorithm 仓库 home-mixer/params/param.rs(2026-11-23 读取)
  const DEFAULT_WEIGHTS = { reply: 5.0, repost: 1.0, like: 0.708 };
  const WEIGHTS_SOURCE = 'xai-org/x-algorithm · home-mixer/params/param.rs · 2026-11-23';

  // "1,234" "1.2K" "12K" "1.5M" "1.2万" "3亿" "1.2 万" → 数字;空白 → 0;无法识别 → null
  function parseCount(text) {
    if (text == null) return 0;
    const s = String(text).replace(/[,\s ]/g, '').trim();
    if (!s) return 0;
    const m = s.match(/^(\d+(?:\.\d+)?)([KkMmBb万萬亿億]?)$/);
    if (!m) return null;
    const mult = { '': 1, k: 1e3, m: 1e6, b: 1e9, '万': 1e4, '萬': 1e4, '亿': 1e8, '億': 1e8 }[m[2].toLowerCase()];
    return Math.round(parseFloat(m[1]) * mult);
  }

  // metrics: {replies, reposts, likes, views};views 缺失或为 0 时无法计算,返回 null
  function score(metrics, weights) {
    const w = Object.assign({}, DEFAULT_WEIGHTS, weights || {});
    const v = metrics.views;
    if (!v || v <= 0) return null;
    const parts = {
      reply: w.reply * (metrics.replies || 0) / v * 1000,
      repost: w.repost * (metrics.reposts || 0) / v * 1000,
      like: w.like * (metrics.likes || 0) / v * 1000,
    };
    return { total: parts.reply + parts.repost + parts.like, parts };
  }

  // 粗略分档(按常见互动率估算,仅供参考):普通帖子约 10–30
  function tier(total) {
    if (total == null) return { key: 'na', label: '无浏览数' };
    if (total >= 60) return { key: 'hot', label: '很受偏爱' };
    if (total >= 30) return { key: 'good', label: '较好' };
    if (total >= 10) return { key: 'ok', label: '一般' };
    return { key: 'low', label: '偏低' };
  }

  function fmt(n) {
    return n == null ? '—' : (n >= 100 ? Math.round(n).toString() : n.toFixed(1));
  }

  // 从 /handle/status/123 这类链接里取出作者和帖子 ID
  function parseStatusHref(href) {
    const m = String(href || '').match(/^\/([A-Za-z0-9_]{1,15})\/status\/(\d+)/);
    return m ? { handle: m[1].toLowerCase(), id: m[2] } : null;
  }

  function toCsv(posts) {
    const esc = v => { const s = v == null ? '' : String(v); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s; };
    const head = ['发布时间', '链接', '内容', '浏览', '回复', '转发', '点赞', '收藏', '参考分', '回复率%', '最后记录时间'];
    const rows = posts.map(p => [p.created, `https://x.com/${p.handle}/status/${p.id}`, p.text, p.views, p.replies, p.reposts,
      p.likes, p.bookmarks, p.score == null ? '' : p.score.toFixed(2),
      p.views ? (p.replies / p.views * 100).toFixed(3) : '', p.lastSeen]);
    return '﻿' + [head, ...rows].map(r => r.map(esc).join(',')).join('\r\n');
  }

  return { DEFAULT_WEIGHTS, WEIGHTS_SOURCE, parseCount, score, tier, fmt, parseStatusHref, toCsv };
});
