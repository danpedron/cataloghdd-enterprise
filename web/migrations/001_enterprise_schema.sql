-- Catalog Enterprise — migração 001
-- Preserva todas as tabelas e registros existentes.

USE catalog_hdd;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(64) NOT NULL,
    email VARCHAR(255) NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','operator','viewer') NOT NULL DEFAULT 'viewer',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    force_password_change TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_active_role (is_active, role),
    CONSTRAINT fk_users_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    session_hash CHAR(64) NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent_hash CHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sessions_hash (session_hash),
    KEY idx_sessions_user_active (user_id, revoked_at, expires_at),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(64) NULL,
    ip_address VARCHAR(45) NOT NULL,
    was_successful TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_attempts_limiter (username, ip_address, was_successful, attempted_at),
    KEY idx_login_attempts_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL,
    event_type VARCHAR(80) NOT NULL,
    resource_type VARCHAR(80) NULL,
    resource_id VARCHAR(80) NULL,
    details_json JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(512) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_created (created_at),
    KEY idx_audit_user_created (user_id, created_at),
    KEY idx_audit_event_created (event_type, created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS disk_access (
    user_id INT UNSIGNED NOT NULL,
    disk_id INT(11) NOT NULL,
    permission ENUM('view','manage') NOT NULL DEFAULT 'view',
    granted_by INT UNSIGNED NULL,
    granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, disk_id),
    KEY idx_disk_access_disk (disk_id),
    CONSTRAINT fk_disk_access_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_disk_access_disk FOREIGN KEY (disk_id) REFERENCES disks(id) ON DELETE CASCADE,
    CONSTRAINT fk_disk_access_granted_by FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS index_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    disk_id INT(11) NULL,
    requested_by INT UNSIGNED NULL,
    source_host VARCHAR(255) NULL,
    root_path VARCHAR(1024) NOT NULL,
    status ENUM('running','completed','completed_with_errors','failed') NOT NULL DEFAULT 'running',
    files_discovered INT UNSIGNED NOT NULL DEFAULT 0,
    files_indexed INT UNSIGNED NOT NULL DEFAULT 0,
    thumbnails_created INT UNSIGNED NOT NULL DEFAULT 0,
    errors_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_summary TEXT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_index_runs_disk_started (disk_id, started_at),
    KEY idx_index_runs_status_started (status, started_at),
    CONSTRAINT fk_index_runs_disk FOREIGN KEY (disk_id) REFERENCES disks(id) ON DELETE SET NULL,
    CONSTRAINT fk_index_runs_user FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE disks
    ADD COLUMN IF NOT EXISTS root_path VARCHAR(1024) NULL AFTER capacity,
    ADD COLUMN IF NOT EXISTS filesystem VARCHAR(64) NULL AFTER root_path,
    ADD COLUMN IF NOT EXISTS status ENUM('active','archived','missing','maintenance') NOT NULL DEFAULT 'active' AFTER filesystem,
    ADD COLUMN IF NOT EXISTS is_protected TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN IF NOT EXISTS last_indexed_at DATETIME NULL AFTER is_protected,
    ADD COLUMN IF NOT EXISTS last_seen_at DATETIME NULL AFTER last_indexed_at,
    ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

ALTER TABLE files
    ADD COLUMN IF NOT EXISTS path_hash CHAR(64) NULL AFTER path,
    ADD COLUMN IF NOT EXISTS extension VARCHAR(32) NULL AFTER name,
    ADD COLUMN IF NOT EXISTS mime_type VARCHAR(255) NULL AFTER extension,
    ADD COLUMN IF NOT EXISTS file_type VARCHAR(32) NULL AFTER mime_type,
    ADD COLUMN IF NOT EXISTS thumbnail_key CHAR(64) NULL AFTER file_type,
    ADD COLUMN IF NOT EXISTS metadata_json JSON NULL AFTER thumbnail_key,
    ADD COLUMN IF NOT EXISTS indexed_at DATETIME NULL AFTER metadata_json,
    ADD COLUMN IF NOT EXISTS last_seen_at DATETIME NULL AFTER indexed_at,
    ADD COLUMN IF NOT EXISTS is_deleted TINYINT(1) NOT NULL DEFAULT 0 AFTER last_seen_at;

CREATE INDEX IF NOT EXISTS idx_files_name ON files(name);
CREATE INDEX IF NOT EXISTS idx_files_disk_name ON files(disk_id, name);
CREATE INDEX IF NOT EXISTS idx_files_disk_state ON files(disk_id, is_deleted);
CREATE INDEX IF NOT EXISTS idx_files_mime_type ON files(mime_type);
CREATE UNIQUE INDEX IF NOT EXISTS uq_files_disk_path_hash ON files(disk_id, path_hash);
CREATE INDEX IF NOT EXISTS idx_disks_status ON disks(status);
CREATE INDEX IF NOT EXISTS idx_disks_last_indexed ON disks(last_indexed_at);

-- Os registros legados permanecem pesquisáveis. O indexador novo preencherá os campos
-- adicionais apenas em novas varreduras, sem remover conteúdo já catalogado.
