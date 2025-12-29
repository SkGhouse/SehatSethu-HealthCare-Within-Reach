CREATE DATABASE IF NOT EXISTS sehatsethu
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE sehatsethu;
CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('PATIENT','DOCTOR','PHARMACIST','ADMIN') NOT NULL DEFAULT 'PATIENT',

  is_verified TINYINT(1) NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,

  -- profile gate (optional but you already use similar)
  profile_completed TINYINT(1) NOT NULL DEFAULT 0,
  profile_submitted_at TIMESTAMP NULL DEFAULT NULL,

  -- admin verification gate (this is what your table screenshot shows)
  admin_verification_status ENUM('PENDING','UNDER_REVIEW','VERIFIED','REJECTED')
    NOT NULL DEFAULT 'PENDING',
  admin_verified_by BIGINT UNSIGNED NULL DEFAULT NULL,
  admin_verified_at TIMESTAMP NULL DEFAULT NULL,
  admin_rejection_reason VARCHAR(255) NULL DEFAULT NULL,

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  last_login_at TIMESTAMP NULL DEFAULT NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role),
  KEY idx_users_active (is_active),
  KEY idx_users_admin_status (admin_verification_status),
  CONSTRAINT fk_users_admin_verified_by
    FOREIGN KEY (admin_verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- -------------------------
-- EMAIL VERIFICATIONS (OTP)  (for "existing user verify email")
-- -------------------------
CREATE TABLE IF NOT EXISTS email_verifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at TIMESTAMP NOT NULL,
  used_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  send_count INT NOT NULL DEFAULT 1,
  attempts INT NOT NULL DEFAULT 0,
  last_sent_at TIMESTAMP NULL DEFAULT NULL,

  PRIMARY KEY (id),
  KEY idx_ev_user (user_id),
  KEY idx_ev_token_hash (token_hash),
  CONSTRAINT fk_ev_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -------------------------
-- PASSWORD RESETS (OTP)
-- -------------------------
CREATE TABLE IF NOT EXISTS password_resets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at TIMESTAMP NOT NULL,
  verified_at TIMESTAMP NULL DEFAULT NULL,
  used_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  send_count INT NOT NULL DEFAULT 1,
  attempts INT NOT NULL DEFAULT 0,
  last_sent_at TIMESTAMP NULL DEFAULT NULL,

  PRIMARY KEY (id),
  KEY idx_pr_user (user_id),
  KEY idx_pr_token_hash (token_hash),
  CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -------------------------
-- SIGNUP PENDING (OTP-only signup before user row exists)
-- -------------------------
CREATE TABLE IF NOT EXISTS signup_pending (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL,
  role ENUM('PATIENT','DOCTOR','PHARMACIST','ADMIN') NOT NULL,
  full_name VARCHAR(120) NOT NULL,
  otp_hash CHAR(64) NOT NULL,
  expires_at TIMESTAMP NOT NULL,

  last_sent_at TIMESTAMP NULL DEFAULT NULL,
  send_count INT NOT NULL DEFAULT 1,
  attempts INT NOT NULL DEFAULT 0,

  verified_at TIMESTAMP NULL DEFAULT NULL,
  signup_token_hash CHAR(64) NULL DEFAULT NULL,
  signup_token_expires_at TIMESTAMP NULL DEFAULT NULL,

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_signup_pending_email_role (email, role),
  KEY idx_signup_pending_email (email),
  KEY idx_signup_pending_last_sent (last_sent_at)
) ENGINE=InnoDB;

-- -------------------------
-- PATIENT PROFILE
-- -------------------------
CREATE TABLE IF NOT EXISTS patient_profiles (
  user_id BIGINT UNSIGNED NOT NULL,
  gender ENUM('MALE','FEMALE','OTHER') NOT NULL,
  age INT NOT NULL,
  village_town VARCHAR(120) NOT NULL,
  district VARCHAR(120) NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_patient_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -------------------------
-- DOCTOR PROFILE
-- -------------------------
CREATE TABLE IF NOT EXISTS doctor_profiles (
  user_id BIGINT UNSIGNED NOT NULL,
  specialization VARCHAR(120) NOT NULL,
  registration_no VARCHAR(80) NOT NULL,
  practice_place VARCHAR(190) NOT NULL,
  experience_years INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_doctor_regno (registration_no),
  CONSTRAINT fk_doctor_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -------------------------
-- PHARMACIST PROFILE
-- -------------------------
CREATE TABLE IF NOT EXISTS pharmacist_profiles (
  user_id BIGINT UNSIGNED NOT NULL,
  pharmacy_name VARCHAR(190) NOT NULL,
  drug_license_no VARCHAR(80) NOT NULL,
  village_town VARCHAR(120) NOT NULL,
  full_address VARCHAR(255) NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_pharm_license (drug_license_no),
  CONSTRAINT fk_pharmacist_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
USE sehatsethu;

-- ===== users: add gate columns if missing =====
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS profile_completed TINYINT(1) NOT NULL DEFAULT 0 AFTER is_verified,
  ADD COLUMN IF NOT EXISTS profile_submitted_at TIMESTAMP NULL DEFAULT NULL AFTER profile_completed,
  ADD COLUMN IF NOT EXISTS admin_verification_status ENUM('PENDING','UNDER_REVIEW','VERIFIED','REJECTED') NOT NULL DEFAULT 'PENDING' AFTER profile_submitted_at,
  ADD COLUMN IF NOT EXISTS admin_verified_by BIGINT UNSIGNED NULL DEFAULT NULL AFTER admin_verification_status,
  ADD COLUMN IF NOT EXISTS admin_verified_at TIMESTAMP NULL DEFAULT NULL AFTER admin_verified_by,
  ADD COLUMN IF NOT EXISTS admin_rejection_reason VARCHAR(255) NULL DEFAULT NULL AFTER admin_verified_at;

-- ===== professional_verifications table (if you use it) =====
CREATE TABLE IF NOT EXISTS professional_verifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  role ENUM('DOCTOR','PHARMACIST') NOT NULL,
  status ENUM('PENDING','UNDER_REVIEW','VERIFIED','REJECTED') NOT NULL DEFAULT 'PENDING',
  submitted_at TIMESTAMP NULL DEFAULT NULL,
  reviewed_by BIGINT UNSIGNED NULL DEFAULT NULL,
  reviewed_at TIMESTAMP NULL DEFAULT NULL,
  rejection_reason VARCHAR(255) NULL DEFAULT NULL,
  notes TEXT NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_prof_user (user_id),
  KEY idx_prof_status (status),
  CONSTRAINT fk_prof_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS doctor_documents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  doc_type ENUM('MEDICAL_LICENSE','AADHAAR','MBBS_CERT') NOT NULL,
  file_url VARCHAR(255) NOT NULL,
  file_name VARCHAR(190) NULL,
  mime_type VARCHAR(80) NULL,
  file_size BIGINT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_doc_user_type (user_id, doc_type),
  KEY idx_doc_user (user_id),
  CONSTRAINT fk_doc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 2) Add application number for admin review (generated by server)


-- 3) Optional: store phone in doctor profile (safe even if you don’t use it)
ALTER TABLE doctor_profiles
  ADD COLUMN IF NOT EXISTS phone VARCHAR(30) NULL AFTER practice_place;

  USE sehatsethu;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS profile_submitted_at TIMESTAMP NULL DEFAULT NULL AFTER profile_completed;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS admin_verification_status ENUM('PENDING','UNDER_REVIEW','VERIFIED','REJECTED')
    NOT NULL DEFAULT 'PENDING' AFTER profile_submitted_at;

CREATE INDEX IF NOT EXISTS idx_users_role_status_applied
  ON users (role, admin_verification_status, profile_submitted_at);
CREATE TABLE IF NOT EXISTS pharmacist_documents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  doc_index INT NOT NULL,
  file_url VARCHAR(500) NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(80) NOT NULL,
  file_size INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pharm_docs_user (user_id),
  CONSTRAINT fk_pharm_docs_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB;
ALTER TABLE professional_verifications
  ADD COLUMN IF NOT EXISTS application_no VARCHAR(40) NULL AFTER id;

CREATE UNIQUE INDEX IF NOT EXISTS uq_prof_app_no
  ON professional_verifications (application_no);
ALTER TABLE pharmacist_documents
  ADD COLUMN IF NOT EXISTS doc_type VARCHAR(40) NULL AFTER doc_index;



ALTER TABLE users ADD COLUMN phone VARCHAR(20) NULL AFTER email;

USE sehatsethu;

ALTER TABLE patient_profiles
  ADD COLUMN medical_history LONGTEXT NULL AFTER district;



  USE sehatsethu;

-- Notifications (unread badge)
CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  body TEXT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notif_user_read (user_id, is_read),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Pharmacy inventory
CREATE TABLE IF NOT EXISTS pharmacy_inventory (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pharmacist_user_id BIGINT UNSIGNED NOT NULL,
  medicine_name VARCHAR(190) NOT NULL,
  strength VARCHAR(80) NULL,
  quantity INT NOT NULL DEFAULT 0,
  reorder_level INT NOT NULL DEFAULT 5,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pharm_item (pharmacist_user_id, medicine_name, strength),
  KEY idx_pharm_inv_user (pharmacist_user_id),
  KEY idx_pharm_inv_qty (pharmacist_user_id, quantity),
  CONSTRAINT fk_pharm_inv_user FOREIGN KEY (pharmacist_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
ALTER TABLE pharmacy_inventory
  ADD COLUMN price DECIMAL(10,2) NULL AFTER reorder_level;

-- Patient medicine requests to pharmacy
CREATE TABLE IF NOT EXISTS medicine_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  patient_user_id BIGINT UNSIGNED NULL,
  pharmacist_user_id BIGINT UNSIGNED NOT NULL,
  medicine_query VARCHAR(255) NOT NULL,
  status ENUM('OPEN','CLOSED') NOT NULL DEFAULT 'OPEN',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_req_pharm_time (pharmacist_user_id, created_at),
  CONSTRAINT fk_req_patient FOREIGN KEY (patient_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_req_pharm FOREIGN KEY (pharmacist_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;



ALTER TABLE doctor_profiles
  ADD COLUMN fee_amount INT NULL DEFAULT 200,
  ADD COLUMN languages_csv VARCHAR(255) NULL DEFAULT 'Hindi, English',
  ADD COLUMN rating DECIMAL(2,1) NULL DEFAULT 5,
  ADD COLUMN reviews_count INT NULL DEFAULT 0,
  ADD COLUMN consultations_count INT NULL DEFAULT 0,
  ADD COLUMN about_text TEXT NULL DEFAULT 'Not Set',
  ADD COLUMN education_text VARCHAR(255) NULL DEFAULT 'Not Set',
  ADD COLUMN works_at_text VARCHAR(255) NULL DEFAULT 'Not Set';

-- Notifications table (for bell badge)
CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  body TEXT NOT NULL,
  is_read TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notif_user_read (user_id, is_read),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS appointments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  patient_id BIGINT UNSIGNED NOT NULL,
  doctor_id  BIGINT UNSIGNED NOT NULL,

  speciality_key VARCHAR(64) NOT NULL,      -- e.g., ORTHOPEDICS
  consult_type   ENUM('AUDIO','VIDEO') NOT NULL DEFAULT 'AUDIO',

  appointment_date DATE NOT NULL,
  appointment_time CHAR(5) NOT NULL,        -- HH:MM (24h)
  duration_minutes INT NOT NULL DEFAULT 30, -- slot duration
  symptoms TEXT NULL,

  status ENUM('BOOKED','CONFIRMED','COMPLETED','CANCELLED') NOT NULL DEFAULT 'BOOKED',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_doc_date (doctor_id, appointment_date),
  KEY idx_patient_date (patient_id, appointment_date),

  CONSTRAINT fk_appt_patient FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_appt_doctor  FOREIGN KEY (doctor_id)  REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE appointments
  ADD UNIQUE KEY uq_doc_slot (doctor_id, appointment_date, appointment_time);


CREATE TABLE IF NOT EXISTS doctor_availability (
  user_id BIGINT UNSIGNED NOT NULL,
  day_of_week TINYINT NOT NULL,   -- 1=Mon ... 7=Sun
  enabled TINYINT NOT NULL DEFAULT 0,
  start_time CHAR(5) NOT NULL DEFAULT '09:00',
  end_time   CHAR(5) NOT NULL DEFAULT '17:00',
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, day_of_week),
  CONSTRAINT fk_doc_av_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


ALTER TABLE appointments
ADD UNIQUE KEY uq_doctor_slot (doctor_id, scheduled_at);
ALTER TABLE appointments
ADD UNIQUE KEY uq_doctor_scheduled (doctor_id, scheduled_at);
