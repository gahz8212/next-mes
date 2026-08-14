<?php
// backend/api/sim_worker.php - SMT / DIP 라인 실시간 생산 시뮬레이터 워커
if (php_sapi_name() !== 'cli' && (!isset($_GET['secret']) || $_GET['secret'] !== 'mes_sim')) {
    die("CLI execution only.");
}

require_once __DIR__ . '/../config.php';

$wo_id = $argv[1] ?? ($_GET['wo_id'] ?? null);
$mode  = $argv[2] ?? ($_GET['mode'] ?? 'SMT'); // SMT 또는 DIP

if (!$wo_id) {
    die("wo_id required.\n");
}

// 작업지시 정보 조회
$stmt = $pdo->prepare("SELECT target_qty, status FROM work_order WHERE wo_id = ?");
$stmt->execute([$wo_id]);
$wo = $stmt->fetch();

if (!$wo) {
    die("Work order not found.\n");
}

$target_qty = intval($wo['target_qty']);
echo "[SIM] Starting simulation for {$wo_id} ({$mode}) Target: {$target_qty}\n";

// 바코드 목록 준비 (WAIT 상태 바코드 조회 또는 자동 생성)
$stmt = $pdo->prepare("SELECT barcode FROM barcode_master WHERE wo_id = ? ORDER BY barcode_id ASC");
$stmt->execute([$wo_id]);
$barcodes = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($barcodes)) {
    for ($i = 1; $i <= $target_qty; $i++) {
        $bc = sprintf("%s-%04d", $wo_id, $i);
        $ins = $pdo->prepare("INSERT INTO barcode_master (wo_id, barcode, status, created_at) VALUES (?, ?, 'WAIT', NOW())");
        $ins->execute([$wo_id, $bc]);
        $barcodes[] = $bc;
    }
}

// 라인 파이프라인 (대기열)
// mode = SMT: LASER -> SPI -> MOUNTER -> REFLOW
// mode = DIP: DIP_AOI -> WAVE
$activeQueue = []; // array of ['barcode' => '...', 'stage' => '...']
$nextIndex = 0;

while (true) {
    // 1. 현재 작업지시가 여전히 진행 중인지 확인 (중단된 경우 즉시 종료)
    $stmt = $pdo->prepare("SELECT status FROM work_order WHERE wo_id = ?");
    $stmt->execute([$wo_id]);
    $currentStatus = $stmt->fetchColumn();

    if ($mode === 'SMT' && $currentStatus !== 'IN_PROGRESS') {
        echo "[SIM] SMT stopped or changed. Exiting.\n";
        break;
    }
    if ($mode === 'DIP' && $currentStatus !== 'DIP_IN_PROGRESS') {
        echo "[SIM] DIP stopped or changed. Exiting.\n";
        break;
    }

    $nextActiveQueue = [];

    // 2. 라인 상의 기존 PCB 공정 전진
    foreach ($activeQueue as $item) {
        $bc = $item['barcode'];
        $stage = $item['stage'];

        if ($mode === 'SMT') {
            if ($stage === 'LASER') {
                // SPI 공정으로 이동
                $isPass = (rand(1, 100) > 4) ? 'PASS' : 'FAIL';
                $solderHeight = number_format(120 + rand(0, 200)/10, 1);
                $volPct = number_format(98 + rand(0, 50)/10, 1);
                $pData = json_encode(["solder_height_um" => $solderHeight, "volume_pct" => $volPct]);

                $insH = $pdo->prepare("INSERT INTO barcode_history (barcode, process_name, result_status, process_data, created_at) VALUES (?, 'SPI', ?, ?, NOW())");
                $insH->execute([$bc, $isPass, $pData]);

                if ($isPass === 'PASS') {
                    $nextActiveQueue[] = ['barcode' => $bc, 'stage' => 'SPI'];
                } else {
                    $updB = $pdo->prepare("UPDATE barcode_master SET status = 'DEFECT' WHERE barcode = ?");
                    $updB->execute([$bc]);
                }
            } else if ($stage === 'SPI') {
                // MOUNTER 공정으로 이동
                $pData = json_encode(["mounted_components" => 10, "offset_x_um" => number_format(rand(0, 50)/10, 2), "offset_y_um" => number_format(rand(0, 50)/10, 2)]);
                $insH = $pdo->prepare("INSERT INTO barcode_history (barcode, process_name, result_status, process_data, created_at) VALUES (?, 'MOUNTER', 'PASS', ?, NOW())");
                $insH->execute([$bc, $pData]);

                $nextActiveQueue[] = ['barcode' => $bc, 'stage' => 'MOUNTER'];
            } else if ($stage === 'MOUNTER') {
                // REFLOW 공정으로 이동 (SMT 최종 완료)
                $temp = number_format(245 + rand(0, 50)/10, 1);
                $pData = json_encode(["peak_temp_c" => $temp, "time_above_liquidus_sec" => 45]);
                $insH = $pdo->prepare("INSERT INTO barcode_history (barcode, process_name, result_status, process_data, created_at) VALUES (?, 'REFLOW', 'PASS', ?, NOW())");
                $insH->execute([$bc, $pData]);

                $updB = $pdo->prepare("UPDATE barcode_master SET status = 'DONE' WHERE barcode = ?");
                $updB->execute([$bc]);
                // Reflow 완료 후 배출됨 (nextActiveQueue에 넣지 않음)
            }
        } else if ($mode === 'DIP') {
            if ($stage === 'DIP_AOI') {
                // WAVE 공정으로 이동 (DIP 최종 완료)
                $isPass = (rand(1, 100) > 3) ? 'PASS' : 'FAIL';
                $temp = number_format(255 + rand(0, 40)/10, 1);
                $pData = json_encode(["pot_temp_c" => $temp, "conveyor_speed_m_min" => 1.2]);

                $insH = $pdo->prepare("INSERT INTO barcode_history (barcode, process_name, result_status, process_data, created_at) VALUES (?, 'WAVE', ?, ?, NOW())");
                $insH->execute([$bc, $isPass, $pData]);

                $finalStatus = ($isPass === 'PASS') ? 'DONE' : 'DEFECT';
                $updB = $pdo->prepare("UPDATE barcode_master SET status = ? WHERE barcode = ?");
                $updB->execute([$finalStatus, $bc]);
            }
        }
    }

    // 3. 신규 PCB 1장 라인 투입
    if ($nextIndex < count($barcodes)) {
        $newBc = $barcodes[$nextIndex];

        if ($mode === 'SMT') {
            // Laser Marker 첫 공정 투입
            $pData = json_encode(["laser_power_w" => 15.2, "mark_time_ms" => 120]);
            $insH = $pdo->prepare("INSERT INTO barcode_history (barcode, process_name, result_status, process_data, created_at) VALUES (?, 'LASER', 'PASS', ?, NOW())");
            $insH->execute([$newBc, $pData]);

            $updB = $pdo->prepare("UPDATE barcode_master SET status = 'IN_PROGRESS' WHERE barcode = ?");
            $updB->execute([$newBc]);

            $nextActiveQueue[] = ['barcode' => $newBc, 'stage' => 'LASER'];
        } else if ($mode === 'DIP') {
            // DIP AOI 첫 공정 투입
            $pData = json_encode(["pin_soldering_score" => number_format(95 + rand(0, 50)/10, 1), "bridge_detected" => false]);
            $insH = $pdo->prepare("INSERT INTO barcode_history (barcode, process_name, result_status, process_data, created_at) VALUES (?, 'DIP_AOI', 'PASS', ?, NOW())");
            $insH->execute([$newBc, $pData]);

            $updB = $pdo->prepare("UPDATE barcode_master SET status = 'IN_PROGRESS' WHERE barcode = ?");
            $updB->execute([$newBc]);

            $nextActiveQueue[] = ['barcode' => $newBc, 'stage' => 'DIP_AOI'];
        }

        $nextIndex++;
    }

    $activeQueue = $nextActiveQueue;

    // 4. 모든 수량 투입 및 배출 완료 시 작업지시 상태 전환
    if ($nextIndex >= count($barcodes) && empty($activeQueue)) {
        if ($mode === 'SMT') {
            $updWo = $pdo->prepare("UPDATE work_order SET status = 'SMT_DONE' WHERE wo_id = ?");
            $updWo->execute([$wo_id]);
            echo "[SIM] SMT All Done! Status updated to SMT_DONE.\n";
        } else if ($mode === 'DIP') {
            $updWo = $pdo->prepare("UPDATE work_order SET status = 'DONE' WHERE wo_id = ?");
            $updWo->execute([$wo_id]);
            echo "[SIM] DIP All Done! Status updated to DONE.\n";
        }
        break;
    }

    // 2초 간격으로 공정 진행
    sleep(2);
}
?>
