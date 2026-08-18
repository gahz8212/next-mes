<?php
// backend/controllers/QualityController.php

class QualityController {
    /**
     * 1. 품질 검사 기준 목록 조회
     */
    public static function getQualityStandards(): void {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->query("SELECT * FROM quality_standard ORDER BY process_name ASC, id ASC");
            Response::json(["status" => "success", "data" => $stmt->fetchAll()]);
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 2. 품질 검사 기준 등록/수정
     */
    public static function saveQualityStandard(): void {
        try {
            $pdo = Database::getConnection();
            $input = Request::getBody();

            $id             = !empty($input['id']) ? (int)$input['id'] : null;
            $process_name   = trim($input['process_name'] ?? '');
            $check_item     = trim($input['check_item'] ?? '');
            $standard_value = $input['standard_value'] ?? null;
            $unit           = trim($input['unit'] ?? '');
            $is_active      = isset($input['is_active']) ? (int)$input['is_active'] : 1;

            if (empty($process_name) || empty($check_item)) {
                Response::error("필수 항목(공정명, 검사항목)을 입력하세요.");
            }

            if ($id) {
                $stmt = $pdo->prepare("UPDATE quality_standard SET process_name = ?, check_item = ?, standard_value = ?, unit = ?, is_active = ? WHERE id = ?");
                $stmt->execute([$process_name, $check_item, $standard_value, $unit, $is_active, $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO quality_standard (process_name, check_item, standard_value, unit, is_active) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$process_name, $check_item, $standard_value, $unit, $is_active]);
            }

            Response::json(["status" => "success"]);
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 3. 품질 검사 기준 삭제
     */
    public static function deleteQualityStandard(?string $id = null): void {
        try {
            $pdo = Database::getConnection();
            $input = Request::getBody();
            $targetId = $id ?? ($input['id'] ?? null);

            if (empty($targetId)) {
                Response::error("id가 전달되지 않았습니다.");
            }

            $stmt = $pdo->prepare("DELETE FROM quality_standard WHERE id = ?");
            $stmt->execute([$targetId]);

            Response::json(["status" => "success"]);
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }
}
