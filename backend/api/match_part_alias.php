<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

$partNo = trim($_GET['part_no'] ?? '');

if (empty($partNo)) {
    echo json_encode(["status" => "error", "message" => "파트번호를 입력하세요."], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 1. Exact match in part_alias
    $stmt = $pdo->prepare("SELECT * FROM part_alias WHERE alias_part_no = ?");
    $stmt->execute([$partNo]);
    $exact = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($exact) {
        // Also fetch other siblings (대체품 목록)
        $sibStmt = $pdo->prepare("SELECT * FROM part_alias WHERE standard_name = ? AND id != ?");
        $sibStmt->execute([$exact['standard_name'], $exact['id']]);
        $siblings = $sibStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "status" => "success",
            "matched" => true,
            "data" => [
                "standard_name" => $exact['standard_name'],
                "standard_code" => $exact['standard_code'],
                "vendor_name"   => $exact['vendor_name'],
                "description"   => $exact['description'],
                "alternatives"  => $siblings
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. Smart Resistor / Capacitor Decoder 추론
    $inferred = null;
    $pnUpper = strtoupper($partNo);

    // Resistor Decoder 예: RC1005F103CS, RC0402FR-0710KL, R0603-10K 등
    if (preg_match('/^(?:RC|R|ERJ|CRCW)?-?(1005|0402|1608|0603|2012|0805|3216|1206)[A-Z\-]*([0-9]{2,4}[RKM]?|[0-9]{3})/i', $pnUpper, $m)) {
        $size = ($m[1] == '1005' || $m[1] == '0402') ? '1005 (0402)' : (($m[1] == '1608' || $m[1] == '0603') ? '1608 (0603)' : $m[1]);
        $inferred = "칩저항 {$size} [규격: {$partNo}]";
    } 
    // MLCC Decoder 예: CL05B104KO5NNNC, GRM155..., C0402-100NF 등
    else if (preg_match('/^(?:CL|GRM|C|EMK|CC)?-?(05|10|15|168|21|0402|0603|0805)[A-Z0-9\-]*/i', $pnUpper, $m)) {
        $inferred = "MLCC 세라믹 커패시터 [규격: {$partNo}]";
    }
    // Inductor Decoder 예: CIH05..., LQG15..., MLG1005..., LPS4018..., VLS3012...
    else if (preg_match('/^(?:CIH|LQG|MLG|HK|LPS|VLS|L-PWR|IND)/i', $pnUpper)) {
        $inferred = "인덕터 / 파워 초크 코일 [규격: {$partNo}]";
    }

    echo json_encode([
        "status" => "success",
        "matched" => false,
        "inferred_name" => $inferred,
        "data" => null
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
