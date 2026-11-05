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

try {
    $pdo = arc_db();
    arc_ensure_user_schema($pdo);
    $packageMode = (($_GET['mode'] ?? '') === 'package');
    if ($packageMode) {
        $credential = (string)($_SERVER['HTTP_X_ARCOLL_INSTALL_TOKEN'] ?? ($_POST['install_token'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/D', $credential)) { http_response_code(403); exit('Credencial invalida.'); }
        $lookup = $pdo->prepare('SELECT k.account_id,k.network_id,k.site_code FROM ' . arc_table('agent_download_keys') . ' k WHERE k.token_hash=? AND k.fingerprint IS NULL AND k.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) LIMIT 1');
        $lookup->execute([hash('sha256', $credential)]);
        $keyRecord = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!$keyRecord) { http_response_code(403); exit('Link expirado ou invalido.'); }
        $networkId = (int)$keyRecord['network_id'];
        $network = $pdo->prepare('SELECT id, name, site_code, monitoring_enabled FROM ' . arc_table('managed_networks') . ' WHERE id=? AND account_id=? AND is_active=1 LIMIT 1');
        $network->execute([$networkId, (int)$keyRecord['account_id']]);
        $downloadKey = $credential;
    } else {
        if (!arc_is_user()) { header('Location: ../index.php', true, 303); exit; }
        $networkId = (int)($_GET['network'] ?? arc_current_network_id() ?? 0);
        $network = $pdo->prepare('SELECT id, name, site_code, monitoring_enabled FROM ' . arc_table('managed_networks') . ' WHERE id=? AND account_id=? AND is_active=1 LIMIT 1');
        $network->execute([$networkId, arc_user_id()]);
        $downloadKey = bin2hex(random_bytes(32));
    }
    $networkRow = $network->fetch(PDO::FETCH_ASSOC);
    if (!$networkRow) { http_response_code(403); exit('Rede inativa ou nao autorizada.'); }
    if (!class_exists(ZipArchive::class)) {
        http_response_code(503);
        exit('A extensão ZipArchive do PHP está desativada. Ative a extensão ZIP no PHP usado pelo site, pelo painel da hospedagem.');
    }

    if ((int)$networkRow['monitoring_enabled'] !== 1) {
        http_response_code(409);
        exit('Ative o monitoramento desta rede antes de baixar um agente.');
    }
    $settings = arc_setting_values($pdo);
    $systemName = trim((string)($settings['site_name'] ?? 'Agente de Rede'));
    if ($systemName === '') $systemName = 'Agente de Rede';
    $systemDescription = trim((string)($settings['tagline'] ?? 'Monitoramento de redes'));
    $systemAscii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $systemName) ?: $systemName;
    $systemSlug = preg_replace('/[^A-Za-z0-9_-]+/', '_', $systemAscii);
    $systemSlug = trim((string)$systemSlug, '_');
    if ($systemSlug === '') $systemSlug = 'Agente_de_Rede';
    $siteCode = strtoupper(trim((string)$networkRow['site_code']));
    if (!preg_match('/^[A-Z0-9]{5}$/', $siteCode)) throw new RuntimeException('O código local da rede não está no formato correto. Atualize o cadastro da rede.');
    arc_require_aligned_agent_release();
    // Crie a credencial em memória; persista somente após validar o ZIP completo.

    // Both installation and remote update use exactly the same validated inventory.
    $source=__DIR__.'/../agentes/windows';
    $sources=arc_agent_installer_sources();
    $required=array_keys($sources);

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

    if (!$packageMode) {
        // Persist the network-scoped enrollment key only after all prerequisite checks.
        $pdo->prepare('INSERT INTO ' . arc_table('agent_download_keys') . ' (token_hash,account_id,network_id,site_code) VALUES (?,?,?,?)')
            ->execute([hash('sha256', $downloadKey), arc_user_id(), $networkId, $siteCode]);
        $endpoint = $origin . '/api/download-agent.php?mode=package';
        $ps = <<<'PS'
$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'
$temp=Join-Path $env:TEMP ('ArcollInstall-'+[guid]::NewGuid().ToString('N'))
New-Item -Path $temp -ItemType Directory | Out-Null
try {
    $zip=Join-Path $temp 'agente.zip'
    $r=Invoke-WebRequest -Uri $env:ARCOLL_URL -Method POST -Body @{install_token=$env:ARCOLL_TOKEN} -UseBasicParsing -OutFile $zip -PassThru
    $expected=$r.Headers['X-Arcoll-SHA256']
    if (!$expected -or $expected -notmatch '^[a-fA-F0-9]{64}$' -or (Get-FileHash -Algorithm SHA256 -LiteralPath $zip).Hash -ne $expected) { throw 'Falha na verificacao de integridade.' }
    Expand-Archive -LiteralPath $zip -DestinationPath $temp -Force
    $prepare=Get-ChildItem -Path $temp -Recurse -Filter Preparar_Instalacao.ps1 -File | Select-Object -First 1
    if (!$prepare) { throw 'Preparador da instalacao nao encontrado.' }
    $arguments=@('-NoLogo','-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-STA','-WindowStyle','Hidden','-File',('"'+$prepare.FullName+'"'),'-Source',('"'+$prepare.DirectoryName+'"'))
    $process=Start-Process -FilePath 'powershell.exe' -Verb RunAs -ArgumentList $arguments -WindowStyle Hidden -Wait -PassThru
    if ($process.ExitCode -ne 0) { throw ('Falha no instalador. Codigo '+$process.ExitCode) }
} catch {
    Add-Type -AssemblyName System.Windows.Forms
    [System.Windows.Forms.MessageBox]::Show($_.Exception.Message,'Falha ao instalar o agente','OK','Error') | Out-Null
    exit 1
}
PS;
        $encoded = base64_encode(iconv('UTF-8', 'UTF-16LE', $ps));
        // O BAT inicia um processo invisível e encerra o CMD imediatamente.
        // A abertura inicial do próprio .bat pelo Explorer ainda pode causar um breve flash.
        $script = "@rem Caminho: agentes/windows/Instalar_Online.bat\r\n@echo off\r\nsetlocal\r\n"
            . 'set "ARCOLL_URL=' . $endpoint . '"' . "\r\n"
            . 'set "ARCOLL_TOKEN=' . $downloadKey . '"' . "\r\n"
            . 'set "ARCOLL_VBS=%TEMP%\ArcollInstall_%RANDOM%_%RANDOM%.vbs"' . "\r\n"
            . '>"%ARCOLL_VBS%" echo Set shell = CreateObject("WScript.Shell")' . "\r\n"
            . '>>"%ARCOLL_VBS%" echo shell.Run "powershell.exe -NoLogo -NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -EncodedCommand ' . $encoded . '", 0, False' . "\r\n"
            . 'wscript.exe //B "%ARCOLL_VBS%"' . "\r\n"
            . 'del /q "%ARCOLL_VBS%" 2>nul' . "\r\n"
            . "exit /b 0\r\n";
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $systemSlug . '_Windows.bat"');
        header('Content-Length: ' . strlen($script));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, private');
        echo $script;
        exit;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'arcoll-agent-');
    if ($tmp === false) throw new RuntimeException('Não foi possível preparar o pacote.');
    $zipPath = $tmp . '.zip';
    @unlink($tmp);
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Não foi possível gerar o ZIP do agente.');
    }
    $packageRoot = $systemSlug . '_Agente';
    $zip->addEmptyDir($packageRoot . '/.dados');
    foreach ($required as $file) {
        $contents = $sources[$file];
        if (!is_string($contents) || $contents === '') throw new RuntimeException('Conteúdo ausente no agente: ' . $file);
        // O Windows PowerShell 5.1 interpreta scripts sem BOM como ANSI.
        // Normalize tambem arquivos provenientes de releases publicados para UTF-8 com BOM.
        if (strtolower((string)pathinfo($file, PATHINFO_EXTENSION)) === 'ps1') {
            $utf8Bom = "\xEF\xBB\xBF";
            if (strncmp($contents, $utf8Bom, 3) !== 0) {
                $contents = $utf8Bom . $contents;
            }
        }
        $zip->addFromString($packageRoot . '/.dados/' . $file, $contents);
    }
    $zip->addFromString($packageRoot . '/.dados/.arcoll-installer-marker', 'ARCOLL-INSTALL-V14');


    // Nome da rede: duas primeiras palavras, sem caracteres proibidos pelo Windows.
    $networkWords = preg_split('/\s+/u', trim((string)$networkRow['name']), -1, PREG_SPLIT_NO_EMPTY);
    $networkLabel = implode(' ', array_slice($networkWords ?: [], 0, 2));
    $networkLabel = trim((string)preg_replace('~[\\\\/:*?"<>|\x00-\x1F]+~u', '', $networkLabel));
    if ($networkLabel === '') $networkLabel = 'Rede';
    $networkLabel = (function_exists('mb_substr') ? mb_substr($networkLabel, 0, 65, 'UTF-8') : substr($networkLabel, 0, 65));
    $installerName = 'Instalar.bat';
    $installer = <<<'CMD'
@rem Caminho: Arcoll_Agente/Instalar.bat
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
    $zip->addFromString($packageRoot . '/' . $installerName, $installer);
    $zip->addFromString($packageRoot . '/.dados/agent-config.json', json_encode([
        'design'=>arc_support_design($pdo,'agent'),
        'origin' => $origin,
        'network_id' => $networkId,
        'site_code' => $siteCode,
        'download_key' => $downloadKey,
        'agent_version' => arc_agent_published_version(),
        'system_name' => $systemName,
        'system_description' => $systemDescription,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    // Marca os componentes internos como ocultos no Explorador do Windows.
    if (method_exists($zip, 'setExternalAttributesName')) {
        foreach (array_merge($required, ['favicon.ico', 'agent-config.json', '.arcoll-installer-marker']) as $file) {
            $zip->setExternalAttributesName($packageRoot . '/.dados/' . $file, ZipArchive::OPSYS_DOS, 0x02 << 16);
        }
        $zip->setExternalAttributesName($packageRoot . '/.dados/', ZipArchive::OPSYS_DOS, 0x12 << 16);

    }
    if (!$zip->close()) throw new RuntimeException('Não foi possível finalizar o ZIP. Verifique espaço e permissão de gravação em temp.');
    clearstatcache(true, $zipPath);
    if (!is_file($zipPath) || filesize($zipPath) < 100) throw new RuntimeException('O pacote ZIP ficou incompleto.');

    // A chave só passa a existir no banco quando o pacote está pronto para envio.
    $versionLabel = preg_replace('/[^0-9A-Za-z._-]/', '_', arc_agent_published_version());
    $networkSafe = preg_replace('/[^A-Za-z0-9_-]/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $networkLabel) ?: 'Rede');
    $safeName = $systemSlug . '_' . $versionLabel . '_' . $networkSafe . '.zip';
    header('X-Arcoll-SHA256: ' . hash_file('sha256', $zipPath));
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
    // Não exponha detalhes internos para visitantes ou usuários sem privilégio administrativo.
    $incident = strtoupper(substr(bin2hex(random_bytes(6)), 0, 12));
    error_log('Arcoll agent download [' . $incident . '] ' . get_class($e) . ': ' . $e->getMessage());
    if (isset($zip) && $zip instanceof ZipArchive) $zip->close();
    if (isset($zipPath) && is_string($zipPath) && is_file($zipPath)) @unlink($zipPath);
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

