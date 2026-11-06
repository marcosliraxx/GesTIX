# Caminho: agentes/windows/Registrar_Instalador_Programas.ps1
# Cria (uma única vez, como administrador) a tarefa "\Arcoll\Install Helper": ela roda com privilégio máximo,
# SEM janela de UAC, e só executa o arquivo protegido em %ProgramFiles%\Arcoll\Installer, que aceita
# exclusivamente IDs do catálogo aprovado de programas. Chamada pelo instalador elevado ou pela 1ª instalação remota.
param(
    [Parameter(Mandatory = $true)][string]$User,
    [string]$Python = '',
    [Parameter(Mandatory = $true)][string]$Script
)
$ErrorActionPreference = 'Stop'
$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) { exit 3 }
# Somente o Python embarcado protegido (gravável apenas por administradores) pode ser usado pela tarefa elevada.
$runtime = Join-Path $env:ProgramFiles 'Arcoll\Runtime\pythonw.exe'
if (-not (Test-Path -LiteralPath $runtime -PathType Leaf)) { exit 4 }
if ((Split-Path -Leaf $Script) -ne 'arcoll_programs.py' -or -not (Test-Path -LiteralPath $Script -PathType Leaf)) { exit 2 }
$target = Join-Path $env:ProgramFiles 'Arcoll\Installer'
New-Item -ItemType Directory -Force -Path $target | Out-Null
$system = '*S-1-5-18'; $admins = '*S-1-5-32-544'; $users = '*S-1-5-32-545'
& icacls.exe $target '/inheritance:r' '/grant:r' "${system}:(OI)(CI)F" "${admins}:(OI)(CI)F" "${users}:(OI)(CI)RX" | Out-Null
if ($LASTEXITCODE -ne 0) { exit 5 }
$copy = Join-Path $target 'arcoll_programs.py'
Copy-Item -LiteralPath $Script -Destination $copy -Force
$action = New-ScheduledTaskAction -Execute $runtime -Argument ('"' + $copy + '" --elevated-job') -WorkingDirectory $target
$principal = New-ScheduledTaskPrincipal -UserId $User -LogonType Interactive -RunLevel Highest
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 2)
Register-ScheduledTask -TaskPath '\Arcoll\' -TaskName 'Install Helper' -Action $action -Principal $principal -Settings $settings -Description 'Arcoll: instalação de programas do catálogo sem janela de UAC' -Force | Out-Null
exit 0
