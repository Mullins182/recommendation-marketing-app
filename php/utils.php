<?php
declare(strict_types=1);

namespace App;

function jsonResponse(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function appUrl(string $path = ''): string {
    $base = rtrim($_ENV['APP_URL'], '/');
    $path = ltrim($path, '/');
    return $base . '/' . $path;
}

function getClientIp(): string {
    return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}