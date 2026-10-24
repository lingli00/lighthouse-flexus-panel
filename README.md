# 多云轻量服务器运维面板（腾讯轻量云 / 华为云 Flexus）

单文件 PHP 运维面板：一个页面同时管理 **腾讯云轻量应用服务器（Lighthouse）** 与 **华为云 Flexus 云服务器（X实例 / L实例，底层 ECS）**，零依赖、无数据库、所有 UI / 弹窗 / 提示保持统一风格。

> ⚠️ 使用云 API 密钥（主账号密钥等同于账号最高权限）请务必知悉风险：建议使用仅授权对应产品权限的子账号 / IAM 子用户密钥，面板部署在 HTTPS 且仅本人访问。

## 功能（两侧一致的交集功能）

| 功能 | 腾讯轻量云 | 华为云 Flexus |
|---|---|---|
| 实例列表（统一字段） | ✅ | ✅（含公网 IP / 带宽 / 到期时间） |
| 开机 / 关机 / 重启 | ✅ | ✅（异步，自动轮询状态） |
| 快照：创建 / 删除 / 回滚 | ✅ | ✅（系统盘 EVS 云快照） |
| 监控（CPU / 内存 / 出入带宽） | ✅ QCE/LIGHTHOUSE | ✅ CES SYS.ECS |
| 重置管理员密码 | ✅ | ✅ |
| 重装系统（选择镜像） | ✅ | ✅（changeos，需先关机） |
| 安全组 / 防火墙规则增删 | ✅ | ✅（入方向放行规则） |
| 操作日志（本地文件） | ✅ | ✅ |
| 连通性测试 | ✅ 一键测试 | ✅ |
| ProjectId 自动定位地域 | — | ✅（填完 ProjectId 自动切到对应区域） |

其他能力：面板登录密码 + Session + CSRF、高危操作二次确认、操作日志落盘、手机端适配、密钥可前端配置也可服务端配置。

## 文件说明

| 文件 | 作用 |
|---|---|
| `index.php` | **全部面板**（后端 API 代理 + 前端单页）。含腾讯 TC3-HMAC-SHA256 签名、华为 SDK-HMAC-SHA256（V4）签名、云厂商适配器（TencentAdapter / HuaweiAdapter）、登录鉴权、页面渲染 |
| `HwV4Sign.php` | 华为云 V4 签名工具类独立版（与 index.php 内联版本一致，可单独 include 复用到其他项目） |
| `教程-密钥配置.md` | 详细的密钥获取与填写教程（两家云 + 常见报错对照表） |

## 部署

1. 服务器要求：PHP ≥ 7.4（推荐 8.x），启用 curl、session 扩展。
2. 把 `index.php` 放到站点根目录，直接访问。
3. 修改 `index.php` 顶部配置区的面板登录密码（支持 sha256 哈希）。

## 密钥配置（两种方式二选一，可共存）

- **方式一（推荐）：网页配置** —— 顶部「密钥配置」填写 AK/SK（华为云另需 ProjectId），密钥仅保存在浏览器 localStorage，后端不落盘、不写日志、不入库。
- **方式二：服务端配置** —— 在 `index.php` 配置区写死：
  ```php
  // 腾讯
  define('TC_SECRET_ID',  '你的SecretId');
  define('TC_SECRET_KEY', '你的SecretKey');
  // 华为（需三项都填）
  define('HW_AK',      '你的AK');
  define('HW_SK',      '你的SK');
  define('HW_PROJECT', 'IAM项目ID');
  ```
  配置后页面会显示「服务端已配置密钥」徽标，前端可不再填写；若前端也填了，以前端为准。

## 华为云签名实现说明

零依赖自实现 SDK-HMAC-SHA256 签名（`HwV4Sign` 类），与官方 SDK（`AKSKSigner.java`）行为一致：

- `Authorization: SDK-HMAC-SHA256 Access={AK}, SignedHeaders=host;x-sdk-date, Signature={HMAC-SHA256(StringToSign, SK)}`
- **签名用 CanonicalURI 每段末尾补 `/`**（如 `/v1/{project_id}/cloudservers/detail/`），实际请求 URL 用正常路径 —— 官方规范「计算签名时 URI 必须以 / 结尾」，缺尾斜杠会报 `APIGW.0301 verify ak sk signature failed`。

## 已实测验证

以下均通过真实华为云 Flexus X实例 实测：签名（Access= + 尾斜杠 URI）、实例列表、EIP 带宽反查、BSS 到期时间、镜像列表（ECS 宿主路由 `/v2/{project_id}/images`）、CES 监控（24h→period 1200 / 7d→14400）、系统盘定位（nova 兼容路由 `/v2.1/{project_id}/servers/{id}/os-volume_attachments`）、安全组规则、快照列表。

## 免责声明

本项目按「现状」提供，使用本面板进行的任何云上操作（含重装、回滚等高危操作）后果由使用者自行承担。
