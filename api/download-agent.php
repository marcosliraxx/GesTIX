<?php
// Caminho: api/download-agent.php
declare(strict_types=1);

if (!is_file(__DIR__ . '/../includes/config.php')) {
    http_response_code(503);
    exit('Sistema não configurado.');
}
require __DIR__ . '/../includes/bootstrap.php';

function arc_agent_published_version(): string {
    return arc_app_version();
}

/** Endereço HTTPS público da plataforma, usado pelo instalador e pelos scripts de instalação. */
function arc_agent_https_origin(): string {
    $origin = arc_base_url();
    if (!preg_match('~^https://~i', $origin)) {
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '' || preg_match('/[^a-zA-Z0-9.:-]/', $host)) {
            throw new RuntimeException('Não foi possível determinar o endereço HTTPS do sistema.');
        }
        $origin = 'https://' . $host . rtrim(str_replace('\\', '/', dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/api/download-agent.php')))), '/');
        if ($origin === 'https:') $origin = 'https://' . $host;
    }
    return rtrim($origin, '/');
}

try {
    $pdo = arc_db();
    arc_ensure_user_schema($pdo);
    $requestedMode = (string)($_GET['mode'] ?? '');
    // Distribuicao WinUI 3: projeto-fonte isolado em agentes/servidor; motores Python legados continuam em agentes/windows ate a migracao completa.
    // O formato interno Arcoll.WinUI3/ do ZIP e mantido para nao quebrar instaladores e atualizadores existentes.
    // O BAT instala a interface de teste em paralelo ao servico operacional existente.
    $nativeRoot = realpath(__DIR__ . '/../agentes/servidor/Arcoll.WinUI3');
    if ($nativeRoot === false || !is_file($nativeRoot . '/Arcoll.WinUI3.csproj') || !is_file($nativeRoot . '/INSTALAR_WINUI3.ps1')) {
        throw new RuntimeException('Projeto nativo WinUI 3 indisponivel no servidor.');
    }
    $nativeOrigin = arc_agent_https_origin();
    if (!preg_match('~^https://[a-z0-9.:-]+(?:/|$)~i', $nativeOrigin)) throw new RuntimeException('Origem HTTPS invalida.');
    $nativeCheck = static function (PDO $db, string $token): array {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) { http_response_code(403); exit('Token invalido.'); }
        $query = $db->prepare('SELECT k.network_id,k.account_id,k.site_code FROM ' . arc_table('agent_download_keys') . ' k INNER JOIN ' . arc_table('managed_networks') . ' n ON n.id=k.network_id AND n.account_id=k.account_id WHERE k.token_hash=? AND k.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND k.fingerprint IS NULL AND n.is_active=1 AND n.monitoring_enabled=1 LIMIT 1');
        $query->execute([hash('sha256', $token)]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row) { http_response_code(403); exit('Token expirado ou rede inativa.'); }
        return $row;
    };
    // Monta o ZIP de fontes do agente nativo (sem binarios). Com $config, inclui o agent-config.json da instalacao; na atualizacao nao inclui.
    $nativeZip = static function (?array $config) use ($nativeRoot, $pdo, &$zipNative, &$tmpZip): string {
        if (!class_exists(ZipArchive::class)) { http_response_code(503); exit('PHP ZIP indisponivel.'); }
        $tmpZip = tempnam(sys_get_temp_dir(), 'winui3-');
        if ($tmpZip === false) throw new RuntimeException('Pasta temporaria indisponivel.');
        $zipNative = new ZipArchive();
        if ($zipNative->open($tmpZip, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Falha ao criar ZIP nativo.');
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($nativeRoot, FilesystemIterator::SKIP_DOTS));
        foreach ($walk as $file) {
            if (!$file->isFile() || $file->isLink()) continue;
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($nativeRoot)+1));
            if (preg_match('~(?:^|/)(?:bin|obj|\.vs|\.git|packages)(?:/|$)~i', $rel)) continue;
            if (preg_match('/\.(?:exe|dll|pdb|zip|nupkg)$/i', $rel)) continue;
            if ($rel === 'agent-config.json') continue;
            // Lista positiva: não distribui arquivos de configuração, bancos, credenciais ou logs
            // acidentalmente colocados na pasta de compilação do servidor.
            if (!preg_match('~\.(?:cs|csproj|xaml|ps1|manifest|md)$~i', $rel)) continue;
            // VERSION e a fonte unica; o pacote compilado no Windows precisa usar a versao publicada,
            // inclusive quando a plataforma avancou o patch automaticamente.
            if (in_array($rel, ['Arcoll.WinUI3.csproj','BootWorker/BootWorker.csproj'], true)) {
                $source = file_get_contents($file->getPathname());
                if (!is_string($source) || !preg_match('~<Version>\d+\.\d+\.\d+</Version>~', $source))
                    throw new RuntimeException('Versao do projeto WinUI nao encontrada.');
                $source = preg_replace('~<Version>\d+\.\d+\.\d+</Version>~', '<Version>' . arc_app_version() . '</Version>', $source, 1);
                $zipNative->addFromString('Arcoll.WinUI3/' . $rel, $source);
                continue;
            }
            $zipNative->addFile($file->getPathname(), 'Arcoll.WinUI3/' . $rel);
        }
        require_once dirname(__DIR__) . '/includes/support.php';
        require_once dirname(__DIR__) . '/includes/network-clean.php';
        $zipNative->addFromString('Arcoll.WinUI3/Assets/system.ico', arc_support_icon($pdo, 'agent'));
        $zipNative->addFromString('Arcoll.WinUI3/Assets/program-catalog.json', json_encode(arc_program_install_catalog(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $zipNative->addFromString('Arcoll.WinUI3/Assets/clean-catalog.json', json_encode([
            'catalog'=>arc_clean_catalog(), 'registry'=>arc_clean_registry_checks(), 'health'=>arc_clean_health_sets(),
            'tabs'=>arc_clean_tabs(), 'ages'=>arc_clean_age_options()
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        foreach (['arcoll_clean.py','arcoll_programs.py','arcoll_cloud.py','arcoll_release.py'] as $engineFile) {
            $enginePath=dirname(__DIR__).'/agentes/windows/'.$engineFile;
            if (!is_file($enginePath)) throw new RuntimeException('Motor Clean incompleto: '.$engineFile);
            $zipNative->addFile($enginePath,'Arcoll.WinUI3/Assets/clean-engine/'.$engineFile);
        }
        $zipNative->addFile($nativeRoot.'/Worker/clean_worker.py','Arcoll.WinUI3/Assets/clean-engine/clean_worker.py');
        $zipNative->addFromString('Arcoll.WinUI3/Assets/clean-engine/VERSION',arc_app_version());

        // Logos dos programas (mesmos PNG da plataforma): o catálogo do agente funciona sem internet.
        // Assets\** é copiado para a pasta de instalação pelo .csproj.
        foreach (glob(dirname(__DIR__) . '/assets/program-logos/*.png') ?: [] as $logo) {
            if (is_file($logo) && !is_link($logo) && filesize($logo) < 200000) $zipNative->addFile($logo, 'Arcoll.WinUI3/Assets/program-logos/' . basename($logo));
        }
        if ($config !== null) $zipNative->addFromString('Arcoll.WinUI3/agent-config.json', json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if (!$zipNative->close()) throw new RuntimeException('Falha ao fechar ZIP nativo.');
        return $tmpZip;
    };
    /* Atualizacao remota/forcada: o agente instalado se autentica com o mesmo vinculo revogavel (impressao digital, codigo e chave). */
    if ($requestedMode === 'native-update') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Metodo invalido.'); }
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || strlen($raw) > 2048) { http_response_code(413); exit('Requisicao invalida.'); }
        $v = json_decode($raw, true);
        if (!is_array($v)) { http_response_code(400); exit('JSON invalido.'); }
        $fp = strtolower(trim((string)($v['fingerprint'] ?? '')));
        $code = strtoupper(trim((string)($v['site_code'] ?? '')));
        $key = trim((string)($v['download_key'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $fp) || !preg_match('/^[A-Z0-9]{5}$/', $code) || !preg_match('/^[a-f0-9]{64}$/', $key)) { http_response_code(403); exit('Credenciais invalidas.'); }
        $q = $pdo->prepare('SELECT a.id FROM ' . arc_table('network_agents') . ' a INNER JOIN ' . arc_table('managed_networks') . ' n ON n.id=a.network_id AND n.account_id=a.account_id INNER JOIN ' . arc_table('agent_download_keys') . ' k ON k.network_id=a.network_id AND k.account_id=a.account_id WHERE a.fingerprint=? AND UPPER(a.site_code)=? AND a.status=\'approved\' AND n.is_active=1 AND n.monitoring_enabled=1 AND k.token_hash=? AND k.fingerprint=? LIMIT 1');
        $q->execute([$fp, $code, hash('sha256', $key), $fp]);
        if (!$q->fetchColumn()) { http_response_code(403); exit('Agente nao autorizado.'); }
        $path = $nativeZip(null);
        $hash = hash_file('sha256', $path);
        if ($hash === false) throw new RuntimeException('Nao foi possivel assinar o pacote.');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="TI-Digital-WinUI3-Atualizacao.zip"');
        header('Content-Length: ' . filesize($path));
        header('X-Agent-Version: ' . arc_app_version());
        header('X-Package-SHA256: ' . $hash);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($path); @unlink($path); exit;
    }
    if ($requestedMode === 'native-package' || $requestedMode === 'native-bootstrap') {
        $token = (string)($_GET['t'] ?? '');
        $nativeRecord = $nativeCheck($pdo, $token);
        if ($requestedMode === 'native-bootstrap') {
            // Entrega o instalador visual WinForms; a janela gerencia download,
            // compilacao, andamento e diagnostico sem usar console interativo.
            $url = $nativeOrigin . '/api/download-agent.php?mode=native-package&t=' . $token;
            $visualPath = $nativeRoot . '/INSTALADOR_VISUAL_WINUI3.ps1';
            if (!is_file($visualPath)) throw new RuntimeException('Instalador visual indisponivel no servidor.');
            $visual = file_get_contents($visualPath);
            if ($visual === false || strpos($visual, '__PACKAGE_URL__') === false) {
                throw new RuntimeException('Modelo do instalador visual invalido.');
            }
            $ps = str_replace('__PACKAGE_URL__', str_replace("'", "''", $url), $visual);
            // Windows PowerShell 5.1 interpreta scripts UTF-8 com BOM corretamente.
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: private, no-store, max-age=0');
            header('X-Content-Type-Options: nosniff');
            exit("\xEF\xBB\xBF" . ltrim($ps, "\xEF\xBB\xBF"));
        }
        $tmpZip = $nativeZip($nativeConfig = [
            'origin'=>$nativeOrigin,
            'network_id'=>(int)$nativeRecord['network_id'],
            'site_code'=>(string)$nativeRecord['site_code'],
            'download_key'=>$token,
            'agent_version'=>arc_app_version(),
        ]);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="TI-Digital-WinUI3-Fontes.zip"');
        header('Content-Length: ' . filesize($tmpZip));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($tmpZip); @unlink($tmpZip); exit;
    }
    if ($requestedMode === 'command') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') arc_json(['ok'=>false,'error'=>'Metodo invalido.'],405);
        if (!arc_is_user()) arc_json(['ok'=>false,'error'=>'Autenticacao necessaria.'],401);
        arc_verify_csrf();
        $networkId = (int)($_POST['network'] ?? 0);
        $q = $pdo->prepare('SELECT id,site_code FROM ' . arc_table('managed_networks') . ' WHERE id=? AND account_id=? AND is_active=1 AND monitoring_enabled=1 LIMIT 1');
        $q->execute([$networkId, arc_user_id()]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row || !preg_match('/^[A-Z0-9]{5}$/', strtoupper(trim((string)$row['site_code'])))) arc_json(['ok'=>false,'error'=>'Rede nao autorizada ou codigo invalido.'],403);
        $key = bin2hex(random_bytes(32));
        $pdo->prepare('INSERT INTO ' . arc_table('agent_download_keys') . ' (token_hash,account_id,network_id,site_code) VALUES (?,?,?,?)')->execute([hash('sha256',$key),arc_user_id(),$networkId,strtoupper(trim((string)$row['site_code']))]);
        $url = $nativeOrigin . '/api/download-agent.php?mode=native-bootstrap&t=' . $key;
        arc_json(['ok'=>true,'expires_hours'=>24,'command'=>'powershell -NoProfile -Command "& { $p=Join-Path $env:TEMP (\'ti-winui3-\'+[guid]::NewGuid().ToString(\'N\')+\'.ps1\'); Invoke-WebRequest -UseBasicParsing -Uri \'' . $url . '\' -OutFile $p; & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $p; if ($LASTEXITCODE -ne 0) { exit 1 } }"']);
    }
    if ($requestedMode === '' || $requestedMode === 'bat') {
        if (!arc_is_user()) { header('Location: ../index.php',true,303); exit; }
        $networkId = (int)($_GET['network'] ?? arc_current_network_id() ?? 0);
        $q = $pdo->prepare('SELECT id,site_code FROM ' . arc_table('managed_networks') . ' WHERE id=? AND account_id=? AND is_active=1 AND monitoring_enabled=1 LIMIT 1');
        $q->execute([$networkId, arc_user_id()]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row || !preg_match('/^[A-Z0-9]{5}$/', strtoupper(trim((string)$row['site_code'])))) { http_response_code(403); exit('Rede nao autorizada.'); }
        $key = bin2hex(random_bytes(32));
        $pdo->prepare('INSERT INTO ' . arc_table('agent_download_keys') . ' (token_hash,account_id,network_id,site_code) VALUES (?,?,?,?)')->execute([hash('sha256',$key),arc_user_id(),$networkId,strtoupper(trim((string)$row['site_code']))]);
        $bootstrap = $nativeOrigin . '/api/download-agent.php?mode=native-bootstrap&t=' . $key;
        $bat = "@echo off\r\nREM Caminho: api/download-agent.php (BAT gerado)\r\nsetlocal\r\ntitle T.I Digital - Instalacao Windows\r\necho Iniciando assistente de instalacao T.I Digital...\r\n" .
            "powershell.exe -NoProfile -ExecutionPolicy Bypass -Command \"\$ErrorActionPreference='Stop'; \$p=Join-Path \$env:TEMP ('ti-winui3-'+[guid]::NewGuid().ToString('N')+'.ps1'); try { Invoke-WebRequest -UseBasicParsing -Uri '" . $bootstrap . "' -OutFile \$p; & powershell.exe -NoProfile -ExecutionPolicy Bypass -File \$p; if(\$LASTEXITCODE -ne 0) { exit 1 } } catch { Write-Host \$_ -ForegroundColor Red; exit 1 } finally { Remove-Item -LiteralPath \$p -Force -ErrorAction SilentlyContinue }\"\r\n" .
            "if errorlevel 1 (echo. & echo Falha na instalacao. Confira os logs em %%LOCALAPPDATA%%\\TI-Digital\\Logs. & pause & exit /b 1)\r\n" .
            "echo.\r\necho Operacao concluida.\r\npause\r\n";
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="Instalar_TI_Digital_WinUI3.bat"');
        header('Content-Length: ' . strlen($bat));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, max-age=0');
        exit($bat);
    }
    // Modos antigos nao podem distribuir o instalador Python descontinuado.
    http_response_code(410);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    exit('Modo antigo descontinuado. Utilize o botao Baixar Agente para obter o BAT WinUI 3.');
} catch (Throwable $e) {
    // Não exponha detalhes internos para visitantes ou usuários sem privilégio administrativo.
    $incident = strtoupper(substr(bin2hex(random_bytes(6)), 0, 12));
    error_log('Arcoll agent download [' . $incident . '] ' . get_class($e) . ': ' . $e->getMessage());
    if (isset($zipNative) && $zipNative instanceof ZipArchive) @$zipNative->close();
    if (isset($tmpZip) && is_string($tmpZip) && is_file($tmpZip)) @unlink($tmpZip);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    $message = 'Não foi possível preparar o download do agente. Referência: ' . $incident . '.';
    if (arc_is_admin()) {
        $message .= "\nDiagnóstico: " . $e->getMessage();
        $message .= "\nVerifique também os registros do servidor e o status da publicação em Administração > Versões.";
    }
    exit($message);
}

