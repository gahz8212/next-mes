const statusPanel = document.getElementById('status-panel');
const statusText = document.getElementById('status-text');
const instructionText = document.getElementById('instruction-text');
const barcodeBufferDisplay = document.getElementById('barcode-buffer');
const historyTbody = document.getElementById('history-tbody');

let barcodeBuffer = '';
let resetTimeout = null;

// [오디오 피드백] AudioContext를 활용한 비프음 생성
const audioCtx = new (window.AudioContext || window.webkitAudioContext)();

function playTone(frequency, duration) {
    if (audioCtx.state === 'suspended') {
        audioCtx.resume();
    }
    const oscillator = audioCtx.createOscillator();
    const gainNode = audioCtx.createGain();
    
    oscillator.type = 'sine';
    oscillator.frequency.setValueAtTime(frequency, audioCtx.currentTime);
    
    gainNode.gain.setValueAtTime(0.5, audioCtx.currentTime);
    gainNode.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + duration);
    
    oscillator.connect(gainNode);
    gainNode.connect(audioCtx.destination);
    
    oscillator.start();
    oscillator.stop(audioCtx.currentTime + duration);
}

const playPass = () => playTone(1200, 0.3); // 고음 (성공)
const playFail = () => playTone(200, 0.6);  // 저음 (실패)

// [마우스 프리] Global Keydown 리스너
document.addEventListener('keydown', (e) => {
    // 단축키 동작 무시
    if (e.ctrlKey || e.altKey || e.metaKey) return;
    
    if (e.key === 'Enter') {
        if (barcodeBuffer.trim() !== '') {
            processBarcode(barcodeBuffer.trim());
            barcodeBuffer = '';
            barcodeBufferDisplay.textContent = '';
        }
    } else if (e.key === 'Backspace') {
        barcodeBuffer = barcodeBuffer.slice(0, -1);
        barcodeBufferDisplay.textContent = barcodeBuffer;
    } else if (e.key.length === 1) { // 문자 입력
        barcodeBuffer += e.key;
        barcodeBufferDisplay.textContent = barcodeBuffer;
    }
});

// [오프라인 큐]
let offlineQueue = JSON.parse(localStorage.getItem('mes_offline_queue') || '[]');

// 백엔드 API 연동
async function processBarcode(barcode) {
    clearTimeout(resetTimeout); // 기존 복귀 타이머 초기화
    
    // 대시보드의 'Laser Marker' 설비에 연동되도록 공정명을 'LASER'로 지정
    const payload = { barcode: barcode, process_name: 'LASER', result_status: 'PASS' };

    if (!navigator.onLine) {
        // 오프라인 상태면 큐에 저장
        offlineQueue.push(payload);
        localStorage.setItem('mes_offline_queue', JSON.stringify(offlineQueue));
        showResult('FAIL', barcode, '네트워크 오프라인: 로컬에 임시 저장되었습니다.');
        return;
    }

    try {
        const response = await fetch('../backend/api/update_process.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        
        const data = await response.json();
        
        if (response.ok && data.status === 'success') {
            const status = data.data?.result_status || 'PASS';
            if (status === 'PASS') {
                showResult('PASS', barcode, '공정 처리가 정상적으로 완료되었습니다.');
            } else {
                showResult('FAIL', barcode, '처리 중 오류가 발생했습니다.');
            }
        } else {
            showResult('FAIL', barcode, data.message || '검증 실패 (순서 오류 등)');
        }
    } catch (err) {
        // 통신 에러 발생 시에도 큐에 저장
        offlineQueue.push(payload);
        localStorage.setItem('mes_offline_queue', JSON.stringify(offlineQueue));
        showResult('FAIL', barcode, '서버 통신 오류: 로컬에 임시 저장되었습니다.');
    }
}

// 오프라인 큐 동기화 (5초마다 확인)
setInterval(async () => {
    if (navigator.onLine && offlineQueue.length > 0) {
        const item = offlineQueue.shift(); // 첫 번째 항목 꺼내기
        try {
            const response = await fetch('../backend/api/update_process.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(item)
            });
            if (response.ok) {
                // 성공 시 큐 갱신
                localStorage.setItem('mes_offline_queue', JSON.stringify(offlineQueue));
                console.log('오프라인 데이터 동기화 완료:', item.barcode);
            } else {
                // 실패 시 다시 큐 맨 앞에 넣음
                offlineQueue.unshift(item);
            }
        } catch (e) {
            offlineQueue.unshift(item);
        }
    }
}, 5000);

// 스캔 결과 화면 표시 및 3초 복귀
function showResult(result, barcode, message) {
    // 1. 패널 UI 변경 및 사운드 출력
    statusPanel.className = 'main-panel';
    statusPanel.classList.add(result.toLowerCase());
    
    if (result === 'PASS') {
        statusText.textContent = 'PASS';
        instructionText.textContent = message;
        playPass();
    } else {
        statusText.textContent = 'REJECTED';
        instructionText.textContent = message;
        playFail();
    }
    
    // 2. 테이블에 이력 추가
    addHistoryRow(barcode, result, message);
    
    // 3. 3초 뒤 대기 상태로 복귀
    resetTimeout = setTimeout(() => {
        statusPanel.className = 'main-panel';
        statusText.textContent = 'WAITING FOR SCAN';
        instructionText.textContent = '바코드를 스캔하세요';
    }, 3000);
}

// 이력 테이블 로우 추가 (최대 5건)
function addHistoryRow(barcode, result, message) {
    const timeString = new Date().toLocaleTimeString('ko-KR', { hour12: false });
    const row = document.createElement('tr');
    const resultClass = result === 'PASS' ? 'result-pass' : 'result-fail';
    
    row.innerHTML = `
        <td>${timeString}</td>
        <td><strong>${barcode}</strong></td>
        <td class="${resultClass}">${result}</td>
        <td>${message}</td>
    `;
    
    historyTbody.prepend(row);
    
    // 최대 5건 유지
    while (historyTbody.children.length > 5) {
        historyTbody.removeChild(historyTbody.lastChild);
    }
}
