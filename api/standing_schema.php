<?php
// Additive schema only. Examples in the adviser document are NOT curriculum edits.
function migrate_subject_standing(PDO $pdo): void {
    $column = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='subjects' AND COLUMN_NAME='required_standing'")->fetchColumn();
    if (!(int)$column) $pdo->exec('ALTER TABLE subjects ADD COLUMN required_standing TINYINT UNSIGNED NOT NULL DEFAULT 0');
}

function reclassify_existing_standings(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS ksu_schema_migrations (migration_key VARCHAR(100) PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $key='20261031_whole_curriculum_standing_v2_all_years';
    $done=$pdo->prepare('SELECT migration_key FROM ksu_schema_migrations WHERE migration_key=?');
    $done->execute([$key]);if ($done->fetchColumn()) return;
    $actor=$pdo->query("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1")->fetchColumn();
    if (!$actor) throw new RuntimeException('Administrator account required for standing migration audit.');
    $pdo->beginTransaction();
    try {
        $students=$pdo->query("SELECT id FROM users WHERE role='student' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($students as $id) refresh_student_standing($pdo,$id,$actor);
        $pdo->prepare('INSERT INTO ksu_schema_migrations(migration_key) VALUES(?)')->execute([$key]);
        $pdo->commit();
    } catch(Throwable $e) {if ($pdo->inTransaction()) $pdo->rollBack();throw $e;}
}
