# Caminho: agentes/servidor/Arcoll.WinUI3/Compilar-WinUI3.ps1
# Compilação local de projeto nativo; exige Windows, SDK .NET e acesso autorizado ao NuGet.
param([ValidateSet('x64','ARM64')][string]$Arquitetura='x64')
$ErrorActionPreference='Stop'
Set-Location -LiteralPath $PSScriptRoot
if (-not $IsWindows -and $PSVersionTable.PSEdition -eq 'Core') { throw 'Execute no Windows 10/11.' }
$dotnet=Get-Command dotnet -ErrorAction SilentlyContinue
if (-not $dotnet) { throw 'Instale o SDK .NET 8 no Windows.' }
$sdks=& dotnet --list-sdks
if (-not ($sdks | Where-Object { $_ -match '^8\.' })) { throw 'SDK .NET 8 não localizado.' }
$project=Join-Path $PSScriptRoot 'Arcoll.WinUI3.csproj'
foreach($name in @('App.xaml','MainWindow.xaml','app.manifest')) {
    if (-not (Test-Path (Join-Path $PSScriptRoot $name))) { throw "Arquivo do projeto ausente: $name" }
}
& dotnet restore $project -p:Platform=$Arquitetura
if ($LASTEXITCODE -ne 0) { throw 'Falha ao restaurar o projeto.' }
$rid='win-'+$Arquitetura.ToLowerInvariant()
& dotnet publish $project -c Release -r $rid --self-contained true -p:Platform=$Arquitetura -p:PublishReadyToRun=false -p:PublishTrimmed=false
if ($LASTEXITCODE -ne 0) { throw 'Falha na compilação do WinUI 3.' }
$published=Join-Path $PSScriptRoot "bin\Release\net8.0-windows10.0.19041.0\$rid\publish"
if (-not (Test-Path $published)) {
 $found=Get-ChildItem -Path (Join-Path $PSScriptRoot 'bin\Release') -Recurse -File -Filter 'Arcoll.WinUI3.exe' -ErrorAction SilentlyContinue | Where-Object { $_.DirectoryName -match 'publish' } | Select-Object -First 1
 if ($found) { $published=$found.DirectoryName }
}
$exe=Join-Path $published 'Arcoll.WinUI3.exe'
if (-not (Test-Path $exe)) { throw "Publicação não localizada: $exe" }
& dotnet publish (Join-Path $PSScriptRoot 'BackupWorker\BackupWorker.csproj') -c Release -r $rid --self-contained true -p:PublishTrimmed=false -o (Join-Path $published 'BackupWorker')
if ($LASTEXITCODE -ne 0) { throw 'Falha ao publicar executor de backup.' }
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'INSTALAR_BACKUP_SERVICO.ps1') -Destination $published -Force
$artifact=Join-Path $PSScriptRoot ("Arcoll-WinUI3-$Arquitetura-publicacao.zip")
if (Test-Path $artifact) { Remove-Item -LiteralPath $artifact -Force }
Compress-Archive -Path (Join-Path $published '*') -DestinationPath $artifact -CompressionLevel Optimal
Write-Host "Compilação publicada em: $artifact"
Write-Warning 'Este arquivo não é o instalador da plataforma. NÃO publique como agente 4.x sem validar comunicação, permissões e operações no Windows.'
