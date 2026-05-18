<?php
// Emby 注册与求片系统 - 环境诊断工具 (check.php)
// ----------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Content-Type: text/html; charset=utf-8");
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系统环境与权限诊断工具</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: #0f172a;
            color: #e2e8f0;
            padding: 40px 20px;
            max-width: 800px;
            margin: 0 auto;
        }
        h1 {
            color: #f8fafc;
            border-bottom: 1px solid #334155;
            padding-bottom: 10px;
            margin-bottom: 30px;
        }
        .section {
            background-color: #1e293b;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            border: 1px solid #334155;
        }
        .section-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 16px;
            color: #38bdf8;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #334155;
        }
        .item:last-child {
            border-bottom: none;
        }
        .status {
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
        }
        .status.ok {
            background-color: rgba(34, 197, 94, 0.2);
            color: #4ade80;
        }
        .status.warning {
            background-color: rgba(234, 179, 8, 0.2);
            color: #facc15;
        }
        .status.error {
            background-color: rgba(239, 68, 68, 0.2);
            color: #f87171;
        }
        .log-box {
            background-color: #020617;
            padding: 16px;
            border-radius: 8px;
            font-family: monospace;
            font-size: 13px;
            color: #94a3b8;
            overflow-x: auto;
            white-space: pre-wrap;
            margin-top: 10px;
        }
    </style>
</head>
<body>

    <h1>🔍 Emby 注册系统 - 群晖/Linux 环境自我诊断工具</h1>

    <!-- 1. PHP 基础与扩展检查 -->
    <div class="section">
        <div class="section-title">📦 PHP 基础与扩展检查</div>
        
        <div class="item">
            <span>PHP 版本 (需 PHP 8.0+)</span>
            <span class="status <?php echo PHP_VERSION_ID >= 80000 ? 'ok' : 'error'; ?>"><?php echo PHP_VERSION; ?></span>
        </div>
        
        <div class="item">
            <span>SQLite3 扩展 (核心数据库扩展)</span>
            <?php if (extension_loaded('sqlite3')): ?>
                <span class="status ok">已加载</span>
            <?php else: ?>
                <span class="status error">未加载 (请在群晖 PHP 设置中启用 sqlite3 扩展)</span>
            <?php endif; ?>
        </div>

        <div class="item">
            <span>Session 状态</span>
            <?php
            @session_start();
            if (session_status() === PHP_SESSION_ACTIVE) {
                echo '<span class="status ok">正常启用</span>';
            } else {
                echo '<span class="status error">禁用或未初始化</span>';
            }
            ?>
        </div>
    </div>

    <!-- 2. 物理目录与读写权限 -->
    <div class="section">
        <div class="section-title">📂 物理目录与读写权限</div>
        
        <?php
        $config_dir = realpath(__DIR__ . '/../config');
        $src_dir = realpath(__DIR__ . '/../src');
        ?>
        
        <div class="item">
            <span>Config 目录绝对路径</span>
            <span style="font-size: 13px; color: #94a3b8;"><?php echo $config_dir ?: '❌ 目录不存在！'; ?></span>
        </div>
        
        <?php if ($config_dir): ?>
            <div class="item">
                <span>Config 目录写入权限 (核心)</span>
                <?php if (is_writable($config_dir)): ?>
                    <span class="status ok">可写</span>
                <?php else: ?>
                    <span class="status error">只读 (请在群晖中为 config 目录赋予 http 账户或 777 读写权限)</span>
                <?php endif; ?>
            </div>
            
            <div class="item">
                <span>config/config.php 存在情况</span>
                <?php if (file_exists($config_dir . '/config.php')): ?>
                    <span class="status ok">存在</span>
                <?php else: ?>
                    <span class="status error">不存在 (请复制 config.example.php 或在 ENV 中提供默认值)</span>
                <?php endif; ?>
            </div>

            <div class="item">
                <span>config/database.php 存在情况</span>
                <?php if (file_exists($config_dir . '/database.php')): ?>
                    <span class="status ok">存在</span>
                <?php else: ?>
                    <span class="status error">不存在</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- 3. 配置文件加载与结构分析 -->
    <div class="section">
        <div class="section-title">⚙️ 配置文件加载与结构分析</div>
        <?php
        $config = null;
        if ($config_dir && file_exists($config_dir . '/config.php')) {
            try {
                $config = require $config_dir . '/config.php';
                echo '<div class="item"><span>配置文件载入</span><span class="status ok">成功</span></div>';
                
                // 打印关键配置项 (脱敏)
                echo '<div class="log-box">';
                echo "Emby URL: " . htmlspecialchars($config['emby']['base_url'] ?? '未定义') . "\n";
                echo "Emby Token: " . (empty($config['emby']['token']) || $config['emby']['token'] === 'YOUR_EMBY_API_TOKEN' ? '❌ 未配置' : '✅ 已配置') . "\n";
                echo "Admin Username: " . htmlspecialchars($config['admin']['username'] ?? '未定义') . "\n";
                echo "Admin Password: " . (empty($config['admin']['password']) || $config['admin']['password'] === 'password' ? '⚠️ 默认密码' : '✅ 已修改') . "\n";
                echo "Auto Ban Enable: " . (($config['auto_ban']['enable'] ?? false) ? '开启' : '关闭') . "\n";
                echo "Auto Delete Requests: " . (($config['auto_delete_requests']['enable'] ?? false) ? '开启' : '关闭') . "\n";
                echo '</div>';
            } catch (Throwable $e) {
                echo '<div class="item"><span>配置文件载入</span><span class="status error">失败: ' . htmlspecialchars($e->getMessage()) . '</span></div>';
            }
        } else {
            echo '<div class="item"><span>配置文件载入</span><span class="status error">未找到 config.php 文件</span></div>';
        }
        ?>
    </div>

    <!-- 4. 数据库及 SQLite 连通性测试 -->
    <div class="section">
        <div class="section-title">🗄️ 数据库及 SQLite 连通性测试</div>
        <?php
        if ($config_dir && file_exists($config_dir . '/database.php')) {
            try {
                require_once $config_dir . '/database.php';
                echo '<div class="item"><span>数据库核心适配器加载</span><span class="status ok">成功</span></div>';
                
                if (isset($invite_db)) {
                    echo '<div class="item"><span>SQLite3 实例化 (\$invite_db)</span><span class="status ok">成功</span></div>';
                    
                    // 测试表读取
                    $codes = $invite_db->getAllUnusedCodes();
                    echo '<div class="item"><span>邀请码表测试 (读取)</span><span class="status ok">成功 (剩余: ' . count($codes) . ' 个)</span></div>';
                    
                    $reqs = $invite_db->getAllRequests();
                    echo '<div class="item"><span>求片记录表测试 (读取)</span><span class="status ok">成功 (总计: ' . count($reqs) . ' 条)</span></div>';
                } else {
                    echo '<div class="item"><span>SQLite3 实例化 (\$invite_db)</span><span class="status error">失败 (未定义全局变量 \$invite_db)</span></div>';
                }
            } catch (Throwable $e) {
                echo '<div class="item"><span>数据库核心适配器加载</span><span class="status error">失败</span></div>';
                echo '<div class="log-box">错误日志:' . htmlspecialchars($e->getMessage()) . "\n" . htmlspecialchars($e->getTraceAsString()) . '</div>';
            }
        } else {
            echo '<div class="item"><span>数据库核心适配器加载</span><span class="status error">未找到 database.php 核心适配器</span></div>';
        }
        ?>
    </div>

    <!-- 5. 模拟 Emby API 联通性测试 -->
    <div class="section">
        <div class="section-title">🌐 Emby 局域网/公网联通性测试</div>
        <?php
        if ($config && !empty($config['emby']['base_url'])) {
            $base_url = rtrim($config['emby']['base_url'], '/');
            echo '<div class="item"><span>正在检测 Emby 接口地址</span><span style="font-size: 13px; color: #38bdf8;">' . htmlspecialchars($base_url) . '</span></div>';
            
            // 简单的 curl 或 file_get_contents 测试
            $test_url = $base_url . "/emby/System/Info/Public";
            
            $options = [
                'http' => [
                    'method' => 'GET',
                    'timeout' => 4, // 快速超时以免卡死
                    'ignore_errors' => true
                ]
            ];
            $context = stream_context_create($options);
            $start_time = microtime(true);
            $response = @file_get_contents($test_url, false, $context);
            $duration = round(microtime(true) - $start_time, 3);
            
            if ($response !== false) {
                $info = json_decode($response, true);
                if (isset($info['ServerName'])) {
                    echo '<div class="item"><span>Emby 联通测试 (系统信息获取)</span><span class="status ok">成功 (耗时: ' . $duration . 's)</span></div>';
                    echo '<div class="log-box">Server Name: ' . htmlspecialchars($info['ServerName']) . "\nVersion: " . htmlspecialchars($info['Version']) . '</div>';
                } else {
                    echo '<div class="item"><span>Emby 联通测试 (获取失败)</span><span class="status warning">有响应但无法解析结构 (耗时: ' . $duration . 's)</span></div>';
                }
            } else {
                echo '<div class="item"><span>Emby 联通测试</span><span class="status error">无法建立 TCP 连接 (连接超时，请确认 URL 是否配置正确且群晖/Docker 网络可达)</span></div>';
            }
        } else {
            echo '<div class="item"><span>Emby 联通测试</span><span class="status error">未找到 Emby 基础 Base URL 配置</span></div>';
        }
        ?>
    </div>

</body>
</html>
