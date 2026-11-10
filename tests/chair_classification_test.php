<?php
require_once __DIR__ . '/../api/chair_classification.php';

function requirement(int $year, int $semester, string $term, array $codes): array {
    return [
        'year' => $year,
        'semester' => $semester,
        'termCode' => $term,
        'codes' => array_fill_keys($codes, true),
        'unitsTenths' => 30,
        'descriptions' => ['course '.strtolower(str_replace(' ', '', $codes[0])) => true],
    ];
}

function expect_classification(string $expected, string $actual, string $label): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "$label: expected $expected, got $actual\n");
        exit(1);
    }
}

$requirements = [
    requirement(1, 1, '27-1', ['CC 101']),
    requirement(1, 1, '27-1', ['NSTP 11', 'NSTP 11-A']),
    requirement(1, 2, '26-2', ['CC 102']),
    requirement(2, 1, '27-1', ['CC 201']),
    requirement(2, 1, '27-1', ['CC 202']),
];
$student = ['year_level' => 2, 'enrollment_type' => 'freshman'];
$passed = ['CC 101' => true, 'NSTP 11' => true, 'CC 102' => true];
$fullCurrentRequest = [
    ['sub_code' => 'CC 201', 'year_level' => 2, 'status' => 'validated'],
    ['sub_code' => 'CC 202', 'year_level' => 2, 'status' => 'pending'],
];

expect_classification('regular', chair_student_classification($student, $requirements, $passed, $fullCurrentRequest, '27-1', 1), 'Complete history and current selection');
expect_classification('irregular', chair_student_classification($student, $requirements, $passed, [$fullCurrentRequest[0]], '27-1', 1), 'Skipped current required subject');
expect_classification('irregular', chair_student_classification($student, $requirements, ['CC 101'=>true, 'NSTP 11'=>true], $fullCurrentRequest, '27-1', 1), 'Unpassed prior-year subject');
expect_classification('irregular', chair_student_classification(['year_level'=>2,'enrollment_type'=>'regular'], $requirements, $passed, array_merge($fullCurrentRequest, [['sub_code'=>'PE 11','year_level'=>1,'status'=>'pending']]), '27-1', 1), 'Back subject');
expect_classification('irregular', chair_student_classification(['year_level'=>2,'enrollment_type'=>'transferee'], $requirements, $passed, $fullCurrentRequest, '27-1', 1), 'Transferee rule');
expect_classification('notDetermined', chair_student_classification($student, [requirement(1, 2, '26-2', ['CC 102'])], $passed, $fullCurrentRequest, '27-1', 1), 'Missing current curriculum snapshot');
expect_classification('regular', chair_student_classification(['year_level'=>2,'enrollment_type'=>'regular'], array_slice($requirements, 0, 3), $passed, [], '27-3', 3), 'Summer term is optional');

echo "Chair classification checks passed.\n";
