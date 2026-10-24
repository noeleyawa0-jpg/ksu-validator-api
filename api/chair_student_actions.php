<?php
// Chairperson-only student management, scoped to the chair's program.
// GET ?studentId=... previews a student from another program for a shift-in.
// POST {action:"register", ...} registers a student in the chair's program.
// POST {action:"shift_in", studentId, yearLevel, yearSection, notes} moves an existing student in.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';
require_once __DIR__ . '/account_email.php';

$session = current_session();
if (!$session || ($session['role'] ?? '') !== 'chairperson') error_response('Unauthorized', 401);
$program = trim((string)($session['program'] ?? ''));
if ($program === '') error_response('Chairperson account has no assigned program.', 403);
$validPrograms = ['BSCpE', 'BSIT', 'BSCE', 'BSMath', 'BSEE', 'BSABE'];
if (!in_array($program, $validPrograms, true)) error_response('Chairperson program is not recognized.', 403);
$pdo = db();

function chair_section_matches(string $program, int $yearLevel, string $section): bool {
    return preg_match('/^' . preg_quote($program, '/') . '\s+' . $yearLevel . '[AB]$/i', trim($section)) === 1;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $studentId = trim((string)($_GET['studentId'] ?? ''));
    if ($studentId === '') error_response('Provide a student ID to preview a shift-in candidate.');
    $stmt = $pdo->prepare('SELECT id, first_name, last_name, email, program, year_level, year_section FROM users WHERE id=? AND role="student" LIMIT 1');
    $stmt->execute([$studentId]);
    $row = $stmt->fetch();
    if (!$row) error_response('Student account not found.', 404);
    if (!in_array((string)$row['program'], $validPrograms, true)) error_response('Only students currently enrolled in a CEIT program can be shifted in.', 409);
    if ($row['program'] === $program) error_response('This student is already in your program.', 409);
    respond([
        'studentId' => $row['id'], 'firstName' => $row['first_name'], 'lastName' => $row['last_name'],
        'email' => $row['email'], 'program' => $row['program'], 'yearLevel' => (int)$row['year_level'],
        'yearSection' => $row['year_section'] ?? '',
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') error_response('Method not allowed', 405);
$body = json_body();
$action = (string)($body['action'] ?? '');

if ($action === 'register') {
    $id = trim((string)($body['id'] ?? ''));
    $password = (string)($body['password'] ?? '');
    $firstName = trim((string)($body['firstName'] ?? ''));
    $lastName = trim((string)($body['lastName'] ?? ''));
    $email = trim((string)($body['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) error_response('A valid student email address is required.');
    $yearLevel = filter_var($body['yearLevel'] ?? null, FILTER_VALIDATE_INT);
    $yearSection = trim((string)($body['yearSection'] ?? ''));
    $enrollmentType = strtolower(trim((string)($body['enrollmentType'] ?? 'freshman')));
    $validTypes = ['freshman', 'regular', 'irregular', 'transferee', 'returnee', 'shifter'];
    if ($id === '' || strlen($id) > 20 || $password === '' || strlen($password) < 8 || $firstName === '' || $lastName === '')
        error_response('Provide a student ID, first and last name, and a password of at least 8 characters.');
    if ($yearLevel === false || $yearLevel < 1 || $yearLevel > 4 || !chair_section_matches($program, (int)$yearLevel, $yearSection)) error_response('Provide a valid section label for your program and year, e.g. ' . $program . ' ' . $yearLevel . 'A.');
    if (!in_array($enrollmentType, $validTypes, true)) error_response('Enrollment type is invalid.');
    $exists = $pdo->prepare('SELECT id FROM users WHERE id=? LIMIT 1');
    $exists->execute([$id]);
    if ($exists->fetch()) error_response('An account with this student ID already exists.', 409);
    try {
        $mail = prepare_account_email($email, "$firstName $lastName", $id, $password);
    } catch (Throwable $e) {
        error_response('Email delivery is not configured. Ask the administrator to configure the email sender before creating accounts.', 503);
    }
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $pdo->beginTransaction();
    try {
    $insert = $pdo->prepare('INSERT INTO users (id,password_hash,role,first_name,last_name,email,department,program,year_level,year_section,enrollment_type) VALUES (?,?,"student",?,?,?,"CEIT",?,?,?,?)');
    // Current table has 11 columns in this insert: id/hash/name/email/program/year/section/type.
    $insert->execute([$id, $hash, $firstName, $lastName, $email ?: "$id@ksu.edu.ph", $program, $yearLevel, $yearSection, $enrollmentType]);
        $mail->send();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Chair student creation or credential email failed.');
        error_response('Unable to create the account and email its login details. The account was not created; check the email sender and try again.', 502);
    }
    respond(['status' => 'ok', 'studentId' => $id, 'program' => $program, 'emailStatus' => 'sent', 'message' => 'Account created. Login details were sent to ' . $email . '.'], 201);
}

if ($action === 'shift_in') {
    $studentId = trim((string)($body['studentId'] ?? ''));
    $yearLevel = filter_var($body['yearLevel'] ?? null, FILTER_VALIDATE_INT);
    $yearSection = trim((string)($body['yearSection'] ?? ''));
    $notes = trim((string)($body['notes'] ?? ''));
    if ($studentId === '' || $yearLevel === false || $yearLevel < 1 || $yearLevel > 4 || !chair_section_matches($program, (int)$yearLevel, $yearSection)) error_response('Provide student ID and a destination section label for your program and year, e.g. ' . $program . ' ' . $yearLevel . 'A.');
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id, first_name, last_name, program, year_level, year_section FROM users WHERE id=? AND role="student" FOR UPDATE');
        $lock->execute([$studentId]);
        $student = $lock->fetch();
        if (!$student) { $pdo->rollBack(); error_response('Student account not found.', 404); }
        if (!in_array((string)$student['program'], $validPrograms, true)) { $pdo->rollBack(); error_response('Only students currently enrolled in a CEIT program can be shifted in.', 409); }
        if ($student['program'] === $program) { $pdo->rollBack(); error_response('This student is already in your program.', 409); }
        // Avoid moving an account after it already submitted for the currently active term.
        $active = $pdo->query('SELECT term_code FROM academic_terms WHERE is_current=1 ORDER BY term_code DESC LIMIT 1')->fetchColumn();
        if ($active) {
            $check = $pdo->prepare('SELECT COUNT(*) FROM enrollment_requests WHERE student_id=? AND term_code=?');
            $check->execute([$studentId, $active]);
            if ((int)$check->fetchColumn() > 0) { $pdo->rollBack(); error_response('This student already has a submission for the current term. Resolve that request before shifting the student.', 409); }
        }
        if ($active) {
            $sectionCapacityStmt = $pdo->prepare('SELECT capacity FROM section_capacities WHERE term_code=? AND program_code=? AND year_level=? AND section=? LIMIT 1');
            $sectionCapacityStmt->execute([$active, $program, (int)$yearLevel, $yearSection]);
            $capacityRow = $sectionCapacityStmt->fetch();
            if ($capacityRow) {
                $rosterCountStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role="student" AND program=? AND year_level=? AND year_section=?');
                $rosterCountStmt->execute([$program, (int)$yearLevel, $yearSection]);
                if ((int)$rosterCountStmt->fetchColumn() >= (int)$capacityRow['capacity']) {
                    $pdo->rollBack();
                    error_response('The destination physical section is at capacity.', 409);
                }
            }
        }
        $movement = $pdo->prepare('INSERT INTO student_movements (student_id, movement_type, from_program, to_program, from_year_level, to_year_level, from_section, to_section, notes, performed_by, moved_at) VALUES (?,"shift_in",?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)');
        $movement->execute([$studentId, $student['program'], $program, (int)$student['year_level'], $yearLevel, (string)($student['year_section'] ?? ''), $yearSection, $notes, (string)($session['sub'] ?? '')]);
        $update = $pdo->prepare('UPDATE users SET program=?, year_level=?, year_section=?, enrollment_type="shifter", department="CEIT" WHERE id=? AND role="student"');
        $update->execute([$program, $yearLevel, $yearSection, $studentId]);
        $pdo->commit();
        respond(['status' => 'ok', 'studentId' => $studentId, 'program' => $program]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Chairperson shift-in failed: ' . $e->getMessage());
        error_response('Unable to shift in this student. Check the migration and database connection.', 500);
    }
}

error_response('Unsupported student action.');
