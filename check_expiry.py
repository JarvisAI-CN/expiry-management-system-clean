#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
保质期管理系统 - 主动预警脚本
功能: 每日巡检数据库，识别即将过期的批次并发送邮件预警。
"""

import mysql.connector
import json
import sys
import os
from datetime import datetime, timedelta
from typing import List, Dict

# 导入贾维斯的邮件工具
sys.path.append('/home/ubuntu/.openclaw/workspace/scripts')
try:
    from email_tool import OutlookEmail
except ImportError:
    OutlookEmail = None

# 配置 (建议从外部文件读取)
DB_CONFIG = {
    "host": "localhost",
    "user": "root",
    "password": "", # 待填充
    "database": "expiry_system"
}

ALERT_RECIPIENT = "jarvis.openclaw@email.cn" # 默认接收人

def get_expiring_batches(db_config: Dict) -> List[Dict]:
    """从数据库获取即将过期的批次"""
    try:
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor(dictionary=True)
        
        # 核心查询逻辑: 考虑分类规则和提前下架天数
        query = """
        SELECT 
            p.sku, p.name as product_name, p.removal_buffer,
            c.name as category_name, c.type as category_type, c.rule as category_rule,
            b.expiry_date, b.quantity
        FROM batches b
        JOIN products p ON b.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE b.expiry_date >= CURDATE()
        ORDER BY b.expiry_date ASC
        """
        
        cursor.execute(query)
        batches = cursor.fetchall()
        
        results = []
        today = datetime.now().date()
        
        for b in batches:
            rule = json.loads(b['category_rule']) if b['category_rule'] else {"need_buffer": True}
            need_buffer = rule.get('need_buffer', True)
            
            buffer_days = int(b['removal_buffer']) if need_buffer else 0
            removal_date = b['expiry_date'] - timedelta(days=buffer_days)
            days_to_removal = (removal_date - today).days
            
            # 分类预警
            status = ""
            if days_to_removal < 0:
                status = "🔴 立即下架"
            elif days_to_removal <= 3:
                status = "🟠 极度紧急"
            elif days_to_removal <= 7:
                status = "🟡 临期预警"
            elif days_to_removal <= 15:
                status = "🔵 常规提醒"
            else:
                continue # 不在预警范围内
            
            results.append({
                "sku": b['sku'],
                "name": b['product_name'],
                "category": b['category_name'],
                "expiry_date": b['expiry_date'].strftime('%Y-%m-%d'),
                "removal_date": removal_date.strftime('%Y-%m-%d'),
                "quantity": b['quantity'],
                "days_left": days_to_removal,
                "status": status
            })
            
        cursor.close()
        conn.close()
        return results
        
    except mysql.connector.Error as err:
        print(f"❌ 数据库错误: {err}")
        return []

def format_report(alerts: List[Dict]) -> str:
    """格式化预警报告"""
    if not alerts:
        return "✨ 今日无待处理的临期预警。"
    
    html = """
    <div style="font-family: sans-serif; max-width: 800px; border: 1px solid #ddd; padding: 20px; border-radius: 10px;">
        <h2 style="color: #333; border-bottom: 2px solid #667eea; padding-bottom: 10px;">⚡ 保质期系统 - 效期预警报告</h2>
        <p>生成时间: {now}</p>
        <table style="width: 100%; border-collapse: collapse; margin-top: 20px;">
            <thead>
                <tr style="background-color: #f8f9fa;">
                    <th style="border: 1px solid #ddd; padding: 12px; text-align: left;">状态</th>
                    <th style="border: 1px solid #ddd; padding: 12px; text-align: left;">商品</th>
                    <th style="border: 1px solid #ddd; padding: 12px; text-align: left;">数量</th>
                    <th style="border: 1px solid #ddd; padding: 12px; text-align: left;">下架日期</th>
                    <th style="border: 1px solid #ddd; padding: 12px; text-align: left;">到期日期</th>
                </tr>
            </thead>
            <tbody>
    """.format(now=datetime.now().strftime('%Y-%m-%d %H:%M'))
    
    for a in alerts:
        row_style = ""
        if "🔴" in a['status']: row_style = "background-color: #fff5f5; color: #c53030; font-weight: bold;"
        elif "🟠" in a['status']: row_style = "background-color: #fffaf0; color: #c05621;"
        
        html += f"""
                <tr style="{row_style}">
                    <td style="border: 1px solid #ddd; padding: 10px;">{a['status']}</td>
                    <td style="border: 1px solid #ddd; padding: 10px;">{a['name']}<br><small>{a['sku']}</small></td>
                    <td style="border: 1px solid #ddd; padding: 10px;">{a['quantity']}</td>
                    <td style="border: 1px solid #ddd; padding: 10px;">{a['removal_date']}</td>
                    <td style="border: 1px solid #ddd; padding: 10px;">{a['expiry_date']}</td>
                </tr>
        """
        
    html += """
            </tbody>
        </table>
        <p style="margin-top: 20px; font-size: 0.9em; color: #666;">
            注: 下架日期 = 到期日期 - 提前下架天数 (基于分类规则计算)。
        </p>
    </div>
    """
    return html

def run_check():
    """执行检查并发送邮件"""
    print(f"🚀 开始效期巡检... {datetime.now()}")
    
    # 获取预警
    alerts = get_expiring_batches(DB_CONFIG)
    
    if not alerts:
        print("✅ 今日无预警。")
        return
    
    # 格式化报告
    report_content = format_report(alerts)
    
    # 发送邮件
    if OutlookEmail:
        email = OutlookEmail()
        subject = f"⚠️ 保质期系统预警: 发现 {len(alerts)} 个临期/过期批次"
        success = email.send_email(ALERT_RECIPIENT, subject, report_content, html=True)
        if success:
            print(f"✅ 预警邮件已发送至 {ALERT_RECIPIENT}")
        else:
            print("❌ 邮件发送失败。")
    else:
        print("❌ 未能加载邮件工具。内容如下:")
        print(report_content)

if __name__ == "__main__":
    run_check()
