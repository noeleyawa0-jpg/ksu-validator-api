"""Real PHP endpoint tests against an isolated local MariaDB (fictional data).
Usage: python year_standing_test.py --port 33317
"""
import argparse,base64,hashlib,hmac,json,os,pathlib,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.error
p=argparse.ArgumentParser();p.add_argument('--port',type=int,required=True);args=p.parse_args()
assert args.port!=3306
backend=pathlib.Path(__file__).resolve().parents[1]
def sql(query):
 return subprocess.run(['C:/xampp/mysql/bin/mysql.exe','-h','127.0.0.1','-P',str(args.port),'-u','root','-N','-B','--default-character-set=utf8mb4'],input=query,text=True,encoding='utf-8',capture_output=True,check=True).stdout.strip()
sql('''DROP DATABASE IF EXISTS year_standing_test; CREATE DATABASE year_standing_test; USE year_standing_test;
CREATE TABLE academic_terms(term_code VARCHAR(20) PRIMARY KEY,school_year VARCHAR(30),term_name VARCHAR(60),semester INT,is_current INT);
INSERT INTO academic_terms VALUES('26-1','2026–2027','First Semester',1,1),('27-1','2027–2028','First Semester',1,0);
CREATE TABLE curriculum_subjects(curriculum_subject_id BIGINT PRIMARY KEY,sub_code VARCHAR(20),is_active INT DEFAULT 1);
INSERT INTO curriculum_subjects VALUES(1,'C1',1);
CREATE TABLE subjects(subject_id BIGINT PRIMARY KEY AUTO_INCREMENT,curriculum_subject_id BIGINT NULL,term_code VARCHAR(20),program_code VARCHAR(20),sub_code VARCHAR(20),description VARCHAR(200),units DECIMAL(5,1),year_level INT,semester INT,section VARCHAR(30));
INSERT INTO subjects(curriculum_subject_id,term_code,program_code,sub_code,description,units,year_level,semester,section) VALUES
(1,'26-1','BSCpE','C1','Course 1',25,1,1,'BSCpE 1A'),
(1,'26-1','BSCpE','C1-B','Course 1',25,1,1,'BSCpE 1B'),
(NULL,'26-1','BSCpE','C2','Course 2',26,1,1,'BSCpE 1A'),
(NULL,'26-1','BSCpE','C2','Course 2',26,1,1,'BSCpE 1B'),
(NULL,'26-1','BSCpE','C3','Course 3',0.1,1,2,'BSCpE 1A'),
(NULL,'26-1','BSCpE','D1','Course 4',0.1,2,1,'BSCpE 2A'),
(NULL,'26-1','BSCpE','D2','Course 5',0.1,2,2,'BSCpE 2A'),
(NULL,'26-1','BSCpE','E1','Course 6',0.1,3,1,'BSCpE 3A'),
(NULL,'26-1','BSCpE','E2','Course 7',23.6,3,2,'BSCpE 3A'),
(NULL,'26-1','BSCpE','F1','Final year course',12.5,4,1,'BSCpE 4A'),
(NULL,'26-1','BSCpE','F2','Final year course',12.5,4,2,'BSCpE 4A'),
(NULL,'27-1','BSCpE','FUTURE','Not current',99,1,1,'BSCpE 1A'),
(NULL,'26-1','BSIT','OTHER','Other program',99,1,1,'BSIT 1A');
CREATE TABLE users(id VARCHAR(20) PRIMARY KEY,role VARCHAR(20),first_name VARCHAR(50) DEFAULT 'Fictional',last_name VARCHAR(50) DEFAULT 'Student',email VARCHAR(100),department VARCHAR(20) DEFAULT 'CEIT',program VARCHAR(20),year_level INT,year_section VARCHAR(30),enrollment_type VARCHAR(20) DEFAULT 'regular');
INSERT INTO users(id,role,program,year_level,year_section) VALUES ('LOCAL-2','student','BSCpE',2,'BSCpE 2B'),('LOCAL-LOW','student','BSCpE',2,'BSCpE 2A'),('LOCAL-FRESH','student','BSCpE',1,'BSCpE 1A');
CREATE TABLE academic_records(id BIGINT PRIMARY KEY AUTO_INCREMENT,student_id VARCHAR(20),sub_code VARCHAR(20),school_year_taken VARCHAR(30),term_code VARCHAR(20),grade VARCHAR(20),passed INT);
CREATE TABLE student_movements(id BIGINT PRIMARY KEY AUTO_INCREMENT,student_id VARCHAR(20),movement_type VARCHAR(30),from_program VARCHAR(20),to_program VARCHAR(20),from_year_level INT,to_year_level INT,from_section VARCHAR(40),to_section VARCHAR(40),notes VARCHAR(500),performed_by VARCHAR(20),moved_at TIMESTAMP);
''')
def token(role='admin',uid='LOCAL-ADMIN',program='BSCpE'):
 payload=base64.b64encode(json.dumps(dict(sub=uid,role=role,program=program,exp=int(time.time())+3600)).encode()).decode()
 return payload+'.'+hmac.new(b'local-test-secret',payload.encode(),hashlib.sha256).hexdigest()
with tempfile.TemporaryDirectory(prefix='ksu-standing-test-') as temporary:
 root=pathlib.Path(temporary);api=root/'api';shutil.copytree(backend/'api',api)
 (api/'config.php').write_text('''<?php
 header('Content-Type: application/json');define('TOKEN_SECRET','local-test-secret');
 function db():PDO{static $pdo;if(!$pdo)$pdo=new PDO('mysql:host=127.0.0.1;port=' . getenv('LOCAL_DB_PORT') . ';dbname=year_standing_test;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);return $pdo;}
 function json_body():array{return json_decode(file_get_contents('php://input'),true)??[];}
 function respond($data,int $status=200):void{http_response_code($status);echo json_encode($data);exit;}
 function error_response(string $message,int $status=400):void{respond(['error'=>$message],$status);}
 ''')
 with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
 log=open(root/'php.log','w');server=subprocess.Popen(['C:/xampp/php/php.exe','-d','error_reporting=-1','-d','display_errors=1','-S',f'127.0.0.1:{port}','-t',str(api)],env=dict(os.environ,LOCAL_DB_PORT=str(args.port)),stdout=log,stderr=log)
 def req(path,body=None,role='admin',uid='LOCAL-ADMIN',expected=200,program='BSCpE',method=None):
  r=urllib.request.Request(f'http://127.0.0.1:{port}/'+path,headers={'Content-Type':'application/json','Authorization':'Bearer '+token(role,uid,program)},data=json.dumps(body).encode() if body is not None else None,method=method)
  try:
   with urllib.request.urlopen(r,timeout=15) as response:status=response.status;raw=response.read()
  except urllib.error.HTTPError as e:status=e.code;raw=e.read()
  assert status==expected,(path,status,raw)
  try:return json.loads(raw)
  except json.JSONDecodeError:raise AssertionError(raw.decode())
 def profile(uid='LOCAL-2',sync=False):return req('student_profile.php'+('' if sync else '?id='+uid),{'id':uid} if sync else None,role='student',uid=uid)
 def grade(code,value='2.00',uid='LOCAL-2',school='2026–2027'):
  return req('admin_academic_records.php',dict(studentId=uid,subCode=code,schoolYearTaken=school,termCode='26-1',grade=value))
 try:
  time.sleep(.4)
  p=profile();assert p['standingProgress']['thirdYear']['totalUnits']==100
  assert p['standingProgress']['fourthYear']['totalUnits']==100
  grade('C1');grade('C2');p=profile(sync=True)
  assert p['yearLevel']==2 and p['standingProgress']['thirdYear']['passedUnits']==51
  assert not p['standingProgress']['thirdYear']['eligible']
  grade('C1',school='2025–2026');grade('C1-B');p=profile()
  assert p['standingProgress']['thirdYear']['passedUnits']==51
  for value in ['5.00','INC','OD']:grade('D1',value)
  assert profile()['yearLevel']==2
  print('PASS Exactly 51/100 does not qualify; duplicates, sections, retakes and failed/incomplete/dropped grades add no extra units')
  r=grade('C3');assert r['promoted'] and r['yearLevel']==3
  p=profile();assert p['yearLevel']==3 and p['yearSection']=='BSCpE 3B'
  assert p['standingProgress']['thirdYear']['passedUnits']==51.1
  print('PASS 51.1/100 qualifies; saving the final passing grade automatically promotes Year 2 to Year 3')
  grade('D1');grade('D2');assert profile()['yearLevel']==3
  grade('E1');r=grade('E2');assert r['promoted'] and r['yearLevel']==4
  p=profile(sync=True);assert p['yearLevel']==4 and p['yearSection']=='BSCpE 4B'
  assert p['standingProgress']['fourthYear']['passedUnits']==75
  assert sql('SELECT COUNT(*) FROM year_standing_test.student_movements')=='2'
  assert sql("SELECT COUNT(*) FROM year_standing_test.academic_records WHERE student_id='LOCAL-2'")=='9'
  print('PASS exactly 75/100 promotes Year 3 to Year 4; audit saved; grades retained; repeat sync does not promote beyond Year 4')
  grade('C3','5.00');p=profile();assert p['yearLevel']==3 and p['standingProgress']['thirdYear']['passedUnits']==74.9
  grade('C3');assert profile()['yearLevel']==4
  sql("INSERT INTO year_standing_test.users(id,role,program,year_level,year_section) VALUES ('LOCAL-OLD','student','BSCpE',4,'BSCpE 4A')")
  sql("INSERT INTO year_standing_test.academic_records(student_id,sub_code,school_year_taken,term_code,grade,passed) SELECT 'LOCAL-OLD',sub_code,school_year_taken,term_code,grade,passed FROM year_standing_test.academic_records WHERE student_id='LOCAL-2' AND sub_code IN ('C1','C2')")
  assert profile('LOCAL-OLD',True)['yearLevel']==2
  assert sql("SELECT COUNT(*) FROM year_standing_test.student_movements WHERE student_id='LOCAL-OLD' AND movement_type='year_reclassification'")=='1'
  print('PASS 74.9% is third year; exactly 75% is fourth year; old fourth-year label at exactly 51% reclassifies to second with audit')
  # Summer is part of the denominator and does not require a separate policy toggle.
  sql("INSERT INTO year_standing_test.subjects(term_code,program_code,sub_code,description,units,year_level,semester,section) VALUES('26-1','BSCpE','SUM','Required Summer',1,1,3,'BSCpE 1A')")
  p=profile();assert p['standingProgress']['thirdYear']['totalUnits']==101 and p['standingProgress']['fourthYear']['totalUnits']==101
  print('PASS required summer units are included; future terms and other programs excluded')
  # Existing Registrar history is synchronized at profile load, without editing it.
  sql("INSERT INTO year_standing_test.academic_records(student_id,sub_code,school_year_taken,term_code,grade,passed) SELECT 'LOCAL-LOW',sub_code,school_year_taken,term_code,grade,passed FROM year_standing_test.academic_records WHERE student_id='LOCAL-2'")
  assert profile('LOCAL-LOW',True)['yearLevel']==3
  req('student_profile.php',{'id':'LOCAL-2'},role='student',uid='LOCAL-LOW',expected=403)
  req('student_profile.php',{'id':'LOCAL-2'},role='chairperson',uid='LOCAL-CHAIR',program='BSIT',expected=403)
  print('PASS profile sync promotes eligible existing history; account/program isolation enforced')
  # Incomplete required curriculum must never create an artificially low denominator.
  sql("DELETE FROM year_standing_test.subjects WHERE program_code='BSCpE' AND year_level=4 AND semester=2")
  p=profile();assert not p['standingProgress']['fourthYear']['curriculumComplete'] and not p['standingProgress']['fourthYear']['eligible']
  assert profile('LOCAL-FRESH',True)['yearLevel']==1
  print('PASS missing required semester blocks promotion; no invented freshman rule')
  sql("INSERT INTO year_standing_test.subjects(term_code,program_code,sub_code,description,units,year_level,semester,section) VALUES('26-1','BSCpE','F2','Restored final semester',12.5,4,2,'BSCpE 4A')")
  sql("INSERT INTO year_standing_test.subjects(term_code,program_code,sub_code,description,units,year_level,semester,section) VALUES('26-1','BSCpE','NSTP 11','ROTC',3,1,1,'BSCpE 1A'),('26-1','BSCpE','NSTP 11-A','CWTS',3,1,1,'BSCpE 1A')")
  assert profile()['standingProgress']['thirdYear']['totalUnits']==104
  grade('NSTP 11');grade('NSTP 11-A')
  assert profile()['standingProgress']['thirdYear']['passedUnits']==78
  print('PASS alternative NSTP tracks count once in both required and passed units')
  sql("INSERT INTO year_standing_test.users(id,role,program,year_level,year_section) VALUES ('LOCAL-ZERO','student','BSCpE',2,'BSCpE 2A')")
  req('admin_students.php',{'program':'BSCpE'})
  assert profile('LOCAL-ZERO')['yearLevel']==2
  req('admin_students.php',dict(id='LOCAL-ZERO',firstName='Fictional',lastName='Student',program='BSCpE',yearLevel=3,yearSection='BSCpE 3A',enrollmentType='regular'),method='PUT',expected=409)
  print('PASS bulk year advancement cannot bypass whole-curriculum percentage requirements')
  sql("INSERT INTO year_standing_test.users(id,role,program,year_level,year_section) VALUES ('LOCAL-ROLLBACK','student','BSCpE',2,'BSCpE 2A')")
  grade('C1',uid='LOCAL-ROLLBACK');grade('C2',uid='LOCAL-ROLLBACK')
  sql('RENAME TABLE year_standing_test.student_movements TO year_standing_test.student_movements_unavailable')
  req('admin_academic_records.php',dict(studentId='LOCAL-ROLLBACK',subCode='NSTP 11',schoolYearTaken='2026–2027',termCode='26-1',grade='2.00'),expected=500)
  sql('RENAME TABLE year_standing_test.student_movements_unavailable TO year_standing_test.student_movements')
  assert profile('LOCAL-ROLLBACK')['yearLevel']==2
  assert sql("SELECT COUNT(*) FROM year_standing_test.academic_records WHERE student_id='LOCAL-ROLLBACK' AND sub_code='NSTP 11'")=='0'
  print('PASS grade and promotion roll back together if audit storage fails')
  sql("INSERT INTO year_standing_test.subjects(term_code,program_code,sub_code,description,units,year_level,semester,section) VALUES('26-1','BSCpE','C2','Conflicting duplicate units',8,1,1,'BSCpE 1C')")
  assert not profile('LOCAL-ZERO')['standingProgress']['thirdYear']['curriculumComplete']
  print('PASS conflicting section units require curriculum review instead of an unsafe promotion')
  sql("DELETE FROM year_standing_test.subjects WHERE section='BSCpE 1C'; INSERT INTO year_standing_test.users(id,role,program,year_level,year_section) VALUES('LOCAL-ADMIN','admin','BSCpE',1,''),('LOCAL-MIGRATE','student','BSCpE',4,'BSCpE 4A')")
  history=sql('SELECT * FROM year_standing_test.academic_records ORDER BY id')
  for _ in range(2):
   subprocess.run(['C:/xampp/php/php.exe',str(api/'migrate_subject_standing.php')],env=dict(os.environ,LOCAL_DB_PORT=str(args.port)),capture_output=True,check=True)
  assert profile('LOCAL-MIGRATE')['yearLevel']==2
  assert sql("SELECT COUNT(*) FROM year_standing_test.student_movements WHERE student_id='LOCAL-MIGRATE'")=='1'
  assert history==sql('SELECT * FROM year_standing_test.academic_records ORDER BY id')
  print('PASS deployment reclassifies existing students once, records movement audit, and preserves every grade')

 finally:
  server.terminate();server.wait(timeout=10);log.close()
