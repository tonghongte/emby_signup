<?php
// Emby 注册管理后台
// ----------------------------------------------------
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/EmbyApi.php';
require_once __DIR__ . '/../src/ConfigHelper.php';
require_once __DIR__ . '/../src/Mailer.php';

Auth::initSession();

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

$is_authenticated = Auth::checkAdmin();

if (!$is_authenticated) {
    header("Location: login.php");
    exit;
}

/**
 * 解析模板 ID 显示名称 (无绑定显示"默认")
 */
function templateLabel($template_id): string
{
    global $invite_db;
    if ($template_id === null || $template_id === '') return '默认';
    $tpl = $invite_db->getTemplateById((int)$template_id);
    return $tpl ? $tpl['name'] : '已删除模板';
}

/**
 * 渲染单行「邀请码」表格 HTML
 */
function renderInviteCodeRow(array $row, string $base_url, string $register_page_path): string
{
    $code = $row['code'];
    $invite_link = rtrim($base_url, '/') . '/' . ltrim($register_page_path, '/') . '?invite_code=' . urlencode($code);
    $tpl_name = templateLabel($row['template_id'] ?? null);

    ob_start();
    ?>
    <tr>
        <td class="col-code"><span class="badge"><?php echo $code; ?></span></td>
        <td><span class="badge"><?php echo htmlspecialchars($tpl_name); ?></span></td>
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
    return ob_get_clean();
}

/**
 * 渲染单行「邀请申请」表格 HTML
 */
function renderInviteRequestRow(array $req): string
{
    $status_map = ['pending' => '待处理', 'sent' => '已发送', 'ignored' => '已忽略'];
    $status_text = $status_map[$req['status']] ?? $req['status'];
    $badge_class = $req['status'] === 'sent' ? 'approved' : ($req['status'] === 'ignored' ? 'rejected' : '');
    $tpl_name = templateLabel($req['template_id'] ?? null);

    ob_start();
    ?>
    <tr>
        <td><?php echo htmlspecialchars($req['email']); ?></td>
        <td><span class="badge"><?php echo htmlspecialchars($tpl_name); ?></span></td>
        <td style="font-size:12px; color:var(--text-sub);"><?php echo htmlspecialchars($req['created_at']); ?></td>
        <td><span class="badge <?php echo $badge_class; ?>"><?php echo $status_text; ?></span></td>
        <td>
            <div style="display: flex; gap: 8px;">
                <?php if ($req['status'] === 'pending'): ?>
                <button class="btn btn-primary" onclick="showConfirm('发送邀请码', '确定生成新邀请码并发送至 <?php echo htmlspecialchars(addslashes($req['email'])); ?> 吗？', () => ajaxAction('send_invite_to_request', {id: <?php echo $req['id']; ?>}))">发送邀请码</button>
                <button class="btn btn-danger" onclick="showConfirm('忽略申请', '确定忽略该邀请码申请吗？', () => ajaxAction('ignore_invite_request', {id: <?php echo $req['id']; ?>}))">忽略</button>
                <?php endif; ?>
                <button class="btn btn-danger-solid" onclick="showConfirm('删除记录', '确定删除该申请记录吗？', () => ajaxAction('delete_invite_request', {id: <?php echo $req['id']; ?>}))">删除</button>
            </div>
        </td>
    </tr>
    <?php
    return ob_get_clean();
}

/**
 * 渲染单行「模板账号」表格 HTML
 */
function renderTemplateRow(array $tpl): string
{
    $enabled = !empty($tpl['enabled']);
    ob_start();
    ?>
    <tr>
        <td><?php echo htmlspecialchars($tpl['name']); ?></td>
        <td style="font-size:12px; color:var(--text-sub);"><?php echo htmlspecialchars($tpl['emby_user_id']); ?></td>
        <td><?php echo $enabled ? '<span class="badge approved">启用</span>' : '<span class="badge rejected">停用</span>'; ?></td>
        <td>
            <div style="display: flex; gap: 8px;">
                <?php if ($enabled): ?>
                <button class="btn btn-danger" onclick="ajaxAction('toggle_template', {id: <?php echo $tpl['id']; ?>, enabled: '0'})">停用</button>
                <?php else: ?>
                <button class="btn btn-primary" onclick="ajaxAction('toggle_template', {id: <?php echo $tpl['id']; ?>, enabled: '1'})">启用</button>
                <?php endif; ?>
                <button class="btn btn-danger-solid" onclick="showConfirm('删除模板', '确定删除该模板吗？已绑定此模板的邀请码将回退至默认模板。', () => ajaxAction('delete_template', {id: <?php echo $tpl['id']; ?>}))">删除</button>
            </div>
        </td>
    </tr>
    <?php
    return ob_get_clean();
}

// ----------------------------------------------------
// AJAX 请求处理逻辑
// ----------------------------------------------------
if ($is_authenticated && isset($_POST['ajax'])) {
    header("Content-Type: application/json; charset=utf-8");
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        echo json_encode(['status' => 'error', 'message' => 'CSRF 验证失败']); exit;
    }
    
    $response = ['status' => 'error', 'message' => '未知操作'];
    $action = $_POST['action'] ?? '';
    
    if ($action === 'poll_data') {
        $all_invite_codes = $invite_db->getAllUnusedCodesDetailed();
        $all_requests = $invite_db->getAllRequests();
        $all_invite_requests = $invite_db->getAllInviteRequests();
        $all_templates = $invite_db->getAllTemplates();
        $emby_users = EmbyApi::getEmbyUsers() ?: [];

        // 生成邀请码表格 HTML 行段
        $invite_codes_tbody = '';
        foreach ($all_invite_codes as $code_row) {
            $invite_codes_tbody .= renderInviteCodeRow($code_row, $base_url, $register_page_path);
        }

        // 生成求片请求表格 HTML 行段
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

        // 生成 Emby 用户表格 HTML 行段
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

        // 生成邀请申请表格 HTML 行段
        $invite_requests_tbody = '';
        foreach ($all_invite_requests as $req) {
            $invite_requests_tbody .= renderInviteRequestRow($req);
        }
        $pending_invite_requests = 0;
        foreach ($all_invite_requests as $req) {
            if ($req['status'] === 'pending') $pending_invite_requests++;
        }

        // 生成模板账号表格 HTML 行段
        $templates_tbody = '';
        foreach ($all_templates as $tpl) {
            $templates_tbody .= renderTemplateRow($tpl);
        }

        echo json_encode([
            'status' => 'success',
            'invite_codes_count' => count($all_invite_codes),
            'requests_count' => count($all_requests),
            'invite_requests_count' => $pending_invite_requests,
            'templates_count' => count($all_templates),
            'users_count' => count($emby_users),
            'invite_codes_html' => $invite_codes_tbody,
            'requests_html' => $requests_tbody,
            'invite_requests_html' => $invite_requests_tbody,
            'templates_html' => $templates_tbody,
            'users_html' => $users_tbody
        ]);
        exit;
    }
    
    if ($action === 'generate') {
        $gen_tpl = (isset($_POST['template_id']) && $_POST['template_id'] !== '') ? (int)$_POST['template_id'] : null;
        if ($invite_db->insertCode(InviteDB::generateRandomCode(), $gen_tpl)) {
            $response = ['status' => 'success', 'message' => '邀请码已生成'];
        }
    } elseif ($action === 'add_template') {
        $name = trim($_POST['name'] ?? '');
        $uid = trim($_POST['emby_user_id'] ?? '');
        if ($name === '' || $uid === '') {
            $response['message'] = '模板名称和 Emby 用户 ID 不能为空';
        } elseif ($invite_db->addTemplate($name, $uid)) {
            $response = ['status' => 'success', 'message' => '模板已添加'];
        }
    } elseif ($action === 'delete_template') {
        if ($invite_db->deleteTemplate((int)$_POST['id'])) {
            $response = ['status' => 'success', 'message' => '模板已删除'];
        }
    } elseif ($action === 'toggle_template') {
        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1';
        if ($invite_db->setTemplateEnabled((int)$_POST['id'], $enabled)) {
            $response = ['status' => 'success', 'message' => $enabled ? '模板已启用' : '模板已停用'];
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
                $user_info_raw = EmbyApi::apiRequest("/emby/Users/{$req['emby_user_id']}");
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
    } elseif ($action === 'send_invite_to_request') {
        $req_id = (int)$_POST['id'];
        $req = $invite_db->getInviteRequestById($req_id);
        if (!$req) {
            $response['message'] = '申请记录不存在';
        } elseif (!filter_var($req['email'], FILTER_VALIDATE_EMAIL)) {
            $response['message'] = '该申请的邮箱格式不正确';
        } elseif (empty($config['smtp']['host'])) {
            $response['message'] = '未配置 SMTP 信息，无法发送邀请码';
        } else {
            // 生成新邀请码并写入数据库 (继承申请时选择的模板)
            $req_tpl = (isset($req['template_id']) && $req['template_id'] !== null) ? (int)$req['template_id'] : null;
            $new_code = InviteDB::generateRandomCode();
            if (!$invite_db->insertCode($new_code, $req_tpl)) {
                $response['message'] = '邀请码生成失败，请重试';
            } else {
                $invite_link = rtrim($base_url, '/') . '/' . ltrim($register_page_path, '/') . '?invite_code=' . urlencode($new_code);

                // 套用邮件模版
                $template_path = $config['email_template']['template_path'] ?? __DIR__ . '/../config/email_template.txt';
                $body = file_exists($template_path) ? file_get_contents($template_path) : "您的邀请码：{code}\n注册链接：{link}";
                $body = str_replace(['{code}', '{link}'], [$new_code, $invite_link], $body);
                $subject = $config['email_template']['subject'] ?? 'Emby 媒体服务器邀请函';

                $res = send_smtp_email($config['smtp'], $req['email'], $subject, $body);
                if ($res['status']) {
                    $invite_db->updateInviteRequestStatus($req_id, 'sent', $new_code);
                    $response = ['status' => 'success', 'message' => '邀请码已发送至 ' . $req['email']];
                } else {
                    $response = ['status' => 'error', 'message' => '邮件发送失败: ' . $res['message']];
                }
            }
        }
    } elseif ($action === 'ignore_invite_request') {
        $req_id = (int)$_POST['id'];
        if ($invite_db->updateInviteRequestStatus($req_id, 'ignored')) {
            $response = ['status' => 'success', 'message' => '申请已忽略'];
        }
    } elseif ($action === 'delete_invite_request') {
        $req_id = (int)$_POST['id'];
        if ($invite_db->deleteInviteRequest($req_id)) {
            $response = ['status' => 'success', 'message' => '申请记录已删除'];
        }
    } elseif ($action === 'ban_user') {
        $uid = $_POST['uid'];
        if (EmbyApi::banEmbyUser($uid)) $response = ['status' => 'success', 'message' => '用户已封禁'];
        else $response = ['status' => 'error', 'message' => '封禁失败'];
    } elseif ($action === 'unban_user') {
        $uid = $_POST['uid'];
        if (EmbyApi::unbanEmbyUser($uid)) $response = ['status' => 'success', 'message' => '用户已解封'];
        else $response = ['status' => 'error', 'message' => '解封失败'];
    } elseif ($action === 'delete_user') {
        $uid = $_POST['uid'];
        if (EmbyApi::deleteEmbyUser($uid)) $response = ['status' => 'success', 'message' => '用户已彻底删除'];
        else $response = ['status' => 'error', 'message' => '删除失败'];
    } elseif ($action === 'save_settings') {
        $config_file = __DIR__ . '/../config/config.php';
        
        // 自动注入缺失的配置块 (兼容老版本)
        ConfigHelper::checkAndInjectMissingConfigBlocks($config_file);
        
        // Emby
        if (!empty($_POST['emby_base_url'])) ConfigHelper::updateConfigValue($config_file, 'EMBY_BASE_URL', $_POST['emby_base_url']);
        if (!empty($_POST['emby_api_token'])) ConfigHelper::updateConfigValue($config_file, 'EMBY_API_TOKEN', $_POST['emby_api_token']);
        
        // Admin
        if (!empty($_POST['admin_username'])) ConfigHelper::updateConfigValue($config_file, 'ADMIN_USERNAME', $_POST['admin_username']);
        if (!empty($_POST['admin_password'])) ConfigHelper::updateConfigValue($config_file, 'ADMIN_PASSWORD', $_POST['admin_password']);
        
        // Site
        if (!empty($_POST['login_url'])) ConfigHelper::updateConfigValue($config_file, 'SITE_LOGIN_URL', $_POST['login_url']);
        
        // SMTP
        if (!empty($_POST['smtp_host'])) ConfigHelper::updateConfigValue($config_file, 'SMTP_HOST', $_POST['smtp_host']);
        if (!empty($_POST['smtp_port'])) ConfigHelper::updateConfigValue($config_file, 'SMTP_PORT', $_POST['smtp_port'], 'int');
        if (!empty($_POST['smtp_username'])) ConfigHelper::updateConfigValue($config_file, 'SMTP_USERNAME', $_POST['smtp_username']);
        if (!empty($_POST['smtp_password'])) ConfigHelper::updateConfigValue($config_file, 'SMTP_PASSWORD', $_POST['smtp_password']);
        if (!empty($_POST['smtp_from_name'])) ConfigHelper::updateConfigValue($config_file, 'SMTP_FROM_NAME', $_POST['smtp_from_name']);
        if (!empty($_POST['smtp_secure'])) ConfigHelper::updateConfigValue($config_file, 'SMTP_SECURE', $_POST['smtp_secure']);
        
        // Email Template
        if (!empty($_POST['email_subject'])) ConfigHelper::updateConfigValue($config_file, 'EMAIL_SUBJECT', $_POST['email_subject']);
        if (isset($_POST['email_template_body'])) {
            $email_template_path = __DIR__ . '/../config/email_template.txt';
            file_put_contents($email_template_path, $_POST['email_template_body']);
        }
        
        // TMDB
        if (!empty($_POST['tmdb_api_key'])) ConfigHelper::updateConfigValue($config_file, 'TMDB_API_KEY', $_POST['tmdb_api_key']);
        if (isset($_POST['tmdb_proxy'])) ConfigHelper::updateConfigValue($config_file, 'TMDB_PROXY', $_POST['tmdb_proxy']);
        
        // Auto Ban & Notifications & Auto Delete Requests
        $enable_admin_email = isset($_POST['enable_admin_email']) && $_POST['enable_admin_email'] === '1';
        $enable_user_email = isset($_POST['enable_user_email']) && $_POST['enable_user_email'] === '1';
        ConfigHelper::updateConfigValue($config_file, 'ENABLE_ADMIN_EMAIL_NOTIFY', $enable_admin_email, 'bool');
        ConfigHelper::updateConfigValue($config_file, 'ENABLE_USER_EMAIL_NOTIFY', $enable_user_email, 'bool');
        
        $enable_autoban = isset($_POST['enable_autoban']) && $_POST['enable_autoban'] === '1';
        ConfigHelper::updateConfigValue($config_file, 'ENABLE_AUTO_BAN', $enable_autoban, 'bool');
        if (!empty($_POST['autoban_days'])) ConfigHelper::updateConfigValue($config_file, 'AUTO_BAN_DAYS', $_POST['autoban_days'], 'int');

        $enable_autodel_req = isset($_POST['enable_autodel_req']) && $_POST['enable_autodel_req'] === '1';
        ConfigHelper::updateConfigValue($config_file, 'ENABLE_AUTO_DELETE_REQUESTS', $enable_autodel_req, 'bool');
        if (!empty($_POST['autodel_req_days'])) ConfigHelper::updateConfigValue($config_file, 'AUTO_DELETE_REQUESTS_DAYS', $_POST['autodel_req_days'], 'int');

        $response = ['status' => 'success', 'message' => '配置已成功保存并生效'];
    }
    
    echo json_encode($response);
    exit; 
}

// ----------------------------------------------------
// 页面渲染逻辑
// ----------------------------------------------------
header("Content-Type: text/html; charset=utf-8");

// Fetch Data for Tabs
$all_invite_codes = $invite_db->getAllUnusedCodesDetailed();
$all_requests = $invite_db->getAllRequests();
$all_invite_requests = $invite_db->getAllInviteRequests();
$all_templates = $invite_db->getAllTemplates();
$enabled_templates = $invite_db->getEnabledTemplates();
$pending_invite_requests = 0;
foreach ($all_invite_requests as $ir) {
    if ($ir['status'] === 'pending') $pending_invite_requests++;
}
$emby_users = EmbyApi::getEmbyUsers() ?: [];

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
                    EmbyApi::banEmbyUser($user['Id']);
                }
            } catch (Exception $e) {
                // 忽略无效日期解析错误
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
    
    <!-- 引入系统通用与管理后台专有样式 -->
    <link rel="stylesheet" href="assets/css/common.css">
    <link rel="stylesheet" href="assets/css/admin.css">

    <!-- 注入安全全局变量与客户端交互逻辑 -->
    <script>
        window.AppConfig = {
            csrfToken: <?php echo json_encode(Auth::csrfToken()); ?>,
            emailTemplate: <?php echo $js_template_body; ?>
        };
    </script>
    <script src="assets/js/common.js"></script>
    <script src="assets/js/admin.js"></script>
</head>
<body>
    <div class="bg-blob blob-1"></div>
    <div class="bg-blob blob-2"></div>
    
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
            <a href="login.php?action=logout" class="logout-link">退出登录</a>
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
                        <div class="tab-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg></div>
                        <span class="tab-text">邀请申请</span>
                    </button>
                    <button class="tab-btn" onclick="switchTab(4)">
                        <div class="tab-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"></rect><rect x="14" y="3" width="7" height="7" rx="1"></rect><rect x="3" y="14" width="7" height="7" rx="1"></rect><rect x="14" y="14" width="7" height="7" rx="1"></rect></svg></div>
                        <span class="tab-text">模板管理</span>
                    </button>
                    <button class="tab-btn" onclick="switchTab(5)">
                        <div class="tab-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg></div>
                        <span class="tab-text">系统设置</span>
                    </button>
                </div>
                
                <div class="content-area">
                    <!-- Tab 1: Invite Codes -->
                    <div class="tab-content active" id="tab-0">
                        <div class="header-flex">
                            <div class="section-title">邀请码 (<?php echo count($all_invite_codes); ?>)</div>
                            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                <?php if (!empty($enabled_templates)): ?>
                                <select id="gen-template-select" style="height:38px; background: var(--input-bg); border: 1px solid var(--border-color); border-radius: 8px; color: white; padding: 0 12px; outline:none; cursor:pointer;">
                                    <option value="">默认模板</option>
                                    <?php foreach ($enabled_templates as $tpl): ?>
                                    <option value="<?php echo (int)$tpl['id']; ?>"><?php echo htmlspecialchars($tpl['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php endif; ?>
                                <button class="btn btn-primary" onclick="generateCode()">生成新邀请码</button>
                            </div>
                        </div>
                        <div class="table-wrapper">
                            <table>
                                <thead><tr><th>邀请码</th><th>模板</th><th>注册链接</th><th>操作</th></tr></thead>
                                <tbody>
                                    <?php foreach ($all_invite_codes as $code_row): echo renderInviteCodeRow($code_row, $base_url, $register_page_path); endforeach; ?>
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

                    <!-- Tab 4: Invite Requests -->
                    <div class="tab-content" id="tab-3">
                        <div class="header-flex">
                            <div class="section-title">邀请申请 (<?php echo $pending_invite_requests; ?>)</div>
                        </div>
                        <div class="table-wrapper">
                            <table>
                                <thead><tr><th>邮箱</th><th>模板</th><th>申请时间</th><th>状态</th><th>操作</th></tr></thead>
                                <tbody>
                                    <?php foreach ($all_invite_requests as $req): echo renderInviteRequestRow($req); endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Tab 5: Templates -->
                    <div class="tab-content" id="tab-4">
                        <div class="header-flex">
                            <div class="section-title">模板管理 (<?php echo count($all_templates); ?>)</div>
                            <button class="btn btn-primary" onclick="openTemplateModal()">添加模板</button>
                        </div>
                        <div class="table-wrapper">
                            <table>
                                <thead><tr><th>名称</th><th>Emby 用户 ID</th><th>状态</th><th>操作</th></tr></thead>
                                <tbody>
                                    <?php foreach ($all_templates as $tpl): echo renderTemplateRow($tpl); endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Tab 6: Settings -->
                    <div class="tab-content" id="tab-5">
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
                                            <label for="enable_admin_email">接收新求片/邀请申请通知</label>
                                            <span class="switch-desc">用户提交新求片或申请邀请码时，发送邮件通知管理员 (需正确配置SMTP)</span>
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

    <!-- 邀请邮件模态框 -->
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

    <!-- 添加模板模态框 -->
    <div id="template-modal" class="modal-overlay" style="display: none; z-index: 2000;">
        <div class="modal-content" style="max-width: 450px; text-align: left;">
            <div class="modal-title" style="justify-content: flex-start; margin-bottom: 20px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: var(--primary);"><rect x="3" y="3" width="7" height="7" rx="1"></rect><rect x="14" y="3" width="7" height="7" rx="1"></rect><rect x="3" y="14" width="7" height="7" rx="1"></rect><rect x="14" y="14" width="7" height="7" rx="1"></rect></svg>
                <span>添加模板账号</span>
            </div>
            <div class="form-group">
                <label for="template_name" style="font-weight: 600;">模板名称</label>
                <input type="text" id="template_name" placeholder="例如：标准会员" autocomplete="off" style="width: 100%; padding: 12px 16px; background: var(--input-bg); border: 1px solid var(--border-color); border-radius: 10px; color: white; margin-top: 8px;">
            </div>
            <div class="form-group" style="margin-top: 16px;">
                <label for="template_uid" style="font-weight: 600;">Emby 模板用户 ID</label>
                <input type="text" id="template_uid" placeholder="该模板用户在 Emby 中的 ID" autocomplete="off" style="width: 100%; padding: 12px 16px; background: var(--input-bg); border: 1px solid var(--border-color); border-radius: 10px; color: white; margin-top: 8px;">
            </div>
            <div style="display: flex; gap: 12px; width: 100%; margin-top: 24px;">
                <button class="modal-btn" onclick="closeTemplateModal()" style="background: rgba(255,255,255,0.1); color: white; width: 50%;">取消</button>
                <button class="modal-btn" id="btn-add-template" onclick="submitTemplate()" style="background: var(--primary); color: white; width: 50%;">添加</button>
            </div>
        </div>
    </div>
</body>
</html>
