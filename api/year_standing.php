<?php
// Adviser clarification: whole-course units; >51% and <75% is third year,
// >=75% is fourth year. No Year-1/2 percentage rule is inferred.
function standing_code(string $code): string {
    return strtoupper(preg_replace('/\s+/', ' ', trim($code)));
}

function standing_requirements(PDO $pdo, string $program): array {
    $term = $pdo->query('SELECT term_code FROM academic_terms WHERE is_current=1 ORDER BY term_code DESC LIMIT 1')->fetchColumn();
    if (!$term) return [];
    $stmt = $pdo->prepare('SELECT s.*,cs.sub_code AS academic_code FROM subjects s
        LEFT JOIN curriculum_subjects cs ON cs.curriculum_subject_id=s.curriculum_subject_id
        WHERE s.program_code=? AND s.year_level BETWEEN 1 AND 5 AND s.term_code<=?
          AND (cs.is_active=1 OR cs.curriculum_subject_id IS NULL)
        ORDER BY s.term_code DESC,s.subject_id ASC');
    $stmt->execute([$program,$term]);
    $latest = []; $requirements = [];
    foreach ($stmt->fetchAll() as $row) {
        $semester = (int)$row['semester'];
        $latest[$semester] ??= $row['term_code'];
        if ($row['term_code'] !== $latest[$semester]) continue;
        $code = standing_code($row['academic_code'] ?: $row['sub_code']);
        $year = (int)$row['year_level'];
        // ROTC/CWTS are alternative requirements, not two sets of credits.
        $group = preg_replace('/\s+/', '', $code);
        if (preg_match('/^NSTP(?:1|11)(?:-[AB])?$/', $group)) $group = 'NSTP-TRACK-1';
        elseif (preg_match('/^NSTP(?:2|12)(?:-[AB])?$/', $group)) $group = 'NSTP-TRACK-2';
        else $group = $code;
        $key = "$year|$semester|$group";
        if (!isset($requirements[$key])) $requirements[$key] = ['year'=>$year,'semester'=>$semester,'creditKey'=>$group,'unitsTenths'=>0,'unitValues'=>[],'valid'=>true,'codes'=>[]];
        $units = (int)round((float)$row['units']*10);
        $requirements[$key]['unitValues'][$units] = true;
        if ($units<0 || count($requirements[$key]['unitValues'])>1) $requirements[$key]['valid'] = false;
        $requirements[$key]['unitsTenths'] = max($requirements[$key]['unitsTenths'],$units);
        $requirements[$key]['codes'][$code] = true;
        $requirements[$key]['codes'][standing_code($row['sub_code'])] = true;
    }
    ksort($requirements);
    return array_values($requirements);
}

function standing_progress(array $requirements, array $passedCodes, int $throughYear, int $targetYear): array {
    $total = 0; $passed = 0; $counted = []; $credited = []; $requiredCredits = []; $present = []; $valid = true;
    $codeUnits = [];
    foreach ($requirements as $r) {
        if ($r['year']>$throughYear) continue;
        foreach ($r['codes'] as $code => $_) {
            if (isset($codeUnits[$code]) && $codeUnits[$code]!==$r['unitsTenths']) $valid=false;
            $codeUnits[$code]=$r['unitsTenths'];
        }
    }
    foreach ($requirements as $r) {
        if ($r['year'] > $throughYear) continue;
        if (!$r['valid']) $valid = false;
        $present[$r['year'].'|'.$r['semester']] = true;
        if (!isset($requiredCredits[$r['creditKey']])) {
            $total += $r['unitsTenths'];
            $requiredCredits[$r['creditKey']] = true;
        }
        $matched = false;
        foreach ($r['codes'] as $code => $_) {
            if (isset($passedCodes[$code]) && !isset($counted[$code])) $matched = true;
        }
        if ($matched && !isset($credited[$r['creditKey']])) {
            $passed += $r['unitsTenths'];
            // A repeated grade / another section can never credit this course twice.
            foreach ($r['codes'] as $code => $_) $counted[$code] = true;
            $credited[$r['creditKey']] = true;
        }
    }
    $complete = $valid;
    for ($year=1;$year<=$throughYear;$year++) {
        foreach ([1,2] as $semester) if (!isset($present["$year|$semester"])) $complete = false;
    }
    return [
        'throughYear'=>$throughYear,'basis'=>'wholeCurriculum',
        'thresholdPercent'=>$targetYear===3 ? 51 : 75,
        'thresholdInclusive'=>$targetYear===4,
        'totalUnits'=>$total/10,'passedUnits'=>$passed/10,
        'percent'=>$total>0 ? round($passed*100/$total,2) : 0,
        'curriculumComplete'=>$complete && $total>0,
        // Compare integer tenths, never a rounded display percentage.
        'eligible'=>$complete && $total>0 && ($targetYear===3
            ? ($passed*100 > $total*51 && $passed*100 < $total*75)
            : $passed*100 >= $total*75),
    ];
}

function student_standing_progress(PDO $pdo, string $studentId, string $program): array {
    $requirements = standing_requirements($pdo,$program);
    $stmt = $pdo->prepare('SELECT sub_code,grade,passed FROM academic_records WHERE student_id=?');
    $stmt->execute([$studentId]);
    $passed = [];
    foreach ($stmt->fetchAll() as $grade) {
        if ((bool)$grade['passed'] && !in_array(strtoupper(trim($grade['grade'])),['5','5.00','INC','INCOMPLETE','OD','ODP','DROP','DROPPED','DRP'],true)) {
            $passed[standing_code($grade['sub_code'])] = true;
        }
    }
    // All six CEIT programs have four curriculum years. Never treat a missing
    // final year as a complete, smaller course. Include a fifth year if present.
    $throughYear = 4;
    foreach ($requirements as $r) $throughYear = max($throughYear,$r['year']);
    return ['thirdYear'=>standing_progress($requirements,$passed,$throughYear,3),
        'fourthYear'=>standing_progress($requirements,$passed,$throughYear,4)];
}

function refresh_student_standing(PDO $pdo, string $studentId, string $actor): array {
    $owns = !$pdo->inTransaction();
    if ($owns) $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id,program,year_level,year_section FROM users WHERE id=? AND role="student" FOR UPDATE');
        $stmt->execute([$studentId]); $student = $stmt->fetch();
        if (!$student) throw new RuntimeException('Student not found.');
        $year = (int)$student['year_level'];
        $progress = student_standing_progress($pdo,$studentId,$student['program']);
        $target = $year;
        $rule = null;
        if ($progress['fourthYear']['curriculumComplete'] && $progress['fourthYear']['eligible']) {
            $target=4; $rule=$progress['fourthYear'];
        } elseif ($progress['thirdYear']['curriculumComplete'] && $progress['thirdYear']['eligible']) {
            $target=3; $rule=$progress['thirdYear'];
        } elseif ($year>=3 && $progress['thirdYear']['curriculumComplete']) {
            $target=2; $rule=$progress['thirdYear'];
        }
        if ($target !== $year) {
            $oldSection = (string)($student['year_section'] ?? '');
            $section = preg_replace('/(?<!\d)'.$year.'(?=[- ]?[A-Z]$)/i',(string)$target,$oldSection);
            $pdo->prepare('UPDATE users SET year_level=?,year_section=? WHERE id=? AND year_level=?')->execute([$target,$section,$studentId,$year]);
            $notes = 'Automatic standing: '.$rule['passedUnits'].' / '.$rule['totalUnits'].' whole-curriculum units passed ('.($target===3 ? 'above 51% and below 75%' : ($target===4 ? 'at least 75%' : 'third/fourth-year threshold not met')).').';
            $pdo->prepare('INSERT INTO student_movements
                (student_id,movement_type,from_program,to_program,from_year_level,to_year_level,from_section,to_section,notes,performed_by,moved_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)')->execute([
                    $studentId,$target>$year ? 'year_promotion' : 'year_reclassification',$student['program'],$student['program'],$year,$target,$oldSection,$section,$notes,$actor,
                ]);
        }
        if ($owns) $pdo->commit();
        return ['previousYearLevel'=>$year,'yearLevel'=>$target,'promoted'=>$target>$year,'changed'=>$target!==$year,'progress'=>$progress];
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
