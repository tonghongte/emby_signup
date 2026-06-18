<?php

if (__FILE__ === realpath($_SERVER['SCRIPT_FILENAME'])) {
    header("HTTP/1.0 403 Forbidden");
    exit("Access Denied");
}

class EmbyApi
{
    /**
     * 发送 Emby API 请求
     */
    public static function apiRequest($endpoint, $method = 'GET', $data = null) {
        $config_path = __DIR__ . '/../config/config.php';
        if (!file_exists($config_path)) return null;
        $config = require $config_path;
        
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

    /**
     * 获取 Emby 所有用户列表
     */
    public static function getEmbyUsers() {
        $res = self::apiRequest('/emby/Users');
        return (is_array($res) && isset($res[0]) && is_array($res[0])) ? $res : [];
    }

    /**
     * 禁用 Emby 用户账号
     */
    public static function banEmbyUser($user_id) {
        $user = self::apiRequest("/emby/Users/{$user_id}");
        if (!$user) return false;
        $policy = $user['Policy'];
        $policy['IsDisabled'] = true;
        self::apiRequest("/emby/Users/{$user_id}/Policy", 'POST', $policy);
        return true;
    }

    /**
     * 启用 Emby 用户账号
     */
    public static function unbanEmbyUser($user_id) {
        $user = self::apiRequest("/emby/Users/{$user_id}");
        if (!$user) return false;
        $policy = $user['Policy'];
        $policy['IsDisabled'] = false;
        self::apiRequest("/emby/Users/{$user_id}/Policy", 'POST', $policy);
        return true;
    }

    /**
     * 彻底删除 Emby 用户账号
     */
    public static function deleteEmbyUser($user_id) {
        self::apiRequest("/emby/Users/{$user_id}", 'DELETE');
        return true;
    }

    /**
     * 复制模板权限并创建新的 Emby 用户
     */
    public static function createEmbyUser($username, $password, $config, $template_override = null) {
        $emby_url = rtrim($config['emby']['base_url'], '/');
        $emby_token = $config['emby']['token'];
        // 优先使用传入的模板用户 ID，否则回退到全局配置
        $template_id = !empty($template_override) ? $template_override : $config['emby']['template_user_id'];

        $url1 = "{$emby_url}/emby/Users/New?X-Emby-Token={$emby_token}";
        $data1 = array(
            'Name' => $username, 
            'CopyFromUserId' => $template_id, 
            'UserCopyOptions' => 'UserPolicy,UserConfiguration'
        );
        $options1 = array(
            'http' => array(
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($data1),
                'ignore_errors' => true
            )
        );
        $context1  = stream_context_create($options1);
        $result1 = @file_get_contents($url1, false, $context1);

        if ($result1 === FALSE) { 
            return ['status' => false, 'message' => "连接 Emby 服务器失败，请联系管理员！"];
        }

        $response1 = json_decode($result1, true);
        if (!isset($response1['Id']) || $response1['Id'] === NULL) {
            return ['status' => false, 'message' => "用户创建失败，请联系管理员！"];
        }

        $userid = $response1['Id'];
        $url2 = "{$emby_url}/emby/Users/{$userid}/Password?X-Emby-Token={$emby_token}";
        $data2 = array('NewPw' => $password);
        $options2 = array(
            'http' => array(
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($data2),
                'ignore_errors' => true
            )
        );
        $context2  = stream_context_create($options2);
        $result2 = @file_get_contents($url2, false, $context2);
        
        if ($result2 === FALSE) {
            // 回滚，删除已半创建的用户
            self::deleteEmbyUser($userid);
            return ['status' => false, 'message' => "密码设置失败，已自动回滚，请联系管理员重置！"];
        }

        return ['status' => true, 'message' => '注册完成！', 'user_id' => $userid];
    }
}
