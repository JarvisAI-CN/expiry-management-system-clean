#!/usr/bin/env python3
import mysql.connector
import json
import datetime
import subprocess
import os

# 数据库配置 (从 auto_deploy_expiry.py 获取)
DB_CONFIG = {
    'host': 'localhost',
    'user': 'expiry_user',
    'password': 'Expiry@2026System!',
    'database': 'expiry_system'
}

def get_alert_data():
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor(dictionary=True)
        
        # 1. 获取设置中的邮箱和天数
        cursor.execute("SELECT s_value FROM settings WHERE s_key = 'alert_email'")
        email_row = cursor.fetchone()
        alert_email = email_row['s_value'] if email_row else None
        
        if not alert_email:
            print("未配置预警邮箱，退出。")
            return None, None
            
        cursor.execute("SELECT s_value FROM settings WHERE s_key = 'alert_days'")
        days_row = cursor.fetchone()
        alert_days = [int(d.strip()) for d in days_row['s_value'].split(',')] if days_row else [3, 7, 15]
        
        # 2. 查询分类规则
        cursor.execute("SELECT * FROM categories")
        categories = {row['id']: row for row in cursor.fetchall()}
        
        # 3. 查询即将过期的批次
        # 逻辑：(expiry_date - removal_buffer) 落在今天到今天+max(alert_days) 之间
        max_days = max(alert_days)
        
        query = """
            SELECT p.sku, p.name, p.removal_buffer, p.category_id, b.expiry_date, b.quantity
            FROM batches b
            JOIN products p ON b.product_id = p.id
            ORDER BY b.expiry_date ASC
        """
        cursor.execute(query)
        rows = cursor.fetchall()
        
        alerts = []
        today = datetime.date.today()
        
        for row in rows:
            cat = categories.get(row['category_id'], {})
            rule = json.loads(cat.get('rule', '{}'))
            need_buffer = rule.get('need_buffer', True)
            
            buffer = row['removal_buffer'] if need_buffer else 0
            expiry_date = row['expiry_date']
            removal_date = expiry_date - datetime.timedelta(days=buffer)
            
            days_left = (removal_date - today).days
            
            if days_left < 0:
                status = "🔴 已过期/需立即下架"
                alerts.append({'row': row, 'days': days_left, 'status': status})
            elif days_left in alert_days or days_left <= 3: # 始终包含3天内的
                status = "🟡 临期预警"
                alerts.append({'row': row, 'days': days_left, 'status': status})
                
        conn.close()
        return alert_email, alerts
        
    except Exception as e:
        print(f"数据库错误: {e}")
        return None, None

def send_alert_email(to_email, alerts):
    if not alerts:
        print("没有需要预警的商品。")
        return
        
    subject = f"【保质期预警】发现 {len(alerts)} 项临期/过期商品 - {datetime.date.today()}"
    
    body = "<h2>保质期管理系统 - 自动预警报告</h2>"
    body += "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse; width: 100%;'>"
    body += "<tr style='background-color: #f2f2f2;'><th>SKU</th><th>商品名称</th><th>到期日期</th><th>数量</th><th>剩余天数(至下架)</th><th>状态建议</th></tr>"
    
    for alert in alerts:
        r = alert['row']
        color = "red" if alert['days'] < 0 else "orange"
        body += f"<tr><td>{r['sku']}</td><td>{r['name']}</td><td>{r['expiry_date']}</td><td>{r['quantity']}</td>"
        body += f"<td style='color: {color}; font-weight: bold;'>{alert['days']} 天</td><td>{alert['status']}</td></tr>"
    
    body += "</table>"
    body += "<p>请及时登录系统处理。 <a href='http://ceshi.dhmip.cn'>进入系统</a></p>"
    
    # 使用 email_tool.py 发送
    script_path = "/home/ubuntu/.openclaw/workspace/scripts/email_tool.py"
    cmd = [
        "python3", script_path, 
        "send", 
        "--to", to_email, 
        "--subject", subject, 
        "--body", body,
        "--html"
    ]
    
    try:
        subprocess.run(cmd, check=True)
        print(f"预警邮件已发送至 {to_email}")
    except Exception as e:
        print(f"发送邮件失败: {e}")

if __name__ == "__main__":
    email, data = get_alert_data()
    if email and data:
        send_alert_email(email, data)
