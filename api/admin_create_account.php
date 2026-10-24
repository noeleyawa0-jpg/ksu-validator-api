<?php
// api/admin_create_account.php  ->  POST /api/admin_create_account.php
// Admin-only. Creates a new login account — most commonly a freshman
// student account that the Admin then hands off to that program's
// chairperson to distribute.
//
// Body (student):
// {
//   "id": "26-000123", "password": "TempPass1", "role": "student",
//   "firstName": "Juan", "lastName": "Dela Cruz", "email": "...",
//   "program": "BSIT", "yearLevel": 1, "yearSection": "1-A",
//   "enrollmentType": "freshman"
// }
//
// Body (chairperson):
// {
//   "id": "CHAIR-BSIT", "password": "TempPass1", "role": "chairperson",
//   "firstName": "BSIT", "lastName": "Chairperson", "email": "...",
//   "program": "BSIT"
// }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';
require_once __DIR__ . '/account_email.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_response('Method not allowed', 405);
}

$session = current_session();
if (!$session || $session['role'] !== 'admin') error_response('Unauthorized', 401);

$body = json_body();
$id = trim($body['id'] ?? '');
$password = $body['password'] ?? '';
$role = $body['role'] ?? 'student';
$firstName = trim($body['firstName'] ?? '');
$lastName = trim($body['lastName'] ?? '');
$email = trim($body['email'] ?? '');
$program = $body['program'] ?? null;
$yearLevel = $body['yearLevel'] ?? null;
$yearSection = $body['yearSection'] ?? null;
$enrollmentType = $body['enrollmentType'] ?? null;

if ($id === '' || $password === '' || $firstName === '' || $lastName === '') {
    error_response('id, password, firstName, and lastName are required.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) error_response('A valid student email address is required.');
if (strlen($password) < 8) error_response('Use a password of at least 8 characters.');
if (!in_array($role, ['student', 'chairperson'], true)) {
    error_response('role must be "student" or "chairperson".');
}
if ($role === 'student' && ($program === null || $yearLevel === null)) {
    error_response('Student accounts require program and yearLevel.');
}

$existing = db()->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
$existing->execute([$id]);
if ($existing->fetch()) {
    error_response('An account with this ID already exists.', 409);
}

try {
    $mail = prepare_account_email($email, "$firstName $lastName", $id, $password, $role);
} catch (Throwable $e) {
    error_response('Email delivery is not configured. Ask the administrator to configure the email sender before creating accounts.', 503);
}

$hash = password_hash($password, PASSWORD_BCRYPT);
$pdo = db();
$pdo->beginTransaction();
try {

$stmt = db()->prepare('
    INSERT INTO users (id, password_hash, role, first_name, last_name, email,
                        department, program, year_level, year_section, enrollment_type)
    VALUES (?, ?, ?, ?, ?, ?, "CEIT", ?, ?, ?, ?)
');
$stmt->execute([
    $id, $hash, $role, $firstName, $lastName, $email ?: "$id@ksu.edu.ph",
    $program, $yearLevel, $yearSection, $enrollmentType,
]);
    $mail->send();
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Account creation or credential email failed.');
    error_response('Unable to create the account and email its login details. The account was not created; check the email sender and try again.', 502);
}

respond(['status' => 'ok', 'id' => $id, 'emailStatus' => 'sent', 'message' => 'Account created. Login details were sent to ' . $email . '.'], 201);
