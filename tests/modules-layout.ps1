# Caminho: testes temporarios de validacao/modules-layout.ps1 (nao distribuido)
$ErrorActionPreference='Stop'
if(-not [Environment]::UserInteractive -or [Diagnostics.Process]::GetCurrentProcess().SessionId -eq 0){throw 'Desktop interativo indisponivel para validar o layout'}
Add-Type -AssemblyName UIAutomationClient
Add-Type -AssemblyName UIAutomationTypes
$publish=Join-Path $env:RUNNER_TEMP 'winui-modules'
$assets=Join-Path $publish 'Assets';New-Item -ItemType Directory -Force $assets|Out-Null
$catalog=1..80|ForEach-Object{@{Name="Aplicativo longo de validacao numero $_";Id="Test.App$_";Category="Grupo $([Math]::Floor(($_-1)/10))"}}
$catalog|ConvertTo-Json|Set-Content -Encoding UTF8 (Join-Path $assets 'program-catalog.json')
@{catalog=@{user_temp=@{label='Arquivos temporarios';group='Windows';tab='windows';default=$true;hint='Sintetico'};recent_docs=@{label='Documentos recentes';group='Privacidade';tab='browser';default=$false;hint='Sintetico'}};registry=@{app_paths=@('Caminhos','Verificar caminhos')};health=@{space=@('user_temp');privacy=@('recent_docs')};ages=@(0,1,7,30)}|ConvertTo-Json -Depth 8|Set-Content -Encoding UTF8 (Join-Path $assets 'clean-catalog.json')

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
 $groups=@{home=@('panel','server','internet','health');maintenance=@('clean','registry','startup','performance','restore','options');software=@('programs','uninstall','updater','drivers','windows_update');storage=@('disk','duplicates','cloud','backup');network=@('devices','map','files');service=@('support','tickets')}
 foreach($group in @('home','maintenance','software','storage','network','service')){
  Invoke "category-$group"
  foreach($menu in $groups[$group]){
   Find "nav-$menu"|Out-Null
   if($menu -in @('health','clean','registry','startup','performance','restore','options','uninstall','updater','drivers','disk','duplicates','cloud','backup')){
    Invoke "nav-$menu";Find "filter-$menu"|Out-Null
    $buttons=$root.FindAll([Windows.Automation.TreeScope]::Descendants,(New-Object Windows.Automation.PropertyCondition([Windows.Automation.AutomationElement]::ControlTypeProperty,[Windows.Automation.ControlType]::Button)))
    foreach($b in $buttons){if($b.Current.Name -match '^(Corrigir selecionados|Instalar drivers selecionados|Enviar selecionados|Limpar sele|Executar backup|Criar ponto|Desinstalar .+)'){if($b.Current.IsEnabled){throw "Acao liberada sem autorizacao: $($b.Current.Name)"}}}
   }
  }
 }
 Find 'remote-access'|Out-Null;Find 'quick-refresh'|Out-Null;Find 'quick-panel'|Out-Null
 Invoke 'category-software';Invoke 'nav-programs'
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
 Write-Host 'PASS: janela real, seis categorias, 24 menus e 14 modulos independentes, acessos rapidos, Programas em 940x700 e 640x560, bloqueio de instalacao sem autorizacao; sem capturas de tela.'
}finally{if(-not $p.HasExited){Stop-Process -Id $p.Id -Force}}
