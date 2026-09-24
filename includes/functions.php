<?php
session_start();

/**
 * 返回JSON响应
 */
function jsonResponse($code, $msg, $data = null) {
    header('Content-Type: application/json; charset=utf-8');
    $res = ['code' => $code, 'msg' => $msg];
    if ($data !== null) $res['data'] = $data;
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 获取留言类型文字
 */
function getTypeLabel($type) {
    $map = ['help' => '居民求助', 'suggest' => '意见建议', 'lost' => '失物招领'];
    return $map[$type] ?? '其他';
}

/**
 * 获取类型图标
 */
function getTypeIcon($type) {
    $map = ['help' => '🆘', 'suggest' => '💡', 'lost' => '🔍'];
    return $map[$type] ?? '📌';
}

/**
 * 获取状态文字
 */
function getStatusLabel($status) {
    $map = [0 => '待审核', 1 => '已通过', 2 => '已拒绝'];
    return $map[$status] ?? '未知';
}

/**
 * 获取状态样式类
 */
function getStatusClass($status) {
    $map = [0 => 'pending', 1 => 'approved', 2 => 'rejected'];
    return $map[$status] ?? '';
}

/**
 * 时间格式化
 */
function timeAgo($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    if ($diff->y > 0) return $diff->y . '年前';
    if ($diff->m > 0) return $diff->m . '个月前';
    if ($diff->d > 0) return $diff->d . '天前';
    if ($diff->h > 0) return $diff->h . '小时前';
    if ($diff->i > 0) return $diff->i . '分钟前';
    return '刚刚';
}

/**
 * 检查管理员登录
 */
function requireAdmin() {
    if (empty($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }
}

/**
 * 过滤输入
 */
function cleanInput($str) {
    return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
}

/**
 * 获取访客唯一标识
 * 基于session和cookie实现匿名用户标识
 */
function getVisitorId() {
    if (empty($_SESSION['visitor_id'])) {
        if (!empty($_COOKIE['visitor_id'])) {
            $_SESSION['visitor_id'] = $_COOKIE['visitor_id'];
        } else {
            $visitorId = md5(uniqid('visitor_', true) . $_SERVER['REMOTE_ADDR'] . $_SERVER['HTTP_USER_AGENT']);
            $_SESSION['visitor_id'] = $visitorId;
            setcookie('visitor_id', $visitorId, time() + 86400 * 365, '/');
        }
    }
    return $_SESSION['visitor_id'];
}

/**
 * 检查留言是否已收藏（仅统计有效收藏，回收站中的不算）
 */
function isFavorited($messageId) {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM favorites WHERE visitor_id = ? AND message_id = ? AND status = 1");
    $stmt->execute([$visitorId, $messageId]);
    return $stmt->fetch() !== false;
}

/**
 * 获取当前访客收藏的所有留言ID（仅有效收藏）
 */
function getFavoritedMessageIds() {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT message_id FROM favorites WHERE visitor_id = ? AND status = 1");
    $stmt->execute([$visitorId]);
    return array_column($stmt->fetchAll(), 'message_id');
}

/**
 * 切换收藏状态
 * 已收藏(含回收站记录) -> 取消(status=0，软删除，可在回收站恢复)
 * 未收藏或曾取消 -> 收藏(新收藏为未分组；恢复旧记录时保留原分组)
 * 返回: ['favorited' => bool, 'action' => 'add'|'remove', 'group_id' => int|null]
 */
function toggleFavorite($messageId) {
    $visitorId = getVisitorId();
    $db = getDB();

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT id, status, group_id FROM favorites WHERE visitor_id = ? AND message_id = ? FOR UPDATE");
        $stmt->execute([$visitorId, $messageId]);
        $row = $stmt->fetch();

        if ($row && $row['status'] == 1) {
            $db->prepare("UPDATE favorites SET status = 0 WHERE id = ?")
                ->execute([$row['id']]);
            $result = ['favorited' => false, 'action' => 'remove', 'group_id' => null];
        } elseif ($row) {
            // 回收站中的记录重新收藏，恢复并保留原分组
            $db->prepare("UPDATE favorites SET status = 1 WHERE id = ?")
                ->execute([$row['id']]);
            $result = ['favorited' => true, 'action' => 'add', 'group_id' => $row['group_id'] !== null ? (int)$row['group_id'] : null];
        } else {
            $db->prepare("INSERT INTO favorites (visitor_id, message_id, group_id, status) VALUES (?, ?, NULL, 1)")
                ->execute([$visitorId, $messageId]);
            $result = ['favorited' => true, 'action' => 'add', 'group_id' => null];
        }

        $db->commit();
        return $result;
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * 获取当前访客的收藏分组（附带各分组有效收藏数）
 */
function getFavoriteGroups() {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT g.id, g.name, g.created_at,
        (SELECT COUNT(*) FROM favorites f WHERE f.group_id = g.id AND f.status = 1) AS count
        FROM favorite_groups g
        WHERE g.visitor_id = ?
        ORDER BY g.created_at ASC");
    $stmt->execute([$visitorId]);
    return $stmt->fetchAll();
}

/**
 * 收藏页概览统计：有效收藏总数、分类统计、未分组数、回收站数
 */
function getFavoriteOverview() {
    $visitorId = getVisitorId();
    $db = getDB();

    $stmt = $db->prepare("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN m.type='help' THEN 1 ELSE 0 END) AS help_count,
        SUM(CASE WHEN m.type='suggest' THEN 1 ELSE 0 END) AS suggest_count,
        SUM(CASE WHEN m.type='lost' THEN 1 ELSE 0 END) AS lost_count,
        SUM(CASE WHEN f.group_id IS NULL THEN 1 ELSE 0 END) AS ungrouped_count
        FROM favorites f INNER JOIN messages m ON f.message_id = m.id
        WHERE f.visitor_id = ? AND f.status = 1 AND m.status = 1");
    $stmt->execute([$visitorId]);
    $row = $stmt->fetch();

    $trashStmt = $db->prepare("SELECT COUNT(*) FROM favorites f
        INNER JOIN messages m ON f.message_id = m.id
        WHERE f.visitor_id = ? AND f.status = 0 AND m.status = 1");
    $trashStmt->execute([$visitorId]);
    $row['trash_count'] = (int)$trashStmt->fetchColumn();

    foreach (['total', 'help_count', 'suggest_count', 'lost_count', 'ungrouped_count'] as $k) {
        $row[$k] = (int)($row[$k] ?? 0);
    }
    return $row;
}

/**
 * 新建收藏分组，返回分组ID
 */
function createFavoriteGroup($name) {
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 50) {
        throw new Exception('分组名称长度需在1-50个字符之间');
    }
    $visitorId = getVisitorId();
    $db = getDB();

    $stmt = $db->prepare("SELECT id FROM favorite_groups WHERE visitor_id = ? AND name = ?");
    $stmt->execute([$visitorId, $name]);
    if ($stmt->fetch()) {
        throw new Exception('已存在同名分组');
    }

    $db->prepare("INSERT INTO favorite_groups (visitor_id, name) VALUES (?, ?)")
        ->execute([$visitorId, $name]);
    return (int)$db->lastInsertId();
}

/**
 * 重命名收藏分组
 */
function renameFavoriteGroup($groupId, $name) {
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 50) {
        throw new Exception('分组名称长度需在1-50个字符之间');
    }
    $visitorId = getVisitorId();
    $db = getDB();

    $stmt = $db->prepare("SELECT id FROM favorite_groups WHERE id = ? AND visitor_id = ?");
    $stmt->execute([$groupId, $visitorId]);
    if (!$stmt->fetch()) {
        throw new Exception('分组不存在');
    }

    $stmt = $db->prepare("SELECT id FROM favorite_groups WHERE visitor_id = ? AND name = ? AND id <> ?");
    $stmt->execute([$visitorId, $name, $groupId]);
    if ($stmt->fetch()) {
        throw new Exception('已存在同名分组');
    }

    $db->prepare("UPDATE favorite_groups SET name = ? WHERE id = ? AND visitor_id = ?")
        ->execute([$name, $groupId, $visitorId]);
}

/**
 * 删除收藏分组，组内收藏自动变为未分组（外键 ON DELETE SET NULL 兜底）
 */
function deleteFavoriteGroup($groupId) {
    $visitorId = getVisitorId();
    $db = getDB();

    $stmt = $db->prepare("SELECT id FROM favorite_groups WHERE id = ? AND visitor_id = ?");
    $stmt->execute([$groupId, $visitorId]);
    if (!$stmt->fetch()) {
        throw new Exception('分组不存在');
    }

    $db->prepare("UPDATE favorites SET group_id = NULL WHERE group_id = ? AND visitor_id = ?")
        ->execute([$groupId, $visitorId]);
    $db->prepare("DELETE FROM favorite_groups WHERE id = ? AND visitor_id = ?")
        ->execute([$groupId, $visitorId]);
}

/**
 * 收藏批量整理：逐条独立处理，成功项不回滚
 *
 * @param array $messageIds 留言ID数组
 * @param string $action move_group|cancel|restore|purge
 * @param int|null $groupId move_group 时的目标分组，null表示未分组
 * @return array {success:int, failed:int, results:array, overview:array}
 */
function batchUpdateFavorites(array $messageIds, $action, $groupId = null) {
    $validActions = ['move_group', 'cancel', 'restore', 'purge'];
    if (!in_array($action, $validActions, true)) {
        throw new Exception('不支持的操作类型');
    }

    $visitorId = getVisitorId();
    $db = getDB();

    // move_group 时先校验目标分组归属
    $targetGroupName = '未分组';
    if ($action === 'move_group' && $groupId !== null) {
        $stmt = $db->prepare("SELECT id, name FROM favorite_groups WHERE id = ? AND visitor_id = ?");
        $stmt->execute([$groupId, $visitorId]);
        $targetGroup = $stmt->fetch();
        if (!$targetGroup) {
            throw new Exception('目标分组不存在');
        }
        $targetGroupName = $targetGroup['name'];
    }

    $results = [];
    $success = 0;
    $failed = 0;

    foreach ($messageIds as $messageId) {
        $messageId = (int)$messageId;
        $item = ['message_id' => $messageId, 'title' => '', 'success' => false, 'msg' => ''];

        // 取本人收藏记录（含回收站）及留言信息
        $stmt = $db->prepare("SELECT f.id, f.status, f.group_id, m.title, m.status AS message_status
            FROM favorites f INNER JOIN messages m ON f.message_id = m.id
            WHERE f.visitor_id = ? AND f.message_id = ?");
        $stmt->execute([$visitorId, $messageId]);
        $row = $stmt->fetch();

        if (!$row) {
            $item['msg'] = '未找到该收藏记录';
        } else {
            $item['title'] = $row['title'];
            switch ($action) {
                case 'move_group':
                    if ((int)$row['status'] !== 1) {
                        $item['msg'] = '收藏已取消，请先恢复后再移动';
                        break;
                    }
                    $db->prepare("UPDATE favorites SET group_id = ? WHERE id = ?")
                        ->execute([$groupId, $row['id']]);
                    $item['success'] = true;
                    $item['msg'] = '已移动到「' . $targetGroupName . '」';
                    break;

                case 'cancel':
                    if ((int)$row['status'] !== 1) {
                        $item['msg'] = '该收藏已在回收站中';
                        break;
                    }
                    $db->prepare("UPDATE favorites SET status = 0 WHERE id = ?")
                        ->execute([$row['id']]);
                    $item['success'] = true;
                    $item['msg'] = '已取消收藏';
                    break;

                case 'restore':
                    if ((int)$row['status'] === 1) {
                        $item['msg'] = '该收藏未被取消，无需恢复';
                        break;
                    }
                    if ((int)$row['message_status'] !== 1) {
                        $item['msg'] = '留言已下架或未通过审核，无法恢复';
                        break;
                    }
                    $db->prepare("UPDATE favorites SET status = 1 WHERE id = ?")
                        ->execute([$row['id']]);
                    $item['success'] = true;
                    $item['msg'] = '已恢复收藏';
                    break;

                case 'purge':
                    if ((int)$row['status'] !== 0) {
                        $item['msg'] = '只能彻底删除回收站中的收藏';
                        break;
                    }
                    $db->prepare("DELETE FROM favorites WHERE id = ?")
                        ->execute([$row['id']]);
                    $item['success'] = true;
                    $item['msg'] = '已彻底删除';
                    break;
            }
        }

        if ($item['success']) {
            $success++;
        } else {
            $failed++;
        }
        $results[] = $item;
    }

    return [
        'success' => $success,
        'failed' => $failed,
        'results' => $results,
        'overview' => getFavoriteOverview(),
        'groups' => getFavoriteGroups(),
    ];
}

/**
 * 查询当前访客在指定视图下的收藏条目（用于整组整理前逐条确认）
 *
 * @param string $scope all|ungrouped|trash|group
 * @param int|null $groupId scope=group 时的分组ID
 * @param string $type 留言类型过滤 help|suggest|lost，空为全部
 * @return array
 */
function getFavoriteItemsForScope($scope, $groupId = null, $type = '') {
    $visitorId = getVisitorId();
    $db = getDB();

    $where = "WHERE f.visitor_id = ? AND m.status = 1";
    $params = [$visitorId];

    if ($scope === 'trash') {
        $where .= " AND f.status = 0";
    } else {
        $where .= " AND f.status = 1";
        if ($scope === 'ungrouped') {
            $where .= " AND f.group_id IS NULL";
        } elseif ($scope === 'group') {
            // 先校验分组归属，防止遍历他人分组
            $gStmt = $db->prepare("SELECT id FROM favorite_groups WHERE id = ? AND visitor_id = ?");
            $gStmt->execute([$groupId, $visitorId]);
            if (!$gStmt->fetch()) {
                throw new Exception('分组不存在');
            }
            $where .= " AND f.group_id = ?";
            $params[] = $groupId;
        }
    }

    if ($type && in_array($type, ['help', 'suggest', 'lost'], true)) {
        $where .= " AND m.type = ?";
        $params[] = $type;
    }

    $sql = "SELECT m.id AS message_id, m.title, m.type, m.nickname
        FROM favorites f INNER JOIN messages m ON f.message_id = m.id
        $where ORDER BY f.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * 获取举报类型文字
 */
function getReportTypeLabel($type) {
    $map = [
        'spam' => '垃圾信息',
        'abuse' => '辱骂攻击',
        'illegal' => '违法违规',
        'porn' => '色情低俗',
        'other' => '其他'
    ];
    return $map[$type] ?? '未知';
}

/**
 * 获取举报状态文字
 */
function getReportStatusLabel($status) {
    $map = [
        0 => '待处理',
        1 => '已处理-已删除',
        2 => '已处理-已忽略',
        3 => '已驳回'
    ];
    return $map[$status] ?? '未知';
}

/**
 * 获取举报状态样式类
 */
function getReportStatusClass($status) {
    $map = [
        0 => 'pending',
        1 => 'resolved-deleted',
        2 => 'resolved-ignored',
        3 => 'rejected'
    ];
    return $map[$status] ?? '';
}

/**
 * 检查当前访客是否已举报过某条留言
 */
function hasReported($messageId) {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM reports WHERE visitor_id = ? AND message_id = ?");
    $stmt->execute([$visitorId, $messageId]);
    return $stmt->fetch() !== false;
}

/**
 * 提交举报
 */
function submitReport($messageId, $reportType, $description = '') {
    $visitorId = getVisitorId();
    $db = getDB();

    $validTypes = ['spam', 'abuse', 'illegal', 'porn', 'other'];
    if (!in_array($reportType, $validTypes)) {
        throw new Exception('无效的举报类型');
    }

    $stmt = $db->prepare("SELECT id FROM messages WHERE id = ? AND status = 1");
    $stmt->execute([$messageId]);
    if (!$stmt->fetch()) {
        throw new Exception('留言不存在或未通过审核');
    }

    if (hasReported($messageId)) {
        throw new Exception('您已经举报过这条留言了');
    }

    $stmt = $db->prepare("INSERT INTO reports (message_id, visitor_id, report_type, description) VALUES (?, ?, ?, ?)");
    $stmt->execute([$messageId, $visitorId, $reportType, $description]);

    return $db->lastInsertId();
}

/**
 * 获取待处理举报数量
 */
function getPendingReportCount() {
    $db = getDB();
    return $db->query("SELECT COUNT(*) FROM reports WHERE status = 0")->fetchColumn();
}
