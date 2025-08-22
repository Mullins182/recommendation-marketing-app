<?php
declare(strict_types=1);

namespace App;

class Csrf {
    public static function ensureSession(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public static function token(): string {
        self::ensureSession();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }

    public static function check(?string $token): void {
        self::ensureSession();
        if (!$token || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'CSRF validation failed']);
            exit;
        }
    }
}