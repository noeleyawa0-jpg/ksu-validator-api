<?php
// api/admin_subjects.php
// POST: create a new subject, or update an existing subject when subjectId is supplied.
// DELETE: ?subjectId=123&program=BSIT
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';

$session = current_session();
if (!$session || $session['role'] !== 'admin') error_response('Unauthorized', 401);
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_body();
    $required = ['subCode','programCode','schedCode','description','units','schedule','section','instructor','yearLevel','semester'];
    foreach ($required as $field) if (!isset($body[$field]) || $body[$field] === '') error_response("Missing field: $field");

    $programCode = trim($body['programCode']);
    $subjectId = isset($body['subjectId']) && $body['subjectId'] !== null ? (int)$body['subjectId'] : null;

    $pdo->beginTransaction();
    try {
        if ($subjectId !== null && $subjectId > 0) {
            $stmt = $pdo->prepare('UPDATE subjects SET sub_code=?, program_code=?, sched_code=?, description=?, units=?, schedule=?, section=?, instructor=?, is_exclusive=?, year_level=?, semester=? WHERE subject_id=? AND program_code=?');
            $stmt->execute([
                trim($body['subCode']), $programCode, trim($body['schedCode']), trim($body['description']), $body['units'],
                trim($body['schedule']), trim($body['section']), trim($body['instructor']), !empty($body['exclusive']) ? 1 : 0,
                (int)$body['yearLevel'], (int)$body['semester'], $subjectId, $programCode
            ]);
            if ($stmt->rowCount() === 0) {
                $check = $pdo->prepare('SELECT subject_id FROM subjects WHERE subject_id=? AND program_code=?');
                $check->execute([$subjectId, $programCode]);
                if (!$check->fetch()) error_response('Subject not found in this program.', 404);
            }
        } else {
            $stmt = $pdo->prepare('INSERT INTO subjects (sub_code, program_code, sched_code, description, units, schedule, section, instructor, is_exclusive, year_level, semester) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                trim($body['subCode']), $programCode, trim($body['schedCode']), trim($body['description']), $body['units'],
                trim($body['schedule']), trim($body['section']), trim($body['instructor']), !empty($body['exclusive']) ? 1 : 0,
                (int)$body['yearLevel'], (int)$body['semester']
            ]);
            $subjectId = (int)$pdo->lastInsertId();
        }

        $pdo->prepare('DELETE FROM subject_prerequisites WHERE subject_id=?')->execute([$subjectId]);
        $prereqStmt = $pdo->prepare('SELECT subject_id FROM subjects WHERE program_code=? AND sub_code=? ORDER BY year_level, semester, subject_id LIMIT 2');
        $insertPrereq = $pdo->prepare('INSERT INTO subject_prerequisites (subject_id, prereq_subject_id, sub_code, prereq_sub_code) VALUES (?, ?, ?, ?)');
        foreach (($body['prerequisites'] ?? []) as $p) {
            $code = trim((string)$p);
            if ($code === '') continue;
            $prereqStmt->execute([$programCode, $code]);
            $matches = $prereqStmt->fetchAll();
            if (count($matches) !== 1) {
                error_response("Prerequisite '$code' is ambiguous or not found in $programCode. Use the curriculum import for duplicate-code prerequisites.");
            }
            $insertPrereq->execute([$subjectId, (int)$matches[0]['subject_id'], trim($body['subCode']), $code]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_response('Failed to save subject: ' . $e->getMessage(), 500);
    }
    respond(['status'=>'ok','subjectId'=>$subjectId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $subjectId = (int)($_GET['subjectId'] ?? 0);
    $program = trim($_GET['program'] ?? '');
    if ($subjectId <= 0 || $program === '') error_response('subjectId and program are required.');
    $stmt = $pdo->prepare('DELETE FROM subjects WHERE subject_id=? AND program_code=?');
    $stmt->execute([$subjectId, $program]);
    if ($stmt->rowCount() === 0) error_response('Subject not found in this program.', 404);
    respond(['status'=>'ok']);
}
error_response('Method not allowed', 405);
