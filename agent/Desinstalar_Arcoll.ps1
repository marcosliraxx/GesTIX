# Caminho: agent/Desinstalar_Arcoll.ps1
# Arcoll Speed Connection - desinstalação local sob confirmação do usuário
# Nunca remove dados armazenados no servidor.
param([switch]$Elevated)
$ErrorActionPreference='Stop'
$installed='C:\Arcoll'
if (-not (Test-Path -LiteralPath (Join-Path $installed 'arcoll_desktop.py'))) {
    Add-Type -AssemblyName System.Windows.Forms
    [System.Windows.Forms.MessageBox]::Show('A instalação em C:\Arcoll não foi encontrada.','Arcoll Speed Connection') | Out-Null
    exit 1
}
Add-Type -AssemblyName System.Windows.Forms
if (-not $Elevated) {
  $confirm=[System.Windows.Forms.MessageBox]::Show('Remover o agente Arcoll, seus atalhos e a configuração local deste computador? Os dados da plataforma serão preservados.','Desinstalar Arcoll',[System.Windows.Forms.MessageBoxButtons]::YesNo,[System.Windows.Forms.MessageBoxIcon]::Warning)
  if ($confirm -ne [System.Windows.Forms.DialogResult]::Yes) {exit 0}
  # Copia o desinstalador para fora da pasta que será removida.
  $tempScript=Join-Path $env:TEMP 'Arcoll-Desinstalar.ps1'
  Copy-Item -LiteralPath $PSCommandPath -Destination $tempScript -Force
  try {
    Start-Process -FilePath 'powershell.exe' -Verb RunAs -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-STA','-File',('"'+$tempScript+'"'),'-Elevated') | Out-Null
  } catch {
    [System.Windows.Forms.MessageBox]::Show('O Windows não autorizou a desinstalação. '+$_.Exception.Message,'Arcoll Speed Connection') | Out-Null
    exit 1
  }
  exit 0
}
$identity=[Security.Principal.WindowsIdentity]::GetCurrent()
if (-not ([Security.Principal.WindowsPrincipal]$identity).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {throw 'Permissão de administrador necessária para remover C:\Arcoll.'}
$logDir=Join-Path $env:LOCALAPPDATA 'Arcoll'
New-Item -ItemType Directory -Force -Path $logDir | Out-Null
$log=Join-Path $logDir 'uninstall.log'
try {
  Start-Sleep -Milliseconds 1250
  $root=[IO.Path]::GetFullPath($installed).TrimEnd('\') + '\'
  Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
    $_.ProcessId -ne $PID -and $_.Name -match '^pythonw?\.exe$' -and
    $_.CommandLine -and $_.CommandLine.Contains($root) -and
    $_.CommandLine -match '(?i)arcoll_(agent|desktop|app)\.py'
  } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
  foreach ($folder in @([Environment]::GetFolderPath('Desktop'),[Environment]::GetFolderPath('Startup'))) {
    $lnk=Join-Path $folder 'Arcoll Speed Connection.lnk'
    if (Test-Path -LiteralPath $lnk) {Remove-Item -LiteralPath $lnk -Force}
  }
  $config=Join-Path $env:APPDATA 'Arcoll\agent.json'
  if (Test-Path -LiteralPath $config) {Remove-Item -LiteralPath $config -Force}
  Remove-Item -LiteralPath $installed -Recurse -Force
  [System.Windows.Forms.MessageBox]::Show('Agente Arcoll desinstalado. Os dados da plataforma foram preservados.','Arcoll Speed Connection') | Out-Null
} catch {
  Add-Content -LiteralPath $log -Value $_.Exception.ToString() -Encoding UTF8
  [System.Windows.Forms.MessageBox]::Show('Não foi possível concluir. Consulte '+$log+"`r`n"+$_.Exception.Message,'Arcoll Speed Connection') | Out-Null
  exit 1
}
