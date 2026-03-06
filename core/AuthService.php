<?php
/**
 * 星巴克门店智能效期管理系统 V3.0.0
 * 鉴权服务类
 * 功能：处理用户登录、会话管理和权限验证
 * 作者：资深 PHP 全栈架构师
 * 日期：2026-02-24
 */

class AuthService {
    private $pdo;
    private $config;

    /**
     * 构造函数
     * @param PDO $pdo 数据库连接
     * @param array $config 配置信息
     */
    public function __construct($pdo, $config) {
        $this->pdo = $pdo;
        $this->config = $config;
    }

    /**
     * 用户登录
     * @param string $username 用户名
     * @param string $password 密码
     * @param bool $remember 是否记住用户
     * @return bool|string 成功返回 true，失败返回错误信息
     */
    public function login($username, $password, $remember = false) {
        try {
            // 查询用户
            $stmt = $this->pdo->prepare("
                SELECT * FROM users WHERE (username = ? OR email = ?) AND is_active = 1
            ");
            
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                return "用户不存在或未激活";
            }
            
            // 验证密码
            if (!password_verify($password, $user['password'])) {
                return "密码不正确";
            }
            
            // 成功登录，创建会话
            $this->createSession($user);
            
            // 如果选择记住用户，设置 Cookie
            if ($remember) {
                $this->createRememberCookie($user);
            }
            
            return true;
        } catch (Exception $e) {
            return "登录失败：" . $e->getMessage();
        }
    }

    /**
     * 用户登出
     */
    public function logout() {
        // 删除会话
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        $_SESSION = [];
        
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            
            setcookie(
                session_name(), 
                '', 
                time() - 42000,
                $params["path"], 
                $params["domain"], 
                $params["secure"], 
                $params["httponly"]
            );
        }
        
        session_destroy();
        
        // 删除记住用户 Cookie
        $this->deleteRememberCookie();
        
        // 删除用户的 remember_token
        if (isset($_COOKIE['remember_token'])) {
            $this->invalidateRememberToken($_COOKIE['remember_token']);
            setcookie('remember_token', '', time() - 3600);
            unset($_COOKIE['remember_token']);
        }
    }

    /**
     * 创建用户会话
     * @param array $user 用户信息
     */
    private function createSession($user) {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        $_SESSION['user'] = [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'is_active' => $user['is_active'],
            'role' => $user['role'] // 添加角色信息
        ];
        
        // 更新最后登录时间
        $stmt = $this->pdo->prepare("
            UPDATE users SET last_login_at = NOW() WHERE id = ?
        ");
        
        $stmt->execute([$user['id']]);
    }

    /**
     * 创建记住用户的 Cookie
     * @param array $user 用户信息
     */
    private function createRememberCookie($user) {
        // 生成新的 remember token
        $token = bin2hex(random_bytes(32));
        
        // 计算过期时间（30天）
        $expires = time() + (30 * 24 * 60 * 60);
        
        // 更新用户的 remember token 和过期时间
        $stmt = $this->pdo->prepare("
            UPDATE users SET remember_token = ?, remember_token_expires_at = ? WHERE id = ?
        ");
        
        $stmt->execute([$token, date('Y-m-d H:i:s', $expires), $user['id']]);
        
        // 设置 Cookie
        setcookie(
            'remember_token', 
            $token, 
            $expires, 
            '/', 
            $this->config['domain'], 
            $this->config['secure'], 
            true
        );
    }

    /**
     * 删除记住用户的 Cookie
     */
    private function deleteRememberCookie() {
        if (isset($_COOKIE['remember_token'])) {
            $this->invalidateRememberToken($_COOKIE['remember_token']);
            setcookie('remember_token', '', time() - 3600);
            unset($_COOKIE['remember_token']);
        }
    }

    /**
     * 验证记住用户的 Cookie
     * @return bool 验证是否成功
     */
    public function validateRememberCookie() {
        if (!isset($_COOKIE['remember_token'])) {
            return false;
        }
        
        try {
            $token = $_COOKIE['remember_token'];
            
            // 查询用户
            $stmt = $this->pdo->prepare("
                SELECT * FROM users WHERE remember_token = ? AND remember_token_expires_at > NOW() AND is_active = 1
            ");
            
            $stmt->execute([$token]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                return false;
            }
            
            // 重新登录
            $this->createSession($user);
            $this->createRememberCookie($user); // 刷新 Cookie
            
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 使记住 token 失效
     * @param string $token Token
     */
    private function invalidateRememberToken($token) {
        $stmt = $this->pdo->prepare("
            UPDATE users SET remember_token = NULL, remember_token_expires_at = NULL WHERE remember_token = ?
        ");
        
        $stmt->execute([$token]);
    }

    /**
     * 检查用户是否已登录
     * @return bool 是否已登录
     */
    public function isLoggedIn() {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        if (isset($_SESSION['user'])) {
            return true;
        }
        
        // 检查 remember cookie
        return $this->validateRememberCookie();
    }
    
    /**
     * 检查用户是否是管理员
     * @return bool 是否是管理员
     */
    public function isAdmin() {
        if (! $this->isLoggedIn()) {
            return false;
        }
        
        $user = $this->getCurrentUser();
        
        // 检查用户角色是否是管理员
        // 这里假设 users 表中有一个 role 字段，值为 'admin' 表示管理员
        $stmt = $this->pdo->prepare("
            SELECT role FROM users WHERE id = ?
        ");
        
        $stmt->execute([$user['id']]);
        $userInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $userInfo['role'] === 'admin';
    }

    /**
     * 获取当前用户信息
     * @return array|false 用户信息或 false（未登录）
     */
    public function getCurrentUser() {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        if (isset($_SESSION['user'])) {
            return $_SESSION['user'];
        }
        
        return false;
    }

    /**
     * 更新用户密码
     * @param int $userId 用户 ID
     * @param string $oldPassword 旧密码
     * @param string $newPassword 新密码
     * @return bool|string 成功返回 true，失败返回错误信息
     */
    public function updatePassword($userId, $oldPassword, $newPassword) {
        try {
            // 获取用户信息
            $stmt = $this->pdo->prepare("
                SELECT * FROM users WHERE id = ? AND is_active = 1
            ");
            
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                return "用户不存在";
            }
            
            // 验证旧密码
            if (!password_verify($oldPassword, $user['password'])) {
                return "旧密码不正确";
            }
            
            // 更新密码
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $this->pdo->prepare("
                UPDATE users SET password = ? WHERE id = ?
            ");
            
            $stmt->execute([$hashedPassword, $userId]);
            
            return true;
        } catch (Exception $e) {
            return "更新失败：" . $e->getMessage();
        }
    }

    /**
     * 重置密码
     * @param int $userId 用户 ID
     * @param string $newPassword 新密码
     * @return bool 是否成功
     */
    public function resetPassword($userId, $newPassword) {
        try {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $this->pdo->prepare("
                UPDATE users SET password = ? WHERE id = ?
            ");
            
            $stmt->execute([$hashedPassword, $userId]);
            
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 获取用户列表
     * @return array 用户列表
     */
    public function getUsers() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, username, email, is_active, created_at, last_login_at FROM users ORDER BY username ASC
            ");
            
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * 创建新用户
     * @param array $userData 用户信息
     * @return bool|string 成功返回 true，失败返回错误信息
     */
    public function createUser($userData) {
        try {
            // 验证用户数据
            $requiredFields = ['username', 'email', 'password'];
            
            foreach ($requiredFields as $field) {
                if (empty($userData[$field])) {
                    return "缺少必填字段：{$field}";
                }
            }
            
            // 检查用户名是否已存在
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM users WHERE username = ?
            ");
            
            $stmt->execute([$userData['username']]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                return "用户名已存在";
            }
            
            // 检查邮箱是否已存在
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM users WHERE email = ?
            ");
            
            $stmt->execute([$userData['email']]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                return "邮箱已存在";
            }
            
            // 哈希密码
            $hashedPassword = password_hash($userData['password'], PASSWORD_DEFAULT);
            
            // 插入用户
            $stmt = $this->pdo->prepare("
                INSERT INTO users (username, email, password, is_active) 
                VALUES (?, ?, ?, ?)
            ");
            
            $isActive = isset($userData['is_active']) ? $userData['is_active'] : 1;
            $stmt->execute([$userData['username'], $userData['email'], $hashedPassword, $isActive]);
            
            return true;
        } catch (Exception $e) {
            return "创建用户失败：" . $e->getMessage();
        }
    }

    /**
     * 更新用户信息
     * @param int $userId 用户 ID
     * @param array $userData 用户信息
     * @return bool|string 成功返回 true，失败返回错误信息
     */
    public function updateUser($userId, $userData) {
        try {
            // 验证用户是否存在
            $stmt = $this->pdo->prepare("
                SELECT id FROM users WHERE id = ?
            ");
            
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                return "用户不存在";
            }
            
            // 检查用户名是否已被其他用户使用
            if (isset($userData['username'])) {
                $stmt = $this->pdo->prepare("
                    SELECT COUNT(*) FROM users WHERE username = ? AND id != ?
                ");
                
                $stmt->execute([$userData['username'], $userId]);
                $count = $stmt->fetchColumn();
                
                if ($count > 0) {
                    return "用户名已被使用";
                }
            }
            
            // 检查邮箱是否已被其他用户使用
            if (isset($userData['email'])) {
                $stmt = $this->pdo->prepare("
                    SELECT COUNT(*) FROM users WHERE email = ? AND id != ?
                ");
                
                $stmt->execute([$userData['email'], $userId]);
                $count = $stmt->fetchColumn();
                
                if ($count > 0) {
                    return "邮箱已被使用";
                }
            }
            
            // 构建更新语句
            $updates = [];
            $params = [];
            
            if (isset($userData['username'])) {
                $updates[] = "username = ?";
                $params[] = $userData['username'];
            }
            
            if (isset($userData['email'])) {
                $updates[] = "email = ?";
                $params[] = $userData['email'];
            }
            
            if (isset($userData['is_active'])) {
                $updates[] = "is_active = ?";
                $params[] = $userData['is_active'];
            }
            
            if (isset($userData['password'])) {
                $updates[] = "password = ?";
                $params[] = password_hash($userData['password'], PASSWORD_DEFAULT);
            }
            
            if (empty($updates)) {
                return "没有需要更新的字段";
            }
            
            $updates[] = "updated_at = NOW()";
            $params[] = $userId;
            
            // 执行更新
            $query = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $this->pdo->prepare($query);
            
            $stmt->execute($params);
            
            return true;
        } catch (Exception $e) {
            return "更新用户信息失败：" . $e->getMessage();
        }
    }

    /**
     * 删除用户
     * @param int $userId 用户 ID
     * @return bool 是否成功
     */
    public function deleteUser($userId) {
        try {
            // 检查是否是系统默认用户
            if ($userId == 1) {
                return false;
            }
            
            $stmt = $this->pdo->prepare("
                DELETE FROM users WHERE id = ?
            ");
            
            $stmt->execute([$userId]);
            
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
