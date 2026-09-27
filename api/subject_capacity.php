<?php
// GET  /api/subject_capacity.php?program=BSIT&yearLevel=4&termCode=26-1
// POST /api/subject_capacity.php -> Admin only
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';

$session=current_session();
if(!$session) error_response('Unauthorized',401);
$pdo=db();

if($_SERVER['REQUEST_METHOD']==='GET'){
    $program=trim((string)($_GET['program']??''));
    $year=(int)($_GET['yearLevel']??0);
    $term=trim((string)($_GET['termCode']??''));
    if($program===''||$year<1||$year>4||$term==='') error_response('program, yearLevel, and termCode are required.');
    if(($session['role']??'')==='chairperson' && ($session['program']??'')!==$program) error_response('Program access denied.',403);

    $stmt=$pdo->prepare('SELECT s.subject_id,s.sub_code,s.description,s.section,s.semester,s.program_code,s.year_level,
        COALESCE(sc.capacity,40) AS capacity,
        (SELECT COUNT(DISTINCT er.id) FROM enrollment_requests er JOIN request_subjects rs ON rs.request_id=er.id
         WHERE er.term_code=? AND rs.subject_id=s.subject_id AND rs.status<>"rejected") AS enrolled
        FROM subjects s LEFT JOIN subject_capacities sc ON sc.subject_id=s.subject_id
        WHERE s.term_code=? AND s.program_code=? AND s.year_level=?
        ORDER BY s.semester,s.section,s.sub_code,s.subject_id');
    $stmt->execute([$term,$term,$program,$year]);
    $out=[];
    foreach($stmt->fetchAll() as $r){
        $cap=(int)$r['capacity']; $en=(int)$r['enrolled'];
        $out[]=[
            'subjectId'=>(int)$r['subject_id'],'subCode'=>$r['sub_code'],'description'=>$r['description'],
            'section'=>$r['section'],'semester'=>(int)$r['semester'],'programCode'=>$r['program_code'],
            'yearLevel'=>(int)$r['year_level'],'capacity'=>$cap,'enrolled'=>$en,
            'available'=>max(0,$cap-$en),'full'=>$en>=$cap
        ];
    }
    respond($out);
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(($session['role']??'')!=='admin') error_response('Admin access required.',403);
    $b=json_body();
    $subjectId=(int)($b['subjectId']??0); $capacity=(int)($b['capacity']??0);
    if($subjectId<=0||$capacity<1) error_response('subjectId and positive capacity are required.');
    $stmt=$pdo->prepare('SELECT subject_id,term_code FROM subjects WHERE subject_id=? LIMIT 1');
    $stmt->execute([$subjectId]); $subject=$stmt->fetch();
    if(!$subject) error_response('Subject offering not found.',404);
    $up=$pdo->prepare('INSERT INTO subject_capacities(subject_id,term_code,capacity) VALUES(?,?,?) ON DUPLICATE KEY UPDATE capacity=VALUES(capacity), term_code=VALUES(term_code)');
    $up->execute([$subjectId,$subject['term_code'],$capacity]);
    respond(['status'=>'ok','subjectId'=>$subjectId,'capacity'=>$capacity]);
}
error_response('Method not allowed',405);
