import requests
import time
import random
import json

# PHP 서버가 로컬(8080 포트)에서 실행 중이라고 가정한 URL
API_URL = "http://localhost:8080/backend/api/update_process.php"

def simulate_edge_device():
    print("🚀 SMT Line #1 - Edge Device Simulator Started")
    print(f"📡 Target API: {API_URL}")
    print("--------------------------------------------------")

    while True:
        # 가상의 바코드 스캔 발생
        random_seq = str(random.randint(0, 999)).zfill(4)
        barcode = f"WO-SMT-{random_seq}"
        
        # 85% 확률로 PASS, 15% 확률로 FAIL
        is_pass = random.random() > 0.15
        result_status = "PASS" if is_pass else "FAIL"

        payload = {
            "barcode": barcode,
            "process_name": "SMT_TOP",
            "result_status": result_status
        }

        try:
            # PHP 백엔드로 데이터 전송
            response = requests.post(
                API_URL, 
                json=payload, 
                headers={'Content-Type': 'application/json'},
                timeout=3
            )
            
            if response.status_code == 200:
                print(f"[✅ SUCCESS] Scanned: {barcode} | Result: {result_status} | API Response: {response.text}")
            else:
                print(f"[❌ ERROR] HTTP {response.status_code} - {response.text}")

        except requests.exceptions.RequestException as e:
            print(f"[🚨 NETWORK ERROR] 백엔드 API 서버({API_URL})에 연결할 수 없습니다. PHP 서버가 켜져 있는지 확인하세요.")
        
        # 3초마다 1개씩 생산(스캔)한다고 가정
        time.sleep(3)

if __name__ == "__main__":
    simulate_edge_device()
