<?php
// GET /api/grade_enrollments.php?studentId=...
// Return a student's past validated enrollment selections that do not yet
// have an academic grade. The returned term is used directly when saving.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') error_response('Method not allowed', 405);

$session = current_session();
if (!$session || !in_array(($session['role'] ?? ''), ['admin', 'chairperson'], true)) {
    error_response('Staff access required.', 403);
}

$studentId = trim((string)($_GET['studentId'] ?? ''));
if ($studentId === '') error_response('studentId is required.');

$pdo = db();
$studentStmt = $pdo->prepare("SELECT id, program FROM users WHERE id = ? AND role = 'student' LIMIT 1");
$studentStmt->execute([$studentId]);
$student = $studentStmt->fetch();
if (!$student) error_response('Student not found.', 404);

$isChair = ($session['role'] ?? '') === 'chairperson';
$program = trim((string)($isChair ? ($session['program'] ?? '') : $student['program']));
if ($program === '') error_response('This chairperson account has no program assigned.', 403);
if ($isChair && $student['program'] !== $program) {
    error_response('You can only manage grades for students in your assigned program.', 403);
}

$currentStmt = $pdo->query('SELECT term_code FROM academic_terms WHERE is_current = 1 ORDER BY term_code DESC LIMIT 1');
$currentTerm = $currentStmt->fetchColumn();
if (!$currentTerm) {
    respond(['studentId' => $studentId, 'activeTermCode' => null, 'enrollments' => []]);
}

$stmt = $pdo->prepare('SELECT
        er.id AS request_id,
        er.term_code,
        er.school_year,
        er.term AS term_name,
        s.subject_id,
        s.curriculum_subject_id,
        s.program_code,
        s.sub_code,
        s.sched_code,
        s.description,
        s.units,
        s.schedule,
        s.section,
        s.instructor,
        s.is_exclusive,
        s.subject_category,
        s.year_level,
        s.semester,
        s.required_standing
    FROM enrollment_requests er
    JOIN request_subjects rs ON rs.request_id = er.id
    JOIN subjects s ON s.subject_id = rs.subject_id
    WHERE er.student_id = ?
      AND er.term_code < ?
      AND rs.status = "validated"
      AND s.program_code = ?
      AND NOT EXISTS (
          SELECT 1
          FROM academic_records ar
          WHERE ar.student_id = er.student_id
            AND ar.sub_code = s.sub_code
            AND (
                ar.term_code = er.term_code
                OR (COALESCE(ar.term_code, "") = "" AND ar.school_year_taken = er.term_code)
            )
      )
    ORDER BY s.year_level, s.semester, s.sub_code, er.term_code DESC, er.id DESC');
$stmt->execute([$studentId, (string)$currentTerm, $program]);

$enrollments = array_map(static function (array $row): array {
    return [
        'requestId' => (int)$row['request_id'],
        'termCode' => (string)$row['term_code'],
        'schoolYear' => (string)($row['school_year'] ?? ''),
        'termName' => (string)($row['term_name'] ?? ''),
        'subject' => [
            'subjectId' => (int)$row['subject_id'],
            'curriculumSubjectId' => !empty($row['curriculum_subject_id']) ? (int)$row['curriculum_subject_id'] : null,
            'programCode' => (string)$row['program_code'],
            'subCode' => (string)$row['sub_code'],
            'schedCode' => (string)($row['sched_code'] ?? ''),
            'description' => (string)$row['description'],
            'units' => (float)$row['units'],
            'schedule' => (string)($row['schedule'] ?? ''),
            'section' => (string)($row['section'] ?? ''),
            'instructor' => (string)($row['instructor'] ?? ''),
            'prerequisites' => [],
            'requiredStanding' => (int)($row['required_standing'] ?? 0),
            'exclusive' => (bool)$row['is_exclusive'],
            'subjectCategory' => (string)($row['subject_category'] ?? 'major'),
            'yearLevel' => (int)$row['year_level'],
            'semester' => (int)$row['semester'],
        ],
    ];
}, $stmt->fetchAll());

respond([
    'studentId' => $studentId,
    'activeTermCode' => (string)$currentTerm,
    'enrollments' => $enrollments,
]);
