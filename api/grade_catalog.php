<?php
// Historical grade entry uses academic codes across terms, never enrollable lists.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') error_response('Method not allowed',405);
$session=current_session();
if (!$session || !in_array($session['role'] ?? '',['admin','chairperson'],true)) error_response('Staff access required.',403);
$program=trim((string)(($session['role'] ?? '')==='chairperson' ? ($session['program'] ?? '') : ($_GET['program'] ?? '')));
if ($program==='') error_response('Program is required.',403);
$pdo=db();
$stmt=$pdo->prepare('SELECT * FROM curricula WHERE program_code=? LIMIT 1');$stmt->execute([$program]);$curriculum=$stmt->fetch();
if (!$curriculum) error_response('Curriculum not found.',404);
// academic_records is keyed by sub_code. Match its existing oldest-definition
// lookup and include each academic code once, regardless of sections/terms.
$stmt=$pdo->prepare('SELECT s.* FROM subjects s JOIN
    (SELECT sub_code,MIN(subject_id) AS subject_id FROM subjects WHERE program_code=? GROUP BY sub_code) c
    ON c.subject_id=s.subject_id ORDER BY s.sub_code');
$stmt->execute([$program]);
$subjects=array_map(static function($s) {
    return [
        'subjectId'=>(int)$s['subject_id'],'curriculumSubjectId'=>!empty($s['curriculum_subject_id'])?(int)$s['curriculum_subject_id']:null,
        'programCode'=>$s['program_code'],'subCode'=>$s['sub_code'],'description'=>$s['description'],'units'=>(float)$s['units'],
        'schedCode'=>$s['sched_code'],'schedule'=>$s['schedule'],'section'=>$s['section'],'instructor'=>$s['instructor'],
        'exclusive'=>(bool)$s['is_exclusive'],'subjectCategory'=>$s['subject_category'] ?? 'major',
        'yearLevel'=>(int)$s['year_level'],'semester'=>(int)$s['semester'],'prerequisites'=>[],
    ];
},$stmt->fetchAll());
respond(['programCode'=>$program,'programName'=>$curriculum['program_name'],'department'=>$curriculum['department'],
    'effectiveSchoolYear'=>$curriculum['effective_school_year'],'subjects'=>$subjects]);
