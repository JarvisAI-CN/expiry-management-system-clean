<?php
/**
 * 星巴克门店智能效期管理系统 V3.0.0
 * AI 服务类
 * 功能：封装 OpenAI API 调用
 * 作者：资深 PHP 全栈架构师
 * 日期：2026-02-24
 */

class AIService {
    private $endpoint;
    private $apiKey;
    private $model;
    private $timeout;

    /**
     * 构造函数
     * @param array $config 配置信息
     */
    public function __construct($config = []) {
        $this->endpoint = $config['endpoint'] ?? 'https://api.openai.com/v1';
        $this->apiKey = $config['api_key'] ?? '';
        $this->model = $config['model'] ?? 'gpt-4o';
        $this->timeout = $config['timeout'] ?? 30;
    }

    /**
     * 设置配置
     * @param array $config 配置信息
     */
    public function setConfig($config) {
        if (isset($config['endpoint'])) $this->endpoint = $config['endpoint'];
        if (isset($config['api_key'])) $this->apiKey = $config['api_key'];
        if (isset($config['model'])) $this->model = $config['model'];
        if (isset($config['timeout'])) $this->timeout = $config['timeout'];
    }

    /**
     * 测试 API 连通性
     * @return bool|string 成功返回 true，失败返回错误信息
     */
    public function testConnection() {
        if (empty($this->apiKey)) {
            return "API Key 未配置";
        }
        
        try {
            $response = $this->sendRequest('chat/completions', [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => '请返回 "Hello, World!" 作为测试响应'
                    ]
                ],
                'max_tokens' => 50,
                'temperature' => 0
            ]);
            
            if ($response['choices'][0]['message']['content'] === 'Hello, World!') {
                return true;
            } else {
                return "响应内容不符合预期";
            }
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * 分析库存健康度
     * @param array $data 最近一次盘点数据
     * @return string AI 分析结果
     */
    public function analyzeStockHealth($data) {
        $prompt = $this->generateStockHealthPrompt($data);
        
        return $this->callChatAPI($prompt);
    }

    /**
     * 分析盘点记录
     * @param array $data 盘点数据
     * @return string AI 分析结果
     */
    public function analyzeStocktake($data) {
        $prompt = $this->generateStocktakePrompt($data);
        
        return $this->callChatAPI($prompt);
    }

    /**
     * 生成 HTML 报告
     * @param array $data 数据
     * @return string HTML 格式的报告
     */
    public function generateHTMLReport($data) {
        $prompt = $this->generateReportPrompt($data);
        
        return $this->callChatAPI($prompt);
    }

    /**
     * 调用聊天 API
     * @param string $prompt 提示词
     * @return string API 响应内容
     */
    private function callChatAPI($prompt) {
        if (empty($this->apiKey)) {
            return "API Key 未配置";
        }
        
        try {
            $response = $this->sendRequest('chat/completions', [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => '你是星巴克门店智能效期管理系统的 AI 分析师。请根据提供的数据，提供准确的分析报告。'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'max_tokens' => 1000,
                'temperature' => 0.3
            ]);
            
            return $response['choices'][0]['message']['content'];
        } catch (Exception $e) {
            return "AI 分析失败：" . $e->getMessage();
        }
    }

    /**
     * 发送 API 请求
     * @param string $path 请求路径
     * @param array $data 请求数据
     * @return array 响应数据
     * @throws Exception
     */
    private function sendRequest($path, $data) {
        $url = rtrim($this->endpoint, '/') . '/' . $path;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            throw new Exception("请求失败：" . curl_error($ch));
        }
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        curl_close($ch);
        
        $responseData = json_decode($response, true);
        
        if ($httpCode != 200) {
            $errorMsg = $responseData['error']['message'] ?? '未知错误';
            throw new Exception("API 请求失败 (HTTP {$httpCode}): {$errorMsg}");
        }
        
        return $responseData;
    }

    /**
     * 生成库存健康度分析提示词
     * @param array $data 数据
     * @return string 提示词
     */
    private function generateStockHealthPrompt($data) {
        $productCounts = $this->countByProduct($data);
        $expiryStats = $this->analyzeExpiryStats($data);
        
        $prompt = "根据以下星巴克门店效期管理数据，请分析库存健康度：\n\n";
        
        $prompt .= "## 库存概览\n";
        $prompt .= "产品数量：" . count($productCounts) . " 种\n";
        
        $prompt .= "\n## 效期分析\n";
        foreach ($expiryStats as $product => $stats) {
            $prompt .= "产品 {$product}：" . $stats['total'] . " 个，" . $stats['expired'] . " 个已过期，" . $stats['expiring_soon'] . " 个即将过期\n";
        }
        
        $prompt .= "\n## 健康度评分\n";
        $prompt .= "请根据过期和即将过期产品的比例，给出库存健康度评分（1-10分），并提供优化建议。\n";
        
        $prompt .= "\n## 格式要求\n";
        $prompt .= "返回一个简洁的 HTML 格式报告，包含以下内容：\n";
        $prompt .= "- 库存健康度评分\n";
        $prompt .= "- 主要问题产品\n";
        $prompt .= "- 优化建议\n";
        $prompt .= "- 使用中文，保持专业但易懂的语气\n";
        
        return $prompt;
    }

    /**
     * 生成盘点分析提示词
     * @param array $data 数据
     * @return string 提示词
     */
    private function generateStocktakePrompt($data) {
        $productCounts = $this->countByProduct($data);
        $expiryStats = $this->analyzeExpiryStats($data);
        
        $prompt = "你是一名星巴克门店库存分析师。请根据以下盘点数据，生成详细的分析报告。\n\n";
        
        $prompt .= "## 盘点摘要\n";
        $prompt .= "产品种类：" . count($productCounts) . " 种\n";
        $prompt .= "总数量：" . array_sum(array_column($productCounts, 'count')) . " 个\n";
        
        $prompt .= "\n## 产品分布\n";
        foreach ($productCounts as $product => $count) {
            $prompt .= "{$product}：{$count} 个\n";
        }
        
        $prompt .= "\n## 效期预警\n";
        $expiringSoonProducts = [];
        $expiredProducts = [];
        
        foreach ($expiryStats as $product => $stats) {
            if ($stats['expired'] > 0) {
                $expiredProducts[] = "{$product}（" . $stats['expired'] . " 个）";
            }
            
            if ($stats['expiring_soon'] > 0) {
                $expiringSoonProducts[] = "{$product}（" . $stats['expiring_soon'] . " 个）";
            }
        }
        
        if (!empty($expiredProducts)) {
            $prompt .= "过期产品：" . implode(', ', $expiredProducts) . "\n";
        }
        
        if (!empty($expiringSoonProducts)) {
            $prompt .= "即将过期产品：" . implode(', ', $expiringSoonProducts) . "\n";
        }
        
        $prompt .= "\n## 分析要求\n";
        $prompt .= "1. 识别库存健康问题\n";
        $prompt .= "2. 提供产品优化建议\n";
        $prompt .= "3. 给出订货策略建议\n";
        $prompt .= "4. 使用中文，保持专业但易懂的语气\n";
        $prompt .= "5. 重点关注报损率高和即将过期的产品\n";
        
        return $prompt;
    }

    /**
     * 生成报告提示词
     * @param array $data 数据
     * @return string 提示词
     */
    private function generateReportPrompt($data) {
        $productCounts = $this->countByProduct($data);
        $expiryStats = $this->analyzeExpiryStats($data);
        
        $prompt = "请生成一份星巴克门店效期管理系统的分析报告。\n\n";
        
        $prompt .= "## 数据来源\n";
        $prompt .= "- 盘点日期：" . date('Y-m-d') . "\n";
        $prompt .= "- 产品种类：" . count($productCounts) . " 种\n";
        $prompt .= "- 总数量：" . array_sum(array_column($productCounts, 'count')) . " 个\n";
        
        $prompt .= "\n## 产品效期分析\n";
        foreach ($expiryStats as $product => $stats) {
            $prompt .= "{$product}：{$stats['total']} 个，{$stats['expired']} 个已过期，{$stats['expiring_soon']} 个即将过期\n";
        }
        
        $prompt .= "\n## 报告要求\n";
        $prompt .= "1. 使用标准 HTML 格式\n";
        $prompt .= "2. 包含图表和统计数据（可使用 Bootstrap 5 组件）\n";
        $prompt .= "3. 使用中文，保持专业但易懂的语气\n";
        $prompt .= "4. 重点关注健康度评分和优化建议\n";
        $prompt .= "5. 提供报告生成时间\n";
        $prompt .= "6. 建议输出内容结构清晰，层次分明\n";
        
        return $prompt;
    }

    /**
     * 统计产品数量
     * @param array $data 数据
     * @return array 产品数量统计
     */
    private function countByProduct($data) {
        $counts = [];
        
        foreach ($data as $item) {
            $productName = $item['product_name'];
            $counts[$productName] = $counts[$productName] ?? 0;
            $counts[$productName]++;
        }
        
        return $counts;
    }

    /**
     * 分析效期统计信息
     * @param array $data 数据
     * @return array 效期统计信息
     */
    private function analyzeExpiryStats($data) {
        $stats = [];
        $today = new DateTime();
        $expirationDays = [
            'expired' => 0,           // 已过期
            'expiring_soon' => 3,    // 即将过期（3天内）
            'fresh' => 7             // 新鲜（7天内）
        ];
        
        foreach ($data as $item) {
            $productName = $item['product_name'];
            
            if (!isset($stats[$productName])) {
                $stats[$productName] = [
                    'total' => 0,
                    'expired' => 0,
                    'expiring_soon' => 0
                ];
            }
            
            $stats[$productName]['total']++;
            
            $expiryDate = new DateTime($item['expiry_date']);
            $daysUntilExpiry = $expiryDate->diff($today)->days;
            
            if ($expiryDate < $today) {
                $stats[$productName]['expired']++;
            } elseif ($daysUntilExpiry <= $expirationDays['expiring_soon']) {
                $stats[$productName]['expiring_soon']++;
            }
        }
        
        return $stats;
    }
}
