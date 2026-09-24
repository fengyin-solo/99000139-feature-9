/**
 * 收藏页脚本：收藏分组 + 批量整理
 * 依赖 main.js 中的 showToast
 */
(function () {
    'use strict';

    const GROUPS = Array.isArray(window.FAV_GROUPS) ? window.FAV_GROUPS : [];
    const VIEW = window.FAV_VIEW || { type: '', gid: null };

    let pendingAction = null;     // {action, groupId}
    let pendingItems = [];        // [{messageId, title, checked}]
    let createGroupForMove = false; // 新建分组后是否把已勾选留言移入

    /* ================= 工具方法 ================= */

    function getCheckedIds() {
        return Array.from(document.querySelectorAll('.favorite-checkbox:checked'))
            .map(cb => parseInt(cb.value, 10));
    }

    function getCard(messageId) {
        return document.querySelector('.message-card[data-message-id="' + messageId + '"]');
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    function groupName(groupId) {
        if (!groupId) return '未分组';
        const g = GROUPS.find(x => x.id === groupId);
        return g ? g.name : '未分组';
    }

    /* ================= 勾选与工具栏 ================= */

    window.onFavoriteCheckChange = function () {
        const ids = getCheckedIds();
        const countEl = document.getElementById('selectedCount');
        if (countEl) countEl.textContent = ids.length;

        const selectAll = document.getElementById('selectAllFavorites');
        if (selectAll) {
            const total = document.querySelectorAll('.favorite-checkbox').length;
            selectAll.checked = total > 0 && ids.length === total;
            selectAll.indeterminate = ids.length > 0 && ids.length < total;
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        const selectAll = document.getElementById('selectAllFavorites');
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                document.querySelectorAll('.favorite-checkbox').forEach(cb => {
                    cb.checked = selectAll.checked;
                });
                onFavoriteCheckChange();
            });
        }

        // 点击其他区域关闭下拉菜单
        document.addEventListener('click', function (e) {
            if (!e.target.closest('.batch-dropdown')) {
                document.querySelectorAll('.batch-dropdown.open').forEach(d => d.classList.remove('open'));
            }
        });
        document.querySelectorAll('.batch-trigger').forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                const dropdown = btn.closest('.batch-dropdown');
                const wasOpen = dropdown.classList.contains('open');
                document.querySelectorAll('.batch-dropdown.open').forEach(d => d.classList.remove('open'));
                if (!wasOpen && getCheckedIds().length === 0) {
                    showToast('请先勾选要整理的留言', 'warning');
                    return;
                }
                dropdown.classList.toggle('open', !wasOpen);
            });
        });

        // 弹窗遮罩点击关闭
        ['groupCreateModal', 'groupManageModal', 'batchConfirmModal', 'batchResultModal'].forEach(id => {
            const modal = document.getElementById(id);
            if (modal) {
                modal.addEventListener('click', function (e) {
                    if (e.target === modal) {
                        modal.style.display = 'none';
                    }
                });
            }
        });

        // 新建分组输入框回车提交
        const nameInput = document.getElementById('newGroupName');
        if (nameInput) {
            nameInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    submitGroupCreate();
                }
            });
        }
        const manageInput = document.getElementById('manageGroupName');
        if (manageInput) {
            manageInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    submitGroupRename();
                }
            });
        }
    });

    /* ================= 新建分组 ================= */

    window.openGroupCreateModal = function (moveSelected) {
        createGroupForMove = !!moveSelected;
        const modal = document.getElementById('groupCreateModal');
        const input = document.getElementById('newGroupName');
        if (input) input.value = '';
        modal.style.display = 'flex';
        setTimeout(() => input && input.focus(), 50);
        document.querySelectorAll('.batch-dropdown.open').forEach(d => d.classList.remove('open'));
    };

    window.closeGroupCreateModal = function () {
        const modal = document.getElementById('groupCreateModal');
        if (modal) modal.style.display = 'none';
    };

    window.submitGroupCreate = function () {
        const input = document.getElementById('newGroupName');
        const btn = document.getElementById('groupCreateBtn');
        const name = input ? input.value.trim() : '';

        if (!name) {
            showToast('请输入分组名称', 'warning');
            return;
        }

        btn.disabled = true;
        btn.textContent = '创建中...';

        const formData = new FormData();
        formData.append('action', 'create');
        formData.append('name', name);

        fetch('api/favorite_group.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(res => {
                if (res.code === 0) {
                    showToast(res.msg, 'success');
                    const group = res.data.group;
                    GROUPS.push(group);
                    addGroupToSidebar(group);
                    addGroupToMenu(group);
                    closeGroupCreateModal();
                    refreshSummary();
                    // 若从“新建分组并移入”进入，自动把已勾选留言移入新分组（走逐条确认）
                    if (createGroupForMove && getCheckedIds().length > 0) {
                        confirmBatch('move', group.id);
                    }
                } else {
                    showToast(res.msg || '创建失败', 'error');
                }
            })
            .catch(() => showToast('网络错误，请稍后重试', 'error'))
            .finally(() => {
                btn.disabled = false;
                btn.textContent = '创建分组';
            });
    };

    function addGroupToSidebar(group) {
        const list = document.querySelector('.group-list');
        if (!list || document.querySelector('.group-item[data-group-id="' + group.id + '"]')) return;

        const li = document.createElement('li');
        li.innerHTML =
            '<a href="favorites.php?gid=' + group.id + '" class="group-item" data-group-id="' + group.id + '">' +
            '  <span class="group-name">🗂 ' + escapeHtml(group.name) + '</span>' +
            '  <span class="group-meta">' +
            '    <span class="group-count" data-group-count="' + group.id + '">0</span>' +
            '    <button type="button" class="group-manage-btn" title="管理分组">⋯</button>' +
            '  </span>' +
            '</a>';
        const manageBtn = li.querySelector('.group-manage-btn');
        manageBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            openGroupManageModal(group.id, group.name);
        });
        list.appendChild(li);
    }

    function addGroupToMenu(group) {
        const menu = document.querySelector('.batch-menu');
        if (!menu) return;
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'batch-menu-item';
        btn.textContent = '🗂 ' + group.name;
        btn.addEventListener('click', function () { confirmBatch('move', group.id); });

        const divider = menu.querySelector('.batch-menu-divider:last-of-type') ||
                        menu.querySelector('.batch-menu-new');
        if (divider) {
            menu.insertBefore(btn, divider);
        } else {
            menu.appendChild(btn);
        }
    }

    /* ================= 分组重命名/删除 ================= */

    window.openGroupManageModal = function (groupId, name) {
        document.getElementById('manageGroupId').value = groupId;
        const nameInput = document.getElementById('manageGroupName');
        nameInput.value = name || '';
        document.getElementById('groupManageModal').style.display = 'flex';
        setTimeout(() => nameInput.focus(), 50);
    };

    window.closeGroupManageModal = function () {
        const modal = document.getElementById('groupManageModal');
        if (modal) modal.style.display = 'none';
    };

    window.submitGroupRename = function () {
        const groupId = parseInt(document.getElementById('manageGroupId').value, 10);
        const name = document.getElementById('manageGroupName').value.trim();
        if (!name) {
            showToast('分组名称不能为空', 'warning');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'rename');
        formData.append('group_id', groupId);
        formData.append('name', name);

        fetch('api/favorite_group.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(res => {
                if (res.code === 0) {
                    showToast(res.msg, 'success');
                    const g = GROUPS.find(x => x.id === groupId);
                    const newName = res.data.group.name;
                    if (g) g.name = newName;
                    // 同步侧边栏名称
                    const nameEl = document.querySelector('.group-item[data-group-id="' + groupId + '"] .group-name');
                    if (nameEl) nameEl.textContent = '🗂 ' + newName;
                    // 同步卡片角标
                    document.querySelectorAll('[data-group-badge="' + groupId + '"]').forEach(el => {
                        el.textContent = '🗂 ' + newName;
                    });
                    closeGroupManageModal();
                } else {
                    showToast(res.msg || '重命名失败', 'error');
                }
            })
            .catch(() => showToast('网络错误，请稍后重试', 'error'));
    };

    window.submitGroupDelete = function () {
        const groupId = parseInt(document.getElementById('manageGroupId').value, 10);
        if (!confirm('删除分组后，组内收藏将全部移回「未分组」，确定删除吗？')) return;

        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('group_id', groupId);

        fetch('api/favorite_group.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(res => {
                if (res.code === 0) {
                    showToast(res.msg, 'success');
                    // 若当前正停留在被删分组视图，跳转至未分组视图；否则直接移除侧边栏项
                    if (VIEW.gid === groupId) {
                        window.location.href = 'favorites.php?gid=ungrouped';
                        return;
                    }
                    const item = document.querySelector('.group-item[data-group-id="' + groupId + '"]');
                    if (item && item.closest('li')) item.closest('li').remove();
                    // 卡片角标回到未分组
                    document.querySelectorAll('[data-group-badge="' + groupId + '"]').forEach(el => {
                        el.textContent = '📂 未分组';
                        el.setAttribute('data-group-badge', '0');
                        el.classList.add('card-group-none');
                    });
                    const idx = GROUPS.findIndex(x => x.id === groupId);
                    if (idx >= 0) GROUPS.splice(idx, 1);
                    closeGroupManageModal();
                    refreshSummary();
                } else {
                    showToast(res.msg || '删除失败', 'error');
                }
            })
            .catch(() => showToast('网络错误，请稍后重试', 'error'));
    };

    /* ================= 批量操作：逐条确认 ================= */

    const ACTION_TEXT = {
        move: '移入分组',
        remove: '取消收藏',
        restore: '恢复收藏'
    };

    window.confirmBatch = function (action, groupId) {
        const ids = getCheckedIds();
        if (ids.length === 0) {
            showToast('请先勾选要整理的留言', 'warning');
            return;
        }

        // 收集选中项的标题信息（从当前卡片读取）
        pendingItems = ids.map(id => {
            const card = getCard(id);
            const titleEl = card ? card.querySelector('.card-title') : null;
            return {
                messageId: id,
                title: titleEl ? titleEl.textContent.trim() : '留言#' + id,
                checked: true
            };
        });
        pendingAction = { action: action, groupId: groupId === undefined ? null : groupId };

        let desc;
        if (action === 'move') {
            desc = '将选中留言移入「' + escapeHtml(groupName(groupId)) + '」，请逐条确认后执行：';
        } else if (action === 'remove') {
            desc = '取消选中留言的收藏（成功项不会因个别失败而回滚），可在结果中逐条恢复：';
        } else {
            desc = '恢复选中的留言到收藏：';
        }
        document.getElementById('batchConfirmDesc').textContent = desc;
        renderConfirmList();
        document.getElementById('batchConfirmModal').style.display = 'flex';
    };

    function renderConfirmList() {
        const list = document.getElementById('batchConfirmList');
        list.innerHTML = '';
        pendingItems.forEach((item, idx) => {
            const li = document.createElement('li');
            li.className = 'batch-confirm-item';
            li.innerHTML =
                '<label class="batch-item-check">' +
                '  <input type="checkbox" ' + (item.checked ? 'checked' : '') + ' data-idx="' + idx + '">' +
                '  <span class="batch-item-title">' + escapeHtml(item.title) + '</span>' +
                '  <span class="batch-item-id">#' + item.messageId + '</span>' +
                '</label>';
            list.appendChild(li);
        });
        list.querySelectorAll('input[type="checkbox"]').forEach(cb => {
            cb.addEventListener('change', function () {
                pendingItems[parseInt(this.dataset.idx, 10)].checked = this.checked;
                updateConfirmCount();
            });
        });
        updateConfirmCount();

        const all = document.getElementById('batchConfirmAll');
        if (all) all.checked = pendingItems.length > 0 && pendingItems.every(i => i.checked);
    }

    window.toggleBatchConfirmAll = function (checkbox) {
        pendingItems.forEach(item => { item.checked = checkbox.checked; });
        document.querySelectorAll('#batchConfirmList input[type="checkbox"]').forEach(cb => {
            cb.checked = checkbox.checked;
        });
        updateConfirmCount();
    };

    function updateConfirmCount() {
        const count = pendingItems.filter(i => i.checked).length;
        document.getElementById('batchConfirmCount').textContent = count;
        const btn = document.getElementById('batchConfirmBtn');
        btn.disabled = count === 0;
    }

    window.closeBatchConfirmModal = function () {
        document.getElementById('batchConfirmModal').style.display = 'none';
    };

    window.executeBatch = function () {
        const items = pendingItems.filter(i => i.checked);
        if (items.length === 0) {
            showToast('请至少勾选一条留言', 'warning');
            return;
        }

        const btn = document.getElementById('batchConfirmBtn');
        btn.disabled = true;
        btn.textContent = '处理中...';

        const formData = new FormData();
        formData.append('action', pendingAction.action);
        items.forEach(item => formData.append('message_ids[]', item.messageId));
        if (pendingAction.groupId !== null && pendingAction.groupId !== undefined) {
            formData.append('group_id', pendingAction.groupId);
        }

        fetch('api/favorite_batch.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(res => {
                if (!res.data || (res.code !== 0 && res.code !== 2)) {
                    showToast(res.msg || '操作失败', 'error');
                    return;
                }
                closeBatchConfirmModal();
                renderBatchResult(res.data);
                applyBatchResult(res.data);
            })
            .catch(() => showToast('网络错误，请稍后重试', 'error'))
            .finally(() => {
                btn.disabled = false;
                btn.textContent = '确认执行';
            });
    };

    /* ================= 批量操作结果：逐条展示 ================= */

    function renderBatchResult(data) {
        const actionText = ACTION_TEXT[data.action] || '操作';
        document.getElementById('batchResultTitle').textContent = actionText + '结果';

        const summary = document.getElementById('batchResultSummary');
        summary.className = 'batch-result-summary ' + (data.failed > 0 ? 'has-failed' : 'all-success');
        let summaryHtml = '共处理 <strong>' + (data.success + data.failed) + '</strong> 条，' +
            '成功 <strong class="result-success-num">' + data.success + '</strong> 条';
        if (data.failed > 0) {
            summaryHtml += '，失败 <strong class="result-failed-num">' + data.failed + '</strong> 条（失败原因见下方明细，成功项不受影响、不会回滚）';
        }
        summary.innerHTML = summaryHtml;

        const list = document.getElementById('batchResultList');
        list.innerHTML = '';
        data.results.forEach(item => {
            const li = document.createElement('li');
            li.className = 'batch-result-item ' + (item.ok ? 'is-ok' : 'is-fail');

            const statusBadge = item.ok
                ? '<span class="result-status result-ok">✓ 成功</span>'
                : '<span class="result-status result-fail">✕ 失败</span>';

            let actionHtml = '';
            // 取消收藏成功的条目提供“恢复”（逐条恢复）
            if (data.action === 'remove' && item.ok) {
                actionHtml = '<button type="button" class="btn btn-sm btn-secondary result-restore-btn" ' +
                    'data-message-id="' + item.message_id + '">↩ 恢复收藏</button>';
            }

            li.innerHTML =
                '<div class="result-item-main">' +
                '  <span class="batch-item-title">' + escapeHtml(item.title) + '</span>' +
                '  <span class="batch-item-id">#' + item.message_id + '</span>' +
                statusBadge +
                '  <span class="result-msg">' + escapeHtml(item.msg || '') + '</span>' +
                '</div>' +
                '<div class="result-item-actions">' + actionHtml + '</div>';
            list.appendChild(li);
        });

        list.querySelectorAll('.result-restore-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                restoreOne(parseInt(this.dataset.messageId, 10), this);
            });
        });

        document.getElementById('batchResultModal').style.display = 'flex';
    }

    window.closeBatchResultModal = function () {
        document.getElementById('batchResultModal').style.display = 'none';
        // 列表在最后一页被清空时跳转刷新，避免停留空页
        const remainingCards = document.querySelectorAll('.message-card').length;
        const hasList = document.querySelector('.message-list');
        if (hasList && remainingCards === 0) {
            window.location.reload();
        }
    };

    /* ================= 逐条恢复 ================= */

    function restoreOne(messageId, btn) {
        btn.disabled = true;
        btn.textContent = '恢复中...';

        const formData = new FormData();
        formData.append('action', 'restore');
        formData.append('message_ids[]', messageId);

        fetch('api/favorite_batch.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(res => {
                if (!res.data) {
                    showToast(res.msg || '恢复失败', 'error');
                    btn.disabled = false;
                    btn.textContent = '↩ 恢复收藏';
                    return;
                }
                const item = res.data.results[0];
                if (item && item.ok) {
                    showToast('已恢复：' + item.title, 'success');
                    btn.textContent = '✓ 已恢复';
                    btn.classList.remove('btn-secondary');
                    btn.classList.add('btn-success');
                    btn.disabled = true;
                    applySummary(res.data.summary);
                } else {
                    showToast((item ? item.msg : '恢复失败'), 'error');
                    btn.disabled = false;
                    btn.textContent = '↩ 恢复收藏';
                }
            })
            .catch(() => {
                showToast('网络错误，请稍后重试', 'error');
                btn.disabled = false;
                btn.textContent = '↩ 恢复收藏';
            });
    }

    /* ================= 结果应用：同步列表、统计、分组视图 ================= */

    function applyBatchResult(data) {
        data.results.forEach(item => {
            if (!item.ok) return;
            const card = getCard(item.message_id);

            if (data.action === 'remove') {
                removeCardAnimated(card);
            } else if (data.action === 'move') {
                // 移入的分组不属于当前视图时，卡片立即从列表移除；属于当前视图则更新角标
                const targetGid = data.group_id ? data.group_id : 0;
                const inCurrentView = VIEW.gid === null || VIEW.gid === targetGid;
                if (inCurrentView) {
                    updateCardGroup(card, data.group_id);
                } else {
                    removeCardAnimated(card);
                }
            }
        });

        // 清空勾选状态
        document.querySelectorAll('.favorite-checkbox').forEach(cb => { cb.checked = false; });
        onFavoriteCheckChange();

        applySummary(data.summary);
    }

    function removeCardAnimated(card) {
        if (!card) return;
        card.style.transition = 'all 0.3s ease';
        card.style.opacity = '0';
        card.style.transform = 'translateX(-100px)';
        setTimeout(() => card.remove(), 300);
    }

    function updateCardGroup(card, groupId) {
        if (!card) return;
        const badge = card.querySelector('[data-group-badge]');
        if (!badge) return;
        if (groupId) {
            badge.textContent = '🗂 ' + groupName(groupId);
            badge.setAttribute('data-group-badge', String(groupId));
            badge.classList.remove('card-group-none');
        } else {
            badge.textContent = '📂 未分组';
            badge.setAttribute('data-group-badge', '0');
            badge.classList.add('card-group-none');
        }
    }

    /* ================= 汇总数据同步 ================= */

    function applySummary(summary) {
        if (!summary) return;

        const numbers = document.querySelectorAll('.favorites-stats .stat-number');
        const values = [summary.total, summary.help_count, summary.suggest_count, summary.lost_count];
        numbers.forEach((el, i) => {
            if (values[i] !== undefined) el.textContent = values[i];
        });

        const subtitle = document.querySelector('.page-subtitle');
        if (subtitle) {
            subtitle.textContent = '共收藏 ' + summary.total + ' 条留言';
        }

        // 侧边栏数量
        const allCounters = document.querySelectorAll('.group-list .group-count');
        if (allCounters[0]) allCounters[0].textContent = summary.total;
        if (allCounters[1]) allCounters[1].textContent = summary.ungrouped_count;

        (summary.groups || []).forEach(g => {
            const el = document.querySelector('[data-group-count="' + g.id + '"]');
            if (el) el.textContent = g.item_count;
        });
    }

    window.refreshSummary = function () {
        fetch('api/favorite_batch.php?action=summary')
            .then(r => r.json())
            .then(res => {
                if (res.code === 0 && res.data) applySummary(res.data);
            })
            .catch(() => {});
    };

    /* ================= 单条取消收藏后的统计同步 ================= */
    // main.js 的 toggleFavorite 提供全局回调钩子
    window.onFavoriteToggled = function (payload) {
        if (!payload || payload.favorited) return;
        // 单条取消后用服务端权威数据刷新统计与分组数量
        refreshSummary();
    };
})();
