<?php
// api/admin_duplicate_section.php
// POST /api/admin_duplicate_section.php
// Body: {"targetSection":"B","program":"BSIT"} or omit program for all CEIT programs.
// Creates one Section B offering for every existing Section A subject.
// A rows are NEVER modified.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';

$session = current_session();
if (!$session || ($session['role'] ?? '') !== 'admin') error_response('Unauthorized', 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') error_response('Method not allowed', 405);

$body = json_body();
$targetSection = strtoupper(trim((string)($body['targetSection'] ?? 'B')));
if ($targetSection !== 'B') error_response('Only Section B duplication is supported.');

$requestedProgram = trim((string)($body['program'] ?? ''));
$allowedPrograms = ['BSIT','BSCpE','BSCE','BSMath','BSEE','BSABE'];
if ($requestedProgram !== '' && !in_array($requestedProgram, $allowedPrograms, true)) {
    error_response('Invalid CEIT program.');
}
$programs = $requestedProgram === '' ? $allowedPrograms : [$requestedProgram];

$pdo = db();
$created = [];
$skipped = [];
$totalCreated = 0;
$totalSkipped = 0;

try {
    $pdo->beginTransaction();

    $findSources = $pdo->prepare("SELECT * FROM subjects WHERE program_code = ? AND term_code = ? AND UPPER(TRIM(section)) LIKE '%A' ORDER BY year_level, semester, subject_id");
    $findExisting = $pdo->prepare('SELECT subject_id FROM subjects WHERE term_code = ? AND program_code = ? AND section = ? AND sub_code = ? LIMIT 1');
    $insertSubject = $pdo->prepare('
        INSERT INTO subjects
            (curriculum_subject_id, term_code, sub_code, program_code, sched_code, description, units, schedule, section,
             instructor, is_exclusive, year_level, semester)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $copyPrereqs = $pdo->prepare('
        INSERT INTO subject_prerequisites
            (subject_id, prereq_subject_id, sub_code, prereq_sub_code)
        SELECT ?, sp.prereq_subject_id, ?, p.sub_code
        FROM subject_prerequisites sp
        JOIN subjects p ON p.subject_id = sp.prereq_subject_id
        WHERE sp.subject_id = ?
    ');
    $capacity = $pdo->prepare('
        INSERT INTO section_capacities (term_code, program_code, year_level, section, capacity)
        SELECT ?, ?, ?, ?, 40
        WHERE NOT EXISTS (
            SELECT 1 FROM section_capacities
            WHERE term_code = ? AND program_code = ? AND year_level = ? AND section = ?
        )
    ');

    $termStmt = $pdo->query("SELECT term_code FROM academic_terms WHERE is_current = 1 ORDER BY term_code DESC LIMIT 1");
    $termRow = $termStmt->fetch();
    $currentTerm = $termRow['term_code'] ?? null;
    if ($currentTerm === null) error_response('No current academic term is configured.', 409);

    foreach ($programs as $program) {
        $findSources->execute([$program, $currentTerm]);
        $sources = $findSources->fetchAll();
        $programCreated = 0;
        $programSkipped = 0;

        foreach ($sources as $source) {
            // Stable generated temporary code. It is intentionally editable later.
            $sourceSection = trim((string)$source['section']);
            if ($sourceSection === '') {
                continue;
            }
            // Preserve the year/section pattern used by the existing curriculum:
            // 1-A becomes 1-B, 2-A becomes 2-B, while plain A becomes B.
            $targetSection = preg_replace('/A$/i', 'B', $sourceSection);
            if (!is_string($targetSection) || $targetSection === $sourceSection) {
                continue;
            }
            $generatedCode = 'B-' . (int)$source['subject_id'];
            $findExisting->execute([$currentTerm, $program, $targetSection, $generatedCode]);
            if ($findExisting->fetch()) {
                $programSkipped++;
                $totalSkipped++;
                continue;
            }

            $insertSubject->execute([
                $source['curriculum_subject_id'],
                $currentTerm,
                $generatedCode,
                $program,
                substr((string)$source['sched_code'] . '-B', 0, 30),
                $source['description'],
                $source['units'],
                $source['schedule'],
                $targetSection,
                $source['instructor'],
                $source['is_exclusive'],
                $source['year_level'],
                $source['semester'],
            ]);
            $newId = (int)$pdo->lastInsertId();

            // Copy the exact prerequisite subject identities from A.
            $copyPrereqs->execute([$newId, $generatedCode, (int)$source['subject_id']]);

            if ($currentTerm !== null) {
                $capacity->execute([
                    $currentTerm, $program, (int)$source['year_level'], $targetSection,
                    $currentTerm, $program, (int)$source['year_level'], $targetSection,
                ]);
            }

            $programCreated++;
            $totalCreated++;
        }

        $created[$program] = $programCreated;
        $skipped[$program] = $programSkipped;
    }

    $pdo->commit();
    respond([
        'status' => 'ok',
        'targetSection' => 'B',
        'termCode' => $currentTerm,
        'created' => $created,
        'skipped' => $skipped,
        'totalCreated' => $totalCreated,
        'totalSkipped' => $totalSkipped,
        'message' => $totalCreated > 0
            ? "Created $totalCreated Section B subject offerings. Existing A offerings were not changed."
            : 'No new Section B offerings were needed; existing generated B copies were kept.',
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_response('Failed to create Section B offerings: ' . $e->getMessage(), 500);
}
