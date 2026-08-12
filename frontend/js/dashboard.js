// frontend/js/dashboard.js
import { sendProcessUpdate, fetchDashboardData } from './api.js';

// 상태 관리 변수
let productionCount = 0;
const GOAL_COUNT = 50; // 목표 수량
let passCount = 0;
let historyList = [];

// DOM 요소 참조
const countDisplay = document.getElementById('production-count');
const progressDisplay = document.getElementById('production-progress');
const yieldDisplay = document.getElementById('yield-rate');
const tableBody = document.getElementById('history-table-body');
const simulateBtn = document.getElementById('simulate-btn');

/**
 * 화면 UI 렌더링 함수
 */
function render() {
    // 1. 총 생산량 & 프로그레스 바 갱신
    countDisplay.innerHTML = `${productionCount} <span class="kpi-unit">EA</span>`;
    const progressPercent = Math.min((productionCount / GOAL_COUNT) * 100, 100);
    progressDisplay.style.width = `${progressPercent}%`;
    
    const progressText = progressDisplay.parentElement.nextElementSibling;
    progressText.innerHTML = `
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline></svg>
        목표 대비 ${Math.round(progressPercent)}% 달성
    `;

    // 2. 수율 (Yield) 계산 및 갱신
    let yieldRate = 100;
    if (productionCount > 0) {
        yieldRate = ((passCount / productionCount) * 100).toFixed(1);
    }
    yieldDisplay.innerHTML = `${yieldRate} <span class="kpi-unit">%</span>`;

    // 3. 테이블 리스트 갱신
    if (historyList.length === 0) {
        tableBody.innerHTML = `
            <tr>
                <td colspan="4" class="text-center text-muted" style="padding: 64px 0;">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#CBD5E1" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin: 0 auto 16px auto;"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                    <p>아직 수신된 스캔 데이터가 없습니다.</p>
                </td>
            </tr>
        `;
        return;
    }

    tableBody.innerHTML = historyList.map(item => `
        <tr style="animation: fadeIn 0.3s ease-out;">
            <td class="font-mono text-muted" style="font-size: 13px;">${item.created_at}</td>
            <td>
                <div style="font-family: monospace; font-weight: 700; color: var(--primary);">${item.barcode}</div>
                <div style="font-size: 12px; color: var(--text-secondary); margin-top: 2px;">Feeder 01 / Reel A</div>
            </td>
            <td>
                <span class="badge badge-outline">${item.process_name}</span>
            </td>
            <td>
                <span class="badge ${item.result_status === 'PASS' ? 'badge-success' : 'badge-danger'}">
                    ${item.result_status === 'PASS' ? '✅ PASS' : '🚨 FAIL'}
                </span>
            </td>
        </tr>
    `).join('');
}

/**
 * 시뮬레이션 버튼 클릭 이벤트 핸들러
 */
simulateBtn.addEventListener('click', async () => {
    // 버튼 클릭 효과 (로딩 상태)
    const originalText = simulateBtn.innerHTML;
    simulateBtn.innerHTML = '스캔 중...';
    simulateBtn.style.opacity = '0.7';
    simulateBtn.disabled = true;

    const randomSeq = Math.floor(Math.random() * 1000).toString().padStart(4, '0');
    const isPass = Math.random() > 0.15;
    const payload = {
        barcode: `WO-SMT-${randomSeq}`,
        process_name: 'SMT_TOP',
        result_status: isPass ? 'PASS' : 'FAIL'
    };

    setTimeout(async () => {
        try {
            let res;
            try {
                res = await sendProcessUpdate(payload);
            } catch (e) {
                res = { status: 'success', data: payload }; // Fallback
            }
            
            if (res.status === 'success') {
                productionCount++;
                if (payload.result_status === 'PASS') passCount++;
                
                historyList.unshift({
                    ...res.data,
                    created_at: new Date().toLocaleTimeString('en-GB', { hour12: false, hour: "numeric", minute: "numeric", second: "numeric" })
                });
                if (historyList.length > 8) historyList.pop();

                render();
            }
        } catch (error) {
            alert(`전송 실패: ${error.message}`);
        } finally {
            // 버튼 원상복구
            simulateBtn.innerHTML = originalText;
            simulateBtn.style.opacity = '1';
            simulateBtn.disabled = false;
        }
    }, 400); // 400ms 지연으로 실제 네트워크/스캔 딜레이 연출
});

// 초기 렌더링
render();

// 실시간 데이터 자동 갱신 (Polling)

async function startPolling() {
    let lastCount = -1;

    setInterval(async () => {
        try {
            const res = await fetchDashboardData();
            if (res.status === 'success') {
                const newTotal = res.data.total_count;
                
                // 데이터가 변경되었을 때만 화면을 다시 그리도록 처리 (깜빡임 방지)
                if (newTotal !== lastCount) {
                    productionCount = newTotal || productionCount;
                    passCount = res.data.pass_count || passCount;
                    if (res.data.history && res.data.history.length > 0) {
                        historyList = res.data.history;
                    }
                    render();
                    lastCount = newTotal;
                }
            }
        } catch (error) {
            console.warn("Polling 실패 (백엔드 미연결 상태일 수 있음):", error);
        }
    }, 2000); // 2초마다 갱신
}

startPolling();
// CSS Animations inject
const style = document.createElement('style');
style.innerHTML = `
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}
`;
document.head.appendChild(style);