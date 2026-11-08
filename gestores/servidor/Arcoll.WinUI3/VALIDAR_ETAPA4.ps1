# Caminho: gestores/servidor/Arcoll.WinUI3/VALIDAR_ETAPA4.ps1
# Diagnostico local somente leitura do T.I. Digital — Etapa 4.
# Execute apos instalar em Windows 10/11: powershell -NoProfile -File .\VALIDAR_ETAPA4.ps1
[CmdletBinding()]
param([string]$InstalledRoot = (Join-Path $env:LOCALAPPDATA 'TI-Digital\WinUI3'))
$ErrorActionPreference = 'Stop'
$checks = [System.Collections.Generic.List[object]]::new()
function Add-Check([string]$Name, [bool]$OK, [string]$Detail) {
    $checks.Add([pscustomobject]@{ teste = $Name; aprovado = $OK; detalhe = $Detail })
}
$exe = Join-Path $InstalledRoot 'Arcoll.WinUI3.exe'
Add-Check 'Executavel instalado' (Test-Path -LiteralPath $exe -PathType Leaf) $exe
foreach ($asset in @('index.html','styles.css','app.js')) {
    $file = Join-Path $InstalledRoot ('Assets\hybrid\dashboard\' + $asset)
    $valid = (Test-Path -LiteralPath $file -PathType Leaf) -and ((Get-Item -LiteralPath $file).Length -gt 0)
    Add-Check ('Painel hibrido ' + $asset) $valid $file
}
$configFile = Join-Path $InstalledRoot 'gestor-config.json'
Add-Check 'Identidade local (presenca do arquivo; conteudo nao exibido)' (Test-Path -LiteralPath $configFile -PathType Leaf) $configFile
$running = @(Get-Process -Name 'Arcoll.WinUI3' -ErrorAction SilentlyContinue | Where-Object {
    try { $_.Path -and $_.Path.Equals($exe, [StringComparison]::OrdinalIgnoreCase) } catch { $false }
})
Add-Check 'Gestor em execucao' ($running.Count -gt 0) ('Instancias nesta instalacao: ' + $running.Count)
$sdk = @()
$dotnet = Get-Command dotnet -ErrorAction SilentlyContinue
if ($dotnet) { try { $sdk = @(& $dotnet.Source --list-sdks 2>$null) } catch { $sdk = @() } }
Add-Check 'SDK .NET 8 para recompilacao local' (@($sdk | Where-Object { $_ -match '^8\.' }).Count -gt 0) 'Requisito de compilacao, nao de uso do executavel publicado.'
$report = [pscustomobject]@{
    titulo = 'T.I. Digital — validacao local etapa 4'
    computador = $env:COMPUTERNAME
    data = (Get-Date).ToString('o')
    somente_leitura = $true
    testes = $checks
    aprovados = @($checks | Where-Object { $_.aprovado }).Count
    total = $checks.Count
}
$path = Join-Path $env:TEMP ('TI-Digital-Validacao-Etapa4-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.json')
$report | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $path -Encoding UTF8
$checks | Format-Table -AutoSize
Write-Host ('Relatorio: ' + $path)
if ($report.aprovados -ne $report.total) { exit 1 }
exit 0
