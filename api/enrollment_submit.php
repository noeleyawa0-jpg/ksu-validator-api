<?php
// api/enrollment_submit.php -> POST /api/enrollment_submit.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') error_response('Method not allowed', 405);

$session = current_session();
if (!$session) error_response('Unauthorized', 401);

$body = json_body();
$requestId = $body['id'] ?? ('REQ-' . time() . random_int(100, 999));
$studentId = trim($body['studentId'] ?? '');
$schoolYear = trim($body['schoolYear'] ?? '');
$term = trim($body['term'] ?? '');
$type = $body['type'] ?? 'regular';
$subjects = $body['subjects'] ?? [];

$tokenStudentId = $session['sub'] ?? '';
if ($tokenStudentId === '' || $studentId !== $tokenStudentId) error_response('Student account mismatch.', 403);
if ($studentId === '' || $schoolYear === '' || $term === '' || empty($subjects)) {
    error_response('studentId, schoolYear, term, and at least one subject are required.');
}

$pdo = db();
$pdo->beginTransaction();

try {
    $studentStmt = $pdo->prepare('SELECT program FROM users WHERE id = ? AND role = "student" LIMIT 1');
    $studentStmt->execute([$studentId]);
    $student = $studentStmt->fetch();
    if (!$student || !$student['program']) error_response('Student program not found.', 400);
    $program = $student['program'];

    $existingStmt = $pdo->prepare(
        'SELECT id FROM enrollment_requests
         WHERE student_id = ? AND school_year = ? AND term = ?
         ORDER BY submitted_at ASC LIMIT 1 FOR UPDATE'
    );
    $existingStmt->execute([$studentId, $schoolYear, $term]);
    if ($existingStmt->fetch()) {
        $pdo->rollBack();
        error_response('You already submitted a pre-enrollment request for this term. Please use Tracking to view its status.', 409);
    }

    $reqStmt = $pdo->prepare(
        'INSERT INTO enrollment_requests (id, student_id, school_year, term, type) VALUES (?, ?, ?, ?, ?)'
    );
    $reqStmt->execute([$requestId, $studentId, $schoolYear, $term, $type]);

    $subStmt = $pdo->prepare(
        'INSERT INTO request_subjects (request_id, sub_code, subject_id, local_check, status)
         SELECT ?, sub_code, subject_id, ?, "pending"
         FROM subjects
         WHERE subject_id = ? AND program_code = ?
         LIMIT 1'
    );

    foreach ($subjects as $s) {
        $subjectId = isset($s['subjectId']) ? (int)$s['subjectId'] : 0;
        if ($subjectId <= 0) {
            $pdo->rollBack();
            error_response('Each selected subject must include subjectId.', 400);
        }
        $subStmt->execute([
            $requestId,
            $s['localCheck'] ?? 'eligible',
            $subjectId,
            $program,
        ]);
        if ($subStmt->rowCount() !== 1) {
            $pdo->rollBack();
            error_response('One or more selected subjects does not belong to your program.', 400);
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Enrollment submission failed: ' . $e->getMessage());
    error_response('Failed to save submission.', 500);
}

respond(['status' => 'ok', 'requestId' => $requestId], 201);
