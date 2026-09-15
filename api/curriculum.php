<?php
// api/curriculum.php  ->  GET /api/curriculum.php?program=BSCpE

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    error_response('Method not allowed', 405);
}

$session = current_session();
if (!$session) error_response('Unauthorized', 401);

$programCode = $_GET['program'] ?? '';
if ($programCode === '') error_response('Missing program parameter.');

$curStmt = db()->prepare('SELECT * FROM curricula WHERE program_code = ? LIMIT 1');
$curStmt->execute([$programCode]);
$curriculum = $curStmt->fetch();
if (!$curriculum) error_response('Curriculum not found.', 404);

$subStmt = db()->prepare('SELECT * FROM subjects WHERE program_code = ?');
$subStmt->execute([$programCode]);
$subjects = $subStmt->fetchAll();

$prereqStmt = db()->prepare('SELECT prereq_sub_code FROM subject_prerequisites WHERE sub_code = ?');

$subjectsOut = array_map(function ($s) use ($prereqStmt) {
    $prereqStmt->execute([$s['sub_code']]);
    $prereqs = array_column($prereqStmt->fetchAll(), 'prereq_sub_code');
    return [
        'subCode' => $s['sub_code'],
        'schedCode' => $s['sched_code'],
        'description' => $s['description'],
        'units' => (float) $s['units'],
        'schedule' => $s['schedule'],
        'section' => $s['section'],
        'instructor' => $s['instructor'],
        'prerequisites' => $prereqs,
        'exclusive' => (bool) $s['is_exclusive'],
        'yearLevel' => (int) $s['year_level'],
        'semester' => (int) $s['semester'],
    ];
}, $subjects);

respond([
    'programCode' => $curriculum['program_code'],
    'programName' => $curriculum['program_name'],
    'department' => $curriculum['department'],
    'effectiveSchoolYear' => $curriculum['effective_school_year'],
    'subjects' => $subjectsOut,
]);
