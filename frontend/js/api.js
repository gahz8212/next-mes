// frontend/js/api.js

const API_BASE_URL = '../backend/api'; // PHP API 경로

/**
 * 공정 스캔 결과 데이터 서버 전송
 */
export async function sendProcessUpdate(payload) {
    try {
        const response = await fetch(`${API_BASE_URL}/update_process.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });

        const result = await response.json();
        
        if (!response.ok) {
            throw new Error(result.message || '서버 통신 중 에러가 발생했습니다.');
        }

        return result;
    } catch (error) {
        console.error('API 요청 실패:', error);
        throw error;
    }
}

/**
 * 대시보드 실시간 데이터 가져오기 (Polling 용도)
 */
export async function fetchDashboardData() {
    try {
        const response = await fetch(`${API_BASE_URL}/get_dashboard.php`);
        if (!response.ok) {
            throw new Error('서버 통신 실패');
        }
        return await response.json();
    } catch (error) {
        console.error('대시보드 데이터 조회 실패:', error);
        throw error;
    }
}