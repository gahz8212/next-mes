import urllib.request
import json

url = 'http://localhost:1881/flows'
z_id = "f6f2187d.f17ca8"

tab = {"id": z_id, "type": "tab", "label": "SMT/DIP Line Sim", "disabled": False, "info": ""}

nodes = [
    tab,
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
msg.payload = { status: 'success', message: 'SMT Simulation Started' };
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
msg.payload = { status: 'success', message: 'DIP Simulation Started' };
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
        "name": "2.5초 타이머", "props": [{"p":"payload"}], "repeat": "2.5", "crontab": "",
        "once": True, "onceDelay": 0.1, "topic": "", "payload": "", "payloadType": "date",
        "x": 130, "y": 240,
        "wires": [["func_sim_tick"]]
    },
    {
        "id": "func_sim_tick", "type": "function", "z": z_id,
        "name": "라인 시뮬레이션 엔진",
        "func": """
const isRunning = flow.get('sim_running') || false;
if (!isRunning) return null;

const mode = flow.get('sim_mode') || 'SMT';
const wo_id = flow.get('sim_wo_id');
const target_qty = flow.get('sim_target_qty') || 20;
let next_id = flow.get('sim_next_id') || 1;
let line = flow.get('sim_line') || [];

let messages = [];

// 1. 공정 이동
let next_line = [];
for (let item of line) {
    if (mode === 'SMT') {
        if (item.stage === 'LASER') {
            item.stage = 'SPI';
            item.process_data = { solder_height_um: (120 + Math.random()*20).toFixed(1), volume_pct: (98 + Math.random()*5).toFixed(1) };
            item.status = Math.random() < 0.05 ? 'FAIL' : 'PASS';
            messages.push({ payload: { ...item } });
            if (item.status === 'PASS') next_line.push(item);
        } else if (item.stage === 'SPI') {
            item.stage = 'MOUNTER';
            item.process_data = { mounted_components: 10, offset_x_um: (Math.random()*5).toFixed(2), offset_y_um: (Math.random()*5).toFixed(2) };
            item.status = 'PASS';
            messages.push({ payload: { ...item } });
            next_line.push(item);
        } else if (item.stage === 'MOUNTER') {
            item.stage = 'REFLOW';
            item.process_data = { peak_temp_c: (245 + Math.random()*5).toFixed(1), time_above_liquidus_sec: 45 };
            item.status = 'PASS';
            messages.push({ payload: { ...item } });
            // 리플로우 완료 시 라인에서 배출
        }
    } else if (mode === 'DIP') {
        if (item.stage === 'DIP_AOI') {
            item.stage = 'WAVE';
            item.process_data = { pot_temp_c: (255 + Math.random()*4).toFixed(1), conveyor_speed_m_min: 1.2 };
            item.status = Math.random() < 0.03 ? 'FAIL' : 'PASS';
            messages.push({ payload: { ...item } });
            // Wave 완료 시 라인에서 배출
        }
    }
}

// 2. 신규 투입
if (next_id <= target_qty) {
    const padded = String(next_id).padStart(4, '0');
    const barcode = `${wo_id}-${padded}`;
    
    if (mode === 'SMT') {
        const newItem = {
            wo_id: wo_id,
            barcode: barcode,
            stage: 'LASER',
            process_data: { laser_power_w: 15.2, mark_time_ms: 120 },
            status: 'PASS'
        };
        messages.push({ payload: { ...newItem } });
        next_line.push(newItem);
        flow.set('sim_next_id', next_id + 1);
    } else if (mode === 'DIP') {
        const newItem = {
            wo_id: wo_id,
            barcode: barcode,
            stage: 'DIP_AOI',
            process_data: { pin_soldering_score: (95 + Math.random()*5).toFixed(1), bridge_detected: false },
            status: 'PASS'
        };
        messages.push({ payload: { ...newItem } });
        next_line.push(newItem);
        flow.set('sim_next_id', next_id + 1);
    }
}

flow.set('sim_line', next_line);

// 모든 수량 투입 및 배출 완료 체크
if (next_id > target_qty && next_line.length === 0) {
    flow.set('sim_running', false);
    messages.push({ payload: { wo_id: wo_id, stage: mode === 'SMT' ? 'SMT_DONE' : 'DIP_DONE', status: 'DONE' } });
}

if (messages.length === 0) return null;
return [messages];
""",
        "outputs": 1, "x": 350, "y": 240,
        "wires": [["http_post_sensor", "debug_sim"]]
    },
    {
        "id": "http_post_sensor", "type": "http request", "z": z_id,
        "name": "PHP record_sensor.php 전송",
        "method": "POST",
        "ret": "obj",
        "url": "http://172.17.0.1:8080/backend/api/record_sensor.php",
        "x": 620, "y": 240,
        "wires": [[]]
    },
    {
        "id": "debug_sim", "type": "debug", "z": z_id,
        "name": "Sim Debug", "active": True, "tosidebar": True, "console": False, "tostatus": False,
        "complete": "payload", "x": 580, "y": 180, "wires": []
    }
]

headers = {'Content-Type': 'application/json'}
req = urllib.request.Request(url, data=json.dumps(nodes).encode('utf-8'), headers=headers, method='POST')
try:
    with urllib.request.urlopen(req) as res:
        print(f"Deploy Response: {res.status}")
except urllib.error.HTTPError as e:
    print(f"Deploy Error: {e.code} - {e.read().decode('utf-8')}")
