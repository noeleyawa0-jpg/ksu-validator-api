<?php
// api/admin_duplicate_section.php
// POST /api/admin_duplicate_section.php
// Body: {"targetSection":"B","program":"BSIT"} for bulk, or
//       {"targetSection":"B","sourceSubjectId":123} for one offering.
// Creates a separate Section B class offering for the same curriculum subject.
// Existing source offerings are NEVER modified.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';

$session = current_session();
if (!$session || ($session['role'] ?? '') !== 'admin') error_response('Unauthorized', 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') error_response('Method not allowed', 405);

$body = json_body();
// Section B is generated from the offering's program and year level, e.g.
// BSIT 1B, BSIT 2B, BSCpE 3B, etc. The client does not choose the stored section label.
$targetSectionRequest = strtoupper(trim((string)($body['targetSection'] ?? '')));
if ($targetSectionRequest !== '' && $targetSectionRequest !== 'B') error_response('Only Section B creation is supported.');

$requestedProgram = trim((string)($body['program'] ?? ''));
$sourceSubjectId = isset($body['sourceSubjectId']) && $body['sourceSubjectId'] !== ''
    ? (int)$body['sourceSubjectId'] : null;

$allowedPrograms = ['BSIT','BSCpE','BSCE','BSMath','BSEE','BSABE'];
if ($requestedProgram !== '' && !in_array($requestedProgram, $allowedPrograms, true)) {
    error_response('Invalid CEIT program.');
}

$pdo = db();

try {
    $termStmt = $pdo->query("SELECT term_code FROM academic_terms WHERE is_current = 1 ORDER BY term_code DESC LIMIT 1");
    $currentTerm = $termStmt->fetchColumn();
    if (!$currentTerm) error_response('No current academic term is configured.', 409);

    // One-subject operation: create exactly one B offering.
    if ($sourceSubjectId !== null) {
        $stmt = $pdo->prepare('SELECT * FROM subjects WHERE subject_id = ? AND (term_code = ? OR term_code IS NULL) LIMIT 1');
        $stmt->execute([$sourceSubjectId, $currentTerm]);
        $source = $stmt->fetch();
        if (!$source) error_response('Source subject offering was not found in the current academic term.', 404);

        $program = trim((string)$source['program_code']);
        if ($requestedProgram !== '' && $requestedProgram !== $program) {
            error_response('Source subject does not belong to the selected program.', 403);
        }

        $sourceSection = strtoupper(trim((string)$source['section']));
        $targetSection = $program . ' ' . (int)$source['year_level'] . 'B';
        if ($sourceSection === strtoupper($targetSection) || $sourceSection === 'B' || preg_match('/(?:^|-|\s)B$/i', $sourceSection)) {
            error_response('This offering is already a Section B offering.', 409);
        }

        $existing = $pdo->prepare('SELECT subject_id FROM subjects
            WHERE term_code = ? AND program_code = ? AND section = ?
              AND ((curriculum_subject_id IS NOT NULL AND curriculum_subject_id = ?)
                   OR (curriculum_subject_id IS NULL AND sub_code = ?))
            LIMIT 1');
        $existing->execute([$currentTerm, $program, $targetSection,
            $source['curriculum_subject_id'], $source['sub_code']]);
        if ($existing->fetch()) {
            respond([
                'status' => 'ok', 'created' => 0, 'skipped' => 1,
                'totalCreated' => 0, 'totalSkipped' => 1,
                'termCode' => $currentTerm, 'targetSection' => $targetSection,
                'message' => "$targetSection already exists for this curriculum subject."
            ]);
        }

        // Upgrade an older test record that used the generic section "B".
        $legacy = $pdo->prepare("SELECT subject_id FROM subjects
            WHERE term_code = ? AND program_code = ? AND section = 'B'
              AND ((curriculum_subject_id IS NOT NULL AND curriculum_subject_id = ?)
                   OR (curriculum_subject_id IS NULL AND sub_code = ?))
            LIMIT 1");
        $legacy->execute([$currentTerm, $program, $source['curriculum_subject_id'], $source['sub_code']]);
        $legacyId = $legacy->fetchColumn();
        if ($legacyId) {
            $pdo->beginTransaction();
            $rename = $pdo->prepare('UPDATE subjects SET section = ? WHERE subject_id = ?');
            $rename->execute([$targetSection, (int)$legacyId]);
            $pdo->commit();
            respond([
                'status'=>'ok', 'created'=>0, 'skipped'=>0, 'totalCreated'=>0, 'totalSkipped'=>0,
                'termCode'=>$currentTerm, 'targetSection'=>$targetSection, 'subjectId'=>(int)$legacyId,
                'message'=>"Existing legacy Section B was upgraded to $targetSection."
            ]);
        }

        $pdo->beginTransaction();
        $insert = $pdo->prepare('INSERT INTO subjects
            (curriculum_subject_id, term_code, sub_code, program_code, sched_code, description, units,
             schedule, section, instructor, is_exclusive, year_level, semester)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([
            $source['curriculum_subject_id'], $currentTerm, $source['sub_code'], $program,
            $source['sched_code'], $source['description'], $source['units'], $source['schedule'],
            $targetSection, $source['instructor'], $source['is_exclusive'], $source['year_level'], $source['semester']
        ]);
        $newId = (int)$pdo->lastInsertId();

        $copy = $pdo->prepare('INSERT INTO subject_prerequisites
            (subject_id, prereq_subject_id, sub_code, prereq_sub_code)
            SELECT ?, sp.prereq_subject_id, ?, p.sub_code
            FROM subject_prerequisites sp
            JOIN subjects p ON p.subject_id = sp.prereq_subject_id
            WHERE sp.subject_id = ?');
        $copy->execute([$newId, $source['sub_code'], $sourceSubjectId]);

        $capacity = $pdo->prepare('INSERT INTO section_capacities
            (term_code, program_code, year_level, section, capacity)
            SELECT ?, ?, ?, ?, 40
            WHERE NOT EXISTS (SELECT 1 FROM section_capacities
                WHERE term_code=? AND program_code=? AND year_level=? AND section=?)');
        $capacity->execute([
            $currentTerm, $program, $source['year_level'], $targetSection,
            $currentTerm, $program, $source['year_level'], $targetSection
        ]);

        $pdo->commit();
        respond([
            'status'=>'ok', 'created'=>1, 'skipped'=>0, 'totalCreated'=>1, 'totalSkipped'=>0,
            'termCode'=>$currentTerm, 'targetSection'=>$targetSection, 'subjectId'=>$newId,
            'message'=>'Section B offering created successfully.'
        ]);
    }

    // Bulk operation: clone each current-term CEIT offering that is not already
    // a Section B offering. The curriculum identity and subject code stay the same.
    $programs = $requestedProgram === '' ? $allowedPrograms : [$requestedProgram];
    $created = [];
    $skipped = [];
    $totalCreated = 0;
    $totalSkipped = 0;

    $findSources = $pdo->prepare("SELECT * FROM subjects
        WHERE program_code = ? AND (term_code = ? OR term_code IS NULL)
          AND UPPER(TRIM(section)) <> 'B'
          AND UPPER(TRIM(section)) NOT LIKE '%-B'
        ORDER BY year_level, semester, subject_id");
    $findExisting = $pdo->prepare('SELECT subject_id FROM subjects
        WHERE term_code=? AND program_code=? AND section=?
          AND ((curriculum_subject_id IS NOT NULL AND curriculum_subject_id=?)
               OR (curriculum_subject_id IS NULL AND sub_code=?)) LIMIT 1');
    $findLegacyB = $pdo->prepare("SELECT subject_id FROM subjects
        WHERE term_code=? AND program_code=? AND section='B'
          AND ((curriculum_subject_id IS NOT NULL AND curriculum_subject_id=?)
               OR (curriculum_subject_id IS NULL AND sub_code=?)) LIMIT 1");
    $renameLegacy = $pdo->prepare('UPDATE subjects SET section=? WHERE subject_id=?');
    $insert = $pdo->prepare('INSERT INTO subjects
        (curriculum_subject_id, term_code, sub_code, program_code, sched_code, description, units,
         schedule, section, instructor, is_exclusive, year_level, semester)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $copy = $pdo->prepare('INSERT INTO subject_prerequisites
        (subject_id, prereq_subject_id, sub_code, prereq_sub_code)
        SELECT ?, sp.prereq_subject_id, ?, p.sub_code
        FROM subject_prerequisites sp JOIN subjects p ON p.subject_id=sp.prereq_subject_id
        WHERE sp.subject_id=?');
    $capacity = $pdo->prepare('INSERT INTO section_capacities
        (term_code, program_code, year_level, section, capacity)
        SELECT ?, ?, ?, ?, 40 WHERE NOT EXISTS
        (SELECT 1 FROM section_capacities WHERE term_code=? AND program_code=? AND year_level=? AND section=?)');

    $pdo->beginTransaction();
    foreach ($programs as $program) {
        $findSources->execute([$program, $currentTerm]);
        $programCreated = 0; $programSkipped = 0;
        foreach ($findSources->fetchAll() as $source) {
            $targetSection = $program . ' ' . (int)$source['year_level'] . 'B';
            $findExisting->execute([
                $currentTerm, $program, $targetSection, $source['curriculum_subject_id'], $source['sub_code']
            ]);
            if ($findExisting->fetch()) {
                $programSkipped++; $totalSkipped++; continue;
            }

            // Upgrade any legacy generic "B" offering created by an older build.
            $findLegacyB->execute([$currentTerm, $program, $source['curriculum_subject_id'], $source['sub_code']]);
            $legacyId = $findLegacyB->fetchColumn();
            if ($legacyId) {
                $renameLegacy->execute([$targetSection, (int)$legacyId]);
                $programCreated++; $totalCreated++;
                continue;
            }

            $insert->execute([
                $source['curriculum_subject_id'], $currentTerm, $source['sub_code'], $program,
                $source['sched_code'], $source['description'], $source['units'], $source['schedule'],
                $targetSection, $source['instructor'], $source['is_exclusive'], $source['year_level'], $source['semester']
            ]);
            $newId = (int)$pdo->lastInsertId();
            $copy->execute([$newId, $source['sub_code'], $source['subject_id']]);
            $capacity->execute([
                $currentTerm, $program, $source['year_level'], $targetSection,
                $currentTerm, $program, $source['year_level'], $targetSection
            ]);
            $programCreated++; $totalCreated++;
        }
        $created[$program] = $programCreated;
        $skipped[$program] = $programSkipped;
    }
    $pdo->commit();

    respond([
        'status'=>'ok', 'targetSection'=>'PROGRAM-YEAR-B', 'termCode'=>$currentTerm,
        'created'=>$created, 'skipped'=>$skipped,
        'totalCreated'=>$totalCreated, 'totalSkipped'=>$totalSkipped,
        'message'=>$totalCreated > 0
            ? "Created $totalCreated Section B offerings. Existing offerings were not changed."
            : 'No new Section B offerings were needed; existing B offerings were kept.'
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_response('Failed to create Section B offerings: ' . $e->getMessage(), 500);
}
