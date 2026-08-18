<?php
// backend/controllers/PartAliasController.php

class PartAliasController {
    /**
     * 1. 업체별 승인 부품 교차 매핑 목록 및 그룹화 조회
     */
    public static function getPartAliases(): void {
        try {
            $pdo = Database::getConnection();
            $search = trim(Request::query('search', ''));
            $companyIdFilter = trim(Request::query('company_id', ''));
            $params = [];
            $whereParts = [];

            if (!empty($search)) {
                $whereParts[] = "(pa.standard_name LIKE :s OR pa.standard_code LIKE :s OR pa.alias_part_no LIKE :s OR pa.vendor_name LIKE :s OR c.name LIKE :s OR pa.description LIKE :s)";
                $params[':s'] = '%' . $search . '%';
            }

            if ($companyIdFilter !== '' && $companyIdFilter !== 'all') {
                $whereParts[] = "pa.company_id = :comp_id";
                $params[':comp_id'] = (int)$companyIdFilter;
            } else {
                $whereParts[] = "pa.company_id IS NOT NULL";
            }

            $whereSql = !empty($whereParts) ? "WHERE " . implode(" AND ", $whereParts) : "";

            $sql = "SELECT pa.*, c.name AS company_name, c.code AS company_code 
                    FROM part_alias pa 
                    JOIN company c ON pa.company_id = c.id 
                    $whereSql 
                    ORDER BY c.name ASC, 
                             pa.standard_name ASC, 
                             pa.created_at DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // KPI 요약
            $summarySql = "SELECT 
                COUNT(DISTINCT pa.company_id) as total_companies,
                COUNT(DISTINCT pa.standard_name) as total_standards,
                COUNT(*) as total_aliases,
                COUNT(DISTINCT pa.vendor_name) as total_vendors,
                SUM(CASE WHEN pa.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as recent_30d
            FROM part_alias pa
            WHERE pa.company_id IS NOT NULL";
            $summaryStmt = $pdo->query($summarySql);
            $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

            // 거래처별 그룹화 (Company Grouped View - Customer Specific Only)
            $companyGroups = [];
            foreach ($records as $r) {
                $cKey = (string)$r['company_id'];
                if (!isset($companyGroups[$cKey])) {
                    $companyGroups[$cKey] = [
                        'company_id'   => (int)$r['company_id'],
                        'company_name' => $r['company_name'],
                        'company_code' => $r['company_code'] ?: '',
                        'is_common'    => false,
                        'total_items'  => 0,
                        'standards'    => []
                    ];
                }
                $companyGroups[$cKey]['total_items']++;

                $stdKey = $r['standard_name'];
                if (!isset($companyGroups[$cKey]['standards'][$stdKey])) {
                    $companyGroups[$cKey]['standards'][$stdKey] = [
                        'standard_name' => $r['standard_name'],
                        'standard_code' => $r['standard_code'],
                        'aliases'       => []
                    ];
                }

                $companyGroups[$cKey]['standards'][$stdKey]['aliases'][] = [
                    'id'            => (int)$r['id'],
                    'company_id'    => (int)$r['company_id'],
                    'company_name'  => $r['company_name'],
                    'standard_name' => $r['standard_name'],
                    'standard_code' => $r['standard_code'],
                    'alias_part_no' => $r['alias_part_no'],
                    'vendor_name'   => $r['vendor_name'],
                    'description'   => $r['description'],
                    'created_at'    => $r['created_at']
                ];
            }

            // standards 연관배열을 순차 배열로 변환
            $groupedResult = [];
            foreach ($companyGroups as $cg) {
                $cg['standards'] = array_values($cg['standards']);
                $groupedResult[] = $cg;
            }

            Response::json([
                "status" => "success",
                "data" => [
                    "summary" => [
                        "total_companies" => (int)($summary['total_companies'] ?? 0),
                        "total_standards" => (int)($summary['total_standards'] ?? 0),
                        "total_aliases"   => (int)($summary['total_aliases'] ?? 0),
                        "total_vendors"   => (int)($summary['total_vendors'] ?? 0),
                        "recent_30d"      => (int)($summary['recent_30d'] ?? 0)
                    ],
                    "records" => $records,
                    "grouped" => $groupedResult
                ]
            ]);
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 2. 부품 교차 매핑 등록 및 일괄 저장
     */
    public static function savePartAlias(): void {
        $pdo = Database::getConnection();
        try {
            $input = Request::getBody();

            // 다건 일괄 등록
            if (isset($input['batch']) && is_array($input['batch'])) {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO part_alias (company_id, standard_name, standard_code, alias_part_no, vendor_name, description) 
                                        VALUES (?, ?, ?, ?, ?, ?)");
                $count = 0;
                foreach ($input['batch'] as $row) {
                    $stdName   = trim($row['standard_name'] ?? '');
                    $aliasPn   = trim($row['alias_part_no'] ?? '');
                    $companyId = !empty($row['company_id']) ? (int)$row['company_id'] : null;
                    if (!empty($stdName) && !empty($aliasPn) && !empty($companyId)) {
                        $stdCode = trim($row['standard_code'] ?? '') ?: null;
                        $vendor  = trim($row['vendor_name'] ?? '') ?: null;
                        $desc    = trim($row['description'] ?? '') ?: null;
                        $stmt->execute([$companyId, $stdName, $stdCode, $aliasPn, $vendor, $desc]);
                        $count++;
                    }
                }
                $pdo->commit();
                Response::json(["status" => "success", "message" => "총 {$count}건의 부품 교차 매핑이 저장되었습니다.", "count" => $count]);
                return;
            }

            // 단건 등록/수정
            $id            = !empty($input['id']) ? (int)$input['id'] : null;
            $company_id    = !empty($input['company_id']) ? (int)$input['company_id'] : null;
            $standard_name = trim($input['standard_name'] ?? '');
            $standard_code = trim($input['standard_code'] ?? '') ?: null;
            $alias_part_no = trim($input['alias_part_no'] ?? '');
            $vendor_name   = trim($input['vendor_name'] ?? '') ?: null;
            $description   = trim($input['description'] ?? '') ?: null;

            if (empty($company_id)) {
                Response::error("승인 적용할 거래처(고객사)를 반드시 지정해야 합니다.");
            }

            if (empty($standard_name) || empty($alias_part_no)) {
                Response::error("대표 파트명과 실제 파트번호는 필수입니다.");
            }

            if ($id) {
                $stmt = $pdo->prepare("UPDATE part_alias SET company_id = ?, standard_name = ?, standard_code = ?, alias_part_no = ?, vendor_name = ?, description = ? WHERE id = ?");
                $stmt->execute([$company_id, $standard_name, $standard_code, $alias_part_no, $vendor_name, $description, $id]);
                $msg = "부품 교차 매핑 수정 완료";
            } else {
                $stmt = $pdo->prepare("INSERT INTO part_alias (company_id, standard_name, standard_code, alias_part_no, vendor_name, description) 
                                        VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$company_id, $standard_name, $standard_code, $alias_part_no, $vendor_name, $description]);
                $id = (int)$pdo->lastInsertId();
                $msg = "부품 교차 매핑 등록 완료";
            }

            Response::json(["status" => "success", "message" => $msg, "data" => ["id" => $id]]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Response::error($e->getMessage());
        }
    }

    /**
     * 3. 부품 교차 매핑 삭제
     */
    public static function deletePartAlias(?string $id = null): void {
        try {
            $pdo = Database::getConnection();
            $input = Request::getBody();
            $aliasId = $id ?? ($input['id'] ?? null);

            if (!$aliasId) {
                Response::error("삭제할 매핑 ID가 필요합니다.");
            }

            $stmt = $pdo->prepare("DELETE FROM part_alias WHERE id = ?");
            $stmt->execute([$aliasId]);
            Response::json(["status" => "success", "message" => "부품 매핑이 삭제되었습니다."]);
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 4. 파트번호 자동 매칭 및 추론
     */
    public static function matchPartAlias(): void {
        try {
            $pdo = Database::getConnection();
            $partNo = trim(Request::query('part_no', Request::input('part_no', '')));
            $companyId = Request::query('company_id', Request::input('company_id', null));

            if (empty($partNo)) {
                Response::error("파트번호를 입력하세요.");
            }

            // 1. Exact match in part_alias (customer-scoped only)
            $stmt = $pdo->prepare("SELECT pa.*, c.name as company_name 
                                    FROM part_alias pa 
                                    JOIN company c ON pa.company_id = c.id 
                                    WHERE pa.alias_part_no = ? AND pa.company_id = ? 
                                    LIMIT 1");
            $stmt->execute([$partNo, $companyId]);
            $exact = $stmt->fetch();

            if ($exact) {
                $sibStmt = $pdo->prepare("SELECT * FROM part_alias WHERE standard_name = ? AND company_id = ? AND id != ?");
                $sibStmt->execute([$exact['standard_name'], $exact['company_id'], $exact['id']]);
                $siblings = $sibStmt->fetchAll();

                Response::json([
                    "status" => "success",
                    "matched" => true,
                    "data" => [
                        "company_id"    => $exact['company_id'],
                        "company_name"  => $exact['company_name'],
                        "standard_name" => $exact['standard_name'],
                        "standard_code" => $exact['standard_code'],
                        "vendor_name"   => $exact['vendor_name'],
                        "description"   => $exact['description'],
                        "alternatives"  => $siblings
                    ]
                ]);
                return;
            }

            // 2. Decoder 추론 엔진 연동
            $decoded = PartDecoder::decode($partNo);
            $inferred = $decoded['success'] ? $decoded['standard_name'] : null;

            Response::json([
                "status"        => "success",
                "matched"       => false,
                "inferred_name" => $inferred,
                "data"          => $decoded['success'] ? $decoded : null
            ]);
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 5. 스마트 부품 규격 디코딩 & 대치품/데이터시트 탐색
     */
    public static function decodeSpec(): void {
        try {
            $input = Request::getBody();
            $spec = trim(Request::query('spec', $input['spec'] ?? Request::query('part_no', $input['part_no'] ?? '')));
            $location = trim(Request::query('location', $input['location'] ?? ''));

            if (empty($spec)) {
                Response::error("분석할 부품 규격 또는 파트번호를 입력하세요.");
            }

            $decoded = PartDecoder::decode($spec, $location);
            Response::json([
                "status" => "success",
                "data"   => $decoded
            ]);
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 6. 대치품 원클릭 공인(AVL) 채택 및 part_alias 자동 등록
     */
    public static function adoptAlternate(): void {
        $pdo = Database::getConnection();
        try {
            $input = Request::getBody();

            $company_id    = !empty($input['company_id']) ? (int)$input['company_id'] : null;
            $standard_name = trim($input['standard_name'] ?? '');
            $standard_code = trim($input['standard_code'] ?? '') ?: null;
            $alias_part_no = trim($input['alias_part_no'] ?? '');
            $vendor_name   = trim($input['vendor_name'] ?? '') ?: null;
            $description   = trim($input['description'] ?? '') ?: null;

            if (empty($company_id)) {
                Response::error("대치품을 승인 적용할 거래처(고객사) 정보가 없습니다. 고객사 전용 승인만 가능합니다.");
            }

            if (empty($standard_name) || empty($alias_part_no)) {
                Response::error("대표 품명(standard_name)과 제조사 품번(alias_part_no)은 필수입니다.");
            }

            $stmt = $pdo->prepare("
                INSERT INTO part_alias (company_id, standard_name, standard_code, alias_part_no, vendor_name, description)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    company_id    = VALUES(company_id),
                    standard_name = VALUES(standard_name),
                    standard_code = VALUES(standard_code),
                    vendor_name   = VALUES(vendor_name),
                    description   = VALUES(description)
            ");
            $stmt->execute([$company_id, $standard_name, $standard_code, $alias_part_no, $vendor_name, $description]);
            $id = (int)$pdo->lastInsertId();

            Response::json([
                "status"  => "success",
                "message" => "[{$vendor_name}]의 대치품 '{$alias_part_no}'이 고객사 공인 AVL로 승인/등록되었습니다.",
                "data"    => ["id" => $id, "alias_part_no" => $alias_part_no, "company_id" => $company_id]
            ]);

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }
}
