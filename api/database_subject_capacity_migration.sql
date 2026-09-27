-- KSU Validator: per-subject/per-section offering capacity
-- Each subject offering (subject_id) has its own capacity.
USE ksu_validator;

CREATE TABLE IF NOT EXISTS subject_capacities (
    subject_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    term_code VARCHAR(20) NOT NULL,
    capacity INT NOT NULL DEFAULT 40,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_subject_capacity_term (term_code, subject_id)
);

-- Backfill current subject offerings. Existing section capacity is used as
-- the initial value for every subject in that section; otherwise 40 is used.
INSERT INTO subject_capacities (subject_id, term_code, capacity)
SELECT s.subject_id, s.term_code, COALESCE(sc.capacity, 40)
FROM subjects s
LEFT JOIN section_capacities sc
  ON sc.term_code=s.term_code
 AND sc.program_code=s.program_code
 AND sc.year_level=s.year_level
 AND sc.section=s.section
WHERE s.term_code IS NOT NULL
ON DUPLICATE KEY UPDATE capacity=subject_capacities.capacity;
