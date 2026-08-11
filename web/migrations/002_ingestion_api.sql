-- Catalog Enterprise — migração 002
-- API de ingestão segura para o indexador Linux remoto.

USE catalog_hdd;

CREATE TABLE IF NOT EXISTS api_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    token_prefix VARCHAR(16) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    scopes VARCHAR(255) NOT NULL DEFAULT 'index',
    expires_at DATETIME NULL,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_api_tokens_hash (token_hash),
    KEY idx_api_tokens_user_active (user_id, revoked_at, expires_at),
    CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preenche chaves de caminhos existentes para que a sincronização futura seja idempotente.
-- IGNORE mantém o primeiro registro caso haja duplicidade legada para o mesmo caminho no volume.
UPDATE IGNORE files
SET path_hash = SHA2(path, 256)
WHERE path_hash IS NULL AND path IS NOT NULL;

UPDATE files
SET extension = LOWER(SUBSTRING_INDEX(name, '.', -1))
WHERE (extension IS NULL OR extension = '') AND name LIKE '%.%';

UPDATE files
SET file_type = CASE
    WHEN extension IN ('jpg','jpeg','png','gif','webp','heic','raw') THEN 'image'
    WHEN extension IN ('mp4','mkv','avi','mov','webm','m4v') THEN 'video'
    WHEN extension IN ('mp3','flac','wav','ogg','m4a') THEN 'audio'
    WHEN extension IN ('pdf','doc','docx','xls','xlsx','odt','epub','txt','md') THEN 'document'
    WHEN extension IN ('zip','7z','rar','tar','gz','iso') THEN 'archive'
    ELSE 'file'
END
WHERE file_type IS NULL OR file_type = '';
