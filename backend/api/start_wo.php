<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true);
$wo_id = $input['wo_id'] ?? '';
$force = !empty($input['force']); // 강제 가동 플래그

if (!$wo_id) {
    echo json_encode(["status" => "error", "message" => "WO ID가 필요합니다."], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. SMT 라인 인터록 검사: 피더 셋업(오삽 방지 검증)이 100% 완료되었는지 확인
    if (!$force) {
        $checkFeeder = $pdo->prepare("
            SELECT 
                count(*) as total,
                SUM(CASE WHEN status = 'VERIFIED' THEN 1 ELSE 0 END) as verified
            FROM feeder_setup 
            WHERE wo_id = ?
        ");
        $checkFeeder->execute([$wo_id]);
        $feederStat = $checkFeeder->fetch();

        if ($feederStat && $feederStat['total'] > 0 && $feederStat['verified'] < $feederStat['total']) {
            $unverified = $feederStat['total'] - $feederStat['verified'];
            throw new Exception("SMT 라인 인터록 잠김(LOCK): 자재 피킹 및 피더 검증이 완료되지 않았습니다. (미검증 피더: {$unverified}개) 먼저 피더 셋업을 완료해 주세요.");
        }
    }

    // 2. 작업지시 조회 및 상태 검증
    $stmt = $pdo->prepare("SELECT status, target_qty FROM work_order WHERE wo_id = ? FOR UPDATE");
    $stmt->execute([$wo_id]);
    $wo = $stmt->fetch();
    if (!$wo) {
        throw new Exception("해당 작업지시를 찾을 수 없습니다: " . $wo_id);
    }
    if ($wo['status'] !== 'READY' && $wo['status'] !== 'IN_PROGRESS') {
        throw new Exception("해당 작업지시를 시작할 수 없습니다. (현재 상태: " . $wo['status'] . ")");
    }

    // 상태를 IN_PROGRESS로 갱신
    $stmt = $pdo->prepare("UPDATE work_order SET status = 'IN_PROGRESS' WHERE wo_id = ?");
    $stmt->execute([$wo_id]);

    // 바코드 상태를 대기(WAIT)로 리셋하여 LASER 공정부터 다시 정상 투입되도록 처리
    $resetBc = $pdo->prepare("UPDATE barcode_master SET status = 'WAIT' WHERE wo_id = ?");
    $resetBc->execute([$wo_id]);
    
    $pdo->commit();

    // 3. Node-RED (Docker 1881 포트) 시뮬레이션 시작 트리거 전송
    $data = json_encode([
        "wo_id" => $wo_id,
        "target_qty" => intval($wo['target_qty'])
    ]);

    $nrHost = defined('NODERED_HOST') ? NODERED_HOST : '127.0.0.1';
    $nrPort = defined('NODERED_PORT') ? NODERED_PORT : 1881;

    // Node-RED로 논블로킹 즉시 전송
    $fp = @fsockopen($nrHost, $nrPort, $errno, $errstr, 0.5);
    if ($fp) {
        $out = "POST /start-sim HTTP/1.1\r\nHost: {$nrHost}:{$nrPort}\r\nContent-Type: application/json\r\nContent-Length: " . strlen($data) . "\r\nConnection: Close\r\n\r\n" . $data;
        fwrite($fp, $out);
        fclose($fp);
    }

    echo json_encode(["status" => "success", "message" => "SMT 라인 인터록 해제 및 자삽 공정이 시작되었습니다."]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
