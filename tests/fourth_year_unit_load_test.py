"""Exercise real PHP endpoints against a disposable LOCAL MariaDB schema.
Usage: python fourth_year_load_test.py --port 33317 --snapshot PATH
The server at this port must be an isolated local test instance, not production.
"""
import argparse, base64, hashlib, hmac, json, os, pathlib, shutil, socket
import subprocess, tempfile, time, urllib.request, urllib.error

p = argparse.ArgumentParser()
p.add_argument('--port', type=int, required=True)
p.add_argument('--snapshot')
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
    sql('INSERT INTO fourth_year_load_test.' + table + ' (' + ','.join(row) + ') VALUES (' + ','.join(map(quote, row.values())) + ')')
sql('''DROP DATABASE IF EXISTS fourth_year_load_test; CREATE DATABASE fourth_year_load_test;
USE fourth_year_load_test;
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
sql("""USE fourth_year_load_test;
INSERT INTO academic_terms(term_code,school_year,term_name,semester,is_current) VALUES('26-1','2026–2027','First Semester',1,1),('26-2','2026–2027','Second Semester',2,0),('26-3','2026–2027','Summer / Special Term',3,0);
INSERT INTO curricula VALUES('BSIT','Fictional IT','CEIT','26-1');
INSERT INTO users VALUES('LOCAL-STUDENT','student','BSIT',4,'regular');
INSERT INTO subjects(subject_id,term_code,sub_code,program_code,description,units,section,year_level,semester,subject_category) VALUES
(1,'26-1','C1','BSIT','Local load fixture',15,'LOCAL-A',4,1,'major'),
(2,'26-1','C2','BSIT','Local load fixture',15,'LOCAL-A',4,1,'major'),
(3,'26-1','C3','BSIT','Local load fixture',0.1,'LOCAL-A',4,1,'minor'),
(4,'26-1','C4','BSIT','Local load fixture',26,'LOCAL-A',4,1,'minor');
""")

def token(role):
    payload=base64.b64encode(json.dumps(dict(sub='LOCAL-STUDENT' if role=='student' else 'LOCAL-ADMIN',role=role,program='BSIT',exp=int(time.time())+3600)).encode()).decode()
    return payload+'.'+hmac.new(b'local-test-secret',payload.encode(),hashlib.sha256).hexdigest()

with tempfile.TemporaryDirectory(prefix='ksu-term-http-') as tmp:
    root=pathlib.Path(tmp);api=root/'api';shutil.copytree(backend/'api',api)
    (api/'config.php').write_text('''<?php
    header('Content-Type: application/json');define('TOKEN_SECRET','local-test-secret');
    function db(): PDO {static $pdo;if(!$pdo)$pdo=new PDO('mysql:host=127.0.0.1;port=' . getenv('LOCAL_DB_PORT') . ';dbname=fourth_year_load_test;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);return $pdo;}
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
        def enroll(ids,expected=201,term='26-1'):
            sql('DELETE FROM fourth_year_load_test.request_subjects; DELETE FROM fourth_year_load_test.enrollment_requests')
            label={'26-1':'First Semester','26-2':'Second Semester','26-3':'Summer / Special Term'}[term]
            result=req('enrollment_submit.php',dict(id='LOCAL-LOAD',studentId='LOCAL-STUDENT',schoolYear='2026–2027',term=label,termCode=term,section='LOCAL-A',type='irregular',yearLevel=4,subjects=[dict(subjectId=i,units=0,localCheck='eligible') for i in ids]),role='student',expected=expected)
            assert sql('SELECT COUNT(*) FROM fourth_year_load_test.enrollment_requests')==('1' if expected==201 else '0')
            return result
        for semester in [1,2]:
            term='26-'+str(semester)
            sql("UPDATE fourth_year_load_test.academic_terms SET is_current=(term_code='"+term+"'); UPDATE fourth_year_load_test.subjects SET term_code='"+term+"',semester="+str(semester))
            for classification in ['regular','freshman','irregular','transferee','returnee','shifter','crossEnroll']:
                sql("UPDATE fourth_year_load_test.users SET enrollment_type='"+classification+"' WHERE id='LOCAL-STUDENT'")
                enroll([1,2],term=term)
                assert '30-unit' in enroll([1,2,3],409,term)['error']
        print('PASS fourth year all seven enrollment types: exactly 30 accepted and 30.1 rejected in both regular semesters')
        for year in [1,2,3]:
            sql('UPDATE fourth_year_load_test.users SET year_level='+str(year)+'; UPDATE fourth_year_load_test.subjects SET year_level='+str(year))
            enroll([4],term='26-2');assert '26-unit' in enroll([4,3],409,'26-2')['error']
            assert '26-unit' in enroll([1,2],409,'26-2')['error']
        print('PASS Years 1–3 remain at 26; forged client year and unit values do not bypass the database values')
        sql("UPDATE fourth_year_load_test.users SET year_level=4; UPDATE fourth_year_load_test.subjects SET year_level=4; INSERT INTO fourth_year_load_test.academic_records(student_id,term_code,sub_code,grade,passed) VALUES('LOCAL-STUDENT','25-2','PREV','5.00',0)")
        enroll([1],term='26-2');assert '15 units' in enroll([1,3],409,'26-2')['error']
        print('PASS fourth-year academic probation retains the 15-unit cap')
        sql("DELETE FROM fourth_year_load_test.academic_records; UPDATE fourth_year_load_test.academic_terms SET is_current=(term_code='26-3'); UPDATE fourth_year_load_test.subjects SET term_code='26-3',semester=3; UPDATE fourth_year_load_test.subjects SET units=CASE subject_id WHEN 1 THEN 6 WHEN 2 THEN 0.1 WHEN 3 THEN 9 ELSE 0.1 END")
        enroll([1],term='26-3');assert '6 units' in enroll([1,2],409,'26-3')['error']
        enroll([3],term='26-3');assert '9 units' in enroll([3,4],409,'26-3')['error']
        enroll([1,3],term='26-3')
        print('PASS senior summer retains 6 major and 9 minor unit caps; rejected requests leave no partial records')
    finally:
        server.terminate();server.wait(timeout=10);log.close()
