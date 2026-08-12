// frontend/js/bom-upload.js

const dropZone = document.getElementById('drop-zone');
const fileInput = document.getElementById('file-input');
const bomPreviewBody = document.getElementById('bom-preview-body');
const bomStatus = document.getElementById('bom-status');
const createWoBtn = document.getElementById('create-wo-btn');

// 임시 저장용 파싱 데이터
let parsedBOM = [];

// 드래그 앤 드롭 이벤트 처리
dropZone.addEventListener('click', () => fileInput.click());

dropZone.addEventListener('dragover', (e) => {
    e.preventDefault();
    dropZone.classList.add('dragover');
});

dropZone.addEventListener('dragleave', () => {
    dropZone.classList.remove('dragover');
});

dropZone.addEventListener('drop', (e) => {
    e.preventDefault();
    dropZone.classList.remove('dragover');
    if (e.dataTransfer.files.length) {
        handleFile(e.dataTransfer.files[0]);
    }
});

fileInput.addEventListener('change', (e) => {
    if (e.target.files.length) {
        handleFile(e.target.files[0]);
    }
});

function handleFile(file) {
    if (!file.name.endsWith('.csv')) {
        alert('CSV 파일만 업로드 가능합니다.');
        return;
    }

    // 파일명 표시
    const textEl = dropZone.querySelector('.drop-zone-text');
    textEl.textContent = `선택된 파일: ${file.name}`;
    textEl.style.color = 'var(--primary)';

    const reader = new FileReader();
    reader.onload = (e) => {
        const text = e.target.result;
        parseCSV(text);
    };
    reader.readAsText(file);
}

function parseCSV(text) {
    // 아주 간단한 CSV 파서 (예시)
    const lines = text.split('\n').filter(line => line.trim().length > 0);
    parsedBOM = [];
    
    // 첫 줄 헤더 생략 후 데이터 파싱
    for (let i = 1; i < lines.length; i++) {
        const cols = lines[i].split(',').map(c => c.trim().replace(/"/g, ''));
        if (cols.length >= 3) {
            parsedBOM.push({
                ref: cols[0],
                partNo: cols[1],
                qty: cols[2]
            });
        }
    }

    // 가상 데이터가 없을 경우를 대비한 Mock 처리
    if (parsedBOM.length === 0) {
        parsedBOM = [
            { ref: 'C101, C102', partNo: 'CAP-0402-104', qty: '2' },
            { ref: 'R201', partNo: 'RES-0603-10K', qty: '1' },
            { ref: 'U1', partNo: 'IC-MCU-32BIT', qty: '1' }
        ];
    }

    renderPreview();
}

function renderPreview() {
    bomStatus.textContent = '분석 완료';
    bomStatus.classList.remove('badge-primary');
    bomStatus.classList.add('badge-success');

    bomPreviewBody.innerHTML = parsedBOM.map(item => `
        <tr style="animation: fadeIn 0.3s ease-out;">
            <td class="font-mono text-muted">${item.ref}</td>
            <td class="font-mono font-bold" style="color: var(--primary);">${item.partNo}</td>
            <td><span class="badge badge-outline">${item.qty} 개</span></td>
        </tr>
    `).join('');
}

// 작업 지시서 발행 로직
createWoBtn.addEventListener('click', () => {
    const modelName = document.getElementById('model-name').value;
    const targetQty = document.getElementById('target-qty').value;
    const line = document.getElementById('line-select').value;

    if (!modelName || !targetQty) {
        alert('제품명과 목표 수량을 모두 입력해주세요.');
        return;
    }
    
    if (parsedBOM.length === 0) {
        alert('먼저 BOM(CSV) 파일을 업로드해주세요.');
        return;
    }

    // 버튼 로딩 효과
    const originalText = createWoBtn.innerHTML;
    createWoBtn.innerHTML = '발행 중...';
    createWoBtn.style.opacity = '0.7';
    createWoBtn.disabled = true;

    // 실제로는 여기서 fetch('/backend/api/create_wo.php') 등으로 서버 전송
    setTimeout(() => {
        alert(`[작업 지시 완료]\n모델: ${modelName}\n수량: ${targetQty}개\n배정: ${line}\n해당 라인으로 지시서가 전송되었습니다.`);
        
        // 원상복구
        createWoBtn.innerHTML = originalText;
        createWoBtn.style.opacity = '1';
        createWoBtn.disabled = false;
        
        // 대시보드로 이동할 수도 있음
        // window.location.href = 'dashboard.html';
    }, 800);
});
