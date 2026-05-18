<?php

if (__FILE__ === realpath($_SERVER['SCRIPT_FILENAME'])) {
    header("HTTP/1.0 403 Forbidden");
    exit("Access Denied");
}

/**
 * 工具函数：简易 SMTP 发送
 * 支持 SSL/TLS 并处理 SMTP 多行响应
 */
function send_smtp_email($smtp_config, $to, $subject, $body) {
    if (empty($smtp_config['host'])) return ['status' => false, 'message' => '未配置 SMTP'];
    $host = $smtp_config['host'];
    $port = $smtp_config['port'];
    $username = $smtp_config['username'];
    $password = $smtp_config['password'];
    $from_name = $smtp_config['from_name'] ?? 'Emby';

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
        "EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "\r\n" => 250,
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
