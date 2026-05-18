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
    $res = apiRequest('/emby/Users');
    // Ensure we actually got a list of users, not an error object like {"Message":"..."}
    return (is_array($res) && isset($res[0]) && is_array($res[0])) ? $res : [];
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

function unbanEmbyUser($user_id) {
    $user = apiRequest("/emby/Users/{$user_id}");
    if (!$user) return false;
    $policy = $user['Policy'];
    $policy['IsDisabled'] = false;
    $res = apiRequest("/emby/Users/{$user_id}/Policy", 'POST', $policy);
    return true; 
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
    
    // 如果找到了对应的 env 定义，直接替换
    if (preg_match($pattern, $content)) {
        $content = preg_replace($pattern, $replacement, $content);
        file_put_contents($file, $content);
    }
}

// 检查并注入缺失的配置块 (兼容旧版本 config.php)
function checkAndInjectMissingConfigBlocks($file) {
    $content = file_get_contents($file);
    $needs_update = false;
    
    // 寻找 return [ ... ]; 的末尾
    $end_pos = strrpos($content, ']');
    if ($end_pos === false) return;
    
    if (strpos($content, "'site'") === false) {
        $insert_str .= "\n    'site' => [\n        'login_url' => env('SITE_LOGIN_URL', '')\n    ],\n";
        $needs_update = true;
    }
    if (strpos($content, "'tmdb'") === false) {
        $insert_str .= "\n    'tmdb' => [\n        'api_key' => env('TMDB_API_KEY', ''),\n        'proxy' => env('TMDB_PROXY', ''),\n        'language' => env('TMDB_LANGUAGE', 'zh-CN')\n    ],\n";
        $needs_update = true;
    }
    if (strpos($content, "'auto_ban'") === false) {
        $insert_str .= "\n    'auto_ban' => [\n        'enable' => env('ENABLE_AUTO_BAN', false),\n        'days' => env('AUTO_BAN_DAYS', 30)\n    ],\n";
        $needs_update = true;
    }
    if (strpos($content, "'auto_delete_requests'") === false) {
        $insert_str .= "\n    'auto_delete_requests' => [\n        'enable' => env('ENABLE_AUTO_DELETE_REQUESTS', false),\n        'days' => env('AUTO_DELETE_REQUESTS_DAYS', 30)\n    ],\n";
        $needs_update = true;
    }
    if (strpos($content, "ENABLE_ADMIN_EMAIL_NOTIFY") === false && strpos($content, "ENABLE_USER_EMAIL_NOTIFY") === false) {
        // 如果 notification 不在或者依然是老版本的单个选项，我们直接替换整个 block 为新版双选项
        if (strpos($content, "'notification'") !== false) {
            $content = preg_replace("/'notification'\s*=>\s*\[(.*?)\]/is", "'notification' => [\n        'enable_admin_email_notify' => env('ENABLE_ADMIN_EMAIL_NOTIFY', false),\n        'enable_user_email_notify' => env('ENABLE_USER_EMAIL_NOTIFY', false)\n    ]", $content);
            $needs_update = true;
        } else {
            $insert_str .= "\n    'notification' => [\n        'enable_admin_email_notify' => env('ENABLE_ADMIN_EMAIL_NOTIFY', false),\n        'enable_user_email_notify' => env('ENABLE_USER_EMAIL_NOTIFY', false)\n    ],\n";
            $needs_update = true;
        }
    }
    
    if ($needs_update && !empty($insert_str)) {
        $content = substr_replace($content, $insert_str, $end_pos, 0);
        file_put_contents($file, $content);
    } elseif ($needs_update && empty($insert_str)) {
        file_put_contents($file, $content); // Regex replacements might have occurred
    }
}

// ----------------------------------------------------
// 1. 登录验证逻辑
// ----------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}

$is_authenticated = !empty($_SESSION['admin_logged_in']);

if (!$is_authenticated) {
    header("Location: login.php");
    exit;
}

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
    
    if ($action === 'poll_data') {
        $all_invite_codes = $invite_db->getAllUnusedCodes();
        $all_requests = $invite_db->getAllRequests();
        $emby_users = getEmbyUsers() ?: [];

        // Generate Invite Codes tbody
        ob_start();
        foreach ($all_invite_codes as $code) {
            $invite_link = rtrim($base_url, '/') . '/' . ltrim($register_page_path, '/') . '?invite_code=' . urlencode($code);
            ?>
            <tr>
                <td class="col-code"><span class="badge"><?php echo $code; ?></span></td>
                <td class="col-link">
                    <div class="link-wrapper">
                        <span class="link-text" title="<?php echo htmlspecialchars($invite_link); ?>"><?php echo htmlspecialchars($invite_link); ?></span>
                        <button type="button" class="copy-action-btn" onclick="copyInviteLink(this, '<?php echo htmlspecialchars(addslashes($invite_link)); ?>')">
                            <svg class="copy-icon" viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                        </button>
                    </div>
                </td>
                <td>
                    <div style="display: flex; gap: 8px;">
                        <button class="btn btn-primary" onclick="openEmailModal('<?php echo $code; ?>', '<?php echo htmlspecialchars(addslashes($invite_link)); ?>')" style="padding: 6px 12px; font-size: 12px;">发送</button>
                        <button class="btn btn-danger" onclick="showConfirm('删除邀请码', '确定删除该邀请码吗？', () => ajaxAction('delete', {code: '<?php echo $code; ?>'}))" style="padding: 6px 12px; font-size: 12px;">删除</button>
                    </div>
                </td>
            </tr>
            <?php
        }
        $invite_codes_tbody = ob_get_clean();

        // Generate Requests tbody
        ob_start();
        foreach ($all_requests as $req) {
            $status_text = '待处理';
            if ($req['status'] === 'approved') $status_text = '已批准';
            elseif ($req['status'] === 'rejected') $status_text = '已拒绝';
            ?>
            <tr>
                <td><div style="display:flex; align-items:center; gap:10px;">
                    <?php if($req['poster_url']): ?><img src="<?php echo htmlspecialchars($req['poster_url']); ?>" style="width:30px; border-radius:4px;"><?php endif; ?>
                    <span><?php echo htmlspecialchars($req['title']); ?></span>
                </div></td>
                <td><?php echo htmlspecialchars($req['emby_username']); ?></td>
                <td style="font-size:12px; color:var(--text-sub);"><?php echo htmlspecialchars($req['created_at']); ?></td>
                <td><span class="badge <?php echo htmlspecialchars($req['status']); ?>"><?php echo $status_text; ?></span></td>
                <td>
                    <div style="display: flex; gap: 8px;">
                        <?php if($req['status'] === 'pending'): ?>
                        <button class="btn btn-primary" onclick="showConfirm('批准求片', '确定批准该求片申请吗？', () => ajaxAction('approve_request', {id: <?php echo $req['id']; ?>}))">批准</button>
                        <button class="btn btn-danger" onclick="showConfirm('拒绝求片', '确定拒绝该求片申请吗？', () => ajaxAction('reject_request', {id: <?php echo $req['id']; ?>}))">拒绝</button>
                        <?php endif; ?>
                        <button class="btn btn-danger-solid" onclick="showConfirm('删除记录', '彻底删除这条记录？', () => ajaxAction('delete_request', {id: <?php echo $req['id']; ?>}))">删除</button>
                    </div>
                </td>
            </tr>
            <?php
        }
        $requests_tbody = ob_get_clean();

        // Generate Users tbody
        ob_start();
        foreach ($emby_users as $user) {
            $is_disabled = !empty($user['Policy']['IsDisabled']);
            ?>
            <tr>
                <td><?php echo htmlspecialchars($user['Name']); ?></td>
                <td style="font-size:12px; color:var(--text-sub);"><?php echo isset($user['LastActivityDate']) ? substr($user['LastActivityDate'], 0, 10) : '从未登录'; ?></td>
                <td><?php echo $is_disabled ? '<span class="badge rejected">封禁</span>' : '<span class="badge approved">正常</span>'; ?></td>
                <td>
                    <div style="display: flex; gap: 8px;">
                        <?php if ($is_disabled): ?>
                        <button class="btn btn-primary" onclick="showConfirm('解封用户', '确定解封此用户吗?', () => ajaxAction('unban_user', {uid: '<?php echo $user['Id']; ?>'}))">解封</button>
                        <?php else: ?>
                        <button class="btn btn-danger" onclick="showConfirm('封禁用户', '确定封禁此用户吗?', () => ajaxAction('ban_user', {uid: '<?php echo $user['Id']; ?>'}))">封禁</button>
                        <?php endif; ?>
                        <button class="btn btn-danger-solid" onclick="showConfirm('删除用户', '确定彻底从 Emby 服务器删除此用户吗? 此操作无法撤销！', () => ajaxAction('delete_user', {uid: '<?php echo $user['Id']; ?>'}))">删除</button>
                    </div>
                </td>
            </tr>
            <?php
        }
        $users_tbody = ob_get_clean();

        echo json_encode([
            'status' => 'success',
            'invite_codes_count' => count($all_invite_codes),
            'requests_count' => count($all_requests),
            'users_count' => count($emby_users),
            'invite_codes_html' => $invite_codes_tbody,
            'requests_html' => $requests_tbody,
            'users_html' => $users_tbody
        ]);
        exit;
    }
    
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
            
            // 尝试发送邮件通知给用户
            if (($config['notification']['enable_user_email_notify'] ?? false) && 
                $invite_db->getUserEmailPreference($req['emby_user_id'])) {
                $user_info_raw = apiRequest("/emby/Users/{$req['emby_user_id']}?X-Emby-Token={$config['emby']['token']}");
                if ($user_info_raw) {
                    $user_info = $user_info_raw;
                    $email = $user_info['Email'] ?? $user_info['ConnectUserName'] ?? null;
                    if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        send_smtp_email($config['smtp'], $email, "Emby " . $title, $msg);
                    }
                }
            }
            
            $response = ['status' => 'success', 'message' => "已{$status_cn}，通知已发送"];
        }
    } elseif ($action === 'delete_request') {
        $req_id = (int)$_POST['id'];
        if ($invite_db->deleteRequest($req_id)) {
            $response = ['status' => 'success', 'message' => "求片记录已删除"];
        }
    } elseif ($action === 'clear_processed_requests') {
        $invite_db->clearProcessedRequests();
        $response = ['status' => 'success', 'message' => "所有已处理求片已清空"];
    } elseif ($action === 'clear_all_requests') {
        $invite_db->clearAllRequests();
        $response = ['status' => 'success', 'message' => "所有求片记录已清空"];
    } elseif ($action === 'ban_user') {
        $uid = $_POST['uid'];
        if (banEmbyUser($uid)) $response = ['status' => 'success', 'message' => '用户已封禁'];
        else $response = ['status' => 'error', 'message' => '封禁失败'];
    } elseif ($action === 'unban_user') {
        $uid = $_POST['uid'];
        if (unbanEmbyUser($uid)) $response = ['status' => 'success', 'message' => '用户已解封'];
        else $response = ['status' => 'error', 'message' => '解封失败'];
    } elseif ($action === 'delete_user') {
        $uid = $_POST['uid'];
        if (deleteEmbyUser($uid)) $response = ['status' => 'success', 'message' => '用户已彻底删除'];
        else $response = ['status' => 'error', 'message' => '删除失败'];
    } elseif ($action === 'save_settings') {
        $config_file = __DIR__ . '/../config/config.php';
        
        // 自动注入缺失的配置块 (兼容老版本)
        checkAndInjectMissingConfigBlocks($config_file);
        
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
        if (!empty($_POST['smtp_from_name'])) updateConfigValue($config_file, 'SMTP_FROM_NAME', $_POST['smtp_from_name']);
        if (!empty($_POST['smtp_secure'])) updateConfigValue($config_file, 'SMTP_SECURE', $_POST['smtp_secure']);
        
        // Email Template
        if (!empty($_POST['email_subject'])) updateConfigValue($config_file, 'EMAIL_SUBJECT', $_POST['email_subject']);
        if (isset($_POST['email_template_body'])) {
            $email_template_path = __DIR__ . '/../config/email_template.txt';
            file_put_contents($email_template_path, $_POST['email_template_body']);
        }
        
        // TMDB
        if (!empty($_POST['tmdb_api_key'])) updateConfigValue($config_file, 'TMDB_API_KEY', $_POST['tmdb_api_key']);
        if (isset($_POST['tmdb_proxy'])) updateConfigValue($config_file, 'TMDB_PROXY', $_POST['tmdb_proxy']);
        
        // Auto Ban & Notifications & Auto Delete Requests
        $enable_admin_email = isset($_POST['enable_admin_email']) && $_POST['enable_admin_email'] === '1';
        $enable_user_email = isset($_POST['enable_user_email']) && $_POST['enable_user_email'] === '1';
        updateConfigValue($config_file, 'ENABLE_ADMIN_EMAIL_NOTIFY', $enable_admin_email, 'bool');
        updateConfigValue($config_file, 'ENABLE_USER_EMAIL_NOTIFY', $enable_user_email, 'bool');
        
        $enable_autoban = isset($_POST['enable_autoban']) && $_POST['enable_autoban'] === '1';
        updateConfigValue($config_file, 'ENABLE_AUTO_BAN', $enable_autoban, 'bool');
        if (!empty($_POST['autoban_days'])) updateConfigValue($config_file, 'AUTO_BAN_DAYS', $_POST['autoban_days'], 'int');

        $enable_autodel_req = isset($_POST['enable_autodel_req']) && $_POST['enable_autodel_req'] === '1';
        updateConfigValue($config_file, 'ENABLE_AUTO_DELETE_REQUESTS', $enable_autodel_req, 'bool');
        if (!empty($_POST['autodel_req_days'])) updateConfigValue($config_file, 'AUTO_DELETE_REQUESTS_DAYS', $_POST['autodel_req_days'], 'int');

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
    
    // 读取邀请邮件正文模版内容
    $email_template_path = __DIR__ . '/../config/email_template.txt';
    $email_template_content = '';
    if (file_exists($email_template_path)) {
        $email_template_content = file_get_contents($email_template_path);
    }
    
    // 自动清理过期的求片记录
    if ($config['auto_delete_requests']['enable'] ?? false) {
        $days = (int)($config['auto_delete_requests']['days'] ?? 30);
        $invite_db->deleteExpiredRequests($days);
    }
    
    // 自动非活跃用户封禁
    if (($config['auto_ban']['enable'] ?? false) && !empty($emby_users)) {
        $ban_days = (int)($config['auto_ban']['days'] ?? 30);
        $now = new DateTime();
        foreach ($emby_users as $user) {
            // 已封禁或管理员/模板用户跳过
            if (!empty($user['Policy']['IsDisabled'])) continue;
            if (!empty($user['Policy']['IsAdministrator'])) continue;
            if ($user['Id'] === ($config['emby']['template_user_id'] ?? '')) continue;
            
            if (!empty($user['LastActivityDate'])) {
                try {
                    $last_active = new DateTime($user['LastActivityDate']);
                    $diff = $now->diff($last_active)->days;
                    if ($diff >= $ban_days) {
                        banEmbyUser($user['Id']);
                    }
                } catch (Exception $e) {
                    // 忽略无效日期解析错误
                }
            }
        }
    }
}

$template_path = $config['email_template']['template_path'] ?? __DIR__ . '/../config/email_template.txt';
$template_content = file_exists($template_path) ? file_get_contents($template_path) : "";
$js_template_body = json_encode($template_content);
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
            height: 100vh; margin: 0; padding: 0; display: flex; flex-direction: column; overflow: hidden;
        }
        .bg-blob { position: fixed; border-radius: 50%; filter: blur(80px); z-index: -1; opacity: 0.4; animation: float 10s infinite ease-in-out; }
        .blob-1 { top: -10%; left: -10%; width: 500px; height: 500px; background: #4f46e5; }
        .blob-2 { bottom: -10%; right: -10%; width: 400px; height: 400px; background: var(--primary); animation-delay: -5s; }
        @keyframes float { 0%, 100% { transform: translate(0, 0); } 50% { transform: translate(30px, 50px); } }

        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 8px; height: 8px; background-color: transparent; }
        ::-webkit-scrollbar-thumb { background-color: rgba(255, 255, 255, 0.15); border-radius: 10px; transition: background-color 0.3s; }
        ::-webkit-scrollbar-thumb:hover { background-color: rgba(255, 255, 255, 0.3); }
        * { scrollbar-width: thin; scrollbar-color: rgba(255, 255, 255, 0.15) transparent; }

        /* Navbar */
        .navbar {
            background: rgba(15, 23, 42, 0.8); backdrop-filter: blur(var(--blur-amt));
            border-bottom: 1px solid var(--border-color); padding: 12px 24px;
            display: flex; justify-content: space-between; align-items: center;
            z-index: 100; flex-shrink: 0;
        }
        .nav-brand { display: flex; align-items: center; gap: 12px; font-weight: 700; font-size: 18px; color: white; text-decoration: none; }
        .nav-brand img { width: 32px; height: 32px; border-radius: 8px; }
        .nav-links { display: flex; align-items: center; gap: 24px; }
        
        .menu-toggle-btn { background: none; border: none; color: white; cursor: pointer; padding: 4px; display: block; }
        
        .btn-emby {
            background: linear-gradient(135deg, var(--primary) 0%, #3d8c38 100%); color: white;
            padding: 8px 16px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px;
            display: flex; align-items: center; gap: 8px; transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn-emby:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(82, 181, 75, 0.3); }
        .btn-portal {
            background: rgba(255,255,255,0.05); color: var(--text-sub); border: 1px solid rgba(255,255,255,0.1);
            padding: 8px 16px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px;
            display: flex; align-items: center; gap: 8px; transition: all 0.2s, box-shadow 0.2s;
        }
        .btn-portal:hover { transform: translateY(-2px); background: rgba(255,255,255,0.15); color: white; border-color: rgba(255,255,255,0.2); }
        .logout-link { color: var(--text-sub); text-decoration: none; font-size: 14px; transition: color 0.2s; }
        .logout-link:hover { color: var(--danger); }

        /* Modals */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(10px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
            opacity: 1;
            transition: opacity 0.3s ease;
        }
        .modal-content {
            background: #1e293b;
            padding: 32px;
            border-radius: 20px;
            border: 1px solid rgba(255,255,255,0.1);
            max-width: 400px;
            width: 100%;
            text-align: center;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5);
            animation: zoomIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .modal-title {
            font-size: 18px;
            font-weight: 700;
            color: white;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .modal-btn {
            background: white;
            color: #0f172a;
            border: none;
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: all 0.2s;
        }
        .modal-btn:hover {
            background: #f1f5f9;
            transform: scale(1.02);
        }
        @keyframes zoomIn { from { opacity: 0; transform: scale(0.9); } to { opacity: 1; transform: scale(1); } }

        .main-container { width: 100%; flex: 1; display: flex; position: relative; z-index: 10; overflow: hidden; }
        
        .admin-panel { background: rgba(30, 41, 59, 0.4); backdrop-filter: blur(var(--blur-amt)); width: 100%; height: 100%; display: flex; flex-direction: column; }
        
        /* Layout for tabs */
        .panel-layout { display: flex; width: 100%; height: 100%; position: relative; }
        
        .sidebar { width: 240px; border-right: 1px solid var(--border-color); padding: 20px 0; background: rgba(0,0,0,0.3); transition: all var(--transition-speed); display: flex; flex-direction: column; flex-shrink: 0; overflow-x: hidden; }
        .sidebar.collapsed { width: 70px; }
        .sidebar.collapsed .tab-text { display: none; }
        .sidebar.collapsed .tab-btn { justify-content: center; padding: 14px 0; }
        .sidebar.collapsed .tab-icon { margin: 0; }
        
        .tab-btn { display: flex; align-items: center; gap: 10px; width: 100%; padding: 14px 24px; background: transparent; border: none; color: var(--text-sub); text-align: left; cursor: pointer; font-size: 15px; font-weight: 500; transition: all 0.2s; border-left: 3px solid transparent; white-space: nowrap; overflow: hidden; }
        .tab-btn:hover { background: rgba(255,255,255,0.05); color: white; }
        .tab-btn.active { background: rgba(255,255,255,0.05); color: white; border-left-color: var(--primary); }
        .tab-icon { min-width: 18px; display: flex; align-items: center; justify-content: center; }
        
        /* Invite Link UI */
        .link-wrapper { display: inline-flex; align-items: center; background: var(--input-bg); padding: 6px 10px; border-radius: 8px; border: 1px solid transparent; transition: all var(--transition-speed); max-width: 100%; }
        .link-wrapper:hover { border-color: var(--primary); background: rgba(82, 181, 75, 0.1); }
        .link-text { font-family: 'SF Mono', 'Roboto Mono', monospace; white-space: nowrap; color: var(--text-sub); font-size: 12px; }
        .copy-action-btn { background: transparent; border: none; cursor: pointer; padding: 0; margin-left: 8px; display: flex; align-items: center; transition: color var(--transition-speed), transform 0.2s; color: var(--text-sub); flex-shrink: 0; }
        .copy-action-btn:hover { color: var(--primary); }
        .copy-action-btn.success { color: var(--primary); transform: scale(1.1); }
        
        .content-area { flex: 1; padding: 32px; position: relative; overflow-x: hidden; overflow-y: auto; }
        .tab-content { display: none; animation: fadeIn 0.3s ease-out; }
        .tab-content.active { display: block; }
        
        .header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .section-title { font-size: 20px; font-weight: 600; color: white; }
        
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 12px; color: var(--text-sub); font-size: 13px; border-bottom: 1px solid var(--border-color); text-transform: uppercase; white-space: nowrap; }
        td { padding: 12px; font-size: 14px; border-bottom: 1px solid rgba(255,255,255,0.05); vertical-align: middle; white-space: nowrap; }
        tr:hover td { background: rgba(255,255,255,0.02); }
        .table-wrapper { overflow-x: auto; width: 100%; padding-right: 4px; padding-bottom: 8px; }
        
        /* Buttons & Forms */
        .btn { padding: 8px 16px; border-radius: 8px; border: none; font-weight: 600; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px; color: white; transition: 0.2s; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06); white-space: nowrap; flex-shrink: 0; }
        .btn:active { transform: scale(0.97); }
        .btn-primary { background: linear-gradient(135deg, var(--primary) 0%, #3d8c38 100%); } .btn-primary:hover { filter: brightness(1.1); box-shadow: 0 4px 12px rgba(82, 181, 75, 0.4); }
        .btn-danger { background: rgba(239, 68, 68, 0.2); color: var(--danger); box-shadow: none; border: 1px solid rgba(239, 68, 68, 0.3); } .btn-danger:hover { background: rgba(239, 68, 68, 0.3); color: #fff; }
        .btn-danger-solid { background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%); color: white; } .btn-danger-solid:hover { filter: brightness(1.1); box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4); }
        
        .badge { padding: 4px 8px; border-radius: 6px; font-size: 12px; font-weight: 600; background: rgba(255,255,255,0.1); color: white; }
        .badge.pending { background: rgba(245, 158, 11, 0.2); color: var(--warning); border: 1px solid rgba(245, 158, 11, 0.3); }
        .badge.approved { background: rgba(82, 181, 75, 0.2); color: var(--primary); border: 1px solid rgba(82, 181, 75, 0.3); }
        .badge.rejected { background: rgba(239, 68, 68, 0.2); color: var(--danger); border: 1px solid rgba(239, 68, 68, 0.3); }
        
        /* Settings Form */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 8px; font-size: 13px; color: var(--text-sub); }
        .form-group input[type="text"], .form-group input[type="password"], .form-group input[type="number"] { width: 100%; padding: 12px 16px; background: var(--input-bg); border: 1px solid var(--border-color); border-radius: 10px; color: white; transition: 0.2s; }
        .form-group input:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 3px rgba(82, 181, 75, 0.2); }
        .form-footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; }
        
        .switch-wrap { display: flex; align-items: center; gap: 10px; }
        
        /* Beautiful Settings UI */
        .settings-section { background: rgba(0, 0, 0, 0.2); border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 16px; padding: 24px; margin-bottom: 24px; }
        .settings-section h3 { margin-bottom: 20px; color: white; font-size: 16px; display: flex; align-items: center; gap: 8px; padding-bottom: 16px; border-bottom: 1px solid rgba(255, 255, 255, 0.05); font-weight: 600; }
        .settings-section h3 svg { color: var(--primary); }
        
        .toggle-switch { position: relative; display: inline-block; width: 44px; height: 24px; flex: 0 0 44px; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-switch .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(255,255,255,0.1); transition: .3s; border-radius: 34px; border: 1px solid rgba(255,255,255,0.2); }
        .toggle-switch .slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 2px; bottom: 2px; background-color: var(--text-sub); transition: .3s; border-radius: 50%; }
        .toggle-switch input:checked + .slider { background-color: rgba(82, 181, 75, 0.2); border-color: var(--primary); }
        .toggle-switch input:checked + .slider:before { transform: translateX(20px); background-color: var(--primary); }
        
        .switch-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; margin-bottom: 16px; }
        .switch-row { display: flex; align-items: center; justify-content: space-between; background: rgba(255,255,255,0.03); padding: 16px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05); transition: 0.2s; gap: 16px; height: 100%; }
        .switch-row:hover { background: rgba(255,255,255,0.05); }
        .switch-row > div { flex: 1; }
        .switch-row label { margin: 0 !important; font-size: 14px; font-weight: 500; color: white !important; cursor: pointer; }
        .switch-row .switch-desc { display: block; font-size: 12px; color: var(--text-sub); margin-top: 4px; font-weight: normal; }
        
        #toast-container { position: fixed; top: 24px; left: 50%; transform: translateX(-50%) translateY(-20px); z-index: 2000; opacity: 0; transition: 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); pointer-events: none; }
        #toast-container.show { opacity: 1; transform: translateX(-50%) translateY(0); pointer-events: auto; }
        .toast { background: rgba(30, 41, 59, 0.85); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); color: white; padding: 12px 28px; border-radius: 16px; font-size: 14px; font-weight: 500; border: 1px solid rgba(255,255,255,0.15); display: flex; align-items: center; gap: 10px; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.4), 0 8px 10px -6px rgba(0, 0, 0, 0.4); }
        
        .mobile-menu-btn { display: none; background: none; border: none; color: white; cursor: pointer; padding: 4px; }
        
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        @media (max-width: 768px) {
            .navbar { padding: 12px 16px; }
            .nav-brand span { display: none; }
            .content-area { padding: 16px; }
            .form-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="bg-blob blob-1"></div>
    <div class="bg-blob blob-2"></div>
    <div id="toast-container"><div id="status-toast" class="toast"></div></div>
    
    <!-- Unified Confirmation Modal -->
    <div id="confirm-modal-overlay" class="modal-overlay" style="display: none; z-index: 3000;">
        <div class="modal-content" style="max-width: 340px;">
            <div style="font-size: 32px; margin-bottom: 10px;">⚠️</div>
            <div id="confirm-modal-title" class="modal-title" style="font-size: 18px; margin-bottom: 12px;">确认操作</div>
            <p id="confirm-modal-text" style="color: white; margin-bottom: 24px; font-size: 14px; line-height: 1.5;"></p>
            <div style="display: flex; gap: 12px; width: 100%;">
                <button id="confirm-modal-cancel-btn" class="modal-btn" style="background: rgba(255,255,255,0.1); color: white; width: 50%;">取消</button>
                <button id="confirm-modal-ok-btn" class="modal-btn" style="background: var(--primary); color: white; width: 50%;">确定</button>
            </div>
        </div>
    </div>

    <!-- Email Invite Modal -->
    <div id="email-modal" class="modal-overlay" style="display: none; z-index: 2000;">
        <div class="modal-content" style="max-width: 450px; text-align: left;">
            <div class="modal-title" style="justify-content: flex-start; margin-bottom: 20px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--primary);"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
                <span>发送邀请邮件</span>
            </div>
            <div class="form-group">
                <label for="email_to" style="font-weight: 600;">接收邮箱</label>
                <input type="email" id="email_to" placeholder="user@example.com" autocomplete="off" style="width: 100%; padding: 12px 16px; background: var(--input-bg); border: 1px solid var(--border-color); border-radius: 10px; color: white; margin-top: 8px;">
            </div>
            <div class="form-group" style="margin-top: 16px;">
                <label for="email_body" style="font-weight: 600;">邮件内容 (可编辑)</label>
                <textarea id="email_body" rows="6" style="width: 100%; padding: 12px 16px; background: var(--input-bg); border: 1px solid var(--border-color); border-radius: 10px; color: white; resize: vertical; margin-top: 8px; font-family: inherit; font-size: 14px; line-height: 1.5;"></textarea>
            </div>
            <div style="display: flex; gap: 12px; width: 100%; margin-top: 24px;">
                <button class="modal-btn" onclick="closeEmailModal()" style="background: rgba(255,255,255,0.1); color: white; width: 50%;">取消</button>
                <button class="modal-btn" id="btn-send-mail" onclick="sendEmail()" style="background: var(--primary); color: white; width: 50%;">发送</button>
            </div>
        </div>
    </div>

    <nav class="navbar">
        <div style="display:flex; align-items:center; gap: 12px;">
            <button class="menu-toggle-btn" onclick="toggleSidebar()">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
            </button>
            <a href="#" class="nav-brand">
                <img src="https://emby.media/favicon-96x96.png" alt="Emby">
                <span>管理后台</span>
            </a>
        </div>
        <div class="nav-links">
            <?php if (isset($_SESSION['emby_user_id'])): ?>
            <a href="user.php" class="btn-portal">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                用户门户
            </a>
            <?php endif; ?>
            <a href="?action=logout" class="logout-link">退出登录</a>
        </div>
    </nav>

    <div class="main-container">
        <div class="admin-panel">
            <div class="panel-layout">
                <div class="sidebar" id="sidebar">
                    
                    <button class="tab-btn active" onclick="switchTab(0)">
                        <div class="tab-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg></div>
                        <span class="tab-text">邀请码管理</span>
                    </button>
                    <button class="tab-btn" onclick="switchTab(1)">
                        <div class="tab-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polygon points="10 8 16 12 10 16 10 8"></polygon></svg></div>
                        <span class="tab-text">求片管理</span>
                    </button>
                    <button class="tab-btn" onclick="switchTab(2)">
                        <div class="tab-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></div>
                        <span class="tab-text">用户列表</span>
                    </button>
                    <button class="tab-btn" onclick="switchTab(3)">
                        <div class="tab-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg></div>
                        <span class="tab-text">系统设置</span>
                    </button>
                </div>
                
                <div class="content-area">
                    <!-- Tab 1: Invite Codes -->
                    <div class="tab-content active" id="tab-0">
                        <div class="header-flex">
                            <div class="section-title">邀请码 (<?php echo count($all_invite_codes); ?>)</div>
                            <button class="btn btn-primary" onclick="ajaxAction('generate')">生成新邀请码</button>
                        </div>
                        <div class="table-wrapper">
                            <table>
                                <thead><tr><th>邀请码</th><th>注册链接</th><th>操作</th></tr></thead>
                                <tbody>
                                    <?php foreach ($all_invite_codes as $code): 
                                        $invite_link = rtrim($base_url, '/') . '/' . ltrim($register_page_path, '/') . '?invite_code=' . urlencode($code); 
                                    ?>
                                    <tr>
                                        <td class="col-code"><span class="badge"><?php echo $code; ?></span></td>
                                        <td class="col-link">
                                            <div class="link-wrapper">
                                                <span class="link-text" title="<?php echo htmlspecialchars($invite_link); ?>"><?php echo htmlspecialchars($invite_link); ?></span>
                                                <button type="button" class="copy-action-btn" onclick="copyInviteLink(this, '<?php echo htmlspecialchars(addslashes($invite_link)); ?>')">
                                                    <svg class="copy-icon" viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                                </button>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 8px;">
                                                <button class="btn btn-primary" onclick="openEmailModal('<?php echo $code; ?>', '<?php echo htmlspecialchars(addslashes($invite_link)); ?>')" style="padding: 6px 12px; font-size: 12px;">发送</button>
                                                <button class="btn btn-danger" onclick="showConfirm('删除邀请码', '确定删除该邀请码吗？', () => ajaxAction('delete', {code: '<?php echo $code; ?>'}))" style="padding: 6px 12px; font-size: 12px;">删除</button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Tab 2: Requests -->
                    <div class="tab-content" id="tab-1">
                        <div class="header-flex" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                            <div class="section-title">求片管理</div>
                            <div style="display:flex; gap:8px;">
                                <button type="button" class="btn btn-danger-solid" onclick="showConfirm('清理历史求片', '您确定要删除所有「已批准」和「已拒绝」的已处理求片记录吗？此操作无法撤销。', () => ajaxAction('clear_processed_requests'))" style="padding: 6px 12px; font-size: 12px; font-weight: normal; border: 1px solid rgba(239, 68, 68, 0.3);">清空已处理</button>
                                <button type="button" class="btn btn-danger" onclick="showConfirm('清理全部求片', '🚨 警告：这会彻底删除包括「待处理」在内的所有求片记录！您确定要清空全部求片吗？', () => ajaxAction('clear_all_requests'))" style="padding: 6px 12px; font-size: 12px; font-weight: normal;">清空全部</button>
                            </div>
                        </div>
                        <div class="table-wrapper">
                            <table>
                                <thead><tr><th>影片</th><th>用户</th><th>时间</th><th>状态</th><th>操作</th></tr></thead>
                                <tbody>
                                    <?php foreach ($all_requests as $req): 
                                        $status_text = '待处理';
                                        if ($req['status'] === 'approved') $status_text = '已批准';
                                        elseif ($req['status'] === 'rejected') $status_text = '已拒绝';
                                    ?>
                                    <tr>
                                        <td><div style="display:flex; align-items:center; gap:10px;">
                                            <?php if($req['poster_url']): ?><img src="<?php echo htmlspecialchars($req['poster_url']); ?>" style="width:30px; border-radius:4px;"><?php endif; ?>
                                            <span><?php echo htmlspecialchars($req['title']); ?></span>
                                        </div></td>
                                        <td><?php echo htmlspecialchars($req['emby_username']); ?></td>
                                        <td style="font-size:12px; color:var(--text-sub);"><?php echo htmlspecialchars($req['created_at']); ?></td>
                                        <td><span class="badge <?php echo htmlspecialchars($req['status']); ?>"><?php echo $status_text; ?></span></td>
                                        <td>
                                            <div style="display: flex; gap: 8px;">
                                                <?php if($req['status'] === 'pending'): ?>
                                                <button class="btn btn-primary" onclick="showConfirm('批准求片', '确定批准该求片申请吗？', () => ajaxAction('approve_request', {id: <?php echo $req['id']; ?>}))">批准</button>
                                                <button class="btn btn-danger" onclick="showConfirm('拒绝求片', '确定拒绝该求片申请吗？', () => ajaxAction('reject_request', {id: <?php echo $req['id']; ?>}))">拒绝</button>
                                                <?php endif; ?>
                                                <button class="btn btn-danger-solid" onclick="showConfirm('删除记录', '确定删除该求片记录吗？', () => ajaxAction('delete_request', {id: <?php echo $req['id']; ?>}))">删除</button>
                                            </div>
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
                        <div class="table-wrapper">
                            <table>
                                <thead><tr><th>用户名</th><th>最后活动</th><th>状态</th><th>操作</th></tr></thead>
                                <tbody>
                                    <?php foreach ($emby_users as $user): 
                                        $is_disabled = !empty($user['Policy']['IsDisabled']);
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($user['Name']); ?></td>
                                        <td style="font-size:12px; color:var(--text-sub);"><?php echo isset($user['LastActivityDate']) ? substr($user['LastActivityDate'], 0, 10) : '从未登录'; ?></td>
                                        <td><?php echo $is_disabled ? '<span class="badge rejected">封禁</span>' : '<span class="badge approved">正常</span>'; ?></td>
                                        <td>
                                            <div style="display: flex; gap: 8px;">
                                                <?php if ($is_disabled): ?>
                                                <button class="btn btn-primary" onclick="showConfirm('解封用户', '确定解封此用户吗？', () => ajaxAction('unban_user', {uid: '<?php echo $user['Id']; ?>'}))">解封</button>
                                                <?php else: ?>
                                                <button class="btn btn-danger" onclick="showConfirm('封禁用户', '确定封禁此用户吗？', () => ajaxAction('ban_user', {uid: '<?php echo $user['Id']; ?>'}))">封禁</button>
                                                <?php endif; ?>
                                                <button class="btn btn-danger-solid" onclick="showConfirm('删除用户', '确定彻底从 Emby 服务器删除此用户吗？此操作无法撤销！', () => ajaxAction('delete_user', {uid: '<?php echo $user['Id']; ?>'}))">删除</button>
                                            </div>
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
                            <button type="button" class="btn btn-primary" onclick="saveSettings()" style="padding: 8px 16px; font-size: 13px;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                                保存
                            </button>
                        </div>
                        <form id="settings-form">
                            
                            <div class="settings-section">
                                <h3><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect><rect x="2" y="14" width="20" height="8" rx="2" ry="2"></rect><line x1="6" y1="6" x2="6.01" y2="6"></line><line x1="6" y1="18" x2="6.01" y2="18"></line></svg> Emby 服务器</h3>
                                <div class="form-grid">
                                    <div class="form-group"><label>Base URL</label><input type="text" name="emby_base_url" value="<?php echo htmlspecialchars($config['emby']['base_url']); ?>"></div>
                                    <div class="form-group"><label>模板用户 ID</label><input type="text" name="template_user_id" value="<?php echo htmlspecialchars($config['emby']['template_user_id']); ?>"></div>
                                    <div class="form-group"><label>API Token (留空不修改)</label><input type="password" name="emby_api_token" placeholder="••••••••••••••••"></div>
                                </div>
                            </div>

                            <div class="settings-section">
                                <h3><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg> 站点基础配置</h3>
                                <div class="form-grid">
                                    <div class="form-group" style="grid-column: 1 / -1;"><label>用户公网访问登录页 (留空则自动走上方 Base URL)</label><input type="text" name="login_url" value="<?php echo htmlspecialchars($config['site']['login_url'] ?? ''); ?>" placeholder="例如: https://emby.lxzy.fun:48920"></div>
                                </div>
                            </div>

                            <div class="settings-section">
                                <h3><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg> 管理员与安全</h3>
                                <div class="form-grid">
                                    <div class="form-group"><label>后台用户名</label><input type="text" name="admin_username" value="<?php echo htmlspecialchars($config['admin']['username']); ?>"></div>
                                    <div class="form-group"><label>后台密码 (留空不修改)</label><input type="password" name="admin_password" placeholder="••••••••"></div>
                                </div>
                            </div>
                            
                             <div class="settings-section">
                                 <h3><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg> SMTP 邮件与模版</h3>
                                 <div class="form-grid">
                                     <div class="form-group"><label>SMTP 服务器</label><input type="text" name="smtp_host" value="<?php echo htmlspecialchars($config['smtp']['host']); ?>"></div>
                                     <div class="form-group"><label>SMTP 端口</label><input type="number" name="smtp_port" value="<?php echo htmlspecialchars($config['smtp']['port']); ?>"></div>
                                     <div class="form-group"><label>发件人显示名称</label><input type="text" name="smtp_from_name" value="<?php echo htmlspecialchars($config['smtp']['from_name'] ?? 'Emby Admin'); ?>"></div>
                                     <div class="form-group">
                                         <label>加密方式</label>
                                         <select name="smtp_secure" style="width:100%; height:40px; background: var(--input-bg); border: 1px solid var(--border-color); border-radius: 8px; color: white; padding: 0 12px; outline:none; cursor:pointer;">
                                             <option value="ssl" <?php echo ($config['smtp']['secure'] ?? 'ssl') === 'ssl' ? 'selected' : ''; ?>>SSL (端口 465)</option>
                                             <option value="tls" <?php echo ($config['smtp']['secure'] ?? 'ssl') === 'tls' ? 'selected' : ''; ?>>TLS (端口 587)</option>
                                             <option value="none" <?php echo ($config['smtp']['secure'] ?? 'ssl') === 'none' ? 'selected' : ''; ?>>无加密</option>
                                         </select>
                                     </div>
                                     <div class="form-group"><label>发件邮箱账号</label><input type="text" name="smtp_username" value="<?php echo htmlspecialchars($config['smtp']['username']); ?>"></div>
                                     <div class="form-group"><label>邮箱密码/授权码 (留空不修改)</label><input type="password" name="smtp_password" placeholder="••••••••"></div>
                                     
                                     <div class="form-group" style="grid-column: 1 / -1;"><label>邀请邮件主题</label><input type="text" name="email_subject" value="<?php echo htmlspecialchars($config['email_template']['subject'] ?? 'Emby 媒体服务器邀请函'); ?>"></div>
                                     
                                     <div class="form-group" style="grid-column: 1 / -1;">
                                         <label>邀请邮件正文模版 (支持 {code} 占命符，系统会自动替换为随机生成的激活码)</label>
                                         <textarea name="email_template_body" rows="6" style="width:100%; font-family: 'SF Mono', 'Roboto Mono', monospace; font-size: 13px; line-height: 1.5; background: var(--input-bg); border: 1px solid var(--border-color); border-radius: 8px; color: white; padding: 12px; outline:none; resize: vertical;"><?php echo htmlspecialchars($email_template_content); ?></textarea>
                                     </div>
                                 </div>
                             </div>

                            <div class="settings-section">
                                <h3><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon></svg> 求片与通知</h3>
                                <div class="form-grid">
                                    <div class="form-group"><label>TMDB API Key (留空不修改)</label><input type="password" name="tmdb_api_key" placeholder="••••••••"></div>
                                    <div class="form-group"><label>TMDB HTTP代理</label><input type="text" name="tmdb_proxy" value="<?php echo htmlspecialchars($config['tmdb']['proxy'] ?? ''); ?>" placeholder="例如: tcp://127.0.0.1:7890"></div>
                                </div>
                                
                                <div class="switch-grid">
                                    <div class="switch-row">
                                        <div>
                                            <label for="enable_admin_email">接收新求片通知</label>
                                            <span class="switch-desc">用户提交新求片时，发送邮件通知管理员 (需正确配置SMTP)</span>
                                        </div>
                                        <label class="toggle-switch">
                                            <input type="checkbox" name="enable_admin_email" id="enable_admin_email" value="1" <?php echo ($config['notification']['enable_admin_email_notify'] ?? false) ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <div class="switch-row">
                                        <div>
                                            <label for="enable_user_email">发送处理结果通知 (全局开关)</label>
                                            <span class="switch-desc">管理员批准或拒绝求片后，向用户发送邮件通知 (需正确配置SMTP)</span>
                                        </div>
                                        <label class="toggle-switch">
                                            <input type="checkbox" name="enable_user_email" id="enable_user_email" value="1" <?php echo ($config['notification']['enable_user_email_notify'] ?? false) ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="settings-section">
                                <h3><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg> 自动清理</h3>
                                
                                <div class="switch-grid">
                                    <div class="switch-row">
                                        <div>
                                            <label for="enable_autoban">非活跃用户自动封禁</label>
                                            <span class="switch-desc">自动封禁超过设定天数未登录的账户</span>
                                        </div>
                                        <label class="toggle-switch">
                                            <input type="checkbox" name="enable_autoban" id="enable_autoban" value="1" <?php echo ($config['auto_ban']['enable'] ?? false) ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <div class="switch-row">
                                        <div>
                                            <label for="enable_autodel_req">过期求片记录自动清理</label>
                                            <span class="switch-desc">自动删除过期的历史求片记录，保持数据库清爽</span>
                                        </div>
                                        <label class="toggle-switch">
                                            <input type="checkbox" name="enable_autodel_req" id="enable_autodel_req" value="1" <?php echo ($config['auto_delete_requests']['enable'] ?? false) ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>
                                <div class="form-grid">
                                    <div class="form-group" style="margin-bottom:0;"><label>判定非活跃天数</label><input type="number" name="autoban_days" value="<?php echo htmlspecialchars($config['auto_ban']['days'] ?? 30); ?>"></div>
                                    <div class="form-group" style="margin-bottom:0;"><label>判定过期天数</label><input type="number" name="autodel_req_days" value="<?php echo htmlspecialchars($config['auto_delete_requests']['days'] ?? 30); ?>"></div>
                                </div>
                            </div>

                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($is_authenticated): ?>
    <script>
        const csrfToken = "<?php echo $_SESSION['csrf_token']; ?>";
        const emailTemplate = <?php echo $js_template_body; ?>;

        function openEmailModal(code, link) {
            const modal = document.getElementById('email-modal');
            const bodyInput = document.getElementById('email_body');
            const emailInput = document.getElementById('email_to');
            let content = emailTemplate.replace(/{code}/g, code).replace(/{link}/g, link);
            bodyInput.value = content; 
            emailInput.value = ''; 
            modal.style.display = 'flex'; 
            emailInput.focus();
        }

        function closeEmailModal() { 
            document.getElementById('email-modal').style.display = 'none'; 
        }

        async function sendEmail() {
            const email = document.getElementById('email_to').value;
            const body = document.getElementById('email_body').value;
            const btn = document.getElementById('btn-send-mail');
            
            if (!email) { 
                displayToast('请输入邮箱地址', 'error'); 
                return; 
            }

            const original = btn.innerText; 
            btn.disabled = true;
            btn.innerText = '发送中...';

            try {
                const formData = new URLSearchParams();
                formData.append('ajax', '1');
                formData.append('csrf_token', csrfToken);
                formData.append('action', 'send_email');
                formData.append('email', email);
                formData.append('body', body);

                const response = await fetch("admin.php", {
                    method: "POST", 
                    headers: { "Content-Type": "application/x-www-form-urlencoded" },
                    body: formData.toString()
                });
                
                if (!response.ok) throw new Error(`HTTP Error: ${response.status}`);
                const data = await response.json();
                
                displayToast(data.message, data.status);
                if (data.status === 'success') {
                    closeEmailModal();
                }
            } catch (error) {
                console.error('Email sending error:', error);
                displayToast('网络请求失败', 'error');
            } finally {
                btn.disabled = false; 
                btn.innerText = original;
            }
        }

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('collapsed');
        }

        function switchTab(index) {
            document.querySelectorAll('.tab-btn').forEach((btn, i) => {
                if(i < 4) btn.classList.toggle('active', i === index); // 4 tabs
            });
            document.querySelectorAll('.tab-content').forEach((content, i) => {
                content.classList.toggle('active', i === index);
            });
            history.replaceState(null, null, '#tab-' + index);
        }

        document.addEventListener("DOMContentLoaded", () => {
            if (window.innerWidth <= 768) {
                document.getElementById('sidebar').classList.add('collapsed');
            }
            const hash = location.hash;
            if (hash && hash.startsWith('#tab-')) {
                const idx = parseInt(hash.replace('#tab-', ''));
                if (!isNaN(idx) && idx >= 0 && idx < 4) {
                    switchTab(idx);
                }
            }
        });

        function displayToast(msg, type = 'info') {
            const container = document.getElementById('toast-container');
            const toast = document.getElementById('status-toast');
            let icon = 'ℹ️';
            if (type === 'success' || msg.includes('成功') || msg.includes('已')) icon = '✅';
            if (type === 'error' || msg.includes('失败') || msg.includes('错误') || msg.includes('出错')) icon = '❌';
            toast.innerHTML = `<span style="font-size: 16px;">${icon}</span> <span>${msg}</span>`;
            container.classList.add('show');
            setTimeout(() => container.classList.remove('show'), 3000);
        }

        function showConfirm(title, message, onConfirm) {
            const overlay = document.getElementById('confirm-modal-overlay');
            const titleEl = document.getElementById('confirm-modal-title');
            const textEl = document.getElementById('confirm-modal-text');
            const cancelBtn = document.getElementById('confirm-modal-cancel-btn');
            const okBtn = document.getElementById('confirm-modal-ok-btn');
            
            titleEl.innerText = title;
            textEl.innerText = message;
            overlay.style.display = 'flex';
            
            const cleanup = () => {
                overlay.style.display = 'none';
                const newCancel = cancelBtn.cloneNode(true);
                const newOk = okBtn.cloneNode(true);
                cancelBtn.parentNode.replaceChild(newCancel, cancelBtn);
                okBtn.parentNode.replaceChild(newOk, okBtn);
            };
            
            document.getElementById('confirm-modal-cancel-btn').onclick = cleanup;
            document.getElementById('confirm-modal-ok-btn').onclick = () => {
                cleanup();
                if (typeof onConfirm === 'function') onConfirm();
            };
        }

        function copyInviteLink(btn, text) {
            const original = btn.innerHTML;
            const temp = document.createElement('textarea');
            temp.value = text; temp.style.position = 'fixed'; temp.style.left = '-9999px';
            document.body.appendChild(temp); temp.select();
            
            let ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { console.error(e); }
            document.body.removeChild(temp);

            if (ok) {
                btn.innerHTML = '<svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
                btn.classList.add('success');
                setTimeout(() => { btn.innerHTML = original; btn.classList.remove('success'); }, 1500); 
            } else {
                displayToast('复制失败，请手动复制'); 
            }
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
                    if (action === 'save_settings') {
                        setTimeout(() => window.location.replace(window.location.href), 1000);
                    } else {
                        pollAdminData();
                    }
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
            if(!dataObj.enable_admin_email) dataObj.enable_admin_email = '0';
            if(!dataObj.enable_user_email) dataObj.enable_user_email = '0';
            if(!dataObj.enable_autoban) dataObj.enable_autoban = '0';
            if(!dataObj.enable_autodel_req) dataObj.enable_autodel_req = '0';
            
            ajaxAction('save_settings', dataObj);
        }

        // Live sync background data
        async function pollAdminData() {
            try {
                const formData = new URLSearchParams();
                formData.append('ajax', '1');
                formData.append('csrf_token', csrfToken);
                formData.append('action', 'poll_data');

                const response = await fetch("admin.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/x-www-form-urlencoded" },
                    body: formData.toString()
                });
                const data = await response.json();
                if (data.status === 'success') {
                    const inviteTbody = document.querySelector('#tab-0 table tbody');
                    if (inviteTbody) inviteTbody.innerHTML = data.invite_codes_html;
                    
                    const reqTbody = document.querySelector('#tab-1 table tbody');
                    if (reqTbody) reqTbody.innerHTML = data.requests_html;
                    
                    const userTbody = document.querySelector('#tab-2 table tbody');
                    if (userTbody) userTbody.innerHTML = data.users_html;

                    const inviteTitle = document.querySelector('#tab-0 .section-title');
                    if (inviteTitle) inviteTitle.innerText = `邀请码 (${data.invite_codes_count})`;

                    const userTitle = document.querySelector('#tab-2 .section-title');
                    if (userTitle) userTitle.innerText = `用户列表 (${data.users_count})`;
                }
            } catch (e) {
                console.error("Polling error", e);
            }
        }

        // Poll every 8 seconds
        setInterval(pollAdminData, 8000);

        // Click outside modals to close them
        window.onclick = function(event) {
            const emailOverlay = document.getElementById('email-modal');
            if (event.target === emailOverlay) {
                closeEmailModal();
            }
        }
    </script>
    <?php endif; ?>
</body>
</html>
