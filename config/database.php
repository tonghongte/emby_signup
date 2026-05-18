<?php

if (__FILE__ === realpath($_SERVER['SCRIPT_FILENAME'])) {
    header("HTTP/1.0 403 Forbidden");
    exit("Access Denied");
}

// 自动检查并修复旧版本 config.php 的 env() 重复定义问题以实现平滑兼容
$config_file = __DIR__ . '/config.php';
if (file_exists($config_file)) {
    $config_content = file_get_contents($config_file);
    if (strpos($config_content, 'function env(') !== false && strpos($config_content, "function_exists('env')") === false) {
        $new_env = "if (!function_exists('env')) {\n    function env(\$key, \$default = null) {\n        \$value = getenv(\$key);\n        return \$value === false ? \$default : \$value;\n    }\n}";
        // 兼容并归一化换行符
        $config_content_norm = str_replace(["\r\n", "\r"], "\n", $config_content);
        $old_env_normalized = "function env(\$key, \$default = null) {\n    \$value = getenv(\$key);\n    return \$value === false ? \$default : \$value;\n}";
        if (strpos($config_content_norm, $old_env_normalized) !== false) {
            $config_content_norm = str_replace($old_env_normalized, $new_env, $config_content_norm);
            file_put_contents($config_file, $config_content_norm);
        } else {
            // 使用正则替换以对抗任何微小的空格/缩进差异
            $pattern = '/function\s+env\s*\(\s*\$key\s*,\s*\$default\s*=\s*null\s*\)\s*\{\s*\$value\s*=\s*getenv\(\s*\$key\s*\);\s*return\s+\$value\s*===\s*false\s*\?\s*\$default\s*:\s*\$value\s*;\s*\}/is';
            $config_content = preg_replace($pattern, $new_env, $config_content);
            file_put_contents($config_file, $config_content);
        }
    }
}

// 引入核心组件
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Mailer.php';

// 保持全局变量 $invite_db 的向后兼容性
$db_path = __DIR__ . '/invite_codes.sqlite'; 
$invite_db = new InviteDB($db_path);