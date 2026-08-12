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

// 백엔드 API에서 실시간으로 데이터를 가져오는 로직 (Polling)
async function fetchDashboardData() {
    try {
        // 실제 백엔드 연동 시 아래 경로를 맞게 수정 (/api/get_dashboard.php 등)
        const response = await fetch('../backend/api/get_dashboard_data.php');
        
        // 응답이 없거나 404면 시연용 가짜 데이터를 생성하도록 분기처리
        if (!response.ok) throw new Error('API Not Ready');
        
        const result = await response.json();
        if(result && result.length > 0) {
            result.forEach(item => {
                updateMachine(item.process, item.status, item.data);
                addLog(item.process, item.status, JSON.stringify(item.data));
                updateKPI(item.status);
            });
        }
    } catch (e) {
        // [데모 모드]: 백엔드 API가 아직 없어도 시연 화면이 작동하도록 가짜 데이터 발생
        demoSimulation();
    }
}

// 시연(Demo)용 가상 데이터 생성 로직
const processes = ['LASER', 'SPI', 'MOUNTER', 'REFLOW', 'DIP_AOI', 'WAVE'];
function demoSimulation() {
    if(Math.random() > 0.7) { // 30% 확률로 이벤트 발생
        const proc = processes[Math.floor(Math.random() * processes.length)];
        const isPass = Math.random() > 0.1 ? 'PASS' : 'FAIL'; // 10% 확률 불량
        const mockData = proc === 'SPI' ? `체적: ${Math.floor(Math.random()*20 + 90)}%` : 
                         proc === 'REFLOW' ? `Temp: 24${Math.floor(Math.random()*5)}℃` : `바코드 스캔 완료`;
        
        updateMachine(proc, isPass, mockData);
        addLog(proc, isPass, mockData);
        updateKPI(isPass);
    }
}

// 2초마다 갱신
setInterval(fetchDashboardData, 2000);
