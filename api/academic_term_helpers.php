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
    $copies = [];
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
        $copies[$key] = $source;
        $existing[$key] = true;
    }
    if ($copies) {
        // Use set-based writes: hundreds of offerings must fit within the app's
        // HTTP timeout, and TiDB IDs are not assumed to be consecutive.
        $sourceIds = array_column(array_values($copies), 'subject_id');
        $marks = implode(',', array_fill(0, count($sourceIds), '?'));
        $insert = $pdo->prepare("INSERT INTO subjects
            (curriculum_subject_id,term_code,sub_code,program_code,sched_code,description,units,schedule,section,instructor,is_exclusive,year_level,semester,subject_category)
            SELECT curriculum_subject_id,?,sub_code,program_code,'Not applicable',description,units,'Not applicable',section,'Not applicable',is_exclusive,year_level,semester,subject_category
            FROM subjects WHERE subject_id IN ($marks)");
        $insert->execute(array_merge([$termCode], $sourceIds));
        $find = $pdo->prepare('SELECT * FROM subjects WHERE term_code=?');
        $find->execute([$termCode]);
        $ids = []; $maps = []; $params = [];
        foreach ($find->fetchAll() as $row) {
            $key = offering_key($row);
            if (!isset($copies[$key])) continue;
            $ids[] = (int)$row['subject_id'];
            $maps[] = 'SELECT ? AS target_id, ? AS source_id, ? AS sub_code';
            array_push($params, $row['subject_id'], $copies[$key]['subject_id'], $row['sub_code']);
        }
        if (count($ids) !== count($copies)) throw new RuntimeException('Offering copy verification failed.');
        $mapping = implode(' UNION ALL ', $maps);
        $pdo->prepare("INSERT INTO subject_prerequisites (subject_id,prereq_subject_id,sub_code,prereq_sub_code)
            SELECT m.target_id,sp.prereq_subject_id,m.sub_code,sp.prereq_sub_code
            FROM ($mapping) m JOIN subject_prerequisites sp ON sp.subject_id=m.source_id")->execute($params);
        $newMarks = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("INSERT INTO subject_capacities (subject_id,term_code,capacity)
            SELECT subject_id,term_code,40 FROM subjects WHERE subject_id IN ($newMarks)")->execute($ids);
        $pdo->prepare("INSERT INTO section_capacities (term_code,program_code,year_level,section,capacity)
            SELECT DISTINCT s.term_code,s.program_code,s.year_level,s.section,40 FROM subjects s
            WHERE s.subject_id IN ($newMarks) AND NOT EXISTS (SELECT 1 FROM section_capacities c
                WHERE c.term_code=s.term_code AND c.program_code=s.program_code AND c.year_level=s.year_level AND c.section=s.section)")->execute($ids);
    }
    $record = $pdo->prepare('INSERT INTO term_offering_rollovers (term_code,program_code,source_term_code) VALUES (?,?,?)');
    foreach ($sourceTerms as $program => $sourceTerm) $record->execute([$termCode,$program,$sourceTerm]);
    return count($copies);
}
