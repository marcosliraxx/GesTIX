# Caminho: agentes/servidor/Arcoll.WinUI3/INSTALAR_WINUI3.ps1
# Instalacao e atualizacao, por usuario, do agente Windows nativo (C# WinUI 3): atalhos, execucao em segundo plano e progresso em porcentagem.
# -Update: troca silenciosa disparada pelo proprio agente (atualizacao remota); -ForceRebuild: reinstalacao integral (build limpo).
[CmdletBinding()]
param([switch]$Update,[int]$ParentPid=0,[switch]$ForceRebuild,[switch]$Unattended,[string]$UpdateId)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
# Implantacao por ferramenta de TI: nao abre UAC nem qualquer confirmacao local.
# Falha antes de alterar arquivos se a ferramenta nao forneceu token administrativo.
if ($Unattended) {
    $isDeploymentAdmin = [Security.Principal.WindowsPrincipal]::new([Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
    if (-not $isDeploymentAdmin) {
        [Console]::Error.WriteLine('Implantacao sem interacao exige contexto administrativo fornecido pela ferramenta de TI. Nenhuma janela UAC sera aberta.')
        exit 5
    }
}

$root = $PSScriptRoot
# Mensagens do MSBuild/.NET em ingles e UTF-8: evita texto corrompido nos logs e facilita a busca por erros.
$env:DOTNET_CLI_UI_LANGUAGE = 'en'
$env:VSLANG = '1033'
$env:DOTNET_NOLOGO = '1'
$env:DOTNET_CLI_TELEMETRY_OPTOUT = '1'
try { [Console]::OutputEncoding = [System.Text.Encoding]::UTF8 } catch { }
$publish = $null
$swapped = $false
$inPlace = $false
$previous = $null
$bootStatus = $null
$taskWasSuspended = $false
$taskNameToResume = 'TI-Digital Agente Windows'
$updateOperationId = $UpdateId
$destination = Join-Path $env:LOCALAPPDATA 'TI-Digital\WinUI3'
$appName = 'Arcoll.WinUI3.exe'
function Step([int]$Percent,[string]$Message,[int]$Shown=-1) {
    Write-Host ('[' + $Percent + '] ' + $Message)
    if ($Update) {
        # Etapas do instalador com porcentagens individuais, sem manter 55% durante a compilacao.
        $shown = switch ($Percent) {
            36 { 55 } 38 { 57 } 45 { 60 } 50 { 63 } 62 { 66 } 82 { 84 } 88 { 89 } 93 { 93 } 96 { 96 } 98 { 98 } 99 { 99 } default { [Math]::Min(99,[Math]::Max(55,$Percent)) }
        }
        if ($Shown -ge 0) { $shown = $Shown }
        try {
            $progressPath = Join-Path $env:LOCALAPPDATA 'TI-Digital\update-progress.json'
            $state = @{ update_id = $updateOperationId; percent = $shown; message = $Message; when = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds() }
            [IO.File]::WriteAllText($progressPath, ($state | ConvertTo-Json -Compress), (New-Object Text.UTF8Encoding($false)))
        } catch { }
        # O processo anterior deve fechar para substituir os arquivos. O instalador
        # continua informando o progresso pela mesma fila autenticada do agente.
        try {
            $pendingPath = Join-Path $env:LOCALAPPDATA 'TI-Digital\update-pending.json'
            if (-not (Test-Path -LiteralPath $pendingPath)) { return }
            $pending = Get-Content -LiteralPath $pendingPath -Raw -Encoding UTF8 | ConvertFrom-Json
            if ([int64]$pending.command_id -le 0) { return }
            $cfgFile = Join-Path $destination 'agent-config.json'
            $migrating = Join-Path $root 'agent-config.json'
            if (Test-Path -LiteralPath $migrating) { $cfgFile = $migrating }
            if (-not (Test-Path -LiteralPath $cfgFile)) { return }
            $cfg = Get-Content -LiteralPath $cfgFile -Raw -Encoding UTF8 | ConvertFrom-Json
            $endpoint = ([string]$cfg.origin).TrimEnd('/') + '/api/api.php?action=agent-command-ack'
            $uri = $null
            if (-not [Uri]::TryCreate($endpoint, [UriKind]::Absolute, [ref]$uri) -or $uri.Scheme -ne 'https' -or $uri.UserInfo -ne '') { return }
            $body = @{ fingerprint = [string]$cfg.fingerprint; site_code = [string]$cfg.site_code;
                       download_key = [string]$cfg.download_key; command_id = [int64]$pending.command_id;
                       status = 'running'; result = 'WinUI 3 ' + $shown + '%: ' + $Message } | ConvertTo-Json -Compress
            Invoke-RestMethod -Uri $endpoint -Method Post -ContentType 'application/json' -Body ([Text.Encoding]::UTF8.GetBytes($body)) -TimeoutSec 8 -MaximumRedirection 0 -ErrorAction Stop | Out-Null
        } catch { } # Desconexao nao interrompe instalacao: o aplicativo retomara o recibo.
    }
}
function Write-UpdateState([string]$Path, [object]$Value) {
    $temp = $Path + '.' + [guid]::NewGuid().ToString('N') + '.tmp'
    try {
        [IO.File]::WriteAllText($temp, ($Value | ConvertTo-Json -Compress), (New-Object Text.UTF8Encoding($false)))
        for ($attempt = 0; $attempt -lt 5; $attempt++) {
            try { Move-Item -LiteralPath $temp -Destination $Path -Force; return }
            catch { if ($attempt -eq 4) { throw }; Start-Sleep -Milliseconds 100 }
        }
    } finally { if (Test-Path -LiteralPath $temp) { Remove-Item -LiteralPath $temp -Force -ErrorAction SilentlyContinue } }
}
function Write-UpdateResult([bool]$Ok,[string]$Message) {
    if (-not $Update) { return }
    $o = [ordered]@{ update_id = $updateOperationId; ok = $Ok; message = $Message; finished = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds() }
    # A janela usa um recibo duravel: o agente pode consumir update-result.json rapidamente.
    try { Write-UpdateState (Join-Path $env:LOCALAPPDATA 'TI-Digital\update-ui-result.json') $o } catch { Write-Warning $_.Exception.Message }
    try { Write-UpdateState (Join-Path $env:LOCALAPPDATA 'TI-Digital\update-result.json') $o } catch { Write-Warning $_.Exception.Message }
}

# Encerra tudo que mantem a pasta da instalacao ocupada: o agente, o worker de presenca e os processos
# do WebView2 (msedgewebview2.exe), que sobrevivem ao agente por alguns segundos e usam o painel local.
function Stop-AgentHolders([string]$Folder) {
    $prefix = $Folder.TrimEnd('\') + '\'
    $webProfile = Join-Path $env:LOCALAPPDATA 'TI-Digital\HybridWebView2'
    foreach ($proc in @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue)) {
        if ([int]$proc.ProcessId -eq $PID) { continue }
        $hit = $false
        if ($proc.ExecutablePath -and $proc.ExecutablePath.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) { $hit = $true }
        elseif ($proc.Name -eq 'msedgewebview2.exe' -and $proc.CommandLine -and
            ($proc.CommandLine.IndexOf($webProfile, [StringComparison]::OrdinalIgnoreCase) -ge 0 -or
             $proc.CommandLine.IndexOf($Folder, [StringComparison]::OrdinalIgnoreCase) -ge 0)) { $hit = $true }
        if ($hit) { try { Stop-Process -Id ([int]$proc.ProcessId) -Force -ErrorAction Stop } catch { } }
    }
}
# Sincroniza as fontes recebidas com um cache persistente: o MSBuild reaproveita obj/ e so recompila o que mudou.
# A comparacao e por conteudo (SHA-256), pois o pacote traz datas fixas nos arquivos.
function Sync-BuildSource([string]$From, [string]$To) {
    New-Item -ItemType Directory -Path $To -Force | Out-Null
    $fromFull = (Resolve-Path -LiteralPath $From).Path.TrimEnd('\')
    $toFull = (Resolve-Path -LiteralPath $To).Path.TrimEnd('\')
    $keep = @{}
    foreach ($file in Get-ChildItem -LiteralPath $fromFull -Recurse -File -Force -ErrorAction SilentlyContinue) {
        $rel = $file.FullName.Substring($fromFull.Length + 1)
        if ($rel -match '(^|\\)(bin|obj)\\' -or $rel -ieq 'agent-config.json') { continue }
        $keep[$rel.ToLowerInvariant()] = $true
        $dst = Join-Path $toFull $rel
        $same = $false
        if (Test-Path -LiteralPath $dst -PathType Leaf) {
            $existing = Get-Item -LiteralPath $dst
            if ($existing.Length -eq $file.Length -and
                (Get-FileHash -LiteralPath $dst -Algorithm SHA256).Hash -eq (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash) { $same = $true }
        }
        if (-not $same) {
            New-Item -ItemType Directory -Path (Split-Path -Parent $dst) -Force | Out-Null
            Copy-Item -LiteralPath $file.FullName -Destination $dst -Force
            (Get-Item -LiteralPath $dst).LastWriteTimeUtc = [DateTime]::UtcNow
        }
    }
    foreach ($old in Get-ChildItem -LiteralPath $toFull -Recurse -File -Force -ErrorAction SilentlyContinue) {
        $rel = $old.FullName.Substring($toFull.Length + 1)
        if ($rel -match '(^|\\)(bin|obj)\\') { continue }
        if (-not $keep.ContainsKey($rel.ToLowerInvariant())) { Remove-Item -LiteralPath $old.FullName -Force -ErrorAction SilentlyContinue }
    }
}
# Executa um comando longo (compilacao) mostrando andamento real: a porcentagem avanca a cada poucos segundos
# em vez de ficar parada, e a plataforma recebe o mesmo andamento pela fila de operacoes.
function Invoke-WithBeat([string]$Exe, [string[]]$ArgList, [string]$LogPath, [int]$From, [int]$To, [string]$Label, [bool]$Append = $false) {
    $previousPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    $started = Get-Date; $lastBeat = Get-Date
    if (-not $Append -and (Test-Path -LiteralPath $LogPath)) { Remove-Item -LiteralPath $LogPath -Force -ErrorAction SilentlyContinue }
    try {
        & $Exe @ArgList 2>&1 | ForEach-Object {
            $line = [string]$_
            try { Add-Content -LiteralPath $LogPath -Value $line -Encoding UTF8 } catch { }
            Write-Host $line
            $now = Get-Date
            if (($now - $lastBeat).TotalSeconds -ge 12) {
                $lastBeat = $now
                $elapsed = ($now - $started).TotalSeconds
                $pct = [int]($From + ($To - $From) * (1 - [Math]::Exp(-$elapsed / 120)))
                Step $From ($Label + ' (' + [TimeSpan]::FromSeconds([int]$elapsed).ToString('mm\:ss') + ')') ([Math]::Min($To, [Math]::Max($From, $pct)))
            }
        }
    } finally { $ErrorActionPreference = $previousPreference }
    return $LASTEXITCODE
}
$logs = Join-Path $env:LOCALAPPDATA 'TI-Digital\Logs'
New-Item -ItemType Directory -Path $logs -Force | Out-Null
$log = Join-Path $logs ('Instalar-WinUI3-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.log')
Start-Transcript -Path $log -Force | Out-Null
try {
    Write-Host ('T.I Digital - ' + $(if ($Update) { 'Atualizacao' } else { 'Instalacao' }) + ' do Agente Windows') -ForegroundColor Cyan
    if ($Update) {
        $pendingPath = Join-Path $env:LOCALAPPDATA 'TI-Digital\update-pending.json'
        $pending = $null
        if (Test-Path -LiteralPath $pendingPath) { $pending = Get-Content -LiteralPath $pendingPath -Raw -Encoding UTF8 | ConvertFrom-Json }
        if (-not $updateOperationId -and $pending) { $updateOperationId = [string]$pending.update_id }
        if (-not $updateOperationId) { $updateOperationId = [guid]::NewGuid().ToString('N') }
        if ($pending) {
            $pending | Add-Member -NotePropertyName update_id -NotePropertyValue $updateOperationId -Force
            Write-UpdateState $pendingPath $pending
        }
        $viewer = Join-Path $root 'ATUALIZACAO_VISUAL.ps1'
        if (-not $Unattended -and [Environment]::UserInteractive -and (Get-Process -Id $PID).SessionId -ne 0 -and (Test-Path -LiteralPath $viewer)) {
            try {
                $viewerArgs = '-NoLogo -NoProfile -NonInteractive -STA -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $viewer + '" -UpdateId "' + $updateOperationId + '"'
                Start-Process -FilePath (Join-Path $PSHOME 'powershell.exe') -ArgumentList $viewerArgs -WindowStyle Hidden | Out-Null
            } catch { Write-Warning ('Janela de progresso indisponivel: ' + $_.Exception.Message) }
        }
    }
    Step 36 'Verificando requisitos do Windows...'
    if ($Update -and $ParentPid -gt 0) {
        Step 38 'Aguardando o agente encerrar...'
        $parent = Get-Process -Id $ParentPid -ErrorAction SilentlyContinue
        if ($parent -and $parent.ProcessName -eq 'Arcoll.WinUI3') {
            if (-not $parent.WaitForExit(25000)) {
                Stop-Process -Id $ParentPid -Force -ErrorAction SilentlyContinue
                try { $parent.WaitForExit(15000) | Out-Null } catch { }
            }
        }
    }
    if ([Environment]::OSVersion.Version.Build -lt 19041) { throw 'Necessario Windows 10 2004 (build 19041) ou superior.' }
    $project = Join-Path $root 'Arcoll.WinUI3.csproj'
    # O servidor publica VERSION automaticamente; os .csproj nao possuem versao fixa.
    $versionPath = Join-Path $root 'VERSION'
    if (-not (Test-Path -LiteralPath $versionPath)) { throw 'Pacote sem VERSION publicado pela plataforma.' }
    $publishedVersion = (Get-Content -LiteralPath $versionPath -Raw -Encoding UTF8).Trim()
    if ($publishedVersion -notmatch '^\d+\.\d+\.\d+$') { throw 'VERSION do pacote e invalido.' }
    $buildVersionArg = '-p:Version=' + $publishedVersion

    $required = @('App.xaml', 'MainWindow.xaml', 'app.manifest', 'PreLogin-Setup.ps1'); if (-not $Update) { $required += 'agent-config.json' }
    foreach ($file in $required) {
        if (-not (Test-Path -LiteralPath (Join-Path $root $file))) { throw "Arquivo ausente: $file" }
    }
    # Detectar SDK 8 sem depender do PATH de uma sessao antiga.
    $dotnetExe = (Get-Command dotnet.exe -ErrorAction SilentlyContinue | Select-Object -First 1 -ExpandProperty Source)
    $defaultDotnet = Join-Path ${env:ProgramFiles} 'dotnet\dotnet.exe'
    if (-not $dotnetExe -and (Test-Path -LiteralPath $defaultDotnet)) { $dotnetExe = $defaultDotnet }
    $hasSdk = $false
    if ($dotnetExe) {
        $sdkList = @(& $dotnetExe --list-sdks 2>&1)
        $hasSdk = [bool](@($sdkList | Where-Object { $_ -match '^8\.' }).Count)
    }
    if (-not $hasSdk) {
        Write-Host 'SDK .NET 8 nao encontrado. Instalando pelo WinGet (Microsoft)...' -ForegroundColor Yellow
        $winget = Get-Command winget.exe -ErrorAction SilentlyContinue
        if (-not $winget) {
            throw 'WinGet indisponivel. Instale o SDK .NET 8 em https://dotnet.microsoft.com/download/dotnet/8.0 e execute o BAT novamente.'
        }
        # Instalacao solicitada pelo proprio usuario ao executar o BAT.
        # Sem Read-Host, pois o bootstrap funciona em modo nao interativo.
        & $winget.Source install --id Microsoft.DotNet.SDK.8 --exact --source winget --silent --disable-interactivity --accept-source-agreements --accept-package-agreements
        if ($LASTEXITCODE -ne 0) { throw ('Instalacao do SDK .NET 8 pelo WinGet falhou (codigo ' + $LASTEXITCODE + '). Instale-o em https://dotnet.microsoft.com/download/dotnet/8.0 e execute o BAT novamente.') }
        if (Test-Path -LiteralPath $defaultDotnet) {
            $dotnetExe = $defaultDotnet
            $env:PATH = (Split-Path -Parent $defaultDotnet) + ';' + $env:PATH
        }
        if (-not $dotnetExe) { throw 'SDK instalado, mas dotnet.exe nao foi localizado. Reabra o terminal e execute o BAT novamente.' }
        $sdkList = @(& $dotnetExe --list-sdks 2>&1)
        if (-not @($sdkList | Where-Object { $_ -match '^8\.' }).Count) {
            throw 'SDK .NET 8 ainda nao detectado. Reabra o terminal e execute o BAT novamente.'
        }
    }
    Step 45 'SDK .NET 8 disponivel.'
    if ($ForceRebuild) { foreach ($d in 'bin','obj','BootWorker\bin','BootWorker\obj') { $path = Join-Path $root $d; if (Test-Path -LiteralPath $path) { Remove-Item -LiteralPath $path -Recurse -Force -ErrorAction SilentlyContinue } } }
    # Cache persistente de compilacao: a primeira atualizacao compila tudo; as seguintes recompilam apenas o que mudou.
    $buildRoot = $root
    if ($Update) {
        $cacheRoot = Join-Path $env:LOCALAPPDATA 'TI-Digital\BuildCache\src'
        try {
            if ($ForceRebuild -and (Test-Path -LiteralPath $cacheRoot)) { Remove-Item -LiteralPath $cacheRoot -Recurse -Force -ErrorAction Stop }
            Step 46 'Preparando compilacao incremental...'
            Sync-BuildSource $root $cacheRoot
            $buildRoot = $cacheRoot
            $project = Join-Path $buildRoot 'Arcoll.WinUI3.csproj'
            if (-not (Test-Path -LiteralPath $project)) { throw 'Projeto ausente no cache.' }
        } catch {
            Write-Warning ('Cache de compilacao indisponivel (' + $_.Exception.Message + '); compilando a partir do pacote recebido.')
            $buildRoot = $root
            $project = Join-Path $root 'Arcoll.WinUI3.csproj'
        }
    }
    $arch = if ([Environment]::Is64BitOperatingSystem) { 'win-x64' } else { '' }
    if ($env:PROCESSOR_ARCHITECTURE -eq 'ARM64') { $arch = 'win-arm64' }
    if (-not $arch) { throw 'Windows de 32 bits nao suportado.' }
    $platform = if ($arch -eq 'win-arm64') { 'ARM64' } else { 'x64' }
    $publish = Join-Path $env:TEMP ('ti-digital-winui-build-' + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $publish | Out-Null
    # Nao instalar uma interface incompleta que quebraria o Painel apos a troca.
    foreach ($asset in @('index.html', 'styles.css', 'app.js')) {
        $assetPath = Join-Path $buildRoot ('Assets\hybrid\dashboard\' + $asset)
        if (-not (Test-Path -LiteralPath $assetPath -PathType Leaf)) {
            throw ('Pacote de distribuicao incompleto: Assets\hybrid\dashboard\' + $asset)
        }
        if ((Get-Item -LiteralPath $assetPath).Length -eq 0) {
            throw ('Recurso visual vazio: ' + $asset)
        }
    }
    Step 50 'Restaurando pacotes assinados da Microsoft pelo NuGet...'
    & $dotnetExe restore $project $buildVersionArg "-p:Platform=$platform" "-p:RuntimeIdentifier=$arch"
    if ($LASTEXITCODE -ne 0) { throw 'Restauracao do projeto falhou. Consulte o log.' }
    # Validacao previa: o App.xaml deve constar uma unica vez como ApplicationDefinition.
    # Duplicidade faz o XamlCompiler.exe encerrar com codigo 1 sem mensagem.
    $appDefs = $null
    try {
        $rawItems = & $dotnetExe msbuild $project $buildVersionArg -nologo -getItem:ApplicationDefinition "-p:Platform=$platform" "-p:RuntimeIdentifier=$arch" 2>$null | Out-String
        $appDefs = @(($rawItems | ConvertFrom-Json).Items.ApplicationDefinition)
    } catch { $appDefs = $null }
    if ($appDefs -and $appDefs.Count -gt 1) { throw ('Projeto invalido: App.xaml declarado ' + $appDefs.Count + ' vezes no .csproj.') }
    Step 62 'Compilando a interface nativa C#...'
    # Preferir o MSBuild do Visual Studio quando presente.
    $vswhere = Join-Path ${env:ProgramFiles(x86)} 'Microsoft Visual Studio\Installer\vswhere.exe'
    $msbuild = $null
    if (Test-Path -LiteralPath $vswhere) {
        $foundMsbuild = & $vswhere -latest -products * -requires Microsoft.Component.MSBuild -find 'MSBuild\**\Bin\MSBuild.exe' 2>$null
        if ($foundMsbuild) { $msbuild = @($foundMsbuild)[0] }
    }
    $buildLog = Join-Path $logs ('Compilacao-WinUI3-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.txt')
    $binLog = [System.IO.Path]::ChangeExtension($buildLog, '.binlog')
    $baseMsbuild = @('/nologo','/m:1','/t:Build','/p:Configuration=Release',"/p:Platform=$platform", "/p:RuntimeIdentifier=$arch",'/p:WindowsPackageType=None','/p:WindowsAppSDKSelfContained=true','/v:normal',"/bl:$binLog")
    if ($msbuild) {
        Write-Host ('Compilando com Visual Studio MSBuild: ' + $msbuild)
        $result = Invoke-WithBeat $msbuild (@($project, $buildVersionArg) + $baseMsbuild) $buildLog 66 82 'Compilando a interface nativa' $false
    } else {
        Write-Host 'MSBuild do Visual Studio nao encontrado; usando MSBuild do SDK .NET.'
        $result = Invoke-WithBeat $dotnetExe (@('msbuild', $project, $buildVersionArg) + $baseMsbuild) $buildLog 66 82 'Compilando a interface nativa' $false
    }
    if ($result -ne 0) {
        Write-Host ('Compilacao falhou (codigo ' + $result + ').') -ForegroundColor Red
        if (Test-Path -LiteralPath $buildLog) { Get-Content -LiteralPath $buildLog -Tail 35 | ForEach-Object { Write-Host $_ } }
        Write-Host ('Log: ' + $buildLog)
        Write-Host ('Diagnostico MSBuild: ' + $binLog)
        # Preservar a resposta estruturada do compilador XAML para obter o erro original.
        $objDir = Join-Path $buildRoot 'obj'
        if (Test-Path -LiteralPath $objDir) {
            # Exibe no proprio log a resposta do compilador XAML (erros reais de XAML).
            Get-ChildItem -LiteralPath $objDir -Recurse -File -Filter 'output.json' -ErrorAction SilentlyContinue | ForEach-Object {
                $txt = Get-Content -LiteralPath $_.FullName -Raw -ErrorAction SilentlyContinue
                if ($txt) { Write-Host ('XamlCompiler output.json: ' + $txt.Substring(0, [Math]::Min(3000, $txt.Length))) }
            }
            $diagnosticDir = Join-Path $logs ('Xaml-' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
            New-Item -ItemType Directory -Path $diagnosticDir -Force | Out-Null
            Get-ChildItem -LiteralPath $objDir -Recurse -File -ErrorAction SilentlyContinue |
                Where-Object { $_.Name -in @('input.json','output.json') } | ForEach-Object {
                    $relative = $_.FullName.Substring($objDir.Length).TrimStart('\')
                    $destFile = Join-Path $diagnosticDir $relative
                    New-Item -ItemType Directory -Path (Split-Path -Parent $destFile) -Force | Out-Null
                    Copy-Item -LiteralPath $_.FullName -Destination $destFile -Force
                }
            Write-Host ('Diagnosticos XAML: ' + $diagnosticDir)
        }
        throw ('Falha na compilacao XAML (codigo ' + $result + ').')
    }
    Step 82 'Compilacao concluida. Publicando arquivos...'
    $publishCode = Invoke-WithBeat $dotnetExe @('publish', $project, $buildVersionArg, '-c', 'Release', '-r', $arch, '--self-contained', 'true', '-p:WindowsPackageType=None', '-p:WindowsAppSDKSelfContained=true', "-p:Platform=$platform", '-p:PublishReadyToRun=false', '-p:PublishTrimmed=false', '-o', $publish, '--no-restore') $buildLog 84 88 'Publicando arquivos' $true
    if ($publishCode -ne 0) { throw ('Publicacao falhou, codigo ' + $publishCode + '. Veja ' + $buildLog) }
    $exe = Join-Path $publish 'Arcoll.WinUI3.exe'
    if (-not (Test-Path $exe)) { throw 'Executavel nao encontrado apos a compilacao.' }
    foreach ($asset in @('index.html', 'styles.css', 'app.js')) {
        $publishedAsset = Join-Path $publish ('Assets\hybrid\dashboard\' + $asset)
        if (-not (Test-Path -LiteralPath $publishedAsset -PathType Leaf)) {
            throw ('Publicacao incompleta: painel hibrido sem ' + $asset + '. A instalacao anterior nao sera alterada.')
        }
    }
    # Compila somente a presenca de boot (sem UI e sem comandos remotos).
    # Instalada em Program Files pela etapa administrativa; nunca execute WinUI em Session 0.
    $bootProject = Join-Path $buildRoot 'BootWorker\BootWorker.csproj'
    $bootPublish = Join-Path $env:TEMP ('ti-digital-boot-build-' + [guid]::NewGuid().ToString('N'))
    if (-not (Test-Path -LiteralPath $bootProject)) { throw 'Projeto BootWorker ausente no pacote.' }
    try {
        & $dotnetExe publish $bootProject $buildVersionArg -c Release -r $arch --self-contained true -p:PublishTrimmed=false -p:PublishReadyToRun=false -o $bootPublish
        if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath (Join-Path $bootPublish 'TIDigital.BootPresence.exe'))) {
            throw 'Falha na compilacao do worker de inicializacao antes do login.'
        }
        Copy-Item -LiteralPath (Join-Path $root 'ATUALIZACAO_VISUAL.ps1') -Destination (Join-Path $publish 'ATUALIZACAO_VISUAL.ps1') -Force
        Copy-Item -LiteralPath (Join-Path $root 'PreLogin-Setup.ps1') -Destination (Join-Path $publish 'PreLogin-Setup.ps1') -Force
        Copy-Item -LiteralPath (Join-Path $root 'HABILITAR_PRELOGIN.ps1') -Destination (Join-Path $publish 'HABILITAR_PRELOGIN.ps1') -Force
        Copy-Item -Path $bootPublish -Destination (Join-Path $publish 'BootWorker') -Recurse -Force
    } finally {
        if (Test-Path -LiteralPath $bootPublish) { Remove-Item -LiteralPath $bootPublish -Recurse -Force -ErrorAction SilentlyContinue }
    }
    # Backup independente: publica binarios, mas a tarefa SYSTEM exige instalacao explicita.
    $backupProject = Join-Path $buildRoot 'BackupWorker\BackupWorker.csproj'
    $backupPublish = Join-Path $env:TEMP ('ti-digital-backup-build-' + [guid]::NewGuid().ToString('N'))
    try {
        & $dotnetExe publish $backupProject $buildVersionArg -c Release -r $arch --self-contained true -p:PublishTrimmed=false -o $backupPublish
        if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath (Join-Path $backupPublish 'TIDigital.BackupWorker.exe'))) { throw 'Falha ao publicar executor de backup.' }
        Copy-Item -Path $backupPublish -Destination (Join-Path $publish 'BackupWorker') -Recurse -Force
        Copy-Item -LiteralPath (Join-Path $root 'INSTALAR_BACKUP_SERVICO.ps1') -Destination $publish -Force
    } finally { if (Test-Path -LiteralPath $backupPublish) { Remove-Item -LiteralPath $backupPublish -Recurse -Force -ErrorAction SilentlyContinue } }
    Step 88 'Instalando arquivos do agente...'
    # A configuracao (origem, codigo e chave) do computador ja instalado e preservada na atualizacao.
    $installedConfig = Join-Path $destination 'agent-config.json'
    # A ponte de migracao do Python fornece a mesma identidade revogavel no pacote ja
    # autenticado. Em atualizacoes WinUI normais nao ha config no ZIP; preserve a instalada.
    # Dar prioridade ao cadastro migrado evita reativar uma rede antiga instalada em paralelo.
    $migratedConfig = Join-Path $root 'agent-config.json'
    if ($Update -and (Test-Path -LiteralPath $migratedConfig)) { Copy-Item -LiteralPath $migratedConfig -Destination (Join-Path $publish 'agent-config.json') -Force }
    elseif ($Update -and (Test-Path -LiteralPath $installedConfig)) { Copy-Item -LiteralPath $installedConfig -Destination (Join-Path $publish 'agent-config.json') -Force }
    elseif (Test-Path -LiteralPath $migratedConfig) { Copy-Item -LiteralPath $migratedConfig -Destination (Join-Path $publish 'agent-config.json') -Force }
    else { throw 'agent-config.json ausente: reinstale pelo painel da plataforma.' }
    # Encerrar apenas instâncias desta instalação; o worker pode manter arquivos da pasta abertos.
    $running = @(Get-Process -Name 'Arcoll.WinUI3','TIDigital.BootPresence' -ErrorAction SilentlyContinue | Where-Object {
        try { $_.Path -and $_.Path.StartsWith(($destination.TrimEnd('\') + '\'),[StringComparison]::OrdinalIgnoreCase) } catch { $false }
    })
    foreach ($process in $running) {
        try { $process.CloseMainWindow() | Out-Null } catch { }
    }
    Start-Sleep -Seconds 2
    foreach ($process in $running) {
        try { if (-not $process.HasExited) { Stop-Process -Id $process.Id -Force -ErrorAction Stop } } catch { Write-Warning ('Não foi possível encerrar ' + $process.ProcessName + ': ' + $_.Exception.Message) }
        try { $process.WaitForExit(15000) | Out-Null } catch { }
    }
    Stop-AgentHolders $destination
    Start-Sleep -Milliseconds 700
    $stage = Join-Path $env:LOCALAPPDATA ('TI-Digital\WinUI3-stage-' + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $stage -Force | Out-Null
    Copy-Item -Path (Join-Path $publish '*') -Destination $stage -Recurse -Force
    if (-not (Test-Path (Join-Path $stage $appName))) { throw 'Falha ao preparar os arquivos.' }
    # Nao mexe em tarefas, servicos ou credenciais do agente antigo.
    # Um backup antigo pode estar bloqueado: nao impedir a nova instalacao por isso.
    $previous = $destination + '.anterior-' + [guid]::NewGuid().ToString('N')
    if ($Update -and (Test-Path $destination)) {
        # Evita que a inicializacao agendada relance o WinUI durante a troca de pasta.
        try {
            $scheduled = Get-ScheduledTask -TaskName $taskNameToResume -ErrorAction SilentlyContinue
            if ($scheduled -and $scheduled.State -ne 'Disabled' -and @($scheduled.Actions | Where-Object { ([string]$_.Execute).Trim('"') -eq (Join-Path $destination $appName) }).Count) {
                Disable-ScheduledTask -TaskName $taskNameToResume -ErrorAction Stop | Out-Null
                $taskWasSuspended = $true
                Write-Host 'Tarefa do proprio agente suspensa durante a troca.'
            }
        } catch { Write-Warning ('Nao foi possivel suspender a tarefa do agente: '+$_.Exception.Message) }
    }
    if (Test-Path $destination) {
        $moved = $false
        for ($retry = 0; $retry -lt 6; $retry++) {
            # O WebView2 e o worker de presenca podem reaparecer durante a compilacao: encerrar de novo antes de cada tentativa.
            Stop-AgentHolders $destination
            try { Move-Item -LiteralPath $destination -Destination $previous -ErrorAction Stop; $moved = $true; break }
            catch { Write-Host ('Aguardando liberar a pasta do agente, tentativa ' + ($retry + 1) + '/6: ' + $_.Exception.Message); Start-Sleep -Milliseconds 1200 }
        }
        if ($moved) { $swapped = $true }
        else {
            # Plano B: Explorer aberto na pasta, antivirus ou handle residual impedem RENOMEAR a pasta, mas nao impedem
            # trocar os ARQUIVOS. Copia de seguranca primeiro; qualquer falha restaura a instalacao anterior.
            Write-Host 'A pasta continua ocupada; trocando os arquivos diretamente (instalacao anterior copiada para seguranca).' -ForegroundColor Yellow
            Step 90 'Pasta em uso; trocando arquivos diretamente...'
            $robocopy = Join-Path $env:SystemRoot 'System32\robocopy.exe'
            if (-not (Test-Path -LiteralPath $robocopy)) { throw 'Atualização impedida: pasta em uso e robocopy indisponível. Instalação anterior preservada.' }
            & $robocopy $destination $previous /E /R:2 /W:1 /NFL /NDL /NJH /NJS /NP | Out-Null
            if ($LASTEXITCODE -ge 8) { throw ('Atualização impedida: não foi possível copiar a instalação anterior (robocopy ' + $LASTEXITCODE + '). Instalação anterior preservada.') }
            Stop-AgentHolders $destination
            $inPlace = $true
            & $robocopy $stage $destination /E /IS /IT /R:5 /W:2 /NFL /NDL /NJH /NJS /NP | Out-Null
            if ($LASTEXITCODE -ge 8) { throw ('Falha ao gravar os arquivos do agente (robocopy ' + $LASTEXITCODE + '). Verifique permissões e antivírus.') }
            Remove-Item -LiteralPath $stage -Recurse -Force -ErrorAction SilentlyContinue
            $swapped = $true
        }
    }
    if (-not $inPlace) {
        Move-Item -LiteralPath $stage -Destination $destination
        $swapped = $true
    }
    $target = Join-Path $destination $appName
    Step 93 'Criando atalhos...'
    $brandIcon = Join-Path $destination 'Assets\system.ico'
    $shortcutIcon = $target + ',0'
    if (Test-Path -LiteralPath $brandIcon) {
        $iconHash = (Get-FileHash -LiteralPath $brandIcon -Algorithm SHA256).Hash.Substring(0,16)
        $cachedIcon = Join-Path $destination ('system-' + $iconHash + '.ico')
        Copy-Item -LiteralPath $brandIcon -Destination $cachedIcon -Force
        $shortcutIcon = $cachedIcon + ',0'
    }
    $shell = New-Object -ComObject WScript.Shell
    $programs = Join-Path $env:APPDATA 'Microsoft\Windows\Start Menu\Programs'
    $oldShortcut = Join-Path $programs 'T.I Digital - WinUI3 (Teste).lnk'
    if (Test-Path -LiteralPath $oldShortcut) { Remove-Item -LiteralPath $oldShortcut -Force -ErrorAction SilentlyContinue }
    foreach ($folder in @($programs, [Environment]::GetFolderPath('Desktop'))) {
        if (-not $folder -or -not (Test-Path -LiteralPath $folder)) { continue }
        $link = $shell.CreateShortcut((Join-Path $folder 'T.I Digital - Agente Windows.lnk'))
        $link.TargetPath = $target; $link.WorkingDirectory = $destination; $link.IconLocation = $shortcutIcon
        $link.Description = 'Agente Windows T.I Digital'; $link.Save()
    }
    Step 96 'Configurando execucao em segundo plano...'
    $taskName = 'TI-Digital Agente Windows'
    $runKey = 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Run'
    try {
        $me = [Security.Principal.WindowsIdentity]::GetCurrent().Name
        $action = New-ScheduledTaskAction -Execute $target -Argument '--background' -WorkingDirectory $destination
        $trigger = New-ScheduledTaskTrigger -AtLogOn -User $me
        $principal = New-ScheduledTaskPrincipal -UserId $me -LogonType Interactive -RunLevel Limited
        $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -MultipleInstances IgnoreNew -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit ([TimeSpan]::Zero)
        Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null
        Remove-ItemProperty -Path $runKey -Name 'TIDigitalAgente' -ErrorAction SilentlyContinue
    } catch {
        Write-Host ('Tarefa agendada indisponivel (' + $_.Exception.Message + '); usando a inicializacao do usuario.')
        New-ItemProperty -Path $runKey -Name 'TIDigitalAgente' -Value ('"' + $target + '" --background') -PropertyType String -Force | Out-Null
    }
    # Eleva somente a instalacao do componente de boot; preserva o perfil da interface.
    Step 98 'Verificando componente de presenca antes do login...'
    # Resolve a configuracao ainda no perfil original, antes de eventual UAC
    # com credenciais de outro administrador (mesma prioridade de AgentConfig.Load).
    $effectiveConfig = @{}
    foreach ($cfgPath in @((Join-Path $env:APPDATA 'Arcoll\agent.json'), (Join-Path $destination 'agent-config.json'))) {
        if (-not (Test-Path -LiteralPath $cfgPath)) { continue }
        try {
            $cfgObject = Get-Content -LiteralPath $cfgPath -Raw -Encoding UTF8 | ConvertFrom-Json
            foreach ($field in @('origin','site_code','download_key','fingerprint','system_name')) {
                $value = [string]$cfgObject.$field
                if (-not $effectiveConfig.ContainsKey($field) -and -not [string]::IsNullOrWhiteSpace($value)) { $effectiveConfig[$field] = $value.Trim() }
            }
        } catch { Write-Warning ('Configuracao ignorada: ' + $cfgPath) }
    }
    [IO.File]::WriteAllText((Join-Path $destination 'agent-config.json'), ($effectiveConfig | ConvertTo-Json -Compress), (New-Object Text.UTF8Encoding($false)))
    . (Join-Path $root 'PreLogin-Setup.ps1')
    $elevated = [Security.Principal.WindowsPrincipal]::new([Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
    $bootStatus = Invoke-PreLoginSetup -AgentFolder $destination -LogFolder $logs -Update $Update.IsPresent -Unattended $Unattended.IsPresent -Elevated $elevated
    [IO.File]::WriteAllText((Join-Path $env:LOCALAPPDATA 'TI-Digital\boot-status.json'), ($bootStatus | ConvertTo-Json -Compress), (New-Object Text.UTF8Encoding($false)))
    if ($bootStatus.pending) { Write-Host ('BOOT-PENDING: ' + $bootStatus.message) -ForegroundColor Yellow }
    else { Write-Host $bootStatus.message }
    Step 99 'Iniciando o agente...'
    $readyPath = Join-Path $env:LOCALAPPDATA 'TI-Digital\native-ready.json'
    if ($Update -and (Test-Path -LiteralPath $readyPath)) { Remove-Item -LiteralPath $readyPath -Force -ErrorAction SilentlyContinue }
    $launched = Start-Process -FilePath $target -WorkingDirectory $destination -PassThru
    if (-not $launched) { throw 'A inicializacao do agente falhou.' }
    Start-Sleep -Seconds 3
    $active = Get-Process -Id $launched.Id -ErrorAction SilentlyContinue
    if (-not $active -or $active.HasExited) { throw 'O executavel encerrou imediatamente apos a instalacao.' }
    if ($Update) {
        Step 99 'Validando versao e autorizacao do agente... '
        # Nao basta a janela abrir: a nova instancia precisa estar APROVADA pela
        # plataforma, possuir a mesma identidade e informar a versao publicada.
        $expected = $publishedVersion
        $installedIdentity = Get-Content -LiteralPath (Join-Path $destination 'agent-config.json') -Raw -Encoding UTF8 | ConvertFrom-Json
        $verified = $false
        for ($attempt = 0; $attempt -lt 25; $attempt++) {
            $active = Get-Process -Id $launched.Id -ErrorAction SilentlyContinue
            if (-not $active -or $active.HasExited) { throw 'O agente encerrou antes de validar a autorizacao.' }
            if (Test-Path -LiteralPath $readyPath) {
                try {
                    $receipt = Get-Content -LiteralPath $readyPath -Raw -Encoding UTF8 | ConvertFrom-Json
                    # Instalações antigas podem não guardar o fingerprint em
                    # agent-config.json; nesses casos, o próprio agente o deriva
                    # do MachineGuid e o servidor já validou a identidade.
                    $identityOk = (-not [string]$installedIdentity.fingerprint) -or
                        ([string]$receipt.fingerprint -eq [string]$installedIdentity.fingerprint)
                    if ([int]$receipt.pid -eq $launched.Id -and
                        [string]$receipt.version -eq $expected -and $identityOk -and
                        [int64]$receipt.approved_at -ge ([DateTimeOffset]::UtcNow.ToUnixTimeSeconds() - 100)) {
                        $verified = $true; break
                    }
                } catch { }
            }
            Start-Sleep -Seconds 2
        }
        if (-not $verified) { throw 'O novo agente nao confirmou autorizacao e versao na plataforma em 50 segundos. Revertendo a instalacao.' }
    }
    $successMessage = 'Versao e autorizacao confirmadas pela plataforma; agente atualizado.'
    if ($bootStatus -and $bootStatus.pending) { $successMessage += ' AVISO: ' + $bootStatus.message }
    Write-UpdateResult $true $successMessage
    # Remove sobras de atualizacoes anteriores (copias .anterior/.falhou/.backup/.stage), sem falhar se ainda estiverem ocupadas.
    try {
        $parentFolder = Split-Path -Parent $destination; $leaf = Split-Path -Leaf $destination
        foreach ($left in @(Get-ChildItem -LiteralPath $parentFolder -Directory -ErrorAction SilentlyContinue | Where-Object { $_.Name -like ($leaf + '.*') })) {
            Remove-Item -LiteralPath $left.FullName -Recurse -Force -ErrorAction SilentlyContinue
        }
        foreach ($left in @(Get-ChildItem -LiteralPath $parentFolder -Directory -Filter 'WinUI3-stage-*' -ErrorAction SilentlyContinue)) {
            Remove-Item -LiteralPath $left.FullName -Recurse -Force -ErrorAction SilentlyContinue
        }
    } catch { }
    Write-Host "Instalado em $destination" -ForegroundColor Green
    Step 100 'Instalacao finalizada.'
} catch {
    $failure = $_.Exception.Message
    if ($inPlace -and $previous -and (Test-Path -LiteralPath $previous)) {
        try {
            $robocopy = Join-Path $env:SystemRoot 'System32\robocopy.exe'
            Stop-AgentHolders $destination
            & $robocopy $previous $destination /E /IS /IT /R:5 /W:2 /NFL /NDL /NJH /NJS /NP | Out-Null
            Write-Host 'Reversao: versao anterior restaurada.' -ForegroundColor Yellow
            $failure += ' A versao anterior foi restaurada.'
        } catch { $failure += ' Falha na reversao automatica: ' + $_.Exception.Message }
    } elseif ($swapped -and $previous -and (Test-Path -LiteralPath $previous)) {
        try {
            $failedDestination = $destination + '.falhou-' + (Get-Date -Format 'yyyyMMddHHmmss')
            Get-Process -Name 'Arcoll.WinUI3' -ErrorAction SilentlyContinue |
                Where-Object { $_.Path -eq (Join-Path $destination $appName) } |
                Stop-Process -Force -ErrorAction SilentlyContinue
            if (Test-Path -LiteralPath $destination) { Move-Item -LiteralPath $destination -Destination $failedDestination }
            Move-Item -LiteralPath $previous -Destination $destination
            $restored = Join-Path $destination $appName
            if (Test-Path -LiteralPath $restored) { Start-Process -FilePath $restored -ArgumentList '--background' -WorkingDirectory $destination }
            Write-Host 'Reversao: versao anterior restaurada.' -ForegroundColor Yellow
            $failure += ' A versao anterior foi restaurada.'
        } catch { $failure += ' Falha na reversao automatica: ' + $_.Exception.Message }
    } elseif ($Update -and $swapped -and $previous -and -not (Test-Path -LiteralPath $previous)) {
        # Primeira migracao Python -> WinUI: nao existia uma instalacao nativa
        # anterior. Retire somente a nova instancia incompleta. O atualizador
        # Python reiniciara o agente antigo preservado quando receber este erro.
        try {
            if ($launched) { Stop-Process -Id $launched.Id -Force -ErrorAction SilentlyContinue }
            $nativeTask = Get-ScheduledTask -TaskName 'TI-Digital Agente Windows' -ErrorAction SilentlyContinue
            if ($nativeTask -and @($nativeTask.Actions | Where-Object { $_.Execute -eq (Join-Path $destination $appName) }).Count) {
                Unregister-ScheduledTask -TaskName 'TI-Digital Agente Windows' -Confirm:$false -ErrorAction SilentlyContinue
            }
            Remove-ItemProperty -Path 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Run' -Name 'TIDigitalAgente' -ErrorAction SilentlyContinue
            if (Test-Path -LiteralPath $destination) { Remove-Item -LiteralPath $destination -Recurse -Force -ErrorAction Stop }
            $failure += ' Nova instalacao descartada; agente anterior disponivel.'
        } catch { $failure += ' Falha na limpeza do nativo incompleto: ' + $_.Exception.Message }
    }
    # Nunca deixar o computador sem agente: se a atualizacao falhou e nenhuma instancia esta ativa, reabre a instalacao existente.
    if ($Update) {
        try {
            $oldExe = Join-Path $destination $appName
            $alive = @(Get-Process -Name 'Arcoll.WinUI3' -ErrorAction SilentlyContinue | Where-Object { try { $_.Path -eq $oldExe } catch { $false } })
            if (-not $alive.Count -and (Test-Path -LiteralPath $oldExe)) {
                Start-Process -FilePath $oldExe -ArgumentList '--background' -WorkingDirectory $destination | Out-Null
                $failure += ' O agente anterior foi reaberto.'
            }
        } catch { }
    }
    Write-UpdateResult $false $failure
    Write-Host ('ERRO: ' + $failure) -ForegroundColor Red
    Write-Host ('Diagnostico: ' + $log)
    exit 1
} finally {
    if ($taskWasSuspended) {
        try { Enable-ScheduledTask -TaskName $taskNameToResume -ErrorAction Stop | Out-Null }
        catch { Write-Warning ('Reative manualmente a tarefa do agente: '+$_.Exception.Message) }
    }
    if ($publish -and (Test-Path -LiteralPath $publish)) { Remove-Item -LiteralPath $publish -Recurse -Force -ErrorAction SilentlyContinue }
    Stop-Transcript | Out-Null
}
