# 部署到你的服务器(含 Bark 推送)

服务端 `server.py` 只用 Python 3 标准库,不需要 `pip install`。它做三件事:提供网页、在多台设备间同步数据、每天定时用 Bark 推送待办。

## 1. 先确认服务器环境

登录服务器后依次执行:

```bash
cat /etc/os-release | head -2      # 是什么 Linux
python3 --version                  # 有输出且 >= 3.8 就能直接运行
docker --version                   # 有输出就可以用 Docker
```

- `python3` 可用 → 用 **方式 A**(最简单)。
- 只有 `docker` → 用 **方式 B**。
- 两个都没有 → 先 `apt install python3`(Debian/Ubuntu)或 `yum install python3`(CentOS)。

## 2. 准备 Bark

1. iPhone 在 App Store 安装 **Bark**。
2. 打开 App,首页示例 URL 形如 `https://api.day.app/AbCdEf123/测试`,其中 `AbCdEf123` 就是你的 `BARK_KEY`。
3. 给 Bark 开启通知权限。

## 3. 生成访问令牌

```bash
openssl rand -hex 16     # 输出一串字符,就是 MOMO_TOKEN,请自己保存好
```

## 方式 A:直接运行 + systemd

```bash
git clone <本仓库地址> /opt/momo && cd /opt/momo
git checkout claude/k-hygv97
sudo tee /etc/systemd/system/momo.service >/dev/null <<'EOF'
[Unit]
Description=momo credit card manager
After=network-online.target

[Service]
WorkingDirectory=/opt/momo
Environment=MOMO_TOKEN=换成你的令牌
Environment=BARK_KEY=换成你的BarkKey
Environment=TZ=Asia/Shanghai
ExecStart=/usr/bin/python3 -u /opt/momo/server.py
Restart=always

[Install]
WantedBy=multi-user.target
EOF
sudo systemctl daemon-reload && sudo systemctl enable --now momo
sudo systemctl status momo
```

## 方式 B:Docker

```bash
git clone <本仓库地址> /opt/momo && cd /opt/momo && git checkout claude/k-hygv97
cat > .env <<'EOF'
MOMO_TOKEN=换成你的令牌
BARK_KEY=换成你的BarkKey
TZ=Asia/Shanghai
EOF
docker compose up -d --build
```

## 4. 加 HTTPS(必须)

程序默认只监听 `127.0.0.1:8765`,请用反向代理对外提供 HTTPS(令牌和账户数据不要走明文)。Caddy 示例,自动申请证书:

```
momo.你的域名.com {
    reverse_proxy 127.0.0.1:8765
}
```

没有域名时,可以只在内网/VPN 内访问,或用 Tailscale 等方案。

## 5. 在手机和电脑上连接

1. 打开 `https://momo.你的域名.com`。
2. 点右上角 **☁️ 同步**,输入 `MOMO_TOKEN`。显示「已同步」即成功;其他设备同样操作,数据会自动保持一致。
3. iPhone:Safari → 分享 → **添加到主屏幕**,以后像 App 一样打开。
4. 测试推送(应在几秒内收到 Bark 通知):

```bash
curl -X POST -H "X-Token: 你的令牌" https://momo.你的域名.com/api/test-push
```

## 推送规则

- 默认每天 **9 点和 20 点** 各检查一次(用 `MOMO_PUSH_HOURS=9,20` 修改),有待办才推送,没有就不打扰。
- 待办包括:开户奖励 7 天内到期 / 已满期待确认 / **收益已到账待转走本金**、账单日和还款日 3 天内。
- 「收益已到账」的记录会每天提醒两次,直到你在网页里点「已转走本金」。
- 满期、待转走等事项使用 Bark 的「时效性通知」,可突破专注模式。

## 自动备份

- 服务器每天自动备份一次,数据变更时(覆盖前)每小时最多再备份一份,默认保留最近 60 份(`MOMO_BACKUP_KEEP` 可改)。
- 备份文件在 `data/backups/db-日期-时间.json`,内容就是完整数据,可直接复制走或用网页「导入」。
- 网页「总览」底部的「🗄 服务器备份」可以立即备份,或一键恢复到某个时间点(恢复前会先把当前数据另存一份)。
- 这些备份和数据在同一台服务器上,**防不了服务器整机故障**。建议再用 cron 把 `data/` 同步到别处,例如:
  `0 4 * * * rsync -a /opt/momo/data/ 你的另一台机器:/backup/momo/`

## 币种

卡片和开户奖励都可以选 CNY / USD / HKD / EUR / GBP / JPY。金额按各自币种记录和显示,总览与分析里的汇总统一折合成人民币,汇率在「我的卡片」页底部设置(默认值只是大致参考,请自己更新)。

## 数据与安全

- 数据保存在 `data/db.json`(Docker 为挂载的 `./data`),备份这个文件即可。
- 所有接口都需要令牌;不要把令牌写进公开仓库。`.env` 和 `data/` 已加入 `.gitignore`。
- 应用不保存完整卡号,只保存尾号,请不要在备注里写密码或 CVV。
