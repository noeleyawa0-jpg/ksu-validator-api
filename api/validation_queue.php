<?php
// api/validation_queue.php  ->  GET /api/validation_queue.php?department=CEIT

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    error_response('Method not allowed', 405);
}

$session = current_session();
if (!$session || $session['role'] !== 'chairperson') error_response('Unauthorized', 401);

$department = $_GET['department'] ?? '';
if ($department === '') error_response('Missing department parameter.');

$reqStmt = db()->prepare('
    SELECT er.* FROM enrollment_requests er
    JOIN users u ON u.id = er.student_id
    WHERE u.department = ?
    ORDER BY er.submitted_at DESC
');
$reqStmt->execute([$department]);
$requests = $reqStmt->fetchAll();

$subStmt = db()->prepare('
    SELECT rs.*, s.description, s.units, s.schedule, s.section, s.instructor,
           s.sched_code, s.is_exclusive, s.year_level, s.semester
    FROM request_subjects rs
    JOIN subjects s ON s.sub_code = rs.sub_code
    WHERE rs.request_id = ?
');

$out = [];
foreach ($requests as $r) {
    $subStmt->execute([$r['id']]);
    $selections = array_map(function ($s) {
        return [
            'subject' => [
                'subCode' => $s['sub_code'],
                'schedCode' => $s['sched_code'],
                'description' => $s['description'],
                'units' => (float) $s['units'],
                'schedule' => $s['schedule'],
                'section' => $s['section'],
                'instructor' => $s['instructor'],
                'prerequisites' => [],
                'exclusive' => (bool) $s['is_exclusive'],
                'yearLevel' => (int) $s['year_level'],
                'semester' => (int) $s['semester'],
            ],
            'localCheck' => $s['local_check'],
            'status' => $s['status'],
            'chairRemarks' => $s['chair_remarks'],
            'overrideApplied' => (bool) $s['override_applied'],
        ];
    }, $subStmt->fetchAll());

    $out[] = [
        'id' => $r['id'],
        'studentId' => $r['student_id'],
        'schoolYear' => $r['school_year'],
        'term' => $r['term'],
        'type' => $r['type'],
        'submittedAt' => $r['submitted_at'],
        'selections' => $selections,
    ];
}

respond($out);
