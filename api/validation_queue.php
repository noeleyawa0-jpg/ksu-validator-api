<?php
// api/validation_queue.php -> GET /api/validation_queue.php[?program=BSIT]

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') error_response('Method not allowed', 405);
$session = current_session();
if (!$session) error_response('Unauthorized', 401);

if ($session['role'] === 'chairperson') {
    $program = $session['program'] ?? '';
    if ($program === '') error_response('This chairperson account has no program assigned.', 403);
} elseif ($session['role'] === 'admin') {
    $program = trim($_GET['program'] ?? '');
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
    SELECT rs.*, s.subject_id, s.description, s.units, s.schedule, s.section,
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
    WHERE sp.subject_id = ? ORDER BY p.sub_code, p.subject_id
');

$historyStmt = $pdo->prepare('SELECT sub_code, school_year_taken, grade, passed FROM academic_records WHERE student_id = ? ORDER BY school_year_taken DESC, id DESC');

$out = [];
foreach ($requests as $r) {
    $subStmt->execute([$r['id']]);
    $historyStmt->execute([$r['student_id']]);
    $historyByCode = [];
    foreach ($historyStmt->fetchAll() as $h) {
        // Keep the newest record for a prerequisite code.
        if (!isset($historyByCode[$h['sub_code']])) {
            $historyByCode[$h['sub_code']] = $h;
        }
    }

    $selections = array_map(function ($s) use ($prereqStmt, $historyByCode) {
        $prereqStmt->execute([(int)$s['subject_id']]);
        $prereqRows = $prereqStmt->fetchAll();
        $prerequisiteChecks = array_map(function ($p) use ($historyByCode) {
            $record = $historyByCode[$p['sub_code']] ?? null;
            if (!$record) {
                $status = 'missing';
            } elseif ((bool)$record['passed']) {
                $status = 'qualified';
            } elseif (in_array(strtoupper(trim((string)$record['grade'])), ['INC', 'INCOMPLETE'], true)) {
                $status = 'incomplete';
            } elseif (in_array(strtoupper(trim((string)$record['grade'])), ['OD', 'DRP', 'DROP', 'DROPPED'], true)) {
                $status = 'dropped';
            } else {
                $status = 'failed';
            }
            return [
                'subjectId' => (int)$p['subject_id'],
                'subCode' => $p['sub_code'],
                'description' => $p['description'],
                'grade' => $record['grade'] ?? null,
                'schoolYearTaken' => $record['school_year_taken'] ?? null,
                'status' => $status,
            ];
        }, $prereqRows);

        return [
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
            'prerequisiteChecks' => $prerequisiteChecks,
            'status' => $s['status'],
            'chairRemarks' => $s['chair_remarks'],
            'overrideApplied' => (bool)$s['override_applied'],
        ];
    }, $subStmt->fetchAll());

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
        'type' => $r['type'],
        'submittedAt' => $r['submitted_at'],
        'selections' => $selections,
    ];
}
respond($out);
