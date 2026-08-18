<?php
// backend/controllers/UserController.php

class UserController {
    /**
     * 1. 사용자 목록 조회
     */
    public static function getUsers(): void {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->query("SELECT id, username, name, role, department, is_active, last_login, created_at FROM users ORDER BY role ASC, name ASC");
            Response::json(["status" => "success", "data" => $stmt->fetchAll()]);
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 2. 사용자 등록
     */
    public static function createUser(): void {
        try {
            $pdo = Database::getConnection();
            $input = Request::getBody();

            $username   = trim($input['username'] ?? '');
            $password   = trim($input['password'] ?? '');
            $name       = trim($input['name'] ?? '');
            $role       = trim($input['role'] ?? '');
            $department = trim($input['department'] ?? '');

            if (empty($username) || empty($password) || empty($name)) {
                Response::error("필수 항목(아이디, 비밀번호, 이름)을 입력하세요.");
            }

            $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmtCheck->execute([$username]);
            if ($stmtCheck->fetch()) {
                Response::error("이미 사용 중인 아이디입니다.");
            }

            $password_hash = hash('sha256', $password);
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, name, role, department) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$username, $password_hash, $name, $role, $department]);
            $id = (int)$pdo->lastInsertId();

            Response::json(["status" => "success", "data" => ["id" => $id]]);

        } catch (PDOException $e) {
            if ($e->getCode() == 23000 || strpos($e->getMessage(), 'Duplicate entry') !== false) {
                Response::error("이미 사용 중인 아이디입니다.");
            } else {
                Response::error($e->getMessage());
            }
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 3. 사용자 수정
     */
    public static function updateUser(?string $id = null): void {
        try {
            $pdo = Database::getConnection();
            $input = Request::getBody();

            $userId     = $id ?? ($input['id'] ?? null);
            $name       = trim($input['name'] ?? '');
            $role       = trim($input['role'] ?? '');
            $department = trim($input['department'] ?? '');
            $is_active  = isset($input['is_active']) ? (int)$input['is_active'] : 1;
            $password   = trim($input['password'] ?? '');

            if (empty($userId) || empty($name)) {
                Response::error("필수 항목(id, 이름)을 입력하세요.");
            }

            if (!empty($password)) {
                $password_hash = hash('sha256', $password);
                $stmt = $pdo->prepare("UPDATE users SET name = ?, role = ?, department = ?, is_active = ?, password_hash = ? WHERE id = ?");
                $stmt->execute([$name, $role, $department, $is_active, $password_hash, $userId]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET name = ?, role = ?, department = ?, is_active = ? WHERE id = ?");
                $stmt->execute([$name, $role, $department, $is_active, $userId]);
            }

            Response::json(["status" => "success"]);

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 4. 사용자 삭제
     */
    public static function deleteUser(?string $id = null): void {
        try {
            $pdo = Database::getConnection();
            $input = Request::getBody();
            $userId = $id ?? ($input['id'] ?? null);

            if (empty($userId)) {
                Response::error("id가 전달되지 않았습니다.");
            }

            if ((int)$userId === 1) {
                Response::error("기본 관리자 계정은 삭제할 수 없습니다.");
            }

            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$userId]);

            Response::json(["status" => "success"]);

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }
}
