<?php
// api/validation_queue.php -> GET /api/validation_queue.php[?program=BSIT]
// Returns enrollment requests plus prerequisite checks backed by the student's
// academic_records. Chairpersons are always scoped to their own program.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') error_response('Method not allowed', 405);
$session = current_session();
if (!$session) error_response('Unauthorized', 401);

if ($session['role'] === 'chairperson') {
    $program = trim((string)($session['program'] ?? ''));
    if ($program === '') error_response('This chairperson account has no program assigned.', 403);
} elseif ($session['role'] === 'admin') {
    $program = trim((string)($_GET['program'] ?? ''));
    if ($program === '') error_response('Missing program parameter.');
} else {
    error_response('Unauthorized', 401);
}

$pdo = db();
$reqStmt = $pdo->prepare('
    SELECT er.*,
           u.first_name AS student_first_name,
           u.last_name AS student_last_name,
           u.program AS student_program,
           u.year_level AS student_year_level,
           u.year_section AS student_year_section
    FROM enrollment_requests er
    JOIN users u ON u.id = er.student_id
    WHERE u.program = ?
    ORDER BY er.submitted_at DESC
');
$reqStmt->execute([$program]);
$requests = $reqStmt->fetchAll();

$subStmt = $pdo->prepare('
    SELECT rs.*, s.subject_id, s.sub_code, s.description, s.units, s.schedule, s.section,
           s.instructor, s.sched_code, s.is_exclusive, s.year_level, s.semester
    FROM request_subjects rs
    JOIN subjects s ON s.subject_id = rs.subject_id
    WHERE rs.request_id = ?
    ORDER BY s.year_level, s.semester, s.sub_code, s.subject_id
');

$prereqStmt = $pdo->prepare('
    SELECT p.subject_id, p.sub_code, p.description
    FROM subject_prerequisites sp
    JOIN subjects p ON p.subject_id = sp.prereq_subject_id
    WHERE sp.subject_id = ?
    ORDER BY p.sub_code, p.subject_id
');

$gradeStmt = $pdo->prepare('
    SELECT grade, school_year_taken, passed
    FROM academic_records
    WHERE student_id = ? AND sub_code = ?
    ORDER BY id DESC
    LIMIT 1
');

function prerequisite_status(string $grade, bool $passed): string {
    $grade = strtoupper(trim($grade));
    if ($passed) return 'qualified';
    if ($grade === 'INC') return 'incomplete';
    if ($grade === 'OD') return 'dropped';
    return 'failed';
}

$out = [];
foreach ($requests as $r) {
    $subStmt->execute([$r['id']]);
    $selections = [];

    foreach ($subStmt->fetchAll() as $s) {
        $prereqStmt->execute([(int)$s['subject_id']]);
        $prereqRows = $prereqStmt->fetchAll();
        $prerequisiteChecks = [];

        foreach ($prereqRows as $p) {
            $gradeStmt->execute([$r['student_id'], $p['sub_code']]);
            $grade = $gradeStmt->fetch();

            if (!$grade) {
                $prerequisiteChecks[] = [
                    'subjectId' => (int)$p['subject_id'],
                    'subCode' => $p['sub_code'],
                    'description' => $p['description'],
                    'grade' => null,
                    'schoolYearTaken' => null,
                    'status' => 'missing',
                ];
            } else {
                $gradeValue = strtoupper(trim((string)$grade['grade']));
                $passed = (bool)$grade['passed'];
                $prerequisiteChecks[] = [
                    'subjectId' => (int)$p['subject_id'],
                    'subCode' => $p['sub_code'],
                    'description' => $p['description'],
                    'grade' => $gradeValue,
                    'schoolYearTaken' => $grade['school_year_taken'],
                    'status' => prerequisite_status($gradeValue, $passed),
                ];
            }
        }

        $selections[] = [
            'subject' => [
                'subjectId' => (int)$s['subject_id'],
                'subCode' => $s['sub_code'],
                'schedCode' => $s['sched_code'],
                'description' => $s['description'],
                'units' => (float)$s['units'],
                'schedule' => $s['schedule'],
                'section' => $s['section'],
                'instructor' => $s['instructor'],
                'prerequisites' => array_column($prereqRows, 'sub_code'),
                'exclusive' => (bool)$s['is_exclusive'],
                'yearLevel' => (int)$s['year_level'],
                'semester' => (int)$s['semester'],
            ],
            'localCheck' => $s['local_check'],
            'status' => $s['status'],
            'chairRemarks' => $s['chair_remarks'],
            'overrideApplied' => (bool)$s['override_applied'],
            'prerequisiteChecks' => $prerequisiteChecks,
        ];
    }

    $out[] = [
        'id' => $r['id'],
        'studentId' => $r['student_id'],
        'studentFirstName' => $r['student_first_name'],
        'studentLastName' => $r['student_last_name'],
        'studentProgram' => $r['student_program'],
        'studentYearLevel' => $r['student_year_level'] !== null ? (int)$r['student_year_level'] : null,
        'studentYearSection' => $r['student_year_section'],
        'schoolYear' => $r['school_year'],
        'term' => $r['term'],
        'termCode' => $r['term_code'] ?? null,
        'selectedSection' => $r['selected_section'] ?? null,
        'type' => $r['type'],
        'submittedAt' => $r['submitted_at'],
        'selections' => $selections,
    ];
}
respond($out);
