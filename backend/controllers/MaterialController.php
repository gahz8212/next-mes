<?php
// backend/controllers/MaterialController.php

class MaterialController {
    /**
     * 1. 자재 입출고 목록 및 집계 조회
     */
    public static function getMaterials(): void {
        try {
            $pdo = Database::getConnection();
            $startDate  = Request::query('start_date', date('Y-m-d', strtotime('-30 days')));
            $endDate    = Request::query('end_date', date('Y-m-d'));
            $partNo     = trim(Request::query('part_no', ''));
            $supplyType = strtoupper(trim(Request::query('supply_type', '')));
            $companyId  = !empty(Request::query('company_id')) ? (int)Request::query('company_id') : null;

            $params = [
                ':start' => $startDate,
                ':end'   => $endDate
            ];

            $cond = " WHERE DATE(m.created_at) BETWEEN :start AND :end";

            if (!empty($partNo)) {
                $cond .= " AND (m.part_no LIKE :part_no_like OR m.part_name LIKE :part_name_like)";
                $params[':part_no_like'] = '%' . $partNo . '%';
                $params[':part_name_like'] = '%' . $partNo . '%';
            }

            if (!empty($supplyType) && in_array($supplyType, ['CONSIGNED', 'PROCURED'])) {
                $cond .= " AND m.supply_type = :supply_type";
                $params[':supply_type'] = $supplyType;
            }

            if (!empty($companyId)) {
                $cond .= " AND m.company_id = :company_id";
                $params[':company_id'] = $companyId;
            }

            // Summary
            $summarySql = "SELECT 
              COALESCE(SUM(CASE WHEN m.inout_type='IN' THEN m.qty ELSE 0 END), 0) as total_in,
              COALESCE(SUM(CASE WHEN m.inout_type='OUT' THEN m.qty ELSE 0 END), 0) as total_out,
              COALESCE(SUM(CASE WHEN m.inout_type='IN' AND m.supply_type='CONSIGNED' THEN m.qty ELSE 0 END), 0) as total_consigned,
              COALESCE(SUM(CASE WHEN m.inout_type='IN' AND m.supply_type='PROCURED' THEN m.qty ELSE 0 END), 0) as total_procured,
              COUNT(DISTINCT m.part_no) as part_count,
              COUNT(*) as record_count
            FROM material_inout m
            LEFT JOIN company c ON m.company_id = c.id
            $cond";

            $stmtSum = $pdo->prepare($summarySql);
            $stmtSum->execute($params);
            $summaryData = $stmtSum->fetch();

            $summary = [
                'total_in'         => (float)($summaryData['total_in'] ?? 0),
                'total_out'        => (float)($summaryData['total_out'] ?? 0),
                'total_consigned'  => (float)($summaryData['total_consigned'] ?? 0),
                'total_procured'   => (float)($summaryData['total_procured'] ?? 0),
                'part_count'       => (int)($summaryData['part_count'] ?? 0),
                'record_count'     => (int)($summaryData['record_count'] ?? 0),
            ];

            // Records
            $recordsSql = "SELECT m.*, COALESCE(c.name, '자사재고') as company_name
            FROM material_inout m
            LEFT JOIN company c ON m.company_id = c.id
            $cond
            ORDER BY m.created_at DESC LIMIT 200";

            $stmtRec = $pdo->prepare($recordsSql);
            $stmtRec->execute($params);
            $records = $stmtRec->fetchAll();

            $groupedMap = [];
            foreach ($records as $r) {
                $pNo = $r['part_no'];
                if (!isset($groupedMap[$pNo])) {
                    $groupedMap[$pNo] = [
                        'part_no'        => $pNo,
                        'part_name'      => $r['part_name'] ?: $pNo,
                        'unit'           => $r['unit'] ?: 'EA',
                        'total_in'       => 0,
                        'total_out'      => 0,
                        'current_stock'  => 0,
                        'consigned_in'   => 0,
                        'procured_in'    => 0,
                        'history_count'  => 0,
                        'items'          => []
                    ];
                }
                $qty = (float)$r['qty'];
                if ($r['inout_type'] === 'IN') {
                    $groupedMap[$pNo]['total_in'] += $qty;
                    if ($r['supply_type'] === 'CONSIGNED') {
                        $groupedMap[$pNo]['consigned_in'] += $qty;
                    } else {
                        $groupedMap[$pNo]['procured_in'] += $qty;
                    }
                } else {
                    $groupedMap[$pNo]['total_out'] += $qty;
                }
                $groupedMap[$pNo]['history_count']++;
                $groupedMap[$pNo]['items'][] = $r;
            }

            foreach ($groupedMap as &$g) {
                $g['current_stock'] = $g['total_in'] - $g['total_out'];
            }
            unset($g);

            Response::json([
                "status" => "success",
                "data" => [
                    "summary" => $summary,
                    "records" => $records,
                    "grouped" => array_values($groupedMap)
                ]
            ]);

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 2. 자재 입출고 등록
     */
    public static function createMaterial(): void {
        try {
            $pdo = Database::getConnection();
            $input = Request::getBody();

            $partNo = trim($input['part_no'] ?? '');
            $partName = trim($input['part_name'] ?? '') ?: null;
            $inoutType = strtoupper(trim($input['inout_type'] ?? ''));
            $supplyType = strtoupper(trim($input['supply_type'] ?? ''));
            if (!in_array($supplyType, ['CONSIGNED', 'PROCURED'])) {
                $supplyType = !empty($input['wo_id']) ? 'CONSIGNED' : 'PROCURED';
            }
            $qty = $input['qty'] ?? null;
            $unit = trim($input['unit'] ?? '') ?: 'EA';
            $woId = trim($input['wo_id'] ?? '') ?: null;
            $companyId = !empty($input['company_id']) ? (int)$input['company_id'] : null;
            $note = trim($input['note'] ?? '') ?: null;

            if (empty($partNo) || !in_array($inoutType, ['IN', 'OUT']) || !is_numeric($qty) || (float)$qty <= 0) {
                Response::error("필수 입력 항목(part_no, inout_type: IN/OUT, qty > 0)을 확인해주세요.");
            }

            $sql = "INSERT INTO material_inout (part_no, part_name, inout_type, supply_type, qty, unit, wo_id, company_id, note)
                    VALUES (:part_no, :part_name, :inout_type, :supply_type, :qty, :unit, :wo_id, :company_id, :note)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':part_no' => $partNo,
                ':part_name' => $partName,
                ':inout_type' => $inoutType,
                ':supply_type' => $supplyType,
                ':qty' => $qty,
                ':unit' => $unit,
                ':wo_id' => $woId,
                ':company_id' => $companyId,
                ':note' => $note
            ]);

            $id = (int)$pdo->lastInsertId();
            Response::json(["status" => "success", "data" => ["id" => $id, "supply_type" => $supplyType]]);

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 3. 자재 입출고 삭제
     */
    public static function deleteMaterial(?string $id = null): void {
        try {
            $pdo = Database::getConnection();
            $input = Request::getBody();
            $matId = $id ?? ($input['id'] ?? null);

            if (empty($matId)) {
                Response::error("필수 입력 항목(id)이 누락되었습니다.");
            }

            $stmt = $pdo->prepare("DELETE FROM material_inout WHERE id = ?");
            $stmt->execute([$matId]);

            Response::json(["status" => "success"]);

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }
}
