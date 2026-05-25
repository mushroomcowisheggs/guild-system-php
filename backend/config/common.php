<?php
/**
 * 数据库连接与公共函数
 * SQLite 文件锁 + 事务序列化并发写入
 */

// --- 配置 --------------------------------------------------------
define('DB_FILE', __DIR__ . '../../database/guild.db');
define('MAX_SLOTS', 50);
define('ADMIN_SECRET', getenv('ADMIN_SECRET') ?: 'changeme');  // 生产环境请通过环境变量设置

// --- 数据库连接 --------------------------------------------------
function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $isNew = !file_exists(DB_FILE);
    $dir = dirname(DB_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }

    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // WAL 模式提升并发读取性能
    $pdo->exec('PRAGMA journal_mode = WAL;');
    // 忙等待超时：当数据库被锁时，最多等待5秒
    $pdo->exec('PRAGMA busy_timeout = 5000;');
    // 外键约束
    $pdo->exec('PRAGMA foreign_keys = ON;');

    if ($isNew) {
        initDatabase($pdo);
    }

    return $pdo;
}

// --- 初始化表结构 ------------------------------------------------
function initDatabase(PDO $pdo): void {
    // hunters 表：存储猎人接单记录
    // 核心修复1：UNIQUE(quest_id, email) — 数据库层防止同一邮箱重复抢单
    // 核心修复2：UNIQUE(quest_id, name)  — 数据库层防止同一代号被占用
    $pdo->exec('CREATE TABLE IF NOT EXISTS hunters (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        quest_id TEXT NOT NULL,
        name TEXT NOT NULL,
        email TEXT NOT NULL,
        timestamp TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT "PENDING"
            CHECK(status IN ("PENDING", "SETTLED", "REJECTED")),
        UNIQUE(quest_id, email),
        UNIQUE(quest_id, name)
    );');

    // 创建索引加速查询
    $pdo->exec('CREATE INDEX idx_quest ON hunters(quest_id);');
    $pdo->exec('CREATE INDEX idx_status ON hunters(status);');
    $pdo->exec('CREATE INDEX idx_email ON hunters(email);');
}

// --- CORS 响应头 -------------------------------------------------
function corsHeaders(): array {
    return [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
        'Content-Type' => 'application/json; charset=utf-8',
    ];
}

function jsonResponse(array $data, int $status = 200): void {
    http_response_code($status);
    foreach (corsHeaders() as $k => $v) {
        header("$k: $v");
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function textResponse(string $text, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo $text;
    exit;
}

// --- 管理员密钥校验 ----------------------------------------------
function requireAdminAuth(): void {
    $provided = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($provided !== ADMIN_SECRET) {
        jsonResponse(['error' => 'Access Denied: 密钥不匹配或越权访问'], 403);
    }
}
