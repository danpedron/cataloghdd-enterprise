-- Índice materializado para o explorador hierárquico.
-- Cada pasta e arquivo é registrado sob o hash do seu diretório-pai, permitindo consultas
-- de navegação por igualdade em vez de varrer todos os descendentes por LIKE.

USE catalog_hdd;

CREATE TABLE IF NOT EXISTS file_browser_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    disk_id INT(11) NOT NULL,
    partition_id INT(11) NULL,
    parent_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    name VARCHAR(255) NOT NULL,
    is_directory TINYINT(1) NOT NULL DEFAULT 0,
    file_id INT(11) NULL,
    last_seen_at DATETIME NULL,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_browser_entry (disk_id, parent_hash, name, is_directory),
    KEY idx_browser_entries_folder (disk_id, parent_hash, is_deleted, is_directory, name),
    KEY idx_browser_entries_file (file_id),
    CONSTRAINT fk_browser_entries_disk FOREIGN KEY (disk_id) REFERENCES disks(id) ON DELETE CASCADE,
    CONSTRAINT fk_browser_entries_partition FOREIGN KEY (partition_id) REFERENCES disk_partitions(id) ON DELETE SET NULL,
    CONSTRAINT fk_browser_entries_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
