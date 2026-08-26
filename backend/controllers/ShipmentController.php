<?php
// backend/controllers/ShipmentController.php

class ShipmentController {
    /**
     * 1. 출하 목록 및 집계 조회
     */
    public static function getShipments(): void {
        try {
            $pdo = Database::getConnection();

            // 1. 자동 동기화: 납품일(delivery_date)이 지정되었거나 완료된 작업지시 중 shipment에 누락된 건 자동 등록
            $pdo->query("
                INSERT INTO shipment (wo_id, ship_qty, ship_date, company_id, status)
                SELECT w.wo_id, 
                       w.target_qty, 
                       COALESCE(w.delivery_date, DATE(w.completed_at), CURDATE()), 
                       w.company_id, 
                       'PENDING'
                FROM work_order w
                WHERE (w.delivery_date IS NOT NULL OR w.status IN ('DONE', 'COMPLETED') OR w.completed_at IS NOT NULL)
                  AND NOT EXISTS (SELECT 1 FROM shipment s WHERE s.wo_id = w.wo_id)
            ");

            // 2. 납품일 변경 동기화: work_order의 delivery_date가 변경된 경우 shipment 출하 예정일 자동 동기화
            $pdo->query("
                UPDATE shipment s
                JOIN work_order w ON s.wo_id = w.wo_id
                SET s.ship_date = w.delivery_date,
                    s.company_id = COALESCE(s.company_id, w.company_id)
                WHERE w.delivery_date IS NOT NULL 
                  AND s.status = 'PENDING' 
                  AND (s.ship_date != w.delivery_date OR s.ship_date IS NULL)
            ");

            $startDate = Request::query('start_date', date('Y-m-d', strtotime('-30 days')));
            $endDate   = Request::query('end_date', date('Y-m-d', strtotime('+30 days')));
            $status    = trim(Request::query('status', ''));

            // Summary
            $summarySql = "SELECT COUNT(*) as total, COALESCE(SUM(ship_qty), 0) as total_qty,
              SUM(CASE WHEN status='SHIPPED' THEN 1 ELSE 0 END) as shipped_count,
              SUM(CASE WHEN status='PENDING' THEN 1 ELSE 0 END) as pending_count
            FROM shipment s
            WHERE (s.ship_date IS NULL OR DATE(s.ship_date) BETWEEN :start AND :end)";

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

            // Records (견고한 5중 품목명 및 거래처명 폴백 조인)
            $recordsSql = "SELECT s.*, 
              w.target_qty, 
              w.due_date,
              w.delivery_date,
              w.status as wo_status,
              COALESCE(c.name, (SELECT c2.name FROM company c2 WHERE c2.id = w.company_id), '거래처') as company_name, 
              COALESCE(
                  (SELECT soi.item_name FROM sales_order_item soi WHERE soi.wo_id = s.wo_id LIMIT 1),
                  (SELECT soi.item_name FROM sales_order_item soi WHERE soi.wo_id = w.parent_wo_id LIMIT 1),
                  (SELECT so.item_name FROM sales_order so WHERE so.wo_id = s.wo_id LIMIT 1),
                  (SELECT so.item_name FROM sales_order so WHERE so.wo_id = w.parent_wo_id LIMIT 1),
                  (SELECT i.item_name FROM item i JOIN bom_master bm ON bm.item_id = i.id WHERE bm.bom_id = w.bom_id LIMIT 1),
                  (SELECT i.item_name FROM item i WHERE i.id = bm.item_id LIMIT 1),
                  pm.product_name, 
                  '완제품 PBA'
              ) as item_name,
              (SELECT COALESCE(SUM(b.status!='WAIT'), 0) FROM barcode_master b WHERE b.wo_id=s.wo_id) as processed_qty,
              (SELECT COALESCE(SUM(CASE WHEN b.status IN ('BOTTOM_DONE','TEST_PASS','SHIPPING','DONE') THEN 1 ELSE 0 END), 0) FROM barcode_master b WHERE b.wo_id=s.wo_id) as good_qty
            FROM shipment s
            LEFT JOIN work_order w ON s.wo_id = w.wo_id
            LEFT JOIN bom_master bm ON w.bom_id = bm.bom_id
            LEFT JOIN product_master pm ON bm.product_id = pm.product_id
            LEFT JOIN company c ON COALESCE(s.company_id, w.company_id) = c.id
            WHERE (s.ship_date IS NULL OR DATE(s.ship_date) BETWEEN :start AND :end)";

            $recParams = [
                ':start' => $startDate,
                ':end'   => $endDate
            ];

            if ($status !== '') {
                $recordsSql .= " AND s.status = :status";
                $recParams[':status'] = $status;
            }
            $recordsSql .= " ORDER BY (CASE WHEN s.status = 'PENDING' THEN 1 ELSE 2 END) ASC, s.ship_date DESC, s.id DESC";

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

            $shipDate = !empty($input['ship_date']) ? trim($input['ship_date']) : null;

            if ($status === 'SHIPPED') {
                $actualShipDate = $shipDate ?: date('Y-m-d');
                $stmtShip = $pdo->prepare("UPDATE shipment SET status = ?, ship_date = ? WHERE id = ?");
                $stmtShip->execute([$status, $actualShipDate, $shipId]);

                $stmtWo = $pdo->prepare("UPDATE work_order SET shipped = 1, shipped_at = NOW() WHERE wo_id = (SELECT wo_id FROM shipment WHERE id = ?)");
                $stmtWo->execute([$shipId]);
            } else {
                $stmt = $pdo->prepare("UPDATE shipment SET status = ? WHERE id = ?");
                $stmt->execute([$status, $shipId]);
            }

            // ── 연결된 sales_order_item 상태를 COMPLETED로 업데이트 ──
            $woIdStmt = $pdo->prepare("SELECT wo_id FROM shipment WHERE id = ?");
            $woIdStmt->execute([$shipId]);
            $woId = $woIdStmt->fetchColumn();
            if ($woId) {
                $pdo->prepare("UPDATE sales_order_item SET status = 'COMPLETED' WHERE wo_id = ?")->execute([$woId]);

                // 해당 수주의 모든 품목이 COMPLETED면 sales_order도 COMPLETED
                $orderIdStmt = $pdo->prepare("SELECT DISTINCT order_id FROM sales_order_item WHERE wo_id = ?");
                $orderIdStmt->execute([$woId]);
                $orderIds = $orderIdStmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($orderIds as $orderId) {
                    $notDoneStmt = $pdo->prepare("SELECT COUNT(*) FROM sales_order_item WHERE order_id = ? AND status != 'COMPLETED'");
                    $notDoneStmt->execute([$orderId]);
                    if ((int)$notDoneStmt->fetchColumn() === 0) {
                        $pdo->prepare("UPDATE sales_order SET status = 'COMPLETED' WHERE id = ?")->execute([$orderId]);
                    }
                }
            }

            $pdo->commit();
            Response::json(["status" => "success"]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Response::error($e->getMessage());
        }
    }
}
