# Caminho: agentes/windows/Diagnosticar_Inicializacao.ps1
# Diagnostico, sem alterar configuracoes de seguranca do Windows.
$task = Get-ScheduledTask -TaskName 'ArcollSecureDesktop' -ErrorAction SilentlyContinue
$info = if ($task) { Get-ScheduledTaskInfo -TaskName 'ArcollSecureDesktop' -ErrorAction SilentlyContinue } else { $null }
$helper = Join-Path $env:ProgramFiles 'Arcoll\SecureDesktop\arcoll_secure_helper.py'
[pscustomobject]@{
    ComponenteTelaSegura = [bool](Test-Path -LiteralPath $helper)
    TarefaRegistrada = [bool]$task
    IniciaAntesDoLogin = if ($task) { [bool](@($task.Triggers | Where-Object { $_.CimClass.CimClassName -eq 'MSFT_TaskBootTrigger' }).Count) } else { $false }
    Estado = if ($task) { [string]$task.State } else { 'Nao instalada' }
    UltimaExecucao = if ($info) { $info.LastRunTime } else { $null }
    CodigoUltimoResultado = if ($info) { $info.LastTaskResult } else { $null }
    AgentePrincipalAntesDoLogin = $false # O app principal ainda depende da sessao interativa.
} | Format-List
