-- CatalogHDD Enterprise — migration 009
-- Observações e risco operacional editáveis por administradores de volume.

USE catalog_hdd;

ALTER TABLE disks
    ADD COLUMN IF NOT EXISTS observation TEXT NULL AFTER is_protected,
    ADD COLUMN IF NOT EXISTS risco TEXT NULL AFTER observation;
