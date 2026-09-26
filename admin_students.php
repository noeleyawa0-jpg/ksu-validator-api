<?php
// Admin-only student record maintenance and semester advancement.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';

function require_admin(): void {
    $session = current_session();
    if (!$session || $session['role'] !== 'admin') {
        error_response('Unauthorized', 401);
    }
}

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $body = json_body();
    $id = trim($body['id'] ?? '');
    $firstName = trim($body['firstName'] ?? '');
    $lastName = trim($body['lastName'] ?? '');
    $email = trim($body['email'] ?? '');
    $program = trim($body['program'] ?? '');
    $yearLevel = filter_var($body['yearLevel'] ?? null, FILTER_VALIDATE_INT);
    $yearSection = trim($body['yearSection'] ?? '');
    $enrollmentType = $body['enrollmentType'] ?? null;

    $validPrograms = ['BSCpE', 'BSIT', 'BSCE', 'BSMath', 'BSEE', 'BSABE'];
    $validTypes = ['freshman', 'regular', 'irregular', 'transferee', 'returnee', 'shifter', 'crossEnroll'];
    if ($id === '' || $firstName === '' || $lastName === '' ||
        !in_array($program, $validPrograms, true) ||
        $yearLevel === false || $yearLevel < 1 || $yearLevel > 4 ||
        !in_array($enrollmentType, $validTypes, true)) {
        error_response('Provide valid student information.');
    }

    $stmt = db()->prepare('
        UPDATE users
        SET first_name = ?, last_name = ?, email = ?, program = ?,
            year_level = ?, year_section = ?, enrollment_type = ?
        WHERE id = ? AND role = "student"
    ');
    $stmt->execute([$firstName, $lastName, $email ?: "$id@ksu.edu.ph", $program,
        $yearLevel, $yearSection, $enrollmentType, $id]);
    if ($stmt->rowCount() === 0) {
        $check = db()->prepare('SELECT id FROM users WHERE id = ? AND role = "student"');
        $check->execute([$id]);
        if (!$check->fetch()) error_response('Student account not found.', 404);
    }
    respond(['status' => 'ok']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_body();
    $program = trim($body['program'] ?? '');
    if ($program === '') error_response('Program is required.');

    // This operation is deliberately admin-triggered, never automatic.
    // Year 4 students stay at Year 4 for graduation review.
    $stmt = db()->prepare('
        UPDATE users
        SET year_level = year_level + 1
        WHERE role = "student" AND program = ? AND year_level BETWEEN 1 AND 3
    ');
    $stmt->execute([$program]);
    respond(['status' => 'ok', 'updated' => $stmt->rowCount()]);
}

error_response('Method not allowed', 405);
