# Caminho: tests/windows_boot_smoke.ps1
# Exclusivo do runner efemero do GitHub: nao executar em computadores de producao.
$ErrorActionPreference = 'Stop'
if ($env:GITHUB_ACTIONS -ne 'true' -or -not $env:RUNNER_TEMP) { throw 'Teste exclusivo do runner efemero do GitHub.' }
$root = Split-Path -Parent $PSScriptRoot
$native = Join-Path $root 'agentes\windows\Arcoll.WinUI3'
$folder = Join-Path $env:RUNNER_TEMP ('boot-test-' + [guid]::NewGuid().ToString('N'))
$target = Join-Path $env:ProgramFiles 'TI-Digital\BootPresence'
$data = Join-Path $env:ProgramData 'TI-Digital\Boot'
$name = 'TI-Digital Presenca PreLogin'
if ((Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue) -or (Test-Path $target) -or (Test-Path $data)) { throw 'Runner nao esta vazio; nao alterar instalacao existente.' }
New-Item -ItemType Directory -Path $folder | Out-Null
try {
    & dotnet publish (Join-Path $native 'BootWorker\BootWorker.csproj') -c Release -r win-x64 --self-contained true -o (Join-Path $folder 'BootWorker')
    if ($LASTEXITCODE -ne 0) { throw 'Build do worker falhou' }
    $config = @{ origin = 'https://boot-test.invalid'; site_code = 'T3ST1'; download_key = ('a' * 64) }
    [IO.File]::WriteAllText((Join-Path $folder 'agent-config.json'), ($config | ConvertTo-Json -Compress))
    foreach ($pass in 1,2) {
        $result = Join-Path $folder ('result-' + $pass + '.json')
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $native 'HABILITAR_PRELOGIN.ps1') -AgentFolder $folder -LogPath (Join-Path $folder ('boot-' + $pass + '.log')) -ResultPath $result
        if ($LASTEXITCODE -ne 0) { throw ('Instalacao do boot falhou: ' + (Get-Content $result -Raw)) }
        $receipt = Get-Content $result -Raw | ConvertFrom-Json
        if (-not $receipt.ok -or (Get-ScheduledTask -TaskName $name).State -ne 'Running') { throw 'Worker nao ficou ativo' }
        $identity = Get-Content (Join-Path $data 'agent-config.json') -Raw | ConvertFrom-Json
        if ($identity.fingerprint -notmatch '^[a-f0-9]{64}$') { throw 'Identidade nao derivada' }
    }
    Write-Host 'PASS: instalacao e reinstalacao da tarefa SYSTEM, identidade ausente e worker ativo sem rede.'
} finally {
    Stop-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
    Unregister-ScheduledTask -TaskName $name -Confirm:$false -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 2
    foreach ($dir in @($target,$data,$folder)) { if (Test-Path $dir) { Remove-Item -LiteralPath $dir -Recurse -Force } }
}
