<?php
// api/validation_action.php  ->  POST /api/validation_action.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_response('Method not allowed', 405);
}

$session = current_session();
if (!$session || $session['role'] !== 'chairperson') error_response('Unauthorized', 401);

$chairProgram = $session['program'] ?? '';
if ($chairProgram === '') {
    error_response('This chairperson account has no program assigned.', 403);
}

$body = json_body();
$requestId = $body['requestId'] ?? '';
$subCode = $body['subCode'] ?? '';
$status = $body['status'] ?? '';
$remarks = $body['remarks'] ?? null;
$override = !empty($body['override']);

if ($requestId === '' || $subCode === '' || !in_array($status, ['validated', 'rejected'], true)) {
    error_response('requestId, subCode, and a valid status are required.');
}

// Defense in depth: confirm this request actually belongs to a student in
// THIS chair's program before allowing any action on it, even though the
// UI only ever shows a chair their own queue in the first place.
$check = db()->prepare('
    SELECT u.program FROM enrollment_requests er
    JOIN users u ON u.id = er.student_id
    WHERE er.id = ? LIMIT 1
');
$check->execute([$requestId]);
$row = $check->fetch();
if (!$row || $row['program'] !== $chairProgram) {
    error_response('This request does not belong to your program.', 403);
}

$stmt = db()->prepare(
    'UPDATE request_subjects SET status = ?, chair_remarks = ?, override_applied = ?
     WHERE request_id = ? AND sub_code = ?'
);
$stmt->execute([$status, $remarks, $override ? 1 : 0, $requestId, $subCode]);

if ($stmt->rowCount() === 0) {
    error_response('No matching request/subject found.', 404);
}

respond(['status' => 'ok']);
