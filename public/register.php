<?php
header("Content-Type: text/html; charset=utf-8");

$config_path = __DIR__ . '/../config/config.php';
$db_file_path = __DIR__ . '/../config/database.php';

if (!file_exists($config_path) || !file_exists($db_file_path)) {
    die("配置文件或数据库文件丢失，请检查路径配置！");
}

$config = require $config_path;
require_once $db_file_path;
global $invite_db;

$message = '';

if (isset($_GET['invite_code'])) {
    $invite_code_from_url = htmlspecialchars($_GET['invite_code']);
} else {
    $invite_code_from_url = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = htmlspecialchars($_POST['username']);
    $passwd = $_POST['passwd'];
    $confirm_passwd = $_POST['confirm_passwd'];
    $input_invite_code = trim($_POST['invite_code']); 

    $valid_codes = $invite_db->getAllUnusedCodes();

    if (!preg_match("/^[a-zA-Z0-9]{4,}$/", $username)) {
        $message = '用户名只允许包含数字和字母且至少需要4位！';
    } else if ($passwd !== $confirm_passwd) {
        $message = '两次输入的密码不一致！';
    } else if (!preg_match("/^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d@$!%*#?&]{8,}$/", $passwd)) {
        $message = '密码至少需要8位且必须包含数字和字母！';
    } else if (!in_array($input_invite_code, $valid_codes)) {
        $message = '邀请码无效！ '; 
    } else {
        if (!$invite_db->useCode($input_invite_code)) {
            $message = '邀请码已被其他请求使用，请重试或使用新的邀请码！';
        } else {
            require_once __DIR__ . '/../src/EmbyApi.php';
            // 解析邀请码绑定的模板账号 (无绑定则回退全局默认模板)
            $tpl_id = $invite_db->getCodeTemplateId($input_invite_code);
            $tpl_uid = $invite_db->getTemplateEmbyUserId($tpl_id);
            $reg_res = EmbyApi::createEmbyUser($username, $passwd, $config, $tpl_uid);
            
            if (!$reg_res['status']) {
                $invite_db->restoreCode($input_invite_code);
                $message = $reg_res['message'];
            } else {
                $emby_url = rtrim($config['emby']['base_url'], '/');
                $auth_url = "{$emby_url}/emby/Users/AuthenticateByName";
                $auth_header = 'Emby Client="Emby Signup Portal", Device="Web", DeviceId="portal_web_client", Version="1.0.0"';
                $data = array(
                    'Username' => $username,
                    'Pw' => $passwd
                );
                $options = array(
                    'http' => array(
                        'header'  => "Content-type: application/json\r\nAuthorization: " . $auth_header . "\r\n",
                        'method'  => 'POST',
                        'content' => json_encode($data),
                        'ignore_errors' => true
                    )
                );
                $context  = stream_context_create($options);
                $result = @file_get_contents($auth_url, false, $context);
                
                require_once __DIR__ . '/../src/Auth.php';
                Auth::initSession();

                if ($result !== FALSE) {
                    $response = json_decode($result, true);
                    if (isset($response['User']['Id']) && isset($response['AccessToken'])) {
                        $_SESSION['emby_user_id'] = $response['User']['Id'];
                        $_SESSION['emby_username'] = $response['User']['Name'];
                        $_SESSION['emby_token'] = $response['AccessToken'];
                        Auth::csrfToken();
                    }
                }
                
                if (empty($_SESSION['emby_user_id'])) {
                    $_SESSION['emby_user_id'] = $reg_res['user_id'];
                    $_SESSION['emby_username'] = $username;
                    $_SESSION['emby_token'] = 'session_authorized_at_reg';
                    Auth::csrfToken();
                }
                
                $message = '注册完成！';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Emby 媒体库注册</title>
    <link rel="icon" type="image/png" href="https://emby.media/favicon-32x32.png" sizes="32x32">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- 引入高颜值系统样式 -->
    <link rel="stylesheet" href="assets/css/common.css">
    <link rel="stylesheet" href="assets/css/register.css">
    
    <script>
        window.AppConfig = {
            loginUrl: <?php echo json_encode($config['site']['login_url'] ?? 'login.php'); ?>
        };
    </script>
    <script src="assets/js/register.js"></script>
</head>
<body>
    <div class="bg-blob blob-1"></div>
    <div class="bg-blob blob-2"></div>
    
    <div class="main-container">
        <div class="logo-section">
            <img src="https://emby.media/favicon-96x96.png" alt="Emby Logo">
            <h1>加入媒体库</h1>
            <p>创建您的 Emby 账户</p>
        </div>

        <div class="form-container">
            <form method="post" action="">
                <div class="form-group">
                    <label for="username">用户名</label>
                    <input type="text" id="username" name="username" required placeholder="请输入用户名" autocomplete="off" value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                </div>
                <div class="form-group">
                    <label for="passwd">密码</label>
                    <input type="password" id="passwd" name="passwd" required placeholder="请输入密码">
                </div>
                <div class="form-group">
                    <label for="confirm_passwd">确认密码</label>
                    <input type="password" id="confirm_passwd" name="confirm_passwd" required placeholder="请再次输入密码">
                </div>
                <div class="form-group">
                    <label for="invite_code">邀请码</label>
                    <input type="text" id="invite_code" name="invite_code" required placeholder="请输入邀请码" 
                           value="<?php echo htmlspecialchars(!empty($invite_code_from_url) ? $invite_code_from_url : (isset($_POST['invite_code']) ? $_POST['invite_code'] : '')); ?>"
                           <?php echo !empty($invite_code_from_url) ? 'readonly' : ''; ?>>
                </div>
                <div class="form-group">
                    <input type="submit" value="立即注册">
                </div>
            </form>

            <div class="status-link">
                <a href="./login.php">已有账号？去登录</a>
                <span style="margin: 0 8px; opacity: 0.4;">|</span>
                <a href="./request_invite.php">没有邀请码？申请一个</a>
            </div>
        </div>

        <div class="footer">
            <div class="footer-content">
                <a href="https://github.com/onelxzy/emby_signup" target="_blank" rel="noopener noreferrer">
                    <svg class="github-icon" viewBox="0 0 24 24">
                        <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/>
                    </svg>
                    Open Source
                </a>
            </div>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <?php 
            $is_success = ($message === '注册完成！');
            $modal_icon = $is_success ? '✅' : '⚠️';
            $modal_style_class = $is_success ? 'modal-success' : 'modal-warning';
        ?>
        <div id="message" class="modal-overlay">
            <div class="modal-content <?php echo $modal_style_class; ?>" style="max-width: 320px;">
                <div style="font-size: 32px; margin-bottom: 10px;"><?php echo $modal_icon; ?></div>
                <p id="msg-text" style="color: white; margin-bottom: 20px; font-size: 16px;"><?php echo htmlspecialchars($message); ?></p>
                <button class="modal-btn" onclick="hideMessage()">确定</button>
            </div>
        </div>
    <?php endif; ?>

</body>
</html>
