# AI 快速上手 · 轻量服务器运维面板

> 换电脑后，只读这一个文件即可接手本项目。所有信息以本文件为准，不需要再翻代码找入口。

## 1. 一句话定位

单文件 PHP 运维面板（`index.php`，约 3860 行，HTML/JS 全内联）：**主用华为轻量云 Flexus**，**腾讯轻量云兼容可用**，内置**阿里云云解析 DNS**（含备注编辑）。零依赖、无数据库、无构建。

仓库：https://github.com/lingli00/lighthouse-flexus-panel （分支 `main`）。面板内**不含**任何仓库同步功能，源码由本地 git 推送。

## 2. 文件与分工

| 文件 | 说明 |
|---|---|
| `index.php` | 全部代码。改功能只改这个文件 |
| `HwV4Sign.php` | 华为签名类独立版（index.php 内有同款内联，改签名要两处同步） |
| `README.md` | 对外说明（功能表 / 部署 / 签名实现） |
| `教程-密钥配置.md` | 各云密钥获取教程 + 报错对照表 |
| `项目说明.md` | 结构、功能定位、已知问题（改代码前先看「已知问题集中点」） |
| `AI-快速上手.md` | 本文件 |

## 3. 面板密码

`index.php` 配置区 `PANEL_PASSWORD`，支持 `sha256:<hash>` 或明文。当前为 **sha256 哈希**；如需重置：`php -r "echo hash('sha256','新密码');"` 后替换。
登录：Session（`lhpanel_sid`，1 小时过期）+ CSRF（`window.__CFG__.csrf`）。所有 API 走 `POST` JSON + `action` 字段。

## 4. 后端约定（改代码必须遵守）

- 新增接口：在 `dispatch()` 里加 `case 'xxx': json_out(['ok'=>true,'data'=>$fn($d)]);`。**别忘 `json_out()` 内含 `exit`**，所以 `case` 不需要 `break`。
- 错误：抛 `new ApiError('中文提示', '错误码')`，由 `dispatch()` 统一捕获并写日志。
- 密钥注入：`dispatch()` 按 action 前缀把前端 `cred` 写进 `$GLOBALS['TC_CRED'|'HW_CRED'|'ALI_CRED'|'GH_CRED']`，后端函数只读全局，**绝不回传到前端、绝不写日志**。
- 日志：`write_log('动作','对象','success|fail','说明')`，落 `lighthouse_panel.log`。
- 云侧统一走适配器：`CloudAdapter` 接口 + `TencentAdapter` / `HuaweiAdapter`，新增云 = 实现接口 + `make_adapter()` 加分支。

## 5. 前端约定

- 视图层：`S.view`（`cloud` | `dns`）+ `S.tab`（`overview/firewall/snapshot/monitor/logs/about/dns`）。
  - `applyView()`：`dns` 视图隐藏 `#insBar`（地域/实例栏）与 `#tabBar`（二级 Tab）。
  - `showTab(name)`：切换 `.tab-pane` 显隐并按需拉数据。**新增一个二级 Tab = 加按钮 `data-tab` + 加 `<section id="tab-xxx" class="tab-pane hidden">` + `PANES` 数组加名字 + 需要时在 `showTab` 里加加载调用。**
- 顶部三入口按钮：`.cloud-tab`，`id="cloudTab-{tencent|huawei|dns}"`，选中样式 `tab-active`（蓝色）。
- 弹窗统一 `openMask(title, html, okText, onOk, danger)`；`$()` / `$$()` 是选择器简写；`esc()` 必须用于任何拼接进 HTML 的接口数据。
- 密钥存 localStorage：`lhpanel.cred.v1`（tencent/huawei/ali）、`lhpanel.cloud.v1` / `lhpanel.view.v1`（视图记忆）。

## 6. 阿里云 DNS 备注（v1.2.0 新增）

- 阿里云 `UpdateDomainRecord` **不含** Remark，必须单独调 `UpdateDomainRecordRemark(RecordId, Remark)`。
- 备注为空字符串 = 删除备注（阿里云文档行为）。
- 列表数据来自 `DescribeDomainRecords` 的 `Remark` 字段（`biz_ali_records()` 已映射）。
- 相关函数：`biz_ali_record_remark()` / `biz_ali_record_add()`（新增后按返回的 `RecordId` 补写备注）/ `biz_ali_record_upd()`；前端 `openDnsRemarkForm()` + 表格「备注」按钮。

## 7. 改完自测清单

1. `php -l index.php`（无 PHP 环境时可用 `npm i php-parser` 解析，或直接 `node --check` 检查抽出的内联 JS）。
2. 登录 → 顶部三个入口都点一遍（腾讯 / 华为 / 域名解析）。
3. 域名解析：选域名 → 记录列表有「备注」列 → 改备注保存 → 提示「已同步」。
4. 「说明」页各区块正常渲染、版本号正确。
5. 手机宽度（≤640px）：顶部按钮换行成整行三选一，不出现横向滚动。

## 8. 交付与打包

- 本地工作副本：`C:/Users/Administrator/WorkBuddy/2026-10-27-11-34-13/lighthouse/`
- 桌面交付目录：`C:/Users/Administrator/Desktop/轻量服务器运维面板/`（含 zip）
- 远端仓库：`git@github.com:lingli00/lighthouse-flexus-panel.git`（HTTPS 推送需 PAT）

推送前**必须排除**：`lighthouse_panel.log`、`cacert.pem`、`.git` 之外的任何 `_site` / 测试脚本（见 `.gitignore`）。

## 9. 硬性约束（不要破坏）

- 单文件部署：`index.php` 必须可独立运行，不引入第三方 PHP 依赖（除可选 `HwV4Sign.php`）。
- 密钥永不出现在 HTML / JS / 接口响应 / 日志中。
- 不新增数据库、不写 Session 存密钥。
- 危操作保持二次确认。
