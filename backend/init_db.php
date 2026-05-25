<?php
/**
 * 数据库初始化脚本
 * 首次部署时运行此脚本创建表结构
 * 用法: php init_db.php
 * 或通过浏览器访问（部署后删除或限制访问）
 */

require __DIR__ . './config/common.php';

try {
    $db = getDB();
    // 手动调用初始化确保表存在
    initDatabase($db);

    echo "数据库初始化成功。\n";
    echo "数据库文件: " . DB_FILE . "\n";

    // 验证表结构
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='hunters'")->fetchAll();
    if (count($tables) > 0) {
        echo "hunters 表已创建。\n";

        // 显示表结构
        $cols = $db->query("PRAGMA table_info(hunters)")->fetchAll();
        echo "表结构:\n";
        foreach ($cols as $col) {
            echo "  - {$col['name']}: {$col['type']}" . ($col['notnull'] ? ' NOT NULL' : '') . ($col['pk'] ? ' PRIMARY KEY' : '') . "\n";
        }

        // 显示索引
        $idxs = $db->query("SELECT name, sql FROM sqlite_master WHERE type='index' AND tbl_name='hunters'")->fetchAll();
        echo "索引:\n";
        foreach ($idxs as $idx) {
            echo "  - {$idx['name']}\n";
        }
    }
} catch (Exception $e) {
    die("初始化失败: " . $e->getMessage() . "\n");
}
