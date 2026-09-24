<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, '不支持的请求方式');
}

$action = $_POST['action'] ?? 'toggle';
$db = getDB();

try {
    switch ($action) {
        // 单条收藏状态切换（原有入口，保持兼容）
        case 'toggle':
        case 'check':
            $messageId = intval($_POST['message_id'] ?? 0);
            if ($messageId <= 0) {
                jsonResponse(1, '无效的留言ID');
            }

            $stmt = $db->prepare("SELECT id FROM messages WHERE id = ? AND status = 1");
            $stmt->execute([$messageId]);
            if (!$stmt->fetch()) {
                jsonResponse(1, '留言不存在或未通过审核');
            }

            if ($action === 'check') {
                jsonResponse(0, '查询成功', ['favorited' => isFavorited($messageId)]);
            }

            $result = toggleFavorite($messageId);
            $result['overview'] = getFavoriteOverview();
            $result['groups'] = getFavoriteGroups();
            $msg = $result['action'] === 'add' ? '收藏成功' : '已取消收藏';
            jsonResponse(0, $msg, $result);

        // 新建分组
        case 'group_create':
            $groupId = createFavoriteGroup($_POST['name'] ?? '');
            jsonResponse(0, '分组创建成功', [
                'group_id' => $groupId,
                'overview' => getFavoriteOverview(),
            ]);

        // 重命名分组
        case 'group_rename':
            $groupId = intval($_POST['group_id'] ?? 0);
            if ($groupId <= 0) {
                jsonResponse(1, '无效的分组ID');
            }
            renameFavoriteGroup($groupId, $_POST['name'] ?? '');
            jsonResponse(0, '分组已重命名', ['overview' => getFavoriteOverview()]);

        // 删除分组（组内收藏变为未分组）
        case 'group_delete':
            $groupId = intval($_POST['group_id'] ?? 0);
            if ($groupId <= 0) {
                jsonResponse(1, '无效的分组ID');
            }
            deleteFavoriteGroup($groupId);
            jsonResponse(0, '分组已删除，组内收藏已移至未分组', ['overview' => getFavoriteOverview()]);

        // 查询整组整理范围内的条目（逐条确认用）
        case 'items':
            $scope = $_POST['scope'] ?? 'all';
            $validScopes = ['all', 'ungrouped', 'trash', 'group'];
            if (!in_array($scope, $validScopes, true)) {
                jsonResponse(1, '无效的范围');
            }
            $groupId = intval($_POST['group_id'] ?? 0);
            if ($scope === 'group' && $groupId <= 0) {
                jsonResponse(1, '无效的分组ID');
            }
            $type = $_POST['type'] ?? '';
            try {
                $items = getFavoriteItemsForScope($scope, $groupId > 0 ? $groupId : null, $type);
            } catch (Exception $e) {
                jsonResponse(1, $e->getMessage());
            }
            jsonResponse(0, '查询成功', ['items' => $items]);

        // 批量整理：move_group / cancel / restore / purge，逐条独立处理
        case 'batch':
            $batchAction = $_POST['batch_action'] ?? '';
            $validBatchActions = ['move_group', 'cancel', 'restore', 'purge'];
            if (!in_array($batchAction, $validBatchActions, true)) {
                jsonResponse(1, '不支持的批量操作');
            }

            $ids = $_POST['message_ids'] ?? [];
            if (is_string($ids)) {
                $ids = $ids !== '' ? explode(',', $ids) : [];
            } elseif (!is_array($ids)) {
                $ids = [];
            }
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($v) {
                return $v > 0;
            })));
            if (empty($ids)) {
                jsonResponse(1, '请至少选择一条收藏');
            }
            if (count($ids) > 500) {
                jsonResponse(1, '单次最多处理500条收藏');
            }

            $targetGroupId = null;
            if ($batchAction === 'move_group') {
                if (($_POST['target'] ?? 'group') === 'ungrouped') {
                    $targetGroupId = null;
                } else {
                    $targetGroupId = intval($_POST['group_id'] ?? 0);
                    if ($targetGroupId <= 0) {
                        jsonResponse(1, '请选择目标分组');
                    }
                }
            }

            $data = batchUpdateFavorites($ids, $batchAction, $targetGroupId);
            $summary = "操作完成：成功 {$data['success']} 条";
            if ($data['failed'] > 0) {
                $summary .= "，失败 {$data['failed']} 条";
            }
            jsonResponse(0, $summary, $data);

        default:
            jsonResponse(1, '未知操作');
    }
} catch (Exception $e) {
    jsonResponse(1, $e->getMessage() ?: '操作失败，请稍后重试');
}
