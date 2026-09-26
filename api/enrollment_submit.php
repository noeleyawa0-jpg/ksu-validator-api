<?php
// api/enrollment_submit.php -> POST /api/enrollment_submit.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') error_response('Method not allowed', 405);
$session=current_session();
if (!$session || ($session['role']??'')!=='student') error_response('Unauthorized',401);

$body=json_body();
$requestId=$body['id'] ?? ('REQ-'.time().random_int(100,999));
$studentId=trim((string)($body['studentId']??''));
$schoolYear=trim((string)($body['schoolYear']??''));
$term=trim((string)($body['term']??''));
$termCode=trim((string)($body['termCode']??''));
$selectedSection=trim((string)($body['section']??''));
$type=$body['type']??'regular';
$subjects=$body['subjects']??[];

if (($session['sub']??'')==='' || $studentId!==$session['sub']) error_response('Student account mismatch.',403);
if($studentId===''||$schoolYear===''||$term===''||$termCode===''||$selectedSection===''||empty($subjects)) error_response('studentId, schoolYear, term, termCode, section, and at least one subject are required.');

$pdo=db();
$pdo->beginTransaction();
try {
    $studentStmt=$pdo->prepare('SELECT program, year_level FROM users WHERE id=? AND role="student" LIMIT 1');
    $studentStmt->execute([$studentId]); $student=$studentStmt->fetch();
    if(!$student||!$student['program']) error_response('Student program not found.',400);
    $program=$student['program']; $yearLevel=(int)$student['year_level'];

    $termStmt=$pdo->prepare('SELECT term_code, school_year, term_name, semester FROM academic_terms WHERE term_code=? AND is_current=1 LIMIT 1');
    $termStmt->execute([$termCode]); $currentTerm=$termStmt->fetch();
    if(!$currentTerm) { $pdo->rollBack(); error_response('The selected academic term is no longer current. Refresh the app and try again.',409); }
    if($currentTerm['school_year']!==$schoolYear || $currentTerm['term_name']!==$term) { $pdo->rollBack(); error_response('Academic term information is out of date. Refresh the app.',409); }

    $existingStmt=$pdo->prepare('SELECT id FROM enrollment_requests WHERE student_id=? AND term_code=? ORDER BY submitted_at ASC LIMIT 1 FOR UPDATE');
    $existingStmt->execute([$studentId,$termCode]);
    if($existingStmt->fetch()) { $pdo->rollBack(); error_response('You already submitted a pre-enrollment request for this term. Please use Tracking to view its status.',409); }

    $capacityStmt=$pdo->prepare('SELECT capacity FROM section_capacities WHERE term_code=? AND program_code=? AND year_level=? AND section=? LIMIT 1');
    $capacityStmt->execute([$termCode,$program,$yearLevel,$selectedSection]);
    $capacityRow=$capacityStmt->fetch(); $capacity=$capacityRow ? (int)$capacityRow['capacity'] : 40;
    $countStmt=$pdo->prepare('SELECT COUNT(DISTINCT er.id) AS enrolled FROM enrollment_requests er JOIN users u ON u.id=er.student_id WHERE er.term_code=? AND er.selected_section=? AND u.program=? AND u.year_level=? AND EXISTS (SELECT 1 FROM request_subjects rs WHERE rs.request_id=er.id AND rs.status<>"rejected")');
    $countStmt->execute([$termCode,$selectedSection,$program,$yearLevel]);
    $enrolled=(int)($countStmt->fetch()['enrolled']??0);
    if($enrolled >= $capacity) { $pdo->rollBack(); error_response("Section {$selectedSection} is full ({$capacity} slots). Please choose another section.",409); }

    $sectionCheck=$pdo->prepare('SELECT COUNT(*) AS c FROM subjects WHERE program_code=? AND year_level=? AND semester=? AND section=?');
    $sectionCheck->execute([$program,$yearLevel,(int)$currentTerm['semester'],$selectedSection]);
    if((int)$sectionCheck->fetch()['c']===0) { $pdo->rollBack(); error_response('Selected section is not available for this program, year level, and semester.',400); }

    $reqStmt=$pdo->prepare('INSERT INTO enrollment_requests (id,student_id,school_year,term,term_code,selected_section,type) VALUES (?,?,?,?,?,?,?)');
    $reqStmt->execute([$requestId,$studentId,$schoolYear,$term,$termCode,$selectedSection,$type]);

    $subStmt=$pdo->prepare('INSERT INTO request_subjects (request_id,sub_code,subject_id,local_check,status) SELECT ?,sub_code,subject_id,?,"pending" FROM subjects WHERE subject_id=? AND program_code=? AND section=? LIMIT 1');
    foreach($subjects as $sub) {
        $subjectId=(int)($sub['subjectId']??0); if($subjectId<=0){$pdo->rollBack();error_response('Each selected subject must include subjectId.',400);}
        $subStmt->execute([$requestId,$sub['localCheck']??'eligible',$subjectId,$program,$selectedSection]);
        if($subStmt->rowCount()!==1){$pdo->rollBack();error_response('One or more selected subjects does not belong to your selected section.',400);}
    }
    $pdo->commit();
} catch(Throwable $e) {
    if($pdo->inTransaction())$pdo->rollBack(); error_log('Enrollment submission failed: '.$e->getMessage()); error_response('Failed to save submission.',500);
}
respond(['status'=>'ok','requestId'=>$requestId],201);
