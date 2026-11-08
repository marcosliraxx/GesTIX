# Caminho: gestores/servidor/Arcoll.WinUI3/INSTALAR_BACKUP_SERVICO.ps1
#requires -RunAsAdministrator
# Executor dedicado de backup em tarefa SYSTEM. Não instala nem executa WinUI em Session 0.
[CmdletBinding()]
param([string]$SourceConfig='', [switch]$Remove)
$ErrorActionPreference='Stop'
$taskName='TIDigital-BackupCenter'
$destination=Join-Path $env:ProgramFiles 'TI-Digital\BackupWorker'
$data=Join-Path $env:ProgramData 'TI-Digital\BackupService'
if($Remove){Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction SilentlyContinue;Write-Host 'Tarefa removida. Configuração, cache e backups preservados.';exit}
$source=Join-Path $PSScriptRoot 'BackupWorker'
if(-not(Test-Path -LiteralPath (Join-Path $source 'TIDigital.BackupWorker.exe'))){throw 'BackupWorker compilado ausente. Publique o projeto BackupWorker antes de instalar.'}
if(-not $SourceConfig){foreach($candidate in @((Join-Path $env:ProgramData 'TI-Digital\Boot\gestor-config.json'),(Join-Path $PSScriptRoot 'gestor-config.json'),(Join-Path $env:APPDATA 'Arcoll\gestor.json'))){if(Test-Path -LiteralPath $candidate){$SourceConfig=$candidate;break}}}
if(-not $SourceConfig){throw 'Defina -SourceConfig com a configuração válida do gestor já autorizado.'}
$config=Get-Content -LiteralPath $SourceConfig -Raw|ConvertFrom-Json
$uri=[Uri]([string]$config.origin)
if($uri.Scheme -ne 'https' -or $uri.UserInfo -or $uri.Query -or $uri.Fragment -or [string]$config.fingerprint -notmatch '^[a-f0-9]{64}$' -or [string]$config.site_code -notmatch '^[A-Z0-9]{5}$' -or [string]$config.download_key -notmatch '^[a-fA-F0-9]{64}$'){throw 'Configuração inválida/incompleta. Nenhuma chave foi exibida.'}
Write-Host ('Plataforma: '+$uri.GetLeftPart([UriPartial]::Path))
Write-Host ('Identificação da rede: '+$config.site_code)
Write-Host 'O executor fará somente planos autorizados pela plataforma, inclusive antes do login. SYSTEM usa a conta da máquina para NAS/UNC; ajuste permissões/conta da tarefa no Windows se necessário.'
if((Read-Host 'Confira a origem e digite INSTALAR para confirmar') -cne 'INSTALAR'){throw 'Instalação cancelada.'}
function Protect-Folder([string]$Path){
 $ancestor=[IO.Path]::GetFullPath($Path)
 while($ancestor){if(Test-Path -LiteralPath $ancestor){if((Get-Item -LiteralPath $ancestor -Force).Attributes -band [IO.FileAttributes]::ReparsePoint){throw 'Ancestral redirecionado nao permitido.'}};$parent=[IO.Path]::GetDirectoryName($ancestor);if($parent -eq $ancestor){break};$ancestor=$parent}
 New-Item -ItemType Directory -Path $Path -Force|Out-Null
 $item=Get-Item -LiteralPath $Path -Force
 if($item.Attributes -band [IO.FileAttributes]::ReparsePoint){throw 'Pasta de instalação redirecionada.'}
 $acl=New-Object Security.AccessControl.DirectorySecurity
 $acl.SetAccessRuleProtection($true,$false)
 $admins=New-Object Security.Principal.SecurityIdentifier 'S-1-5-32-544'
 $system=New-Object Security.Principal.SecurityIdentifier 'S-1-5-18'
 $acl.SetOwner($admins)
 foreach($sid in @($admins,$system)){$acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new($sid,'FullControl','ContainerInherit,ObjectInherit','None','Allow'))}
 Set-Acl -LiteralPath $Path -AclObject $acl
}
Stop-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
Protect-Folder $destination
Protect-Folder $data
Copy-Item -Path (Join-Path $source '*') -Destination $destination -Recurse -Force
$payload=@{origin=$uri.AbsoluteUri.TrimEnd('/');fingerprint=[string]$config.fingerprint;site_code=[string]$config.site_code;download_key=[string]$config.download_key}|ConvertTo-Json -Compress
[IO.File]::WriteAllText((Join-Path $data 'gestor-config.json'),$payload,[Text.UTF8Encoding]::new($false))
$action=New-ScheduledTaskAction -Execute (Join-Path $destination 'TIDigital.BackupWorker.exe') -WorkingDirectory $destination
$trigger=New-ScheduledTaskTrigger -AtStartup
$principal=New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$settings=New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force|Out-Null
Start-ScheduledTask -TaskName $taskName
Write-Host 'Executor instalado. Verifique o contato na Central de Backup antes de encerrar a implantação.'
