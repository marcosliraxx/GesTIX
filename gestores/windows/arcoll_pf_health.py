# Caminho: gestores/windows/arcoll_pf_health.py
"""Módulo independente de health; contratos e validações compartilhados no núcleo."""
import subprocess
import time
from arcoll_pentefino import CATEGORIES, REGISTRY_CHECKS, _CHROMIUM, _scaled, _step, execute_clean
from arcoll_pf_registry import _scan_registry
from arcoll_pf_startup import _startup_items

HEALTH_SPACE = ('user_temp', 'windows_temp', 'thumbnails', 'system_logs', 'error_reports', 'windows_update', 'gpu_shader_cache', 'chkdsk_fragments')
HEALTH_PRIVACY = ('recent_docs', 'jump_lists', 'run_history', 'search_history', 'clipboard', 'firefox_history', 'firefox_cookies') + tuple('%s_%s' % (browser, facet) for browser in _CHROMIUM for facet in ('history', 'cookies'))


def _tool_health(payload, progress):
    space, privacy = payload.get('space') or [], payload.get('privacy') or []
    for group, allowed in ((space, HEALTH_SPACE), (privacy, HEALTH_PRIVACY)):
        if not isinstance(group, list) or len(set(group)) != len(group) or any(c not in allowed or c not in CATEGORIES for c in group):
            raise ValueError('Categorias de verificação inválidas.')
    empty = {'categories': {}, 'file_count': 0, 'bytes': 0, 'errors': 0}
    report = {'tool': 'health'}
    _step(progress, 2, 'Coletando e analisando arquivos...')
    report['privacy'] = execute_clean(privacy, False, 0, _scaled(progress, 2, 40), payload.get('exclusions'), payload.get('custom_folders')) if privacy else empty
    _step(progress, 40, 'Coletando alguns arquivos inúteis antigos...')
    report['space'] = execute_clean(space, False, 7, _scaled(progress, 40, 80), payload.get('exclusions'), payload.get('custom_folders')) if space else empty
    report['incomplete'] = any(report[p].get('errors', 0) or report[p].get('truncated', False) for p in ('space', 'privacy'))
    _step(progress, 80, 'Inventariando inicialização...')
    items = _startup_items()
    report['speed'] = {'startup_total': len(items), 'startup_enabled': sum(1 for i in items if i.get('enabled'))}
    _step(progress, 90, 'Analisando o registro, sem corrigir...')
    try:
        issues = _scan_registry(REGISTRY_CHECKS, _scaled(progress, 90, 99))
        report['registry'] = {'issues': len(issues), 'complete': True}
    except (OSError, subprocess.SubprocessError, RuntimeError) as exc:
        report['registry'] = {'issues': 0, 'complete': False, 'message': str(exc)[:250]}
        report['incomplete'] = True
    report['created'] = True
    report['captured_at'] = int(time.time())
    report['message'] = 'Análise concluída, sem alterações. Revise espaço, privacidade, registro e inicialização.'
    _step(progress, 100, 'Análise concluída. Nenhuma correção foi aplicada.')
    return report


# ----------------------------------------------------------------------------------------------------------------
# Otimização de Desempenho: suspender e retomar aplicativos em segundo plano
# ----------------------------------------------------------------------------------------------------------------
