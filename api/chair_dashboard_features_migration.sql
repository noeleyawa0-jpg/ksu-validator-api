-- Run this once in TiDB Cloud SQL Editor.
USE ksu_validator;

-- Required before deploying Shift In and Student Movements.
-- Run once in the existing ksu_validator database.
CREATE TABLE IF NOT EXISTS student_movements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(20) NOT NULL,
    movement_type VARCHAR(30) NOT NULL DEFAULT 'shift_in',
    from_program VARCHAR(20) NOT NULL,
    to_program VARCHAR(20) NOT NULL,
    from_year_level INT NOT NULL,
    to_year_level INT NOT NULL,
    from_section VARCHAR(40) NOT NULL DEFAULT '',
    to_section VARCHAR(40) NOT NULL DEFAULT '',
    notes VARCHAR(500) NULL,
    performed_by VARCHAR(20) NOT NULL,
    moved_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_student_movements_from_program (from_program, moved_at),
    KEY idx_student_movements_to_program (to_program, moved_at),
    KEY idx_student_movements_student (student_id, moved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
