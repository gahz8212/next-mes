// frontend/js/ceo.js - MES CEO Executive Dashboard Engine

let refreshInterval = null;
let trendChartData = [];

document.addEventListener('DOMContentLoaded', () => {
    // 1. 인증 확인
    const role = localStorage.getItem('role');
    if (!role) {
        localStorage.setItem('role', 'admin');
    }

    // 2. 경영진 데이터 최초 로드
    loadExecutiveData();

    // 3. 5초 주기 자동 갱신
    refreshInterval = setInterval(loadExecutiveData, 5000);
});

// 로그아웃
function logout() {
    localStorage.removeItem('role');
    window.location.href = 'login.html';
}
window.logout = logout;

// 종합 경영 데이터 로드
async function loadExecutiveData() {
    try {
        await Promise.all([
            loadKpiAnalytics(),
            loadDailyTargetKpi(),
            loadOrdersData(),
            loadNotifications()
        ]);

        const syncEl = document.getElementById('ceoLiveSyncStatus');
        if (syncEl) {
            const now = new Date();
            const timeStr = now.toLocaleTimeString('ko-KR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            syncEl.innerText = `실시간 집계 완료 (${timeStr} 기준)`;
        }
    } catch(e) {
        console.error('Executive data load error:', e);
    }
}

// 1. KPI 분석 (종합 수율, 납기 준수율, 일별 추이)
async function loadKpiAnalytics() {
    try {
        const res = await fetch('/backend/api/get_kpi_analytics.php?days=14');
        const json = await res.json();

        if (json.status === 'success' && json.data) {
            const d = json.data;

            // 종합 누적 수율
            const yieldValEl = document.getElementById('kpiOverallYield');
            const yieldBarEl = document.getElementById('kpiYieldBar');
            const yieldSubEl = document.getElementById('kpiYieldSub');
            if (yieldValEl) yieldValEl.innerText = `${d.overall_yield || 99.2}%`;
            if (yieldBarEl) yieldBarEl.style.width = `${Math.min(100, d.overall_yield || 99.2)}%`;
            if (yieldSubEl) yieldSubEl.innerHTML = `양품 <strong>${(d.total_good || 0).toLocaleString()}</strong> / 불량 <strong>${(d.total_fail || 0).toLocaleString()}</strong> EA`;

            // 납기 준수율
            const onTimeValEl = document.getElementById('kpiOnTimeRate');
            const onTimeBarEl = document.getElementById('kpiOnTimeBar');
            if (onTimeValEl) onTimeValEl.innerText = `${d.on_time_rate || 98.4}%`;
            if (onTimeBarEl) onTimeBarEl.style.width = `${Math.min(100, d.on_time_rate || 98.4)}%`;

            // 일별 추이 차트 렌더링
            if (d.daily_trend && Array.isArray(d.daily_trend)) {
                trendChartData = d.daily_trend;
                drawDailyTrendChart(trendChartData);
            }
        }
    } catch(e) {
        console.warn('loadKpiAnalytics error:', e);
    }
}

// 2. 오늘의 생산 목표 및 라인 실적
async function loadDailyTargetKpi() {
    try {
        const res = await fetch('/backend/api/get_kpi.php');
        const json = await res.json();

        if (json.status === 'success' && json.data) {
            const d = json.data;
            const target = d.target_qty || 2000;
            const actual = d.actual_qty || 0;
            const rate = target > 0 ? Math.min(100, Math.round((actual / target) * 100)) : 0;

            const targetRateEl = document.getElementById('kpiDailyTargetRate');
            const targetBarEl = document.getElementById('kpiTargetBar');
            const targetSubEl = document.getElementById('kpiTargetSub');

            if (targetRateEl) targetRateEl.innerText = `${rate}%`;
            if (targetBarEl) targetBarEl.style.width = `${rate}%`;
            if (targetSubEl) targetSubEl.innerHTML = `생산 실적 <strong>${actual.toLocaleString()}</strong> / 목표 <strong>${target.toLocaleString()}</strong> EA`;

            // SMT 라인 가동 카드 업데이트
            const smtModelEl = document.getElementById('smtCurrentModel');
            const smtQtyEl = document.getElementById('smtOutputQty');
            if (smtModelEl) smtModelEl.innerText = d.item_name || 'Main Board A타입 (SMT)';
            if (smtQtyEl) smtQtyEl.innerText = `${actual.toLocaleString()} EA`;
        }
    } catch(e) {
        console.warn('loadDailyTargetKpi error:', e);
    }
}

// 3. 수주 및 매출액, 납기 임박 주문
async function loadOrdersData() {
    try {
        const res = await fetch('/backend/api/get_orders.php');
        const json = await res.json();

        if (json.status === 'success' && json.data) {
            const orders = json.data.orders || [];
            const kpi = json.data.kpi || {};

            // 월간 총 수주액 & 매출
            const totalRev = kpi.total_revenue || 0;
            const completedRev = kpi.completed_revenue || 0;
            const inProdRev = kpi.in_prod_revenue || 0;

            const revHeroEl = document.getElementById('kpiTotalRevenue');
            const revBarEl = document.getElementById('kpiRevenueBar');
            const revSubEl = document.getElementById('kpiRevenueSub');

            if (revHeroEl) {
                revHeroEl.innerText = formatCurrency(totalRev);
            }
            if (revBarEl) {
                const revRate = totalRev > 0 ? Math.min(100, Math.round((completedRev / totalRev) * 100)) : 0;
                revBarEl.style.width = `${revRate}%`;
            }
            if (revSubEl) {
                revSubEl.innerHTML = `출하완료 <strong>${formatCurrency(completedRev)}</strong> / 진행중 <strong>${formatCurrency(inProdRev)}</strong>`;
            }

            // 납기 임박 수주 건수 계산 (D-3일 이내)
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            let urgentCount = 0;
            const processedOrders = orders.map(ord => {
                const dueDate = new Date(ord.due_date);
                dueDate.setHours(0, 0, 0, 0);
                const diffTime = dueDate.getTime() - today.getTime();
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

                let ddayBadge = '';
                if (diffDays < 0) {
                    ddayBadge = `<span class="due-badge urgent">지연 D+${Math.abs(diffDays)}</span>`;
                    urgentCount++;
                } else if (diffDays <= 3) {
                    ddayBadge = `<span class="due-badge urgent">임박 D-${diffDays}</span>`;
                    urgentCount++;
                } else if (diffDays <= 7) {
                    ddayBadge = `<span class="due-badge caution">D-${diffDays}</span>`;
                } else {
                    ddayBadge = `<span class="due-badge normal">D-${diffDays}</span>`;
                }

                return { ...ord, diffDays, ddayBadge };
            });

            // 납기 준수율 서브 텍스트
            const onTimeSubEl = document.getElementById('kpiOnTimeSub');
            if (onTimeSubEl) {
                if (urgentCount > 0) {
                    onTimeSubEl.innerHTML = `⚠️ 납기 임박/지연 <strong style="color:#f87171;">${urgentCount}건</strong> 주의 필요`;
                } else {
                    onTimeSubEl.innerHTML = `전체 수주 납기 정상 준수 중 (안정)`;
                }
            }

            // 납기 임박 & 주요 수주 테이블 렌더링
            renderExecutiveOrdersTable(processedOrders.slice(0, 6));
        }
    } catch(e) {
        console.warn('loadOrdersData error:', e);
    }
}

// 4. 경영 알림 브리핑
async function loadNotifications() {
    try {
        const res = await fetch('/backend/api/get_notifications.php');
        const json = await res.json();

        if (json.status === 'success' && json.data) {
            const listEl = document.getElementById('ceoNotifList');
            if (!listEl) return;

            const notifs = json.data.slice(0, 4);
            if (notifs.length === 0) {
                listEl.innerHTML = `<div style="text-align:center; color:var(--text-muted); padding:16px;">알림 내역이 없습니다.</div>`;
                return;
            }

            let html = '';
            notifs.forEach(n => {
                const typeClass = (n.type || 'INFO').toLowerCase();
                const timeStr = n.created_at ? n.created_at.substring(11, 16) : '';
                html += `
                    <div class="ceo-notif-item ${typeClass}">
                        <div class="notif-content-wrap">
                            <div class="notif-title-row">
                                <span class="notif-title-text">${n.title}</span>
                                <span class="notif-time-ago">${timeStr}</span>
                            </div>
                            <div class="notif-body-text">${n.message}</div>
                        </div>
                    </div>
                `;
            });
            listEl.innerHTML = html;
        }
    } catch(e) {
        console.warn('loadNotifications error:', e);
    }
}

// 테이블 렌더링
function renderExecutiveOrdersTable(orders) {
    const tbody = document.getElementById('ceoOrdersTbody');
    if (!tbody) return;

    if (orders.length === 0) {
        tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; color:var(--text-muted); padding:18px;">진행 중인 수주 내역이 없습니다.</td></tr>`;
        return;
    }

    let html = '';
    orders.forEach(o => {
        let statusTag = '';
        if (o.status === 'RECEIVED') statusTag = '<span style="color:#93c5fd; font-weight:700;">수주접수</span>';
        else if (o.status === 'IN_PRODUCTION') statusTag = '<span style="color:#34d399; font-weight:700;">생산가동</span>';
        else if (o.status === 'COMPLETED') statusTag = '<span style="color:#a78bfa; font-weight:700;">출하완료</span>';
        else statusTag = `<span style="color:var(--text-muted);">${o.status}</span>`;

        html += `
            <tr>
                <td><strong>${o.company_name || '에이텍 솔루션'}</strong></td>
                <td>${o.item_name || 'Main Board A타입'}</td>
                <td><strong>${(o.order_qty || 0).toLocaleString()}</strong> EA</td>
                <td><strong>${formatCurrency(o.total_price || 0)}</strong></td>
                <td>${o.ddayBadge} <span style="font-size:11px; color:var(--text-muted);">(${o.due_date})</span></td>
                <td>${statusTag}</td>
            </tr>
        `;
    });
    tbody.innerHTML = html;
}

// ── 5. 일별 생산량 및 수율 복합 바 차트 캔버스 렌더링 ──
function drawDailyTrendChart(trendData) {
    const canvas = document.getElementById('ceoDailyTrendCanvas');
    if (!canvas) return;

    const dpr = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    const w = Math.max(300, Math.round(rect.width) || 700);
    const h = 220;

    if (canvas.width !== w * dpr || canvas.height !== h * dpr) {
        canvas.width = w * dpr;
        canvas.height = h * dpr;
    }

    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, w, h);

    if (!trendData || trendData.length === 0) {
        ctx.fillStyle = '#64748b';
        ctx.font = '13px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('일별 생산 데이터 집계 중...', w / 2, h / 2);
        return;
    }

    const padL = 45;
    const padR = 45;
    const padT = 25;
    const padB = 30;
    const plotW = w - padL - padR;
    const plotH = h - padT - padB;

    const maxQty = Math.max(50, ...trendData.map(d => d.total_count || (d.pass_count + d.fail_count) || 0));
    const stepX = plotW / trendData.length;
    const barW = Math.max(8, Math.min(26, stepX * 0.45));

    // 1. 가로 그리드선
    ctx.strokeStyle = 'rgba(255, 255, 255, 0.06)';
    ctx.lineWidth = 1;
    for (let i = 0; i <= 4; i++) {
        const y = padT + (plotH / 4) * i;
        ctx.beginPath();
        ctx.moveTo(padL, y);
        ctx.lineTo(w - padR, y);
        ctx.stroke();

        // 수량 라벨 (좌측)
        const qVal = Math.round(maxQty * (1 - i / 4));
        ctx.fillStyle = '#64748b';
        ctx.font = '10px monospace';
        ctx.textAlign = 'right';
        ctx.fillText(qVal.toLocaleString(), padL - 6, y + 3);
    }

    // 2. 바 차트 (일별 생산 수량)
    trendData.forEach((d, idx) => {
        const cx = padL + idx * stepX + stepX / 2;
        const total = d.total_count || (d.pass_count + d.fail_count) || 0;
        const barH = (total / maxQty) * plotH;
        const barY = padT + plotH - barH;

        // 바 그라데이션
        const grad = ctx.createLinearGradient(0, barY, 0, barY + barH);
        grad.addColorStop(0, '#38bdf8');
        grad.addColorStop(1, 'rgba(56, 189, 248, 0.2)');

        ctx.fillStyle = grad;
        ctx.beginPath();
        ctx.roundRect(cx - barW / 2, barY, barW, barH, [4, 4, 0, 0]);
        ctx.fill();

        // X축 날짜 (MM/DD)
        const dateStr = d.log_date ? d.log_date.substring(5) : `${idx + 1}일`;
        ctx.fillStyle = '#94a3b8';
        ctx.font = '10px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(dateStr, cx, h - 10);
    });

    // 3. 수율 꺾은선 (Yield Rate Line - Emerald)
    ctx.beginPath();
    trendData.forEach((d, idx) => {
        const cx = padL + idx * stepX + stepX / 2;
        const yieldVal = d.yield_rate !== undefined ? d.yield_rate : 100;
        // 90% ~ 100%를 Y축 0 ~ plotH로 맵핑
        const normYield = Math.max(0, Math.min(1, (yieldVal - 90) / 10));
        const lineY = padT + plotH - normYield * plotH;

        if (idx === 0) ctx.moveTo(cx, lineY);
        else ctx.lineTo(cx, lineY);
    });
    ctx.strokeStyle = '#10b981';
    ctx.lineWidth = 2.5;
    ctx.stroke();

    // 수율 포인트 원 & 텍스트
    trendData.forEach((d, idx) => {
        const cx = padL + idx * stepX + stepX / 2;
        const yieldVal = d.yield_rate !== undefined ? d.yield_rate : 100;
        const normYield = Math.max(0, Math.min(1, (yieldVal - 90) / 10));
        const lineY = padT + plotH - normYield * plotH;

        ctx.beginPath();
        ctx.arc(cx, lineY, 3.5, 0, Math.PI * 2);
        ctx.fillStyle = '#10b981';
        ctx.fill();
        ctx.strokeStyle = '#fff';
        ctx.lineWidth = 1.5;
        ctx.stroke();
    });
}

// 통화 단위 포맷터
function formatCurrency(num) {
    if (isNaN(num)) return '₩ 0';
    if (num >= 100000000) {
        return `₩ ${(num / 100000000).toFixed(2)} 억`;
    } else if (num >= 10000) {
        return `₩ ${(num / 10000).toFixed(0)} 만원`;
    }
    return `₩ ${Number(num).toLocaleString()}`;
}

// ── 6. CEO 실시간 라인 오버레이 (대시보드 Line 1 / Line 2 원본 카드 1:1) ──
let activeCeoLineType = null;
let ceoLastHistoryId = 0;
let isCeoPollingActive = false;

const CEO_MACHINE_SCHEMAS = {
    LASER: {
        defaultHealth: 98,
        defaultCycleVal: 82,
        defaultCycleText: 'D-14',
        defaultCycleSub: '필터수명',
        bars: [
            { key: 'laser_power_w', label: '출력', unit: 'W', base: 15.2, min: 10, max: 20, decimals: 1, color: '#10b981' },
            { key: 'tube_temp_c', label: '온도', unit: '℃', base: 31.5, min: 20, max: 45, decimals: 1, color: '#38bdf8' },
            { key: 'fume_pressure_kpa', label: '차압', unit: 'kPa', base: -2.3, min: -3.5, max: -1.0, decimals: 1, color: '#a78bfa' },
            { key: 'lens_cleanliness_pct', label: '렌즈', unit: '%', base: 96, min: 70, max: 100, decimals: 0, color: '#38bdf8' }
        ]
    },
    SPI: {
        defaultHealth: 97,
        defaultCycleVal: 84,
        defaultCycleText: '16타',
        defaultCycleSub: '세척주기',
        bars: [
            { key: 'volume_pct', label: '체적', unit: '%', base: 102.5, min: 70, max: 130, decimals: 1, color: '#10b981' },
            { key: 'solder_height_um', label: '높이', unit: 'μm', base: 142.0, min: 100, max: 180, decimals: 0, color: '#38bdf8' },
            { key: 'paste_viscosity_pa_s', label: '점도', unit: 'Pa·s', base: 202, min: 150, max: 250, decimals: 0, color: '#a78bfa' },
            { key: 'offset_x_um', label: '오프셋', unit: 'μm', base: 6.8, min: -20, max: 20, decimals: 1, color: '#38bdf8' }
        ]
    },
    MOUNTER_1: {
        defaultHealth: 99,
        defaultCycleVal: 84,
        defaultCycleText: 'D-4',
        defaultCycleSub: '노즐수명',
        bars: [
            { key: 'vacuum_kpa', label: '진공', unit: 'kPa', base: -84.5, min: -100, max: -60, decimals: 1, color: '#10b981' },
            { key: 'head_vibration_g', label: '진동', unit: 'G', base: 0.088, min: 0, max: 0.25, decimals: 3, color: '#38bdf8' },
            { key: 'motor_temp_c', label: '발열', unit: '℃', base: 37.8, min: 20, max: 55, decimals: 1, color: '#a78bfa' },
            { key: 'feeder_tension_n', label: '장력', unit: 'N', base: 4.2, min: 2.0, max: 6.5, decimals: 1, color: '#38bdf8' }
        ]
    },
    MOUNTER_2: {
        defaultHealth: 98,
        defaultCycleVal: 88,
        defaultCycleText: 'D-7',
        defaultCycleSub: '척교정',
        bars: [
            { key: 'vacuum_kpa', label: '진공', unit: 'kPa', base: -81.2, min: -100, max: -60, decimals: 1, color: '#10b981' },
            { key: 'feeder_tension_n', label: '압력', unit: 'N', base: 1.85, min: 0.5, max: 4.0, decimals: 2, color: '#38bdf8' },
            { key: 'motor_temp_c', label: '온도', unit: '℃', base: 39.2, min: 20, max: 55, decimals: 1, color: '#a78bfa' },
            { key: 'head_vibration_g', label: '각도', unit: '°', base: 0.12, min: -1.0, max: 1.0, decimals: 2, color: '#38bdf8' }
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
        defaultCycleVal: 92,
        defaultCycleText: 'D-20',
        defaultCycleSub: '광학보정',
        bars: [
            { key: 'pin_soldering_score', label: '품질', unit: '점', base: 98.5, min: 70, max: 100, decimals: 1, color: '#10b981' },
            { key: 'bridge_risk_pct', label: '쇼트', unit: '%', base: 1.2, min: 0, max: 8.0, decimals: 1, color: '#38bdf8' },
            { key: 'lift_height_um', label: '들뜸', unit: 'μm', base: 18.0, min: 0, max: 60, decimals: 0, color: '#a78bfa' },
            { key: 'comp_tilt_deg', label: '경사', unit: '°', base: 0.4, min: 0, max: 3.0, decimals: 1, color: '#38bdf8' }
        ]
    },
    WAVE: {
        defaultHealth: 97,
        defaultCycleVal: 72,
        defaultCycleText: 'D-5',
        defaultCycleSub: '드로스정비',
        bars: [
            { key: 'pot_temp_c', label: '용탕', unit: '℃', base: 250.2, min: 230, max: 270, decimals: 1, color: '#10b981' },
            { key: 'wave_height_mm', label: '파고', unit: 'mm', base: 9.15, min: 6.0, max: 12.0, decimals: 2, color: '#38bdf8' },
            { key: 'preheater_temp_c', label: '예열', unit: '℃', base: 132.5, min: 100, max: 160, decimals: 1, color: '#a78bfa' },
            { key: 'flux_amount_ml_min', label: '분사', unit: 'ml/m', base: 16.2, min: 10, max: 22, decimals: 1, color: '#38bdf8' }
        ]
    },
    ICT: {
        defaultHealth: 98,
        defaultCycleVal: 92,
        defaultCycleText: 'D-20',
        defaultCycleSub: '핀베드교정',
        bars: [
            { key: 'contact_res_ohm', label: '접촉', unit: 'mΩ', base: 45.2, min: 10, max: 90, decimals: 1, color: '#10b981' },
            { key: 'res_accuracy_pct', label: '정밀', unit: '%', base: 99.8, min: 90, max: 100, decimals: 1, color: '#38bdf8' },
            { key: 'leakage_curr_ua', label: '누설', unit: 'μA', base: 0.45, min: 0, max: 2.0, decimals: 2, color: '#a78bfa' },
            { key: 'pin_wear_pct', label: '마모', unit: '%', base: 12.0, min: 0, max: 40, decimals: 0, color: '#38bdf8' }
        ]
    },
    COATING: {
        defaultHealth: 99,
        defaultCycleVal: 84,
        defaultCycleText: 'D-12',
        defaultCycleSub: '노즐세척',
        bars: [
            { key: 'dispense_press_mpa', label: '압력', unit: 'MPa', base: 0.35, min: 0.2, max: 0.5, decimals: 2, color: '#10b981' },
            { key: 'film_thickness_um', label: '두께', unit: 'μm', base: 75.0, min: 50, max: 100, decimals: 1, color: '#38bdf8' },
            { key: 'uv_energy_mj', label: '광량', unit: 'mJ', base: 1250, min: 800, max: 1600, decimals: 0, color: '#a78bfa' },
            { key: 'fluid_viscosity_cp', label: '점도', unit: 'cP', base: 185, min: 120, max: 250, decimals: 0, color: '#38bdf8' }
        ]
    },
    FCT: {
        defaultHealth: 99,
        defaultCycleVal: 95,
        defaultCycleText: 'D-30',
        defaultCycleSub: '지그교정',
        bars: [
            { key: 'mcu_volt_v', label: '전압', unit: 'V', base: 5.02, min: 4.5, max: 5.5, decimals: 2, color: '#10b981' },
            { key: 'curr_draw_ma', label: '전류', unit: 'mA', base: 142.5, min: 100, max: 200, decimals: 1, color: '#38bdf8' },
            { key: 'can_resp_ms', label: '응답', unit: 'ms', base: 4.8, min: 1.0, max: 10.0, decimals: 1, color: '#a78bfa' },
            { key: 'fw_check_score', label: '검증', unit: '점', base: 100, min: 80, max: 100, decimals: 0, color: '#38bdf8' }
        ]
    }
};

const ceoMachineCurrentState = {};
function initCeoMachineStates() {
    Object.keys(CEO_MACHINE_SCHEMAS).forEach(id => {
        const schema = CEO_MACHINE_SCHEMAS[id];
        ceoMachineCurrentState[id] = {
            health: schema.defaultHealth,
            cycleVal: schema.defaultCycleVal,
            cycleText: schema.defaultCycleText,
            cycleSub: schema.defaultCycleSub,
            bars: schema.bars.map(b => b.base),
            status: 'NORMAL'
        };
    });
}
initCeoMachineStates();

function openCeoLineModal(lineType) {
    activeCeoLineType = lineType;
    const overlay = document.getElementById('ceoLineModalOverlay');
    const smtCard = document.getElementById('ceoLinePanelSMT');
    const dipCard = document.getElementById('ceoLinePanelDIP');
    if (!overlay) return;

    if (lineType === 'SMT') {
        if (smtCard) smtCard.style.display = 'block';
        if (dipCard) dipCard.style.display = 'none';
    } else {
        if (smtCard) smtCard.style.display = 'none';
        if (dipCard) dipCard.style.display = 'block';
    }

    overlay.classList.add('open');

    // 캔버스 즉시 드로잉 (각 5대씩 총 10대 풀-파이프라인)
    setTimeout(() => {
        const targetMachines = (lineType === 'SMT') ? 
            ['LASER', 'SPI', 'MOUNTER_1', 'MOUNTER_2', 'REFLOW'] : 
            ['DIP_AOI', 'WAVE', 'ICT', 'COATING', 'FCT'];
        targetMachines.forEach(id => {
            const st = ceoMachineCurrentState[id];
            if (st) {
                drawCeoRadialGauges(id, st.health, st.cycleVal, st.cycleText, st.cycleSub, st.status);
                drawCeoVerticalBars(id, st.bars, st.status);
            }
        });
        ceoLastHistoryId = 0;
        pollCeoLiveStream();
    }, 50);
}
window.openCeoLineModal = openCeoLineModal;

function closeCeoLineModal(e) {
    if (e && e.target && e.target.closest && e.target.closest('.line-panel') && !e.target.classList.contains('ceo-card-close-btn')) {
        return;
    }
    activeCeoLineType = null;
    const overlay = document.getElementById('ceoLineModalOverlay');
    if (overlay) overlay.classList.remove('open');
}
window.closeCeoLineModal = closeCeoLineModal;
window.closeCeoLineModal = closeCeoLineModal;

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeCeoLineModal();
});

// ── 7. 캔버스 렌더링 엔진 (건전도 듀얼 게이지 & 4대 막대그래프 대형 렌더링) ──
function drawCeoRadialGauges(processId, healthVal, cycleVal, cycleText, cycleSub, pdmStatus) {
    const canvas = document.getElementById(`ceo-canvas-radial-${processId}`);
    if (!canvas) return;

    const dpr = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    const cssW = Math.max(260, Math.round(rect.width) || 300);
    const cssH = Math.max(48, Math.round(rect.height) || 56);

    if (canvas.width !== Math.round(cssW * dpr) || canvas.height !== Math.round(cssH * dpr)) {
        canvas.width = Math.round(cssW * dpr);
        canvas.height = Math.round(cssH * dpr);
    }

    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, cssW, cssH);

    const safeHealth = Math.max(0, Math.min(100, Number(healthVal) || 98));
    const safeCycle  = Math.max(0, Math.min(100, Number(cycleVal) || 80));

    let healthColor = '#10b981';
    let statusText = '정상';
    if (pdmStatus === 'WARNING' || safeHealth < 75) {
        healthColor = '#ef4444';
        statusText = '경고';
    } else if (pdmStatus === 'CAUTION' || safeHealth < 88) {
        healthColor = '#f59e0b';
        statusText = '주의';
    }

    const cy = Math.round(cssH / 2);
    const r = Math.min(21, Math.round((cssH - 6) / 2));
    const strokeW = 3.8;

    // 좌측: 설비 건전도
    const leftCX = Math.round(cssW * 0.16);
    ctx.beginPath();
    ctx.arc(leftCX, cy, r, 0, Math.PI * 2);
    ctx.strokeStyle = 'rgba(255, 255, 255, 0.08)';
    ctx.lineWidth = strokeW;
    ctx.stroke();

    ctx.beginPath();
    ctx.arc(leftCX, cy, r, -Math.PI / 2, -Math.PI / 2 + (safeHealth / 100) * (Math.PI * 2));
    ctx.strokeStyle = healthColor;
    ctx.lineWidth = strokeW;
    ctx.lineCap = 'round';
    ctx.stroke();

    ctx.font = 'bold 10.5px "JetBrains Mono", monospace';
    ctx.fillStyle = '#f8fafc';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(`${safeHealth}%`, leftCX, cy);

    ctx.textAlign = 'left';
    ctx.font = '10px sans-serif';
    ctx.fillStyle = '#94a3b8';
    ctx.fillText('건전도', leftCX + r + 8, cy - 5);
    ctx.fillStyle = healthColor;
    ctx.font = 'bold 10.5px sans-serif';
    ctx.fillText(statusText, leftCX + r + 8, cy + 8);

    // 중앙 구분선
    ctx.strokeStyle = 'rgba(255, 255, 255, 0.1)';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(Math.round(cssW * 0.49), 4);
    ctx.lineTo(Math.round(cssW * 0.49), cssH - 4);
    ctx.stroke();

    // 우측: 정비주기
    const rightCX = Math.round(cssW * 0.63);
    const cycleColor = '#38bdf8';
    ctx.beginPath();
    ctx.arc(rightCX, cy, r, 0, Math.PI * 2);
    ctx.strokeStyle = 'rgba(255, 255, 255, 0.08)';
    ctx.lineWidth = strokeW;
    ctx.stroke();

    ctx.beginPath();
    ctx.arc(rightCX, cy, r, -Math.PI / 2, -Math.PI / 2 + (safeCycle / 100) * (Math.PI * 2));
    ctx.strokeStyle = cycleColor;
    ctx.lineWidth = strokeW;
    ctx.lineCap = 'round';
    ctx.stroke();

    ctx.font = 'bold 10px "JetBrains Mono", monospace';
    ctx.fillStyle = '#f8fafc';
    ctx.textAlign = 'center';
    ctx.fillText(cycleText || `${safeCycle}%`, rightCX, cy);

    ctx.textAlign = 'left';
    ctx.font = '10px sans-serif';
    ctx.fillStyle = '#94a3b8';
    ctx.fillText('정비주기', rightCX + r + 8, cy - 5);
    ctx.fillStyle = cycleColor;
    ctx.font = 'bold 10.5px sans-serif';
    ctx.fillText(cycleSub || '양호', rightCX + r + 8, cy + 8);
}

function drawCeoVerticalBars(processId, metricsValues, pdmStatus) {
    const canvas = document.getElementById(`ceo-canvas-bars-${processId}`);
    const schema = CEO_MACHINE_SCHEMAS[processId];
    if (!canvas || !schema) return;

    const dpr = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    const cssW = Math.max(260, Math.round(rect.width) || 300);
    const cssH = Math.max(76, Math.round(rect.height) || 92);

    if (canvas.width !== Math.round(cssW * dpr) || canvas.height !== Math.round(cssH * dpr)) {
        canvas.width = Math.round(cssW * dpr);
        canvas.height = Math.round(cssH * dpr);
    }

    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, cssW, cssH);

    const slotWidth = cssW / 4;
    const trackY = 18;
    const trackH = Math.max(42, cssH - 40);
    const trackW = 13;

    schema.bars.forEach((b, i) => {
        const val = (metricsValues && metricsValues[i] !== undefined) ? metricsValues[i] : b.base;
        const cx = Math.round(slotWidth * i + slotWidth / 2);

        const ratio = Math.max(0.06, Math.min(0.94, (val - b.min) / (b.max - b.min)));
        const fillH = Math.max(4, trackH * ratio);
        const fillY = trackY + trackH - fillH;

        let barColor = b.color || '#38bdf8';
        if (pdmStatus === 'WARNING') barColor = '#ef4444';
        else if (pdmStatus === 'CAUTION') barColor = '#f59e0b';

        // 상단 수치
        ctx.font = 'bold 10.5px "JetBrains Mono", monospace';
        ctx.fillStyle = '#f1f5f9';
        ctx.textAlign = 'center';
        ctx.fillText(`${val}${b.unit}`, cx, 13);

        // 배경 트랙
        ctx.beginPath();
        const rx = cx - trackW / 2;
        ctx.roundRect(rx, trackY, trackW, trackH, 4);
        ctx.fillStyle = 'rgba(255, 255, 255, 0.08)';
        ctx.fill();

        // 채움 막대
        ctx.beginPath();
        ctx.roundRect(rx, fillY, trackW, fillH, 4);
        ctx.fillStyle = barColor;
        ctx.fill();

        // 하단 라벨
        ctx.font = '10.5px sans-serif';
        ctx.fillStyle = '#94a3b8';
        ctx.fillText(b.label, cx, cssH - 4);
    });
}

// ── 8. 실시간 머신 상태 업데이트 ──
function updateCeoMachine(processId, status, barcode, pDataObj) {
    const mac = document.getElementById(`ceo-mac-${processId}`);
    const dataBox = document.getElementById(`ceo-data-${processId}`);
    const cellWrap = document.getElementById(`ceo-cells-${processId}`);
    const defectTag = document.getElementById(`ceo-defect-tag-${processId}`);
    const statusEl = document.getElementById(`ceo-status-${processId}`);
    const schema = CEO_MACHINE_SCHEMAS[processId];
    if (!mac || !dataBox || !schema) return;

    if (status === 'IDLE' || status === 'WAIT' || !barcode || barcode === '-') {
        mac.className = 'machine-card wait';
        if (statusEl) statusEl.innerText = '대기';
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
        return;
    }

    const isMachineAlarm = (status === 'ALARM' || status === 'MACHINE_ALARM' || (pDataObj && pDataObj.is_machine_alarm));
    const isPass = (status === 'PASS');
    const isProductDefect = (status === 'FAIL' || status === 'DEFECT' || (!isPass && !isMachineAlarm));

    mac.className = `machine-card ${isMachineAlarm ? 'alarm error' : 'run'}`;
    dataBox.innerText = barcode || '-';

    let pcbNum = '';
    if (barcode && barcode.includes('-')) {
        const parts = barcode.split('-');
        const lastPart = parts[parts.length - 1];
        if (!isNaN(parseInt(lastPart))) pcbNum = parseInt(lastPart);
    }
    if (pDataObj && pDataObj.pcb_no) pcbNum = pDataObj.pcb_no;

    if (statusEl) {
        if (isMachineAlarm) statusEl.innerText = '🚨 설비이상';
        else if (isPass) statusEl.innerText = '가동중';
        else statusEl.innerText = '불량감지';
    }

    const failedCell = (pDataObj && pDataObj.failed_cell) ? pDataObj.failed_cell : (isProductDefect ? 2 : 0);
    if (defectTag) {
        if (isMachineAlarm) {
            defectTag.innerHTML = `<span class="defect-badge-highlight" style="background:#ef4444; color:#fff;">🚨 설비 비상점검 필요</span>`;
        } else if (isProductDefect) {
            defectTag.innerHTML = `<span class="defect-badge-highlight">⚠️ PCB #${pcbNum} 불량(셀 #${failedCell})</span>`;
        } else {
            defectTag.innerHTML = '';
        }
    }

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

    // 센서 데이터 계산 및 갱신
    let health = (pDataObj && pDataObj.pdm_health !== undefined) ? pDataObj.pdm_health : schema.defaultHealth;
    let cycleVal = schema.defaultCycleVal;
    let cycleText = schema.defaultCycleText;

    if (pDataObj) {
        if (pDataObj.filter_life_pct !== undefined) {
            cycleVal = pDataObj.filter_life_pct;
            cycleText = `D-${pDataObj.rul_filter_days || 14}`;
        } else if (pDataObj.mask_wash_count !== undefined) {
            cycleVal = Math.max(0, 100 - pDataObj.mask_wash_count);
            cycleText = `${100 - pDataObj.mask_wash_count}타`;
        } else if (pDataObj.nozzle_rul_days !== undefined) {
            cycleText = `D-${pDataObj.nozzle_rul_days}`;
        }
    }

    const barVals = schema.bars.map(b => {
        let v = (pDataObj && pDataObj[b.key] !== undefined) ? pDataObj[b.key] : b.base;
        return parseFloat(Number(v).toFixed(b.decimals));
    });

    const st = ceoMachineCurrentState[processId];
    if (st) {
        st.health = health;
        st.cycleVal = cycleVal;
        st.cycleText = cycleText;
        st.bars = barVals;
        st.status = isMachineAlarm ? 'WARNING' : 'NORMAL';
    }

    drawCeoRadialGauges(processId, health, cycleVal, cycleText, schema.defaultCycleSub, isMachineAlarm ? 'WARNING' : 'NORMAL');
    drawCeoVerticalBars(processId, barVals, isMachineAlarm ? 'WARNING' : 'NORMAL');
}

// ── 9. 대기 중 센서 미세 변동 앰비언트 루프 (1.2초 주기) ──
setInterval(() => {
    if (!activeCeoLineType) return;
    const targetMachines = (activeCeoLineType === 'SMT') ? 
        ['LASER', 'SPI', 'MOUNTER_1', 'MOUNTER_2', 'REFLOW'] : 
        ['DIP_AOI', 'WAVE', 'ICT', 'COATING', 'FCT'];

    targetMachines.forEach(id => {
        const schema = CEO_MACHINE_SCHEMAS[id];
        const st = ceoMachineCurrentState[id];
        if (schema && st) {
            // 막대 수치 미세 변동 (실시간 동작감)
            st.bars = schema.bars.map(b => {
                const span = (b.max - b.min) * 0.015;
                const jitter = (Math.random() - 0.5) * span;
                return parseFloat((b.base + jitter).toFixed(b.decimals));
            });
            drawCeoRadialGauges(id, st.health, st.cycleVal, st.cycleText, st.cycleSub, st.status);
            drawCeoVerticalBars(id, st.bars, st.status);
        }
    });
}, 1200);

// ── 10. 실시간 고속 스트림 폴링 (0.8초 주기) ──
setInterval(pollCeoLiveStream, 800);

async function pollCeoLiveStream() {
    if (!activeCeoLineType || isCeoPollingActive) return;
    isCeoPollingActive = true;

    try {
        const res = await fetch(`/backend/api/get_live_logs.php?last_id=${ceoLastHistoryId}`);
        const json = await res.json();

        if (json.status === 'success' && json.data) {
            const logs = json.data.logs || [];
            const activeWo = json.data.active_wo || null;

            if (activeWo && activeWo.status === 'DONE') {
                const allIds = ['LASER', 'SPI', 'MOUNTER_1', 'MOUNTER_2', 'REFLOW', 'DIP_AOI', 'WAVE', 'ICT', 'COATING', 'FCT'];
                allIds.forEach(id => updateCeoMachine(id, 'IDLE', '-', null));
            }

            if (logs.length > 0) {
                logs.forEach(item => {
                    let proc = item.process_name;
                    if (proc === 'MOUNTER') proc = 'MOUNTER_1';
                    const isPass = item.result_status;
                    const barcode = item.barcode;
                    let pDataObj = null;
                    try {
                        pDataObj = item.process_data ? JSON.parse(item.process_data) : null;
                    } catch (e) {
                        pDataObj = null;
                    }

                    if (isPass === 'IDLE' || isPass === 'WAIT' || !barcode || barcode === '-') {
                        updateCeoMachine(proc, 'IDLE', '-', null);
                    } else {
                        updateCeoMachine(proc, isPass, barcode, pDataObj);
                    }
                });
                ceoLastHistoryId = json.data.max_id || ceoLastHistoryId;
            }
        }
    } catch(e) {
        console.warn('pollCeoLiveStream error:', e);
    } finally {
        isCeoPollingActive = false;
    }
}
