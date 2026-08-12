<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function apiJson(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function apiBody(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($data)) apiJson(400, ['error' => 'JSON inválido.']);
    return $data;
}

function apiUser(): array
{
    $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/^Bearer\s+([A-Za-z0-9_-]{32,256})$/', $header, $matches)) {
        apiJson(401, ['error' => 'Token de API ausente ou inválido.']);
    }
    $token = $matches[1];
    $stmt = db()->prepare(
        "SELECT t.id AS token_id, t.scopes, t.expires_at, u.id, u.username, u.role, u.is_active
         FROM api_tokens t INNER JOIN users u ON u.id=t.user_id
         WHERE t.token_hash=:hash AND t.revoked_at IS NULL AND u.is_active=1
           AND (t.expires_at IS NULL OR t.expires_at > NOW()) LIMIT 1"
    );
    $stmt->execute([':hash' => hash('sha256', $token)]);
    $user = $stmt->fetch();
    if (!$user || !in_array('index', explode(',', (string) $user['scopes']), true)) {
        apiJson(401, ['error' => 'Token de API sem escopo de indexação.']);
    }
    db()->prepare('UPDATE api_tokens SET last_used_at=NOW() WHERE id=:id')->execute([':id' => $user['token_id']]);
    return $user;
}

function apiAudit(array $user, string $event, ?string $resourceType = null, string|int|null $resourceId = null, array $details = []): void
{
    db()->prepare('INSERT INTO audit_log (user_id,event_type,resource_type,resource_id,details_json,ip_address,user_agent) VALUES (:user_id,:event,:type,:resource,:details,:ip,:ua)')
        ->execute([
            ':user_id' => (int) $user['id'], ':event' => $event, ':type' => $resourceType,
            ':resource' => $resourceId === null ? null : (string) $resourceId,
            ':details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ':ip' => clientIp(), ':ua' => userAgent(),
        ]);
}

function apiCanManageDisk(array $user, int $diskId): bool
{
    if ($user['role'] === 'admin') return true;
    $stmt = db()->prepare("SELECT 1 FROM disk_access WHERE user_id=:user_id AND disk_id=:disk_id AND permission='manage' LIMIT 1");
    $stmt->execute([':user_id' => (int)$user['id'], ':disk_id' => $diskId]);
    return (bool) $stmt->fetchColumn();
}

function apiCleanString(mixed $value, int $max): ?string
{
    if (!is_scalar($value)) return null;
    $value = trim((string)$value);
    return $value === '' ? null : mb_substr($value, 0, $max);
}

function apiIndexSettings(): never
{
    $user = apiUser();
    apiAudit($user, 'index.settings.read');
    apiJson(200, ['settings' => indexSettings()]);
}

function apiSyncPartitions(PDO $pdo, int $diskId, array $partitions): array
{
    if (count($partitions) > 128) apiJson(422, ['error' => 'Quantidade de partições acima do limite permitido.']);
    $upsert = $pdo->prepare(
        "INSERT INTO disk_partitions (disk_id,partition_number,device_path,partuuid,filesystem_uuid,label,filesystem,capacity,mount_point_hint,status,last_seen_at)
         VALUES (:disk_id,:number,:device_path,:partuuid,:filesystem_uuid,:label,:filesystem,:capacity,:mount_point_hint,:status,NOW())
         ON DUPLICATE KEY UPDATE device_path=VALUES(device_path),partuuid=VALUES(partuuid),filesystem_uuid=VALUES(filesystem_uuid),label=VALUES(label),filesystem=VALUES(filesystem),capacity=VALUES(capacity),mount_point_hint=VALUES(mount_point_hint),status=VALUES(status),last_seen_at=NOW()"
    );
    $lookup = $pdo->prepare('SELECT id FROM disk_partitions WHERE disk_id=:disk_id AND partition_number=:number LIMIT 1');
    $map = [];
    foreach ($partitions as $partition) {
        if (!is_array($partition) || !isset($partition['number']) || !is_numeric($partition['number'])) continue;
        $number = (int)$partition['number'];
        if ($number < 0 || $number > 256) continue;
        $device = apiCleanString($partition['device'] ?? null, 255);
        if ($device === null || !str_starts_with($device, '/dev/')) continue;
        $status = apiCleanString($partition['status'] ?? null, 32) ?? 'unmounted';
        if (!in_array($status, ['indexed','mounted','unmounted','unsupported','encrypted','error','empty'], true)) $status = 'unmounted';
        $capacity = isset($partition['capacity']) && is_numeric($partition['capacity']) ? max(0, (int)$partition['capacity']) : null;
        $upsert->execute([
            ':disk_id'=>$diskId, ':number'=>$number, ':device_path'=>$device,
            ':partuuid'=>apiCleanString($partition['partuuid'] ?? null, 128),
            ':filesystem_uuid'=>apiCleanString($partition['uuid'] ?? null, 255),
            ':label'=>apiCleanString($partition['label'] ?? null, 255),
            ':filesystem'=>apiCleanString($partition['filesystem'] ?? null, 64),
            ':capacity'=>$capacity, ':mount_point_hint'=>apiCleanString($partition['mount_point_hint'] ?? null, 1024), ':status'=>$status,
        ]);
        $lookup->execute([':disk_id'=>$diskId, ':number'=>$number]);
        $map[$number] = (int)$lookup->fetchColumn();
    }
    return $map;
}

function apiStart(): never
{
    $user = apiUser(); $data = apiBody();
    $label = apiCleanString($data['label'] ?? null, 255);
    $serial = apiCleanString($data['serial'] ?? null, 255);
    $model = apiCleanString($data['model'] ?? null, 100);
    $rootPath = apiCleanString($data['root_path'] ?? null, 1024);
    $filesystem = apiCleanString($data['filesystem'] ?? null, 64);
    $sourceHost = apiCleanString($data['source_host'] ?? null, 255);
    $partitions = isset($data['partitions']) && is_array($data['partitions']) ? $data['partitions'] : [];
    $clientVersion = apiCleanString($data['client_version'] ?? null, 64);
    $clientOptions = isset($data['client_options']) && is_array($data['client_options']) ? $data['client_options'] : [];
    $capacity = isset($data['capacity']) && is_numeric($data['capacity']) ? max(0, (int)$data['capacity']) : null;
    if ($rootPath === null || ($label === null && $serial === null)) apiJson(422, ['error' => 'Informe root_path e ao menos label ou serial.']);

    $pdo = db(); $pdo->beginTransaction();
    try {
        $disk = null;
        if ($serial !== null) {
            $lookup = $pdo->prepare('SELECT * FROM disks WHERE serial=:serial LIMIT 1'); $lookup->execute([':serial'=>$serial]); $disk=$lookup->fetch();
        }
        if (!$disk && isset($data['disk_id']) && is_numeric($data['disk_id'])) {
            $lookup=$pdo->prepare('SELECT * FROM disks WHERE id=:id LIMIT 1');$lookup->execute([':id'=>(int)$data['disk_id']]);$disk=$lookup->fetch();
        }
        if ($disk) {
            if (!apiCanManageDisk($user, (int)$disk['id'])) { $pdo->rollBack(); apiJson(403, ['error'=>'O token não possui permissão de indexação neste volume.']); }
            $pdo->prepare('UPDATE disks SET label=COALESCE(:label,label),serial=COALESCE(:serial,serial),model=COALESCE(:model,model),capacity=COALESCE(:capacity,capacity),root_path=:root_path,filesystem=:filesystem,last_seen_at=NOW(),status=\'active\' WHERE id=:id')
                ->execute([':label'=>$label,':serial'=>$serial,':model'=>$model,':capacity'=>$capacity,':root_path'=>$rootPath,':filesystem'=>$filesystem,':id'=>$disk['id']]);
            $diskId=(int)$disk['id'];
        } else {
            if (!in_array($user['role'], ['admin','operator'], true)) { $pdo->rollBack(); apiJson(403,['error'=>'Seu perfil não pode criar novos volumes.']); }
            $pdo->prepare('INSERT INTO disks (label,serial,model,capacity,root_path,filesystem,status,last_seen_at) VALUES (:label,:serial,:model,:capacity,:root_path,:filesystem,\'active\',NOW())')
                ->execute([':label'=>$label,':serial'=>$serial,':model'=>$model,':capacity'=>$capacity,':root_path'=>$rootPath,':filesystem'=>$filesystem]);
            $diskId=(int)$pdo->lastInsertId();
            if ($user['role'] === 'operator') $pdo->prepare("INSERT INTO disk_access (user_id,disk_id,permission,granted_by) VALUES (:user_id,:disk_id,'manage',:granted_by)")->execute([':user_id'=>$user['id'],':disk_id'=>$diskId,':granted_by'=>$user['id']]);
        }
        $partitionMap = apiSyncPartitions($pdo, $diskId, $partitions);
        $pdo->prepare('INSERT INTO index_runs (disk_id,requested_by,source_host,client_version,options_json,root_path,status) VALUES (:disk_id,:user_id,:source_host,:client_version,:options_json,:root_path,\'running\')')
            ->execute([':disk_id'=>$diskId,':user_id'=>$user['id'],':source_host'=>$sourceHost,':client_version'=>$clientVersion,':options_json'=>$clientOptions?json_encode($clientOptions,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,':root_path'=>$rootPath]);
        $runId=(int)$pdo->lastInsertId();
        if ($partitionMap) $pdo->prepare('UPDATE disk_partitions SET last_run_id=:run_id,last_index_host=:source_host WHERE disk_id=:disk_id')->execute([':run_id'=>$runId,':source_host'=>$sourceHost,':disk_id'=>$diskId]);
        $pdo->commit();apiAudit($user,'index.run.started','disk',$diskId,['run_id'=>$runId,'root_path'=>$rootPath,'partitions'=>array_keys($partitionMap),'client_version'=>$clientVersion]);apiJson(201,['disk_id'=>$diskId,'run_id'=>$runId,'partitions'=>$partitionMap,'settings'=>indexSettings()]);
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('CatalogHDD api start: '.$e->getMessage()); apiJson(500,['error'=>'Não foi possível iniciar a indexação.']); }
}

function apiRunForUser(array $user, int $runId): array
{
    $stmt=db()->prepare('SELECT r.*,d.id AS disk_id FROM index_runs r INNER JOIN disks d ON d.id=r.disk_id WHERE r.id=:id AND r.status=\'running\' LIMIT 1');$stmt->execute([':id'=>$runId]);$run=$stmt->fetch();if(!$run)apiJson(404,['error'=>'Execução ativa não encontrada.']);if(!apiCanManageDisk($user,(int)$run['disk_id']))apiJson(403,['error'=>'Sem permissão para esta execução.']);return $run;
}

function apiBrowserSegments(string $path): array
{
    $segments = [];
    foreach (explode('/', trim($path, '/')) as $segment) {
        if ($segment !== '' && $segment !== '.' && $segment !== '..') $segments[] = $segment;
    }
    return $segments;
}

function apiSyncBrowserEntries(PDOStatement $directoryStmt, PDOStatement $fileEntryStmt, int $diskId, ?int $partitionId, int $fileId, string $path, array &$seenDirectories): void
{
    $segments = apiBrowserSegments($path);
    if ($segments === []) return;
    $parentPath = '/'; $last = count($segments) - 1;
    for ($index = 0; $index < $last; $index++) {
        $parentHash = hash('sha256', $parentPath); $cacheKey = ($partitionId ?? 0) . ':' . $parentHash . ':' . $segments[$index];
        if (!isset($seenDirectories[$cacheKey])) {
            $directoryStmt->execute([':disk_id'=>$diskId, ':partition_id'=>$partitionId, ':parent_hash'=>$parentHash, ':name'=>$segments[$index]]);
            $seenDirectories[$cacheKey] = true;
        }
        $parentPath = rtrim($parentPath, '/') . '/' . $segments[$index];
    }
    $fileEntryStmt->execute([':disk_id'=>$diskId, ':partition_id'=>$partitionId, ':parent_hash'=>hash('sha256', $parentPath), ':name'=>$segments[$last], ':file_id'=>$fileId]);
}

function apiAccumulatePartitionDelta(array &$deltas, ?int $partitionId, int $countDelta, int $sizeDelta): void
{
    if ($partitionId === null) return;
    if (!isset($deltas[$partitionId])) $deltas[$partitionId] = ['count'=>0, 'size'=>0];
    $deltas[$partitionId]['count'] += $countDelta; $deltas[$partitionId]['size'] += $sizeDelta;
}

function apiApplyInventoryCounters(PDO $pdo, int $diskId, int $diskCountDelta, int $diskSizeDelta, array $partitionDeltas): void
{
    if ($diskCountDelta !== 0 || $diskSizeDelta !== 0) {
        $pdo->prepare('UPDATE disks SET indexed_file_count=GREATEST(0,indexed_file_count+:count_delta),indexed_size_bytes=GREATEST(0,indexed_size_bytes+:size_delta) WHERE id=:disk_id')
            ->execute([':count_delta'=>$diskCountDelta, ':size_delta'=>$diskSizeDelta, ':disk_id'=>$diskId]);
    }
    $partitionStmt = $pdo->prepare('UPDATE disk_partitions SET indexed_file_count=GREATEST(0,indexed_file_count+:count_delta),indexed_size_bytes=GREATEST(0,indexed_size_bytes+:size_delta) WHERE id=:partition_id');
    foreach ($partitionDeltas as $partitionId => $delta) {
        if ($delta['count'] !== 0 || $delta['size'] !== 0) $partitionStmt->execute([':count_delta'=>$delta['count'], ':size_delta'=>$delta['size'], ':partition_id'=>$partitionId]);
    }
}

function apiRebuildInventoryCounters(PDO $pdo, int $diskId): void
{
    $pdo->prepare('UPDATE disks d LEFT JOIN (SELECT disk_id,COUNT(*) AS file_count,COALESCE(SUM(size),0) AS size_bytes FROM files WHERE disk_id=:source_disk AND is_deleted=0 GROUP BY disk_id) totals ON totals.disk_id=d.id SET d.indexed_file_count=COALESCE(totals.file_count,0),d.indexed_size_bytes=COALESCE(totals.size_bytes,0) WHERE d.id=:target_disk')
        ->execute([':source_disk'=>$diskId, ':target_disk'=>$diskId]);
    $pdo->prepare('UPDATE disk_partitions p LEFT JOIN (SELECT partition_id,COUNT(*) AS file_count,COALESCE(SUM(size),0) AS size_bytes FROM files WHERE disk_id=:source_disk AND partition_id IS NOT NULL AND is_deleted=0 GROUP BY partition_id) totals ON totals.partition_id=p.id SET p.indexed_file_count=COALESCE(totals.file_count,0),p.indexed_size_bytes=COALESCE(totals.size_bytes,0) WHERE p.disk_id=:target_disk')
        ->execute([':source_disk'=>$diskId, ':target_disk'=>$diskId]);
}

function apiBatch(): never
{
    $user = apiUser(); $data = apiBody(); $runId = (int)($data['run_id'] ?? 0); $records = $data['files'] ?? null;
    if ($runId < 1 || !is_array($records) || count($records) < 1 || count($records) > 200) apiJson(422, ['error'=>'Envie entre 1 e 200 registros por lote.']);
    $run = apiRunForUser($user, $runId); $pdo = db(); $pdo->beginTransaction(); $accepted = 0;
    $fileStmt = $pdo->prepare('INSERT INTO files (disk_id,partition_id,path,path_hash,name,size,modified,extension,mime_type,file_type,thumbnail_key,metadata_json,indexed_at,last_seen_at,is_deleted) VALUES (:disk_id,:partition_id,:path,:path_hash,:name,:size,:modified,:extension,:mime_type,:file_type,:thumbnail_key,:metadata_json,NOW(),NOW(),0) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),partition_id=VALUES(partition_id),name=VALUES(name),size=VALUES(size),modified=VALUES(modified),extension=VALUES(extension),mime_type=VALUES(mime_type),file_type=VALUES(file_type),thumbnail_key=COALESCE(VALUES(thumbnail_key),thumbnail_key),metadata_json=VALUES(metadata_json),indexed_at=NOW(),last_seen_at=NOW(),is_deleted=0');
    $directoryStmt = $pdo->prepare('INSERT INTO file_browser_entries (disk_id,partition_id,parent_hash,name,is_directory,last_seen_at,is_deleted) VALUES (:disk_id,:partition_id,:parent_hash,:name,1,NOW(),0) ON DUPLICATE KEY UPDATE partition_id=VALUES(partition_id),last_seen_at=NOW(),is_deleted=0');
    $fileEntryStmt = $pdo->prepare('INSERT INTO file_browser_entries (disk_id,partition_id,parent_hash,name,is_directory,file_id,last_seen_at,is_deleted) VALUES (:disk_id,:partition_id,:parent_hash,:name,0,:file_id,NOW(),0) ON DUPLICATE KEY UPDATE partition_id=VALUES(partition_id),file_id=VALUES(file_id),last_seen_at=NOW(),is_deleted=0');
    $partitionLookup = $pdo->prepare('SELECT id FROM disk_partitions WHERE disk_id=:disk_id AND partition_number=:number LIMIT 1');
    $existingLookup = $pdo->prepare('SELECT partition_id,size,is_deleted FROM files WHERE disk_id=:disk_id AND path_hash=:path_hash LIMIT 1');
    $seenDirectories = []; $diskCountDelta = 0; $diskSizeDelta = 0; $partitionDeltas = [];
    try {
        foreach ($records as $record) {
            if (!is_array($record)) continue;
            $partitionNumber = isset($record['partition_number']) && is_numeric($record['partition_number']) ? (int)$record['partition_number'] : null;
            $partitionId = null;
            if ($partitionNumber !== null && $partitionNumber >= 0) { $partitionLookup->execute([':disk_id'=>$run['disk_id'], ':number'=>$partitionNumber]); $found = $partitionLookup->fetchColumn(); $partitionId = $found === false ? null : (int)$found; }
            $path = apiCleanString($record['path'] ?? null, 65000); $name = apiCleanString($record['name'] ?? null, 255);
            if ($path === null || $name === null) continue;
            $hash = hash('sha256', $path); $extension = strtolower((string)(apiCleanString($record['extension'] ?? null, 32) ?? ''));
            $mime = apiCleanString($record['mime_type'] ?? null, 255); $type = apiCleanString($record['file_type'] ?? null, 32) ?? 'file';
            $size = isset($record['size']) && is_numeric($record['size']) ? max(0, (int)$record['size']) : 0; $modified = apiCleanString($record['modified'] ?? null, 19);
            $thumbnail = isset($record['thumbnail_key']) && preg_match('/^[a-f0-9]{64}$/', (string)$record['thumbnail_key']) ? (string)$record['thumbnail_key'] : null;
            $metadata = $record['metadata'] ?? []; if (!is_array($metadata)) $metadata = [];
            $existingLookup->execute([':disk_id'=>$run['disk_id'], ':path_hash'=>$hash]); $existing = $existingLookup->fetch();
            if (!$existing || (int)$existing['is_deleted'] === 1) {
                $diskCountDelta++; $diskSizeDelta += $size; apiAccumulatePartitionDelta($partitionDeltas, $partitionId, 1, $size);
            } else {
                $previousSize = (int)$existing['size']; $previousPartitionId = $existing['partition_id'] === null ? null : (int)$existing['partition_id'];
                $diskSizeDelta += $size - $previousSize;
                apiAccumulatePartitionDelta($partitionDeltas, $previousPartitionId, -1, -$previousSize);
                apiAccumulatePartitionDelta($partitionDeltas, $partitionId, 1, $size);
            }
            $fileStmt->execute([':disk_id'=>$run['disk_id'], ':partition_id'=>$partitionId, ':path'=>$path, ':path_hash'=>$hash, ':name'=>$name, ':size'=>$size, ':modified'=>$modified, ':extension'=>$extension ?: null, ':mime_type'=>$mime, ':file_type'=>$type, ':thumbnail_key'=>$thumbnail, ':metadata_json'=>json_encode($metadata, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
            $fileId = (int)$pdo->lastInsertId();
            apiSyncBrowserEntries($directoryStmt, $fileEntryStmt, (int)$run['disk_id'], $partitionId, $fileId, $path, $seenDirectories);
            $accepted++;
        }
        apiApplyInventoryCounters($pdo, (int)$run['disk_id'], $diskCountDelta, $diskSizeDelta, $partitionDeltas);
        $pdo->prepare('UPDATE index_runs SET files_discovered=files_discovered+:discovered,files_indexed=files_indexed+:indexed WHERE id=:id')->execute([':discovered'=>$accepted, ':indexed'=>$accepted, ':id'=>$runId]);
        $pdo->commit(); apiJson(200, ['accepted'=>$accepted]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack(); error_log('CatalogHDD api batch: ' . $e->getMessage()); apiJson(500, ['error'=>'Não foi possível gravar o lote.']);
    }
}

function apiArchiveScanForUser(array $user, int $runId, int $scanId): array
{
    $run = apiRunForUser($user, $runId);
    $stmt = db()->prepare('SELECT * FROM archive_scans WHERE id=:id AND disk_id=:disk_id AND last_run_id=:run_id LIMIT 1');
    $stmt->execute([':id'=>$scanId, ':disk_id'=>$run['disk_id'], ':run_id'=>$runId]);
    $scan = $stmt->fetch();
    if (!$scan) apiJson(404, ['error'=>'Leitura de compactado não encontrada para esta execução.']);
    return [$run, $scan];
}

function apiArchiveStart(): never
{
    $user = apiUser(); $data = apiBody(); $runId = (int)($data['run_id'] ?? 0); $run = apiRunForUser($user, $runId);
    $path = apiCleanString($data['archive_path'] ?? null, 65000); $format = apiCleanString($data['archive_format'] ?? null, 32);
    $partitionNumber = isset($data['partition_number']) && is_numeric($data['partition_number']) ? (int)$data['partition_number'] : null;
    if ($path === null || $format === null) apiJson(422, ['error'=>'Informe archive_path e archive_format.']);
    $pdo = db(); $fileStmt=$pdo->prepare('SELECT id,partition_id,size,modified FROM files WHERE disk_id=:disk_id AND path_hash=:path_hash LIMIT 1');$fileStmt->execute([':disk_id'=>$run['disk_id'],':path_hash'=>hash('sha256',$path)]);$archiveFile=$fileStmt->fetch();
    if (!$archiveFile) apiJson(404, ['error'=>'Arquivo compactado físico não foi localizado. Envie o lote principal antes do conteúdo virtual.']);
    $partitionId = $archiveFile['partition_id'] ?: null;
    if ($partitionId === null && $partitionNumber !== null) { $partStmt=$pdo->prepare('SELECT id FROM disk_partitions WHERE disk_id=:disk_id AND partition_number=:number LIMIT 1');$partStmt->execute([':disk_id'=>$run['disk_id'],':number'=>$partitionNumber]);$partitionId=$partStmt->fetchColumn()?:null; }
    $pdo->beginTransaction();
    try {
        $upsert=$pdo->prepare("INSERT INTO archive_scans (archive_file_id,disk_id,partition_id,last_run_id,archive_format,source_size,source_modified,scan_status,entries_count,started_at,completed_at,error_summary) VALUES (:file_id,:disk_id,:partition_id,:run_id,:format,:source_size,:source_modified,'running',0,NOW(),NULL,NULL) ON DUPLICATE KEY UPDATE disk_id=VALUES(disk_id),partition_id=VALUES(partition_id),last_run_id=VALUES(last_run_id),archive_format=VALUES(archive_format),source_size=VALUES(source_size),source_modified=VALUES(source_modified),scan_status='running',entries_count=0,started_at=NOW(),completed_at=NULL,error_summary=NULL");
        $upsert->execute([':file_id'=>$archiveFile['id'],':disk_id'=>$run['disk_id'],':partition_id'=>$partitionId,':run_id'=>$runId,':format'=>$format,':source_size'=>$archiveFile['size'],':source_modified'=>$archiveFile['modified']]);
        $scanStmt=$pdo->prepare('SELECT id FROM archive_scans WHERE archive_file_id=:file_id LIMIT 1');$scanStmt->execute([':file_id'=>$archiveFile['id']]);$scanId=(int)$scanStmt->fetchColumn();
        $pdo->commit(); apiJson(201,['archive_scan_id'=>$scanId,'archive_file_id'=>(int)$archiveFile['id']]);
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('CatalogHDD api archive start: '.$e->getMessage()); apiJson(500,['error'=>'Não foi possível iniciar a leitura do compactado.']); }
}

function apiArchiveBatch(): never
{
    $user=apiUser();$data=apiBody();$runId=(int)($data['run_id']??0);$scanId=(int)($data['archive_scan_id']??0);$entries=$data['entries']??null;
    if($runId<1||$scanId<1||!is_array($entries)||count($entries)<1||count($entries)>500)apiJson(422,['error'=>'Envie entre 1 e 500 entradas virtuais por lote.']);
    [$run,$scan]=apiArchiveScanForUser($user,$runId,$scanId);$pdo=db();$pdo->beginTransaction();$accepted=0;
    $sql='INSERT INTO virtual_entries (archive_scan_id,archive_file_id,disk_id,partition_id,internal_path,path_hash,name,extension,size,modified,mime_type,file_type,is_directory,metadata_json,indexed_at,last_seen_at,is_deleted) VALUES (:scan_id,:archive_file_id,:disk_id,:partition_id,:internal_path,:path_hash,:name,:extension,:size,:modified,:mime_type,:file_type,:is_directory,:metadata_json,NOW(),NOW(),0) ON DUPLICATE KEY UPDATE archive_scan_id=VALUES(archive_scan_id),partition_id=VALUES(partition_id),name=VALUES(name),extension=VALUES(extension),size=VALUES(size),modified=VALUES(modified),mime_type=VALUES(mime_type),file_type=VALUES(file_type),is_directory=VALUES(is_directory),metadata_json=VALUES(metadata_json),indexed_at=NOW(),last_seen_at=NOW(),is_deleted=0';$stmt=$pdo->prepare($sql);
    try { foreach($entries as $entry){if(!is_array($entry))continue;$internal=apiCleanString($entry['path']??null,65000);$name=apiCleanString($entry['name']??null,255);if($internal===null||$name===null)continue;$extension=strtolower((string)(apiCleanString($entry['extension']??null,32)??''));$size=isset($entry['size'])&&is_numeric($entry['size'])?max(0,(int)$entry['size']):null;$modified=apiCleanString($entry['modified']??null,19);$mime=apiCleanString($entry['mime_type']??null,255);$type=apiCleanString($entry['file_type']??null,32)??'file';$isDirectory=!empty($entry['is_directory'])?1:0;$metadata=$entry['metadata']??[];if(!is_array($metadata))$metadata=[];$stmt->execute([':scan_id'=>$scan['id'],':archive_file_id'=>$scan['archive_file_id'],':disk_id'=>$run['disk_id'],':partition_id'=>$scan['partition_id'],':internal_path'=>$internal,':path_hash'=>hash('sha256',$internal),':name'=>$name,':extension'=>$extension?:null,':size'=>$size,':modified'=>$modified,':mime_type'=>$mime,':file_type'=>$type,':is_directory'=>$isDirectory,':metadata_json'=>json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);$accepted++; }
        $pdo->prepare('UPDATE archive_scans SET entries_count=entries_count+:accepted WHERE id=:id')->execute([':accepted'=>$accepted,':id'=>$scan['id']]);$pdo->commit();apiJson(200,['accepted'=>$accepted]);
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('CatalogHDD api archive batch: '.$e->getMessage());apiJson(500,['error'=>'Não foi possível gravar as entradas do compactado.']);}
}

function apiArchiveFinish(): never
{
    $user=apiUser();$data=apiBody();$runId=(int)($data['run_id']??0);$scanId=(int)($data['archive_scan_id']??0);[$run,$scan]=apiArchiveScanForUser($user,$runId,$scanId);$status=apiCleanString($data['status']??null,32)??'error';$summary=apiCleanString($data['error_summary']??null,65000);if(!in_array($status,['completed','partial','error','unsupported'],true))$status='error';$pdo=db();$pdo->beginTransaction();try{if($status==='completed')$pdo->prepare('UPDATE virtual_entries SET is_deleted=1 WHERE archive_file_id=:file_id AND last_seen_at < :started_at')->execute([':file_id'=>$scan['archive_file_id'],':started_at'=>$scan['started_at']]);$pdo->prepare('UPDATE archive_scans SET scan_status=:status,error_summary=:summary,completed_at=NOW() WHERE id=:id')->execute([':status'=>$status,':summary'=>$summary,':id'=>$scan['id']]);$pdo->commit();apiAudit($user,'archive.scan.finished','file',(int)$scan['archive_file_id'],['archive_scan_id'=>$scan['id'],'status'=>$status]);apiJson(200,['status'=>$status]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('CatalogHDD api archive finish: '.$e->getMessage());apiJson(500,['error'=>'Não foi possível finalizar a leitura do compactado.']);}
}

function apiThumbnail(): never
{
    $user=apiUser();$runId=(int)($_POST['run_id']??0);$pathHash=(string)($_POST['path_hash']??'');$key=(string)($_POST['thumbnail_key']??'');$run=apiRunForUser($user,$runId);if(!preg_match('/^[a-f0-9]{64}$/',$pathHash)||!preg_match('/^[a-f0-9]{64}$/',$key)||!isset($_FILES['thumbnail']))apiJson(422,['error'=>'Miniatura inválida.']);$expected=hash('sha256',$run['disk_id'].':'.$pathHash);if(!hash_equals($expected,$key))apiJson(422,['error'=>'Chave de miniatura não corresponde ao arquivo.']);$upload=$_FILES['thumbnail'];if(($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||($upload['size']??0)>5242880)apiJson(422,['error'=>'Falha no envio ou miniatura acima de 5 MB.']);$finfo=new finfo(FILEINFO_MIME_TYPE);if($finfo->file($upload['tmp_name'])!=='image/jpeg')apiJson(422,['error'=>'A miniatura deve ser JPEG.']);$dir=rtrim(appConfig()['THUMBNAIL_DIR'],'/');if(!is_dir($dir)&&!mkdir($dir,0750,true))apiJson(500,['error'=>'Armazenamento indisponível.']);$target=$dir.'/'.$key.'.jpg';if(!move_uploaded_file($upload['tmp_name'],$target))apiJson(500,['error'=>'Não foi possível armazenar a miniatura.']);chmod($target,0640);db()->prepare('UPDATE files SET thumbnail_key=:key WHERE disk_id=:disk_id AND path_hash=:path_hash')->execute([':key'=>$key,':disk_id'=>$run['disk_id'],':path_hash'=>$pathHash]);db()->prepare('UPDATE index_runs SET thumbnails_created=thumbnails_created+1 WHERE id=:id')->execute([':id'=>$runId]);apiJson(200,['stored'=>true]);
}

function apiFinish(): never
{
    $user = apiUser(); $data = apiBody(); $runId = (int)($data['run_id'] ?? 0); $run = apiRunForUser($user, $runId);
    $errors = isset($data['errors_count']) && is_numeric($data['errors_count']) ? max(0, (int)$data['errors_count']) : 0;
    $summary = apiCleanString($data['error_summary'] ?? null, 65000);
    $partitionStates = isset($data['partitions']) && is_array($data['partitions']) ? $data['partitions'] : [];
    $status = $errors > 0 ? 'completed_with_errors' : 'completed'; $pdo = db(); $pdo->beginTransaction();
    try {
        $removedStmt = $pdo->prepare('SELECT partition_id,COUNT(*) AS file_count,COALESCE(SUM(size),0) AS size_bytes FROM files WHERE disk_id=:disk_id AND is_deleted=0 AND last_seen_at IS NOT NULL AND last_seen_at < :started_at GROUP BY partition_id');
        $removedStmt->execute([':disk_id'=>$run['disk_id'], ':started_at'=>$run['started_at']]); $removedRows = $removedStmt->fetchAll();
        $removedCount = 0; $removedSize = 0; $removedPartitions = [];
        foreach ($removedRows as $removed) { $count = (int)$removed['file_count']; $size = (int)$removed['size_bytes']; $removedCount += $count; $removedSize += $size; apiAccumulatePartitionDelta($removedPartitions, $removed['partition_id'] === null ? null : (int)$removed['partition_id'], -$count, -$size); }
        $pdo->prepare('UPDATE files SET is_deleted=1 WHERE disk_id=:disk_id AND last_seen_at IS NOT NULL AND last_seen_at < :started_at')
            ->execute([':disk_id'=>$run['disk_id'], ':started_at'=>$run['started_at']]);
        apiApplyInventoryCounters($pdo, (int)$run['disk_id'], -$removedCount, -$removedSize, $removedPartitions);
        $pdo->prepare('UPDATE file_browser_entries SET is_deleted=1 WHERE disk_id=:disk_id AND last_seen_at IS NOT NULL AND last_seen_at < :started_at')
            ->execute([':disk_id'=>$run['disk_id'], ':started_at'=>$run['started_at']]);
        apiRebuildInventoryCounters($pdo, (int)$run['disk_id']);
        $partStmt = $pdo->prepare('UPDATE disk_partitions SET status=:status,last_indexed_at=CASE WHEN :indexed=1 THEN NOW() ELSE last_indexed_at END,last_seen_at=NOW(),used_bytes=COALESCE(:used_bytes,used_bytes),free_bytes=COALESCE(:free_bytes,free_bytes),usage_updated_at=CASE WHEN :has_usage=1 THEN NOW() ELSE usage_updated_at END WHERE disk_id=:disk_id AND partition_number=:number');
        foreach ($partitionStates as $part) {
            if (!is_array($part) || !isset($part['number']) || !is_numeric($part['number'])) continue;
            $partStatus = apiCleanString($part['status'] ?? null, 32) ?? 'unmounted';
            if (!in_array($partStatus, ['indexed','mounted','unmounted','unsupported','encrypted','error','empty'], true)) $partStatus = 'error';
            $used = isset($part['used_bytes']) && is_numeric($part['used_bytes']) ? max(0, (int)$part['used_bytes']) : null;
            $free = isset($part['free_bytes']) && is_numeric($part['free_bytes']) ? max(0, (int)$part['free_bytes']) : null;
            $partStmt->execute([':status'=>$partStatus, ':indexed'=>$partStatus==='indexed'?1:0, ':used_bytes'=>$used, ':free_bytes'=>$free, ':has_usage'=>($used !== null && $free !== null)?1:0, ':disk_id'=>$run['disk_id'], ':number'=>(int)$part['number']]);
        }
        $pdo->prepare('UPDATE index_runs SET status=:status,errors_count=:errors,error_summary=:summary,finished_at=NOW() WHERE id=:id')->execute([':status'=>$status, ':errors'=>$errors, ':summary'=>$summary, ':id'=>$runId]);
        $pdo->prepare('UPDATE disks SET last_indexed_at=NOW(),last_seen_at=NOW() WHERE id=:id')->execute([':id'=>$run['disk_id']]);
        $pdo->commit(); apiAudit($user, 'index.run.finished', 'disk', $run['disk_id'], ['run_id'=>$runId, 'status'=>$status, 'errors'=>$errors]); apiJson(200, ['status'=>$status]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack(); error_log('CatalogHDD api finish: ' . $e->getMessage()); apiJson(500, ['error'=>'Não foi possível finalizar a indexação.']);
    }
}
