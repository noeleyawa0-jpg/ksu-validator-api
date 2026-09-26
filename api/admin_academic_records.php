<?php
// api/admin_academic_records.php
// GET    ?studentId=...              -> read one student's academic records
// POST   {studentId, subCode, schoolYearTaken, grade} -> add/update a grade
// DELETE ?id=...                     -> delete one academic record
// Admin-only grade maintenance used for testing the Chairperson prerequisite
// validation flow.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';

function require_admin_or_chair(): array {
    $session = current_session();
    if (!$session || !in_array(($session['role'] ?? ''), ['admin', 'chairperson'], true)) {
        error_response('Unauthorized', 401);
    }
    return $session;
}

function normalize_grade(string $grade): string {
    $g = strtoupper(trim($grade));
    if ($g === 'INCOMPLETE') return 'INC';
    if ($g === 'DROPPED' || $g === 'DROP') return 'OD';
    return $g;
}

function is_valid_kSU_grade(string $grade): bool {
    return in_array($grade, [
        '1.00', '1.25', '1.50', '1.75', '2.00', '2.25', '2.50', '2.75', '3.00',
        '5.00', 'INC', 'OD'
    ], true);
}

function passed_from_grade(string $grade): bool {
    return in_array($grade, [
        '1.00', '1.25', '1.50', '1.75', '2.00', '2.25', '2.50', '2.75', '3.00'
    ], true);
}

function status_from_grade(string $grade, bool $passed): string {
    if ($passed) return 'qualified';
    if ($grade === 'INC') return 'incomplete';
    if ($grade === 'OD') return 'dropped';
    return 'failed';
}

$session = require_admin_or_chair();
$pdo = db();
$isChair = ($session['role'] ?? '') === 'chairperson';
$chairProgram = trim((string)($session['program'] ?? ''));
if ($isChair && $chairProgram === '') {
    error_response('This chairperson account has no program assigned.', 403);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $studentId = trim($_GET['studentId'] ?? '');
    if ($studentId === '') error_response('studentId is required.');

    $studentStmt = $pdo->prepare("SELECT id, first_name, last_name, program FROM users WHERE id = ? AND role = 'student' LIMIT 1");
    $studentStmt->execute([$studentId]);
    $student = $studentStmt->fetch();
    if (!$student) error_response('Student not found.', 404);
    if ($isChair && $student['program'] !== $chairProgram) {
        error_response('You can only manage grades for students in your assigned program.', 403);
    }

    $stmt = $pdo->prepare('SELECT id, student_id, sub_code, school_year_taken, grade, passed FROM academic_records WHERE student_id = ? ORDER BY school_year_taken DESC, sub_code ASC, id DESC');
    $stmt->execute([$studentId]);

    $records = array_map(function ($r) {
        $grade = strtoupper(trim((string)$r['grade']));
        $status = status_from_grade($grade, (bool)$r['passed']);
        return [
            'id' => (int)$r['id'],
            'studentId' => $r['student_id'],
            'subCode' => $r['sub_code'],
            'schoolYearTaken' => $r['school_year_taken'],
            'grade' => $r['grade'],
            'passed' => (bool)$r['passed'],
            'status' => $status,
        ];
    }, $stmt->fetchAll());

    respond([
        'studentId' => $student['id'],
        'studentName' => trim($student['first_name'] . ' ' . $student['last_name']),
        'program' => $student['program'],
        'records' => $records,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_body();
    $studentId = trim($body['studentId'] ?? '');
    $subCode = trim($body['subCode'] ?? '');
    $schoolYearTaken = trim($body['schoolYearTaken'] ?? '');
    $grade = normalize_grade((string)($body['grade'] ?? ''));

    if ($studentId === '' || $subCode === '' || $schoolYearTaken === '' || $grade === '') {
        error_response('studentId, subCode, schoolYearTaken, and grade are required.');
    }
    if (!is_valid_kSU_grade($grade)) {
        error_response('Invalid KSU grade. Use 1.00, 1.25, 1.50, 1.75, 2.00, 2.25, 2.50, 2.75, 3.00, 5.00, INC, or OD.');
    }

    $studentStmt = $pdo->prepare("SELECT id, program FROM users WHERE id = ? AND role = 'student' LIMIT 1");
    $studentStmt->execute([$studentId]);
    $student = $studentStmt->fetch();
    if (!$student) error_response('Student not found.', 404);
    if ($isChair && $student['program'] !== $chairProgram) {
        error_response('You can only manage grades for students in your assigned program.', 403);
    }

    // Verify the subject exists for at least one curriculum program. The
    // academic_records table intentionally remains keyed by sub_code because
    // that is the existing schema used by the SIS-style history API.
    if ($isChair) {
        $subjectStmt = $pdo->prepare('SELECT sub_code FROM subjects WHERE sub_code = ? AND program_code = ? LIMIT 1');
        $subjectStmt->execute([$subCode, $chairProgram]);
    } else {
        $subjectStmt = $pdo->prepare('SELECT sub_code FROM subjects WHERE sub_code = ? LIMIT 1');
        $subjectStmt->execute([$subCode]);
    }
    if (!$subjectStmt->fetch()) error_response('Subject code not found in the assigned program curriculum.', 404);

    $passed = passed_from_grade($grade) ? 1 : 0;

    $existingStmt = $pdo->prepare('SELECT id FROM academic_records WHERE student_id = ? AND sub_code = ? AND school_year_taken = ? ORDER BY id DESC LIMIT 1');
    $existingStmt->execute([$studentId, $subCode, $schoolYearTaken]);
    $existing = $existingStmt->fetch();

    if ($existing) {
        $stmt = $pdo->prepare('UPDATE academic_records SET grade = ?, passed = ? WHERE id = ?');
        $stmt->execute([$grade, $passed, $existing['id']]);
        $recordId = (int)$existing['id'];
    } else {
        $stmt = $pdo->prepare('INSERT INTO academic_records (student_id, sub_code, school_year_taken, grade, passed) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$studentId, $subCode, $schoolYearTaken, $grade, $passed]);
        $recordId = (int)$pdo->lastInsertId();
    }

    respond([
        'status' => 'ok',
        'id' => $recordId,
        'grade' => $grade,
        'passed' => (bool)$passed,
        'result' => status_from_grade($grade, $passed),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) error_response('Record id is required.');

    if ($isChair) {
        $stmt = $pdo->prepare('DELETE ar FROM academic_records ar JOIN users u ON u.id = ar.student_id WHERE ar.id = ? AND u.role = 'student' AND u.program = ?');
        $stmt->execute([$id, $chairProgram]);
    } else {
        $stmt = $pdo->prepare('DELETE FROM academic_records WHERE id = ?');
        $stmt->execute([$id]);
    }
    if ($stmt->rowCount() === 0) error_response('Academic record not found or outside your assigned program.', 404);
    respond(['status' => 'ok']);
}

error_response('Method not allowed', 405);
