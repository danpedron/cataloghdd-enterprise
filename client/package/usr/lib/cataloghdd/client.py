#!/usr/bin/env python3
"""Cliente Debian do CatalogHDD Enterprise.

O cliente é deliberadamente conservador: indexa apenas dispositivos de bloco do
nível solicitado, monta somente partições elegíveis em leitura e jamais formata,
repara ou remonta o disco de origem.
"""
from __future__ import annotations

import argparse
import configparser
import datetime as dt
import hashlib
import json
import mimetypes
import os
import platform
import re
import shutil
import ssl
import subprocess
import sys
import tempfile
import time
import uuid
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Iterator
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import Request, urlopen

from archives import ArchiveError, ArchiveLimitReached, ArchiveUnsupported, archive_format, is_archive, list_entries

CONFIG_PATH = Path('/etc/cataloghdd/client.conf')
CLIENT_VERSION = '1.4.2'
USER_AGENT = 'CatalogHDD-Debian-Client/' + CLIENT_VERSION
SUPPORTED_SKIP_FILESYSTEMS = {'swap', 'crypto_luks', 'lvm2_member', 'linux_raid_member'}
IMAGE_EXTENSIONS = {'.jpg', '.jpeg', '.png', '.gif', '.webp', '.bmp', '.tif', '.tiff', '.heic'}
VIDEO_EXTENSIONS = {'.mp4', '.mkv', '.avi', '.mov', '.webm', '.m4v', '.mpeg', '.mpg'}
AUDIO_EXTENSIONS = {'.mp3', '.flac', '.wav', '.ogg', '.m4a', '.aac', '.wma'}
DOCUMENT_EXTENSIONS = {'.pdf', '.doc', '.docx', '.xls', '.xlsx', '.odt', '.ods', '.epub', '.txt', '.md'}
ARCHIVE_EXTENSIONS = {'.zip', '.7z', '.rar', '.tar', '.gz', '.bz2', '.xz', '.iso'}

try:
    from PIL import Image, ImageOps
except ImportError:
    Image = None
    ImageOps = None


class ClientError(RuntimeError):
    pass


class ApiError(ClientError):
    pass


@dataclass
class Partition:
    number: int
    device: str
    capacity: int
    filesystem: str | None
    label: str | None
    uuid: str | None
    partuuid: str | None
    mountpoints: list[str]
    status: str = 'unmounted'
    error: str | None = None


@dataclass
class Disk:
    device: str
    kname: str
    model: str | None
    serial: str | None
    wwn: str | None
    transport: str | None
    capacity: int
    removable: bool
    rotational: bool
    readonly: bool
    partitions: list[Partition]


class ApiClient:
    def __init__(self, base_url: str, token: str, timeout: int = 90):
        self.base_url = base_url.rstrip('/')
        self.token = token
        self.timeout = timeout
        self.context = ssl.create_default_context()

    def endpoint(self, route: str) -> str:
        glue = '&' if '?' in self.base_url else '?'
        return f'{self.base_url}/{glue}r={route}'

    def json(self, route: str, payload: dict[str, Any]) -> dict[str, Any]:
        request = Request(
            self.endpoint(route), data=json.dumps(payload, ensure_ascii=False).encode('utf-8'), method='POST',
            headers={'Authorization': f'Bearer {self.token}', 'Content-Type': 'application/json; charset=utf-8', 'Accept': 'application/json', 'User-Agent': USER_AGENT},
        )
        return self._request(request)

    def thumbnail(self, run_id: int, path_hash: str, key: str, image: Path) -> dict[str, Any]:
        boundary = '----CatalogHDD' + uuid.uuid4().hex
        fields = {'run_id': str(run_id), 'path_hash': path_hash, 'thumbnail_key': key}
        body = bytearray()
        for name, value in fields.items():
            body.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
        body.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="thumbnail"; filename="thumbnail.jpg"\r\nContent-Type: image/jpeg\r\n\r\n'.encode())
        body.extend(image.read_bytes())
        body.extend(f'\r\n--{boundary}--\r\n'.encode())
        request = Request(
            self.endpoint('api-index-thumbnail'), data=bytes(body), method='POST',
            headers={'Authorization': f'Bearer {self.token}', 'Content-Type': f'multipart/form-data; boundary={boundary}', 'Accept': 'application/json', 'User-Agent': USER_AGENT},
        )
        return self._request(request)

    def _request(self, request: Request) -> dict[str, Any]:
        try:
            with urlopen(request, timeout=self.timeout, context=self.context) as response:
                raw = response.read().decode('utf-8')
        except HTTPError as exc:
            raw = exc.read().decode('utf-8', errors='replace')
            try:
                detail = json.loads(raw).get('error', raw)
            except json.JSONDecodeError:
                detail = raw
            raise ApiError(f'API respondeu HTTP {exc.code}: {detail}') from exc
        except URLError as exc:
            raise ApiError(f'Não foi possível conectar à API: {exc.reason}') from exc
        try:
            return json.loads(raw)
        except json.JSONDecodeError as exc:
            raise ApiError('A API retornou uma resposta que não é JSON.') from exc


def command(args: list[str], timeout: int = 60, check: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=timeout, check=check)


def lsblk() -> list[dict[str, Any]]:
    result = command(['lsblk', '--json', '--bytes', '-o', 'NAME,KNAME,PATH,TYPE,SIZE,MODEL,SERIAL,WWN,TRAN,RM,RO,ROTA,PKNAME,PARTN,FSTYPE,LABEL,UUID,PARTUUID,MOUNTPOINTS'])
    return json.loads(result.stdout).get('blockdevices', [])


def flatten(nodes: list[dict[str, Any]]) -> Iterator[dict[str, Any]]:
    for node in nodes:
        yield node
        yield from flatten(node.get('children') or [])


def normalize_mountpoints(value: Any) -> list[str]:
    if isinstance(value, str):
        return [value] if value else []
    if isinstance(value, list):
        return [str(item) for item in value if item]
    return []


def read_disk(device: str) -> Disk:
    requested = os.path.realpath(device)
    if not requested.startswith('/dev/'):
        raise ClientError('Informe um dispositivo de bloco em /dev/, por exemplo /dev/sdc.')
    nodes = list(flatten(lsblk()))
    target = next((node for node in nodes if os.path.realpath(str(node.get('path') or '')) == requested), None)
    if target is None:
        raise ClientError(f'Dispositivo não encontrado: {device}')
    if target.get('type') != 'disk':
        raise ClientError(f'{device} não é um disco físico. Informe o disco inteiro, e não uma partição.')
    if not os.path.exists(requested):
        raise ClientError(f'Dispositivo não está acessível: {device}')
    kname = str(target.get('kname') or '')
    partitions: list[Partition] = []
    for node in nodes:
        if node.get('type') != 'part' or str(node.get('pkname') or '') != kname:
            continue
        number = node.get('partn')
        path = str(node.get('path') or '')
        if not isinstance(number, int) and not (isinstance(number, str) and number.isdigit()):
            continue
        if not path.startswith('/dev/'):
            continue
        partitions.append(Partition(
            number=int(number), device=path, capacity=int(node.get('size') or 0),
            filesystem=(str(node.get('fstype')).lower() if node.get('fstype') else None),
            label=str(node.get('label')) if node.get('label') else None,
            uuid=str(node.get('uuid')) if node.get('uuid') else None,
            partuuid=str(node.get('partuuid')) if node.get('partuuid') else None,
            mountpoints=normalize_mountpoints(node.get('mountpoints')),
        ))
    if not partitions and target.get('fstype'):
        partitions.append(Partition(
            number=0, device=requested, capacity=int(target.get('size') or 0),
            filesystem=str(target.get('fstype')).lower(), label=str(target.get('label')) if target.get('label') else None,
            uuid=str(target.get('uuid')) if target.get('uuid') else None, partuuid=None,
            mountpoints=normalize_mountpoints(target.get('mountpoints')),
        ))
    return Disk(
        device=requested, kname=kname, model=str(target.get('model')).strip() if target.get('model') else None,
        serial=str(target.get('serial')).strip() if target.get('serial') else None,
        wwn=str(target.get('wwn')).strip() if target.get('wwn') else None,
        transport=str(target.get('tran')).strip() if target.get('tran') else None,
        capacity=int(target.get('size') or 0), removable=bool(int(target.get('rm') or 0)),
        rotational=bool(int(target.get('ro') or 0) == 0 and str(target.get('rota') or '0') == '1'), readonly=bool(int(target.get('ro') or 0)), partitions=sorted(partitions, key=lambda p: p.number),
    )


def display_disk(disk: Disk, as_json: bool = False) -> None:
    payload = {
        'device': disk.device, 'model': disk.model, 'serial': disk.serial, 'wwn': disk.wwn,
        'transport': disk.transport, 'capacity': disk.capacity, 'removable': disk.removable,
        'readonly': disk.readonly, 'partitions': [partition_payload(partition) for partition in disk.partitions],
    }
    if as_json:
        print(json.dumps(payload, ensure_ascii=False, indent=2))
        return
    print(f'Disco: {disk.device}')
    print(f'Modelo: {disk.model or "não informado"}')
    print(f'Serial: {disk.serial or "não informado"}')
    print(f'WWN: {disk.wwn or "não informado"}')
    print(f'Capacidade: {format_bytes(disk.capacity)}')
    print(f'Transporte: {disk.transport or "não informado"}')
    print('Partições:')
    if not disk.partitions:
        print('  Nenhuma partição detectada.')
    for partition in disk.partitions:
        mounted = ', '.join(partition.mountpoints) if partition.mountpoints else 'não montada'
        caption = 'Disco inteiro' if partition.number == 0 else f'#{partition.number}'
        print(f'  {caption} {partition.device} · {format_bytes(partition.capacity)} · {partition.filesystem or "sem filesystem"} · {mounted}')


def partition_payload(partition: Partition) -> dict[str, Any]:
    return {
        'number': partition.number, 'device': partition.device, 'capacity': partition.capacity,
        'filesystem': partition.filesystem, 'label': partition.label, 'uuid': partition.uuid,
        'partuuid': partition.partuuid, 'mount_point_hint': partition.mountpoints[0] if partition.mountpoints else None,
        'status': partition.status,
    }


def ensure_root() -> None:
    if os.geteuid() != 0:
        raise ClientError('A indexação precisa de sudo para montar partições com segurança. Execute: sudo cataloghdd index /dev/sdc')


def load_config() -> tuple[str, str]:
    if not CONFIG_PATH.is_file():
        raise ClientError('Cliente ainda não configurado. Execute cataloghdd configure --server URL --token TOKEN.')
    if CONFIG_PATH.stat().st_mode & 0o077:
        raise ClientError(f'Permissões inseguras em {CONFIG_PATH}; execute sudo chmod 600 {CONFIG_PATH}.')
    config = configparser.ConfigParser()
    config.read(CONFIG_PATH)
    url = config.get('server', 'url', fallback='').strip()
    token = config.get('server', 'token', fallback='').strip()
    if not url.startswith('https://') or not token:
        raise ClientError('Configuração incompleta: use URL HTTPS e token de indexação.')
    return url, token


def save_config(url: str, token: str) -> None:
    if os.geteuid() != 0:
        raise ClientError('A configuração é protegida. Execute: sudo cataloghdd configure ...')
    if not url.startswith('https://'):
        raise ClientError('A URL precisa começar com https://.')
    if len(token) < 32:
        raise ClientError('O token informado parece inválido.')
    CONFIG_PATH.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
    content = f'[server]\nurl = {url.rstrip("/")}/\ntoken = {token}\n'
    temporary = CONFIG_PATH.with_suffix('.tmp')
    temporary.write_text(content, encoding='utf-8')
    os.chmod(temporary, 0o600)
    os.replace(temporary, CONFIG_PATH)
    print(f'Configuração salva com segurança em {CONFIG_PATH}.')


def mount_partition(partition: Partition, mount_root: Path) -> tuple[Path | None, bool]:
    filesystem = (partition.filesystem or '').lower()
    if filesystem in SUPPORTED_SKIP_FILESYSTEMS:
        partition.status = 'encrypted' if filesystem == 'crypto_luks' else 'unsupported'
        return None, False
    if not filesystem:
        partition.status = 'empty'
        return None, False
    if partition.mountpoints and filesystem != 'btrfs':
        partition.status = 'mounted'
        return Path(partition.mountpoints[0]), False
    point = mount_root / ('disk-whole' if partition.number == 0 else f'part-{partition.number}')
    point.mkdir(mode=0o700)
    options = 'ro,nosuid,nodev,noexec'
    if filesystem == 'btrfs':
        options += ',norecovery,subvolid=5'
    result = command(['mount', '-o', options, partition.device, str(point)], timeout=45, check=False)
    if result.returncode != 0:
        partition.status = 'error'
        partition.error = result.stderr.strip()[-400:] or 'mount falhou'
        return None, False
    partition.status = 'mounted'
    return point, True


def unmount_partition(point: Path, mounted_by_client: bool) -> None:
    if not mounted_by_client:
        return
    result = command(['umount', str(point)], timeout=45, check=False)
    if result.returncode != 0:
        raise ClientError(f'Não foi possível desmontar {point}: {result.stderr.strip()}')


def filesystem_usage(root: Path) -> dict[str, int]:
    """Coleta capacidade lógica do filesystem já montado, sem alterar a origem."""
    try:
        stats = os.statvfs(root)
        total = max(0, int(stats.f_blocks) * int(stats.f_frsize))
        free = max(0, int(stats.f_bavail) * int(stats.f_frsize))
        return {'used_bytes': max(0, total - free), 'free_bytes': free}
    except OSError:
        return {}


def btrfs_subvolume_map(root: Path) -> list[tuple[str, int]]:
    """Retorna subvolumes conhecidos a partir de uma montagem Btrfs no topo (id 5)."""
    if not shutil.which('btrfs'):
        return []
    result = command(['btrfs', 'subvolume', 'list', str(root)], timeout=45, check=False)
    if result.returncode != 0:
        return []
    discovered: list[tuple[str, int]] = []
    for line in result.stdout.splitlines():
        match = re.match(r'^ID\\s+(\\d+).*?\\spath\\s+(.+)$', line.strip())
        if match and match.group(2).strip():
            discovered.append((match.group(2).strip().strip('/'), int(match.group(1))))
    return sorted(discovered, key=lambda item: len(item[0]), reverse=True)


def btrfs_metadata(relative_path: str, subvolumes: list[tuple[str, int]]) -> dict[str, Any]:
    for subvolume_path, subvolume_id in subvolumes:
        if relative_path == subvolume_path or relative_path.startswith(subvolume_path + '/'):
            return {'btrfs_subvolume_id': subvolume_id, 'btrfs_subvolume_path': '/' + subvolume_path}
    return {}


def file_type(mime: str | None, extension: str) -> str:
    if mime:
        if mime.startswith('image/'): return 'image'
        if mime.startswith('video/'): return 'video'
        if mime.startswith('audio/'): return 'audio'
        if mime.startswith('text/'): return 'document'
    if extension in IMAGE_EXTENSIONS: return 'image'
    if extension in VIDEO_EXTENSIONS: return 'video'
    if extension in AUDIO_EXTENSIONS: return 'audio'
    if extension in DOCUMENT_EXTENSIONS: return 'document'
    if extension in ARCHIVE_EXTENSIONS: return 'archive'
    return 'file'


def iso_time(timestamp: float) -> str:
    return dt.datetime.fromtimestamp(timestamp).strftime('%Y-%m-%d %H:%M:%S')


def image_info(path: Path) -> dict[str, Any]:
    if Image is None:
        return {}
    try:
        with Image.open(path) as image:
            return {'width': image.width, 'height': image.height, 'format': image.format}
    except (OSError, ValueError):
        return {}


def image_for_jpeg(image: Any) -> Any:
    """Normaliza transparência e modos do Pillow antes de gravar um JPEG."""
    if image.mode == 'P' and image.info.get('transparency') is not None:
        # Evita o aviso do Pillow para tabelas de transparência em bytes e preserva alpha.
        image = image.convert('RGBA')
    if ImageOps is not None:
        image = ImageOps.exif_transpose(image)
    if image.mode in ('RGBA', 'LA'):
        rgba = image.convert('RGBA')
        background = Image.new('RGB', rgba.size, (255, 255, 255))
        background.paste(rgba, mask=rgba.getchannel('A'))
        return background
    if image.mode not in ('RGB', 'L'):
        return image.convert('RGB')
    return image


def make_thumbnail(path: Path, kind: str, destination: Path, max_px: int = 256, quality: int = 70) -> bool:
    try:
        if kind == 'image' and Image is not None:
            with Image.open(path) as image:
                image = image_for_jpeg(image)
                image.thumbnail((max_px, max_px))
                image.save(destination, 'JPEG', quality=quality, optimize=True)
            return True
        if kind == 'video' and shutil.which('ffmpeg'):
            result = command(['ffmpeg', '-y', '-ss', '00:00:01', '-i', str(path), '-frames:v', '1', '-vf', f'scale={max_px}:{max_px}:force_original_aspect_ratio=decrease', '-q:v', '5', str(destination)], timeout=90, check=False)
            return result.returncode == 0 and destination.is_file() and destination.stat().st_size > 0
    except (OSError, ValueError, subprocess.SubprocessError):
        return False
    return False


def iter_files(root: Path) -> Iterator[Path]:
    for current, directories, filenames in os.walk(root, followlinks=False):
        directories[:] = [directory for directory in directories if directory not in {'.Trash-1000', '$RECYCLE.BIN', 'System Volume Information'}]
        for filename in filenames:
            path = Path(current) / filename
            try:
                if path.is_file() and not path.is_symlink():
                    yield path
            except OSError:
                continue


def record_for_file(path: Path, root: Path, partition_number: int, extra_metadata: dict[str, Any] | None = None) -> dict[str, Any]:
    stat = path.stat()
    extension = path.suffix.lower()
    mime, _ = mimetypes.guess_type(str(path))
    kind = file_type(mime, extension)
    relative = path.relative_to(root).as_posix()
    logical_root = 'disco-inteiro' if partition_number == 0 else f'particao-{partition_number}'
    logical_path = f'/{logical_root}/{relative}'
    metadata: dict[str, Any] = {'source_relative_path': relative, 'created_at': iso_time(stat.st_ctime), 'inode': getattr(stat, 'st_ino', None)}
    if extra_metadata:
        metadata.update(extra_metadata)
    if kind == 'image': metadata.update(image_info(path))
    return {'partition_number': partition_number, 'path': logical_path, 'name': path.name, 'size': stat.st_size, 'modified': iso_time(stat.st_mtime), 'extension': extension.lstrip('.'), 'mime_type': mime, 'file_type': kind, 'metadata': {key: value for key, value in metadata.items() if value is not None}}


def send_batch(client: ApiClient, run_id: int, disk_id: int, batch: list[tuple[dict[str, Any], Path | None]], counters: dict[str, int]) -> None:
    if not batch:
        return
    response = client.json('api-index-batch', {'run_id': run_id, 'files': [record for record, _ in batch]})
    counters['indexed'] += int(response.get('accepted') or 0)
    for record, thumbnail in batch:
        if thumbnail is None:
            continue
        path_hash = hashlib.sha256(record['path'].encode('utf-8')).hexdigest()
        thumbnail_key = hashlib.sha256(f'{disk_id}:{path_hash}'.encode('ascii')).hexdigest()
        try:
            client.thumbnail(run_id, path_hash, thumbnail_key, thumbnail)
            counters['thumbnails'] += 1
        finally:
            thumbnail.unlink(missing_ok=True)


def scan_archive(client: ApiClient, run_id: int, record: dict[str, Any], archive_path: Path, counters: dict[str, int], args: argparse.Namespace) -> None:
    """Enumera um compactado físico já enviado à API e persiste suas entradas virtuais."""
    archive_kind = archive_format(archive_path)
    if archive_kind is None or not args.archives:
        return
    started = client.json('api-archive-start', {
        'run_id': run_id, 'archive_path': record['path'], 'archive_format': archive_kind,
        'partition_number': record['partition_number'],
    })
    scan_id = int(started['archive_scan_id'])
    virtual_batch: list[dict[str, Any]] = []
    status = 'completed'; error_summary: str | None = None
    try:
        if archive_path.stat().st_size > args.max_archive_bytes:
            raise ArchiveLimitReached(f'Arquivo compactado excede o limite configurado de {format_bytes(args.max_archive_bytes)}.')
        for entry in list_entries(archive_path, args.max_archive_entries):
            virtual_batch.append(entry)
            if len(virtual_batch) >= args.archive_batch_size:
                response = client.json('api-archive-batch', {'run_id': run_id, 'archive_scan_id': scan_id, 'entries': virtual_batch})
                counters['virtual'] += int(response.get('accepted') or 0)
                virtual_batch.clear()
        if virtual_batch:
            response = client.json('api-archive-batch', {'run_id': run_id, 'archive_scan_id': scan_id, 'entries': virtual_batch})
            counters['virtual'] += int(response.get('accepted') or 0)
    except ArchiveLimitReached as exc:
        if virtual_batch:
            response = client.json('api-archive-batch', {'run_id': run_id, 'archive_scan_id': scan_id, 'entries': virtual_batch})
            counters['virtual'] += int(response.get('accepted') or 0)
            virtual_batch.clear()
        status = 'partial'; error_summary = str(exc); counters['archive_warnings'] += 1
    except ArchiveUnsupported as exc:
        status = 'unsupported'; error_summary = str(exc); counters['archive_warnings'] += 1
    except (ArchiveError, OSError, ApiError) as exc:
        status = 'error'; error_summary = str(exc); counters['errors'] += 1
    finally:
        client.json('api-archive-finish', {'run_id': run_id, 'archive_scan_id': scan_id, 'status': status, 'error_summary': error_summary})


def index_disk(device: str, args: argparse.Namespace) -> int:
    ensure_root()
    disk = read_disk(device)
    if not disk.partitions:
        raise ClientError('Nenhuma partição nem filesystem foi detectado no disco. O cliente só indexa discos com partições ou filesystem reconhecido diretamente no dispositivo.')
    if args.dry_run:
        display_disk(disk, args.json)
        print('Modo de simulação: nenhum volume foi montado e nenhum dado foi enviado.')
        return 0
    url, token = load_config()
    client = ApiClient(url, token, args.timeout)
    policy_response = client.json('api-index-settings', {})
    policy = policy_response.get('settings') if isinstance(policy_response.get('settings'), dict) else {}
    args.archives = bool(args.archives and policy.get('index_archives', True))
    args.thumbnails = bool(args.thumbnails and policy.get('generate_thumbnails', True))
    args.max_archive_entries = max(1, int(policy.get('max_archive_entries', args.max_archive_entries)))
    args.max_archive_bytes = max(1, int(policy.get('max_archive_bytes', args.max_archive_bytes)))
    args.max_thumbnail_source_bytes = max(1, int(policy.get('max_thumbnail_source_bytes', args.max_thumbnail_source_bytes)))
    args.thumbnail_max_px = min(512, max(64, int(policy.get('thumbnail_max_px', args.thumbnail_max_px))))
    args.thumbnail_quality = min(90, max(1, int(policy.get('thumbnail_quality', args.thumbnail_quality))))
    effective_options = {'archives':args.archives,'thumbnails':args.thumbnails,'max_archive_entries':args.max_archive_entries,'max_archive_bytes':args.max_archive_bytes,'max_thumbnail_source_bytes':args.max_thumbnail_source_bytes,'thumbnail_max_px':args.thumbnail_max_px,'thumbnail_quality':args.thumbnail_quality}
    payload = {'disk_id': args.disk_id, 'label': args.label or disk.model or Path(disk.device).name, 'serial': args.serial or disk.serial or disk.wwn or Path(disk.device).name, 'model': disk.model, 'capacity': disk.capacity, 'filesystem': None, 'root_path': disk.device, 'source_host': platform.node(), 'client_version': CLIENT_VERSION, 'client_options': effective_options, 'partitions': [partition_payload(partition) for partition in disk.partitions]}
    started = client.json('api-index-start', payload)
    run_id, disk_id = int(started['run_id']), int(started['disk_id'])
    counters = {'discovered': 0, 'indexed': 0, 'virtual': 0, 'thumbnails': 0, 'archive_warnings': 0, 'errors': 0}
    errors: list[str] = []
    mount_root = Path(tempfile.mkdtemp(prefix='cataloghdd-mount-'))
    thumbs = Path(tempfile.mkdtemp(prefix='cataloghdd-thumbs-'))
    states: list[dict[str, Any]] = []
    batch: list[tuple[dict[str, Any], Path | None]] = []
    try:
        for partition in disk.partitions:
            point: Path | None = None; mounted_by_client = False; usage: dict[str, int] = {}
            try:
                point, mounted_by_client = mount_partition(partition, mount_root)
                if point is None:
                    if partition.error: errors.append(f'{partition.device}: {partition.error}')
                    continue
                usage = filesystem_usage(point)
                subvolumes = btrfs_subvolume_map(point) if (partition.filesystem or '').lower() == 'btrfs' else []
                if subvolumes:
                    print(f'Btrfs: {len(subvolumes)} subvolume(s) detectado(s) em {partition.device}.')
                for path in iter_files(point):
                    counters['discovered'] += 1
                    try:
                        relative = path.relative_to(point).as_posix()
                        record = record_for_file(path, point, partition.number, btrfs_metadata(relative, subvolumes))
                        thumbnail: Path | None = None
                        if args.thumbnails and record['file_type'] in {'image', 'video'} and record['size'] <= args.max_thumbnail_source_bytes:
                            candidate = thumbs / f'{hashlib.sha256(record["path"].encode()).hexdigest()}.jpg'
                            if make_thumbnail(path, record['file_type'], candidate, args.thumbnail_max_px, args.thumbnail_quality): thumbnail = candidate
                        batch.append((record, thumbnail))
                        if is_archive(path) and args.archives:
                            send_batch(client, run_id, disk_id, batch, counters); batch.clear()
                            scan_archive(client, run_id, record, path, counters, args)
                        elif len(batch) >= args.batch_size:
                            send_batch(client, run_id, disk_id, batch, counters); batch.clear()
                    except (OSError, ValueError, ApiError) as exc:
                        counters['errors'] += 1
                        if len(errors) < 30: errors.append(f'{path}: {exc}')
                partition.status = 'indexed'
            except ClientError as exc:
                partition.status = 'error'; counters['errors'] += 1
                if len(errors) < 30: errors.append(f'{partition.device}: {exc}')
            finally:
                if point is not None:
                    try:
                        unmount_partition(point, mounted_by_client)
                    except ClientError as exc:
                        counters['errors'] += 1
                        if len(errors) < 30: errors.append(str(exc))
                states.append({'number': partition.number, 'status': partition.status, **usage})
        send_batch(client, run_id, disk_id, batch, counters)
        client.json('api-index-finish', {'run_id': run_id, 'errors_count': counters['errors'], 'error_summary': '\n'.join(errors) if errors else None, 'partitions': states})
        print(f'Indexação concluída: disco #{disk_id}; {counters["indexed"]:,} arquivos físicos; {counters["virtual"]:,} entradas virtuais; {counters["thumbnails"]:,} miniaturas; {counters["errors"]} erros; {counters["archive_warnings"]} avisos de compactados.')
        return 0
    except Exception as exc:
        counters['errors'] += 1
        errors.append(str(exc))
        try: client.json('api-index-finish', {'run_id': run_id, 'errors_count': counters['errors'], 'error_summary': '\n'.join(errors), 'partitions': states})
        except ApiError: pass
        raise
    finally:
        shutil.rmtree(thumbs, ignore_errors=True)
        shutil.rmtree(mount_root, ignore_errors=True)


def format_bytes(value: int) -> str:
    amount = float(value); units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']; index = 0
    while amount >= 1024 and index < len(units) - 1: amount /= 1024; index += 1
    return f'{amount:.2f} {units[index]}' if index else f'{int(amount)} {units[index]}'


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(prog='cataloghdd', description='Cliente Debian do CatalogHDD Enterprise.')
    commands = parser.add_subparsers(dest='command', required=True)
    configure = commands.add_parser('configure', help='Salvar URL HTTPS e token de indexação.')
    configure.add_argument('--server', required=True, help='URL do CatalogHDD Enterprise.')
    configure.add_argument('--token', required=True, help='Token de indexação.')
    inspect = commands.add_parser('inspect', help='Exibir os dados e partições de um disco sem modificá-lo.')
    inspect.add_argument('device', help='Disco físico, ex.: /dev/sdc')
    inspect.add_argument('--json', action='store_true', help='Exibir resultado em JSON.')
    index = commands.add_parser('index', help='Montar partições em leitura e indexar um disco.')
    index.add_argument('device', help='Disco físico, ex.: /dev/sdc')
    index.add_argument('--disk-id', type=int, help='ID de volume existente no CatalogHDD.')
    index.add_argument('--label', help='Rótulo a usar no catálogo.')
    index.add_argument('--serial', help='Serial a usar se não puder ser detectado.')
    index.add_argument('--dry-run', action='store_true', help='Exibir o plano sem montar ou transmitir dados.')
    index.add_argument('--thumbnails', action=argparse.BooleanOptionalAction, default=True, help='Gerar miniaturas de imagens e vídeos.')
    index.add_argument('--archives', action=argparse.BooleanOptionalAction, default=True, help='Indexar o conteúdo interno de arquivos compactados sem extraí-los.')
    index.add_argument('--max-archive-entries', type=int, default=20000, choices=range(1, 200001), metavar='1-200000', help='Limite de entradas lidas por arquivo compactado.')
    index.add_argument('--max-archive-bytes', type=int, default=8 * 1024 * 1024 * 1024, help='Tamanho máximo de pacote compactado a ser enumerado.')
    index.add_argument('--archive-batch-size', type=int, default=200, choices=range(1, 501), metavar='1-500')
    index.add_argument('--batch-size', type=int, default=100, choices=range(1, 201), metavar='1-200')
    index.add_argument('--timeout', type=int, default=90, choices=range(10, 301), metavar='10-300')
    index.add_argument('--max-thumbnail-source-bytes', type=int, default=3 * 1024 * 1024 * 1024)
    index.add_argument('--thumbnail-max-px', type=int, default=256, choices=range(64, 513), metavar='64-512')
    index.add_argument('--thumbnail-quality', type=int, default=70, choices=range(1, 91), metavar='1-90')
    index.add_argument('--json', action='store_true', help='Exibir o disco no modo de simulação como JSON.')
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    try:
        if args.command == 'configure':
            save_config(args.server, args.token); return 0
        if args.command == 'inspect':
            display_disk(read_disk(args.device), args.json); return 0
        if args.command == 'index':
            return index_disk(args.device, args)
        return 2
    except ClientError as exc:
        print(f'ERRO: {exc}', file=sys.stderr); return 2
    except KeyboardInterrupt:
        print('Operação interrompida.', file=sys.stderr); return 130
    except Exception as exc:
        print(f'ERRO inesperado: {exc}', file=sys.stderr); return 3


if __name__ == '__main__':
    sys.exit(main())
