<?php
// GET /api/student_movements.php — movements associated with the logged-in chair's program.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') error_response('Method not allowed', 405);
$session = current_session();
if (!$session || ($session['role'] ?? '') !== 'chairperson') error_response('Unauthorized', 401);
$program = trim((string)($session['program'] ?? ''));
if ($program === '') error_response('Chairperson account has no assigned program.', 403);
$stmt = db()->prepare('
    SELECT m.id, m.student_id, u.first_name, u.last_name, m.movement_type,
           m.from_program, m.to_program, m.from_year_level, m.to_year_level,
           m.from_section, m.to_section, m.notes, m.performed_by, m.moved_at
    FROM student_movements m
    LEFT JOIN users u ON u.id=m.student_id
    WHERE m.from_program=? OR m.to_program=?
    ORDER BY m.moved_at DESC, m.id DESC LIMIT 200');
$stmt->execute([$program, $program]);
respond(array_map(static function ($r) {
    return [
        'id' => (int)$r['id'], 'studentId' => $r['student_id'],
        'studentName' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
        'movementType' => $r['movement_type'], 'fromProgram' => $r['from_program'], 'toProgram' => $r['to_program'],
        'fromYearLevel' => (int)$r['from_year_level'], 'toYearLevel' => (int)$r['to_year_level'],
        'fromSection' => $r['from_section'], 'toSection' => $r['to_section'],
        'notes' => $r['notes'], 'performedBy' => $r['performed_by'], 'movedAt' => $r['moved_at'],
    ];
}, $stmt->fetchAll()));
