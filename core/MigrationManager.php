<?php
/**
 * 星巴克门店智能效期管理系统 V3.0.0
 * 数据库迁移管理器
 * 功能：自动检测并应用数据库结构变更
 * 作者：资深 PHP 全栈架构师
 * 日期：2026-02-24
 */

class MigrationManager {
    private $pdo;
    private $migrationsDir;
    private $tablePrefix;

    public function __construct($pdo, $migrationsDir = null, $tablePrefix = '') {
        $this->pdo = $pdo;
        $this->tablePrefix = $tablePrefix;
        $this->migrationsDir = $migrationsDir ?: __DIR__ . '/../migrations';
        
        $this->init();
    }

    private function init() {
        // 创建迁移记录表（如果不存在）
        $this->createMigrationTable();
    }

    private function createMigrationTable() {
        $tableName = $this->tablePrefix . 'migrations';
        
        $sql = "
            CREATE TABLE IF NOT EXISTS `{$tableName}` (
                `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '迁移ID',
                `migration_name` varchar(255) NOT NULL COMMENT '迁移文件名',
                `version` int(11) NOT NULL COMMENT '版本号',
                `applied_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '应用时间',
                PRIMARY KEY (`id`),
                UNIQUE KEY `migration_name` (`migration_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数据库迁移记录表';
        ";
        
        $this->pdo->exec($sql);
    }

    public function getCurrentVersion() {
        $tableName = $this->tablePrefix . 'migrations';
        
        $sql = "SELECT MAX(version) as current_version FROM `{$tableName}`";
        $stmt = $this->pdo->query($sql);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result['current_version'] ?? 0;
    }

    public function getAvailableMigrations() {
        $migrations = [];
        
        if (!is_dir($this->migrationsDir)) {
            mkdir($this->migrationsDir, 0755, true);
            return $migrations;
        }
        
        $files = glob($this->migrationsDir . '/*.php');
        
        foreach ($files as $file) {
            $filename = basename($file);
            
            if (preg_match('/^(\d+)_(.*)\.php$/', $filename, $matches)) {
                $version = (int)$matches[1];
                $name = $matches[2];
                
                $migrations[$version] = [
                    'version' => $version,
                    'name' => $name,
                    'filename' => $filename,
                    'path' => $file
                ];
            }
        }
        
        ksort($migrations);
        
        return $migrations;
    }

    public function getPendingMigrations() {
        $currentVersion = $this->getCurrentVersion();
        $availableMigrations = $this->getAvailableMigrations();
        
        $pendingMigrations = [];
        
        foreach ($availableMigrations as $version => $migration) {
            if ($version > $currentVersion) {
                $pendingMigrations[$version] = $migration;
            }
        }
        
        return $pendingMigrations;
    }

    public function applyMigrations($targetVersion = null) {
        $pendingMigrations = $this->getPendingMigrations();
        
        if (empty($pendingMigrations)) {
            return [
                'success' => true,
                'message' => '没有需要应用的迁移',
                'applied' => 0
            ];
        }
        
        $appliedCount = 0;
        $errors = [];
        $transactionStarted = false;
        
        try {
            $this->pdo->beginTransaction();
            $transactionStarted = true;
            
            foreach ($pendingMigrations as $version => $migration) {
                if ($targetVersion && $version > $targetVersion) {
                    break;
                }
                
                try {
                    // 应用迁移
                    $this->applyMigration($migration);
                    $appliedCount++;
                } catch (Exception $e) {
                    $errors[] = [
                        'version' => $version,
                        'name' => $migration['name'],
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            if (empty($errors)) {
                $this->pdo->commit();
                $transactionStarted = false;
                
                return [
                    'success' => true,
                    'message' => "成功应用了 {$appliedCount} 个迁移",
                    'applied' => $appliedCount
                ];
            } else {
                if ($transactionStarted) {
                    $this->pdo->rollBack();
                    $transactionStarted = false;
                }
                
                return [
                    'success' => false,
                    'message' => '部分迁移失败',
                    'applied' => $appliedCount,
                    'errors' => $errors
                ];
            }
        } catch (Exception $e) {
            if ($transactionStarted) {
                $this->pdo->rollBack();
                $transactionStarted = false;
            }
            
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'applied' => 0
            ];
        }
    }

    private function applyMigration($migration) {
        $tableName = $this->tablePrefix . 'migrations';
        
        // 执行迁移文件
        require_once $migration['path'];
        
        $className = $this->getMigrationClassName($migration['name']);
        
        if (!class_exists($className)) {
            throw new Exception("Migration class {$className} not found");
        }
        
        $migrationInstance = new $className($this->pdo);
        
        if (!method_exists($migrationInstance, 'up')) {
            throw new Exception("Migration class {$className} must have 'up' method");
        }
        
        $migrationInstance->up();
        
        // 记录迁移
        $sql = "
            INSERT INTO `{$tableName}` (migration_name, version, applied_at)
            VALUES (?, ?, NOW())
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$migration['filename'], $migration['version']]);
    }

    private function getMigrationClassName($name) {
        // 转换为驼峰式类名
        $parts = explode('_', $name);
        $className = '';
        
        foreach ($parts as $part) {
            $className .= ucfirst($part);
        }
        
        return $className . 'Migration';
    }

    public function createMigration($name, $upSql = '', $downSql = '') {
        $currentVersion = $this->getCurrentVersion();
        $newVersion = $currentVersion + 1;
        
        $filename = sprintf('%04d_%s.php', $newVersion, $name);
        $filepath = $this->migrationsDir . '/' . $filename;
        
        $className = $this->getMigrationClassName($name);
        
        $content = <<<PHP
<?php
/**
 * 迁移：{$name}
 * 版本：{$newVersion}
 * 作者：系统自动生成
 * 日期：" . date('Y-m-d H:i:s') . "
 */

class {$className} {
    private \$pdo;

    public function __construct(\$pdo) {
        \$this->pdo = \$pdo;
    }

    public function up() {
        // 向上迁移脚本
        \$sql = <<<SQL
{$upSql}
SQL;
        
        \$this->pdo->exec(\$sql);
    }

    public function down() {
        // 向下迁移脚本
        \$sql = <<<SQL
{$downSql}
SQL;
        
        \$this->pdo->exec(\$sql);
    }
}

PHP;
        
        file_put_contents($filepath, $content);
        
        return [
            'success' => true,
            'filename' => $filename,
            'version' => $newVersion
        ];
    }

    public function getMigrationHistory() {
        $tableName = $this->tablePrefix . 'migrations';
        
        $sql = "
            SELECT * FROM `{$tableName}` 
            ORDER BY applied_at DESC
        ";
        
        $stmt = $this->pdo->query($sql);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function rollback($targetVersion = 0) {
        $tableName = $this->tablePrefix . 'migrations';
        
        $sql = "
            SELECT * FROM `{$tableName}` 
            WHERE version > ?
            ORDER BY version DESC
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$targetVersion]);
        $migrationsToRollback = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($migrationsToRollback)) {
            return [
                'success' => true,
                'message' => '没有需要回滚的迁移',
                'rolledback' => 0
            ];
        }
        
        $rolledbackCount = 0;
        $errors = [];
        
        $this->pdo->beginTransaction();
        
        try {
            foreach ($migrationsToRollback as $migration) {
                try {
                    // 回滚迁移
                    $this->rollbackMigration($migration['migration_name']);
                    $rolledbackCount++;
                } catch (Exception $e) {
                    $errors[] = [
                        'version' => $migration['version'],
                        'name' => $migration['migration_name'],
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            if (empty($errors)) {
                $this->pdo->commit();
                
                return [
                    'success' => true,
                    'message' => "成功回滚了 {$rolledbackCount} 个迁移",
                    'rolledback' => $rolledbackCount
                ];
            } else {
                $this->pdo->rollBack();
                
                return [
                    'success' => false,
                    'message' => '部分迁移回滚失败',
                    'rolledback' => $rolledbackCount,
                    'errors' => $errors
                ];
            }
        } catch (Exception $e) {
            $this->pdo->rollBack();
            
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'rolledback' => 0
            ];
        }
    }

    private function rollbackMigration($filename) {
        $tableName = $this->tablePrefix . 'migrations';
        
        $filePath = $this->migrationsDir . '/' . $filename;
        
        if (!file_exists($filePath)) {
            throw new Exception("Migration file not found: {$filename}");
        }
        
        require_once $filePath;
        
        if (preg_match('/^(\d+)_(.*)\.php$/', $filename, $matches)) {
            $version = (int)$matches[1];
            $name = $matches[2];
            
            $className = $this->getMigrationClassName($name);
            
            if (!class_exists($className)) {
                throw new Exception("Migration class not found: {$className}");
            }
            
            $migrationInstance = new $className($this->pdo);
            
            if (!method_exists($migrationInstance, 'down')) {
                throw new Exception("Migration class {$className} must have 'down' method");
            }
            
            $migrationInstance->down();
            
            // 删除迁移记录
            $sql = "DELETE FROM `{$tableName}` WHERE migration_name = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$filename]);
        }
    }

    public function checkAndApplyMigrations() {
        $pendingMigrations = $this->getPendingMigrations();
        
        if (empty($pendingMigrations)) {
            return [
                'success' => true,
                'message' => '数据库结构与代码保持一致',
                'applied' => 0
            ];
        }
        
        return $this->applyMigrations();
    }
}
