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
# Executa a janela real no desktop do runner, sem imagens ou arquivos de usuarios.
if ($env:GITHUB_ACTIONS -eq 'true' -and [Environment]::UserInteractive -and (Get-Process -Id $PID).SessionId -ne 0) {
    $folder = Join-Path $env:RUNNER_TEMP ('update-window-' + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $folder | Out-Null
    $marker = Join-Path $folder 'opened.txt'
    $probe = Join-Path $folder 'agent-probe.exe'
    $savedMarker = $env:TI_DIGITAL_UPDATE_TEST_MARKER
    $child = $null
    try {
        $env:TI_DIGITAL_UPDATE_TEST_MARKER = $marker
        Add-Type -TypeDefinition 'public static class UpdateOpenProbe { public static void Main() { System.IO.File.WriteAllText(System.Environment.GetEnvironmentVariable("TI_DIGITAL_UPDATE_TEST_MARKER"), "opened"); } }' -OutputAssembly $probe -OutputType WindowsApplication
        $operation = [guid]::NewGuid().ToString('N')
        [IO.File]::WriteAllText((Join-Path $folder 'update-progress.json'), (@{update_id=$operation;percent=74;message='Teste de janela'} | ConvertTo-Json -Compress))
        $viewer = Join-Path $native 'ATUALIZACAO_VISUAL.ps1'
        $arguments = '-NoProfile -NonInteractive -STA -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $viewer + '" -UpdateId "' + $operation + '" -StateFolder "' + $folder + '" -AgentPath "' + $probe + '"'
        $child = Start-Process powershell.exe -ArgumentList $arguments -WindowStyle Hidden -PassThru -RedirectStandardError (Join-Path $folder 'stderr.txt')
        for ($attempt=0; $attempt -lt 60; $attempt++) {
            $child.Refresh()
            if ($child.HasExited) { throw ('Janela encerrou antes do sucesso: ' + (Get-Content (Join-Path $folder 'stderr.txt') -Raw)) }
            if ($child.MainWindowHandle -ne [IntPtr]::Zero) { break }
            Start-Sleep -Milliseconds 200
        }
        if ($child.MainWindowHandle -eq [IntPtr]::Zero) { throw 'Janela grafica nao foi exibida no runner.' }
        Add-Type -AssemblyName UIAutomationClient
        Add-Type -AssemblyName UIAutomationTypes
        $window = [System.Windows.Automation.AutomationElement]::FromHandle($child.MainWindowHandle)
        $condition = [System.Windows.Automation.PropertyCondition]::new([System.Windows.Automation.AutomationElement]::NameProperty,'74%')
        $found = $null
        for ($attempt=0; $attempt -lt 20; $attempt++) {
            $found = $window.FindFirst([System.Windows.Automation.TreeScope]::Descendants,$condition)
            if ($found) { break }; Start-Sleep -Milliseconds 150
        }
        if (-not $found) { throw 'Janela nao exibiu a porcentagem recebida.' }
        $clock = [Diagnostics.Stopwatch]::StartNew()
        [IO.File]::WriteAllText((Join-Path $folder 'update-ui-result.json'), (@{update_id=$operation;ok=$true;message='Sucesso sintetico'} | ConvertTo-Json -Compress))
        Start-Sleep -Milliseconds 1500
        $child.Refresh()
        if ($child.HasExited -or (Test-Path $marker)) { throw 'Janela abriu o agente antes da contagem.' }
        if (-not $child.WaitForExit(10000)) { throw 'Janela nao fechou automaticamente.' }
        if ($clock.Elapsed.TotalSeconds -lt 2.8) { throw 'Contagem encerrou antes de tres segundos.' }
        for ($attempt=0; $attempt -lt 20 -and -not (Test-Path $marker); $attempt++) { Start-Sleep -Milliseconds 150 }
        if (-not (Test-Path $marker)) { throw 'Janela nao abriu o agente ao concluir.' }
        Write-Host 'PASS: janela real mostrou 74%, aguardou tres segundos, fechou e abriu o agente de teste.'
    } finally {
        if ($child -and -not $child.HasExited) { Stop-Process -Id $child.Id -Force -ErrorAction SilentlyContinue }
        $env:TI_DIGITAL_UPDATE_TEST_MARKER = $savedMarker
        Remove-Item -LiteralPath $folder -Recurse -Force -ErrorAction Continue
    }
} else { Write-Host 'Desktop indisponivel: teste da janela real omitido; maquina de estados validada.' }
Write-Host 'PASS: progresso monotono, recibos isolados, falha sem falso sucesso, contagem 3/2/1/0 e recibo duravel.'
