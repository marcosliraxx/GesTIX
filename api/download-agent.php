<?php
// Caminho: api/download-agent.php
declare(strict_types=1);

if (!is_file(__DIR__ . '/../includes/config.php')) {
    http_response_code(503);
    exit('Sistema não configurado.');
}
require __DIR__ . '/../includes/bootstrap.php';

function arc_agent_published_version(): string {
    return ARC_APP_VERSION;
}

try {
    $pdo = arc_db();
    arc_ensure_user_schema($pdo);
    if (!arc_is_user()) {
        header('Location: ../pages/../index.php', true, 303);
        exit;
    }
    $networkId = (int)($_GET['network'] ?? arc_current_network_id() ?? 0);
    $network = $pdo->prepare('SELECT id, name, site_code, monitoring_enabled FROM ' . arc_table('managed_networks') . ' WHERE id=? AND account_id=? AND is_active=1 LIMIT 1');
    $network->execute([$networkId, arc_user_id()]);
    $networkRow = $network->fetch(PDO::FETCH_ASSOC);
    if (!$networkRow) {
        http_response_code(403);
        exit('Selecione uma rede ativa para baixar o agente.');
    }
    if (!class_exists(ZipArchive::class)) {
        http_response_code(503);
        exit('O servidor não possui suporte a ZIP. Ative a extensão PHP Zip no cPanel.');
    }

    if ((int)$networkRow['monitoring_enabled'] !== 1) {
        http_response_code(409);
        exit('Ative o monitoramento desta rede antes de baixar um agente.');
    }
    $siteCode = strtoupper(trim((string)$networkRow['site_code']));
    if (!preg_match('/^[A-Z0-9]{5}$/', $siteCode)) throw new RuntimeException('O código local da rede não está no formato correto. Atualize o cadastro da rede.');
    arc_require_aligned_agent_release();
    $downloadKey = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO ' . arc_table('agent_download_keys') . ' (token_hash,account_id,network_id,site_code) VALUES (?,?,?,?)')
        ->execute([hash('sha256', $downloadKey), arc_user_id(), $networkId, $siteCode]);

    // Download and authenticated updates use the same current release sources.
    $source = __DIR__ . '/../agent';
    $required = ['arcoll_agent.py','arcoll_desktop.py','arcoll_app.py','arcoll_toolkit.py','rt_engine.py','arcoll_updater.py','Instalar_Arcoll.ps1','Preparar_Instalacao.ps1','Desinstalar_Arcoll.ps1'];
    // Inclui automaticamente novos módulos Python/PowerShell da release.
    foreach (glob($source.'/*') ?: [] as $candidate) {
        $name=basename($candidate);
        if (is_file($candidate) && preg_match('/^[A-Za-z][A-Za-z0-9_-]*\.(?:py|ps1)$/D', $name) && !in_array($name,$required,true)) $required[]=$name;
    }
    sort($required, SORT_STRING);
    foreach ($required as $file) {
        if (!is_readable($source.'/'.$file)) throw new RuntimeException('Arquivo do agente ausente: '.$file);
    }

    $origin = arc_base_url();
    if (!preg_match('~^https://~i', $origin)) {
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '' || preg_match('/[^a-zA-Z0-9.:-]/', $host)) {
            throw new RuntimeException('Não foi possível determinar o endereço HTTPS do sistema.');
        }
        $origin = 'https://' . $host . rtrim(str_replace('\\', '/', dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/api/download-agent.php')))), '/');
        if ($origin === 'https:') $origin = 'https://' . $host;
    }
    $origin = rtrim($origin, '/');
    $batTemplate = <<<'BAT'
@echo off
setlocal
cd /d "%~dp0"
set "ARCOLL_ORIGIN=__ORIGIN__"
if /I "%~1"=="--background" (
    powershell.exe -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -STA -WindowStyle Hidden -File "%~dp0Instalar_Arcoll.ps1" -Run -Background
    exit /b %errorlevel%
)
if /I "%~1"=="--run" (
    powershell.exe -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -STA -WindowStyle Hidden -File "%~dp0Instalar_Arcoll.ps1" -Run
    exit /b %errorlevel%
)
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -STA -WindowStyle Hidden -File "%~dp0Instalar_Arcoll.ps1"
exit /b %errorlevel%
BAT;
    $bat = str_replace('__ORIGIN__', $origin, $batTemplate);

    $tmp = tempnam(sys_get_temp_dir(), 'arcoll-agent-');
    if ($tmp === false) throw new RuntimeException('Não foi possível preparar o pacote.');
    $zipPath = $tmp . '.zip';
    @unlink($tmp);
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Não foi possível gerar o ZIP do agente.');
    }
    $zip->addEmptyDir('Arcoll_Agente/.dados');
    foreach ($required as $file) {
        $contents = file_get_contents($source . '/' . $file);
        if (!is_string($contents) || $contents === '') throw new RuntimeException('Conteúdo ausente no agente: ' . $file);
        // O Windows PowerShell 5.1 interpreta scripts sem BOM como ANSI.
        // Normalize tambem arquivos provenientes de releases publicados para UTF-8 com BOM.
        if (strtolower((string)pathinfo($file, PATHINFO_EXTENSION)) === 'ps1') {
            $utf8Bom = "\xEF\xBB\xBF";
            if (strncmp($contents, $utf8Bom, 3) !== 0) {
                $contents = $utf8Bom . $contents;
            }
        }
        $zip->addFromString('Arcoll_Agente/.dados/' . $file, $contents);
    }
    // Módulo complementar, disponível mesmo quando a release foi publicada antes dele.
    $extraModule = $source . '/connection_diagnostics.py';
    if (!in_array('connection_diagnostics.py',$required,true) && is_file($extraModule) && is_readable($extraModule)) {
        $zip->addFile($extraModule, 'Arcoll_Agente/.dados/connection_diagnostics.py');
    }
    $zip->addFromString('Arcoll_Agente/.dados/.arcoll-installer-marker', 'ARCOLL-INSTALL-V14');

    $zip->addFromString('Arcoll_Agente/.dados/favicon.ico',arc_support_icon($pdo,'agent'));
    // Nome da rede: duas primeiras palavras, sem caracteres proibidos pelo Windows.
    $networkWords = preg_split('/\s+/u', trim((string)$networkRow['name']), -1, PREG_SPLIT_NO_EMPTY);
    $networkLabel = implode(' ', array_slice($networkWords ?: [], 0, 2));
    $networkLabel = trim((string)preg_replace('~[\\\\/:*?"<>|\x00-\x1F]+~u', '', $networkLabel));
    if ($networkLabel === '') $networkLabel = 'Rede';
    $networkLabel = (function_exists('mb_substr') ? mb_substr($networkLabel, 0, 65, 'UTF-8') : substr($networkLabel, 0, 65));
    $installerName = 'Instalar.bat';
    $installer = <<<'CMD'
@echo off
setlocal
cd /d "%~dp0"
if not exist ".dados\Preparar_Instalacao.ps1" (
  echo Arquivos da instalacao ausentes. Extraia o ZIP completo.
  pause
  exit /b 1
)
powershell.exe -NoProfile -ExecutionPolicy Bypass -STA -Command "$p=Join-Path (Get-Location).Path '.dados\Preparar_Instalacao.ps1'; $s=Join-Path (Get-Location).Path '.dados'; Start-Process -FilePath 'powershell.exe' -Verb RunAs -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-STA','-File',('"'+$p+'"'),'-Source',('"'+$s+'"'))"
if errorlevel 1 (echo Nao foi possivel iniciar o instalador elevado.& pause)
exit /b
CMD;
    $zip->addFromString('Arcoll_Agente/' . $installerName, $installer);
    $zip->addFromString('Arcoll_Agente/.dados/agent-config.json', json_encode([
        'design'=>arc_support_design($pdo,'agent'),
        'origin' => $origin,
        'network_id' => $networkId,
        'site_code' => $siteCode,
        'download_key' => $downloadKey,
        'agent_version' => arc_agent_published_version(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    // Marca os componentes internos como ocultos no Explorador do Windows.
    if (method_exists($zip, 'setExternalAttributesName')) {
        foreach (array_merge($required, ['favicon.ico', 'agent-config.json', '.arcoll-installer-marker']) as $file) {
            $zip->setExternalAttributesName('Arcoll_Agente/.dados/' . $file, ZipArchive::OPSYS_DOS, 0x02 << 16);
        }
        $zip->setExternalAttributesName('Arcoll_Agente/.dados/', ZipArchive::OPSYS_DOS, 0x12 << 16);

    }
    $zip->close();
    if (!is_file($zipPath) || filesize($zipPath) < 100) throw new RuntimeException('O pacote ZIP ficou incompleto.');

    $safeName = 'Arcoll_Agente_' . preg_replace('/[^A-Za-z0-9_-]/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $networkLabel) ?: 'Rede') . '.zip';
    header('Content-Type: application/zip');
    header('Content-Length: ' . (string)filesize($zipPath));
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    readfile($zipPath);
    @unlink($zipPath);
    exit;
} catch (Throwable $e) {
    error_log('Arcoll agent download error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Não foi possível preparar o download do agente. Verifique os arquivos do agente e a extensão ZIP do PHP.');
}
