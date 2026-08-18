<?php
// backend/controllers/ShipmentController.php

class ShipmentController {
    /**
     * 1. 출하 목록 및 집계 조회
     */
    public static function getShipments(): void {
        try {
            $pdo = Database::getConnection();
            $startDate = Request::query('start_date', date('Y-m-d', strtotime('-30 days')));
            $endDate   = Request::query('end_date', date('Y-m-d'));
            $status    = trim(Request::query('status', ''));

            // Summary
            $summarySql = "SELECT COUNT(*) as total, COALESCE(SUM(ship_qty), 0) as total_qty,
              SUM(CASE WHEN status='SHIPPED' THEN 1 ELSE 0 END) as shipped_count,
              SUM(CASE WHEN status='PENDING' THEN 1 ELSE 0 END) as pending_count
            FROM shipment
            WHERE DATE(ship_date) BETWEEN :start AND :end";

            $stmtSum = $pdo->prepare($summarySql);
            $stmtSum->execute([
                ':start' => $startDate,
                ':end'   => $endDate
            ]);
            $summaryData = $stmtSum->fetch();

            $summary = [
                'total'         => (int)($summaryData['total'] ?? 0),
                'total_qty'     => (float)($summaryData['total_qty'] ?? 0),
                'shipped_count' => (int)($summaryData['shipped_count'] ?? 0),
                'pending_count' => (int)($summaryData['pending_count'] ?? 0),
            ];

            // Records
            $recordsSql = "SELECT s.*, w.target_qty, c.name as company_name,
              (SELECT COALESCE(SUM(b.status!='WAIT'), 0) FROM barcode_master b WHERE b.wo_id=s.wo_id) as processed_qty
            FROM shipment s
            LEFT JOIN work_order w ON s.wo_id = w.wo_id
            LEFT JOIN company c ON s.company_id = c.id
            WHERE DATE(s.ship_date) BETWEEN :start AND :end";

            $recParams = [
                ':start' => $startDate,
                ':end'   => $endDate
            ];

            if ($status !== '') {
                $recordsSql .= " AND s.status = :status";
                $recParams[':status'] = $status;
            }
            $recordsSql .= " ORDER BY s.ship_date DESC";

            $stmtRec = $pdo->prepare($recordsSql);
            $stmtRec->execute($recParams);
            $records = $stmtRec->fetchAll();

            Response::json([
                "status" => "success",
                "data"   => [
                    "summary" => $summary,
                    "records" => $records
                ]
            ]);

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 2. 출하 등록
     */
    public static function createShipment(): void {
        try {
            $pdo = Database::getConnection();
            $input = Request::getBody();

            $woId      = trim($input['wo_id'] ?? '');
            $shipQty   = $input['ship_qty'] ?? null;
            $shipDate  = trim($input['ship_date'] ?? '');
            $companyId = !empty($input['company_id']) ? (int)$input['company_id'] : null;
            $invoiceNo = trim($input['invoice_no'] ?? '') ?: null;
            $note      = trim($input['note'] ?? '') ?: null;

            if (empty($woId) || !is_numeric($shipQty) || (float)$shipQty <= 0 || empty($shipDate)) {
                Response::error("필수 입력 항목(wo_id, ship_qty(>0), ship_date)을 확인해주세요.");
            }

            $sql = "INSERT INTO shipment (wo_id, ship_qty, ship_date, company_id, invoice_no, note)
                    VALUES (:wo_id, :ship_qty, :ship_date, :company_id, :invoice_no, :note)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':wo_id'      => $woId,
                ':ship_qty'   => $shipQty,
                ':ship_date'  => $shipDate,
                ':company_id' => $companyId,
                ':invoice_no' => $invoiceNo,
                ':note'       => $note
            ]);

            $id = (int)$pdo->lastInsertId();
            Response::json(["status" => "success", "data" => ["id" => $id]]);

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 3. 출하 상태 변경
     */
    public static function updateShipmentStatus(?string $id = null): void {
        $pdo = Database::getConnection();
        try {
            $input = Request::getBody();
            $shipId = $id ?? ($input['id'] ?? null);
            $status = strtoupper(trim($input['status'] ?? ''));

            if (empty($shipId) || !in_array($status, ['PENDING', 'SHIPPED', 'CANCELLED'])) {
                Response::error("필수 입력 항목(id, status: PENDING/SHIPPED/CANCELLED)을 확인해주세요.");
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE shipment SET status = ? WHERE id = ?");
            $stmt->execute([$status, $shipId]);

            if ($status === 'SHIPPED') {
                $stmtWo = $pdo->prepare("UPDATE work_order SET shipped = 1, shipped_at = NOW() WHERE wo_id = (SELECT wo_id FROM shipment WHERE id = ?)");
                $stmtWo->execute([$shipId]);
            }

            $pdo->commit();
            Response::json(["status" => "success"]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Response::error($e->getMessage());
        }
    }
}
