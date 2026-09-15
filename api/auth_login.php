<?php
// api/auth_login.php  ->  POST /api/auth_login.php
// Body: { "id": "25-116391", "password": "password123", "role": "student" }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_response('Method not allowed', 405);
}

$body = json_body();
$id = trim($body['id'] ?? '');
$password = $body['password'] ?? '';
$role = $body['role'] ?? '';

if ($id === '' || $password === '' || $role === '') {
    error_response('id, password, and role are required.');
}

$stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND role = ? LIMIT 1');
$stmt->execute([$id, $role]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    error_response('Invalid credentials.', 401);
}

$token = issue_token($user['id'], $user['role']);

respond([
    'id' => $user['id'],
    'firstName' => $user['first_name'],
    'lastName' => $user['last_name'],
    'email' => $user['email'],
    'role' => $user['role'],
    'department' => $user['department'],
    'program' => $user['program'],
    'token' => $token,
]);
