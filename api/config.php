<?php
// Shared DB connection and HTTP helpers for the Render/TiDB deployment.

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function env_required(string $name): string {
    $value = getenv($name);
    if ($value === false || $value === '') {
        throw new RuntimeException("Missing required environment variable: {$name}");
    }
    return $value;
}

define('TOKEN_SECRET', env_required('TOKEN_SECRET'));

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                env_required('DB_HOST'),
                getenv('DB_PORT') ?: '4000',
                env_required('DB_NAME'),
            );
            $pdo = new PDO($dsn, env_required('DB_USER'), env_required('DB_PASS'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_SSL_CA => '/etc/ssl/certs/ca-certificates.crt',
                PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
            ]);
        } catch (Throwable $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Database connection failed.']);
            exit;
        }
    }
    return $pdo;
}

function json_body(): array {
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function respond($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function error_response(string $message, int $status = 400): void {
    respond(['error' => $message], $status);
}
