<?php
/**
 * 星巴克门店智能效期管理系统 V3.0.0
 * 邮件服务类
 * 功能：实现邮件发送与 SMTP 轮询策略
 * 作者：资深 PHP 全栈架构师
 * 日期：2026-02-24
 */

class EmailService {
    private $pdo;
    private $smtpConfig = [
        'host' => 'smtp.qq.com',
        'port' => 465,
        'secure' => 'ssl',
        'charset' => 'utf-8'
    ];

    /**
     * 构造函数
     * @param PDO $pdo 数据库连接对象
     */
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * 获取可用的邮件账号
     * @return array 邮件账号列表
     */
    public function getAvailableAccounts() {
        $stmt = $this->pdo->prepare("SELECT * FROM email_accounts WHERE is_active = 1 ORDER BY last_used_at ASC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 轮询获取下一个发送账号
     * @return array|false 邮件账号信息或 false（无可用账号）
     */
    public function getNextAccount() {
        $accounts = $this->getAvailableAccounts();
        
        if (empty($accounts)) {
            return false;
        }
        
        // 找到最早使用的账号
        $nextAccount = $accounts[0];
        
        // 更新使用时间
        $stmt = $this->pdo->prepare("UPDATE email_accounts SET last_used_at = NOW() WHERE id = ?");
        $stmt->execute([$nextAccount['id']]);
        
        return $nextAccount;
    }

    /**
     * 发送邮件
     * @param string $to 收件人邮箱
     * @param string $subject 邮件主题
     * @param string $htmlContent 邮件内容（HTML 格式）
     * @param string $fromEmail 发件人邮箱
     * @param string $fromName 发件人名称
     * @return bool|string 成功返回 true，失败返回错误信息
     */
    public function sendEmail($to, $subject, $htmlContent, $fromEmail, $fromName) {
        // 获取发送账号
        $account = $this->getNextAccount();
        
        if (!$account) {
            return "没有可用的邮件发送账号";
        }
        
        // 初始化 PHPMailer
        require_once 'PHPMailer/PHPMailer.php';
        require_once 'PHPMailer/SMTP.php';
        require_once 'PHPMailer/Exception.php';
        
        use PHPMailer\PHPMailer\PHPMailer;
        use PHPMailer\PHPMailer\SMTP;
        use PHPMailer\PHPMailer\Exception;
        
        $mail = new PHPMailer(true);
        
        try {
            // 服务器配置
            $mail->SMTPDebug = SMTP::DEBUG_OFF;
            $mail->isSMTP();
            $mail->Host = $this->smtpConfig['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $account['qq_number'];
            $mail->Password = $account['auth_code'];
            $mail->Port = $this->smtpConfig['port'];
            $mail->SMTPSecure = $this->smtpConfig['secure'];
            $mail->CharSet = $this->smtpConfig['charset'];
            
            // 发件人
            $mail->setFrom("{$account['qq_number']}@qq.com", $fromName);
            
            // 收件人
            $mail->addAddress($to);
            
            // 内容
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlContent;
            
            // 发送
            if ($mail->send()) {
                return true;
            } else {
                return "邮件发送失败：" . $mail->ErrorInfo;
            }
            
        } catch (Exception $e) {
            return "邮件发送异常：" . $e->getMessage();
        }
    }

    /**
     * 批量发送邮件
     * @param array $recipients 收件人列表（格式：['email' => 'name']）
     * @param string $subject 邮件主题
     * @param string $htmlContent 邮件内容（HTML 格式）
     * @param string $fromEmail 发件人邮箱
     * @param string $fromName 发件人名称
     * @return array 发送结果（成功/失败）
     */
    public function sendBatchEmail($recipients, $subject, $htmlContent, $fromEmail, $fromName) {
        $results = [];
        
        foreach ($recipients as $email => $name) {
            $result = $this->sendEmail($email, $subject, $htmlContent, $fromEmail, $fromName);
            $results[$email] = $result;
            
            // 发送间隔，避免被封禁
            sleep(1);
        }
        
        return $results;
    }

    /**
     * 添加邮件账号
     * @param string $qqNumber QQ 号码
     * @param string $authCode 授权码
     * @return bool 是否添加成功
     */
    public function addAccount($qqNumber, $authCode) {
        try {
            $stmt = $this->pdo->prepare("INSERT INTO email_accounts (qq_number, auth_code) VALUES (?, ?)");
            $stmt->execute([$qqNumber, $authCode]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 更新邮件账号
     * @param int $id 账号 ID
     * @param string $qqNumber QQ 号码
     * @param string $authCode 授权码
     * @return bool 是否更新成功
     */
    public function updateAccount($id, $qqNumber, $authCode) {
        try {
            $stmt = $this->pdo->prepare("UPDATE email_accounts SET qq_number = ?, auth_code = ? WHERE id = ?");
            $stmt->execute([$qqNumber, $authCode, $id]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 删除邮件账号
     * @param int $id 账号 ID
     * @return bool 是否删除成功
     */
    public function deleteAccount($id) {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM email_accounts WHERE id = ?");
            $stmt->execute([$id]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 验证邮件账号配置
     * @param string $qqNumber QQ 号码
     * @param string $authCode 授权码
     * @return bool|string 成功返回 true，失败返回错误信息
     */
    public function testAccount($qqNumber, $authCode) {
        require_once 'PHPMailer/PHPMailer.php';
        require_once 'PHPMailer/SMTP.php';
        require_once 'PHPMailer/Exception.php';
        
        use PHPMailer\PHPMailer\PHPMailer;
        use PHPMailer\PHPMailer\SMTP;
        use PHPMailer\PHPMailer\Exception;
        
        $mail = new PHPMailer(true);
        
        try {
            // 服务器配置
            $mail->SMTPDebug = SMTP::DEBUG_OFF;
            $mail->isSMTP();
            $mail->Host = $this->smtpConfig['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $qqNumber;
            $mail->Password = $authCode;
            $mail->Port = $this->smtpConfig['port'];
            $mail->SMTPSecure = $this->smtpConfig['secure'];
            $mail->CharSet = $this->smtpConfig['charset'];
            
            // 建立连接
            $mail->smtpConnect([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ]);
            
            return true;
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }
}
