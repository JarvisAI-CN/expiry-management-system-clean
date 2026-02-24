<?php
/**
 * 星巴克门店智能效期管理系统 V3.0.0
 * 数据导入服务类
 * 功能：处理 Excel 文件解析、数据导入与映射
 * 作者：资深 PHP 全栈架构师
 * 日期：2026-02-24
 */

class ImportService {
    private $pdo;

    /**
     * 构造函数
     * @param PDO $pdo 数据库连接对象
     */
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * 解析 Excel 文件
     * @param string $filePath 文件路径
     * @return array|string 成功返回解析后的数据，失败返回错误信息
     */
    public function parseExcel($filePath) {
        try {
            // 检查文件是否存在
            if (!file_exists($filePath)) {
                return "文件不存在";
            }

            // 获取文件扩展名
            $ext = pathinfo($filePath, PATHINFO_EXTENSION);
            
            // 根据扩展名选择解析方法
            if (in_array($ext, ['xls', 'xlsx'])) {
                return $this->parsePhpSpreadsheet($filePath);
            } else {
                return "不支持的文件格式：{$ext}";
            }
        } catch (Exception $e) {
            return "解析失败：" . $e->getMessage();
        }
    }

    /**
     * 使用 PhpSpreadsheet 解析 Excel 文件
     * @param string $filePath 文件路径
     * @return array 解析后的数据
     */
    private function parsePhpSpreadsheet($filePath) {
        require_once 'PhpSpreadsheet/autoload.php';
        
        use PhpOffice\PhpSpreadsheet\IOFactory;
        
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $data = [];

        // 读取标题行（第一行）
        $titleRow = 1;
        $columns = [];
        
        for ($col = 'A'; $col <= 'Z'; $col++) {
            $cellValue = $worksheet->getCell($col . $titleRow)->getValue();
            
            if (empty($cellValue)) {
                break;
            }
            
            $columns[] = $this->normalizeColumnName($cellValue);
        }

        // 读取数据行
        $startRow = $titleRow + 1;
        
        for ($row = $startRow; $row <= $worksheet->getHighestRow(); $row++) {
            $rowData = [];
            $isValid = false;
            
            foreach ($columns as $index => $columnName) {
                $colLetter = chr(ord('A') + $index);
                $cellValue = $worksheet->getCell($colLetter . $row)->getValue();
                
                $rowData[$columnName] = $cellValue;
                
                if (!empty($cellValue)) {
                    $isValid = true;
                }
            }
            
            if ($isValid) {
                $data[] = $rowData;
            }
        }
        
        return $data;
    }

    /**
     * 标准化列名
     * @param string $originalName 原始列名
     * @return string 标准化后的列名
     */
    private function normalizeColumnName($originalName) {
        $originalName = trim(strtolower($originalName));
        
        // 常见列名映射
        $columnMapping = [
            '公司分类' => 'company_category',
            '商品名称' => 'product_name',
            'sku' => 'sku',
            '商品编码' => 'sku',
            '库存数量' => 'quantity',
            '数量' => 'quantity',
            '效期' => 'expiry_date',
            '到期日期' => 'expiry_date'
        ];
        
        foreach ($columnMapping as $key => $value) {
            if (strpos($originalName, $key) !== false) {
                return $value;
            }
        }
        
        return $originalName;
    }

    /**
     * 验证解析后的数据格式
     * @param array $data 解析后的数据
     * @return array 验证结果 [成功:true/false, 错误信息]
     */
    public function validateData($data) {
        $errors = [];
        
        foreach ($data as $index => $row) {
            // 检查是否包含必要字段
            $requiredFields = ['company_category', 'product_name', 'sku'];
            
            foreach ($requiredFields as $field) {
                if (empty($row[$field])) {
                    $errors[] = "第 " . ($index + 2) . " 行：缺少必填字段 \"{$field}\"";
                }
            }
            
            // 验证 SKU 格式
            if (!empty($row['sku']) && !preg_match('/^[A-Z0-9\-_]+$/', $row['sku'])) {
                $errors[] = "第 " . ($index + 2) . " 行：SKU 格式不正确";
            }
            
            // 验证效期格式
            if (!empty($row['expiry_date'])) {
                $dateValue = $row['expiry_date'];
                
                // 尝试解析日期
                $parsedDate = null;
                
                if (is_numeric($dateValue)) {
                    // 数字日期（Excel 序列号）
                    $parsedDate = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($dateValue);
                } else if (is_string($dateValue)) {
                    // 字符串日期
                    $parsedDate = new DateTime($dateValue);
                }
                
                if (!$parsedDate) {
                    $errors[] = "第 " . ($index + 2) . " 行：效期格式不正确";
                }
            }
        }
        
        return [
            'success' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * 将数据添加到待办列表
     * @param array $data 解析后的数据
     * @param int $userId 用户 ID
     * @return bool 是否成功
     */
    public function addToTodoList($data, $userId) {
        try {
            foreach ($data as $row) {
                $sku = $row['sku'] ?? '';
                $productName = $row['product_name'] ?? '';
                $companyCategory = $row['company_category'] ?? '';
                
                // 检查是否已存在
                $stmt = $this->pdo->prepare("
                    SELECT COUNT(*) FROM import_todo 
                    WHERE sku = ? AND company_category_raw = ? AND user_id = ?
                ");
                
                $stmt->execute([$sku, $companyCategory, $userId]);
                $count = $stmt->fetchColumn();
                
                if ($count == 0) {
                    $stmt = $this->pdo->prepare("
                        INSERT INTO import_todo (sku, product_name, company_category_raw, user_id) 
                        VALUES (?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([$sku, $productName, $companyCategory, $userId]);
                }
            }
            
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 获取待办列表
     * @param int $userId 用户 ID
     * @param string $filterCategory 分类筛选
     * @return array 待办数据
     */
    public function getTodoList($userId, $filterCategory = '') {
        try {
            $query = "
                SELECT * FROM import_todo 
                WHERE user_id = ?
            ";
            
            $params = [$userId];
            
            if (!empty($filterCategory)) {
                $query .= " AND company_category_raw LIKE ?";
                $params[] = "%{$filterCategory}%";
            }
            
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * 绑定分类
     * @param array $ids 待办数据 ID 数组
     * @param int $categoryId 分类 ID
     * @param string $checkFrequency 盘点频次
     * @return bool 是否成功
     */
    public function bindCategories($ids, $categoryId, $checkFrequency) {
        try {
            foreach ($ids as $id) {
                // 获取待办数据
                $stmt = $this->pdo->prepare("
                    SELECT * FROM import_todo WHERE id = ?
                ");
                
                $stmt->execute([$id]);
                $todo = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($todo) {
                    // 检查 SKU 是否已存在
                    $stmt = $this->pdo->prepare("
                        SELECT * FROM products WHERE sku = ?
                    ");
                    
                    $stmt->execute([$todo['sku']]);
                    $product = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($product) {
                        // 更新现有产品分类
                        $stmt = $this->pdo->prepare("
                            UPDATE products SET category_id = ?, check_frequency = ? 
                            WHERE sku = ?
                        ");
                        
                        $stmt->execute([$categoryId, $checkFrequency, $todo['sku']]);
                    } else {
                        // 插入新产品
                        $stmt = $this->pdo->prepare("
                            INSERT INTO products (sku, name, category_id, company_category_raw, check_frequency) 
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        
                        $stmt->execute([
                            $todo['sku'], 
                            $todo['product_name'], 
                            $categoryId, 
                            $todo['company_category_raw'],
                            $checkFrequency
                        ]);
                    }
                    
                    // 删除待办记录
                    $stmt = $this->pdo->prepare("
                        DELETE FROM import_todo WHERE id = ?
                    ");
                    
                    $stmt->execute([$id]);
                }
            }
            
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 删除待办数据
     * @param int $id 待办数据 ID
     * @return bool 是否成功
     */
    public function deleteTodo($id) {
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM import_todo WHERE id = ?
            ");
            
            $stmt->execute([$id]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 清空待办列表
     * @param int $userId 用户 ID
     * @return bool 是否成功
     */
    public function clearTodoList($userId) {
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM import_todo WHERE user_id = ?
            ");
            
            $stmt->execute([$userId]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
