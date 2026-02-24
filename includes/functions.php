<?php
/**
 * 星巴克门店智能效期管理系统 V3.0.0
 * 公用函数库
 * 功能：提供系统通用的函数和工具方法
 * 作者：资深 PHP 全栈架构师
 * 日期：2026-02-24
 */

/**
 * 生成随机字符串
 * @param int $length 字符串长度
 * @param string $chars 字符集
 * @return string 随机字符串
 */
function generateRandomString($length = 16, $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ') {
    $randomString = '';
    $charsLength = strlen($chars);
    
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $chars[rand(0, $charsLength - 1)];
    }
    
    return $randomString;
}

/**
 * 格式化日期
 * @param string $date 日期字符串
 * @param string $format 输出格式
 * @return string 格式化后的日期
 */
function formatDate($date, $format = 'Y-m-d H:i:s') {
    if (empty($date) || $date === '0000-00-00 00:00:00') {
        return '-';
    }
    
    try {
        $dateTime = new DateTime($date);
        return $dateTime->format($format);
    } catch (Exception $e) {
        return $date;
    }
}

/**
 * 计算两个日期之间的天数
 * @param string $date1 第一个日期
 * @param string $date2 第二个日期
 * @return int 天数差
 */
function daysBetween($date1, $date2) {
    try {
        $d1 = new DateTime($date1);
        $d2 = new DateTime($date2);
        $interval = $d1->diff($d2);
        return abs($interval->days);
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * 获取相对时间
 * @param string $date 日期字符串
 * @return string 相对时间
 */
function getRelativeTime($date) {
    if (empty($date) || $date === '0000-00-00 00:00:00') {
        return '-';
    }
    
    try {
        $now = new DateTime();
        $target = new DateTime($date);
        $interval = $now->diff($target);
        
        if ($target > $now) {
            if ($interval->days > 365) {
                return $interval->y . '年后';
            } elseif ($interval->days > 30) {
                return $interval->m . '个月后';
            } elseif ($interval->days > 0) {
                return $interval->days . '天后';
            } elseif ($interval->h > 0) {
                return $interval->h . '小时后';
            } elseif ($interval->i > 0) {
                return $interval->i . '分钟后';
            } else {
                return $interval->s . '秒后';
            }
        } else {
            if ($interval->days > 365) {
                return $interval->y . '年前';
            } elseif ($interval->days > 30) {
                return $interval->m . '个月前';
            } elseif ($interval->days > 0) {
                return $interval->days . '天前';
            } elseif ($interval->h > 0) {
                return $interval->h . '小时前';
            } elseif ($interval->i > 0) {
                return $interval->i . '分钟前';
            } else {
                return $interval->s . '秒前';
            }
        }
    } catch (Exception $e) {
        return $date;
    }
}

/**
 * 格式化数字
 * @param float $number 数字
 * @param int $decimals 小数位数
 * @return string 格式化后的数字
 */
function formatNumber($number, $decimals = 2) {
    if (is_null($number) || $number === '') {
        return '0';
    }
    
    $number = floatval($number);
    
    // 如果是整数，去掉小数位
    if ($decimals > 0 && floor($number) == $number) {
        $decimals = 0;
    }
    
    return number_format($number, $decimals);
}

/**
 * 格式化价格
 * @param float $price 价格
 * @param string $currency 货币符号
 * @return string 格式化后的价格
 */
function formatPrice($price, $currency = '¥') {
    $formatted = formatNumber($price);
    return $currency . $formatted;
}

/**
 * 安全转义 HTML
 * @param string $string 原始字符串
 * @return string 转义后的字符串
 */
function escapeHtml($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * 获取客户端 IP 地址
 * @return string IP 地址
 */
function getClientIp() {
    $ipAddress = 'unknown';
    
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ipAddress = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED'])) {
        $ipAddress = $_SERVER['HTTP_X_FORWARDED'];
    } elseif (!empty($_SERVER['HTTP_FORWARDED_FOR'])) {
        $ipAddress = $_SERVER['HTTP_FORWARDED_FOR'];
    } elseif (!empty($_SERVER['HTTP_FORWARDED'])) {
        $ipAddress = $_SERVER['HTTP_FORWARDED'];
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $ipAddress = $_SERVER['REMOTE_ADDR'];
    }
    
    // 如果有多个 IP 地址，取第一个
    if (strpos($ipAddress, ',') !== false) {
        $ipAddress = trim(explode(',', $ipAddress)[0]);
    }
    
    return $ipAddress;
}

/**
 * 生成 Pagination 链接
 * @param int $currentPage 当前页码
 * @param int $totalPages 总页数
 * @param int $visiblePages 可见页码数量
 * @return string HTML 链接
 */
function generatePagination($currentPage, $totalPages, $visiblePages = 5) {
    if ($totalPages <= 1) {
        return '';
    }
    
    $html = '<nav aria-label="Page navigation">';
    $html .= '<ul class="pagination justify-content-center">';
    
    // 上一页
    $html .= '<li class="page-item' . ($currentPage <= 1 ? ' disabled' : '') . '">';
    $html .= '<a class="page-link" href="?page=' . ($currentPage - 1) . '" aria-label="Previous">';
    $html .= '<span aria-hidden="true">&laquo;</span>';
    $html .= '</a>';
    $html .= '</li>';
    
    // 页码
    $half = floor($visiblePages / 2);
    $start = max(1, $currentPage - $half);
    $end = min($totalPages, $start + $visiblePages - 1);
    
    if ($start > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="?page=1">1</a></li>';
        if ($start > 2) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }
    
    for ($i = $start; $i <= $end; $i++) {
        $html .= '<li class="page-item' . ($i == $currentPage ? ' active' : '') . '">';
        $html .= '<a class="page-link" href="?page=' . $i . '">' . $i . '</a>';
        $html .= '</li>';
    }
    
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
        $html .= '<li class="page-item"><a class="page-link" href="?page=' . $totalPages . '">' . $totalPages . '</a></li>';
    }
    
    // 下一页
    $html .= '<li class="page-item' . ($currentPage >= $totalPages ? ' disabled' : '') . '">';
    $html .= '<a class="page-link" href="?page=' . ($currentPage + 1) . '" aria-label="Next">';
    $html .= '<span aria-hidden="true">&raquo;</span>';
    $html .= '</a>';
    $html .= '</li>';
    
    $html .= '</ul>';
    $html .= '</nav>';
    
    return $html;
}

/**
 * 获取文件大小的可读格式
 * @param int $bytes 文件大小（字节）
 * @return string 可读格式的文件大小
 */
function humanFileSize($bytes) {
    $sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    for ($i = 0; $bytes >= 1024 && $i < count($sizes) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, 2) . ' ' . $sizes[$i];
}

/**
 * 检查文件是否是图像
 * @param string $filename 文件名
 * @return bool 是否是图像
 */
function isImage($filename) {
    $imageTypes = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, $imageTypes);
}

/**
 * 生成缩略图
 * @param string $source 源图像路径
 * @param string $dest 目标图像路径
 * @param int $width 宽度
 * @param int $height 高度
 * @return bool 是否成功
 */
function generateThumbnail($source, $dest, $width, $height) {
    try {
        list($srcWidth, $srcHeight, $type) = getimagesize($source);
        
        $imageCreateFunc = 'imagecreatefrom' . image_type_to_extension($type, false);
        $imageSaveFunc = 'image' . image_type_to_extension($type, false);
        
        $srcImage = $imageCreateFunc($source);
        $destImage = imagecreatetruecolor($width, $height);
        
        // 计算比例并调整尺寸
        $ratio = min($width / $srcWidth, $height / $srcHeight);
        $newWidth = $srcWidth * $ratio;
        $newHeight = $srcHeight * $ratio;
        
        $offsetX = ($width - $newWidth) / 2;
        $offsetY = ($height - $newHeight) / 2;
        
        imagecopyresampled(
            $destImage, $srcImage, 
            $offsetX, $offsetY, 
            0, 0, 
            $newWidth, $newHeight, 
            $srcWidth, $srcHeight
        );
        
        $imageSaveFunc($destImage, $dest);
        imagedestroy($srcImage);
        imagedestroy($destImage);
        
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * 发送 HTTP 请求
 * @param string $url URL
 * @param array $options 选项
 * @return array 响应
 */
function sendRequest($url, $options = []) {
    $ch = curl_init();
    
    $defaults = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
    ];
    
    $options = array_merge($defaults, $options);
    curl_setopt_array($ch, $options);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    return [
        'success' => $httpCode >= 200 && $httpCode < 300,
        'status' => $httpCode,
        'data' => $response,
        'error' => $error
    ];
}

/**
 * 记录日志
 * @param string $message 消息
 * @param string $level 级别（info, warning, error, debug）
 * @param string $category 分类
 */
function logMessage($message, $level = 'info', $category = 'general') {
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logFile = $logDir . '/' . $category . '_' . date('Ymd') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $message\n";
    
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

/**
 * 生成 UUID
 * @return string UUID
 */
function generateUUID() {
    if (function_exists('uuid_create') && !function_exists('uuid_is_valid')) {
        return uuid_create(UUID_TYPE_RANDOM);
    }
    
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * 获取浏览器信息
 * @return string 浏览器信息
 */
function getBrowserInfo() {
    if (!isset($_SERVER['HTTP_USER_AGENT'])) {
        return 'Unknown';
    }
    
    $userAgent = $_SERVER['HTTP_USER_AGENT'];
    $browser = 'Unknown';
    
    if (strpos($userAgent, 'Chrome')) {
        $browser = 'Chrome';
    } elseif (strpos($userAgent, 'Firefox')) {
        $browser = 'Firefox';
    } elseif (strpos($userAgent, 'Safari')) {
        $browser = 'Safari';
    } elseif (strpos($userAgent, 'Edg')) {
        $browser = 'Edge';
    } elseif (strpos($userAgent, 'MSIE') || strpos($userAgent, 'Trident/')) {
        $browser = 'Internet Explorer';
    }
    
    return $browser;
}

/**
 * 检查是否是移动端
 * @return bool 是否是移动端
 */
function isMobile() {
    if (!isset($_SERVER['HTTP_USER_AGENT'])) {
        return false;
    }
    
    $userAgent = strtolower($_SERVER['HTTP_USER_AGENT']);
    $mobileKeywords = ['android', 'webos', 'iphone', 'ipad', 'ipod', 'blackberry', 'windows phone'];
    
    foreach ($mobileKeywords as $keyword) {
        if (strpos($userAgent, $keyword) !== false) {
            return true;
        }
    }
    
    return false;
}

/**
 * 获取文件扩展名
 * @param string $filename 文件名
 * @return string 扩展名
 */
function getFileExtension($filename) {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

/**
 * 验证邮箱格式
 * @param string $email 邮箱地址
 * @return bool 是否是有效邮箱
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * 验证手机号格式（中国大陆）
 * @param string $phone 手机号
 * @return bool 是否是有效手机号
 */
function isValidPhone($phone) {
    $phone = preg_replace('/[\s\-\(\)]/', '', $phone);
    return preg_match('/^1[3-9]\d{9}$/', $phone);
}
