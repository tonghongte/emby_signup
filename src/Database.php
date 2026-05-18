<?php

if (__FILE__ === realpath($_SERVER['SCRIPT_FILENAME'])) {
    header("HTTP/1.0 403 Forbidden");
    exit("Access Denied");
}

/**
 * InviteDB 类：用于管理邀请码的 SQLite 数据库操作
 */
class InviteDB
{
    private SQLite3 $db;
    
    /**
     * 构造函数：连接或创建数据库，并确保表结构存在。
     * @param string $db_path 数据库文件的绝对路径
     */
    public function __construct(string $db_path)
    {
        // 自动创建数据库文件（如果不存在）
        try {
            $this->db = new SQLite3($db_path);
        } catch (Throwable $e) {
            die("❌ 数据库连接失败：请确认 PHP sqlite3 扩展已启用，且 config 目录拥有读写权限。");
        }

        $this->initTable();
    }

    /**
     * 初始化表结构
     */
    private function initTable(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS invite_codes (
            code TEXT PRIMARY KEY NOT NULL,
            used INTEGER DEFAULT 0 NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        
        $this->db->exec("CREATE TABLE IF NOT EXISTS requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            emby_user_id TEXT NOT NULL,
            emby_username TEXT NOT NULL,
            tmdb_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            poster_url TEXT,
            media_type TEXT NOT NULL,
            status TEXT DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        
        $this->db->exec("CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            emby_user_id TEXT NOT NULL,
            title TEXT NOT NULL,
            message TEXT,
            is_read INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $this->db->exec("CREATE TABLE IF NOT EXISTS user_settings (
            emby_user_id TEXT PRIMARY KEY NOT NULL,
            email_notify_enabled INTEGER DEFAULT 1
        )");

        // 启用 WAL 模式以提高并发写入性能
        $this->db->exec('PRAGMA journal_mode = wal;');
    }
    
    /**
     * 生成随机邀请码
     */
    public static function generateRandomCode($length = 16): string
    {
        $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)]; 
        }
        return $out;
    }

    /**
     * 新增邀请码
     * @param string $code
     * @return bool 成功返回 true，如果邀请码已存在或失败返回 false
     */
    public function insertCode(string $code): bool
    {
        $stmt = $this->db->prepare('INSERT INTO invite_codes (code) VALUES (:code)');
        $stmt->bindValue(':code', $code, SQLITE3_TEXT);
        
        $this->db->exec('BEGIN TRANSACTION');
        $result = $stmt->execute();
        $this->db->exec('COMMIT');

        return $result !== false && $this->db->changes() > 0;
    }

    /**
     * 查询所有未使用的邀请码
     * @return array 包含所有未使用的邀请码字符串的数组
     */
    public function getAllUnusedCodes(): array
    {
        $codes = [];
        // 查询未使用的 (used = 0)，并按创建时间倒序排列
        $result = $this->db->query("SELECT code FROM invite_codes WHERE used = 0 ORDER BY created_at DESC");

        if ($result) {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $codes[] = $row['code'];
            }
        }
        return $codes;
    }

    /**
     * 删除指定的邀请码 (只能删除未使用的)
     * @param string $code
     * @return bool 成功删除返回 true，否则返回 false
     */
    public function deleteCode(string $code): bool
    {
        $stmt = $this->db->prepare('DELETE FROM invite_codes WHERE code = :code AND used = 0');
        $stmt->bindValue(':code', $code, SQLITE3_TEXT);
        
        $this->db->exec('BEGIN TRANSACTION');
        $stmt->execute();
        $this->db->exec('COMMIT');
        
        // 检查是否有行被删除
        return $this->db->changes() > 0;
    }

    /**
     * 验证并使用邀请码 (原子性操作)
     * @param string $code 用户输入的邀请码
     * @return bool 验证成功并标记为已使用返回 true，否则返回 false
     */
    public function useCode(string $code): bool
    {
        $this->db->exec('BEGIN TRANSACTION');
        
        // 1. 尝试更新邀请码：从 used=0 状态改为 used=1
        // 只有当邀请码存在且状态为未使用(0)时，更新才会成功。
        $stmt = $this->db->prepare('UPDATE invite_codes SET used = 1 WHERE code = :code AND used = 0');
        $stmt->bindValue(':code', $code, SQLITE3_TEXT);
        $stmt->execute();
        
        // 2. 检查更新是否成功：如果影响行数 > 0，说明更新成功，邀请码有效。
        $success = $this->db->changes() > 0;
        
        // 提交或回滚事务
        if ($success) {
            $this->db->exec('COMMIT');
            return true;
        } else {
            $this->db->exec('ROLLBACK');
            return false; // 邀请码不存在或已被使用
        }
    }

    /**
     * 恢复邀请码 (当且仅当 API 请求等后续流程失败时调用)
     * @param string $code
     * @return bool
     */
    public function restoreCode(string $code): bool
    {
        $this->db->exec('BEGIN TRANSACTION');
        $stmt = $this->db->prepare('UPDATE invite_codes SET used = 0 WHERE code = :code AND used = 1');
        $stmt->bindValue(':code', $code, SQLITE3_TEXT);
        $stmt->execute();
        
        $success = $this->db->changes() > 0;
        
        if ($success) {
            $this->db->exec('COMMIT');
            return true;
        } else {
            $this->db->exec('ROLLBACK');
            return false;
        }
    }
    
    // ==========================================
    // Requests & Notifications Methods
    // ==========================================

    public function addRequest($emby_user_id, $emby_username, $tmdb_id, $title, $poster_url, $media_type): bool
    {
        $stmt = $this->db->prepare('INSERT INTO requests (emby_user_id, emby_username, tmdb_id, title, poster_url, media_type) VALUES (:user_id, :username, :tmdb_id, :title, :poster_url, :media_type)');
        $stmt->bindValue(':user_id', $emby_user_id, SQLITE3_TEXT);
        $stmt->bindValue(':username', $emby_username, SQLITE3_TEXT);
        $stmt->bindValue(':tmdb_id', $tmdb_id, SQLITE3_INTEGER);
        $stmt->bindValue(':title', $title, SQLITE3_TEXT);
        $stmt->bindValue(':poster_url', $poster_url, SQLITE3_TEXT);
        $stmt->bindValue(':media_type', $media_type, SQLITE3_TEXT);
        $result = $stmt->execute();
        return $result !== false;
    }

    public function getUserRequests($emby_user_id): array
    {
        $stmt = $this->db->prepare("SELECT * FROM requests WHERE emby_user_id = :user_id ORDER BY created_at DESC");
        if (!$stmt) return [];
        $stmt->bindValue(':user_id', $emby_user_id, SQLITE3_TEXT);
        $result = $stmt->execute();
        if (!$result) return [];
        $requests = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $requests[] = $row;
        }
        return $requests;
    }

    public function getAllRequests(): array
    {
        $result = $this->db->query("SELECT * FROM requests ORDER BY created_at DESC");
        $requests = [];
        if ($result) {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $requests[] = $row;
            }
        }
        return $requests;
    }
    
    public function getRequestById($id)
    {
        $stmt = $this->db->prepare("SELECT * FROM requests WHERE id = :id");
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $result = $stmt->execute();
        if ($result) {
            return $result->fetchArray(SQLITE3_ASSOC);
        }
        return null;
    }

    public function updateRequestStatus($id, $status): bool
    {
        $stmt = $this->db->prepare("UPDATE requests SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->bindValue(':status', $status, SQLITE3_TEXT);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
        return $this->db->changes() > 0;
    }

    public function deleteRequest($id, $emby_user_id = null): bool
    {
        $sql = "DELETE FROM requests WHERE id = :id";
        if ($emby_user_id !== null) {
            $sql .= " AND emby_user_id = :user_id";
        }
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        if ($emby_user_id !== null) {
            $stmt->bindValue(':user_id', $emby_user_id, SQLITE3_TEXT);
        }
        $stmt->execute();
        return $this->db->changes() > 0;
    }

    public function deleteExpiredRequests(int $days): int
    {
        $stmt = $this->db->prepare("DELETE FROM requests WHERE datetime(created_at) < datetime('now', '-' || :days || ' days')");
        $stmt->bindValue(':days', $days, SQLITE3_INTEGER);
        $stmt->execute();
        return $this->db->changes();
    }

    public function addNotification($emby_user_id, $title, $message): bool
    {
        $stmt = $this->db->prepare('INSERT INTO notifications (emby_user_id, title, message) VALUES (:user_id, :title, :message)');
        $stmt->bindValue(':user_id', $emby_user_id, SQLITE3_TEXT);
        $stmt->bindValue(':title', $title, SQLITE3_TEXT);
        $stmt->bindValue(':message', $message, SQLITE3_TEXT);
        $result = $stmt->execute();
        return $result !== false;
    }

    public function getUserNotifications($emby_user_id, $unread_only = false): array
    {
        $sql = "SELECT * FROM notifications WHERE emby_user_id = :user_id";
        if ($unread_only) {
            $sql .= " AND is_read = 0";
        }
        $sql .= " ORDER BY created_at DESC";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) return [];
        $stmt->bindValue(':user_id', $emby_user_id, SQLITE3_TEXT);
        $result = $stmt->execute();
        if (!$result) return [];
        $notifications = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $notifications[] = $row;
        }
        return $notifications;
    }
    
    public function getUnreadNotificationCount($emby_user_id): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM notifications WHERE emby_user_id = :user_id AND is_read = 0");
        if (!$stmt) return 0;
        $stmt->bindValue(':user_id', $emby_user_id, SQLITE3_TEXT);
        $result = $stmt->execute();
        if ($result && $row = $result->fetchArray(SQLITE3_ASSOC)) {
            return (int)$row['count'];
        }
        return 0;
    }

    public function markNotificationAsRead($id, $emby_user_id): bool
    {
        $stmt = $this->db->prepare("UPDATE notifications SET is_read = 1 WHERE id = :id AND emby_user_id = :user_id");
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->bindValue(':user_id', $emby_user_id, SQLITE3_TEXT);
        $stmt->execute();
        return $this->db->changes() > 0;
    }

    public function markAllNotificationsAsRead($emby_user_id): bool
    {
        $stmt = $this->db->prepare("UPDATE notifications SET is_read = 1 WHERE emby_user_id = :user_id AND is_read = 0");
        $stmt->bindValue(':user_id', $emby_user_id, SQLITE3_TEXT);
        $stmt->execute();
        return $this->db->changes() > 0;
    }

    public function clearUserNotifications($emby_user_id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM notifications WHERE emby_user_id = :user_id");
        $stmt->bindValue(':user_id', $emby_user_id, SQLITE3_TEXT);
        $stmt->execute();
        return $this->db->changes() > 0;
    }

    public function clearProcessedRequests(): bool
    {
        $stmt = $this->db->prepare("DELETE FROM requests WHERE status != 'pending'");
        $stmt->execute();
        return $this->db->changes() > 0;
    }

    public function clearAllRequests(): bool
    {
        $stmt = $this->db->prepare("DELETE FROM requests");
        $stmt->execute();
        return $this->db->changes() > 0;
    }

    public function getUserEmailPreference($emby_user_id): bool
    {
        $stmt = $this->db->prepare("SELECT email_notify_enabled FROM user_settings WHERE emby_user_id = :user_id");
        $stmt->bindValue(':user_id', $emby_user_id, SQLITE3_TEXT);
        $result = $stmt->execute();
        if ($result && $row = $result->fetchArray(SQLITE3_ASSOC)) {
            return (bool)$row['email_notify_enabled'];
        }
        return false; // Default to false if not set
    }

    public function setUserEmailPreference($emby_user_id, $enabled): bool
    {
        $stmt = $this->db->prepare("INSERT INTO user_settings (emby_user_id, email_notify_enabled) VALUES (:user_id, :enabled) ON CONFLICT(emby_user_id) DO UPDATE SET email_notify_enabled = :enabled");
        $stmt->bindValue(':user_id', $emby_user_id, SQLITE3_TEXT);
        $stmt->bindValue(':enabled', $enabled ? 1 : 0, SQLITE3_INTEGER);
        $stmt->execute();
        return $this->db->changes() > 0;
    }
}
