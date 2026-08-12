import { NextResponse } from 'next/server';
import pool from '@/lib/db';

export async function POST(request: Request) {
  try {
    const body = await request.json();
    const { barcode, process_name, result_status } = body;

    // 필수 값 검증 (포카요케 기본 방어)
    if (!barcode || !process_name || !result_status) {
      return NextResponse.json(
        { status: 'error', message: '필수 데이터가 누락되었습니다.' },
        { status: 400 }
      );
    }

    const connection = await pool.getConnection();

    try {
      // 기존 DB의 barcode_history 테이블에 이력 기록 (쿼리는 기존과 동일)
      const query = `
        INSERT INTO barcode_history (barcode, process_name, result_status, created_at) 
        VALUES (?, ?, ?, NOW())
      `;
      await connection.execute(query, [barcode, process_name, result_status]);

      return NextResponse.json({
        status: 'success',
        message: '공정 처리 완료 (Next.js API)',
        data: { barcode, process_name, result_status }
      });
    } finally {
      connection.release();
    }

  } catch (error: any) {
    console.error('API Error:', error);
    return NextResponse.json(
      { status: 'error', message: error.message },
      { status: 500 }
    );
  }
}