<?php
// GET /api/student_academic_records.php
// Returns the authenticated student's own academic grade history.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    error_response('Method not allowed', 405);
}

$session = current_session();
if (!$session || ($session['role'] ?? '') !== 'student') {
    error_response('Student access required.', 403);
}

$studentId = trim((string)($session['sub'] ?? ''));
if ($studentId === '') error_response('Invalid student session.', 401);

$pdo = db();

$studentStmt = $pdo->prepare("SELECT id, first_name, last_name, program, year_level, year_section FROM users WHERE id = ? AND role = 'student' LIMIT 1");
$studentStmt->execute([$studentId]);
$student = $studentStmt->fetch();
if (!$student) error_response('Student account not found.', 404);

// Use the student's program when resolving the subject title/units. The
// grade history remains keyed by sub_code to match the existing SIS-style
// academic_records table.
$stmt = $pdo->prepare("SELECT
        ar.id,
        ar.student_id,
        ar.sub_code,
        ar.school_year_taken,
        ar.term_code,
        ar.grade,
        ar.passed,
        COALESCE((SELECT s.description
                  FROM subjects s
                  WHERE s.sub_code = ar.sub_code
                    AND s.program_code = ?
                  ORDER BY s.subject_id ASC
                  LIMIT 1), ar.sub_code) AS description,
        COALESCE((SELECT s.units
                  FROM subjects s
                  WHERE s.sub_code = ar.sub_code
                    AND s.program_code = ?
                  ORDER BY s.subject_id ASC
                  LIMIT 1), 0) AS units
    FROM academic_records ar
    WHERE ar.student_id = ?
    ORDER BY ar.school_year_taken DESC,
             CASE
                 WHEN ar.term_code REGEXP '^[0-9]+-[0-9]+$'
                 THEN CAST(SUBSTRING_INDEX(ar.term_code, '-', -1) AS UNSIGNED)
                 ELSE 99
             END DESC,
             ar.sub_code ASC,
             ar.id DESC");
$stmt->execute([$student['program'], $student['program'], $studentId]);

function student_grade_status(string $grade, bool $passed): string {
    if ($passed) return 'qualified';
    if ($grade === 'INC') return 'incomplete';
    if ($grade === 'OD') return 'dropped';
    return 'failed';
}

$records = array_map(function ($r) {
    $grade = strtoupper(trim((string)$r['grade']));
    return [
        'id' => (int)$r['id'],
        'studentId' => (string)$r['student_id'],
        'subCode' => (string)$r['sub_code'],
        'schoolYearTaken' => (string)$r['school_year_taken'],
        'termCode' => $r['term_code'] !== null ? (string)$r['term_code'] : null,
        'grade' => (string)$r['grade'],
        'passed' => (bool)$r['passed'],
        'status' => student_grade_status($grade, (bool)$r['passed']),
        'description' => (string)($r['description'] ?? $r['sub_code']),
        'units' => (float)($r['units'] ?? 0),
    ];
}, $stmt->fetchAll());

respond([
    'studentId' => $student['id'],
    'studentName' => trim($student['first_name'] . ' ' . $student['last_name']),
    'program' => $student['program'],
    'yearLevel' => (int)$student['year_level'],
    'yearSection' => $student['year_section'] ?? '',
    'records' => $records,
]);
