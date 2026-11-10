# Caminho: gestores/windows/arcoll_pf_cloud.py
"""Módulo independente de cloud; contratos e validações compartilhados no núcleo."""
from pathlib import Path
import fnmatch
import json
import os
import re
import time
from arcoll_pentefino import _REPARSE_POINT, _is_link, _item_id, _load_cache, _norm, _profiles, _recycle, _safe_listdir, _save_cache, _sha1_file, _step

_CLOUD_CACHE = 'cloud-last.json'
_CLOUD_JUNK = ('~$*', '*.tmp', 'thumbs.db', '.ds_store', '*conflicted copy*', '*cópia em conflito*', '*.crdownload', '*.part', '*.partial')
_CLOUD_PLACEHOLDER = 0x1000 | 0x400000 | 0x40000   # OFFLINE | RECALL_ON_DATA_ACCESS | RECALL_ON_OPEN: ler o arquivo o baixaria da nuvem
MAX_CLOUD_ITEMS = 120


def _cloud_roots():
    roots, seen = [], set()

    def add(key, name, path):
        try:
            resolved = Path(path).resolve()
        except (OSError, ValueError):
            return
        if resolved.is_dir() and len(resolved.parts) >= 3 and _norm(resolved) not in seen:
            seen.add(_norm(resolved))
            roots.append({'key': key, 'name': name, 'root': resolved})

    for profile in _profiles():
        home = profile['home']
        for entry in _safe_listdir(home):
            low = entry.name.lower()
            if Path(entry).is_dir() and (low == 'onedrive' or low.startswith('onedrive -')):
                add('onedrive', 'OneDrive' + (' (%s)' % entry.name.split('-', 1)[1].strip() if '-' in entry.name else ''), entry)
        for name, key, label in (('Google Drive', 'gdrive', 'Google Drive'), ('iCloudDrive', 'icloud', 'iCloud Drive'), ('iCloud Drive', 'icloud', 'iCloud Drive'), ('Box', 'box', 'Box')):
            add(key, label, Path(home) / name)
        for base in (profile['local'], profile['roaming']):
            info = base / 'Dropbox' / 'info.json'
            try:
                data = json.loads(info.read_text(encoding='utf-8'))
                for account in data.values() if isinstance(data, dict) else []:
                    if isinstance(account, dict) and account.get('path'):
                        add('dropbox', 'Dropbox', account['path'])
            except (OSError, ValueError):
                pass
    for env in ('OneDrive', 'OneDriveConsumer', 'OneDriveCommercial'):
        if os.environ.get(env):
            add('onedrive', 'OneDrive', os.environ[env])
    for letter in 'DEFGHIJKLMNOPQRSTUVWXYZ':
        for name in ('Meu Drive', 'My Drive'):
            add('gdrive', 'Google Drive', '%s:\\%s' % (letter, name))
    return roots


def _cloud_scan(roots, progress, deadline):
    items, providers, total_files = [], [], 0
    by_size, large_min, old_min = {}, 100 * 1048576, 10 * 1048576
    old_cutoff = time.time() - 730 * 86400
    truncated = False
    for number, entry in enumerate(roots, 1):
        root, files, size = entry['root'], 0, 0
        stack = [str(root)]
        _step(progress, 5 + 60 * (number - 1) // max(1, len(roots)), 'Analisando %s...' % entry['name'])
        while stack:
            if time.time() > deadline or total_files > 400000:
                truncated = True
                break
            folder = stack.pop()
            try:
                with os.scandir(folder) as it:
                    for child in it:
                        try:
                            if child.is_symlink():
                                continue
                            attrs = getattr(child.stat(follow_symlinks=False), 'st_file_attributes', 0)
                            if child.is_dir(follow_symlinks=False):
                                if not attrs & _REPARSE_POINT:
                                    stack.append(child.path)
                                continue
                            st = child.stat(follow_symlinks=False)
                        except OSError:
                            continue
                        files += 1
                        total_files += 1
                        size += st.st_size
                        local = not (getattr(st, 'st_file_attributes', 0) & _CLOUD_PLACEHOLDER)
                        item = {'provider': entry['key'], 'path': child.path, 'bytes': int(st.st_size), 'modified': int(st.st_mtime), 'local': local}
                        low = child.name.lower()
                        if any(fnmatch.fnmatch(low, pat) for pat in _CLOUD_JUNK):
                            items.append(dict(item, cat='junk'))
                        elif st.st_size >= large_min:
                            items.append(dict(item, cat='large'))
                        elif st.st_size >= old_min and st.st_mtime < old_cutoff:
                            items.append(dict(item, cat='old'))
                        if local and st.st_size >= 1048576 and st.st_size <= 2 * 1073741824:
                            by_size.setdefault(st.st_size, []).append(item)
            except OSError:
                continue
        providers.append({'key': entry['key'], 'name': entry['name'], 'root': str(root), 'files': files, 'bytes': size})
    # Duplicados: mesmo tamanho, depois conteúdo idêntico (somente arquivos já presentes no computador).
    _step(progress, 70, 'Procurando arquivos duplicados na nuvem...')
    for size, group in by_size.items():
        if len(group) < 2 or len(group) > 8 or time.time() > deadline:
            continue
        hashes = {}
        for item in group:
            try:
                hashes.setdefault(_sha1_file(item['path']), []).append(item)
            except OSError:
                continue
        for same in hashes.values():
            for extra in sorted(same, key=lambda i: i['modified'])[1:]:
                items.append(dict(extra, cat='duplicate'))
    return items, providers, truncated


def _cloud_public(items):
    cats = {c: {'count': 0, 'bytes': 0} for c in ('junk', 'duplicate', 'large', 'old')}
    for item in items:
        cats[item['cat']]['count'] += 1
        cats[item['cat']]['bytes'] += item['bytes']
    rows = []
    for cat in cats:
        mine = sorted((i for i in items if i['cat'] == cat), key=lambda i: -i['bytes'])[:MAX_CLOUD_ITEMS]
        rows += [{'id': _item_id('cloud', i['path']), 'cat': cat, 'provider': i['provider'], 'path': i['path'][:400], 'bytes': i['bytes'],
                  'modified': i['modified'], 'local': i['local']} for i in mine]
    return cats, rows


def _tool_cloud(payload, progress):
    action = str(payload.get('action') or 'scan')
    report = {'tool': 'cloud'}
    if action == 'delete':
        ids = payload.get('ids')
        if not isinstance(ids, list) or not 1 <= len(ids) <= 300 or any(not re.fullmatch(r'[a-f0-9]{16}', str(i)) for i in ids):
            raise ValueError('Seleção de arquivos inválida.')
        last = _load_cache(_CLOUD_CACHE) or {}
        known = {i['id']: i for i in last.get('items', []) if isinstance(i, dict) and 'id' in i and 'path' in i}
        roots = [_norm(r['root']) for r in _cloud_roots()]
        removed = failed = freed = 0
        for number, ident in enumerate(ids, 1):
            _step(progress, 10 + 85 * number // len(ids), 'Enviando para a Lixeira %d/%d...' % (number, len(ids)))
            entry = known.get(ident)
            try:
                path = Path(entry['path']).resolve() if entry else None
                inside = path is not None and any(_norm(path).startswith(r + os.sep) for r in roots)
                if not inside or not path.is_file() or _is_link(path):
                    raise OSError('fora das pastas de nuvem')
                size = path.stat().st_size
                if not _recycle(path):
                    raise OSError('lixeira')
                removed += 1
                freed += size
            except (OSError, KeyError, ValueError):
                failed += 1
        report.update({'removed': removed, 'freed': freed, 'failed': failed})
    roots = _cloud_roots()
    if not roots:
        report.update({'providers': [], 'items': [], 'cats': {}, 'truncated': False})
        _save_cache(_CLOUD_CACHE, {'items': []})
        return report
    items, providers, truncated = _cloud_scan(roots, progress, time.time() + 420)
    cats, rows = _cloud_public(items)
    full = {_item_id('cloud', i['path']): i['path'] for i in items}
    _save_cache(_CLOUD_CACHE, {'items': [dict(r, path=full.get(r['id'], r['path'])) for r in rows]})   # caminhos completos ficam só no computador
    report.update({'providers': providers, 'cats': cats, 'items': rows, 'truncated': truncated})
    _step(progress, 100, 'Análise concluída.')
    return report


# ----------------------------------------------------------------------------------------------------------------
# Ponto de entrada das ferramentas
# ----------------------------------------------------------------------------------------------------------------