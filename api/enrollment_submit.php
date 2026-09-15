<?php
// api/enrollment_submit.php  ->  POST /api/enrollment_submit.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_response('Method not allowed', 405);
}

$session = current_session();
if (!$session) error_response('Unauthorized', 401);

$body = json_body();
$requestId = $body['id'] ?? ('REQ-' . time());
$studentId = $body['studentId'] ?? '';
$schoolYear = $body['schoolYear'] ?? '';
$term = $body['term'] ?? '';
$type = $body['type'] ?? 'regular';
$subjects = $body['subjects'] ?? [];

if ($studentId === '' || empty($subjects)) {
    error_response('studentId and at least one subject are required.');
}

$pdo = db();
$pdo->beginTransaction();
try {
    $reqStmt = $pdo->prepare(
        'INSERT INTO enrollment_requests (id, student_id, school_year, term, type) VALUES (?, ?, ?, ?, ?)'
    );
    $reqStmt->execute([$requestId, $studentId, $schoolYear, $term, $type]);

    $subStmt = $pdo->prepare(
        'INSERT INTO request_subjects (request_id, sub_code, local_check, status) VALUES (?, ?, ?, "pending")'
    );
    foreach ($subjects as $s) {
        $subStmt->execute([$requestId, $s['subCode'], $s['localCheck'] ?? 'eligible']);
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    error_response('Failed to save submission: ' . $e->getMessage(), 500);
}

respond(['status' => 'ok', 'requestId' => $requestId], 201);
