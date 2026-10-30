"""Read-only grade-catalog integration tests with real PHP and isolated SQLite."""
import base64,hashlib,hmac,json,os,pathlib,shutil,socket,sqlite3,subprocess,tempfile,time,urllib.request,urllib.error
backend=pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='ksu-grade-catalog-') as tmp:
 root=pathlib.Path(tmp);api=root/'api';api.mkdir()
 for name in ['grade_catalog.php','token.php']:shutil.copy2(backend/'api'/name,api/name)
 (api/'config.php').write_text('''<?php
 header('Content-Type: application/json');define('TOKEN_SECRET','isolated-test-secret');
 function db():PDO{static $pdo;if(!$pdo)$pdo=new PDO('sqlite:'.getenv('TEST_DB'),null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);return $pdo;}
 function respond($data,int $status=200):void{http_response_code($status);echo json_encode($data);exit;}
 function error_response(string $message,int $status=400):void{respond(['error'=>$message],$status);}
 ''')
 database=root/'catalog.sqlite'
 with sqlite3.connect(database) as db:
  db.executescript('''CREATE TABLE curricula(program_code TEXT PRIMARY KEY,program_name TEXT,department TEXT,effective_school_year TEXT);
  INSERT INTO curricula VALUES('BSIT','Information Technology','CEIT','26-1'),('BSCpE','Computer Engineering','CEIT','26-1');
  CREATE TABLE subjects(subject_id INTEGER PRIMARY KEY,curriculum_subject_id INTEGER,program_code TEXT,sub_code TEXT,description TEXT,units REAL,year_level INTEGER,semester INTEGER,section TEXT,term_code TEXT,sched_code TEXT DEFAULT 'NA',schedule TEXT DEFAULT 'NA',instructor TEXT DEFAULT 'NA',is_exclusive INTEGER DEFAULT 0,subject_category TEXT DEFAULT 'major');
  INSERT INTO subjects(subject_id,program_code,sub_code,description,units,year_level,semester,section,term_code) VALUES
  (1,'BSIT','PAST-1','First semester',3,1,1,'A','26-1'),
  (2,'BSIT','PAST-1','First semester B',3,1,1,'B','26-1'),
  (3,'BSIT','PAST-1','New term copy',3,1,1,'A','27-1'),
  (4,'BSIT','PAST-2','Second semester',3,1,2,'A','26-2'),
  (5,'BSIT','SUMMER','Summer subject',2,2,3,'A','26-3'),
  (6,'BSCpE','OTHER','Other program',5,1,1,'A','26-1');''')
  before=list(db.iterdump())
 db.close()
 with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
 log=open(root/'php.log','w');server=subprocess.Popen(['C:/xampp/php/php.exe','-d','error_reporting=-1','-d','display_errors=1','-S',f'127.0.0.1:{port}','-t',str(api)],env=dict(os.environ,TEST_DB=str(database)),stdout=log,stderr=log)
 def req(role,program='BSIT',url_program='BSIT',expected=200,method='GET'):
  payload=base64.b64encode(json.dumps({'sub':'LOCAL','role':role,'program':program,'exp':int(time.time())+600}).encode()).decode()
  token=payload+'.'+hmac.new(b'isolated-test-secret',payload.encode(),hashlib.sha256).hexdigest()
  request=urllib.request.Request(f'http://127.0.0.1:{port}/grade_catalog.php?program={url_program}',headers={'Authorization':'Bearer '+token},method=method)
  try:
   with urllib.request.urlopen(request,timeout=10) as r:status=r.status;raw=r.read()
  except urllib.error.HTTPError as e:status=e.code;raw=e.read()
  assert status==expected,(status,raw)
  return json.loads(raw)
 try:
  time.sleep(.3)
  catalog=req('admin');assert [s['subCode'] for s in catalog['subjects']]==['PAST-1','PAST-2','SUMMER']
  assert catalog['subjects'][0]['description']=='First semester'
  assert {s['semester'] for s in catalog['subjects']}=={1,2,3}
  print('PASS historical first/second/summer subjects available once per code across sections and terms')
  chair=req('chairperson',url_program='BSCpE');assert chair['programCode']=='BSIT' and all(s['programCode']=='BSIT' for s in chair['subjects'])
  req('chairperson',program='',expected=403);req('student',expected=403);req('admin',url_program='UNKNOWN',expected=404);req('admin',method='POST',expected=405)
  print('PASS chair program isolation, student denial, and invalid requests handled as JSON')
  with sqlite3.connect(database) as db:assert list(db.iterdump())==before
  db.close()
  print('PASS catalog access changes no database records')
 finally:server.terminate();server.wait(timeout=10);log.close()
