<?php
// backend/controllers/CompanyController.php

class CompanyController {
    /**
     * 1. 거래처 기본 목록 조회 (GET /companies 또는 get_companies.php)
     */
    public static function getCompanies(): void {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->query("SELECT * FROM company ORDER BY name ASC");
            Response::json(["status" => "success", "data" => $stmt->fetchAll()]);
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 2. 거래처 상세 목록 조회 (GET /companies/detail 또는 get_companies_detail.php)
     */
    public static function getCompaniesDetail(): void {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->query("SELECT id, name, code, tel, email, memo, bom_mapping, created_at FROM company ORDER BY name ASC");
            Response::json(["status" => "success", "data" => $stmt->fetchAll()]);
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 3. 거래처별 실적 및 통계 조회 (GET /companies/stats 또는 get_company_stats.php)
     */
    public static function getCompanyStats(): void {
        try {
            $pdo = Database::getConnection();
            $sql = "SELECT 
              c.id, c.name, c.code, c.tel, c.email,
              COUNT(w.wo_id) as total_wo,
              SUM(CASE WHEN w.status = 'DONE' THEN 1 ELSE 0 END) as done_wo,
              SUM(CASE WHEN w.status NOT IN ('DONE') THEN 1 ELSE 0 END) as active_wo,
              COALESCE(SUM(w.target_qty), 0) as total_qty,
              MAX(w.due_date) as last_due_date
            FROM company c
            LEFT JOIN work_order w ON c.id = w.company_id
            GROUP BY c.id, c.name, c.code, c.tel, c.email
            ORDER BY c.name ASC";

            $stmt = $pdo->query($sql);
            Response::json(["status" => "success", "data" => $stmt->fetchAll()]);
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 4. 거래처 등록 (POST /companies 또는 create_company.php)
     */
    public static function createCompany(): void {
        try {
            $pdo = Database::getConnection();
            $input = Request::getBody();
            $name = trim($input['name'] ?? '');

            if (!$name) {
                Response::error("업체명을 입력하세요.");
            }

            $code = strtoupper(bin2hex(random_bytes(1))); // 2-hex char code
            $stmt = $pdo->prepare("INSERT INTO company (name, code) VALUES (?, ?)");
            $stmt->execute([$name, $code]);
            $id = (int)$pdo->lastInsertId();

            Response::json([
                "status" => "success",
                "data" => ["id" => $id, "name" => $name, "code" => $code]
            ]);
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 5. 거래처 수정 (PUT /companies/{id} 또는 POST update_company.php)
     */
    public static function updateCompany(?string $id = null): void {
        try {
            $pdo = Database::getConnection();
            $input = Request::getBody();
            $companyId = $id ?? ($input['id'] ?? null);
            $name = trim($input['name'] ?? '');

            if (empty($companyId) || $name === '') {
                Response::error("필수 입력 항목(id, name)이 누락되었습니다.");
            }

            $tel   = isset($input['tel']) ? trim($input['tel']) : null;
            $email = isset($input['email']) ? trim($input['email']) : null;
            $memo  = isset($input['memo']) ? trim($input['memo']) : null;

            $stmt = $pdo->prepare("UPDATE company SET name=?, tel=?, email=?, memo=? WHERE id=?");
            $stmt->execute([$name, $tel, $email, $memo, $companyId]);

            Response::json(["status" => "success", "message" => "업체 정보가 수정되었습니다."]);
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 6. 거래처 삭제 (DELETE /companies/{id} 또는 POST delete_company.php)
     */
    public static function deleteCompany(?string $id = null): void {
        try {
            $pdo = Database::getConnection();
            $input = Request::getBody();
            $companyId = $id ?? ($input['id'] ?? null);

            if (empty($companyId)) {
                Response::error("필수 입력 항목(id)이 누락되었습니다.");
            }

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM work_order WHERE company_id = ?");
            $stmt->execute([$companyId]);
            $count = (int)$stmt->fetchColumn();

            if ($count > 0) {
                Response::error("연결된 작업지시(work_order)가 있어 삭제할 수 없습니다.");
            }

            $stmt = $pdo->prepare("DELETE FROM company WHERE id = ?");
            $stmt->execute([$companyId]);

            Response::json(["status" => "success", "message" => "업체가 삭제되었습니다."]);
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }
}
