#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este utilitário deve ser executado pelo terminal.\n");
    exit(1);
}

$username = $argv[1] ?? 'admin';
$name = $argv[2] ?? 'indexador-inicial';
$stmt = db()->prepare('SELECT id FROM users WHERE username = :username AND is_active = 1 LIMIT 1');
$stmt->execute([':username' => $username]);
$userId = $stmt->fetchColumn();
if ($userId === false) {
    fwrite(STDERR, "Usuário ativo não encontrado.\n");
    exit(2);
}

$token = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
$prefix = substr($token, 0, 12);
$stmt = db()->prepare('INSERT INTO api_tokens (user_id, name, token_prefix, token_hash, scopes) VALUES (:user_id, :name, :prefix, :hash, :scopes)');
$stmt->execute([
    ':user_id' => $userId,
    ':name' => mb_substr($name, 0, 120),
    ':prefix' => $prefix,
    ':hash' => hash('sha256', $token),
    ':scopes' => 'index',
]);

printf("token_id=%d\nname=%s\ntoken=%s\n", (int) db()->lastInsertId(), $name, $token);
