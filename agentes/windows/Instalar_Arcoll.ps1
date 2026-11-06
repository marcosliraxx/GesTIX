# Caminho: agentes/windows/Instalar_Arcoll.ps1
param(
    [switch]$Run,
    [switch]$Background,
    [switch]$Repair
)

$ErrorActionPreference = 'Stop'
if ($Repair) { $Background=$true; $Run=$false }
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
$fullApp = Join-Path $base 'arcoll_app.py'
$agentService = Join-Path $base 'arcoll_agent.py'
$stateDir = Join-Path $env:LOCALAPPDATA 'Arcoll'
$logPath = Join-Path $stateDir 'agent-install.log'
$systemName = 'Agente de Rede'
$systemDescription = 'Monitoramento de redes'
New-Item -ItemType Directory -Force -Path $stateDir | Out-Null
if (Test-Path -LiteralPath $configPath) {
    try {
        if ($installedConfig.system_name) { $systemName = [string]$installedConfig.system_name }
        if ($installedConfig.system_description) { $systemDescription = [string]$installedConfig.system_description }
    } catch {}
}

function Write-Log([string]$Message) {
    try { Add-Content -LiteralPath $logPath -Value ("[{0}] {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message) -Encoding UTF8 } catch {}
}
function Show-Error([string]$Message) {
    Write-Log $Message
    try {
        Add-Type -AssemblyName System.Windows.Forms
        [System.Windows.Forms.MessageBox]::Show(
            $Message + "`r`n`r`nRegistro técnico: " + $logPath,
            $systemName,
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
    # Verificar a assinatura antes de executar um instalador obtido pela rede.
    $signature = Get-AuthenticodeSignature -LiteralPath $installerPath
    if ($signature.Status -ne 'Valid' -or -not $signature.SignerCertificate -or
        $signature.SignerCertificate.Subject -notmatch 'Python Software Foundation') {
        throw 'Assinatura digital do instalador do Python ausente ou inválida. Instalação interrompida.'
    }


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
function Test-Elevated {
    ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}
function Set-ProtectedAcl([string]$Path, [string[]]$Grants) {
    # Usa SIDs conhecidos (SYSTEM, Administradores, Usuários, CREATOR OWNER): independe do idioma do Windows.
    $icaclsArgs = @($Path, '/inheritance:r', '/grant:r') + $Grants
    & icacls.exe @icaclsArgs | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "Não foi possível proteger $Path (icacls código $LASTEXITCODE)." }
}
function Install-SecureDesktopHelper($Runtime) {
    # Componente SYSTEM que permite ver e digitar na tela de bloqueio/login/UAC durante o acesso remoto.
    # Nunca roda a partir de C:\Arcoll nem de um Python por usuário: ambos são graváveis por usuários comuns,
    # o que permitiria escalar privilégios. Código e interpretador ficam em %ProgramFiles% (somente administradores).
    $taskName = 'ArcollSecureDesktop'
    # Retira somente o supervisor antigo substituído pelo componente oficial.
    $legacy=Get-ScheduledTask -TaskPath '\Arcoll\' -TaskName 'Session Helper' -ErrorAction SilentlyContinue
    if ($legacy -and @($legacy.Actions | Where-Object { $_.Arguments -match 'arcoll_session\.py.*--supervisor' }).Count) {
        Stop-ScheduledTask -InputObject $legacy -ErrorAction SilentlyContinue
        Unregister-ScheduledTask -InputObject $legacy -Confirm:$false
    }
    $obsolete=Join-Path $base 'arcoll_session.py'
    if (Test-Path -LiteralPath $obsolete -PathType Leaf) { Remove-Item -LiteralPath $obsolete -Force }

    $secureRoot = Join-Path $env:ProgramFiles 'Arcoll\SecureDesktop'
    $runtimeRoot = Join-Path $env:ProgramFiles 'Arcoll\Runtime'
    $dataRoot = Join-Path $env:ProgramData 'Arcoll'
    $system = '*S-1-5-18'; $admins = '*S-1-5-32-544'; $users = '*S-1-5-32-545'; $owner = '*S-1-3-4'
    foreach ($name in @('arcoll_remote.py', 'arcoll_secure_helper.py','arcoll_release.py')) {
        if (-not (Test-Path -LiteralPath (Join-Path $base $name))) { throw "Arquivo ausente: $name" }
    }
    if (-not $origin.StartsWith('https://', [System.StringComparison]::OrdinalIgnoreCase)) { throw 'Origem HTTPS não configurada.' }

    # 1) Encerra a versão anterior do componente.
    try { Stop-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue } catch {}
    Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
        $_.CommandLine -and $_.CommandLine -match '(?i)arcoll_secure_helper\.py'
    } | ForEach-Object { try { Stop-Process -Id $_.ProcessId -Force -ErrorAction Stop } catch {} }

    # 2) Pastas protegidas (herança removida; novos arquivos herdam somente leitura/execução para usuários).
    foreach ($dir in @($secureRoot, $runtimeRoot)) {
        New-Item -ItemType Directory -Force -Path $dir | Out-Null
        Set-ProtectedAcl $dir @("${system}:(OI)(CI)F", "${admins}:(OI)(CI)F", "${users}:(OI)(CI)RX")
    }

    # 3) Python embarcado protegido (sem instalador, sem conflito com o Python do usuário) + Pillow.
    $pythonw = Join-Path $runtimeRoot 'pythonw.exe'
    $sitePackages = Join-Path $runtimeRoot 'Lib\site-packages'
    if (-not ((Test-Path -LiteralPath $pythonw) -and (Test-Path -LiteralPath (Join-Path $sitePackages 'PIL')))) {
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
        $arch = if ([Environment]::Is64BitOperatingSystem) { 'amd64' } else { 'win32' }
        $platformTag = if ($arch -eq 'amd64') { 'win_amd64' } else { 'win32' }
        $zip = Join-Path $env:TEMP "arcoll-python-embed-$arch.zip"
        $ProgressPreference = 'SilentlyContinue'
        Invoke-WebRequest -Uri "https://www.python.org/ftp/python/3.12.10/python-3.12.10-embed-$arch.zip" -OutFile $zip -UseBasicParsing -TimeoutSec 300
        if ((Get-Item -LiteralPath $zip).Length -lt 3000000) { throw 'O Python embarcado não foi baixado corretamente.' }
        Expand-Archive -LiteralPath $zip -DestinationPath $runtimeRoot -Force
        Remove-Item -LiteralPath $zip -Force -ErrorAction SilentlyContinue
        New-Item -ItemType Directory -Force -Path $sitePackages | Out-Null
        # Caminhos relativos ao arquivo ._pth: o código do componente fica em ..\SecureDesktop.
        Set-Content -LiteralPath (Join-Path $runtimeRoot 'python312._pth') -Value @('python312.zip', '.', 'Lib\site-packages', '..\SecureDesktop', 'import site') -Encoding ASCII
        $previous = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
        try {
            & $Runtime.Python -m pip install --disable-pip-version-check --quiet --only-binary=:all: --implementation cp --python-version 3.12 --abi cp312 --platform $platformTag --target $sitePackages Pillow *> $null
            $pipCode = $LASTEXITCODE
        } finally { $ErrorActionPreference = $previous }
        if ($pipCode -ne 0 -or -not (Test-Path -LiteralPath (Join-Path $sitePackages 'PIL'))) { throw "Pillow não foi instalado no runtime protegido (pip código $pipCode)." }
    }

    # 4) Código do componente e origem HTTPS (gravados em local protegido).
    foreach ($name in @('arcoll_remote.py', 'arcoll_secure_helper.py','arcoll_release.py')) {
        Copy-Item -LiteralPath (Join-Path $base $name) -Destination (Join-Path $secureRoot $name) -Force
    }
    [IO.File]::WriteAllText((Join-Path $secureRoot 'helper-config.json'), (@{ origin = $origin } | ConvertTo-Json -Compress), (New-Object System.Text.UTF8Encoding($false)))

    # 5) Pastas de dados: usuários criam apenas o próprio pedido (sem listar/ler os de outros); status é somente leitura.
    $requests = Join-Path $dataRoot 'SecureRequests'; $status = Join-Path $dataRoot 'SecureStatus'; $logs = Join-Path $dataRoot 'Logs'
    foreach ($dir in @($dataRoot, $requests, $status, $logs)) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }
    Set-ProtectedAcl $dataRoot @("${system}:(OI)(CI)F", "${admins}:(OI)(CI)F", "${users}:(X)")
    Set-ProtectedAcl $requests @("${system}:(OI)(CI)F", "${admins}:(OI)(CI)F", "${owner}:(OI)(CI)(IO)M", "${users}:(X,WD,AD,RA)")
    Set-ProtectedAcl $status @("${system}:(OI)(CI)F", "${admins}:(OI)(CI)F", "${users}:(OI)(CI)RX")
    Set-ProtectedAcl $logs @("${system}:(OI)(CI)F", "${admins}:(OI)(CI)F")
    Get-ChildItem -LiteralPath $requests -Force -ErrorAction SilentlyContinue | Remove-Item -Force -ErrorAction SilentlyContinue

    # 5b) Ctrl+Alt+Del remoto: o Windows só aceita SendSAS de serviços (SoftwareSASGeneration 1 ou 3).
    try {
        $policyKey = 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System'
        $sasPolicy = (Get-ItemProperty -Path $policyKey -Name 'SoftwareSASGeneration' -ErrorAction SilentlyContinue).SoftwareSASGeneration
        if ($sasPolicy -notin 1, 3) {
            New-ItemProperty -Path $policyKey -Name 'SoftwareSASGeneration' -PropertyType DWord -Value 1 -Force | Out-Null
            Write-Log 'Política SoftwareSASGeneration definida como 1 (Ctrl+Alt+Del remoto).'
        }
    } catch { Write-Log ('Política SoftwareSASGeneration não ajustada: ' + $_.Exception.Message) }

    # 6) Tarefa SYSTEM na inicialização do Windows (reinicia sozinha em caso de falha).
    $action = New-ScheduledTaskAction -Execute $pythonw -Argument ('"' + (Join-Path $secureRoot 'arcoll_secure_helper.py') + '" --broker') -WorkingDirectory $secureRoot
    $trigger = New-ScheduledTaskTrigger -AtStartup
    $principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
    $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 99 -RestartInterval (New-TimeSpan -Minutes 1)
    Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description 'Arcoll: acesso remoto à tela de bloqueio/login do Windows' -Force | Out-Null
    Start-ScheduledTask -TaskName $taskName
    Write-Log "Componente de tela segura instalado em $secureRoot (tarefa $taskName)."
}
# BLOCO 01 - NÚCLEO LOCAL SEM SESSÃO GRÁFICA
function Install-HeadlessCore {
    # O núcleo local só expõe a API na interface loopback e usa conta de serviço limitada.
    # Nenhuma rotina Tkinter/desktop é lançada na sessão 0.
    if (-not (Test-Elevated)) { Write-Log 'Núcleo pré-login: requer instalação administrativa.'; return }
    $taskName = 'ArcollLocalCore'
    $coreRoot = Join-Path $env:ProgramFiles 'Arcoll\LocalCore'
    $runtimeExe = Join-Path $env:ProgramFiles 'Arcoll\Runtime\pythonw.exe'
    if (-not (Test-Path -LiteralPath $runtimeExe -PathType Leaf)) {
        Write-Log 'Núcleo pré-login: runtime protegido indisponível. Não será criado um serviço incompleto.'
        return
    }
    New-Item -ItemType Directory -Force -Path $coreRoot | Out-Null
    $system = '*S-1-5-18'; $admins = '*S-1-5-32-544'; $localService = '*S-1-5-19'
    # A pasta contém a chave revogável do agente: não conceder leitura a usuários comuns.
    Set-ProtectedAcl $coreRoot @("${system}:(OI)(CI)F", "${admins}:(OI)(CI)F", "${localService}:(OI)(CI)RX")
    Copy-Item -LiteralPath (Join-Path $base 'arcoll_release.py') -Destination (Join-Path $coreRoot 'arcoll_release.py') -Force
    Copy-Item -LiteralPath $agentService -Destination (Join-Path $coreRoot 'arcoll_agent.py') -Force
    $agentConfig = Join-Path $base 'agent-config.json'
    if (-not (Test-Path -LiteralPath $agentConfig -PathType Leaf)) {
        throw 'Configuração de identidade do agente ausente; núcleo pré-login não instalado.'
    }
    $identity = Get-Content -LiteralPath $agentConfig -Raw -Encoding UTF8 | ConvertFrom-Json
    if (([string]$identity.origin) -notmatch '^https://' -or
        ([string]$identity.site_code) -notmatch '^[A-Za-z0-9]{5}$' -or
        ([string]$identity.download_key) -notmatch '^[a-fA-F0-9]{64}$') {
        throw 'Configuração de identidade incompleta; núcleo pré-login não instalado.'
    }
    Copy-Item -LiteralPath $agentConfig -Destination (Join-Path $coreRoot 'agent-config.json') -Force
    $action = New-ScheduledTaskAction -Execute $runtimeExe -Argument ('"' + (Join-Path $coreRoot 'arcoll_agent.py') + '" --origin "' + $origin + '"') -WorkingDirectory $coreRoot
    $trigger = New-ScheduledTaskTrigger -AtStartup
    $principal = New-ScheduledTaskPrincipal -UserId 'NT AUTHORITY\LOCAL SERVICE' -LogonType ServiceAccount -RunLevel Limited
    $settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -MultipleInstances IgnoreNew -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 20 -RestartInterval (New-TimeSpan -Minutes 1)
    Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description 'Arcoll: API local sem interface, iniciada antes do login; porta loopback.' -Force | Out-Null
    Start-ScheduledTask -TaskName $taskName -ErrorAction Stop
    Write-Log "Núcleo pré-login configurado: $taskName (LOCAL SERVICE, loopback 127.0.0.1)."
}

# BLOCO 02 - WinFsp: driver do disco virtual da nuvem (a unidade é criada pelo próprio agente, sem WebClient)
function Test-WinFspInstalled {
    foreach ($key in @('HKLM:\SOFTWARE\WOW6432Node\WinFsp', 'HKLM:\SOFTWARE\WinFsp')) {
        $dir = (Get-ItemProperty -Path $key -Name InstallDir -ErrorAction SilentlyContinue).InstallDir
        if ($dir -and (Test-Path -LiteralPath (Join-Path $dir 'bin'))) {
            if (Get-ChildItem -LiteralPath (Join-Path $dir 'bin') -Filter 'winfsp-*.dll' -ErrorAction SilentlyContinue) { return $true }
        }
    }
    return $false
}

function Install-WinFsp {
    if (-not (Test-Elevated)) { Write-Log 'WinFsp: instalação requer administrador; o disco virtual usará a reserva WebDAV.'; return }
    if (Test-WinFspInstalled) { return }
    $msi = Join-Path $env:TEMP 'arcoll-winfsp.msi'
    try {
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
        $ProgressPreference = 'SilentlyContinue'
        Write-Log 'WinFsp ausente. Baixando a versão estável mais recente.'
        $release = Invoke-RestMethod -Uri 'https://api.github.com/repos/winfsp/winfsp/releases/latest' -Headers @{ 'User-Agent' = 'ArcollSetup' } -UseBasicParsing -TimeoutSec 60
        $asset = $release.assets | Where-Object { $_.name -match '^winfsp-.*\.msi$' } | Select-Object -First 1
        if (-not $asset) { throw 'MSI do WinFsp não encontrado na versão publicada.' }
        Invoke-WebRequest -Uri ([string]$asset.browser_download_url) -OutFile $msi -UseBasicParsing -TimeoutSec 300
        if ((Get-Item -LiteralPath $msi).Length -lt 500000) { throw 'O instalador do WinFsp não foi baixado corretamente.' }
        $signature = Get-AuthenticodeSignature -LiteralPath $msi
        if ($signature.Status -ne 'Valid' -or $signature.SignerCertificate.Subject -notmatch 'Navimatics') {
            throw 'Assinatura digital do WinFsp ausente ou inválida. Instalação interrompida.'
        }
        $process = Start-Process -FilePath 'msiexec.exe' -ArgumentList @('/i', ('"' + $msi + '"'), '/qn', '/norestart', '/l*v', ('"' + (Join-Path $stateDir 'winfsp-setup.log') + '"')) -Wait -PassThru
        Write-Log ("WinFsp: msiexec terminou com código {0}." -f $process.ExitCode)
        if (-not (Test-WinFspInstalled)) { Write-Log 'WinFsp não ficou registrado; o disco virtual usará a reserva WebDAV.' }
    } catch {
        Write-Log ('WinFsp: ' + $_.Exception.Message + ' O disco virtual usará a reserva WebDAV.')
    } finally {
        Remove-Item -LiteralPath $msi -Force -ErrorAction SilentlyContinue
    }
}

function Start-ArcollApp($Runtime) {
    $pythonw = [string]$Runtime.PythonW
    if (-not (Test-Path -LiteralPath $pythonw)) { throw "Interpretador Python não encontrado: $pythonw" }
    if (-not (Test-Path -LiteralPath $fullApp)) { throw "Arquivo ausente: $fullApp" }
    if (-not (Test-Path -LiteralPath $agentService)) { throw "Arquivo ausente: $agentService" }
    $arguments = '"' + $fullApp + '" --origin "' + $origin + '" --show'
    $guiProcess = Start-Process -FilePath $pythonw -ArgumentList $arguments -WorkingDirectory $base -PassThru
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

    if (-not (Test-Path -LiteralPath $fullApp)) { throw "Aplicativo desktop completo ausente: $fullApp. Baixe novamente o pacote do agente." }
    if (-not (Test-Path -LiteralPath $agentService)) { throw "Arquivo arcoll_agent.py ausente. Extraia novamente o ZIP completo: $agentService" }

    $runtime = Get-PythonRuntime
    if (-not $runtime) {
        Write-Log 'Python 3 não localizado. Preparando instalação silenciosa do runtime.'
        $runtime = Install-PythonRuntime
        Write-Log "Python pronto: $($runtime.Python)"
    }

    # Dependências verificadas novamente em instalações e reparos.
    # Dependências opcionais para o ícone na área de notificação do Windows.
    if (-not $Run) { $previousEap = $ErrorActionPreference; $ErrorActionPreference = 'Continue'; try {
        & $runtime.Python -m pip --version *> $null
        if ($LASTEXITCODE -ne 0) { & $runtime.Python -m ensurepip --upgrade *> $null }
        & $runtime.Python -m pip install --disable-pip-version-check --quiet psutil pystray Pillow *> $null
        if ($LASTEXITCODE -ne 0) { Write-Log 'Bandeja: dependências não instaladas; aplicativo continua funcional.' }
    } catch { Write-Log ('Bandeja: ' + $_.Exception.Message) } finally { $ErrorActionPreference = $previousEap } }

    if ($Repair) {
        # A atualização do código não deve falhar por dependências opcionais da bandeja.
        # A interface principal valida suas dependências no início, sem exigir reinstalação.
        & $runtime.Python -c "import tkinter" *> $null
        if ($LASTEXITCODE -ne 0) { throw 'O interpretador do agente não possui tkinter; repare o Python antes de prosseguir.' }
    }
    if (-not $Run) {
        $desktop = [Environment]::GetFolderPath('Desktop')
        $shortcutPath = Join-Path $desktop ($systemName + '.lnk')
        $ws = New-Object -ComObject WScript.Shell
        $link = $ws.CreateShortcut($shortcutPath)
        # Atalho abre o agente existente em vez de iniciar novamente o instalador.
        $link.TargetPath = [string]$runtime.PythonW
        $link.Arguments = '"' + $fullApp + '" --show'
        $link.WorkingDirectory = $base
        $link.WindowStyle = 7
        $link.Description = 'Agente de rede ' + $systemName
        $iconPath = Join-Path $base 'favicon.ico'
        # O nome versionado invalida o cache de ícones do Explorer após atualizar a logo.
        if (Test-Path -LiteralPath $iconPath) {
            $hash = (Get-FileHash -LiteralPath $iconPath -Algorithm SHA256).Hash.Substring(0,12)
            $shortcutIcon = Join-Path $base ('Atalho-' + $hash + '.ico')
            if (-not (Test-Path -LiteralPath $shortcutIcon)) { Copy-Item -LiteralPath $iconPath -Destination $shortcutIcon -Force }
            $iconPath = $shortcutIcon
            $link.IconLocation = $iconPath + ',0'
        }
        $link.Save()
        Write-Log "Atalho criado em $shortcutPath"
        # Inicialização automática por usuário, sem exigir privilégios administrativos.
        $startup = [Environment]::GetFolderPath('Startup')
        if ($startup) {
            $autoLink = $ws.CreateShortcut((Join-Path $startup ($systemName + '.lnk')))
            $autoLink.TargetPath = $link.TargetPath
            $autoLink.Arguments = $link.Arguments
            $autoLink.WorkingDirectory = $base
            $autoLink.Description = 'Inicialização de ' + $systemName
            if (Test-Path -LiteralPath $iconPath) { $autoLink.IconLocation = $iconPath + ',0' }
            $autoLink.Save()
            Write-Log 'Inicialização automática configurada para o usuário atual.'
        }

        # Tela de bloqueio/login no acesso remoto: exige o instalador elevado (grava em %ProgramFiles% e registra a tarefa SYSTEM).
        if (Test-Elevated) {
            try { Install-SecureDesktopHelper $runtime } catch { Write-Log ('Tela segura: ' + $_.Exception.Message + ' O acesso remoto funcionará, mas sem controle da tela de bloqueio.') }
            try { Install-HeadlessCore } catch { Write-Log ('Núcleo pré-login: ' + $_.Exception.Message) }
            try {
                $helperScript = Join-Path $PSScriptRoot 'Registrar_Instalador_Programas.ps1'
                if (Test-Path -LiteralPath $helperScript) {
                    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $helperScript -User ($env:USERDOMAIN + '\' + $env:USERNAME) -Script (Join-Path $base 'arcoll_programs.py') | Out-Null
                    Write-Log ('Auxiliar de instalação de programas: código ' + $LASTEXITCODE)
                }
            } catch { Write-Log ('Auxiliar de instalação de programas: ' + $_.Exception.Message) }
        } else {
            Write-Log 'Tela segura e núcleo pré-login não instalados: execute o instalador como administrador.'
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

    # Instalações, reparos e atualizações elevadas garantem o driver do disco virtual (ignorado se já instalado).
    try { Install-WinFsp } catch { Write-Log ('WinFsp: ' + $_.Exception.Message) }

    if (-not $Repair) { Start-ArcollApp $runtime }
} catch {
    if ($Repair) { Write-Log ('Reparo falhou: '+$_.Exception.Message) } else { Show-Error ("Não foi possível iniciar o Agente Arcoll.`r`n" + $_.Exception.Message) }
    exit 1
}
exit 0
