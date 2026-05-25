# 慈善家工会悬赏板 (PHP + SQLite 版本技术性尝试)

## 项目概述

限额悬赏任务抢单系统。一个任务(G-001)有 50 个名额，用户通过 10 秒强制弹窗后抢单，管理员可审核核销。

## 架构变更

| 组件 | 原版 | PHP 版 |
|------|------|--------|
| 运行时 | Cloudflare Pages Functions | PHP 7.4+ |
| 数据库 | Cloudflare KV | SQLite (文件型) |
| API 路由 | `/api/submit`, `/api/admin` | `/api/submit.php`, `/api/admin.php` |

## 功能变更说明

### 1. 原 Bug（竞态条件）修复

原 KV 版采用"读取-修改-写入"模式：

```
请求A: 读取(49人) → 校验通过 → push数据 → 写入(50人)
请求B: 读取(49人) → 校验通过 → push数据 → 写入(51人) [覆盖A的数据]
```

修复方案：

利用 SQLite 的 ACID 事务特性：

1. **`BEGIN IMMEDIATE TRANSACTION`** — 立即获取 RESERVED(写)锁，强制并发请求串行执行
2. **`UNIQUE(quest_id, email)`** — 数据库层唯一约束，防止同一邮箱重复抢单
3. **`UNIQUE(quest_id, name)`** — 数据库层唯一约束，防止同一代号被占用
4. **事务内计数** — `SELECT COUNT(*)` 在锁保护下读取，保证准确
5. **`busy_timeout = 5000`** — 锁等待机制，避免立即返回错误

```
请求A: BEGIN IMMEDIATE(获取写锁) → COUNT=49 → INSERT → COMMIT(释放锁)
请求B: BEGIN IMMEDIATE(等待A释放) → COUNT=50 → 已满 → ROLLBACK
```

### 引入的问题

原 KV 版部署无需服务器，Cloudflare 托管，自动全球分布。而 PHP 服务器限于单机服务器部署。

未来的可能方案：

#### 一、经典架构：Primary-Secondary（一写多读）

这是 SQLite 全球扩展的基础模式。核心概念很简单：**单个主库处理写入，多个从库在全球各地处理读取**。

```text
                 ┌─────────────┐
                 │   用户 A    │
                 │  (亚洲用户)  │
                 └──────┬──────┘
                        │
                        ▼
                 ┌─────────────┐
                 │  Read 从库   │  ← 亚洲边缘节点
                 │  (只读副本)   │
                 └─────────────┘
                        ▲
                        │ 异步复制 WAL
                        │
                 ┌─────────────┐
                 │   Write      │
                 │   主库       │  ← 单一主节点
                 │  (唯一的写入点)│
                 └─────────────┘
                        ▲
                        │ 异步复制 WAL
                        │
                 ┌─────────────┐
                 │  Read 从库   │  ← 欧洲边缘节点
                 │  (只读副本)   │
                 └──────┬──────┘
                        │
                        ▼
                 ┌─────────────┐
                 │   用户 B    │
                 │  (欧洲用户)  │
                 └─────────────┘
```

SQLite 本身原生支持多读单写。要实现全球分布，就是在全球各地部署只读副本，通过复制日志（WAL）从主库同步数据到各副本。悬赏板场景的特点是写少读多（一次抢单写入，多次账本读取），极其契合这个模式。现代 SSD 上的 SQLite 单机可支撑每秒 **1 万到 5 万次写入**，对悬赏板的 50 名额抢单绰绰有余。

但是，经典方案的**致命问题**在于：**要在全球各地自己部署和管理这些只读副本**——选云厂商、配置机器、搭建复制管道、监控副本数据一致性、处理副本故障切换，不是简单工作，已经背离了 SQLite "零运维" 的初衷。

---

#### 二、嵌入式副本（Embedded Replicas）方案

**2026 年这个领域已经发生了质的变化。** Turso 等平台开发出了一种更优雅的做法：**在应用程序本地存放一个 SQLite 文件，它自动与云端主库保持同步。** 查询直接在本地执行（零网络延迟），写操作被自动转发回主库处理。

具体的实现方式是：应用程序运行时，在本地文件系统（服务器实例的本地存储或边缘 Worker 的临时存储）存放一个 SQLite 文件副本，后台有一个同步进程持续追踪云端主库的 WAL（Write-Ahead Log）变更，并将这些变更应用到本地副本上，使其与主库保持一致。当应用程序执行查询时，直接读取本地这个 SQLite 文件，完全没有网络开销；当应用程序执行写入（如抢单 INSERT）时，Turso 的客户端 SDK 会将该操作通过 HTTP 发送给云端主库执行，主库完成后再将变更同步回所有副本。

性能数据很有说服力：边缘 Postgres 跨区域查询延迟平均 **30-80 毫秒**；Cloudflare D1 的边缘副本可降到 **10 毫秒以内**；而 Turso 的嵌入式副本实测平均延迟仅 **624.8 微秒**。迁移案例显示，从 AWS RDS PostgreSQL 迁移到 Turso 后，P50 延迟从 12ms 降到 0.8ms，P99 从 45ms 降到 3ms，月成本从 $340 降到 $95。

具体到悬赏板，API 路径设计为：`/api/submit`（抢单写入）自动路由到主库；`/api/ledger`（查看账本）从就近副本读取。前端代码几乎不需要改动。

```text
Turso / libSQL

libSQL 是 SQLite 的一个开源分支，在其基础之上增加了服务器模式、副本嵌入等原生不支持的特性，同时保持了 100% API 兼容性。Turso 则是基于 libSQL 构建的托管平台，提供多区域复制、自动故障转移等功能。

PHP 可通过以下方式接入：
- 官方推荐的 `libSQL` 客户端库（通过 HTTP/WebSocket 连接）
- 标准的 PDO_SQLITE（如果部署在 Turso 托管的边缘节点上）
- 社区驱动的 `php-libsql` 扩展（正在发展中）

**成本估算**：类似读写比例和规模（50 名额抢单 + 管理员操作），月费约 **$95 起**。
```

---

#### 三、SQLite Cloud / TiDB（完整集群方案）

SQLite Cloud 在 SQLite 之上层叠了一个完整的 Raft 共识算法，组建一个多节点集群。Leader 节点负责所有写操作，Follower 节点承担读取请求，Learner 节点则专门用于扩展读容量且不参与选举，读写扩展能力最为强大。

但这需要**额外的独立集群运维成本**且国内访问质量可能不稳定，对当前项目属于明显的"高射炮打蚊子"。

## 项目目录结构

```
├── backend/                # 所有后端代码（不对外直接暴露）
│   ├── api/                # API 入口脚本（对外暴露，需配置重写）
│   │   ├── submit.php      # 抢单提交 & 账本查询
│   │   └── admin.php       # 管理接口
│   ├── classes/            # 可放置数据库操作类、业务逻辑类（当前未使用，预留）
│   ├── config/             # 配置文件
│   │   └── common.php      # 数据库连接、公共函数、常量等
│   └── init_db.php         # 数据库初始化脚本
├── database/               # 数据库文件（必须禁止 Web 访问）
│   └── guild.db
└── frontend/               # Web 根目录
    ├── index.html          # 用户页面
    ├── admin.html          # 管理员控制台
    ├── .htaccess           # Apache 服务器的配置文件，使用 Nginx 需要通过主配置文件实现类似功能
    └── assets/             # 前端资源
        ├── css/
        ├── js/
        ├── fonts/
        ├── icons/
        └── images/
```

###  修改前端 HTML 中的 API 请求路径
**方案：通过 Web 服务器重写规则**  
配置将 `/api/*.php` 请求内部重写到 `backend/api/*.php`，同时防止直接访问 `backend/` 目录。

- **Apache (`.htaccess`)**：在 `frontend/` 目录下放置 `.htaccess`，内容如下：
  ```apache
  RewriteEngine On
  # 如果请求的是 /api/ 开头的路径，则重写到 ../backend/api/ 对应文件
  RewriteRule ^api/(.*\.php)$ ../backend/api/$1 [L,NC]
  # 禁止访问 backend 目录（返回 403）
  RewriteRule ^backend/ - [F,L]
  # 禁止访问 database 目录
  RewriteRule ^database/ - [F,L]
  ```

- **Nginx**：在 server 块中添加：
  ```nginx
  location /api/ {
      alias /path/to/your-project/backend/api/;
      try_files $uri =404;
  }
  location ~ ^/backend/ { deny all; }
  location ~ ^/database/ { deny all; }
  ```

#### 调整 Web 服务器的 DocumentRoot

- 将 Web 服务器的根目录指向 `frontend/` 文件夹（而不是项目根目录）。
- 例如 Apache VirtualHost 配置：
  ```apache
  DocumentRoot "/path/to/your-project/frontend"
  <Directory "/path/to/your-project/frontend">
      Options Indexes FollowSymLinks
      AllowOverride All
      Require all granted
  </Directory>
  ```

#### 数据库初始化脚本的调用方式
 
需要：`cd backend && php init_db.php` 或从项目根执行 `php backend/init_db.php`。

#### 保护敏感目录

确保 `database/` 和 `backend/` 目录不被 Web 直接访问：

- 在 Apache 中，可通过在前端目录的 `.htaccess` 中添加 `RewriteRule ^(backend|database)/ - [F,L]` 实现。
- 或者将这些目录放置在 Web 根目录之外（需要保证安全性的情况），然后调整 PHP 的 `include_path` 和数据库路径，使其使用绝对路径访问外部目录。这需要进一步修改：
  - 将 `backend/` 和 `database/` 移到 `frontend/` 的同级但上级目录不可访问的位置（例如 `/var/www/private/`）。
  - 修改 `common.php` 中的数据库路径为绝对路径（如 `/var/www/private/database/guild.db`）。
  - 修改 PHP 文件包含路径（仍需重写 API 访问入口）。

  对于大多数共享主机，通过 `.htaccess` 禁止访问足够安全。


## 部署步骤

### 1. 环境要求
- PHP 7.4+ (推荐 8.0+)
- SQLite3 扩展已启用
- Apache/Nginx

### 2. 配置
```bash
# 设置管理员密钥 (推荐通过环境变量)
export ADMIN_SECRET="your-secure-secret-key"

# 或在 common.php 中修改默认密钥
```

### 3. 初始化数据库
```bash
cd guild-system/
php init_db.php
```

### 4. 权限设置
```bash
# 确保 data/ 目录可写
chmod 755 data/

# Apache 用户需要写权限 (根据实际用户调整)
chown www-data:www-data data/
```

### 5. Web 服务器配置

#### Apache (.htaccess)
注意事项
- **启用 `mod_rewrite`**：确保 Apache 已加载 `mod_rewrite` 模块。
- **允许 `.htaccess` 覆盖**：在 Apache 主配置或 VirtualHost 中设置：
  ```apache
  <Directory "/path/to/your-project/frontend">
      AllowOverride All
  </Directory>
  ```

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^api/(.*)$ api/$1.php [L]
```

#### Nginx
```nginx
location /api/ {
    try_files $uri $uri.php =404;
}

location ~ \.php$ {
    fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
    fastcgi_index index.php;
    include fastcgi_params;
}

# 保护数据库文件
location ~ /data/ {
    deny all;
}
```

## 从原版迁移

1. 从 Cloudflare KV 导出数据为 JSON
2. 编写简单的 PHP 脚本将 JSON 数据导入 SQLite
3. 更新前端 API 路径（已在本版本中完成）
4. 部署并测试

## 安全注意事项

1. 生产环境务必通过环境变量设置 `ADMIN_SECRET`
2. `data/` 目录必须禁止 Web 访问（已通过 `.htaccess` 配置）
3. 定期备份 `data/guild.db` 文件
4. 考虑为 API 增加速率限制（Rate Limiting）
