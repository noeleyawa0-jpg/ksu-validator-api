<?php
// api/admin_subjects.php
// Manages curriculum subjects and their class offerings.
// A curriculum subject is the academic identity; each subjects row is an offering
// (section/schedule/instructor) of that identity.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';

$session = current_session();
if (!$session || ($session['role'] ?? '') !== 'admin') error_response('Unauthorized', 401);

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_body();
    $required = ['subCode', 'programCode', 'schedCode', 'description', 'units',
                 'schedule', 'section', 'instructor', 'yearLevel', 'semester'];
    foreach ($required as $field) {
        if (!isset($body[$field]) || $body[$field] === '') error_response("Missing field: $field");
    }

    $subjectId = isset($body['subjectId']) && $body['subjectId'] !== '' ? (int)$body['subjectId'] : null;
    $curriculumSubjectId = isset($body['curriculumSubjectId']) && $body['curriculumSubjectId'] !== ''
        ? (int)$body['curriculumSubjectId'] : null;
    $programCode = trim((string)$body['programCode']);
    $subCode = trim((string)$body['subCode']);
    $yearLevel = (int)$body['yearLevel'];
    $semester = (int)$body['semester'];

    if ($yearLevel < 1 || $yearLevel > 5 || !in_array($semester, [1, 2, 3], true)) {
        error_response('Invalid year level or semester.');
    }

    $termStmt = $pdo->query('SELECT term_code FROM academic_terms WHERE is_current=1 ORDER BY term_code DESC LIMIT 1');
    $currentTerm = $termStmt->fetchColumn();
    if (!$currentTerm) error_response('No current academic term is configured.', 409);

    $termCode = trim((string)($body['termCode'] ?? $currentTerm));
    if ($termCode !== $currentTerm) error_response('Subjects can only be created or edited for the current academic term.', 409);

    $pdo->beginTransaction();
    try {
        if ($subjectId !== null) {
            $check = $pdo->prepare('SELECT * FROM subjects WHERE subject_id = ? AND program_code = ? LIMIT 1');
            $check->execute([$subjectId, $programCode]);
            $existing = $check->fetch();
            if (!$existing) error_response('Subject offering not found for this program.', 404);

            $curriculumSubjectId = $curriculumSubjectId ?: (int)($existing['curriculum_subject_id'] ?? 0);
            if ($curriculumSubjectId <= 0) {
                $createCurr = $pdo->prepare('INSERT INTO curriculum_subjects
                    (program_code, sub_code, description, units, year_level, semester, legacy_subject_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?)');
                $createCurr->execute([$programCode, $subCode, $body['description'], $body['units'], $yearLevel, $semester, $subjectId]);
                $curriculumSubjectId = (int)$pdo->lastInsertId();
            }

            // Only the curriculum-owning Section-A offering updates the shared
            // curriculum identity. Section-B (and later sections) remain
            // independently editable offerings.
            if (preg_match('/A$/i', trim((string)$body['section']))) {
                $updateCurr = $pdo->prepare('UPDATE curriculum_subjects SET
                    program_code=?, sub_code=?, description=?, units=?, year_level=?, semester=?
                    WHERE curriculum_subject_id=?');
                $updateCurr->execute([$programCode, $subCode, $body['description'], $body['units'], $yearLevel, $semester, $curriculumSubjectId]);
            }

            $stmt = $pdo->prepare('UPDATE subjects SET
                curriculum_subject_id=?, term_code=?, sub_code=?, sched_code=?, description=?, units=?,
                schedule=?, section=?, instructor=?, is_exclusive=?, year_level=?, semester=?
                WHERE subject_id=? AND program_code=?');
            $stmt->execute([
                $curriculumSubjectId, $termCode, $subCode, $body['schedCode'], $body['description'], $body['units'],
                $body['schedule'], $body['section'], $body['instructor'], !empty($body['exclusive']) ? 1 : 0,
                $yearLevel, $semester, $subjectId, $programCode,
            ]);
        } else {
            // If this is another section of an existing curriculum subject,
            // the caller supplies curriculumSubjectId. Otherwise create a new
            // curriculum identity and its first offering.
            if ($curriculumSubjectId !== null) {
                $checkCurr = $pdo->prepare('SELECT curriculum_subject_id FROM curriculum_subjects WHERE curriculum_subject_id=? AND program_code=? LIMIT 1');
                $checkCurr->execute([$curriculumSubjectId, $programCode]);
                if (!$checkCurr->fetch()) error_response('Curriculum subject not found for this program.', 404);
            } else {
                $createCurr = $pdo->prepare('INSERT INTO curriculum_subjects
                    (program_code, sub_code, description, units, year_level, semester)
                    VALUES (?, ?, ?, ?, ?, ?)');
                $createCurr->execute([$programCode, $subCode, $body['description'], $body['units'], $yearLevel, $semester]);
                $curriculumSubjectId = (int)$pdo->lastInsertId();
            }

            $stmt = $pdo->prepare('INSERT INTO subjects
                (curriculum_subject_id, term_code, sub_code, program_code, sched_code, description, units,
                 schedule, section, instructor, is_exclusive, year_level, semester)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $curriculumSubjectId, $termCode, $subCode, $programCode, $body['schedCode'], $body['description'], $body['units'],
                $body['schedule'], $body['section'], $body['instructor'], !empty($body['exclusive']) ? 1 : 0,
                $yearLevel, $semester,
            ]);
            $subjectId = (int)$pdo->lastInsertId();
        }

        // Prerequisites describe the curriculum requirement. We keep the
        // existing exact subject identities for compatibility with the current
        // validation engine; every section of the same curriculum subject can
        // therefore share the same prerequisite set.
        $pdo->prepare('DELETE FROM subject_prerequisites WHERE subject_id=?')->execute([$subjectId]);

        $prereqIds = $body['prerequisiteSubjectIds'] ?? [];
        if (is_array($prereqIds) && count($prereqIds) > 0) {
            $pstmt = $pdo->prepare('INSERT INTO subject_prerequisites
                (subject_id, prereq_subject_id, sub_code, prereq_sub_code)
                SELECT ?, p.subject_id, ?, p.sub_code
                FROM subjects p WHERE p.subject_id=? AND p.program_code=?');
            foreach ($prereqIds as $pid) {
                $pid = (int)$pid;
                if ($pid > 0) $pstmt->execute([$subjectId, $subCode, $pid, $programCode]);
            }
        } else {
            $prereqStmt = $pdo->prepare('INSERT INTO subject_prerequisites
                (subject_id, prereq_subject_id, sub_code, prereq_sub_code)
                SELECT ?, p.subject_id, ?, p.sub_code
                FROM subjects p WHERE p.program_code=? AND p.sub_code=? LIMIT 1');
            foreach (($body['prerequisites'] ?? []) as $p) {
                $p = trim((string)$p);
                if ($p !== '') $prereqStmt->execute([$subjectId, $subCode, $programCode, $p]);
            }
        }

        $pdo->commit();
        respond(['status'=>'ok', 'subjectId'=>$subjectId, 'curriculumSubjectId'=>$curriculumSubjectId]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_response('Failed to save subject offering: ' . $e->getMessage(), 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $subjectId = isset($_GET['subjectId']) ? (int)$_GET['subjectId'] : 0;
    $program = trim((string)($_GET['program'] ?? ''));
    if ($subjectId <= 0 || $program === '') error_response('subjectId and program are required.');

    $stmt = $pdo->prepare('SELECT curriculum_subject_id, section FROM subjects WHERE subject_id=? AND program_code=? LIMIT 1');
    $stmt->execute([$subjectId, $program]);
    $row = $stmt->fetch();
    if (!$row) error_response('Subject offering not found.', 404);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM subjects WHERE subject_id=? AND program_code=?')->execute([$subjectId, $program]);
        // A curriculum identity is retained if any other offering still uses it.
        if (!empty($row['curriculum_subject_id'])) {
            $left = $pdo->prepare('SELECT COUNT(*) c FROM subjects WHERE curriculum_subject_id=?');
            $left->execute([(int)$row['curriculum_subject_id']]);
            if ((int)$left->fetch()['c'] === 0) {
                $pdo->prepare('UPDATE curriculum_subjects SET is_active=0 WHERE curriculum_subject_id=?')->execute([(int)$row['curriculum_subject_id']]);
            }
        }
        $pdo->commit();
        respond(['status'=>'ok']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_response('Failed to delete offering: ' . $e->getMessage(), 500);
    }
}

error_response('Method not allowed', 405);
