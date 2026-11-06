# Caminho: agentes/windows/Arcoll.WinUI3/VERIFICAR_PRELOGIN.ps1
# Executar como administrador no Windows.
$ErrorActionPreference = 'Stop'
$name = 'TI-Digital Presenca PreLogin'
$task = Get-ScheduledTask -TaskName $name
$info = Get-ScheduledTaskInfo -TaskName $name
$task | Select-Object TaskName, State
$task.Principal | Select-Object UserId, LogonType
$task.Triggers | Select-Object CimClass, Enabled
$info | Select-Object LastRunTime, LastTaskResult, NextRunTime
$log = Join-Path $env:ProgramData 'TI-Digital\Boot\presence.log'
if (Test-Path -LiteralPath $log) { Get-Content -LiteralPath $log -Tail 25 }
if ($task.State -ne 'Running') { throw 'Componente parado. Execute HABILITAR_PRELOGIN.ps1 como administrador.' }
Write-Host 'Para comprovar o pre-login: reinicie, aguarde 60 segundos SEM entrar no Windows e confira a presenca pelo painel em outro computador.'
