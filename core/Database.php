<?php
/**
 * 星巴克门店智能效期管理系统 V3.0.0
 * 数据库连接类
 * 功能：提供数据库连接和基本操作
 * 作者：资深 PHP 全栈架构师
 * 日期：2026-02-24
 */

class Database {
    private $pdo;
    private $config;

    /**
     * 构造函数
     * @param array $config 数据库配置
     */
    public function __construct($config = []) {
        $this->config = $config;
    }

    /**
     * 获取数据库连接
     * @return PDO 数据库连接对象
     */
    public function getConnection() {
        if (!$this->pdo) {
            $this->connect();
        }
        
        return $this->pdo;
    }

    /**
     * 建立数据库连接
     * @throws PDOException
     */
    private function connect() {
        try {
            // 检查配置文件
            if (empty($this->config)) {
                throw new PDOException("数据库配置为空");
            }
            
            // 构建 DSN
            $dsn = sprintf(
                "mysql:host=%s;dbname=%s;charset=%s",
                $this->config['host'],
                $this->config['name'],
                $this->config['charset']
            );
            
            // 创建 PDO 连接
            $this->pdo = new PDO(
                $dsn,
                $this->config['user'],
                $this->config['pass'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            throw new PDOException("数据库连接失败：" . $e->getMessage());
        }
    }

    /**
     * 查询所有记录
     * @param string $query SQL 查询语句
     * @param array $params 查询参数
     * @return array 查询结果
     */
    public function fetchAll($query, $params = []) {
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            throw new PDOException("查询失败：" . $e->getMessage());
        }
    }

    /**
     * 查询单条记录
     * @param string $query SQL 查询语句
     * @param array $params 查询参数
     * @return array|false 查询结果或 false
     */
    public function fetchOne($query, $params = []) {
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            return $stmt->fetch();
        } catch (PDOException $e) {
            throw new PDOException("查询失败：" . $e->getMessage());
        }
    }

    /**
     * 执行插入操作
     * @param string $table 表名
     * @param array $data 数据（关联数组）
     * @return int 插入的 ID
     */
    public function insert($table, $data) {
        try {
            $columns = implode(', ', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));
            
            $query = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
            
            $stmt = $this->pdo->prepare($query);
            
            foreach ($data as $key => $value) {
                $stmt->bindValue(":{$key}", $value);
            }
            
            $stmt->execute();
            
            return $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            throw new PDOException("插入失败：" . $e->getMessage());
        }
    }

    /**
     * 执行更新操作
     * @param string $table 表名
     * @param array $data 数据（关联数组）
     * @param string $where WHERE 条件
     * @param array $whereParams WHERE 参数
     * @return int 影响的行数
     */
    public function update($table, $data, $where, $whereParams = []) {
        try {
            $set = [];
            
            foreach (array_keys($data) as $column) {
                $set[] = "{$column} = :{$column}";
            }
            
            $setClause = implode(', ', $set);
            $query = "UPDATE {$table} SET {$setClause} WHERE {$where}";
            
            $stmt = $this->pdo->prepare($query);
            
            foreach ($data as $key => $value) {
                $stmt->bindValue(":{$key}", $value);
            }
            
            foreach ($whereParams as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            $stmt->execute();
            
            return $stmt->rowCount();
        } catch (PDOException $e) {
            throw new PDOException("更新失败：" . $e->getMessage());
        }
    }

    /**
     * 执行删除操作
     * @param string $table 表名
     * @param string $where WHERE 条件
     * @param array $params 参数
     * @return int 影响的行数
     */
    public function delete($table, $where, $params = []) {
        try {
            $query = "DELETE FROM {$table} WHERE {$where}";
            
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            
            return $stmt->rowCount();
        } catch (PDOException $e) {
            throw new PDOException("删除失败：" . $e->getMessage());
        }
    }

    /**
     * 执行事务
     * @param callable $callback 事务回调函数
     * @return mixed 回调函数的返回值
     */
    public function transaction($callback) {
        try {
            $this->pdo->beginTransaction();
            
            $result = $callback($this);
            
            $this->pdo->commit();
            
            return $result;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw new PDOException("事务执行失败：" . $e->getMessage());
        }
    }

    /**
     * 获取表的记录数
     * @param string $table 表名
     * @param string $where WHERE 条件
     * @param array $params 参数
     * @return int 记录数
     */
    public function count($table, $where = '1=1', $params = []) {
        try {
            $query = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
            
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            throw new PDOException("计数失败：" . $e->getMessage());
        }
    }

    /**
     * 检查记录是否存在
     * @param string $table 表名
     * @param string $column 列名
     * @param mixed $value 值
     * @return bool 是否存在
     */
    public function exists($table, $column, $value) {
        try {
            $query = "SELECT COUNT(*) FROM {$table} WHERE {$column} = ?";
            
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([$value]);
            
            return (int)$stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            throw new PDOException("检查失败：" . $e->getMessage());
        }
    }
}
