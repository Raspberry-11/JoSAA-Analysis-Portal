-- =====================================================================
-- Migration: Add AI Query Cache table
-- =====================================================================
-- Run this ONLY if you already imported data using an older schema.sql
-- and don't want to re-import everything.
--
-- Via phpMyAdmin:
--   1. Select the josaa_portal database
--   2. Click the SQL tab
--   3. Paste and run this script

USE josaa_portal;

CREATE TABLE IF NOT EXISTS ai_queries (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cache_key        CHAR(64) NOT NULL UNIQUE,
  question         TEXT NOT NULL,
  response_json    MEDIUMTEXT NOT NULL,
  hit_count        INT UNSIGNED NOT NULL DEFAULT 0,
  created_at       DATETIME NOT NULL,
  last_accessed_at DATETIME NOT NULL,
  INDEX idx_last_accessed (last_accessed_at),
  INDEX idx_hit_count     (hit_count DESC)
) ENGINE=InnoDB;
