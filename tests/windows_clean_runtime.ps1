# Caminho: tests/windows_clean_runtime.ps1
# Confere a dependencia oficial usada pelo Clean sem ler ou alterar arquivos do usuario.
$ErrorActionPreference = 'Stop'
if ($env:GITHUB_ACTIONS -ne 'true') { throw 'Teste exclusivo do runner efemero do GitHub.' }
$folder = Join-Path $env:RUNNER_TEMP ('clean-runtime-test-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $folder | Out-Null
try {
    $archive = Join-Path $folder 'runtime.zip'
    Invoke-WebRequest -UseBasicParsing -Uri 'https://www.python.org/ftp/python/3.12.10/python-3.12.10-embed-amd64.zip' -OutFile $archive
    Expand-Archive -LiteralPath $archive -DestinationPath $folder
    $python = Join-Path $folder 'python.exe'
    $signature = Get-AuthenticodeSignature -LiteralPath $python
    if ($signature.Status -ne 'Valid' -or $signature.SignerCertificate.Subject -notmatch 'Python Software Foundation') { throw 'Assinatura do componente Clean invalida.' }
    & $python -I -c "import sqlite3, ssl, winreg; print('PASS: runtime oficial e bibliotecas do Clean')"
    if ($LASTEXITCODE -ne 0) { throw 'Componente Clean incompleto.' }
    python (Join-Path $PSScriptRoot 'test_native_clean_worker.py')
    if ($LASTEXITCODE -ne 0) { throw 'Motor Clean falhou no Windows com dados sinteticos.' }
} finally { Remove-Item -LiteralPath $folder -Recurse -Force }
