let totalCount = 0;
let failCount = 0;
let currentTarget = 0;

// 페이지 로드 시 DB에서 실제 생산 실적을 가져와 KPI 초기값을 세팅
async function initKPI() {
    try {
        const res = await fetch('../backend/api/get_kpi.php');
        const json = await res.json();
        if (json.status === 'success' && json.data) {
            const d = json.data;
            currentTarget = d.target_qty;
            totalCount    = d.actual_qty;
            failCount     = d.fail_qty;

            document.getElementById('val-target').innerText = currentTarget;
            document.getElementById('val-actual').innerText = totalCount;
            document.getElementById('val-good').innerText   = totalCount - failCount;
            document.getElementById('val-fail').innerText   = failCount;
            const yieldRate = totalCount > 0
                ? ((totalCount - failCount) / totalCount * 100).toFixed(1)
                : '100.0';
            document.getElementById('val-yield').innerText = yieldRate + '%';
        }
    } catch(e) {
        console.error('KPI 초기화 실패:', e);
    }
}

function applyLogFilter() {
    const selProc = document.getElementById('filter-process').value;
    const selStat = document.getElementById('filter-status').value;
    const items = document.querySelectorAll('#log-list li');
    
    items.forEach(li => {
        if (!li.dataset.process) return; // Skip dummy initial message
        
        const matchProc = (selProc === 'ALL' || li.dataset.process === selProc);
        const matchStat = (selStat === 'ALL' || li.dataset.status === selStat);
        
        if (matchProc && matchStat) {
            li.style.display = '';
        } else {
            li.style.display = 'none';
        }
    });
}

function addLog(process, status, dataStr) {
    const logList = document.getElementById('log-list');
    const li = document.createElement('li');
    const time = new Date().toLocaleTimeString('ko-KR');
    const statusClass = status === 'PASS' ? 'log-pass' : 'log-fail';
    
    li.dataset.process = process;
    li.dataset.status = status;
    
    li.innerHTML = `<span class="log-time">[${time}]</span> <span style="color:#38bdf8">[${process}]</span> <span class="${statusClass}">${status}</span> - ${dataStr}`;
    logList.appendChild(li);
    
    // Apply current filter immediately to new log
    applyLogFilter();
    
    logList.scrollTop = logList.scrollHeight; // 자동 스크롤
}

function resetAllMachines() {
    document.querySelectorAll('.machine').forEach(mac => {
        mac.className = 'machine wait';
        const dataBox = mac.querySelector('.mac-data');
        if(dataBox) dataBox.innerText = '-';
    });
}

function updateMachine(processId, status, dataStr, pDataObj) {
    // UI 버그(잔상) 해결: 다른 장비에 동일한 바코드가 띄워져 있다면 빈 상태로 초기화
    document.querySelectorAll('.machine').forEach(mac => {
        if (mac.id !== `mac-${processId}`) {
            const box = mac.querySelector('.mac-data');
            if (box && box.innerText === dataStr) {
                mac.className = 'machine wait';
                box.innerText = '-';
            }
        }
    });

    const mac = document.getElementById(`mac-${processId}`);
    const dataBox = document.getElementById(`data-${processId}`);
    if (!mac || !dataBox) return;

    // 클래스 초기화 후 상태 적용
    mac.className = 'machine';
    mac.classList.add(status === 'PASS' ? 'run' : 'error');
    dataBox.innerText = dataStr;
    
    if (pDataObj) {
        mac.dataset.detail = JSON.stringify(pDataObj);
        mac.title = "Click to view detailed data";
        mac.onclick = () => alert(processId + " Data:\n" + JSON.stringify(pDataObj, null, 2));
    }
}

function updateKPI(status) {
    totalCount++;
    if (status === 'FAIL') failCount++;
    
    const yieldRate = ((totalCount - failCount) / totalCount * 100).toFixed(1);
    
    document.getElementById('val-actual').innerText = totalCount;
    if (document.getElementById('val-good')) {
        document.getElementById('val-good').innerText = totalCount - failCount;
        document.getElementById('val-fail').innerText = failCount;
    }
    document.getElementById('val-yield').innerText = yieldRate + '%';

    // 목표 양품 도달 시 (작업 완료) 모든 설비를 대기 상태로 초기화
    const goodCount = totalCount - failCount;
    if (currentTarget > 0 && goodCount >= currentTarget) {
        setTimeout(() => {
            resetAllMachines();
            addLog('SYSTEM', 'PASS', '작업 목표 달성. 모든 설비가 대기 상태로 전환되었습니다.');
            loadWOList(); // 목록 새로고침
        }, 1000);
    }
}

// [SSE 실시간 동기화 연동]
function connectSSE() {
    // EventSource 인스턴스 생성 (작성했던 dashboard_sse.php 연결)
    const evtSource = new EventSource('../backend/api/dashboard_sse.php');
    
    // 최초 연결 시 로그 출력
    evtSource.onopen = function() {
        const logList = document.getElementById('log-list');
        if(logList.innerHTML.includes('시스템 대기 중')) {
            logList.innerHTML = ''; // 기본 메시지 삭제
        }
        addLog('SYSTEM', 'PASS', 'SSE 실시간 연결 성공');
    };

    // 서버에서 데이터(push)가 넘어왔을 때
    evtSource.onmessage = function(event) {
        try {
            const item = JSON.parse(event.data);
            
            const proc = item.process_name;
            const isPass = item.result_status;
            const barcode = item.barcode;
            const pDataStr = item.process_data;
            const pDataObj = pDataStr ? JSON.parse(pDataStr) : null;
            
            updateMachine(proc, isPass, `바코드: ${barcode}`, pDataObj);
            addLog(proc, isPass, `상태: ${item.status}, 바코드: ${barcode} ${pDataStr ? pDataStr : ''}`);
            
            // 목표 수량 갱신
            if (item.target_qty) {
                currentTarget = parseInt(item.target_qty);
                document.getElementById('val-target').innerText = currentTarget;
            }

            // 생산 실적 카운트 (자삽 완료, 수삽 완료, 혹은 중간 불량 발생으로 인한 폐기 시)
            if (proc === 'REFLOW' || proc === 'WAVE' || isPass === 'FAIL') {
                updateKPI(isPass);
            }

            if (item.wo_status === 'SMT_DONE') {
                resetAllMachines(); // SMT 완료 시 설비 초기화 보장
            } else if (item.wo_status === 'DONE') {
                resetAllMachines(); // 최종 완료 시 설비 초기화 보장
            }
            
        } catch(e) {
            console.error("데이터 파싱 에러:", e);
        }
    };
    
    // 에러 발생 시 재연결 처리
    evtSource.onerror = function() {
        addLog('SYSTEM', 'FAIL', 'SSE 연결 끊어짐... 재연결 시도 중');
        evtSource.close();
        setTimeout(connectSSE, 5000); // 5초 뒤 재연결
    };
}

// 스크립트 로드 시: DB에서 KPI 초기값 로드 후 SSE 연결 시작
initKPI().then(() => connectSSE());
