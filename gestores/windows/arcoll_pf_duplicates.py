# Caminho: gestores/windows/arcoll_pf_duplicates.py
"""Módulo independente de duplicates; contratos e validações compartilhados no núcleo."""
from pathlib import Path
import os
import time
from arcoll_pentefino import _REPARSE_POINT, _dedupe, _fixed_drive, _is_link, _item_id, _load_cache, _profiles, _recycle, _remove_file, _save_cache, _sha1_file

_DUP_SKIP_DIRS = {'windows', 'program files', 'program files (x86)', 'programdata', '$recycle.bin', 'system volume information', 'appdata',
                  'node_modules', '.git', 'recovery', 'msocache', 'onedrivetemp'}
MAX_DUP_GROUPS = 300
MAX_DUP_PER_GROUP = 20


def _duplicate_roots(tokens):
    roots = []
    for token in tokens:
        if token == 'users':
            for profile in _profiles():
                roots += [profile['home'] / n for n in ('Desktop', 'Documents', 'Downloads', 'Pictures', 'Videos', 'Music')]
        elif _fixed_drive(str(token)):
            roots.append(Path(str(token).upper() + '\\'))
        else:
            raise ValueError('Origem de duplicados inválida.')
    return [r for r in _dedupe(roots) if r.is_dir()]


def _tool_duplicates(payload, progress):
    report = {'tool': 'duplicates'}
    if payload.get('action') == 'delete':
        report.update(_delete_duplicates(payload))
    else:
        tokens = payload.get('roots') or ['users']
        if not isinstance(tokens, list) or not tokens or len(tokens) > 6:
            raise ValueError('Origens de duplicados inválidas.')
        min_size = int(payload.get('min_size_kb') or 1024) * 1024
        if min_size < 1024 or min_size > 1073741824:
            raise ValueError('Tamanho mínimo inválido.')
        deadline = time.time() + 900
        by_size, scanned, truncated = {}, 0, False
        for root in _duplicate_roots(tokens):
            for folder, dirs, files in os.walk(root, followlinks=False):
                dirs[:] = [d for d in dirs if d.lower() not in _DUP_SKIP_DIRS and not _is_link(os.path.join(folder, d))]
                for name in files:
                    path = os.path.join(folder, name)
                    try:
                        info = os.lstat(path)
                    except OSError:
                        continue
                    if info.st_size >= min_size and not (getattr(info, 'st_file_attributes', 0) & _REPARSE_POINT):
                        by_size.setdefault(info.st_size, []).append(path)
                        scanned += 1
                if time.time() > deadline or scanned > 600000:
                    truncated = True
                    break
            if truncated:
                break
        candidates = {s: p for s, p in by_size.items() if len(p) > 1}
        groups, hashed = [], 0
        for number, (size, paths) in enumerate(sorted(candidates.items(), reverse=True), 1):
            if time.time() > deadline:
                truncated = True
                break
            if progress and number % 25 == 0:
                progress('Comparando arquivos: grupo %d/%d' % (number, len(candidates)))
            partial = {}
            for path in paths:
                try:
                    partial.setdefault(_sha1_file(path, 65536), []).append(path)
                except OSError:
                    pass
            for same in (v for v in partial.values() if len(v) > 1):
                full = {}
                for path in same:
                    try:
                        full.setdefault(_sha1_file(path), []).append(path)
                        hashed += size
                    except OSError:
                        pass
                for digest, equal in full.items():
                    if len(equal) > 1:
                        groups.append({'hash': digest, 'size': size, 'paths': sorted(equal)[:MAX_DUP_PER_GROUP]})
        groups.sort(key=lambda g: -g['size'] * (len(g['paths']) - 1))
        groups = groups[:MAX_DUP_GROUPS]
        cache = [{'hash': g['hash'], 'size': g['size'], 'files': [{'id': _item_id(p), 'path': p} for p in g['paths']]} for g in groups]
        _save_cache('duplicates.json', cache)
        report.update({'groups': [{'size': g['size'], 'files': [{'id': f['id'], 'path': f['path'], 'modified': int(os.path.getmtime(f['path'])) if os.path.exists(f['path']) else 0}
                                                                  for f in g['files']]} for g in cache],
                       'scanned': scanned, 'wasted': sum(g['size'] * (len(g['files']) - 1) for g in cache), 'truncated': truncated})
    return report


def _delete_duplicates(payload):
    ids = payload.get('ids')
    if not isinstance(ids, list) or not ids or len(ids) > 500 or any(not isinstance(i, str) for i in ids):
        raise ValueError('Seleção de duplicados inválida.')
    cache, chosen, removed, freed, failed = _load_cache('duplicates.json'), set(ids), 0, 0, 0
    if not cache:
        raise ValueError('Execute a busca de duplicados novamente.')
    for group in cache:
        victims = [f for f in group['files'] if f['id'] in chosen]
        keepers = [f for f in group['files'] if f['id'] not in chosen and os.path.isfile(f['path'])]
        if not victims or not keepers:   # nunca remove todas as cópias
            failed += len(victims)
            continue
        try:
            if os.path.getsize(keepers[0]['path']) != group['size'] or _sha1_file(keepers[0]['path']) != group['hash']:
                failed += len(victims)
                continue
        except OSError:
            failed += len(victims)
            continue
        for victim in victims:
            try:
                if os.path.getsize(victim['path']) != group['size'] or _sha1_file(victim['path']) != group['hash']:
                    failed += 1
                    continue
                if payload.get('permanent'):
                    _remove_file(victim['path'])
                elif not _recycle(victim['path']):
                    failed += 1
                    continue
                removed += 1
                freed += group['size']
            except OSError:
                failed += 1
    return {'removed': removed, 'freed': freed, 'failed': failed}


# ----------------------------------------------------------------------------------------------------------------
# Restauração do sistema
# ----------------------------------------------------------------------------------------------------------------
