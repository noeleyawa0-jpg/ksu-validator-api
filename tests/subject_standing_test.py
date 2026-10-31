"""Exercise real PHP endpoints against a disposable LOCAL MariaDB schema.
Usage: python subject_standing_test.py --port 33317 --snapshot PATH
The server at this port must be an isolated local test instance, not production.
"""
import argparse, base64, hashlib, hmac, json, os, pathlib, shutil, socket
import subprocess, tempfile, time, urllib.request, urllib.error

p = argparse.ArgumentParser()
p.add_argument('--port', type=int, required=True)
p.add_argument('--snapshot', required=True)
a = p.parse_args()
assert a.port != 3306, 'Use an isolated MariaDB instance.'
backend = pathlib.Path(__file__).resolve().parents[1]
mysql = 'C:/xampp/mysql/bin/mysql.exe'
php = 'C:/xampp/php/php.exe'
def sql(query):
    r = subprocess.run([mysql, '-h', '127.0.0.1', '-P', str(a.port), '-u', 'root', '-N', '-B', '--default-character-set=utf8mb4'],
                       input=query, text=True, encoding='utf-8', capture_output=True, check=True)
    return r.stdout.strip()
def quote(value):
    if value is None: return 'NULL'
    return "'" + str(value).replace('\\', '\\\\').replace("'", "''") + "'"
def insert(table, row):
    sql('INSERT INTO subject_standing_test.' + table + ' (' + ','.join(row) + ') VALUES (' + ','.join(map(quote, row.values())) + ')')
sql('''DROP DATABASE IF EXISTS subject_standing_test; CREATE DATABASE subject_standing_test;
USE subject_standing_test;
CREATE TABLE academic_terms(term_code VARCHAR(20) PRIMARY KEY,school_year VARCHAR(30),term_name VARCHAR(60),semester INT,is_current INT,start_date DATE,end_date DATE);
CREATE TABLE curricula(program_code VARCHAR(20) PRIMARY KEY,program_name VARCHAR(200),department VARCHAR(20),effective_school_year VARCHAR(20));
CREATE TABLE curriculum_subjects(curriculum_subject_id BIGINT PRIMARY KEY AUTO_INCREMENT,program_code VARCHAR(20),sub_code VARCHAR(20),description VARCHAR(200),units DECIMAL(4,1),year_level INT,semester INT,is_active INT DEFAULT 1,legacy_subject_id BIGINT NULL);
CREATE TABLE subjects(subject_id BIGINT PRIMARY KEY AUTO_INCREMENT,curriculum_subject_id BIGINT NULL,term_code VARCHAR(20),sub_code VARCHAR(20),program_code VARCHAR(20),sched_code VARCHAR(100),description VARCHAR(200),units DECIMAL(4,1),schedule VARCHAR(100),section VARCHAR(30),instructor VARCHAR(100),is_exclusive INT,year_level INT,semester INT,subject_category VARCHAR(10),required_standing INT NOT NULL DEFAULT 0);
CREATE TABLE subject_prerequisites(subject_id BIGINT,prereq_subject_id BIGINT,sub_code VARCHAR(20),prereq_sub_code VARCHAR(20),PRIMARY KEY(subject_id,prereq_subject_id));
CREATE TABLE subject_capacities(subject_id BIGINT PRIMARY KEY,term_code VARCHAR(20),capacity INT);
CREATE TABLE section_capacities(term_code VARCHAR(20),program_code VARCHAR(20),year_level INT,section VARCHAR(30),capacity INT,PRIMARY KEY(term_code,program_code,year_level,section));
CREATE TABLE users(id VARCHAR(30) PRIMARY KEY,role VARCHAR(20),program VARCHAR(20),year_level INT,enrollment_type VARCHAR(20));
CREATE TABLE academic_records(id BIGINT PRIMARY KEY AUTO_INCREMENT,student_id VARCHAR(30),term_code VARCHAR(20),sub_code VARCHAR(20),grade VARCHAR(10),passed INT);
CREATE TABLE enrollment_requests(id VARCHAR(50) PRIMARY KEY,student_id VARCHAR(30),school_year VARCHAR(30),term VARCHAR(60),term_code VARCHAR(20),selected_section VARCHAR(30),type VARCHAR(20),submitted_at DATETIME);
CREATE TABLE request_subjects(request_id VARCHAR(50),sub_code VARCHAR(20),subject_id BIGINT,local_check VARCHAR(30),status VARCHAR(30));
''')
snapshot = json.loads(pathlib.Path(a.snapshot).read_text())
for t in snapshot['terms']:
    insert('academic_terms',dict(term_code=t['termCode'],school_year=t['schoolYear'],term_name=t['term'],semester=t['semester'],is_current=int(t['termCode']=='26-1')))
seen_cs = set()
seed = []
for entry in snapshot['curricula']:
    c = entry['data']; program = entry['program']
    if entry['term'] == '26-1':
        insert('curricula',dict(program_code=program,program_name=c['programName'],department=c['department'],effective_school_year=c['effectiveSchoolYear']))
    for s in c['subjects']:
        cid=s.get('curriculumSubjectId')
        if cid and cid not in seen_cs:
            insert('curriculum_subjects',dict(curriculum_subject_id=cid,program_code=program,sub_code=s['subCode'],description=s['description'],units=s['units'],year_level=s['yearLevel'],semester=s['semester']))
            seen_cs.add(cid)
        insert('subjects',dict(subject_id=s['subjectId'],curriculum_subject_id=cid,term_code=entry['term'],sub_code=s['subCode'],program_code=program,sched_code=s['schedCode'],description=s['description'],units=s['units'],schedule=s['schedule'],section=s['section'],instructor=s['instructor'],is_exclusive=int(s['exclusive']),year_level=s['yearLevel'],semester=s['semester'],subject_category=s.get('subjectCategory','major')))
        seed.append((entry['term'],program,s))
for term, program, s in seed:
    for code in s['prerequisites']:
        choices=[x for t,p,x in seed if t==term and p==program and x['subCode']==code]
        if not choices: choices=[x for t,p,x in seed if p==program and x['subCode']==code]
        if choices:
            insert('subject_prerequisites',dict(subject_id=s['subjectId'],prereq_subject_id=choices[0]['subjectId'],sub_code=s['subCode'],prereq_sub_code=code))
insert('users',dict(id='LOCAL-STUDENT',role='student',program='BSIT',year_level=1,enrollment_type='regular'))

def token(role):
    payload=base64.b64encode(json.dumps(dict(sub='LOCAL-STUDENT' if role=='student' else 'LOCAL-ADMIN',role=role,program='BSIT',exp=int(time.time())+3600)).encode()).decode()
    return payload+'.'+hmac.new(b'local-test-secret',payload.encode(),hashlib.sha256).hexdigest()

with tempfile.TemporaryDirectory(prefix='ksu-term-http-') as tmp:
    root=pathlib.Path(tmp);api=root/'api';shutil.copytree(backend/'api',api)
    (api/'config.php').write_text('''<?php
    header('Content-Type: application/json');define('TOKEN_SECRET','local-test-secret');
    function db(): PDO {static $pdo;if(!$pdo)$pdo=new PDO('mysql:host=127.0.0.1;port=' . getenv('LOCAL_DB_PORT') . ';dbname=subject_standing_test;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);return $pdo;}
    function json_body(): array{return json_decode(file_get_contents('php://input'),true)??[];}
    function respond($data,int $status=200): void{http_response_code($status);echo json_encode($data);exit;}
    function error_response(string $message,int $status=400):void{respond(['error'=>$message],$status);}
    ''')
    with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
    log=open(root/'php.log','w')
    server=subprocess.Popen([php,'-d','error_reporting=-1','-d','display_errors=1','-S',f'127.0.0.1:{port}','-t',str(api)],env=dict(os.environ,LOCAL_DB_PORT=str(a.port)),stdout=log,stderr=log)
    def req(path,body=None,role='admin',expected=200,method=None):
        h={'Content-Type':'application/json','Authorization':'Bearer '+token(role)}
        r=urllib.request.Request(f'http://127.0.0.1:{port}/'+path,headers=h,data=json.dumps(body).encode() if body is not None else None,method=method)
        try:
            with urllib.request.urlopen(r,timeout=30) as response:status=response.status;raw=response.read()
        except urllib.error.HTTPError as e:status=e.code;raw=e.read()
        assert status==expected,(path,status,raw)
        try: return json.loads(raw)
        except json.JSONDecodeError: raise AssertionError((path,raw.decode()))
    def set_term(code):
        # Deliberately send the wrong semester/name: server must use the code.
        return req('admin_term.php',dict(termCode=code,schoolYear=f'20{code[:2]}–20{int(code[:2])+1}',term='Wrong client label',semester=1))
    try:
        time.sleep(.4)
        sql("ALTER TABLE subject_standing_test.subjects DROP COLUMN required_standing; ALTER TABLE subject_standing_test.users ADD year_section VARCHAR(30); INSERT INTO subject_standing_test.users(id,role,program,year_level) VALUES('LOCAL-ADMIN','admin','BSIT',1)")
        sql("CREATE TABLE subject_standing_test.student_movements(id BIGINT PRIMARY KEY AUTO_INCREMENT,student_id VARCHAR(30),movement_type VARCHAR(30),from_program VARCHAR(20),to_program VARCHAR(20),from_year_level INT,to_year_level INT,from_section VARCHAR(40),to_section VARCHAR(40),notes VARCHAR(500),performed_by VARCHAR(30),moved_at TIMESTAMP)")
        before=sql('SELECT subject_id,sub_code,description,units,term_code FROM subject_standing_test.subjects ORDER BY subject_id')
        prereqs=sql('SELECT * FROM subject_standing_test.subject_prerequisites ORDER BY subject_id,prereq_subject_id')
        for _ in range(2):
            subprocess.run([php,str(api/'migrate_subject_standing.php')],env=dict(os.environ,LOCAL_DB_PORT=str(a.port)),capture_output=True,check=True)
        assert before==sql('SELECT subject_id,sub_code,description,units,term_code FROM subject_standing_test.subjects ORDER BY subject_id')
        assert prereqs==sql('SELECT * FROM subject_standing_test.subject_prerequisites ORDER BY subject_id,prereq_subject_id')
        assert sql('SELECT SUM(required_standing) FROM subject_standing_test.subjects')=='0'
        print('PASS additive migration is idempotent and assigns no sample prerequisites or standing restrictions')
        sql('CREATE TABLE subject_standing_test.term_offering_rollovers(term_code VARCHAR(20),program_code VARCHAR(20),source_term_code VARCHAR(20),PRIMARY KEY(term_code,program_code))')
        catalog=req('curriculum.php?program=BSCE&view=catalog')['subjects']
        section2=next(s['section'] for s in catalog if s['yearLevel']==2 and s['semester']==1)
        section4=next(s['section'] for s in catalog if s['yearLevel']==4 and s['semester']==1)
        target=next(s for s in catalog if s['subCode']=="Eng'g Mgt 11" and s['termCode']=='26-1')
        target['programCode']='BSCE';target['requiredStanding']=3
        req('admin_subjects.php',target)
        assert next(s for s in req('curriculum.php?program=BSCE')['subjects'] if s['subjectId']==target['subjectId'])['requiredStanding']==3
        target.pop('requiredStanding');req('admin_subjects.php',target)
        assert sql('SELECT required_standing FROM subject_standing_test.subjects WHERE subject_id='+str(target['subjectId']))=='3'
        bad=dict(target,requiredStanding=5);req('admin_subjects.php',bad,expected=400)
        print('PASS Admin saves standing prerequisites; older clients cannot erase an existing value; invalid levels rejected')
        for classification in ['regular','freshman','irregular','transferee','shifter','returnee','crossEnroll']:
            sql("UPDATE subject_standing_test.users SET program='BSCE',year_level=2,enrollment_type='"+classification+"',year_section='BSCE 2A' WHERE id='LOCAL-STUDENT'")
            found=req('subject_search.php?q=Management',role='student')['results']
            result=next(s for s in found if s['subject']['subjectId']==target['subjectId'])
            assert not result['eligible'] and 'standing' in result['eligibilityReason']
            body=dict(id='LOCAL-BLOCKED',studentId='LOCAL-STUDENT',schoolYear='2026–2027',term='First Semester',termCode='26-1',section=section2,type=classification,yearLevel=4,subjects=[dict(subjectId=target['subjectId'],localCheck='eligible',requiredStanding=0)])
            denial=req('enrollment_submit.php',body,role='student',expected=409);assert 'standing' in denial['error']
            assert sql('SELECT COUNT(*) FROM subject_standing_test.enrollment_requests')=='0'
        print('PASS all seven enrollment types are blocked below standing; forged client year/standing ignored; no partial requests')
        sql("UPDATE subject_standing_test.users SET year_level=4,enrollment_type='irregular',year_section='BSCE 4A' WHERE id='LOCAL-STUDENT'")
        result=next(s for s in req('subject_search.php?q=Management',role='student')['results'] if s['subject']['subjectId']==target['subjectId'])
        assert not result['eligible']
        # A whole-course passing history except the candidate earns >=75% without already passing the candidate.
        sql("INSERT INTO subject_standing_test.academic_records(student_id,term_code,sub_code,grade,passed) SELECT 'LOCAL-STUDENT','26-1',sub_code,'2.00',1 FROM subject_standing_test.subjects WHERE program_code='BSCE' AND term_code='26-1' AND sub_code<>\"Eng'g Mgt 11\" GROUP BY sub_code")
        result=next(s for s in req('subject_search.php?q=Management',role='student')['results'] if s['subject']['subjectId']==target['subjectId'])
        assert result['eligible'],result
        body.update(id='LOCAL-ALLOWED',section=section4,type='irregular')
        req('enrollment_submit.php',body,role='student',expected=201)
        print('PASS recorded Year 4 alone cannot bypass earned units; earned senior standing satisfies third-year back-subject requirement')
        # Copy requirement with the offering, including new Section B and term rollover.
        duplicated=req('admin_duplicate_section.php',dict(sourceSubjectId=target['subjectId'],program='BSCE'))
        code=target['subCode']+'-B'
        copied=sql("SELECT required_standing FROM subject_standing_test.subjects WHERE sub_code=\""+code+"\" AND program_code='BSCE' AND term_code='26-1'")
        assert copied=='3',duplicated
        set_term('27-1')
        assert sql("SELECT MIN(required_standing) FROM subject_standing_test.subjects WHERE sub_code=\""+target['subCode']+"\" AND program_code='BSCE' AND term_code='27-1'")=='3'
        print('PASS standing prerequisite survives Section B duplication and next-year term rollover')
    finally:
        server.terminate();server.wait(timeout=10);log.close()
