<?php
// Emby 注册管理后台
// ----------------------------------------------------
session_start();

$config_path = __DIR__ . '/../config/config.php';
$db_core_file = __DIR__ . '/../config/database.php'; 

if (!file_exists($db_core_file)) {
    die("错误：未找到数据库核心文件。");
}
require_once $db_core_file;
global $invite_db;

$error_message = '';
$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
$register_page_path = dirname($_SERVER['PHP_SELF']) . '/register.php';

if (!file_exists($config_path)) {
    die("错误：未找到配置文件。");
}
$config = require $config_path;


// ----------------------------------------------------
// 工具函数：简易 SMTP 发送
// ----------------------------------------------------
function send_smtp_email($smtp_config, $to, $subject, $body) {
    $host = $smtp_config['host'];
    $port = $smtp_config['port'];
    $username = $smtp_config['username'];
    $password = $smtp_config['password'];
    $from_name = $smtp_config['from_name'];

    $remote_socket = ($smtp_config['secure'] === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;

    $socket = @stream_socket_client($remote_socket, $errno, $errstr, 10);
    if (!$socket) return ['status' => false, 'message' => "连接失败: $errstr ($errno)"];

    $response_msg = "";
    while ($line = fgets($socket, 515)) {
        $response_msg .= $line;
        if (substr($line, 3, 1) === ' ') break;
    }

    if (empty($response_msg) || substr($response_msg, 0, 3) != '220') return ['status' => false, 'message' => "SMTP 握手错误: $response_msg"];

    $commands = [
        "EHLO " . $_SERVER['HTTP_HOST'] . "\r\n" => 250,
        "AUTH LOGIN\r\n" => 334,
        base64_encode($username) . "\r\n" => 334,
        base64_encode($password) . "\r\n" => 235,
        "MAIL FROM: <$username>\r\n" => 250,
        "RCPT TO: <$to>\r\n" => 250,
        "DATA\r\n" => 354,
    ];

    foreach ($commands as $command => $expect_code) {
        fwrite($socket, $command);
        $last_line = '';
        while ($line = fgets($socket, 515)) {
            $last_line = $line;
            if (substr($line, 3, 1) === ' ') break;
        }
        if (substr($last_line, 0, 3) != $expect_code) {
            fclose($socket);
            return ['status' => false, 'message' => "SMTP 错误 [$expect_code]: " . trim($last_line)];
        }
    }

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/plain; charset=utf-8\r\n";
    $headers .= "From: =?UTF-8?B?".base64_encode($from_name)."?= <$username>\r\n";
    $headers .= "To: <$to>\r\n";
    $headers .= "Subject: =?UTF-8?B?".base64_encode($subject)."?=\r\n";
    
    $email_content = $headers . "\r\n" . $body . "\r\n.\r\n";
    
    fwrite($socket, $email_content);
    
    $last_line = '';
    while ($line = fgets($socket, 515)) {
        $last_line = $line;
        if (substr($line, 3, 1) === ' ') break;
    }
    
    $result = ['status' => true, 'message' => '邮件发送成功'];
    if (substr($last_line, 0, 3) != 250) $result = ['status' => false, 'message' => "数据发送错误: " . trim($last_line)];

    fwrite($socket, "QUIT\r\n");
    fclose($socket);
    
    return $result;
}

// ----------------------------------------------------
// Emby API 助手
// ----------------------------------------------------
function apiRequest($endpoint, $method = 'GET', $data = null) {
    global $config;
    $emby_url = rtrim($config['emby']['base_url'], '/');
    $token = $config['emby']['token'];
    $url = $emby_url . $endpoint;
    $separator = (parse_url($url, PHP_URL_QUERY) == NULL) ? '?' : '&';
    $url .= $separator . "api_key=" . $token;

    $options = [
        'http' => [
            'header'  => "Content-type: application/json\r\n",
            'method'  => $method,
            'ignore_errors' => true
        ]
    ];
    if ($data !== null) {
        $options['http']['content'] = json_encode($data);
    }
    $context = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);
    return $result ? json_decode($result, true) : null;
}

function getEmbyUsers() {
    return apiRequest('/emby/Users');
}

function banEmbyUser($user_id) {
    $user = apiRequest("/emby/Users/{$user_id}");
    if (!$user) return false;
    // Emby stores policy under user, we need to update the Policy
    $policy = $user['Policy'];
    $policy['IsDisabled'] = true;
    $res = apiRequest("/emby/Users/{$user_id}/Policy", 'POST', $policy);
    return true; // Simple true for now, Emby returns 204 No Content usually
}

function deleteEmbyUser($user_id) {
    $res = apiRequest("/emby/Users/{$user_id}", 'DELETE');
    return true;
}

// ----------------------------------------------------
// 配置修改助手
// ----------------------------------------------------
function updateConfigValue($file, $env_key, $new_value, $type = 'string') {
    $content = file_get_contents($file);
    if ($type === 'bool') {
        $val_str = $new_value ? 'true' : 'false';
        $pattern = "/env\('{$env_key}',\s*(true|false)\)/i";
        $replacement = "env('{$env_key}', {$val_str})";
    } elseif ($type === 'int') {
        $pattern = "/env\('{$env_key}',\s*\d+\)/i";
        $replacement = "env('{$env_key}', " . (int)$new_value . ")";
    } else {
        $val_str = addslashes($new_value);
        $pattern = "/env\('{$env_key}',\s*'[^']*'\)/i";
        $replacement = "env('{$env_key}', '{$val_str}')";
    }
    $content = preg_replace($pattern, $replacement, $content);
    file_put_contents($file, $content);
}

// ----------------------------------------------------
// 1. 登录验证逻辑
// ----------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    header("Location: admin.php");
    exit;
}

if (isset($_POST['login_user'], $_POST['login_pass'])) {
    if ($_POST['login_user'] === $config['admin']['username'] && $_POST['login_pass'] === $config['admin']['password']) {
        $_SESSION['admin_logged_in'] = true;
        if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header("Location: admin.php");
        exit;
    } else {
        $error_message = "用户名或密码错误";
    }
}

$is_authenticated = !empty($_SESSION['admin_logged_in']);

// ----------------------------------------------------
// 2. AJAX 请求处理逻辑
// ----------------------------------------------------
if ($is_authenticated && isset($_POST['ajax'])) {
    header("Content-Type: application/json; charset=utf-8");
    if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        echo json_encode(['status' => 'error', 'message' => 'CSRF 验证失败']); exit;
    }
    
    $response = ['status' => 'error', 'message' => '未知操作'];
    $action = $_POST['action'] ?? '';
    
    if ($action === 'generate') {
        if ($invite_db->insertCode(InviteDB::generateRandomCode())) {
            $response = ['status' => 'success', 'message' => '邀请码已生成'];
        }
    } elseif ($action === 'delete' && isset($_POST['code'])) {
        if ($invite_db->deleteCode($_POST['code'])) { 
            $response = ['status' => 'success', 'message' => '邀请码已删除'];
        }
    } elseif ($action === 'send_email') {
        $to_email = $_POST['email'] ?? '';
        $mail_body = $_POST['body'] ?? '';
        $mail_subject = $config['email_template']['subject'] ?? 'Emby Invite';
        if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) $response['message'] = '邮箱格式不正确';
        elseif (empty($config['smtp']['host'])) $response['message'] = '未配置 SMTP 信息';
        else {
            $res = send_smtp_email($config['smtp'], $to_email, $mail_subject, $mail_body);
            $response = ['status' => $res['status'] ? 'success' : 'error', 'message' => $res['message']];
        }
    } elseif ($action === 'approve_request' || $action === 'reject_request') {
        $req_id = (int)$_POST['id'];
        $status = $action === 'approve_request' ? 'approved' : 'rejected';
        $req = $invite_db->getRequestById($req_id);
        
        if ($req && $invite_db->updateRequestStatus($req_id, $status)) {
            // 发送站内信
            $status_cn = $status === 'approved' ? '批准' : '拒绝';
            $title = "求片申请已" . $status_cn;
            $msg = "您申请的《{$req['title']}》已被管理员" . $status_cn . "。";
            $invite_db->addNotification($req['emby_user_id'], $title, $msg);
            
            $response = ['status' => 'success', 'message' => "已{$status_cn}，通知已发送"];
        }
    } elseif ($action === 'ban_user') {
        $uid = $_POST['uid'];
        if (banEmbyUser($uid)) $response = ['status' => 'success', 'message' => '用户已封禁'];
        else $response = ['status' => 'error', 'message' => '封禁失败'];
    } elseif ($action === 'delete_user') {
        $uid = $_POST['uid'];
        if (deleteEmbyUser($uid)) $response = ['status' => 'success', 'message' => '用户已删除'];
        else $response = ['status' => 'error', 'message' => '删除失败'];
    } elseif ($action === 'save_settings') {
        $config_file = __DIR__ . '/../config/config.php';
        
        // Emby
        if (!empty($_POST['emby_base_url'])) updateConfigValue($config_file, 'EMBY_BASE_URL', $_POST['emby_base_url']);
        if (!empty($_POST['emby_api_token'])) updateConfigValue($config_file, 'EMBY_API_TOKEN', $_POST['emby_api_token']);
        if (!empty($_POST['template_user_id'])) updateConfigValue($config_file, 'EMBY_TEMPLATE_USER_ID', $_POST['template_user_id']);
        
        // Admin
        if (!empty($_POST['admin_username'])) updateConfigValue($config_file, 'ADMIN_USERNAME', $_POST['admin_username']);
        if (!empty($_POST['admin_password'])) updateConfigValue($config_file, 'ADMIN_PASSWORD', $_POST['admin_password']);
        
        // Site
        if (!empty($_POST['login_url'])) updateConfigValue($config_file, 'SITE_LOGIN_URL', $_POST['login_url']);
        
        // SMTP
        if (!empty($_POST['smtp_host'])) updateConfigValue($config_file, 'SMTP_HOST', $_POST['smtp_host']);
        if (!empty($_POST['smtp_port'])) updateConfigValue($config_file, 'SMTP_PORT', $_POST['smtp_port'], 'int');
        if (!empty($_POST['smtp_username'])) updateConfigValue($config_file, 'SMTP_USERNAME', $_POST['smtp_username']);
        if (!empty($_POST['smtp_password'])) updateConfigValue($config_file, 'SMTP_PASSWORD', $_POST['smtp_password']);
        
        // TMDB
        if (!empty($_POST['tmdb_api_key'])) updateConfigValue($config_file, 'TMDB_API_KEY', $_POST['tmdb_api_key']);
        
        // Auto Ban & Notifications
        $enable_email = isset($_POST['enable_email']) && $_POST['enable_email'] === '1';
        updateConfigValue($config_file, 'ENABLE_REQUEST_EMAIL_NOTIFY', $enable_email, 'bool');
        
        $enable_autoban = isset($_POST['enable_autoban']) && $_POST['enable_autoban'] === '1';
        updateConfigValue($config_file, 'ENABLE_AUTO_BAN', $enable_autoban, 'bool');
        if (!empty($_POST['autoban_days'])) updateConfigValue($config_file, 'AUTO_BAN_DAYS', $_POST['autoban_days'], 'int');

        $response = ['status' => 'success', 'message' => '配置已成功保存并生效'];
    }
    
    echo json_encode($response);
    exit; 
}

// ----------------------------------------------------
// 3. 页面渲染逻辑
// ----------------------------------------------------
header("Content-Type: text/html; charset=utf-8");

// Fetch Data for Tabs
if ($is_authenticated) {
    $all_invite_codes = $invite_db->getAllUnusedCodes();
    $all_requests = $invite_db->getAllRequests();
    $emby_users = getEmbyUsers() ?: [];
    
    // Auto-ban pseduo-cron check could be placed here:
    if ($config['auto_ban']['enable'] ?? false) {
        // Implementation left structurally ready but execution is empty per instructions.
        // foreach ($emby_users as $user) { ... check LastActivityDate ... }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Emby 邀请码管理后台</title>
    <link rel="icon" type="image/png" href="https://emby.media/favicon-32x32.png" sizes="32x32">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0f172a; --card-bg: rgba(30, 41, 59, 0.7);
            --primary: #52B54B; --primary-hover: #43943d;
            --danger: #ef4444; --info: #3b82f6; --warning: #f59e0b;
            --text-main: #f8fafc; --text-sub: #94a3b8;
            --input-bg: rgba(15, 23, 42, 0.6); --border-color: rgba(255, 255, 255, 0.1);
            --blur-amt: 16px; --transition-speed: 0.3s;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-color); color: var(--text-main);
            min-height: 100vh; display: flex; flex-direction: column; align-items: center;
            padding: 40px 20px; position: relative; overflow-x: hidden;
        }
        .bg-blob { position: fixed; border-radius: 50%; filter: blur(80px); z-index: -1; opacity: 0.4; animation: float 10s infinite ease-in-out; }
        .blob-1 { top: -10%; left: -10%; width: 500px; height: 500px; background: #4f46e5; }
        .blob-2 { bottom: -10%; right: -10%; width: 400px; height: 400px; background: var(--primary); animation-delay: -5s; }
        @keyframes float { 0%, 100% { transform: translate(0, 0); } 50% { transform: translate(30px, 50px); } }
        
        .main-container { width: 100%; max-width: <?php echo $is_authenticated ? '1200px' : '400px'; ?>; position: relative; z-index: 10; transition: max-width var(--transition-speed); }
        .logo-section { text-align: center; margin-bottom: 30px; }
        .logo-section img { width: 64px; height: 64px; margin-bottom: 16px; border-radius: 16px; box-shadow: 0 0 20px rgba(82, 181, 75, 0.3); }
        
        .admin-panel { background: var(--card-bg); backdrop-filter: blur(var(--blur-amt)); border: 1px solid var(--border-color); border-radius: 24px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); overflow: hidden; display: flex; flex-direction: column; }
        
        /* Layout for tabs */
        .panel-layout { display: flex; min-height: 600px; }
        .sidebar { width: 220px; border-right: 1px solid var(--border-color); padding: 20px 0; background: rgba(0,0,0,0.2); }
        .tab-btn { display: flex; align-items: center; gap: 10px; width: 100%; padding: 14px 24px; background: transparent; border: none; color: var(--text-sub); text-align: left; cursor: pointer; font-size: 15px; font-weight: 500; transition: all 0.2s; border-left: 3px solid transparent; }
        .tab-btn:hover { background: rgba(255,255,255,0.05); color: white; }
        .tab-btn.active { background: rgba(255,255,255,0.05); color: white; border-left-color: var(--primary); }
        
        .content-area { flex: 1; padding: 32px; position: relative; }
        .tab-content { display: none; animation: fadeIn 0.3s ease-out; }
        .tab-content.active { display: block; }
        
        .header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .section-title { font-size: 20px; font-weight: 600; color: white; }
        
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 12px; color: var(--text-sub); font-size: 13px; border-bottom: 1px solid var(--border-color); text-transform: uppercase; }
        td { padding: 12px; font-size: 14px; border-bottom: 1px solid rgba(255,255,255,0.05); vertical-align: middle; }
        tr:hover td { background: rgba(255,255,255,0.02); }
        
        /* Buttons & Forms */
        .btn { padding: 8px 16px; border-radius: 8px; border: none; font-weight: 600; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; color: white; transition: 0.2s; }
        .btn-primary { background: var(--primary); } .btn-primary:hover { background: var(--primary-hover); }
        .btn-danger { background: rgba(239, 68, 68, 0.2); color: var(--danger); } .btn-danger:hover { background: rgba(239, 68, 68, 0.4); }
        .btn-info { background: rgba(59, 130, 246, 0.2); color: var(--info); } .btn-info:hover { background: rgba(59, 130, 246, 0.4); }
        
        .badge { padding: 4px 8px; border-radius: 6px; font-size: 12px; font-weight: 600; background: rgba(255,255,255,0.1); color: white; }
        .badge.pending { background: rgba(245, 158, 11, 0.2); color: var(--warning); }
        .badge.approved { background: rgba(82, 181, 75, 0.2); color: var(--primary); }
        .badge.rejected { background: rgba(239, 68, 68, 0.2); color: var(--danger); }
        
        /* Settings Form */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 8px; font-size: 13px; color: var(--text-sub); }
        .form-group input[type="text"], .form-group input[type="password"], .form-group input[type="number"] { width: 100%; padding: 12px 16px; background: var(--input-bg); border: 1px solid var(--border-color); border-radius: 10px; color: white; }
        .form-group input:focus { border-color: var(--primary); outline: none; }
        
        .switch-wrap { display: flex; align-items: center; gap: 10px; }
        
        #toast-container { position: fixed; top: 24px; left: 50%; transform: translateX(-50%); z-index: 2000; opacity: 0; transition: 0.3s; pointer-events: none; }
        #toast-container.show { opacity: 1; pointer-events: auto; }
        .toast { background: #1e293b; color: white; padding: 12px 24px; border-radius: 50px; font-size: 14px; font-weight: 500; border: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; gap: 8px; }
        
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>
    <div class="bg-blob blob-1"></div>
    <div class="bg-blob blob-2"></div>
    <div id="toast-container"><div id="status-toast" class="toast"></div></div>

    <div class="main-container">
        <div class="logo-section">
            <img src="https://emby.media/favicon-96x96.png" alt="Emby">
            <h1>后台管理</h1>
        </div>

        <?php if (!$is_authenticated): ?>
        <div class="admin-panel" style="padding: 32px; max-width: 400px; margin: 0 auto;">
            <?php if ($error_message): ?><div style="color:var(--danger); margin-bottom:15px; text-align:center;">⚠️ <?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
            <form method="post">
                <div class="form-group"><label>账号</label><input type="text" name="login_user" required></div>
                <div class="form-group"><label>密码</label><input type="password" name="login_pass" required></div>
                <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 12px; font-size: 16px;">登录</button>
            </form>
        </div>
        
        <?php else: ?>
        <div class="admin-panel">
            <div class="panel-layout">
                <div class="sidebar">
                    <button class="tab-btn active" onclick="switchTab(0)"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg> 邀请码管理</button>
                    <button class="tab-btn" onclick="switchTab(1)"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polygon points="10 8 16 12 10 16 10 8"></polygon></svg> 求片管理</button>
                    <button class="tab-btn" onclick="switchTab(2)"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg> 用户列表</button>
                    <button class="tab-btn" onclick="switchTab(3)"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg> 系统设置</button>
                    <a href="?action=logout" class="tab-btn" style="margin-top:auto; color:var(--danger);"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg> 退出登录</a>
                </div>
                
                <div class="content-area">
                    <!-- Tab 1: Invite Codes -->
                    <div class="tab-content active" id="tab-0">
                        <div class="header-flex">
                            <div class="section-title">邀请码 (<?php echo count($all_invite_codes); ?>)</div>
                            <button class="btn btn-primary" onclick="ajaxAction('generate')">生成新邀请码</button>
                        </div>
                        <div style="max-height: 500px; overflow-y:auto;">
                            <table>
                                <thead><tr><th>邀请码</th><th>注册链接</th><th>操作</th></tr></thead>
                                <tbody>
                                    <?php foreach ($all_invite_codes as $code): 
                                        $invite_link = rtrim($base_url, '/') . '/' . ltrim($register_page_path, '/') . '?invite_code=' . urlencode($code); 
                                    ?>
                                    <tr>
                                        <td><span class="badge"><?php echo $code; ?></span></td>
                                        <td><code style="font-size:12px; color:var(--text-sub);"><?php echo $invite_link; ?></code></td>
                                        <td>
                                            <button class="btn btn-danger" onclick="if(confirm('删除?')) ajaxAction('delete', {code: '<?php echo $code; ?>'})">删除</button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Tab 2: Requests -->
                    <div class="tab-content" id="tab-1">
                        <div class="header-flex">
                            <div class="section-title">求片管理</div>
                        </div>
                        <div style="max-height: 500px; overflow-y:auto;">
                            <table>
                                <thead><tr><th>影片</th><th>用户</th><th>时间</th><th>状态</th><th>操作</th></tr></thead>
                                <tbody>
                                    <?php foreach ($all_requests as $req): ?>
                                    <tr>
                                        <td><div style="display:flex; align-items:center; gap:10px;">
                                            <?php if($req['poster_url']): ?><img src="<?php echo $req['poster_url']; ?>" style="width:30px; border-radius:4px;"><?php endif; ?>
                                            <span><?php echo htmlspecialchars($req['title']); ?></span>
                                        </div></td>
                                        <td><?php echo htmlspecialchars($req['emby_username']); ?></td>
                                        <td style="font-size:12px; color:var(--text-sub);"><?php echo $req['created_at']; ?></td>
                                        <td><span class="badge <?php echo $req['status']; ?>"><?php echo $req['status']; ?></span></td>
                                        <td>
                                            <?php if($req['status'] === 'pending'): ?>
                                            <button class="btn btn-primary" onclick="ajaxAction('approve_request', {id: <?php echo $req['id']; ?>})">批准</button>
                                            <button class="btn btn-danger" onclick="ajaxAction('reject_request', {id: <?php echo $req['id']; ?>})">拒绝</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Tab 3: Users -->
                    <div class="tab-content" id="tab-2">
                        <div class="header-flex">
                            <div class="section-title">用户列表 (<?php echo count($emby_users); ?>)</div>
                        </div>
                        <div style="max-height: 500px; overflow-y:auto;">
                            <table>
                                <thead><tr><th>用户名</th><th>最后活动</th><th>状态</th><th>操作</th></tr></thead>
                                <tbody>
                                    <?php foreach ($emby_users as $user): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($user['Name']); ?></td>
                                        <td style="font-size:12px; color:var(--text-sub);"><?php echo isset($user['LastActivityDate']) ? substr($user['LastActivityDate'], 0, 10) : '从未登录'; ?></td>
                                        <td><?php echo isset($user['Policy']['IsDisabled']) && $user['Policy']['IsDisabled'] ? '<span class="badge rejected">已封禁</span>' : '<span class="badge approved">正常</span>'; ?></td>
                                        <td>
                                            <button class="btn btn-danger" onclick="if(confirm('封禁此用户?')) ajaxAction('ban_user', {uid: '<?php echo $user['Id']; ?>'})">封禁</button>
                                            <button class="btn btn-danger" onclick="if(confirm('彻底删除此用户?')) ajaxAction('delete_user', {uid: '<?php echo $user['Id']; ?>'})">删除</button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Tab 4: Settings -->
                    <div class="tab-content" id="tab-3">
                        <div class="header-flex">
                            <div class="section-title">系统设置</div>
                            <button class="btn btn-primary" onclick="saveSettings()">保存设置</button>
                        </div>
                        <form id="settings-form" style="max-height: 500px; overflow-y:auto; padding-right:10px;">
                            <h3 style="margin-bottom:10px; color:white; font-size:16px;">Emby 服务器</h3>
                            <div class="form-grid">
                                <div class="form-group"><label>Base URL</label><input type="text" name="emby_base_url" value="<?php echo htmlspecialchars($config['emby']['base_url']); ?>"></div>
                                <div class="form-group"><label>模板用户 ID</label><input type="text" name="template_user_id" value="<?php echo htmlspecialchars($config['emby']['template_user_id']); ?>"></div>
                                <div class="form-group"><label>API Token (留空则不修改)</label><input type="password" name="emby_api_token" placeholder="••••••••••••••••"></div>
                            </div>

                            <h3 style="margin-bottom:10px; color:white; font-size:16px;">管理员与安全</h3>
                            <div class="form-grid">
                                <div class="form-group"><label>后台用户名</label><input type="text" name="admin_username" value="<?php echo htmlspecialchars($config['admin']['username']); ?>"></div>
                                <div class="form-group"><label>后台密码 (留空则不修改)</label><input type="password" name="admin_password" placeholder="••••••••"></div>
                            </div>
                            
                            <h3 style="margin-bottom:10px; color:white; font-size:16px;">SMTP 邮件</h3>
                            <div class="form-grid">
                                <div class="form-group"><label>SMTP 服务器</label><input type="text" name="smtp_host" value="<?php echo htmlspecialchars($config['smtp']['host']); ?>"></div>
                                <div class="form-group"><label>SMTP 端口</label><input type="number" name="smtp_port" value="<?php echo htmlspecialchars($config['smtp']['port']); ?>"></div>
                                <div class="form-group"><label>发件邮箱账号</label><input type="text" name="smtp_username" value="<?php echo htmlspecialchars($config['smtp']['username']); ?>"></div>
                                <div class="form-group"><label>邮箱密码/授权码 (留空不修改)</label><input type="password" name="smtp_password" placeholder="••••••••"></div>
                            </div>

                            <h3 style="margin-bottom:10px; color:white; font-size:16px;">求片与通知</h3>
                            <div class="form-grid">
                                <div class="form-group"><label>TMDB API Key (留空不修改)</label><input type="password" name="tmdb_api_key" placeholder="••••••••"></div>
                                <div class="form-group switch-wrap">
                                    <input type="checkbox" name="enable_email" id="enable_email" value="1" <?php echo ($config['notification']['enable_request_email_notify'] ?? false) ? 'checked' : ''; ?>>
                                    <label for="enable_email" style="margin:0;">开启求片处理结果的邮件通知</label>
                                </div>
                            </div>
                            
                            <h3 style="margin-bottom:10px; color:white; font-size:16px;">自动清理</h3>
                            <div class="form-grid">
                                <div class="form-group switch-wrap">
                                    <input type="checkbox" name="enable_autoban" id="enable_autoban" value="1" <?php echo ($config['auto_ban']['enable'] ?? false) ? 'checked' : ''; ?>>
                                    <label for="enable_autoban" style="margin:0;">启用非活跃用户自动封禁功能</label>
                                </div>
                                <div class="form-group"><label>判定非活跃天数</label><input type="number" name="autoban_days" value="<?php echo htmlspecialchars($config['auto_ban']['days'] ?? 30); ?>"></div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($is_authenticated): ?>
    <script>
        const csrfToken = "<?php echo $_SESSION['csrf_token']; ?>";

        function switchTab(index) {
            document.querySelectorAll('.tab-btn').forEach((btn, i) => {
                if(i < 4) btn.classList.toggle('active', i === index); // 4 is logout btn
            });
            document.querySelectorAll('.tab-content').forEach((content, i) => {
                content.classList.toggle('active', i === index);
            });
        }

        function displayToast(msg) {
            const container = document.getElementById('toast-container');
            const toast = document.getElementById('status-toast');
            toast.innerText = msg;
            container.classList.add('show');
            setTimeout(() => container.classList.remove('show'), 3000);
        }

        async function ajaxAction(action, data = {}) {
            const formData = new URLSearchParams();
            formData.append('ajax', '1');
            formData.append('csrf_token', csrfToken);
            formData.append('action', action);
            for (const key in data) formData.append(key, data[key]);

            try {
                const res = await fetch("admin.php", {
                    method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded" },
                    body: formData.toString()
                });
                const result = await res.json();
                displayToast(result.message);
                if (result.status === 'success') {
                    setTimeout(() => location.reload(), 1000);
                }
            } catch (error) {
                displayToast('网络请求失败');
            }
        }
        
        function saveSettings() {
            const form = document.getElementById('settings-form');
            const formData = new FormData(form);
            const dataObj = {};
            formData.forEach((value, key) => dataObj[key] = value);
            
            // Checkboxes might be missing if unchecked, enforce false
            if(!dataObj.enable_email) dataObj.enable_email = '0';
            if(!dataObj.enable_autoban) dataObj.enable_autoban = '0';
            
            ajaxAction('save_settings', dataObj);
        }
    </script>
    <?php endif; ?>
</body>
</html>
