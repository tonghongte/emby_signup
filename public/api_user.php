<?php
session_start();

if (!isset($_SESSION['emby_user_id'])) {
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
} elseif ($action === 'save_email_pref') {
    $enabled = ($_POST['enabled'] ?? '0') === '1';
    if ($invite_db->setUserEmailPreference($user_id, $enabled)) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error']);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => '未知操作']);
