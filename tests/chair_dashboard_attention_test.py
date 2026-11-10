"""Chair attention warnings clear when the missing prerequisite is passed later.

Run against an isolated local MariaDB test server, for example:
  python chair_dashboard_attention_test.py --port 33317
"""
import argparse
import base64
import hashlib
import hmac
import json
import os
import pathlib
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

parser = argparse.ArgumentParser()
parser.add_argument('--port', type=int, required=True)
args = parser.parse_args()
assert args.port != 3306, 'Use an isolated test database server, not the default DB.'

backend = pathlib.Path(__file__).resolve().parents[1]
database = 'chair_dashboard_attention_test'

def sql(query):
    return subprocess.run(
        ['C:/xampp/mysql/bin/mysql.exe', '-h', '127.0.0.1', '-P', str(args.port),
         '-u', 'root', '-N', '-B', '--default-character-set=utf8mb4'],
        input=query, text=True, encoding='utf-8', capture_output=True,
        check=True).stdout.strip()

sql(f'''DROP DATABASE IF EXISTS {database}; CREATE DATABASE {database}; USE {database};
CREATE TABLE academic_terms(term_code VARCHAR(20) PRIMARY KEY,school_year VARCHAR(30),term_name VARCHAR(60),semester INT,is_current INT);
INSERT INTO academic_terms VALUES('27-1','2027–2028','First Semester',1,1);
CREATE TABLE users(id VARCHAR(20) PRIMARY KEY,role VARCHAR(20),first_name VARCHAR(50),last_name VARCHAR(50),email VARCHAR(100),program VARCHAR(20),year_level INT,year_section VARCHAR(30),enrollment_type VARCHAR(20));
INSERT INTO users VALUES
('LOCAL-1','student','First','Student','one@example.test','BSCpE',3,'BSCpE 3A','irregular'),
('LOCAL-2','student','Second','Student','two@example.test','BSCpE',3,'BSCpE 3A','irregular'),
('LOCAL-OTHER','student','Other','Program','other@example.test','BSIT',3,'BSIT 3A','regular');
CREATE TABLE subjects(subject_id BIGINT PRIMARY KEY,program_code VARCHAR(20),sub_code VARCHAR(20),description VARCHAR(100));
INSERT INTO subjects VALUES(1,'BSCpE','PRE','Prerequisite'),(2,'BSCpE','ADV','Advanced Course'),(3,'BSIT','PRE','Prerequisite'),(4,'BSIT','ADV','Advanced Course');
CREATE TABLE subject_prerequisites(subject_id BIGINT,prereq_subject_id BIGINT);
INSERT INTO subject_prerequisites VALUES(2,1),(4,3);
CREATE TABLE academic_records(id BIGINT PRIMARY KEY AUTO_INCREMENT,student_id VARCHAR(20),sub_code VARCHAR(20),school_year_taken VARCHAR(30),term_code VARCHAR(20),grade VARCHAR(20),passed INT);
INSERT INTO academic_records(student_id,sub_code,school_year_taken,term_code,grade,passed) VALUES
('LOCAL-1','ADV','2026–2027','26-1','2.00',1),
('LOCAL-2','ADV','2026–2027','26-1','2.00',1),
('LOCAL-OTHER','ADV','2026–2027','26-1','2.00',1);
''')

def token():
    payload = base64.b64encode(json.dumps({
        'sub': 'LOCAL-CHAIR', 'role': 'chairperson', 'program': 'BSCpE',
        'exp': int(time.time()) + 3600,
    }).encode()).decode()
    return payload + '.' + hmac.new(b'local-test-secret', payload.encode(), hashlib.sha256).hexdigest()

with tempfile.TemporaryDirectory(prefix='ksu-chair-attention-') as temp:
    root = pathlib.Path(temp)
    api = root / 'api'
    api.mkdir()
    for name in ['chair_dashboard.php', 'token.php']:
        shutil.copy2(backend / 'api' / name, api / name)
    (api / 'config.php').write_text('''<?php
header('Content-Type: application/json'); define('TOKEN_SECRET','local-test-secret');
function db():PDO { static $pdo; if(!$pdo) $pdo=new PDO('mysql:host=127.0.0.1;port='.getenv('LOCAL_DB_PORT').';dbname=chair_dashboard_attention_test;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); return $pdo; }
function respond($data,int $status=200):void { http_response_code($status); echo json_encode($data); exit; }
function error_response(string $message,int $status=400):void { respond(['error'=>$message],$status); }
''', encoding='utf-8')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    log = open(root / 'php.log', 'w')
    server = subprocess.Popen(
        ['C:/xampp/php/php.exe', '-d', 'error_reporting=-1', '-d', 'display_errors=1',
         '-S', f'127.0.0.1:{port}', '-t', str(api)],
        env=dict(os.environ, LOCAL_DB_PORT=str(args.port)), stdout=log, stderr=log)

    def dashboard():
        request = urllib.request.Request(
            f'http://127.0.0.1:{port}/chair_dashboard.php',
            headers={'Authorization': 'Bearer ' + token()})
        try:
            with urllib.request.urlopen(request, timeout=10) as response:
                return json.loads(response.read())
        except urllib.error.HTTPError as error:
            raise AssertionError((error.code, error.read()))

    try:
        time.sleep(.3)
        initial = dashboard()
        assert initial['studentsWithViolations'] == 2, initial
        assert {row['studentId'] for row in initial['violations']} == {'LOCAL-1', 'LOCAL-2'}

        sql("INSERT INTO chair_dashboard_attention_test.academic_records(student_id,sub_code,school_year_taken,term_code,grade,passed) VALUES('LOCAL-1','PRE','2027–2028','27-1','2.00',1)")
        after_first_pass = dashboard()
        assert after_first_pass['studentsWithViolations'] == 1, after_first_pass
        assert [row['studentId'] for row in after_first_pass['violations']] == ['LOCAL-2']

        sql("INSERT INTO chair_dashboard_attention_test.academic_records(student_id,sub_code,school_year_taken,term_code,grade,passed) VALUES('LOCAL-2','PRE','2027–2028','27-1','5.00',0)")
        assert dashboard()['studentsWithViolations'] == 1, 'Failed prerequisites must not clear the warning.'

        sql("INSERT INTO chair_dashboard_attention_test.academic_records(student_id,sub_code,school_year_taken,term_code,grade,passed) VALUES('LOCAL-2','PRE','2028–2029','28-1','2.00',1)")
        resolved = dashboard()
        assert resolved['studentsWithViolations'] == 0, resolved
        assert resolved['violations'] == [], resolved
        print('PASS: warning remains for missing/failed prerequisites, clears after a later passing grade, and stays program-scoped.')
    finally:
        server.terminate()
        server.wait(timeout=10)
        log.close()
        sql(f'DROP DATABASE IF EXISTS {database};')
