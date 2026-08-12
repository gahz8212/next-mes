let totalCount = 0;
let failCount = 0;

function addLog(process, status, dataStr) {
    const logList = document.getElementById('log-list');
    const li = document.createElement('li');
    const time = new Date().toLocaleTimeString('ko-KR');
    const statusClass = status === 'PASS' ? 'log-pass' : 'log-fail';
    
    li.innerHTML = `<span class="log-time">[${time}]</span> <span style="color:#38bdf8">[${process}]</span> <span class="${statusClass}">${status}</span> - ${dataStr}`;
    logList.appendChild(li);
    logList.scrollTop = logList.scrollHeight; // 자동 스크롤
}

function updateMachine(processId, status, dataStr) {
    const mac = document.getElementById(`mac-${processId}`);
    const dataBox = document.getElementById(`data-${processId}`);
    if (!mac || !dataBox) return;

    // 클래스 초기화 후 상태 적용
    mac.className = 'machine';
    mac.classList.add(status === 'PASS' ? 'run' : 'error');
    dataBox.innerText = dataStr;

    // 3초 뒤 다시 Wait 상태로 (시연용 효과)
    setTimeout(() => {
        mac.className = 'machine wait';
        dataBox.innerText = '-';
    }, 3000);
}

function updateKPI(status) {
    totalCount++;
    if (status === 'FAIL') failCount++;
    
    const yieldRate = ((totalCount - failCount) / totalCount * 100).toFixed(1);
    
    document.getElementById('val-actual').innerText = totalCount;
    document.getElementById('val-yield').innerText = `${yieldRate}%`;
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
            
            // DB의 process_name(예: SMT_TOP, MOUNTER 등)
            const proc = item.process_name;
            const isPass = item.result_status;
            const barcode = item.barcode;
            
            // UI를 위한 매핑 처리 (필요에 따라 process_name 그대로 사용할 수도 있음)
            // dashboard.html에는 LASER, SPI, MOUNTER, REFLOW, DIP_AOI, WAVE 가 있음.
            // 만약 DB에서 들어온 proc이 위와 다르다면, 적절히 변환하거나 매칭되는 돔만 업데이트
            
            updateMachine(proc, isPass, `바코드: ${barcode}`);
            addLog(proc, isPass, `상태: ${item.status}, 바코드: ${barcode}`);
            
            // SMT_TOP 등 생산 완료성 공정일 때만 카운트 올리기 위해 임시로 모두 올림
            updateKPI(isPass);
            
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

// 스크립트 로드 시 SSE 연결 시작
connectSSE();
