-- KSU Validator: make each subject row a term-specific class offering.
-- Run after database_subject_offering_migration.sql.
USE ksu_validator;

ALTER TABLE subjects
    ADD COLUMN IF NOT EXISTS term_code VARCHAR(20) NULL AFTER curriculum_subject_id;

-- Existing curriculum rows belong to the currently configured term.
UPDATE subjects s
JOIN academic_terms t ON t.is_current = 1
SET s.term_code = t.term_code
WHERE s.term_code IS NULL;

ALTER TABLE subjects
    ADD INDEX IF NOT EXISTS idx_subject_offering_term
    (term_code, program_code, year_level, semester, section);
