<?php
require_once __DIR__ . '/../src/Auth.php';
Auth::requireUser();

$config_path = __DIR__ . '/../config/config.php';
$db_file_path = __DIR__ . '/../config/database.php';

if (!file_exists($config_path) || !file_exists($db_file_path)) {
    die("系统配置错误");
}

$config = require $config_path;
require_once $db_file_path;
global $invite_db;

$user_id = $_SESSION['emby_user_id'];
$username = $_SESSION['emby_username'];
$emby_token = $_SESSION['emby_token'];

$base_url = rtrim($config['emby']['base_url'], '/');
$emby_url = !empty($config['site']['login_url']) ? rtrim($config['site']['login_url'], '/') : $base_url;
$emby_sso_url = $emby_url;

// Fetch initial data
$requests = $invite_db->getUserRequests($user_id);
$notifications = $invite_db->getUserNotifications($user_id);
$unread_count = $invite_db->getUnreadNotificationCount($user_id);

$global_user_email_enabled = $config['notification']['enable_user_email_notify'] ?? false;
$user_email_pref = $invite_db->getUserEmailPreference($user_id);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>用户中心 - Emby 媒体库</title>
    <link rel="icon" type="image/png" href="https://emby.media/favicon-32x32.png" sizes="32x32">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- 引入系统通用与用户专有样式 -->
    <link rel="stylesheet" href="assets/css/common.css">
    <link rel="stylesheet" href="assets/css/user.css">

    <!-- 注入安全全局变量与客户端交互逻辑 -->
    <script>
        window.AppConfig = {
            csrfToken: <?php echo json_encode(Auth::csrfToken()); ?>
        };
    </script>
    <script src="assets/js/common.js"></script>
    <script src="assets/js/user.js"></script>
</head>
<body>
    <div class="bg-blob blob-1"></div>
    <div class="bg-blob blob-2"></div>

    <nav class="navbar">
        <a href="#" class="nav-brand">
            <img src="https://emby.media/favicon-96x96.png" alt="Emby">
            <span>用户门户</span>
        </a>
        <div class="nav-links">
            <a href="<?php echo htmlspecialchars($emby_sso_url); ?>" target="_blank" class="btn-emby">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                进入 Emby
            </a>
            <?php if (!empty($_SESSION['admin_logged_in'])): ?>
            <a href="admin.php" class="btn-portal">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
                返回管理后台
            </a>
            <?php endif; ?>
            <div class="notification-bell" onclick="openNotifications()">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                <span class="badge" id="notif-badge" style="<?php echo $unread_count > 0 ? '' : 'display:none;'; ?>"><?php echo $unread_count; ?></span>
            </div>
            <a href="login.php?action=logout" class="logout-link">退出</a>
        </div>
    </nav>

    <div class="container">
        <!-- 左侧栏：TMDB求片搜索与选择 -->
        <div class="card">
            <div class="card-header">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                搜索与求片
            </div>
            <div class="search-bar">
                <input type="text" id="search-input" placeholder="输入电影或剧集名称..." onkeypress="if(event.key === 'Enter') searchTMDB()">
                <button onclick="searchTMDB()">搜索</button>
            </div>
            
            <div id="loading" style="display:none; text-align:center; padding: 20px; color:var(--text-sub);">搜索中...</div>
            <div id="error-msg" style="display:none; color:var(--danger); text-align:center; padding: 10px;"></div>
            
            <div class="posters-grid" id="search-results">
                <div style="grid-column: 1/-1; text-align:center; color:rgba(255,255,255,0.2); padding: 40px 0;">搜索结果将显示在这里</div>
            </div>
        </div>

        <!-- 右侧栏：求片状态与偏好 -->
        <div class="card">
            <div class="card-header" style="justify-content: space-between;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    我的求片
                </div>
                <div style="display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: normal; color: var(--text-sub);">
                    <input type="checkbox" id="user-email-pref" <?php echo $user_email_pref ? 'checked' : ''; ?> <?php echo !$global_user_email_enabled ? 'disabled' : ''; ?> onchange="saveEmailPref(this.checked)">
                    <label for="user-email-pref" style="<?php echo !$global_user_email_enabled ? 'opacity:0.5;' : ''; ?> cursor:pointer;">
                        接收处理结果邮件
                        <?php if(!$global_user_email_enabled) echo '(管理员未开启)'; ?>
                    </label>
                </div>
            </div>
            <div class="requests-list" id="my-requests">
                <?php if (empty($requests)): ?>
                    <div style="text-align:center; color:rgba(255,255,255,0.2); padding: 20px 0;">暂无求片记录</div>
                <?php else: ?>
                    <?php foreach ($requests as $req): 
                        $status_class = 'status-pending'; $status_text = '待处理';
                        if ($req['status'] === 'approved') { $status_class = 'status-approved'; $status_text = '已批准'; }
                        if ($req['status'] === 'rejected') { $status_class = 'status-rejected'; $status_text = '已拒绝'; }
                        $poster = $req['poster_url'] ?: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAiIGhlaWdodD0iMTUwIj48cmVjdCB3aWR0aD0iMTAwIiBoZWlnaHQ9IjE1MCIgZmlsbD0iIzIyMiIvPjwvc3ZnPg==';
                    ?>
                    <div class="request-item">
                        <img src="<?php echo htmlspecialchars($poster); ?>" class="req-poster" alt="poster">
                        <div class="req-details">
                            <div class="req-title"><?php echo htmlspecialchars($req['title']); ?></div>
                            <div class="req-date"><?php echo date('Y-m-d', strtotime($req['created_at'])); ?></div>
                        </div>
                        <div class="status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 站内通知模态框 -->
    <div class="modal-overlay" id="notif-modal">
        <div class="modal-content">
            <div class="modal-header">
                <div style="display:flex; align-items:center; gap:12px;">
                    <span>站内通知</span>
                    <button class="btn-clear-notifs" onclick="clearNotifications()" style="background:transparent; border:1px solid rgba(255,255,255,0.15); color:var(--text-sub); font-size:11px; padding:3px 8px; border-radius:4px; cursor:pointer; transition:0.2s; font-weight:normal;" onmouseover="this.style.color='#ef4444'; this.style.borderColor='rgba(239,68,68,0.4)'; this.style.background='rgba(239,68,68,0.05)';" onmouseout="this.style.color='var(--text-sub)'; this.style.borderColor='rgba(255,255,255,0.15)'; this.style.background='transparent';">一键清空</button>
                </div>
                <button class="close-btn" onclick="closeNotifications()">×</button>
            </div>
            <div class="notification-list">
                <?php if (empty($notifications)): ?>
                    <div style="text-align:center; color:rgba(255,255,255,0.3); padding: 20px;">暂无通知</div>
                <?php else: ?>
                    <?php foreach ($notifications as $notif): ?>
                    <div class="notification-item <?php echo $notif['is_read'] ? '' : 'notif-unread'; ?>">
                        <div class="notif-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                        <div class="notif-msg"><?php echo nl2br(htmlspecialchars($notif['message'])); ?></div>
                        <div class="notif-date"><?php echo $notif['created_at']; ?></div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 详情求片确认模态框 -->
    <div class="modal-overlay" id="confirm-modal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header" style="color: white; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 16px; margin-bottom: 20px;">
                媒体详细信息
                <button class="close-btn" onclick="document.getElementById('confirm-modal').style.display='none'">×</button>
            </div>
            <div style="display: flex; gap: 24px; flex-wrap: wrap; margin-bottom: 24px;">
                <img id="confirm-poster" src="" style="width: 140px; height: 210px; object-fit: cover; border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,0.5); flex-shrink: 0;">
                <div style="flex: 1; min-width: 250px; display: flex; flex-direction: column; gap: 12px;">
                    <h2 id="confirm-title" style="color: white; font-size: 22px; line-height: 1.3;"></h2>
                    <div style="display: flex; gap: 16px; font-size: 14px; color: var(--text-sub);">
                        <span id="confirm-date" style="display: flex; align-items: center; gap: 4px;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                            <span id="confirm-date-val"></span>
                        </span>
                        <span id="confirm-rating" style="display: flex; align-items: center; gap: 4px; color: var(--warning);">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                            <span id="confirm-rating-val"></span>
                        </span>
                    </div>
                    <div id="confirm-overview" style="font-size: 14px; color: rgba(255,255,255,0.7); line-height: 1.6; max-height: 120px; overflow-y: auto; padding-right: 8px;"></div>
                </div>
            </div>
            <div style="display: flex; gap: 12px; justify-content: flex-end; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 20px;">
                <button onclick="document.getElementById('confirm-modal').style.display='none'" style="padding: 10px 24px; border-radius: 8px; border:none; background: rgba(255,255,255,0.1); color: white; cursor: pointer; transition: 0.2s;">取消</button>
                <button id="submit-req-btn" style="padding: 10px 24px; border-radius: 8px; border:none; background: var(--primary); color: white; cursor: pointer; font-weight: bold; transition: 0.2s;">确认求片</button>
            </div>
        </div>
    </div>
</body>
</html>
