-- Métricas de capacidade coletadas pelo cliente durante a indexação.
-- Valores nulos indicam que o filesystem ainda não foi montado/indexado por um cliente compatível.

USE catalog_hdd;

ALTER TABLE disk_partitions
    ADD COLUMN IF NOT EXISTS used_bytes BIGINT UNSIGNED NULL AFTER capacity,
    ADD COLUMN IF NOT EXISTS free_bytes BIGINT UNSIGNED NULL AFTER used_bytes,
    ADD COLUMN IF NOT EXISTS usage_updated_at DATETIME NULL AFTER last_indexed_at;
