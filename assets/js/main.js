/**
 * 社区便民留言板 - 前端脚本
 */
document.addEventListener('DOMContentLoaded', function() {
    // 滚动信息复制实现无缝滚动
    const scrollContent = document.getElementById('scrollContent');
    if (scrollContent) {
        scrollContent.innerHTML += scrollContent.innerHTML;
    }
});

/* ============================================================
 * 通用工具
 * ============================================================ */

function escapeHtml(str) {
    return String(str == null ? '' : str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function favPost(data) {
    const formData = new FormData();
    Object.keys(data).forEach(function(k) {
        if (data[k] !== null && data[k] !== undefined) {
            formData.append(k, data[k]);
        }
    });
    return fetch('api/favorite.php', { method: 'POST', body: formData })
        .then(function(res) { return res.json(); });
}

/**
 * 显示提示消息
 */
function showToast(message, type = 'info') {
    const existingToast = document.querySelector('.toast-message');
    if (existingToast) {
        existingToast.remove();
    }

    const toast = document.createElement('div');
    toast.className = `toast-message toast-${type}`;
    toast.textContent = message;

    const styles = {
        position: 'fixed',
        top: '80px',
        left: '50%',
        transform: 'translateX(-50%)',
        padding: '12px 24px',
        borderRadius: '8px',
        color: '#fff',
        fontSize: '0.9rem',
        zIndex: '9999',
        boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
        animation: 'slideDown 0.3s ease',
        maxWidth: '90%',
        textAlign: 'center'
    };

    const typeColors = {
        success: 'background: #10b981',
        error: 'background: #ef4444',
        info: 'background: #3b82f6',
        warning: 'background: #f59e0b'
    };

    Object.assign(toast.style, styles);
    toast.style.cssText += ';' + (typeColors[type] || typeColors.info);

    const styleSheet = document.createElement('style');
    styleSheet.textContent = `
        @keyframes slideDown {
            from { opacity: 0; transform: translate(-50%, -20px); }
            to { opacity: 1; transform: translate(-50%, 0); }
        }
        @keyframes fadeOut {
            from { opacity: 1; }
            to { opacity: 0; }
        }
    `;
    document.head.appendChild(styleSheet);

    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.animation = 'fadeOut 0.3s ease forwards';
        setTimeout(() => {
            toast.remove();
        }, 300);
    }, 2000);
}

/* ============================================================
 * 单条收藏切换（首页 / 详情页 / 收藏页共用，原有入口）
 * ============================================================ */

function toggleFavorite(event, btn) {
    event.preventDefault();
    event.stopPropagation();

    const messageId = btn.dataset.messageId;
    if (!messageId) return;

    const icon = btn.querySelector('.favorite-icon');
    const text = btn.querySelector('.favorite-text');
    const originalIcon = icon.textContent;
    const originalText = text.textContent;
    const originalClass = btn.className;

    btn.disabled = true;

    favPost({ message_id: messageId, action: 'toggle' })
    .then(result => {
        if (result.code === 0) {
            const onFavoritesPage = window.location.pathname.includes('favorites.php');

            if (result.data.favorited) {
                btn.classList.add('favorited');
                btn.classList.remove('btn-secondary');
                btn.classList.add('btn-warning');
                icon.textContent = '⭐';
                text.textContent = '已收藏';
                showToast(result.msg, 'success');
            } else {
                btn.classList.remove('favorited');
                btn.classList.remove('btn-warning');
                btn.classList.add('btn-secondary');
                icon.textContent = '☆';
                text.textContent = '收藏';
                showToast(result.msg, 'info');

                if (onFavoritesPage) {
                    const card = btn.closest('.message-card');
                    if (card) {
                        card.style.transition = 'all 0.3s ease';
                        card.style.opacity = '0';
                        card.style.transform = 'translateX(-100px)';
                        setTimeout(() => {
                            card.remove();
                            checkEmptyState();
                        }, 300);
                    }
                }
            }

            // 收藏页任何单条操作后同步统计、侧栏数量
            if (onFavoritesPage && result.data.overview) {
                updateOverview(result.data.overview);
                if (result.data.groups) {
                    updateGroupCounts(result.data.groups);
                    window.FAV_PAGE.groups = result.data.groups.map(function(g) {
                        return { id: parseInt(g.id), name: g.name };
                    });
                }
            }
        } else {
            showToast(result.msg || '操作失败', 'error');
            icon.textContent = originalIcon;
            text.textContent = originalText;
            btn.className = originalClass;
        }
    })
    .catch(error => {
        console.error('收藏操作失败:', error);
        showToast('网络错误，请稍后重试', 'error');
        icon.textContent = originalIcon;
        text.textContent = originalText;
        btn.className = originalClass;
    })
    .finally(() => {
        btn.disabled = false;
    });
}

/* ============================================================
 * 收藏页：统计 / 侧栏同步
 * ============================================================ */

function updateOverview(overview) {
    if (!overview) return;

    document.querySelectorAll('#favoritesStats [data-stat]').forEach(function(el) {
        const key = el.getAttribute('data-stat');
        if (overview[key] !== undefined) {
            el.textContent = overview[key];
        }
    });

    document.querySelectorAll('.group-count[data-count]').forEach(function(el) {
        const key = el.getAttribute('data-count');
        if (overview[key] !== undefined) {
            el.textContent = overview[key];
        }
    });

    const subtitle = document.getElementById('pageSubtitle');
    if (subtitle && window.FAV_PAGE) {
        if (window.FAV_PAGE.isTrash) {
            subtitle.textContent = '回收站中共有 ' + overview.trash_count + ' 条已取消的收藏';
        } else {
            subtitle.textContent = `共收藏 ${overview.total} 条留言`;
        }
    }
}

function updateGroupCounts(groups) {
    if (!groups) return;
    const map = {};
    groups.forEach(function(g) { map[g.id] = g.count; });
    document.querySelectorAll('.group-row').forEach(function(row) {
        const id = row.getAttribute('data-group-id');
        const countEl = row.querySelector('.group-count');
        if (countEl && map[id] !== undefined) {
            countEl.textContent = map[id];
        }
    });
}

/**
 * 列表为空时：当前页非第一页则回退一页；第一页则就地渲染空状态
 */
function checkEmptyState() {
    const list = document.querySelector('#favoritesList');
    if (!list) return;

    if (list.querySelectorAll('.message-card').length === 0) {
        const m = location.search.match(/[?&]page=(\d+)/);
        const curPage = m ? parseInt(m[1]) : 1;
        if (curPage > 1) {
            // 回退一页（服务端会再次校正越界页码）
            const params = new URLSearchParams(location.search);
            params.set('page', curPage - 1);
            location.href = 'favorites.php?' + params.toString();
        } else {
            // 第一页已清空：就地显示空状态，隐藏工具栏
            const toolbar = document.getElementById('batchToolbar');
            if (toolbar) toolbar.style.display = 'none';
            const p = window.FAV_PAGE || {};
            const main = document.querySelector('.favorites-main');
            const emptyHtml = p.isTrash
                ? `<div class="empty-state"><div class="empty-icon">🗑️</div><p>回收站是空的</p><a href="favorites.php" class="btn btn-secondary">返回收藏</a></div>`
                : `<div class="empty-state"><div class="empty-icon">⭐</div><p>暂无收藏的留言</p><a href="index.php" class="btn btn-primary">去浏览留言</a></div>`;
            list.outerHTML = emptyHtml;
        }
    }
}

/* ============================================================
 * 收藏页：勾选与批量整理
 * ============================================================ */

const BATCH_META = {
    move_group: { title: '📁 移入分组', tip: '以下收藏将被移动到分组，逐条确认后执行：', btnClass: 'btn-primary', btnText: '确认移动' },
    cancel:      { title: '取消收藏', tip: '以下收藏将被取消并移入回收站（可恢复），逐条确认后执行：', btnClass: 'btn-warning', btnText: '确认取消' },
    restore:     { title: '♻️ 恢复收藏', tip: '以下收藏将被恢复到原分组，逐条确认后执行：', btnClass: 'btn-success', btnText: '确认恢复' },
    purge:       { title: '彻底删除', tip: '以下收藏将被彻底删除且无法恢复，逐条确认后执行：', btnClass: 'btn-danger', btnText: '确认彻底删除' },
    group_delete: { title: '🗑️ 删除分组', tip: '分组将被删除，组内以下收藏会自动变为「未分组」，不会丢失：', btnClass: 'btn-danger', btnText: '确认删除分组' }
};

let pendingBatch = null; // {action, ids, items:[{message_id,title}], extra:{}}

document.addEventListener('DOMContentLoaded', function() {
    if (!window.FAV_PAGE) {
        initReportForm();
        return;
    }
    initFavoritesPage();
    initReportForm();
});

function initFavoritesPage() {
    const toolbar = document.getElementById('batchToolbar');
    if (!toolbar) return;

    const checkboxes = document.querySelectorAll('.favorite-item-chk');
    const selectAll = document.getElementById('selectAllChk');

    checkboxes.forEach(function(chk) {
        chk.addEventListener('change', refreshSelection);
    });

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            checkboxes.forEach(function(chk) { chk.checked = selectAll.checked; });
            refreshSelection();
        });
    }

    document.getElementById('batchMoveBtn').addEventListener('click', openMoveGroupModal);
    const cancelBtn = document.getElementById('batchCancelBtn');    if (cancelBtn) cancelBtn.addEventListener('click', function() {
        prepareBatch('cancel', getSelectedIds());
    });
    const restoreBtn = document.getElementById('batchRestoreBtn');
    if (restoreBtn) restoreBtn.addEventListener('click', function() {
        prepareBatch('restore', getSelectedIds());
    });
    const purgeBtn = document.getElementById('batchPurgeBtn');
    if (purgeBtn) purgeBtn.addEventListener('click', function() {
        prepareBatch('purge', getSelectedIds());
    });
    const emptyTrashBtn = document.getElementById('emptyTrashBtn');
    if (emptyTrashBtn) emptyTrashBtn.addEventListener('click', function() {
        prepareScopeBatch('trash', null, 'purge');
    });

    // 整组 / 当前视图全部整理
    const scopeBtn = document.getElementById('scopeOrganizeBtn');
    if (scopeBtn) {
        scopeBtn.addEventListener('click', function() {
            const p = window.FAV_PAGE;
            prepareScopeBatch(p.view === 'all' ? 'all' : p.view, p.groupId || null, null);
        });
    }

    document.getElementById('batchExecuteBtn').addEventListener('click', executePendingBatch);

    initGroupModal();
    initMoveGroupModal();
    initSidebarGroupActions();
}

function getSelectedIds() {
    return Array.from(document.querySelectorAll('.favorite-item-chk:checked'))
        .map(function(chk) { return parseInt(chk.value); });
}

function refreshSelection() {
    const ids = getSelectedIds();
    const all = document.querySelectorAll('.favorite-item-chk');
    document.getElementById('selectedCount').textContent = ids.length;

    document.querySelectorAll('.favorite-item-chk').forEach(function(chk) {
        const card = chk.closest('.message-card');
        if (card) card.classList.toggle('selected-card', chk.checked);
    });

    const selectAll = document.getElementById('selectAllChk');
    if (selectAll) {
        selectAll.checked = all.length > 0 && ids.length === all.length;
        selectAll.indeterminate = ids.length > 0 && ids.length < all.length;
    }

    const has = ids.length > 0;
    ['batchMoveBtn', 'batchCancelBtn', 'batchRestoreBtn', 'batchPurgeBtn'].forEach(function(id) {
        const btn = document.getElementById(id);
        if (btn) btn.disabled = !has;
    });
}

/**
 * 收集选中项的卡片信息（标题），打开逐条确认弹窗
 */
function prepareBatch(action, ids, extra) {
    ids = (ids || []).filter(function(v) { return v > 0; });
    if (ids.length === 0) {
        showToast('请至少选择一条收藏', 'warning');
        return;
    }

    const items = ids.map(function(id) {
        const card = document.querySelector('.message-card[data-message-id="' + id + '"]');
        return {
            message_id: id,
            title: card ? card.getAttribute('data-title') : ('留言 #' + id),
            type: card ? card.getAttribute('data-type') : ''
        };
    });

    openBatchConfirm(action, ids, items, extra || {});
}

/**
 * 整组/整视图：从服务端取全部条目后逐条确认
 */
function prepareScopeBatch(scope, groupId, fixedAction) {
    const p = window.FAV_PAGE;
    const payload = { action: 'items', scope: scope, type: p.type || '' };
    if (scope === 'group') payload.group_id = groupId;

    favPost(payload).then(function(res) {
        if (res.code !== 0) {
            showToast(res.msg || '查询失败', 'error');
            return;
        }
        const items = res.data.items || [];
        if (items.length === 0) {
            showToast('当前范围内没有可整理的收藏', 'info');
            return;
        }
        const ids = items.map(function(it) { return parseInt(it.message_id); });
        if (fixedAction) {
            openBatchConfirm(fixedAction, ids, items, {});
        } else {
            openBatchConfirm(null, ids, items, { scope: scope });
        }
    }).catch(function() {
        showToast('网络错误，请稍后重试', 'error');
    });
}

/**
 * 打开逐条确认弹窗。action 为 null 时（整组整理）展示操作选择按钮
 */
function openBatchConfirm(action, ids, items, extra) {
    const modal = document.getElementById('batchConfirmModal');
    const titleEl = document.getElementById('batchConfirmTitle');
    const tipEl = document.getElementById('batchConfirmTip');
    const listEl = document.getElementById('batchConfirmList');
    const execBtn = document.getElementById('batchExecuteBtn');

    listEl.innerHTML = items.map(function(it, idx) {
        const icon = it.type ? ({ help: '🆘', suggest: '💡', lost: '🔍' })[it.type] || '📌' : '📌';
        return `
        <li class="batch-confirm-item">
            <label>
                <input type="checkbox" class="batch-confirm-chk" data-index="${idx}" checked>
                <span class="batch-item-icon">${icon}</span>
                <span class="batch-item-title">${escapeHtml(it.title)}</span>
                <span class="batch-item-id">#${it.message_id}</span>
            </label>
        </li>`;
    }).join('');

    if (action) {
        const meta = BATCH_META[action];
        titleEl.textContent = meta.title + `（${items.length} 条）`;
        tipEl.textContent = meta.tip;
        execBtn.textContent = meta.btnText;
        execBtn.className = 'btn ' + meta.btnClass;
        execBtn.style.display = '';
    } else {
        // 整组整理：逐条勾选 + 选择要执行的操作
        titleEl.textContent = `整组批量整理（${items.length} 条）`;
        tipEl.textContent = '请逐条确认要整理的留言（可取消勾选），再选择操作：';
        execBtn.style.display = 'none';
    }

    pendingBatch = { action: action, ids: ids, items: items, extra: extra || {} };

    // 整组整理的操作按钮
    let actionBar = document.getElementById('scopeActionBar');
    if (!actionBar) {
        actionBar = document.createElement('div');
        actionBar.id = 'scopeActionBar';
        actionBar.className = 'scope-action-bar';
        execBtn.parentNode.insertBefore(actionBar, execBtn);
    }
    if (action) {
        actionBar.style.display = 'none';
        actionBar.innerHTML = '';
    } else {
        actionBar.style.display = '';
        const inTrash = (extra.scope === 'trash') || window.FAV_PAGE.isTrash;
        actionBar.innerHTML = inTrash
            ? `<button type="button" class="btn btn-success" data-scope-action="restore">♻️ 批量恢复</button>
               <button type="button" class="btn btn-danger" data-scope-action="purge">彻底删除</button>`
            : `<button type="button" class="btn btn-primary" data-scope-action="move_group">📁 批量移入分组</button>
               <button type="button" class="btn btn-warning" data-scope-action="cancel">批量取消收藏</button>`;
        actionBar.querySelectorAll('button').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const chosen = btn.getAttribute('data-scope-action');
                const chosenItems = getConfirmedItems();
                if (chosenItems.length === 0) {
                    showToast('请至少勾选一条留言', 'warning');
                    return;
                }
                closeBatchConfirmModal();
                if (chosen === 'move_group') {
                    openMoveGroupModalWithItems(chosenItems);
                } else {
                    openBatchConfirm(chosen, chosenItems.map(function(i) { return i.message_id; }), chosenItems, {});
                }
            });
        });
    }

    modal.style.display = 'flex';
}

function getConfirmedItems() {
    if (!pendingBatch) return [];
    const checkedIndexes = Array.from(document.querySelectorAll('.batch-confirm-chk:checked'))
        .map(function(chk) { return parseInt(chk.getAttribute('data-index')); });
    return checkedIndexes.map(function(i) { return pendingBatch.items[i]; });
}

function closeBatchConfirmModal() {
    const modal = document.getElementById('batchConfirmModal');
    if (modal) modal.style.display = 'none';
    pendingBatch = null;
}

/**
 * 执行确认后的批量操作，逐条结果在结果弹窗中展示，成功项不回滚
 */
function executePendingBatch() {
    if (!pendingBatch) return;
    const batch = pendingBatch;

    const confirmed = getConfirmedItems();
    if (confirmed.length === 0) {
        showToast('请至少勾选一条留言', 'warning');
        return;
    }

    let payload;

    // 删除分组走专用接口，选中项仅用于逐条向用户展示组内成员
    if (batch.action === 'group_delete') {
        payload = { action: 'group_delete', group_id: batch.extra.group_id };
    } else {
        payload = {
            action: 'batch',
            batch_action: batch.action,
            message_ids: confirmed.map(function(i) { return i.message_id; })
        };
        if (batch.action === 'move_group') {
            if (batch.extra.target === 'ungrouped') {
                payload.target = 'ungrouped';
            } else {
                payload.group_id = batch.extra.group_id;
            }
        }
    }

    const execBtn = document.getElementById('batchExecuteBtn');
    execBtn.disabled = true;
    execBtn.textContent = '处理中...';

    favPost(payload).then(function(res) {
        if (res.code !== 0) {
            showToast(res.msg || '操作失败', 'error');
            return;
        }

        closeBatchConfirmModal();

        if (batch.action === 'group_delete') {
            showToast(res.msg, 'success');
            setTimeout(function() { location.reload(); }, 600);
            return;
        }

        showBatchResult(res.data, batch.action, batch.extra);
    }).catch(function() {
        showToast('网络错误，请稍后重试', 'error');
    }).finally(function() {
        execBtn.disabled = false;
        execBtn.textContent = '确认执行';
    });
}

/**
 * 结果弹窗：逐条列出成功/失败，失败项说明原因
 */
function showBatchResult(data, action, extra) {
    const modal = document.getElementById('batchResultModal');
    const summary = document.getElementById('batchResultSummary');
    const list = document.getElementById('batchResultList');

    summary.innerHTML = `共处理 <strong>${data.success + data.failed}</strong> 条：
        <span class="result-ok">成功 ${data.success}</span>
        ${data.failed > 0 ? `，<span class="result-fail">失败 ${data.failed}</span>` : ''}`;

    list.innerHTML = data.results.map(function(r) {
        const status = r.success
            ? '<span class="batch-result-status result-ok">✓ 成功</span>'
            : '<span class="batch-result-status result-fail">✗ 失败</span>';
        return `
        <li class="batch-result-item ${r.success ? 'is-ok' : 'is-fail'}">
            <div class="batch-result-head">
                <span class="batch-item-title">${escapeHtml(r.title || ('留言 #' + r.message_id))}</span>
                ${status}
            </div>
            <div class="batch-result-msg">${escapeHtml(r.msg)}</div>
        </li>`;
    }).join('');

    modal.style.display = 'flex';

    // 同步页面数据
    applyBatchResult(data, action, extra || {});
}

/**
 * 操作后同步更新：列表数量、分类统计、分组视图
 */
function applyBatchResult(data, action, extra) {
    if (data.overview) updateOverview(data.overview);
    if (data.groups) {
        updateGroupCounts(data.groups);
        window.FAV_PAGE.groups = data.groups.map(function(g) {
            return { id: parseInt(g.id), name: g.name };
        });
    }

    const p = window.FAV_PAGE;
    const successIds = {};
    (data.results || []).forEach(function(r) {
        if (r.success) successIds[r.message_id] = true;
    });

    Object.keys(successIds).forEach(function(id) {
        const card = document.querySelector('.message-card[data-message-id="' + id + '"]');
        if (!card) return;

        let remove = false;
        if (action === 'cancel' || action === 'purge') {
            remove = true;
        } else if (action === 'restore') {
            remove = p.isTrash; // 回收站视图中恢复后移出列表
        } else if (action === 'move_group') {
            if (p.view === 'ungrouped' && extra.target !== 'ungrouped') {
                remove = true;
            } else if (p.view === 'group') {
                const movedHere = extra.target !== 'ungrouped' && parseInt(extra.group_id) === p.groupId;
                remove = !movedHere;
            }
            if (!remove) {
                // 同视图内移动，更新分组标签
                const tag = card.querySelector('.card-group-tag');
                if (tag) {
                    if (extra.target === 'ungrouped') {
                        tag.textContent = '📂 未分组';
                    } else {
                        const g = (window.FAV_PAGE.groups || []).filter(function(x) {
                            return parseInt(x.id) === parseInt(extra.group_id);
                        })[0];
                        tag.textContent = '🗂️ ' + (g ? g.name : (extra.new_name || '当前分组'));
                    }
                }
            }
        }

        if (remove) {
            card.style.transition = 'all 0.3s ease';
            card.style.opacity = '0';
            card.style.transform = 'translateX(-60px)';
            setTimeout(function() { card.remove(); }, 300);
        }
    });

    // 列表若已清空，等用户看完逐条结果、关闭弹窗时再回退页码，避免打断结果确认
    setTimeout(function() {
        const list = document.querySelector('#favoritesList');
        if (list && list.querySelectorAll('.message-card').length === 0) {
            window.__favListEmptied = true;
        }
    }, 350);
}

function closeBatchResultModal() {
    const modal = document.getElementById('batchResultModal');
    if (modal) modal.style.display = 'none';

    // 重置勾选
    document.querySelectorAll('.favorite-item-chk').forEach(function(chk) { chk.checked = false; });
    const selectAll = document.getElementById('selectAllChk');
    if (selectAll) {
        selectAll.checked = false;
        selectAll.indeterminate = false;
    }
    refreshSelection();

    // 列表已被清空时回退页码（或第一页由服务端渲染空状态）
    if (window.__favListEmptied) {
        window.__favListEmptied = false;
        checkEmptyState();
    }
}

/**
 * 回收站单条恢复 / 彻底删除入口
 */
function singleBatchAction(event, action, ids) {
    event.preventDefault();
    event.stopPropagation();
    prepareBatch(action, ids);
}

/* ============================================================
 * 收藏页：分组新建 / 重命名 / 删除 / 移动
 * ============================================================ */

let groupModalMode = 'create';
let groupModalEditId = 0;

function initGroupModal() {
    document.getElementById('addGroupBtn').addEventListener('click', function() {
        openGroupModal('create');
    });
    document.getElementById('groupModalSubmit').addEventListener('click', submitGroupModal);
    document.getElementById('groupNameInput').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') submitGroupModal();
    });
}

function openGroupModal(mode, groupId, currentName) {
    groupModalMode = mode;
    groupModalEditId = groupId || 0;
    document.getElementById('groupModalTitle').textContent = mode === 'create' ? '新建分组' : '重命名分组';
    const input = document.getElementById('groupNameInput');
    input.value = currentName || '';
    document.getElementById('groupModal').style.display = 'flex';
    setTimeout(function() { input.focus(); }, 50);
}

function closeGroupModal() {
    document.getElementById('groupModal').style.display = 'none';
}

function submitGroupModal() {
    const name = document.getElementById('groupNameInput').value;
    if (!name.trim()) {
        showToast('请输入分组名称', 'warning');
        return;
    }
    const btn = document.getElementById('groupModalSubmit');
    btn.disabled = true;

    const payload = groupModalMode === 'create'
        ? { action: 'group_create', name: name }
        : { action: 'group_rename', group_id: groupModalEditId, name: name };

    favPost(payload).then(function(res) {
        btn.disabled = false;
        if (res.code !== 0) {
            showToast(res.msg || '操作失败', 'error');
            return;
        }
        closeGroupModal();
        showToast(res.msg, 'success');
        // 重命名就地更新；新建直接刷新页面以展示新分组
        if (groupModalMode === 'rename') {
            const row = document.querySelector('.group-row[data-group-id="' + groupModalEditId + '"]');
            if (row) {
                const nameEl = row.querySelector('.group-name');
                if (nameEl) nameEl.textContent = '🗂️ ' + name.trim();
                row.setAttribute('data-group-name', name.trim());
            }
            const tip = document.querySelector('.current-group-tip strong');
            if (tip && window.FAV_PAGE.groupId === groupModalEditId) {
                tip.textContent = '🗂️ ' + name.trim();
            }
        } else {
            setTimeout(function() { location.reload(); }, 500);
        }
    }).catch(function() {
        btn.disabled = false;
        showToast('网络错误，请稍后重试', 'error');
    });
}

function initSidebarGroupActions() {
    document.querySelectorAll('.group-manage-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const row = btn.closest('.group-row');
            const id = parseInt(row.getAttribute('data-group-id'));
            const name = row.getAttribute('data-group-name');
            const act = btn.getAttribute('data-group-action');
            if (act === 'rename') {
                openGroupModal('rename', id, name);
            } else {
                confirmDeleteGroup(id, name);
            }
        });
    });
}

/**
 * 删除分组前逐条列出组内收藏供确认
 */
function confirmDeleteGroup(groupId, groupName) {
    favPost({ action: 'items', scope: 'group', group_id: groupId, type: '' })
    .then(function(res) {
        if (res.code !== 0) {
            showToast(res.msg || '操作失败', 'error');
            return;
        }
        const items = res.data.items || [];
        if (items.length === 0) {
            // 空分组：无成员需展示，直接二次确认
            openBatchConfirm('group_delete', [], [], { group_id: groupId, group_name: groupName });
            document.getElementById('batchConfirmList').innerHTML =
                '<li class="batch-confirm-empty">该分组为空，删除后不影响任何收藏。</li>';
            document.getElementById('batchConfirmTitle').textContent = '删除分组「' + groupName + '」';
            document.getElementById('batchConfirmTip').textContent = BATCH_META.group_delete.tip;
            return;
        }
        openBatchConfirm('group_delete', items.map(function(i) { return i.message_id; }), items,
            { group_id: groupId, group_name: groupName });
        document.getElementById('batchConfirmTitle').textContent =
            '删除分组「' + groupName + '」（' + items.length + ' 条收藏）';
        // 组成员列表仅展示，不允许取消勾选（它们不会被删除）
        document.querySelectorAll('.batch-confirm-chk').forEach(function(chk) {
            chk.checked = true;
            chk.disabled = true;
        });
    });
}

/* ============================================================
 * 移入分组弹窗
 * ============================================================ */

function initMoveGroupModal() {
    document.getElementById('moveGroupConfirmBtn').addEventListener('click', function() {
        const selected = document.querySelector('input[name="moveTarget"]:checked');
        const newName = document.getElementById('moveNewGroupName').value.trim();
        const pendingItems = window.__moveItems || [];

        if (newName) {
            // 先建新分组，再移动
            const btn = this;
            btn.disabled = true;
            favPost({ action: 'group_create', name: newName }).then(function(res) {
                btn.disabled = false;
                if (res.code !== 0) {
                    showToast(res.msg || '分组创建失败', 'error');
                    return;
                }
                const moveItems = pendingItems;
                closeMoveGroupModal();
                openBatchConfirm('move_group', moveItems.map(function(i) { return i.message_id; }), moveItems,
                    { group_id: res.data.group_id, new_name: newName });
            }).catch(function() {
                btn.disabled = false;
                showToast('网络错误，请稍后重试', 'error');
            });
            return;
        }

        if (!selected) {
            showToast('请选择目标分组或输入新分组名称', 'warning');
            return;
        }
        const moveItems = pendingItems;
        closeMoveGroupModal();
        if (selected.value === 'ungrouped') {
            openBatchConfirm('move_group', moveItems.map(function(i) { return i.message_id; }), moveItems,
                { target: 'ungrouped' });
        } else {
            openBatchConfirm('move_group', moveItems.map(function(i) { return i.message_id; }), moveItems,
                { group_id: parseInt(selected.value) });
        }
    });
}

function openMoveGroupModal() {
    const ids = getSelectedIds();
    if (ids.length === 0) {
        showToast('请先勾选要移动的收藏', 'warning');
        return;
    }
    const items = ids.map(function(id) {
        const card = document.querySelector('.message-card[data-message-id="' + id + '"]');
        return {
            message_id: id,
            title: card ? card.getAttribute('data-title') : ('留言 #' + id),
            type: card ? card.getAttribute('data-type') : ''
        };
    });
    openMoveGroupModalWithItems(items);
}

function openMoveGroupModalWithItems(items) {
    window.__moveItems = items;
    const box = document.getElementById('moveGroupOptions');
    const currentGroupId = window.FAV_PAGE.view === 'group' ? window.FAV_PAGE.groupId : 0;

    let html = `
        <label class="move-group-option">
            <input type="radio" name="moveTarget" value="ungrouped">
            <span>📂 未分组</span>
        </label>`;
    (window.FAV_PAGE.groups || []).forEach(function(g) {
        if (parseInt(g.id) === parseInt(currentGroupId)) return;
        html += `
        <label class="move-group-option">
            <input type="radio" name="moveTarget" value="${g.id}">
            <span>🗂️ ${escapeHtml(g.name)}</span>
        </label>`;
    });
    box.innerHTML = html;
    document.getElementById('moveNewGroupName').value = '';
    document.getElementById('moveGroupModal').style.display = 'flex';
}

function closeMoveGroupModal() {
    document.getElementById('moveGroupModal').style.display = 'none';
    window.__moveItems = null;
}

document.addEventListener('DOMContentLoaded', function() {
    // 点击遮罩关闭弹窗
    document.querySelectorAll('.modal').forEach(function(modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                if (modal.id === 'groupModal') closeGroupModal();
                if (modal.id === 'moveGroupModal') closeMoveGroupModal();
                if (modal.id === 'batchConfirmModal') closeBatchConfirmModal();
                if (modal.id === 'batchResultModal') closeBatchResultModal();
                if (modal.id === 'reportModal' && typeof closeReportModal === 'function') closeReportModal();
            }
        });
    });
});

/* ============================================================
 * 举报功能
 * ============================================================ */

/**
 * 打开举报弹窗
 */
function openReportModal(messageId) {
    const modal = document.getElementById('reportModal');
    if (!modal) return;

    document.getElementById('reportMessageId').value = messageId;
    document.getElementById('reportForm').reset();
    document.getElementById('reportDescCount').textContent = '0';
    modal.style.display = 'flex';
}

/**
 * 关闭举报弹窗
 */
function closeReportModal() {
    const modal = document.getElementById('reportModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

/**
 * 初始化举报表单
 */
function initReportForm() {
    const form = document.getElementById('reportForm');
    if (!form) return;

    const descInput = document.getElementById('reportDescription');
    const descCount = document.getElementById('reportDescCount');

    if (descInput && descCount) {
        descInput.addEventListener('input', function() {
            descCount.textContent = this.value.length;
        });
    }

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        submitReport();
    });
}

/**
 * 提交举报
 */
function submitReport() {
    const form = document.getElementById('reportForm');
    if (!form) return;

    const submitBtn = document.getElementById('reportSubmitBtn');
    const messageId = document.getElementById('reportMessageId').value;
    const reportType = form.querySelector('input[name="report_type"]:checked');
    const description = document.getElementById('reportDescription').value;

    if (!reportType) {
        showToast('请选择举报类型', 'warning');
        return;
    }

    submitBtn.disabled = true;
    submitBtn.textContent = '提交中...';

    const formData = new FormData();
    formData.append('message_id', messageId);
    formData.append('report_type', reportType.value);
    formData.append('description', description);
    formData.append('action', 'submit');

    fetch('api/report.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(result => {
        if (result.code === 0) {
            showToast(result.msg, 'success');
            closeReportModal();

            const reportBtn = document.querySelector('.report-btn[data-message-id="' + messageId + '"]');
            if (reportBtn) {
                reportBtn.disabled = true;
                reportBtn.classList.remove('btn-danger');
                reportBtn.classList.add('btn-secondary');
                const reportText = reportBtn.querySelector('.report-text');
                if (reportText) {
                    reportText.textContent = '已举报';
                }
            }
        } else {
            showToast(result.msg || '举报失败', 'error');
        }
    })
    .catch(error => {
        console.error('举报提交失败:', error);
        showToast('网络错误，请稍后重试', 'error');
    })
    .finally(() => {
        submitBtn.disabled = false;
        submitBtn.textContent = '提交举报';
    });
}
