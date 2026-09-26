<?php
// GET /api/sections.php?program=BSIT&yearLevel=2&semester=1&termCode=26-1
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') error_response('Method not allowed', 405);
$session = current_session();
if (!$session) error_response('Unauthorized', 401);

$program = trim((string)($_GET['program'] ?? ''));
$yearLevel = (int)($_GET['yearLevel'] ?? 0);
$semester = (int)($_GET['semester'] ?? 0);
$termCode = trim((string)($_GET['termCode'] ?? ''));
if ($program === '' || $yearLevel < 1 || $yearLevel > 4 || !in_array($semester, [1,2,3], true) || $termCode === '') {
    error_response('program, yearLevel, semester, and termCode are required.');
}

if (($session['role'] ?? '') === 'chairperson' && ($session['program'] ?? '') !== $program) error_response('Program access denied.', 403);
if (($session['role'] ?? '') === 'student') {
    $studentStmt = db()->prepare('SELECT program, year_level FROM users WHERE id = ? AND role = "student" LIMIT 1');
    $studentStmt->execute([$session['sub'] ?? '']);
    $student = $studentStmt->fetch();
    if (!$student || $student['program'] !== $program || (int)$student['year_level'] !== $yearLevel) error_response('Student section access denied.', 403);
}

$pdo = db();
$capacityStmt = $pdo->prepare('SELECT capacity FROM section_capacities WHERE term_code=? AND program_code=? AND year_level=? AND section=? LIMIT 1');
$countStmt = $pdo->prepare('SELECT COUNT(DISTINCT er.id) AS enrolled FROM enrollment_requests er JOIN users u ON u.id=er.student_id WHERE er.school_year=? AND er.term_code=? AND er.selected_section=? AND u.program=? AND u.year_level=? AND EXISTS (SELECT 1 FROM request_subjects rs WHERE rs.request_id=er.id AND rs.status <> "rejected")');
$subjectStmt = $pdo->prepare('SELECT DISTINCT section FROM subjects WHERE program_code=? AND year_level=? AND semester=? AND TRIM(section)<>"" ORDER BY section');
$subjectStmt->execute([$program, $yearLevel, $semester]);

$termStmt = $pdo->prepare('SELECT school_year FROM academic_terms WHERE term_code=? LIMIT 1');
$termStmt->execute([$termCode]);
$term = $termStmt->fetch();
if (!$term) error_response('Academic term not found.', 404);

$out=[];
foreach ($subjectStmt->fetchAll() as $row) {
    $section=trim($row['section']);
    $capacityStmt->execute([$termCode,$program,$yearLevel,$section]);
    $capacity=(int)(($capacityStmt->fetch()['capacity'] ?? 40));
    $countStmt->execute([$term['school_year'],$termCode,$section,$program,$yearLevel]);
    $enrolled=(int)($countStmt->fetch()['enrolled'] ?? 0);
    $out[]=['section'=>$section,'capacity'=>$capacity,'enrolled'=>$enrolled,'available'=>max(0,$capacity-$enrolled),'full'=>$enrolled >= $capacity];
}
respond($out);
