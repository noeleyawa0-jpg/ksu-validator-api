-- KSU Validator: adviser-approved enrollment policy fields
-- Run once in TiDB Cloud SQL Editor after the existing subject-offering migrations.
USE ksu_validator;

-- Summer/special-term classification is intentionally explicit. Existing rows default
-- to major (the stricter 6-unit cap) until an Admin classifies them correctly.
ALTER TABLE subjects ADD COLUMN IF NOT EXISTS subject_category ENUM('major','minor') NOT NULL DEFAULT 'major';

-- Student profile flags are derived from academic_records at request time; no stored
-- probation flag is used, avoiding stale probation status after grade corrections.

CREATE TABLE IF NOT EXISTS transferee_accreditation_reviews (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  student_id VARCHAR(20) NOT NULL,
  source_description VARCHAR(200) NOT NULL,
  source_units DECIMAL(4,1) NOT NULL,
  local_program_code VARCHAR(20) NOT NULL,
  matched_curriculum_subject_id BIGINT UNSIGNED NULL,
  local_description VARCHAR(200) NULL,
  local_units DECIMAL(4,1) NULL,
  school_year_taken VARCHAR(20) NULL,
  evaluation_status ENUM('eligible_for_review','not_eligible') NOT NULL,
  evaluation_reason VARCHAR(500) NOT NULL,
  evaluated_by VARCHAR(50) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_transfer_student (student_id, created_at)
);
