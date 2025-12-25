# V2Board 项目定制化修改记录

> 最后更新：2025-12-25

## 📌 项目概述

- **项目名称**: 天阙 V2Board
- **仓库地址**: https://github.com/wxfyes/v2board
- **服务器目录**: `/www/wwwroot/tianquege.top`
- **上游仓库**: https://github.com/cedar2025/Xboard

---

## ✅ 已完成的定制功能

### 1. 客户端追踪系统 (2025-12-24)

**功能描述**: 记录用户客户端登录信息，支持历史记录查看和筛选

**修改文件**:
- `app/Http/Controllers/V1/Client/ClientController.php` - 记录客户端类型和登录时间
- `database/install.sql` - 添加 `client_login_at` 和 `client_type` 字段
- `database/update.sql` - 添加字段的 ALTER 语句
- `app/Http/Requests/Admin/UserFetch.php` - 添加筛选验证规则
- `public/assets/admin/umi.js` - 前端显示和 Tooltip

**数据库变更**:
```sql
ALTER TABLE `v2_user` ADD `client_login_at` int(11) NULL COMMENT '客户端登录时间';
ALTER TABLE `v2_user` ADD `client_type` text NULL COMMENT '客户端类型(JSON历史记录)';
```

---

### 2. 邮件营销系统 (2025-12-25)

**功能描述**: 自动发送营销邮件，包括注册催单、到期提醒、用户召回

**新增文件**:
- `app/Console/Commands/BobUtilDay.php` - 每日定时任务
- `app/Console/Commands/BobUtilMinute.php` - 每分钟定时任务
- `app/Jobs/SendBobEmailJob.php` - 邮件发送 Job
- `config/bobutil.php` - 邮件营销配置

**配置说明**:
- 营销邮箱: `tianquee@gmail.com`
- 密码: `nluuzrwyivvxgtlx`

**定时任务** (宝塔面板):
```bash
# 每天 09:00 执行
php /www/wwwroot/tianquege.top/artisan bob:util_day

# 每分钟执行
php /www/wwwroot/tianquege.top/artisan bob:util_minute
```

**召回时间点**: 7天、15天、30天、60天、90天

---

### 3. 仪表盘统计增强 (2025-12-25)

**功能描述**: 在管理后台仪表盘添加新的统计指标

**修改文件**:
- `app/Http/Controllers/V1/Admin/StatController.php` - 添加 API 数据
- `public/assets/admin/umi.js` - 前端显示

**新增指标**:
- 今日总流量 (GB)
- 有效订阅用户数

---

### 4. MOMclash 自定义协议 (历史)

**功能描述**: 为 MOM 客户端定制的订阅协议

**相关文件**:
- `app/Protocols/MOMclash.php`
- `resources/rules/momclash.yaml`

---

## 🔧 常用命令

### 服务器部署
```bash
# SSH 到服务器后执行
cd /www/wwwroot/tianquege.top
bash update.sh
php artisan config:cache
```

### 清除 Cloudflare 缓存
登录 Cloudflare → 缓存 → 清除所有内容

### 前端修改注入脚本
```bash
# 客户端类型 Tooltip
node inject_client_tooltip.js

# 仪表盘统计
node inject_dashboard_stats.js
```

---

## 📁 项目结构说明

```
v2board/
├── app/
│   ├── Console/Commands/     # 定时任务命令
│   │   ├── BobUtilDay.php    # 邮件营销-每日
│   │   └── BobUtilMinute.php # 邮件营销-每分钟
│   ├── Http/Controllers/
│   │   └── V1/
│   │       ├── Admin/        # 管理后台 API
│   │       └── Client/       # 客户端 API
│   ├── Jobs/                 # 队列任务
│   ├── Models/               # 数据模型
│   └── Protocols/            # 订阅协议生成
│       └── MOMclash.php      # 自定义协议
├── config/
│   └── bobutil.php           # 邮件营销配置
├── database/
│   ├── install.sql           # 全新安装 SQL
│   └── update.sql            # 升级 SQL
├── public/assets/admin/
│   └── umi.js                # 管理后台前端
├── resources/rules/
│   └── momclash.yaml         # MOM 规则配置
└── inject_*.js               # 前端注入脚本
```

---

## ⚠️ 注意事项

1. **前端修改**: `umi.js` 是编译后的文件，修改需要用 Node.js 脚本注入
2. **上游同步**: 使用 `/sync-upstream` 工作流，注意保护自定义文件
3. **缓存问题**: 修改后记得清除 Cloudflare 和浏览器缓存
4. **数据库**: 新字段需要同时修改 `install.sql` 和 `update.sql`

---

## 🔄 上游同步保护文件

同步时需要特别保护的自定义文件:
- `app/Protocols/MOMclash.php`
- `resources/rules/momclash.yaml`
- `config/bobutil.php`
- `app/Console/Commands/BobUtil*.php`
- `app/Jobs/SendBobEmailJob.php`

---

## 📞 联系信息

- 营销邮箱: tianquee@gmail.com
- 系统邮箱: gotianque@gmail.com
