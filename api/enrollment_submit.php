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


    $sectionCheck=$pdo->prepare('SELECT COUNT(*) AS c FROM subjects WHERE program_code=? AND year_level=? AND semester=? AND term_code=? AND section=?');
    $sectionCheck->execute([$program,$yearLevel,(int)$currentTerm['semester'],$termCode,$selectedSection]);
    if((int)$sectionCheck->fetch()['c']===0) { $pdo->rollBack(); error_response('Selected section is not available for your program, year level, and current semester.',400); }

    $subjectStmt=$pdo->prepare('SELECT * FROM subjects WHERE subject_id=? AND semester=? AND term_code=? LIMIT 1');
    $prereqStmt=$pdo->prepare('SELECT p.sub_code FROM subject_prerequisites sp JOIN subjects p ON p.subject_id=sp.prereq_subject_id WHERE sp.subject_id=? ORDER BY p.sub_code,p.subject_id');
    $gradeStmt=$pdo->prepare('SELECT grade, passed FROM academic_records WHERE student_id=? AND sub_code=? ORDER BY id DESC LIMIT 1');
    $countOfferingStmt=$pdo->prepare('SELECT COUNT(DISTINCT er.id) AS enrolled FROM enrollment_requests er JOIN request_subjects rs ON rs.request_id=er.id WHERE er.term_code=? AND rs.subject_id=? AND rs.status<>"rejected"');
    $capacityOfferingStmt=$pdo->prepare('SELECT capacity FROM subject_capacities WHERE subject_id=? LIMIT 1');
    $insertStmt=$pdo->prepare('INSERT INTO request_subjects (request_id,sub_code,subject_id,local_check,status) VALUES (?,?,?,?,"pending")');

    $seen=[];
    foreach($subjects as $sub) {
        $subjectId=(int)($sub['subjectId']??0);
        if($subjectId<=0 || isset($seen[$subjectId])) { $pdo->rollBack(); error_response('Each selected subject must include a unique subjectId.',400); }
        $seen[$subjectId]=true;

        $subjectStmt->execute([$subjectId,(int)$currentTerm['semester'],$termCode]);
        $offering=$subjectStmt->fetch();
        if(!$offering){$pdo->rollBack();error_response('One or more selected subjects is not offered in the current semester.',400);}

        // Normal subjects must belong to the student's home program/year/section.
        // Cross-level and cross-program subjects are allowed through the search
        // flow, but they must still be current-semester offerings.
        $isHomeOffering = $offering['program_code'] === $program;
        if($isHomeOffering && ((int)$offering['year_level'] !== $yearLevel || trim($offering['section']) !== $selectedSection)) {
            $pdo->rollBack(); error_response('A home-program subject must belong to your current year level and selected section.',400);
        }

        $prereqStmt->execute([$subjectId]);
        foreach($prereqStmt->fetchAll() as $pr) {
            $gradeStmt->execute([$studentId,$pr['sub_code']]);
            $g=$gradeStmt->fetch();
            if(!$g || !(bool)$g['passed']) {
                $pdo->rollBack(); error_response('You are not eligible for '.$offering['sub_code'].' because prerequisite '.$pr['sub_code'].' is not completed.',409);
            }
        }

        $gradeStmt->execute([$studentId,$offering['sub_code']]);
        $ownGrade=$gradeStmt->fetch();
        if($ownGrade && (bool)$ownGrade['passed']) {
            $pdo->rollBack(); error_response('You already completed '.$offering['sub_code'].'.',409);
        }

        $countOfferingStmt->execute([$termCode,$subjectId]);
        $enrolledOffering=(int)($countOfferingStmt->fetch()['enrolled']??0);
        $capacityOfferingStmt->execute([$subjectId]);
        $capRow=$capacityOfferingStmt->fetch(); $offeringCapacity=$capRow ? (int)$capRow['capacity'] : 40;
        if($enrolledOffering >= $offeringCapacity) {
            $pdo->rollBack(); error_response('The selected subject '.$offering['sub_code'].' is full.',409);
        }

        $insertStmt->execute([$requestId,$offering['sub_code'],$subjectId,$sub['localCheck']??'eligible']);
    }
    $pdo->commit();
} catch(Throwable $e) {
    if($pdo->inTransaction())$pdo->rollBack(); error_log('Enrollment submission failed: '.$e->getMessage()); error_response('Failed to save submission.',500);
}
respond(['status'=>'ok','requestId'=>$requestId],201);
