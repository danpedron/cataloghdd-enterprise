#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CatalogHDD Enterprise — restaurador completo do banco de dados.
 *
 * Uso típico na VPS:
 *   sudo php web/bin/rebuild_database.php --env=/etc/catalog-enterprise/catalog.env --create-database
 *
 * O script não remove dados por padrão. Para zerar um banco existente, acrescente:
 *   --reset --confirm-reset
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este utilitário só pode ser executado pela linha de comando.\n");
    exit(1);
}

$options = getopt('', ['env:', 'database:', 'create-database', 'reset', 'confirm-reset', 'admin-dsn::', 'admin-user::', 'admin-pass::']);
$defaultEnv = is_readable('/etc/catalog-enterprise/catalog.env') ? '/etc/catalog-enterprise/catalog.env' : '/etc/cataloghdd/catalog.env';
$envPath = (string)($options['env'] ?? getenv('CATALOG_ENV_FILE') ?: $defaultEnv);

function fail(string $message): never
{
    fwrite(STDERR, "ERRO: {$message}\n");
    exit(1);
}

function loadEnv(string $path): array
{
    if (!is_readable($path)) fail("Não foi possível ler o arquivo de ambiente: {$path}");
    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $values[trim($key)] = trim(trim($value), "\"'");
    }
    return $values;
}

function identifier(string $value, string $label): string
{
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $value)) fail("{$label} inválido.");
    return '`' . $value . '`';
}

function connection(string $dsn, string $user, string $pass): PDO
{
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function compressed(string $sql): string
{
    return rtrim($sql) . ' ENGINE=InnoDB ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
}

function tableStatements(): array
{
    return [
        'disks' => compressed(<<<'SQL'
CREATE TABLE IF NOT EXISTS disks (
    id INT NOT NULL AUTO_INCREMENT,
    label VARCHAR(255) NULL,
    serial VARCHAR(255) NULL,
    model VARCHAR(255) NULL,
    capacity BIGINT UNSIGNED NULL,
    indexed_file_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    indexed_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    root_path VARCHAR(1024) NULL,
    filesystem VARCHAR(64) NULL,
    status ENUM('active','archived','missing','maintenance') NOT NULL DEFAULT 'active',
    is_protected TINYINT(1) NOT NULL DEFAULT 0,
    observation TEXT NULL,
    risco TEXT NULL,
    last_indexed_at DATETIME NULL,
    last_seen_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_disks_serial (serial),
    KEY idx_disks_status (status),
    KEY idx_disks_last_indexed (last_indexed_at)
)
SQL),
        'users' => compressed(<<<'SQL'
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
)
SQL),
        'index_runs' => compressed(<<<'SQL'
CREATE TABLE IF NOT EXISTS index_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    disk_id INT NULL,
    requested_by INT UNSIGNED NULL,
    source_host VARCHAR(255) NULL,
    client_version VARCHAR(64) NULL,
    options_json JSON NULL,
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
)
SQL),
        'disk_partitions' => compressed(<<<'SQL'
CREATE TABLE IF NOT EXISTS disk_partitions (
    id INT NOT NULL AUTO_INCREMENT,
    disk_id INT NOT NULL,
    partition_number INT UNSIGNED NOT NULL,
    device_path VARCHAR(255) NOT NULL,
    partuuid VARCHAR(128) NULL,
    filesystem_uuid VARCHAR(255) NULL,
    label VARCHAR(255) NULL,
    filesystem VARCHAR(64) NULL,
    capacity BIGINT UNSIGNED NULL,
    indexed_file_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    indexed_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    mount_point_hint VARCHAR(1024) NULL,
    status ENUM('indexed','mounted','unmounted','unsupported','encrypted','error','empty') NOT NULL DEFAULT 'unmounted',
    last_indexed_at DATETIME NULL,
    last_run_id BIGINT UNSIGNED NULL,
    last_index_host VARCHAR(255) NULL,
    last_seen_at DATETIME NULL,
    used_bytes BIGINT UNSIGNED NULL,
    free_bytes BIGINT UNSIGNED NULL,
    usage_updated_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_disk_partitions_number (disk_id, partition_number),
    KEY idx_disk_partitions_disk_status (disk_id, status),
    KEY idx_disk_partitions_partuuid (partuuid),
    KEY idx_disk_partitions_last_run (last_run_id),
    CONSTRAINT fk_disk_partitions_disk FOREIGN KEY (disk_id) REFERENCES disks(id) ON DELETE CASCADE,
    CONSTRAINT fk_disk_partitions_last_run FOREIGN KEY (last_run_id) REFERENCES index_runs(id) ON DELETE SET NULL
)
SQL),
        'files' => compressed(<<<'SQL'
CREATE TABLE IF NOT EXISTS files (
    id INT NOT NULL AUTO_INCREMENT,
    disk_id INT NOT NULL,
    partition_id INT NULL,
    path MEDIUMTEXT NOT NULL,
    path_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    name VARCHAR(255) NOT NULL,
    size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    modified DATETIME NULL,
    extension VARCHAR(32) NULL,
    mime_type VARCHAR(255) NULL,
    file_type VARCHAR(32) NULL,
    thumbnail_key CHAR(64) CHARACTER SET ascii COLLATE ascii_general_ci NULL,
    metadata_json JSON NULL,
    indexed_at DATETIME NULL,
    last_seen_at DATETIME NULL,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_files_disk_path_hash (disk_id, path_hash),
    KEY idx_files_name (name),
    KEY idx_files_disk_name (disk_id, name),
    KEY idx_files_disk_state (disk_id, is_deleted),
    KEY idx_files_mime_type (mime_type),
    KEY idx_files_partition (partition_id),
    CONSTRAINT fk_files_disk FOREIGN KEY (disk_id) REFERENCES disks(id) ON DELETE CASCADE,
    CONSTRAINT fk_files_partition FOREIGN KEY (partition_id) REFERENCES disk_partitions(id) ON DELETE SET NULL
)
SQL),
        'disk_access' => compressed(<<<'SQL'
CREATE TABLE IF NOT EXISTS disk_access (
    user_id INT UNSIGNED NOT NULL,
    disk_id INT NOT NULL,
    permission ENUM('view','manage') NOT NULL DEFAULT 'view',
    granted_by INT UNSIGNED NULL,
    granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, disk_id),
    KEY idx_disk_access_disk (disk_id),
    CONSTRAINT fk_disk_access_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_disk_access_disk FOREIGN KEY (disk_id) REFERENCES disks(id) ON DELETE CASCADE,
    CONSTRAINT fk_disk_access_granted_by FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
)
SQL),
        'user_sessions' => compressed(<<<'SQL'
CREATE TABLE IF NOT EXISTS user_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_general_ci NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sessions_hash (session_hash),
    KEY idx_sessions_user_active (user_id, revoked_at, expires_at),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)
SQL),
        'login_attempts' => compressed(<<<'SQL'
CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(64) NULL,
    ip_address VARCHAR(45) NOT NULL,
    was_successful TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_attempts_limiter (username, ip_address, was_successful, attempted_at),
    KEY idx_login_attempts_ip (ip_address, attempted_at)
)
SQL),
        'audit_log' => compressed(<<<'SQL'
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
)
SQL),
        'api_tokens' => compressed(<<<'SQL'
CREATE TABLE IF NOT EXISTS api_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    token_prefix VARCHAR(16) NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    scopes VARCHAR(255) NOT NULL DEFAULT 'index',
    expires_at DATETIME NULL,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_api_tokens_hash (token_hash),
    KEY idx_api_tokens_user_active (user_id, revoked_at, expires_at),
    CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)
SQL),
        'app_settings' => compressed(<<<'SQL'
CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(100) NOT NULL,
    value_json JSON NOT NULL,
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key),
    CONSTRAINT fk_app_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
)
SQL),
        'archive_scans' => compressed(<<<'SQL'
CREATE TABLE IF NOT EXISTS archive_scans (
    id INT NOT NULL AUTO_INCREMENT,
    archive_file_id INT NOT NULL,
    disk_id INT NOT NULL,
    partition_id INT NULL,
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
)
SQL),
        'virtual_entries' => compressed(<<<'SQL'
CREATE TABLE IF NOT EXISTS virtual_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    archive_scan_id INT NOT NULL,
    archive_file_id INT NOT NULL,
    disk_id INT NOT NULL,
    partition_id INT NULL,
    internal_path MEDIUMTEXT NOT NULL,
    path_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
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
)
SQL),
        'file_browser_entries' => compressed(<<<'SQL'
CREATE TABLE IF NOT EXISTS file_browser_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    disk_id INT NOT NULL,
    partition_id INT NULL,
    parent_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    name VARCHAR(255) NOT NULL,
    is_directory TINYINT(1) NOT NULL DEFAULT 0,
    file_id INT NULL,
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
)
SQL),
        // Tabelas de compatibilidade com o inventário inicial do projeto.
        'catalogs' => compressed(<<<'SQL'
CREATE TABLE IF NOT EXISTS catalogs (
    id INT NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    password_hash VARCHAR(255) NULL,
    is_protected TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_catalogs_name (name)
)
SQL),
        'media_files' => compressed(<<<'SQL'
CREATE TABLE IF NOT EXISTS media_files (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    catalog_id INT NULL,
    disk_id INT NULL,
    path MEDIUMTEXT NOT NULL,
    name VARCHAR(255) NOT NULL,
    size BIGINT UNSIGNED NULL,
    modified DATETIME NULL,
    mime_type VARCHAR(255) NULL,
    metadata_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_media_files_catalog (catalog_id),
    KEY idx_media_files_disk (disk_id),
    KEY idx_media_files_name (name),
    CONSTRAINT fk_media_files_catalog FOREIGN KEY (catalog_id) REFERENCES catalogs(id) ON DELETE SET NULL,
    CONSTRAINT fk_media_files_disk FOREIGN KEY (disk_id) REFERENCES disks(id) ON DELETE SET NULL
)
SQL),
        'metadata' => compressed(<<<'SQL'
CREATE TABLE IF NOT EXISTS metadata (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    file_id INT NULL,
    meta_key VARCHAR(255) NOT NULL,
    meta_value MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_metadata_file_key (file_id, meta_key),
    KEY idx_metadata_key (meta_key),
    CONSTRAINT fk_metadata_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE
)
SQL),
    ];
}

$env = loadEnv($envPath);
$database = (string)($options['database'] ?? $env['DB_NAME'] ?? 'catalog_hdd');
$quotedDatabase = identifier($database, 'Nome do banco');
$host = (string)($env['DB_HOST'] ?? '127.0.0.1');
$port = (int)($env['DB_PORT'] ?? 3306);
$appUser = (string)($env['DB_USER'] ?? '');
$appPass = (string)($env['DB_PASS'] ?? '');
if ($appUser === '') fail('DB_USER não foi definido no arquivo de ambiente.');
$baseDsn = "mysql:host={$host};port={$port};charset=utf8mb4";

$schemaPdo = null;
try {
    if (isset($options['create-database'])) {
        $adminDsn = (string)($options['admin-dsn'] ?? getenv('CATALOG_ADMIN_DSN') ?: $baseDsn);
        $adminUser = (string)($options['admin-user'] ?? getenv('CATALOG_ADMIN_USER') ?: $appUser);
        $adminPass = (string)($options['admin-pass'] ?? getenv('CATALOG_ADMIN_PASS') ?: $appPass);
        $createDatabase = static function (PDO $admin) use ($quotedDatabase): void {
            $admin->exec("CREATE DATABASE IF NOT EXISTS {$quotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        };
        try {
            $admin = connection($adminDsn, $adminUser, $adminPass);
            $createDatabase($admin);
        } catch (Throwable $firstError) {
            if (!is_file('/run/mysqld/mysqld.sock')) throw $firstError;
            try {
                $admin = connection('mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4', 'root', '');
                $createDatabase($admin);
            } catch (Throwable $socketError) {
                throw $firstError;
            }
        }
        $admin->exec("USE {$quotedDatabase}");
        $schemaPdo = $admin;
    }
    $applicationPdo = connection($baseDsn . ';dbname=' . $database, $appUser, $appPass);
    $pdo = $schemaPdo ?? $applicationPdo;
} catch (Throwable $error) {
    fail('Não foi possível conectar ao MariaDB. Para criar e estruturar o banco, use --create-database com uma conta administrativa ou execute como root no servidor. Detalhe: ' . $error->getMessage());
}

if (isset($options['reset'])) {
    if (!isset($options['confirm-reset'])) fail('A opção --reset exige --confirm-reset.');
    $tables = array_reverse(array_keys(tableStatements()));
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    fwrite(STDOUT, "Tabelas existentes removidas mediante confirmação explícita.\n");
}

try {
    foreach (tableStatements() as $table => $sql) {
        $pdo->exec($sql);
        fwrite(STDOUT, "OK  {$table}\n");
    }
    $settings = [
        'index_archives' => 'true', 'generate_thumbnails' => 'true', 'thumbnail_max_px' => '256',
        'thumbnail_quality' => '70', 'max_thumbnail_source_bytes' => '3221225472',
        'max_archive_entries' => '20000', 'max_archive_bytes' => '8589934592',
    ];
    $pdo->beginTransaction();
    $settingsStmt = $pdo->prepare('INSERT IGNORE INTO app_settings (setting_key,value_json) VALUES (:setting_key,:value_json)');
    foreach ($settings as $key => $value) $settingsStmt->execute([':setting_key'=>$key, ':value_json'=>$value]);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('Falha ao criar o schema: ' . $error->getMessage());
}

$expected = array_keys(tableStatements());
$placeholders = implode(',', array_fill(0, count($expected), '?'));
$check = $pdo->prepare("SELECT table_name,engine,row_format FROM information_schema.tables WHERE table_schema=? AND table_name IN ({$placeholders}) ORDER BY table_name");
$check->execute(array_merge([$database], $expected));
$created = $check->fetchAll();
if (count($created) !== count($expected)) fail('A validação final não encontrou todas as tabelas esperadas.');
foreach ($created as $table) {
    if (strtolower((string)$table['engine']) !== 'innodb') fail('A tabela ' . $table['table_name'] . ' não foi criada em InnoDB.');
    if (strtolower((string)$table['row_format']) !== 'compressed') fail('A tabela ' . $table['table_name'] . ' não recebeu ROW_FORMAT=COMPRESSED. Verifique innodb_file_per_table e o suporte do servidor.');
}

fwrite(STDOUT, "\nSchema reconstruído: " . count($created) . " tabelas InnoDB.\n");
fwrite(STDOUT, "Compactação solicitada: ROW_FORMAT=COMPRESSED, KEY_BLOCK_SIZE=8.\n");
fwrite(STDOUT, "Próximo passo: sudo -u www-data php web/bin/create_admin.php admin\n");
