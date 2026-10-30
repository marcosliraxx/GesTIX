<?php
declare(strict_types=1);

if (!is_file(__DIR__ . '/../includes/config.php')) {
    http_response_code(503);
    exit('Sistema não configurado.');
}
require __DIR__ . '/../includes/bootstrap.php';

function arc_agent_published_version(): string {
    $path = dirname(__DIR__) . '/includes/storage/releases/agent_manifest.json';
    $json = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
    $v = is_array($json) ? (string)($json['version'] ?? '') : '';
    return preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$/', $v) ? $v : ARC_APP_VERSION;
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
    $downloadKey = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO ' . arc_table('agent_download_keys') . ' (token_hash,account_id,network_id,site_code) VALUES (?,?,?,?)')
        ->execute([hash('sha256', $downloadKey), arc_user_id(), $networkId, $siteCode]);

    $releaseArchive = null;
    $releaseManifest = null;
    $releaseManifestPath = dirname(__DIR__) . '/includes/storage/releases/agent_manifest.json';
    $releaseDirectory = dirname(__DIR__) . '/includes/storage/releases';
    if (is_file($releaseManifestPath)) {
        $releaseManifest = json_decode((string)file_get_contents($releaseManifestPath), true);
        if (is_array($releaseManifest) && !empty($releaseManifest['file'])) {
            $publishedName = (string)$releaseManifest['file'];
            if (basename($publishedName) !== $publishedName || !preg_match('/^Arcoll-Agent-[A-Za-z0-9.+_-]+\.zip$/', $publishedName)) {
                throw new RuntimeException('Nome do pacote publicado inválido.');
            }
            $publishedPath = $releaseDirectory . '/' . $publishedName;
            if (!is_file($publishedPath) || !hash_equals((string)($releaseManifest['sha256'] ?? ''), (string)hash_file('sha256', $publishedPath))) {
                throw new RuntimeException('Falha de integridade na versão publicada.');
            }
            $releaseArchive = new ZipArchive();
            if ($releaseArchive->open($publishedPath) !== true) throw new RuntimeException('Pacote publicado ilegível.');
        }
    }
    $source = __DIR__ . '/../agent';
    $required = ['arcoll_agent.py', 'arcoll_desktop.py', 'arcoll_app.py', 'arcoll_toolkit.py', 'rt_engine.py', 'arcoll_updater.py', 'Instalar_Arcoll.ps1', 'Preparar_Instalacao.ps1', 'Desinstalar_Arcoll.ps1'];
    foreach ($required as $file) {
        if ($releaseArchive !== null) {
            if ($file === 'Desinstalar_Arcoll.ps1' || $file === 'rt_engine.py' || $file === 'arcoll_updater.py') { if (!is_readable(__DIR__ . '/../agent/' . $file)) throw new RuntimeException('Desinstalador indisponível.'); } elseif ($releaseArchive->locateName('agent/' . $file) === false) throw new RuntimeException('Arquivo ausente na versão publicada: ' . $file);
        } elseif (!is_file($source . '/' . $file) || !is_readable(__DIR__ . '/../agent/' . $file)) {
            throw new RuntimeException('Arquivo do agente ausente: ' . $file);
        }
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
        $localOverrides = ['Instalar_Arcoll.ps1', 'Preparar_Instalacao.ps1', 'Desinstalar_Arcoll.ps1', 'arcoll_app.py', 'arcoll_desktop.py', 'rt_engine.py', 'arcoll_updater.py'];
        $contents = in_array($file, $localOverrides, true)
            ? file_get_contents($source . '/' . $file)
            : ($releaseArchive !== null ? $releaseArchive->getFromName('agent/' . $file) : file_get_contents($source . '/' . $file));
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
    if (is_file($extraModule) && is_readable($extraModule)) {
        $zip->addFile($extraModule, 'Arcoll_Agente/.dados/connection_diagnostics.py');
    }
    if ($releaseArchive !== null) $releaseArchive->close();
    $zip->addFromString('Arcoll_Agente/.dados/.arcoll-installer-marker', 'ARCOLL-INSTALL-V14');

    // Gera o mesmo ícone configurado em pages/identidade.php para o Windows.
    $brandName = trim((string)(arc_setting_values($pdo)['logo_path'] ?? ''));
    $brandPath = ($brandName !== '' && basename($brandName) === $brandName && preg_match('/^[a-f0-9]{32,40}\.(png|jpg|webp)$/', $brandName)) ? arc_upload_dir('brand') . '/' . $brandName : '';
    $iconPng = '';
    if (function_exists('imagecreatetruecolor')) {
        $im = ($brandPath !== '' && is_file($brandPath)) ? @imagecreatefromstring((string)file_get_contents($brandPath)) : false;
        $canvas = imagecreatetruecolor(64, 64);
        imagealphablending($canvas, false); imagesavealpha($canvas, true);
        $clear = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, 63, 63, $clear);
        if ($im !== false) {
            $w=imagesx($im); $h=imagesy($im); $scale=min(58/max(1,$w),58/max(1,$h));
            $dw=max(1,(int)round($w*$scale)); $dh=max(1,(int)round($h*$scale));
            imagecopyresampled($canvas,$im,(int)((64-$dw)/2),(int)((64-$dh)/2),0,0,$dw,$dh,$w,$h);
            imagedestroy($im);
        } else {
            $bg=imagecolorallocate($canvas,15,36,54);$fg=imagecolorallocate($canvas,34,211,238);
            imagefilledellipse($canvas,32,32,58,58,$bg);imageellipse($canvas,32,32,57,57,$fg);
        }
        ob_start(); imagepng($canvas); $iconPng=(string)ob_get_clean(); imagedestroy($canvas);
    }
    if ($iconPng !== '') {
        $ico = pack('vvv',0,1,1).pack('CCCCvvVV',64,64,0,0,1,32,strlen($iconPng),22).$iconPng;
        $zip->addFromString('Arcoll_Agente/.dados/favicon.ico',$ico);
    } elseif (is_file($source . '/favicon.ico')) {
        $zip->addFile($source . '/favicon.ico','Arcoll_Agente/.dados/favicon.ico');
    } else {
        throw new RuntimeException('É necessário habilitar a extensão GD do PHP para gerar o ícone da identidade.');
    }
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
