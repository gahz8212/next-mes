<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$wo_id    = trim($input['wo_id'] ?? '');
$item_id  = !empty($input['item_id']) ? (int)$input['item_id'] : null;
$newItems = $input['new_items'] ?? [];

if (empty($newItems) || !is_array($newItems)) {
    echo json_encode(["status" => "error", "message" => "비교할 신규 BOM 데이터가 없습니다."], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 1. 기존 BOM ID 탐색
    $existingBomId = null;
    $existingVersion = 'v1.0';

    if (!empty($wo_id)) {
        $stmtWo = $pdo->prepare("SELECT bom_id FROM work_order WHERE wo_id = ?");
        $stmtWo->execute([$wo_id]);
        $wo = $stmtWo->fetch();
        if ($wo && !empty($wo['bom_id'])) {
            $existingBomId = (int)$wo['bom_id'];
        }
    }

    if (!$existingBomId && !empty($item_id)) {
        $stmtItem = $pdo->prepare("SELECT bom_id, version FROM bom_master WHERE item_id = ? ORDER BY bom_id DESC LIMIT 1");
        $stmtItem->execute([$item_id]);
        $bm = $stmtItem->fetch();
        if ($bm) {
            $existingBomId = (int)$bm['bom_id'];
            $existingVersion = $bm['version'] ?: 'v1.0';
        }
    }

    // 기존 BOM 상세 조회
    $oldItems = [];
    if ($existingBomId) {
        $stmtDetail = $pdo->prepare("SELECT part_no, COALESCE(part_name, '') as part_name, req_qty, COALESCE(location, '') as location, feeder_slot FROM bom_detail WHERE bom_id = ?");
        $stmtDetail->execute([$existingBomId]);
        $oldItems = $stmtDetail->fetchAll(PDO::FETCH_ASSOC);

        $stmtV = $pdo->prepare("SELECT version FROM bom_master WHERE bom_id = ?");
        $stmtV->execute([$existingBomId]);
        $vRow = $stmtV->fetch();
        if ($vRow && !empty($vRow['version'])) {
            $existingVersion = $vRow['version'];
        }
    }

    // 기존 BOM이 아예 없는 경우: 신규 등록
    if (empty($oldItems)) {
        echo json_encode([
            "status" => "success",
            "is_initial" => true,
            "is_changed" => true,
            "existing_version" => null,
            "next_version" => "v1.0",
            "summary" => [
                "total_old" => 0,
                "total_new" => count($newItems),
                "added_count" => count($newItems),
                "removed_count" => 0,
                "modified_count" => 0,
                "unchanged_count" => 0
            ],
            "diff_list" => array_map(function($item) {
                return [
                    "status" => "ADDED",
                    "part_no_old" => null,
                    "part_no_new" => $item['part_no'] ?? '',
                    "part_name_old" => null,
                    "part_name_new" => $item['part_name'] ?? '',
                    "qty_old" => null,
                    "qty_new" => (float)($item['req_qty'] ?? 1),
                    "location_old" => null,
                    "location_new" => $item['location'] ?? '',
                    "feeder_slot" => $item['feeder_slot'] ?? null,
                    "change_desc" => "신규 부품 추가"
                ];
            }, $newItems)
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. Diff 정밀 비교 알고리즘
    // 매핑 키: 1순위 location (위치명), 2순위 part_no
    $oldMapByLoc = [];
    $oldMapByPn  = [];
    foreach ($oldItems as $idx => $o) {
        $locKey = strtoupper(trim($o['location']));
        $pnKey  = strtoupper(trim($o['part_no']));
        if ($locKey !== '') $oldMapByLoc[$locKey] = $o;
        if (!isset($oldMapByPn[$pnKey])) $oldMapByPn[$pnKey] = [];
        $oldMapByPn[$pnKey][] = $o;
    }

    $diffList = [];
    $matchedOldLocs = [];
    $matchedOldPns  = [];

    $addedCount = 0;
    $removedCount = 0;
    $modifiedCount = 0;
    $unchangedCount = 0;

    foreach ($newItems as $n) {
        $newLoc = strtoupper(trim($n['location'] ?? ''));
        $newPn  = strtoupper(trim($n['part_no'] ?? ''));
        $newQty = (float)($n['req_qty'] ?? 1);
        $newPName = trim($n['part_name'] ?? '');

        $matchedOld = null;

        // 1) Location 기반 매칭
        if ($newLoc !== '' && isset($oldMapByLoc[$newLoc])) {
            $matchedOld = $oldMapByLoc[$newLoc];
            $matchedOldLocs[$newLoc] = true;
        }
        // 2) Part No 기반 매칭
        else if (isset($oldMapByPn[$newPn]) && count($oldMapByPn[$newPn]) > 0) {
            $matchedOld = array_shift($oldMapByPn[$newPn]);
            $matchedOldPns[] = $matchedOld;
        }

        if ($matchedOld) {
            $oldPn = strtoupper(trim($matchedOld['part_no']));
            $oldQty = (float)$matchedOld['req_qty'];
            $oldLoc = trim($matchedOld['location']);
            $oldPName = trim($matchedOld['part_name']);

            $changes = [];
            if ($oldPn !== $newPn) {
                $changes[] = "품번 변경 ({$matchedOld['part_no']} → {$n['part_no']})";
            }
            if (abs($oldQty - $newQty) > 0.0001) {
                $changes[] = "수량 변경 ({$oldQty} → {$newQty})";
            }
            if (strtoupper($oldLoc) !== $newLoc && $newLoc !== '') {
                $changes[] = "위치 변경 ({$oldLoc} → {$n['location']})";
            }

            if (!empty($changes)) {
                $modifiedCount++;
                $diffList[] = [
                    "status" => "MODIFIED",
                    "part_no_old" => $matchedOld['part_no'],
                    "part_no_new" => $n['part_no'],
                    "part_name_old" => $oldPName,
                    "part_name_new" => $newPName ?: $oldPName,
                    "qty_old" => $oldQty,
                    "qty_new" => $newQty,
                    "location_old" => $oldLoc,
                    "location_new" => $n['location'] ?? '',
                    "feeder_slot" => $n['feeder_slot'] ?? $matchedOld['feeder_slot'],
                    "change_desc" => implode(', ', $changes)
                ];
            } else {
                $unchangedCount++;
                $diffList[] = [
                    "status" => "UNCHANGED",
                    "part_no_old" => $matchedOld['part_no'],
                    "part_no_new" => $n['part_no'],
                    "part_name_old" => $oldPName,
                    "part_name_new" => $newPName ?: $oldPName,
                    "qty_old" => $oldQty,
                    "qty_new" => $newQty,
                    "location_old" => $oldLoc,
                    "location_new" => $n['location'] ?? '',
                    "feeder_slot" => $n['feeder_slot'] ?? $matchedOld['feeder_slot'],
                    "change_desc" => "변동 없음"
                ];
            }
        } else {
            // 신규 추가된 부품
            $addedCount++;
            $diffList[] = [
                "status" => "ADDED",
                "part_no_old" => null,
                "part_no_new" => $n['part_no'],
                "part_name_old" => null,
                "part_name_new" => $newPName,
                "qty_old" => null,
                "qty_new" => $newQty,
                "location_old" => null,
                "location_new" => $n['location'] ?? '',
                "feeder_slot" => $n['feeder_slot'] ?? null,
                "change_desc" => "신규 부품 추가"
            ];
        }
    }

    // 기존 부품 중 신규에 매칭되지 않은 삭제된 부품 탐색
    foreach ($oldItems as $o) {
        $locKey = strtoupper(trim($o['location']));
        $isMatched = false;
        if ($locKey !== '' && isset($matchedOldLocs[$locKey])) {
            $isMatched = true;
        } else if (in_array($o, $matchedOldPns, true)) {
            $isMatched = true;
        }

        if (!$isMatched) {
            $removedCount++;
            $diffList[] = [
                "status" => "REMOVED",
                "part_no_old" => $o['part_no'],
                "part_no_new" => null,
                "part_name_old" => $o['part_name'],
                "part_name_new" => null,
                "qty_old" => (float)$o['req_qty'],
                "qty_new" => null,
                "location_old" => $o['location'],
                "location_new" => null,
                "feeder_slot" => $o['feeder_slot'],
                "change_desc" => "부품 삭제 (제외됨)"
            ];
        }
    }

    $isChanged = ($addedCount > 0 || $removedCount > 0 || $modifiedCount > 0);

    // 다음 버전 번호 산출 (예: v1.0 -> v1.1 또는 v2.0)
    $nextVersion = 'v1.1';
    if (preg_match('/v?([0-9]+)\.([0-9]+)/i', $existingVersion, $vm)) {
        $major = (int)$vm[1];
        $minor = (int)$vm[2];
        if ($removedCount > 0 || $modifiedCount >= 3) {
            $nextVersion = 'v' . ($major + 1) . '.0';
        } else {
            $nextVersion = 'v' . $major . '.' . ($minor + 1);
        }
    }

    echo json_encode([
        "status" => "success",
        "is_initial" => false,
        "is_changed" => $isChanged,
        "existing_version" => $existingVersion,
        "next_version" => $nextVersion,
        "summary" => [
            "total_old" => count($oldItems),
            "total_new" => count($newItems),
            "added_count" => $addedCount,
            "removed_count" => $removedCount,
            "modified_count" => $modifiedCount,
            "unchanged_count" => $unchangedCount
        ],
        "diff_list" => $diffList
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
