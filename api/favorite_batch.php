<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

$db = getDB();
ensureFavoriteGroupsSchema($db);

$visitorId = getVisitorId();
$method = $_SERVER['REQUEST_METHOD'];

// GET action=summary：返回当前访客收藏汇总（数量、分类统计、分组统计）
if ($method === 'GET' && ($_GET['action'] ?? '') === 'summary') {
    $type = $_GET['type'] ?? '';
    if ($type && !in_array($type, ['help', 'suggest', 'lost'])) {
        $type = '';
    }
    jsonResponse(0, '查询成功', getFavoriteSummary($visitorId, $type));
}

if ($method !== 'POST') {
    jsonResponse(405, '不支持的请求方式');
}

$action = $_POST['action'] ?? '';
if (!in_array($action, ['move', 'remove', 'restore'])) {
    jsonResponse(1, '不支持的批量操作');
}

// message_ids 支持数组或以逗号分隔的字符串
$messageIds = $_POST['message_ids'] ?? [];
if (is_string($messageIds)) {
    $messageIds = array_filter(explode(',', $messageIds), 'strlen');
} elseif (is_array($messageIds)) {
    $messageIds = array_filter($messageIds, 'is_scalar');
} else {
    $messageIds = [];
}

$groupId = isset($_POST['group_id']) && $_POST['group_id'] !== '' ? intval($_POST['group_id']) : null;

try {
    $result = batchUpdateFavorites($visitorId, $action, $messageIds, $groupId);
    // 批量操作后返回最新汇总，保证列表数量/分类统计/分组视图与刷新后保持一致
    $result['summary'] = getFavoriteSummary($visitorId);

    if ($result['failed'] > 0 && $result['success'] === 0) {
        $code = 1;
    } elseif ($result['failed'] > 0) {
        $code = 2; // 部分成功
    } else {
        $code = 0;
    }

    $actionText = ['move' => '移动', 'remove' => '取消收藏', 'restore' => '恢复'][$action];
    $msg = "{$actionText}完成：成功 {$result['success']} 条";
    if ($result['failed'] > 0) {
        $msg .= "，失败 {$result['failed']} 条";
    }
    jsonResponse($code, $msg, $result);
} catch (Exception $e) {
    jsonResponse(1, $e->getMessage());
}
