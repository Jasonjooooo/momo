# 信用卡管家

记录信用卡返现/积分规则和开户存款奖励的计算工具:自动计算收益、年费是否回本、奖励年化,到期提醒确认收益并转走本金。支持多币种、图表、导出 Excel。

- 直接用:打开 `index.html`(数据只存浏览器本地)。
- 自己部署带同步和 Bark 推送的版本:见 [DEPLOY.md](DEPLOY.md)。
- 打包售卖版、激活码:见 [SELLING.md](SELLING.md)。
- 作为 Claude Skill 使用:`skill/credit-card-manager/`,和网页版共用同一套计算代码、数据格式互通(命令行 `scripts/cc.js` 导出的文件可以直接在网页「导入」)。打包:`python3 tools/package_skill.py`。
- 测试:`npm install && npx playwright install chromium && npm test`。

文件说明:`core/calc.js` 是**唯一的计算核心**(改完运行 `node tools/sync-core.js` 同步到网页和 Skill);`index.html` 是全部前端;`server.py` 是可选的同步/备份/推送服务(仅标准库);`tools/` 是激活码和打包脚本;`tests/` 是计算与服务端测试。
