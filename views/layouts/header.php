<?php
// header.php
require_once __DIR__ . '/../../includes/auth.php';

// Check if user is logged in
if (!isLoggedIn() && basename($_SERVER['PHP_SELF']) != 'login.php') {
    header('Location: /pos-system/views/auth/login.php');
    exit();
}

$currentUser = getCurrentUser();
$pageTitle = $pageTitle ?? 'Dashboard';
$normalizedSelf = isset($_SERVER['PHP_SELF']) ? str_replace('\\', '/', $_SERVER['PHP_SELF']) : '';
$isDashboardIndexPage = $normalizedSelf !== '' && str_ends_with($normalizedSelf, '/views/dashboard/index.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - ' : ''; ?>POS System</title>
    
    <!-- CSS Files -->
    <link rel="stylesheet" href="/pos-system/assets/css/style.css">
    <link rel="stylesheet" href="/pos-system/assets/css/dashboard.css">
    <link rel="stylesheet" href="/pos-system/assets/css/responsive.css">
    
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <!-- QR Scanner Libraries -->
    <script src="/pos-system/assets/js/jsqr.js"></script>
    <script src="https://rawgit.com/schmich/instascan-builds/master/instascan.min.js"></script>
    
    <style>
        /* Additional header styles */
        .header {
            background: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .header-left {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }
        
        .menu-toggle {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #4e73df;
            cursor: pointer;
            display: none;
        }
        
        .header-left h2 {
            margin: 0;
            color: #2b2d42;
            font-size: 1.5rem;
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }
        
        .user-dropdown {
            position: relative;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            cursor: pointer;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            transition: background 0.3s;
        }
        
        .user-dropdown:hover {
            background: #f8f9fc;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .user-name {
            font-weight: 600;
            color: #2b2d42;
        }
        
        .dropdown-menu {
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            border-radius: 8px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
            min-width: 200px;
            display: none;
            z-index: 1000;
            margin-top: 0.5rem;
        }
        
        .dropdown-menu.show {
            display: block;
        }
        
        .dropdown-menu a {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            padding: 0.8rem 1.2rem;
            color: #2b2d42;
            text-decoration: none;
            transition: background 0.3s;
        }
        
        .dropdown-menu a:hover {
            background: #f8f9fc;
        }
        
        .dropdown-menu a i {
            width: 20px;
            color: #4e73df;
        }
        
        .dropdown-menu hr {
            margin: 0.5rem 0;
            border: none;
            border-top: 1px solid #e9ecef;
        }

        .notification-icon {
            position: relative;
            width: 42px;
            height: 42px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #334155;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .notification-icon:hover {
            background: #eef2ff;
            color: #4e73df;
        }

        .notification-icon .notif-badge {
            position: absolute;
            top: -3px;
            right: -1px;
            min-width: 18px;
            height: 18px;
            border-radius: 999px;
            background: #ef4444;
            color: #fff;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 700;
            padding: 0 5px;
            line-height: 1;
        }

        .global-chat-panel {
            position: fixed;
            top: 72px;
            right: 18px;
            width: 360px;
            max-width: calc(100vw - 24px);
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 16px 45px rgba(15, 23, 42, 0.18);
            border: 1px solid #e5e7eb;
            display: none;
            flex-direction: column;
            overflow: hidden;
            z-index: 1100;
        }

        .global-chat-panel.show {
            display: flex;
        }

        .global-chat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 14px;
            background: linear-gradient(135deg, #4e73df, #1d4ed8);
            color: #fff;
            font-weight: 600;
        }

        .global-chat-close {
            background: transparent;
            border: none;
            color: #fff;
            font-size: 18px;
            cursor: pointer;
        }

        .global-chat-list {
            max-height: 360px;
            overflow-y: auto;
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            background: #f8fafc;
        }

        .global-chat-item {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 9px 10px;
            background: #fff;
        }

        .global-chat-item.unread {
            border-color: #4e73df;
            background: #eef2ff;
        }

        .global-chat-meta {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 4px;
            font-size: 11px;
            color: #64748b;
        }

        .global-chat-text {
            font-size: 13px;
            color: #0f172a;
            white-space: pre-wrap;
        }

        .global-chat-empty {
            text-align: center;
            color: #64748b;
            font-size: 13px;
            padding: 14px 8px;
        }

        .global-chat-form {
            border-top: 1px solid #e5e7eb;
            padding: 12px;
            display: grid;
            gap: 8px;
            background: #fff;
        }

        .global-chat-form-row {
            display: grid;
            grid-template-columns: 1fr 110px;
            gap: 8px;
        }

        .global-chat-toast {
            position: fixed;
            top: 84px;
            right: 18px;
            background: #111827;
            color: #fff;
            padding: 10px 14px;
            border-radius: 10px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.2);
            z-index: 1200;
            font-size: 13px;
        }
        
        @media (max-width: 768px) {
            .menu-toggle {
                display: block;
            }
            
            .user-name {
                display: none;
            }

            .global-chat-panel {
                right: 12px;
                left: 12px;
                width: auto;
                max-width: none;
            }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- Sidebar will be included here -->
        <?php include 'sidebar.php'; ?>
        
        <div class="main-content">
            <header class="header">
                <div class="header-left">
                    <button class="menu-toggle" onclick="toggleSidebar()">
                        <i class="fas fa-bars"></i>
                    </button>
                    <h2><?php echo $pageTitle ?? 'Dashboard'; ?></h2>
                </div>
                
                <div class="header-right">
                    <!-- Notifications (optional) -->
                    <div class="notification-icon chat-notifications" title="Group Chat" aria-label="Group Chat">
                        <i class="fas fa-comments"></i>
                    </div>
                    <?php if (isAdmin() && $isDashboardIndexPage): ?>
                    <div class="notification-icon approval-notifications" title="Balance Approvals" aria-label="Balance Approvals">
                        <i class="fas fa-bell"></i>
                    </div>
                    <?php endif; ?>
                    
                    <!-- User Dropdown -->
                    <div class="user-dropdown" id="userDropdown">
                        <?php 
                        $avatarPath = '/pos-system/assets/images/placeholder/avatar.png';
                        $userName = $_SESSION['user_name'] ?? $_SESSION['username'] ?? 'Guest';
                        $userRole = $_SESSION['user_role'] ?? 'guest';
                        
                        // Try to get user avatar if exists
                        if (isset($_SESSION['user_id']) && file_exists($_SERVER['DOCUMENT_ROOT'] . "/pos-system/assets/uploads/avatars/user_{$_SESSION['user_id']}.jpg")) {
                            $avatarPath = "/pos-system/assets/uploads/avatars/user_{$_SESSION['user_id']}.jpg";
                        }
                        ?>
                        <img src="<?php echo $avatarPath; ?>" alt="User" class="user-avatar">
                        <span class="user-name"><?php echo htmlspecialchars($userName); ?></span>
                        <i class="fas fa-chevron-down"></i>
                        
                        <div class="dropdown-menu" id="userMenu">
                            <div style="padding: 1rem; border-bottom: 1px solid #e9ecef;">
                                <strong><?php echo htmlspecialchars($userName); ?></strong><br>
                                <small style="color: #6c757d;">
                                    <?php 
                                    $roleLabels = [
                                        'admin' => 'Administrator',
                                        'manager' => 'Store Manager',
                                        'cashier' => 'Cashier',
                                        'guest' => 'Guest'
                                    ];
                                    echo $roleLabels[$userRole] ?? ucfirst($userRole);
                                    ?>
                                </small>
                            </div>
                            <a href="/pos-system/views/profile.php"><i class="fas fa-user"></i> Profile</a>
                            <?php if (isAdmin()): ?>
                            <a href="/pos-system/views/settings/index.php"><i class="fas fa-cog"></i> Settings</a>
                            <?php endif; ?>
                            <hr>
                            <a href="/pos-system/index.php" style="color: #e74a3b;">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </a>
                        </div>
                    </div>
                </div>
            </header>
            
            <div class="content">
                <!-- Main content area starts here -->
                <!-- The rest of the page content will go here -->
            <script>
                (function () {
                    if (window.POSGlobalChat) return;

                    const currentUser = {
                        id: <?php echo (int)($_SESSION['user_id'] ?? 0); ?>,
                        role: <?php echo json_encode($_SESSION['user_role'] ?? 'guest'); ?>,
                        name: <?php echo json_encode($_SESSION['user_name'] ?? $_SESSION['username'] ?? 'User'); ?>
                    };

                    const state = {
                        panel: null,
                        listEl: null,
                        messageEl: null,
                        targetEl: null,
                        visible: false,
                        pollTimer: null,
                        lastNotifId: 0
                    };

                    function escapeHtml(text) {
                        const div = document.createElement('div');
                        div.textContent = text == null ? '' : String(text);
                        return div.innerHTML;
                    }

                    function showToast(message) {
                        const toast = document.createElement('div');
                        toast.className = 'global-chat-toast';
                        toast.textContent = message;
                        document.body.appendChild(toast);
                        setTimeout(() => {
                            if (toast.parentNode) toast.parentNode.removeChild(toast);
                        }, 2600);
                    }

                    function ensurePanel() {
                        if (state.panel) return;

                        const panel = document.createElement('div');
                        panel.id = 'global-chat-panel';
                        panel.className = 'global-chat-panel';
                        panel.innerHTML = `
                            <div class="global-chat-header">
                                <span>Team Chat</span>
                                <button class="global-chat-close" type="button" aria-label="Close">&times;</button>
                            </div>
                            <div class="global-chat-list" id="global-chat-list">
                                <div class="global-chat-empty">Loading messages...</div>
                            </div>
                            <form class="global-chat-form" id="global-chat-form">
                                <div class="global-chat-form-row">
                                    <input type="text" id="global-chat-message" class="form-control" placeholder="Type a message" maxlength="200" required>
                                    <select id="global-chat-target" class="form-control">
                                        <option value="admin">Admin</option>
                                        <option value="manager">Manager</option>
                                        <option value="cashier">Cashier</option>
                                        <option value="all">Everyone</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <i class="fas fa-paper-plane mr-1"></i> Send
                                </button>
                            </form>
                        `;

                        document.body.appendChild(panel);
                        state.panel = panel;
                        state.listEl = panel.querySelector('#global-chat-list');
                        state.messageEl = panel.querySelector('#global-chat-message');
                        state.targetEl = panel.querySelector('#global-chat-target');

                        if (state.targetEl) {
                            state.targetEl.value = currentUser.role === 'admin' ? 'cashier' : 'admin';
                        }

                        panel.querySelector('.global-chat-close').addEventListener('click', hidePanel);
                        panel.querySelector('#global-chat-form').addEventListener('submit', async (event) => {
                            event.preventDefault();
                            await sendMessage();
                        });
                    }

                    function renderMessages(list) {
                        if (!state.listEl) return;
                        if (!Array.isArray(list) || !list.length) {
                            state.listEl.innerHTML = '<div class="global-chat-empty">No messages yet.</div>';
                            return;
                        }

                        state.listEl.innerHTML = list.map((item) => {
                            const unread = !item.is_read && (item.target_role === currentUser.role || item.target_role === 'all');
                            const time = item.created_at ? new Date(item.created_at.replace(' ', 'T')).toLocaleString() : '';
                            const sender = item.sender_name || item.sender_role || 'User';
                            const target = item.target_role === 'all' ? 'Everyone' : item.target_role;
                            return `
                                <div class="global-chat-item ${unread ? 'unread' : ''}">
                                    <div class="global-chat-meta">
                                        <span>${escapeHtml(sender)} -> ${escapeHtml(target)}</span>
                                        <span>${escapeHtml(time)}</span>
                                    </div>
                                    <div class="global-chat-text">${escapeHtml(item.message || '')}</div>
                                </div>
                            `;
                        }).join('');
                    }

                    function updateBadge(count) {
                        const bell = document.querySelector('.chat-notifications');
                        if (!bell) return;

                        let badge = bell.querySelector('.notif-badge');
                        if (!badge) {
                            badge = document.createElement('span');
                            badge.className = 'notif-badge';
                            bell.appendChild(badge);
                        }

                        badge.textContent = count > 99 ? '99+' : String(count);
                        badge.style.display = count > 0 ? 'inline-flex' : 'none';
                    }

                    async function fetchMessages(showToastOnNew) {
                        try {
                            const response = await fetch('/pos-system/api/notifications/fetch.php', { cache: 'no-store' });
                            const data = await response.json();
                            if (!data.success) return;

                            const list = Array.isArray(data.data) ? data.data : [];
                            renderMessages(list);

                            const unreadCount = list.filter(item => !item.is_read && (item.target_role === currentUser.role || item.target_role === 'all')).length;
                            updateBadge(unreadCount);

                            if (list.length && Number(list[0].id) > state.lastNotifId) {
                                if (showToastOnNew && !state.visible) {
                                    const from = list[0].sender_name || list[0].sender_role || 'Someone';
                                    showToast(`New chat from ${from}`);
                                }
                                state.lastNotifId = Number(list[0].id);
                            }
                        } catch (error) {
                            console.warn('Global chat fetch failed', error);
                        }
                    }

                    async function markRead() {
                        try {
                            await fetch('/pos-system/api/notifications/mark-read.php', { method: 'POST' });
                        } catch (error) {
                            console.warn('Global chat mark-read failed', error);
                        }
                    }

                    async function sendMessage() {
                        if (!state.messageEl || !state.targetEl) return;
                        const message = state.messageEl.value.trim();
                        if (!message) {
                            state.messageEl.focus();
                            return;
                        }

                        try {
                            const response = await fetch('/pos-system/api/notifications/send.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({
                                    message,
                                    target_role: state.targetEl.value
                                })
                            });
                            const data = await response.json();
                            if (!data.success) {
                                showToast(data.message || 'Unable to send message');
                                return;
                            }
                            state.messageEl.value = '';
                            await fetchMessages(false);
                        } catch (error) {
                            console.error('Global chat send failed', error);
                            showToast('Unable to send message');
                        }
                    }

                    function togglePanel() {
                        ensurePanel();
                        state.visible = !state.visible;
                        state.panel.classList.toggle('show', state.visible);
                        if (state.visible) {
                            fetchMessages(false);
                            markRead();
                            if (state.messageEl) state.messageEl.focus();
                        }
                    }

                    function hidePanel() {
                        state.visible = false;
                        if (state.panel) state.panel.classList.remove('show');
                    }

                    function startPolling() {
                        if (state.pollTimer) {
                            clearInterval(state.pollTimer);
                        }
                        state.pollTimer = setInterval(() => fetchMessages(true), 15000);
                    }

                    function init() {
                        ensurePanel();
                        const bell = document.querySelector('.chat-notifications');
                        if (bell) {
                            bell.addEventListener('click', togglePanel);
                        }
                        fetchMessages(false);
                        startPolling();
                    }

                    window.POSGlobalChat = {
                        init,
                        fetchMessages,
                        togglePanel,
                        hidePanel
                    };

                    document.addEventListener('DOMContentLoaded', init, { once: true });
                })();
            </script>
