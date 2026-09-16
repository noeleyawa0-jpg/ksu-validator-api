<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') error_response('Method not allowed',405);
$session=current_session(); if(!$session || $session['role']!=='chairperson') error_response('Unauthorized',401);
$chairProgram=$session['program']??''; if($chairProgram==='') error_response('This chairperson account has no program assigned.',403);
$body=json_body(); $requestId=$body['requestId']??''; $subjectId=(int)($body['subjectId']??0); $subCode=trim($body['subCode']??''); $status=$body['status']??''; $remarks=$body['remarks']??null; $override=!empty($body['override']);
if($requestId==='' || ($subjectId<=0 && $subCode==='') || !in_array($status,['validated','rejected'],true)) error_response('requestId, subjectId/subCode, and a valid status are required.');
$pdo=db();
$check=$pdo->prepare('SELECT u.program FROM enrollment_requests er JOIN users u ON u.id=er.student_id WHERE er.id=? LIMIT 1');$check->execute([$requestId]);$row=$check->fetch();if(!$row||$row['program']!==$chairProgram) error_response('This request does not belong to your program.',403);
if($subjectId>0){$stmt=$pdo->prepare('UPDATE request_subjects rs JOIN enrollment_requests er ON er.id=rs.request_id JOIN users u ON u.id=er.student_id JOIN subjects s ON s.subject_id=rs.subject_id SET rs.status=?,rs.chair_remarks=?,rs.override_applied=? WHERE rs.request_id=? AND rs.subject_id=? AND u.program=?');$stmt->execute([$status,$remarks,$override?1:0,$requestId,$subjectId,$chairProgram]);}
else{$stmt=$pdo->prepare('UPDATE request_subjects rs JOIN enrollment_requests er ON er.id=rs.request_id JOIN users u ON u.id=er.student_id JOIN subjects s ON s.subject_id=rs.subject_id SET rs.status=?,rs.chair_remarks=?,rs.override_applied=? WHERE rs.request_id=? AND rs.sub_code=? AND u.program=?');$stmt->execute([$status,$remarks,$override?1:0,$requestId,$subCode,$chairProgram]);}
if($stmt->rowCount()===0) error_response('No matching request/subject found.',404);respond(['status'=>'ok']);
