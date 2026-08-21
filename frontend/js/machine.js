// frontend/js/machine.js - MES 설비별 독립 전용 HMI 터미널 제어 엔진

// ── 1. 10대 설비 메타데이터 및 텔레메트리/TPM 스키마 ──
const MACHINE_DEFINITIONS = {
    LASER: {
        code: 'LASER',
        seqNum: '01',
        lineType: 'SMT 라인 1호기',
        name: 'Laser Marker (PCB 각인기)',
        desc: 'SMT 투입 전 고정밀 레이저 2D DataMatrix 바코드 마킹',
        defaultHealth: 98,
        tpm: {
            itemTitle: '집진기 흄(Fume) 헤파 필터 잔여 수명',
            badgeText: 'D-14 (82%)',
            percent: 82,
            subLeft: '집진기 음압: -2.3 kPa (정상)',
            subRight: '권장 교체주기: 30일',
            btnText: '🛠️ 집진기 필터 교체 완료 (Reset)',
            actionName: 'LASER_FILTER_REPLACE',
            aiGuide: '집진기 차압 및 레이저 튜브 온도가 정상 범위 내에 있습니다. 14일 후 정기 필터 교체를 권장합니다.'
        },
        sensors: [
            { key: 'laser_power_w', label: '발진 출력', unit: 'W', base: 15.2, min: 10, max: 20, lcl: 14.5, ucl: 16.0, decimals: 1 },
            { key: 'tube_temp_c', label: '튜브 온도', unit: '℃', base: 31.5, min: 20, max: 45, lcl: 25.0, ucl: 38.0, decimals: 1 },
            { key: 'fume_pressure_kpa', label: '집진 차압', unit: 'kPa', base: -2.3, min: -3.5, max: -1.0, lcl: -3.0, ucl: -1.5, decimals: 1 },
            { key: 'lens_cleanliness_pct', label: '렌즈 청결도', unit: '%', base: 96, min: 70, max: 100, lcl: 90, ucl: 100, decimals: 0 }
        ]
    },
    SPI: {
        code: 'SPI',
        seqNum: '02',
        lineType: 'SMT 라인 2호기',
        name: 'SPI 3D (납도포 검사기)',
        desc: '솔더 페이스트 3D 체적, 높이, 오프셋 인쇄 품질 전수 검사',
        defaultHealth: 97,
        tpm: {
            itemTitle: '메탈마스크 초음파 세척 주기 관리',
            badgeText: '잔여 16타 (84/100타)',
            percent: 84,
            subLeft: '인쇄 오프셋: X +6.8μm / Y -4.2μm',
            subRight: '세척 한계: 100타 주기',
            btnText: '🧼 메탈마스크 세척 완료 (Reset)',
            actionName: 'SPI_MASK_WASH',
            aiGuide: '누적 84타 인쇄 완료되었습니다. 16타 후 메탈마스크 자동/초음파 세척을 진행하십시오.'
        },
        sensors: [
            { key: 'volume_pct', label: '도포 체적율', unit: '%', base: 102.5, min: 70, max: 130, lcl: 90.0, ucl: 115.0, decimals: 1 },
            { key: 'solder_height_um', label: '도포 높이', unit: 'μm', base: 142.0, min: 100, max: 180, lcl: 120.0, ucl: 160.0, decimals: 1 },
            { key: 'paste_viscosity_pa_s', label: '페이스트 점도', unit: 'Pa·s', base: 202, min: 150, max: 250, lcl: 180, ucl: 220, decimals: 0 },
            { key: 'offset_x_um', label: 'X축 오프셋', unit: 'μm', base: 6.8, min: -20, max: 20, lcl: -15.0, ucl: 15.0, decimals: 1 }
        ]
    },
    MOUNTER_1: {
        code: 'MOUNTER_1',
        seqNum: '03',
        lineType: 'SMT 라인 3호기',
        name: '#1 고속 칩 마운터 (Chip Shooter)',
        desc: '0402/0603 초소형 칩 부품 초고속 실장 (16-Head Ultra Fast)',
        defaultHealth: 99,
        tpm: {
            itemTitle: '고속 노즐 팁 마모도 및 교체 주기 (RUL)',
            badgeText: 'D-4 (누적 16.8만타)',
            percent: 84,
            subLeft: '피더 텐션: 4.2 N (정상)',
            subRight: '권장 수명: 200,000타',
            btnText: '🔧 고속 노즐 교체 완료 (Reset)',
            actionName: 'MOUNTER_NOZZLE_REPLACE',
            aiGuide: '16개 고속 노즐 헤드의 흡착 진공압과 픽업 성공률이 99.8%로 최상 상태를 유지하고 있습니다.'
        },
        sensors: [
            { key: 'vacuum_kpa', label: '노즐 진공압', unit: 'kPa', base: -84.5, min: -100, max: -60, lcl: -95.0, ucl: -78.0, decimals: 1 },
            { key: 'head_vibration_g', label: '헤드 진동치', unit: 'G', base: 0.088, min: 0, max: 0.25, lcl: 0.0, ucl: 0.15, decimals: 3 },
            { key: 'motor_temp_c', label: '리니어 모터온도', unit: '℃', base: 37.8, min: 20, max: 55, lcl: 25.0, ucl: 48.0, decimals: 1 },
            { key: 'feeder_tension_n', label: '피더 테이프장력', unit: 'N', base: 4.2, min: 2.0, max: 6.5, lcl: 2.5, ucl: 6.0, decimals: 1 }
        ]
    },
    MOUNTER_2: {
        code: 'MOUNTER_2',
        seqNum: '04',
        lineType: 'SMT 라인 4호기',
        name: '#2 이형/범용 마운터 (Flexible)',
        desc: '대형 IC, 커넥터, QFP, BGA 부품 비전 얼라인먼트 정밀 실장',
        defaultHealth: 98,
        tpm: {
            itemTitle: '비전 카메라 조명 및 트레이 피더 정비 주기',
            badgeText: 'D-7 (잔여 78%)',
            percent: 78,
            subLeft: '장착 가압력: 1.85 N (정상)',
            subRight: '교정 주기: 14일',
            btnText: '🎯 비전 얼라인먼트 교정 완료 (Reset)',
            actionName: 'MOUNTER_VISION_CAL',
            aiGuide: 'QFP/BGA 정밀 비전 카메라 인식률이 99.4%로 정상이며 트레이 피더 공급이 원활합니다.'
        },
        sensors: [
            { key: 'align_theta_deg', label: '회전 정렬오차', unit: '°', base: 0.12, min: -1.0, max: 1.0, lcl: -0.5, ucl: 0.5, decimals: 2 },
            { key: 'force_n', label: '장착 가압력', unit: 'N', base: 1.85, min: 0.5, max: 4.0, lcl: 1.0, ucl: 2.8, decimals: 2 },
            { key: 'tray_feeder_rem', label: '트레이 피더잔량', unit: '%', base: 86, min: 10, max: 100, lcl: 20, ucl: 100, decimals: 0 },
            { key: 'vision_match_pct', label: '비전 패턴일치율', unit: '%', base: 99.4, min: 80, max: 100, lcl: 95, ucl: 100, decimals: 1 }
        ]
    },
    REFLOW: {
        code: 'REFLOW',
        seqNum: '05',
        lineType: 'SMT 라인 5호기',
        name: 'Reflow Oven (10존 질소 열풍 오븐)',
        desc: 'SMT 무연(Lead-Free) 솔더링 10-Zone 정밀 열풍 프로파일',
        defaultHealth: 98,
        tpm: {
            itemTitle: '플럭스 회수 트랩 포화도 & 클리닝 주기',
            badgeText: 'D-8 (트랩 42%)',
            percent: 42,
            subLeft: '냉각 구배: -2.4 ℃/s (급랭 양호)',
            subRight: '정비 기준: 포화도 70% 도달 시',
            btnText: '🧹 플럭스 트랩 정비 완료 (Reset)',
            actionName: 'REFLOW_TRAP_CLEAN',
            aiGuide: '피크 솔더링 온도와 질소 산소 농도가 최적 상태입니다. 플럭스 트랩은 8일 후 청소 권장합니다.'
        },
        sensors: [
            { key: 'peak_temp_c', label: '피크 솔더온도', unit: '℃', base: 245.5, min: 220, max: 270, lcl: 243.0, ucl: 249.0, decimals: 1 },
            { key: 'oxygen_ppm', label: '질소 산소농도', unit: 'ppm', base: 375, min: 100, max: 700, lcl: 150, ucl: 500, decimals: 0 },
            { key: 'ramp_rate_c_s', label: '승온 구배', unit: '℃/s', base: 1.85, min: 1.0, max: 3.0, lcl: 1.5, ucl: 2.2, decimals: 2 },
            { key: 'tal_sec', label: '액상 체류시간', unit: 'sec', base: 52.0, min: 35, max: 70, lcl: 45.0, ucl: 60.0, decimals: 1 }
        ]
    },
    DIP_AOI: {
        code: 'DIP_AOI',
        seqNum: '06',
        lineType: 'DIP 수삽 라인 1호기',
        name: 'DIP AOI (수삽 3D 비전 검사기)',
        desc: '수삽 자재 삽입 결함, 리드 핀 들뜸, 납 브릿지 쇼트 전수 검사',
        defaultHealth: 99,
        tpm: {
            itemTitle: '비전 광학계 조도 & 카메라 캘리브레이션',
            badgeText: 'D-20 (92%)',
            percent: 92,
            subLeft: '조명 조도: 5,000 Lux (100% 정상)',
            subRight: '교정 주기: 30일',
            btnText: '🎯 광학계 캘리브레이션 완료 (Reset)',
            actionName: 'AOI_CALIBRATION',
            aiGuide: '광학계 렌즈 및 상하부 링 조명이 교정 기준값 내에서 매우 안정적으로 가동 중입니다.'
        },
        sensors: [
            { key: 'pin_soldering_score', label: '솔더링 품질점수', unit: '점', base: 98.5, min: 70, max: 100, lcl: 90.0, ucl: 100.0, decimals: 1 },
            { key: 'bridge_risk_pct', label: '브릿지 쇼트율', unit: '%', base: 1.2, min: 0, max: 8.0, lcl: 0.0, ucl: 5.0, decimals: 1 },
            { key: 'lift_height_um', label: '리드 들뜸높이', unit: 'μm', base: 18.0, min: 0, max: 60, lcl: 0, ucl: 50, decimals: 0 },
            { key: 'comp_tilt_deg', label: '부품 기울기', unit: '°', base: 0.4, min: 0, max: 3.0, lcl: 0.0, ucl: 2.0, decimals: 1 }
        ]
    },
    WAVE: {
        code: 'WAVE',
        seqNum: '07',
        lineType: 'DIP 수삽 라인 2호기',
        name: 'Wave Soldering (자동 웨이브 납땜기)',
        desc: 'DIP PCB 하부 자동 플럭싱, 예열 및 듀얼 솔더 웨이브 납땜',
        defaultHealth: 97,
        tpm: {
            itemTitle: '솔더팟 산화 슬러지(드로스) 청소 주기',
            badgeText: 'D-5 (누적 28.5%)',
            percent: 28.5,
            subLeft: '임펠러 펌프: 1,255 RPM (정상)',
            subRight: '청소 기준: 드로스 60% 도달 시',
            btnText: '🔥 솔더팟 드로스 청소 완료 (Reset)',
            actionName: 'WAVE_DROSS_CLEAN',
            aiGuide: '솔더팟 용탕 온도가 250.2℃로 균일하게 제어되고 있으며, 5일 후 드로스 배출 정비를 권장합니다.'
        },
        sensors: [
            { key: 'pot_temp_c', label: '솔더팟 용탕온도', unit: '℃', base: 250.2, min: 230, max: 270, lcl: 245.0, ucl: 258.0, decimals: 1 },
            { key: 'wave_height_mm', label: '웨이브 파고높이', unit: 'mm', base: 9.15, min: 6.0, max: 12.0, lcl: 8.5, ucl: 9.8, decimals: 2 },
            { key: 'preheater_temp_c', label: '예열 챔버온도', unit: '℃', base: 132.5, min: 100, max: 160, lcl: 120.0, ucl: 145.0, decimals: 1 },
            { key: 'flux_amount_ml_min', label: '플럭스 분사량', unit: 'ml/min', base: 16.2, min: 10, max: 22, lcl: 14.0, ucl: 18.0, decimals: 1 }
        ]
    },
    ICT: {
        code: 'ICT',
        seqNum: '08',
        lineType: 'DIP 수삽 라인 3호기',
        name: 'ICT (인서킷 전기 회로 검사기)',
        desc: '코팅 전 기판 쇼트, 오픈, 저항/용량 특성 핀베드 전수 전기 검사',
        defaultHealth: 99,
        tpm: {
            itemTitle: '테스트 핀 접촉 건전도 및 핀베드 교체 주기',
            badgeText: 'D-20 (92%)',
            percent: 92,
            subLeft: '핀 접촉저항: 45.2 mΩ (정상)',
            subRight: '교체 주기: 100,000회 접촉',
            btnText: '⚡ ICT 테스트 핀 정비 완료 (Reset)',
            actionName: 'ICT_PIN_MAINT',
            aiGuide: '전체 512개 테스트 핀의 접촉 저항이 균일하며 단락/오픈 검사 정확도가 99.8%를 기록 중입니다.'
        },
        sensors: [
            { key: 'contact_res_ohm', label: '접촉 저항치', unit: 'mΩ', base: 45.2, min: 10, max: 120, lcl: 20.0, ucl: 80.0, decimals: 1 },
            { key: 'res_accuracy_pct', label: '저항 측정정밀도', unit: '%', base: 99.8, min: 90, max: 100, lcl: 95.0, ucl: 100.0, decimals: 1 },
            { key: 'leakage_curr_ua', label: '누설 전류량', unit: 'μA', base: 0.45, min: 0, max: 3.0, lcl: 0.0, ucl: 1.5, decimals: 2 },
            { key: 'pin_wear_pct', label: '핀 마모 진행률', unit: '%', base: 12, min: 0, max: 50, lcl: 0, ucl: 30, decimals: 0 }
        ]
    },
    COATING: {
        code: 'COATING',
        seqNum: '09',
        lineType: 'DIP 수삽 라인 4호기',
        name: 'Conformal Coating (방습 코팅기)',
        desc: '전장/산업용 방수·절연 실리콘 코팅액 자동 도포 및 UV 초고속 경화',
        defaultHealth: 98,
        tpm: {
            itemTitle: '디스펜서 분사 노즐 초음파 세척 주기',
            badgeText: 'D-12 (84%)',
            percent: 84,
            subLeft: 'UV 광량: 1,250 mJ/cm² (정상 경화)',
            subRight: '세척 주기: 7일',
            btnText: '🧴 코팅 노즐 세척 완료 (Reset)',
            actionName: 'COATING_NOZZLE_WASH',
            aiGuide: '코팅 피막 두께(75μm)와 UV 램프 적산 광량이 최적 규격 범위 내에서 안정적으로 유지되고 있습니다.'
        },
        sensors: [
            { key: 'dispense_press_mpa', label: '노즐 분사압력', unit: 'MPa', base: 0.35, min: 0.2, max: 0.5, lcl: 0.30, ucl: 0.40, decimals: 2 },
            { key: 'film_thickness_um', label: '도포 피막두께', unit: 'μm', base: 75.0, min: 40, max: 120, lcl: 60.0, ucl: 90.0, decimals: 1 },
            { key: 'uv_energy_mj', label: 'UV 적산광량', unit: 'mJ', base: 1250, min: 800, max: 1800, lcl: 1000, ucl: 1500, decimals: 0 },
            { key: 'fluid_viscosity_cp', label: '코팅액 점도', unit: 'cP', base: 185, min: 120, max: 260, lcl: 150, ucl: 220, decimals: 0 }
        ]
    },
    FCT: {
        code: 'FCT',
        seqNum: '10',
        lineType: 'DIP 수삽 라인 5호기',
        name: 'FCT (완제품 기능 동작 검사기)',
        desc: '실제 전원 인가 후 MCU 동작, CAN/통신, 소비전류 최종 전수 검사',
        defaultHealth: 99,
        tpm: {
            itemTitle: '테스트 지그 전원 릴레이 및 커넥터 건전도',
            badgeText: 'D-30 (95%)',
            percent: 95,
            subLeft: 'CAN 통신 지연: 4.8 ms (초고속)',
            subRight: '지그 교정: 30일 주기',
            btnText: '🎯 FCT 테스트 지그 교정 완료 (Reset)',
            actionName: 'FCT_JIG_CAL',
            aiGuide: 'MCU 5V 인가 전압 및 통신 응답 패킷 손실률 0%로 완제품 기능 검사가 최적 가동 중입니다.'
        },
        sensors: [
            { key: 'mcu_volt_v', label: 'MCU 인가전압', unit: 'V', base: 5.02, min: 4.5, max: 5.5, lcl: 4.85, ucl: 5.15, decimals: 2 },
            { key: 'curr_draw_ma', label: '총 소비전류', unit: 'mA', base: 142.5, min: 80, max: 220, lcl: 120.0, ucl: 170.0, decimals: 1 },
            { key: 'can_resp_ms', label: 'CAN 통신지연', unit: 'ms', base: 4.8, min: 1.0, max: 15.0, lcl: 2.0, ucl: 8.0, decimals: 1 },
            { key: 'fw_check_score', label: '펌웨어 검증점수', unit: '점', base: 100, min: 80, max: 100, lcl: 90, ucl: 100, decimals: 0 }
        ]
    }
};

// ── 2. 전역 상태 ──
let currentEqCode = 'LASER';
let currentHealth = 98;
let currentTpmPercent = 82;
let waveformHistory = [];
let maxHistoryPoints = 30;
let pollingTimer = null;
let lastLogTimestamp = 0;
let scanHistoryList = [];

// 오디오 피드백 (웹 오디오 API)
let audioCtx = null;
function getAudioContext() {
    if (!audioCtx) {
        audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    }
    if (audioCtx.state === 'suspended') {
        audioCtx.resume();
    }
    return audioCtx;
}

function playTone(freq, dur) {
    try {
        const ctx = getAudioContext();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(freq, ctx.currentTime);
        gain.gain.setValueAtTime(0.3, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + dur);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + dur);
    } catch(e) {
        console.warn('Audio play failed:', e);
    }
}
const playPassTone = () => playTone(1350, 0.25);
const playFailTone = () => playTone(240, 0.5);

// ── 3. 초기화 & 설비 전환 ──
document.addEventListener('DOMContentLoaded', () => {
    // 0. 인증 확인
    const currentRole = localStorage.getItem('role');
    if (!currentRole) {
        localStorage.setItem('role', 'worker'); // 기본 작업자 권한 부여
    }

    // 1. URL 파라미터 확인
    const params = new URLSearchParams(window.location.search);
    let eqParam = params.get('eq');
    if (eqParam === 'MOUNTER') eqParam = 'MOUNTER_1';
    if (eqParam && MACHINE_DEFINITIONS[eqParam]) {
        currentEqCode = eqParam;
    }

    // 2. 상단 탭 렌더링
    renderTopTabs();

    // 3. 설비 화면 로드
    loadMachineView(currentEqCode);

    // 4. 실시간 시계 시작
    startLiveClock();

    // 5. 고속 실시간 폴링 (0.8초)
    startRealtimePolling();

    // 6. 바코드 스캐너 키보드 리스너
    initScannerListener();
});

// 상단 원터치 공정 탭 렌더링
function renderTopTabs() {
    const nav = document.getElementById('machineTabsNav');
    if (!nav) return;

    let html = '';
    Object.keys(MACHINE_DEFINITIONS).forEach(code => {
        const def = MACHINE_DEFINITIONS[code];
        const isActive = (code === currentEqCode);
        html += `
            <button class="mach-tab-btn ${isActive ? 'active' : ''}" onclick="switchMachine('${code}')" id="tab-${code}">
                <span class="tab-seq-num">#${def.seqNum}</span>
                <span class="tab-status-dot" id="dot-${code}"></span>
                <span>${code}</span>
            </button>
        `;
    });
    nav.innerHTML = html;
}

// 설비 전환 함수
function switchMachine(eqCode) {
    if (!MACHINE_DEFINITIONS[eqCode]) return;
    currentEqCode = eqCode;

    // URL 갱신 (새로고침 없이 파라미터만 변경)
    const newUrl = `${window.location.pathname}?eq=${eqCode}`;
    window.history.pushState({ path: newUrl }, '', newUrl);

    // 탭 UI 갱신
    document.querySelectorAll('.mach-tab-btn').forEach(btn => btn.classList.remove('active'));
    const targetTab = document.getElementById(`tab-${eqCode}`);
    if (targetTab) targetTab.classList.add('active');

    // 화면 데이터 갱신
    loadMachineView(eqCode);
}

// 설비 화면 전체 데이터 바인딩
function loadMachineView(eqCode) {
    const def = MACHINE_DEFINITIONS[eqCode];
    if (!def) return;

    // 1. 헤더 & 아이덴티티
    const badgeEl = document.getElementById('machCodeBadge');
    const titleEl = document.getElementById('machTitleLarge');
    const descEl = document.getElementById('machCategoryDesc');

    if (badgeEl) badgeEl.innerText = `${def.lineType} • #${def.seqNum}`;
    if (titleEl) titleEl.innerText = def.name;
    if (descEl) descEl.innerText = def.desc;

    // 2. 건전도 초기화
    currentHealth = def.defaultHealth;
    currentTpmPercent = def.tpm.percent;
    updateHealthUI(currentHealth, 'NORMAL');

    // 3. TPM 예방정비 섹션
    const tpmTitleEl = document.getElementById('tpmItemTitle');
    const tpmBadgeEl = document.getElementById('tpmDaysBadge');
    const tpmFillEl = document.getElementById('tpmBarFill');
    const tpmLeftEl = document.getElementById('tpmSubLeft');
    const tpmRightEl = document.getElementById('tpmSubRight');
    const tpmBtnEl = document.getElementById('btnTpmReset');
    const aiGuideEl = document.getElementById('aiGuideText');

    if (tpmTitleEl) tpmTitleEl.innerText = def.tpm.itemTitle;
    if (tpmBadgeEl) tpmBadgeEl.innerText = def.tpm.badgeText;
    if (tpmFillEl) {
        tpmFillEl.style.width = `${def.tpm.percent}%`;
        tpmFillEl.className = `tpm-bar-fill ${def.tpm.percent < 50 ? 'warn' : ''}`;
    }
    if (tpmLeftEl) tpmLeftEl.innerText = def.tpm.subLeft;
    if (tpmRightEl) tpmRightEl.innerText = def.tpm.subRight;
    if (tpmBtnEl) {
        tpmBtnEl.innerHTML = def.tpm.btnText;
        tpmBtnEl.classList.remove('reset-done');
    }
    if (aiGuideEl) aiGuideEl.innerText = def.tpm.aiGuide;

    // 상단 AI 예지보전 진단 알람 배너 동기화
    showAiPdmBanner(false);

    // 4. 4대 센서 그리드 렌더링
    renderSensorGrid(def.sensors);

    // 5. 파형 차트 초기 데이터 구성
    initWaveformHistory(def.sensors[0]);
    drawWaveformChart();

    // 6. 타워 램프 & 상태 초기화
    setTowerLamp('green');
    setStatusChip('IDLE', '대기 중 (Ready)');
}

// 4대 센서 그리드 렌더링
function renderSensorGrid(sensors) {
    const grid = document.getElementById('sensorGrid4');
    if (!grid) return;

    let html = '';
    sensors.forEach((s, idx) => {
        const ratio = Math.max(5, Math.min(95, ((s.base - s.min) / (s.max - s.min)) * 100));
        html += `
            <div class="sensor-stat-card" id="sensorCard-${idx}">
                <div class="sensor-card-top">
                    <span class="sensor-label">${s.label}</span>
                    <span class="sensor-limit-tag">관리선 [${s.lcl} ~ ${s.ucl}]</span>
                </div>
                <div class="sensor-val-row">
                    <span class="sensor-big-val" id="sensorVal-${idx}">${s.base}</span>
                    <span class="sensor-unit">${s.unit}</span>
                </div>
                <div class="sensor-mini-track">
                    <div class="sensor-mini-fill" id="sensorFill-${idx}" style="width: ${ratio}%;"></div>
                </div>
            </div>
        `;
    });
    grid.innerHTML = html;
}

// ── 4. 건전도 원형 게이지 Canvas 드로잉 ──
function updateHealthUI(score, status) {
    const canvas = document.getElementById('healthScoreCanvas');
    const valEl = document.getElementById('healthValNum');
    const descEl = document.getElementById('healthStatusDesc');

    if (valEl) valEl.innerText = score;
    if (descEl) {
        if (score >= 95) {
            descEl.innerText = '정상 안정 (Optimal)';
            descEl.style.color = 'var(--emerald-green)';
        } else if (score >= 85) {
            descEl.innerText = '주의 관찰 (Caution)';
            descEl.style.color = 'var(--amber-warning)';
        } else {
            descEl.innerText = '경고 점검 (Warning)';
            descEl.style.color = 'var(--rose-danger)';
        }
    }

    if (!canvas) return;
    const dpr = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    const cssSize = Math.max(100, Math.round(rect.width) || canvas.offsetWidth || 140);
    if (canvas.width !== Math.round(cssSize * dpr) || canvas.height !== Math.round(cssSize * dpr)) {
        canvas.width = Math.round(cssSize * dpr);
        canvas.height = Math.round(cssSize * dpr);
    }

    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, cssSize, cssSize);

    const cx = cssSize / 2;
    const cy = cssSize / 2;
    const strokeW = Math.max(6, Math.min(9, cssSize * 0.06));
    const r = Math.max(20, (cssSize / 2) - (strokeW / 2) - 4);

    // 1. 배경 트랙
    ctx.beginPath();
    ctx.arc(cx, cy, r, 0, Math.PI * 2);
    ctx.strokeStyle = 'rgba(255, 255, 255, 0.08)';
    ctx.lineWidth = strokeW;
    ctx.stroke();

    // 2. 건전도 아크
    let arcColor = '#10b981';
    if (score < 85) arcColor = '#ef4444';
    else if (score < 95) arcColor = '#f59e0b';

    const startAngle = -Math.PI / 2;
    const endAngle = startAngle + (Math.max(0, Math.min(100, score)) / 100) * (Math.PI * 2);

    ctx.beginPath();
    ctx.arc(cx, cy, r, startAngle, endAngle);
    ctx.strokeStyle = arcColor;
    ctx.lineWidth = strokeW;
    ctx.lineCap = 'round';
    ctx.stroke();
}

// ── 5. 실시간 SPC 파형 차트 캔버스 렌더링 ──
function initWaveformHistory(primarySensor) {
    waveformHistory = [];
    const base = primarySensor.base;
    const span = Math.abs(primarySensor.ucl - primarySensor.lcl) * 0.35;

    for (let i = 0; i < maxHistoryPoints; i++) {
        const val = base + (Math.random() - 0.5) * span;
        waveformHistory.push(parseFloat(val.toFixed(primarySensor.decimals)));
    }
}

function drawWaveformChart() {
    const canvas = document.getElementById('hmiWaveformCanvas');
    const def = MACHINE_DEFINITIONS[currentEqCode];
    if (!canvas || !def) return;

    const sensor = def.sensors[0];
    const dpr = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    const w = Math.max(300, Math.round(rect.width) || canvas.offsetWidth || 700);
    const h = Math.max(140, Math.round(rect.height) || canvas.offsetHeight || 165);

    if (canvas.width !== Math.round(w * dpr) || canvas.height !== Math.round(h * dpr)) {
        canvas.width = Math.round(w * dpr);
        canvas.height = Math.round(h * dpr);
    }

    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, w, h);

    const padL = 74; // UCL/LCL 수치 라벨 가시성 확보
    const padR = 20;
    const padT = 20;
    const padB = 22;
    const plotW = w - padL - padR;
    const plotH = h - padT - padB;

    const ucl = sensor.ucl;
    const lcl = sensor.lcl;
    const limitSpan = Math.abs(ucl - lcl) || 1;

    // 상한선(UCL)과 하한선(LCL)의 시각적 간격을 인위적으로 넓고 시원하게 확보
    // 그래프 전체 높이의 약 60%를 UCL~LCL 영역으로 배정 (상하 여백 25%씩)
    const extraMargin = limitSpan * 0.28;
    let dispMax = Math.max(ucl, lcl) + extraMargin;
    let dispMin = Math.min(ucl, lcl) - extraMargin;

    // 파형 실측 데이터가 관리 한계를 벗어나는 경우 짤림 방지 동적 스케일링
    if (waveformHistory.length > 0) {
        const dataMax = Math.max(...waveformHistory);
        const dataMin = Math.min(...waveformHistory);
        dispMax = Math.max(dispMax, dataMax + limitSpan * 0.08);
        dispMin = Math.min(dispMin, dataMin - limitSpan * 0.08);
    }

    const getY = (val) => padT + plotH - ((val - dispMin) / (dispMax - dispMin)) * plotH;

    // 0. 배경 가이드 그리드 라인
    ctx.lineWidth = 1;
    ctx.strokeStyle = 'rgba(255, 255, 255, 0.06)';
    const gridSteps = 4;
    for (let i = 0; i <= gridSteps; i++) {
        const gy = padT + (plotH / gridSteps) * i;
        ctx.beginPath();
        ctx.moveTo(padL, gy);
        ctx.lineTo(w - padR, gy);
        ctx.stroke();
    }

    // 1. UCL 라인 (빨강 점선 & 굵은 텍스트)
    const uclY = getY(sensor.ucl);
    ctx.lineWidth = 1.5;
    ctx.setLineDash([5, 4]);
    ctx.strokeStyle = 'rgba(239, 68, 68, 0.8)';
    ctx.beginPath();
    ctx.moveTo(padL, uclY);
    ctx.lineTo(w - padR, uclY);
    ctx.stroke();

    ctx.font = 'bold 12px "JetBrains Mono", monospace';
    ctx.fillStyle = '#ef4444';
    ctx.textAlign = 'right';
    ctx.fillText(`UCL ${sensor.ucl}`, padL - 8, uclY + 4);

    // 2. LCL 라인 (노랑 점선 & 굵은 텍스트)
    const lclY = getY(sensor.lcl);
    ctx.strokeStyle = 'rgba(245, 158, 11, 0.8)';
    ctx.beginPath();
    ctx.moveTo(padL, lclY);
    ctx.lineTo(w - padR, lclY);
    ctx.stroke();

    ctx.fillStyle = '#f59e0b';
    ctx.fillText(`LCL ${sensor.lcl}`, padL - 8, lclY + 4);

    // 3. 중심선 (Center Line)
    const centerVal = (sensor.ucl + sensor.lcl) / 2;
    const clY = getY(centerVal);
    ctx.strokeStyle = 'rgba(148, 163, 184, 0.3)';
    ctx.setLineDash([2, 3]);
    ctx.beginPath();
    ctx.moveTo(padL, clY);
    ctx.lineTo(w - padR, clY);
    ctx.stroke();

    ctx.fillStyle = '#94a3b8';
    ctx.fillText(`CL ${centerVal.toFixed(1)}`, padL - 8, clY + 4);

    ctx.setLineDash([]); // 점선 해제
    ctx.textAlign = 'left';

    // 4. 파형 라인 & 그라데이션 영역 채우기
    if (waveformHistory.length > 1) {
        const stepX = plotW / (maxHistoryPoints - 1);

        // 영역 그라데이션 (영역을 가득 채우는 하이테크 네온 블루)
        const grad = ctx.createLinearGradient(0, padT, 0, padT + plotH);
        grad.addColorStop(0, 'rgba(56, 189, 248, 0.45)');
        grad.addColorStop(0.7, 'rgba(56, 189, 248, 0.12)');
        grad.addColorStop(1, 'rgba(56, 189, 248, 0.0)');

        ctx.beginPath();
        waveformHistory.forEach((v, i) => {
            const x = padL + i * stepX;
            const y = getY(v);
            if (i === 0) ctx.moveTo(x, y);
            else ctx.lineTo(x, y);
        });
        ctx.lineTo(padL + (waveformHistory.length - 1) * stepX, padT + plotH);
        ctx.lineTo(padL, padT + plotH);
        ctx.closePath();
        ctx.fillStyle = grad;
        ctx.fill();

        // 파형 외곽선 (두께 2.5px로 뚜렷하게)
        ctx.beginPath();
        waveformHistory.forEach((v, i) => {
            const x = padL + i * stepX;
            const y = getY(v);
            if (i === 0) ctx.moveTo(x, y);
            else ctx.lineTo(x, y);
        });
        ctx.strokeStyle = '#38bdf8';
        ctx.lineWidth = 2.5;
        ctx.lineJoin = 'round';
        ctx.stroke();

        // 최신 데이터 점 & 현재값 뱃지 표시
        const lastVal = waveformHistory[waveformHistory.length - 1];
        const lastX = padL + (waveformHistory.length - 1) * stepX;
        const lastY = getY(lastVal);

        ctx.beginPath();
        ctx.arc(lastX, lastY, 6, 0, Math.PI * 2);
        ctx.fillStyle = '#38bdf8';
        ctx.fill();

        ctx.beginPath();
        ctx.arc(lastX, lastY, 3, 0, Math.PI * 2);
        ctx.fillStyle = '#ffffff';
        ctx.fill();

        // 현재 실시간 측정치 텍스트 배너 렌더링
        const valText = `${lastVal} ${sensor.unit}`;
        ctx.font = 'bold 13px "JetBrains Mono", monospace';
        const textWidth = ctx.measureText(valText).width;
        const bubbleX = Math.min(w - padR - textWidth - 14, Math.max(padL + 10, lastX - textWidth / 2));
        const bubbleY = Math.max(padT + 16, Math.min(h - padB - 8, lastY - 10));

        // 뱃지 배경
        ctx.fillStyle = 'rgba(15, 23, 42, 0.9)';
        ctx.strokeStyle = '#38bdf8';
        ctx.lineWidth = 1;
        ctx.beginPath();
        if (ctx.roundRect) {
            ctx.roundRect(bubbleX - 6, bubbleY - 14, textWidth + 12, 20, 4);
        } else {
            ctx.rect(bubbleX - 6, bubbleY - 14, textWidth + 12, 20);
        }
        ctx.fill();
        ctx.stroke();

        // 뱃지 텍스트
        ctx.fillStyle = '#38bdf8';
        ctx.fillText(valText, bubbleX, bubbleY);
    }
}

// ── 6. TPM 예방정비 완료 리셋 동작 ──
async function handleTpmReset() {
    const def = MACHINE_DEFINITIONS[currentEqCode];
    if (!def) return;

    const btn = document.getElementById('btnTpmReset');
    if (btn) {
        btn.innerHTML = '✅ 정비 완료 초기화 성공!';
        btn.classList.add('reset-done');
    }

    // 1. 수명 100% 회복 & 건전도 100점 회복
    currentHealth = 100;
    currentTpmPercent = 100;
    updateHealthUI(currentHealth, 'NORMAL');

    const fillEl = document.getElementById('tpmBarFill');
    const badgeEl = document.getElementById('tpmDaysBadge');
    if (fillEl) fillEl.style.width = '100%';
    if (badgeEl) badgeEl.innerText = 'D-30 (100%)';

    showHmiToast(`[${def.name}] ${def.tpm.itemTitle} 초기화 및 정비 이력 기록 완료`);
    playPassTone();

    // 2. 백엔드 정비 이력 및 감사 로그 API 기록
    try {
        const res = await fetch('/backend/api/reset_maintenance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                process_name: currentEqCode,
                item_name: def.tpm.itemTitle,
                action_name: def.tpm.actionName,
                operator: localStorage.getItem('username') || '현장작업자'
            })
        });
        const json = await res.json();
        if (json.status === 'success') {
            console.log('TPM 정비 이력 저장 성공:', json.data);
        }
    } catch(e) {
        console.warn('TPM log save fallback:', e);
    }

    setTimeout(() => {
        if (btn) {
            btn.innerHTML = def.tpm.btnText;
            btn.classList.remove('reset-done');
        }
    }, 4000);
}
window.handleTpmReset = handleTpmReset;

// ── 7. 실시간 데이터 폴링 및 가공 이벤트 반영 ──
function startRealtimePolling() {
    if (pollingTimer) clearInterval(pollingTimer);

    pollingTimer = setInterval(async () => {
        try {
            const res = await fetch('/backend/api/get_live_logs.php?last_id=0');
            const json = await res.json();

            if (json.status === 'success' && json.data && json.data.length > 0) {
                const logs = json.data;
                const matchLog = logs.find(l => l.process_name === currentEqCode);

                if (matchLog) {
                    processLiveEvent(matchLog);
                } else {
                    simulateIdleFluctuation();
                }
            } else {
                simulateIdleFluctuation();
            }
        } catch(e) {
            simulateIdleFluctuation();
        }
    }, 900);
}

// 실시간 이벤트 수신 시 화면 업데이트
function processLiveEvent(logItem) {
    const isMachineAlarm = (logItem.result_status === 'ALARM' || logItem.result_status === 'MACHINE_ALARM');
    const isPass = (logItem.result_status === 'PASS');
    const isProductDefect = (logItem.result_status === 'FAIL' || logItem.result_status === 'DEFECT' || (!isPass && !isMachineAlarm));
    const barcode = logItem.barcode || '-';

    // 1. 타워 램프 & 상태 칩
    if (isMachineAlarm) {
        setTowerLamp('red');
        setStatusChip('ERROR', '🚨 설비 비상 알람 (Alarm)');
        updateHealthUI(62, 'WARNING');
    } else if (isProductDefect) {
        setTowerLamp('green'); // 설비는 가동 지속
        setStatusChip('RUN', '가동 중 (불량 스킵 처리)');
    } else {
        setTowerLamp('green');
        setStatusChip('RUN', '정상 가동 중 (Running)');
    }

    // 2. 가공 중 바코드 & 4-UP 셀
    const bcEl = document.getElementById('activeBarcodeVal');
    if (bcEl) bcEl.innerText = barcode;

    let pcbNo = '1';
    if (barcode.includes('-')) {
        const parts = barcode.split('-');
        pcbNo = parts[parts.length - 1];
    }

    const failedCell = isProductDefect ? 2 : 0;
    update4UpCells(failedCell);

    // 3. 4대 센서 수치 갱신
    const def = MACHINE_DEFINITIONS[currentEqCode];
    if (def) {
        let pData = null;
        try {
            if (typeof logItem.process_data === 'string') pData = JSON.parse(logItem.process_data);
            else pData = logItem.process_data;
        } catch(e) {}

        const primarySensor = def.sensors[0];
        let primaryVal = primarySensor.base;

        def.sensors.forEach((s, idx) => {
            let val = s.base;
            if (pData && pData[s.key] !== undefined) val = pData[s.key];
            else {
                const span = (s.max - s.min) * 0.03;
                val = s.base + (Math.random() - 0.5) * span;
            }
            val = parseFloat(Number(val).toFixed(s.decimals));

            if (idx === 0) primaryVal = val;

            const valEl = document.getElementById(`sensorVal-${idx}`);
            const fillEl = document.getElementById(`sensorFill-${idx}`);
            if (valEl) valEl.innerText = val;
            if (fillEl) {
                const ratio = Math.max(5, Math.min(95, ((val - s.min) / (s.max - s.min)) * 100));
                fillEl.style.width = `${ratio}%`;
                fillEl.className = `sensor-mini-fill ${val < s.lcl || val > s.ucl ? 'warn' : ''}`;
            }
        });

        // 파형 히스토리에 추가
        waveformHistory.push(primaryVal);
        if (waveformHistory.length > maxHistoryPoints) waveformHistory.shift();
        drawWaveformChart();
    }

    // 4. 이력 테이블 추가
    addHistoryRow(logItem.created_at || new Date().toLocaleTimeString('ko-KR'), barcode, logItem.result_status, isPass ? '정상 판정' : '셀 불량 감지');
}

// 대기 상태 시 미세 변동 (Heartbeat)
function simulateIdleFluctuation() {
    const def = MACHINE_DEFINITIONS[currentEqCode];
    if (!def) return;

    def.sensors.forEach((s, idx) => {
        const span = Math.abs(s.ucl - s.lcl) * 0.15;
        const val = parseFloat((s.base + (Math.random() - 0.5) * span).toFixed(s.decimals));
        const valEl = document.getElementById(`sensorVal-${idx}`);
        if (valEl) valEl.innerText = val;

        if (idx === 0) {
            waveformHistory.push(val);
            if (waveformHistory.length > maxHistoryPoints) waveformHistory.shift();
            drawWaveformChart();
        }
    });
}

// 4-UP 어레이 패널 셀 인디케이터 업데이트
function update4UpCells(failedCellIndex = 0) {
    const wrap = document.getElementById('arrayCellsWrap');
    if (!wrap) return;

    let html = '';
    for (let c = 1; c <= 4; c++) {
        if (failedCellIndex === c) {
            html += `<div class="hmi-cell-chip fail">#${c} 셀 불량 ✖</div>`;
        } else {
            html += `<div class="hmi-cell-chip pass">#${c} 셀 정상 ✔</div>`;
        }
    }
    wrap.innerHTML = html;
}

// ── 8. 최근 가공 이력 테이블 ──
function addHistoryRow(timeStr, barcode, result, message) {
    const tbody = document.getElementById('recentHistoryTbody');
    if (!tbody) return;

    const row = document.createElement('tr');
    const isPass = (result === 'PASS');
    row.innerHTML = `
        <td>${timeStr}</td>
        <td><strong>${barcode}</strong></td>
        <td><span class="${isPass ? 'tag-pass' : 'tag-fail'}">${result}</span></td>
        <td>${message}</td>
    `;
    tbody.prepend(row);

    while (tbody.children.length > 5) {
        tbody.removeChild(tbody.lastChild);
    }
}

// ── 9. 타워램프 & 상태 칩 제어 ──
function setTowerLamp(color) {
    document.querySelectorAll('.lamp-bulb').forEach(b => b.classList.remove('active'));
    const lamp = document.getElementById(`lamp-${color}`);
    if (lamp) lamp.classList.add('active');
}

function setStatusChip(statusClass, statusText) {
    const chip = document.getElementById('machStatusChip');
    if (chip) {
        chip.className = `status-badge-chip ${statusClass.toLowerCase()}`;
        chip.innerText = statusText;
    }
}

// ── 10. 바코드 직접 스캔 입력 ──
function initScannerListener() {
    const input = document.getElementById('scannerInput');
    if (!input) return;

    input.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            triggerManualScan();
        }
    });
}

async function triggerManualScan() {
    const input = document.getElementById('scannerInput');
    if (!input) return;

    const barcode = input.value.trim();
    if (!barcode) return;

    input.value = '';
    showHmiToast(`바코드 스캔 완료: ${barcode}`);

    try {
        const res = await fetch('/backend/api/update_process.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                barcode: barcode,
                process_name: currentEqCode,
                result_status: 'PASS'
            })
        });
        const json = await res.json();
        if (json.status === 'success') {
            playPassTone();
        } else {
            playFailTone();
        }
    } catch(e) {
        playPassTone();
    }
}
window.triggerManualScan = triggerManualScan;

// ── 11. 유틸리티 (실시간 시계, 토스트) ──
function startLiveClock() {
    const clockEl = document.getElementById('liveClock');
    if (!clockEl) return;

    const update = () => {
        const now = new Date();
        clockEl.innerText = now.toLocaleTimeString('ko-KR', { hour12: false });
    };
    update();
    setInterval(update, 1000);
}

function showHmiToast(msg) {
    const toast = document.getElementById('hmiToast');
    if (!toast) return;

    toast.innerText = msg;
    toast.classList.add('show');
    setTimeout(() => {
        toast.classList.remove('show');
    }, 3000);
}

// ── 12. 상단 AI 예지보전 진단 알람 배너 제어 ──
function showAiPdmBanner(isManual = false) {
    const banner = document.getElementById('aiPdmTopBanner');
    const textEl = document.getElementById('aiGuideText');
    const dotEl = document.getElementById('pdmGuideDot');
    if (!banner) return;

    const def = MACHINE_DEFINITIONS[currentEqCode];
    if (def && def.tpm) {
        if (textEl) textEl.innerText = def.tpm.aiGuide;
        const isWarn = (def.tpm.percent < 50) || (currentHealth < 85);
        if (isWarn) {
            banner.classList.add('banner-warning');
            if (dotEl) dotEl.classList.add('warn');
        } else {
            banner.classList.remove('banner-warning');
            if (dotEl) dotEl.classList.remove('warn');
        }
    }

    banner.classList.remove('banner-hidden');
    banner.style.display = 'flex';
    if (isManual) {
        showHmiToast('상단에 AI 예지보전 진단 알람을 표시했습니다.');
    }
}

function dismissAiPdmBanner() {
    const banner = document.getElementById('aiPdmTopBanner');
    if (!banner) return;
    banner.classList.add('banner-hidden');
    banner.style.display = 'none';
}

window.showAiPdmBanner = showAiPdmBanner;
window.dismissAiPdmBanner = dismissAiPdmBanner;
