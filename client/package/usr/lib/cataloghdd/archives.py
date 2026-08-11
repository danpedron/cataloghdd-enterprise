#!/usr/bin/env python3
"""Enumeração segura de arquivos compactados para o CatalogHDD.

O módulo somente lê diretórios internos e cabeçalhos. Ele nunca extrai arquivos
nem executa conteúdo do pacote.
"""
from __future__ import annotations

import datetime as dt
import mimetypes
import os
import shutil
import subprocess
import tarfile
import zipfile
from pathlib import Path
from typing import Any, Iterator

ARCHIVE_EXTENSIONS = {
    '.zip', '.jar', '.apk', '.cbz', '.tar', '.tgz', '.tbz', '.tbz2', '.txz',
    '.gz', '.bz2', '.xz', '.7z', '.rar', '.cab', '.iso',
}


class ArchiveError(RuntimeError):
    pass


class ArchiveUnsupported(ArchiveError):
    pass


class ArchiveLimitReached(ArchiveError):
    pass


def archive_format(path: Path) -> str | None:
    name = path.name.lower()
    if name.endswith(('.tar.gz', '.tar.bz2', '.tar.xz', '.tgz', '.tbz', '.tbz2', '.txz')):
        return 'tar'
    if name.endswith(('.zip', '.jar', '.apk', '.cbz')):
        return 'zip'
    if name.endswith('.tar'):
        return 'tar'
    if name.endswith('.7z'):
        return '7z'
    if name.endswith('.rar'):
        return 'rar'
    if name.endswith('.cab'):
        return 'cab'
    return None


def is_archive(path: Path) -> bool:
    return archive_format(path) is not None


def normalize_path(value: str) -> str | None:
    path = value.replace('\\', '/').strip().lstrip('./')
    if not path or '\x00' in path:
        return None
    parts = [part for part in path.split('/') if part not in ('', '.')]
    if any(part == '..' for part in parts):
        return None
    return '/'.join(parts)


def format_time(value: dt.datetime | tuple[int, int, int, int, int, int] | None) -> str | None:
    if value is None:
        return None
    try:
        if isinstance(value, tuple):
            return dt.datetime(*value[:6]).strftime('%Y-%m-%d %H:%M:%S')
        return value.strftime('%Y-%m-%d %H:%M:%S')
    except (TypeError, ValueError, OverflowError):
        return None


def classify(name: str) -> tuple[str, str | None, str | None]:
    extension = Path(name).suffix.lower().lstrip('.') or None
    mime, _ = mimetypes.guess_type(name)
    if mime and mime.startswith('image/'):
        kind = 'image'
    elif mime and mime.startswith('video/'):
        kind = 'video'
    elif mime and mime.startswith('audio/'):
        kind = 'audio'
    elif mime and (mime.startswith('text/') or mime in {'application/pdf', 'application/msword'}):
        kind = 'document'
    elif extension in {'zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz'}:
        kind = 'archive'
    else:
        kind = 'file'
    return kind, extension, mime


def entry(path: str, size: int | None, modified: str | None, is_directory: bool, archive_format_name: str) -> dict[str, Any] | None:
    normalized = normalize_path(path)
    if normalized is None:
        return None
    is_directory = bool(is_directory or normalized.endswith('/'))
    normalized = normalized.rstrip('/')
    if not normalized:
        return None
    name = normalized.rsplit('/', 1)[-1]
    kind, extension, mime = classify(name)
    return {
        'path': normalized,
        'name': name,
        'extension': extension,
        'size': None if is_directory else max(0, int(size or 0)),
        'modified': modified,
        'mime_type': mime,
        'file_type': 'folder' if is_directory else kind,
        'is_directory': is_directory,
        'metadata': {'archive_format': archive_format_name},
    }


def entries_from_zip(path: Path, max_entries: int) -> Iterator[dict[str, Any]]:
    with zipfile.ZipFile(path, 'r') as archive:
        for index, info in enumerate(archive.infolist(), start=1):
            if index > max_entries:
                raise ArchiveLimitReached(f'Limite de {max_entries} entradas atingido.')
            result = entry(info.filename, info.file_size, format_time(info.date_time), info.is_dir(), 'zip')
            if result is not None:
                yield result


def entries_from_tar(path: Path, max_entries: int) -> Iterator[dict[str, Any]]:
    with tarfile.open(path, 'r:*') as archive:
        for index, info in enumerate(archive, start=1):
            if index > max_entries:
                raise ArchiveLimitReached(f'Limite de {max_entries} entradas atingido.')
            modified = dt.datetime.fromtimestamp(info.mtime).strftime('%Y-%m-%d %H:%M:%S') if info.mtime else None
            result = entry(info.name, info.size, modified, info.isdir(), 'tar')
            if result is not None:
                yield result


def entries_from_7z(path: Path, max_entries: int, archive_format_name: str) -> Iterator[dict[str, Any]]:
    binary = shutil.which('7z') or shutil.which('7zz')
    if not binary:
        raise ArchiveUnsupported('O comando 7z não está instalado para listar este formato.')
    process = subprocess.run([binary, 'l', '-slt', str(path)], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=180, check=False)
    if process.returncode not in (0, 1):
        raise ArchiveError(process.stderr.strip()[-500:] or '7z não conseguiu listar o arquivo.')
    records: list[dict[str, str]] = []
    current: dict[str, str] = {}
    reading = False
    for raw_line in process.stdout.splitlines():
        line = raw_line.strip()
        if line == '----------':
            reading = True
            if current:
                records.append(current); current = {}
            continue
        if not reading or ' = ' not in line:
            continue
        key, value = line.split(' = ', 1)
        if key == 'Path' and current:
            records.append(current); current = {}
        current[key] = value
    if current:
        records.append(current)
    emitted = 0
    for record_data in records:
        name = record_data.get('Path', '')
        if name in {str(path), path.name}:
            continue
        emitted += 1
        if emitted > max_entries:
            raise ArchiveLimitReached(f'Limite de {max_entries} entradas atingido.')
        folder = record_data.get('Folder', '-') == '+' or record_data.get('Attributes', '').startswith('D')
        size_value = record_data.get('Size')
        try:
            size = int(size_value) if size_value else 0
        except ValueError:
            size = 0
        modified = record_data.get('Modified')
        result = entry(name, size, modified, folder, archive_format_name)
        if result is not None:
            yield result


def list_entries(path: Path, max_entries: int) -> Iterator[dict[str, Any]]:
    kind = archive_format(path)
    if kind is None:
        raise ArchiveUnsupported('Formato não suportado para indexação virtual.')
    try:
        if kind == 'zip':
            yield from entries_from_zip(path, max_entries)
        elif kind == 'tar':
            yield from entries_from_tar(path, max_entries)
        else:
            yield from entries_from_7z(path, max_entries, kind)
    except (OSError, zipfile.BadZipFile, tarfile.TarError) as exc:
        raise ArchiveError(str(exc)) from exc
