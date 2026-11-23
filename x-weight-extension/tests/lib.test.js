const test = require('node:test'), assert = require('node:assert/strict');
const XW = require('../lib.js');
test('数字解析:K/M/万/亿/逗号/空白', () => {
  assert.deepEqual(['1,234', '1.2K', '12K', '1.5M', '2B', '1.2万', '3亿', '1.2 万', '', '—'].map(XW.parseCount),
    [1234, 1200, 12000, 1500000, 2e9, 12000, 3e8, 12000, 0, null]);
});
test('参考分 = 每千次浏览的加权互动', () => {
  const s = XW.score({ replies: 20, reposts: 10, likes: 100, views: 10000 });
  assert.ok(Math.abs(s.total - 18.08) < 1e-9);
  assert.equal(XW.score({ replies: 1, views: 0 }), null);
  assert.ok(Math.abs(XW.score({ replies: 20, views: 10000 }, { reply: 10 }).total - 20) < 1e-9);
});
test('分档与链接解析', () => {
  assert.deepEqual([null, 5, 10, 30, 60].map(v => XW.tier(v).key), ['na', 'low', 'ok', 'good', 'hot']);
  assert.equal(XW.tier(400, 50).key, 'few');          // 浏览太少不评级
  assert.equal(XW.tier(400, 200).key, 'hot');
  assert.deepEqual(XW.parseStatusHref('/Foo_1/status/123/analytics'), { handle: 'foo_1', id: '123' });
  assert.equal(XW.parseStatusHref('/i/lists/1'), null);
});
test('导出 CSV 转义', () => {
  const csv = XW.toCsv([{ id: '1', handle: 'a', text: '含,逗号和"引号"', views: 100, replies: 1, reposts: 0, likes: 2, bookmarks: 0, score: 51.4 }]);
  assert.ok(csv.startsWith('﻿发布时间')); assert.ok(csv.includes('"含,逗号和""引号"""'));
});
