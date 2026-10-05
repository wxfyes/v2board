# V2Board 访问日志审计与反诈溯源系统实施计划书 (ClickHouse 旁路架构)

> **设计宗旨**：
> 1. **严守社会责任**：精准打击电诈、网赌、黑客扫描等违法分子，保护正常用户隐私的同时，对恶意违规行为进行定点溯源与封禁。
> 2. **100% 零侵入与软依赖 (Zero-Intrusion & Soft Dependency)**：完全不改动 MySQL 任何表结构；即使未部署 ClickHouse，系统各业务模块 100% 稳健运行，绝无任何报错阻断。
> 3. **极低资源开销 (4G 内存深度调优)**：通过 ClickHouse 严格限额（锁定内存 ≤ 400MB）与 3 天 TTL 自动物理回收，磁盘占用恒定在 1G~2G 以内。

---

## 一、系统架构全景图

```text
[普通用户上网] ──> [节点服务端 (Sing-box / Xray)]
                           │
                 (1) 旁路抓取连接元数据
                     {时间, UID, 来源IP, 目标Host, 出口IP, 端口, 协议}
                           │
                 (2) 轻量探针 (Vector / 异步批量上报)
                     每 5 秒 / 满 1000 条批量打入
                           ▼
                 [ClickHouse 独立列式数据库] (端口: 8123 / 9000)
                     ├── 内存硬限额: ≤ 400 MB
                     └── 自动碎纸机: TTL 3 天自动物理删除
                           ▲
                 (3) 管理员按需只读查询 (耗时 10~20ms)
                           │
           [v2board 后端 (Laravel / Webman)]
                     ├── CLICKHOUSE_ENABLE 开关 (未开启时软降级)
                     └── 只读参数化查询控制器
                           ▲
                 (4) 管理后台检索面板
           [React 19 / Vue 3 管理后台 (Data Explorer)]
```

---

## 二、第一阶段：ClickHouse 极简部署与低内存优化 (服务端)

在宝塔终端或宿主机执行快速部署。为了保护 4G 内存服务器，**必须写入内存硬限制**：

### 1. 快速安装 ClickHouse
```bash
# 推荐使用官方免编译静态脚本或 Docker 部署 (Ubuntu / Debian / CentOS 通用)
curl https://clickhouse.com/ | sh
sudo ./clickhouse install
```

### 2. 4G 服务器专属内存优化配置
编辑 `/etc/clickhouse-server/config.d/low-mem.xml`：
```xml
<clickhouse>
    <!-- 限制最大内存使用量为 400MB，防止与 MySQL/PHP 争抢内存 -->
    <max_server_memory_usage>419430400</max_server_memory_usage>
    <!-- 设置并发查询数限制，适合单管理员后台审计 -->
    <max_concurrent_queries>5</max_concurrent_queries>
    <!-- 限制后台合并线程数，降低 CPU 波动 -->
    <background_pool_size>2</background_pool_size>
</clickhouse>
```

启动并设置开机自启：
```bash
sudo systemctl enable clickhouse-server
sudo systemctl start clickhouse-server
```

---

## 三、第二阶段：ClickHouse 表结构初始化 (DDL 与 3 天 TTL)

连接 ClickHouse：`clickhouse-client` 或执行 HTTP SQL：

```sql
CREATE DATABASE IF NOT EXISTS v2board_audit;

CREATE TABLE IF NOT EXISTS v2board_audit.access_logs (
    created_at  DateTime64(3, 'Asia/Shanghai') COMMENT '访问精确时间戳',
    user_id     UInt32                         COMMENT '用户 UID',
    user_ip     String                         COMMENT '客户端来源 IP',
    network     LowCardinality(String)         COMMENT '协议 (tcp / udp)',
    node_id     UInt32                         COMMENT '接入服务节点 ID',
    host        String                         COMMENT '目标 Host 域名 (支持 LIKE 检索)',
    dest_ip     String                         COMMENT '目标出口 IP',
    dest_port   UInt16                         COMMENT '目标端口'
)
ENGINE = MergeTree()
PARTITION BY toYYYYMMDD(created_at)
ORDER BY (toStartOfHour(created_at), user_id, host)
-- 【核心规则】只保留最近 3 天日志，到期底层全自动擦除回收磁盘，免人工维护：
TTL created_at + INTERVAL 3 DAY DELETE
SETTINGS index_granularity = 8192;
```

---

## 四、第三阶段：节点端日志采集与上报管道 (边缘节点)

### 方案 A：使用通用高性能轻量探针 Vector (最稳健)
在节点安装 [Vector](https://vector.dev/)（单二进制文件，内存仅需 20MB）：
1. 监听 Xray/Sing-box 的 Access Log 文件；
2. 正则解析日志行（提取 UID、来源IP、目标域名、端口）；
3. 配置 Sink 直接批量推送到主控 ClickHouse HTTP 端口 `http://主控IP:8123/?query=INSERT+INTO+v2board_audit.access_logs+FORMAT+JSONEachRow`。

### 方案 B：节点端插件内嵌异步推流
在节点端转发程序中增加一个非阻塞的 Memory Buffer 环形队列：
- 当堆积满 500 条或每隔 5 秒，启动一个非阻塞协程以 HTTP 方式批量 POST 给主控 ClickHouse。
- 若网络超时直接丢弃日志，**绝对不阻塞用户的正常转发流量**。

---

## 五、第四阶段：v2board 后端软依赖接口建设 (Laravel / Webman)

### 1. 环境变量配置 (`.env`)
```ini
# ClickHouse 访问日志审计 (默认关闭，完全不影响系统运行)
CLICKHOUSE_AUDIT_ENABLE=false
CLICKHOUSE_HOST=127.0.0.1
CLICKHOUSE_PORT=8123
CLICKHOUSE_USER=default
CLICKHOUSE_PASSWORD=
CLICKHOUSE_DATABASE=v2board_audit
```

### 2. 软依赖控制器逻辑设计
新建控制器：`app/Http/Controllers/V1/Admin/AuditLogController.php`
- **安全检查**：必须管理员（`is_admin = 1`）鉴权；
- **开关防呆**：
  ```php
  if (!config('v2board.clickhouse_audit_enable', false)) {
      return response([
          'data' => [],
          'total' => 0,
          'enabled' => false,
          'message' => '当前系统未启用 ClickHouse 访问日志审计模块（如需启用请在 .env 中配置并启动服务）'
      ]);
  }
  ```
- **参数化安全查询**：
  支持 UID、User IP、Host 包含匹配、Node ID、起止时间等。
  利用 ClickHouse HTTP API 发送参数化 SQL，返回 JSON 数据。

---

## 六、第五阶段：双管理后台前端集成 (React 19 & Vue 3)

遵循我们的核心准则：在两套管理端中同步实现页面与菜单挂载：

1. **React 19 版** (`v2board-admin-main`)：
   - 新增页面：`src/pages/audit-logs/AccessLogPage.tsx`
   - 挂载路由：`/audit/access-logs`
   - 挂载菜单：【安全审计】分组 -> 【访问日志检索 (Data Explorer)】
   - 包含多条件筛选栏、时间快捷范围选择、毫秒级表格展示与 CSV 导出功能。
   - 若后端返回 `enabled: false`，优雅展示提示卡片：“ClickHouse 审计模块已休眠，未连接服务”，没有任何红屏或报错。

2. **Vue 3 版** (`admin-panel`)：
   - 新增页面：`src/views/audit/AccessLog.vue`
   - 同步注册侧边栏路由并打包。

---

## 七、第六阶段：反诈关键词告警与定向惩罚扩展 (进阶功能)

在日志系统就绪后，可进一步扩展“反诈与恶意行为自动预警”计划任务：
1. **反诈黑名单词库**：配置常见涉诈、杀猪盘、黑客攻击关键字（如特定仿冒域名、违规端口、高危网银扫描等）；
2. **定时预警 Artisan 命令**：
   - 每 5 分钟在 ClickHouse 中轻量查询：
     `SELECT user_id, host, count(*) FROM access_logs WHERE host LIKE '%涉诈关键字%' GROUP BY user_id, host`
   - 若命中规则，可通过 Telegram Bot 向管理员私发告警：
     `🚨 反诈预警：用户 [UID 1024 / 邮箱 a@b.com] 疑似访问违规涉诈站点！`
   - 管理员可一键封禁该账号，真正为净网与反诈做出贡献！

---

## 八、维护与灾备备忘录

1. **备份与迁移**：迁移新服务器时，如不迁移日志，只需在新机部署原版 v2board，`.env` 保持默认 `CLICKHOUSE_AUDIT_ENABLE=false` 即可，**0 迁移阻力**。
2. **彻底销毁**：如需完全关闭，直接在终端执行 `systemctl stop clickhouse-server`，并在 `.env` 设置 `false`，即刻彻底卸载，不留任何痕迹。
