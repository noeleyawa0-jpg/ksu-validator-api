<?php
// GET  /api/section_capacity.php?program=BSIT&yearLevel=2&termCode=26-1
// POST /api/section_capacity.php -> Admin only
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';
$session=current_session();
if (!$session) error_response('Unauthorized',401);
$pdo=db();

if ($_SERVER['REQUEST_METHOD']==='GET') {
    $program=trim((string)($_GET['program']??'')); $year=(int)($_GET['yearLevel']??0); $term=trim((string)($_GET['termCode']??''));
    if($program===''||$year<1||$year>4||$term==='') error_response('program, yearLevel, and termCode are required.');
    if($session['role']==='chairperson' && ($session['program']??'')!==$program) error_response('Program access denied.',403);
    $stmt=$pdo->prepare('SELECT section, capacity FROM section_capacities WHERE term_code=? AND program_code=? AND year_level=? ORDER BY section');
    $stmt->execute([$term,$program,$year]);
    respond(array_map(fn($r)=>['section'=>$r['section'],'capacity'=>(int)$r['capacity']],$stmt->fetchAll()));
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    if(($session['role']??'')!=='admin') error_response('Unauthorized',401);
    $b=json_body(); $term=trim((string)($b['termCode']??'')); $program=trim((string)($b['program']??'')); $year=(int)($b['yearLevel']??0); $section=trim((string)($b['section']??'')); $capacity=(int)($b['capacity']??0);
    if($term===''||$program===''||$year<1||$year>4||$section===''||$capacity<1) error_response('termCode, program, yearLevel, section, and positive capacity are required.');
    $stmt=$pdo->prepare('INSERT INTO section_capacities (term_code, program_code, year_level, section, capacity) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE capacity=VALUES(capacity)');
    $stmt->execute([$term,$program,$year,$section,$capacity]);
    respond(['status'=>'ok']);
}
error_response('Method not allowed',405);
