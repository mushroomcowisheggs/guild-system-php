<?php
/**
 * 特权审计接口
 * GET:  提取无掩码完整账本
 * POST: 强行篡改底层状态（核销打款 / 驳回）
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

// 全局密钥校验
requireAdminAuth();

$questId = $_GET['id'] ?? 'G-001';

// =================================================================
// GET: 提取完整账本（含隐私数据：邮箱）
// =================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT name, email, timestamp, status FROM hunters WHERE quest_id = ? ORDER BY id ASC');
        $stmt->execute([$questId]);
        $hunters = $stmt->fetchAll();

        jsonResponse(['hunters' => $hunters]);
    } catch (Exception $e) {
        jsonResponse(['error' => 'Database read failed: ' . $e->getMessage()], 500);
    }
}

// =================================================================
// POST: 强行篡改状态
// =================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $targetEmail = $body['email'] ?? '';
    $newStatus = $body['status'] ?? '';

    if (!$targetEmail || !in_array($newStatus, ['SETTLED', 'REJECTED', 'PENDING'], true)) {
        jsonResponse(['error' => '参数错误：缺少邮箱或状态非法'], 400);
    }

    try {
        $db = getDB();

        // 使用事务保护状态更新
        $db->exec('BEGIN IMMEDIATE TRANSACTION');

        // 先查询确认记录存在
        $checkStmt = $db->prepare('SELECT id FROM hunters WHERE quest_id = ? AND email = ?');
        $checkStmt->execute([$questId, $targetEmail]);
        $record = $checkStmt->fetch();

        if (!$record) {
            $db->exec('ROLLBACK');
            jsonResponse(['error' => '未找到对应的猎人邮箱'], 404);
        }

        // 执行状态更新
        $updateStmt = $db->prepare('UPDATE hunters SET status = ? WHERE quest_id = ? AND email = ?');
        $updateStmt->execute([$newStatus, $questId, $targetEmail]);

        $db->exec('COMMIT');

        jsonResponse(['success' => true]);
    } catch (Exception $e) {
        try {
            $db->exec('ROLLBACK');
        } catch (Exception $rollbackEx) {
            // 忽略
        }
        jsonResponse(['error' => '崩溃: ' . $e->getMessage()], 500);
    }
}

jsonResponse(['error' => 'Method not allowed'], 405);
