# Executar em Windows Acom SDK .NET 8, para cada arquitetura. Publicar a pasta gerada no servidor.
[CmdletBinding()]
param([ValidateSet('win-x64','win-arm64')][string]$Arch='win-x64',[string]$Output='')
$ErrorActionPreference='Stop'
$root=$PSScriptRoot
$repo=Resolve-Path (Join-Path $root '..\..\..')
$version=[regex]::Match((Get-Content (Join-Path $repo 'VERSION') -Raw), '^\s*(\d+\.\d+\.\d+)').Groups[1].Value
if(-not $version){throw 'Arquivo VERSION invalido.'}
if(-not $Output){$Output=Join-Path $repo ('release-build\'+$Arch+'\current')}
$Output=[IO.Path]::GetFullPath($Output)
if(Test-Path $Output){throw 'Diretorio de destino ja existe. Use outra pasta para impedir publicacao parcial.'}
$finalOutput=$Output
$Output=$finalOutput+'.stage-'+[guid]::NewGuid().ToString('N')
$publish=Join-Path $env:TEMP ('ti-agent-publish-'+[guid]::NewGuid().ToString('N'))
$boot=Join-Path $env:TEMP ('ti-agent-boot-'+[guid]::NewGuid().ToString('N'))
$backup=Join-Path $env:TEMP ('ti-agent-backup-'+[guid]::NewGuid().ToString('N'))
try{
 $platform=if($Arch -eq 'win-arm64'){'ARM64'}else{'x64'}
 & dotnet publish (Join-Path $root 'Arcoll.WinUI3.csproj') -p:Version=$version -c Release -r $Arch --self-contained true -p:WindowsPackageType=None -p:WindowsAppSDKSelfContained=true -p:Platform=$platform -o $publish
 if($LASTEXITCODE -ne 0){throw 'Falha ao publicar WinUI3.'}
 & dotnet publish (Join-Path $root 'BootWorker\BootWorker.csproj') -p:Version=$version -c Release -r $Arch --self-contained true -p:PublishTrimmed=false -o $boot
 if($LASTEXITCODE -ne 0){throw 'Falha ao publicar BootWorker.'}
 & dotnet publish (Join-Path $root 'BackupWorker\BackupWorker.csproj') -p:Version=$version -c Release -r $Arch --self-contained true -p:PublishTrimmed=false -o $backup
 if($LASTEXITCODE -ne 0 -or -not(Test-Path (Join-Path $backup 'TIDigital.BackupWorker.exe'))){throw 'Falha ao publicar BackupWorker.'}
 if(-not (Test-Path (Join-Path $publish 'Arcoll.WinUI3.exe')) -or -not (Test-Path (Join-Path $boot 'TIDigital.BootPresence.exe'))){throw 'Binarios esperados ausentes.'}
 New-Item -ItemType Directory -Path (Join-Path $Output 'files') -Force | Out-Null
 Copy-Item -Path (Join-Path $publish '*') -Destination (Join-Path $Output 'files') -Recurse -Force
 New-Item -ItemType Directory -Path (Join-Path $Output 'files\BootWorker') -Force|Out-Null
 Copy-Item -Path (Join-Path $boot '*') -Destination (Join-Path $Output 'files\BootWorker') -Recurse -Force
 New-Item -ItemType Directory -Path (Join-Path $Output 'files\BackupWorker') -Force|Out-Null
 Copy-Item -Path (Join-Path $backup '*') -Destination (Join-Path $Output 'files\BackupWorker') -Recurse -Force
 foreach($script in @('INSTALAR_BACKUP_SERVICO.ps1','ATUALIZACAO_VISUAL.ps1','PreLogin-Setup.ps1','HABILITAR_PRELOGIN.ps1')){Copy-Item -LiteralPath (Join-Path $root $script) -Destination (Join-Path $Output 'files' $script) -Force}
 $hashes=[ordered]@{}
 foreach($file in (Get-ChildItem (Join-Path $Output 'files') -File -Recurse | Sort-Object FullName)){
  $rel=$file.FullName.Substring((Join-Path $Output 'files').Length+1).Replace('\','/')
  if($rel -eq 'agent-config.json'){throw 'Segredo encontrado nos arquivos da release.'}
  if($rel -notmatch '^[a-zA-Z0-9._/-]{1,240}$' -or $rel -match '(^|/)\.\.?(/|$)'){throw ('Caminho invalido: '+$rel)}
  $hashes[$rel]=(Get-FileHash $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
 }
 $manifest=[ordered]@{version=$version;arch=$Arch;files=$hashes}|ConvertTo-Json -Depth 8
 [IO.File]::WriteAllText((Join-Path $Output 'release.json'),$manifest,(New-Object Text.UTF8Encoding($false)))
 Move-Item -LiteralPath $Output -Destination $finalOutput -ErrorAction Stop
 Write-Host ('Release precompilada criada em: '+$finalOutput)
 Write-Host ('Publique o CONTEUDO desta pasta sob agentes/releases/'+$Arch+'/current no servidor via upload seguro. Os binarios nao sao compilados em PHP.')
}finally{if(Test-Path $Output){Remove-Item -LiteralPath $Output -Recurse -Force -ErrorAction SilentlyContinue};foreach($dir in @($publish,$boot,$backup)){if(Test-Path $dir){Remove-Item $dir -Recurse -Force -ErrorAction SilentlyContinue}}}
