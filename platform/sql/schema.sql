-- WKC Voting System schema (MySQL 5.7+ / MariaDB 10.3+).
-- JSON-ish columns are MEDIUMTEXT for compatibility with older MariaDB on shared hosting.

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(64) NOT NULL PRIMARY KEY,
  v MEDIUMTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admins (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  session_epoch INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  last_login_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(64) NOT NULL,
  attempted_at DATETIME NOT NULL,
  KEY ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS media (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  filename VARCHAR(128) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime VARCHAR(64) NOT NULL,
  size INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS polls (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(80) NOT NULL UNIQUE,
  type ENUM('single','multi','ranked') NOT NULL DEFAULT 'single',
  eyebrow VARCHAR(120) NOT NULL DEFAULT '',
  title VARCHAR(400) NOT NULL,
  subtitle VARCHAR(600) NOT NULL DEFAULT '',
  picks TINYINT UNSIGNED NOT NULL DEFAULT 1,          -- multi: max picks, ranked: how many to rank
  points VARCHAR(120) NOT NULL DEFAULT '[3,2,1]',     -- ranked: points per rank (JSON array)
  comment_mode ENUM('off','optional','required') NOT NULL DEFAULT 'off',
  comment_label VARCHAR(255) NOT NULL DEFAULT '',
  comment_max SMALLINT UNSIGNED NOT NULL DEFAULT 500,
  votes_per_device TINYINT UNSIGNED NOT NULL DEFAULT 1,
  access ENUM('open','code') NOT NULL DEFAULT 'open',
  results_visibility ENUM('always','after_vote','after_close','hidden') NOT NULL DEFAULT 'always',
  top_n TINYINT UNSIGNED NOT NULL DEFAULT 5,
  chart ENUM('bars','pie','both') NOT NULL DEFAULT 'bars',
  answers_wall TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('draft','open','closed') NOT NULL DEFAULT 'draft',
  opens_at DATETIME NULL,
  closes_at DATETIME NULL,
  reveal ENUM('live','hidden','revealed') NOT NULL DEFAULT 'live',
  logo_media_id INT UNSIGNED NULL,
  accent VARCHAR(9) NOT NULL DEFAULT '',
  texts MEDIUMTEXT NULL,                              -- per-poll text overrides (JSON object)
  version INT UNSIGNED NOT NULL DEFAULT 1,            -- bumped on every vote/change (live updates)
  archived TINYINT(1) NOT NULL DEFAULT 0,
  deleted_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS options (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  poll_id INT UNSIGNED NOT NULL,
  label VARCHAR(255) NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  icon_type ENUM('initials','emoji','image','none') NOT NULL DEFAULT 'initials',
  icon_value VARCHAR(64) NOT NULL DEFAULT '',          -- emoji text or media id
  sort_order INT NOT NULL DEFAULT 0,
  hidden TINYINT(1) NOT NULL DEFAULT 0,
  KEY poll_order (poll_id, sort_order),
  CONSTRAINT fk_options_poll FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A ballot is one submission. It is deliberately NOT linked to any device or code.
CREATE TABLE IF NOT EXISTS ballots (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  poll_id INT UNSIGNED NOT NULL,
  created_hour DATETIME NOT NULL,                     -- truncated to the hour for anonymity
  KEY poll (poll_id),
  CONSTRAINT fk_ballots_poll FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ballot_choices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ballot_id INT UNSIGNED NOT NULL,
  poll_id INT UNSIGNED NOT NULL,
  option_id INT UNSIGNED NOT NULL,
  rank_pos TINYINT UNSIGNED NOT NULL DEFAULT 1,
  comment TEXT NULL,
  comment_hidden TINYINT(1) NOT NULL DEFAULT 0,
  KEY poll_option (poll_id, option_id),
  KEY ballot (ballot_id),
  CONSTRAINT fk_choices_ballot FOREIGN KEY (ballot_id) REFERENCES ballots(id) ON DELETE CASCADE,
  CONSTRAINT fk_choices_option FOREIGN KEY (option_id) REFERENCES options(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Access codes: special ballots / QR cards. Claimed by the first device that uses them.
CREATE TABLE IF NOT EXISTS access_codes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  poll_id INT UNSIGNED NOT NULL,
  code VARCHAR(24) NOT NULL UNIQUE,
  label VARCHAR(120) NOT NULL DEFAULT '',
  votes_allowed TINYINT UNSIGNED NOT NULL DEFAULT 1,
  claimed_by CHAR(64) NULL,
  created_at DATETIME NOT NULL,
  KEY poll (poll_id),
  CONSTRAINT fk_codes_poll FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- How many submissions each (hashed) device has used, per poll. Never linked to ballots.
CREATE TABLE IF NOT EXISTS voters (
  poll_id INT UNSIGNED NOT NULL,
  device_hash CHAR(64) NOT NULL,
  used TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (poll_id, device_hash),
  CONSTRAINT fk_voters_poll FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  action VARCHAR(64) NOT NULL,
  detail VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  KEY created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
