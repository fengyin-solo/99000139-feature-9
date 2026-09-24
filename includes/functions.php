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
 * 检查留言是否已收藏
 */
function isFavorited($messageId) {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM favorites WHERE visitor_id = ? AND message_id = ?");
    $stmt->execute([$visitorId, $messageId]);
    return $stmt->fetch() !== false;
}

/**
 * 获取当前访客收藏的所有留言ID
 */
function getFavoritedMessageIds() {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT message_id FROM favorites WHERE visitor_id = ?");
    $stmt->execute([$visitorId]);
    return array_column($stmt->fetchAll(), 'message_id');
}

/**
 * 切换收藏状态
 * 返回: ['favorited' => bool, 'action' => 'add'|'remove']
 */
function toggleFavorite($messageId) {
    $visitorId = getVisitorId();
    $db = getDB();

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT id FROM favorites WHERE visitor_id = ? AND message_id = ? FOR UPDATE");
        $stmt->execute([$visitorId, $messageId]);
        $exists = $stmt->fetch();

        if ($exists) {
            $db->prepare("DELETE FROM favorites WHERE visitor_id = ? AND message_id = ?")
                ->execute([$visitorId, $messageId]);
            $result = ['favorited' => false, 'action' => 'remove'];
        } else {
            $db->prepare("INSERT INTO favorites (visitor_id, message_id) VALUES (?, ?)")
                ->execute([$visitorId, $messageId]);
            $result = ['favorited' => true, 'action' => 'add'];
        }

        $db->commit();
        return $result;
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * 确保收藏分组相关表结构存在（幂等迁移，老库访问时自动补建）
 */
function ensureFavoriteGroupsSchema($db) {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $db->exec("CREATE TABLE IF NOT EXISTS `favorite_groups` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `visitor_id` VARCHAR(64) NOT NULL COMMENT '访客唯一标识',
        `name` VARCHAR(50) NOT NULL COMMENT '分组名称',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        UNIQUE KEY `uk_visitor_name` (`visitor_id`, `name`),
        INDEX `idx_visitor_id` (`visitor_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='收藏分组表'");

    $col = $db->query("SHOW COLUMNS FROM `favorites` LIKE 'group_id'")->fetch();
    if (!$col) {
        $db->exec("ALTER TABLE `favorites`
            ADD COLUMN `group_id` INT UNSIGNED NULL DEFAULT NULL COMMENT '所属分组ID，NULL为未分组' AFTER `message_id`");
        $db->exec("ALTER TABLE `favorites` ADD INDEX `idx_group_id` (`group_id`)");
        try {
            $db->exec("ALTER TABLE `favorites`
                ADD CONSTRAINT `fk_favorites_group`
                FOREIGN KEY (`group_id`) REFERENCES `favorite_groups`(`id`) ON DELETE SET NULL");
        } catch (Exception $e) {
            // 外键已存在等情况可忽略，不影响功能
        }
    }
}

/**
 * 校验收藏分组名称
 * 返回清洗后的名称，不合法时抛出异常
 */
function normalizeGroupName($name) {
    $name = trim($name);
    $len = mb_strlen($name, 'UTF-8');
    if ($len === 0) {
        throw new Exception('分组名称不能为空');
    }
    if ($len > 20) {
        throw new Exception('分组名称不能超过20个字符');
    }
    return $name;
}

/**
 * 获取访客的全部分组（含组内有效收藏数量，按创建时间排序）
 */
function getFavoriteGroups($visitorId, $type = '') {
    $db = getDB();
    // 按与有效留言（status=1）的关联统计组内收藏数量；失效留言不计入
    $sql = "SELECT g.id, g.name, g.created_at,
            COUNT(m.id) AS item_count
            FROM favorite_groups g
            LEFT JOIN favorites f ON f.group_id = g.id
            LEFT JOIN messages m ON f.message_id = m.id AND m.status = 1
            WHERE g.visitor_id = ?";
    $params = [$visitorId];
    if ($type && in_array($type, ['help', 'suggest', 'lost'])) {
        $sql .= " AND (m.id IS NULL OR m.type = ?)";
        $params[] = $type;
    }
    $sql .= " GROUP BY g.id, g.name, g.created_at ORDER BY g.created_at ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * 按ID获取访客自己的分组（归属校验），不存在返回 false
 */
function getFavoriteGroup($groupId, $visitorId) {
    $groupId = intval($groupId);
    if ($groupId <= 0) return false;
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM favorite_groups WHERE id = ? AND visitor_id = ?");
    $stmt->execute([$groupId, $visitorId]);
    return $stmt->fetch() ?: false;
}

/**
 * 创建收藏分组，返回分组ID
 */
function createFavoriteGroup($visitorId, $name) {
    $name = normalizeGroupName($name);
    $db = getDB();

    $stmt = $db->prepare("SELECT id FROM favorite_groups WHERE visitor_id = ? AND name = ?");
    $stmt->execute([$visitorId, $name]);
    if ($stmt->fetch()) {
        throw new Exception('已存在同名分组');
    }

    $stmt = $db->prepare("INSERT INTO favorite_groups (visitor_id, name) VALUES (?, ?)");
    $stmt->execute([$visitorId, $name]);
    return intval($db->lastInsertId());
}

/**
 * 重命名收藏分组
 */
function renameFavoriteGroup($groupId, $visitorId, $name) {
    $name = normalizeGroupName($name);
    if (!getFavoriteGroup($groupId, $visitorId)) {
        throw new Exception('分组不存在');
    }
    $db = getDB();

    $stmt = $db->prepare("SELECT id FROM favorite_groups WHERE visitor_id = ? AND name = ? AND id <> ?");
    $stmt->execute([$visitorId, $name, $groupId]);
    if ($stmt->fetch()) {
        throw new Exception('已存在同名分组');
    }

    $stmt = $db->prepare("UPDATE favorite_groups SET name = ? WHERE id = ? AND visitor_id = ?");
    $stmt->execute([$name, $groupId, $visitorId]);
    return true;
}

/**
 * 删除收藏分组，组内收藏自动回到未分组（依赖外键 ON DELETE SET NULL）
 */
function deleteFavoriteGroup($groupId, $visitorId) {
    $group = getFavoriteGroup($groupId, $visitorId);
    if (!$group) {
        throw new Exception('分组不存在');
    }
    $db = getDB();

    // 即使外键未生效也显式将组内收藏移回未分组，保证行为一致
    $db->prepare("UPDATE favorites SET group_id = NULL WHERE group_id = ? AND visitor_id = ?")
        ->execute([$groupId, $visitorId]);
    $db->prepare("DELETE FROM favorite_groups WHERE id = ? AND visitor_id = ?")
        ->execute([$groupId, $visitorId]);
    return true;
}

/**
 * 获取收藏页统计汇总（总数、分类数、未分组数、各分组数）
 * type 非空时所有统计均按留言类型过滤
 */
function getFavoriteSummary($visitorId, $type = '') {
    $db = getDB();

    $where = "WHERE f.visitor_id = ? AND m.status = 1";
    $params = [$visitorId];
    if ($type && in_array($type, ['help', 'suggest', 'lost'])) {
        $where .= " AND m.type = ?";
        $params[] = $type;
    }

    $stmt = $db->prepare("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN m.type='help' THEN 1 ELSE 0 END) AS help_count,
        SUM(CASE WHEN m.type='suggest' THEN 1 ELSE 0 END) AS suggest_count,
        SUM(CASE WHEN m.type='lost' THEN 1 ELSE 0 END) AS lost_count,
        SUM(CASE WHEN f.group_id IS NULL THEN 1 ELSE 0 END) AS ungrouped_count
        FROM favorites f INNER JOIN messages m ON f.message_id = m.id $where");
    $stmt->execute($params);
    $summary = $stmt->fetch();

    $summary['total'] = intval($summary['total']);
    $summary['help_count'] = intval($summary['help_count']);
    $summary['suggest_count'] = intval($summary['suggest_count']);
    $summary['lost_count'] = intval($summary['lost_count']);
    $summary['ungrouped_count'] = intval($summary['ungrouped_count']);
    $summary['groups'] = getFavoriteGroups($visitorId, $type);

    return $summary;
}

/**
 * 批量整理收藏：move 移入分组（groupId 为 0/NULL 表示移到未分组）、remove 批量取消、restore 批量恢复
 * 逐条独立处理，单条失败不影响其他条目、不回滚成功项
 * 返回: ['action' => ..., 'results' => [['message_id','title','ok','msg']...], 'success' => n, 'failed' => n]
 */
function batchUpdateFavorites($visitorId, $action, array $messageIds, $groupId = null) {
    $db = getDB();

    $validActions = ['move', 'remove', 'restore'];
    if (!in_array($action, $validActions)) {
        throw new Exception('不支持的批量操作');
    }

    // 归并、去重、过滤非法ID
    $ids = [];
    foreach ($messageIds as $id) {
        $id = intval($id);
        if ($id > 0) $ids[$id] = $id;
    }
    $ids = array_values($ids);
    if (empty($ids)) {
        throw new Exception('请先勾选要操作的留言');
    }
    if (count($ids) > 100) {
        throw new Exception('单次最多操作100条留言');
    }

    $targetGroupId = null;
    if ($action === 'move' && $groupId !== null && intval($groupId) > 0) {
        if (!getFavoriteGroup($groupId, $visitorId)) {
            throw new Exception('目标分组不存在');
        }
        $targetGroupId = intval($groupId);
    }
    if ($action === 'restore' && $groupId !== null && intval($groupId) > 0) {
        // 恢复时允许直接恢复到当前所在分组视图
        if (!getFavoriteGroup($groupId, $visitorId)) {
            throw new Exception('目标分组不存在');
        }
        $targetGroupId = intval($groupId);
    }

    // 一次性查询留言信息（标题、状态）
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $msgStmt = $db->prepare("SELECT id, title, status FROM messages WHERE id IN ($placeholders)");
    $msgStmt->execute($ids);
    $messages = [];
    foreach ($msgStmt->fetchAll() as $row) {
        $messages[intval($row['id'])] = $row;
    }

    // 一次性查询当前收藏归属
    $favStmt = $db->prepare("SELECT message_id, group_id FROM favorites WHERE visitor_id = ? AND message_id IN ($placeholders)");
    $favStmt->execute(array_merge([$visitorId], $ids));
    $favorites = [];
    foreach ($favStmt->fetchAll() as $row) {
        $favorites[intval($row['message_id'])] = $row;
    }

    $results = [];
    $success = 0;
    $failed = 0;

    $moveStmt = $db->prepare("UPDATE favorites SET group_id = ? WHERE visitor_id = ? AND message_id = ?");
    $deleteStmt = $db->prepare("DELETE FROM favorites WHERE visitor_id = ? AND message_id = ?");
    $insertStmt = $db->prepare("INSERT INTO favorites (visitor_id, message_id, group_id) VALUES (?, ?, ?)");

    foreach ($ids as $id) {
        $title = isset($messages[$id]) ? $messages[$id]['title'] : "留言#{$id}";
        $item = ['message_id' => $id, 'title' => $title, 'ok' => false, 'msg' => ''];

        try {
            if ($action === 'move') {
                if (!isset($messages[$id])) {
                    throw new Exception('留言不存在，无法移动');
                }
                if (!isset($favorites[$id])) {
                    throw new Exception('该留言不在收藏中，无法移动');
                }
                $currentGroupId = $favorites[$id]['group_id'] !== null ? intval($favorites[$id]['group_id']) : null;
                if ($currentGroupId === $targetGroupId) {
                    $item['ok'] = true;
                    $item['msg'] = $targetGroupId === null ? '已在未分组中' : '已在该分组中';
                } else {
                    $moveStmt->execute([$targetGroupId, $visitorId, $id]);
                    if ($moveStmt->rowCount() < 1) {
                        throw new Exception('移动失败，请稍后重试');
                    }
                    $item['msg'] = $targetGroupId === null ? '已移至未分组' : '已移入分组';
                    $item['ok'] = true;
                }
            } elseif ($action === 'remove') {
                if (!isset($favorites[$id])) {
                    throw new Exception('该留言不在收藏中，无需取消');
                }
                $deleteStmt->execute([$visitorId, $id]);
                if ($deleteStmt->rowCount() < 1) {
                    throw new Exception('取消失败，请稍后重试');
                }
                $item['msg'] = '已取消收藏';
                $item['ok'] = true;
            } else { // restore
                if (isset($favorites[$id])) {
                    throw new Exception('该留言已在收藏中，无需恢复');
                }
                if (!isset($messages[$id])) {
                    throw new Exception('留言已被删除，无法恢复');
                }
                if (intval($messages[$id]['status']) !== 1) {
                    throw new Exception('留言未通过审核，无法恢复');
                }
                $insertStmt->execute([$visitorId, $id, $targetGroupId]);
                $item['msg'] = $targetGroupId === null ? '已恢复到未分组' : '已恢复到分组';
                $item['ok'] = true;
            }
        } catch (Exception $e) {
            $item['msg'] = $e->getMessage();
        }

        if ($item['ok']) {
            $success++;
        } else {
            $failed++;
        }
        $results[] = $item;
    }

    return [
        'action' => $action,
        'group_id' => $targetGroupId,
        'results' => $results,
        'success' => $success,
        'failed' => $failed,
    ];
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
