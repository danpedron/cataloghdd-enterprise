-- Catalog Enterprise — migração 005
-- Preferências centralizadas, identificação do cliente e histórico operacional por partição.

USE catalog_hdd;

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(100) NOT NULL,
    value_json JSON NOT NULL,
    updated_by INT(10) UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key),
    CONSTRAINT fk_app_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO app_settings (setting_key, value_json) VALUES
    ('index_archives', 'true'),
    ('generate_thumbnails', 'true'),
    ('thumbnail_max_px', '256'),
    ('thumbnail_quality', '70'),
    ('max_thumbnail_source_bytes', '3221225472'),
    ('max_archive_entries', '20000'),
    ('max_archive_bytes', '8589934592');

ALTER TABLE index_runs
    ADD COLUMN IF NOT EXISTS client_version VARCHAR(64) NULL AFTER source_host,
    ADD COLUMN IF NOT EXISTS options_json JSON NULL AFTER client_version;

ALTER TABLE disk_partitions
    ADD COLUMN IF NOT EXISTS last_run_id BIGINT UNSIGNED NULL AFTER last_indexed_at,
    ADD COLUMN IF NOT EXISTS last_index_host VARCHAR(255) NULL AFTER last_run_id;

CREATE INDEX IF NOT EXISTS idx_disk_partitions_last_run ON disk_partitions(last_run_id);
