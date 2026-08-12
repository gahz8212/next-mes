// src/lib/db.ts
import mysql from 'mysql2/promise';

const pool = mysql.createPool({
  host: 'localhost',
  user: 'root', // 실제 DB 계정으로 변경
  password: 'your_password_here', // 실제 DB 비밀번호로 변경
  database: 'smt_mes_db',
  port: 3307, 
  waitForConnections: true,
  connectionLimit: 10,
  queueLimit: 0
});

export default pool;