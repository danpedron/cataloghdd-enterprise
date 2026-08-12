<?php
declare(strict_types=1);

$releaseRoot = dirname((string) realpath(__DIR__));
require_once $releaseRoot . '/app/bootstrap.php';
require_once $releaseRoot . '/app/layout.php';
require_once $releaseRoot . '/app/api.php';

startSecureSession();

function sqlVisibility(array $user, string $diskAlias = 'd'): array
{
    if ($user['role'] === 'admin') {
        return ['', []];
    }
    return [" INNER JOIN disk_access da ON da.disk_id = {$diskAlias}.id AND da.user_id = :visibility_user_id", [':visibility_user_id' => (int) $user['id']]];
}

function sqlLike(string $value): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value) . '%';
}

function loginPage(?string $error = null): never
{
    $message = $error ? '<div class="notice error">' . h($error) . '</div>' : '';
    $content = '<div class="login-shell"><div class="login-brand"><span class="brand-mark large">CH</span><div><p class="eyebrow">CATALOGADOR DE MÍDIAS</p><h2>CatalogHDD<br>Enterprise</h2><p>Inventário seguro e pesquisável para seus volumes físicos.</p></div></div>'
        . '<form class="panel login-form" method="post" action="' . h(url('login')) . '"><h2>Acessar painel</h2><p>Use suas credenciais administrativas.</p>' . $message
        . '<input type="hidden" name="csrf" value="' . h(csrfToken()) . '"><label>Usuário<input name="username" autocomplete="username" required maxlength="64"></label>'
        . '<label>Senha<input type="password" name="password" autocomplete="current-password" required></label><button class="button primary" type="submit">Entrar com segurança</button></form></div>';
    renderPage('Acesso seguro', $content, null, true);
    exit;
}

function doLogin(): never
{
    verifyCsrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $ip = clientIp();

    $limit = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address = :ip AND was_successful = 0 AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $limit->execute([':ip' => $ip]);
    if ((int) $limit->fetchColumn() >= 5) {
        audit('auth.login.rate_limited', 'user', $username);
        loginPage('Muitas tentativas falhas. Aguarde alguns minutos antes de tentar novamente.');
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE username = :username AND is_active = 1 LIMIT 1');
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();
    $success = $user && password_verify($password, $user['password_hash']);

    db()->prepare('INSERT INTO login_attempts (username, ip_address, was_successful) VALUES (:username, :ip, :ok)')
        ->execute([':username' => substr($username, 0, 64), ':ip' => $ip, ':ok' => $success ? 1 : 0]);

    if (!$success) {
        audit('auth.login.failed', 'user', $username, ['reason' => 'invalid_credentials']);
        loginPage('Usuário ou senha inválidos.');
    }

    session_regenerate_id(true);
    $nonce = bin2hex(random_bytes(32));
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['session_nonce'] = $nonce;
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO user_sessions (user_id, session_hash, ip_address, user_agent_hash, expires_at) VALUES (:user_id, :hash, :ip, :ua, DATE_ADD(NOW(), INTERVAL 8 HOUR))')
        ->execute([':user_id' => $user['id'], ':hash' => hash('sha256', $nonce), ':ip' => $ip, ':ua' => hash('sha256', userAgent())]);
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')->execute([':id' => $user['id']]);
    audit('auth.login.success', 'user', $user['id']);

    if ((int) $user['force_password_change'] === 1) {
        flash('warning', 'Por segurança, defina uma nova senha antes de continuar.');
        redirect('profile');
    }
    redirect('dashboard');
}

function doLogout(): never
{
    verifyCsrf();
    $user = currentUser();
    $nonce = $_SESSION['session_nonce'] ?? '';
    if (is_string($nonce) && $nonce !== '') {
        db()->prepare('UPDATE user_sessions SET revoked_at = NOW() WHERE session_hash = :hash')->execute([':hash' => hash('sha256', $nonce)]);
    }
    if ($user) audit('auth.logout', 'user', $user['id']);
    clearSession();
    startSecureSession();
    flash('success', 'Sua sessão foi encerrada.');
    redirect('login');
}

function dashboard(): never
{
    $user = requireLogin();
    [$join, $bind] = sqlVisibility($user);
    $statsSql = "SELECT COUNT(*) AS disks, COALESCE(SUM(d.capacity),0) AS capacity, MAX(d.last_indexed_at) AS last_indexed FROM disks d {$join} WHERE d.status <> 'archived'";
    $stmt = db()->prepare($statsSql); $stmt->execute($bind); $stats = $stmt->fetch() ?: [];

    $filesSql = "SELECT COUNT(f.id) AS files, COALESCE(SUM(f.size),0) AS bytes FROM files f INNER JOIN disks d ON d.id=f.disk_id {$join} WHERE f.is_deleted=0";
    $stmt = db()->prepare($filesSql); $stmt->execute($bind); $fileStats = $stmt->fetch() ?: [];

    $runsSql = "SELECT r.*, d.label FROM index_runs r LEFT JOIN disks d ON d.id=r.disk_id {$join} ORDER BY r.started_at DESC LIMIT 7";
    $stmt = db()->prepare($runsSql); $stmt->execute($bind); $runs = $stmt->fetchAll();
    audit('inventory.dashboard.view');

    $content = '<div class="metric-grid">'
        . metricCard('Volumes acessíveis', (string) ($stats['disks'] ?? 0), 'Volumes ativos no inventário')
        . metricCard('Arquivos catalogados', number_format((int) ($fileStats['files'] ?? 0), 0, ',', '.'), 'Registros visíveis ao seu perfil')
        . metricCard('Capacidade física', formatBytes((int) ($stats['capacity'] ?? 0)), 'Soma das capacidades informadas')
        . metricCard('Última indexação', !empty($stats['last_indexed']) ? date('d/m/Y H:i', strtotime($stats['last_indexed'])) : 'Sem registro', 'Atualização mais recente')
        . '</div><div class="two-columns"><section class="panel"><div class="panel-heading"><div><p class="eyebrow">ACESSO RÁPIDO</p><h2>Encontre conteúdo</h2></div></div>'
        . '<form class="search-box" method="get" action="' . h(basePath()) . '/"><input type="hidden" name="r" value="search"><input name="q" type="search" minlength="2" placeholder="Nome, extensão ou caminho do arquivo"><button class="button primary">Pesquisar</button></form>'
        . '<p class="muted">A busca respeita as permissões do seu usuário e consulta o índice local, sem acessar os discos físicos.</p></section>'
        . '<section class="panel"><div class="panel-heading"><div><p class="eyebrow">OPERAÇÃO</p><h2>Indexações recentes</h2></div><a href="' . h(url('audit')) . '" class="text-link">Ver auditoria</a></div>'
        . recentRuns($runs) . '</section></div>';
    renderPage('Visão geral', $content, $user);
    exit;
}

function metricCard(string $label, string $value, string $caption): string
{
    return '<article class="metric-card"><span>' . h($label) . '</span><strong>' . h($value) . '</strong><small>' . h($caption) . '</small></article>';
}

function recentRuns(array $runs): string
{
    if ($runs === []) return '<p class="empty">Ainda não há execuções do novo indexador registradas.</p>';
    $html = '<div class="compact-list">';
    foreach ($runs as $run) {
        $html .= '<div><span class="run-status ' . h($run['status']) . '"></span><p><strong>' . h($run['label'] ?: 'Volume não identificado') . '</strong><small>' . h($run['status']) . ' · ' . h($run['started_at']) . '</small></p><b>' . number_format((int) $run['files_indexed'], 0, ',', '.') . '</b></div>';
    }
    return $html . '</div>';
}

function disksPage(): never
{
    $user = requireLogin();
    $query = trim((string) ($_GET['q'] ?? ''));
    $view = (string)($_GET['view'] ?? 'cards') === 'table' ? 'table' : 'cards';
    [$join, $bind] = sqlVisibility($user);
    $where = ' WHERE 1=1 ';
    if ($query !== '') {
        $where .= ' AND (d.label LIKE :query ESCAPE "\\\\" OR d.serial LIKE :query ESCAPE "\\\\" OR d.model LIKE :query ESCAPE "\\\\")';
        $bind[':query'] = sqlLike($query);
    }
    $sql = "SELECT d.* FROM disks d {$join} {$where} ORDER BY FIELD(d.status, 'active','maintenance','missing','archived'), d.updated_at DESC";
    $stmt = db()->prepare($sql); $stmt->execute($bind); $disks = $stmt->fetchAll();
    audit('inventory.disks.view', null, null, ['query' => $query, 'view' => $view]);

    $cards = ''; $rows = '';
    foreach ($disks as $disk) {
        $name = $disk['label'] ?: 'Volume sem rótulo';
        $lastIndexed = $disk['last_indexed_at'] ? date('d/m/Y H:i', strtotime($disk['last_indexed_at'])) : 'Sem registro';
        $open = '<a class="button secondary" href="' . h(url('disk', ['id' => $disk['id']])) . '">Abrir</a>';
        $cards .= '<article class="disk-card"><div class="disk-card-head"><span class="disk-symbol">◉</span><div><h2>' . h($name) . '</h2><p>' . h($disk['model'] ?: $disk['serial'] ?: 'Modelo não informado') . '</p></div>' . statusBadge($disk['status']) . '</div>'
            . '<div class="disk-stats"><span><b>' . number_format((int) $disk['indexed_file_count'], 0, ',', '.') . '</b> arquivos</span><span><b>' . h(formatBytes((int) $disk['indexed_size_bytes'])) . '</b> indexados</span></div>'
            . '<div class="disk-footer"><small>Serial: ' . h($disk['serial'] ?: 'não informado') . '</small>' . $open . '</div></article>';
        $rows .= '<tr><td><strong>' . h($name) . '</strong><small>' . h($disk['model'] ?: 'Modelo não informado') . ' · Serial: ' . h($disk['serial'] ?: 'não informado') . '</small></td><td>' . statusBadge($disk['status']) . '</td><td>' . number_format((int)$disk['indexed_file_count'], 0, ',', '.') . '</td><td>' . h(formatBytes((int)$disk['indexed_size_bytes'])) . '</td><td>' . h(formatBytes((int)$disk['capacity'])) . '</td><td>' . h($lastIndexed) . '</td><td>' . $open . '</td></tr>';
    }
    $empty = '<div class="panel empty">Nenhum volume corresponde aos filtros ou está disponível para seu usuário.</div>';
    $display = $view === 'table' ? '<section class="panel volume-table-panel"><div class="table-wrap"><table><thead><tr><th>Volume</th><th>Status</th><th>Arquivos</th><th>Índice</th><th>Capacidade</th><th>Última indexação</th><th></th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="7" class="empty">Nenhum volume corresponde aos filtros ou está disponível para seu usuário.</td></tr>') . '</tbody></table></div></section>' : ($cards === '' ? $empty : '<section class="disk-grid">' . $cards . '</section>');
    $cardsLink = url('disks', ['q'=>$query, 'view'=>'cards']); $tableLink = url('disks', ['q'=>$query, 'view'=>'table']);
    $content = '<section class="page-actions"><form class="inline-search" method="get" action="' . h(basePath()) . '/"><input type="hidden" name="r" value="disks"><input type="hidden" name="view" value="' . h($view) . '"><input name="q" value="' . h($query) . '" placeholder="Filtrar por rótulo, serial ou modelo"><button class="button secondary">Filtrar</button></form><nav class="view-toggle" aria-label="Modo de visualização"><a class="' . ($view === 'cards' ? 'active' : '') . '" href="' . h($cardsLink) . '" title="Visualização em cards">▦ <span>Cards</span></a><a class="' . ($view === 'table' ? 'active' : '') . '" href="' . h($tableLink) . '" title="Visualização em tabela">☷ <span>Tabela</span></a></nav></section>' . $display;
    renderPage('Volumes', $content, $user);
    exit;
}

function diskPage(): never
{
    $id = max(1, (int) ($_GET['id'] ?? 0));
    $user = requireDiskAccess($id);
    $stmt = db()->prepare('SELECT d.* FROM disks d WHERE d.id=:id');
    $stmt->execute([':id' => $id]); $disk = $stmt->fetch();
    if (!$disk) { http_response_code(404); exit('Volume não encontrado.'); }
    $partitionStmt = db()->prepare('SELECT p.*, r.source_host AS run_source_host, r.client_version AS run_client_version, r.finished_at AS run_finished_at FROM disk_partitions p LEFT JOIN index_runs r ON r.id=p.last_run_id WHERE p.disk_id=:disk_id ORDER BY p.partition_number');
    $partitionStmt->execute([':disk_id' => $id]);
    $partitions = $partitionStmt->fetchAll();
    $usageStmt = db()->prepare('SELECT COUNT(*) AS partitions_with_usage,COALESCE(SUM(used_bytes),0) AS used_bytes,COALESCE(SUM(free_bytes),0) AS free_bytes,MAX(usage_updated_at) AS usage_updated_at FROM disk_partitions WHERE disk_id=:disk_id AND used_bytes IS NOT NULL AND free_bytes IS NOT NULL');
    $usageStmt->execute([':disk_id'=>$id]); $usage = $usageStmt->fetch() ?: ['partitions_with_usage'=>0,'used_bytes'=>0,'free_bytes'=>0,'usage_updated_at'=>null];
    $query = trim((string) ($_GET['q'] ?? ''));
    $path = explorerPath((string)($_GET['path'] ?? ''));
    $files = []; $total = (int)$disk['indexed_file_count']; $paging = pagination(0, 1); $entries = [];
    if ($query !== '') {
        $page = pageNumber(); $where = 'f.disk_id=:id AND f.is_deleted=0'; $params = [':id' => $id];
        $where .= ' AND (f.name LIKE :query ESCAPE "\\\\" OR f.path LIKE :query ESCAPE "\\\\")'; $params[':query'] = sqlLike($query);
        $totalStmt = db()->prepare("SELECT COUNT(*) FROM files f WHERE {$where}"); $totalStmt->execute($params); $total = (int) $totalStmt->fetchColumn(); $paging = pagination($total, $page);
        $fileStmt = db()->prepare("SELECT f.* FROM files f WHERE {$where} ORDER BY f.name ASC LIMIT :limit OFFSET :offset");
        foreach ($params as $key => $value) $fileStmt->bindValue($key, $value);
        $fileStmt->bindValue(':limit', $paging['per_page'], PDO::PARAM_INT); $fileStmt->bindValue(':offset', $paging['offset'], PDO::PARAM_INT); $fileStmt->execute(); $files = $fileStmt->fetchAll();
    } else {
        $entries = explorerEntries($id, $path);
    }
    audit('inventory.disk.view', 'disk', $id, ['query' => $query, 'path' => $path]);

    $managerControls = '';
    if (canAccessDisk($user, $id, true)) {
        $managerControls = '<details class="panel admin-panel"><summary>Administrar volume</summary><form class="form-grid" method="post" action="' . h(url('disk-save')) . '"><input type="hidden" name="csrf" value="' . h(csrfToken()) . '"><input type="hidden" name="id" value="' . $id . '"><label>Rótulo<input name="label" value="' . h($disk['label']) . '" maxlength="255"></label><label>Serial<input name="serial" value="' . h($disk['serial']) . '" maxlength="255"></label><label>Modelo<input name="model" value="' . h($disk['model']) . '" maxlength="100"></label><label>Capacidade em bytes<input name="capacity" value="' . h($disk['capacity']) . '" inputmode="numeric"></label><label>Caminho raiz<input name="root_path" value="' . h($disk['root_path']) . '" maxlength="1024"></label><label>Sistema de arquivos<input name="filesystem" value="' . h($disk['filesystem']) . '" maxlength="64"></label><label>Status<select name="status">' . selectOptions(['active'=>'Ativo','archived'=>'Arquivado','missing'=>'Ausente','maintenance'=>'Manutenção'], $disk['status']) . '</select></label><label class="toggle">Protegido<input type="checkbox" name="is_protected" value="1"' . ((int)$disk['is_protected'] ? ' checked' : '') . '></label><label class="full">Observações<textarea name="observation">' . h($disk['observation']) . '</textarea></label><label class="full">Risco / anotação operacional<textarea name="risco">' . h($disk['risco']) . '</textarea></label><div class="full"><button class="button primary">Salvar alterações</button></div></form></details>';
    }

    $dangerControls = '';
    if ($user['role'] === 'admin') {
        $dangerControls = '<details class="panel admin-panel danger-panel"><summary>Excluir volume</summary><p class="muted">Esta ação remove o volume, seus arquivos indexados, entradas virtuais e miniaturas. Os arquivos no HDD/SSD não são alterados.</p><form class="form-grid" method="post" action="' . h(url('disk-delete')) . '"><input type="hidden" name="csrf" value="' . h(csrfToken()) . '"><input type="hidden" name="id" value="' . $id . '"><label>Confirme digitando EXCLUIR<input name="confirmation" required autocomplete="off" pattern="EXCLUIR"></label><div><button class="button danger" data-danger="yes" data-confirm-message="Excluir definitivamente este volume do catálogo?">Excluir do catálogo</button></div></form></details>';
    }

    $accessControls = '';
    if ($user['role'] === 'admin') {
        $grantStmt = db()->prepare('SELECT da.user_id, da.permission, u.username, u.role FROM disk_access da INNER JOIN users u ON u.id=da.user_id WHERE da.disk_id=:disk_id ORDER BY u.username');
        $grantStmt->execute([':disk_id' => $id]);
        $grants = $grantStmt->fetchAll();
        $availableUsers = db()->query("SELECT id, username, role FROM users WHERE is_active=1 AND role IN ('operator','viewer') ORDER BY username")->fetchAll();
        $grantRows = '';
        foreach ($grants as $grant) {
            $grantRows .= '<tr><td><strong>' . h($grant['username']) . '</strong><small>' . h($grant['role']) . '</small></td><td>' . h($grant['permission'] === 'manage' ? 'Gerenciar' : 'Consultar') . '</td><td><form method="post" action="' . h(url('disk-revoke')) . '"><input type="hidden" name="csrf" value="' . h(csrfToken()) . '"><input type="hidden" name="disk_id" value="' . $id . '"><input type="hidden" name="user_id" value="' . (int)$grant['user_id'] . '"><button class="text-link" data-danger="yes" data-confirm-message="Remover o acesso deste usuário?">Remover</button></form></td></tr>';
        }
        $options = '<option value="">Selecione um usuário</option>';
        foreach ($availableUsers as $available) $options .= '<option value="' . (int)$available['id'] . '">' . h($available['username']) . ' · ' . h($available['role']) . '</option>';
        $accessControls = '<details class="panel admin-panel"><summary>Permissões de acesso</summary><form class="form-grid" method="post" action="' . h(url('disk-grant')) . '"><input type="hidden" name="csrf" value="' . h(csrfToken()) . '"><input type="hidden" name="disk_id" value="' . $id . '"><label>Usuário<select name="user_id" required>' . $options . '</select></label><label>Permissão<select name="permission"><option value="view">Consultar</option><option value="manage">Gerenciar</option></select></label><div><button class="button secondary">Conceder acesso</button></div></form><div class="table-wrap"><table><thead><tr><th>Usuário</th><th>Permissão</th><th></th></tr></thead><tbody>' . ($grantRows ?: '<tr><td colspan="3" class="empty">Sem acessos delegados. Administradores têm acesso integral.</td></tr>') . '</tbody></table></div></details>';
    }

    $partitionPanel = '';
    if ($partitions !== []) {
        $partitionRows = '';
        foreach ($partitions as $partition) {
            $statusLabels = ['indexed'=>'Indexada','mounted'=>'Montada','unmounted'=>'Não montada','unsupported'=>'Não suportada','encrypted'=>'Criptografada','error'=>'Erro','empty'=>'Vazia'];
            $indexedAt = $partition['last_indexed_at'] ?: $partition['run_finished_at'];
            $agent = $partition['last_index_host'] ?: $partition['run_source_host'];
            $agentCaption = $agent ?: 'Não informado';
            if (!empty($partition['run_client_version'])) $agentCaption .= ' · ' . $partition['run_client_version'];
            $partitionTitle = (int)$partition['partition_number'] === 0 ? 'Disco inteiro' : '#' . (int)$partition['partition_number'];
            $partitionRows .= '<tr><td><strong>' . h($partitionTitle) . '</strong><small>' . h($partition['device_path']) . '</small></td><td>' . h($partition['label'] ?: '—') . '</td><td>' . h($partition['filesystem'] ?: '—') . '</td><td>' . h(formatBytes((int)$partition['capacity'])) . '</td><td>' . number_format((int)$partition['indexed_file_count'], 0, ',', '.') . '</td><td>' . h($statusLabels[$partition['status']] ?? $partition['status']) . '</td><td>' . h($indexedAt ? date('d/m/Y H:i', strtotime($indexedAt)) : 'Ainda não indexada') . '</td><td>' . h($agentCaption) . '</td></tr>';
        }
        $partitionPanel = '<section class="panel"><div class="panel-heading"><div><p class="eyebrow">ESTRUTURA FÍSICA</p><h2>Partições detectadas</h2></div></div><div class="table-wrap"><table><thead><tr><th>Partição</th><th>Rótulo</th><th>Sistema</th><th>Capacidade</th><th>Arquivos</th><th>Status</th><th>Última indexação</th><th>Agente</th></tr></thead><tbody>' . $partitionRows . '</tbody></table></div></section>';
    }

    $parentPath = $path === '' ? '' : implode('/', array_slice(explode('/', $path), 0, -1));
    $clearSearch = '<a class="text-link" href="' . h(url('disk', ['id'=>$id,'path'=>$path])) . '">Voltar ao explorador</a>';
    if ($query !== '') {
        $browserContent = '<section class="panel explorer-panel"><div class="panel-heading"><div><p class="eyebrow">RESULTADOS NO VOLUME</p><h2>Busca por “' . h($query) . '” <small>(' . number_format($total, 0, ',', '.') . ')</small></h2></div>' . $clearSearch . '</div>' . filesTable($files) . paginationLinks('disk', $paging['page'], $paging['pages'], ['id'=>$id,'q'=>$query,'path'=>$path]) . '</section>';
    } else {
        $up = $path === '' ? '<span class="explorer-up disabled"><span aria-hidden="true">↑</span> Subir</span>' : '<a class="explorer-up" href="' . h(url('disk', ['id'=>$id,'path'=>$parentPath])) . '"><span aria-hidden="true">↑</span> Subir</a>';
        $home = '<a class="explorer-home" href="' . h(url('disk', ['id'=>$id])) . '"><span class="side-icon drive" aria-hidden="true"></span> Início</a>';
        $browserContent = '<section class="panel explorer-panel"><div class="explorer-heading"><div><p class="eyebrow">CONTEÚDO INDEXADO</p><h2>Explorador de arquivos</h2></div><span class="explorer-count">' . number_format(count($entries), 0, ',', '.') . ' itens nesta pasta</span></div><div class="explorer-workspace">' . explorerSidebar($id, $path, $partitions) . '<div class="explorer-main"><nav class="explorer-breadcrumb" aria-label="Caminho atual">' . explorerBreadcrumbs($id, $path) . '</nav><div class="explorer-commandbar"><div class="explorer-navigation">' . $home . $up . '</div><form class="explorer-search" method="get" action="' . h(basePath()) . '/"><input type="hidden" name="r" value="disk"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="path" value="' . h($path) . '"><input name="q" value="" placeholder="Pesquisar neste volume" aria-label="Pesquisar neste volume"><button class="button secondary">Buscar</button></form></div>' . explorerTable($entries, $id, $path) . '</div></div></section>';
    }

    $usedBytes = (int)$usage['used_bytes']; $freeBytes = (int)$usage['free_bytes'];
    $usageKnown = (int)$usage['partitions_with_usage'] > 0 && ($usedBytes + $freeBytes) > 0;
    $usageTotal = $usedBytes + $freeBytes; $usagePercent = $usageKnown ? min(100, max(0, (int)round(($usedBytes * 100) / $usageTotal))) : 0;
    $usageUpdated = !empty($usage['usage_updated_at']) ? date('d/m/Y H:i', strtotime((string)$usage['usage_updated_at'])) : null;
    $usageSummary = $usageKnown ? '<p class="capacity-caption">Uso real de ' . number_format((int)$usage['partitions_with_usage'], 0, ',', '.') . ' filesystem(s) indexado(s)' . ($usageUpdated ? ' · atualizado em ' . h($usageUpdated) : '') . '.</p>' : '<p class="capacity-caption">A leitura de espaço real aparecerá após a próxima indexação com o cliente 1.4.0 ou posterior.</p>';
    $donutValue = $usageKnown ? $usagePercent . '%' : '—';
    $donutStep = min(100, max(0, (int)(round($usagePercent / 5) * 5)));
    $donutClass = $usageKnown ? ' p' . $donutStep : ' unknown';
    $volumeVisualPanel = '<section class="volume-insights"><article class="capacity-card"><div class="capacity-card-head"><div><p class="eyebrow">CAPACIDADE</p><h2>Uso do volume</h2></div><span class="capacity-status">' . ($usageKnown ? 'Medição atual' : 'Aguardando medição') . '</span></div><div class="capacity-visual"><div class="capacity-donut' . $donutClass . '"><strong>' . h($donutValue) . '</strong><span>ocupado</span></div><div class="capacity-legend"><div><i class="legend-used"></i><span>Usado</span><b>' . ($usageKnown ? h(formatBytes($usedBytes)) : '—') . '</b></div><div><i class="legend-free"></i><span>Livre</span><b>' . ($usageKnown ? h(formatBytes($freeBytes)) : '—') . '</b></div><div><i class="legend-indexed"></i><span>Arquivos no catálogo</span><b>' . h(formatBytes((int)$disk['indexed_size_bytes'])) . '</b></div></div></div>' . $usageSummary . '</article><article class="disk-facts-card"><p class="eyebrow">INFORMAÇÕES DO DISCO</p><h2>Identificação e estado</h2><dl class="disk-facts"><div><dt>Modelo</dt><dd>' . h($disk['model'] ?: 'Não informado') . '</dd></div><div><dt>Serial</dt><dd>' . h($disk['serial'] ?: 'Não informado') . '</dd></div><div><dt>Capacidade física</dt><dd>' . h(formatBytes((int)$disk['capacity'])) . '</dd></div><div><dt>Última indexação</dt><dd>' . h($disk['last_indexed_at'] ? date('d/m/Y H:i', strtotime($disk['last_indexed_at'])) : 'Ainda não indexado') . '</dd></div><div><dt>Origem</dt><dd>' . h($disk['root_path'] ?: 'Não informada') . '</dd></div><div><dt>Filesystems</dt><dd>' . number_format(count($partitions), 0, ',', '.') . ' detectado(s)</dd></div></dl></article></section>';

    $content = '<section class="volume-hero"><a class="back-link" href="' . h(url('disks')) . '">← Volumes</a><div class="volume-title"><div class="disk-symbol large">◉</div><div><div class="title-line"><h2>' . h($disk['label'] ?: 'Volume sem rótulo') . '</h2>' . statusBadge($disk['status']) . '</div><p>' . h($disk['model'] ?: 'Modelo não informado') . ' · Serial: ' . h($disk['serial'] ?: 'não informado') . '</p></div></div><div class="volume-metrics"><span><b>' . number_format((int)$disk['indexed_file_count'], 0, ',', '.') . '</b> arquivos</span><span><b>' . h(formatBytes((int)$disk['indexed_size_bytes'])) . '</b> no índice</span><span><b>' . h(formatBytes((int)$disk['capacity'])) . '</b> capacidade</span></div></section>'
        . $volumeVisualPanel . $partitionPanel . $managerControls . $accessControls . $dangerControls . $browserContent;
    renderPage($disk['label'] ?: 'Detalhes do volume', $content, $user);
    exit;
}

function filesTable(array $files): string
{
    if ($files === []) return '<p class="empty">Nenhum arquivo encontrado.</p>';
    $showVolume = array_key_exists('disk_label', $files[0]);
    $volumeHeader = $showVolume ? '<th>Volume</th>' : '';
    $html = '<div class="table-wrap"><table><thead><tr><th>Tipo</th><th>Arquivo</th>' . $volumeHeader . '<th>Caminho</th><th>Tamanho</th><th>Modificado</th></tr></thead><tbody>';
    foreach ($files as $file) {
        $thumb = !empty($file['thumbnail_key']) ? '<img class="file-thumb" src="' . h(url('thumbnail', ['id' => $file['id']])) . '" alt="">' : '<span class="file-icon">' . fileIcon($file['mime_type'], $file['extension']) . '</span>';
        $volumeCell = '';
        if ($showVolume) {
            $volumeName = $file['disk_label'] ?: ($file['disk_serial'] ?: 'Volume sem rótulo');
            $volumeCell = '<td class="volume-cell"><a href="' . h(url('disk', ['id' => (int)$file['disk_id']])) . '">' . h($volumeName) . '</a><small>' . h($file['disk_serial'] ?: 'Serial não informado') . '</small></td>';
        }
        $virtualOrigin = !empty($file['is_virtual']) ? '<small class="virtual-origin">Dentro de ' . h($file['archive_name'] ?: 'compactado') . '</small>' : '';
        $html .= '<tr><td>' . $thumb . '</td><td><strong>' . h($file['name']) . '</strong><small>' . h($file['mime_type'] ?: ($file['extension'] ? strtoupper($file['extension']) : 'desconhecido')) . '</small>' . $virtualOrigin . '</td>' . $volumeCell . '<td class="path">' . h($file['path']) . '</td><td>' . h(formatBytes((int)$file['size'])) . '</td><td>' . h($file['modified'] ?: '—') . '</td></tr>';
    }
    return $html . '</tbody></table></div>';
}

function explorerPath(string $path): string
{
    $segments = [];
    foreach (explode('/', str_replace('\\', '/', trim($path))) as $segment) {
        $segment = trim($segment);
        if ($segment === '' || $segment === '.') continue;
        if ($segment === '..') { array_pop($segments); continue; }
        $segments[] = mb_substr($segment, 0, 255);
    }
    return implode('/', $segments);
}

function explorerLabel(string $segment): string
{
    if ($segment === 'disco-inteiro') return 'Disco inteiro';
    if (preg_match('/^particao-(\\d+)$/', $segment, $match)) return 'Partição ' . $match[1];
    return $segment;
}

function explorerEntries(int $diskId, string $path): array
{
    $parentPath = $path === '' ? '/' : '/' . $path;
    $stmt = db()->prepare('SELECT e.name AS entry_name,e.is_directory,e.file_id,COALESCE(f.size,0) AS size,f.modified,f.extension,f.mime_type,f.file_type,f.thumbnail_key,0 AS item_count FROM file_browser_entries e LEFT JOIN files f ON f.id=e.file_id AND f.is_deleted=0 WHERE e.disk_id=:disk_id AND e.parent_hash=:parent_hash AND e.is_deleted=0 AND (e.is_directory=1 OR f.id IS NOT NULL) ORDER BY e.is_directory DESC,e.name ASC LIMIT 750');
    $stmt->execute([':disk_id'=>$diskId, ':parent_hash'=>hash('sha256', $parentPath)]);
    return $stmt->fetchAll();
}

function explorerBreadcrumbs(int $diskId, string $path): string
{
    $html = '<a href="' . h(url('disk', ['id'=>$diskId])) . '">Este volume</a>';
    $segments = $path === '' ? [] : explode('/', $path); $walk = [];
    foreach ($segments as $segment) {
        $walk[] = $segment;
        $html .= '<span>›</span><a href="' . h(url('disk', ['id'=>$diskId,'path'=>implode('/', $walk)])) . '">' . h(explorerLabel($segment)) . '</a>';
    }
    return $html;
}

function explorerSidebar(int $diskId, string $path, array $partitions): string
{
    $rootActive = $path === '' ? ' active' : '';
    $html = '<aside class="explorer-sidebar"><p class="explorer-sidebar-title">Este volume</p><a class="explorer-side-link' . $rootActive . '" href="' . h(url('disk', ['id'=>$diskId])) . '"><span class="side-icon drive" aria-hidden="true"></span>Raiz do volume</a>';
    if ($partitions !== []) {
        $html .= '<p class="explorer-sidebar-title section">Dispositivos</p>';
        foreach ($partitions as $partition) {
            $segment = (int)$partition['partition_number'] === 0 ? 'disco-inteiro' : 'particao-' . (int)$partition['partition_number'];
            $active = ($path === $segment || str_starts_with($path, $segment . '/')) ? ' active' : '';
            $label = (int)$partition['partition_number'] === 0 ? 'Disco inteiro' : ($partition['label'] ?: 'Partição ' . (int)$partition['partition_number']);
            $html .= '<a class="explorer-side-link' . $active . '" href="' . h(url('disk', ['id'=>$diskId,'path'=>$segment])) . '"><span class="side-icon folder" aria-hidden="true"></span>' . h($label) . '<small>' . h($partition['filesystem'] ?: 'filesystem') . '</small></a>';
        }
    }
    return $html . '</aside>';
}

function explorerTable(array $entries, int $diskId, string $path): string
{
    if ($entries === []) return '<div class="explorer-empty"><span>◌</span><p>Esta pasta não contém arquivos indexados.</p></div>';
    $rows = '';
    foreach ($entries as $entry) {
        $name = (string)$entry['entry_name']; $directory = (int)$entry['is_directory'] === 1;
        $childPath = explorerPath(trim($path . '/' . $name, '/'));
        if ($directory) {
            $icon = '<span class="explorer-icon folder" aria-hidden="true"></span>';
            $nameCell = '<a class="explorer-name" href="' . h(url('disk', ['id'=>$diskId,'path'=>$childPath])) . '">' . h(explorerLabel($name)) . '</a>';
            $type = 'Pasta'; $size = '—'; $modified = '—';
        } else {
            $thumb = !empty($entry['thumbnail_key']) ? '<img class="explorer-thumb" src="' . h(url('thumbnail', ['id'=>(int)$entry['file_id']])) . '" alt="">' : '<span class="explorer-icon file">' . fileIcon($entry['mime_type'], $entry['extension']) . '</span>';
            $icon = $thumb; $nameCell = '<span class="explorer-name">' . h($name) . '</span>';
            $type = h($entry['mime_type'] ?: ($entry['extension'] ? strtoupper((string)$entry['extension']) . ' arquivo' : 'Arquivo'));
            $size = h(formatBytes((int)$entry['size'])); $modified = h($entry['modified'] ?: '—');
        }
        $rows .= '<tr class="' . ($directory ? 'is-folder' : '') . '"><td class="explorer-file-cell">' . $icon . $nameCell . '</td><td>' . $modified . '</td><td>' . $type . '</td><td>' . $size . '</td></tr>';
    }
    return '<div class="explorer-table-wrap"><table class="explorer-table"><thead><tr><th>Nome</th><th>Modificado</th><th>Tipo</th><th>Tamanho</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
}

function selectOptions(array $options, ?string $selected): string
{
    $html = '';
    foreach ($options as $value => $label) $html .= '<option value="' . h($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . h($label) . '</option>';
    return $html;
}

function searchPage(): never
{
    $user = requireLogin();
    $query = trim((string) ($_GET['q'] ?? ''));
    $page = pageNumber(); $files = []; $total = 0; $paging = pagination(0, 1);
    if (mb_strlen($query) >= 2) {
        $searchValue = sqlLike($query);
        $physicalJoin = ''; $virtualJoin = ''; $bind = [
            ':physical_name'=>$searchValue, ':physical_path'=>$searchValue, ':physical_extension'=>$searchValue,
            ':virtual_name'=>$searchValue, ':virtual_path'=>$searchValue, ':virtual_extension'=>$searchValue,
        ];
        if ($user['role'] !== 'admin') {
            $physicalJoin = ' INNER JOIN disk_access da_physical ON da_physical.disk_id=d.id AND da_physical.user_id=:visibility_physical ';
            $virtualJoin = ' INNER JOIN disk_access da_virtual ON da_virtual.disk_id=d.id AND da_virtual.user_id=:visibility_virtual ';
            $bind[':visibility_physical'] = (int)$user['id'];
            $bind[':visibility_virtual'] = (int)$user['id'];
        }
        $physical = "SELECT f.id,f.disk_id,f.partition_id,f.path,f.name,f.size,f.modified,f.extension,f.mime_type,f.file_type,f.thumbnail_key,f.metadata_json,0 AS is_virtual,NULL AS archive_file_id,NULL AS archive_name,NULL AS archive_path,d.label AS disk_label,d.serial AS disk_serial FROM files f INNER JOIN disks d ON d.id=f.disk_id {$physicalJoin} WHERE f.is_deleted=0 AND (f.name LIKE :physical_name ESCAPE '\\\\' OR f.path LIKE :physical_path ESCAPE '\\\\' OR f.extension LIKE :physical_extension ESCAPE '\\\\')";
        $virtual = "SELECT v.id,v.disk_id,v.partition_id,v.internal_path COLLATE utf8mb4_general_ci AS path,v.name COLLATE utf8mb4_general_ci AS name,v.size,v.modified,v.extension COLLATE utf8mb4_general_ci AS extension,v.mime_type COLLATE utf8mb4_general_ci AS mime_type,v.file_type COLLATE utf8mb4_general_ci AS file_type,NULL AS thumbnail_key,v.metadata_json,1 AS is_virtual,v.archive_file_id,af.name COLLATE utf8mb4_general_ci AS archive_name,af.path COLLATE utf8mb4_general_ci AS archive_path,d.label AS disk_label,d.serial AS disk_serial FROM virtual_entries v INNER JOIN files af ON af.id=v.archive_file_id INNER JOIN disks d ON d.id=v.disk_id {$virtualJoin} WHERE v.is_deleted=0 AND (v.name LIKE :virtual_name ESCAPE '\\\\' OR v.internal_path LIKE :virtual_path ESCAPE '\\\\' OR v.extension LIKE :virtual_extension ESCAPE '\\\\')";
        $union = "({$physical} UNION ALL {$virtual}) AS search_results";
        $count = db()->prepare("SELECT COUNT(*) FROM {$union}"); $count->execute($bind); $total = (int)$count->fetchColumn(); $paging = pagination($total, $page);
        $sql = "SELECT * FROM {$union} ORDER BY name ASC LIMIT :limit OFFSET :offset";
        $stmt = db()->prepare($sql); foreach ($bind as $key => $value) $stmt->bindValue($key, $value); $stmt->bindValue(':limit', $paging['per_page'], PDO::PARAM_INT); $stmt->bindValue(':offset', $paging['offset'], PDO::PARAM_INT); $stmt->execute(); $files = $stmt->fetchAll();
        audit('inventory.search', 'file', null, ['query' => $query, 'results' => $total]);
    }
    $result = $query === '' ? '<p class="empty">Digite pelo menos dois caracteres para pesquisar no inventário.</p>' : (mb_strlen($query) < 2 ? '<p class="empty">A busca precisa ter pelo menos dois caracteres.</p>' : filesTable($files) . paginationLinks('search', $paging['page'], $paging['pages'], ['q' => $query]));
    $content = '<section class="panel search-panel"><p class="eyebrow">PESQUISA GLOBAL</p><h2>Localize arquivos sem conectar os discos</h2><form class="search-box" method="get" action="' . h(basePath()) . '/"><input type="hidden" name="r" value="search"><input autofocus name="q" type="search" minlength="2" value="' . h($query) . '" placeholder="Ex.: fotos de viagem, .mkv, orçamento"><button class="button primary">Pesquisar</button></form></section><section class="panel"><div class="panel-heading"><div><h2>Resultados' . ($query !== '' ? ' <small>(' . number_format($total, 0, ',', '.') . ')</small>' : '') . '</h2></div></div>' . $result . '</section>';
    renderPage('Pesquisar', $content, $user);
    exit;
}

function usersPage(): never
{
    $user = requireRole('admin');
    $users = db()->query('SELECT id,username,email,role,is_active,force_password_change,last_login_at,created_at FROM users ORDER BY created_at DESC')->fetchAll();
    $rows = '';
    foreach ($users as $account) {
        $self = (int)$account['id'] === (int)$user['id'];
        $rows .= '<tr><td><strong>' . h($account['username']) . '</strong><small>' . h($account['email'] ?: 'sem e-mail') . '</small></td><td>' . roleBadge($account['role']) . '</td><td>' . ((int)$account['is_active'] ? '<span class="badge active">Ativo</span>' : '<span class="badge missing">Inativo</span>') . '</td><td>' . h($account['last_login_at'] ?: 'Nunca') . '</td><td><details><summary class="text-link">Gerenciar</summary><form class="compact-form" method="post" action="' . h(url('user-update')) . '"><input type="hidden" name="csrf" value="' . h(csrfToken()) . '"><input type="hidden" name="id" value="' . (int)$account['id'] . '"><label>Papel<select name="role">' . selectOptions(['admin'=>'Administrador','operator'=>'Operador','viewer'=>'Leitor'], $account['role']) . '</select></label><label>Nova senha <small>(opcional)</small><input type="password" name="password" minlength="16" autocomplete="new-password"></label><label class="toggle">Ativo<input type="checkbox" name="is_active" value="1"' . ((int)$account['is_active'] ? ' checked' : '') . ($self ? ' disabled' : '') . '></label><label class="toggle">Exigir troca de senha<input type="checkbox" name="force_password_change" value="1"' . ((int)$account['force_password_change'] ? ' checked' : '') . '></label><button class="button secondary">Salvar</button></form></details></td></tr>';
    }
    $create = '<section class="panel"><div class="panel-heading"><div><p class="eyebrow">NOVO ACESSO</p><h2>Criar usuário</h2></div></div><form class="form-grid" method="post" action="' . h(url('user-create')) . '"><input type="hidden" name="csrf" value="' . h(csrfToken()) . '"><label>Usuário<input name="username" required pattern="[A-Za-z0-9._-]{3,64}" maxlength="64"></label><label>E-mail<input name="email" type="email" maxlength="255"></label><label>Papel<select name="role">' . selectOptions(['viewer'=>'Leitor','operator'=>'Operador','admin'=>'Administrador'], 'viewer') . '</select></label><label>Senha inicial<input name="password" type="password" required minlength="16" autocomplete="new-password"></label><div class="full"><button class="button primary">Criar usuário</button></div></form></section>';
    $content = $create . '<section class="panel"><div class="panel-heading"><div><p class="eyebrow">CONTROLE DE ACESSO</p><h2>Usuários registrados</h2></div></div><div class="table-wrap"><table><thead><tr><th>Usuário</th><th>Papel</th><th>Status</th><th>Último acesso</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>';
    renderPage('Usuários e permissões', $content, $user);
    exit;
}

function doUserCreate(): never
{
    $admin = requireRole('admin'); verifyCsrf();
    $username = trim((string)($_POST['username'] ?? '')); $email = trim((string)($_POST['email'] ?? '')); $password = (string)($_POST['password'] ?? ''); $role = (string)($_POST['role'] ?? 'viewer');
    if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username) || strlen($password) < 16 || !in_array($role, ['admin','operator','viewer'], true) || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) { flash('error', 'Revise os campos: usuário válido, senha com 16 caracteres e e-mail opcional válido.'); redirect('users'); }
    try { db()->prepare('INSERT INTO users (username,email,password_hash,role,created_by) VALUES (:username,:email,:hash,:role,:by)')->execute([':username'=>$username, ':email'=>$email ?: null, ':hash'=>password_hash($password, PASSWORD_DEFAULT), ':role'=>$role, ':by'=>$admin['id']]); $id=(int)db()->lastInsertId(); audit('user.created','user',$id,['username'=>$username,'role'=>$role]); flash('success','Usuário criado. Ele deverá trocar a senha no primeiro acesso.'); } catch (PDOException) { flash('error','Não foi possível criar o usuário: nome ou e-mail já estão em uso.'); }
    redirect('users');
}

function doUserUpdate(): never
{
    $admin = requireRole('admin'); verifyCsrf(); $id=(int)($_POST['id'] ?? 0); $role=(string)($_POST['role'] ?? 'viewer'); $password=(string)($_POST['password'] ?? '');
    if ($id < 1 || !in_array($role,['admin','operator','viewer'],true) || ($password !== '' && strlen($password)<16)) { flash('error','Dados de usuário inválidos.'); redirect('users'); }
    if ($id === (int)$admin['id'] && (empty($_POST['is_active']) || $role !== 'admin')) { flash('error','Você não pode remover seu próprio acesso administrativo.'); redirect('users'); }
    $fields=['role=:role','is_active=:active','force_password_change=:force']; $params=[':id'=>$id,':role'=>$role,':active'=>isset($_POST['is_active'])?1:0,':force'=>isset($_POST['force_password_change'])?1:0];
    if ($password !== '') { $fields[]='password_hash=:hash'; $params[':hash']=password_hash($password,PASSWORD_DEFAULT); $params[':force']=1; db()->prepare('UPDATE user_sessions SET revoked_at=NOW() WHERE user_id=:id')->execute([':id'=>$id]); }
    db()->prepare('UPDATE users SET '.implode(',',$fields).' WHERE id=:id')->execute($params); audit('user.updated','user',$id,['role'=>$role,'password_reset'=>$password!=='' ]); flash('success','Dados do usuário atualizados.'); redirect('users');
}

function tokensPage(): never
{
    $admin = requireRole('admin');
    startSecureSession(); $generated = $_SESSION['generated_api_token'] ?? null; unset($_SESSION['generated_api_token']);
    $tokens = db()->query('SELECT t.*,u.username FROM api_tokens t INNER JOIN users u ON u.id=t.user_id ORDER BY t.created_at DESC')->fetchAll();
    $owners = db()->query("SELECT id,username,role FROM users WHERE is_active=1 AND role IN ('admin','operator') ORDER BY username")->fetchAll();
    $rows = '';
    foreach ($tokens as $token) {
        $active = empty($token['revoked_at']) && (empty($token['expires_at']) || strtotime($token['expires_at']) > time());
        $state = $active ? '<span class="badge active">Ativo</span>' : '<span class="badge missing">Revogado ou expirado</span>';
        $action = $active ? '<form method="post" action="' . h(url('token-revoke')) . '"><input type="hidden" name="csrf" value="' . h(csrfToken()) . '"><input type="hidden" name="id" value="' . (int)$token['id'] . '"><button class="button danger" data-danger="yes" data-confirm-message="Invalidar este token? O cliente que o usa deixará de indexar.">Invalidar</button></form>' : '—';
        $rows .= '<tr><td><strong>' . h($token['name']) . '</strong><small>' . h($token['token_prefix']) . '…</small></td><td>' . h($token['username']) . '</td><td>' . $state . '</td><td>' . h($token['last_used_at'] ?: 'Nunca') . '</td><td>' . h($token['expires_at'] ?: 'Sem expiração') . '</td><td>' . $action . '</td></tr>';
    }
    $ownerOptions = ''; foreach ($owners as $owner) $ownerOptions .= '<option value="' . (int)$owner['id'] . '"' . ((int)$owner['id']===(int)$admin['id']?' selected':'') . '>' . h($owner['username']) . ' · ' . h($owner['role']) . '</option>';
    $secret = is_string($generated) ? '<section class="panel secret-panel"><p class="eyebrow">TOKEN GERADO</p><h2>Copie agora: ele não será mostrado novamente</h2><code class="secret-token">' . h($generated) . '</code><p class="muted">Configure este valor no cliente Debian e guarde-o em local seguro.</p></section>' : '';
    $create = '<section class="panel"><div class="panel-heading"><div><p class="eyebrow">NOVO CLIENTE</p><h2>Gerar token de indexação</h2></div></div><form class="form-grid" method="post" action="' . h(url('token-create')) . '"><input type="hidden" name="csrf" value="' . h(csrfToken()) . '"><label>Identificação<input name="name" required maxlength="120" placeholder="Ex.: Debian da casa"></label><label>Responsável<select name="user_id">' . $ownerOptions . '</select></label><label>Expira em <small>(opcional)</small><input type="datetime-local" name="expires_at"></label><div><button class="button primary">Gerar token</button></div></form></section>';
    $content = $secret . $create . '<section class="panel"><div class="panel-heading"><div><p class="eyebrow">CREDENCIAIS DE CLIENTE</p><h2>Tokens existentes</h2></div></div><div class="table-wrap"><table><thead><tr><th>Token</th><th>Responsável</th><th>Status</th><th>Último uso</th><th>Expiração</th><th></th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="6" class="empty">Nenhum token criado.</td></tr>') . '</tbody></table></div></section>';
    renderPage('Tokens de indexação', $content, $admin); exit;
}

function doTokenCreate(): never
{
    $admin = requireRole('admin'); verifyCsrf(); $name = trim((string)($_POST['name'] ?? '')); $ownerId = max(1,(int)($_POST['user_id'] ?? 0)); $expires = trim((string)($_POST['expires_at'] ?? ''));
    if ($name === '' || mb_strlen($name)>120) { flash('error','Informe uma identificação de até 120 caracteres.'); redirect('tokens'); }
    $owner = db()->prepare("SELECT id FROM users WHERE id=:id AND is_active=1 AND role IN ('admin','operator') LIMIT 1"); $owner->execute([':id'=>$ownerId]); if (!$owner->fetchColumn()) { flash('error','Responsável inválido.'); redirect('tokens'); }
    $expiresAt = null; if ($expires !== '') { $timestamp=strtotime($expires); if ($timestamp===false || $timestamp<=time()) { flash('error','A expiração deve estar no futuro.'); redirect('tokens'); } $expiresAt=date('Y-m-d H:i:s',$timestamp); }
    $token = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '='); $prefix=substr($token,0,12);
    db()->prepare("INSERT INTO api_tokens (user_id,name,token_prefix,token_hash,scopes,expires_at) VALUES (:user_id,:name,:prefix,:hash,'index',:expires_at)")->execute([':user_id'=>$ownerId,':name'=>$name,':prefix'=>$prefix,':hash'=>hash('sha256',$token),':expires_at'=>$expiresAt]);
    $id=(int)db()->lastInsertId(); startSecureSession(); $_SESSION['generated_api_token']=$token; audit('api_token.created','api_token',$id,['name'=>$name,'owner_id'=>$ownerId,'expires_at'=>$expiresAt]); redirect('tokens');
}

function doTokenRevoke(): never
{
    $admin = requireRole('admin'); verifyCsrf(); $id=max(1,(int)($_POST['id']??0));
    db()->prepare('UPDATE api_tokens SET revoked_at=NOW() WHERE id=:id AND revoked_at IS NULL')->execute([':id'=>$id]); audit('api_token.revoked','api_token',$id); flash('success','Token invalidado.'); redirect('tokens');
}

function settingsPage(): never
{
    $admin = requireRole('admin'); $settings = indexSettings();
    $checked = static fn(bool $value): string => $value ? ' checked' : '';
    $content = '<section class="panel"><div class="panel-heading"><div><p class="eyebrow">POLÍTICA CENTRALIZADA</p><h2>Preferências de indexação</h2><p class="muted">Os clientes consultam estas preferências antes de iniciar uma nova indexação.</p></div></div><form class="form-grid" method="post" action="' . h(url('settings-save')) . '"><input type="hidden" name="csrf" value="' . h(csrfToken()) . '"><label class="toggle">Consultar compactados<input type="checkbox" name="index_archives" value="1"' . $checked((bool)$settings['index_archives']) . '></label><label class="toggle">Gerar miniaturas<input type="checkbox" name="generate_thumbnails" value="1"' . $checked((bool)$settings['generate_thumbnails']) . '></label><label>Maior lado da miniatura (px)<input type="number" name="thumbnail_max_px" min="64" max="512" value="' . (int)$settings['thumbnail_max_px'] . '"></label><label>Qualidade JPEG (1–90)<input type="number" name="thumbnail_quality" min="1" max="90" value="' . (int)$settings['thumbnail_quality'] . '"></label><label>Máximo de entradas por compactado<input type="number" name="max_archive_entries" min="1" max="200000" value="' . (int)$settings['max_archive_entries'] . '"></label><label>Máximo por compactado (bytes)<input type="number" name="max_archive_bytes" min="1" value="' . (int)$settings['max_archive_bytes'] . '"></label><label class="full">Máximo do arquivo-fonte para miniatura (bytes)<input type="number" name="max_thumbnail_source_bytes" min="1" value="' . (int)$settings['max_thumbnail_source_bytes'] . '"></label><div class="full"><button class="button primary">Salvar preferências</button></div></form></section>';
    renderPage('Preferências de indexação', $content, $admin); exit;
}

function doSettingsSave(): never
{
    $admin=requireRole('admin'); verifyCsrf();
    $settings=['index_archives'=>isset($_POST['index_archives']),'generate_thumbnails'=>isset($_POST['generate_thumbnails']),'thumbnail_max_px'=>min(512,max(64,(int)($_POST['thumbnail_max_px']??256))),'thumbnail_quality'=>min(90,max(1,(int)($_POST['thumbnail_quality']??70))),'max_archive_entries'=>min(200000,max(1,(int)($_POST['max_archive_entries']??20000))),'max_archive_bytes'=>max(1,(int)($_POST['max_archive_bytes']??0)),'max_thumbnail_source_bytes'=>max(1,(int)($_POST['max_thumbnail_source_bytes']??0))];
    saveIndexSettings($settings,(int)$admin['id']); audit('index.settings.updated','system',null,$settings); flash('success','Preferências salvas. Os clientes aplicarão os valores na próxima indexação.'); redirect('settings');
}

function auditPage(): never
{
    $user = requireRole('admin'); $query=trim((string)($_GET['q']??'')); $page=pageNumber(); $params=[]; $where='1=1';
    if ($query !== '') { $where.=' AND (a.event_type LIKE :q ESCAPE "\\\\" OR u.username LIKE :q ESCAPE "\\\\" OR a.resource_type LIKE :q ESCAPE "\\\\")'; $params[':q']=sqlLike($query); }
    $count=db()->prepare("SELECT COUNT(*) FROM audit_log a LEFT JOIN users u ON u.id=a.user_id WHERE {$where}"); $count->execute($params); $paging=pagination((int)$count->fetchColumn(),$page);
    $stmt=db()->prepare("SELECT a.*,u.username FROM audit_log a LEFT JOIN users u ON u.id=a.user_id WHERE {$where} ORDER BY a.id DESC LIMIT :limit OFFSET :offset"); foreach($params as $k=>$v)$stmt->bindValue($k,$v); $stmt->bindValue(':limit',$paging['per_page'],PDO::PARAM_INT);$stmt->bindValue(':offset',$paging['offset'],PDO::PARAM_INT);$stmt->execute();$logs=$stmt->fetchAll();
    $rows=''; foreach($logs as $log){$details=$log['details_json']?json_decode($log['details_json'],true):[];$rows.='<tr><td>'.h($log['created_at']).'</td><td><strong>'.h($log['event_type']).'</strong><small>'.h($log['resource_type']?:'sistema').($log['resource_id']?' #'.h($log['resource_id']):'').'</small></td><td>'.h($log['username']?:'Sistema').'</td><td class="path">'.h(is_array($details)?json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):'').'</td><td>'.h($log['ip_address']?:'—').'</td></tr>';}
    $content='<section class="panel"><div class="panel-heading"><div><p class="eyebrow">TRILHA DE AUDITORIA</p><h2>Eventos e ações</h2></div><form class="inline-search" method="get" action="'.h(basePath()).'/"><input type="hidden" name="r" value="audit"><input name="q" value="'.h($query).'" placeholder="Evento, usuário ou recurso"><button class="button secondary">Filtrar</button></form></div><div class="table-wrap"><table><thead><tr><th>Data</th><th>Evento</th><th>Usuário</th><th>Detalhes</th><th>IP</th></tr></thead><tbody>'.($rows?:'<tr><td colspan="5" class="empty">Nenhum evento encontrado.</td></tr>').'</tbody></table></div>'.paginationLinks('audit',$paging['page'],$paging['pages'],['q'=>$query]).'</section>'; renderPage('Auditoria', $content,$user);exit;
}

function profilePage(): never
{
    $user=requireLogin(); $content='<section class="panel narrow"><p class="eyebrow">CONTA</p><h2>Atualizar senha</h2><p class="muted">Use uma senha única, com ao menos 16 caracteres. A alteração encerra as demais sessões abertas.</p><form class="form-grid" method="post" action="'.h(url('profile-save')).'"><input type="hidden" name="csrf" value="'.h(csrfToken()).'"><label class="full">Senha atual<input type="password" name="current_password" required autocomplete="current-password"></label><label class="full">Nova senha<input type="password" name="new_password" required minlength="16" autocomplete="new-password"></label><div class="full"><button class="button primary">Atualizar senha</button></div></form></section>';renderPage('Minha conta',$content,$user);exit;
}

function doProfileSave(): never
{
    $user=requireLogin();verifyCsrf();$current=(string)($_POST['current_password']??'');$new=(string)($_POST['new_password']??'');$stmt=db()->prepare('SELECT password_hash FROM users WHERE id=:id');$stmt->execute([':id'=>$user['id']]);$hash=(string)$stmt->fetchColumn();
    if (!password_verify($current,$hash)||strlen($new)<16){flash('error','Não foi possível atualizar a senha. Confirme a senha atual e use ao menos 16 caracteres.');redirect('profile');}
    db()->prepare('UPDATE users SET password_hash=:hash,force_password_change=0 WHERE id=:id')->execute([':hash'=>password_hash($new,PASSWORD_DEFAULT),':id'=>$user['id']]);$nonce=$_SESSION['session_nonce'];db()->prepare('UPDATE user_sessions SET revoked_at=NOW() WHERE user_id=:id AND session_hash<>:current')->execute([':id'=>$user['id'],':current'=>hash('sha256',$nonce)]);audit('user.password.changed','user',$user['id']);flash('success','Senha atualizada. As demais sessões foram encerradas.');redirect('dashboard');
}

function doDiskSave(): never
{
    $id=max(1,(int)($_POST['id']??0));$user=requireDiskAccess($id,true);verifyCsrf();$status=(string)($_POST['status']??'active');if(!in_array($status,['active','archived','missing','maintenance'],true)){flash('error','Status inválido.');redirect('disk',['id'=>$id]);}
    $capacity=preg_replace('/\D/','',(string)($_POST['capacity']??''));$stmt=db()->prepare('UPDATE disks SET label=:label,serial=:serial,model=:model,capacity=:capacity,root_path=:root_path,filesystem=:filesystem,status=:status,is_protected=:protected,observation=:observation,risco=:risco WHERE id=:id');$stmt->execute([':label'=>trim((string)($_POST['label']??''))?:null,':serial'=>trim((string)($_POST['serial']??''))?:null,':model'=>trim((string)($_POST['model']??''))?:null,':capacity'=>$capacity===''?null:(int)$capacity,':root_path'=>trim((string)($_POST['root_path']??''))?:null,':filesystem'=>trim((string)($_POST['filesystem']??''))?:null,':status'=>$status,':protected'=>isset($_POST['is_protected'])?1:0,':observation'=>trim((string)($_POST['observation']??''))?:null,':risco'=>trim((string)($_POST['risco']??''))?:null,':id'=>$id]);audit('disk.updated','disk',$id,['by'=>$user['username']]);flash('success','Dados do volume atualizados.');redirect('disk',['id'=>$id]);
}

function doDiskDelete(): never
{
    $admin = requireRole('admin'); verifyCsrf();
    $diskId = max(1, (int)($_POST['id'] ?? 0));
    if ((string)($_POST['confirmation'] ?? '') !== 'EXCLUIR') { flash('error', 'Digite EXCLUIR para confirmar a remoção.'); redirect('disk', ['id'=>$diskId]); }
    $pdo = db();
    $diskStmt = $pdo->prepare('SELECT id,label FROM disks WHERE id=:id LIMIT 1'); $diskStmt->execute([':id'=>$diskId]); $disk = $diskStmt->fetch();
    if (!$disk) { flash('error', 'Volume não encontrado.'); redirect('disks'); }
    $thumbStmt = $pdo->prepare('SELECT thumbnail_key FROM files WHERE disk_id=:disk_id AND thumbnail_key IS NOT NULL'); $thumbStmt->execute([':disk_id'=>$diskId]); $thumbKeys = $thumbStmt->fetchAll(PDO::FETCH_COLUMN);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM disk_access WHERE disk_id=:disk_id')->execute([':disk_id'=>$diskId]);
        $pdo->prepare('DELETE FROM files WHERE disk_id=:disk_id')->execute([':disk_id'=>$diskId]);
        $pdo->prepare('DELETE FROM index_runs WHERE disk_id=:disk_id')->execute([':disk_id'=>$diskId]);
        $pdo->prepare('DELETE FROM disk_partitions WHERE disk_id=:disk_id')->execute([':disk_id'=>$diskId]);
        $pdo->prepare('DELETE FROM disks WHERE id=:id')->execute([':id'=>$diskId]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); flash('error','Não foi possível excluir o volume.'); redirect('disk',['id'=>$diskId]); }
    $thumbnailDir = rtrim(appConfig()['THUMBNAIL_DIR'], '/');
    foreach ($thumbKeys as $key) if (is_string($key) && preg_match('/^[a-f0-9]{64}$/',$key)) @unlink($thumbnailDir . '/' . $key . '.jpg');
    audit('disk.deleted','disk',$diskId,['label'=>$disk['label'],'thumbnails_removed'=>count($thumbKeys)]);
    flash('success','Volume removido do catálogo. Os arquivos no disco físico não foram alterados.'); redirect('disks');
}

function doDiskGrant(): never
{
    $admin = requireRole('admin'); verifyCsrf();
    $diskId = max(1, (int)($_POST['disk_id'] ?? 0)); $targetUserId = max(1, (int)($_POST['user_id'] ?? 0)); $permission = (string)($_POST['permission'] ?? 'view');
    if (!in_array($permission, ['view','manage'], true)) { flash('error', 'Permissão inválida.'); redirect('disk', ['id' => $diskId]); }
    $target = db()->prepare("SELECT id FROM users WHERE id=:id AND is_active=1 AND role IN ('operator','viewer')"); $target->execute([':id' => $targetUserId]);
    if (!$target->fetchColumn()) { flash('error', 'Selecione um usuário ativo com papel compatível.'); redirect('disk', ['id' => $diskId]); }
    db()->prepare('INSERT INTO disk_access (user_id,disk_id,permission,granted_by) VALUES (:user_id,:disk_id,:permission,:granted_by) ON DUPLICATE KEY UPDATE permission=VALUES(permission),granted_by=VALUES(granted_by),granted_at=NOW()')->execute([':user_id'=>$targetUserId,':disk_id'=>$diskId,':permission'=>$permission,':granted_by'=>$admin['id']]);
    audit('disk.access.granted','disk',$diskId,['target_user_id'=>$targetUserId,'permission'=>$permission]); flash('success','Permissão de acesso atualizada.'); redirect('disk',['id'=>$diskId]);
}

function doDiskRevoke(): never
{
    $admin = requireRole('admin'); verifyCsrf(); $diskId=max(1,(int)($_POST['disk_id']??0));$targetUserId=max(1,(int)($_POST['user_id']??0));
    db()->prepare('DELETE FROM disk_access WHERE disk_id=:disk_id AND user_id=:user_id')->execute([':disk_id'=>$diskId,':user_id'=>$targetUserId]); audit('disk.access.revoked','disk',$diskId,['target_user_id'=>$targetUserId]); flash('success','Acesso removido.'); redirect('disk',['id'=>$diskId]);
}

function thumbnail(): never
{
    $id=max(1,(int)($_GET['id']??0));$stmt=db()->prepare('SELECT f.thumbnail_key,f.mime_type,f.extension,d.id AS disk_id FROM files f INNER JOIN disks d ON d.id=f.disk_id WHERE f.id=:id LIMIT 1');$stmt->execute([':id'=>$id]);$file=$stmt->fetch();if(!$file){http_response_code(404);exit;}$user=requireDiskAccess((int)$file['disk_id']);$key=(string)($file['thumbnail_key']??'');if(!preg_match('/^[a-f0-9]{64}$/',$key)){http_response_code(404);exit;}$path=rtrim(appConfig()['THUMBNAIL_DIR'],'/').'/'.$key.'.jpg';if(!is_file($path)){http_response_code(404);exit;}header('Content-Type: image/jpeg');header('Cache-Control: private, max-age=86400');header('X-Content-Type-Options: nosniff');readfile($path);exit;
}

$method=requestMethod();
if ($method === 'HEAD') $method = 'GET';
$r=route();
if ($r === 'api-index-settings' && $method === 'POST') apiIndexSettings();
if ($r === 'api-index-start' && $method === 'POST') apiStart();
if ($r === 'api-index-batch' && $method === 'POST') apiBatch();
if ($r === 'api-index-thumbnail' && $method === 'POST') apiThumbnail();
if ($r === 'api-index-finish' && $method === 'POST') apiFinish();
if ($r === 'api-archive-start' && $method === 'POST') apiArchiveStart();
if ($r === 'api-archive-batch' && $method === 'POST') apiArchiveBatch();
if ($r === 'api-archive-finish' && $method === 'POST') apiArchiveFinish();
if ($r === 'login' && $method === 'GET') { if (currentUser()) redirect('dashboard'); loginPage(); }
if ($r === 'login' && $method === 'POST') doLogin();
if ($r === 'logout' && $method === 'POST') doLogout();
if ($r === 'thumbnail' && $method === 'GET') thumbnail();
if ($r === 'dashboard' && $method === 'GET') dashboard();
if ($r === 'disks' && $method === 'GET') disksPage();
if ($r === 'disk' && $method === 'GET') diskPage();
if ($r === 'search' && $method === 'GET') searchPage();
if ($r === 'users' && $method === 'GET') usersPage();
if ($r === 'tokens' && $method === 'GET') tokensPage();
if ($r === 'token-create' && $method === 'POST') doTokenCreate();
if ($r === 'token-revoke' && $method === 'POST') doTokenRevoke();
if ($r === 'settings' && $method === 'GET') settingsPage();
if ($r === 'settings-save' && $method === 'POST') doSettingsSave();
if ($r === 'user-create' && $method === 'POST') doUserCreate();
if ($r === 'user-update' && $method === 'POST') doUserUpdate();
if ($r === 'audit' && $method === 'GET') auditPage();
if ($r === 'profile' && $method === 'GET') profilePage();
if ($r === 'profile-save' && $method === 'POST') doProfileSave();
if ($r === 'disk-save' && $method === 'POST') doDiskSave();
if ($r === 'disk-delete' && $method === 'POST') doDiskDelete();
if ($r === 'disk-grant' && $method === 'POST') doDiskGrant();
if ($r === 'disk-revoke' && $method === 'POST') doDiskRevoke();
http_response_code(404);renderPage('Página não encontrada','<section class="panel empty"><h2>Não foi possível localizar este recurso.</h2><a class="button primary" href="'.h(url('dashboard')).'">Voltar ao painel</a></section>',currentUser());
