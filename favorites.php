<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

$pageTitle = '我的收藏 - 社区便民留言板';
$currentPage = 'favorites';
$cssPath = 'assets/css/style.css';
$jsPath = 'assets/js/main.js';
$extraJs = 'assets/js/favorites.js';

$db = getDB();
ensureFavoriteGroupsSchema($db);

$visitorId = getVisitorId();

$type = $_GET['type'] ?? '';
if ($type && !in_array($type, ['help', 'suggest', 'lost'])) {
    $type = '';
}

// 分组视图：gid=ungrouped=未分组，正整数=具体分组，缺省=全部
$groupId = null;          // null: 全部; 0: 未分组; >0: 指定分组
$activeGroup = null;
$gidRaw = $_GET['gid'] ?? '';
if ($gidRaw === 'ungrouped') {
    $groupId = 0;
} elseif (intval($gidRaw) > 0) {
    $activeGroup = getFavoriteGroup(intval($gidRaw), $visitorId);
    $groupId = $activeGroup ? intval($gidRaw) : null;
}

$page = max(1, intval($_GET['page'] ?? 1));
$pageSize = 10;
$offset = ($page - 1) * $pageSize;

$where = "WHERE f.visitor_id = ? AND m.status = 1";
$params = [$visitorId];

if ($type) {
    $where .= " AND m.type = ?";
    $params[] = $type;
}
if ($groupId === 0) {
    $where .= " AND f.group_id IS NULL";
} elseif ($groupId !== null) {
    $where .= " AND f.group_id = ?";
    $params[] = $groupId;
}

$countSql = "SELECT COUNT(*) FROM favorites f INNER JOIN messages m ON f.message_id = m.id $where";
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$total = intval($countStmt->fetchColumn());
$totalPages = ceil($total / $pageSize);

$sql = "SELECT m.id, m.nickname, m.type, m.title, m.content, m.image, m.views, m.created_at,
               f.created_at as favorited_at, f.group_id, g.name AS group_name
        FROM favorites f
        INNER JOIN messages m ON f.message_id = m.id
        LEFT JOIN favorite_groups g ON f.group_id = g.id
        $where
        ORDER BY f.created_at DESC
        LIMIT $pageSize OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$favorites = $stmt->fetchAll();

// 全局统计（与刷新后口径一致：不受类型/分组筛选影响）
$stats = getFavoriteSummary($visitorId);
$groups = $stats['groups'];

// 当前视图的查询参数（供分页链接使用）
$viewParams = array_filter(['type' => $type]);
if ($groupId === 0) {
    $viewParams['gid'] = 'ungrouped';
} elseif ($groupId !== null) {
    $viewParams['gid'] = $groupId;
}
$viewQuery = http_build_query($viewParams);

// 构造收藏页视图链接
$favUrl = function (array $override = []) use ($type, $groupId) {
    $params = array_filter(['type' => $type]);
    if ($groupId === 0) {
        $params['gid'] = 'ungrouped';
    } elseif ($groupId !== null) {
        $params['gid'] = $groupId;
    }
    $params = array_merge($params, $override);
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    return 'favorites.php' . ($params ? '?' . http_build_query($params) : '');
};

include __DIR__ . '/includes/header.php';
?>
<script>
window.FAV_GROUPS = <?= json_encode(array_map(function ($g) {
    return ['id' => intval($g['id']), 'name' => $g['name']];
}, $groups), JSON_UNESCAPED_UNICODE) ?>;
window.FAV_VIEW = {
    type: <?= json_encode($type) ?>,
    gid: <?= $groupId === null ? 'null' : ($groupId === 0 ? '0' : $groupId) ?>
};
</script>

<section class="favorites-section">
    <div class="container">
        <div class="page-header">
            <h1 class="page-title">⭐ 我的收藏</h1>
            <p class="page-subtitle">共收藏 <?= $stats['total'] ?> 条留言</p>
        </div>

        <div class="favorites-stats">
            <div class="stat-card">
                <div class="stat-number"><?= $stats['total'] ?></div>
                <div class="stat-label">全部收藏</div>
            </div>
            <div class="stat-card stat-help">
                <div class="stat-number"><?= $stats['help_count'] ?></div>
                <div class="stat-label">🆘 求助</div>
            </div>
            <div class="stat-card stat-suggest">
                <div class="stat-number"><?= $stats['suggest_count'] ?></div>
                <div class="stat-label">💡 建议</div>
            </div>
            <div class="stat-card stat-lost">
                <div class="stat-number"><?= $stats['lost_count'] ?></div>
                <div class="stat-label">🔍 失物</div>
            </div>
        </div>
    </div>
</section>

<section class="message-list-section">
    <div class="container">
        <div class="favorites-layout">
            <!-- 分组侧边栏 -->
            <aside class="favorite-sidebar">
                <div class="sidebar-header">
                    <span class="sidebar-title">📁 收藏分组</span>
                    <button type="button" class="btn btn-sm btn-primary" onclick="openGroupCreateModal()">+ 新建分组</button>
                </div>
                <ul class="group-list">
                    <li>
                        <a href="<?= $favUrl(['gid' => null]) ?>"
                           class="group-item <?= $groupId === null ? 'active' : '' ?>">
                            <span class="group-name">⭐ 全部收藏</span>
                            <span class="group-count"><?= $stats['total'] ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="<?= $favUrl(['gid' => 'ungrouped']) ?>"
                           class="group-item <?= $groupId === 0 ? 'active' : '' ?>">
                            <span class="group-name">📂 未分组</span>
                            <span class="group-count"><?= $stats['ungrouped_count'] ?></span>
                        </a>
                    </li>
                    <?php foreach ($groups as $group): ?>
                    <li>
                        <a href="<?= $favUrl(['gid' => $group['id']]) ?>"
                           class="group-item <?= $groupId === intval($group['id']) ? 'active' : '' ?>"
                           data-group-id="<?= $group['id'] ?>">
                            <span class="group-name">🗂 <?= cleanInput($group['name']) ?></span>
                            <span class="group-meta">
                                <span class="group-count" data-group-count="<?= $group['id'] ?>"><?= intval($group['item_count']) ?></span>
                                <button type="button" class="group-manage-btn" title="管理分组"
                                        onclick="event.preventDefault(); event.stopPropagation(); openGroupManageModal(<?= $group['id'] ?>, <?= htmlspecialchars(json_encode($group['name'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)">⋯</button>
                            </span>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <p class="sidebar-tip">提示：分组关系会自动保存，刷新后依然有效。</p>
            </aside>

            <!-- 收藏主区域 -->
            <div class="favorite-main">
                <div class="filter-section">
                    <div class="filter-bar">
                        <div class="filter-types">
                            <a href="<?= $favUrl(['type' => '']) ?>"
                               class="filter-tag <?= !$type ? 'active' : '' ?>">全部</a>
                            <a href="<?= $favUrl(['type' => 'help']) ?>"
                               class="filter-tag <?= $type === 'help' ? 'active' : '' ?>">🆘 求助</a>
                            <a href="<?= $favUrl(['type' => 'suggest']) ?>"
                               class="filter-tag <?= $type === 'suggest' ? 'active' : '' ?>">💡 建议</a>
                            <a href="<?= $favUrl(['type' => 'lost']) ?>"
                               class="filter-tag <?= $type === 'lost' ? 'active' : '' ?>">🔍 失物招领</a>
                        </div>
                    </div>
                </div>

                <?php if (empty($favorites)): ?>
                <div class="empty-state">
                    <div class="empty-icon">⭐</div>
                    <p><?= $groupId === 0 ? '该筛选条件下暂无未分组的收藏' : ($groupId !== null ? '该分组下暂无收藏，勾选留言后可批量移入' : '暂无收藏的留言') ?></p>
                    <a href="index.php" class="btn btn-primary">去浏览留言</a>
                </div>
                <?php else: ?>
                <!-- 批量整理工具栏 -->
                <div class="batch-toolbar" id="batchToolbar">
                    <label class="select-all-label">
                        <input type="checkbox" id="selectAllFavorites"> 全选本页
                    </label>
                    <span class="batch-selected-count">已选 <strong id="selectedCount">0</strong> 条</span>
                    <div class="batch-actions">
                        <div class="batch-dropdown">
                            <button type="button" class="btn btn-sm btn-secondary batch-trigger">📁 移入分组 ▾</button>
                            <div class="batch-menu">
                                <button type="button" class="batch-menu-item" onclick="confirmBatch('move', null)">📂 移至未分组</button>
                                <div class="batch-menu-divider"></div>
                                <?php foreach ($groups as $group): ?>
                                <button type="button" class="batch-menu-item" onclick="confirmBatch('move', <?= $group['id'] ?>)">🗂 <?= cleanInput($group['name']) ?></button>
                                <?php endforeach; ?>
                                <div class="batch-menu-divider"></div>
                                <button type="button" class="batch-menu-item batch-menu-new" onclick="openGroupCreateModal(true)">＋ 新建分组并移入</button>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-danger" onclick="confirmBatch('remove')">批量取消收藏</button>
                    </div>
                </div>

                <div class="message-list">
                    <?php foreach ($favorites as $msg): ?>
                    <div class="message-card" data-message-id="<?= $msg['id'] ?>">
                        <label class="card-select" onclick="event.stopPropagation()">
                            <input type="checkbox" class="favorite-checkbox" value="<?= $msg['id'] ?>"
                                   onchange="onFavoriteCheckChange()">
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
                                <?php if ($msg['group_id']): ?>
                                <span class="card-group-badge" data-group-badge="<?= $msg['group_id'] ?>">🗂 <?= cleanInput($msg['group_name']) ?></span>
                                <?php else: ?>
                                <span class="card-group-badge card-group-none" data-group-badge="0">📂 未分组</span>
                                <?php endif; ?>
                            </div>
                        </a>
                        <button class="favorite-btn favorited" data-message-id="<?= $msg['id'] ?>" onclick="toggleFavorite(event, this)">
                            <span class="favorite-icon">⭐</span>
                            <span class="favorite-text">已收藏</span>
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php
                    $buildPageUrl = function ($p) use ($viewQuery) {
                        return 'favorites.php?' . ($viewQuery ? $viewQuery . '&' : '') . 'page=' . $p;
                    };
                    ?>
                    <?php if ($page > 1): ?>
                    <a href="<?= $buildPageUrl($page - 1) ?>" class="page-btn">上一页</a>
                    <?php endif; ?>
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <a href="<?= $buildPageUrl($i) ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                    <a href="<?= $buildPageUrl($page + 1) ?>" class="page-btn">下一页</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- 新建分组弹窗 -->
<div class="modal-overlay" id="groupCreateModal" style="display:none;">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3>新建收藏分组</h3>
            <button type="button" class="modal-close" onclick="closeGroupCreateModal()">✕</button>
        </div>
        <div class="modal-body">
            <p class="form-tip">分组名称不超过20个字符，创建后勾选的留言将自动移入新分组。</p>
            <input type="text" id="newGroupName" class="form-input" maxlength="20" placeholder="例如：邻里互助、宠物相关">
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeGroupCreateModal()">取消</button>
            <button type="button" class="btn btn-primary" id="groupCreateBtn" onclick="submitGroupCreate()">创建分组</button>
        </div>
    </div>
</div>

<!-- 分组管理弹窗（重命名/删除） -->
<div class="modal-overlay" id="groupManageModal" style="display:none;">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3>管理分组</h3>
            <button type="button" class="modal-close" onclick="closeGroupManageModal()">✕</button>
        </div>
        <div class="modal-body">
            <label class="form-label">分组名称</label>
            <input type="hidden" id="manageGroupId">
            <input type="text" id="manageGroupName" class="form-input" maxlength="20">
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-danger" onclick="submitGroupDelete()">删除分组</button>
            <span class="modal-footer-spacer"></span>
            <button type="button" class="btn btn-secondary" onclick="closeGroupManageModal()">取消</button>
            <button type="button" class="btn btn-primary" onclick="submitGroupRename()">保存</button>
        </div>
    </div>
</div>

<!-- 批量操作确认弹窗：逐条勾选确认 -->
<div class="modal-overlay" id="batchConfirmModal" style="display:none;">
    <div class="modal-dialog modal-lg">
        <div class="modal-header">
            <h3 id="batchConfirmTitle">确认批量操作</h3>
            <button type="button" class="modal-close" onclick="closeBatchConfirmModal()">✕</button>
        </div>
        <div class="modal-body">
            <p class="batch-confirm-desc" id="batchConfirmDesc"></p>
            <label class="select-all-label batch-confirm-selectall">
                <input type="checkbox" id="batchConfirmAll" checked onchange="toggleBatchConfirmAll(this)"> 全选
            </label>
            <ul class="batch-confirm-list" id="batchConfirmList"></ul>
        </div>
        <div class="modal-footer">
            <span class="batch-confirm-count">确认处理 <strong id="batchConfirmCount">0</strong> 条</span>
            <span class="modal-footer-spacer"></span>
            <button type="button" class="btn btn-secondary" onclick="closeBatchConfirmModal()">取消</button>
            <button type="button" class="btn btn-primary" id="batchConfirmBtn" onclick="executeBatch()">确认执行</button>
        </div>
    </div>
</div>

<!-- 批量操作结果弹窗：逐条展示成功/失败，失败项说明原因，成功项不回滚 -->
<div class="modal-overlay" id="batchResultModal" style="display:none;">
    <div class="modal-dialog modal-lg">
        <div class="modal-header">
            <h3 id="batchResultTitle">批量整理结果</h3>
            <button type="button" class="modal-close" onclick="closeBatchResultModal()">✕</button>
        </div>
        <div class="modal-body">
            <div class="batch-result-summary" id="batchResultSummary"></div>
            <ul class="batch-result-list" id="batchResultList"></ul>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-primary" onclick="closeBatchResultModal()">完成</button>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
