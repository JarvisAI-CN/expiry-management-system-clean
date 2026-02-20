#!/usr/bin/env php
<?php
/**
 * 简单的Excel XLSX解析器（不依赖PhpSpreadsheet）
 * 原理：xlsx = zip+xml，解压后读取XML数据
 */

function parseXlsxFile($filepath) {
    // 1. 解压xlsx文件到临时目录
    $tempDir = sys_get_temp_dir() . '/xlsx_parse_' . time();
    $zip = new ZipArchive;

    if ($zip->open($filepath) !== TRUE) {
        throw new Exception("无法打开Excel文件");
    }

    $zip->extractTo($tempDir);
    $zip->close();

    // 2. 读取共享字符串表
    $sharedStrings = [];
    $sharedStringsFile = $tempDir . '/xl/sharedStrings.xml';
    if (file_exists($sharedStringsFile)) {
        $xml = simplexml_load_file($sharedStringsFile);
        foreach ($xml->si as $si) {
            $sharedStrings[] = (string)$si->t;
        }
    }

    // 3. 读取工作表数据
    $worksheetFile = $tempDir . '/xl/worksheets/sheet1.xml';
    if (!file_exists($worksheetFile)) {
        // 清理临时目录
        system("rm -rf " . escapeshellarg($tempDir));
        throw new Exception("工作表文件不存在");
    }

    $xml = simplexml_load_file($worksheetFile);
    $rows = [];

    // 命名空间
    $ns = $xml->getNamespaces(true);
    $xml->registerXPathNamespace('default', $ns['']);

    // 读取所有行
    foreach ($xml->sheetData->row as $row) {
        $rowData = [];
        foreach ($row->c as $cell) {
            $cellValue = '';

            // 获取单元格类型
            $cellType = (string)$cell['t'];

            // 获取值
            $v = (string)$cell->v;

            if ($cellType === 's') {
                // 共享字符串
                $cellValue = $sharedStrings[intval($v)] ?? '';
            } else {
                // 直接值
                $cellValue = $v;
            }

            $rowData[] = $cellValue;
        }

        if (!empty($rowData)) {
            $rows[] = $rowData;
        }
    }

    // 4. 清理临时目录
    system("rm -rf " . escapeshellarg($tempDir));

    return $rows;
}
