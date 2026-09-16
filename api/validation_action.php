<?php
// api/validation_action.php -> POST /api/validation_action.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') error_response('Method not allowed', 405);
$session = current_session();
if (!$session || $session['role'] !== 'chairperson') error_response('Unauthorized', 401);

$chairProgram = $session['program'] ?? '';
if ($chairProgram === '') error_response('This chairperson account has no program assigned.', 403);

$body = json_body();
$requestId = trim($body['requestId'] ?? '');
$subjectId = isset($body['subjectId']) ? (int)$body['subjectId'] : 0;
$status = $body['status'] ?? '';
$remarks = $body['remarks'] ?? null;
$override = !empty($body['override']);

if ($requestId === '' || $subjectId <= 0 || !in_array($status, ['validated', 'rejected'], true)) {
    error_response('requestId, subjectId, and a valid status are required.');
}

$pdo = db();
$check = $pdo->prepare('
    SELECT u.program
    FROM enrollment_requests er
    JOIN users u ON u.id = er.student_id
    JOIN request_subjects rs ON rs.request_id = er.id
    WHERE er.id = ? AND rs.subject_id = ? LIMIT 1
');
$check->execute([$requestId, $subjectId]);
$row = $check->fetch();
if (!$row || $row['program'] !== $chairProgram) error_response('This request/subject does not belong to your program.', 403);

$stmt = $pdo->prepare('
    UPDATE request_subjects
    SET status = ?, chair_remarks = ?, override_applied = ?
    WHERE request_id = ? AND subject_id = ?
');
$stmt->execute([$status, $remarks, $override ? 1 : 0, $requestId, $subjectId]);
if ($stmt->rowCount() === 0) error_response('No matching request/subject found.', 404);

respond(['status' => 'ok']);
