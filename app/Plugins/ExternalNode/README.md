# ExternalNode 商业独立外部节点采集与隔离分发插件 (v1.0.0)

> 适用于 **V2Board / Xboard** 及衍生面板的轻量级、零侵入、不死自愈外部节点管理系统。
> 专为**“零成本免费引流”**、**“白嫖池防封隔离”**与**“商业防白嫖流控”**设计。

---

## 一、 为什么选择 ExternalNode 独立插件？

| 特性 | 本插件设计 | 传统侵入式修改方案 |
| :--- | :--- | :--- |
| **数据库影响** | **零数据库变动（0 张新表，0 改原有表）** | 在 MySQL 强行新建表，升级面板极易报错 |
| **数据可靠性** | **不死自愈引擎（Auto-healing）**，误删毫秒级自愈 | 文件误删或格式错误直接抛出 500 崩溃 |
| **业务隔离** | **免费引流与付费核心专线 100% 物理隔离** | 免费混在正规订阅中，被爬虫盯上导致付费客户被墙 |
| **耦合程度** | **绝对解耦**，放进目录即生效，删掉目录即完全卸载 | 散落在各业务控制器中，卸载如同大修 |
| **流量成本** | **零中转机成本**，直接复用外部节点直连 | 必须自费上中转机转发，IP 几天就被墙 |

---

## 二、 极速安装与部署 (只需 1 分钟)

### 步骤 1：上传插件包
将 `ExternalNode` 文件夹直接复制/解压到你的 V2Board 网站根目录下的：
```bash
app/Plugins/ExternalNode/
```

### 步骤 2：启用挂载 (二选一，推荐方式 A)

**方式 A (优雅 ServiceProvider 挂载，推荐)**：
在 `app/Providers/AppServiceProvider.php` 的 `register()` 方法内追加：
```php
if (class_exists(\App\Plugins\ExternalNode\ExternalNodeServiceProvider::class)) {
    $this->app->register(\App\Plugins\ExternalNode\ExternalNodeServiceProvider::class);
}
```

**方式 B (路由文件直接引用)**：
在 `routes/web.php` 末尾追加：
```php
@include_once app_path('Plugins/ExternalNode/routes.php');
```

---

## 三、 定时调度与自动化配置

插件自带三个 Artisan 命令行：
- `php artisan external:collect`：全网采集订阅源、广告清洗、去重与智能命名；
- `php artisan external:check`：分批非阻塞并发 TCPing 测活，自动过滤离线节点；
- `php artisan external:cron`：**全自动总调度器**（定时触发采集、每次测活、自动清理连续离线死节点）。

### 宝塔计划任务配置
在宝塔面板添加一条**Shell 脚本计划任务**（每 5~10 分钟执行一次）：
```bash
cd /www/wwwroot/你的网站目录 && php artisan external:cron >> /dev/null 2>&1
```

---

## 四、 独立可视化 Web 控制台 (开箱即用)

本插件内置了 **极致现代化高颜值单文件 Web 仪表盘**，完全还原并超越商业版界面，无需安装任何额外 Node.js 或构建工具，直接在浏览器打开即可管理！

* **访问地址**：`https://你的域名/admin/external-nodes`（或 `/api/v1/admin/plugin/external-node/dashboard`）
* **核心功能**：
  - **4 块实时磨砂玻璃统计卡片**（在线节点数、离线异常数、已启用订阅源、虚拟扣费规则）；
  - **一键全网采集清洗**与**一键非阻塞并发测活**；
  - **节点池管理与筛选**（按地区国旗、Vmess/Vless/Trojan/SS/Hy2 协议筛选，支持单节点复制）；
  - **订阅源列表可视化增删**（开关切换、自定义名称、URL编辑）；
  - **引流策略与虚拟扣费配置**（每次扣除流量 MB、下发节点数上限、前缀后缀广告词）；
  - **引流工具箱**（直接生成测试订阅链接，一键复制 Clash / Base64）。

### 🛡️ 银行级安全防护 (双重鉴权锁定)
为了防止公网扫描器或未授权人员窥探控制台，插件采用了**“双重鉴权防护”**：
1. **自动放行**：若当前浏览器已登录 V2Board/Xboard 管理后台，自动识别管理员身份免密放行；
2. **安全锁屏**：若未检测到管理员登录态，页面直接弹出**毛玻璃安全锁**，必须输入高强度安全管理通信密钥（`admin_secret_key`）才能解锁；
3. **接口加锁**：所有后台 API 均受 `verifyAuth` 卫士保护，未授权调用一律 403 强行阻断；
4. **密钥获取与自定义**：查看服务器 `storage/app/external_nodes.json` 里的 `settings.admin_secret_key`，亦可在控制台设置中随时重置。

---

## 五、 接口定义与使用指引

### 1. 免费引流独立订阅接口
- **路由**：`GET /api/v1/free/subscribe?token={user_token}`
- **客户端自适应**：
  - 若客户端为 **Clash / Clash Meta / Clash Verge / MOMclash**，自动下发标准完整 YAML 配置（包含延迟优选策略组）；
  - 若为通用客户端（Shadowrocket、V2RayN），自动下发 Base64 订阅；
- **虚拟按次扣费**：
  - 默认每次拉取扣除用户 `1024MB`（1GB）流量，扣完即止；
  - 流量用尽时下发友好提示节点：`⚠️ 免费额度已耗尽 - 请前往官网签到或升级VIP`；
- **防封独立域名**：可在 Nginx 或 Cloudflare 中将引流专用二级域名（如 `free-sub.yourdomain.com`）直接反代此接口，主站核心域名永不暴露。

### 2. 后台管理与控制 API
| 路径 | 方法 | 功能说明 |
| :--- | :--- | :--- |
| `/api/v1/admin/plugin/external-node/overview` | GET | 节点池统计、在线/离线数、地区分布 |
| `/api/v1/admin/plugin/external-node/sources` | POST | 增删改订阅源链接 |
| `/api/v1/admin/plugin/external-node/settings` | POST | 调整每次扣除流量、前缀广告词、并发数量 |
| `/api/v1/admin/plugin/external-node/collect` | POST | 手动立即全量采集 |
| `/api/v1/admin/plugin/external-node/check` | POST | 手动立即测活节点 |

---

## 五、 不死自愈机制验证

本插件的数据与状态持久化在：
```bash
storage/app/external_nodes.json
```
- **测试不死特性**：你可以在服务器上直接执行 `rm -rf storage/app/external_nodes.json`；
- **自愈结果**：下次任何访问请求到达或执行命令行，系统将在 0.01 秒内自动无感重建标准骨架，**绝对不会产生 500 报错**。

---

## 六、 商业分发与彻底卸载

如果你想把本插件打包给客户或售卖：
1. 直接把 `app/Plugins/ExternalNode/` 打包成 zip 发给客户；
2. 客户按照第二节的步骤配置即可；
3. **彻底卸载**：只需删除 `app/Plugins/ExternalNode/` 文件夹与 `storage/app/external_nodes.json`，原有系统和数据库立刻 100% 恢复初始状态，零冗余孤儿数据。
