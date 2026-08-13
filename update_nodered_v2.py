import urllib.request
import json

req = urllib.request.Request('http://localhost:1880/flows')
with urllib.request.urlopen(req) as response:
    flows = json.loads(response.read().decode())

z_id = next(n.get("z") for n in flows if n.get("type") == "inject")

# Remove all existing nodes in this flow to start clean
flows = [n for n in flows if n.get("z") != z_id or n.get("type") == "tab"]

new_nodes = [
    {
        "id": "http_in_start",
        "type": "http in",
        "z": z_id,
        "name": "POST /start-sim",
        "url": "/start-sim",
        "method": "post",
        "upload": False,
        "swaggerDoc": "",
        "x": 140,
        "y": 80,
        "wires": [["func_init_sim"]]
    },
    {
        "id": "func_init_sim",
        "type": "function",
        "z": z_id,
        "name": "시뮬레이션 초기화",
        "func": """
const payload = msg.payload;
if (!payload.wo_id || !payload.target_qty) {
    msg.payload = { status: 'error', message: 'Missing parameters' };
    msg.statusCode = 400;
    return [msg, null];
}

flow.set('sim_running', true);
flow.set('sim_wo_id', payload.wo_id);
flow.set('sim_target_qty', payload.target_qty);
flow.set('sim_next_id', 1);
flow.set('sim_line', []);

msg.payload = { status: 'success', message: 'Simulation started for ' + payload.wo_id };
msg.statusCode = 200;
return [msg, { payload: 'Started ' + payload.wo_id }];
""",
        "outputs": 2,
        "x": 350,
        "y": 80,
        "wires": [["http_res_start"], ["debug_sim"]]
    },
    {
        "id": "http_res_start",
        "type": "http response",
        "z": z_id,
        "name": "응답",
        "statusCode": "",
        "headers": {},
        "x": 550,
        "y": 60,
        "wires": []
    },
    {
        "id": "inject_tick",
        "type": "inject",
        "z": z_id,
        "name": "3초 타이머",
        "props": [{"p":"payload"}],
        "repeat": "3",
        "crontab": "",
        "once": True,
        "onceDelay": 0.1,
        "topic": "",
        "payload": "",
        "payloadType": "date",
        "x": 130,
        "y": 200,
        "wires": [["func_sim_tick"]]
    },
    {
        "id": "func_sim_tick",
        "type": "function",
        "z": z_id,
        "name": "파이프라인 진행",
        "func": """
if (!flow.get('sim_running')) return null;

let line = flow.get('sim_line') || [];
let nextId = flow.get('sim_next_id') || 1;
let wo_id = flow.get('sim_wo_id');
let target_qty = flow.get('sim_target_qty');

const processList = ["LASER", "SPI", "MOUNTER", "REFLOW", "DIP_AOI", "WAVE"];

// 1. 기존 기판 이동
for (let i=0; i<line.length; i++) {
    line[i].step++;
}

// 완료된 기판 및 불량(FAIL) 기판 배출
line = line.filter(b => b.step < processList.length && b.status !== 'FAIL');

// 2. 신규 기판 투입
if (nextId <= target_qty) {
    line.push({
        barcode: wo_id + '-' + String(nextId).padStart(4, '0'),
        step: 0,
        status: 'PASS'
    });
    nextId++;
} else if (line.length === 0) {
    // 모두 완료됨
    flow.set('sim_running', false);
    return null;
}

// 3. 각 기판별 데이터 생성
let msgs = line.map(b => {
    let proc = processList[b.step];
    let pdata = {};
    let isFail = Math.random() < 0.03; // 3% 불량률
    
    if (proc === 'SPI') {
        pdata = { volume: isFail ? 40 : 100 + Math.floor(Math.random()*20), height: isFail ? 80 : 150 };
    } else if (proc === 'REFLOW') {
        pdata = { zone1: 150, zone2: 200, zone3: isFail ? 300 : 250, zone4: 260 };
    } else if (proc === 'MOUNTER') {
        pdata = { pick_rate: isFail ? 92.5 : 99.8, place_force: 1.2 };
    }
    
    if (isFail) b.status = 'FAIL';
    
    return {
        payload: {
            barcode: b.barcode,
            process_name: proc,
            result_status: isFail ? 'FAIL' : 'PASS',
            process_data: pdata
        }
    };
});

flow.set('sim_line', line);
flow.set('sim_next_id', nextId);

return [msgs];
""",
        "outputs": 1,
        "x": 330,
        "y": 200,
        "wires": [["http_req_sim", "debug_sim"]]
    },
    {
        "id": "http_req_sim",
        "type": "http request",
        "z": z_id,
        "name": "API 전송",
        "method": "POST",
        "ret": "obj",
        "paytoqs": "ignore",
        "url": "http://localhost:8080/backend/api/update_process.php",
        "tls": "",
        "persist": False,
        "proxy": "",
        "insecureHTTPParser": False,
        "authType": "",
        "senderr": False,
        "headers": [],
        "x": 520,
        "y": 200,
        "wires": [[]]
    },
    {
        "id": "debug_sim",
        "type": "debug",
        "z": z_id,
        "name": "디버그",
        "active": True,
        "tosidebar": True,
        "console": False,
        "tostatus": False,
        "complete": "payload",
        "targetType": "msg",
        "statusVal": "",
        "statusType": "auto",
        "x": 510,
        "y": 140,
        "wires": []
    }
]

flows.extend(new_nodes)

req = urllib.request.Request('http://localhost:1880/flows', data=json.dumps(flows).encode(), headers={'Content-Type': 'application/json', 'Node-RED-Deployment-Type': 'full'})
req.get_method = lambda: 'POST'
with urllib.request.urlopen(req) as response:
    print("Deployed successfully!")

