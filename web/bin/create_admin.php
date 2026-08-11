#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este utilitário deve ser executado pelo terminal.\n");
    exit(1);
}

$username = $argv[1] ?? 'admin';
if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
    fwrite(STDERR, "O usuário deve ter entre 3 e 64 caracteres (letras, números, ponto, hífen ou sublinhado).\n");
    exit(1);
}

$existing = db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
if ((int) $existing > 0) {
    fwrite(STDERR, "Já existe ao menos um administrador ativo. Use o painel para criar ou recuperar contas.\n");
    exit(2);
}

$password = substr(strtr(base64_encode(random_bytes(30)), '+/', 'AZ'), 0, 30);
$stmt = db()->prepare('INSERT INTO users (username, password_hash, role, is_active, force_password_change) VALUES (:username, :password_hash, :role, 1, 1)');
$stmt->execute([
    ':username' => $username,
    ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ':role' => 'admin',
]);

printf("username=%s\npassword=%s\n", $username, $password);
