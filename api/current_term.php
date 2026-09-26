<?php
// GET /api/current_term.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/token.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') error_response('Method not allowed', 405);
if (!current_session()) error_response('Unauthorized', 401);

$pdo = db();
$stmt = $pdo->query('SELECT term_code, school_year, term_name, semester, is_current, start_date, end_date FROM academic_terms WHERE is_current = 1 ORDER BY term_code DESC LIMIT 1');
$term = $stmt->fetch();
if (!$term) error_response('No current academic term has been configured by the Admin.', 404);

respond([
    'termCode' => $term['term_code'],
    'schoolYear' => $term['school_year'],
    'term' => $term['term_name'],
    'semester' => (int)$term['semester'],
    'isCurrent' => (bool)$term['is_current'],
    'startDate' => $term['start_date'],
    'endDate' => $term['end_date'],
]);
