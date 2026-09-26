<?php
// api/students_list.php -> GET /api/students_list.php[?program=BSIT&search=22-000001]
//
// Chairperson: always scoped to THEIR OWN program (taken from their signed
// session token). Admin: can pass ?program=XXX. Optional ?search=... searches
// by School ID (and, for convenience, exact/partial student name).

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    error_response('Method not allowed', 405);
}

$session = current_session();
if (!$session) error_response('Unauthorized', 401);

if ($session['role'] === 'chairperson') {
    $program = trim((string)($session['program'] ?? ''));
    if ($program === '') {
        error_response('This chairperson account has no program assigned. Ask the System Administrator to set one.', 403);
    }
} elseif ($session['role'] === 'admin') {
    $program = trim((string)($_GET['program'] ?? ''));
    if ($program === '') error_response('Missing program parameter.');
} else {
    error_response('Unauthorized', 401);
}

$search = trim((string)($_GET['search'] ?? ''));

$sql = '
    SELECT id, first_name, last_name, email, program, year_level, year_section, enrollment_type
    FROM users
    WHERE role = "student" AND program = ? AND year_level BETWEEN 1 AND 4
';
$params = [$program];

if ($search !== '') {
    $sql .= ' AND (id LIKE ? OR CONCAT(first_name, " ", last_name) LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}

$sql .= ' ORDER BY year_level ASC, last_name ASC, first_name ASC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

respond(array_map(function ($s) {
    return [
        'studentId' => $s['id'],
        'firstName' => $s['first_name'],
        'lastName' => $s['last_name'],
        'email' => $s['email'],
        'program' => $s['program'],
        'yearLevel' => (int) $s['year_level'],
        'yearSection' => $s['year_section'],
        'enrollmentType' => $s['enrollment_type'],
    ];
}, $students));
