<?php
// Read current chair account details or change the authenticated user's password.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';
$session = current_session();
if (!$session || ($session['role'] ?? '') !== 'chairperson') error_response('Unauthorized', 401);
$id = (string)($session['sub'] ?? '');
if ($id === '') error_response('Invalid session.', 401);
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare('SELECT id, first_name, last_name, email, department, program FROM users WHERE id=? AND role="chairperson" LIMIT 1');
    $stmt->execute([$id]);
    $u = $stmt->fetch();
    if (!$u) error_response('Chairperson account not found.', 404);
    respond(['id' => $u['id'], 'firstName' => $u['first_name'], 'lastName' => $u['last_name'], 'email' => $u['email'], 'department' => $u['department'], 'program' => $u['program']]);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_body();
    $current = (string)($body['currentPassword'] ?? '');
    $next = (string)($body['newPassword'] ?? '');
    if ($current === '' || strlen($next) < 8) error_response('Enter your current password and a new password of at least 8 characters.');
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id=? AND role="chairperson" LIMIT 1');
    $stmt->execute([$id]);
    $hash = $stmt->fetchColumn();
    if (!$hash || !password_verify($current, (string)$hash)) error_response('Current password is incorrect.', 403);
    $update = $pdo->prepare('UPDATE users SET password_hash=? WHERE id=? AND role="chairperson"');
    $update->execute([password_hash($next, PASSWORD_BCRYPT), $id]);
    respond(['status' => 'ok']);
}
error_response('Method not allowed', 405);
