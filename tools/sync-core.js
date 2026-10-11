#!/usr/bin/env node
// 把 core/calc.js 同步到网页(index.html 中的标记区)和 Skill(scripts/calc.js)。
//   node tools/sync-core.js           同步
//   node tools/sync-core.js --check   只检查是否一致(不一致时退出码 1,测试里会调用)
const fs = require('fs'), path = require('path');
const root = path.join(__dirname, '..');
const core = fs.readFileSync(path.join(root, 'core', 'calc.js'), 'utf8').replace(/\s+$/, '') + '\n';
const BEGIN = '// <core:begin> 以下由 tools/sync-core.js 从 core/calc.js 生成,请勿手改\n', END = '// <core:end>';
const htmlPath = path.join(root, 'index.html'), skillPath = path.join(root, 'skill', 'credit-card-manager', 'scripts', 'calc.js');
const check = process.argv.includes('--check');
let ok = true;
let html = fs.readFileSync(htmlPath, 'utf8');
const a = html.indexOf(BEGIN), b = html.indexOf(END);
if (a < 0 || b < 0) { console.error('index.html 里缺少 core 标记区'); process.exit(1); }
const nextHtml = html.slice(0, a) + BEGIN + core + html.slice(b);
if (nextHtml !== html) { ok = false; if (!check) fs.writeFileSync(htmlPath, nextHtml); }
const cur = fs.existsSync(skillPath) ? fs.readFileSync(skillPath, 'utf8') : '';
if (cur !== core) { ok = false; if (!check) { fs.mkdirSync(path.dirname(skillPath), { recursive: true }); fs.writeFileSync(skillPath, core); } }
if (check) { console.log(ok ? 'core 已同步' : 'core 与网页/Skill 不一致,请运行 node tools/sync-core.js'); process.exit(ok ? 0 : 1); }
console.log(ok ? '已是最新' : '已同步到 index.html 和 skill/credit-card-manager/scripts/calc.js');
