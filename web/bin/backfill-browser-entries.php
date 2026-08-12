#!/usr/bin/env php
<?php
declare(strict_types=1);

$releaseRoot = dirname(__DIR__);
require_once $releaseRoot . '/app/bootstrap.php';

$diskId = null;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--disk-id=')) $diskId = max(1, (int)substr($argument, 10));
}

function browserSegmentsForBackfill(string $path): array
{
    return array_values(array_filter(explode('/', trim($path, '/')), static fn(string $part): bool => $part !== '' && $part !== '.' && $part !== '..'));
}

$pdo = db();
$where = 'is_deleted=0'; $params = [];
if ($diskId !== null) { $where .= ' AND disk_id=:disk_id'; $params[':disk_id'] = $diskId; }
$source = $pdo->prepare("SELECT id,disk_id,partition_id,path FROM files WHERE {$where} ORDER BY id");
$source->execute($params);
$directoryStmt = $pdo->prepare('INSERT INTO file_browser_entries (disk_id,partition_id,parent_hash,name,is_directory,last_seen_at,is_deleted) VALUES (:disk_id,:partition_id,:parent_hash,:name,1,NOW(),0) ON DUPLICATE KEY UPDATE partition_id=VALUES(partition_id),last_seen_at=NOW(),is_deleted=0');
$fileStmt = $pdo->prepare('INSERT INTO file_browser_entries (disk_id,partition_id,parent_hash,name,is_directory,file_id,last_seen_at,is_deleted) VALUES (:disk_id,:partition_id,:parent_hash,:name,0,:file_id,NOW(),0) ON DUPLICATE KEY UPDATE partition_id=VALUES(partition_id),file_id=VALUES(file_id),last_seen_at=NOW(),is_deleted=0');

$processed = 0; $pdo->beginTransaction();
while ($file = $source->fetch()) {
    $segments = browserSegmentsForBackfill((string)$file['path']);
    if ($segments === []) continue;
    $parentPath = '/'; $last = count($segments) - 1;
    for ($index = 0; $index < $last; $index++) {
        $directoryStmt->execute([':disk_id'=>(int)$file['disk_id'], ':partition_id'=>$file['partition_id'] !== null ? (int)$file['partition_id'] : null, ':parent_hash'=>hash('sha256', $parentPath), ':name'=>$segments[$index]]);
        $parentPath = rtrim($parentPath, '/') . '/' . $segments[$index];
    }
    $fileStmt->execute([':disk_id'=>(int)$file['disk_id'], ':partition_id'=>$file['partition_id'] !== null ? (int)$file['partition_id'] : null, ':parent_hash'=>hash('sha256', $parentPath), ':name'=>$segments[$last], ':file_id'=>(int)$file['id']]);
    $processed++;
    if ($processed % 1000 === 0) { $pdo->commit(); $pdo->beginTransaction(); fwrite(STDOUT, "Processados {$processed} arquivos\n"); }
}
$pdo->commit();
fwrite(STDOUT, "Backfill concluído: {$processed} arquivos processados.\n");
