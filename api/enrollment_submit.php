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
$requestId = $body['id'] ?? ('REQ-' . time() . random_int(100, 999));
$studentId = trim($body['studentId'] ?? '');
$schoolYear = trim($body['schoolYear'] ?? '');
$term = trim($body['term'] ?? '');
$type = $body['type'] ?? 'regular';
$subjects = $body['subjects'] ?? [];

// The signed token is the source of truth for the authenticated student.
$tokenStudentId = $session['sub'] ?? '';
if ($tokenStudentId === '' || $studentId !== $tokenStudentId) {
    error_response('Student account mismatch.', 403);
}

if ($studentId === '' || $schoolYear === '' || $term === '' || empty($subjects)) {
    error_response('studentId, schoolYear, term, and at least one subject are required.');
}

$pdo = db();
$pdo->beginTransaction();

try {
    // Keep the first submission for this student + school year + term.
    // A duplicate is rejected; the original request is never overwritten.
    $existingStmt = $pdo->prepare(
        'SELECT id FROM enrollment_requests
         WHERE student_id = ? AND school_year = ? AND term = ?
         ORDER BY submitted_at ASC LIMIT 1
         FOR UPDATE'
    );
    $existingStmt->execute([$studentId, $schoolYear, $term]);
    $existing = $existingStmt->fetch();

    if ($existing) {
        $pdo->rollBack();
        error_response(
            'You already submitted a pre-enrollment request for this term. '
            . 'Please use Tracking to view its status.',
            409
        );
    }

    $reqStmt = $pdo->prepare(
        'INSERT INTO enrollment_requests (id, student_id, school_year, term, type)
         VALUES (?, ?, ?, ?, ?)'
    );
    $reqStmt->execute([$requestId, $studentId, $schoolYear, $term, $type]);

    $subStmt = $pdo->prepare(
        'INSERT INTO request_subjects (request_id, sub_code, local_check, status)
         VALUES (?, ?, ?, "pending")'
    );

    foreach ($subjects as $s) {
        $subStmt->execute([
            $requestId,
            $s['subCode'],
            $s['localCheck'] ?? 'eligible',
        ]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Enrollment submission failed: ' . $e->getMessage());
    error_response('Failed to save submission.', 500);
}

respond(['status' => 'ok', 'requestId' => $requestId], 201);
