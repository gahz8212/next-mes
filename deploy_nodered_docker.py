import urllib.request
import json
import sys

# Docker Node-RED endpoint
NODERED_URL = "http://localhost:1881/flows"
z_id = "f6f2187d.f17ca8"

tab = {
    "id": z_id,
    "type": "tab",
    "label": "SMT/DIP MES Simulation & PdM Telemetry",
    "disabled": False,
    "info": "SMT 및 DIP 설비 공정 시뮬레이션 및 예지보전(PdM) 실시간 텔레메트리 파이프라인"
}

nodes = [
    tab,
    # 1. SMT 시작 HTTP IN
    {
        "id": "http_in_start", "type": "http in", "z": z_id,
        "name": "POST /start-sim (SMT)", "url": "/start-sim", "method": "post",
        "outputs": 1, "x": 160, "y": 80,
        "wires": [["func_init_smt"]]
    },
    # 2. DIP 시작 HTTP IN
    {
        "id": "http_in_dip", "type": "http in", "z": z_id,
        "name": "POST /start-dip-sim (DIP)", "url": "/start-dip-sim", "method": "post",
        "outputs": 1, "x": 160, "y": 140,
        "wires": [["func_init_dip"]]
    },
    # 3. 중단 HTTP IN
    {
        "id": "http_in_stop", "type": "http in", "z": z_id,
        "name": "POST /stop-sim", "url": "/stop-sim", "method": "post",
        "outputs": 1, "x": 160, "y": 200,
        "wires": [["func_stop_sim"]]
    },
    # 4. SMT 초기화 Function
    {
        "id": "func_init_smt", "type": "function", "z": z_id,
        "name": "SMT 초기화",
        "func": """
const payload = msg.payload || {};
if (!payload.wo_id || !payload.target_qty) {
    msg.statusCode = 400;
    msg.payload = { status: 'error', message: 'wo_id and target_qty required' };
    return [msg, null];
}
flow.set('sim_mode', 'SMT');
flow.set('sim_running', true);
flow.set('sim_wo_id', payload.wo_id);
flow.set('sim_target_qty', parseInt(payload.target_qty));
flow.set('sim_next_id', 1);
flow.set('sim_slots', {});
msg.payload = { status: 'success', message: 'SMT Simulation Started', wo_id: payload.wo_id };
msg.statusCode = 200;
return [msg, { payload: `[SMT STARTED] WO: ${payload.wo_id}, QTY: ${payload.target_qty}` }];
""",
        "outputs": 2, "x": 380, "y": 80,
        "wires": [["http_res_start"], ["debug_sim"]]
    },
    # 5. DIP 초기화 Function
    {
        "id": "func_init_dip", "type": "function", "z": z_id,
        "name": "DIP 초기화",
        "func": """
const payload = msg.payload || {};
if (!payload.wo_id || !payload.target_qty) {
    msg.statusCode = 400;
    msg.payload = { status: 'error', message: 'wo_id and target_qty required' };
    return [msg, null];
}
flow.set('sim_mode', 'DIP');
flow.set('sim_running', true);
flow.set('sim_wo_id', payload.wo_id);
flow.set('sim_target_qty', parseInt(payload.target_qty));
flow.set('sim_next_id', 1);
flow.set('sim_slots', {});
msg.payload = { status: 'success', message: 'DIP Simulation Started', wo_id: payload.wo_id };
msg.statusCode = 200;
return [msg, { payload: `[DIP STARTED] WO: ${payload.wo_id}, QTY: ${payload.target_qty}` }];
""",
        "outputs": 2, "x": 380, "y": 140,
        "wires": [["http_res_start"], ["debug_sim"]]
    },
    # 6. 중단 Function
    {
        "id": "func_stop_sim", "type": "function", "z": z_id,
        "name": "시뮬레이션 중단",
        "func": """
const wo_id = flow.get('sim_wo_id') || 'Unknown';
flow.set('sim_running', false);
flow.set('sim_slots', {});
msg.payload = { status: 'success', message: 'Simulation Stopped', wo_id: wo_id };
msg.statusCode = 200;
return [msg, { payload: `[STOPPED] WO: ${wo_id}` }];
""",
        "outputs": 2, "x": 380, "y": 200,
        "wires": [["http_res_start"], ["debug_sim"]]
    },
    # 7. HTTP Response 노드
    {
        "id": "http_res_start", "type": "http response", "z": z_id,
        "name": "HTTP 응답", "statusCode": "", "headers": {}, "x": 600, "y": 120, "wires": []
    },
    # 8. 3초 주기 타이머 (공정 진행)
    {
        "id": "inject_tick", "type": "inject", "z": z_id,
        "name": "3초 타이머", "props": [{"p": "payload"}], "repeat": "3", "crontab": "",
        "once": True, "onceDelay": 0.1, "topic": "", "payload": "", "payloadType": "date",
        "x": 150, "y": 300,
        "wires": [["func_sim_tick"]]
    },
    # 9. 파이프라인 엔진 & 고도화 예지보전(PdM) 정밀 텔레메트리 생성 Function
    {
        "id": "func_sim_tick", "type": "function", "z": z_id,
        "name": "파이프라인 진행 및 고도화 PdM 텔레메트리 생성",
        "func": """
if (!flow.get('sim_running')) return null;

let mode = flow.get('sim_mode') || 'SMT';
let processList = mode === 'SMT' ? ["LASER", "SPI", "MOUNTER", "REFLOW"] : ["DIP_AOI", "WAVE"];
let target_qty = flow.get('sim_target_qty') || 20;
let wo_id = flow.get('sim_wo_id');
let nextId = flow.get('sim_next_id') || 1;
let slots = flow.get('sim_slots') || {};

// 1. 컨베이어 물리적 슬롯 전진 (Pipeline Shift)
let newSlots = {};
let msgs = [];

for (let i = processList.length - 1; i >= 0; i--) {
    let proc = processList[i];
    if (i === 0) {
        // 첫 공정 (LASER 또는 DIP_AOI): 새 기판 진입
        if (nextId <= target_qty) {
            let bcode = wo_id + '-' + String(nextId).padStart(4, '0');
            newSlots[proc] = { id: nextId, barcode: bcode, status: 'PASS' };
            nextId++;
        } else {
            newSlots[proc] = null; // 기판 공급 완료 (대기 전환)
        }
    } else {
        // 이전 설비에서 가공 완료된 기판을 인계받음 (불량 기판도 컨베이어를 타고 마지막 공정까지 계속 이동!)
        let prevProc = processList[i - 1];
        let prevItem = slots[prevProc] || null;
        if (prevItem) {
            newSlots[proc] = prevItem;
        } else {
            newSlots[proc] = null;
        }
    }
}

flow.set('sim_slots', newSlots);
flow.set('sim_next_id', nextId);

// 전체 라인에 잔여 기판이 없고 목표 수량 투입이 끝났는지 확인
let activeCount = Object.values(newSlots).filter(v => v !== null).length;
if (activeCount === 0 && nextId > target_qty) {
    flow.set('sim_running', false);
    flow.set('sim_slots', {});
    // 모든 설비 즉시 대기(IDLE) 처리 및 공정 최종 완료 플래그 전송
    let idleEvents = processList.map(proc => ({
        barcode: '-',
        process_name: proc,
        result_status: 'IDLE',
        process_data: { pdm_status: 'NORMAL' }
    }));
    return [{ payload: { wo_id: wo_id, events: idleEvents, is_complete: true, sim_mode: mode } }];
}

// 2. 각 설비 슬롯별 텔레메트리 및 상태 메시지 생성
let eventsList = [];
processList.forEach(proc => {
    let item = newSlots[proc];
    if (item) {
        let isAlreadyFailed = (item.status === 'FAIL');
        let isNewFail = (!isAlreadyFailed && Math.random() < 0.04);
        let isFail = isAlreadyFailed || isNewFail;

        if (isFail) {
            item.status = 'FAIL';
            if (!item.failed_cell) {
                item.failed_cell = Math.floor(Math.random() * 4) + 1;
            }
            if (!item.failed_process) {
                item.failed_process = proc;
            }
        }

        let pdata = {};

        if (proc === 'LASER') {
            let pwr = +(15.2 + (Math.random() * 0.6 - 0.3) - (isFail ? 0.8 : 0)).toFixed(2);
            let temp = +(31.5 + (Math.random() * 3 - 1.5)).toFixed(1);
            let lens = +(97.5 - (Math.random() * 4)).toFixed(1);
            let fume = +(-2.4 + (Math.random() * 0.3 - 0.15)).toFixed(2);
            let galvano = +(32.4 + (Math.random() * 2 - 1)).toFixed(1);
            let health = isFail ? 82 : +(96 + Math.random() * 4).toFixed(0);
            
            pdata = {
                metric_name: "레이저 출력",
                metric_val: pwr,
                metric_unit: "W",
                pdm_health: health,
                pdm_status: isFail ? "WARNING" : (pwr < 14.7 ? "CAUTION" : "NORMAL"),
                laser_power_w: pwr,
                tube_temp_c: temp,
                lens_cleanliness_pct: lens,
                fume_pressure_kpa: fume,
                galvano_temp_c: galvano,
                mark_time_ms: 120 + Math.floor(Math.random() * 10 - 5),
                rul_filter_days: 14,
                filter_life_pct: 82,
                recommendation: isFail ? `PCB #${item.id} 바코드 각인 불량 감지 ➔ 광학계 미세 캘리브레이션 권장` : "집진기 필터 차압 및 갈바노 미러 온도 양호"
            };
        } else if (proc === 'SPI') {
            let vol = +(102.5 + (Math.random() * 12 - 6) + (isFail ? -25 : 0)).toFixed(1);
            let hgt = +(142.0 + (Math.random() * 16 - 8)).toFixed(1);
            let visc = +(202 + Math.floor(Math.random() * 10 - 5)).toFixed(0);
            let offX = +(8.2 + (Math.random() * 6 - 3)).toFixed(1);
            let offY = +(-4.5 + (Math.random() * 5 - 2.5)).toFixed(1);
            let maskWash = 84 + (item.id % 15);
            let health = isFail ? 79 : +(95 + Math.random() * 5).toFixed(0);
            
            pdata = {
                metric_name: "납 도포 체적율",
                metric_val: vol,
                metric_unit: "%",
                pdm_health: health,
                pdm_status: (vol < 85 || vol > 115) ? "WARNING" : "NORMAL",
                volume_pct: vol,
                solder_height_um: hgt,
                paste_viscosity_pa_s: visc,
                offset_x_um: offX,
                offset_y_um: offY,
                mask_wash_count: maskWash,
                blade_pressure_kg: +(3.0 + (Math.random() * 0.2 - 0.1)).toFixed(2),
                recommendation: isFail ? `PCB #${item.id} (셀 #${item.failed_cell || 2}) 납 도포 체적 불량 감지 ➔ 스퀴지 클리닝` : `X/Y 인쇄 오프셋 편차 안정 (마스크 세척 잔여: ${100 - maskWash}타)`
            };
        } else if (proc === 'MOUNTER') {
            let vac = +(-84.2 + (Math.random() * 4 - 2) + (isFail ? 12.5 : 0)).toFixed(1);
            let vib = +(0.09 + (Math.random() * 0.04 - 0.02) + (isFail ? 0.08 : 0)).toFixed(3);
            let pickRate = isFail ? 93.8 : +(99.6 + Math.random() * 0.4).toFixed(1);
            let motorTemp = +(38.5 + (Math.random() * 3 - 1.5)).toFixed(1);
            let strikes = 168400 + (item.id * 40);
            let health = isFail ? 76 : +(97 + Math.random() * 3).toFixed(0);

            pdata = {
                metric_name: "노즐 진공압",
                metric_val: vac,
                metric_unit: "kPa",
                pdm_health: health,
                pdm_status: vac > -77.0 ? "WARNING" : (vac > -80.0 ? "CAUTION" : "NORMAL"),
                vacuum_kpa: vac,
                head_vibration_g: vib,
                pick_rate: pickRate,
                motor_temp_c: motorTemp,
                nozzle_strike_count: strikes,
                nozzle_rul_days: 4,
                feeder_tension_n: +(4.2 + (Math.random() * 0.4 - 0.2)).toFixed(2),
                place_force_n: +(1.22 + (Math.random() * 0.1 - 0.05)).toFixed(2),
                recommendation: vac > -77.0 ? "헤드 #3 노즐 팁 진공압 누설 징후 ➔ 팁 교체 및 오링 점검" : "X/Y 리니어 모터 발열 및 노즐 흡착 수명 양호 (D-4)"
            };
        } else if (proc === 'REFLOW') {
            let peak = +(246.5 + (Math.random() * 3 - 1.5) + (isFail ? 6.5 : 0)).toFixed(1);
            let z1 = +(150 + (Math.random() * 2 - 1)).toFixed(1);
            let z2 = +(201 + (Math.random() * 2 - 1)).toFixed(1);
            let z3 = +(248 + (Math.random() * 3 - 1.5)).toFixed(1);
            let z4 = +(261 + (Math.random() * 2 - 1)).toFixed(1);
            let o2 = +(360 + Math.floor(Math.random() * 40 - 20)).toFixed(0);
            let tal = +(52.5 + (Math.random() * 4 - 2)).toFixed(1);
            let ramp = +(1.85 + (Math.random() * 0.15 - 0.07)).toFixed(2);
            let cooling = +(-2.40 + (Math.random() * 0.2 - 0.1)).toFixed(2);
            let trap = +(42.0 + (Math.random() * 2 - 1)).toFixed(1);
            let health = isFail ? 81 : +(98 + Math.random() * 2).toFixed(0);

            pdata = {
                metric_name: "피크 프로파일 온도",
                metric_val: peak,
                metric_unit: "℃",
                pdm_health: health,
                pdm_status: (peak > 251.0 || peak < 242.0) ? "WARNING" : "NORMAL",
                peak_temp_c: peak,
                zone1_temp: z1,
                zone2_temp: z2,
                zone3_temp: z3,
                zone4_temp: z4,
                oxygen_ppm: o2,
                tal_sec: tal,
                ramp_rate_c_s: ramp,
                cooling_rate_c_s: cooling,
                flux_trap_level_pct: trap,
                conveyor_vibration_g: +(0.06 + Math.random() * 0.02).toFixed(3),
                recommendation: peak > 251.0 ? "Zone 3 히터 과열 편차 감지 ➔ SSR 릴레이 및 열풍 팬 점검" : "액상선 체류시간(TAL 52s) 및 승온/냉각 구배 최적"
            };
        } else if (proc === 'DIP_AOI') {
            let score = +(97.2 + (Math.random() * 2.5 - 1.2) - (isFail ? 12 : 0)).toFixed(1);
            let bridge = +(isFail ? 8.4 : 1.5 + Math.random() * 1.5).toFixed(1);
            let tilt = +(0.4 + (Math.random() * 0.3)).toFixed(2);
            let lift = +(18 + Math.floor(Math.random() * 10 - 5)).toFixed(0);
            let health = isFail ? 83 : +(97 + Math.random() * 3).toFixed(0);

            pdata = {
                metric_name: "납땜 판정 점수",
                metric_val: score,
                metric_unit: "pts",
                pdm_health: health,
                pdm_status: score < 90.0 ? "WARNING" : "NORMAL",
                pin_soldering_score: score,
                bridge_risk_pct: bridge,
                comp_tilt_deg: tilt,
                lift_height_um: lift,
                camera_fps: 59.8,
                recommendation: score < 90.0 ? "리드 핀 솔더링 브릿지 형성 위험율 상승 ➔ 플럭스 분사량 재조정" : "부품 들뜸(Lift) 및 기울어짐 허용치 이내 안정"
            };
        } else if (proc === 'WAVE') {
            let pot = +(255.0 + (Math.random() * 3 - 1.5) + (isFail ? -7 : 0)).toFixed(1);
            let speed = +(1.20 + (Math.random() * 0.04 - 0.02)).toFixed(2);
            let waveHgt = +(9.1 + (Math.random() * 0.4 - 0.2)).toFixed(1);
            let preheater = +(132.5 + (Math.random() * 4 - 2)).toFixed(1);
            let fluxAmt = +(16.2 + (Math.random() * 0.8 - 0.4)).toFixed(1);
            let dross = +(28.5 + (Math.random() * 3 - 1.5)).toFixed(1);
            let health = isFail ? 80 : +(96 + Math.random() * 4).toFixed(0);

            pdata = {
                metric_name: "솔더팟 용탕 온도",
                metric_val: pot,
                metric_unit: "℃",
                pdm_health: health,
                pdm_status: (pot < 250.0 || pot > 260.0) ? "WARNING" : "NORMAL",
                pot_temp_c: pot,
                conveyor_speed_m_min: speed,
                wave_height_mm: waveHgt,
                preheater_temp_c: preheater,
                flux_amount_ml_min: fluxAmt,
                dross_level_pct: dross,
                pump_rpm: 1255,
                recommendation: pot < 250.0 ? "솔더팟 히터 가열 지연 ➔ 하부 히터 저항 측정 및 드로스 제거 권장" : `예열 온도(${preheater}℃) 및 솔더 드로스 수위(${dross}%) 안정`
            };
        }

        pdata.pcb_no = item.id;
        pdata.failed_cell = item.failed_cell || (isFail ? 2 : 0);
        pdata.failed_process = item.failed_process || (isFail ? proc : null);
        pdata.is_inherited_fail = isAlreadyFailed;

        if (isAlreadyFailed) {
            if (proc === 'MOUNTER') {
                pdata.recommendation = `[Bad Mark 스킵] PCB #${item.id}번 이전 ${item.failed_process} 불량(셀 #${item.failed_cell}) ➔ 부품 실장 스킵`;
            } else if (proc === 'REFLOW') {
                pdata.recommendation = `[불량품 배출 이송] PCB #${item.id}번 불량 기판 통과 ➔ NG 언로더 버퍼로 이송`;
            } else if (proc === 'WAVE') {
                pdata.recommendation = `[불량품 배출 이송] PCB #${item.id}번 불량 기판 통과 ➔ 최종 NG 버퍼로 이송`;
            }
        }

        eventsList.push({
            barcode: item.barcode,
            process_name: proc,
            result_status: isFail ? 'FAIL' : 'PASS',
            process_data: pdata
        });
    } else {
        // 현재 이 설비 슬롯은 비어있음 (대기)
        eventsList.push({
            barcode: '-',
            process_name: proc,
            result_status: 'IDLE',
            process_data: { pdm_status: 'NORMAL' }
        });
    }
});

return [{ payload: { wo_id: wo_id, events: eventsList } }];
""",
        "outputs": 1, "x": 380, "y": 300,
        "wires": [["http_req_sim", "debug_sim"]]
    },
    # 10. PHP update_process.php 로 전송 (host.docker.internal)
    {
        "id": "http_req_sim", "type": "http request", "z": z_id,
        "name": "PHP update_process.php 전송",
        "method": "POST",
        "ret": "obj",
        "url": "http://host.docker.internal:8080/backend/api/update_process.php",
        "x": 680, "y": 300,
        "wires": [[]]
    },
    # 11. 디버그 노드
    {
        "id": "debug_sim", "type": "debug", "z": z_id,
        "name": "디버그 로그",
        "active": True,
        "tosidebar": True,
        "console": False,
        "complete": "payload",
        "x": 650, "y": 200,
        "wires": []
    }
]

def deploy():
    data = json.dumps(nodes).encode('utf-8')
    req = urllib.request.Request(
        NODERED_URL,
        data=data,
        headers={'Content-Type': 'application/json'},
        method='POST'
    )
    try:
        with urllib.request.urlopen(req) as res:
            print(f"Deploy Successful! HTTP Status: {res.status}")
    except urllib.error.HTTPError as e:
        print(f"Deploy HTTPError: {e.code} - {e.read().decode('utf-8')}")
        sys.exit(1)
    except Exception as e:
        print(f"Deploy Error: {e}")
        sys.exit(1)

if __name__ == '__main__':
    deploy()
