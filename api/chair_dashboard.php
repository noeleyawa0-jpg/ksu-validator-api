<?php
// GET /api/chair_dashboard.php
// Program-scoped, read-only summary for the mobile chairperson dashboard.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') error_response('Method not allowed', 405);
$session = current_session();
if (!$session || ($session['role'] ?? '') !== 'chairperson') error_response('Unauthorized', 401);
$program = trim((string)($session['program'] ?? ''));
if ($program === '') error_response('Chairperson account has no assigned program.', 403);
$pdo = db();

$termStmt = $pdo->query('SELECT term_code, school_year, term_name, semester FROM academic_terms WHERE is_current=1 ORDER BY term_code DESC LIMIT 1');
$term = $termStmt->fetch() ?: null;

$studentStmt = $pdo->prepare('SELECT id, first_name, last_name, email, program, year_level, year_section, enrollment_type FROM users WHERE role="student" AND program=? AND year_level BETWEEN 1 AND 4 ORDER BY year_level, last_name, first_name');
$studentStmt->execute([$program]);
$students = $studentStmt->fetchAll();
$total = count($students);
$yearCounts = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
$regular = 0; $irregular = 0; $notDetermined = 0;
foreach ($students as $student) {
    $year = (int)$student['year_level'];
    if (isset($yearCounts[$year])) $yearCounts[$year]++;
    $type = strtolower(trim((string)($student['enrollment_type'] ?? '')));
    if (in_array($type, ['freshman', 'regular'], true)) $regular++;
    elseif (in_array($type, ['irregular', 'transferee', 'shifter', 'returnee', 'crossenroll', 'cross_enroll', 'crossenrollment'], true)) $irregular++;
    else $notDetermined++;
}

// Flag historical subjects for which a required prerequisite has no passing
// record from an earlier term. Term codes are used when present; record ID is
// the fallback for older records without a term code.
$violationSql = '
    SELECT u.id AS student_id, u.first_name, u.last_name, u.program, u.year_level, u.year_section,
           COUNT(DISTINCT CONCAT(ar.id, ":", s.subject_id)) AS violation_count,
           GROUP_CONCAT(DISTINCT CONCAT(ar.sub_code, " — ", s.description) ORDER BY ar.sub_code SEPARATOR " | ") AS subjects
    FROM academic_records ar
    JOIN users u ON u.id=ar.student_id AND u.role="student" AND u.program=?
    JOIN subjects s ON s.program_code=u.program AND s.sub_code=ar.sub_code
    JOIN subject_prerequisites sp ON sp.subject_id=s.subject_id
    JOIN subjects p ON p.subject_id=sp.prereq_subject_id
    WHERE NOT EXISTS (
        SELECT 1 FROM academic_records pr
        WHERE pr.student_id=ar.student_id AND pr.sub_code=p.sub_code AND pr.passed=1
          AND ((ar.term_code IS NOT NULL AND pr.term_code IS NOT NULL AND pr.term_code < ar.term_code)
               OR ((ar.term_code IS NULL OR pr.term_code IS NULL) AND pr.id < ar.id))
    )
    GROUP BY u.id, u.first_name, u.last_name, u.program, u.year_level, u.year_section
    ORDER BY violation_count DESC, u.last_name, u.first_name';
$violationStmt = $pdo->prepare($violationSql);
$violationStmt->execute([$program]);
$violations = array_map(static function ($r) {
    return [
        'studentId' => $r['student_id'], 'firstName' => $r['first_name'], 'lastName' => $r['last_name'],
        'program' => $r['program'], 'yearLevel' => (int)$r['year_level'], 'yearSection' => $r['year_section'] ?? '',
        'violationCount' => (int)$r['violation_count'], 'subjects' => $r['subjects'] ?? '',
    ];
}, $violationStmt->fetchAll());

respond([
    'program' => $program,
    'currentTerm' => $term ? [
        'termCode' => $term['term_code'], 'schoolYear' => $term['school_year'],
        'termName' => $term['term_name'], 'semester' => (int)$term['semester'],
    ] : null,
    'totalStudents' => $total,
    'regularStudents' => $regular,
    'irregularStudents' => $irregular,
    'notDeterminedStudents' => $notDetermined,
    'studentsByYear' => array_map(static fn($year, $count) => ['yearLevel' => $year, 'count' => $count], array_keys($yearCounts), array_values($yearCounts)),
    'studentsWithViolations' => count($violations),
    'violations' => array_slice($violations, 0, 20),
    'classificationSource' => 'Student account enrollment type; review unclassified records when zero/unknown.',
]);
