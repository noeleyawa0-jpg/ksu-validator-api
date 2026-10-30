<?php
// Admin-only student record maintenance and semester advancement.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';
require_once __DIR__ . '/year_standing.php';

function require_admin(): void {
    $session = current_session();
    if (!$session || $session['role'] !== 'admin') {
        error_response('Unauthorized', 401);
    }
}

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $body = json_body();
    $id = trim($body['id'] ?? '');
    $firstName = trim($body['firstName'] ?? '');
    $lastName = trim($body['lastName'] ?? '');
    $email = trim($body['email'] ?? '');
    $program = trim($body['program'] ?? '');
    $yearLevel = filter_var($body['yearLevel'] ?? null, FILTER_VALIDATE_INT);
    $yearSection = trim($body['yearSection'] ?? '');
    $enrollmentType = $body['enrollmentType'] ?? null;

    $validPrograms = ['BSCpE', 'BSIT', 'BSCE', 'BSMath', 'BSEE', 'BSABE'];
    $validTypes = ['freshman', 'regular', 'irregular', 'transferee', 'returnee', 'shifter', 'crossEnroll'];
    if ($id === '' || $firstName === '' || $lastName === '' ||
        !in_array($program, $validPrograms, true) ||
        $yearLevel === false || $yearLevel < 1 || $yearLevel > 4 ||
        !in_array($enrollmentType, $validTypes, true)) {
        error_response('Provide valid student information.');
    }

    $current=db()->prepare('SELECT year_level FROM users WHERE id=? AND role="student"');
    $current->execute([$id]);$previous=$current->fetchColumn();
    if ($previous !== false && $yearLevel>(int)$previous && $yearLevel>=3) {
        $progress=student_standing_progress(db(),$id,$program);
        $rule=$yearLevel===3 ? $progress['thirdYear'] : $progress['fourthYear'];
        if (!$rule['eligible']) error_response('This student has not passed at least 51% of the required Year 1–'.$rule['throughYear'].' curriculum units.',409);
    }

    $stmt = db()->prepare('
        UPDATE users
        SET first_name = ?, last_name = ?, email = ?, program = ?,
            year_level = ?, year_section = ?, enrollment_type = ?
        WHERE id = ? AND role = "student"
    ');
    $stmt->execute([$firstName, $lastName, $email ?: "$id@ksu.edu.ph", $program,
        $yearLevel, $yearSection, $enrollmentType, $id]);
    if ($stmt->rowCount() === 0) {
        $check = db()->prepare('SELECT id FROM users WHERE id = ? AND role = "student"');
        $check->execute([$id]);
        if (!$check->fetch()) error_response('Student account not found.', 404);
    }
    respond(['status' => 'ok']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_body();
    $program = trim($body['program'] ?? '');
    if ($program === '') error_response('Program is required.');

    // Keep the existing Admin-controlled Year-1/2 transition. Higher-year
    // advancement must satisfy the approved unit rule; Year 4 never graduates here.
    $pdo = db(); $pdo->beginTransaction();
    try {
        $stmt=$pdo->prepare('SELECT id,year_level FROM users WHERE role="student" AND program=? AND year_level BETWEEN 1 AND 3 FOR UPDATE');
        $stmt->execute([$program]); $students=$stmt->fetchAll(); $updated=0;
        foreach ($students as $student) {
            if ((int)$student['year_level']===1) {
                $pdo->prepare('UPDATE users SET year_level=2 WHERE id=? AND year_level=1')->execute([$student['id']]);
                $updated++;
            } else {
                $result=refresh_student_standing($pdo,$student['id'],(string)current_session()['sub']);
                if ($result['promoted']) $updated++;
            }
        }
        $pdo->commit(); respond(['status'=>'ok','updated'=>$updated]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Standing advancement failed: '.$e->getMessage());
        error_response('Unable to update student standing.',500);
    }
}

error_response('Method not allowed', 405);
