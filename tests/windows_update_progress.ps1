# Caminho: tests/windows_update_progress.ps1
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$native = Join-Path $root 'agentes\windows\Arcoll.WinUI3'
. (Join-Path $native 'ATUALIZACAO_VISUAL.ps1') -TestMode
$id = 'a' * 32
$now = [DateTime]::UtcNow
$progress = [pscustomobject]@{ update_id = $id; percent = 72; message = 'Compilando' }
$state = Get-UpdateDisplayState -ExpectedId $id -ProgressState $progress -Now $now
if ($state.percent -ne 72 -or $state.open_agent -or $state.state -ne 'working') { throw 'Progresso real nao foi exibido' }
$progress.percent = 15
$state = Get-UpdateDisplayState -ExpectedId $id -ProgressState $progress -PreviousPercent 72
if ($state.percent -ne 72) { throw 'Progresso regrediu' }
$progress.percent = 100
$state = Get-UpdateDisplayState -ExpectedId $id -ProgressState $progress
if ($state.percent -ne 99 -or $state.open_agent) { throw 'Progresso 100 nao pode significar sucesso sem recibo' }
$stale = [pscustomobject]@{ update_id = ('b' * 32); ok = $true }
$state = Get-UpdateDisplayState -ExpectedId $id -ResultState $stale
if ($state.state -ne 'working') { throw 'Recibo antigo confirmou outra atualizacao' }
$failed = [pscustomobject]@{ update_id = $id; ok = $false; message = 'Falha sintetica' }
$state = Get-UpdateDisplayState -ExpectedId $id -ResultState $failed -PreviousPercent 98
if ($state.state -ne 'failed' -or $state.open_agent -or $state.percent -eq 100) { throw 'Falha foi apresentada como sucesso' }
$result = [pscustomobject]@{ update_id = $id; ok = $true }
$state = Get-UpdateDisplayState -ExpectedId $id -ResultState $result -Now $now
$deadline = $state.deadline
foreach ($second in 0,1,2,3) {
    $state = Get-UpdateDisplayState -ExpectedId $id -ResultState $result -Now ($now.AddSeconds($second)) -Deadline $deadline
    if ($state.percent -ne 100 -or $state.open_agent -ne ($second -eq 3)) { throw ('Contagem incorreta no segundo ' + $second) }
    if ($state.message -notmatch ([string](3-$second) + ' segundo')) { throw 'Contador nao exibiu 3, 2, 1, 0' }
}
# Testa o produtor real dos recibos sem executar o instalador.
$tokens = $null; $errors = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile((Join-Path $native 'INSTALAR_WINUI3.ps1'), [ref]$tokens, [ref]$errors)
foreach ($name in 'Write-UpdateState','Write-UpdateResult') {
    $definition = $ast.Find({ param($node) $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -eq $name }, $true)
    Invoke-Expression $definition.Extent.Text
}
$previousLocal = $env:LOCALAPPDATA
$testFolder = Join-Path ([IO.Path]::GetTempPath()) ('update-receipt-' + [guid]::NewGuid().ToString('N'))
try {
    $env:LOCALAPPDATA = $testFolder
    New-Item -ItemType Directory -Path (Join-Path $testFolder 'TI-Digital') -Force | Out-Null
    $Update = $true; $updateOperationId = $id
    Write-UpdateResult $true 'Sucesso sintetico'
    Remove-Item -LiteralPath (Join-Path $testFolder 'TI-Digital\update-result.json')
    $durable = Get-Content -LiteralPath (Join-Path $testFolder 'TI-Digital\update-ui-result.json') -Raw -Encoding UTF8 | ConvertFrom-Json
    if ($durable.update_id -ne $id -or -not $durable.ok) { throw 'Confirmacao da plataforma removeu recibo da janela' }
    Write-UpdateResult $false 'Falha sintetica'
    $durable = Get-Content -LiteralPath (Join-Path $testFolder 'TI-Digital\update-ui-result.json') -Raw -Encoding UTF8 | ConvertFrom-Json
    if ($durable.ok) { throw 'Falha manteve um recibo de sucesso antigo' }
} finally {
    $env:LOCALAPPDATA = $previousLocal
    if (Test-Path -LiteralPath $testFolder) { Remove-Item -LiteralPath $testFolder -Recurse -Force }
}
Write-Host 'PASS: progresso monotono, recibos isolados, falha sem falso sucesso, contagem 3/2/1/0 e recibo duravel.'
