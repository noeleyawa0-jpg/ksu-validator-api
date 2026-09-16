<?php
// api/student_enrollment_status.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') error_response('Method not allowed', 405);
$session = current_session();
if (!$session || ($session['role'] ?? '') !== 'student') error_response('Unauthorized', 401);

$studentId = $session['sub'] ?? '';
$schoolYear = trim($_GET['schoolYear'] ?? '');
$term = trim($_GET['term'] ?? '');
if ($studentId === '' || $schoolYear === '' || $term === '') error_response('schoolYear and term are required.');

$pdo = db();
$reqStmt = $pdo->prepare('
    SELECT id, student_id, school_year, term, type, submitted_at
    FROM enrollment_requests
    WHERE student_id = ? AND school_year = ? AND term = ?
    ORDER BY submitted_at ASC LIMIT 1
');
$reqStmt->execute([$studentId, $schoolYear, $term]);
$request = $reqStmt->fetch();
if (!$request) respond(null);

$subStmt = $pdo->prepare('
    SELECT rs.*, s.subject_id, s.description, s.units, s.schedule, s.section,
           s.instructor, s.sched_code, s.is_exclusive, s.year_level, s.semester
    FROM request_subjects rs
    JOIN subjects s ON s.subject_id = rs.subject_id
    WHERE rs.request_id = ?
    ORDER BY s.year_level, s.semester, s.sub_code, s.subject_id
');
$subStmt->execute([$request['id']]);

$prereqStmt = $pdo->prepare('
    SELECT p.sub_code FROM subject_prerequisites sp
    JOIN subjects p ON p.subject_id = sp.prereq_subject_id
    WHERE sp.subject_id = ? ORDER BY p.sub_code, p.subject_id
');

$selections = array_map(function ($s) use ($prereqStmt) {
    $prereqStmt->execute([(int)$s['subject_id']]);
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
            'prerequisites' => array_column($prereqStmt->fetchAll(), 'sub_code'),
            'exclusive' => (bool)$s['is_exclusive'],
            'yearLevel' => (int)$s['year_level'],
            'semester' => (int)$s['semester'],
        ],
        'localCheck' => $s['local_check'],
        'status' => $s['status'],
        'chairRemarks' => $s['chair_remarks'],
        'overrideApplied' => (bool)$s['override_applied'],
    ];
}, $subStmt->fetchAll());

respond([
    'id' => $request['id'],
    'studentId' => $request['student_id'],
    'schoolYear' => $request['school_year'],
    'term' => $request['term'],
    'type' => $request['type'],
    'submittedAt' => $request['submitted_at'],
    'selections' => $selections,
]);
