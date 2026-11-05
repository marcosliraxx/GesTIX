# Caminho: agentes/windows/Diagnosticar_Nucleo.ps1
# BLOCO 01 - DIAGNÓSTICO SEM MODIFICAR O WINDOWS
$taskName = 'ArcollLocalCore'
Write-Host 'ARCOLL LOCAL CORE - DIAGNÓSTICO' -ForegroundColor Cyan
try {
    $task = Get-ScheduledTask -TaskName $taskName -ErrorAction Stop
    $info = Get-ScheduledTaskInfo -TaskName $taskName -ErrorAction Stop
    Write-Host ('Estado: ' + $task.State)
    Write-Host ('Última execução: ' + $info.LastRunTime)
    Write-Host ('Código da última execução: ' + $info.LastTaskResult)
    Write-Host ('Conta: ' + $task.Principal.UserId)
} catch { Write-Host ('Tarefa indisponível: ' + $_.Exception.Message) -ForegroundColor Yellow }
try {
    $r = Invoke-RestMethod -Uri 'http://127.0.0.1:8787/api/health' -TimeoutSec 4
    Write-Host ('API local: ativa | Versão: ' + $r.version) -ForegroundColor Green
} catch { Write-Host 'API local: indisponível' -ForegroundColor Yellow }
Write-Host 'A interface e o acesso remoto com tela bloqueada exigem validação independente.'
