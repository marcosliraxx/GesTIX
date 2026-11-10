# Caminho: gestores/windows/arcoll_pf_disk.py
"""Módulo independente de disk; contratos e validações compartilhados no núcleo."""
import heapq
import os
import shutil
import time
from arcoll_pentefino import _REPARSE_POINT, _fixed_drive

_TYPES = {
    'Imagens': ('.jpg', '.jpeg', '.png', '.gif', '.bmp', '.tif', '.tiff', '.webp', '.heic', '.psd', '.cdr', '.svg', '.raw'),
    'Vídeos': ('.mp4', '.mkv', '.avi', '.mov', '.wmv', '.flv', '.webm', '.mpg', '.mpeg', '.m4v'),
    'Música': ('.mp3', '.wav', '.flac', '.aac', '.ogg', '.m4a', '.wma'),
    'Documentos': ('.doc', '.docx', '.xls', '.xlsx', '.ppt', '.pptx', '.pdf', '.txt', '.csv', '.odt', '.ods', '.rtf'),
    'Compactados': ('.zip', '.rar', '.7z', '.gz', '.tar', '.iso', '.cab', '.img', '.vhd', '.vhdx'),
    'Programas e sistema': ('.exe', '.dll', '.sys', '.msi', '.msu', '.dat', '.bin', '.mui', '.cat'),
}
_EXTENSION_TYPE = {ext: name for name, exts in _TYPES.items() for ext in exts}
_DISK_SKIP = ('system volume information',)


def _tool_disk(payload, progress):
    drive = str(payload.get('drive') or os.environ.get('SystemDrive', 'C:')).upper()
    if not _fixed_drive(drive):
        raise ValueError('Unidade inválida ou não fixa.')
    root = drive + '\\'
    deadline = time.time() + 240
    by_type = {name: [0, 0] for name in list(_TYPES) + ['Outros']}
    level1, level2, largest = {}, {}, []
    files, total, truncated = 0, 0, False
    stack = [(root, ())]
    while stack:
        if time.time() > deadline:
            truncated = True
            break
        folder, chain = stack.pop()
        try:
            with os.scandir(folder) as entries:
                for entry in entries:
                    try:
                        if entry.is_symlink() or (getattr(entry.stat(follow_symlinks=False), 'st_file_attributes', 0) & _REPARSE_POINT):
                            continue
                        if entry.is_dir(follow_symlinks=False):
                            if entry.name.lower() not in _DISK_SKIP:
                                stack.append((entry.path, chain + (entry.name,) if len(chain) < 2 else chain))
                            continue
                        size = entry.stat(follow_symlinks=False).st_size
                    except OSError:
                        continue
                    files += 1
                    total += size
                    kind = _EXTENSION_TYPE.get(os.path.splitext(entry.name)[1].lower(), 'Outros')
                    by_type[kind][0] += 1
                    by_type[kind][1] += size
                    if chain:
                        level1[chain[0]] = level1.get(chain[0], 0) + size
                        if len(chain) > 1:
                            level2[chain[0] + '\\' + chain[1]] = level2.get(chain[0] + '\\' + chain[1], 0) + size
                    if size > 1048576:
                        if len(largest) < 50:
                            heapq.heappush(largest, (size, entry.path))
                        elif size > largest[0][0]:
                            heapq.heapreplace(largest, (size, entry.path))
        except OSError:
            continue
        if progress and files and files % 20000 < 200:
            progress('Analisando %s: %d arquivos' % (drive, files))
    usage = shutil.disk_usage(root)
    return {'tool': 'disk', 'drive': drive, 'files': files, 'bytes': total, 'truncated': truncated, 'disk_total': int(usage.total), 'disk_free': int(usage.free),
            'types': [{'name': n, 'count': v[0], 'bytes': v[1]} for n, v in sorted(by_type.items(), key=lambda kv: -kv[1][1])],
            'folders': [{'path': drive + '\\' + k, 'bytes': v} for k, v in sorted(level1.items(), key=lambda kv: -kv[1])[:25]]
                       + [{'path': drive + '\\' + k, 'bytes': v} for k, v in sorted(level2.items(), key=lambda kv: -kv[1])[:40]],
            'largest': [{'path': p, 'bytes': s} for s, p in sorted(largest, reverse=True)]}


# ----------------------------------------------------------------------------------------------------------------
# Localizador de duplicados
# ----------------------------------------------------------------------------------------------------------------
