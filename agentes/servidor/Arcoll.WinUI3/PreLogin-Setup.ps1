# Caminho: agentes/servidor/Arcoll.WinUI3/PreLogin-Setup.ps1
# Funcoes separadas para testar atualizacao sem UAC e falhas do processo elevado.
function Invoke-PreLoginProcess {
    param([string]$Script, [string]$AgentFolder, [string]$LogPath, [string]$ResultPath, [bool]$Elevated)
    $powershell = Join-Path $env:WINDIR 'System32\WindowsPowerShell\v1.0\powershell.exe'
    if ($Elevated) {
        & $powershell -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $Script -AgentFolder $AgentFolder -LogPath $LogPath -ResultPath $ResultPath | ForEach-Object { Write-Host $_ }
        return $LASTEXITCODE
    }
    $arguments = '-NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' + $Script + '" -AgentFolder "' + $AgentFolder + '" -LogPath "' + $LogPath + '" -ResultPath "' + $ResultPath + '"'
    $process = Start-Process -FilePath $powershell -ArgumentList $arguments -Verb RunAs -Wait -PassThru
    return $process.ExitCode
}

function Invoke-PreLoginSetup {
    param([string]$AgentFolder, [string]$LogFolder, [bool]$Update, [bool]$Unattended, [bool]$Elevated)
    # Atualizacoes normais E forcadas nunca solicitam elevacao na estacao.
    # Uma tarefa de boot existente continua usando a copia protegida ja instalada.
    if (-not $Elevated -and ($Update -or $Unattended)) {
        return [pscustomobject]@{ installed = $false; pending = $true; log = ''; message = 'Aplicativo atualizado. Configuracao do boot pendente de implantacao administrativa; componente protegido anterior preservado.' }
    }
    $stamp = (Get-Date -Format 'yyyyMMdd-HHmmss') + '-' + [guid]::NewGuid().ToString('N')
    $logPath = Join-Path $LogFolder ('PreLogin-' + $stamp + '.log')
    $resultPath = Join-Path $LogFolder ('PreLogin-' + $stamp + '.json')
    try {
        $exitCode = Invoke-PreLoginProcess -Script (Join-Path $AgentFolder 'HABILITAR_PRELOGIN.ps1') -AgentFolder $AgentFolder -LogPath $logPath -ResultPath $resultPath -Elevated $Elevated
        $result = $null
        if (Test-Path -LiteralPath $resultPath) { $result = Get-Content -LiteralPath $resultPath -Raw -Encoding UTF8 | ConvertFrom-Json }
        if ($exitCode -ne 0 -or -not $result -or $result.ok -ne $true) {
            $detail = if ($result -and $result.error) { [string]$result.error } else { 'Processo de boot terminou sem confirmar a instalacao (codigo ' + $exitCode + ').' }
            throw $detail
        }
        return [pscustomobject]@{ installed = $true; pending = $false; log = $logPath; message = 'Tarefa de presenca antes do login instalada e em execucao.' }
    } catch {
        return [pscustomobject]@{ installed = $false; pending = $true; log = $logPath; message = ($_.Exception.Message + ' Diagnostico: ' + $logPath) }
    }
}
