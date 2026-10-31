<?php
// A standing prerequisite is independent of course prerequisites and applies
// to every enrollment type, including irregular/back-subject enrollment.
function required_subject_standing(array $subject): int {
    return (int)($subject['required_standing'] ?? 0);
}
function subject_standing_allowed(array $student, array $subject, ?array $progress=null): bool {
    $required=required_subject_standing($subject);
    if ((int)$student['year_level'] < $required) return false;
    if ($required<3) return true;
    if (!$progress) return false;
    return $required===3
        ? (($progress['thirdYear']['eligible'] ?? false) || ($progress['fourthYear']['eligible'] ?? false))
        : ($progress['fourthYear']['eligible'] ?? false);
}
