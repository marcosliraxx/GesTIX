# Caminho: tests/windows_installer_policy.ps1
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$native = Join-Path $root 'agentes\windows\Arcoll.WinUI3'
Get-ChildItem -LiteralPath $native -Filter '*.ps1' -Recurse | ForEach-Object {
    $tokens = $null; $errors = $null
    [System.Management.Automation.Language.Parser]::ParseFile($_.FullName, [ref]$tokens, [ref]$errors) | Out-Null
    if ($errors.Count) { throw ($_.FullName + ': ' + ($errors.Message -join '; ')) }
}
. (Join-Path $native 'PreLogin-Setup.ps1')
$folder = Join-Path ([IO.Path]::GetTempPath()) ('installer-policy-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $folder | Out-Null
try {
    $script:calls = 0
    function Invoke-PreLoginProcess { $script:calls++; throw 'Nao deveria iniciar processo elevado' }
    foreach ($mode in @('normal','forcada')) {
        $status = Invoke-PreLoginSetup -AgentFolder $folder -LogFolder $folder -Update $true -Unattended $false -Elevated $false
        if (-not $status.pending -or $status.installed -or $script:calls) { throw ('Atualizacao ' + $mode + ' tentou UAC') }
    }
    $status = Invoke-PreLoginSetup -AgentFolder $folder -LogFolder $folder -Update $false -Unattended $true -Elevated $false
    if (-not $status.pending -or $script:calls) { throw 'Implantacao silenciosa abriu elevacao' }
    function Invoke-PreLoginProcess {
        param($Script,$AgentFolder,$LogPath,$ResultPath,$Elevated)
        [IO.File]::WriteAllText($ResultPath, '{"ok":false,"error":"Erro sintetico do Agendador"}')
        return 1
    }
    $status = Invoke-PreLoginSetup -AgentFolder $folder -LogFolder $folder -Update $false -Unattended $false -Elevated $true
    if ($status.installed -or -not $status.pending -or $status.message -notmatch 'Erro sintetico do Agendador') { throw 'Erro real do filho perdido' }
    function Invoke-PreLoginProcess {
        param($Script,$AgentFolder,$LogPath,$ResultPath,$Elevated)
        [IO.File]::WriteAllText($ResultPath, '{"ok":true,"error":""}')
        return 0
    }
    $status = Invoke-PreLoginSetup -AgentFolder $folder -LogFolder $folder -Update $true -Unattended $true -Elevated $true
    if (-not $status.installed -or $status.pending) { throw 'Sucesso do boot nao reconhecido' }
    function Invoke-PreLoginProcess { return 0 }
    $status = Invoke-PreLoginSetup -AgentFolder $folder -LogFolder $folder -Update $false -Unattended $false -Elevated $true
    if ($status.installed -or -not $status.pending) { throw 'Exit 0 sem recibo nao pode confirmar boot' }
    function Invoke-PreLoginProcess { throw 'Cancelamento sintetico do UAC' }
    $status = Invoke-PreLoginSetup -AgentFolder $folder -LogFolder $folder -Update $false -Unattended $false -Elevated $false
    if (-not $status.pending -or $status.message -notmatch 'Cancelamento sintetico') { throw 'Cancelamento nao foi preservado como pendencia' }
    Write-Host 'PASS: sintaxe PS, atualizacao normal/forcada sem UAC, falha do filho, sucesso e cancelamento.'
} finally { Remove-Item -LiteralPath $folder -Recurse -Force }
