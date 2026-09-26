<?php
// api/curriculum.php -> GET /api/curriculum.php?program=BSIT

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') error_response('Method not allowed', 405);

$session = current_session();
if (!$session) error_response('Unauthorized', 401);

$programCode = trim($_GET['program'] ?? '');
$section = trim($_GET['section'] ?? '');
$semesterFilter = isset($_GET['semester']) ? (int)$_GET['semester'] : 0;
$yearLevelFilter = isset($_GET['yearLevel']) ? (int)$_GET['yearLevel'] : 0;
if ($programCode === '') error_response('Missing program parameter.');
if ($semesterFilter !== 0 && !in_array($semesterFilter, [1,2,3], true)) error_response('Invalid semester.');
if ($yearLevelFilter !== 0 && ($yearLevelFilter < 1 || $yearLevelFilter > 5)) error_response('Invalid year level.');

$pdo = db();
$curStmt = $pdo->prepare('SELECT * FROM curricula WHERE program_code = ? LIMIT 1');
$curStmt->execute([$programCode]);
$curriculum = $curStmt->fetch();
if (!$curriculum) error_response('Curriculum not found.', 404);

$sql = 'SELECT * FROM subjects WHERE program_code = ?';
$params = [$programCode];
if ($semesterFilter !== 0) { $sql .= ' AND semester = ?'; $params[] = $semesterFilter; }
if ($yearLevelFilter !== 0) { $sql .= ' AND year_level = ?'; $params[] = $yearLevelFilter; }
if ($section !== '') { $sql .= ' AND section = ?'; $params[] = $section; }
$sql .= ' ORDER BY year_level ASC, semester ASC, sub_code ASC, subject_id ASC';
$subStmt = $pdo->prepare($sql);
$subStmt->execute($params);
$subjects = $subStmt->fetchAll();

$prereqStmt = $pdo->prepare('
    SELECT p.sub_code
    FROM subject_prerequisites sp
    JOIN subjects p ON p.subject_id = sp.prereq_subject_id
    WHERE sp.subject_id = ?
    ORDER BY p.sub_code, p.subject_id
');

$subjectsOut = array_map(function ($s) use ($prereqStmt) {
    $prereqStmt->execute([(int)$s['subject_id']]);
    $prereqs = array_column($prereqStmt->fetchAll(), 'sub_code');
    return [
        'subjectId' => (int)$s['subject_id'],
        'programCode' => $s['program_code'],
        'subCode' => $s['sub_code'],
        'schedCode' => $s['sched_code'],
        'description' => $s['description'],
        'units' => (float)$s['units'],
        'schedule' => $s['schedule'],
        'section' => $s['section'],
        'instructor' => $s['instructor'],
        'prerequisites' => $prereqs,
        'exclusive' => (bool)$s['is_exclusive'],
        'yearLevel' => (int)$s['year_level'],
        'semester' => (int)$s['semester'],
    ];
}, $subjects);

respond([
    'programCode' => $curriculum['program_code'],
    'programName' => $curriculum['program_name'],
    'department' => $curriculum['department'],
    'effectiveSchoolYear' => $curriculum['effective_school_year'],
    'subjects' => $subjectsOut,
]);
