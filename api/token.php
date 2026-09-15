<?php
// api/token.php
// A minimal signed-token helper — dependency-free stand-in for JWT so this
// project runs straight out of htdocs with zero Composer setup.

require_once __DIR__ . '/config.php';

function issue_token(string $userId, string $role): string {
    $payload = [
        'sub' => $userId,
        'role' => $role,
        'exp' => time() + (60 * 60 * 12), // 12-hour session
    ];
    $payloadJson = base64_encode(json_encode($payload));
    $signature = hash_hmac('sha256', $payloadJson, TOKEN_SECRET);
    return $payloadJson . '.' . $signature;
}

function verify_token(?string $token): ?array {
    if (!$token) return null;
    $parts = explode('.', $token);
    if (count($parts) !== 2) return null;
    [$payloadJson, $signature] = $parts;
    $expected = hash_hmac('sha256', $payloadJson, TOKEN_SECRET);
    if (!hash_equals($expected, $signature)) return null;
    $payload = json_decode(base64_decode($payloadJson), true);
    if (!$payload || $payload['exp'] < time()) return null;
    return $payload;
}

function current_session(): ?array {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $auth = $headers['Authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (!str_starts_with($auth, 'Bearer ')) return null;
    return verify_token(substr($auth, 7));
}
