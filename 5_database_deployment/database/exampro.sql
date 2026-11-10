-- ============================================================================
-- ExamPro - MySQL schema (MySQL 5.7+ / 8.x / MariaDB 10.3+)
-- Import with phpMyAdmin: Import tab -> choose this file -> Go.
-- All timestamps are stored in UTC (the PHP layer formats them for display).
-- If your host does not allow CREATE DATABASE, delete the first two statements,
-- create/select the database in phpMyAdmin first, then import.
-- ============================================================================
CREATE DATABASE IF NOT EXISTS `exampro` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `exampro`;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- To rebuild from scratch, uncomment:
-- DROP TABLE IF EXISTS exam_incidents, submitted_answers, results, exam_attempts, exam_assignments,
--   options, questions, exams, students, examiners, admins, login_attempts;

-- ---------------------------------------------------------------- admins
CREATE TABLE IF NOT EXISTS `admins` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(60)  NOT NULL,
  `full_name`     VARCHAR(120) NOT NULL DEFAULT 'Administrator',
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login_at` DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admins_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------- examiners
CREATE TABLE IF NOT EXISTS `examiners` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name`     VARCHAR(120) NOT NULL,
  `email`         VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `status`        ENUM('Active','Disabled') NOT NULL DEFAULT 'Active',
  `legacy_id`     VARCHAR(64)  NULL COMMENT 'UUID from the old data.json (migration only)',
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login_at` DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_examiners_email` (`email`),
  UNIQUE KEY `uq_examiners_legacy` (`legacy_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------- students
CREATE TABLE IF NOT EXISTS `students` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name`     VARCHAR(120) NOT NULL,
  `email`         VARCHAR(190) NOT NULL,
  `student_code`  VARCHAR(60)  NOT NULL COMMENT 'Student ID / roll number',
  `course`        VARCHAR(120) NOT NULL,
  `year_label`    VARCHAR(30)  NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `status`        ENUM('Pending verification','Verified','Rejected') NOT NULL DEFAULT 'Pending verification',
  `reviewed_at`   DATETIME     NULL,
  `legacy_id`     VARCHAR(64)  NULL,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login_at` DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_students_email` (`email`),
  UNIQUE KEY `uq_students_code` (`student_code`),
  UNIQUE KEY `uq_students_legacy` (`legacy_id`),
  KEY `idx_students_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------- exams
CREATE TABLE IF NOT EXISTS `exams` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `examiner_id`        INT UNSIGNED NOT NULL,
  `title`              VARCHAR(100) NOT NULL,
  `description`        VARCHAR(500) NOT NULL DEFAULT '',
  `duration_minutes`   SMALLINT UNSIGNED NOT NULL,
  `status`             ENUM('Draft','Published','Closed') NOT NULL DEFAULT 'Draft',
  `negative_marking`   TINYINT(1) NOT NULL DEFAULT 0,
  `negative_fraction`  DECIMAL(4,2) NOT NULL DEFAULT 0.00 COMMENT 'Fraction of a question''s marks deducted per wrong answer (0.25 = 25%)',
  `max_violations`     TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Counted violations that trigger auto-submit (1 = original behaviour)',
  `pass_percentage`    DECIMAL(5,2) NOT NULL DEFAULT 40.00,
  `show_results`       TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = student sees score and answer review after submitting',
  `legacy_id`          VARCHAR(64) NULL,
  `created_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_exams_legacy` (`legacy_id`),
  KEY `idx_exams_examiner` (`examiner_id`),
  KEY `idx_exams_status` (`status`),
  CONSTRAINT `fk_exams_examiner` FOREIGN KEY (`examiner_id`) REFERENCES `examiners`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------- questions
CREATE TABLE IF NOT EXISTS `questions` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `exam_id`       INT UNSIGNED NOT NULL,
  `position`      SMALLINT UNSIGNED NOT NULL,
  `question_text` TEXT NOT NULL,
  `marks`         DECIMAL(6,2) NOT NULL DEFAULT 1.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_questions_pos` (`exam_id`,`position`),
  CONSTRAINT `fk_questions_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------- options
CREATE TABLE IF NOT EXISTS `options` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `question_id` INT UNSIGNED NOT NULL,
  `label`       CHAR(1) NOT NULL COMMENT 'A, B, C or D',
  `option_text` TEXT NOT NULL,
  `is_correct`  TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_options_label` (`question_id`,`label`),
  CONSTRAINT `fk_options_question` FOREIGN KEY (`question_id`) REFERENCES `questions`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------ exam_assignments
CREATE TABLE IF NOT EXISTS `exam_assignments` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `exam_id`     INT UNSIGNED NOT NULL,
  `student_id`  INT UNSIGNED NOT NULL,
  `assigned_by` INT UNSIGNED NULL COMMENT 'examiner id',
  `assigned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_assign` (`exam_id`,`student_id`),
  KEY `idx_assign_student` (`student_id`),
  CONSTRAINT `fk_assign_exam`     FOREIGN KEY (`exam_id`)     REFERENCES `exams`(`id`)     ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_assign_student`  FOREIGN KEY (`student_id`)  REFERENCES `students`(`id`)  ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_assign_examiner` FOREIGN KEY (`assigned_by`) REFERENCES `examiners`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------- exam_attempts
-- One attempt per student per exam (UNIQUE) => duplicate attempts are impossible.
CREATE TABLE IF NOT EXISTS `exam_attempts` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `exam_id`           INT UNSIGNED NOT NULL,
  `student_id`        INT UNSIGNED NOT NULL,
  `started_at`        DATETIME NOT NULL,
  `deadline_at`       DATETIME NOT NULL COMMENT 'Server-controlled end time (UTC)',
  `submitted_at`      DATETIME NULL,
  `status`            ENUM('in_progress','submitted') NOT NULL DEFAULT 'in_progress',
  `submission_reason` VARCHAR(30) NULL COMMENT 'student_submit | time_limit | fullscreen_exit | tab_switch | violation_limit | migrated',
  `violation_count`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attempt` (`exam_id`,`student_id`),
  KEY `idx_attempt_student` (`student_id`),
  KEY `idx_attempt_status` (`status`,`deadline_at`),
  CONSTRAINT `fk_attempt_exam`    FOREIGN KEY (`exam_id`)    REFERENCES `exams`(`id`)    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_attempt_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------- submitted_answers
CREATE TABLE IF NOT EXISTS `submitted_answers` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `attempt_id`       INT UNSIGNED NOT NULL,
  `question_id`      INT UNSIGNED NOT NULL,
  `chosen_option_id` INT UNSIGNED NULL,
  `is_correct`       TINYINT(1) NULL COMMENT 'Filled when the attempt is graded',
  `marks_awarded`    DECIMAL(6,2) NULL,
  `answered_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_answer` (`attempt_id`,`question_id`),
  KEY `idx_answer_question` (`question_id`),
  CONSTRAINT `fk_answer_attempt`  FOREIGN KEY (`attempt_id`)       REFERENCES `exam_attempts`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_answer_question` FOREIGN KEY (`question_id`)      REFERENCES `questions`(`id`)     ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_answer_option`   FOREIGN KEY (`chosen_option_id`) REFERENCES `options`(`id`)       ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------- results
-- Count columns are NULL for results migrated from data.json (answers were never stored there).
CREATE TABLE IF NOT EXISTS `results` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `attempt_id`        INT UNSIGNED NOT NULL,
  `exam_id`           INT UNSIGNED NOT NULL,
  `student_id`        INT UNSIGNED NOT NULL,
  `examiner_id`       INT UNSIGNED NOT NULL,
  `total_questions`   SMALLINT UNSIGNED NOT NULL,
  `correct_count`     SMALLINT UNSIGNED NULL,
  `incorrect_count`   SMALLINT UNSIGNED NULL,
  `unattempted_count` SMALLINT UNSIGNED NULL,
  `max_marks`         DECIMAL(8,2) NOT NULL,
  `obtained_marks`    DECIMAL(8,2) NOT NULL,
  `percentage`        DECIMAL(5,2) NOT NULL,
  `result_status`     ENUM('Pass','Fail') NOT NULL,
  `submission_reason` VARCHAR(30) NOT NULL DEFAULT 'student_submit',
  `submitted_at`      DATETIME NOT NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_result_attempt` (`attempt_id`),
  KEY `idx_result_exam` (`exam_id`),
  KEY `idx_result_student` (`student_id`),
  KEY `idx_result_examiner` (`examiner_id`),
  CONSTRAINT `fk_result_attempt`  FOREIGN KEY (`attempt_id`)  REFERENCES `exam_attempts`(`id`) ON DELETE CASCADE  ON UPDATE CASCADE,
  CONSTRAINT `fk_result_exam`     FOREIGN KEY (`exam_id`)     REFERENCES `exams`(`id`)         ON DELETE CASCADE  ON UPDATE CASCADE,
  CONSTRAINT `fk_result_student`  FOREIGN KEY (`student_id`)  REFERENCES `students`(`id`)      ON DELETE CASCADE  ON UPDATE CASCADE,
  CONSTRAINT `fk_result_examiner` FOREIGN KEY (`examiner_id`) REFERENCES `examiners`(`id`)     ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------- exam_incidents
CREATE TABLE IF NOT EXISTS `exam_incidents` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `attempt_id`    INT UNSIGNED NOT NULL,
  `exam_id`       INT UNSIGNED NOT NULL,
  `student_id`    INT UNSIGNED NOT NULL,
  `incident_type` VARCHAR(40)  NOT NULL COMMENT 'fullscreen_exit | tab_switch | window_blur | exam_resumed | student_report | ...',
  `description`   VARCHAR(500) NOT NULL DEFAULT '',
  `counted`       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = counts toward the violation threshold',
  `occurred_at`   DATETIME     NOT NULL COMMENT 'Server time when recorded (UTC)',
  `client_time`   VARCHAR(40)  NULL COMMENT 'Browser-reported time (informational)',
  PRIMARY KEY (`id`),
  KEY `idx_inc_attempt` (`attempt_id`),
  KEY `idx_inc_exam` (`exam_id`),
  KEY `idx_inc_student` (`student_id`),
  CONSTRAINT `fk_inc_attempt` FOREIGN KEY (`attempt_id`) REFERENCES `exam_attempts`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_inc_exam`    FOREIGN KEY (`exam_id`)    REFERENCES `exams`(`id`)         ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_inc_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`)      ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------- login_attempts (throttle)
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip`           VARCHAR(45) NOT NULL,
  `attempted_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_login_ip` (`ip`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- SEED DATA (sample logins - CHANGE THESE PASSWORDS before any real use)
--   Admin     : admin               / admin123
--   Examiner  : examiner@example.com / Examiner@123
--   Student   : student@example.com  / Student@123   (already Verified)
-- ============================================================================
INSERT IGNORE INTO `admins` (`id`,`username`,`full_name`,`password_hash`) VALUES
 (1,'admin','Administrator','$2y$10$aoEEaK65IvFmh5UgiQaiKOcE.k2lgArqX5uBJH.bN9AcngLcx/NcW');

INSERT IGNORE INTO `examiners` (`id`,`full_name`,`email`,`password_hash`,`status`) VALUES
 (1,'Demo Examiner','examiner@example.com','$2y$10$ms4iPbtDWghcXnnwevgL9OpWQD69b9Yb0J.BHHoQf.EQriHaMcGc6','Active');

INSERT IGNORE INTO `students` (`id`,`full_name`,`email`,`student_code`,`course`,`year_label`,`password_hash`,`status`,`reviewed_at`) VALUES
 (1,'Demo Student','student@example.com','STU-001','B.Tech CSE','2nd year','$2y$10$wK7syfXmCJ1fpgVdnJ.7FOjUguSDgbz4nekM7nTVhnEW67nAdaJYS','Verified',UTC_TIMESTAMP());

-- Sample exam (the four questions from sample-questions.csv), published and assigned to the demo student.
INSERT IGNORE INTO `exams` (`id`,`examiner_id`,`title`,`description`,`duration_minutes`,`status`,`negative_marking`,`negative_fraction`,`max_violations`,`pass_percentage`,`show_results`) VALUES
 (1,1,'Sample General Knowledge Test','Four quick questions to try the exam flow. Stay in full-screen mode.',10,'Published',0,0.00,1,40.00,1);

INSERT IGNORE INTO `questions` (`id`,`exam_id`,`position`,`question_text`,`marks`) VALUES
 (1,1,1,'What is the capital of India?',1.00),
 (2,1,2,'Which language is used to structure web pages?',1.00),
 (3,1,3,'What does DBMS stand for?',2.00),
 (4,1,4,'Which SQL command retrieves records?',1.00);

INSERT IGNORE INTO `options` (`question_id`,`label`,`option_text`,`is_correct`) VALUES
 (1,'A','Mumbai',0),(1,'B','Delhi',1),(1,'C','Kolkata',0),(1,'D','Chennai',0),
 (2,'A','Python',0),(2,'B','Java',0),(2,'C','HTML',1),(2,'D','C++',0),
 (3,'A','Database Management System',1),(3,'B','Data Backup Main Server',0),(3,'C','Digital Base Memory System',0),(3,'D','Database Machine Software',0),
 (4,'A','INSERT',0),(4,'B','SELECT',1),(4,'C','UPDATE',0),(4,'D','DELETE',0);

INSERT IGNORE INTO `exam_assignments` (`exam_id`,`student_id`,`assigned_by`) VALUES (1,1,1);
