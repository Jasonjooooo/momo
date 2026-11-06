# 审核发布机器人(newsbot)

自动收集 RSS 新闻和网友投稿 → 发到你的**私人审核群** → 你点「✅ 发布」才发到**公开频道**,点「❌ 忽略」就丢掉。

- **RSS 监控**:定时抓取订阅源,按关键词筛选、去重、跳过过期内容。
- **匿名投稿**:网友私聊机器人发文字、图片、视频。审核群里看不到投稿人的账号。数据库只存加盐哈希,不存用户 ID 和用户名。
- **去除元数据**:以文件形式发来的图片会重新编码,去掉 EXIF(GPS 位置、设备型号、拍摄时间);视频用 ffmpeg 去掉位置、设备等容器信息。以普通照片形式发送的图片,Telegram 服务器本身已经去除了 EXIF。
- **改写后发布**:在审核群里**回复**某条新闻,写上你想发布的文字,再点「发布」,就会用你的文字发布。
- **命令**:在审核群发 `/id` 查看对话 ID,发 `/stats` 查看统计。

## 准备

1. **创建机器人**:Telegram 搜 `@BotFather` → `/newbot` → 记下 Token。
2. **创建公开频道**:把机器人加为频道**管理员**,并给它「发布消息」权限。
3. **创建私人审核群**:把机器人拉进群。在群里发 `/id`,机器人会回复群 ID(以 `-100` 开头)和你的用户 ID。
4. 复制 `config.example.json` 为 `config.json`,填写:
   - `review_chat_id`:审核群 ID
   - `channel_id`:频道用户名(如 `@my_channel`)或频道 ID
   - `admin_ids`:允许点审核按钮的人的用户 ID,可以填多个
   - `feeds`:RSS 订阅源;`include` / `exclude`:关键词(`/正则/` 也可以)

## 运行(你的服务器)

```bash
sudo apt install -y python3 python3-pip ffmpeg
pip3 install -r requirements.txt
BOT_TOKEN=你的Token python3 newsbot.py
```

长期运行用 systemd(把 `/opt/momo/newsbot` 换成你的实际路径):

```ini
# /etc/systemd/system/newsbot.service
[Unit]
Description=newsbot
After=network-online.target

[Service]
WorkingDirectory=/opt/momo/newsbot
Environment=BOT_TOKEN=你的Token
Environment=TZ=Asia/Shanghai
ExecStart=/usr/bin/python3 -u newsbot.py
Restart=always

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload && sudo systemctl enable --now newsbot
journalctl -u newsbot -f      # 看日志
```

服务器需要能访问 `api.telegram.org`。

## 安全须知(请务必读)

- **元数据只是第一层。** 画面里的人脸、车牌、门牌、店招、窗外景物、口音,都可能暴露投稿人。发布前请人工检查,必要时打码。
- **机器人本身的记录。** Telegram 服务器知道是哪个账号给机器人发了消息。机器人这边不存用户身份,但无法改变 Telegram 平台本身能看到的信息。高风险的投稿人应当使用不关联真实身份的账号。
- **你自己的风险。** 运营涉及敏感时政内容的频道,可能给你本人和家人带来法律或人身风险。请评估你所在地的法律和自己的处境。
- **核实。** 发布前尽量多源比对,无法确认的标注"未经证实"。发错的内容会伤害当事人,也会伤害频道的信誉。
- **Token 保密。** 拿到 Token 的人可以完全控制机器人,包括查看投稿。

## 测试

```bash
pip install Pillow
python3 -m unittest discover -s tests -v
```

测试用一个假的 Telegram 服务器跑完整流程:RSS 筛选去重、权限检查、改写发布、匿名投稿、图片 EXIF 去除、视频元数据去除、限流。
