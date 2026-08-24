// frontend/js/dashboard.js - SMT / DIP 라인 실시간 관제 & 예지보전(PdM) 스파크라인 및 RUL 엔진
let currentTarget = 0;
let lastHistoryId = 0;
let isPollingActive = false;
const machineResetTimers = {};

// ── 10대 설비 텔레메트리 스키마 (상단 듀얼 원그래프 + 하단 4대 물리량 막대그래프) ──
const MACHINE_TELEMETRY_SCHEMAS = {
    LASER: {
        defaultHealth: 98,
        defaultCycleVal: 82,
        defaultCycleText: 'D-14',
        defaultCycleSub: '필터수명',
        bars: [
            { key: 'laser_power_w', label: '출력', unit: 'W', base: 15.20, min: 10, max: 20, decimals: 1, color: '#10b981' },
            { key: 'tube_temp_c', label: '온도', unit: '℃', base: 31.5, min: 20, max: 45, decimals: 1, color: '#38bdf8' },
            { key: 'fume_pressure_kpa', label: '차압', unit: 'kPa', base: -2.3, min: -3.5, max: -1.0, decimals: 1, color: '#a78bfa' },
            { key: 'lens_cleanliness_pct', label: '렌즈', unit: '%', base: 95, min: 70, max: 100, decimals: 0, color: '#38bdf8' }
        ]
    },
    SPI: {
        defaultHealth: 97,
        defaultCycleVal: 88,
        defaultCycleText: '12타',
        defaultCycleSub: '세척주기',
        bars: [
            { key: 'volume_pct', label: '체적', unit: '%', base: 100.2, min: 70, max: 130, decimals: 0, color: '#10b981' },
            { key: 'offset_x_um', label: '오프셋', unit: 'μm', base: 6.8, min: -15, max: 15, decimals: 1, color: '#38bdf8' },
            { key: 'paste_viscosity_pa_s', label: '점도', unit: 'Pa·s', base: 202, min: 160, max: 240, decimals: 0, color: '#a78bfa' },
            { key: 'solder_height_um', label: '높이', unit: 'μm', base: 143.5, min: 110, max: 170, decimals: 0, color: '#38bdf8' }
        ]
    },
    MOUNTER_1: {
        defaultHealth: 99,
        defaultCycleVal: 85,
        defaultCycleText: 'D-4',
        defaultCycleSub: '노즐수명',
        bars: [
            { key: 'vacuum_kpa', label: '진공', unit: 'kPa', base: -84.5, min: -100, max: -60, decimals: 1, color: '#10b981' },
            { key: 'head_vibration_g', label: '진동', unit: 'G', base: 0.088, min: 0, max: 0.20, decimals: 3, color: '#38bdf8' },
            { key: 'motor_temp_c', label: '발열', unit: '℃', base: 37.8, min: 25, max: 55, decimals: 1, color: '#a78bfa' },
            { key: 'feeder_tension_n', label: '장력', unit: 'N', base: 4.2, min: 2.5, max: 6.0, decimals: 1, color: '#38bdf8' }
        ]
    },
    MOUNTER_2: {
        defaultHealth: 98,
        defaultCycleVal: 78,
        defaultCycleText: 'D-7',
        defaultCycleSub: '비전보정',
        bars: [
            { key: 'align_theta_deg', label: '각도', unit: '°', base: 0.12, min: -1.0, max: 1.0, decimals: 2, color: '#10b981' },
            { key: 'force_n', label: '가압력', unit: 'N', base: 1.85, min: 0.5, max: 4.0, decimals: 2, color: '#38bdf8' },
            { key: 'tray_feeder_rem', label: '트레이', unit: '%', base: 86, min: 10, max: 100, decimals: 0, color: '#a78bfa' },
            { key: 'vision_match_pct', label: '비전', unit: '%', base: 99.4, min: 80, max: 100, decimals: 1, color: '#38bdf8' }
        ]
    },
    REFLOW: {
        defaultHealth: 98,
        defaultCycleVal: 58,
        defaultCycleText: 'D-8',
        defaultCycleSub: '트랩정비',
        bars: [
            { key: 'peak_temp_c', label: '피크', unit: '℃', base: 245.5, min: 220, max: 270, decimals: 1, color: '#10b981' },
            { key: 'oxygen_ppm', label: '산소', unit: 'ppm', base: 375, min: 100, max: 700, decimals: 0, color: '#38bdf8' },
            { key: 'ramp_rate_c_s', label: '승온', unit: '℃/s', base: 1.85, min: 1.0, max: 3.0, decimals: 2, color: '#a78bfa' },
            { key: 'tal_sec', label: '체류', unit: 's', base: 52.0, min: 35, max: 70, decimals: 1, color: '#38bdf8' }
        ]
    },
    DIP_AOI: {
        defaultHealth: 99,
        defaultCycleVal: 90,
        defaultCycleText: 'D-15',
        defaultCycleSub: '광학보정',
        bars: [
            { key: 'pin_soldering_score', altKey: 'metric_val', label: '점수', unit: '점', base: 99.2, min: 70, max: 100, decimals: 1, color: '#10b981' },
            { key: 'bridge_risk_pct', label: '브릿지', unit: '%', base: 1.2, min: 0, max: 8.0, decimals: 1, color: '#38bdf8' },
            { key: 'lift_height_um', label: '들뜸', unit: 'μm', base: 18.0, min: 0, max: 50, decimals: 0, color: '#a78bfa' },
            { key: 'comp_tilt_deg', label: '경사', unit: '°', base: 0.4, min: 0, max: 2.5, decimals: 1, color: '#38bdf8' }
        ]
    },
    WAVE: {
        defaultHealth: 97,
        defaultCycleVal: 72,
        defaultCycleText: 'D-5',
        defaultCycleSub: '드로스정비',
        bars: [
            { key: 'pot_temp_c', label: '용탕', unit: '℃', base: 250.2, min: 230, max: 270, decimals: 1, color: '#10b981' },
            { key: 'wave_height_mm', label: '파고', unit: 'mm', base: 9.15, min: 6.5, max: 12.0, decimals: 2, color: '#38bdf8' },
            { key: 'preheater_temp_c', label: '예열', unit: '℃', base: 132.5, min: 100, max: 160, decimals: 1, color: '#a78bfa' },
            { key: 'dross_level_pct', label: '드로스', unit: '%', base: 28.5, min: 0, max: 60, decimals: 0, color: '#38bdf8' }
        ]
    },
    ICT: {
        defaultHealth: 99,
        defaultCycleVal: 92,
        defaultCycleText: 'D-20',
        defaultCycleSub: '핀베드교체',
        bars: [
            { key: 'contact_res_ohm', label: '접촉저항', unit: 'mΩ', base: 45.2, min: 10, max: 120, decimals: 1, color: '#10b981' },
            { key: 'res_accuracy_pct', label: '저항정밀', unit: '%', base: 99.8, min: 90, max: 100, decimals: 1, color: '#38bdf8' },
            { key: 'leakage_curr_ua', label: '누설전류', unit: 'μA', base: 0.45, min: 0, max: 3.0, decimals: 2, color: '#a78bfa' },
            { key: 'pin_wear_pct', label: '핀마모', unit: '%', base: 12, min: 0, max: 50, decimals: 0, color: '#38bdf8' }
        ]
    },
    COATING: {
        defaultHealth: 98,
        defaultCycleVal: 84,
        defaultCycleText: 'D-12',
        defaultCycleSub: '노즐세척',
        bars: [
            { key: 'dispense_press_mpa', label: '분사압', unit: 'MPa', base: 0.35, min: 0.2, max: 0.5, decimals: 2, color: '#10b981' },
            { key: 'film_thickness_um', label: '도포두께', unit: 'μm', base: 75.0, min: 40, max: 120, decimals: 1, color: '#38bdf8' },
            { key: 'uv_energy_mj', label: 'UV광량', unit: 'mJ', base: 1250, min: 800, max: 1800, decimals: 0, color: '#a78bfa' },
            { key: 'fluid_viscosity_cp', label: '액점도', unit: 'cP', base: 185, min: 120, max: 260, decimals: 0, color: '#38bdf8' }
        ]
    },
    FCT: {
        defaultHealth: 99,
        defaultCycleVal: 95,
        defaultCycleText: 'D-30',
        defaultCycleSub: '지그교정',
        bars: [
            { key: 'mcu_volt_v', label: '전원전압', unit: 'V', base: 5.02, min: 4.5, max: 5.5, decimals: 2, color: '#10b981' },
            { key: 'curr_draw_ma', label: '소비전류', unit: 'mA', base: 142.5, min: 80, max: 220, decimals: 1, color: '#38bdf8' },
            { key: 'can_resp_ms', label: '통신지연', unit: 'ms', base: 4.8, min: 1.0, max: 15.0, decimals: 1, color: '#a78bfa' },
            { key: 'fw_check_score', label: '펌웨어검증', unit: '점', base: 100, min: 80, max: 100, decimals: 0, color: '#38bdf8' }
        ]
    }
};

const latestPdmData = {
    LASER: null,
    SPI: null,
    MOUNTER_1: null,
    MOUNTER_2: null,
    REFLOW: null,
    DIP_AOI: null,
    WAVE: null,
    ICT: null,
    COATING: null,
    FCT: null
};

// 최근 실제 공정 이벤트 수신 시각 (ms)
const lastActiveTimestamp = {
    LASER: 0,
    SPI: 0,
    MOUNTER_1: 0,
    MOUNTER_2: 0,
    REFLOW: 0,
    DIP_AOI: 0,
    WAVE: 0,
    ICT: 0,
    COATING: 0,
    FCT: 0
};

// 현재 머신별 실시간 렌더링 캐시
const machineCurrentState = {};

// 대기/미가동 상태용 텔레메트리 초기화
function initIdleHistories() {
    Object.keys(MACHINE_TELEMETRY_SCHEMAS).forEach(id => {
        const schema = MACHINE_TELEMETRY_SCHEMAS[id];
        const barVals = schema.bars.map(b => b.base);
        machineCurrentState[id] = {
            health: schema.defaultHealth,
            cycleVal: schema.defaultCycleVal,
            cycleText: schema.defaultCycleText,
            cycleSub: schema.defaultCycleSub,
            bars: barVals,
            status: 'NORMAL'
        };

        drawRadialGauges(id, schema.defaultHealth, schema.defaultCycleVal, schema.defaultCycleText, schema.defaultCycleSub, 'NORMAL');
        drawVerticalBars(id, barVals, 'NORMAL');
    });
}
window.initIdleHistories = initIdleHistories;

let isIdleAmbientLoopRunning = false;

// 미가동 설비 대기 하트비트 루프 (게이지 및 막대 미세 변동)
function startIdleAmbientLoop() {
    if (isIdleAmbientLoopRunning) return;
    isIdleAmbientLoopRunning = true;

    setInterval(() => {
        const now = Date.now();
        Object.keys(MACHINE_TELEMETRY_SCHEMAS).forEach(id => {
            // 최근 3.5초간 실제 생산 이벤트가 없었던 미가동 설비만 대상
            if (now - lastActiveTimestamp[id] > 3500) {
                const schema = MACHINE_TELEMETRY_SCHEMAS[id];
                const state = machineCurrentState[id] || {
                    health: schema.defaultHealth,
                    cycleVal: schema.defaultCycleVal,
                    cycleText: schema.defaultCycleText,
                    cycleSub: schema.defaultCycleSub,
                    bars: schema.bars.map(b => b.base),
                    status: 'NORMAL'
                };

                // 건전도 미세 변동
                let health = schema.defaultHealth + (Math.random() - 0.5) * 1.2;
                health = Math.round(Math.max(94, Math.min(100, health)));
                state.health = health;

                // 4대 막대 수치 미세 변동
                state.bars = schema.bars.map((b, idx) => {
                    const base = b.base;
                    const span = (b.max - b.min) * 0.02;
                    let val = base + (Math.random() - 0.5) * span;
                    return parseFloat(val.toFixed(b.decimals));
                });

                drawRadialGauges(id, state.health, state.cycleVal, state.cycleText, state.cycleSub, 'NORMAL');
                drawVerticalBars(id, state.bars, 'NORMAL');
            }
        });
    }, 1400);
}

let activeModalProcess = null;

// 1. KPI 실시간 동기화
async function initKPI() {
    try {
        const res = await fetch('/backend/api/get_kpi.php');
        const json = await res.json();
        if (json.status === 'success' && json.data) {
            const d = json.data;
            currentTarget = d.target_qty || 0;
            const totalCount = d.actual_qty || 0;
            const failCount  = d.fail_qty || 0;
            const goodCount  = d.good_qty || 0;

            const elTarget = document.getElementById('val-target');
            const elActual = document.getElementById('val-actual');
            const elGood   = document.getElementById('val-good');
            const elFail   = document.getElementById('val-fail');
            const elYield  = document.getElementById('val-yield');

            if (elTarget) elTarget.innerText = currentTarget;
            if (elActual) elActual.innerText = totalCount;
            if (elGood)   elGood.innerText   = goodCount;
            if (elFail)   elFail.innerText   = failCount;
            if (elYield) {
                elYield.innerText  = d.yield_rate || '100.0%';
                const yNum = parseFloat(d.yield_rate || '100');
                elYield.className = 'kpi-val ' + (yNum >= 95 ? 'good' : (yNum >= 80 ? 'warn' : 'danger'));
            }
        }
    } catch(e) {
        console.error('KPI 동기화 실패:', e);
    }
}
const syncKPI = initKPI;
window.initKPI = initKPI;
window.syncKPI = syncKPI;
window.resetAllMachines = resetAllMachines;

// 2. 로그 필터링
function applyLogFilter() {
    const selProcEl = document.getElementById('filter-process');
    const selStatEl = document.getElementById('filter-status');
    if (!selProcEl || !selStatEl) return;

    const selProc = selProcEl.value;
    const selStat = selStatEl.value;
    const items = document.querySelectorAll('#log-list li');
    
    items.forEach(li => {
        if (!li.dataset.process) return;
        const matchProc = (selProc === 'ALL' || li.dataset.process === selProc);
        const matchStat = (selStat === 'ALL' || li.dataset.status === selStat);
        li.style.display = (matchProc && matchStat) ? 'flex' : 'none';
    });
}
window.applyLogFilter = applyLogFilter;

// 3. 로그 리스트 추가
function addLog(process, status, dataStr) {
    const logList = document.getElementById('log-list');
    if (!logList) return;

    if (logList.children.length === 1 && logList.children[0].innerText.includes('센서 스트림 대기 중')) {
        logList.innerHTML = '';
    }

    const li = document.createElement('li');
    const time = new Date().toLocaleTimeString('ko-KR');
    const isPass = (status === 'PASS');
    
    li.dataset.process = process;
    li.dataset.status = status;
    
    li.innerHTML = `
        <div class="log-row-top">
            <span class="log-time">[${time}]</span>
            <span class="log-tag">${process}</span>
            <span class="log-res ${isPass ? 'pass' : 'fail'}">${status}</span>
        </div>
        <div class="log-msg">${dataStr}</div>
    `;
    logList.appendChild(li);
    
    if (logList.children.length > 150) {
        logList.removeChild(logList.firstChild);
    }

    applyLogFilter();
    logList.scrollTop = logList.scrollHeight;
}
window.addLog = addLog;

// 4. 머신 카드 및 텔레메트리 전체 클린 리셋
function resetAllMachines(targetLine = 'ALL') {
    const smtIds = ['LASER', 'SPI', 'MOUNTER_1', 'MOUNTER_2', 'REFLOW'];
    const dipIds = ['DIP_AOI', 'WAVE', 'ICT', 'COATING', 'FCT'];
    const machineIds = targetLine === 'SMT' ? smtIds : (targetLine === 'DIP' ? dipIds : [...smtIds, ...dipIds]);

    machineIds.forEach(id => {
        if (machineResetTimers[id]) {
            clearTimeout(machineResetTimers[id]);
        }
        lastActiveTimestamp[id] = 0;
        const mac = document.getElementById(`mac-${id}`);
        const dataBox = document.getElementById(`data-${id}`);
        const cellWrap = document.getElementById(`cells-${id}`);
        
        latestPdmData[id] = null;
        const defectTag = document.getElementById(`defect-tag-${id}`);
        if (defectTag) defectTag.innerHTML = '';

        if (mac) {
            mac.className = 'machine-card wait';
            const indicator = mac.querySelector('.mac-status-indicator');
            if (indicator) indicator.innerText = '대기';
        }
        if (dataBox) dataBox.innerText = '-';
        
        if (cellWrap) {
            cellWrap.innerHTML = `
                <span class="cell-chip wait">#1</span>
                <span class="cell-chip wait">#2</span>
                <span class="cell-chip wait">#3</span>
                <span class="cell-chip wait">#4</span>
            `;
        }
    });

    initIdleHistories();
}

// 5-1. 상단: 건전도 & 정비주기 듀얼 원형 게이지 (27인치/15인치/8인치 태블릿 완벽 반응형 동적 스케일링)
function drawRadialGauges(processId, healthVal, cycleVal, cycleText, cycleSub, pdmStatus) {
    const canvas = document.getElementById(`canvas-radial-${processId}`);
    if (!canvas) return;

    const dpr = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    const cssW = Math.max(100, Math.round(rect.width) || canvas.offsetWidth || 180);
    const cssH = Math.max(28, Math.round(rect.height) || canvas.offsetHeight || 46);

    if (canvas.width !== Math.round(cssW * dpr) || canvas.height !== Math.round(cssH * dpr)) {
        canvas.width = Math.round(cssW * dpr);
        canvas.height = Math.round(cssH * dpr);
    }

    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, cssW, cssH);

    const safeHealth = Math.max(0, Math.min(100, Number(healthVal) || 98));
    const safeCycle  = Math.max(0, Math.min(100, Number(cycleVal) || 80));

    // 색상 테마
    let healthColor = '#10b981';
    let statusText = '정상';
    if (pdmStatus === 'WARNING' || safeHealth < 75) {
        healthColor = '#ef4444';
        statusText = '경고';
    } else if (pdmStatus === 'CAUTION' || safeHealth < 88) {
        healthColor = '#f59e0b';
        statusText = '주의';
    }

    const cy = cssH / 2;
    const midX = Math.round(cssW / 2);
    const halfW = midX;

    // 원형 게이지 반지름 및 선 굵기 (화면 크기 / 컨테이너 높이에 비례하여 동적 계산)
    // 27인치(cssH 60~80), 15인치(cssH 44~52), 8인치 태블릿(cssH 30~40)에 맞춘 선형 스케일링
    const strokeW = Math.max(2.5, Math.min(6.5, cssH * 0.09));
    const maxRByHeight = (cssH / 2) - (strokeW / 2) - 1.5;
    const maxRByWidth = (halfW / 2) - (strokeW / 2) - 4;
    const r = Math.max(10, Math.min(maxRByHeight, maxRByWidth));
    const innerR = Math.max(7, r - (strokeW / 2));

    // 내부 텍스트 폰트 크기 및 상하 오프셋 (innerR에 1:1 완벽 비례)
    // 27인치(innerR 25~32): numFontSize 16~21px, statusFontSize 11~14px
    // 15인치(innerR 16~20): numFontSize 11~14px, statusFontSize 8~10px
    // 8인치 태블릿(innerR 10~14): numFontSize 8~10px, statusFontSize 6.5~8px
    const numFontSize = Math.max(8, Math.min(22, Math.round(innerR * 0.65)));
    const statusFontSize = Math.max(6.5, Math.min(15, Math.round(innerR * 0.44)));
    const lineGap = Math.max(2.8, Math.round(innerR * 0.38));

    // ── 중앙 구분선 ──
    ctx.save();
    ctx.strokeStyle = 'rgba(255, 255, 255, 0.1)';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(midX, Math.max(2, cssH * 0.1));
    ctx.lineTo(midX, Math.min(cssH - 2, cssH * 0.9));
    ctx.stroke();
    ctx.restore();

    // 각 절반 중앙에 원 배치
    const leftCX = Math.round(midX / 2);
    const rightCX = Math.round(midX + (cssW - midX) / 2);

    // ── 좌측 원그래프: 설비 건전도 (Health Index) ──
    ctx.save();
    // 배경 링
    ctx.beginPath();
    ctx.arc(leftCX, cy, r, 0, Math.PI * 2);
    ctx.strokeStyle = 'rgba(255, 255, 255, 0.08)';
    ctx.lineWidth = strokeW;
    ctx.stroke();
    // 값 아크
    ctx.beginPath();
    ctx.arc(leftCX, cy, r, -Math.PI / 2, -Math.PI / 2 + (safeHealth / 100) * (Math.PI * 2));
    ctx.strokeStyle = healthColor;
    ctx.lineWidth = strokeW;
    ctx.lineCap = 'round';
    ctx.stroke();
    ctx.restore();

    // 도넛 내부: 퍼센트 (위) + 상태 (아래)
    ctx.save();
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.font = `bold ${numFontSize}px "JetBrains Mono", monospace`;
    ctx.fillStyle = '#f8fafc';
    ctx.fillText(`${safeHealth}%`, leftCX, cy - lineGap);
    ctx.font = `bold ${statusFontSize}px sans-serif`;
    ctx.fillStyle = healthColor;
    ctx.fillText(statusText, leftCX, cy + lineGap);
    ctx.restore();

    // ── 우측 원그래프: 예방보전 주기/수명 (PM Cycle / RUL) ──
    const cycleColor = '#38bdf8';
    ctx.save();
    // 배경 링
    ctx.beginPath();
    ctx.arc(rightCX, cy, r, 0, Math.PI * 2);
    ctx.strokeStyle = 'rgba(255, 255, 255, 0.08)';
    ctx.lineWidth = strokeW;
    ctx.stroke();
    // 값 아크
    ctx.beginPath();
    ctx.arc(rightCX, cy, r, -Math.PI / 2, -Math.PI / 2 + (safeCycle / 100) * (Math.PI * 2));
    ctx.strokeStyle = cycleColor;
    ctx.lineWidth = strokeW;
    ctx.lineCap = 'round';
    ctx.stroke();
    ctx.restore();

    // 도넛 내부: 값 (위) + 상태 (아래)
    ctx.save();
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.font = `bold ${numFontSize}px "JetBrains Mono", monospace`;
    ctx.fillStyle = '#f8fafc';
    ctx.fillText(cycleText || `${safeCycle}%`, rightCX, cy - lineGap);
    ctx.font = `bold ${statusFontSize}px sans-serif`;
    ctx.fillStyle = cycleColor;
    ctx.fillText(cycleSub || '양호', rightCX, cy + lineGap);
    ctx.restore();
}

// 5-2. 하단: 4대 핵심 물리량 수직 막대 그래프 (27인치/15인치/8인치 태블릿 완벽 반응형)
function drawVerticalBars(processId, metricsValues, pdmStatus) {
    const canvas = document.getElementById(`canvas-bars-${processId}`);
    const schema = MACHINE_TELEMETRY_SCHEMAS[processId];
    if (!canvas || !schema) return;

    const dpr = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    const cssW = Math.max(100, Math.round(rect.width) || canvas.offsetWidth || 180);
    const cssH = Math.max(26, Math.round(rect.height) || canvas.offsetHeight || 48);

    if (canvas.width !== Math.round(cssW * dpr) || canvas.height !== Math.round(cssH * dpr)) {
        canvas.width = Math.round(cssW * dpr);
        canvas.height = Math.round(cssH * dpr);
    }

    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, cssW, cssH);

    const slotWidth = cssW / 4;
    const is8InchOrLess = (window.innerWidth <= 1199) || (cssW < 135);
    const is15Inch = !is8InchOrLess && (window.innerWidth < 1700 || slotWidth < 70);

    let valFontSize = Math.max(7.5, Math.min(13, Math.round(cssH * 0.20)));
    let labelFontSize = Math.max(7, Math.min(12, Math.round(cssH * 0.19)));
    let labelH = 0;
    let footH = 0;
    let staggerOffset = 0;

    if (is8InchOrLess) {
        // 8인치 이하: 그래프만 표시 (상단 수치 및 하단 라벨 미표시)
        labelH = 2;
        footH = 2;
    } else if (is15Inch) {
        // 15인치: 상단 수치값 위,아래,위,아래 지그재그 배치로 텍스트 겹침 방지
        valFontSize = Math.max(7, Math.min(10.5, Math.round(cssH * 0.18)));
        labelFontSize = Math.max(7, Math.min(10, Math.round(cssH * 0.18)));
        staggerOffset = Math.max(8.5, Math.round(valFontSize + 1.5));
        labelH = Math.round(valFontSize + staggerOffset + 2);
        footH = Math.max(8, Math.round(cssH * 0.18));
    } else {
        // 27인치 대화면: 상단 수치값 한 줄 배치
        valFontSize = Math.max(8.5, Math.min(14, Math.round(cssH * 0.22)));
        labelFontSize = Math.max(7.5, Math.min(13, Math.round(cssH * 0.20)));
        labelH = Math.max(12, Math.round(cssH * 0.22));
        footH = Math.max(10, Math.round(cssH * 0.22));
    }

    const trackY = labelH + 1;
    const trackH = Math.max(6, cssH - trackY - footH - 1);
    const trackW = is8InchOrLess
        ? Math.max(5, Math.min(14, Math.round(slotWidth * 0.32)))
        : Math.max(6, Math.min(18, Math.round(slotWidth * 0.25)));

    schema.bars.forEach((b, i) => {
        const val = (metricsValues && metricsValues[i] !== undefined) ? metricsValues[i] : b.base;
        const cx = Math.round(slotWidth * i + slotWidth / 2);

        // 정규화 비율
        const ratio = Math.max(0.06, Math.min(0.94, (val - b.min) / (b.max - b.min)));
        const fillH = Math.max(2, trackH * ratio);
        const fillY = trackY + trackH - fillH;

        // 색상 판정
        let barColor = b.color || '#38bdf8';
        if (pdmStatus === 'WARNING') barColor = '#ef4444';
        else if (pdmStatus === 'CAUTION') barColor = '#f59e0b';

        ctx.save();

        // 1. 상단 측정 수치 (8인치 이하에서는 그래프만 표시)
        if (!is8InchOrLess) {
            ctx.font = `bold ${valFontSize}px "JetBrains Mono", monospace`;
            ctx.fillStyle = '#f1f5f9';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'top';

            let valY = 1;
            if (is15Inch) {
                // 위(0, 2), 아래(1, 3) 위/아래 교대 배치로 인접 텍스트 겹침 방지
                valY = (i % 2 === 0) ? 1 : (1 + staggerOffset);
            }
            ctx.fillText(`${val}${b.unit}`, cx, valY);
        }

        // 2. 바 배경 트랙
        const rx = cx - trackW / 2;
        ctx.beginPath();
        if (ctx.roundRect) {
            ctx.roundRect(rx, trackY, trackW, trackH, Math.min(3.5, trackW / 2));
        } else {
            ctx.rect(rx, trackY, trackW, trackH);
        }
        ctx.fillStyle = 'rgba(255, 255, 255, 0.08)';
        ctx.fill();

        // 3. 내부 채움 막대
        ctx.beginPath();
        if (ctx.roundRect) {
            ctx.roundRect(rx, fillY, trackW, fillH, Math.min(3.5, trackW / 2));
        } else {
            ctx.rect(rx, fillY, trackW, fillH);
        }
        ctx.fillStyle = barColor;
        ctx.fill();

        // 4. 중심 기준선 틱
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.35)';
        ctx.lineWidth = 1.0;
        ctx.beginPath();
        ctx.moveTo(rx - 2, trackY + trackH * 0.5);
        ctx.lineTo(rx + trackW + 2, trackY + trackH * 0.5);
        ctx.stroke();

        // 5. 하단 물리량 명칭 (8인치 이하에서는 숨김)
        if (!is8InchOrLess) {
            ctx.font = `bold ${labelFontSize}px sans-serif`;
            ctx.fillStyle = '#94a3b8';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'bottom';
            ctx.fillText(b.label, cx, cssH - 1);
        }

        ctx.restore();
    });
}

// 6. 머신 카드 실시간 상태 업데이트 & PdM 텔레메트리 연동
function updateMachine(processId, status, barcode, pDataObj) {
    const mac = document.getElementById(`mac-${processId}`);
    const dataBox = document.getElementById(`data-${processId}`);
    const cellWrap = document.getElementById(`cells-${processId}`);
    const defectTag = document.getElementById(`defect-tag-${processId}`);
    if (!mac || !dataBox) return;

    const indicator = mac.querySelector('.mac-status-indicator');

    // 설비 대기(IDLE/WAIT) 처리
    if (status === 'IDLE' || status === 'WAIT' || !barcode || barcode === '-') {
        mac.classList.remove('run', 'error', 'pulse-active');
        mac.classList.add('wait');
        if (indicator) indicator.innerText = '대기';
        dataBox.innerText = '-';
        if (defectTag) defectTag.innerHTML = '';
        if (cellWrap) {
            cellWrap.innerHTML = `
                <span class="cell-chip wait">#1</span>
                <span class="cell-chip wait">#2</span>
                <span class="cell-chip wait">#3</span>
                <span class="cell-chip wait">#4</span>
            `;
        }
        if (machineResetTimers[processId]) {
            clearTimeout(machineResetTimers[processId]);
            machineResetTimers[processId] = null;
        }
        return;
    }

    lastActiveTimestamp[processId] = Date.now();
    
    // 자동 복귀 타이머 (3.8초간 후속 이벤트 미수신 시 대기 모드로 자연스럽게 전환)
    if (machineResetTimers[processId]) {
        clearTimeout(machineResetTimers[processId]);
    }
    machineResetTimers[processId] = setTimeout(() => {
        const currentMac = document.getElementById(`mac-${processId}`);
        if (currentMac && currentMac.classList.contains('run')) {
            updateMachine(processId, 'IDLE', '-', null);
        }
    }, 3800);

    // 1. 상태 분류 (설비 고장 vs 제품 불량 vs 정상 가동)
    const isMachineAlarm = (status === 'ALARM' || status === 'MACHINE_ALARM' || (pDataObj && pDataObj.is_machine_alarm));
    const isPass = (status === 'PASS');
    const isProductDefect = (status === 'FAIL' || status === 'DEFECT' || (!isPass && !isMachineAlarm));

    // 2. 카드 클래스 적용 (설비 알람 시에만 error/alarm 적용, 제품 불량 시에는 가동 run 유지!)
    mac.classList.remove('wait', 'run', 'error', 'alarm');
    if (isMachineAlarm) {
        mac.classList.add('error', 'alarm');
    } else {
        mac.classList.add('run');
    }
    
    dataBox.innerText = barcode || '-';
    
    // 바코드에서 PCB 번호 추출 (예: C1-20260813-2A6-0006 -> 6)
    let pcbNum = '';
    if (barcode && barcode.includes('-')) {
        const parts = barcode.split('-');
        const lastPart = parts[parts.length - 1];
        if (!isNaN(parseInt(lastPart))) {
            pcbNum = parseInt(lastPart);
        }
    }
    if (pDataObj && pDataObj.pcb_no) pcbNum = pDataObj.pcb_no;

    // 3. 상태 인디케이터 라벨
    if (indicator) {
        if (isMachineAlarm) {
            indicator.innerText = '🚨 설비알람';
        } else if (isPass) {
            indicator.innerText = '가동중';
        } else {
            indicator.innerText = (pDataObj && pDataObj.is_inherited_fail) ? '불량 통과' : '불량감지';
        }
    }

    // 4. 설비카드 하단 불량 PCB 알림 태그 갱신
    const failedCell = (pDataObj && pDataObj.failed_cell) ? pDataObj.failed_cell : (isProductDefect ? 2 : 0);
    if (defectTag) {
        if (isMachineAlarm) {
            defectTag.innerHTML = `<span class="defect-badge-highlight" style="background:#ef4444; color:#fff;" title="설비 파라미터 임계치 초과 경보">🚨 설비 비상점검 필요</span>`;
        } else if (isProductDefect) {
            defectTag.innerHTML = `<span class="defect-badge-highlight" title="[불량] PCB #${pcbNum}번 기판 셀 #${failedCell} 불량 감지">⚠️ PCB #${pcbNum} 불량(셀 #${failedCell})</span>`;
        } else {
            defectTag.innerHTML = '';
        }
    }

    // 5. 4-UP 어레이 패널 셀 인디케이터 업데이트
    if (cellWrap) {
        let cellHtml = '';
        for (let c = 1; c <= 4; c++) {
            if (isProductDefect && failedCell === c) {
                cellHtml += `<span class="cell-chip fail" title="[셀 #${c} 불량] Bad Mark 스킵">#${c} ✖</span>`;
            } else {
                cellHtml += `<span class="cell-chip pass" title="[셀 #${c}] 정상">#${c} ✔</span>`;
            }
        }
        cellWrap.innerHTML = cellHtml;
    }
    
    // PdM 텔레메트리 파싱 및 상단 게이지 + 하단 4대 막대그래프 갱신
    if (pDataObj) {
        latestPdmData[processId] = pDataObj;
        mac.dataset.pdm = JSON.stringify(pDataObj);

        const schema = MACHINE_TELEMETRY_SCHEMAS[processId];
        if (schema) {
            const health = pDataObj.pdm_health !== undefined ? pDataObj.pdm_health : schema.defaultHealth;
            
            // 수명주기 텍스트 추출
            let cycleVal = schema.defaultCycleVal;
            let cycleText = schema.defaultCycleText;
            let cycleSub = schema.defaultCycleSub;

            if (pDataObj.filter_life_pct !== undefined) {
                cycleVal = pDataObj.filter_life_pct;
                cycleText = `D-${pDataObj.rul_filter_days || 14}`;
            } else if (pDataObj.mask_wash_count !== undefined) {
                cycleVal = Math.max(0, 100 - pDataObj.mask_wash_count);
                cycleText = `${100 - pDataObj.mask_wash_count}타`;
            } else if (pDataObj.nozzle_rul_days !== undefined) {
                cycleVal = 85;
                cycleText = `D-${pDataObj.nozzle_rul_days}`;
            } else if (pDataObj.flux_trap_level_pct !== undefined) {
                cycleVal = Math.max(0, 100 - Math.round(pDataObj.flux_trap_level_pct));
                cycleText = `D-8`;
            }

            // 4대 물리량 막대 값 추출
            const barVals = schema.bars.map(b => {
                let v = pDataObj[b.key];
                if (v === undefined && b.altKey) v = pDataObj[b.altKey];
                if (v === undefined) v = b.base;
                return parseFloat(Number(v).toFixed(b.decimals));
            });

            machineCurrentState[processId] = {
                health,
                cycleVal,
                cycleText,
                cycleSub,
                bars: barVals,
                status: pDataObj.pdm_status || (isPass ? 'NORMAL' : 'WARNING')
            };

            drawRadialGauges(processId, health, cycleVal, cycleText, cycleSub, pDataObj.pdm_status);
            drawVerticalBars(processId, barVals, pDataObj.pdm_status);
        }

        if (activeModalProcess === processId) {
            renderPdmModalContent(processId);
        }
    }

    if (machineResetTimers[processId]) {
        clearTimeout(machineResetTimers[processId]);
    }
    machineResetTimers[processId] = setTimeout(() => {
        if (mac.classList.contains('run')) {
            mac.classList.remove('run', 'pulse-active');
            mac.classList.add('wait');
            if (indicator) indicator.innerText = '대기';
            dataBox.innerText = '-';
            if (cellWrap) {
                cellWrap.innerHTML = `
                    <span class="cell-chip wait">#1</span>
                    <span class="cell-chip wait">#2</span>
                    <span class="cell-chip wait">#3</span>
                    <span class="cell-chip wait">#4</span>
                `;
            }
        }
    }, 3500);
}

// 7. 설비 정밀 예지보전(PdM) 진단 모달 엔진 (RUL & PM 캘린더 & 최근 알람 이력 포함)
function openPdmModal(processId, event) {
    if (event) {
        if (typeof event.preventDefault === 'function') event.preventDefault();
        if (typeof event.stopPropagation === 'function') event.stopPropagation();
    }
    activeModalProcess = processId;
    const modal = document.getElementById('pdmModalOverlay');
    if (!modal) return;

    modal.classList.add('open');
    renderPdmModalContent(processId);
    loadMachineAlarmsInModal(processId);
}
window.openPdmModal = openPdmModal;

function closePdmModal(e) {
    if (e && e.target) {
        if (e.target.closest && e.target.closest('.pdm-modal-box')) return;
    }
    const modal = document.getElementById('pdmModalOverlay');
    if (modal) modal.classList.remove('open');
    activeModalProcess = null;
}
window.closePdmModal = closePdmModal;

async function loadMachineAlarmsInModal(processId) {
    const listEl = document.getElementById('pdmAlarmsList');
    const countEl = document.getElementById('pdmAlarmsCount');
    if (!listEl) return;

    try {
        const res = await fetch(`../backend/api/get_machine_alarms.php?process_id=${processId}`);
        const json = await res.json();
        if (json.status === 'success' && json.data) {
            const alarms = json.data.alarms || [];
            if (countEl) countEl.innerText = `${alarms.length}건`;

            if (alarms.length === 0) {
                listEl.innerHTML = `<div class="pdm-alarm-empty">✅ 최근 감지된 이상/알람 이력이 없습니다. (설비 정상 가동 중)</div>`;
                return;
            }

            listEl.innerHTML = alarms.map(a => `
                <div class="pdm-alarm-item">
                    <div class="pdm-alarm-top">
                        <span class="pdm-alarm-time">⏰ ${a.created_at} | <code>${a.barcode || '-'}</code></span>
                        <span class="pdm-alarm-status">${a.result_status === 'FAIL' ? '🚨 공정 불량' : '⚠️ 설비 주의'} (건전도 ${a.pdm_health}점)</span>
                    </div>
                    <div class="pdm-alarm-msg"><strong>${a.metric_name}:</strong> <span style="color:#f87171; font-weight:700;">${a.metric_val} ${a.metric_unit}</span></div>
                    <div class="pdm-alarm-rec">💡 <strong>조치 권고:</strong> ${a.recommendation}</div>
                </div>
            `).join('');
        }
    } catch (e) {
        console.warn('loadMachineAlarmsInModal error:', e);
    }
}
window.loadMachineAlarmsInModal = loadMachineAlarmsInModal;

function renderPdmModalContent(processId) {
    const d = latestPdmData[processId] || {};
    const badge = document.getElementById('pdmModalBadge');
    const title = document.getElementById('pdmModalTitle');
    const healthScoreEl = document.getElementById('pdmHealthScore');
    const healthBarEl = document.getElementById('pdmHealthBar');
    const statusTagEl = document.getElementById('pdmStatusTag');
    const sensorsGrid = document.getElementById('pdmSensorsGrid');
    const rulSection = document.getElementById('pdmRulSection');
    const recommendationEl = document.getElementById('pdmRecommendation');

    const names = {
        LASER: 'Laser Marker #1',
        SPI: 'SPI 3D 납 도포 검사기 #1',
        MOUNTER_1: 'High-Speed Surface Mounter #1 (고속 마운터)',
        MOUNTER_2: 'Odd-Form Surface Mounter #2 (이형 마운터)',
        REFLOW: 'Reflow Soldering 10-Zone Oven',
        DIP_AOI: 'Through-Hole DIP AOI System',
        WAVE: 'Wave Soldering Bath #1',
        ICT: 'In-Circuit Tester #1 (ICT 회로 검사기)',
        COATING: 'Conformal Coating & UV Curing #1 (방습 코팅기)',
        FCT: 'Functional Circuit Tester #1 (FCT 기능 검사기)'
    };

    if (badge) badge.innerText = processId;
    if (title) title.innerText = `${names[processId] || processId} 정밀 예지보전 진단`;

    const openHmiBtn = document.getElementById('btnOpenDedicatedHmi');
    if (openHmiBtn) openHmiBtn.href = `machine.html?eq=${processId}`;

    const health = d.pdm_health !== undefined ? d.pdm_health : 98;
    if (healthScoreEl) healthScoreEl.innerHTML = `${health} <span class="score-unit">/ 100</span>`;
    if (healthBarEl) healthBarEl.style.width = `${health}%`;

    const status = d.pdm_status || 'NORMAL';
    if (statusTagEl) {
        if (status === 'WARNING') {
            statusTagEl.className = 'health-status-tag tag-warning';
            statusTagEl.innerText = '경고 (주의 관찰 필요)';
        } else if (status === 'CAUTION') {
            statusTagEl.className = 'health-status-tag tag-caution';
            statusTagEl.innerText = '주의 (사전 점검 권고)';
        } else {
            statusTagEl.className = 'health-status-tag tag-normal';
            statusTagEl.innerText = '정상 안정 (Good)';
        }
    }

    // 1. 설비별 4분할 센서 그리드 렌더링
    let sensorsHtml = '';
    let rulHtml = '';

    if (processId === 'LASER') {
        sensorsHtml = `
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>레이저 발진 출력</span><span class="sensor-limit">목표 15.2W (±0.5)</span></div>
                <div class="sensor-val ${d.laser_power_w < 14.7 ? 'warn' : 'good'}">${d.laser_power_w || 15.2} W</div>
            </div>
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>발진 튜브 온도</span><span class="sensor-limit">상한 38.0℃</span></div>
                <div class="sensor-val good">${d.tube_temp_c || 31.5} ℃</div>
            </div>
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>광학계 렌즈 청결도</span><span class="sensor-limit">하한 90.0%</span></div>
                <div class="sensor-val good">${d.lens_cleanliness_pct || 97.2} %</div>
            </div>
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>갈바노 미러 온도</span><span class="sensor-limit">상한 40.0℃</span></div>
                <div class="sensor-val good">${d.galvano_temp_c || 32.4} ℃</div>
            </div>
        `;
        rulHtml = `
            <div class="pdm-rul-card">
                <div class="rul-top-row">
                    <span class="rul-title">집진기 흄(Fume) 필터 잔여 수명 (RUL)</span>
                    <span class="rul-days-badge">잔여 D-14</span>
                </div>
                <div class="rul-bar-wrap">
                    <div class="rul-bar-fill" style="width: ${d.filter_life_pct || 82}%;"></div>
                </div>
                <div class="rul-sub-info">
                    <span>집진기 음압: ${d.fume_pressure_kpa || -2.4} kPa (정상)</span>
                    <span>필터 상태: ${d.filter_life_pct || 82}% 양호</span>
                </div>
            </div>
        `;
    } else if (processId === 'SPI') {
        sensorsHtml = `
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>납 도포 체적율 (Volume)</span><span class="sensor-limit">90 ~ 115%</span></div>
                <div class="sensor-val ${d.volume_pct < 85 || d.volume_pct > 115 ? 'crit' : 'good'}">${d.volume_pct || 102.5} %</div>
            </div>
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>솔더 도포 높이</span><span class="sensor-limit">120 ~ 160μm</span></div>
                <div class="sensor-val good">${d.solder_height_um || 142.0} μm</div>
            </div>
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>페이스트 점도 (Viscosity)</span><span class="sensor-limit">180 ~ 220 Pa·s</span></div>
                <div class="sensor-val good">${d.paste_viscosity_pa_s || 202} Pa·s</div>
            </div>
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>스퀴지 인쇄 가압력</span><span class="sensor-limit">2.8 ~ 3.3 kg</span></div>
                <div class="sensor-val good">${d.blade_pressure_kg || 3.0} kg</div>
            </div>
        `;
        rulHtml = `
            <div class="pdm-rul-card">
                <div class="rul-top-row">
                    <span class="rul-title">메탈마스크 초음파 세척 주기 관리</span>
                    <span class="rul-days-badge">잔여 ${100 - (d.mask_wash_count || 84)}타</span>
                </div>
                <div class="rul-bar-wrap">
                    <div class="rul-bar-fill ${d.mask_wash_count > 90 ? 'warn' : ''}" style="width: ${(d.mask_wash_count || 84)}%;"></div>
                </div>
                <div class="rul-sub-info">
                    <span>인쇄 오프셋 편차: X ${d.offset_x_um || '+8.2'}μm / Y ${d.offset_y_um || '-4.5'}μm</span>
                    <span>누적 인쇄: ${d.mask_wash_count || 84} / 100타</span>
                </div>
            </div>
        `;
    } else if (processId === 'MOUNTER_1' || processId === 'MOUNTER') {
        sensorsHtml = `
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>고속 노즐 진공 흡착압</span><span class="sensor-limit">하한 -78.0 kPa</span></div>
                <div class="sensor-val ${d.vacuum_kpa > -77.0 ? 'crit' : (d.vacuum_kpa > -80.0 ? 'warn' : 'good')}">${d.vacuum_kpa || -84.5} kPa</div>
            </div>
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>초고속 헤드 진동치</span><span class="sensor-limit">상한 0.150 G</span></div>
                <div class="sensor-val good">${d.head_vibration_g || 0.088} G</div>
            </div>
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>리니어 모터 온도</span><span class="sensor-limit">상한 48.0℃</span></div>
                <div class="sensor-val good">${d.motor_temp_c || 37.8} ℃</div>
            </div>
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>피더 테이프 텐션</span><span class="sensor-limit">2.5 ~ 6.0 N</span></div>
                <div class="sensor-val good">${d.feeder_tension_n || 4.2} N</div>
            </div>
        `;
        rulHtml = `
            <div class="pdm-rul-card">
                <div class="rul-top-row">
                    <span class="rul-title">고속 마운터 노즐 팁 마모도 및 교체 주기 (RUL)</span>
                    <span class="rul-days-badge">잔여 D-4 (84%)</span>
                </div>
                <div class="rul-bar-wrap">
                    <div class="rul-bar-fill" style="width: 84%;"></div>
                </div>
                <div class="rul-sub-info">
                    <span>누적 타수: ${(d.nozzle_strike_count || 168400).toLocaleString()} / 200,000타</span>
                    <span>픽업 성공률: 99.8% (정상)</span>
                </div>
            </div>
        `;
    } else if (processId === 'MOUNTER_2') {
        sensorsHtml = `
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>비전 회전 정렬오차 (θ)</span><span class="sensor-limit">±0.50 °</span></div>
                <div class="sensor-val good">${d.align_theta_deg || 0.12}°</div>
            </div>
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>부품 장착 가압력</span><span class="sensor-limit">1.0 ~ 2.8 N</span></div>
                <div class="sensor-val good">${d.force_n || 1.85} N</div>
            </div>
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>트레이 피더 잔량</span><span class="sensor-limit">하한 20%</span></div>
                <div class="sensor-val good">${d.tray_feeder_rem || 86} %</div>
            </div>
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>비전 패턴 일치율</span><span class="sensor-limit">하한 95.0%</span></div>
                <div class="sensor-val good">${d.vision_match_pct || 99.4} %</div>
            </div>
        `;
        rulHtml = `
            <div class="pdm-rul-card">
                <div class="rul-top-row">
                    <span class="rul-title">이형 마운터 비전 카메라 조명 & 얼라인먼트 교정</span>
                    <span class="rul-days-badge">잔여 D-7 (78%)</span>
                </div>
                <div class="rul-bar-wrap">
                    <div class="rul-bar-fill" style="width: 78%;"></div>
                </div>
                <div class="rul-sub-info">
                    <span>IC/커넥터 정밀 얼라인먼트 오차: X 4.2μm / Y 3.1μm</span>
                    <span>트레이 자동 공급 상태: 양호</span>
                </div>
            </div>
        `;
    } else if (processId === 'REFLOW') {
        sensorsHtml = `
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>최고 피크 솔더링 온도</span><span class="sensor-limit">243 ~ 249 ℃</span></div>
                <div class="sensor-val ${d.peak_temp_c > 251.0 ? 'crit' : 'good'}">${d.peak_temp_c || 245.5} ℃</div>
            </div>
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>액상선 체류 시간 (TAL)</span><span class="sensor-limit">45 ~ 60 sec</span></div>
                <div class="sensor-val good">${d.tal_sec || 52.0} sec</div>
            </div>
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>승온 구배 (Ramp-Up)</span><span class="sensor-limit">1.5 ~ 2.2 ℃/s</span></div>
                <div class="sensor-val good">${d.ramp_rate_c_s || 1.85} ℃/s</div>
            </div>
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>질소 챔버 산소 농도</span><span class="sensor-limit">상한 500 ppm</span></div>
                <div class="sensor-val good">${d.oxygen_ppm || 375} ppm</div>
            </div>
        `;
        rulHtml = `
            <div class="pdm-rul-card">
                <div class="rul-top-row">
                    <span class="rul-title">플럭스 회수 트랩 포화도 & 클리닝 주기</span>
                    <span class="rul-days-badge">잔여 D-8</span>
                </div>
                <div class="rul-bar-wrap">
                    <div class="rul-bar-fill" style="width: ${d.flux_trap_level_pct || 42}%;"></div>
                </div>
                <div class="rul-sub-info">
                    <span>냉각 구배: ${d.cooling_rate_c_s || -2.4} ℃/s (급랭 양호)</span>
                    <span>트랩 포화도: ${d.flux_trap_level_pct || 42}% (70% 도달 시 알림)</span>
                </div>
            </div>
        `;
    } else if (processId === 'DIP_AOI') {
        sensorsHtml = `
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>리드 핀 솔더링 품질점수</span><span class="sensor-limit">하한 90.0점</span></div>
                <div class="sensor-val ${d.pin_soldering_score < 90.0 ? 'crit' : 'good'}">${d.pin_soldering_score || 98.5} pts</div>
            </div>
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>납 브릿지 쇼트 위험율</span><span class="sensor-limit">상한 5.0%</span></div>
                <div class="sensor-val good">${d.bridge_risk_pct || 1.2} %</div>
            </div>
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>부품 들뜸(Lift) 높이</span><span class="sensor-limit">상한 50 μm</span></div>
                <div class="sensor-val good">${d.lift_height_um || 18.0} μm</div>
            </div>
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>부품 기울어짐 각도</span><span class="sensor-limit">상한 2.0°</span></div>
                <div class="sensor-val good">${d.comp_tilt_deg || 0.4}°</div>
            </div>
        `;
        rulHtml = `
            <div class="pdm-rul-card">
                <div class="rul-top-row">
                    <span class="rul-title">비전 광학계 조명 조도 & 카메라 캘리브레이션</span>
                    <span class="rul-days-badge">잔여 D-20</span>
                </div>
                <div class="rul-bar-wrap">
                    <div class="rul-bar-fill" style="width: 92%;"></div>
                </div>
                <div class="rul-sub-info">
                    <span>카메라 프레임 레이트: ${d.camera_fps || 59.8} FPS</span>
                    <span>조명 조도: 5000 Lux (100% 정상)</span>
                </div>
            </div>
        `;
    } else if (processId === 'WAVE') {
        sensorsHtml = `
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>솔더팟 용탕 온도</span><span class="sensor-limit">245 ~ 258 ℃</span></div>
                <div class="sensor-val ${d.pot_temp_c < 245.0 ? 'warn' : 'good'}">${d.pot_temp_c || 250.2} ℃</div>
            </div>
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>프리히터 예열 온도</span><span class="sensor-limit">120 ~ 145 ℃</span></div>
                <div class="sensor-val good">${d.preheater_temp_c || 132.5} ℃</div>
            </div>
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>솔더 웨이브 파고 높이</span><span class="sensor-limit">8.5 ~ 9.5 mm</span></div>
                <div class="sensor-val good">${d.wave_height_mm || 9.1} mm</div>
            </div>
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>플럭스 도포 분사량</span><span class="sensor-limit">14 ~ 18 ml/min</span></div>
                <div class="sensor-val good">${d.flux_amount_ml_min || 16.2} ml/min</div>
            </div>
        `;
        rulHtml = `
            <div class="pdm-rul-card">
                <div class="rul-top-row">
                    <span class="rul-title">솔더팟 드로스(산화 슬러지) 누적율 & 드로스 청소 주기</span>
                    <span class="rul-days-badge">잔여 D-5</span>
                </div>
                <div class="rul-bar-wrap">
                    <div class="rul-bar-fill ${d.dross_level_pct > 60 ? 'warn' : ''}" style="width: ${d.dross_level_pct || 28.5}%;"></div>
                </div>
                <div class="rul-sub-info">
                    <span>임펠러 펌프: ${d.pump_rpm || 1255} RPM / 속도 ${d.conveyor_speed_m_min || 1.20} m/min</span>
                    <span>드로스 누적율: ${d.dross_level_pct || 28.5}% (70% 도달 시 청소 알림)</span>
                </div>
            </div>
        `;
    } else if (processId === 'ICT') {
        sensorsHtml = `
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>테스트 핀 접촉저항</span><span class="sensor-limit">20 ~ 80 mΩ</span></div>
                <div class="sensor-val good">${d.contact_res_ohm || 45.2} mΩ</div>
            </div>
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>저항 측정 정밀도</span><span class="sensor-limit">하한 95.0%</span></div>
                <div class="sensor-val good">${d.res_accuracy_pct || 99.8} %</div>
            </div>
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>누설 전류량</span><span class="sensor-limit">상한 1.5 μA</span></div>
                <div class="sensor-val good">${d.leakage_curr_ua || 0.45} μA</div>
            </div>
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>핀 마모 진행률</span><span class="sensor-limit">상한 30%</span></div>
                <div class="sensor-val good">${d.pin_wear_pct || 12} %</div>
            </div>
        `;
        rulHtml = `
            <div class="pdm-rul-card">
                <div class="rul-top-row">
                    <span class="rul-title">테스트 핀 접촉 건전도 및 핀베드 교체 주기</span>
                    <span class="rul-days-badge">잔여 D-20 (92%)</span>
                </div>
                <div class="rul-bar-wrap">
                    <div class="rul-bar-fill" style="width: 92%;"></div>
                </div>
                <div class="rul-sub-info">
                    <span>512채널 전수 단락/오픈 검사 이상 없음</span>
                    <span>누적 접촉: 42,100 / 100,000회</span>
                </div>
            </div>
        `;
    } else if (processId === 'COATING') {
        sensorsHtml = `
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>노즐 분사 압력</span><span class="sensor-limit">0.30 ~ 0.40 MPa</span></div>
                <div class="sensor-val good">${d.dispense_press_mpa || 0.35} MPa</div>
            </div>
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>도포 피막 두께</span><span class="sensor-limit">60 ~ 90 μm</span></div>
                <div class="sensor-val good">${d.film_thickness_um || 75.0} μm</div>
            </div>
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>UV 적산 광량</span><span class="sensor-limit">1000 ~ 1500 mJ</span></div>
                <div class="sensor-val good">${d.uv_energy_mj || 1250} mJ</div>
            </div>
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>코팅액 점도</span><span class="sensor-limit">150 ~ 220 cP</span></div>
                <div class="sensor-val good">${d.fluid_viscosity_cp || 185} cP</div>
            </div>
        `;
        rulHtml = `
            <div class="pdm-rul-card">
                <div class="rul-top-row">
                    <span class="rul-title">디스펜서 분사 노즐 초음파 세척 주기</span>
                    <span class="rul-days-badge">잔여 D-12 (84%)</span>
                </div>
                <div class="rul-bar-wrap">
                    <div class="rul-bar-fill" style="width: 84%;"></div>
                </div>
                <div class="rul-sub-info">
                    <span>UV 경화 램프 광량 유지율: 98.2%</span>
                    <span>노즐 잔여 수명: 정상</span>
                </div>
            </div>
        `;
    } else if (processId === 'FCT') {
        sensorsHtml = `
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>MCU 인가 전압</span><span class="sensor-limit">4.85 ~ 5.15 V</span></div>
                <div class="sensor-val good">${d.mcu_volt_v || 5.02} V</div>
            </div>
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>총 소비 전류</span><span class="sensor-limit">120 ~ 170 mA</span></div>
                <div class="sensor-val good">${d.curr_draw_ma || 142.5} mA</div>
            </div>
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>CAN 통신 응답시간</span><span class="sensor-limit">2.0 ~ 8.0 ms</span></div>
                <div class="sensor-val good">${d.can_resp_ms || 4.8} ms</div>
            </div>
            <div class="pdm-sensor-card">
                <div class="sensor-header"><span>펌웨어 검증 점수</span><span class="sensor-limit">하한 90점</span></div>
                <div class="sensor-val good">${d.fw_check_score || 100} pts</div>
            </div>
        `;
        rulHtml = `
            <div class="pdm-rul-card">
                <div class="rul-top-row">
                    <span class="rul-title">FCT 테스트 지그 전원 릴레이 및 커넥터 교정</span>
                    <span class="rul-days-badge">잔여 D-30 (95%)</span>
                </div>
                <div class="rul-bar-wrap">
                    <div class="rul-bar-fill" style="width: 95%;"></div>
                </div>
                <div class="rul-sub-info">
                    <span>CAN 통신 패킷 손실률: 0.00%</span>
                    <span>완제품 최종 합격률: 99.7%</span>
                </div>
            </div>
        `;
    }

    if (sensorsGrid) sensorsGrid.innerHTML = sensorsHtml;
    if (rulSection) rulSection.innerHTML = rulHtml;

    if (recommendationEl) {
        recommendationEl.innerText = d.recommendation || '설비 센서 및 구동 모터 파라미터가 관리한계선(UCL/LCL) 내에서 양호하게 유지되고 있습니다.';
    }

    drawExpandedModalChart(processId);
}

// 8. 모달 대형 실시간 파라미터 관리도(SPC/UCL/LCL) 차트 드로잉
function drawExpandedModalChart(processId) {
    const canvas = document.getElementById('pdmModalCanvas');
    if (!canvas) return;

    const ctx = canvas.getContext('2d');
    const w = canvas.width;
    const h = canvas.height;
    ctx.clearRect(0, 0, w, h);

    const history = pdmHistory[processId] || [];
    if (history.length < 2) {
        ctx.fillStyle = '#64748b';
        ctx.font = '12px Inter, sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('공정 가동 중 실시간 텔레메트리 수집 대기 중...', w / 2, h / 2);
        return;
    }

    const min = Math.min(...history);
    const max = Math.max(...history);
    const range = (max - min) === 0 ? 2 : (max - min) * 1.3;
    const padding = 20;

    const yCenter = h / 2;
    const yUcl = padding;
    const yLcl = h - padding;

    ctx.strokeStyle = 'rgba(255, 255, 255, 0.08)';
    ctx.lineWidth = 1;
    ctx.setLineDash([4, 4]);

    // UCL
    ctx.beginPath();
    ctx.moveTo(padding, yUcl);
    ctx.lineTo(w - padding, yUcl);
    ctx.stroke();

    // Center
    ctx.beginPath();
    ctx.moveTo(padding, yCenter);
    ctx.lineTo(w - padding, yCenter);
    ctx.stroke();

    // LCL
    ctx.beginPath();
    ctx.moveTo(padding, yLcl);
    ctx.lineTo(w - padding, yLcl);
    ctx.stroke();

    ctx.setLineDash([]);

    ctx.fillStyle = '#64748b';
    ctx.font = '9px JetBrains Mono, monospace';
    ctx.textAlign = 'right';
    ctx.fillText('UCL', w - 4, yUcl + 3);
    ctx.fillText('CL', w - 4, yCenter + 3);
    ctx.fillText('LCL', w - 4, yLcl + 3);

    const points = history.map((val, idx) => {
        const x = padding + (idx / (history.length - 1)) * (w - padding * 2 - 20);
        const y = h - padding - ((val - min) / range) * (h - padding * 2);
        return { x, y, val };
    });

    ctx.beginPath();
    ctx.moveTo(points[0].x, points[0].y);
    for (let i = 1; i < points.length; i++) {
        const xc = (points[i - 1].x + points[i].x) / 2;
        const yc = (points[i - 1].y + points[i].y) / 2;
        ctx.quadraticCurveTo(points[i - 1].x, points[i - 1].y, xc, yc);
    }
    ctx.lineTo(points[points.length - 1].x, points[points.length - 1].y);
    ctx.strokeStyle = '#38bdf8';
    ctx.lineWidth = 2.2;
    ctx.stroke();

    points.forEach((p, idx) => {
        ctx.beginPath();
        ctx.arc(p.x, p.y, 3, 0, Math.PI * 2);
        ctx.fillStyle = '#0f172a';
        ctx.fill();
        ctx.strokeStyle = '#38bdf8';
        ctx.lineWidth = 2;
        ctx.stroke();

        if (idx >= points.length - 3) {
            ctx.fillStyle = '#f1f5f9';
            ctx.font = '10px JetBrains Mono, monospace';
            ctx.textAlign = 'center';
            ctx.fillText(p.val, p.x, p.y - 8);
        }
    });
}

// 9. 실시간 고속 폴링 루프 (0.8초 주기)
async function pollLiveStream() {
    if (isPollingActive) return;
    isPollingActive = true;

    try {
        const url = `/backend/api/get_live_logs.php?last_id=${lastHistoryId}`;
        const res = await fetch(url);
        const json = await res.json();

        if (json.status === 'success' && json.data) {
            const logs = json.data.logs || [];
            const activeWo = json.data.active_wo || null;

            // 라인 상태 분리: SMT_DONE 또는 DIP_IN_PROGRESS 상태일 때 SMT 라인 설비는 항상 대기(READY/IDLE) 보장
            const smtIds = ['LASER', 'SPI', 'MOUNTER_1', 'MOUNTER_2', 'REFLOW'];
            const dipIds = ['DIP_AOI', 'WAVE', 'ICT', 'COATING', 'FCT'];

            if (activeWo && (activeWo.status === 'SMT_DONE' || activeWo.status === 'DIP_IN_PROGRESS')) {
                smtIds.forEach(id => {
                    const mac = document.getElementById(`mac-${id}`);
                    if (mac && !mac.classList.contains('wait')) {
                        updateMachine(id, 'IDLE', '-', null);
                    }
                });
            } else if (activeWo && activeWo.status === 'IN_PROGRESS') {
                dipIds.forEach(id => {
                    const mac = document.getElementById(`mac-${id}`);
                    if (mac && !mac.classList.contains('wait')) {
                        updateMachine(id, 'IDLE', '-', null);
                    }
                });
            } else if (activeWo && activeWo.status === 'DONE') {
                resetAllMachines('ALL');
            }

            if (logs.length > 0) {
                logs.forEach(item => {
                    let proc = item.process_name;
                    if (proc === 'MOUNTER') proc = 'MOUNTER_1';
                    const isPass = item.result_status;
                    const barcode = item.barcode;
                    const pDataStr = item.process_data;
                    let pDataObj = null;
                    try {
                        pDataObj = pDataStr ? JSON.parse(pDataStr) : null;
                    } catch (err) {
                        pDataObj = null;
                    }
                    
                    // 수삽 진행 중이거나 SMT 완료 상태일 때 들어오는 SMT 이벤트는 머신 카드를 run으로 바꾸지 않음
                    if (activeWo && (activeWo.status === 'SMT_DONE' || activeWo.status === 'DIP_IN_PROGRESS') && smtIds.includes(proc)) {
                        updateMachine(proc, 'IDLE', '-', null);
                        return;
                    }
                    // 자삽 진행 중일 때 들어오는 DIP 이벤트는 머신 카드를 run으로 바꾸지 않음
                    if (activeWo && activeWo.status === 'IN_PROGRESS' && dipIds.includes(proc)) {
                        updateMachine(proc, 'IDLE', '-', null);
                        return;
                    }

                    if (isPass === 'IDLE' || isPass === 'WAIT' || !barcode || barcode === '-') {
                        updateMachine(proc, 'IDLE', '-', null);
                    } else {
                        updateMachine(proc, isPass, barcode, pDataObj);
                        addLog(proc, isPass, `[${item.barcode_status || 'ING'}] 바코드: ${barcode} ${pDataStr ? JSON.stringify(pDataObj) : ''}`);
                    }
                    
                    if (item.target_qty) {
                        currentTarget = parseInt(item.target_qty);
                        const elT = document.getElementById('val-target');
                        if (elT) elT.innerText = currentTarget;
                    }
                });

                lastHistoryId = json.data.max_id;
            }
        }

        await syncKPI();

    } catch (e) {
        console.error("실시간 스트림 폴링 오류:", e);
    } finally {
        isPollingActive = false;
    }
}

function checkEmbedMode() {
    const urlParams = new URLSearchParams(window.location.search);
    const embedLine = urlParams.get('embed_line');
    if (embedLine) {
        document.body.classList.add('embed-mode');
        const smtPanel = document.getElementById('linePanel-SMT');
        const dipPanel = document.getElementById('linePanel-DIP');
        if (embedLine === '1') {
            if (dipPanel) dipPanel.style.display = 'none';
            if (smtPanel) smtPanel.style.display = 'block';
        } else if (embedLine === '2') {
            if (smtPanel) smtPanel.style.display = 'none';
            if (dipPanel) dipPanel.style.display = 'block';
        }
    }
}

// 10. 초기화 및 실시간 루프 시작
document.addEventListener('DOMContentLoaded', () => {
    checkEmbedMode();
    initIdleHistories();
    startIdleAmbientLoop();
});

// 즉시 실행 (DOMContentLoaded 이전 로드 대응)
checkEmbedMode();
initIdleHistories();
startIdleAmbientLoop();

syncKPI().then(() => {
    pollLiveStream();
    setInterval(pollLiveStream, 600);
});

// 화면 크기 변경 및 요소 리사이즈 시 고해상도 즉시 재렌더링
function renderAllTelemetryGauges() {
    Object.keys(MACHINE_TELEMETRY_SCHEMAS).forEach(id => {
        const state = machineCurrentState[id];
        if (state) {
            drawRadialGauges(id, state.health, state.cycleVal, state.cycleText, state.cycleSub, state.status);
            drawVerticalBars(id, state.bars, state.status);
        }
    });
}

window.addEventListener('resize', renderAllTelemetryGauges);

// ResizeObserver로 머신 카드 및 라인 컨테이너 크기 변화 정밀 감지 (27"/15"/8" 뷰포트 전환 대응)
if (window.ResizeObserver) {
    let resizeTimer = null;
    const ro = new ResizeObserver(() => {
        if (resizeTimer) cancelAnimationFrame(resizeTimer);
        resizeTimer = requestAnimationFrame(renderAllTelemetryGauges);
    });
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.machine-card, .line-panel, .lines-wrapper').forEach(el => ro.observe(el));
    });
}
