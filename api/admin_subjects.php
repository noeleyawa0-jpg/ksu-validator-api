<?php
// api/admin_subjects.php
// POST   /api/admin_subjects.php -> create/update exact curriculum entry
// DELETE /api/admin_subjects.php?subjectId=123&program=BSIT -> delete exact entry

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

    $subjectId = isset($body['subjectId']) && $body['subjectId'] !== ''
        ? (int)$body['subjectId'] : null;
    $programCode = trim($body['programCode']);
    $subCode = trim($body['subCode']);
    $yearLevel = (int)$body['yearLevel'];
    $semester = (int)$body['semester'];

    $pdo->beginTransaction();
    try {
        if ($subjectId !== null) {
            // Update only the exact row. Never use sub_code alone as identity.
            $check = $pdo->prepare('SELECT subject_id FROM subjects WHERE subject_id = ? AND program_code = ? LIMIT 1');
            $check->execute([$subjectId, $programCode]);
            if (!$check->fetch()) {
                error_response('Subject entry not found for this program.', 404);
            }

            $stmt = $pdo->prepare('
                UPDATE subjects SET
                    sub_code = ?, sched_code = ?, description = ?, units = ?,
                    schedule = ?, section = ?, instructor = ?, is_exclusive = ?,
                    year_level = ?, semester = ?
                WHERE subject_id = ? AND program_code = ?
            ');
            $stmt->execute([
                $subCode, $body['schedCode'], $body['description'], $body['units'],
                $body['schedule'], $body['section'], $body['instructor'],
                !empty($body['exclusive']) ? 1 : 0, $yearLevel, $semester,
                $subjectId, $programCode,
            ]);
        } else {
            // New rows are uniquely identified by their generated subject_id.
            // The API does not overwrite another program's subject.
            $stmt = $pdo->prepare('
                INSERT INTO subjects
                    (sub_code, program_code, sched_code, description, units,
                     schedule, section, instructor, is_exclusive, year_level, semester)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $subCode, $programCode, $body['schedCode'], $body['description'], $body['units'],
                $body['schedule'], $body['section'], $body['instructor'],
                !empty($body['exclusive']) ? 1 : 0, $yearLevel, $semester,
            ]);
            $subjectId = (int)$pdo->lastInsertId();
        }

        // Prerequisites are supplied by the UI as displayed sub-codes.
        // Resolve them within this program. When a code is duplicated in the
        // same program, an optional prerequisiteSubjectIds array is preferred.
        $pdo->prepare('DELETE FROM subject_prerequisites WHERE subject_id = ?')->execute([$subjectId]);

        $prereqIds = $body['prerequisiteSubjectIds'] ?? [];
        if (is_array($prereqIds) && count($prereqIds) > 0) {
            $pstmt = $pdo->prepare('
                INSERT INTO subject_prerequisites (subject_id, prereq_subject_id, sub_code, prereq_sub_code)
                SELECT ?, p.subject_id, ?, p.sub_code
                FROM subjects p
                WHERE p.subject_id = ? AND p.program_code = ?
            ');
            foreach ($prereqIds as $pid) {
                $pid = (int)$pid;
                if ($pid > 0) {
                    $pstmt->execute([$subjectId, $subCode, $pid, $programCode]);
                }
            }
        } else {
            $prereqStmt = $pdo->prepare('
                INSERT INTO subject_prerequisites (subject_id, prereq_subject_id, sub_code, prereq_sub_code)
                SELECT ?, p.subject_id, ?, p.sub_code
                FROM subjects p
                WHERE p.program_code = ? AND p.sub_code = ?
                LIMIT 1
            ');
            foreach (($body['prerequisites'] ?? []) as $p) {
                $p = trim((string)$p);
                if ($p !== '') {
                    $prereqStmt->execute([$subjectId, $subCode, $programCode, $p]);
                }
            }
        }

        $pdo->commit();
        respond(['status' => 'ok', 'subjectId' => $subjectId]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_response('Failed to save subject: ' . $e->getMessage(), 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $subjectId = isset($_GET['subjectId']) ? (int)$_GET['subjectId'] : 0;
    $program = trim($_GET['program'] ?? '');
    if ($subjectId <= 0 || $program === '') error_response('subjectId and program are required.');

    $stmt = $pdo->prepare('DELETE FROM subjects WHERE subject_id = ? AND program_code = ?');
    $stmt->execute([$subjectId, $program]);
    if ($stmt->rowCount() === 0) error_response('Subject entry not found.', 404);
    respond(['status' => 'ok']);
}

error_response('Method not allowed', 405);
