<?php

if (__FILE__ === realpath($_SERVER['SCRIPT_FILENAME'])) {
    header("HTTP/1.0 403 Forbidden");
    exit("Access Denied");
}

class Auth
{
    /**
     * 初始化 Session 会话
     */
    public static function initSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * 校验并获取 CSRF Token，自动生成（如果不存在）
     */
    public static function csrfToken(): string
    {
        self::initSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * 校验 CSRF Token 是否有效
     */
    public static function verifyCsrf(?string $token): bool
    {
        self::initSession();
        $stored_token = $_SESSION['csrf_token'] ?? '';
        if (empty($token) || empty($stored_token)) {
            return false;
        }
        return hash_equals($stored_token, $token);
    }

    /**
     * 检查管理员登录状态
     */
    public static function checkAdmin(): bool
    {
        self::initSession();
        return !empty($_SESSION['admin_logged_in']);
    }

    /**
     * 检查普通用户登录状态
     */
    public static function checkUser(): bool
    {
        self::initSession();
        return isset($_SESSION['emby_user_id']) && isset($_SESSION['emby_token']);
    }

    /**
     * 强制拦截管理员，如果未登录直接跳转至登录页
     */
    public static function requireAdmin(): void
    {
        if (!self::checkAdmin()) {
            header("Location: login.php");
            exit;
        }
    }

    /**
     * 强制拦截普通用户，如果未登录直接跳转至登录页
     */
    public static function requireUser(): void
    {
        if (!self::checkUser()) {
            header("Location: login.php");
            exit;
        }
    }
}
