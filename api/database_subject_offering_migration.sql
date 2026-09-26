-- KSU Validator: professional curriculum-subject / offering relationship
-- Run once in TiDB Cloud SQL Editor after the term/section migration.
USE ksu_validator;

CREATE TABLE IF NOT EXISTS curriculum_subjects (
    curriculum_subject_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    program_code VARCHAR(20) NOT NULL,
    sub_code VARCHAR(20) NOT NULL,
    description VARCHAR(200) NOT NULL,
    units DECIMAL(3,1) NOT NULL DEFAULT 0,
    year_level INT NOT NULL,
    semester INT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    legacy_subject_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_curriculum_legacy_subject (legacy_subject_id),
    INDEX idx_curriculum_program_term (program_code, year_level, semester),
    INDEX idx_curriculum_code (program_code, sub_code)
);

ALTER TABLE subjects
    ADD COLUMN IF NOT EXISTS curriculum_subject_id BIGINT UNSIGNED NULL AFTER subject_id;

-- Existing Section-A rows become the canonical curriculum subjects.
-- legacy_subject_id lets this migration safely map the original records.
INSERT INTO curriculum_subjects
    (program_code, sub_code, description, units, year_level, semester, legacy_subject_id)
SELECT
    s.program_code, s.sub_code, s.description, s.units, s.year_level, s.semester, s.subject_id
FROM subjects s
LEFT JOIN curriculum_subjects cs ON cs.legacy_subject_id = s.subject_id
WHERE UPPER(TRIM(s.section)) LIKE '%A'
  AND cs.curriculum_subject_id IS NULL;

UPDATE subjects s
JOIN curriculum_subjects cs ON cs.legacy_subject_id = s.subject_id
SET s.curriculum_subject_id = cs.curriculum_subject_id
WHERE s.curriculum_subject_id IS NULL;

-- The previously generated Section-B rows used B-<A subject_id>.
-- Link those rows to their original A curriculum subject.
UPDATE subjects b
JOIN subjects a
  ON a.subject_id = CAST(SUBSTRING(b.sub_code, 3) AS UNSIGNED)
 AND a.program_code = b.program_code
 AND UPPER(TRIM(a.section)) LIKE '%A'
JOIN curriculum_subjects cs ON cs.legacy_subject_id = a.subject_id
SET b.curriculum_subject_id = cs.curriculum_subject_id
WHERE UPPER(TRIM(b.section)) LIKE '%B'
  AND b.sub_code LIKE 'B-%'
  AND b.curriculum_subject_id IS NULL;

-- Enforce the relationship after the backfill. Existing rows are already
-- populated by the statements above.
ALTER TABLE subjects
    ADD INDEX IF NOT EXISTS idx_subject_curriculum_subject (curriculum_subject_id);
