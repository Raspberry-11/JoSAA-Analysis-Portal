-- =====================================================================
-- JOSAA Analytics Portal — Star Schema (Read-heavy OLAP)
-- =====================================================================
CREATE DATABASE IF NOT EXISTS josaa_portal
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE josaa_portal;

DROP TABLE IF EXISTS fact_allotment;
DROP TABLE IF EXISTS dim_iit;
DROP TABLE IF EXISTS dim_branch;
DROP TABLE IF EXISTS dim_quota;
DROP TABLE IF EXISTS dim_seat_type;
DROP TABLE IF EXISTS dim_gender;

-- =========================
-- DIMENSION TABLES
-- =========================
CREATE TABLE dim_iit (
  iit_id       SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  iit_name     VARCHAR(120) NOT NULL UNIQUE,
  short_code   VARCHAR(20),
  founded_year SMALLINT,
  generation   ENUM('old','new') NOT NULL DEFAULT 'new',
  INDEX idx_generation (generation)
) ENGINE=InnoDB;

CREATE TABLE dim_branch (
  branch_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  branch_name VARCHAR(255) NOT NULL UNIQUE,
  category    ENUM('core','cse_family','new_age','interdisciplinary','other')
              NOT NULL DEFAULT 'other',
  INDEX idx_category (category)
) ENGINE=InnoDB;

CREATE TABLE dim_quota (
  quota_id   TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quota_code VARCHAR(20) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE dim_seat_type (
  seat_type_id   TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  seat_type_code VARCHAR(30) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE dim_gender (
  gender_id   TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gender_code VARCHAR(60) NOT NULL UNIQUE
) ENGINE=InnoDB;

-- =========================
-- FACT TABLE
-- =========================
CREATE TABLE fact_allotment (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  iit_id         SMALLINT UNSIGNED NOT NULL,
  branch_id      INT UNSIGNED NOT NULL,
  quota_id       TINYINT UNSIGNED NOT NULL,
  seat_type_id   TINYINT UNSIGNED NOT NULL,
  gender_id      TINYINT UNSIGNED NOT NULL,
  year           SMALLINT NOT NULL,
  round_no       TINYINT UNSIGNED NOT NULL,
  opening_rank   INT UNSIGNED NOT NULL,
  closing_rank   INT UNSIGNED NOT NULL,
  is_preparatory TINYINT(1) NOT NULL DEFAULT 0,

  CONSTRAINT fk_fa_iit    FOREIGN KEY (iit_id)       REFERENCES dim_iit(iit_id),
  CONSTRAINT fk_fa_branch FOREIGN KEY (branch_id)    REFERENCES dim_branch(branch_id),
  CONSTRAINT fk_fa_quota  FOREIGN KEY (quota_id)     REFERENCES dim_quota(quota_id),
  CONSTRAINT fk_fa_seat   FOREIGN KEY (seat_type_id) REFERENCES dim_seat_type(seat_type_id),
  CONSTRAINT fk_fa_gender FOREIGN KEY (gender_id)    REFERENCES dim_gender(gender_id),

  -- Composite indexes tuned for common filter + aggregate query shapes
  INDEX idx_year_iit_branch   (year, iit_id, branch_id),
  INDEX idx_branch_seat_year  (branch_id, seat_type_id, year),
  INDEX idx_iit_year_round    (iit_id, year, round_no),
  INDEX idx_seat_gender_year  (seat_type_id, gender_id, year),
  INDEX idx_closing_rank      (closing_rank),
  INDEX idx_year_round        (year, round_no)
) ENGINE=InnoDB;

-- =========================
-- AI QUERY CACHE
-- =========================
-- Stores normalized natural-language questions + their generated response
-- (SQL, data, chart config, explanation) so repeated questions don't burn tokens.
DROP TABLE IF EXISTS ai_queries;
CREATE TABLE ai_queries (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cache_key        CHAR(64) NOT NULL UNIQUE,          -- SHA-256 of question+context
  question         TEXT NOT NULL,
  response_json    MEDIUMTEXT NOT NULL,                -- cached JSON response
  hit_count        INT UNSIGNED NOT NULL DEFAULT 0,
  created_at       DATETIME NOT NULL,
  last_accessed_at DATETIME NOT NULL,
  INDEX idx_last_accessed (last_accessed_at),
  INDEX idx_hit_count     (hit_count DESC)
) ENGINE=InnoDB;
