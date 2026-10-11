#!/usr/bin/env node
// 激活码工具(需要 Node 18+,无需安装依赖)
//   node tools/license.js keygen                生成密钥对(只需做一次)
//   node tools/license.js issue "买家名称"       签发激活码
//   node tools/license.js verify <激活码>        校验激活码
// 私钥 tools/private.jwk.json 只留在你自己的电脑上,绝不要提交到仓库或发给别人。丢了私钥就无法再签发能被已售版本接受的激活码。
const fs = require('fs'), path = require('path'), crypto = require('crypto');
const subtle = crypto.webcrypto.subtle;
const priv = path.join(__dirname, 'private.jwk.json'), pub = path.join(__dirname, 'public.jwk.json');
const b64u = b => Buffer.from(b).toString('base64url');
const alg = { name: 'ECDSA', namedCurve: 'P-256' }, sig = { name: 'ECDSA', hash: 'SHA-256' };
async function main() {
  const [cmd, arg] = process.argv.slice(2);
  if (cmd === 'keygen') {
    if (fs.existsSync(priv)) return console.error('私钥已存在,为避免覆盖已停止:' + priv);
    const k = await subtle.generateKey(alg, true, ['sign', 'verify']);
    fs.writeFileSync(priv, JSON.stringify(await subtle.exportKey('jwk', k.privateKey)), { mode: 0o600 });
    fs.writeFileSync(pub, JSON.stringify(await subtle.exportKey('jwk', k.publicKey)));
    return console.log('已生成:\n  私钥(保密,已在 .gitignore 中)', priv, '\n  公钥(会被打包进售卖版)', pub);
  }
  if (cmd === 'issue') {
    if (!arg) return console.error('用法:node tools/license.js issue "买家名称"');
    const key = await subtle.importKey('jwk', JSON.parse(fs.readFileSync(priv)), alg, false, ['sign']);
    const payload = Buffer.from(JSON.stringify({ n: arg, id: crypto.randomBytes(4).toString('hex'), t: new Date().toISOString().slice(0, 10) }));
    const s = await subtle.sign(sig, key, payload);
    return console.log(b64u(payload) + '.' + b64u(s));
  }
  if (cmd === 'verify') {
    const [pl, sg] = String(arg || '').split('.');
    const key = await subtle.importKey('jwk', JSON.parse(fs.readFileSync(pub)), alg, false, ['verify']);
    const ok = pl && sg && await subtle.verify(sig, key, Buffer.from(sg, 'base64url'), Buffer.from(pl, 'base64url'));
    return console.log(ok ? '有效:' + Buffer.from(pl, 'base64url').toString() : '无效');
  }
  console.error('用法:keygen | issue "买家名称" | verify <激活码>');
}
main().catch(e => { console.error(e.message); process.exit(1); });
