<?php
// backend/api/sim_worker.php - SMT / DIP 라인 실시간 생산 시뮬레이터 워커
if (php_sapi_name() !== 'cli' && (!isset($_GET['secret']) || $_GET['secret'] !== 'mes_sim')) {
    die("CLI execution only.");
}

require_once __DIR__ . '/bootstrap.php';

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
                $isPass = (rand(1, 100) > 3) ? 'PASS' : 'FAIL';
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
                // MOUNTER_1 (고속 마운터) 공정으로 이동
                $pData = json_encode(["mounted_chips" => 48, "vacuum_kpa" => -84.5, "head_vibration_g" => 0.088]);
                $insH = $pdo->prepare("INSERT INTO barcode_history (barcode, process_name, result_status, process_data, created_at) VALUES (?, 'MOUNTER_1', 'PASS', ?, NOW())");
                $insH->execute([$bc, $pData]);

                $nextActiveQueue[] = ['barcode' => $bc, 'stage' => 'MOUNTER_1'];
            } else if ($stage === 'MOUNTER_1') {
                // MOUNTER_2 (이형 마운터) 공정으로 이동
                $pData = json_encode(["mounted_ic" => 6, "align_theta_deg" => 0.12, "force_n" => 1.85]);
                $insH = $pdo->prepare("INSERT INTO barcode_history (barcode, process_name, result_status, process_data, created_at) VALUES (?, 'MOUNTER_2', 'PASS', ?, NOW())");
                $insH->execute([$bc, $pData]);

                $nextActiveQueue[] = ['barcode' => $bc, 'stage' => 'MOUNTER_2'];
            } else if ($stage === 'MOUNTER_2') {
                // REFLOW 공정으로 이동 (SMT 최종 솔더링)
                $temp = number_format(245 + rand(0, 50)/10, 1);
                $pData = json_encode(["peak_temp_c" => $temp, "tal_sec" => 52.0, "oxygen_ppm" => 375]);
                $insH = $pdo->prepare("INSERT INTO barcode_history (barcode, process_name, result_status, process_data, created_at) VALUES (?, 'REFLOW', 'PASS', ?, NOW())");
                $insH->execute([$bc, $pData]);

                $updB = $pdo->prepare("UPDATE barcode_master SET status = 'DONE' WHERE barcode = ?");
                $updB->execute([$bc]);
                // Reflow 완료 후 SMT 라인 배출
            }
        } else if ($mode === 'DIP') {
            if ($stage === 'DIP_AOI') {
                // WAVE (웨이브 솔더링) 공정으로 이동
                $temp = number_format(250 + rand(0, 40)/10, 1);
                $pData = json_encode(["pot_temp_c" => $temp, "wave_height_mm" => 9.15]);
                $insH = $pdo->prepare("INSERT INTO barcode_history (barcode, process_name, result_status, process_data, created_at) VALUES (?, 'WAVE', 'PASS', ?, NOW())");
                $insH->execute([$bc, $pData]);

                $nextActiveQueue[] = ['barcode' => $bc, 'stage' => 'WAVE'];
            } else if ($stage === 'WAVE') {
                // ICT (인서킷 전기 회로 검사) 공정으로 이동
                $isPass = (rand(1, 100) > 3) ? 'PASS' : 'FAIL';
                $pData = json_encode(["contact_res_ohm" => 45.2, "res_accuracy_pct" => 99.8, "channels_tested" => 512]);
                $insH = $pdo->prepare("INSERT INTO barcode_history (barcode, process_name, result_status, process_data, created_at) VALUES (?, 'ICT', ?, ?, NOW())");
                $insH->execute([$bc, $isPass, $pData]);

                if ($isPass === 'PASS') {
                    $nextActiveQueue[] = ['barcode' => $bc, 'stage' => 'ICT'];
                } else {
                    $updB = $pdo->prepare("UPDATE barcode_master SET status = 'DEFECT' WHERE barcode = ?");
                    $updB->execute([$bc]);
                }
            } else if ($stage === 'ICT') {
                // COATING (방습/절연 코팅 & UV 경화) 공정으로 이동
                $pData = json_encode(["film_thickness_um" => 75.0, "uv_energy_mj" => 1250, "dispense_press_mpa" => 0.35]);
                $insH = $pdo->prepare("INSERT INTO barcode_history (barcode, process_name, result_status, process_data, created_at) VALUES (?, 'COATING', 'PASS', ?, NOW())");
                $insH->execute([$bc, $pData]);

                $nextActiveQueue[] = ['barcode' => $bc, 'stage' => 'COATING'];
            } else if ($stage === 'COATING') {
                // FCT (최종 완제품 기능 동작 검사) 공정으로 이동
                $isPass = (rand(1, 100) > 2) ? 'PASS' : 'FAIL';
                $pData = json_encode(["mcu_volt_v" => 5.02, "can_resp_ms" => 4.8, "curr_draw_ma" => 142.5]);
                $insH = $pdo->prepare("INSERT INTO barcode_history (barcode, process_name, result_status, process_data, created_at) VALUES (?, 'FCT', ?, ?, NOW())");
                $insH->execute([$bc, $isPass, $pData]);

                $finalStatus = ($isPass === 'PASS') ? 'DONE' : 'DEFECT';
                $updB = $pdo->prepare("UPDATE barcode_master SET status = ? WHERE barcode = ?");
                $updB->execute([$finalStatus, $bc]);
                // FCT 완료 후 최종 출하 대기
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
