<?php
// api/students_list.php  ->  GET /api/students_list.php[?program=BSIT]
//
// Chairperson: always scoped to THEIR OWN program (taken from their signed
// session token — the `program` query param, if sent, is ignored for
// chairs so they can never probe another program's roster).
// Admin: can pass ?program=XXX to view any program's roster.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    error_response('Method not allowed', 405);
}

$session = current_session();
if (!$session) error_response('Unauthorized', 401);

if ($session['role'] === 'chairperson') {
    $program = $session['program'] ?? '';
    if ($program === '') {
        error_response('This chairperson account has no program assigned. Ask the System Administrator to set one.', 403);
    }
} elseif ($session['role'] === 'admin') {
    $program = $_GET['program'] ?? '';
    if ($program === '') error_response('Missing program parameter.');
} else {
    error_response('Unauthorized', 401);
}

$stmt = db()->prepare("
    SELECT id, first_name, last_name, program, year_level, year_section, enrollment_type
    FROM users
    WHERE role = 'student' AND program = ? AND year_level BETWEEN 1 AND 4
    ORDER BY year_level ASC, last_name ASC
");
$stmt->execute([$program]);
$students = $stmt->fetchAll();

respond(array_map(function ($s) {
    return [
        'studentId' => $s['id'],
        'firstName' => $s['first_name'],
        'lastName' => $s['last_name'],
        'program' => $s['program'],
        'yearLevel' => (int) $s['year_level'],
        'yearSection' => $s['year_section'],
        'enrollmentType' => $s['enrollment_type'],
    ];
}, $students));
