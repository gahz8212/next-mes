// frontend/js/dashboard.js - SMT / DIP 라인 실시간 관제 및 머신 상태 업데이트 엔진
let currentTarget = 0;
let lastHistoryId = 0;
let isPollingActive = false;

// 1. KPI 실시간 동기화 (DB 실제 수치와 100% 일치)
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

            document.getElementById('val-target').innerText = currentTarget;
            document.getElementById('val-actual').innerText = totalCount;
            document.getElementById('val-good').innerText   = goodCount;
            document.getElementById('val-fail').innerText   = failCount;
            document.getElementById('val-yield').innerText  = d.yield_rate || '100.0%';
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
    const selProc = document.getElementById('filter-process').value;
    const selStat = document.getElementById('filter-status').value;
    const items = document.querySelectorAll('#log-list li');
    
    items.forEach(li => {
        if (!li.dataset.process) return;
        
        const matchProc = (selProc === 'ALL' || li.dataset.process === selProc);
        const matchStat = (selStat === 'ALL' || li.dataset.status === selStat);
        
        if (matchProc && matchStat) {
            li.style.display = 'flex';
        } else {
            li.style.display = 'none';
        }
    });
}

// 3. 로그 리스트 추가
function addLog(process, status, dataStr) {
    const logList = document.getElementById('log-list');
    if (!logList) return;

    // 초기 안내 메시지 삭제
    if (logList.children.length === 1 && logList.children[0].innerText.includes('센서 스트림 대기 중')) {
        logList.innerHTML = '';
    }

    const li = document.createElement('li');
    const time = new Date().toLocaleTimeString('ko-KR');
    const isPass = (status === 'PASS');
    
    li.dataset.process = process;
    li.dataset.status = status;
    
    li.innerHTML = `
        <span class="log-time">[${time}]</span>
        <span class="log-tag">${process}</span>
        <span class="log-res ${isPass ? 'pass' : 'fail'}">${status}</span>
        <span class="log-msg">${dataStr}</span>
    `;
    logList.appendChild(li);
    
    // 최대 150개 로그 유지
    if (logList.children.length > 150) {
        logList.removeChild(logList.firstChild);
    }

    applyLogFilter();
    logList.scrollTop = logList.scrollHeight; // 자동 스크롤
}

// 4. 머신 카드 전체 클린 리셋
function resetAllMachines() {
    const machineIds = ['LASER', 'SPI', 'MOUNTER', 'REFLOW', 'DIP_AOI', 'WAVE'];
    machineIds.forEach(id => {
        const mac = document.getElementById(`mac-${id}`);
        const dataBox = document.getElementById(`data-${id}`);
        const cellWrap = document.getElementById(`cells-${id}`);
        if (mac) {
            mac.className = 'machine-card wait';
            const indicator = mac.querySelector('.mac-status-indicator');
            if (indicator) indicator.innerText = '대기';
        }
        if (dataBox) {
            dataBox.innerText = '-';
        }
        if (cellWrap) {
            cellWrap.innerHTML = `
                <span class="cell-chip wait">#1</span>
                <span class="cell-chip wait">#2</span>
                <span class="cell-chip wait">#3</span>
                <span class="cell-chip wait">#4</span>
            `;
        }
    });
}

// 5. 머신 카드 실시간 상태 업데이트 & 4-UP 어레이 패널 셀 개별 판정
const machineResetTimers = {};

function updateMachine(processId, status, barcode, pDataObj) {
    const mac = document.getElementById(`mac-${processId}`);
    const dataBox = document.getElementById(`data-${processId}`);
    const cellWrap = document.getElementById(`cells-${processId}`);
    if (!mac || !dataBox) return;

    const isPass = (status === 'PASS');
    const targetClass = isPass ? 'run' : 'error';
    
    if (!mac.classList.contains(targetClass)) {
        mac.classList.remove('wait', 'run', 'error');
        mac.classList.add(targetClass);
    }
    
    // 바코드 텍스트 갱신
    dataBox.innerText = barcode || '-';
    
    const indicator = mac.querySelector('.mac-status-indicator');
    if (indicator) {
        indicator.innerText = isPass ? '가동중' : '불량감지';
    }

    // 4-UP 어레이 패널 셀 인디케이터 업데이트
    if (cellWrap) {
        let failedCell = (pDataObj && pDataObj.failed_cell) ? pDataObj.failed_cell : (!isPass ? 2 : 0);
        let cellHtml = '';
        for (let c = 1; c <= 4; c++) {
            if (failedCell === c) {
                cellHtml += `<span class="cell-chip fail" title="[셀 #${c} 불량] Bad Mark 스킵 처리됨">#${c} ✖</span>`;
            } else {
                cellHtml += `<span class="cell-chip pass" title="[셀 #${c}] 정상 양품">#${c} ✔</span>`;
            }
        }
        cellWrap.innerHTML = cellHtml;
    }
    
    if (pDataObj) {
        mac.dataset.detail = JSON.stringify(pDataObj);
        mac.title = "클릭 시 설비 파라미터 세부 정보 확인";
        mac.onclick = () => alert(`[${processId} 설비 파라미터]\n` + JSON.stringify(pDataObj, null, 2));
    }

    // 공정 라인에 기판이 끊겼을 때 (6초 동안 신호 부재 시) 대기 상태로 자연 복귀
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
    }, 6000);
}

// 6. 실시간 고속 폴링 엔진 (0.8초 주기 실시간 자동 동기화)
async function pollLiveStream() {
    if (isPollingActive) return;
    isPollingActive = true;

    try {
        const url = `/backend/api/get_live_logs.php?last_id=${lastHistoryId}`;
        const res = await fetch(url);
        const json = await res.json();

        if (json.status === 'success' && json.data) {
            const logs = json.data.logs || [];
            
            if (logs.length > 0) {
                logs.forEach(item => {
                    const proc = item.process_name;
                    const isPass = item.result_status;
                    const barcode = item.barcode;
                    const pDataStr = item.process_data;
                    const pDataObj = pDataStr ? JSON.parse(pDataStr) : null;
                    
                    // 설비 가동 상태 및 실시간 로그 갱신
                    updateMachine(proc, isPass, barcode, pDataObj);
                    addLog(proc, isPass, `[${item.barcode_status || 'ING'}] 바코드: ${barcode} ${pDataStr ? JSON.stringify(pDataObj) : ''}`);
                    
                    if (item.target_qty) {
                        currentTarget = parseInt(item.target_qty);
                        document.getElementById('val-target').innerText = currentTarget;
                    }

                    // 공정 완료 이벤트 감지
                    if (item.wo_status === 'SMT_DONE') {
                        setTimeout(() => {
                            resetAllMachines();
                            addLog('SMT_LINE', 'PASS', `작업지시 [${item.wo_id || ''}] 자삽(SMT) 공정 완료 ➔ 수삽(DIP) 대기`);
                            if (typeof loadWOList === 'function') loadWOList();
                        }, 1000);
                    } else if (item.wo_status === 'DONE') {
                        setTimeout(() => {
                            resetAllMachines();
                            addLog('DIP_LINE', 'PASS', `작업지시 [${item.wo_id || ''}] 최종 생산 완료`);
                            if (typeof loadWOList === 'function') loadWOList();
                        }, 1000);
                    }
                });

                lastHistoryId = json.data.max_id;
            }
        }

        // 실시간 KPI 수치 동기화
        await syncKPI();

    } catch (e) {
        console.error("실시간 스트림 폴링 오류:", e);
    } finally {
        isPollingActive = false;
    }
}

// 7. 초기화 및 고속 실시간 루프 가동 (0.8초마다 자동 실행)
syncKPI().then(() => {
    pollLiveStream();
    setInterval(pollLiveStream, 800);
});
