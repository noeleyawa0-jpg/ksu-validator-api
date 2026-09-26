-- KSU Validator: term + section/capacity migration
-- Run once in TiDB Cloud SQL Editor using the ksu_validator database.
USE ksu_validator;

CREATE TABLE IF NOT EXISTS academic_terms (
    term_code VARCHAR(20) NOT NULL PRIMARY KEY,
    school_year VARCHAR(20) NOT NULL,
    term_name VARCHAR(50) NOT NULL,
    semester TINYINT NOT NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 0,
    start_date DATE NULL,
    end_date DATE NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS section_capacities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    term_code VARCHAR(20) NOT NULL,
    program_code VARCHAR(20) NOT NULL,
    year_level INT NOT NULL,
    section VARCHAR(20) NOT NULL,
    capacity INT NOT NULL DEFAULT 40,
    UNIQUE KEY uq_section_capacity (term_code, program_code, year_level, section),
    INDEX idx_section_term (term_code, program_code, year_level)
);

ALTER TABLE enrollment_requests ADD COLUMN IF NOT EXISTS term_code VARCHAR(20) NULL AFTER term;
ALTER TABLE enrollment_requests ADD COLUMN IF NOT EXISTS selected_section VARCHAR(20) NULL AFTER term_code;
ALTER TABLE academic_records ADD COLUMN IF NOT EXISTS term_code VARCHAR(20) NULL AFTER school_year_taken;

INSERT INTO academic_terms (term_code, school_year, term_name, semester, is_current)
VALUES ('26-1', '2026–2027', 'First Semester', 1, 1)
ON DUPLICATE KEY UPDATE school_year=VALUES(school_year), term_name=VALUES(term_name), semester=VALUES(semester), is_current=1;

UPDATE academic_terms SET is_current=0 WHERE term_code<>'26-1' AND is_current=1;

-- Existing requests created before this migration remain historical.
-- New submissions will always carry the current term_code and selected_section.
