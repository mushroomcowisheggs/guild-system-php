<?php
/**
 * 处理前端的抢单提交 (POST) 和账本拉取 (GET)
 *   1. BEGIN IMMEDIATE    → 立即获取 RESERVED(写)锁，阻塞其它写入请求
 *   2. SELECT COUNT(*)    → 在事务内读取当前人数（此时数据是隔离的）
 *   3. INSERT OR ROLLBACK → 若唯一约束冲突（重复邮箱/代号），事务回滚
 *   4. COMMIT             → 事务提交，释放锁，下一个请求才能进入
 * 
 * 由于 SQLite 同一时间只允许一个写事务，并发请求会被强制串行执行。
 * 唯一约束作为最后一道防线。
 */

require __DIR__ . '../config/common.php';

// 处理 CORS 预检
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    foreach (corsHeaders() as $k => $v) {
        header("$k: $v");
    }
    exit;
}

// =================================================================
// GET: 拉取公共账本（带隐私过滤）
// =================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $questId = $_GET['id'] ?? '';
    if (!$questId) {
        jsonResponse(['error' => 'Missing quest ID'], 400);
    }

    try {
        $db = getDB();
        // 普通 SELECT 不需要事务，WAL模式下读操作不阻塞
        $stmt = $db->prepare('SELECT name, timestamp, status FROM hunters WHERE quest_id = ? ORDER BY id ASC');
        $stmt->execute([$questId]);
        $hunters = $stmt->fetchAll();

        jsonResponse(['hunters' => $hunters]);
    } catch (Exception $e) {
        jsonResponse(['error' => 'Database read failed: ' . $e->getMessage()], 500);
    }
}

// =================================================================
// POST: 抢单提交（竞态条件修复核心）
// =================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $questId = $_POST['quest_id'] ?? '';
    $name = trim($_POST['hunter_name'] ?? '');
    $email = trim($_POST['hunter_email'] ?? '');
    
    
    if (!$questId || !$name || !$email) {
        textResponse('错误：参数不完整。', 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        textResponse('错误：邮箱地址格式无效。', 400);
    }
    if (strlen($email) > 254) {
        textResponse('错误：邮箱地址过长。', 400);
    }
    
    $db = getDB();

    try {
        // =========================================================
        // BEGIN IMMEDIATE TRANSACTION
        //
        //  立即获取 RESERVED 写锁，不经过 SHARED 读锁阶段。
        //   保证：同一时间只有一个事务能进入临界区，天然串行化。
        //   其它请求会等待（busy_timeout=5000ms）或排队。
        // =========================================================
        $db->exec('BEGIN IMMEDIATE TRANSACTION');

        // 步骤1：在事务内查询当前人数（此时看到的快照是隔离的）
        $countStmt = $db->prepare('SELECT COUNT(*) as cnt FROM hunters WHERE quest_id = ?');
        $countStmt->execute([$questId]);
        $currentCount = (int) $countStmt->fetch()['cnt'];

        // 步骤2：上限校验 —— 基于事务内读取的准确计数
        if ($currentCount >= MAX_SLOTS) {
            $db->exec('ROLLBACK');
            textResponse('名额已满', 403);
        }

        // 步骤3：尝试插入（唯一约束作为兜底防线）
        // UNIQUE(quest_id, email) 会在同一邮箱重复时触发冲突
        // UNIQUE(quest_id, name)  会在同一代号重复时触发冲突
        try {
            $insertStmt = $db->prepare('INSERT INTO hunters (quest_id, name, email, timestamp, status) VALUES (?, ?, ?, ?, "PENDING")');
            $insertStmt->execute([
                $questId,
                substr($name, 0, 15),  // 防恶意超长文本
                $email,
                date('c')  // ISO 8601 格式
            ]);
        } catch (PDOException $e) {
            $db->exec('ROLLBACK');

            // 判断是哪种唯一约束冲突
            $msg = $e->getMessage();
            if (str_contains($msg, 'hunters.quest_id, email')) {
                textResponse('该邮箱已经接取过此任务', 403);
            } elseif (str_contains($msg, 'hunters.quest_id, name')) {
                textResponse('该代号已被占用', 403);
            }
            // 其它边缘错误
            textResponse('数据冲突，请重试', 409);
        }

        // 步骤4：提交事务 —— 数据持久化，释放写锁
        $db->exec('COMMIT');

        textResponse('OK', 200);

    } catch (Exception $e) {
        // 安全回滚
        try {
            $db->exec('ROLLBACK');
        } catch (Exception $rollbackEx) {
            // 忽略回滚时的错误
        }
        textResponse('嘻嘻，崩溃了: ' . $e->getMessage(), 500);
    }
}

// 不支持的方法
jsonResponse(['error' => 'Method not allowed'], 405);
