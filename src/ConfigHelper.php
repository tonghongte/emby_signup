<?php

if (__FILE__ === realpath($_SERVER['SCRIPT_FILENAME'])) {
    header("HTTP/1.0 403 Forbidden");
    exit("Access Denied");
}

class ConfigHelper
{
    /**
     * 修改持久化配置文件 config/config.php 中指定的 env 缺省参数
     */
    public static function updateConfigValue($file, $env_key, $new_value, $type = 'string') {
        if (!file_exists($file)) return;
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
        
        // 如果找到了对应的 env 定义，直接替换并写入
        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, $replacement, $content);
            file_put_contents($file, $content);
        }
    }

    /**
     * 自动向 config.php 尾部注入缺失的配置结构段 (实现平滑版本迁移)
     */
    public static function checkAndInjectMissingConfigBlocks($file) {
        if (!file_exists($file)) return;
        $content = file_get_contents($file);
        $needs_update = false;
        $insert_str = "";
        
        // 寻找 return [ ... ]; 的末尾括号
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
            if (strpos($content, "'notification'") !== false) {
                $content = preg_replace("/'notification'\s*=>\s*\[(.*?)\]/is", "'notification' => [\n        'enable_admin_email_notify' => env('ENABLE_ADMIN_EMAIL_NOTIFY', false),\n        'enable_user_email_notify' => env('ENABLE_USER_EMAIL_NOTIFY', false)\n    ]", $content);
                $needs_update = true;
            } else {
                $insert_str .= "\n    'notification' => [\n        'enable_admin_email_notify' => env('ENABLE_ADMIN_EMAIL_NOTIFY', false),\n        'enable_user_email_notify' => env('ENABLE_USER_EMAIL_NOTIFY', false)\n    ],\n";
                $needs_update = true;
            }
        }
        
        if ($needs_update) {
            if (!empty($insert_str)) {
                $content = substr_replace($content, $insert_str, $end_pos, 0);
            }
            file_put_contents($file, $content);
        }
    }
}
