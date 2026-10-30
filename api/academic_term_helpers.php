<?php
// Shared term naming and preparation. Call preparation inside a transaction.
function semester_from_term_code(string $code): ?int {
    return preg_match('/^\d{2,4}-([123])$/', trim($code), $m) ? (int)$m[1] : null;
}

function academic_term_name(int $semester): string {
    return [1 => 'First Semester', 2 => 'Second Semester', 3 => 'Summer / Special Term'][$semester];
}

function ensure_term_rollover_schema(PDO $pdo): void {
    // Run before opening the transaction: MySQL DDL implicitly commits.
    $pdo->exec('CREATE TABLE IF NOT EXISTS term_offering_rollovers (
        term_code VARCHAR(20) NOT NULL,
        program_code VARCHAR(20) NOT NULL,
        source_term_code VARCHAR(20) NOT NULL,
        prepared_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (term_code,program_code)
    )');
}

function offering_key(array $row): string {
    $program = strtoupper(trim($row['program_code']));
    $section = strtoupper(trim($row['section']));
    // Legacy program-only section names mean Section A.
    if ($section === $program || $section === 'A') $section = $program . ' ' . $row['year_level'] . 'A';
    if (preg_match('/^(?:' . preg_quote($program, '/') . '\s*)?([1-4])[- ]?([A-Z])$/', $section, $m)) {
        $section = $program . ' ' . $m[1] . $m[2];
    }
    return implode('|', [$program, strtoupper(trim($row['sub_code'])), (int)$row['year_level'], (int)$row['semester'], $section]);
}

function prepare_term_offerings(PDO $pdo, string $termCode, int $semester): int {
    // Read existing definitions; never move/delete historical offerings or enrollments.
    // Existing target offerings win, including their edits, prerequisites and capacity.
    $done = $pdo->prepare('SELECT program_code FROM term_offering_rollovers WHERE term_code=?');
    $done->execute([$termCode]);
    $prepared = array_fill_keys($done->fetchAll(PDO::FETCH_COLUMN), true);
    $stmt = $pdo->prepare('SELECT s.* FROM subjects s WHERE s.term_code=?');
    $stmt->execute([$termCode]);
    $existing = [];
    foreach ($stmt->fetchAll() as $row) $existing[offering_key($row)] = true;

    $stmt = $pdo->prepare('SELECT s.* FROM subjects s
        LEFT JOIN curriculum_subjects cs ON cs.curriculum_subject_id=s.curriculum_subject_id
        LEFT JOIN academic_terms t ON t.term_code=s.term_code
        WHERE s.semester=? AND s.term_code<? AND TRIM(s.section)<>\'\' AND (cs.is_active=1 OR cs.curriculum_subject_id IS NULL)
        ORDER BY t.school_year DESC, s.term_code DESC, s.subject_id DESC');
    $stmt->execute([$semester, $termCode]);
    $sources = $stmt->fetchAll();
    $insert = $pdo->prepare('INSERT INTO subjects
        (curriculum_subject_id,term_code,sub_code,program_code,sched_code,description,units,schedule,section,instructor,is_exclusive,year_level,semester,subject_category)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $prereqs = $pdo->prepare('INSERT INTO subject_prerequisites (subject_id,prereq_subject_id,sub_code,prereq_sub_code)
        SELECT ?,prereq_subject_id,?,prereq_sub_code FROM subject_prerequisites WHERE subject_id=?');
    $capacity = $pdo->prepare('INSERT INTO subject_capacities (subject_id,term_code,capacity) VALUES (?,?,40)');
    $sectionCapacity = $pdo->prepare('INSERT INTO section_capacities (term_code,program_code,year_level,section,capacity)
        SELECT ?,?,?,?,40 WHERE NOT EXISTS (SELECT 1 FROM section_capacities WHERE term_code=? AND program_code=? AND year_level=? AND section=?)');
    $created = 0;
    $sourceTerms = [];
    foreach ($sources as $source) {
        // Use the latest prior list for each program, rather than merging every
        // historical version (which could resurrect a renamed/removed subject).
        $program = $source['program_code'];
        if (isset($prepared[$program])) continue;
        $sourceTerms[$program] ??= $source['term_code'];
        if ($source['term_code'] !== $sourceTerms[$program]) continue;
        $key = offering_key($source);
        if (isset($existing[$key])) continue;
        $insert->execute([
            $source['curriculum_subject_id'], $termCode, $source['sub_code'], $source['program_code'],
            'Not applicable', $source['description'], $source['units'], 'Not applicable', $source['section'],
            'Not applicable', $source['is_exclusive'], $source['year_level'], $semester, $source['subject_category'] ?? 'major',
        ]);
        $id = (int)$pdo->lastInsertId();
        $prereqs->execute([$id, $source['sub_code'], $source['subject_id']]);
        $capacity->execute([$id, $termCode]);
        $section = [$termCode, $source['program_code'], $source['year_level'], $source['section']];
        $sectionCapacity->execute(array_merge($section, $section));
        $existing[$key] = true;
        $created++;
    }
    $record = $pdo->prepare('INSERT INTO term_offering_rollovers (term_code,program_code,source_term_code) VALUES (?,?,?)');
    foreach ($sourceTerms as $program => $sourceTerm) $record->execute([$termCode,$program,$sourceTerm]);
    return $created;
}
