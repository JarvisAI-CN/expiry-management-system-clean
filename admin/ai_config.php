<?php
/**
 * 星巴克门店智能效期管理系统 V3.0.0
 * AI 配置页面
 * 功能：OpenAI API 配置与测试
 * 作者：资深 PHP 全栈架构师
 * 日期：2026-02-24
 */

session_start();

// 引入必要的类文件
require_once '../core/Database.php';
require_once '../core/AuthService.php';

// 加载数据库配置
$config = include '../config/database.php';

// 创建数据库连接
$database = new Database($config);
$pdo = $database->getConnection();

// 创建鉴权服务
$authConfig = [
    'domain' => $_SERVER['HTTP_HOST'],
    'secure' => isset($_SERVER['HTTPS'])
];

$authService = new AuthService($pdo, $authConfig);

// 检查用户登录状态
if (!$authService->isLoggedIn()) {
    header('Location: ../login.php');
    exit;
}

// 获取系统配置
$stmt = $pdo->prepare("SELECT * FROM system_configs");
$stmt->execute();
$systemConfigs = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $systemConfigs[$row['config_key']] = $row['config_value'];
}

// 处理配置保存
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $configs = [
            'ai_endpoint' => $_POST['ai_endpoint'] ?? '',
            'ai_api_key' => $_POST['ai_api_key'] ?? '',
            'ai_model' => $_POST['ai_model'] ?? 'gpt-4o',
            'ai_timeout' => intval($_POST['ai_timeout'] ?? 30)
        ];
        
        // 验证必填字段
        if (empty($configs['ai_endpoint']) || empty($configs['ai_api_key'])) {
            throw new Exception("OpenAI 端点和 API 密钥不能为空");
        }
        
        // 验证 API 连通性（如果用户选择测试）
        if (isset($_POST['test_connection'])) {
            require_once '../core/AIService.php';
            
            $aiService = new AIService([
                'endpoint' => $configs['ai_endpoint'],
                'api_key' => $configs['ai_api_key'],
                'model' => $configs['ai_model'],
                'timeout' => $configs['ai_timeout']
            ]);
            
            $result = $aiService->testConnection();
            
            if ($result !== true) {
                throw new Exception($result);
            }
            
            $success = "API 连通性测试成功！";
        }
        
        // 保存配置
        foreach ($configs as $key => $value) {
            $stmt = $pdo->prepare("
                INSERT INTO system_configs (config_key, config_value, created_at, updated_at) 
                VALUES (?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE 
                    config_value = ?, updated_at = NOW()
            ");
            
            $stmt->execute([$key, $value, $value]);
        }
        
        if (!isset($_POST['test_connection'])) {
            $success = "配置保存成功！";
        }
        
        // 更新本地配置变量
        $systemConfigs = array_merge($systemConfigs, $configs);
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// 设置页面标题
$pageTitle = 'AI 配置中心 - 星巴克门店智能效期管理系统';

?>
<?php include '../includes/header.php'; ?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="page-title">AI 配置中心</h1>
        <a href="../dashboard.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> 返回首页
        </a>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> <?php echo $success; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <h5 class="card-title mb-4">OpenAI 配置</h5>
            
            <form method="post">
                <div class="mb-3">
                    <label for="ai_endpoint" class="form-label">API 端点</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="fas fa-link"></i>
                        </span>
                        <input type="text" class="form-control" id="ai_endpoint" name="ai_endpoint"
                               value="<?php echo escapeHtml($systemConfigs['ai_endpoint'] ?? 'https://api.openai.com/v1'); ?>"
                               placeholder="https://api.openai.com/v1" required>
                    </div>
                    <div class="form-text">
                        OpenAI API 接口地址。对于国内用户，可以使用代理地址。
                    </div>
                </div>

                <div class="mb-3">
                    <label for="ai_api_key" class="form-label">API 密钥</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="fas fa-key"></i>
                        </span>
                        <input type="password" class="form-control" id="ai_api_key" name="ai_api_key"
                               value="<?php echo escapeHtml($systemConfigs['ai_api_key'] ?? ''); ?>"
                               placeholder="sk-...">
                        <button type="button" class="btn btn-outline-secondary" id="toggleApiKey">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="form-text">
                        从 OpenAI 官网获取的 API 密钥。保持此密钥机密！
                    </div>
                </div>

                <div class="mb-3">
                    <label for="ai_model" class="form-label">模型名称</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="fas fa-brain"></i>
                        </span>
                        <select class="form-select" id="ai_model" name="ai_model" required>
                            <option value="gpt-4o" <?php echo ($systemConfigs['ai_model'] ?? 'gpt-4o') == 'gpt-4o' ? 'selected' : ''; ?>>
                                GPT-4o
                            </option>
                            <option value="gpt-4o-mini" <?php echo ($systemConfigs['ai_model'] ?? 'gpt-4o') == 'gpt-4o-mini' ? 'selected' : ''; ?>>
                                GPT-4o mini
                            </option>
                            <option value="gpt-3.5-turbo" <?php echo ($systemConfigs['ai_model'] ?? 'gpt-4o') == 'gpt-3.5-turbo' ? 'selected' : ''; ?>>
                                GPT-3.5 Turbo
                            </option>
                            <option value="gpt-4" <?php echo ($systemConfigs['ai_model'] ?? 'gpt-4o') == 'gpt-4' ? 'selected' : ''; ?>>
                                GPT-4
                            </option>
                        </select>
                    </div>
                    <div class="form-text">
                        用于分析的 OpenAI 模型。推荐使用 GPT-4o 以获得最佳效果。
                    </div>
                </div>

                <div class="mb-3">
                    <label for="ai_timeout" class="form-label">超时时间</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="fas fa-clock"></i>
                        </span>
                        <input type="number" class="form-control" id="ai_timeout" name="ai_timeout"
                               value="<?php echo intval($systemConfigs['ai_timeout'] ?? 30); ?>"
                               min="5" max="300" required>
                        <span class="input-group-text">秒</span>
                    </div>
                    <div class="form-text">
                        API 请求超时时间。建议设置为 30-60 秒。
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> 保存配置
                    </button>
                    <button type="submit" name="test_connection" class="btn btn-outline-primary">
                        <i class="fas fa-check-circle"></i> 保存并测试
                    </button>
                    <button type="reset" class="btn btn-outline-secondary">
                        <i class="fas fa-undo"></i> 重置
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if (!empty($systemConfigs['ai_endpoint']) && !empty($systemConfigs['ai_api_key'])): ?>
    <div class="card mt-4">
        <div class="card-body">
            <h5 class="card-title mb-4">
                <i class="fas fa-info-circle"></i> 当前配置状态
            </h5>
            
            <div class="table-responsive">
                <table class="table table-bordered">
                    <tr>
                        <td style="width: 30%"><strong>API 端点</strong></td>
                        <td><?php echo escapeHtml($systemConfigs['ai_endpoint'] ?? ''); ?></td>
                    </tr>
                    <tr>
                        <td><strong>API 密钥</strong></td>
                        <td>
                            <?php 
                            $apiKey = $systemConfigs['ai_api_key'] ?? '';
                            if (strlen($apiKey) > 8) {
                                echo substr($apiKey, 0, 4) . str_repeat('*', strlen($apiKey) - 8) . substr($apiKey, -4);
                            } else {
                                echo str_repeat('*', strlen($apiKey));
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>使用模型</strong></td>
                        <td><?php echo escapeHtml($systemConfigs['ai_model'] ?? 'gpt-4o'); ?></td>
                    </tr>
                    <tr>
                        <td><strong>超时设置</strong></td>
                        <td><?php echo intval($systemConfigs['ai_timeout'] ?? 30); ?> 秒</td>
                    </tr>
                    <tr>
                        <td><strong>最后更新</strong></td>
                        <td>
                            <?php 
                            $stmt = $pdo->prepare("SELECT updated_at FROM system_configs WHERE config_key = 'ai_endpoint'");
                            $stmt->execute();
                            $lastUpdate = $stmt->fetchColumn();
                            echo $lastUpdate ? formatDate($lastUpdate) : '未设置';
                            ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card mt-4">
        <div class="card-body">
            <h5 class="card-title mb-4">
                <i class="fas fa-lightbulb"></i> 配置建议
            </h5>
            
            <div class="alert alert-info">
                <h6><i class="fas fa-shield-alt"></i> 安全提示</h6>
                <ul>
                    <li>API 密钥是您的访问凭证，请勿泄露给他人</li>
                    <li>建议定期轮换 API 密钥，提高安全性</li>
                    <li>如果使用代理服务，请确保其安全性和稳定性</li>
                </ul>
            </div>
            
            <div class="alert alert-warning">
                <h6><i class="fas fa-bolt"></i> 性能建议</h6>
                <ul>
                    <li>GPT-4o 提供最佳的分析效果，但成本较高</li>
                    <li>GPT-4o mini 速度更快且成本更低，适合简单分析</li>
                    <li>根据您的需求选择合适的模型，以平衡成本和性能</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
    // API密钥显示/隐藏
    $('#toggleApiKey').click(function() {
        const input = $('#ai_api_key');
        const icon = $(this).find('i');
        
        if (input.attr('type') === 'password') {
            input.attr('type', 'text');
            icon.removeClass('fa-eye').addClass('fa-eye-slash');
        } else {
            input.attr('type', 'password');
            icon.removeClass('fa-eye-slash').addClass('fa-eye');
        }
    });
</script>

<?php include '../includes/footer.php'; ?>
