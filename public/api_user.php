<?php
require_once __DIR__ . '/../src/Auth.php';
Auth::initSession();

if (!Auth::checkUser()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db_file_path = __DIR__ . '/../config/database.php';
if (!file_exists($db_file_path)) {
    http_response_code(500);
    echo json_encode(['error' => 'Database configuration not found']);
    exit;
}
require_once $db_file_path;
global $invite_db; // Instance created in database.php

// 强制校验 CSRF Token，消灭跨站伪造隐患
if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'CSRF 验证失败，请刷新页面重试。']);
    exit;
}

$action = $_POST['action'] ?? '';
$user_id = $_SESSION['emby_user_id'];
$username = $_SESSION['emby_username'];

header('Content-Type: application/json');

if ($action === 'submit_request') {
    $tmdb_id = (int)($_POST['tmdb_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $poster_url = trim($_POST['poster_url'] ?? '');
    $media_type = trim($_POST['media_type'] ?? 'movie');

    if (!$tmdb_id || empty($title)) {
        echo json_encode(['status' => 'error', 'message' => '参数错误']);
        exit;
    }

    // Check if user already requested this
    $existing = $invite_db->getUserRequests($user_id);
    foreach ($existing as $req) {
        if ($req['tmdb_id'] == $tmdb_id && $req['media_type'] == $media_type) {
            echo json_encode(['status' => 'error', 'message' => '您已经提交过此求片申请了！']);
            exit;
        }
    }

    $success = $invite_db->addRequest($user_id, $username, $tmdb_id, $title, $poster_url, $media_type);
    
    if ($success) {
        $config_path = __DIR__ . '/../config/config.php';
        if (file_exists($config_path)) {
            $config = require $config_path;
            if ($config['notification']['enable_admin_email_notify'] ?? false) {
                $admin_email = $config['smtp']['username'];
                if ($admin_email) {
                    $subject = "新求片提醒: {$title}";
                    $body = "用户 {$username} 提交了新的求片申请：\r\n\r\n名称: {$title}\r\nTMDB ID: {$tmdb_id}\r\n类型: {$media_type}\r\n\r\n请前往管理后台处理。";
                    send_smtp_email($config['smtp'], $admin_email, $subject, $body);
                }
            }
        }
        echo json_encode(['status' => 'success', 'message' => '求片申请提交成功！']);
    } else {
        echo json_encode(['status' => 'error', 'message' => '提交失败，请稍后再试。']);
    }
    exit;
} elseif ($action === 'mark_read') {
    $notification_id = (int)($_POST['id'] ?? 0);
    if ($notification_id > 0) {
        $invite_db->markNotificationAsRead($notification_id, $user_id);
    } else {
        $invite_db->markAllNotificationsAsRead($user_id);
    }
    echo json_encode(['status' => 'success']);
    exit;
} elseif ($action === 'clear_notifications') {
    $invite_db->clearUserNotifications($user_id);
    echo json_encode(['status' => 'success', 'message' => '所有通知已清空']);
    exit;
} elseif ($action === 'save_email_pref') {
    $enabled = ($_POST['enabled'] ?? '0') === '1';
    if ($invite_db->setUserEmailPreference($user_id, $enabled)) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error']);
    }
    exit;
} elseif ($action === 'poll_data') {
    $requests = $invite_db->getUserRequests($user_id);
    $notifications = $invite_db->getUserNotifications($user_id);
    $unread_count = $invite_db->getUnreadNotificationCount($user_id);

    // Render requests HTML
    ob_start();
    if (empty($requests)) {
        ?>
        <div style="text-align:center; color:rgba(255,255,255,0.2); padding: 20px 0;">暂无求片记录</div>
        <?php
    } else {
        foreach ($requests as $req) {
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
            <?php
        }
    }
    $requests_html = ob_get_clean();

    // Render notifications HTML
    ob_start();
    if (empty($notifications)) {
        ?>
        <div style="text-align:center; color:rgba(255,255,255,0.3); padding: 20px;">暂无通知</div>
        <?php
    } else {
        foreach ($notifications as $notif) {
            ?>
            <div class="notification-item <?php echo $notif['is_read'] ? '' : 'notif-unread'; ?>">
                <div class="notif-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                <div class="notif-msg"><?php echo nl2br(htmlspecialchars($notif['message'])); ?></div>
                <div class="notif-date"><?php echo $notif['created_at']; ?></div>
            </div>
            <?php
        }
    }
    $notifications_html = ob_get_clean();

    echo json_encode([
        'status' => 'success',
        'unread_count' => $unread_count,
        'requests_html' => $requests_html,
        'notifications_html' => $notifications_html
    ]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => '未知操作']);
