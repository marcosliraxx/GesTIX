<?php
// Caminho: includes/network-clean.php
/* Caminho: includes/network-clean.php */
/* Fonte única de Programas (inventário/desinstalação) e Clean (limpeza, registro, inicialização, disco, duplicados,
   restauração do sistema, atualizador de software e verificação de integridade): catálogos, limites, validações,
   higienização dos relatórios do gestor, configurações, histórico, operação ativa e política de tempo limite.
   Usado por api/api.php, includes/bootstrap.php, redes/programas.php e redes/clean.php.
   As chaves e limites são o contrato com gestores/windows/arcoll_clean.py e arcoll_programs.py: a plataforma nunca
   envia caminhos para exclusão; envia chaves de catálogo e identificadores que o próprio gestor produziu. */
declare(strict_types=1);
require_once __DIR__ . '/error-logging.php';
require_once __DIR__.'/general-analysis.php';


/** Máximo de programas por desinstalação (tela, API e gestor usam o mesmo valor). */
const ARC_PROGRAM_BATCH_MAX = 100;

/** Idade máxima (segundos) do inventário aceito para desinstalar. */
const ARC_PROGRAM_INVENTORY_MAX_AGE = 1800;

/** Limites compartilhados com o gestor. */
const ARC_CLEAN_MAX_EXCLUSIONS = 50;
const ARC_CLEAN_MAX_CUSTOM_FOLDERS = 20;
const ARC_CLEAN_MAX_REGISTRY_IDS = 1500;

/** Idades mínimas (dias) aceitas na limpeza; 0 = tudo que não estiver em uso. */
function arc_clean_age_options(): array
{
    return [0, 1, 7, 30];
}

/** Abas da Limpeza personalizada, na ordem do CCleaner (a aba Registro usa arc_clean_registry_checks). */
function arc_clean_tabs(): array
{
    return ['junk' => 'Lixo', 'browser' => 'Navegador', 'registry' => 'Registro'];
}

/** Grupos da aba Lixo, na ordem do CCleaner: nome => [ícone Font Awesome, cor]. */
function arc_clean_junk_groups(): array
{
    return ['Windows' => 'fa-windows', 'Sistema' => 'fa-desktop', 'Programas' => 'fa-clone', 'Windows Store' => 'fa-shopping-cart', 'Utilitários' => 'fa-wrench',
            'Multimídia' => 'fa-film', 'Internet' => 'fa-globe', 'Downloads para Windows' => 'fa-arrow-circle-down', 'Ferramentas de IA' => 'fa-commenting-o', 'Avançado' => 'fa-sliders'];
}

/** Ícone de cada navegador (Font Awesome 4 não tem Brave nem Vivaldi: a tela usa a inicial). */
function arc_clean_browser_icons(): array
{
    return ['chrome' => 'fa-chrome', 'edge' => 'fa-edge', 'brave' => '', 'opera' => 'fa-opera', 'vivaldi' => '', 'firefox' => 'fa-firefox'];
}

/** Navegadores: nome, grupo e opções (a chave final é "<navegador>_<opção>"). */
function arc_clean_browsers(): array
{
    return ['brave' => 'Brave', 'edge' => 'Edge Chromium', 'chrome' => 'Google Chrome', 'opera' => 'Opera', 'vivaldi' => 'Vivaldi', 'firefox' => 'Mozilla Firefox'];
}

function arc_clean_browser_facets(): array
{
    return [
        'cache'     => ['Cache da internet', 'Páginas e imagens temporárias.', true, false],
        'history'   => ['Histórico de navegação e downloads', 'Endereços visitados, downloads e miniaturas de sites.', false, false],
        'cookies'   => ['Cookies', 'Remove também os logins mantidos por cookie.', false, false],
        'session'   => ['Sessão', 'Abas abertas e restauração da última sessão.', false, false],
        'forms'     => ['Dados de formulários salvos', 'Preenchimento automático (inclui cartões salvos no Chromium).', false, true],
        'passwords' => ['Senhas salvas', 'Remove as senhas armazenadas no navegador. Não há como desfazer.', false, true],
    ];
}

/** Categorias de limpeza: chave => tab, group, label, hint, default, danger. A chave é o contrato com o gestor. */
function arc_clean_catalog(): array
{
    $c = [];
    $add = static function (string $key, string $tab, string $group, string $label, string $hint, bool $default = false, bool $danger = false) use (&$c): void {
        $c[$key] = ['tab' => $tab, 'group' => $group, 'label' => $label, 'hint' => $hint, 'default' => $default, 'danger' => $danger];
    };
    $t = 'junk';
    $g = 'Windows';
    $add('recent_docs',   $t, $g, 'Documentos recentes', 'Atalhos da lista de arquivos recentes do Explorer.', true);
    $add('run_history',   $t, $g, 'Histórico do Executar', 'Comandos digitados em Win+R.', true);
    $add('search_history',$t, $g, 'Histórico de pesquisa e caminhos digitados', 'Pesquisas e endereços digitados no Explorer.', true);
    $add('thumbnails',    $t, $g, 'Cache de miniaturas e ícones', 'Recriado automaticamente pelo Explorer.', true);
    $add('jump_lists',    $t, $g, 'Listas de atalhos da barra de tarefas', 'Itens recentes dos aplicativos fixados.', true);
    $g = 'Sistema';
    $add('recycle_bin',   $t, $g, 'Lixeira', 'Itens excluídos há mais tempo que o prazo escolhido.', false, true);
    $add('user_temp',     $t, $g, 'Arquivos temporários dos usuários', 'Pasta Temp de cada perfil do Windows.', true);
    $add('windows_temp',  $t, $g, 'Arquivos temporários do Windows', 'C:\\Windows\\Temp.', true);
    $add('clipboard',     $t, $g, 'Área de transferência', 'Conteúdo copiado atualmente.', true);
    $add('memory_dumps',  $t, $g, 'Despejos de memória', 'MEMORY.DMP e relatórios do kernel.');
    $add('chkdsk_fragments', $t, $g, 'Fragmentos do Chkdsk', 'Arquivos .chk em pastas found.nnn.', true);
    $add('system_logs',   $t, $g, 'Arquivos de log do Windows', 'CBS, DISM, Panther e Windows Update (*.log, *.cab, *.etl).');
    $add('error_reports', $t, $g, 'Relatórios de erro e despejos', 'WER, CrashDumps e Minidump.');
    $g = 'Programas';
    $add('office_recent', $t, $g, 'Documentos recentes do Office', 'Atalhos de arquivos usados no Word, Excel e PowerPoint.');
    $add('office_cache',  $t, $g, 'Cache de arquivos do Office', 'OfficeFileCache e telemetria local.');
    $add('teams_cache',   $t, $g, 'Microsoft Teams: cache', 'Cache e arquivos temporários do Teams.');
    $add('discord_cache', $t, $g, 'Discord: cache', 'Cache e GPUCache.');
    $add('vscode_cache',  $t, $g, 'Visual Studio Code: cache e logs', 'Cache, CachedData e logs.');
    $add('java_cache',    $t, $g, 'Java: cache', 'Cache do Java Deployment.');
    $g = 'Windows Store';
    $add('store_cache',   $t, $g, 'Cache da Microsoft Store', 'Arquivos temporários da loja de aplicativos.', true);
    $g = 'Utilitários';
    $add('mspaint_recent',$t, $g, 'Paint e WordPad: arquivos recentes', 'Listas de arquivos recentes.');
    $add('winrar_history',$t, $g, 'WinRAR: histórico', 'Arquivos compactados recentes.');
    $g = 'Multimídia';
    $add('spotify_cache', $t, $g, 'Spotify: armazenamento em cache', 'Músicas e dados em cache.');
    $add('adobe_cache',   $t, $g, 'Adobe: cache de mídia', 'Media Cache e Peak Files (Premiere, After Effects).');
    $add('gpu_shader_cache', $t, $g, 'Cache de shaders de vídeo', 'DirectX, NVIDIA e AMD; recriado pelos jogos.');
    $g = 'Internet';
    $add('dns_cache',     $t, $g, 'Cache de DNS', 'Executa ipconfig /flushdns.');
    $g = 'Downloads para Windows';
    $add('windows_update','junk', $g, 'Download do Windows Update', 'SoftwareDistribution\\Download (pacotes já baixados).', true);
    $add('delivery_optimization', $t, $g, 'Otimização de Entrega', 'Cache de atualizações compartilhadas.', true);
    $g = 'Ferramentas de IA';
    $add('ai_cache',      $t, $g, 'Aplicativos de IA: cache', 'Somente caches do Claude, Cursor, Windsurf, Perplexity e ChatGPT para desktop; conversas e contas não são tocadas.');
    $g = 'Avançado';
    $add('prefetch',      $t, $g, 'Dados antigos do Prefetch', 'Arquivos .pf; o Windows os recria.');
    $add('user_assist',   $t, $g, 'Histórico UserAssist', 'Contadores de programas executados.');
    $add('event_logs',    $t, $g, 'Logs de eventos do Windows', 'Limpa todos os logs, exceto Security (auditoria).', false, true);
    $add('custom_folders',$t, $g, 'Pastas personalizadas', 'Pastas definidas em Configurações. Exclusões se aplicam.', false, true);
    foreach (arc_clean_browsers() as $b => $name) {
        foreach (arc_clean_browser_facets() as $f => [$label, $hint, $default, $danger]) {
            $add($b . '_' . $f, 'browser', $name, $label, $hint, $default, $danger);
        }
    }
    return $c;
}

/** Grupos da Verificação de integridade (somente chaves do catálogo). */
function arc_clean_health_sets(): array
{
    $space = ['user_temp', 'windows_temp', 'thumbnails', 'system_logs', 'error_reports', 'windows_update', 'gpu_shader_cache', 'chkdsk_fragments'];
    $privacy = ['recent_docs', 'jump_lists', 'run_history', 'search_history', 'clipboard', 'firefox_cookies', 'firefox_history'];
    foreach (['chrome', 'edge', 'brave', 'opera', 'vivaldi'] as $b) array_push($privacy, $b . '_cookies', $b . '_history');
    return ['space' => $space, 'privacy' => $privacy];
}

/** Verificações do limpador de registro (chaves = contrato com o gestor). */
function arc_clean_registry_checks(): array
{
    return [
        'shared_dlls'       => ['DLLs compartilhadas ausentes', 'Entradas SharedDLLs que apontam para arquivos inexistentes.'],
        'app_paths'         => ['Caminhos de aplicativos', 'App Paths de programas removidos.'],
        'startup_missing'   => ['Inicialização com arquivo ausente', 'Itens de Run cujo programa não existe mais.'],
        'fonts'             => ['Fontes ausentes', 'Fontes registradas sem o arquivo.'],
        'help_files'        => ['Arquivos de ajuda', 'Arquivos .chm registrados que não existem.'],
        'uninstall_orphans' => ['Software obsoleto', 'Entradas de desinstalação sem o programa e sem pasta de instalação.'],
        'com_activex'       => ['Componentes COM/ActiveX', 'Classes cujo servidor (DLL/EXE) não existe.'],
        'mui_cache'         => ['Cache MUI', 'Nomes de aplicativos de executáveis removidos.'],
    ];
}

/** Ferramentas servidas por clean_tool e suas ações. */
function arc_clean_tools(): array
{
    return ['general'=>['scan','fix'],'registry' => ['scan', 'fix', 'restore'], 'startup' => ['list', 'apply'], 'disk' => ['scan'], 'duplicates' => ['scan', 'delete'],
            'restore' => ['list', 'create'], 'updater' => ['list', 'apply'], 'health' => ['scan'],
            'performance' => ['list', 'suspend', 'resume'], 'drivers' => ['scan', 'install'], 'cloud' => ['scan', 'delete']];
}

/** Valida categorias e prazo vindos da tela. Lança InvalidArgumentException com mensagem amigável. */
function arc_clean_validate_request(mixed $categories, mixed $age): array
{
    $catalog = arc_clean_catalog();
    if (!is_array($categories) || count($categories) < 1 || count($categories) > count($catalog) || count(array_unique($categories, SORT_REGULAR)) !== count($categories)) {
        throw new InvalidArgumentException('Seleção de categorias inválida');
    }
    foreach ($categories as $category) {
        if (!is_string($category) || !isset($catalog[$category])) throw new InvalidArgumentException('Categoria não permitida');
    }
    $age = filter_var($age ?? 7, FILTER_VALIDATE_INT);
    if ($age === false || !in_array($age, arc_clean_age_options(), true)) throw new InvalidArgumentException('Prazo de limpeza inválido');
    return [array_values($categories), (int)$age];
}

/** Caminho absoluto do Windows aceito em exclusões e pastas personalizadas (sem curingas, exceto * nas exclusões). */
function arc_clean_valid_path(mixed $value, bool $wildcard): bool
{
    return is_string($value) && strlen($value) >= 4 && strlen($value) <= 240
        && preg_match($wildcard ? '#^[A-Za-z]:\\\\[^<>"|?\x00-\x1f]+$#D' : '#^[A-Za-z]:\\\\[^<>"|?*\x00-\x1f]+$#D', $value) === 1
        && !str_contains($value, '..');
}

/** Configurações por máquina: exclusões, pastas personalizadas, aplicativos excluídos da suspensão e drivers ignorados.
    Lança InvalidArgumentException. */
function arc_clean_default_settings(): array
{
    return ['exclusions' => [], 'custom_folders' => [], 'perf_excluded' => [], 'drivers_ignored' => []];
}

function arc_clean_validate_settings(mixed $body): array
{
    $out = arc_clean_default_settings();
    foreach (['exclusions' => [ARC_CLEAN_MAX_EXCLUSIONS, true], 'custom_folders' => [ARC_CLEAN_MAX_CUSTOM_FOLDERS, false]] as $field => [$max, $wildcard]) {
        $list = is_array($body) ? ($body[$field] ?? []) : [];
        if (!is_array($list) || count($list) > $max) throw new InvalidArgumentException('Lista de caminhos inválida (máximo ' . $max . ')');
        foreach ($list as $path) {
            $path = is_string($path) ? trim($path) : '';
            if ($path === '') continue;
            if (!arc_clean_valid_path($path, $wildcard)) throw new InvalidArgumentException('Caminho inválido: ' . mb_substr($path, 0, 60));
            $out[$field][] = $path;
        }
        $out[$field] = array_values(array_unique($out[$field]));
    }
    foreach (['perf_excluded', 'drivers_ignored'] as $field) {
        $list = is_array($body) ? ($body[$field] ?? []) : [];
        if (!is_array($list) || count($list) > 200) throw new InvalidArgumentException('Lista de itens inválida');
        foreach ($list as $id) {
            if (!is_string($id) || !preg_match('/^[a-f0-9]{16}$/D', $id)) throw new InvalidArgumentException('Identificador inválido');
            $out[$field][] = $id;
        }
        $out[$field] = array_values(array_unique($out[$field]));
    }
    if(is_array($body)&&array_key_exists('general_preferences',$body))$out['general_preferences']=arc_general_preferences($body['general_preferences']);
    return $out;
}

function arc_clean_settings_get(PDO $pdo, int $gestorId, int $networkId, int $accountId): array
{
    try {
        $q = $pdo->prepare('SELECT settings_json FROM ' . arc_table('clean_settings') . ' WHERE gestor_id=? AND network_id=? AND account_id=?');
        $q->execute([$gestorId, $networkId, $accountId]);
        $data = json_decode((string)$q->fetchColumn(), true);
    } catch (Throwable $e) {
        $data = null;
    }
    try {
        return arc_clean_validate_settings(is_array($data) ? $data : []);
    } catch (InvalidArgumentException $e) {
        return arc_clean_default_settings();
    }
}

function arc_clean_settings_save(PDO $pdo, int $gestorId, int $networkId, int $accountId, array $settings): void
{
    if(!array_key_exists('general_preferences',$settings)){$previous=arc_clean_settings_get($pdo,$gestorId,$networkId,$accountId);if(isset($previous['general_preferences']))$settings['general_preferences']=$previous['general_preferences'];}
    $pdo->prepare('INSERT INTO ' . arc_table('clean_settings') . ' (gestor_id,network_id,account_id,settings_json) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE network_id=VALUES(network_id),account_id=VALUES(account_id),settings_json=VALUES(settings_json)')
        ->execute([$gestorId, $networkId, $accountId, json_encode($settings, JSON_UNESCAPED_UNICODE)]);
}

/** Valida um pedido de ferramenta e devolve a carga enviada ao gestor. $last é o último relatório da mesma ferramenta
    (os identificadores aceitos são somente os que o gestor produziu). Lança InvalidArgumentException. */
function arc_clean_validate_tool(array $body, ?array $last): array
{
    $tool = (string)($body['tool'] ?? '');
    $action = (string)($body['action'] ?? '');
    $tools = arc_clean_tools();
    if (!isset($tools[$tool]) || !in_array($action, $tools[$tool], true)) throw new InvalidArgumentException('Operação inválida');
    if($tool==='general')return arc_general_request($body,$last);
    $payload = ['tool' => $tool, 'action' => $action];
    $known = static function (string $field, array $rows): array {
        $ids = [];
        foreach ($rows as $r) if (is_array($r) && isset($r[$field]) && is_string($r[$field])) $ids[$r[$field]] = true;
        return $ids;
    };
    $ids = static function (mixed $value, array $allowed, int $max): array {
        if (!is_array($value) || count($value) < 1 || count($value) > $max || count(array_unique($value)) !== count($value)) throw new InvalidArgumentException('Seleção inválida');
        foreach ($value as $id) if (!is_string($id) || !isset($allowed[$id])) throw new InvalidArgumentException('Item não encontrado. Execute a análise novamente.');
        return array_values($value);
    };
    switch ($tool) {
        case 'registry':
            $checks = $body['checks'] ?? array_keys(arc_clean_registry_checks());
            if (!is_array($checks) || !$checks || array_diff($checks, array_keys(arc_clean_registry_checks()))) throw new InvalidArgumentException('Verificações inválidas');
            $payload['checks'] = array_values($checks);
            if ($action === 'fix') $payload['ids'] = $ids($body['ids'] ?? null, $known('id', (array)($last['issues'] ?? [])), ARC_CLEAN_MAX_REGISTRY_IDS);
            if ($action === 'restore') {
                $backup = (string)($body['backup'] ?? '');
                if (!preg_match('/^\d{8}-\d{6}$/D', $backup) || !in_array($backup, (array)($last['backups'] ?? []), true)) throw new InvalidArgumentException('Backup inexistente');
                $payload['backup'] = $backup;
            }
            break;
        case 'startup':
            if ($action === 'apply') {
                $ops = $body['operations'] ?? null;
                if (!is_array($ops) || count($ops) < 1 || count($ops) > 100) throw new InvalidArgumentException('Operações inválidas');
                $allowed = $known('id', (array)($last['items'] ?? []));
                $payload['operations'] = [];
                foreach ($ops as $op) {
                    if (!is_array($op) || !isset($allowed[(string)($op['id'] ?? '')]) || !in_array($op['op'] ?? '', ['enable', 'disable', 'delete'], true)) throw new InvalidArgumentException('Item de inicialização não encontrado. Atualize a lista.');
                    $payload['operations'][] = ['id' => (string)$op['id'], 'op' => (string)$op['op']];
                }
            }
            break;
        case 'disk':
            $drive = strtoupper((string)($body['drive'] ?? 'C:'));
            if (!preg_match('/^[A-Z]:$/D', $drive)) throw new InvalidArgumentException('Unidade inválida');
            $payload['drive'] = $drive;
            break;
        case 'duplicates':
            if ($action === 'scan') {
                $roots = $body['roots'] ?? ['users'];
                if (!is_array($roots) || count($roots) < 1 || count($roots) > 6) throw new InvalidArgumentException('Origens inválidas');
                foreach ($roots as $r) if (!is_string($r) || !preg_match('/^(users|[A-Za-z]:)$/D', $r)) throw new InvalidArgumentException('Origem inválida');
                $min = filter_var($body['min_size_kb'] ?? 1024, FILTER_VALIDATE_INT);
                if ($min === false || $min < 100 || $min > 1048576) throw new InvalidArgumentException('Tamanho mínimo inválido');
                $payload['roots'] = array_values(array_unique(array_map(static fn($r) => $r === 'users' ? 'users' : strtoupper($r), $roots)));
                $payload['min_size_kb'] = $min;
            } else {
                $files = [];
                foreach ((array)($last['groups'] ?? []) as $g) foreach ((array)($g['files'] ?? []) as $f) if (isset($f['id'])) $files[(string)$f['id']] = true;
                $payload['ids'] = $ids($body['ids'] ?? null, $files, 500);
                $payload['permanent'] = !empty($body['permanent']);
            }
            break;
        case 'updater':
            if ($action === 'apply') $payload['ids'] = $ids($body['ids'] ?? null, $known('id', (array)($last['items'] ?? [])), 30);
            break;
        case 'health':
            $payload += arc_clean_health_sets();
            break;
        case 'performance':
            if ($action !== 'list') $payload['ids'] = $ids($body['ids'] ?? null, $known('id', array_merge((array)($last['active'] ?? []), (array)($last['suspended'] ?? []))), 40);
            break;
        case 'drivers':
            if ($action === 'install') $payload['ids'] = $ids($body['ids'] ?? null, $known('id', (array)($last['outdated'] ?? [])), 20);
            break;
        case 'cloud':
            if ($action === 'delete') $payload['ids'] = $ids($body['ids'] ?? null, $known('id', (array)($last['items'] ?? [])), 300);
            break;
    }
    return $payload;
}

/** Inteiros não negativos e textos curtos: o relatório do gestor nunca é guardado como veio. */
function arc_clean_text(mixed $v, int $max): string
{
    return mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', ' ', (string)$v) ?? '', 0, $max);
}

function arc_clean_count(mixed $v): int
{
    return max(0, (int)$v);
}

/** Mantém somente campos conhecidos e numéricos do relatório da Limpeza personalizada.
    $execute vem do nome do comando solicitado, nunca do corpo enviado pelo gestor. */
function arc_clean_sanitize_report(array $report, bool $execute): array
{
    $age = (int)($report['age_days'] ?? 7);
    $clean = [
        'file_count' => 0, 'bytes' => 0, 'errors' => 0, 'truncated' => false,
        'execute'    => $execute,
        'age_days'   => in_array($age, arc_clean_age_options(), true) ? $age : 7,
        'categories' => [],
    ];
    foreach (array_keys(arc_clean_catalog()) as $key) {
        $r = $report['categories'][$key] ?? null;
        if (!is_array($r)) continue;
        $item = [
            'file_count' => arc_clean_count($r['file_count'] ?? 0),
            'bytes'      => arc_clean_count($r['bytes'] ?? 0),
            'errors'     => arc_clean_count($r['errors'] ?? 0),
            'truncated'  => !empty($r['truncated']),
            'unit'       => !empty($r['action']) ? 'action' : (!empty($r['records']) || !empty($r['registry']) ? 'records' : 'files'),
        ];
        $clean['categories'][$key] = $item;
        $clean['file_count'] += $item['file_count'];
        $clean['bytes']      += $item['bytes'];
        $clean['errors']     += $item['errors'];
        $clean['truncated'] = $clean['truncated'] || $item['truncated'];
    }
    $disk = $report['disk'] ?? null;
    if (is_array($disk) && isset($disk['total'], $disk['free'])) {
        $clean['disk'] = ['total' => arc_clean_count($disk['total']), 'free' => arc_clean_count($disk['free']), 'drive' => mb_substr((string)preg_replace('/[^A-Za-z:]/', '', (string)($disk['drive'] ?? 'C:')), 0, 3)];
    }
    return $clean;
}

/** Higieniza o relatório de uma ferramenta. Retorna [tipo, relatório]. Lança InvalidArgumentException. */
function arc_clean_sanitize_tool_report(string $tool, array $r): array
{
    if (!isset(arc_clean_tools()[$tool])) throw new InvalidArgumentException('Ferramenta desconhecida');
    if($tool==='general')return ['general',arc_general_sanitize_report($r)];
    $rows = static fn(mixed $v, int $max): array => is_array($v) ? array_slice(array_values(array_filter($v, 'is_array')), 0, $max) : [];
    $out = ['tool' => $tool];
    switch ($tool) {
        case 'registry':
            $checks = arc_clean_registry_checks();
            $out['issues'] = [];
            foreach ($rows($r['issues'] ?? [], ARC_CLEAN_MAX_REGISTRY_IDS) as $i) {
                if (!preg_match('/^[a-f0-9]{16}$/D', (string)($i['id'] ?? '')) || !isset($checks[$i['check'] ?? ''])) continue;
                $out['issues'][] = ['id' => (string)$i['id'], 'check' => (string)$i['check'], 'hive' => ($i['hive'] ?? '') === 'HKCU' ? 'HKCU' : 'HKLM',
                                    'key' => arc_clean_text($i['key'] ?? '', 400), 'value' => isset($i['value']) ? arc_clean_text($i['value'], 260) : null, 'detail' => arc_clean_text($i['detail'] ?? '', 300)];
            }
            $out['counts'] = [];
            foreach ($checks as $k => $_) if (isset($r['counts'][$k])) $out['counts'][$k] = arc_clean_count($r['counts'][$k]);
            $out['backups'] = array_values(array_filter(array_map('strval', (array)($r['backups'] ?? [])), static fn($b) => preg_match('/^\d{8}-\d{6}$/D', $b) === 1));
            foreach (['fixed', 'failed', 'restored', 'total'] as $n) if (isset($r[$n])) $out[$n] = arc_clean_count($r[$n]);
            if (!empty($r['backup']) && preg_match('/^\d{8}-\d{6}$/D', (string)$r['backup'])) $out['backup'] = (string)$r['backup'];
            break;
        case 'startup':
            $out['items'] = [];
            foreach ($rows($r['items'] ?? [], 700) as $i) {
                if (!preg_match('/^[a-f0-9]{16}$/D', (string)($i['id'] ?? ''))) continue;
                $out['items'][] = ['id' => (string)$i['id'], 'kind' => in_array($i['kind'] ?? '', ['run', 'folder', 'task', 'service', 'context'], true) ? (string)$i['kind'] : 'run', 'name' => arc_clean_text($i['name'] ?? '', 120),
                                   'detail' => arc_clean_text($i['detail'] ?? '', 120), 'publisher' => arc_clean_text($i['publisher'] ?? '', 80),
                                   'command' => arc_clean_text($i['command'] ?? '', 260), 'source' => arc_clean_text($i['source'] ?? '', 60), 'enabled' => !empty($i['enabled'])];
            }
            foreach (['done', 'failed'] as $n) if (isset($r[$n])) $out[$n] = arc_clean_count($r[$n]);
            break;
        case 'disk':
            $out += ['drive' => mb_substr((string)preg_replace('/[^A-Za-z:]/', '', (string)($r['drive'] ?? 'C:')), 0, 2), 'files' => arc_clean_count($r['files'] ?? 0), 'bytes' => arc_clean_count($r['bytes'] ?? 0),
                     'truncated' => !empty($r['truncated']), 'disk_total' => arc_clean_count($r['disk_total'] ?? 0), 'disk_free' => arc_clean_count($r['disk_free'] ?? 0)];
            $out['types'] = array_map(static fn($t) => ['name' => arc_clean_text($t['name'] ?? '', 40), 'count' => arc_clean_count($t['count'] ?? 0), 'bytes' => arc_clean_count($t['bytes'] ?? 0)], $rows($r['types'] ?? [], 12));
            $out['folders'] = array_map(static fn($t) => ['path' => arc_clean_text($t['path'] ?? '', 300), 'bytes' => arc_clean_count($t['bytes'] ?? 0)], $rows($r['folders'] ?? [], 80));
            $out['largest'] = array_map(static fn($t) => ['path' => arc_clean_text($t['path'] ?? '', 400), 'bytes' => arc_clean_count($t['bytes'] ?? 0)], $rows($r['largest'] ?? [], 50));
            break;
        case 'duplicates':
            $out['groups'] = [];
            foreach ($rows($r['groups'] ?? [], 300) as $g) {
                $files = [];
                foreach ($rows($g['files'] ?? [], 20) as $f) {
                    if (preg_match('/^[a-f0-9]{16}$/D', (string)($f['id'] ?? ''))) $files[] = ['id' => (string)$f['id'], 'path' => arc_clean_text($f['path'] ?? '', 500), 'modified' => arc_clean_count($f['modified'] ?? 0)];
                }
                if (count($files) > 1) $out['groups'][] = ['size' => arc_clean_count($g['size'] ?? 0), 'files' => $files];
            }
            $out += ['scanned' => arc_clean_count($r['scanned'] ?? 0), 'wasted' => arc_clean_count($r['wasted'] ?? 0), 'truncated' => !empty($r['truncated'])];
            foreach (['removed', 'freed', 'failed'] as $n) if (isset($r[$n])) $out[$n] = arc_clean_count($r[$n]);
            break;
        case 'restore':
            $out['complete'] = !isset($r['complete']) || !empty($r['complete']);
            $out['points'] = array_map(static fn($p) => ['sequence' => arc_clean_count($p['sequence'] ?? 0), 'description' => arc_clean_text($p['description'] ?? '', 120), 'created' => preg_replace('/\D/', '', (string)($p['created'] ?? '')) ?: '', 'type' => arc_clean_count($p['type'] ?? 0)], $rows($r['points'] ?? [], 30));
            if (isset($r['created'])) $out['created'] = !empty($r['created']);
            if (!empty($r['message'])) $out['message'] = arc_clean_text($r['message'], 300);
            break;
        case 'updater':
            $out['items'] = [];
            foreach ($rows($r['items'] ?? [], 200) as $i) {
                if (preg_match('/^[A-Za-z0-9][A-Za-z0-9.+_-]{1,120}$/D', (string)($i['id'] ?? ''))) $out['items'][] = ['id' => (string)$i['id'], 'name' => arc_clean_text($i['name'] ?? '', 120), 'version' => arc_clean_text($i['version'] ?? '', 40), 'available' => arc_clean_text($i['available'] ?? '', 40)];
            }
            $out['results'] = array_map(static fn($x) => ['id' => arc_clean_text($x['id'] ?? '', 120), 'ok' => !empty($x['ok']), 'message' => arc_clean_text($x['message'] ?? '', 120)], $rows($r['results'] ?? [], 30));
            break;
        case 'performance':
            foreach (['active', 'suspended'] as $list) {
                $out[$list] = [];
                foreach ($rows($r[$list] ?? [], 150) as $i) {
                    if (!preg_match('/^[a-f0-9]{16}$/D', (string)($i['id'] ?? ''))) continue;
                    $out[$list][] = ['id' => (string)$i['id'], 'name' => arc_clean_text($i['name'] ?? '', 80), 'publisher' => arc_clean_text($i['publisher'] ?? '', 60),
                                     'memory' => arc_clean_count($i['memory'] ?? 0), 'impact' => max(1, min(3, (int)($i['impact'] ?? 1))), 'count' => max(1, (int)($i['count'] ?? 1))];
                }
            }
            foreach (['done', 'failed'] as $n) if (isset($r[$n])) $out[$n] = arc_clean_count($r[$n]);
            break;
        case 'drivers':
            $driverRow = static function (array $d): array {
                return ['id' => (string)$d['id'], 'name' => arc_clean_text($d['name'] ?? '', 100), 'group' => arc_clean_text($d['group'] ?? 'Outros', 40), 'version' => arc_clean_text($d['version'] ?? '', 40),
                        'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string)($d['date'] ?? '')) ? (string)$d['date'] : '', 'manufacturer' => arc_clean_text($d['manufacturer'] ?? '', 60)];
            };
            foreach (['outdated' => 100, 'current' => 400] as $list => $max) {
                $out[$list] = [];
                foreach ($rows($r[$list] ?? [], $max) as $d) if (preg_match('/^[a-f0-9]{16}$/D', (string)($d['id'] ?? ''))) $out[$list][] = $driverRow($d) + ($list === 'outdated' ? ['size' => arc_clean_count($d['size'] ?? 0)] : []);
            }
            $out['results'] = array_map(static fn($x) => ['id' => arc_clean_text($x['id'] ?? '', 20), 'ok' => !empty($x['ok']), 'message' => arc_clean_text($x['message'] ?? '', 120)], $rows($r['results'] ?? [], 40));
            $out['reboot'] = !empty($r['reboot']);
            $out['complete'] = !isset($r['complete']) || !empty($r['complete']);
            break;
        case 'cloud':
            $providerKeys = ['onedrive', 'gdrive', 'dropbox', 'icloud', 'box'];
            $out['providers'] = array_map(static fn($p) => ['key' => in_array($p['key'] ?? '', $providerKeys, true) ? (string)$p['key'] : 'box', 'name' => arc_clean_text($p['name'] ?? '', 80), 'root' => arc_clean_text($p['root'] ?? '', 200),
                                                             'files' => arc_clean_count($p['files'] ?? 0), 'bytes' => arc_clean_count($p['bytes'] ?? 0)], $rows($r['providers'] ?? [], 12));
            $out['cats'] = [];
            foreach (['junk', 'duplicate', 'large', 'old'] as $cat) $out['cats'][$cat] = ['count' => arc_clean_count($r['cats'][$cat]['count'] ?? 0), 'bytes' => arc_clean_count($r['cats'][$cat]['bytes'] ?? 0)];
            $out['items'] = [];
            foreach ($rows($r['items'] ?? [], 480) as $i) {
                if (!preg_match('/^[a-f0-9]{16}$/D', (string)($i['id'] ?? '')) || !isset($out['cats'][$i['cat'] ?? ''])) continue;
                $out['items'][] = ['id' => (string)$i['id'], 'cat' => (string)$i['cat'], 'provider' => in_array($i['provider'] ?? '', $providerKeys, true) ? (string)$i['provider'] : 'box',
                                   'path' => arc_clean_text($i['path'] ?? '', 400), 'bytes' => arc_clean_count($i['bytes'] ?? 0), 'modified' => arc_clean_count($i['modified'] ?? 0), 'local' => !empty($i['local'])];
            }
            $out['truncated'] = !empty($r['truncated']);
            foreach (['removed', 'freed', 'failed'] as $n) if (isset($r[$n])) $out[$n] = arc_clean_count($r[$n]);
            break;
        case 'health':
            $out['incomplete'] = !empty($r['incomplete']);
            $out['missing'] = array_values(array_slice(array_filter((array)($r['missing'] ?? []), 'is_string'), 0, 60));
            $out['note'] = arc_clean_text($r['note'] ?? '', 300);
            foreach (['space', 'privacy'] as $part) {
                $san = arc_clean_sanitize_report(is_array($r[$part] ?? null) ? $r[$part] : [], false);
                $out[$part] = ['file_count' => $san['file_count'], 'bytes' => $san['bytes'], 'errors' => $san['errors'], 'categories' => $san['categories']];
            }
            $out['speed'] = ['startup_total' => arc_clean_count($r['speed']['startup_total'] ?? 0), 'startup_enabled' => arc_clean_count($r['speed']['startup_enabled'] ?? 0)];
            $out['registry'] = ['issues'=>arc_clean_count($r['registry']['issues']??0),'complete'=>!isset($r['registry']['complete'])||!empty($r['registry']['complete']),'message'=>arc_clean_text($r['registry']['message']??'',250)];
            $out['sync_errors']=array_slice(array_map(static fn($text)=>arc_clean_text($text,300),array_filter((array)($r['sync_errors']??[]),'is_string')),0,4);
            $out['optimization'] = !empty($r['optimization']);
            $out['removed'] = arc_clean_count($r['removed']??0); $out['freed_bytes'] = arc_clean_count($r['freed_bytes']??0); $out['errors'] = arc_clean_count($r['errors']??0);
            $out['reboot'] = !empty($r['reboot']);$out['created'] = !isset($r['created'])||!empty($r['created']);$out['captured_at'] = arc_clean_count($r['captured_at']??0);
            $out['message'] = arc_clean_text($r['message']??'',500);
            $out['steps'] = array_values(array_intersect((array)($r['steps']??[]),['space','privacy']));
            $out['free_space_delta'] = isset($r['free_space_delta'])&&is_numeric($r['free_space_delta'])?(int)$r['free_space_delta']:null;
            break;
    }
    return [$tool, $out];
}

/** Último relatório de uma ferramenta/limpeza da máquina (tipo "clean" para a Limpeza personalizada). */
function arc_clean_report_get(PDO $pdo, int $gestorId, int $networkId, int $accountId, string $kind): ?array
{
    $q = $pdo->prepare('SELECT report_json,collected_at FROM ' . arc_table('gestor_clean_inventory') . ' WHERE gestor_id=? AND network_id=? AND account_id=? AND kind=?');
    $q->execute([$gestorId, $networkId, $accountId, $kind]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $report = json_decode((string)$row['report_json'], true);
    return is_array($report) ? ['report' => $report, 'collected_at' => $row['collected_at']] : null;
}

function arc_clean_report_save(PDO $pdo, int $gestorId, int $networkId, int $accountId, string $kind, array $report): void
{
    $pdo->prepare('INSERT INTO ' . arc_table('gestor_clean_inventory') . ' (gestor_id,kind,network_id,account_id,report_json) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE network_id=VALUES(network_id),account_id=VALUES(account_id),report_json=VALUES(report_json),collected_at=CURRENT_TIMESTAMP')
        ->execute([$gestorId, $kind, $networkId, $accountId, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);
}

/** Datas da última execução de cada ferramenta (a tela só baixa o relatório da ferramenta aberta). */
function arc_clean_report_index(PDO $pdo, int $gestorId, int $networkId, int $accountId): array
{
    $q = $pdo->prepare('SELECT kind,collected_at FROM ' . arc_table('gestor_clean_inventory') . ' WHERE gestor_id=? AND network_id=? AND account_id=?');
    $q->execute([$gestorId, $networkId, $accountId]);
    return $q->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Guarda a linha do histórico e preserva apenas as 30 mais recentes por máquina.
    O histórico é auxiliar: se a tabela ainda não existir, a limpeza continua válida. */
function arc_clean_record_history(PDO $pdo, int $gestorId, int $networkId, int $accountId, array $report): void
{
    try {
        $table = arc_table('clean_history');
        $pdo->prepare('INSERT INTO ' . $table . ' (gestor_id,network_id,account_id,mode,age_days,file_count,bytes,errors,categories) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$gestorId, $networkId, $accountId, $report['execute'] ? 'execute' : 'scan', $report['age_days'], $report['file_count'], $report['bytes'], $report['errors'], mb_substr(implode(',', array_keys($report['categories'])), 0, 255)]);
        $pdo->prepare('DELETE FROM ' . $table . ' WHERE gestor_id=? AND id NOT IN (SELECT id FROM (SELECT id FROM ' . $table . ' WHERE gestor_id=? ORDER BY id DESC LIMIT 30) keep)')
            ->execute([$gestorId, $gestorId]);
    } catch (Throwable $e) {
        arc_log_error('Arcoll clean history: ' . $e->getMessage());
    }
}

/** Últimas execuções da máquina, mais recentes primeiro. Lista vazia se a tabela ainda não existir. */
function arc_clean_history(PDO $pdo, int $gestorId, int $networkId, int $accountId, int $limit = 8): array
{
    try {
        $q = $pdo->prepare('SELECT mode,age_days,file_count,bytes,errors,categories,created_at FROM ' . arc_table('clean_history') . ' WHERE gestor_id=? AND network_id=? AND account_id=? ORDER BY id DESC LIMIT ' . max(1, min(30, $limit)));
        $q->execute([$gestorId, $networkId, $accountId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/** Esquema do Clean (idempotente, sem apagar dados): relatórios por ferramenta, histórico e configurações.
    A tabela de relatórios ganha a coluna "kind" mantendo os relatórios existentes como tipo "clean". */
function arc_clean_ensure_schema(PDO $pdo): void
{
    $engine = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . arc_table('gestor_clean_inventory') . ' (
        gestor_id BIGINT UNSIGNED NOT NULL,
        kind VARCHAR(24) NOT NULL DEFAULT \'clean\',
        network_id BIGINT UNSIGNED NOT NULL,
        account_id BIGINT UNSIGNED NOT NULL,
        report_json MEDIUMTEXT NOT NULL,
        collected_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (gestor_id,kind),
        KEY idx_clean_network (network_id,account_id)
    )' . $engine);
    $has = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=\'kind\'');
    $has->execute([arc_prefix() . 'gestor_clean_inventory']);
    if ((int)$has->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE ' . arc_table('gestor_clean_inventory') . ' ADD COLUMN kind VARCHAR(24) NOT NULL DEFAULT \'clean\' AFTER gestor_id, DROP PRIMARY KEY, ADD PRIMARY KEY (gestor_id,kind)');
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . arc_table('clean_history') . ' (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        gestor_id BIGINT UNSIGNED NOT NULL,
        network_id BIGINT UNSIGNED NOT NULL,
        account_id BIGINT UNSIGNED NOT NULL,
        mode ENUM(\'scan\',\'execute\') NOT NULL DEFAULT \'scan\',
        age_days SMALLINT UNSIGNED NOT NULL DEFAULT 7,
        file_count INT UNSIGNED NOT NULL DEFAULT 0,
        bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
        errors INT UNSIGNED NOT NULL DEFAULT 0,
        categories VARCHAR(255) NOT NULL DEFAULT \'\',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_clean_history_gestor (gestor_id,id),
        KEY idx_clean_history_network (network_id,account_id)
    )' . $engine);
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . arc_table('clean_settings') . ' (
        gestor_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        network_id BIGINT UNSIGNED NOT NULL,
        account_id BIGINT UNSIGNED NOT NULL,
        settings_json TEXT NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_clean_settings_network (network_id,account_id)
    )' . $engine);
}


/** Catálogo explícito para instalação remota via WinGet (IDs fixos, sem comandos personalizados). */
/** Domínio do site oficial de cada pacote do catálogo: origem do logo exibido na plataforma (api/program-logo.php) e no gestor. */
function arc_program_logo_domains(): array
{
    return [
        'AnyDesk.AnyDesk'=>'anydesk.com',
        'RustDesk.RustDesk'=>'rustdesk.com',
        'TeamViewer.TeamViewer'=>'teamviewer.com',
        'GlavSoft.TightVNC'=>'tightvnc.com',
        'uvncbvba.UltraVNC'=>'uvnc.com',
        'Dropbox.Dropbox'=>'dropbox.com',
        'Google.GoogleDrive'=>'drive.google.com',
        'Microsoft.OneDrive'=>'onedrive.live.com',
        'RARLab.WinRAR'=>'win-rar.com',
        'qBittorrent.qBittorrent'=>'qbittorrent.org',
        'Microsoft.DotNet.DesktopRuntime.10'=>'dotnet.microsoft.com',
        'Microsoft.DotNet.DesktopRuntime.8'=>'dotnet.microsoft.com',
        'Microsoft.DotNet.DesktopRuntime.9'=>'dotnet.microsoft.com',
        'Microsoft.DotNet.AspNetCore.10'=>'dotnet.microsoft.com',
        'Microsoft.DotNet.AspNetCore.8'=>'dotnet.microsoft.com',
        'Microsoft.DotNet.AspNetCore.9'=>'dotnet.microsoft.com',
        'Microsoft.VCRedist.2015+.x64'=>'visualstudio.microsoft.com',
        'Microsoft.VCRedist.2015+.x86'=>'visualstudio.microsoft.com',
        'Anysphere.Cursor'=>'cursor.com',
        'DBeaver.DBeaver.Community'=>'dbeaver.io',
        'Docker.DockerDesktop'=>'docker.com',
        'EclipseFoundation.EclipseIDEforJavaDevelopers'=>'eclipse.org',
        'TimKosse.FileZilla.Client'=>'filezilla-project.org',
        'Git.Git'=>'git-scm.com',
        'GitHub.GitHubDesktop'=>'desktop.github.com',
        'JetBrains.Toolbox'=>'jetbrains.com',
        'OpenJS.NodeJS.22'=>'nodejs.org',
        'OpenJS.NodeJS.24'=>'nodejs.org',
        'OpenJS.NodeJS.LTS'=>'nodejs.org',
        'Postman.Postman'=>'postman.com',
        'PuTTY.PuTTY'=>'putty.org',
        'Python.Python.3.12'=>'python.org',
        'Python.Python.3.13'=>'python.org',
        'Python.Python.3.14'=>'python.org',
        'SublimeHQ.SublimeText.4'=>'sublimetext.com',
        'Microsoft.VisualStudioCode'=>'code.visualstudio.com',
        'VSCodium.VSCodium'=>'vscodium.com',
        'WinMerge.WinMerge'=>'winmerge.org',
        'WinSCP.WinSCP'=>'winscp.net',
        'Adobe.Acrobat.Reader.64-bit'=>'acrobat.adobe.com',
        'AcroSoftware.CutePDFWriter'=>'cutepdf.com',
        'Foxit.FoxitReader'=>'foxit.com',
        'TheDocumentFoundation.LibreOffice'=>'libreoffice.org',
        'Notepad++.Notepad++'=>'notepad-plus-plus.org',
        'Apache.OpenOffice'=>'openoffice.org',
        'geeksoftwareGmbH.PDF24Creator'=>'pdf24.org',
        'SumatraPDF.SumatraPDF'=>'sumatrapdfreader.org',
        'BlenderFoundation.Blender'=>'blender.org',
        'FastStone.Viewer'=>'faststone.org',
        'GIMP.GIMP'=>'gimp.org',
        'Inkscape.Inkscape'=>'inkscape.org',
        'IrfanSkiljan.IrfanView'=>'irfanview.com',
        'KDE.Krita'=>'krita.org',
        'dotPDN.PaintDotNet'=>'getpaint.net',
        'XnSoft.XnViewMP'=>'xnview.com',
        'BambuLab.BambuStudio'=>'bambulab.com',
        'Ultimaker.Cura'=>'ultimaker.com',
        'Amazon.Corretto.11.JDK'=>'aws.amazon.com',
        'Amazon.Corretto.17.JDK'=>'aws.amazon.com',
        'Amazon.Corretto.21.JDK'=>'aws.amazon.com',
        'Amazon.Corretto.25.JDK'=>'aws.amazon.com',
        'Amazon.Corretto.8.JDK'=>'aws.amazon.com',
        'EclipseAdoptium.Temurin.11.JDK'=>'adoptium.net',
        'EclipseAdoptium.Temurin.17.JDK'=>'adoptium.net',
        'EclipseAdoptium.Temurin.21.JDK'=>'adoptium.net',
        'EclipseAdoptium.Temurin.25.JDK'=>'adoptium.net',
        'EclipseAdoptium.Temurin.8.JDK'=>'adoptium.net',
        'EclipseAdoptium.Temurin.8.JRE'=>'adoptium.net',
        'EpicGames.EpicGamesLauncher'=>'epicgames.com',
        'Valve.Steam'=>'steampowered.com',
        'Discord.Discord'=>'discord.com',
        'Microsoft.Teams'=>'teams.microsoft.com',
        'Pidgin.Pidgin'=>'pidgin.im',
        'OpenWhisperSystems.Signal'=>'signal.org',
        'SlackTechnologies.Slack'=>'slack.com',
        'Telegram.TelegramDesktop'=>'telegram.org',
        'Mozilla.Thunderbird'=>'thunderbird.net',
        'CeruleanStudios.Trillian'=>'trillian.im',
        'WhatsApp.WhatsApp'=>'whatsapp.com',
        'Zoom.Zoom'=>'zoom.us',
        'AIMP.AIMP'=>'aimp.ru',
        'Audacity.Audacity'=>'audacityteam.org',
        'PeterPawlowski.foobar2000'=>'foobar2000.org',
        'HandBrake.HandBrake'=>'handbrake.fr',
        'Apple.iTunes'=>'apple.com',
        'CodecGuide.K-LiteCodecPack.Full'=>'codecguide.com',
        'VentisMedia.MediaMonkey'=>'mediamonkey.com',
        'MusicBee.MusicBee'=>'getmusicbee.com',
        'OBSProject.OBSStudio'=>'obsproject.com',
        'Spotify.Spotify'=>'spotify.com',
        'VideoLAN.VLC'=>'videolan.org',
        'Winamp.Winamp'=>'winamp.com',
        'Brave.Brave'=>'brave.com',
        'Google.Chrome'=>'chrome.google.com',
        'Microsoft.Edge'=>'microsoftedge.microsoft.com',
        'Mozilla.Firefox'=>'mozilla.org',
        'Opera.Opera'=>'opera.com',
        'Vivaldi.Vivaldi'=>'vivaldi.com',
        'Evernote.Evernote'=>'evernote.com',
        'Google.EarthPro'=>'earth.google.com',
        'Famatech.AdvancedIPScanner'=>'advanced-ip-scanner.com',
        'Malwarebytes.Malwarebytes'=>'malwarebytes.com',
        'OpenVPNTechnologies.OpenVPNConnect'=>'openvpn.net',
        'Tailscale.Tailscale'=>'tailscale.com',
        'WireGuard.WireGuard'=>'wireguard.com',
        'WiresharkFoundation.Wireshark'=>'wireshark.org',
        'AgileBits.1Password'=>'1password.com',
        'Bitwarden.Bitwarden'=>'bitwarden.com',
        'KeePassXCTeam.KeePassXC'=>'keepassxc.org',
        '7zip.7zip'=>'7-zip.org',
        'Piriform.CCleaner'=>'ccleaner.com',
        'voidtools.Everything'=>'voidtools.com',
        'Glarysoft.GlaryUtilities'=>'glarysoft.com',
        'Greenshot.Greenshot'=>'getgreenshot.org',
        'DominikReichl.KeePass'=>'keepass.info',
        'Launchy.Launchy'=>'launchy.net',
        'Open-Shell.Open-Shell-Menu'=>'open-shell.github.io',
        'Giorgiotani.Peazip'=>'peazip.github.io',
        'Microsoft.PowerToys'=>'microsoft.com',
        'RevoUninstaller.RevoUninstaller'=>'revouninstaller.com',
        'Rufus.Rufus'=>'rufus.ie',
        'ShareX.ShareX'=>'getsharex.com',
        'CodeSector.TeraCopy'=>'codesector.com',
        'WinDirStat.WinDirStat'=>'windirstat.net',
        'AntibodySoftware.WizTree'=>'diskanalyzer.com',
    ];
}

/** Nome do arquivo de logo de um pacote: o ID do WinGet com tudo fora de [A-Za-z0-9.-] trocado por "_". */
function arc_program_logo_filename(string $id): string
{
    return preg_replace('/[^A-Za-z0-9.-]/', '_', $id) . '.png';
}

/** Caminho do logo embutido (assets/program-logos/*.png, 128 px, fundo transparente) ou '' se o pacote não tiver arquivo.
    Para trocar ou acrescentar um logo basta gravar um PNG com este nome na pasta; não há outra configuração. */
function arc_program_logo_file(string $id): string
{
    static $cache = [];   // o catálogo é montado várias vezes por requisição: evita repetir consultas ao disco
    if (isset($cache[$id])) return $cache[$id];
    $file = dirname(__DIR__) . '/assets/program-logos/' . arc_program_logo_filename($id);
    return $cache[$id] = (is_file($file) && filesize($file) > 100 ? $file : '');
}

function arc_program_install_catalog(): array
{
    $domains = arc_program_logo_domains();
    $rows = [
        ['name'=>'AnyDesk','id'=>'AnyDesk.AnyDesk','category'=>'Acesso remoto'],
        ['name'=>'RustDesk','id'=>'RustDesk.RustDesk','category'=>'Acesso remoto'],
        ['name'=>'TeamViewer','id'=>'TeamViewer.TeamViewer','category'=>'Acesso remoto'],
        ['name'=>'TightVNC','id'=>'GlavSoft.TightVNC','category'=>'Acesso remoto'],
        ['name'=>'UltraVNC','id'=>'uvncbvba.UltraVNC','category'=>'Acesso remoto'],
        ['name'=>'Dropbox','id'=>'Dropbox.Dropbox','category'=>'Armazenamento'],
        ['name'=>'Google Drive para computador','id'=>'Google.GoogleDrive','category'=>'Armazenamento'],
        ['name'=>'OneDrive','id'=>'Microsoft.OneDrive','category'=>'Armazenamento'],
        ['name'=>'WinRAR','id'=>'RARLab.WinRAR','category'=>'Compactação'],
        ['name'=>'qBittorrent','id'=>'qBittorrent.qBittorrent','category'=>'Compartilhamento'],
        ['name'=>'.NET Desktop Runtime 10','id'=>'Microsoft.DotNet.DesktopRuntime.10','category'=>'Componentes Windows'],
        ['name'=>'.NET Desktop Runtime 8','id'=>'Microsoft.DotNet.DesktopRuntime.8','category'=>'Componentes Windows'],
        ['name'=>'.NET Desktop Runtime 9','id'=>'Microsoft.DotNet.DesktopRuntime.9','category'=>'Componentes Windows'],
        ['name'=>'ASP.NET Core Runtime 10','id'=>'Microsoft.DotNet.AspNetCore.10','category'=>'Componentes Windows'],
        ['name'=>'ASP.NET Core Runtime 8','id'=>'Microsoft.DotNet.AspNetCore.8','category'=>'Componentes Windows'],
        ['name'=>'ASP.NET Core Runtime 9','id'=>'Microsoft.DotNet.AspNetCore.9','category'=>'Componentes Windows'],
        ['name'=>'Visual C++ Redistributable 2015+ x64','id'=>'Microsoft.VCRedist.2015+.x64','category'=>'Componentes Windows'],
        ['name'=>'Visual C++ Redistributable 2015+ x86','id'=>'Microsoft.VCRedist.2015+.x86','category'=>'Componentes Windows'],
        ['name'=>'Cursor','id'=>'Anysphere.Cursor','category'=>'Desenvolvimento'],
        ['name'=>'DBeaver','id'=>'DBeaver.DBeaver.Community','category'=>'Desenvolvimento'],
        ['name'=>'Docker Desktop','id'=>'Docker.DockerDesktop','category'=>'Desenvolvimento'],
        ['name'=>'Eclipse IDE','id'=>'EclipseFoundation.EclipseIDEforJavaDevelopers','category'=>'Desenvolvimento'],
        ['name'=>'FileZilla','id'=>'TimKosse.FileZilla.Client','category'=>'Desenvolvimento'],
        ['name'=>'Git','id'=>'Git.Git','category'=>'Desenvolvimento'],
        ['name'=>'GitHub Desktop','id'=>'GitHub.GitHubDesktop','category'=>'Desenvolvimento'],
        ['name'=>'JetBrains Toolbox','id'=>'JetBrains.Toolbox','category'=>'Desenvolvimento'],
        ['name'=>'Node.js 22','id'=>'OpenJS.NodeJS.22','category'=>'Desenvolvimento'],
        ['name'=>'Node.js 24','id'=>'OpenJS.NodeJS.24','category'=>'Desenvolvimento'],
        ['name'=>'Node.js LTS','id'=>'OpenJS.NodeJS.LTS','category'=>'Desenvolvimento'],
        ['name'=>'Postman','id'=>'Postman.Postman','category'=>'Desenvolvimento'],
        ['name'=>'PuTTY','id'=>'PuTTY.PuTTY','category'=>'Desenvolvimento'],
        ['name'=>'Python 3.12','id'=>'Python.Python.3.12','category'=>'Desenvolvimento'],
        ['name'=>'Python 3.13','id'=>'Python.Python.3.13','category'=>'Desenvolvimento'],
        ['name'=>'Python 3.14','id'=>'Python.Python.3.14','category'=>'Desenvolvimento'],
        ['name'=>'Sublime Text','id'=>'SublimeHQ.SublimeText.4','category'=>'Desenvolvimento'],
        ['name'=>'Visual Studio Code','id'=>'Microsoft.VisualStudioCode','category'=>'Desenvolvimento'],
        ['name'=>'VSCodium','id'=>'VSCodium.VSCodium','category'=>'Desenvolvimento'],
        ['name'=>'WinMerge','id'=>'WinMerge.WinMerge','category'=>'Desenvolvimento'],
        ['name'=>'WinSCP','id'=>'WinSCP.WinSCP','category'=>'Desenvolvimento'],
        ['name'=>'Adobe Acrobat Reader','id'=>'Adobe.Acrobat.Reader.64-bit','category'=>'Documentos'],
        ['name'=>'CutePDF Writer','id'=>'AcroSoftware.CutePDFWriter','category'=>'Documentos'],
        ['name'=>'Foxit PDF Reader','id'=>'Foxit.FoxitReader','category'=>'Documentos'],
        ['name'=>'LibreOffice','id'=>'TheDocumentFoundation.LibreOffice','category'=>'Documentos'],
        ['name'=>'Notepad++','id'=>'Notepad++.Notepad++','category'=>'Documentos'],
        ['name'=>'OpenOffice','id'=>'Apache.OpenOffice','category'=>'Documentos'],
        ['name'=>'PDF24 Creator','id'=>'geeksoftwareGmbH.PDF24Creator','category'=>'Documentos'],
        ['name'=>'SumatraPDF','id'=>'SumatraPDF.SumatraPDF','category'=>'Documentos'],
        ['name'=>'Blender','id'=>'BlenderFoundation.Blender','category'=>'Imagens e design'],
        ['name'=>'FastStone Image Viewer','id'=>'FastStone.Viewer','category'=>'Imagens e design'],
        ['name'=>'GIMP','id'=>'GIMP.GIMP','category'=>'Imagens e design'],
        ['name'=>'Inkscape','id'=>'Inkscape.Inkscape','category'=>'Imagens e design'],
        ['name'=>'IrfanView','id'=>'IrfanSkiljan.IrfanView','category'=>'Imagens e design'],
        ['name'=>'Krita','id'=>'KDE.Krita','category'=>'Imagens e design'],
        ['name'=>'Paint.NET','id'=>'dotPDN.PaintDotNet','category'=>'Imagens e design'],
        ['name'=>'XnView MP','id'=>'XnSoft.XnViewMP','category'=>'Imagens e design'],
        ['name'=>'Bambu Studio','id'=>'BambuLab.BambuStudio','category'=>'Impressão 3D'],
        ['name'=>'Ultimaker Cura','id'=>'Ultimaker.Cura','category'=>'Impressão 3D'],
        ['name'=>'Amazon Corretto JDK 11','id'=>'Amazon.Corretto.11.JDK','category'=>'Java'],
        ['name'=>'Amazon Corretto JDK 17','id'=>'Amazon.Corretto.17.JDK','category'=>'Java'],
        ['name'=>'Amazon Corretto JDK 21','id'=>'Amazon.Corretto.21.JDK','category'=>'Java'],
        ['name'=>'Amazon Corretto JDK 25','id'=>'Amazon.Corretto.25.JDK','category'=>'Java'],
        ['name'=>'Amazon Corretto JDK 8','id'=>'Amazon.Corretto.8.JDK','category'=>'Java'],
        ['name'=>'Java Temurin JDK 11','id'=>'EclipseAdoptium.Temurin.11.JDK','category'=>'Java'],
        ['name'=>'Java Temurin JDK 17','id'=>'EclipseAdoptium.Temurin.17.JDK','category'=>'Java'],
        ['name'=>'Java Temurin JDK 21','id'=>'EclipseAdoptium.Temurin.21.JDK','category'=>'Java'],
        ['name'=>'Java Temurin JDK 25','id'=>'EclipseAdoptium.Temurin.25.JDK','category'=>'Java'],
        ['name'=>'Java Temurin JDK 8','id'=>'EclipseAdoptium.Temurin.8.JDK','category'=>'Java'],
        ['name'=>'Java Temurin JRE 8','id'=>'EclipseAdoptium.Temurin.8.JRE','category'=>'Java'],
        ['name'=>'Epic Games Launcher','id'=>'EpicGames.EpicGamesLauncher','category'=>'Jogos'],
        ['name'=>'Steam','id'=>'Valve.Steam','category'=>'Jogos'],
        ['name'=>'Discord','id'=>'Discord.Discord','category'=>'Mensagens'],
        ['name'=>'Microsoft Teams','id'=>'Microsoft.Teams','category'=>'Mensagens'],
        ['name'=>'Pidgin','id'=>'Pidgin.Pidgin','category'=>'Mensagens'],
        ['name'=>'Signal','id'=>'OpenWhisperSystems.Signal','category'=>'Mensagens'],
        ['name'=>'Slack','id'=>'SlackTechnologies.Slack','category'=>'Mensagens'],
        ['name'=>'Telegram Desktop','id'=>'Telegram.TelegramDesktop','category'=>'Mensagens'],
        ['name'=>'Thunderbird','id'=>'Mozilla.Thunderbird','category'=>'Mensagens'],
        ['name'=>'Trillian','id'=>'CeruleanStudios.Trillian','category'=>'Mensagens'],
        ['name'=>'WhatsApp','id'=>'WhatsApp.WhatsApp','category'=>'Mensagens'],
        ['name'=>'Zoom','id'=>'Zoom.Zoom','category'=>'Mensagens'],
        ['name'=>'AIMP','id'=>'AIMP.AIMP','category'=>'Multimídia'],
        ['name'=>'Audacity','id'=>'Audacity.Audacity','category'=>'Multimídia'],
        ['name'=>'foobar2000','id'=>'PeterPawlowski.foobar2000','category'=>'Multimídia'],
        ['name'=>'HandBrake','id'=>'HandBrake.HandBrake','category'=>'Multimídia'],
        ['name'=>'iTunes','id'=>'Apple.iTunes','category'=>'Multimídia'],
        ['name'=>'K-Lite Codec Pack','id'=>'CodecGuide.K-LiteCodecPack.Full','category'=>'Multimídia'],
        ['name'=>'MediaMonkey','id'=>'VentisMedia.MediaMonkey','category'=>'Multimídia'],
        ['name'=>'MusicBee','id'=>'MusicBee.MusicBee','category'=>'Multimídia'],
        ['name'=>'OBS Studio','id'=>'OBSProject.OBSStudio','category'=>'Multimídia'],
        ['name'=>'Spotify','id'=>'Spotify.Spotify','category'=>'Multimídia'],
        ['name'=>'VLC','id'=>'VideoLAN.VLC','category'=>'Multimídia'],
        ['name'=>'Winamp','id'=>'Winamp.Winamp','category'=>'Multimídia'],
        ['name'=>'Brave','id'=>'Brave.Brave','category'=>'Navegadores'],
        ['name'=>'Google Chrome','id'=>'Google.Chrome','category'=>'Navegadores'],
        ['name'=>'Microsoft Edge','id'=>'Microsoft.Edge','category'=>'Navegadores'],
        ['name'=>'Mozilla Firefox','id'=>'Mozilla.Firefox','category'=>'Navegadores'],
        ['name'=>'Opera','id'=>'Opera.Opera','category'=>'Navegadores'],
        ['name'=>'Vivaldi','id'=>'Vivaldi.Vivaldi','category'=>'Navegadores'],
        ['name'=>'Evernote','id'=>'Evernote.Evernote','category'=>'Produtividade'],
        ['name'=>'Google Earth Pro','id'=>'Google.EarthPro','category'=>'Produtividade'],
        ['name'=>'Advanced IP Scanner','id'=>'Famatech.AdvancedIPScanner','category'=>'Rede e segurança'],
        ['name'=>'Malwarebytes','id'=>'Malwarebytes.Malwarebytes','category'=>'Rede e segurança'],
        ['name'=>'OpenVPN Connect','id'=>'OpenVPNTechnologies.OpenVPNConnect','category'=>'Rede e segurança'],
        ['name'=>'Tailscale','id'=>'Tailscale.Tailscale','category'=>'Rede e segurança'],
        ['name'=>'WireGuard','id'=>'WireGuard.WireGuard','category'=>'Rede e segurança'],
        ['name'=>'Wireshark','id'=>'WiresharkFoundation.Wireshark','category'=>'Rede e segurança'],
        ['name'=>'1Password','id'=>'AgileBits.1Password','category'=>'Senhas'],
        ['name'=>'Bitwarden','id'=>'Bitwarden.Bitwarden','category'=>'Senhas'],
        ['name'=>'KeePassXC','id'=>'KeePassXCTeam.KeePassXC','category'=>'Senhas'],
        ['name'=>'7-Zip','id'=>'7zip.7zip','category'=>'Utilitários'],
        ['name'=>'CCleaner','id'=>'Piriform.CCleaner','category'=>'Utilitários'],
        ['name'=>'Everything','id'=>'voidtools.Everything','category'=>'Utilitários'],
        ['name'=>'Glary Utilities','id'=>'Glarysoft.GlaryUtilities','category'=>'Utilitários'],
        ['name'=>'Greenshot','id'=>'Greenshot.Greenshot','category'=>'Utilitários'],
        ['name'=>'KeePass','id'=>'DominikReichl.KeePass','category'=>'Utilitários'],
        ['name'=>'Launchy','id'=>'Launchy.Launchy','category'=>'Utilitários'],
        ['name'=>'Open-Shell','id'=>'Open-Shell.Open-Shell-Menu','category'=>'Utilitários'],
        ['name'=>'PeaZip','id'=>'Giorgiotani.Peazip','category'=>'Utilitários'],
        ['name'=>'PowerToys','id'=>'Microsoft.PowerToys','category'=>'Utilitários'],
        ['name'=>'Revo Uninstaller','id'=>'RevoUninstaller.RevoUninstaller','category'=>'Utilitários'],
        ['name'=>'Rufus','id'=>'Rufus.Rufus','category'=>'Utilitários'],
        ['name'=>'ShareX','id'=>'ShareX.ShareX','category'=>'Utilitários'],
        ['name'=>'TeraCopy','id'=>'CodeSector.TeraCopy','category'=>'Utilitários'],
        ['name'=>'WinDirStat','id'=>'WinDirStat.WinDirStat','category'=>'Utilitários'],
        ['name'=>'WizTree','id'=>'AntibodySoftware.WizTree','category'=>'Utilitários'],
    ];
    foreach ($rows as &$row) {
        $row['domain'] = $domains[$row['id']] ?? '';
        // Caminho relativo à raiz da plataforma; vazio quando não há logo embutido (a tela usa o endpoint como reserva).
        $row['logo'] = arc_program_logo_file($row['id']) !== '' ? 'assets/program-logos/' . arc_program_logo_filename($row['id']) : '';
    }
    unset($row);
    return $rows;
}

/** Comandos de cada tela (nomes usados na fila gestor_commands). */
function arc_network_operation_commands(): array
{
    return ['program' => ['program_inventory', 'program_uninstall', 'program_install'], 'clean' => ['clean_scan', 'clean_execute', 'clean_tool']];
}

/** Tempo limite (minutos) das operações longas. As demais usam a política padrão de arc_expire_running_commands. */
function arc_network_operation_timeouts(): array
{
    return ['program_inventory' => 10, 'program_uninstall' => 120, 'program_install' => 120, 'clean_scan' => 20, 'clean_execute' => 60, 'clean_tool' => 120];
}

/** Modos do ponto de restauração antes de desinstalar: off, try (tenta e segue) e require (cancela se falhar). */
function arc_program_restore_mode(mixed $value): string
{
    if ($value === true) return 'require';
    if ($value === false || $value === null || $value === '') return 'off';
    return in_array($value, ['off', 'try', 'require'], true) ? (string)$value : 'try';
}

/** Higieniza o inventário enviado pelo gestor. Cada programa é identificado por um SHA-256 local da máquina. */
function arc_program_sanitize_inventory(array $programs): array
{
    $clean = [];
    foreach (array_slice($programs, 0, 1500) as $r) {
        if (!is_array($r)) continue;
        $id = (string)($r['id'] ?? '');
        $name = trim((string)($r['name'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/D', $id) || $name === '') continue;
        $method = in_array($r['method'] ?? '', ['silent', 'interactive'], true) ? (string)$r['method'] : '';
        $icon = (string)($r['icon'] ?? '');
        $clean[] = [
            'id' => $id, 'name' => mb_substr($name, 0, 160),
            'version' => mb_substr((string)($r['version'] ?? ''), 0, 80),
            'publisher' => mb_substr((string)($r['publisher'] ?? ''), 0, 130),
            'date' => mb_substr((string)($r['date'] ?? ''), 0, 20),
            'size' => max(0, min(10000000, (int)($r['size'] ?? 0))),
            'method' => $method, 'uninstallable' => $method !== '',
            'icon' => preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]{1,35000}$#D', $icon) ? $icon : '',
            'package_id' => in_array((string)($r['package_id']??''), array_column(arc_program_install_catalog(),'id'), true) ? (string)$r['package_id'] : '',
            'available_version' => mb_substr((string)($r['available_version']??''),0,80),
        ];
    }
    return $clean;
}

/** Valida a seleção de desinstalação contra o inventário recente. Retorna [ids, usaJanela]. */
function arc_program_validate_uninstall(mixed $ids, array $inventoryRows): array
{
    if (!is_array($ids) || count($ids) < 1 || count($ids) > ARC_PROGRAM_BATCH_MAX || count(array_unique($ids)) !== count($ids)) {
        throw new InvalidArgumentException('Selecione de 1 a ' . ARC_PROGRAM_BATCH_MAX . ' programas diferentes');
    }
    $methods = [];
    foreach ($inventoryRows as $r) if (is_array($r) && !empty($r['uninstallable'])) $methods[(string)($r['id'] ?? '')] = (string)($r['method'] ?? 'silent');
    $window = false;
    foreach ($ids as $id) {
        if (!is_string($id) || !preg_match('/^[a-f0-9]{64}$/D', $id)) throw new InvalidArgumentException('Identificador inválido');
        if (!isset($methods[$id])) throw new InvalidArgumentException('Programa não encontrado ou sem desinstalador válido. Atualize o inventário.');
        if ($methods[$id] === 'interactive') $window = true;
    }
    return [array_values($ids), $window];
}

/** Operação ainda pendente/em andamento (permite à tela retomar o acompanhamento após recarregar). */
function arc_network_active_operation(PDO $pdo, int $gestorId, int $networkId, int $accountId, array $names): ?array
{
    $in = implode(',', array_fill(0, count($names), '?'));
    $q = $pdo->prepare('SELECT id,command_name,status,result_text FROM ' . arc_table('gestor_commands') . " WHERE gestor_id=? AND network_id=? AND account_id=? AND status IN ('pending','running') AND created_at>DATE_SUB(NOW(),INTERVAL 3 HOUR) AND command_name IN ($in) ORDER BY id DESC LIMIT 1");
    $q->execute(array_merge([$gestorId, $networkId, $accountId], $names));
    $row = $q->fetch(PDO::FETCH_ASSOC);
    return $row ? ['command_id' => (int)$row['id'], 'command_name' => $row['command_name'], 'status' => $row['status'], 'message' => (string)$row['result_text']] : null;
}


/** Estado que o gestor baixa para espelhar a plataforma: configurações, histórico, último relatório de cada ferramenta e inventário de programas. */
function arc_clean_gestor_state(PDO $pdo, int $gestorId, int $networkId, int $accountId, array $kinds): array
{
    $reports = [];
    foreach ($kinds as $kind) {
        if ($kind !== 'clean' && !isset(arc_clean_tools()[$kind])) continue;
        $row = arc_clean_report_get($pdo, $gestorId, $networkId, $accountId, (string)$kind);
        if ($row) $reports[$kind] = ['report' => $row['report'], 'collected_at' => $row['collected_at']];
    }
    $q = $pdo->prepare('SELECT programs_json,collected_at FROM ' . arc_table('gestor_software_inventory') . ' WHERE gestor_id=? AND network_id=? AND account_id=?');
    $q->execute([$gestorId, $networkId, $accountId]);
    $inventory = $q->fetch(PDO::FETCH_ASSOC);
    $programs = $inventory ? json_decode((string)$inventory['programs_json'], true) : [];
    return ['settings' => arc_clean_settings_get($pdo, $gestorId, $networkId, $accountId), 'history' => arc_clean_history($pdo, $gestorId, $networkId, $accountId),
            'index' => arc_clean_report_index($pdo, $gestorId, $networkId, $accountId), 'reports' => $reports,
            'programs' => is_array($programs) ? $programs : [], 'programs_at' => $inventory['collected_at'] ?? null];
}
