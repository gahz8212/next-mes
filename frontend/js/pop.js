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

// 백엔드 API 연동
async function processBarcode(barcode) {
    clearTimeout(resetTimeout); // 기존 복귀 타이머 초기화
    
    try {
        const response = await fetch('../backend/api/update_process.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ barcode: barcode, process_name: 'MATERIAL_SETUP' })
        });
        
        const data = await response.json();
        
        if (response.ok && data.status === 'success') {
            const status = data.data?.result_status || 'PASS';
            if (status === 'PASS') {
                showResult('PASS', barcode, '자재 세팅이 정상적으로 승인되었습니다.');
            } else {
                showResult('FAIL', barcode, '유효기간 경과 또는 잘못된 자재입니다.');
            }
        } else {
            showResult('FAIL', barcode, data.message || '검증 실패');
        }
    } catch (err) {
        showResult('FAIL', barcode, '서버 통신 오류가 발생했습니다.');
    }
}

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
