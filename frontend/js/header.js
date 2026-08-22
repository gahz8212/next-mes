/**
 * MES PRO 공통 헤더 네비게이션 & 세션 관리자
 */

const SCREEN_INFO = {
    admin: {
        name: '관리자 콘솔',
        url: 'admin.html',
        icon: '⚙️',
        label: '관리자 (Admin)'
    },
    dashboard: {
        name: '통합 라인 관제',
        url: 'dashboard.html',
        icon: '📊',
        label: '라인 대시보드'
    },
    kitting: {
        name: '자재 피킹 (Kitting)',
        url: 'kitting.html',
        icon: '📦',
        label: '자재 피킹'
    },
    machine: {
        name: '설비 HMI 관제',
        url: 'machine.html',
        icon: '🔧',
        label: '설비 HMI'
    },
    ceo: {
        name: '최고경영자 브리핑',
        url: 'ceo.html',
        icon: '👑',
        label: '경영진 뷰'
    }
};

/**
 * 공통 로그아웃 함수
 */
function mesLogout() {
    localStorage.removeItem('role');
    localStorage.removeItem('userRole');
    localStorage.removeItem('roleName');
    localStorage.removeItem('userName');
    window.location.href = 'login.html';
}

/**
 * 역할 계층 및 홈 화면 스펙 정의
 */
const ROLE_SPECS = {
    ceo: {
        level: 4,
        homeKey: 'ceo',
        homeUrl: 'ceo.html',
        homeLabel: '경영진 뷰',
        returnBtnClass: 'btn-ceo',
        returnIcon: '👑',
        allowedScreens: ['ceo', 'admin', 'dashboard', 'kitting', 'machine']
    },
    admin: {
        level: 3,
        homeKey: 'admin',
        homeUrl: 'admin.html',
        homeLabel: '관리자 콘솔',
        returnBtnClass: '',
        returnIcon: '⚙️',
        allowedScreens: ['admin', 'dashboard', 'kitting', 'machine']
    },
    supervisor: {
        level: 2,
        homeKey: 'dashboard',
        homeUrl: 'dashboard.html',
        homeLabel: '라인 대시보드',
        returnBtnClass: 'btn-supervisor',
        returnIcon: '📊',
        allowedScreens: ['dashboard', 'kitting', 'machine']
    },
    kitting: {
        level: 1,
        homeKey: 'kitting',
        homeUrl: 'kitting.html',
        homeLabel: '자재 피킹',
        allowedScreens: ['kitting', 'machine']
    },
    machine: {
        level: 1,
        homeKey: 'machine',
        homeUrl: 'machine.html',
        homeLabel: '설비 HMI',
        allowedScreens: ['kitting', 'machine']
    },
    worker: {
        level: 1,
        homeKey: 'kitting',
        homeUrl: 'kitting.html',
        homeLabel: '자재 피킹',
        allowedScreens: ['kitting', 'machine']
    }
};

const SCREEN_LEVELS = {
    ceo: 4,
    admin: 3,
    dashboard: 2,
    kitting: 1,
    machine: 1
};

/**
 * 상단 헤더 동적 렌더링 및 세션 바인딩
 * @param {string} currentKey - 현재 화면 키 ('admin' | 'dashboard' | 'kitting' | 'machine' | 'ceo')
 */
function initMesHeader(currentKey) {
    const role = localStorage.getItem('role');
    let userRole = localStorage.getItem('userRole') || (role === 'admin' ? 'admin' : 'worker');
    if (!ROLE_SPECS[userRole]) userRole = (role === 'admin' ? 'admin' : 'worker');

    const userSpec = ROLE_SPECS[userRole] || ROLE_SPECS.worker;
    const currentScreenLevel = SCREEN_LEVELS[currentKey] || 1;

    const userName = localStorage.getItem('userName') || (userRole === 'admin' ? '관리자' : (userRole === 'ceo' ? '대표이사' : '작업자'));

    // 1. 로그인 사용자 이름/담당자 텍스트 업데이트
    const userNameEl = document.getElementById('hdrUserName');
    if (userNameEl) {
        userNameEl.textContent = userName;
    }

    // 2. 로그인 사용자의 역할에 따른 중앙 네비게이션 버튼 동적 렌더링
    const navCenter = document.querySelector('.mes-header-center');
    if (navCenter && userSpec.allowedScreens) {
        let navHtml = '';
        userSpec.allowedScreens.forEach(scrKey => {
            const scr = SCREEN_INFO[scrKey];
            if (!scr) return;
            const isActive = (scrKey === currentKey);
            let targetUrl = scr.url;
            if (scrKey === 'admin') {
                try {
                    const savedTab = localStorage.getItem('mes_admin_active_tab');
                    if (savedTab) targetUrl = `admin.html#${savedTab}`;
                } catch(e) {}
            }
            navHtml += `
                <a href="${targetUrl}" class="mes-nav-btn ${isActive ? 'active' : ''}" data-screen="${scrKey}">
                    <span class="nav-icon">${scr.icon}</span>
                    <span>${scr.label}</span>
                </a>
            `;
        });
        navCenter.innerHTML = navHtml;
    }

    // 3. 현재 화면이 로그인 사용자의 본래 권한(Home)보다 낮은 화면일 때 스마트 복귀 버튼 생성
    if (userSpec.level > currentScreenLevel && userSpec.homeKey !== currentKey) {
        const headerRight = document.querySelector('.mes-header-right');
        if (headerRight && !document.getElementById('mesRoleReturnBtn')) {
            const returnBtn = document.createElement('a');
            returnBtn.id = 'mesRoleReturnBtn';
            let homeUrl = userSpec.homeUrl;
            if (userSpec.homeKey === 'admin') {
                try {
                    const savedTab = localStorage.getItem('mes_admin_active_tab');
                    if (savedTab) homeUrl = `admin.html#${savedTab}`;
                } catch(e) {}
            }
            returnBtn.href = homeUrl;
            returnBtn.className = `mes-return-btn ${userSpec.returnBtnClass || ''}`;
            returnBtn.innerHTML = `
                <span>${userSpec.returnIcon}</span>
                <span>${userSpec.homeLabel} 복귀</span>
            `;
            // 헤더 우측 맨 앞에 삽입
            headerRight.insertBefore(returnBtn, headerRight.firstChild);
        }
    }
}

// ── 공통 알림 센터 컨트롤러 (Notification Center) ──
async function loadMesNotifications() {
    const countBadge = document.getElementById('noti-count');
    const listEl = document.getElementById('noti-list');
    if (!countBadge && !listEl) return;

    try {
        const res = await fetch('../backend/api/get_notifications.php');
        const json = await res.json();
        if (json.status === 'success' && json.data) {
            const { unread_count, notifications } = json.data;
            if (countBadge) {
                countBadge.textContent = unread_count;
                countBadge.style.display = unread_count > 0 ? 'inline-block' : 'none';
            }
            if (listEl) {
                if (!notifications || notifications.length === 0) {
                    listEl.innerHTML = '<div style="padding:20px; text-align:center; color:var(--hdr-muted); font-size:12px;">새로운 알림이 없습니다.</div>';
                } else {
                    listEl.innerHTML = notifications.map(n => {
                        const isUnread = parseInt(n.is_read) === 0;
                        let tagClass = 'tag-system';
                        if (n.type === 'URGENT' || n.type === 'ALARM') tagClass = 'tag-urgent';
                        else if (n.type === 'DEFECT') tagClass = 'tag-defect';
                        else if (n.type === 'ORDER' || n.type === 'WO') tagClass = 'tag-order';
                        else if (n.type === 'MATERIAL') tagClass = 'tag-material';

                        return `
                            <div class="noti-item ${isUnread ? 'unread' : ''}" onclick="onMesNotificationClick(${n.id}, '${n.link_url || ''}')">
                                <div class="noti-item-top">
                                    <span class="noti-type-tag ${tagClass}">${n.type || '알림'}</span>
                                    <span class="noti-time">${n.created_at ? n.created_at.substring(11, 16) : ''}</span>
                                </div>
                                <div class="noti-title">${n.title || ''}</div>
                                <div class="noti-msg">${n.message || ''}</div>
                            </div>
                        `;
                    }).join('');
                }
            }
        }
    } catch (e) {
        // fail silently
    }
}
window.loadMesNotifications = loadMesNotifications;

function toggleNotificationPopup(event) {
    if (event) event.stopPropagation();
    const dropdown = document.getElementById('notiDropdown');
    if (dropdown) {
        dropdown.classList.toggle('open');
        if (dropdown.classList.contains('open')) {
            loadMesNotifications();
        }
    }
}
window.toggleNotificationPopup = toggleNotificationPopup;

async function markAllNotificationsRead() {
    try {
        await fetch('../backend/api/read_notification.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ read_all: true })
        });
        loadMesNotifications();
    } catch(e) {}
}
window.markAllNotificationsRead = markAllNotificationsRead;

async function onMesNotificationClick(id, linkUrl) {
    try {
        await fetch('../backend/api/read_notification.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        });
        loadMesNotifications();
        if (linkUrl && linkUrl !== '#' && linkUrl !== '') {
            window.location.href = linkUrl;
        }
    } catch(e) {}
}
window.onMesNotificationClick = onMesNotificationClick;

// 문서 바깥 클릭 시 알림 드롭다운 닫기
document.addEventListener('click', (e) => {
    const wrap = document.querySelector('.noti-wrap');
    const dropdown = document.getElementById('notiDropdown');
    if (dropdown && dropdown.classList.contains('open')) {
        if (!wrap || !wrap.contains(e.target)) {
            dropdown.classList.remove('open');
        }
    }
});

// ── 시스템 리셋 컨트롤러 (전체 초기화 & 실적 트랜잭션 초기화) ──
async function confirmFullReset() {
    const ok = confirm(
        "⚡ [전체 공장 데이터 초기화]\n\n" +
        "수주, 작업지시, 사급 자재, 바코드 실적, 출하 이력, 알림 등 모든 데이터와 시뮬레이션을 공장 출하 초기 데모 상태로 완전 리셋합니다.\n\n" +
        "정말 전체 초기화를 진행하시겠습니까?"
    );
    if (!ok) return;

    try {
        const res = await fetch('../backend/api/reset_system.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ mode: 'full' })
        });
        const text = await res.text();
        let json;
        try {
            json = JSON.parse(text);
        } catch(err) {
            throw new Error(`서버 응답 오류 (HTTP ${res.status}): ${text.substring(0, 100)}`);
        }
        if (json.status === 'success') {
            alert(json.message || "전체 공장 데이터가 성공적으로 초기화되었습니다.");
            window.location.reload();
        } else {
            alert("초기화 실패: " + (json.message || "알 수 없는 오류"));
        }
    } catch(e) {
        alert("서버 통신 오류: " + e.message);
    }
}
window.confirmFullReset = confirmFullReset;

async function confirmTransactionReset() {
    const ok = confirm(
        "🔄 [생산/수주 트랜잭션 실적 초기화]\n\n" +
        "거래처, 품목, 기준 BOM, 사용자 등 기초 마스터 정보는 안전하게 보존하고,\n" +
        "진행 중이던 수주, 작업지시, 바코드, 출하, 설비 가동 실적만 처음 대기(READY) 상태로 깨끗이 되돌립니다.\n\n" +
        "실적 초기화를 진행하시겠습니까?"
    );
    if (!ok) return;

    try {
        const res = await fetch('../backend/api/reset_system.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ mode: 'transactions' })
        });
        const text = await res.text();
        let json;
        try {
            json = JSON.parse(text);
        } catch(err) {
            throw new Error(`서버 응답 오류 (HTTP ${res.status}): ${text.substring(0, 100)}`);
        }
        if (json.status === 'success') {
            alert(json.message || "트랜잭션 실적이 성공적으로 초기화되었습니다.");
            window.location.reload();
        } else {
            alert("초기화 실패: " + (json.message || "알 수 없는 오류"));
        }
    } catch(e) {
        alert("서버 통신 오류: " + e.message);
    }
}
window.confirmTransactionReset = confirmTransactionReset;

// ── 상단 오토 하이드 헤더 마우스 감지 제어 ──
function setupAutoHideHeader() {
    const headerEl = document.querySelector('.mes-unified-header');
    if (!headerEl) return;

    // 상단 마우스 감지 트리거 및 핸들 바 동적 생성 (없을 경우)
    if (!document.getElementById('mesTopHoverTrigger')) {
        const trigger = document.createElement('div');
        trigger.id = 'mesTopHoverTrigger';
        trigger.className = 'mes-top-hover-trigger';
        document.body.prepend(trigger);

        const handle = document.createElement('div');
        handle.id = 'mesTopHoverHandle';
        handle.className = 'mes-top-hover-handle';
        handle.title = '마우스를 상단으로 가져가면 전체 메뉴 및 로그아웃이 나타납니다';
        document.body.prepend(handle);
    }

    let hideTimer = null;

    const showHeader = () => {
        if (hideTimer) {
            clearTimeout(hideTimer);
            hideTimer = null;
        }
        headerEl.classList.add('header-revealed');
    };

    const scheduleHide = () => {
        if (hideTimer) clearTimeout(hideTimer);
        hideTimer = setTimeout(() => {
            headerEl.classList.remove('header-revealed');
        }, 300);
    };

    // 마우스 Y 좌표 감지
    document.addEventListener('mousemove', (e) => {
        if (e.clientY <= 14) {
            showHeader();
        } else if (e.clientY > 65 && !headerEl.matches(':hover') && !headerEl.contains(document.activeElement)) {
            scheduleHide();
        }
    });

    headerEl.addEventListener('mouseenter', showHeader);
    headerEl.addEventListener('mouseleave', scheduleHide);
}
window.setupAutoHideHeader = setupAutoHideHeader;

// 자동 실행 (DOMContentLoaded)
document.addEventListener('DOMContentLoaded', () => {
    const headerEl = document.querySelector('.mes-unified-header');
    if (headerEl && headerEl.dataset.currentScreen) {
        initMesHeader(headerEl.dataset.currentScreen);
    }
    setupAutoHideHeader();
    loadMesNotifications();
    setInterval(loadMesNotifications, 10000);
});
