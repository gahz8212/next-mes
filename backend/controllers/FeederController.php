<?php
// backend/controllers/FeederController.php

class FeederController {
    /**
     * 헬퍼: 두 품번이 직접 일치하거나 part_alias 상의 동일 규격 대체품인지 검증
     */
    private static function isPartCompatible(PDO $pdo, string $reqPartNo, string $scannedPartNo, string &$vendorInfo = '', ?int $companyId = null): bool {
        if ($reqPartNo === $scannedPartNo) {
            $vendorInfo = '정품번 일치';
            return true;
        }

        // 1. 스캔된 품번과 요구 품번의 part_alias 교차 참조 검사 (해당 거래처 전용 승인)
        $compCondition = $companyId ? "AND p1.company_id = {$companyId} AND p2.company_id = {$companyId}" : "AND 1=0";
        $stmt = $pdo->prepare("
            SELECT p1.standard_name, p1.vendor_name, p1.company_id, c.name as comp_name
            FROM part_alias p1
            JOIN part_alias p2 ON p1.standard_name = p2.standard_name
            JOIN company c ON p1.company_id = c.id
            WHERE (p1.alias_part_no = ? OR p1.standard_code = ?)
              AND (p2.alias_part_no = ? OR p2.standard_code = ? OR p2.standard_name = ?)
              $compCondition
            LIMIT 1
        ");
        $stmt->execute([$scannedPartNo, $scannedPartNo, $reqPartNo, $reqPartNo, $reqPartNo]);
        $row = $stmt->fetch();

        if ($row) {
            $vName = $row['vendor_name'] ?: '공인 벤더';
            $cPrefix = $row['comp_name'] ? "[{$row['comp_name']} 승인] " : "";
            $vendorInfo = "AVL {$cPrefix}대체품 승인 [{$vName}]";
            return true;
        }

        // 2. 단방향 표준 코드 매핑 확인 (해당 거래처 전용 승인)
        $compCondition2 = $companyId ? "AND p.company_id = {$companyId}" : "AND 1=0";
        $stmt2 = $pdo->prepare("
            SELECT p.standard_name, p.vendor_name, p.company_id, c.name as comp_name 
            FROM part_alias p
            JOIN company c ON p.company_id = c.id
            WHERE p.alias_part_no = ? AND (p.standard_name = ? OR p.standard_code = ?)
              $compCondition2
            LIMIT 1
        ");
        $stmt2->execute([$scannedPartNo, $reqPartNo, $reqPartNo]);
        $row2 = $stmt2->fetch();

        if ($row2) {
            $vName = $row2['vendor_name'] ?: '공인 벤더';
            $cPrefix = $row2['comp_name'] ? "[{$row2['comp_name']} 승인] " : "";
            $vendorInfo = "AVL {$cPrefix}대체품 승인 [{$vName}]";
            return true;
        }

        return false;
    }

    /**
     * 1. 피더 셋업 상태 조회
     */
    public static function getFeederSetup(?string $id = null): void {
        try {
            $pdo = Database::getConnection();
            $wo_id = trim($id ?? Request::query('wo_id', Request::input('wo_id', '')));

            if (!$wo_id) {
                Response::error("wo_id 파라미터가 필요합니다.");
            }

            // 1. 작업지시 확인
            $stmt = $pdo->prepare("SELECT wo_id, bom_id, target_qty, status FROM work_order WHERE wo_id = ?");
            $stmt->execute([$wo_id]);
            $wo = $stmt->fetch();

            if (!$wo) {
                throw new Exception("작업지시를 찾을 수 없습니다: " . $wo_id);
            }

            $bom_id = $wo['bom_id'];
            if (!$bom_id) {
                $bom_id = 1006;
                $pdo->prepare("UPDATE work_order SET bom_id = ? WHERE wo_id = ?")->execute([$bom_id, $wo_id]);
            }

            // 2. feeder_setup 기존 데이터 확인
            $checkStmt = $pdo->prepare("SELECT count(*) FROM feeder_setup WHERE wo_id = ?");
            $checkStmt->execute([$wo_id]);
            $count = (int)$checkStmt->fetchColumn();

            if ($count === 0) {
                $bomStmt = $pdo->prepare("SELECT part_no, COALESCE(points, req_qty, 1) as points, COALESCE(provide_qty, 0) as provide_qty, location, COALESCE(is_nc, 0) as is_nc FROM bom_detail WHERE bom_id = ? ORDER BY detail_id ASC");
                $bomStmt->execute([$bom_id]);
                $bomList = $bomStmt->fetchAll();

                if (empty($bomList)) {
                    $bomList = [
                        ['part_no' => 'MT29F2G08ABAGAH4-IT:G', 'req_qty' => 10, 'location' => 'U2', 'is_nc' => 0],
                        ['part_no' => 'LIS3MDL', 'req_qty' => 80, 'location' => 'U16', 'is_nc' => 0],
                        ['part_no' => 'BMA250E', 'req_qty' => 80, 'location' => 'U15', 'is_nc' => 0],
                        ['part_no' => 'XC61FC2512MR', 'req_qty' => 90, 'location' => 'U36', 'is_nc' => 0],
                        ['part_no' => 'BMC-2703', 'req_qty' => 10, 'location' => 'U12', 'is_nc' => 0],
                        ['part_no' => 'MAX1554ETA', 'req_qty' => 69, 'location' => 'U11', 'is_nc' => 0],
                        ['part_no' => 'SY8008CAAC', 'req_qty' => 221, 'location' => 'U31,U32', 'is_nc' => 0]
                    ];
                }

                $insStmt = $pdo->prepare("
                    INSERT INTO feeder_setup (wo_id, slot_no, part_no, location, req_qty, status, reel_barcode, scanned_at, scanned_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $slot = 1;
                foreach ($bomList as $item) {
                    $pts = (int)round((float)($item['points'] ?? $item['req_qty'] ?? 1));
                    $isNc = !empty($item['is_nc']) || $pts <= 0 || (stripos($item['location'] ?? '', 'NC') !== false);
                    $status = $isNc ? 'VERIFIED' : 'PENDING';
                    $barcode = $isNc ? 'NC (미실장 SKIP)' : null;
                    $scannedAt = $isNc ? date('Y-m-d H:i:s') : null;
                    $scannedBy = $isNc ? 'SYSTEM (NC)' : null;

                    $insStmt->execute([
                        $wo_id,
                        $slot++,
                        $item['part_no'],
                        $item['location'] ?? 'U' . $slot,
                        $pts,
                        $status,
                        $barcode,
                        $scannedAt,
                        $scannedBy
                    ]);
                }
            }

            // 3. feeder_setup 및 MSL 정보 조회 (BOM 포인트 및 제공수량 매핑)
            $listStmt = $pdo->prepare("
                SELECT 
                    fs.id, fs.wo_id, fs.slot_no, fs.part_no, fs.location,
                    COALESCE(bd.points, fs.req_qty, 1) as points,
                    COALESCE(bd.points, fs.req_qty, 1) as req_qty,
                    COALESCE(bd.provide_qty, 0) as provide_qty,
                    fs.reel_barcode, fs.status, fs.scanned_at, fs.scanned_by,
                    rm.msl_level, rm.floor_life_hours, rm.unsealed_at, rm.status as reel_status
                FROM feeder_setup fs
                JOIN work_order w ON fs.wo_id = w.wo_id
                LEFT JOIN bom_detail bd ON w.bom_id = bd.bom_id AND fs.part_no = bd.part_no
                LEFT JOIN reel_master rm ON fs.reel_barcode = rm.reel_barcode
                WHERE fs.wo_id = ?
                ORDER BY fs.slot_no ASC
            ");
            $listStmt->execute([$wo_id]);
            $slots = $listStmt->fetchAll();

            foreach ($slots as &$s) {
                $s['points'] = (int)round((float)($s['points'] ?? 1));
                $s['req_qty'] = (int)round((float)($s['req_qty'] ?? 1));
                $s['provide_qty'] = (int)round((float)($s['provide_qty'] ?? 0));
            }
            unset($s);

            $total_count = count($slots);
            $verified_count = 0;
            foreach ($slots as $s) {
                if ($s['status'] === 'VERIFIED') {
                    $verified_count++;
                }
            }

            $interlock_released = ($total_count > 0 && $verified_count === $total_count);

            $reelStmt = $pdo->query("SELECT reel_barcode, part_no, msl_level, status FROM reel_master ORDER BY reel_barcode ASC");
            $allReels = $reelStmt->fetchAll();

            Response::json([
                "status" => "success",
                "data" => [
                    "wo_id" => $wo_id,
                    "target_qty" => (int)$wo['target_qty'],
                    "total_slots" => $total_count,
                    "verified_slots" => $verified_count,
                    "progress_percent" => $total_count > 0 ? round(($verified_count / $total_count) * 100, 1) : 0,
                    "interlock_released" => $interlock_released,
                    "slots" => $slots,
                    "available_reels" => $allReels
                ]
            ]);

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 2. 릴 바코드 스캔 및 포카요케 피더 장착 검증
     */
    public static function scanFeeder(): void {
        $pdo = Database::getConnection();
        try {
            $input = Request::getBody();
            $wo_id          = trim($input['wo_id'] ?? '');
            $reel_barcode   = trim($input['reel_barcode'] ?? '');
            $target_slot_no = !empty($input['slot_no']) ? (int)$input['slot_no'] : null;
            $scanned_by     = $input['scanned_by'] ?? 'Worker';

            if (!$wo_id || !$reel_barcode) {
                Response::error("작업지시 ID(wo_id)와 릴 바코드(reel_barcode)가 필요합니다.");
            }

            $pdo->beginTransaction();
            $vendorInfo = '';

            // 1. 릴 마스터에서 릴 정보 확인
            $stmt = $pdo->prepare("SELECT reel_barcode, part_no, msl_level, floor_life_hours, unsealed_at, status FROM reel_master WHERE reel_barcode = ?");
            $stmt->execute([$reel_barcode]);
            $reel = $stmt->fetch();

            if (!$reel) {
                $stmtPart = $pdo->prepare("SELECT reel_barcode, part_no, msl_level, floor_life_hours, unsealed_at, status FROM reel_master WHERE part_no = ? LIMIT 1");
                $stmtPart->execute([$reel_barcode]);
                $reel = $stmtPart->fetch();

                if ($reel) {
                    $reel_barcode = $reel['reel_barcode'];
                } else {
                    $checkBom = $pdo->prepare("SELECT part_no FROM bom_detail bd JOIN work_order wo ON bd.bom_id = wo.bom_id WHERE wo.wo_id = ?");
                    $checkBom->execute([$wo_id]);
                    $bomParts = $checkBom->fetchAll(PDO::FETCH_COLUMN);

                    $isMatch = false;
                    foreach ($bomParts as $bp) {
                        if (self::isPartCompatible($pdo, $bp, $reel_barcode, $vendorInfo)) {
                            $isMatch = true;
                            break;
                        }
                    }

                    if ($isMatch) {
                        $autoBarcode = 'REEL-' . substr(md5($reel_barcode), 0, 8);
                        $insReel = $pdo->prepare("INSERT IGNORE INTO reel_master (reel_barcode, part_no, msl_level, floor_life_hours, unsealed_at, status) VALUES (?, ?, 1, 0, NOW(), 'IN_USE')");
                        $insReel->execute([$autoBarcode, $reel_barcode]);
                        $reel = ['part_no' => $reel_barcode, 'msl_level' => 1, 'floor_life_hours' => 0, 'unsealed_at' => date('Y-m-d H:i:s'), 'status' => 'IN_USE'];
                        $reel_barcode = $autoBarcode;
                    } else {
                        throw new Exception("등록되지 않았거나 BOM에 호환되지 않는 릴 바코드/품번입니다: [{$reel_barcode}]");
                    }
                }
            }

            if ($reel['status'] === 'EXPIRED') {
                throw new Exception("MSL 습기 노출 허용시간이 만료된 자재입니다. 베이킹(Baking) 후 사용해야 합니다.");
            }

            $is_first_scan = false;
            if (is_null($reel['unsealed_at'])) {
                $updateReel = $pdo->prepare("UPDATE reel_master SET unsealed_at = NOW(), status = 'IN_USE' WHERE reel_barcode = ?");
                $updateReel->execute([$reel_barcode]);
                $is_first_scan = true;
            }

            if ($target_slot_no) {
                $slotStmt = $pdo->prepare("SELECT id, slot_no, part_no, location, status FROM feeder_setup WHERE wo_id = ? AND slot_no = ?");
                $slotStmt->execute([$wo_id, $target_slot_no]);
                $targetSlot = $slotStmt->fetch();

                if (!$targetSlot) {
                    throw new Exception("해당 피더 슬롯 정보를 찾을 수 없습니다.");
                }

                if (!self::isPartCompatible($pdo, $targetSlot['part_no'], $reel['part_no'], $vendorInfo)) {
                    throw new Exception("오투입(MISMATCH) 경고! 슬롯 {$target_slot_no}번의 필요 부품은 [{$targetSlot['part_no']}]이지만, 스캔된 릴은 [{$reel['part_no']}] (호환되지 않는 부품) 입니다.");
                }

                $matched_slot_id = $targetSlot['id'];
                $matched_slot_no = $targetSlot['slot_no'];
                $matched_location = $targetSlot['location'];
            } else {
                $allSlotsStmt = $pdo->prepare("SELECT id, slot_no, part_no, location, status FROM feeder_setup WHERE wo_id = ? ORDER BY slot_no ASC");
                $allSlotsStmt->execute([$wo_id]);
                $allSlots = $allSlotsStmt->fetchAll();

                $matchedSlot = null;
                $alreadyVerifiedSlot = null;

                foreach ($allSlots as $sl) {
                    if (self::isPartCompatible($pdo, $sl['part_no'], $reel['part_no'], $vendorInfo)) {
                        if ($sl['status'] !== 'VERIFIED') {
                            $matchedSlot = $sl;
                            break;
                        } else {
                            $alreadyVerifiedSlot = $sl;
                        }
                    }
                }

                if (!$matchedSlot) {
                    if ($alreadyVerifiedSlot) {
                        throw new Exception("이미 검증 장착이 완료된 부품입니다: [{$reel['part_no']}] (피더 슬롯 {$alreadyVerifiedSlot['slot_no']}번)");
                    } else {
                        throw new Exception("오투입(MISMATCH) 경고! 현재 작업지시의 BOM에 포함되지 않았거나 승인되지 않은 부품 품번입니다: [{$reel['part_no']}]");
                    }
                }

                $matched_slot_id = $matchedSlot['id'];
                $matched_slot_no = $matchedSlot['slot_no'];
                $matched_location = $matchedSlot['location'];
            }

            $upStmt = $pdo->prepare("
                UPDATE feeder_setup 
                SET reel_barcode = ?, status = 'VERIFIED', scanned_at = NOW(), scanned_by = ?
                WHERE id = ?
            ");
            $upStmt->execute([$reel_barcode, $scanned_by, $matched_slot_id]);

            $statStmt = $pdo->prepare("
                SELECT 
                    count(*) as total,
                    COALESCE(SUM(CASE WHEN status = 'VERIFIED' THEN 1 ELSE 0 END), 0) as verified
                FROM feeder_setup 
                WHERE wo_id = ?
            ");
            $statStmt->execute([$wo_id]);
            $stats = $statStmt->fetch();

            $total = (int)$stats['total'];
            $verified = (int)$stats['verified'];
            $interlock_released = ($total > 0 && $verified === $total);

            $pdo->commit();

            $msgDesc = $vendorInfo ? " ({$vendorInfo})" : "";
            Response::json([
                "status" => "success",
                "message" => "포카요케 검증 성공! 피더 슬롯 {$matched_slot_no}번 [{$matched_location}]에 장착되었습니다.{$msgDesc}",
                "data" => [
                    "slot_no" => $matched_slot_no,
                    "location" => $matched_location,
                    "part_no" => $reel['part_no'],
                    "vendor_info" => $vendorInfo,
                    "reel_barcode" => $reel_barcode,
                    "is_first_scan" => $is_first_scan,
                    "verified_count" => $verified,
                    "total_count" => $total,
                    "progress_percent" => $total > 0 ? round(($verified / $total) * 100, 1) : 0,
                    "interlock_released" => $interlock_released
                ]
            ]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Response::error($e->getMessage());
        }
    }

    /**
     * 3. 피더 셋업 초기화 및 일괄 자동 검증
     */
    public static function resetFeederSetup(): void {
        $pdo = Database::getConnection();
        try {
            $input = Request::getBody();
            $wo_id = trim($input['wo_id'] ?? '');
            $action = $input['action'] ?? 'reset';

            if (!$wo_id) {
                Response::error("wo_id가 필요합니다.");
            }

            if ($action === 'reset') {
                $stmt = $pdo->prepare("UPDATE feeder_setup SET reel_barcode = NULL, status = 'PENDING', scanned_at = NULL WHERE wo_id = ?");
                $stmt->execute([$wo_id]);
                Response::json(["status" => "success", "message" => "피더 셋업이 초기화되었습니다. (인터록 잠김)"]);
            } else if ($action === 'auto_verify') {
                $slotsStmt = $pdo->prepare("SELECT id, part_no, slot_no FROM feeder_setup WHERE wo_id = ?");
                $slotsStmt->execute([$wo_id]);
                $slots = $slotsStmt->fetchAll();

                foreach ($slots as $s) {
                    $reelStmt = $pdo->prepare("SELECT reel_barcode FROM reel_master WHERE part_no = ? LIMIT 1");
                    $reelStmt->execute([$s['part_no']]);
                    $reel = $reelStmt->fetch();

                    $barcode = $reel ? $reel['reel_barcode'] : 'AUTO-REEL-' . $s['slot_no'];
                    
                    if (!$reel) {
                        $insReel = $pdo->prepare("INSERT IGNORE INTO reel_master (reel_barcode, part_no, msl_level, floor_life_hours, status) VALUES (?, ?, 1, 0, 'IN_USE')");
                        $insReel->execute([$barcode, $s['part_no']]);
                    }

                    $up = $pdo->prepare("UPDATE feeder_setup SET reel_barcode = ?, status = 'VERIFIED', scanned_at = NOW(), scanned_by = 'AutoSetup' WHERE id = ?");
                    $up->execute([$barcode, $s['id']]);
                }

            } else if ($action === 'set_slot_nc') {
                $slot_id = (int)($input['slot_id'] ?? 0);
                if (!$slot_id) Response::error("슬롯 ID가 필요합니다.");

                $reason = trim($input['reason'] ?? '현장 결품 NC 승인');
                $up = $pdo->prepare("UPDATE feeder_setup SET reel_barcode = 'NC (현장승인)', status = 'VERIFIED', scanned_at = NOW(), scanned_by = ? WHERE id = ?");
                $up->execute(["NC 승인: {$reason}", $slot_id]);

                Response::json(["status" => "success", "message" => "해당 슬롯이 [NC 미실장 SKIP]으로 승인되었습니다."]);
            }

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }
}
