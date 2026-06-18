<?php
header("Content-Type: text/html; charset=utf-8");

require_once __DIR__ . '/../src/Auth.php';
Auth::initSession();

$config_path = __DIR__ . '/../config/config.php';
$db_file_path = __DIR__ . '/../config/database.php';

if (!file_exists($config_path) || !file_exists($db_file_path)) {
    die("配置文件或数据库文件丢失，请检查路径配置！");
}

$config = require $config_path;
require_once $db_file_path;
global $invite_db;

$message = '';
$is_success = false;

// 可供用户选择的启用中模板账号
$enabled_templates = $invite_db->getEnabledTemplates();
$enabled_template_ids = array_map(fn($t) => (int)$t['id'], $enabled_templates);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $captcha_input = trim($_POST['captcha'] ?? '');
    $captcha_session = $_SESSION['captcha_code'] ?? '';
    $template_id = (isset($_POST['template_id']) && $_POST['template_id'] !== '') ? (int)$_POST['template_id'] : null;

    // 验证码一次性使用，无论成功失败都立即失效，强制刷新
    unset($_SESSION['captcha_code']);

    if (empty($captcha_session) || strtoupper($captcha_input) !== strtoupper($captcha_session)) {
        $message = '验证码错误，请重新输入！';
    } else if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = '邮箱格式不正确！';
    } else if (!empty($enabled_templates) && ($template_id === null || !in_array($template_id, $enabled_template_ids, true))) {
        $message = '请选择有效的模板！';
    } else if ($invite_db->hasPendingInviteRequest($email)) {
        $message = '该邮箱已提交过申请，请耐心等待管理员处理！';
    } else if ($invite_db->addInviteRequest($email, $template_id)) {
        $message = '申请已提交！请等待管理员审核后将邀请码发送至您的邮箱。';
        $is_success = true;

        // 通知管理员有新的邀请码申请
        if (($config['notification']['enable_admin_email_notify'] ?? false) && !empty($config['smtp']['host'])) {
            $admin_email = $config['smtp']['username'] ?? '';
            if ($admin_email) {
                $subject = "新邀请码申请提醒";
                $body = "收到一条新的邀请码申请：\r\n\r\n申请邮箱: {$email}\r\n申请时间: " . date('Y-m-d H:i:s') . "\r\n\r\n请前往管理后台「邀请申请」处理。";
                send_smtp_email($config['smtp'], $admin_email, $subject, $body);
            }
        }
    } else {
        $message = '提交失败，请稍后重试！';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>申请 Emby 邀请码</title>
    <link rel="icon" type="image/png" href="https://emby.media/favicon-32x32.png" sizes="32x32">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="assets/css/common.css">
    <link rel="stylesheet" href="assets/css/register.css">

    <script>
        function refreshCaptcha() {
            const img = document.getElementById('captcha-img');
            if (img) img.src = 'captcha.php?t=' + Date.now();
        }
        function hideMessage() {
            const box = document.getElementById('message');
            if (!box) return;
            box.style.opacity = '0';
            setTimeout(() => { box.style.display = 'none'; }, 300);
        }
        document.addEventListener('DOMContentLoaded', function () {
            const box = document.getElementById('message');
            if (box) {
                box.style.display = 'flex';
                setTimeout(hideMessage, 3500);
                box.addEventListener('click', (e) => { if (e.target === box) hideMessage(); });
            }
        });
    </script>
</head>
<body>
    <div class="bg-blob blob-1"></div>
    <div class="bg-blob blob-2"></div>

    <div class="main-container">
        <div class="logo-section">
            <img src="https://emby.media/favicon-96x96.png" alt="Emby Logo">
            <h1>申请邀请码</h1>
            <p>提交邮箱，等待管理员发放邀请码</p>
        </div>

        <div class="form-container">
            <form method="post" action="">
                <div class="form-group">
                    <label for="email">邮箱地址</label>
                    <input type="email" id="email" name="email" required placeholder="请输入您的邮箱" autocomplete="off" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>
                <?php if (!empty($enabled_templates)): ?>
                <div class="form-group">
                    <label for="template_id">选择模板</label>
                    <select id="template_id" name="template_id" required style="width:100%; height:48px; background: var(--input-bg, rgba(255,255,255,0.05)); border: 1px solid var(--border-color, rgba(255,255,255,0.1)); border-radius: 12px; color: white; padding: 0 16px; outline:none; cursor:pointer;">
                        <option value="" disabled <?php echo !isset($_POST['template_id']) ? 'selected' : ''; ?>>请选择一个模板</option>
                        <?php foreach ($enabled_templates as $tpl): ?>
                        <option value="<?php echo (int)$tpl['id']; ?>" <?php echo (isset($_POST['template_id']) && (int)$_POST['template_id'] === (int)$tpl['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($tpl['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label for="captcha">验证码</label>
                    <div style="display:flex; gap:10px; align-items:stretch;">
                        <input type="text" id="captcha" name="captcha" required placeholder="请输入图中字符" autocomplete="off" maxlength="4" style="flex:1; text-transform:uppercase;">
                        <img id="captcha-img" src="captcha.php" alt="验证码" title="看不清？点击刷新" onclick="refreshCaptcha()" style="height:auto; border-radius:8px; cursor:pointer; flex-shrink:0;">
                    </div>
                </div>
                <div class="form-group">
                    <input type="submit" value="提交申请">
                </div>
            </form>

            <div class="status-link">
                <a href="./register.php">已有邀请码？去注册</a>
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
