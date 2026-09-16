<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') error_response('Method not allowed',405);
$session=current_session(); if(!$session) error_response('Unauthorized',401);
if($session['role']==='chairperson') {$program=$session['program']??''; if($program==='') error_response('This chairperson account has no program assigned.',403);}
elseif($session['role']==='admin') {$program=trim($_GET['program']??''); if($program==='') error_response('Missing program parameter.');}
else error_response('Unauthorized',401);
$reqStmt=db()->prepare('SELECT er.* FROM enrollment_requests er JOIN users u ON u.id=er.student_id WHERE u.program=? ORDER BY er.submitted_at DESC');
$reqStmt->execute([$program]); $requests=$reqStmt->fetchAll();
$subStmt=db()->prepare('SELECT rs.*, s.description,s.units,s.schedule,s.section,s.instructor,s.sched_code,s.is_exclusive,s.year_level,s.semester FROM request_subjects rs JOIN subjects s ON s.subject_id=rs.subject_id WHERE rs.request_id=? ORDER BY rs.id');
$prStmt=db()->prepare('SELECT p.sub_code FROM subject_prerequisites sp JOIN subjects p ON p.subject_id=sp.prereq_subject_id WHERE sp.subject_id=? ORDER BY sp.id');
$out=[];
foreach($requests as $r){$subStmt->execute([$r['id']]);$selections=[];foreach($subStmt->fetchAll() as $s){$prStmt->execute([(int)$s['subject_id']]);$selections[]=['subject'=>['subjectId'=>(int)$s['subject_id'],'subCode'=>$s['sub_code'],'schedCode'=>$s['sched_code'],'description'=>$s['description'],'units'=>(float)$s['units'],'schedule'=>$s['schedule'],'section'=>$s['section'],'instructor'=>$s['instructor'],'prerequisites'=>array_column($prStmt->fetchAll(),'sub_code'),'exclusive'=>(bool)$s['is_exclusive'],'yearLevel'=>(int)$s['year_level'],'semester'=>(int)$s['semester']],'localCheck'=>$s['local_check'],'status'=>$s['status'],'chairRemarks'=>$s['chair_remarks'],'overrideApplied'=>(bool)$s['override_applied']];}
$out[]=['id'=>$r['id'],'studentId'=>$r['student_id'],'schoolYear'=>$r['school_year'],'term'=>$r['term'],'type'=>$r['type'],'submittedAt'=>$r['submitted_at'],'selections'=>$selections];}
respond($out);
