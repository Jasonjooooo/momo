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

## 一键发到 X(可选)

开启后,每条待审核内容会有四个按钮:**✅ 发频道**、**𝕏 发到 X**、**🚀 两边都发**、**❌ 忽略**。可以先发一边,另一边稍后再发。

- **新闻**:发到 X 的内容是"标题 + 链接 + 来源"。超过 X 的长度上限会自动截断,链接和来源始终保留。如果你回复改写过,就用你改写的文字。
- **投稿**:文字原样发;照片会再去一次元数据后上传;**视频暂不支持自动发到 X**,需要手动上传。
- 发送失败(比如额度用完、权限不对)时,审核群会收到失败原因,按钮保留,可以重试。

**设置方法**
1. 登录 X 开发者后台 developer.x.com,创建一个 App。
2. 在 App 的 **User authentication settings** 里把权限设为 **Read and Write**。
3. 在 **Keys and tokens** 里拿到 4 个值:API Key、API Key Secret、Access Token、Access Token Secret。**改完权限后要重新生成 Access Token**,否则还是只读权限。
4. `config.json` 里改成 `"x": {"enabled": true}`。
5. 运行时多加 4 个环境变量(systemd 里各写一行 `Environment=`):
   ```
   X_API_KEY=...
   X_API_SECRET=...
   X_ACCESS_TOKEN=...
   X_ACCESS_SECRET=...
   ```

X API 的免费额度、价格和发帖数量限制经常调整,以开发者后台显示的为准。用 API 自动发帖也要遵守 X 的自动化规则,不要批量发重复内容。

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

测试用假的 Telegram 和 X 服务器跑完整流程:RSS 筛选去重、权限检查、改写发布、匿名投稿、图片 EXIF 去除、视频元数据去除、限流、发到 X(签名、长度截断、图片上传、失败重试)。OAuth 签名用 X 官方文档里的示例数据校验过。
