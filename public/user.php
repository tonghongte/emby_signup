<?php
session_start();

if (!isset($_SESSION['emby_user_id']) || !isset($_SESSION['emby_token'])) {
    header("Location: login.php");
    exit;
}

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
// Attempt SSO login link (Emby Web UI might accept api_key or app_token parameter, though it's client dependent, api_key is safest bet for some clients, otherwise they just log in normally)
$emby_sso_url = "{$emby_url}/web/index.html#!/home.html?api_key={$emby_token}";

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
    <style>
        :root {
            --bg-color: #0f172a;
            --card-bg: rgba(30, 41, 59, 0.7);
            --primary: #52B54B; 
            --primary-hover: #43943d;
            --text-main: #f8fafc;
            --text-sub: #94a3b8;
            --input-bg: rgba(15, 23, 42, 0.6);
            --border-color: rgba(255, 255, 255, 0.1);
            --blur-amt: 16px;
            --danger: #ef4444;
            --warning: #f59e0b;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            min-height: 100vh;
            padding-bottom: 60px;
        }

        .bg-blob {
            position: fixed;
            border-radius: 50%;
            filter: blur(80px);
            z-index: -1;
            opacity: 0.4;
            animation: float 10s infinite ease-in-out;
        }
        .blob-1 { top: -10%; left: -10%; width: 500px; height: 500px; background: #4f46e5; animation-delay: 0s; }
        .blob-2 { bottom: -10%; right: -10%; width: 400px; height: 400px; background: #52B54B; animation-delay: -5s; }

        @keyframes float {
            0%, 100% { transform: translate(0, 0); }
            50% { transform: translate(30px, 50px); }
        }

        /* Navbar */
        .navbar {
            background: rgba(15, 23, 42, 0.8);
            backdrop-filter: blur(var(--blur-amt));
            border-bottom: 1px solid var(--border-color);
            padding: 16px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .nav-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 700;
            font-size: 18px;
            color: white;
            text-decoration: none;
        }
        .nav-brand img { width: 32px; height: 32px; border-radius: 8px; }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 24px;
        }

        .btn-emby {
            background: linear-gradient(135deg, var(--primary) 0%, #3d8c38 100%);
            color: white;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn-emby:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(82, 181, 75, 0.3);
        }
        .btn-portal {
            background: rgba(255,255,255,0.05); color: var(--text-sub); border: 1px solid rgba(255,255,255,0.1);
            padding: 8px 16px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px;
            display: flex; align-items: center; gap: 8px; transition: all 0.2s, box-shadow 0.2s;
        }
        .btn-portal:hover { transform: translateY(-2px); background: rgba(255,255,255,0.15); color: white; border-color: rgba(255,255,255,0.2); }

        .notification-bell {
            position: relative;
            cursor: pointer;
            color: var(--text-sub);
            transition: color 0.2s;
        }
        .notification-bell:hover { color: white; }
        .badge {
            position: absolute;
            top: -6px;
            right: -8px;
            background: var(--danger);
            color: white;
            font-size: 10px;
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 10px;
            display: <?php echo $unread_count > 0 ? 'block' : 'none'; ?>;
        }

        .logout-link { color: var(--text-sub); text-decoration: none; font-size: 14px; transition: color 0.2s; }
        .logout-link:hover { color: var(--danger); }

        /* Main Container */
        .container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px;
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
        }

        .card {
            background: var(--card-bg);
            backdrop-filter: blur(var(--blur-amt));
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }

        .card-header {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            color: white;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Search Section */
        .search-bar {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
        }
        .search-bar input {
            flex: 1;
            padding: 12px 16px;
            background: var(--input-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            color: white;
            font-size: 15px;
        }
        .search-bar input:focus {
            outline: none;
            border-color: var(--primary);
        }
        .search-bar button {
            background: var(--primary);
            color: white;
            border: none;
            padding: 0 24px;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        .search-bar button:hover { background: var(--primary-hover); }

        /* Posters Grid */
        .posters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 16px;
            max-height: 500px;
            overflow-y: auto;
            padding-right: 8px;
        }
        .posters-grid::-webkit-scrollbar { width: 6px; }
        .posters-grid::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 10px; }
        
        .poster-card {
            position: relative;
            border-radius: 12px;
            overflow: hidden;
            background: rgba(0,0,0,0.2);
            aspect-ratio: 2/3;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .poster-card:hover {
            transform: scale(1.05);
            box-shadow: 0 8px 24px rgba(0,0,0,0.5);
        }
        .poster-card img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .poster-info {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(transparent, rgba(0,0,0,0.9));
            padding: 12px 8px;
            transform: translateY(100%);
            transition: transform 0.2s;
        }
        .poster-card:hover .poster-info { transform: translateY(0); }
        .poster-title { font-size: 13px; font-weight: 600; color: white; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .poster-year { font-size: 11px; color: var(--text-sub); }

        /* Requests List */
        .requests-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            max-height: 600px;
            overflow-y: auto;
        }
        .requests-list::-webkit-scrollbar { width: 6px; }
        .requests-list::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 10px; }
        
        .request-item {
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 12px;
            padding: 12px;
            display: flex;
            gap: 12px;
        }
        .req-poster {
            width: 48px;
            height: 72px;
            border-radius: 6px;
            object-fit: cover;
            background: rgba(0,0,0,0.2);
        }
        .req-details { flex: 1; display: flex; flex-direction: column; justify-content: center; }
        .req-title { font-size: 14px; font-weight: 600; margin-bottom: 4px; }
        .req-date { font-size: 11px; color: var(--text-sub); }
        .status-badge {
            align-self: flex-start;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
        }
        .status-pending { background: rgba(245, 158, 11, 0.2); color: var(--warning); }
        .status-approved { background: rgba(82, 181, 75, 0.2); color: var(--primary); }
        .status-rejected { background: rgba(239, 68, 68, 0.2); color: var(--danger); }

        /* Modal */
        .modal-overlay {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.7); backdrop-filter: blur(8px); z-index: 1000;
            align-items: center; justify-content: center; padding: 20px;
        }
        .modal-content {
            background: #1e293b; padding: 24px; border-radius: 16px; max-width: 500px; width: 100%;
            border: 1px solid rgba(255,255,255,0.1); box-shadow: 0 25px 50px rgba(0,0,0,0.5);
            animation: zoomIn 0.2s ease-out;
        }
        .modal-header { font-size: 18px; font-weight: 600; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center;}
        .close-btn { background: transparent; border: none; color: var(--text-sub); cursor: pointer; font-size: 20px;}
        .close-btn:hover { color: white; }
        
        .notification-list { max-height: 400px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px;}
        .notification-item { background: rgba(255,255,255,0.05); padding: 12px; border-radius: 8px; }
        .notif-title { font-size: 14px; font-weight: 600; margin-bottom: 4px; color: white;}
        .notif-msg { font-size: 13px; color: var(--text-sub); line-height: 1.4;}
        .notif-date { font-size: 11px; color: rgba(255,255,255,0.3); margin-top: 8px;}
        .notif-unread { border-left: 3px solid var(--primary); }

        @keyframes zoomIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
        
        @media (max-width: 768px) {
            .container { grid-template-columns: 1fr; }
            .navbar { padding: 16px 20px; }
            .nav-brand span { display: none; }
        }
    </style>
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
                <span class="badge" id="notif-badge"><?php echo $unread_count; ?></span>
            </div>
            <a href="login.php?action=logout" class="logout-link">退出</a>
        </div>
    </nav>

    <div class="container">
        <!-- Left Column: Search & Request -->
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
                <!-- Results will be injected here -->
                <div style="grid-column: 1/-1; text-align:center; color:rgba(255,255,255,0.2); padding: 40px 0;">搜索结果将显示在这里</div>
            </div>
        </div>

        <!-- Right Column: My Requests -->
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

    <!-- Notifications Modal -->
    <div class="modal-overlay" id="notif-modal">
        <div class="modal-content">
            <div class="modal-header">
                站内通知
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

    <!-- Request Confirm Modal -->
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

    <script>
        let currentRequestData = null;

        async function searchTMDB() {
            const query = document.getElementById('search-input').value.trim();
            if (!query) return;
            
            const resultsDiv = document.getElementById('search-results');
            const loadingDiv = document.getElementById('loading');
            const errorDiv = document.getElementById('error-msg');
            
            resultsDiv.innerHTML = '';
            errorDiv.style.display = 'none';
            loadingDiv.style.display = 'block';

            try {
                const response = await fetch(`api_tmdb.php?q=${encodeURIComponent(query)}`);
                const data = await response.json();
                
                if (data.error) throw new Error(data.error);

                loadingDiv.style.display = 'none';
                
                if (!data.results || data.results.length === 0) {
                    resultsDiv.innerHTML = '<div style="grid-column: 1/-1; text-align:center; color:rgba(255,255,255,0.3);">未找到相关结果</div>';
                    return;
                }

                data.results.forEach(item => {
                    if (item.media_type !== 'movie' && item.media_type !== 'tv') return;
                    
                    const title = item.title || item.name;
                    const date = item.release_date || item.first_air_date || '';
                    const year = date ? date.substring(0, 4) : '';
                    const poster = item.poster_path ? `https://image.tmdb.org/t/p/w200${item.poster_path}` : '';
                    const posterDisplay = poster || 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIzMDAiIGhlaWdodD0iNDUwIj48cmVjdCB3aWR0aD0iMzAwIiBoZWlnaHQ9IjQ1MCIgZmlsbD0iIzIyMiIvPjwvc3ZnPg==';

                    const card = document.createElement('div');
                    card.className = 'poster-card';
                    card.innerHTML = `
                        <img src="${posterDisplay}" alt="${title}" loading="lazy">
                        <div class="poster-info">
                            <div class="poster-title">${title}</div>
                            <div class="poster-year">${year} ${item.media_type === 'tv' ? '(剧集)' : ''}</div>
                        </div>
                    `;
                    
                    card.onclick = () => {
                        currentRequestData = {
                            tmdb_id: item.id,
                            title: title + (year ? ` (${year})` : ''),
                            poster_url: poster,
                            media_type: item.media_type
                        };
                        document.getElementById('confirm-title').innerText = currentRequestData.title;
                        document.getElementById('confirm-poster').src = posterDisplay;
                        document.getElementById('confirm-date-val').innerText = date || '未知日期';
                        document.getElementById('confirm-rating-val').innerText = item.vote_average ? parseFloat(item.vote_average).toFixed(1) : '暂无评分';
                        document.getElementById('confirm-overview').innerText = item.overview || '暂无简介内容。';
                        document.getElementById('confirm-modal').style.display = 'flex';
                    };
                    
                    resultsDiv.appendChild(card);
                });

            } catch (err) {
                loadingDiv.style.display = 'none';
                errorDiv.innerText = err.message;
                errorDiv.style.display = 'block';
            }
        }

        document.getElementById('submit-req-btn').onclick = async () => {
            if (!currentRequestData) return;
            const btn = document.getElementById('submit-req-btn');
            btn.disabled = true;
            btn.innerText = '提交中...';

            try {
                const formData = new URLSearchParams();
                formData.append('action', 'submit_request');
                formData.append('tmdb_id', currentRequestData.tmdb_id);
                formData.append('title', currentRequestData.title);
                formData.append('poster_url', currentRequestData.poster_url);
                formData.append('media_type', currentRequestData.media_type);

                const response = await fetch('api_user.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: formData.toString()
                });
                
                const data = await response.json();
                alert(data.message);
                if (data.status === 'success') location.reload();
            } catch (e) {
                alert('提交出错');
            } finally {
                btn.disabled = false;
                btn.innerText = '确认提交';
                document.getElementById('confirm-modal').style.display = 'none';
            }
        };

        function openNotifications() {
            document.getElementById('notif-modal').style.display = 'flex';
            const badge = document.getElementById('notif-badge');
            if (badge.style.display !== 'none') {
                // Mark as read
                fetch('api_user.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=mark_read'
                });
                badge.style.display = 'none';
                document.querySelectorAll('.notif-unread').forEach(el => el.classList.remove('notif-unread'));
            }
        }

        function closeNotifications() {
            document.getElementById('notif-modal').style.display = 'none';
        }

        async function saveEmailPref(enabled) {
            try {
                const formData = new URLSearchParams();
                formData.append('action', 'save_email_pref');
                formData.append('enabled', enabled ? '1' : '0');

                const response = await fetch('api_user.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: formData.toString()
                });
                const data = await response.json();
                if (data.status !== 'success') {
                    alert('保存设置失败');
                }
            } catch (e) {
                alert('网络错误');
            }
        }

        // Close modals on outside click
        window.onclick = function(event) {
            if (event.target == document.getElementById('notif-modal')) closeNotifications();
            if (event.target == document.getElementById('confirm-modal')) document.getElementById('confirm-modal').style.display = 'none';
        }
    </script>
</body>
</html>
