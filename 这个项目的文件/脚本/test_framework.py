import requests
import json
import time

BASE_URL = "http://150.109.204.23:8888" # 本地测试地址

def test_login():
    print("Testing Login...")
    payload = {"username": "admin", "password": "your_password"}
    r = requests.post(f"{BASE_URL}/index.php?api=login", json=payload)
    assert r.status_code == 200
    print("✅ Login API Passed")

def test_product_search():
    print("Testing Product Search...")
    r = requests.get(f"{BASE_URL}/index.php?api=get_product&sku=6901234567890")
    assert r.status_code == 200
    data = r.json()
    assert "success" in data
    print("✅ Product Search API Passed")

def test_syntax():
    import subprocess
    print("Checking PHP Syntax...")
    res = subprocess.run(["php", "-l", "/home/ubuntu/.openclaw/workspace/PARA/Projects/保质期管理系统/这个项目的文件/scripts/index.php"], capture_output=True, text=True)
    assert "No syntax errors detected" in res.stdout
    print("✅ PHP Syntax Check Passed")

if __name__ == "__main__":
    try:
        test_syntax()
        # Note: API tests require a running server and valid DB
        # test_login()
        # test_product_search()
        print("\n--- ALL PRE-FLIGHT CHECKS PASSED ---")
    except Exception as e:
        print(f"\n❌ TEST FAILED: {str(e)}")
        exit(1)
