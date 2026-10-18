<?php
// POST /api/transferee_accreditation.php — pre-evaluate external coursework.
// This endpoint records an evaluation only; it never writes a grade or grants credit automatically.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') error_response('Method not allowed', 405);
$session = current_session();
if (!$session || !in_array(($session['role'] ?? ''), ['admin','chairperson'], true)) error_response('Unauthorized', 401);
$body = json_body();
$studentId = trim((string)($body['studentId'] ?? ''));
$sourceDescription = trim((string)($body['sourceDescription'] ?? ''));
$sourceUnits = (float)($body['sourceUnits'] ?? 0);
$program = trim((string)($body['programCode'] ?? ''));
$schoolYear = trim((string)($body['schoolYearTaken'] ?? ''));
if ($studentId === '' || $sourceDescription === '' || $sourceUnits <= 0 || $program === '') error_response('Student ID, previous subject description, previous units, and target program are required.');
if (($session['role'] ?? '') === 'chairperson' && $program !== trim((string)($session['program'] ?? ''))) error_response('Chairpersons can evaluate only their assigned program.', 403);
$pdo = db();
$stu = $pdo->prepare("SELECT id FROM users WHERE id=? AND role='student' LIMIT 1");
$stu->execute([$studentId]);
if (!$stu->fetch()) error_response('Student account not found.', 404);
$match = $pdo->prepare('SELECT curriculum_subject_id, description, units FROM curriculum_subjects WHERE program_code=? AND LOWER(TRIM(description))=LOWER(TRIM(?)) AND is_active=1 ORDER BY curriculum_subject_id LIMIT 1');
$match->execute([$program, $sourceDescription]);
$local = $match->fetch();
if ($local && $sourceUnits >= (float)$local['units']) {
  $status='eligible_for_review';
  $reason='Description matches the local curriculum and previous units meet or exceed the local requirement. Registrar/chairperson final approval is still required.';
} elseif (!$local) {
  $status='not_eligible';
  $reason='No exact course-description match was found in the selected local curriculum.';
} else {
  $status='not_eligible';
  $reason='Previous subject units are below the local curriculum requirement; partial credit is not accepted.';
}
$ins=$pdo->prepare('INSERT INTO transferee_accreditation_reviews (student_id,source_description,source_units,local_program_code,matched_curriculum_subject_id,local_description,local_units,school_year_taken,evaluation_status,evaluation_reason,evaluated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
$ins->execute([$studentId,$sourceDescription,$sourceUnits,$program,$local['curriculum_subject_id'] ?? null,$local['description'] ?? null,$local['units'] ?? null,$schoolYear,$status,$reason,(string)($session['sub'] ?? 'staff')]);
respond(['status'=>'ok','reviewId'=>(int)$pdo->lastInsertId(),'evaluationStatus'=>$status,'reason'=>$reason,'matchedSubject'=>$local ? ['curriculumSubjectId'=>(int)$local['curriculum_subject_id'],'description'=>$local['description'],'units'=>(float)$local['units']] : null],201);
