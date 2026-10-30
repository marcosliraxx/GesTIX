param(
    [switch]$Run,
    [switch]$Background
)

$ErrorActionPreference = 'Stop'
$origin = if ($env:ARCOLL_ORIGIN) { $env:ARCOLL_ORIGIN.TrimEnd('/') } else { '' }
$base = $PSScriptRoot
$configPath = Join-Path $base 'agent-config.json'
if (Test-Path -LiteralPath $configPath) {
    try {
        $installedConfig = Get-Content -LiteralPath $configPath -Raw -Encoding UTF8 | ConvertFrom-Json
        $configuredOrigin = ([string]$installedConfig.origin).TrimEnd('/')
        if ($configuredOrigin -match '^https://[^/\s]+(?:/.*)?$') { $origin = $configuredOrigin }
    } catch { }
}
$desktopApp = Join-Path $base 'arcoll_desktop.py'
$fullApp = Join-Path $base 'arcoll_app.py'
$agentService = Join-Path $base 'arcoll_agent.py'
$stateDir = Join-Path $env:LOCALAPPDATA 'Arcoll'
$logPath = Join-Path $stateDir 'agent-install.log'
New-Item -ItemType Directory -Force -Path $stateDir | Out-Null

function Write-Log([string]$Message) {
    try { Add-Content -LiteralPath $logPath -Value ("[{0}] {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message) -Encoding UTF8 } catch {}
}
function Show-Error([string]$Message) {
    Write-Log $Message
    try {
        Add-Type -AssemblyName System.Windows.Forms
        [System.Windows.Forms.MessageBox]::Show(
            $Message + "`r`n`r`nRegistro técnico: " + $logPath,
            'Arcoll Speed Connection',
            [System.Windows.Forms.MessageBoxButtons]::OK,
            [System.Windows.Forms.MessageBoxIcon]::Error
        ) | Out-Null
    } catch {}
}
function Test-PythonCandidate([string]$Exe) {
    # Valida o interpretador de verdade: executa, exige Python >= 3.8 e tkinter (usado pela interface).
    if (-not $Exe -or -not (Test-Path -LiteralPath $Exe -PathType Leaf)) { return $null }
    if ($Exe -match '(?i)\\WindowsApps\\') { return $null }   # atalho da Microsoft Store, não é Python real
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $out = & $Exe -c "import sys, tkinter; print(sys.executable); print(sys.version_info[0]*100+sys.version_info[1])" 2>$null
        if ($LASTEXITCODE -ne 0 -or -not $out) { return $null }
        $lines = @($out | ForEach-Object { $_.ToString().Trim() } | Where-Object { $_ })
        if ($lines.Count -lt 2) { return $null }
        if ([int]$lines[-1] -lt 308) { return $null }
        $resolved = $lines[-2]
        if (-not (Test-Path -LiteralPath $resolved)) { return $null }
        $pythonw = Join-Path (Split-Path -Parent $resolved) 'pythonw.exe'
        if (-not (Test-Path -LiteralPath $pythonw)) { $pythonw = $resolved }
        return @{ Python = $resolved; PythonW = $pythonw }
    } catch { return $null } finally { $ErrorActionPreference = $previous }
}
function Get-PythonRuntime {
    $candidates = New-Object System.Collections.Generic.List[string]

    # 1) Python instalado anteriormente pelo próprio Arcoll
    $candidates.Add((Join-Path $stateDir 'Python\python.exe'))
    $candidates.Add((Join-Path $base 'Python\python.exe'))

    # 2) Launcher "py"
    foreach ($launcherPath in @((Join-Path $env:WINDIR 'py.exe'), (Join-Path $env:LOCALAPPDATA 'Programs\Python\Launcher\py.exe'))) {
        if (Test-Path -LiteralPath $launcherPath) {
            try {
                $previous = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
                $r = (& $launcherPath -3 -c "import sys; print(sys.executable)" 2>$null | Select-Object -Last 1)
                $ErrorActionPreference = $previous
                if ($r) { $candidates.Add($r.ToString().Trim()) }
            } catch { $ErrorActionPreference = 'Stop' }
        }
    }

    # 3) Registro do Windows (PEP 514) - instalações por usuário e para todos os usuários
    foreach ($hive in @('HKCU:\SOFTWARE\Python\PythonCore','HKLM:\SOFTWARE\Python\PythonCore','HKLM:\SOFTWARE\WOW6432Node\Python\PythonCore')) {
        if (-not (Test-Path $hive)) { continue }
        foreach ($ver in (Get-ChildItem $hive -ErrorAction SilentlyContinue)) {
            $ip = Join-Path $ver.PSPath 'InstallPath'
            if (-not (Test-Path $ip)) { continue }
            try {
                $props = Get-ItemProperty -Path $ip -ErrorAction Stop
                if ($props.ExecutablePath) { $candidates.Add([string]$props.ExecutablePath) }
                if ($props.'(default)') { $candidates.Add((Join-Path ([string]$props.'(default)') 'python.exe')) }
            } catch {}
        }
    }

    # 4) Pastas padrão
    foreach ($root in @((Join-Path $env:LOCALAPPDATA 'Programs\Python'), $env:ProgramFiles, ${env:ProgramFiles(x86)}, 'C:\')) {
        if (-not $root -or -not (Test-Path -LiteralPath $root)) { continue }
        Get-ChildItem -LiteralPath $root -Directory -Filter 'Python3*' -ErrorAction SilentlyContinue |
            Sort-Object Name -Descending | ForEach-Object { $candidates.Add((Join-Path $_.FullName 'python.exe')) }
    }

    # 5) PATH
    foreach ($name in @('python.exe','python3.exe')) {
        Get-Command $name -All -ErrorAction SilentlyContinue | ForEach-Object { if ($_.Source) { $candidates.Add($_.Source) } }
    }

    $seen = @{}
    foreach ($exe in $candidates) {
        if (-not $exe) { continue }
        $key = $exe.ToLowerInvariant()
        if ($seen.ContainsKey($key)) { continue }
        $seen[$key] = $true
        $runtime = Test-PythonCandidate $exe
        if ($runtime) { Write-Log "Python encontrado: $($runtime.Python)"; return $runtime }
    }
    return $null
}
function Invoke-PythonSetup([string]$Setup, [string]$Arguments, [string]$Label) {
    Write-Log "Python setup ($Label): $Arguments"
    $p = Start-Process -FilePath $Setup -ArgumentList $Arguments -Wait -PassThru -WindowStyle Hidden
    Write-Log "Python setup ($Label) terminou com código $($p.ExitCode)."
    return $p.ExitCode
}
function Install-PythonRuntime {
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    $arch = if ([Environment]::Is64BitOperatingSystem) { 'amd64' } else { 'win32' }
    $installerUrl = "https://www.python.org/ftp/python/3.12.10/python-3.12.10-$arch.exe"
    $installerPath = Join-Path $env:TEMP 'arcoll-python-3.12.10-setup.exe'
    $pythonHome = Join-Path $stateDir 'Python'

    $downloaded = $false
    for ($try = 1; $try -le 3 -and -not $downloaded; $try++) {
        try {
            if (Test-Path -LiteralPath $installerPath) { Remove-Item -LiteralPath $installerPath -Force -ErrorAction SilentlyContinue }
            Write-Log "Baixando Python (tentativa $try): $installerUrl"
            $ProgressPreference = 'SilentlyContinue'   # acelera muito o Invoke-WebRequest no PS 5.1
            Invoke-WebRequest -Uri $installerUrl -OutFile $installerPath -UseBasicParsing -TimeoutSec 300
            if ((Test-Path -LiteralPath $installerPath) -and (Get-Item -LiteralPath $installerPath).Length -gt 1000000) { $downloaded = $true }
        } catch {
            Write-Log ("Falha no download: " + $_.Exception.Message)
            try { Start-BitsTransfer -Source $installerUrl -Destination $installerPath -ErrorAction Stop
                  if ((Get-Item -LiteralPath $installerPath).Length -gt 1000000) { $downloaded = $true } } catch { Write-Log ("BITS falhou: " + $_.Exception.Message) }
        }
    }
    if (-not $downloaded) { throw 'O instalador do Python não foi baixado corretamente. Verifique a conexão com a internet (acesso a python.org) e execute novamente.' }

    $common = 'InstallAllUsers=0 Include_launcher=1 Include_pip=1 Include_tcltk=1 Include_test=0 Include_doc=0 PrependPath=0 Shortcuts=0 AssociateFiles=0'
    $setupLog = Join-Path $stateDir 'python-setup.log'

    # Tentativa 1: instalação limpa por usuário.
    $code = Invoke-PythonSetup $installerPath ("/quiet /log `"$setupLog`" $common TargetDir=`"$pythonHome`"") 'instalação'
    $runtime = Get-PythonRuntime
    if ($runtime) { return $runtime }

    # Tentativa 2: registro órfão de uma instalação anterior que falhou faz o setup "não fazer nada" com código 0.
    # Remove o registro/instalação anterior da mesma versão e instala de novo.
    Write-Log 'Python ausente após a instalação. Removendo registro anterior e tentando novamente.'
    [void](Invoke-PythonSetup $installerPath ("/quiet /uninstall /log `"$stateDir\python-uninstall.log`"") 'remoção')
    $code = Invoke-PythonSetup $installerPath ("/quiet /log `"$setupLog`" $common TargetDir=`"$pythonHome`"") 'reinstalação'
    $runtime = Get-PythonRuntime
    if ($runtime) { return $runtime }

    # Tentativa 3: instalação para todos os usuários (esta etapa roda como administrador).
    $common3 = $common -replace 'InstallAllUsers=0','InstallAllUsers=1'
    $pf = Join-Path $env:ProgramFiles 'Python312'
    $code = Invoke-PythonSetup $installerPath ("/quiet /log `"$setupLog`" $common3 TargetDir=`"$pf`"") 'instalação para todos os usuários'
    $runtime = Get-PythonRuntime
    if ($runtime) { return $runtime }

    # Tentativa 4: winget, se existir.
    $winget = Get-Command winget.exe -ErrorAction SilentlyContinue
    if ($winget) {
        Write-Log 'Tentando instalar Python via winget.'
        try {
            $p = Start-Process -FilePath $winget.Source -ArgumentList 'install -e --id Python.Python.3.12 --silent --accept-package-agreements --accept-source-agreements' -Wait -PassThru -WindowStyle Hidden
            Write-Log "winget terminou com código $($p.ExitCode)."
        } catch { Write-Log ("winget falhou: " + $_.Exception.Message) }
        $runtime = Get-PythonRuntime
        if ($runtime) { return $runtime }
    }
    throw "Não foi possível instalar o Python automaticamente (último código $code). Instale o Python 3.12 manualmente em https://www.python.org/downloads/ (marque 'tcl/tk and IDLE') e execute o instalador novamente. Detalhes: $setupLog"
}
function Start-ArcollApp($Runtime) {
    $pythonw = [string]$Runtime.PythonW
    if (-not (Test-Path -LiteralPath $pythonw)) { throw "Interpretador Python não encontrado: $pythonw" }
    if (-not (Test-Path -LiteralPath $desktopApp)) { throw "Arquivo ausente: $desktopApp" }
    if (-not (Test-Path -LiteralPath $agentService)) { throw "Arquivo ausente: $agentService" }
    $arguments = '"' + $desktopApp + '" --origin "' + $origin + '"' + $(if ($Background) { ' --background' } else { '' })
    $guiProcess = Start-Process -FilePath $pythonw -ArgumentList $arguments -WorkingDirectory $base -WindowStyle Hidden -PassThru
    Start-Sleep -Milliseconds 1500
    $guiProcess.Refresh()
    if ($guiProcess.HasExited -and $guiProcess.ExitCode -ne 0) {
        throw "A interface do agente encerrou durante a inicialização (código $($guiProcess.ExitCode)). Consulte $stateDir\agent-runtime.log."
    }
    Write-Log "Interface iniciada. Runtime: $pythonw; PID: $($guiProcess.Id)"
}

try {
    Write-Log ("Início. Modo Run={0}; pasta={1}" -f $Run.IsPresent, $base)

    if (-not $origin.StartsWith('https://', [System.StringComparison]::OrdinalIgnoreCase)) {
        throw 'O endereço HTTPS do sistema não foi configurado. Baixe o agente novamente pela página Redes.'
    }
    if (-not (Test-Path -LiteralPath $desktopApp)) { throw "Arquivo arcoll_desktop.py ausente. Extraia novamente o ZIP completo: $desktopApp" }
    if (-not (Test-Path -LiteralPath $fullApp)) { throw "Aplicativo desktop completo ausente: $fullApp. Baixe novamente o pacote do agente." }
    if (-not (Test-Path -LiteralPath $agentService)) { throw "Arquivo arcoll_agent.py ausente. Extraia novamente o ZIP completo: $agentService" }

    $runtime = Get-PythonRuntime
    if (-not $runtime) {
        Write-Log 'Python 3 não localizado. Preparando instalação silenciosa do runtime.'
        $runtime = Install-PythonRuntime
        Write-Log "Python pronto: $($runtime.Python)"
    }

    # Dependências opcionais para o ícone na área de notificação do Windows.
    if (-not $Run) { $previousEap = $ErrorActionPreference; $ErrorActionPreference = 'Continue'; try {
        & $runtime.Python -m pip --version *> $null
        if ($LASTEXITCODE -ne 0) { & $runtime.Python -m ensurepip --upgrade *> $null }
        & $runtime.Python -m pip install --disable-pip-version-check --quiet psutil pystray Pillow *> $null
        if ($LASTEXITCODE -ne 0) { Write-Log 'Bandeja: dependências não instaladas; aplicativo continua funcional.' }
    } catch { Write-Log ('Bandeja: ' + $_.Exception.Message) } finally { $ErrorActionPreference = $previousEap } }

    if (-not $Run) {
        $desktop = [Environment]::GetFolderPath('Desktop')
        $shortcutPath = Join-Path $desktop 'Arcoll Speed Connection.lnk'
        $ws = New-Object -ComObject WScript.Shell
        $link = $ws.CreateShortcut($shortcutPath)
        $link.TargetPath = 'powershell.exe'
        $link.Arguments = '-NoLogo -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + (Join-Path $base 'Instalar_Arcoll.ps1') + '" -Run' 
        $link.WorkingDirectory = $base
        $link.WindowStyle = 7
        $link.Description = 'Agente de rede Arcoll Speed Connection'
        $iconPath = Join-Path $base 'favicon.ico'
        # O nome versionado invalida o cache de ícones do Explorer após atualizar a logo.
        if (Test-Path -LiteralPath $iconPath) {
            $hash = (Get-FileHash -LiteralPath $iconPath -Algorithm SHA256).Hash.Substring(0,12)
            $shortcutIcon = Join-Path $base ('Arcoll-Atalho-' + $hash + '.ico')
            if (-not (Test-Path -LiteralPath $shortcutIcon)) { Copy-Item -LiteralPath $iconPath -Destination $shortcutIcon -Force }
            $iconPath = $shortcutIcon
            $link.IconLocation = $iconPath + ',0'
        }
        $link.Save()
        Write-Log "Atalho criado em $shortcutPath"
        # Inicialização automática por usuário, sem exigir privilégios administrativos.
        $startup = [Environment]::GetFolderPath('Startup')
        if ($startup) {
            $autoLink = $ws.CreateShortcut((Join-Path $startup 'Arcoll Speed Connection.lnk'))
            $autoLink.TargetPath = $link.TargetPath
             $autoLink.Arguments = '-NoLogo -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + (Join-Path $base 'Instalar_Arcoll.ps1') + '" -Run -Background'
            $autoLink.WorkingDirectory = $base
            $autoLink.Description = 'Inicialização do Arcoll Speed Connection'
            if (Test-Path -LiteralPath $iconPath) { $autoLink.IconLocation = $iconPath + ',0' }
            $autoLink.Save()
            Write-Log 'Inicialização automática configurada para o usuário atual.'
        }

    }

    if ($Run) {
        # Garante o psutil (métricas da aba Servidor) em instalações antigas; o agente também funciona sem ele.
        try {
            & $runtime.Python -c "import psutil" *> $null
            if ($LASTEXITCODE -ne 0) {
                Write-Log 'psutil ausente. Instalando em segundo plano para a aba Servidor.'
                Start-Process -FilePath $runtime.Python -ArgumentList '-m pip install --disable-pip-version-check --quiet psutil' -WindowStyle Hidden | Out-Null
            }
        } catch { Write-Log ('psutil: ' + $_.Exception.Message) }
    }

    Start-ArcollApp $runtime
} catch {
    Show-Error ("Não foi possível iniciar o Agente Arcoll.`r`n" + $_.Exception.Message)
    exit 1
}
exit 0