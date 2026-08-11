-- Catalog Enterprise — migração 004
-- Conteúdo virtual de arquivos compactados, sem extração no dispositivo de origem.

USE catalog_hdd;

CREATE TABLE IF NOT EXISTS archive_scans (
    id INT(11) NOT NULL AUTO_INCREMENT,
    archive_file_id INT(11) NOT NULL,
    disk_id INT(11) NOT NULL,
    partition_id INT(11) NULL,
    last_run_id BIGINT UNSIGNED NULL,
    archive_format VARCHAR(32) NULL,
    source_size BIGINT UNSIGNED NULL,
    source_modified DATETIME NULL,
    scan_status ENUM('running','completed','partial','error','unsupported') NOT NULL DEFAULT 'running',
    entries_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_summary TEXT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_archive_scans_file (archive_file_id),
    KEY idx_archive_scans_disk_status (disk_id, scan_status),
    CONSTRAINT fk_archive_scans_file FOREIGN KEY (archive_file_id) REFERENCES files(id) ON DELETE CASCADE,
    CONSTRAINT fk_archive_scans_disk FOREIGN KEY (disk_id) REFERENCES disks(id) ON DELETE CASCADE,
    CONSTRAINT fk_archive_scans_partition FOREIGN KEY (partition_id) REFERENCES disk_partitions(id) ON DELETE SET NULL,
    CONSTRAINT fk_archive_scans_run FOREIGN KEY (last_run_id) REFERENCES index_runs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS virtual_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    archive_scan_id INT(11) NOT NULL,
    archive_file_id INT(11) NOT NULL,
    disk_id INT(11) NOT NULL,
    partition_id INT(11) NULL,
    internal_path TEXT NOT NULL,
    path_hash CHAR(64) NOT NULL,
    name VARCHAR(255) NOT NULL,
    extension VARCHAR(32) NULL,
    size BIGINT UNSIGNED NULL,
    modified DATETIME NULL,
    mime_type VARCHAR(255) NULL,
    file_type VARCHAR(32) NOT NULL DEFAULT 'file',
    is_directory TINYINT(1) NOT NULL DEFAULT 0,
    metadata_json JSON NULL,
    indexed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_virtual_entries_archive_path (archive_file_id, path_hash),
    KEY idx_virtual_entries_name (name),
    KEY idx_virtual_entries_disk_state (disk_id, is_deleted),
    KEY idx_virtual_entries_partition (partition_id),
    KEY idx_virtual_entries_scan (archive_scan_id),
    CONSTRAINT fk_virtual_entries_scan FOREIGN KEY (archive_scan_id) REFERENCES archive_scans(id) ON DELETE CASCADE,
    CONSTRAINT fk_virtual_entries_file FOREIGN KEY (archive_file_id) REFERENCES files(id) ON DELETE CASCADE,
    CONSTRAINT fk_virtual_entries_disk FOREIGN KEY (disk_id) REFERENCES disks(id) ON DELETE CASCADE,
    CONSTRAINT fk_virtual_entries_partition FOREIGN KEY (partition_id) REFERENCES disk_partitions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
