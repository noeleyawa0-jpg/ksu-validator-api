<?php
// api/admin_duplicate_section.php
// Creates Section B offerings without changing the existing Section A offering.
// B uses a distinct offering subject code (for example CC 124-B) and a
// year-specific section label (for example BSIT 4B).

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';

$session = current_session();
if (!$session || ($session['role'] ?? '') !== 'admin') error_response('Unauthorized', 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') error_response('Method not allowed', 405);

function b_section_label(string $program, int $yearLevel): string {
    return trim($program) . ' ' . $yearLevel . 'B';
}

function b_subject_code(string $code): string {
    $code = trim($code);
    // If the source already has an explicit A suffix, replace it with B.
    if (preg_match('/(?:-|\s)A$/i', $code)) {
        $code = preg_replace('/(?:-|\s)A$/i', '-B', $code);
    } else {
        $code .= '-B';
    }
    // subjects.sub_code is VARCHAR(20). Preserve the -B suffix.
    if (strlen($code) > 20) {
        $code = substr($code, 0, 18) . '-B';
    }
    return $code;
}

function is_b_section(string $section): bool {
    return (bool)preg_match('/(?:^|[\s-])(?:[1-9][0-9]*)?B$/i', trim($section));
}

function is_a_or_legacy_source(string $program, string $section): bool {
    $s = strtoupper(trim($section));
    $p = strtoupper(trim($program));
    if ($s === '' || is_b_section($s)) return false;
    // Legacy source data used the program code itself as the A section.
    if ($s === $p || $s === 'A') return true;
    return (bool)preg_match('/(?:^|[\s-])(?:[1-9][0-9]*)?A$/i', $s);
}

$body = json_body();
$requestedProgram = trim((string)($body['program'] ?? ''));
$sourceSubjectId = isset($body['sourceSubjectId']) && $body['sourceSubjectId'] !== ''
    ? (int)$body['sourceSubjectId'] : null;

$allowedPrograms = ['BSIT','BSCpE','BSCE','BSMath','BSEE','BSABE'];
if ($requestedProgram !== '' && !in_array($requestedProgram, $allowedPrograms, true)) {
    error_response('Invalid CEIT program.');
}

$pdo = db();
try {
    $termStmt = $pdo->query("SELECT term_code FROM academic_terms WHERE is_current=1 ORDER BY term_code DESC LIMIT 1");
    $currentTerm = $termStmt->fetchColumn();
    if (!$currentTerm) error_response('No current academic term is configured.', 409);

    $findExisting = $pdo->prepare('SELECT * FROM subjects
        WHERE term_code=? AND program_code=? AND curriculum_subject_id=? AND section=? LIMIT 1');

    $findByLegacy = $pdo->prepare('SELECT * FROM subjects
        WHERE term_code=? AND program_code=? AND sub_code=? AND section=? LIMIT 1');

    $insert = $pdo->prepare('INSERT INTO subjects
        (curriculum_subject_id, term_code, sub_code, program_code, sched_code, description, units,
         schedule, section, instructor, is_exclusive, year_level, semester)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

    $updateCode = $pdo->prepare('UPDATE subjects SET sub_code=?, section=?, curriculum_subject_id=COALESCE(curriculum_subject_id, ?) WHERE subject_id=?');
    $deleteOldPrereqCode = $pdo->prepare('UPDATE subject_prerequisites SET sub_code=? WHERE subject_id=?');

    $copyPrereqs = $pdo->prepare('INSERT INTO subject_prerequisites
        (subject_id, prereq_subject_id, sub_code, prereq_sub_code)
        SELECT ?, sp.prereq_subject_id, ?, p.sub_code
        FROM subject_prerequisites sp
        JOIN subjects p ON p.subject_id=sp.prereq_subject_id
        WHERE sp.subject_id=?');

    $ensureCapacity = $pdo->prepare('INSERT INTO section_capacities
        (term_code, program_code, year_level, section, capacity)
        SELECT ?, ?, ?, ?, 40
        WHERE NOT EXISTS (SELECT 1 FROM section_capacities
          WHERE term_code=? AND program_code=? AND year_level=? AND section=?)');
    $ensureSubjectCapacity = $pdo->prepare('INSERT INTO subject_capacities(subject_id,term_code,capacity) VALUES(?,?,40) ON DUPLICATE KEY UPDATE capacity=capacity');

    $normalizeCapacity = $pdo->prepare('UPDATE section_capacities
        SET section=?
        WHERE term_code=? AND program_code=? AND year_level=? AND (section=? OR section=? OR section=?)');

    $process = function(array $source) use ($pdo,$findExisting,$findByLegacy,$insert,$updateCode,$deleteOldPrereqCode,$copyPrereqs,$ensureCapacity,$normalizeCapacity,$currentTerm) {
        $program = trim((string)$source['program_code']);
        $year = (int)$source['year_level'];
        $targetSection = b_section_label($program, $year);
        $newCode = b_subject_code((string)$source['sub_code']);
        $curriculumId = (int)($source['curriculum_subject_id'] ?? 0);

        $existing = null;
        if ($curriculumId > 0) {
            $findExisting->execute([$currentTerm,$program,$curriculumId,$targetSection]);
            $existing = $findExisting->fetch();
        }
        if (!$existing) {
            // Also recognize older B rows that were stored as B or program-year-B.
            $findByLegacy->execute([$currentTerm,$program,(string)$source['sub_code'],'B']);
            $existing = $findByLegacy->fetch() ?: null;
            if (!$existing) {
                $legacyShort = $program . ' ' . $year . 'B';
                $findByLegacy->execute([$currentTerm,$program,(string)$source['sub_code'],$legacyShort]);
                $existing = $findByLegacy->fetch() ?: null;
            }
        }

        if ($existing) {
            // Repair the accidental same-code B copy in-place. This preserves
            // its offering ID while giving it the requested distinct B code.
            if ((string)$existing['sub_code'] !== $newCode || (string)$existing['section'] !== $targetSection) {
                $updateCode->execute([$newCode,$targetSection,$curriculumId > 0 ? $curriculumId : null,(int)$existing['subject_id']]);
                $deleteOldPrereqCode->execute([$newCode,(int)$existing['subject_id']]);
            }
            $normalizeCapacity->execute([$targetSection,$currentTerm,$program,$year,'B',$program . ' ' . $year . 'B',$targetSection]);
            $ensureCapacity->execute([$currentTerm,$program,$year,$targetSection,$currentTerm,$program,$year,$targetSection]);
            $ensureSubjectCapacity->execute([(int)$existing['subject_id'],$currentTerm]);
            return ['created'=>false,'repaired'=>true,'subjectId'=>(int)$existing['subject_id'],'code'=>$newCode,'section'=>$targetSection];
        }

        $pdo->beginTransaction();
        try {
            $insert->execute([
                $curriculumId > 0 ? $curriculumId : null,
                $currentTerm, $newCode, $program, $source['sched_code'], $source['description'],
                $source['units'], $source['schedule'], $targetSection, $source['instructor'],
                $source['is_exclusive'], $year, $source['semester']
            ]);
            $newId=(int)$pdo->lastInsertId();
            $copyPrereqs->execute([$newId,$newCode,(int)$source['subject_id']]);
            $ensureSubjectCapacity->execute([$newId,$currentTerm]);
            $ensureCapacity->execute([$currentTerm,$program,$year,$targetSection,$currentTerm,$program,$year,$targetSection]);
            $pdo->commit();
            return ['created'=>true,'repaired'=>false,'subjectId'=>$newId,'code'=>$newCode,'section'=>$targetSection];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    };

    if ($sourceSubjectId !== null) {
        $stmt=$pdo->prepare('SELECT * FROM subjects WHERE subject_id=? AND term_code=? LIMIT 1');
        $stmt->execute([$sourceSubjectId,$currentTerm]);
        $source=$stmt->fetch();
        if (!$source) error_response('Source subject offering was not found in the current academic term.',404);
        if ($requestedProgram !== '' && trim($source['program_code']) !== $requestedProgram) error_response('Source subject does not belong to the selected program.',403);
        if (!is_a_or_legacy_source((string)$source['program_code'],(string)$source['section'])) {
            error_response('Select a Section A/current curriculum offering, not an existing Section B offering.',409);
        }
        $result=$process($source);
        respond([
            'status'=>'ok','created'=>$result['created']?1:0,'repaired'=>$result['repaired']?1:0,
            'totalCreated'=>$result['created']?1:0,'totalRepaired'=>$result['repaired']?1:0,
            'subjectId'=>$result['subjectId'],'subCode'=>$result['code'],'section'=>$result['section'],
            'termCode'=>$currentTerm,
            'message'=>$result['created']?'Section B offering created successfully.':($result['repaired']?'Existing Section B offering was repaired with the correct B subject code.':'Section B offering already exists.')
        ]);
    }

    $programs=$requestedProgram===''?$allowedPrograms:[$requestedProgram];
    $created=[];$repaired=[];$skipped=[];$totalCreated=0;$totalRepaired=0;$totalSkipped=0;
    $findSources=$pdo->prepare('SELECT * FROM subjects WHERE program_code=? AND term_code=? ORDER BY year_level,semester,subject_id');
    foreach($programs as $program){
        $findSources->execute([$program,$currentTerm]);
        $c=$r=$s=0;
        foreach($findSources->fetchAll() as $source){
            if(!is_a_or_legacy_source($program,(string)$source['section'])) { $s++;$totalSkipped++;continue; }
            $result=$process($source);
            if($result['created']){$c++;$totalCreated++;}
            elseif($result['repaired']){$r++;$totalRepaired++;}
            else{$s++;$totalSkipped++;}
        }
        $created[$program]=$c;$repaired[$program]=$r;$skipped[$program]=$s;
    }

    respond([
        'status'=>'ok','termCode'=>$currentTerm,
        'created'=>$created,'repaired'=>$repaired,'skipped'=>$skipped,
        'totalCreated'=>$totalCreated,'totalRepaired'=>$totalRepaired,'totalSkipped'=>$totalSkipped,
        'message'=>"Section B processing complete: {$totalCreated} created, {$totalRepaired} repaired, {$totalSkipped} skipped."
    ]);
} catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    error_response('Failed to create/repair Section B offerings: '.$e->getMessage(),500);
}
