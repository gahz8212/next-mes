'use client';

import { useState, useEffect } from 'react';

interface HistoryItem {
  id: number;
  barcode: string;
  process_name: string;
  result_status: string;
  created_at: string;
}

export default function MesDashboard() {
  const [productionCount, setProductionCount] = useState<number>(0);
  const [historyList, setHistoryList] = useState<HistoryItem[]>([]);
  const [isSimulating, setIsSimulating] = useState<boolean>(false);

  // 주기적으로 최신 데이터(생산 이력)를 가져오는 폴링 함수 (웹소켓 전단계의 확실한 뼈대)
  useEffect(() => {
    // 임시로 화면에 마운트될 때 연동 테스트용 데이터를 불러오는 로직을 둘 수 있습니다.
    console.log("MES 관제 대시보드 클라이언트 마운트 완료");
  }, []);

  // 가상 기계 작동 테스트 버튼 (클릭 시 Next.js API를 직접 찌름)
  const handleSimulateScan = async () => {
    const randomSeq = Math.floor(Math.random() * 1000).toString().padStart(4, '0');
    const testData = {
      barcode: `WO-TEST-001-${randomSeq}`,
      process_name: 'SMT_TOP',
      result_status: Math.random() > 0.1 ? 'PASS' : 'FAIL', // 10프로 확률로 불량 테스트
    };

    try {
      const res = await fetch('/api/update_process', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(testData),
      });

      const result = await res.json();
      if (result.status === 'success') {
        setProductionCount((prev) => prev + 1);
        setHistoryList((prev) => [
          {
            id: Date.now(),
            barcode: testData.barcode,
            process_name: testData.process_name,
            result_status: testData.result_status,
            created_at: new Date().toLocaleTimeString(),
          },
          ...prev.slice(0, 9), // 최근 10개만 유지
        ]);
      }
    } catch (error) {
      console.error('시뮬레이션 전송 실패:', error);
    }
  };

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100 p-8">
      {/* 상단 헤더 */}
      <header className="flex justify-between items-center mb-8 border-b border-slate-800 pb-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-emerald-400">⚡ Smart MES 관제 대시보드</h1>
          <p className="text-sm text-slate-400">Next.js App Router 기반 실시간 스마트 팩토리 모니터링 시스템</p>
        </div>
        <div className="flex items-center gap-3">
          <span className="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-emerald-950 text-emerald-400 border border-emerald-800">
            ● SYSTEM ONLINE
          </span>
        </div>
      </header>

      {/* 메인 그리드 지표 섹션 */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <div className="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-xl">
          <h3 className="text-sm font-medium text-slate-400 mb-2">총 누적 생산량 (Today)</h3>
          <div className="text-4xl font-extrabold text-white tracking-tight">{productionCount} <span className="text-lg font-normal text-slate-400">EA</span></div>
        </div>
        <div className="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-xl">
          <h3 className="text-sm font-medium text-slate-400 mb-2">현재 가동 설비</h3>
          <div className="text-4xl font-extrabold text-blue-400 tracking-tight">SMT 라인 #1</div>
        </div>
        <div className="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-xl flex flex-col justify-between">
          <h3 className="text-sm font-medium text-slate-400">가상 기계 수동 제어</h3>
          <button 
            onClick={handleSimulateScan}
            className="mt-2 w-full bg-emerald-600 hover:bg-emerald-500 text-white font-semibold py-2.5 px-4 rounded-lg transition-colors shadow-lg shadow-emerald-900/30 active:scale-95"
          >
            기계 스캔 시뮬레이션 (삐빅!)
          </button>
        </div>
      </div>

      {/* 하단 실시간 이력 테이블 */}
      <div className="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-xl">
        <h3 className="text-lg font-semibold mb-4 text-slate-200">실시간 공정 스캔 이력</h3>
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm text-slate-300">
            <thead className="bg-slate-950 text-slate-400 uppercase text-xs tracking-wider border-b border-slate-800">
              <tr>
                <th className="py-3 px-4">시간</th>
                <th className="py-3 px-4">바코드 번호</th>
                <th className="py-3 px-4">공정명</th>
                <th className="py-3 px-4">판정 결과</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-800">
              {historyList.length === 0 ? (
                <tr>
                  <td colSpan={4} className="py-8 text-center text-slate-500">
                    아직 수신된 스캔 데이터가 없습니다. 위의 시뮬레이션 버튼을 눌러보세요!
                  </td>
                </tr>
              ) : (
                historyList.map((item) => (
                  <tr key={item.id} className="hover:bg-slate-800/50 transition-colors">
                    <td className="py-3 px-4 font-mono text-slate-400">{item.created_at}</td>
                    <td className="py-3 px-4 font-mono text-white font-medium">{item.barcode}</td>
                    <td className="py-3 px-4">{item.process_name}</td>
                    <td className="py-3 px-4">
                      <span className={`px-2.5 py-1 rounded-md text-xs font-bold ${
                        item.result_status === 'PASS' 
                          ? 'bg-emerald-950 text-emerald-400 border border-emerald-800' 
                          : 'bg-rose-950 text-rose-400 border border-rose-800'
                      }`}>
                        {item.result_status}
                      </span>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}