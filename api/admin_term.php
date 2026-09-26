<?php
// GET  /api/admin_term.php -> current term
// POST /api/admin_term.php -> create/set current term (Admin only)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';

$session = current_session();
if (!$session || ($session['role'] ?? '') !== 'admin') error_response('Unauthorized', 401);
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query('SELECT term_code, school_year, term_name, semester, is_current, start_date, end_date FROM academic_terms ORDER BY term_code DESC');
    $rows = $stmt->fetchAll();
    respond(array_map(function ($r) {
        return [
            'termCode' => $r['term_code'], 'schoolYear' => $r['school_year'],
            'term' => $r['term_name'], 'semester' => (int)$r['semester'],
            'isCurrent' => (bool)$r['is_current'], 'startDate' => $r['start_date'], 'endDate' => $r['end_date'],
        ];
    }, $rows));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_body();
    $termCode = trim((string)($body['termCode'] ?? ''));
    $schoolYear = trim((string)($body['schoolYear'] ?? ''));
    $termName = trim((string)($body['term'] ?? ''));
    $semester = (int)($body['semester'] ?? 0);
    $startDate = trim((string)($body['startDate'] ?? ''));
    $endDate = trim((string)($body['endDate'] ?? ''));

    if ($termCode === '' || $schoolYear === '' || $termName === '' || !in_array($semester, [1,2,3], true)) {
        error_response('termCode, schoolYear, term, and semester (1, 2, or 3) are required.');
    }
    if ($startDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) error_response('startDate must be YYYY-MM-DD.');
    if ($endDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) error_response('endDate must be YYYY-MM-DD.');

    $pdo->beginTransaction();
    try {
        $pdo->exec('UPDATE academic_terms SET is_current = 0');
        $stmt = $pdo->prepare('INSERT INTO academic_terms (term_code, school_year, term_name, semester, is_current, start_date, end_date) VALUES (?, ?, ?, ?, 1, ?, ?) ON DUPLICATE KEY UPDATE school_year=VALUES(school_year), term_name=VALUES(term_name), semester=VALUES(semester), is_current=1, start_date=VALUES(start_date), end_date=VALUES(end_date)');
        $stmt->execute([$termCode, $schoolYear, $termName, $semester, $startDate !== '' ? $startDate : null, $endDate !== '' ? $endDate : null]);
        $pdo->commit();
        respond(['status'=>'ok','termCode'=>$termCode]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_response('Failed to save academic term: ' . $e->getMessage(), 500);
    }
}

error_response('Method not allowed', 405);
