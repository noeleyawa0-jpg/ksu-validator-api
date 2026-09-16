<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') error_response('Method not allowed', 405);
$session = current_session();
if (!$session) error_response('Unauthorized', 401);
$body = json_body();
$requestId = $body['id'] ?? ('REQ-' . time());
$studentId = $body['studentId'] ?? '';
$schoolYear = $body['schoolYear'] ?? '';
$term = $body['term'] ?? '';
$type = $body['type'] ?? 'regular';
$subjects = $body['subjects'] ?? [];
if ($studentId === '' || empty($subjects)) error_response('studentId and at least one subject are required.');
$pdo = db();
$studentStmt = $pdo->prepare("SELECT program FROM users WHERE id=? AND role='student' LIMIT 1");
$studentStmt->execute([$studentId]);
$student = $studentStmt->fetch();
if (!$student) error_response('Student not found.', 404);
$program = $student['program'];
$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT INTO enrollment_requests (id, student_id, school_year, term, type) VALUES (?, ?, ?, ?, ?)')->execute([$requestId,$studentId,$schoolYear,$term,$type]);
    $byCode = $pdo->prepare('SELECT subject_id FROM subjects WHERE program_code=? AND sub_code=? ORDER BY year_level, semester, subject_id');
    $ins = $pdo->prepare('INSERT INTO request_subjects (request_id, subject_id, sub_code, local_check, status) VALUES (?, ?, ?, ?, "pending")');
    foreach ($subjects as $s) {
        $code = trim((string)($s['subCode'] ?? ''));
        $subjectId = isset($s['subjectId']) ? (int)$s['subjectId'] : 0;
        if ($subjectId <= 0) {
            $byCode->execute([$program,$code]);
            $matches = $byCode->fetchAll();
            if (count($matches) !== 1) error_response("Subject '$code' is ambiguous or unavailable for $program.");
            $subjectId = (int)$matches[0]['subject_id'];
        }
        $verify = $pdo->prepare('SELECT sub_code FROM subjects WHERE subject_id=? AND program_code=? LIMIT 1');
        $verify->execute([$subjectId,$program]);
        $row = $verify->fetch();
        if (!$row) error_response("Subject '$code' is not part of $program.");
        $ins->execute([$requestId,$subjectId,$row['sub_code'],$s['localCheck'] ?? 'eligible']);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_response('Failed to save submission: ' . $e->getMessage(), 500);
}
respond(['status'=>'ok','requestId'=>$requestId],201);
