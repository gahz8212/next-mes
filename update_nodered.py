import urllib.request
import json

req = urllib.request.Request('http://localhost:1880/flows')
with urllib.request.urlopen(req) as response:
    flows = json.loads(response.read().decode())

# Find the function node in "플로우 1" (which had the basic logic)
function_node_id = "8c972a5209b58129"
http_node_id = "67a5e3ff9c3ccd72"

for node in flows:
    if node.get("id") == function_node_id:
        node["func"] = """
let line = flow.get('line') || [];
let nextBarcodeId = flow.get('nextBarcodeId') || 1;
const processList = ["LASER", "SPI", "MOUNTER", "REFLOW", "DIP_AOI", "WAVE"];

// 1. 기존 라인에 있는 기판들 한 칸씩 이동
for (let i=0; i<line.length; i++) {
    line[i].step++;
}

// 2. 공정이 끝난 기판(WAVE 완료) 라인에서 배출
line = line.filter(b => b.step < processList.length);

// 3. 새로운 기판 1개 투입 (LASER)
line.push({
    barcode: "WO-SIM-" + String(nextBarcodeId).padStart(4, '0'),
    step: 0
});
nextBarcodeId++;

// 4. 상태 저장
flow.set('line', line);
flow.set('nextBarcodeId', nextBarcodeId);

// 5. 현재 라인에 있는 모든 기판의 상태를 각각 메시지로 생성하여 HTTP 노드로 전송
let msgs = line.map(b => {
    return {
        payload: {
            barcode: b.barcode,
            process_name: processList[b.step],
            result_status: "PASS"
        }
    };
});

// 다중 메시지를 배열로 반환하면 차례대로 다음 노드로 전달됨
return [msgs];
"""
        # Ensure it connects to HTTP node and a new debug node
        node["wires"] = [[http_node_id, "sim_debug"]]
    
    if node.get("id") == http_node_id:
        # Just in case, ensure it's pointing to localhost:8080 and has no wires if it shouldn't
        node["url"] = "http://localhost:8080/backend/api/update_process.php"
        node["wires"] = [[]]

# Check if debug node exists, if not add it
has_debug = any(n.get("id") == "sim_debug" for n in flows)
if not has_debug:
    # get the tab id of the function node
    z_id = next(n.get("z") for n in flows if n.get("id") == function_node_id)
    flows.append({
        "id": "sim_debug",
        "type": "debug",
        "z": z_id,
        "name": "시뮬레이션 디버그",
        "active": True,
        "tosidebar": True,
        "console": False,
        "tostatus": False,
        "complete": "payload",
        "targetType": "msg",
        "statusVal": "",
        "statusType": "auto",
        "x": 650,
        "y": 200,
        "wires": []
    })

req = urllib.request.Request('http://localhost:1880/flows', data=json.dumps(flows).encode(), headers={'Content-Type': 'application/json', 'Node-RED-Deployment-Type': 'full'})
req.get_method = lambda: 'POST'
with urllib.request.urlopen(req) as response:
    print(response.read().decode())

