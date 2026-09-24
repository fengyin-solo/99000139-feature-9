<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, '不支持的请求方式');
}

$db = getDB();
ensureFavoriteGroupsSchema($db);

$visitorId = getVisitorId();
$action = $_POST['action'] ?? '';
$validActions = ['create', 'rename', 'delete'];

if (!in_array($action, $validActions)) {
    jsonResponse(1, '不支持的操作');
}

try {
    if ($action === 'create') {
        $name = $_POST['name'] ?? '';
        $groupId = createFavoriteGroup($visitorId, $name);
        $group = getFavoriteGroup($groupId, $visitorId);
        jsonResponse(0, '分组创建成功', [
            'group' => [
                'id' => intval($group['id']),
                'name' => $group['name'],
                'item_count' => 0,
            ],
        ]);
    }

    if ($action === 'rename') {
        $groupId = intval($_POST['group_id'] ?? 0);
        $name = $_POST['name'] ?? '';
        renameFavoriteGroup($groupId, $visitorId, $name);
        $group = getFavoriteGroup($groupId, $visitorId);
        jsonResponse(0, '分组已重命名', [
            'group' => [
                'id' => intval($group['id']),
                'name' => $group['name'],
            ],
        ]);
    }

    // delete
    $groupId = intval($_POST['group_id'] ?? 0);
    deleteFavoriteGroup($groupId, $visitorId);
    jsonResponse(0, '分组已删除，组内收藏已移至未分组');
} catch (Exception $e) {
    jsonResponse(1, $e->getMessage());
}
