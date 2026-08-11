<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';
const APP_ENV_FILE = '/etc/catalog-enterprise/catalog.env';

function appConfig(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    if (!is_readable(APP_ENV_FILE)) {
        http_response_code(500);
        exit('Configuração da aplicação indisponível.');
    }

    $loaded = parse_ini_file(APP_ENV_FILE, false, INI_SCANNER_RAW);
    if (!is_array($loaded)) {
        http_response_code(500);
        exit('Configuração da aplicação inválida.');
    }

    $required = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'APP_BASE_PATH', 'THUMBNAIL_DIR'];
    foreach ($required as $key) {
        if (!array_key_exists($key, $loaded)) {
            http_response_code(500);
            exit('Configuração incompleta da aplicação.');
        }
    }

    $config = $loaded;
    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = appConfig();
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $cfg['DB_HOST'], $cfg['DB_NAME']);
    $pdo = new PDO($dsn, $cfg['DB_USER'], $cfg['DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function defaultIndexSettings(): array
{
    return [
        'index_archives' => true,
        'generate_thumbnails' => true,
        'thumbnail_max_px' => 256,
        'thumbnail_quality' => 70,
        'max_thumbnail_source_bytes' => 3 * 1024 * 1024 * 1024,
        'max_archive_entries' => 20000,
        'max_archive_bytes' => 8 * 1024 * 1024 * 1024,
    ];
}

function indexSettings(): array
{
    $settings = defaultIndexSettings();
    try {
        $rows = db()->query('SELECT setting_key,value_json FROM app_settings')->fetchAll();
        foreach ($rows as $row) {
            if (!array_key_exists($row['setting_key'], $settings)) continue;
            $value = json_decode((string)$row['value_json'], true);
            if (is_bool($settings[$row['setting_key']])) $settings[$row['setting_key']] = (bool)$value;
            elseif (is_int($settings[$row['setting_key']]) && is_numeric($value)) $settings[$row['setting_key']] = (int)$value;
        }
    } catch (Throwable) {
        // O cliente mantém defaults seguros se a migração ainda não foi aplicada.
    }
    return $settings;
}

function saveIndexSettings(array $settings, int $userId): void
{
    $allowed = defaultIndexSettings();
    $stmt = db()->prepare('INSERT INTO app_settings (setting_key,value_json,updated_by) VALUES (:key,:value,:user_id) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_by=VALUES(updated_by),updated_at=NOW()');
    foreach ($allowed as $key => $default) {
        if (!array_key_exists($key, $settings)) continue;
        $value = is_bool($default) ? (bool)$settings[$key] : max(0, (int)$settings[$key]);
        $stmt->execute([':key'=>$key, ':value'=>json_encode($value), ':user_id'=>$userId]);
    }
}

function basePath(): string
{
    return rtrim(appConfig()['APP_BASE_PATH'], '/');
}

function url(string $route = 'dashboard', array $params = []): string
{
    $params = ['r' => $route] + $params;
    return basePath() . '/?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $route, array $params = []): never
{
    header('Location: ' . url($route, $params), true, 303);
    exit;
}

function requestMethod(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function route(): string
{
    return (string) ($_GET['r'] ?? 'dashboard');
}

function clientIp(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function userAgent(): string
{
    return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);
}

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name('catalog_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => basePath() . '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrfToken(): string
{
    startSecureSession();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verifyCsrf(): void
{
    $token = (string) ($_POST['csrf'] ?? '');
    if ($token === '' || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(419);
        exit('A solicitação expirou ou não é válida. Atualize a página e tente novamente.');
    }
}

function flash(string $type, string $message): void
{
    startSecureSession();
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function consumeFlash(): array
{
    startSecureSession();
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($messages) ? $messages : [];
}

function audit(string $event, ?string $resourceType = null, string|int|null $resourceId = null, array $details = []): void
{
    try {
        $userId = $_SESSION['user_id'] ?? null;
        $stmt = db()->prepare(
            'INSERT INTO audit_log (user_id, event_type, resource_type, resource_id, details_json, ip_address, user_agent)
             VALUES (:user_id, :event_type, :resource_type, :resource_id, :details_json, :ip_address, :user_agent)'
        );
        $stmt->execute([
            ':user_id' => is_numeric($userId) ? (int) $userId : null,
            ':event_type' => $event,
            ':resource_type' => $resourceType,
            ':resource_id' => $resourceId === null ? null : (string) $resourceId,
            ':details_json' => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':ip_address' => clientIp(),
            ':user_agent' => userAgent(),
        ]);
    } catch (Throwable) {
        // Auditoria não pode derrubar uma consulta de leitura se o banco estiver indisponível.
    }
}

function currentUser(bool $refresh = false): ?array
{
    static $cached = null;
    if (!$refresh && $cached !== null) {
        return $cached;
    }

    startSecureSession();
    $userId = $_SESSION['user_id'] ?? null;
    $nonce = $_SESSION['session_nonce'] ?? null;
    if (!is_int($userId) && !ctype_digit((string) $userId)) {
        return null;
    }
    if (!is_string($nonce) || $nonce === '') {
        return null;
    }

    $hash = hash('sha256', $nonce);
    $stmt = db()->prepare(
        'SELECT u.id, u.username, u.email, u.role, u.is_active, u.force_password_change
         FROM users u
         INNER JOIN user_sessions s ON s.user_id = u.id
         WHERE u.id = :user_id AND s.session_hash = :hash
           AND s.revoked_at IS NULL AND s.expires_at > NOW() AND u.is_active = 1
         LIMIT 1'
    );
    $stmt->execute([':user_id' => (int) $userId, ':hash' => $hash]);
    $user = $stmt->fetch();
    if (!$user) {
        clearSession();
        return null;
    }

    db()->prepare('UPDATE user_sessions SET last_seen_at = NOW() WHERE session_hash = :hash')
        ->execute([':hash' => $hash]);
    $cached = $user;
    return $cached;
}

function clearSession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        startSecureSession();
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?? '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}

function requireLogin(): array
{
    $user = currentUser();
    if ($user === null) {
        redirect('login');
    }
    $allowedWhilePasswordChangeRequired = ['profile', 'profile-save', 'logout'];
    if ((int) $user['force_password_change'] === 1 && !in_array(route(), $allowedWhilePasswordChangeRequired, true)) {
        flash('warning', 'Por segurança, defina uma nova senha antes de acessar o painel.');
        redirect('profile');
    }
    return $user;
}

function requireRole(string ...$roles): array
{
    $user = requireLogin();
    if (!in_array($user['role'], $roles, true)) {
        audit('authorization.denied', 'route', route(), ['required_roles' => $roles]);
        http_response_code(403);
        exit('Você não possui permissão para esta operação.');
    }
    return $user;
}

function canAccessDisk(array $user, int $diskId, bool $manage = false): bool
{
    if ($user['role'] === 'admin') {
        return true;
    }
    $stmt = db()->prepare(
        'SELECT permission FROM disk_access WHERE user_id = :user_id AND disk_id = :disk_id LIMIT 1'
    );
    $stmt->execute([':user_id' => (int) $user['id'], ':disk_id' => $diskId]);
    $permission = $stmt->fetchColumn();
    if ($permission === false) {
        return false;
    }
    return !$manage || $permission === 'manage';
}

function requireDiskAccess(int $diskId, bool $manage = false): array
{
    $user = requireLogin();
    if (!canAccessDisk($user, $diskId, $manage)) {
        audit('authorization.denied', 'disk', $diskId, ['manage_required' => $manage]);
        http_response_code(403);
        exit('Você não possui acesso a este volume.');
    }
    return $user;
}

function formatBytes(int|float|null $bytes): string
{
    $value = (float) ($bytes ?? 0);
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $index = 0;
    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }
    return number_format($value, $index === 0 ? 0 : 2, ',', '.') . ' ' . $units[$index];
}

function fileCategory(?string $mime, ?string $extension): string
{
    $mime = strtolower((string) $mime);
    $extension = strtolower((string) $extension);
    if (str_starts_with($mime, 'image/') || in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'raw'], true)) return 'image';
    if (str_starts_with($mime, 'video/') || in_array($extension, ['mp4', 'mkv', 'avi', 'mov', 'webm', 'm4v'], true)) return 'video';
    if (str_starts_with($mime, 'audio/') || in_array($extension, ['mp3', 'flac', 'wav', 'ogg', 'm4a'], true)) return 'audio';
    if (str_starts_with($mime, 'text/') || in_array($extension, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'odt', 'epub', 'txt', 'md'], true)) return 'document';
    if (in_array($extension, ['zip', '7z', 'rar', 'tar', 'gz', 'iso'], true)) return 'archive';
    return 'file';
}

function pageNumber(): int
{
    return max(1, min(100000, (int) ($_GET['page'] ?? 1)));
}

function pagination(int $total, int $page, int $perPage = 50): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    return ['page' => min($page, $pages), 'pages' => $pages, 'per_page' => $perPage, 'offset' => (min($page, $pages) - 1) * $perPage];
}
