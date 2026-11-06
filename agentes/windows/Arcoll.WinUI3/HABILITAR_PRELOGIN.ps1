# Caminho: agentes/windows/Arcoll.WinUI3/HABILITAR_PRELOGIN.ps1
# Executar como administrador no computador autorizado; cria tarefa de presenca
# no boot. Nao desbloqueia o Windows, nao recebe comandos remotos no pre-login.
[CmdletBinding()]
param(
    [string]$AgentFolder = (Join-Path $env:LOCALAPPDATA 'TI-Digital\WinUI3'),
    [switch]$Disable
)
$ErrorActionPreference = 'Stop'
$taskName = 'TI-Digital Presenca PreLogin'
$admin = [Security.Principal.WindowsPrincipal]::new([Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) { throw 'Abra o PowerShell como administrador para habilitar a inicializacao no boot. O Windows exigira autorizacao UAC.' }
if ($Disable) {
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction SilentlyContinue
    Write-Host 'Presenca antes do login desabilitada.'
    exit 0
}
$sourceWorker = Join-Path $AgentFolder 'BootWorker'
$sourceExe = Join-Path $sourceWorker 'TIDigital.BootPresence.exe'
$configSrc = Join-Path $AgentFolder 'agent-config.json'
if (-not (Test-Path -LiteralPath $sourceExe)) { throw 'BootWorker nao compilado. Reinstale o agente antes de habilitar.' }
if (-not (Test-Path -LiteralPath $configSrc)) { throw 'Configuracao do agente ausente; vincule o computador pela plataforma.' }
# Congela a identidade efetiva do mesmo perfil que executa a interface.
# O pacote inicial pode nao conter fingerprint; use o mesmo algoritmo do agente.
$config = Get-Content -LiteralPath $configSrc -Raw -Encoding UTF8 | ConvertFrom-Json
$fp = ([string]$config.fingerprint).Trim().ToLowerInvariant()
if ($fp -notmatch '^[a-f0-9]{64}$') {
    $registry = [Microsoft.Win32.RegistryKey]::OpenBaseKey([Microsoft.Win32.RegistryHive]::LocalMachine, [Microsoft.Win32.RegistryView]::Registry64)
    try {
        $key = $registry.OpenSubKey('SOFTWARE\Microsoft\Cryptography')
        try { $machineGuid = [string]$key.GetValue('MachineGuid') } finally { if ($key) { $key.Dispose() } }
    } finally { $registry.Dispose() }
    if (-not $machineGuid) { throw 'MachineGuid indisponivel; abra o agente e repare a identidade antes de instalar.' }
    $sha = [Security.Cryptography.SHA256]::Create()
    try { $fp = -join ($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes('arcoll-speed-connection|' + $machineGuid)) | ForEach-Object { $_.ToString('x2') }) } finally { $sha.Dispose() }
}
$uri = $null
if (-not [Uri]::TryCreate(([string]$config.origin), [UriKind]::Absolute, [ref]$uri) -or $uri.Scheme -ne 'https' -or $uri.UserInfo -ne '' -or
    ([string]$config.site_code) -notmatch '^[A-Za-z0-9]{5}$' -or ([string]$config.download_key) -notmatch '^[a-fA-F0-9]{64}$') { throw 'Configuracao incompleta ou invalida.' }
$bootConfig = @{ origin = ([string]$config.origin).TrimEnd('/'); site_code = ([string]$config.site_code).ToUpperInvariant(); download_key = [string]$config.download_key; fingerprint = $fp }
$targetDir = Join-Path $env:ProgramFiles 'TI-Digital\BootPresence'
$dataDir = Join-Path $env:ProgramData 'TI-Digital\Boot'
$stage = Join-Path $env:ProgramFiles ('TI-Digital\BootPresence-stage-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $stage -Force | Out-Null
try {
    Copy-Item -Path (Join-Path $sourceWorker '*') -Destination $stage -Recurse -Force -ErrorAction Stop
    if (-not (Test-Path -LiteralPath (Join-Path $stage 'TIDigital.BootPresence.exe'))) { throw 'Executavel nao copiado.' }
    & icacls.exe $stage /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' /T /Q | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Falha ao proteger executaveis do boot.' }
    $task = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
    if ($task) { Stop-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue }
    Start-Sleep -Seconds 1
    if (Test-Path -LiteralPath $targetDir) { Remove-Item -LiteralPath $targetDir -Recurse -Force }
    Move-Item -LiteralPath $stage -Destination $targetDir
    New-Item -ItemType Directory -Path $dataDir -Force | Out-Null
    # Retira acesso de usuarios comuns a configuracao e aos logs; chave revogavel.
    & icacls.exe $dataDir /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Falha ao proteger a pasta ProgramData.' }
    [IO.File]::WriteAllText((Join-Path $dataDir 'agent-config.json'), ($bootConfig | ConvertTo-Json -Compress), (New-Object Text.UTF8Encoding($false)))
    & icacls.exe (Join-Path $dataDir 'agent-config.json') /inheritance:r /grant:r '*S-1-5-18:F' '*S-1-5-32-544:F' | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Falha ao proteger configuracao.' }
    $exe = Join-Path $targetDir 'TIDigital.BootPresence.exe'
    $action = New-ScheduledTaskAction -Execute $exe -WorkingDirectory $targetDir
    $trigger = New-ScheduledTaskTrigger -AtStartup
    $principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
    $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -MultipleInstances IgnoreNew -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit ([TimeSpan]::Zero)
    Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null
    Start-ScheduledTask -TaskName $taskName
    Start-Sleep -Seconds 2
    $info = Get-ScheduledTaskInfo -TaskName $taskName
    if ((Get-ScheduledTask -TaskName $taskName).State -ne 'Running') { throw ('Worker de boot nao permaneceu em execucao. Resultado: ' + $info.LastTaskResult + '. Consulte ' + (Join-Path $dataDir 'presence.log')) }
    Write-Host ('Inicializacao antes do login instalada. Estado: ' + (Get-ScheduledTask -TaskName $taskName).State + '; ultimo resultado: ' + $info.LastTaskResult)
    Write-Host 'O boot worker informa presenca; a interface WinUI e os comandos completos exigem a sessao Windows.'
} finally {
    if (Test-Path -LiteralPath $stage) { Remove-Item -LiteralPath $stage -Recurse -Force -ErrorAction SilentlyContinue }
}
