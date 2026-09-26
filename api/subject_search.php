<?php
// GET /api/subject_search.php?q=IT-401
// Searches current-semester subject offerings across CEIT programs for
// cross-level/cross-program enrollment. The student's home program is never
// changed by this endpoint.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') error_response('Method not allowed', 405);
$session = current_session();
if (!$session || ($session['role'] ?? '') !== 'student') error_response('Unauthorized', 401);

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) error_response('Enter at least 2 characters to search.');

$pdo = db();
$termStmt = $pdo->query("SELECT term_code, school_year, semester FROM academic_terms WHERE is_current=1 ORDER BY term_code DESC LIMIT 1");
$term = $termStmt->fetch();
if (!$term) error_response('No current academic term has been configured.', 404);
$semester = (int)$term['semester'];

$studentStmt = $pdo->prepare('SELECT id, program, year_level, enrollment_type FROM users WHERE id=? AND role="student" LIMIT 1');
$studentStmt->execute([$session['sub'] ?? '']);
$student = $studentStmt->fetch();
if (!$student) error_response('Student account not found.', 404);

$like = '%' . $q . '%';
$stmt = $pdo->prepare('SELECT s.*, c.program_name FROM subjects s LEFT JOIN curricula c ON c.program_code=s.program_code WHERE s.semester=? AND (s.sub_code LIKE ? OR s.description LIKE ?) ORDER BY s.program_code, s.year_level, s.sub_code, s.section, s.subject_id LIMIT 50');
$stmt->execute([$semester, $like, $like]);
$rows = $stmt->fetchAll();

$prereqStmt = $pdo->prepare('SELECT p.sub_code, p.description FROM subject_prerequisites sp JOIN subjects p ON p.subject_id=sp.prereq_subject_id WHERE sp.subject_id=? ORDER BY p.sub_code,p.subject_id');
$gradeStmt = $pdo->prepare('SELECT grade, passed FROM academic_records WHERE student_id=? AND sub_code=? ORDER BY id DESC LIMIT 1');
$countStmt = $pdo->prepare('SELECT COUNT(DISTINCT er.id) AS enrolled FROM enrollment_requests er JOIN request_subjects rs ON rs.request_id=er.id JOIN subjects os ON os.subject_id=rs.subject_id WHERE er.term_code=? AND os.program_code=? AND os.year_level=? AND os.section=? AND rs.status<>"rejected"');
$capacityStmt = $pdo->prepare('SELECT capacity FROM section_capacities WHERE term_code=? AND program_code=? AND year_level=? AND section=? LIMIT 1');

function search_prereq_status(?array $grade): string {
    if (!$grade) return 'missing';
    if ((bool)$grade['passed']) return 'qualified';
    $g = strtoupper(trim((string)$grade['grade']));
    if ($g === 'INC') return 'incomplete';
    if ($g === 'OD') return 'dropped';
    return 'failed';
}

$out=[];
foreach ($rows as $s) {
    $prereqStmt->execute([(int)$s['subject_id']]);
    $prereqs=$prereqStmt->fetchAll();
    $eligible=true; $reason='Eligible';

    $gradeStmt->execute([$student['id'],$s['sub_code']]);
    $ownGrade=$gradeStmt->fetch();
    if ($ownGrade && (bool)$ownGrade['passed']) {
        $eligible=false; $reason='Already completed and passed.';
    }

    if ($eligible) {
        foreach ($prereqs as $p) {
            $gradeStmt->execute([$student['id'],$p['sub_code']]);
            $g=$gradeStmt->fetch();
            if (search_prereq_status($g) !== 'qualified') {
                $eligible=false;
                $reason='Missing or incomplete prerequisite: '.$p['sub_code'].'.';
                break;
            }
        }
    }

    // Keep the existing eligibility rule: regular/freshman students may take
    // up to one year ahead; irregular/shifter/returnee are not restricted by year.
    if ($eligible && in_array($student['enrollment_type'], ['regular','freshman'], true) && (int)$s['year_level'] > ((int)$student['year_level'] + 1)) {
        $eligible=false; $reason='This subject is beyond the allowed year-level range.';
    }

    $countStmt->execute([$term['term_code'],$s['program_code'],(int)$s['year_level'],trim($s['section'])]);
    $enrolled=(int)($countStmt->fetch()['enrolled'] ?? 0);
    $capacityStmt->execute([$term['term_code'],$s['program_code'],(int)$s['year_level'],trim($s['section'])]);
    $capRow=$capacityStmt->fetch();
    $capacity=$capRow ? (int)$capRow['capacity'] : 40;
    $available=max(0,$capacity-$enrolled);
    if ($available <= 0) { $eligible=false; $reason='No available slots in this offering.'; }

    $out[]=[
        'subject'=>[
            'subjectId'=>(int)$s['subject_id'], 'programCode'=>$s['program_code'],
            'subCode'=>$s['sub_code'], 'schedCode'=>$s['sched_code'],
            'description'=>$s['description'], 'units'=>(float)$s['units'],
            'schedule'=>$s['schedule'], 'section'=>$s['section'],
            'instructor'=>$s['instructor'],
            'prerequisites'=>array_column($prereqs,'sub_code'),
            'exclusive'=>(bool)$s['is_exclusive'], 'yearLevel'=>(int)$s['year_level'],
            'semester'=>(int)$s['semester'],
        ],
        'programName'=>$s['program_name'] ?? $s['program_code'],
        'available'=>(int)$available, 'capacity'=>(int)$capacity, 'enrolled'=>(int)$enrolled,
        'eligible'=>$eligible, 'eligibilityReason'=>$reason,
        'isCrossProgram'=>($s['program_code'] !== $student['program']),
        'isCrossLevel'=>((int)$s['year_level'] !== (int)$student['year_level']),
    ];
}
respond(['termCode'=>$term['term_code'],'semester'=>$semester,'results'=>$out]);
