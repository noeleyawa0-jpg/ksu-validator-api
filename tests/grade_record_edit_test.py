"""Real PHP endpoint tests against an isolated local MariaDB (fictional data).
Usage: python grade_record_edit_test.py --port 33317
"""
import argparse,base64,hashlib,hmac,json,os,pathlib,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.error
p=argparse.ArgumentParser();p.add_argument('--port',type=int,required=True);args=p.parse_args()
assert args.port!=3306
backend=pathlib.Path(__file__).resolve().parents[1]
def sql(query):
 return subprocess.run(['C:/xampp/mysql/bin/mysql.exe','-h','127.0.0.1','-P',str(args.port),'-u','root','-N','-B','--default-character-set=utf8mb4'],input=query,text=True,encoding='utf-8',capture_output=True,check=True).stdout.strip()
sql('''DROP DATABASE IF EXISTS grade_record_edit_test; CREATE DATABASE grade_record_edit_test; USE grade_record_edit_test;
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
 function db():PDO{static $pdo;if(!$pdo)$pdo=new PDO('mysql:host=127.0.0.1;port=' . getenv('LOCAL_DB_PORT') . ';dbname=grade_record_edit_test;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);return $pdo;}
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
  sql("INSERT INTO grade_record_edit_test.academic_records(student_id,sub_code,school_year_taken,term_code,grade,passed) VALUES('LOCAL-FRESH','C1','2024-2025',NULL,'INC',0),('LOCAL-FRESH','C1','2024-2025','25-1','5.00',0),('LOCAL-FRESH','C1','2023-2024','24-1','5.00',0)")
  record=int(sql('SELECT MIN(id) FROM grade_record_edit_test.academic_records'))
  def edit(value,expected='INC',role='admin',program='BSCpE',uid='LOCAL-FRESH',status=200):
   return req('admin_academic_records.php',dict(recordId=record,studentId=uid,grade=value,expectedGrade=expected,termCode='99-3',schoolYearTaken='WRONG',subCode='WRONG',program='BSCpE'),method='PUT',role=role,program=program,expected=status)
  before=sql('SELECT id,student_id,sub_code,school_year_taken,term_code FROM grade_record_edit_test.academic_records ORDER BY id')
  edit('2.00');assert profile('LOCAL-FRESH')['yearLevel']==2
  assert sql('SELECT COUNT(*) FROM grade_record_edit_test.academic_records')=='3'
  assert before==sql('SELECT id,student_id,sub_code,school_year_taken,term_code FROM grade_record_edit_test.academic_records ORDER BY id')
  assert sql('SELECT grade FROM grade_record_edit_test.academic_records WHERE id='+str(record+1))=='5.00'
  print('PASS exact legacy/null-term record corrected; ID, original term, school year, subject, and other attempts preserved')
  edit('1.50',status=409)
  assert sql('SELECT grade FROM grade_record_edit_test.academic_records WHERE id='+str(record))=='2.00'
  edit('2.00')
  edit('INC',expected='2.00',role='chairperson');assert profile('LOCAL-FRESH')['yearLevel']==1
  print('PASS stale edits rejected; identical retry is idempotent; authorized chair can restore INC and recalculate standing')
  edit('2.00',role='chairperson',program='BSIT',status=403)
  edit('2.00',role='student',status=401)
  edit('2.00',uid='LOCAL-2',status=404)
  edit('1.20',status=400)
  req('admin_academic_records.php',dict(recordId=999999,studentId='LOCAL-FRESH',grade='2.00'),method='PUT',expected=404)
  print('PASS chair token program, student denial, record ownership, missing record, and invalid grade enforced')
  sql('RENAME TABLE grade_record_edit_test.student_movements TO grade_record_edit_test.student_movements_unavailable')
  edit('2.00',status=500)
  sql('RENAME TABLE grade_record_edit_test.student_movements_unavailable TO grade_record_edit_test.student_movements')
  assert sql('SELECT grade FROM grade_record_edit_test.academic_records WHERE id='+str(record))=='INC'
  assert profile('LOCAL-FRESH')['yearLevel']==1
  edit('1.50',role='chairperson');assert profile('LOCAL-FRESH')['yearLevel']==2
  assert before==sql('SELECT id,student_id,sub_code,school_year_taken,term_code FROM grade_record_edit_test.academic_records ORDER BY id')
  print('PASS grade and standing update atomically; audit failure rolls both back; successful retry preserves original rows')
 finally:
  server.terminate();server.wait(timeout=10);log.close()
