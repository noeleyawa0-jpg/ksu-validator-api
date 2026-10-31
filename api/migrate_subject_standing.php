<?php
// CLI only: called during deployment before Apache accepts requests.
if (PHP_SAPI !== 'cli') {http_response_code(404);exit;}
require_once __DIR__.'/config.php';
require_once __DIR__.'/standing_schema.php';
require_once __DIR__.'/year_standing.php';
migrate_subject_standing(db());
reclassify_existing_standings(db());
echo "Subject standing schema is ready.\n";
