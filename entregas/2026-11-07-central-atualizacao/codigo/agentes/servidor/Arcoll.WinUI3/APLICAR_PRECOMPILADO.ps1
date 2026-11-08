# Aplicador da release WinUI3 precompilada. Executado com privilegios do usuario do agente.
[CmdletBinding()]
param([int]$ParentPid=0,[string]$UpdateId='',[switch]$Integrated)
$ErrorActionPreference='Stop'
$base=Join-Path $env:LOCALAPPDATA 'TI-Digital'
$dest=Join-Path $base 'WinUI3'
$work=$PSScriptRoot
$stage=$dest+'.stage-'+[guid]::NewGuid().ToString('N')
$backup=$dest+'.backup-'+[guid]::NewGuid().ToString('N')
$logs=Join-Path $base 'Logs'
New-Item -ItemType Directory -Force -Path $logs | Out-Null
Start-Transcript -Path (Join-Path $logs ('Atualizacao-Binaria-'+(Get-Date -Format yyyyMMdd-HHmmss)+'.log')) -Force | Out-Null
$swapped=$false;$started=$false;$taskSuspended=$false;$inPlace=$false
$robocopy=Join-Path $env:SystemRoot 'System32\robocopy.exe'
# Encerra o que mantem a pasta ocupada: agente, worker de presenca e processos do WebView2 (que sobrevivem ao agente).
function Stop-Holders([string]$Folder){
 $prefix=$Folder.TrimEnd('\')+'\'; $webProfile=Join-Path $env:LOCALAPPDATA 'TI-Digital\HybridWebView2'
 foreach($proc in @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue)){
  if([int]$proc.ProcessId -eq $PID){continue}
  $hit=$false
  if($proc.ExecutablePath -and $proc.ExecutablePath.StartsWith($prefix,[StringComparison]::OrdinalIgnoreCase)){$hit=$true}
  elseif($proc.Name -eq 'msedgewebview2.exe' -and $proc.CommandLine -and ($proc.CommandLine.IndexOf($webProfile,[StringComparison]::OrdinalIgnoreCase) -ge 0 -or $proc.CommandLine.IndexOf($Folder,[StringComparison]::OrdinalIgnoreCase) -ge 0)){$hit=$true}
  if($hit){try{Stop-Process -Id ([int]$proc.ProcessId) -Force -ErrorAction Stop}catch{}}
 }
}
function Receipt([bool]$ok,[string]$message) {
 $data=@{update_id=$UpdateId;ok=$ok;message=$message;finished=[DateTimeOffset]::UtcNow.ToUnixTimeSeconds()} | ConvertTo-Json -Compress
 foreach($name in @('update-result.json','update-ui-result.json')) {
  $target=Join-Path $base $name;$temp=$target+'.'+[guid]::NewGuid().ToString('N')
  [IO.File]::WriteAllText($temp,$data,(New-Object Text.UTF8Encoding($false)))
  Move-Item -LiteralPath $temp -Destination $target -Force
 }
}
function State([int]$percent,[string]$message) {
 $data=@{update_id=$UpdateId;percent=$percent;message=$message}|ConvertTo-Json -Compress
 [IO.File]::WriteAllText((Join-Path $base 'update-progress.json'),$data,(New-Object Text.UTF8Encoding($false)))
}

# Two-way handoff: a running UI explicitly acknowledges staging before any file replacement.
function Invoke-IntegratedHandoff([string]$Id,[int]$AgentPid,[string]$StateRoot) {
    if($Id -notmatch '^[a-f0-9]{32}$' -or $AgentPid -le 0){throw 'Identificacao da atualizacao integrada invalida.'}
    $agent=Get-Process -Id $AgentPid -ErrorAction Stop
    $expected=Join-Path $StateRoot 'WinUI3\Arcoll.WinUI3.exe'
    if($agent.ProcessName -ne 'Arcoll.WinUI3' -or $agent.Path -ne $expected){throw 'Processo pai nao pertence ao agente instalado.'}
    $file=Join-Path $StateRoot 'update-handoff.json'
    $temp=$file+'.'+[guid]::NewGuid().ToString('N')
    [IO.File]::WriteAllText($temp,(@{update_id=$Id;ready=$true}|ConvertTo-Json -Compress),(New-Object Text.UTF8Encoding($false)))
    Move-Item -LiteralPath $temp -Destination $file -Force
    $ack=Join-Path $StateRoot 'update-handoff-ack.json'
    $until=(Get-Date).AddSeconds(45);$approved=$false
    while((Get-Date) -lt $until){
        if(Test-Path -LiteralPath $ack){try{$value=Get-Content -LiteralPath $ack -Raw -Encoding UTF8|ConvertFrom-Json;if([string]$value.update_id -eq $Id){$approved=$true;break}}catch{}}
        if($agent.HasExited){throw 'O agente encerrou sem confirmar a troca; instalacao anterior preservada.'}
        Start-Sleep -Milliseconds 200
    }
    if(-not $approved){throw 'A interface nao confirmou a troca; instalacao anterior preservada.'}
    if(-not $agent.WaitForExit(25000)){Stop-Process -Id $AgentPid -Force -ErrorAction Stop;if(-not $agent.WaitForExit(10000)){throw 'O executavel anterior nao foi liberado.'}}
}

function Safe([string]$s){return $s -match '^[a-zA-Z0-9._/-]{1,240}$' -and $s -notmatch '(^|/)\.\.?(/|$)' -and $s -notmatch '//'}
try {
 if(-not (Test-Path (Join-Path $dest 'Arcoll.WinUI3.exe'))) {throw 'Instalacao anterior nao encontrada.'}
 $release=Get-Content -LiteralPath (Join-Path $work 'release.json') -Raw -Encoding UTF8|ConvertFrom-Json
 if(-not ($release.version -match '^\d+\.\d+\.\d+$')) {throw 'Versao invalida.'}
 State 78 'Preparando delta precompilado'
 # Copiar tudo antes de encerrar o agente: preserva configuracoes e permite rollback.
 # robocopy e muito mais rapido que Copy-Item para centenas de arquivos; codigos 0-7 indicam sucesso.
 $copied=$false
 $robocopy=Join-Path $env:SystemRoot 'System32\robocopy.exe'
 if(Test-Path -LiteralPath $robocopy){
  & $robocopy $dest $stage /E /R:1 /W:1 /NFL /NDL /NJH /NJS /NP | Out-Null
  if($LASTEXITCODE -lt 8){$copied=$true}else{Write-Warning ('robocopy falhou ('+$LASTEXITCODE+'); usando Copy-Item.');if(Test-Path -LiteralPath $stage){Remove-Item -LiteralPath $stage -Recurse -Force -ErrorAction SilentlyContinue}}
 }
 if(-not $copied){Copy-Item -LiteralPath $dest -Destination $stage -Recurse -Force}
 $files=$release.files.PSObject.Properties
 if(@($files).Count -lt 2 -or @($files).Count -gt 3500){throw 'Manifesto incompleto ou excedido.'}
 foreach($entry in $files){
  $rel=[string]$entry.Name; $sha=[string]$entry.Value
  if(-not (Safe $rel) -or $sha -notmatch '^[a-fA-F0-9]{64}$' -or $rel -eq 'agent-config.json') {throw ('Manifesto inseguro: '+$rel)}
  $target=Join-Path $stage ($rel.Replace('/','\'))
  $incoming=Join-Path $work ('files\'+$rel.Replace('/','\'))
  if(Test-Path -LiteralPath $incoming){
   New-Item -ItemType Directory -Path (Split-Path -Parent $target) -Force | Out-Null
   Copy-Item -LiteralPath $incoming -Destination $target -Force
  }
  if(-not (Test-Path -LiteralPath $target -PathType Leaf)){throw ('Arquivo ausente no delta: '+$rel)}
  # So os arquivos recebidos precisam de nova conferencia: os demais foram validados por SHA-256 pelo proprio agente
  # antes do pedido (e continuam identicos ao manifesto). Reler todos os arquivos so atrasava a troca.
  if((Test-Path -LiteralPath $incoming) -and ((Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash -ne $sha.ToUpperInvariant())){throw ('Hash divergente: '+$rel)}
 }
 # Nao apagar componentes desconhecidos e dados pessoais, apenas substituir os arquivos versionados.
 if(-not (Test-Path -LiteralPath (Join-Path $stage 'Arcoll.WinUI3.exe'))){throw 'Executavel nao encontrado no staging.'}
 State 87 'Arquivos preparados; reiniciando o agente'
 if($Integrated){Invoke-IntegratedHandoff $UpdateId $ParentPid $base}
 # Suspender apenas a tarefa do agente instalada neste mesmo perfil.
 try {
  $name='TI-Digital Agente Windows'
  $task=Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
  if($task -and $task.State -ne 'Disabled' -and @($task.Actions | Where-Object { ([string]$_.Execute).Trim('"') -eq (Join-Path $dest 'Arcoll.WinUI3.exe') }).Count){
   Disable-ScheduledTask -TaskName $name -ErrorAction Stop|Out-Null
   $taskSuspended=$true
  }
 }catch{Write-Warning ('Tarefa de inicializacao: '+$_.Exception.Message)}
 if($ParentPid -gt 0){
  $parent=Get-Process -Id $ParentPid -ErrorAction SilentlyContinue
  if($parent -and $parent.ProcessName -eq 'Arcoll.WinUI3' -and -not $parent.WaitForExit(25000)) {
   $expectedExe=Join-Path $dest 'Arcoll.WinUI3.exe'
   if($parent.Path -ne $expectedExe){throw 'Processo pai nao pertence a instalacao ativa; atualizacao cancelada.'}
   Stop-Process -Id $ParentPid -Force -ErrorAction Stop
   if(-not $parent.WaitForExit(10000)){throw 'O agente anterior nao liberou o executavel.'}
  }
 }
 Stop-Holders $dest
 $moved=$false
 for($i=0;$i -lt 6;$i++){
  Stop-Holders $dest
  try { Move-Item -LiteralPath $dest -Destination $backup -ErrorAction Stop; $moved=$true; $swapped=$true; break }
  catch { Write-Host ('Aguardando liberar a pasta, tentativa '+($i+1)+'/6: '+$_.Exception.Message); Start-Sleep -Milliseconds 1200 }
 }
 if($moved){ Move-Item -LiteralPath $stage -Destination $dest -ErrorAction Stop }
 else {
  # Explorer aberto, antivirus ou handle residual impedem renomear a pasta, mas nao trocar os arquivos.
  State 90 'Pasta em uso; trocando arquivos diretamente'
  if(-not (Test-Path -LiteralPath $robocopy)){throw 'Pasta em uso e robocopy indisponivel. Instalacao anterior preservada.'}
  & $robocopy $dest $backup /E /R:2 /W:1 /NFL /NDL /NJH /NJS /NP | Out-Null
  if($LASTEXITCODE -ge 8){throw ('Nao foi possivel copiar a instalacao anterior (robocopy '+$LASTEXITCODE+'). Instalacao anterior preservada.')}
  $inPlace=$true; $swapped=$true
  Stop-Holders $dest
  & $robocopy $stage $dest /E /IS /IT /R:5 /W:2 /NFL /NDL /NJH /NJS /NP | Out-Null
  if($LASTEXITCODE -ge 8){throw ('Falha ao gravar os arquivos do agente (robocopy '+$LASTEXITCODE+'). Verifique permissoes e antivirus.')}
 }
 State 94 'Reiniciando o agente'
 $exe=Join-Path $dest 'Arcoll.WinUI3.exe'
 $ready=Join-Path $base 'native-ready.json'
 if(Test-Path $ready){Remove-Item -LiteralPath $ready -Force -ErrorAction SilentlyContinue}
 $proc=Start-Process -FilePath $exe -WorkingDirectory $dest -PassThru
 $started=$true;$verified=$false
 for($j=0;$j -lt 25;$j++){
  Start-Sleep -Seconds 2
  if($proc.HasExited){throw 'Novo agente encerrou inesperadamente.'}
  if(Test-Path $ready){
   try {
    $receipt=Get-Content -LiteralPath $ready -Raw|ConvertFrom-Json
    if([int]$receipt.pid -eq $proc.Id -and [string]$receipt.version -eq [string]$release.version -and
       [int64]$receipt.approved_at -ge ([DateTimeOffset]::UtcNow.ToUnixTimeSeconds()-100)){$verified=$true;break}
   }catch{}
  }
 }
 if(-not $verified){throw 'Nova versao nao confirmou autenticacao e prontidao.'}
 State 100 'Agente atualizado e autorizado'
 Receipt $true ('Agente atualizado para '+$release.version+' com binarios precompilados.')
 # Limpa apenas copia de seguranca desta operacao apos validacao.
 if(Test-Path $backup){try{Remove-Item -LiteralPath $backup -Recurse -Force -ErrorAction Stop}catch{Write-Warning 'Backup mantido para limpeza posterior.'}}
 exit 0
}catch{
 $why=$_.Exception.Message
 Write-Warning ('Atualizacao binaria falhou: '+$why)
 if($swapped){
  try {
   if($started){Get-Process -Name 'Arcoll.WinUI3' -ErrorAction SilentlyContinue | Where-Object {$_.Path -eq (Join-Path $dest 'Arcoll.WinUI3.exe')} | Stop-Process -Force -ErrorAction SilentlyContinue}
   if($inPlace){
    Stop-Holders $dest
    & $robocopy $backup $dest /E /IS /IT /R:5 /W:2 /NFL /NDL /NJH /NJS /NP | Out-Null
    Start-Process -FilePath (Join-Path $dest 'Arcoll.WinUI3.exe') -WorkingDirectory $dest|Out-Null
   } else {
    if(Test-Path $dest){Move-Item -LiteralPath $dest -Destination ($dest+'.failed-'+[guid]::NewGuid().ToString('N'))}
    if(Test-Path $backup){Move-Item -LiteralPath $backup -Destination $dest; Start-Process -FilePath (Join-Path $dest 'Arcoll.WinUI3.exe') -WorkingDirectory $dest|Out-Null}
   }
  }catch{ $why+='; recuperacao manual necessaria: '+$_.Exception.Message }
 }
 # Nunca deixar o computador sem agente: reabre a instalacao existente se nenhuma instancia estiver ativa.
 if(-not $swapped){
  try{
   $oldExe=Join-Path $dest 'Arcoll.WinUI3.exe'
   $alive=@(Get-Process -Name 'Arcoll.WinUI3' -ErrorAction SilentlyContinue | Where-Object {try{$_.Path -eq $oldExe}catch{$false}})
   if(-not $alive.Count -and (Test-Path -LiteralPath $oldExe)){Start-Process -FilePath $oldExe -ArgumentList '--background' -WorkingDirectory $dest|Out-Null; $why+=' O agente anterior foi reaberto.'}
  }catch{}
 }
 Receipt $false $why
 exit 1
}finally {
 if($taskSuspended){try{Enable-ScheduledTask -TaskName 'TI-Digital Agente Windows' -ErrorAction Stop|Out-Null}catch{Write-Warning ('Tarefa do agente necessita reativacao: '+$_.Exception.Message)}}
 if(Test-Path $stage){Remove-Item -LiteralPath $stage -Recurse -Force -ErrorAction SilentlyContinue}
 try{Stop-Transcript|Out-Null}catch{}
}
