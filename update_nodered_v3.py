import urllib.request
import json

url = 'http://localhost:1880/flow/f6f2187d.f17ca8'
z_id = "f6f2187d.f17ca8"

nodes = [
    {
        "id": "http_in_start", "type": "http in", "z": z_id,
        "name": "POST /start-sim (SMT)", "url": "/start-sim", "method": "post",
        "outputs": 1, "x": 150, "y": 80,
        "wires": [["func_init_smt"]]
    },
    {
        "id": "http_in_dip", "type": "http in", "z": z_id,
        "name": "POST /start-dip-sim (DIP)", "url": "/start-dip-sim", "method": "post",
        "outputs": 1, "x": 150, "y": 140,
        "wires": [["func_init_dip"]]
    },
    {
        "id": "func_init_smt", "type": "function", "z": z_id,
        "name": "SMT 초기화",
        "func": """
const payload = msg.payload;
if (!payload.wo_id || !payload.target_qty) { msg.statusCode = 400; return [msg, null]; }
flow.set('sim_mode', 'SMT');
flow.set('sim_running', true);
flow.set('sim_wo_id', payload.wo_id);
flow.set('sim_target_qty', payload.target_qty);
flow.set('sim_next_id', 1);
flow.set('sim_line', []);
msg.payload = { status: 'success' };
msg.statusCode = 200;
return [msg, { payload: 'SMT Started' }];
""",
        "outputs": 2, "x": 350, "y": 80,
        "wires": [["http_res_start"], ["debug_sim"]]
    },
    {
        "id": "func_init_dip", "type": "function", "z": z_id,
        "name": "DIP 초기화",
        "func": """
const payload = msg.payload;
if (!payload.wo_id || !payload.target_qty) { msg.statusCode = 400; return [msg, null]; }
flow.set('sim_mode', 'DIP');
flow.set('sim_running', true);
flow.set('sim_wo_id', payload.wo_id);
flow.set('sim_target_qty', payload.target_qty);
flow.set('sim_next_id', 1);
flow.set('sim_line', []);
msg.payload = { status: 'success' };
msg.statusCode = 200;
return [msg, { payload: 'DIP Started' }];
""",
        "outputs": 2, "x": 350, "y": 140,
        "wires": [["http_res_start"], ["debug_sim"]]
    },
    {
        "id": "http_res_start", "type": "http response", "z": z_id,
        "name": "응답", "statusCode": "", "headers": {}, "x": 550, "y": 100, "wires": []
    },
    {
        "id": "inject_tick", "type": "inject", "z": z_id,
        "name": "3초 타이머", "props": [{"p":"payload"}], "repeat": "3", "crontab": "",
        "once": True, "onceDelay": 0.1, "topic": "", "payload": "", "payloadType": "date",
        "x": 130, "y": 240,
        "wires": [["func_sim_tick"]]
    },
    {
        "id": "func_sim_tick", "type": "function", "z": z_id,
        "name": "파이프라인 진행",
        "func": """
if (!flow.get('sim_running')) return null;

let mode = flow.get('sim_mode');
let processList = mode === 'SMT' ? ["LASER", "SPI", "MOUNTER", "REFLOW"] : ["DIP_AOI", "WAVE"];
let line = flow.get('sim_line') || [];
let nextId = flow.get('sim_next_id') || 1;
let wo_id = flow.get('sim_wo_id');
let target_qty = flow.get('sim_target_qty');

// 1. 기존 기판 이동
for (let i=0; i<line.length; i++) line[i].step++;

// 완료된 기판 배출
line = line.filter(b => b.step < processList.length && b.status !== 'FAIL');

// 2. 신규 기판 투입
if (nextId <= target_qty) {
    line.push({ barcode: wo_id + '-' + String(nextId).padStart(4, '0'), step: 0, status: 'PASS' });
    nextId++;
} else if (line.length === 0) {
    flow.set('sim_running', false);
    return null;
}

// 3. 데이터 생성
let msgs = line.map(b => {
    let proc = processList[b.step];
    let pdata = {};
    let isFail = Math.random() < 0.03; 
    
    if (proc === 'SPI') {
        pdata = { volume: isFail ? 40 : 100 + Math.floor(Math.random()*20), height: isFail ? 80 : 150 };
    } else if (proc === 'REFLOW') {
        pdata = { zone1: 150, zone2: 200, zone3: isFail ? 300 : 250, zone4: 260 };
    } else if (proc === 'MOUNTER') {
        pdata = { pick_rate: isFail ? 92.5 : 99.8, place_force: 1.2 };
    } else if (proc === 'DIP_AOI') {
        pdata = { short_detect: isFail ? 1 : 0 };
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

if (msgs.length === 0) return null;
return [msgs];
""",
        "outputs": 1, "x": 330, "y": 240,
        "wires": [["http_req_sim"]]
    },
    {
        "id": "http_req_sim", "type": "http request", "z": z_id,
        "name": "API 전송", "method": "POST", "ret": "obj", "url": "http://localhost:8080/backend/api/update_process.php",
        "x": 520, "y": 240, "wires": [[]]
    },
    {
        "id": "debug_sim", "type": "debug", "z": z_id,
        "name": "디버그", "active": True, "tosidebar": True, "console": False, "complete": "payload", "x": 550, "y": 140, "wires": []
    }
]

req = urllib.request.Request(url, data=json.dumps(nodes).encode('utf-8'),
                             headers={'Content-Type': 'application/json', 'Node-RED-Deployment-Type': 'nodes'},
                             method='PUT')
try:
    with urllib.request.urlopen(req) as response:
        print("Deployed successfully!")
except Exception as e:
    print("Error:", e)
