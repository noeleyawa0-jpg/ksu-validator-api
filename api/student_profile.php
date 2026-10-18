<?php
// api/student_profile.php  ->  GET /api/student_profile.php?id=25-116391

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    error_response('Method not allowed', 405);
}

$session = current_session();
if (!$session) error_response('Unauthorized', 401);

$studentId = $_GET['id'] ?? '';
if ($studentId === '') error_response('Missing id parameter.');

$stmt = db()->prepare("SELECT * FROM users WHERE id = ? AND role = 'student' LIMIT 1");
$stmt->execute([$studentId]);
$student = $stmt->fetch();
if (!$student) error_response('Student not found.', 404);

$probationTermStmt = db()->prepare('SELECT term_code FROM academic_records WHERE student_id=? AND term_code IS NOT NULL GROUP BY term_code ORDER BY MAX(id) DESC LIMIT 1');
$probationTermStmt->execute([$studentId]);
$probationTerm = $probationTermStmt->fetchColumn();
$probationStmt = db()->prepare('SELECT COUNT(*) AS total, SUM(CASE WHEN passed=0 THEN 1 ELSE 0 END) AS failed FROM academic_records WHERE student_id=? AND term_code=?');
$probationStmt->execute([$studentId, $probationTerm ?: '']);
$probationCounts = $probationStmt->fetch() ?: ['total'=>0,'failed'=>0];
$probationTotal = (int)($probationCounts['total'] ?? 0);
$probationFailed = (int)($probationCounts['failed'] ?? 0);
$isOnProbation = $probationTotal > 0 && ($probationFailed / $probationTotal) >= 0.5;

$histStmt = db()->prepare('SELECT sub_code, school_year_taken, grade, passed FROM academic_records WHERE student_id = ?');
$histStmt->execute([$studentId]);
$history = array_map(function ($r) {
    return [
        'subCode' => $r['sub_code'],
        'schoolYearTaken' => $r['school_year_taken'],
        'grade' => $r['grade'],
        'passed' => (bool) $r['passed'],
    ];
}, $histStmt->fetchAll());

respond([
    'studentId' => $student['id'],
    'firstName' => $student['first_name'],
    'lastName' => $student['last_name'],
    'program' => $student['program'],
    'department' => $student['department'],
    'yearLevel' => (int) $student['year_level'],
    'yearSection' => $student['year_section'],
    'enrollmentType' => $student['enrollment_type'],
    'isOnProbation' => $isOnProbation,
    'probationFailedCount' => $probationFailed,
    'probationSubjectCount' => $probationTotal,
    'probationTermCode' => $probationTerm ?: null,
    'history' => $history,
    'lastSynced' => (new DateTime())->format(DateTime::ATOM),
]);
