-- Catalog Enterprise — migração 003
-- Inventário de partições de discos físicos enviados pelo cliente Debian.

USE catalog_hdd;

CREATE TABLE IF NOT EXISTS disk_partitions (
    id INT(11) NOT NULL AUTO_INCREMENT,
    disk_id INT(11) NOT NULL,
    partition_number INT UNSIGNED NOT NULL,
    device_path VARCHAR(255) NOT NULL,
    partuuid VARCHAR(128) NULL,
    filesystem_uuid VARCHAR(255) NULL,
    label VARCHAR(255) NULL,
    filesystem VARCHAR(64) NULL,
    capacity BIGINT UNSIGNED NULL,
    mount_point_hint VARCHAR(1024) NULL,
    status ENUM('indexed','mounted','unmounted','unsupported','encrypted','error','empty') NOT NULL DEFAULT 'unmounted',
    last_indexed_at DATETIME NULL,
    last_seen_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_disk_partitions_number (disk_id, partition_number),
    KEY idx_disk_partitions_disk_status (disk_id, status),
    KEY idx_disk_partitions_partuuid (partuuid),
    CONSTRAINT fk_disk_partitions_disk FOREIGN KEY (disk_id) REFERENCES disks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE files
    ADD COLUMN IF NOT EXISTS partition_id INT(11) NULL AFTER disk_id;

CREATE INDEX IF NOT EXISTS idx_files_partition ON files(partition_id);

-- A chave de arquivo existente continua vinculada ao disco e passa a ser estável porque
-- o cliente envia um caminho lógico com o número da partição.
