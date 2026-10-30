"""Real PHP/MySQL retry and authorization tests; use isolated local MariaDB."""
import argparse,base64,hashlib,hmac,json,os,pathlib,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.error
p=argparse.ArgumentParser();p.add_argument('--port',type=int,required=True);a=p.parse_args();assert a.port!=3306
backend=pathlib.Path(__file__).resolve().parents[1]
def sql(q):return subprocess.run(['C:/xampp/mysql/bin/mysql.exe','-h','127.0.0.1','-P',str(a.port),'-u','root','-N','-B'],input=q,text=True,capture_output=True,check=True).stdout.strip()
sql('''DROP DATABASE IF EXISTS validation_action_test; CREATE DATABASE validation_action_test; USE validation_action_test;
CREATE TABLE enrollment_requests(id VARCHAR(30) PRIMARY KEY);
CREATE TABLE subjects(subject_id BIGINT PRIMARY KEY,program_code VARCHAR(20));
CREATE TABLE request_subjects(request_id VARCHAR(30),subject_id BIGINT,status VARCHAR(20),chair_remarks TEXT,override_applied INT,PRIMARY KEY(request_id,subject_id));
INSERT INTO enrollment_requests VALUES('LOCAL');INSERT INTO subjects VALUES(1,'BSIT'),(2,'BSCpE');
INSERT INTO request_subjects VALUES('LOCAL',1,'pending',NULL,0),('LOCAL',2,'pending',NULL,0);''')
with tempfile.TemporaryDirectory(prefix='ksu-validation-test-') as tmp:
 root=pathlib.Path(tmp);api=root/'api';api.mkdir()
 for name in ['validation_action.php','token.php']:shutil.copy2(backend/'api'/name,api/name)
 (api/'config.php').write_text('''<?php
 header('Content-Type: application/json');define('TOKEN_SECRET','isolated-secret');
 function db():PDO{static $pdo;if(!$pdo)$pdo=new PDO('mysql:host=127.0.0.1;port='.getenv('LOCAL_DB_PORT').';dbname=validation_action_test','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);return $pdo;}
 function json_body():array{return json_decode(file_get_contents('php://input'),true)??[];}
 function respond($d,int $s=200):void{http_response_code($s);echo json_encode($d);exit;}
 function error_response(string $m,int $s=400):void{respond(['error'=>$m],$s);}
 ''')
 with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
 log=open(root/'php.log','w');server=subprocess.Popen(['C:/xampp/php/php.exe','-d','error_reporting=-1','-d','display_errors=1','-S',f'127.0.0.1:{port}','-t',str(api)],env=dict(os.environ,LOCAL_DB_PORT=str(a.port)),stdout=log,stderr=log)
 def req(subject=1,program='BSIT',role='chairperson',status='validated',remarks=None,override=False,expected=200):
  payload=base64.b64encode(json.dumps({'sub':'LOCAL','role':role,'program':program,'exp':int(time.time())+600}).encode()).decode()
  token=payload+'.'+hmac.new(b'isolated-secret',payload.encode(),hashlib.sha256).hexdigest()
  r=urllib.request.Request(f'http://127.0.0.1:{port}/validation_action.php',headers={'Content-Type':'application/json','Authorization':'Bearer '+token},data=json.dumps(dict(requestId='LOCAL',subjectId=subject,program='BSCpE',status=status,remarks=remarks,override=override)).encode())
  try:
   with urllib.request.urlopen(r,timeout=10) as response:code=response.status;raw=response.read()
  except urllib.error.HTTPError as e:code=e.code;raw=e.read()
  assert code==expected,(code,raw);return json.loads(raw)
 try:
  time.sleep(.3)
  assert req()['status']=='ok';assert req()['status']=='ok'
  assert req(status='rejected',remarks='Review required')['status']=='ok'
  assert req(status='rejected',remarks='Review required')['status']=='ok'
  print('PASS repeated identical validation/rejection is successful, including MySQL zero-changed-row retries')
  req(subject=2,expected=403);req(role='student',expected=401);req(role='admin',expected=401)
  req(subject=2,program='BSCpE',override=True,remarks='Approved exception')
  assert sql('SELECT COUNT(*) FROM validation_action_test.request_subjects')=='2'
  print('PASS chair offering ownership enforced; client program ignored; other roles denied; records retained')
 finally:server.terminate();server.wait(timeout=10);log.close()
