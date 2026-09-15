<?php
// api/admin_subjects.php
//   POST   /api/admin_subjects.php   -> create or update a subject
//   DELETE /api/admin_subjects.php?subCode=CPE+301&program=BSCpE -> delete

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';

$session = current_session();
if (!$session || $session['role'] !== 'admin') error_response('Unauthorized', 401);

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_body();
    $required = ['subCode', 'programCode', 'schedCode', 'description', 'units',
                 'schedule', 'section', 'instructor', 'yearLevel', 'semester'];
    foreach ($required as $field) {
        if (!isset($body[$field]) || $body[$field] === '') {
            error_response("Missing field: $field");
        }
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('
            INSERT INTO subjects (sub_code, program_code, sched_code, description, units,
                                   schedule, section, instructor, is_exclusive, year_level, semester)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                sched_code = VALUES(sched_code), description = VALUES(description),
                units = VALUES(units), schedule = VALUES(schedule), section = VALUES(section),
                instructor = VALUES(instructor), is_exclusive = VALUES(is_exclusive),
                year_level = VALUES(year_level), semester = VALUES(semester)
        ');
        $stmt->execute([
            $body['subCode'], $body['programCode'], $body['schedCode'], $body['description'],
            $body['units'], $body['schedule'], $body['section'], $body['instructor'],
            !empty($body['exclusive']) ? 1 : 0, $body['yearLevel'], $body['semester'],
        ]);

        $pdo->prepare('DELETE FROM subject_prerequisites WHERE sub_code = ?')->execute([$body['subCode']]);
        $prereqStmt = $pdo->prepare('INSERT INTO subject_prerequisites (sub_code, prereq_sub_code) VALUES (?, ?)');
        foreach (($body['prerequisites'] ?? []) as $p) {
            if (trim($p) !== '') $prereqStmt->execute([$body['subCode'], trim($p)]);
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_response('Failed to save subject: ' . $e->getMessage(), 500);
    }

    respond(['status' => 'ok']);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $subCode = $_GET['subCode'] ?? '';
    if ($subCode === '') error_response('Missing subCode parameter.');
    $pdo->prepare('DELETE FROM subjects WHERE sub_code = ?')->execute([$subCode]);
    respond(['status' => 'ok']);
}

error_response('Method not allowed', 405);
