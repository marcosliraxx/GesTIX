# Caminho: testes temporarios de validacao/ribbon-layout.ps1 (nao distribuido)
$ErrorActionPreference='Stop'
if(-not [Environment]::UserInteractive -or [Diagnostics.Process]::GetCurrentProcess().SessionId -eq 0){throw 'Desktop interativo indisponivel para validar o layout'}
Add-Type -AssemblyName UIAutomationClient
Add-Type -AssemblyName UIAutomationTypes
$publish=Join-Path $env:RUNNER_TEMP 'winui-ribbon'
$assets=Join-Path $publish 'Assets';New-Item -ItemType Directory -Force $assets|Out-Null
$catalog=1..80|ForEach-Object{@{Name="Aplicativo longo de validacao numero $_";Id="Test.App$_";Category="Grupo $([Math]::Floor(($_-1)/10))"}}
$catalog|ConvertTo-Json|Set-Content -Encoding UTF8 (Join-Path $assets 'program-catalog.json')
$p=Start-Process (Join-Path $publish 'Arcoll.WinUI3.exe') -PassThru
try{
 $root=$null
 for($i=0;$i -lt 40;$i++){
  if($p.HasExited){throw "Agente encerrou no inicio: $($p.ExitCode)"}
  $root=[Windows.Automation.AutomationElement]::RootElement.FindFirst([Windows.Automation.TreeScope]::Children,(New-Object Windows.Automation.PropertyCondition([Windows.Automation.AutomationElement]::ProcessIdProperty,$p.Id)))
  if($root){break};Start-Sleep -Milliseconds 500
 }
 if(-not $root){throw 'Janela nativa nao encontrada'}
 function Find($id){
  for($j=0;$j -lt 20;$j++){
   $n=$root.FindFirst([Windows.Automation.TreeScope]::Descendants,(New-Object Windows.Automation.PropertyCondition([Windows.Automation.AutomationElement]::AutomationIdProperty,$id)))
   if($n){return $n};Start-Sleep -Milliseconds 200
  };throw "Controle ausente: $id"
 }
 function Invoke($id){$n=Find $id;$n.GetCurrentPattern([Windows.Automation.InvokePattern]::Pattern).Invoke();Start-Sleep -Milliseconds 300}
 $groups=@{home=@('panel','server','internet');maintenance=@('clean','programs');network=@('devices','map','files');service=@('support','tickets')}
 foreach($group in @('home','maintenance','network','service')){
  Invoke "category-$group"
  foreach($menu in $groups[$group]){Find "nav-$menu"|Out-Null}
 }
 Find 'remote-access'|Out-Null;Find 'quick-refresh'|Out-Null;Find 'quick-panel'|Out-Null
 Invoke 'category-maintenance';Invoke 'nav-programs'
 $transform=$root.GetCurrentPattern([Windows.Automation.TransformPattern]::Pattern)
 foreach($size in @(@(940,700),@(640,560))){
  $transform.Resize($size[0],$size[1]);Start-Sleep -Milliseconds 500
  $window=$root.Current.BoundingRectangle
  foreach($id in @('program-search','program-category','program-list','program-install')){
   $n=Find $id;$b=$n.Current.BoundingRectangle
   if($b.Width -le 0 -or $b.Height -le 0 -or $b.Left -lt $window.Left -or $b.Right -gt $window.Right+1 -or $b.Top -lt $window.Top -or $b.Bottom -gt $window.Bottom+1){throw "Controle fora da janela $id em $size : $b"}
  }
  $install=Find 'program-install';if($install.Current.IsEnabled){throw 'Instalacao liberada sem agente autorizado'}
 }
 Write-Host 'PASS: janela real, quatro categorias, dez menus, acessos rapidos, Programas em 940x700 e 640x560, bloqueio de instalacao sem autorizacao; sem capturas de tela.'
}finally{if(-not $p.HasExited){Stop-Process -Id $p.Id -Force}}
