<?php
// Shared rules for the chair dashboard's Regular / Irregular student counts.
// A unit total is not a classification signal: completion and course selection are.

function chair_classification_code(string $code): string {
    return strtoupper(preg_replace('/\s+/', ' ', trim($code)));
}

function chair_course_group_is_satisfied(array $requirement, array $codes, array $courseRows = []): bool {
    foreach (($requirement['codes'] ?? []) as $code => $_) {
        if (isset($codes[chair_classification_code((string)$code)])) return true;
    }
    $requiredUnits = (int)($requirement['unitsTenths'] ?? 0);
    foreach ($courseRows as $row) {
        $description = strtolower(preg_replace('/\s+/', ' ', trim((string)($row['description'] ?? ''))));
        $units = (int)round((float)($row['units'] ?? 0) * 10);
        if ($description !== ''
            && $units === $requiredUnits
            && isset($requirement['descriptions'][$description])) return true;
    }
    return false;
}

/**
 * Return regular, irregular, or notDetermined for one student.
 *
 * Previous required semesters must be passed. During regular semesters, every
 * required current-year course must already be passed or selected in the active
 * term. A lower-year request is a back subject. Special/summer offerings are
 * optional, so the dashboard does not require every summer course to be selected.
 */
function chair_student_classification(
    array $student,
    array $requirements,
    array $passedCodes,
    array $currentRequestRows,
    string $currentTermCode,
    int $currentSemester
): string {
    $year = (int)($student['year_level'] ?? 0);
    if ($year < 1 || $year > 4 || $currentTermCode === '' || !in_array($currentSemester, [1, 2, 3], true)) {
        return 'notDetermined';
    }

    $type = strtolower(trim((string)($student['enrollment_type'] ?? '')));
    if (in_array($type, ['transferee', 'transfer', 'transferred'], true)) return 'irregular';

    $passed = [];
    foreach ($passedCodes as $code => $isPassed) {
        if ($isPassed) $passed[chair_classification_code((string)$code)] = true;
    }

    $selectedByYear = [];
    $selectedRows = [];
    foreach ($currentRequestRows as $row) {
        if (strtolower(trim((string)($row['status'] ?? ''))) === 'rejected') continue;
        $subjectYear = (int)($row['year_level'] ?? 0);
        $selectedByYear[$subjectYear][chair_classification_code((string)($row['sub_code'] ?? ''))] = true;
        $selectedRows[] = $row;
    }

    $currentRequirements = [];
    foreach ($requirements as $requirement) {
        $requiredYear = (int)($requirement['year'] ?? 0);
        $requiredSemester = (int)($requirement['semester'] ?? 0);
        if ($requiredYear < 1 || $requiredYear > $year) continue;

        // Every subject in a completed year, plus earlier semesters in the
        // current year, must have a passing record. Summer in the current year
        // remains optional; summer requirements from lower years are included.
        $isPrior = $requiredYear < $year || ($requiredYear === $year && $requiredSemester < $currentSemester);
        if ($requiredYear < $year && $requiredSemester === 3) $isPrior = true;
        if ($isPrior && !chair_course_group_is_satisfied($requirement, $passed)) return 'irregular';

        if ($requiredYear === $year
            && $requiredSemester === $currentSemester
            && (string)($requirement['termCode'] ?? '') === $currentTermCode) {
            $currentRequirements[] = $requirement;
        }
    }

    // No current-term curriculum snapshot for this student's year means there
    // is not enough data to assert regularity or irregularity.
    if ($currentSemester !== 3 && !$currentRequirements) return 'notDetermined';

    // Enrollment in a lower-year course is a back subject unless it is a
    // description-and-unit equivalent of one of the current-year requirements.
    foreach ($selectedRows as $row) {
        if ((int)($row['year_level'] ?? 0) >= $year) continue;
        $isCurrentEquivalent = false;
        foreach ($currentRequirements as $requirement) {
            if (chair_course_group_is_satisfied($requirement, [], [$row])) {
                $isCurrentEquivalent = true;
                break;
            }
            if ((int)($row['year_level'] ?? 0) === (int)$requirement['year']
                && isset($requirement['codes'][chair_classification_code((string)($row['sub_code'] ?? ''))])) {
                $isCurrentEquivalent = true;
                break;
            }
        }
        if (!$isCurrentEquivalent) return 'irregular';
    }

    foreach ($currentRequirements as $requirement) {
        if (chair_course_group_is_satisfied($requirement, $passed)
            || chair_course_group_is_satisfied($requirement, $selectedByYear[(int)$requirement['year']] ?? [], $selectedRows)) continue;
        return 'irregular';
    }

    return 'regular';
}
