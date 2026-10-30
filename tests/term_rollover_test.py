"""Exercise real PHP endpoints against a disposable LOCAL MariaDB schema.
Usage: python term_rollover_test.py --port 33317 --snapshot PATH
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
    sql('INSERT INTO term_rollover_test.' + table + ' (' + ','.join(row) + ') VALUES (' + ','.join(map(quote, row.values())) + ')')
sql('''DROP DATABASE IF EXISTS term_rollover_test; CREATE DATABASE term_rollover_test;
USE term_rollover_test;
CREATE TABLE academic_terms(term_code VARCHAR(20) PRIMARY KEY,school_year VARCHAR(30),term_name VARCHAR(60),semester INT,is_current INT,start_date DATE,end_date DATE);
CREATE TABLE curricula(program_code VARCHAR(20) PRIMARY KEY,program_name VARCHAR(200),department VARCHAR(20),effective_school_year VARCHAR(20));
CREATE TABLE curriculum_subjects(curriculum_subject_id BIGINT PRIMARY KEY AUTO_INCREMENT,program_code VARCHAR(20),sub_code VARCHAR(20),description VARCHAR(200),units DECIMAL(4,1),year_level INT,semester INT,is_active INT DEFAULT 1,legacy_subject_id BIGINT NULL);
CREATE TABLE subjects(subject_id BIGINT PRIMARY KEY AUTO_INCREMENT,curriculum_subject_id BIGINT NULL,term_code VARCHAR(20),sub_code VARCHAR(20),program_code VARCHAR(20),sched_code VARCHAR(100),description VARCHAR(200),units DECIMAL(4,1),schedule VARCHAR(100),section VARCHAR(30),instructor VARCHAR(100),is_exclusive INT,year_level INT,semester INT,subject_category VARCHAR(10));
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
    function db(): PDO {static $pdo;if(!$pdo)$pdo=new PDO('mysql:host=127.0.0.1;port=' . getenv('LOCAL_DB_PORT') . ';dbname=term_rollover_test;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);return $pdo;}
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
        time.sleep(.5)
        baseline=sql("SELECT * FROM term_rollover_test.subjects WHERE term_code='26-1' ORDER BY subject_id")
        for code in ['26-2','26-3','27-1','27-2','28-1']:
            result=set_term(code);sem=int(code[-1]);assert result['semester']==sem,result
            if sem==3:assert result['term']=='Summer / Special Term'
            for program in ['BSIT','BSCpE','BSCE','BSMath','BSEE','BSABE']:
                c=req(f'curriculum.php?program={program}')
                assert all(s['semester']==sem for s in c['subjects']),(code,program)
                if code=='26-2':
                    expected=[s for t,p,s in seed if t=='26-1' and p==program and s['semester']==2]
                    assert len(c['subjects'])==len(expected),(program,len(c['subjects']),len(expected))
                    for item in c['subjects']:
                        source=next(s for s in expected if s['subCode']==item['subCode'] and s['section']==item['section'] and s['yearLevel']==item['yearLevel'])
                        assert sorted(item['prerequisites'])==sorted(source['prerequisites']),(program,item['subCode'],'prerequisites changed')
                if program=='BSIT' and sem==2:assert c['subjects']
                capacities=req(f'subject_capacity.php?program={program}&yearLevel=1&termCode={code}')
                assert all(row['semester']==sem for row in capacities)
            assert set_term(code)['offeringsCreated']==0, 'Repeated save duplicated offerings'
            # Even a request for the wrong semester/old term remains restricted for students.
            c=req('curriculum.php?program=BSIT&termCode=26-1&semester='+str(1 if sem!=1 else 2),role='student')
            assert c['termCode']==code and all(s['semester']==sem for s in c['subjects'])
            search=req('subject_search.php?q=IT',role='student')
            assert search['semester']==sem and all(r['subject']['semester']==sem for r in search['results'])
            print('PASS',code,'semester filter, all-program rollover, idempotence and student search')
        assert sql("SELECT * FROM term_rollover_test.subjects WHERE term_code='26-1' ORDER BY subject_id")==baseline
        # Latest-list edits/deletions must propagate instead of resurrecting old versions.
        c=req('curriculum.php?program=BSIT')['subjects'];chosen=c[0]
        sql("UPDATE term_rollover_test.subjects SET description='Updated last-used description' WHERE subject_id="+str(chosen['subjectId']))
        removed=c[-1];sql('DELETE FROM term_rollover_test.subjects WHERE subject_id='+str(removed['subjectId']))
        assert set_term('28-1')['offeringsCreated']==0, 'Saving same term resurrected a removed offering'
        assert len(req('curriculum.php?program=BSIT')['subjects'])==len(c)-1
        set_term('29-1');new=req('curriculum.php?program=BSIT')['subjects']
        assert len(new)==len(c)-1
        assert next(s for s in new if s['subCode']==chosen['subCode'] and s['section']==chosen['section'])['description']=='Updated last-used description'
        print('PASS latest prior semester edits/deletions retained; historical rows unchanged')
        set_term('29-2')
        subjects=req('curriculum.php?program=BSIT&yearLevel=1',role='student')['subjects'];section=subjects[0]['section']
        old=next(s for t,p,s in seed if t=='26-1' and p=='BSIT' and s['semester']==2 and s['yearLevel']==1)
        body=dict(id='LOCAL-REQUEST',studentId='LOCAL-STUDENT',schoolYear='2029–2030',term='Second Semester',termCode='29-2',section=section,type='regular',subjects=[dict(subjectId=old['subjectId'],subCode=old['subCode'],localCheck='eligible')])
        r=req('enrollment_submit.php',body,role='student',expected=400)
        assert 'not offered' in r['error'],r
        assert sql('SELECT COUNT(*) FROM term_rollover_test.enrollment_requests')=='0'
        valid=next(s for s in subjects if not s['prerequisites'] and s['section']==section)
        body['subjects']=[dict(subjectId=valid['subjectId'],subCode=valid['subCode'],localCheck='eligible')]
        req('enrollment_submit.php',body,role='student',expected=201)
        print('PASS stale subject ID rejected without writes; valid current-semester enrollment succeeds')
        req('admin_term.php',dict(termCode='30-1',schoolYear='2030–2031',term='First Semester',semester=1),role='chairperson',expected=401)
        req('admin_term.php',dict(termCode='bad',schoolYear='2030–2031',term='First Semester',semester=1),expected=400)
        req('admin_subjects.php?subjectId='+str(old['subjectId'])+'&program=BSIT',method='DELETE',expected=409)
        assert sql("SELECT * FROM term_rollover_test.subjects WHERE term_code='26-1' ORDER BY subject_id")==baseline
        print('PASS chair cannot switch term; invalid term code rejected')
    finally:
        server.terminate();server.wait(timeout=10);log.close()
