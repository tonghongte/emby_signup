<?php
session_start();
header("Content-Type: text/html; charset=utf-8");

$config_path = __DIR__ . '/../config/config.php';
$db_file_path = __DIR__ . '/../config/database.php';

if (!file_exists($config_path) || !file_exists($db_file_path)) {
    die("配置文件或数据库文件丢失，请检查路径配置！");
}

$config = require $config_path;
require_once $db_file_path;

$message = '';

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}

// Redirect if already logged in
if (isset($_SESSION['emby_user_id']) && isset($_SESSION['emby_token'])) {
    header("Location: user.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['passwd'];

    if (empty($username) || empty($password)) {
        $message = '用户名和密码不能为空！';
    } else {
        // 1. Check fallback admin account first
        if ($username === $config['admin']['username'] && $password === $config['admin']['password']) {
            $_SESSION['admin_logged_in'] = true;
            if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            header("Location: admin.php");
            exit;
        }

        // 2. Emby authentication
        $emby_url = rtrim($config['emby']['base_url'], '/');
        $auth_url = "{$emby_url}/emby/Users/AuthenticateByName";
        $auth_header = 'Emby Client="Emby Signup Portal", Device="Web", DeviceId="portal_web_client", Version="1.0.0"';

        $data = array(
            'Username' => $username,
            'Pw' => $password
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

        if ($result === FALSE) {
            $message = "连接 Emby 服务器失败，请联系管理员！";
        } else {
            $response = json_decode($result, true);
            $http_code = 0;
            if (isset($http_response_header)) {
                preg_match('{HTTP\/\S*\s(\d{3})}', $http_response_header[0], $match);
                $http_code = isset($match[1]) ? (int)$match[1] : 0;
            }

            if ($http_code === 200 && isset($response['User']['Id']) && isset($response['AccessToken'])) {
                // Login successful
                $_SESSION['emby_user_id'] = $response['User']['Id'];
                $_SESSION['emby_username'] = $response['User']['Name'];
                $_SESSION['emby_token'] = $response['AccessToken'];
                
                // Check if user is an Emby Administrator
                if (!empty($response['User']['Policy']['IsAdministrator'])) {
                    $_SESSION['admin_logged_in'] = true;
                    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    header("Location: admin.php");
                } else {
                    header("Location: user.php");
                }
                exit;
            } else {
                $message = "用户名或密码错误！";
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
    <title>登录 - Emby 媒体库</title>
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
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow-x: hidden;
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

        .main-container {
            width: 100%;
            max-width: 400px;
            position: relative;
            z-index: 10;
        }

        .logo-section {
            text-align: center;
            margin-bottom: 30px;
            animation: fadeInDown 0.8s ease-out;
        }

        .logo-section img {
            width: 72px;
            height: 72px;
            margin-bottom: 16px;
            border-radius: 18px;
            box-shadow: 0 0 20px rgba(82, 181, 75, 0.3);
        }

        .logo-section h1 {
            font-size: 24px;
            font-weight: 700;
            letter-spacing: -0.5px;
            margin-bottom: 8px;
            background: linear-gradient(to right, #fff, #cbd5e1);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .logo-section p {
            font-size: 14px;
            color: var(--text-sub);
        }

        .form-container {
            background: var(--card-bg);
            backdrop-filter: blur(var(--blur-amt));
            -webkit-backdrop-filter: blur(var(--blur-amt));
            border: 1px solid var(--border-color);
            border-radius: 24px;
            padding: 40px 32px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            animation: fadeInUp 0.8s ease-out;
        }

        .form-group {
            margin-bottom: 20px;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-sub);
        }

        .form-group input {
            width: 100%;
            padding: 14px 16px;
            background: var(--input-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            color: white;
            font-size: 15px;
            font-family: inherit;
            transition: all 0.3s ease;
        }

        .form-group input::placeholder {
            color: rgba(148, 163, 184, 0.4);
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(82, 181, 75, 0.15);
            background: rgba(15, 23, 42, 0.8);
        }

        .form-group input[type="submit"] {
            background: linear-gradient(135deg, var(--primary) 0%, #3d8c38 100%);
            color: white;
            cursor: pointer;
            font-weight: 600;
            border: none;
            margin-top: 12px;
            font-size: 16px;
            letter-spacing: 0.5px;
            box-shadow: 0 10px 15px -3px rgba(82, 181, 75, 0.3);
        }

        .form-group input[type="submit"]:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 20px -3px rgba(82, 181, 75, 0.4);
        }

        .form-group input[type="submit"]:active {
            transform: translateY(0);
        }

        .status-link {
            text-align: center;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
        }

        .status-link a {
            color: var(--text-sub);
            text-decoration: none;
            font-size: 13px;
            transition: color 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .status-link a::before {
            content: '';
            display: block;
            width: 8px;
            height: 8px;
            background-color: #3b82f6;
            border-radius: 50%;
            box-shadow: 0 0 8px #3b82f6;
        }

        .status-link a:hover {
            color: white;
        }
        
        .error-msg { 
            background: rgba(239, 68, 68, 0.15); 
            color: #fca5a5; 
            padding: 12px; 
            border-radius: 10px; 
            margin-bottom: 20px; 
            font-size: 13px; 
            text-align: center; 
            border: 1px solid rgba(239, 68, 68, 0.3); 
        }

        /* Footer */
        .footer {
            margin-top: 40px;
            text-align: center;
            animation: fadeIn 1s ease-out 0.5s both;
        }

        .footer-content {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 8px 16px;
            background: rgba(255,255,255,0.05);
            border-radius: 50px;
            border: 1px solid rgba(255,255,255,0.05);
        }

        .footer-content a {
            color: var(--text-sub);
            text-decoration: none;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }

        .footer-content a:hover {
            color: white;
        }

        .github-icon {
            width: 16px;
            height: 16px;
            fill: currentColor;
        }

        @keyframes fadeInDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    </style>
</head>
<body>
    <div class="bg-blob blob-1"></div>
    <div class="bg-blob blob-2"></div>
    
    <div class="main-container">
        <div class="logo-section">
            <img src="https://emby.media/favicon-96x96.png" alt="Emby Logo">
            <h1>欢迎回来</h1>
            <p>登录用户控制台</p>
        </div>

        <div class="form-container">
            <?php if ($message): ?>
                <div class="error-msg">⚠️ <?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            
            <form method="post" action="">
                <div class="form-group">
                    <label for="username">Emby 用户名</label>
                    <input type="text" id="username" name="username" required placeholder="请输入您的 Emby 账号" autocomplete="off" value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                </div>
                <div class="form-group">
                    <label for="passwd">密码</label>
                    <input type="password" id="passwd" name="passwd" required placeholder="请输入密码">
                </div>
                <div class="form-group">
                    <input type="submit" value="立即登录">
                </div>
            </form>

            <div class="status-link">
                <a href="./register.php">还没有账号？前往注册</a>
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
</body>
</html>
