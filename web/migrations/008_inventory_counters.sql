-- Resumos persistidos do inventário para carregamento imediato de volumes e partições.

USE catalog_hdd;

ALTER TABLE disks
    ADD COLUMN IF NOT EXISTS indexed_file_count BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER capacity,
    ADD COLUMN IF NOT EXISTS indexed_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER indexed_file_count;

ALTER TABLE disk_partitions
    ADD COLUMN IF NOT EXISTS indexed_file_count BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER capacity,
    ADD COLUMN IF NOT EXISTS indexed_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER indexed_file_count;

UPDATE disks d
LEFT JOIN (
    SELECT disk_id,COUNT(*) AS file_count,COALESCE(SUM(size),0) AS size_bytes
    FROM files
    WHERE is_deleted=0
    GROUP BY disk_id
) f ON f.disk_id=d.id
SET d.indexed_file_count=COALESCE(f.file_count,0),
    d.indexed_size_bytes=COALESCE(f.size_bytes,0);

UPDATE disk_partitions p
LEFT JOIN (
    SELECT partition_id,COUNT(*) AS file_count,COALESCE(SUM(size),0) AS size_bytes
    FROM files
    WHERE is_deleted=0 AND partition_id IS NOT NULL
    GROUP BY partition_id
) f ON f.partition_id=p.id
SET p.indexed_file_count=COALESCE(f.file_count,0),
    p.indexed_size_bytes=COALESCE(f.size_bytes,0);
