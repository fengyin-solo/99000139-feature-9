<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

$pageTitle = '我的收藏 - 社区便民留言板';
$currentPage = 'favorites';
$cssPath = 'assets/css/style.css';
$jsPath = 'assets/js/main.js';

$db = getDB();
$visitorId = getVisitorId();

// 视图：all 全部 / ungrouped 未分组 / group 某分组 / trash 回收站
$view = $_GET['view'] ?? 'all';
if (!in_array($view, ['all', 'ungrouped', 'group', 'trash'], true)) {
    $view = 'all';
}
$groupId = intval($_GET['group_id'] ?? 0);
$type = $_GET['type'] ?? '';
if (!in_array($type, ['help', 'suggest', 'lost'], true)) {
    $type = '';
}
$page = max(1, intval($_GET['page'] ?? 1));
$pageSize = 10;
$offset = ($page - 1) * $pageSize;

$groups = getFavoriteGroups();
foreach ($groups as &$g) {
    $g['id'] = (int)$g['id'];
    $g['count'] = (int)$g['count'];
}
unset($g);
$groupMap = array_column($groups, null, 'id');
if ($view === 'group' && ($groupId <= 0 || !isset($groupMap[$groupId]))) {
    $redirectParams = array_filter(['type' => $type]);
    header('Location: favorites.php' . ($redirectParams ? '?' . http_build_query($redirectParams) : ''));
    exit;
}
$currentGroup = $view === 'group' ? $groupMap[$groupId] : null;
$isTrash = $view === 'trash';

// 列表查询条件
$where = "WHERE f.visitor_id = ? AND m.status = 1 AND f.status = " . ($isTrash ? '0' : '1');
$params = [$visitorId];

if (!$isTrash) {
    if ($view === 'ungrouped') {
        $where .= " AND f.group_id IS NULL";
    } elseif ($view === 'group') {
        $where .= " AND f.group_id = ?";
        $params[] = $groupId;
    }
}
if ($type) {
    $where .= " AND m.type = ?";
    $params[] = $type;
}

$countSql = "SELECT COUNT(*) FROM favorites f INNER JOIN messages m ON f.message_id = m.id $where";
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = (int)ceil($total / $pageSize);
if ($page > $totalPages && $totalPages > 0) {
    parse_str($_SERVER['QUERY_STRING'] ?? '', $queryParams);
    $queryParams['page'] = $totalPages;
    $queryParams = array_filter($queryParams, function ($v) {
        return $v !== '' && $v !== 0 && $v !== 'all';
    });
    header('Location: favorites.php' . ($queryParams ? '?' . http_build_query($queryParams) : ''));
    exit;
}

$sql = "SELECT m.id, m.nickname, m.type, m.title, m.content, m.image, m.views, m.created_at,
        f.created_at AS favorited_at, f.group_id
        FROM favorites f
        INNER JOIN messages m ON f.message_id = m.id
        $where
        ORDER BY f.created_at DESC
        LIMIT $pageSize OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$favorites = $stmt->fetchAll();

$overview = getFavoriteOverview();

// 构造分页/筛选链接时保留当前视图参数
function buildFavUrl(array $override = []) {
    $params = [
        'view' => $GLOBALS['view'] !== 'all' ? $GLOBALS['view'] : '',
        'group_id' => $GLOBALS['groupId'] ?: '',
        'type' => $GLOBALS['type'] ?? '',
        'page' => $GLOBALS['page'] ?? 1,
    ];
    $params = array_merge($params, $override);
    $params = array_filter($params, function ($v) {
        return $v !== '' && $v !== 0;
    });
    return 'favorites.php' . ($params ? '?' . http_build_query($params) : '');
}

include __DIR__ . '/includes/header.php';
?>

<section class="favorites-section">
    <div class="container">
        <div class="page-header">
            <h1 class="page-title">⭐ 我的收藏</h1>
            <p class="page-subtitle" id="pageSubtitle">
                <?= $isTrash ? '回收站中共有 ' . $overview['trash_count'] . ' 条已取消的收藏' : '共收藏 ' . $overview['total'] . ' 条留言' ?>
            </p>
        </div>

        <div class="favorites-stats" id="favoritesStats" data-view="<?= cleanInput($view) ?>">
            <a href="favorites.php" class="stat-card stat-total <?= $view === 'all' && !$isTrash ? 'stat-active' : '' ?>">
                <div class="stat-number" data-stat="total"><?= $overview['total'] ?></div>
                <div class="stat-label">全部收藏</div>
            </a>
            <div class="stat-card stat-help">
                <div class="stat-number" data-stat="help_count"><?= $overview['help_count'] ?></div>
                <div class="stat-label">🆘 求助</div>
            </div>
            <div class="stat-card stat-suggest">
                <div class="stat-number" data-stat="suggest_count"><?= $overview['suggest_count'] ?></div>
                <div class="stat-label">💡 建议</div>
            </div>
            <div class="stat-card stat-lost">
                <div class="stat-number" data-stat="lost_count"><?= $overview['lost_count'] ?></div>
                <div class="stat-label">🔍 失物</div>
            </div>
        </div>

        <div class="favorites-layout">
            <!-- 分组侧栏 -->
            <aside class="groups-sidebar" id="groupsSidebar">
                <div class="groups-sidebar-header">
                    <span>📁 收藏分组</span>
                    <button type="button" class="group-add-btn" id="addGroupBtn" title="新建分组">＋</button>
                </div>
                <ul class="groups-list" id="groupsList">
                    <li>
                        <a href="favorites.php" class="group-item <?= $view === 'all' && !$isTrash ? 'active' : '' ?>">
                            <span class="group-name">⭐ 全部收藏</span>
                            <span class="group-count" data-count="total"><?= $overview['total'] ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="favorites.php?view=ungrouped<?= $type ? '&type=' . urlencode($type) : '' ?>" class="group-item <?= $view === 'ungrouped' ? 'active' : '' ?>">
                            <span class="group-name">📂 未分组</span>
                            <span class="group-count" data-count="ungrouped_count"><?= $overview['ungrouped_count'] ?></span>
                        </a>
                    </li>
                    <?php foreach ($groups as $group): ?>
                    <li class="group-row" data-group-id="<?= $group['id'] ?>" data-group-name="<?= cleanInput($group['name']) ?>">
                        <a href="favorites.php?view=group&group_id=<?= $group['id'] ?><?= $type ? '&type=' . urlencode($type) : '' ?>"
                           class="group-item <?= $view === 'group' && $groupId === (int)$group['id'] ? 'active' : '' ?>">
                            <span class="group-name">🗂️ <?= cleanInput($group['name']) ?></span>
                            <span class="group-count"><?= (int)$group['count'] ?></span>
                        </a>
                        <span class="group-manage">
                            <button type="button" class="group-manage-btn" data-group-action="rename" title="重命名">✏️</button>
                            <button type="button" class="group-manage-btn" data-group-action="delete" title="删除分组">🗑️</button>
                        </span>
                    </li>
                    <?php endforeach; ?>
                    <li>
                        <a href="favorites.php?view=trash" class="group-item group-trash <?= $isTrash ? 'active' : '' ?>">
                            <span class="group-name">🗑️ 回收站</span>
                            <span class="group-count" data-count="trash_count"><?= $overview['trash_count'] ?></span>
                        </a>
                    </li>
                </ul>
            </aside>

            <!-- 右侧主区域 -->
            <div class="favorites-main">
                <?php if (!$isTrash): ?>
                <div class="filter-section">
                    <div class="filter-types">
                        <a href="<?= buildFavUrl(['type' => '', 'page' => 1]) ?>" class="filter-tag <?= !$type ? 'active' : '' ?>">全部</a>
                        <a href="<?= buildFavUrl(['type' => 'help', 'page' => 1]) ?>" class="filter-tag <?= $type === 'help' ? 'active' : '' ?>">🆘 求助</a>
                        <a href="<?= buildFavUrl(['type' => 'suggest', 'page' => 1]) ?>" class="filter-tag <?= $type === 'suggest' ? 'active' : '' ?>">💡 建议</a>
                        <a href="<?= buildFavUrl(['type' => 'lost', 'page' => 1]) ?>" class="filter-tag <?= $type === 'lost' ? 'active' : '' ?>">🔍 失物招领</a>
                    </div>
                    <?php if ($view === 'group' && $currentGroup): ?>
                    <div class="current-group-tip">
                        当前分组：<strong>🗂️ <?= cleanInput($currentGroup['name']) ?></strong>
                    </div>
                    <?php elseif ($view === 'ungrouped'): ?>
                    <div class="current-group-tip"><strong>📂 未分组收藏</strong></div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="filter-section">
                    <div class="trash-tip">🗑️ 回收站中的收藏不会计入统计，可恢复到原分组或彻底删除。</div>
                </div>
                <?php endif; ?>

                <?php if (empty($favorites)): ?>
                <div class="empty-state">
                    <div class="empty-icon"><?= $isTrash ? '🗑️' : '⭐' ?></div>
                    <p><?= $isTrash ? '回收站是空的' : '暂无收藏的留言' ?></p>
                    <?php if (!$isTrash): ?>
                    <a href="index.php" class="btn btn-primary">去浏览留言</a>
                    <?php else: ?>
                    <a href="favorites.php" class="btn btn-secondary">返回收藏</a>
                    <?php endif; ?>
                </div>
                <?php else: ?>

                <!-- 批量整理工具栏 -->
                <div class="batch-toolbar" id="batchToolbar">
                    <label class="batch-select-all">
                        <input type="checkbox" id="selectAllChk">
                        <span>全选当前页</span>
                    </label>
                    <span class="batch-selected-count">已选 <strong id="selectedCount">0</strong> 条</span>
                    <div class="batch-actions">
                        <button type="button" class="btn btn-sm btn-info" id="scopeOrganizeBtn">
                            <?= $isTrash ? '🧹 整理整个回收站' : '🧺 整理当前视图全部' ?>
                        </button>
                        <?php if (!$isTrash): ?>
                        <button type="button" class="btn btn-sm btn-primary" id="batchMoveBtn" disabled>📁 移入分组</button>
                        <button type="button" class="btn btn-sm btn-warning" id="batchCancelBtn" disabled>取消收藏</button>
                        <?php else: ?>
                        <button type="button" class="btn btn-sm btn-success" id="batchRestoreBtn" disabled>♻️ 恢复</button>
                        <button type="button" class="btn btn-sm btn-danger" id="batchPurgeBtn" disabled>彻底删除</button>
                        <button type="button" class="btn btn-sm btn-secondary" id="emptyTrashBtn">清空回收站</button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="message-list" id="favoritesList">
                    <?php foreach ($favorites as $msg): ?>
                    <div class="message-card favorite-card" data-message-id="<?= $msg['id'] ?>" data-title="<?= cleanInput($msg['title']) ?>" data-type="<?= cleanInput($msg['type']) ?>">
                        <label class="favorite-check" title="勾选后可批量整理">
                            <input type="checkbox" class="favorite-item-chk" value="<?= $msg['id'] ?>">
                        </label>
                        <a href="detail.php?id=<?= $msg['id'] ?>" class="card-link">
                            <div class="card-header">
                                <span class="card-type type-<?= $msg['type'] ?>"><?= getTypeIcon($msg['type']) ?> <?= getTypeLabel($msg['type']) ?></span>
                                <span class="card-time">收藏于 <?= timeAgo($msg['favorited_at']) ?></span>
                            </div>
                            <h3 class="card-title"><?= cleanInput($msg['title']) ?></h3>
                            <p class="card-content"><?= cleanInput(mb_substr($msg['content'], 0, 80)) ?><?= mb_strlen($msg['content']) > 80 ? '...' : '' ?></p>
                            <div class="card-footer">
                                <span class="card-author">👤 <?= cleanInput($msg['nickname']) ?></span>
                                <?php if ($msg['image']): ?>
                                <span class="card-image">📷 有图</span>
                                <?php endif; ?>
                                <span class="card-views">👁 <?= $msg['views'] ?></span>
                                <?php if (!$isTrash): ?>
                                <span class="card-group-tag">
                                    <?= $msg['group_id'] && isset($groupMap[$msg['group_id']]) ? '🗂️ ' . cleanInput($groupMap[$msg['group_id']]['name']) : '📂 未分组' ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </a>
                        <?php if (!$isTrash): ?>
                        <button class="favorite-btn favorited" data-message-id="<?= $msg['id'] ?>" onclick="toggleFavorite(event, this)">
                            <span class="favorite-icon">⭐</span>
                            <span class="favorite-text">已收藏</span>
                        </button>
                        <?php else: ?>
                        <div class="trash-card-actions">
                            <button type="button" class="btn btn-xs btn-success" onclick="singleBatchAction(event, 'restore', [<?= $msg['id'] ?>])">♻️ 恢复</button>
                            <button type="button" class="btn btn-xs btn-danger" onclick="singleBatchAction(event, 'purge', [<?= $msg['id'] ?>])">彻底删除</button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                    <a href="<?= buildFavUrl(['page' => $page - 1]) ?>" class="page-btn">上一页</a>
                    <?php endif; ?>
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <a href="<?= buildFavUrl(['page' => $i]) ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                    <a href="<?= buildFavUrl(['page' => $page + 1]) ?>" class="page-btn">下一页</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- 新建/重命名分组弹窗 -->
<div class="modal" id="groupModal" style="display:none;">
    <div class="modal-content modal-sm">
        <div class="modal-header">
            <h3 id="groupModalTitle">新建分组</h3>
            <button class="modal-close" onclick="closeGroupModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label for="groupNameInput">分组名称 <span class="text-muted">(1-50字)</span></label>
                <input type="text" id="groupNameInput" maxlength="50" placeholder="请输入分组名称">
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeGroupModal()">取消</button>
                <button type="button" class="btn btn-primary" id="groupModalSubmit">确定</button>
            </div>
        </div>
    </div>
</div>

<!-- 移入分组弹窗 -->
<div class="modal" id="moveGroupModal" style="display:none;">
    <div class="modal-content modal-sm">
        <div class="modal-header">
            <h3>📁 移入分组</h3>
            <button class="modal-close" onclick="closeMoveGroupModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="move-group-options" id="moveGroupOptions"></div>
            <div class="move-new-group">
                <input type="text" id="moveNewGroupName" maxlength="50" placeholder="或输入新分组名称（最多50字）">
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeMoveGroupModal()">取消</button>
                <button type="button" class="btn btn-primary" id="moveGroupConfirmBtn">下一步：确认明细</button>
            </div>
        </div>
    </div>
</div>

<!-- 批量操作逐条确认弹窗 -->
<div class="modal" id="batchConfirmModal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="batchConfirmTitle">确认操作</h3>
            <button class="modal-close" onclick="closeBatchConfirmModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p class="batch-confirm-tip" id="batchConfirmTip"></p>
            <ul class="batch-confirm-list" id="batchConfirmList"></ul>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeBatchConfirmModal()">取消</button>
                <button type="button" class="btn btn-primary" id="batchExecuteBtn">确认执行</button>
            </div>
        </div>
    </div>
</div>

<!-- 批量操作结果弹窗：逐条展示成功/失败 -->
<div class="modal" id="batchResultModal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>📋 处理结果</h3>
            <button class="modal-close" onclick="closeBatchResultModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p class="batch-result-summary" id="batchResultSummary"></p>
            <ul class="batch-result-list" id="batchResultList"></ul>
            <div class="form-actions">
                <button type="button" class="btn btn-primary" onclick="closeBatchResultModal()">完成</button>
            </div>
        </div>
    </div>
</div>

<script>
// 页面初始状态，供前端脚本使用
window.FAV_PAGE = {
    view: <?= json_encode($view) ?>,
    groupId: <?= $groupId ?: 0 ?>,
    type: <?= json_encode($type) ?>,
    isTrash: <?= $isTrash ? 'true' : 'false' ?>,
    groups: <?= json_encode(array_map(function ($g) {
        return ['id' => (int)$g['id'], 'name' => $g['name']];
    }, $groups), JSON_UNESCAPED_UNICODE) ?>
};
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
