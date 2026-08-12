<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

function renderPage(string $title, string $content, ?array $user = null, bool $minimal = false): void
{
    $flash = consumeFlash();
    $base = h(basePath());
    $safeTitle = h($title);
    $nav = '';
    if (!$minimal && $user !== null) {
        $admin = $user['role'] === 'admin';
        $nav = '<aside class="sidebar">'
            . '<a class="brand" href="' . h(url('dashboard')) . '"><span class="brand-mark">CH</span><span>CatalogHDD <small>Enterprise</small></span></a>'
            . '<nav class="nav-links">'
            . navItem('dashboard', 'Visão geral', '▦')
            . navItem('disks', 'Volumes', '◉')
            . navItem('search', 'Pesquisar', '⌕')
            . ($admin ? navItem('users', 'Usuários', '♙') . navItem('tokens', 'Tokens', '⌁') . navItem('settings', 'Preferências', '⚙') . navItem('audit', 'Auditoria', '◷') : '')
            . '</nav>'
            . '<div class="sidebar-user"><div><strong>' . h($user['username']) . '</strong><span>' . h($user['role']) . '</span></div>'
            . '<form method="post" action="' . h(url('logout')) . '"><input type="hidden" name="csrf" value="' . h(csrfToken()) . '"><button class="icon-button" title="Sair" aria-label="Sair">↪</button></form></div>'
            . '</aside>';
    }

    $flashHtml = '';
    foreach ($flash as $message) {
        $type = in_array($message['type'], ['success', 'error', 'warning', 'info'], true) ? $message['type'] : 'info';
        $flashHtml .= '<div class="notice ' . h($type) . '">' . h($message['message']) . '</div>';
    }

    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow"><title>' . $safeTitle . ' · CatalogHDD</title>'
        . '<link rel="stylesheet" href="' . $base . '/assets/app.css?v=20260812-2"><script defer src="' . $base . '/assets/app.js"></script></head>'
        . '<body class="' . ($minimal ? 'minimal' : 'application') . '">' . $nav
        . '<main class="main"><header class="topbar"><div><p class="eyebrow">CATALOGADOR DE MÍDIAS</p><h1>' . $safeTitle . '</h1></div>'
        . (!$minimal && $user ? '<a class="profile" href="' . h(url('profile')) . '">' . h($user['username']) . '<span>›</span></a>' : '')
        . '</header><section class="page-content">' . $flashHtml . $content . '</section></main></body></html>';
}

function navItem(string $routeName, string $label, string $icon): string
{
    $active = route() === $routeName ? ' active' : '';
    return '<a class="nav-item' . $active . '" href="' . h(url($routeName)) . '"><span>' . $icon . '</span>' . h($label) . '</a>';
}

function statusBadge(?string $status): string
{
    $status = $status ?: 'active';
    $labels = ['active' => 'Ativo', 'archived' => 'Arquivado', 'missing' => 'Ausente', 'maintenance' => 'Manutenção'];
    $class = array_key_exists($status, $labels) ? $status : 'active';
    return '<span class="badge ' . h($class) . '">' . h($labels[$class]) . '</span>';
}

function roleBadge(string $role): string
{
    $labels = ['admin' => 'Administrador', 'operator' => 'Operador', 'viewer' => 'Leitor'];
    return '<span class="badge role-' . h($role) . '">' . h($labels[$role] ?? $role) . '</span>';
}

function fileIcon(?string $mime, ?string $extension): string
{
    return match (fileCategory($mime, $extension)) {
        'image' => '▧',
        'video' => '▶',
        'audio' => '♫',
        'document' => '▤',
        'archive' => '▣',
        default => '□',
    };
}

function paginationLinks(string $routeName, int $current, int $pages, array $params = []): string
{
    if ($pages <= 1) return '';
    $html = '<nav class="pagination" aria-label="Paginação">';
    $start = max(1, $current - 2);
    $end = min($pages, $current + 2);
    if ($current > 1) $html .= '<a href="' . h(url($routeName, $params + ['page' => $current - 1])) . '">← Anterior</a>';
    for ($i = $start; $i <= $end; $i++) {
        $class = $i === $current ? 'current' : '';
        $html .= '<a class="' . $class . '" href="' . h(url($routeName, $params + ['page' => $i])) . '">' . $i . '</a>';
    }
    if ($current < $pages) $html .= '<a href="' . h(url($routeName, $params + ['page' => $current + 1])) . '">Próxima →</a>';
    return $html . '</nav>';
}
