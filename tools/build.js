#!/usr/bin/env node
// 打包售卖版到 dist/:关闭服务器同步、写入公钥启用试用限制。
//   node tools/build.js          需要先 keygen 生成公钥
//   node tools/build.js --dev    不启用激活限制(自测用)
const fs = require('fs'), path = require('path');
const root = path.join(__dirname, '..'), out = path.join(root, 'dist');
const dev = process.argv.includes('--dev');
let html = fs.readFileSync(path.join(root, 'index.html'), 'utf8');
const swap = (a, b) => { if (!html.includes(a)) throw new Error('index.html 中找不到:' + a); html = html.replace(a, b); };
swap('const BUILD={sync:true};', 'const BUILD={sync:false};');
if (!dev) {
  const pubFile = path.join(__dirname, 'public.jwk.json');
  if (!fs.existsSync(pubFile)) { console.error('缺少 tools/public.jwk.json,请先运行:node tools/license.js keygen(或用 --dev)'); process.exit(1); }
  swap('const LICENSE_PUBKEY=null;', 'const LICENSE_PUBKEY=' + JSON.stringify(JSON.parse(fs.readFileSync(pubFile))) + ';');
}
fs.rmSync(out, { recursive: true, force: true }); fs.mkdirSync(out);
fs.writeFileSync(path.join(out, 'index.html'), html);
for (const f of ['manifest.json', 'sw.js', 'icon.svg']) fs.copyFileSync(path.join(root, f), path.join(out, f));
// 单文件版:去掉对外部文件的引用,双击即可打开
const single = html.replace(/<link rel="manifest"[^>]*>\s*/, '').replace(/<link rel="icon"[^>]*>\s*/, '');
fs.writeFileSync(path.join(out, 'momo-single.html'), single);
console.log((dev ? '[开发模式,无激活限制] ' : '') + '已生成 dist/:index.html(托管用,含 PWA 文件)、momo-single.html(单文件)');
