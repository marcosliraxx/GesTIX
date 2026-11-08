﻿# T.I. Digital - Instalador WPF. Windows PowerShell 5.1 / .NET Framework, sem SDK previo.
[CmdletBinding()]
param([switch]$ValidateOnly,[switch]$Preview,[string]$RenderPreview='',
      [ValidateSet('preparing','download','verify','compile','install','ready','failed')][string]$PreviewState='compile',
      [string]$ThemeOverrideBase64='')
$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12
$packageUrl='__PACKAGE_URL__'
$themeBase64='__INSTALLER_THEME_BASE64__'
if($ThemeOverrideBase64){$themeBase64=$ThemeOverrideBase64}
Add-Type -AssemblyName PresentationFramework,PresentationCore,WindowsBase,System.Xaml

function Protect-InstallText([string]$Text){
 if(-not $Text){return ''}
 $Text=[regex]::Replace($Text,'(?i)([?&](?:t|token|download_key)=)[^\s&''"]+','$1[oculto]')
 $Text=[regex]::Replace($Text,'(?i)(download_key["'':=\s]+)[a-f0-9]{64}','$1[oculto]')
 $Text=[regex]::Replace($Text,'\x1b\[[0-9;]*[A-Za-z]','')
 return $Text
}
function Get-InstallStage([int]$Percent){
 if($Percent -lt 8){return 0};if($Percent -lt 25){return 1};if($Percent -lt 35){return 2}
 if($Percent -lt 82){return 3};if($Percent -lt 100){return 4};return 5
}
function Read-InstallProgress([string[]]$Lines,[int]$Previous=0){
 $p=[Math]::Max(0,[Math]::Min(99,$Previous));$message='';$failure='';$pending='';$done=$false
 foreach($line in $Lines){$safe=Protect-InstallText ([string]$line)
  if($safe -match '^\[(\d{1,3})\]\s*(.+)$'){$n=[int]$matches[1];if($n -le 100){$p=[Math]::Max($p,[Math]::Min(99,$n));$message=$matches[2];if($n -eq 100){$done=$true}}}
  if($safe -match '^(ERRO|FALHA):\s*(.+)$'){$failure=$matches[2]}
  if($safe -match '^BOOT-PENDING:\s*(.+)$'){$pending=$matches[1]}
 }
 return @{Percent=$p;Message=$message;Failure=$failure;Pending=$pending;Done=$done}
}
function Get-InstallTheme([string]$Base64){
 $t=@{Id='graphite';Mode='theme';Bg='#16191f';Surface='#242a34';Alt='#303744';Text='#f1f4fa';Muted='#b0bbc9';Accent='#9aaee5';Line='#485364';Light=$false}
 try{$data=[Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($Base64))|ConvertFrom-Json
  foreach($pair in @(@('bg','Bg'),@('surface','Surface'),@('alt','Alt'),@('text','Text'),@('muted','Muted'),@('accent','Accent'),@('border','Line'))){$v=[string]$data.($pair[0]);if($v -match '^#[a-fA-F0-9]{6}$'){$t[$pair[1]]=$v}}
  $t.Light=([bool]$data.light);if($data.id){$t.Id=[string]$data.id};if([string]$data.mode -in @('theme','light','dark','auto')){$t.Mode=[string]$data.mode}
 }catch{}
 $light=$t.Light
 if($t.Mode -eq 'light'){$light=$true};if($t.Mode -eq 'dark'){$light=$false}
 if($t.Mode -eq 'auto'){try{$light=(Get-ItemProperty 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Themes\Personalize' -ErrorAction Stop).AppsUseLightTheme -ne 0}catch{$light=$true}}
 if($light -ne $t.Light){if($light){$t.Bg='#edf2f7';$t.Surface='#ffffff';$t.Alt='#e3ebf5';$t.Text='#203249';$t.Muted='#5d7189';$t.Line='#cfdae7'}else{$t.Bg='#0b1420';$t.Surface='#111f30';$t.Alt='#192c40';$t.Text='#e5edf8';$t.Muted='#9db0c8';$t.Line='#30485d'}}
 if($data -and $data.variants){$variant=if($light){$data.variants.light}else{$data.variants.dark};if($variant){foreach($pair in @(@('bg','Bg'),@('surface','Surface'),@('alt','Alt'),@('text','Text'),@('muted','Muted'),@('accent','Accent'),@('border','Line'))){$value=[string]$variant.($pair[0]);if($value -match '^#[a-fA-F0-9]{6}$'){$t[$pair[1]]=$value}}}}
 $t.Light=$light;return $t
}
function New-PrivateInstallFolder {
 $path=Join-Path ([IO.Path]::GetTempPath()) ('ti-digital-install-'+[guid]::NewGuid().ToString('N'))
 [IO.Directory]::CreateDirectory($path)|Out-Null
 $acl=New-Object Security.AccessControl.DirectorySecurity
 $acl.SetAccessRuleProtection($true,$false)
 foreach($sid in @([Security.Principal.WindowsIdentity]::GetCurrent().User,(New-Object Security.Principal.SecurityIdentifier('S-1-5-18')))){
  $rule=New-Object Security.AccessControl.FileSystemAccessRule($sid,'FullControl','ContainerInherit, ObjectInherit','None','Allow');$acl.AddAccessRule($rule)
 }
 [IO.Directory]::SetAccessControl($path,$acl);return $path
}
# O worker conserva o instalador operacional da versao recebida. Nunca compila no thread da janela.
$workerText=@'
param([Parameter(Mandatory=$true)][string]$ConfigPath)
$ErrorActionPreference='Stop';$ProgressPreference='SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12
[Console]::OutputEncoding=New-Object Text.UTF8Encoding($false)
function Safe([string]$text){return [regex]::Replace($text,'(?i)([?&](?:t|token|download_key)=)[^\s&''"]+','$1[oculto]')}
$response=$null;$stream=$null;$file=$null
try{
 $cfg=Get-Content -LiteralPath $ConfigPath -Raw -Encoding UTF8|ConvertFrom-Json
 $uri=New-Object Uri([string]$cfg.url);if($uri.Scheme -ne 'https' -or $uri.UserInfo){throw 'Endereco de instalacao invalido.'}
 $dir=Split-Path -Parent $ConfigPath;$archive=Join-Path $dir 'pacote.zip'
 Write-Output '[8] Conectando a plataforma...'
 $request=[Net.HttpWebRequest]::Create($uri);$request.AllowAutoRedirect=$false;$request.Timeout=45000;$request.ReadWriteTimeout=45000;$request.UserAgent='TI-Digital-Installer/5.0.152'
 $response=$request.GetResponse();if([int]$response.StatusCode -ne 200){throw 'A plataforma nao confirmou o download.'}
 $expected=[string]$response.Headers['X-Package-SHA256'];if($expected -notmatch '^[a-fA-F0-9]{64}$'){throw 'O pacote nao possui a verificacao SHA-256. Atualize a API da plataforma.'}
 if($response.ContentLength -gt 67108864){throw 'Pacote de instalacao acima do limite permitido.'}
 $stream=$response.GetResponseStream();$file=[IO.File]::Create($archive);$buffer=New-Object byte[] 81920;$total=0L;$last=8
 while(($n=$stream.Read($buffer,0,$buffer.Length)) -gt 0){$total+=$n;if($total -gt 67108864){throw 'Download acima do limite permitido.'};$file.Write($buffer,0,$n)
  if($response.ContentLength -gt 0){$p=8+[int][Math]::Floor(16.0*$total/$response.ContentLength);if($p -gt $last){$last=$p;Write-Output ('['+$p+'] Recebendo pacote da plataforma...')}}
 }
 $file.Dispose();$file=$null;$stream.Dispose();$stream=$null;$response.Dispose();$response=$null
 if($total -lt 1000){throw 'Pacote vazio ou incompleto.'}
 Write-Output '[25] Conferindo a integridade do pacote...'
 if((Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash -ne $expected){throw 'A integridade do pacote nao confere. Nenhum arquivo foi instalado.'}
 Add-Type -AssemblyName System.IO.Compression.FileSystem
 $zip=[IO.Compression.ZipFile]::OpenRead($archive)
 try{$expanded=0L;$names=New-Object 'Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
  if($zip.Entries.Count -gt 5000){throw 'Pacote com quantidade de arquivos invalida.'}
  foreach($entry in $zip.Entries){$name=$entry.FullName.Replace('\','/');$expanded+=$entry.Length
   if($name -notmatch '^Arcoll\.WinUI3/' -or $name -match '(^|/)\.\.(/|$)|:|^/' -or -not $names.Add($name) -or $expanded -gt 268435456){throw 'Estrutura do pacote invalida. Nenhum arquivo foi instalado.'}
  }
 }finally{$zip.Dispose()}
 Write-Output '[30] Extraindo arquivos verificados...'
 Expand-Archive -LiteralPath $archive -DestinationPath $dir -Force
 $installer=Join-Path $dir 'Arcoll.WinUI3\INSTALAR_WINUI3.ps1'
 if(-not(Test-Path -LiteralPath $installer)){throw 'Instalador operacional ausente no pacote.'}
 Write-Output '[35] Preparando o ambiente Windows...'
 $engine=Join-Path $PSHOME 'powershell.exe'
 & $engine -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $installer 2>&1|ForEach-Object{Write-Output (Safe ([string]$_))}
 if($LASTEXITCODE -ne 0){throw ('A instalacao nao foi concluida. Codigo: '+$LASTEXITCODE)}
 Write-Output '[100] Instalacao concluida. Agente iniciado.'
 exit 0
}catch{Write-Output ('FALHA: '+(Safe $_.Exception.Message));exit 1}
finally{if($file){$file.Dispose()};if($stream){$stream.Dispose()};if($response){$response.Dispose()}}
'@

if(-not ('TiInstaller.ProgressRing' -as [type])){
 Add-Type -ReferencedAssemblies @([Windows.Media.Brush].Assembly.Location,[Windows.FrameworkElement].Assembly.Location,[Windows.DependencyObject].Assembly.Location,[System.Xaml.XamlReader].Assembly.Location,[System.Uri].Assembly.Location) -TypeDefinition @'
using System;
using System.Windows;
using System.Windows.Media;
namespace TiInstaller {
 public sealed class ProgressRing:FrameworkElement {
  public static readonly DependencyProperty ProgressProperty=DependencyProperty.Register("Progress",typeof(double),typeof(ProgressRing),new FrameworkPropertyMetadata(0d,FrameworkPropertyMetadataOptions.AffectsRender));
  public static readonly DependencyProperty StrokeProperty=DependencyProperty.Register("Stroke",typeof(Brush),typeof(ProgressRing),new FrameworkPropertyMetadata(Brushes.DodgerBlue,FrameworkPropertyMetadataOptions.AffectsRender));
  public static readonly DependencyProperty TrackProperty=DependencyProperty.Register("Track",typeof(Brush),typeof(ProgressRing),new FrameworkPropertyMetadata(Brushes.SlateGray,FrameworkPropertyMetadataOptions.AffectsRender));
  public double Progress{get{return(double)GetValue(ProgressProperty);}set{SetValue(ProgressProperty,value);}}
  public Brush Stroke{get{return(Brush)GetValue(StrokeProperty);}set{SetValue(StrokeProperty,value);}}
  public Brush Track{get{return(Brush)GetValue(TrackProperty);}set{SetValue(TrackProperty,value);}}
  protected override void OnRender(DrawingContext dc){base.OnRender(dc);double radius=Math.Max(1,Math.Min(ActualWidth,ActualHeight)/2-9);Point center=new Point(ActualWidth/2,ActualHeight/2);dc.DrawEllipse(null,new Pen(Track,8),center,radius,radius);double p=Math.Max(0,Math.Min(100,Progress));if(p<=0)return;if(p>=100){dc.DrawEllipse(null,new Pen(Stroke,8),center,radius,radius);return;}double a=p*2*Math.PI/100;Point end=new Point(center.X+radius*Math.Sin(a),center.Y-radius*Math.Cos(a));var geometry=new StreamGeometry();using(var g=geometry.Open()){g.BeginFigure(new Point(center.X,center.Y-radius),false,false);g.ArcTo(end,new Size(radius,radius),0,p>50,SweepDirection.Clockwise,true,false);}var pen=new Pen(Stroke,8){StartLineCap=PenLineCap.Round,EndLineCap=PenLineCap.Round};dc.DrawGeometry(null,pen,geometry);}
 }
}
'@
}
$t=Get-InstallTheme $themeBase64
$xaml=@'
<Window xmlns="http://schemas.microsoft.com/winfx/2006/xaml/presentation" xmlns:x="http://schemas.microsoft.com/winfx/2006/xaml" Title="T.I. Digital · Instalação do Gestor" Width="820" Height="690" MinWidth="610" MinHeight="540" WindowStartupLocation="CenterScreen" ResizeMode="CanMinimize" FontFamily="Segoe UI" UseLayoutRounding="True" SnapsToDevicePixels="True">
 <Window.Resources>
  <SolidColorBrush x:Key="Bg" Color="#16191f"/><SolidColorBrush x:Key="Surface" Color="#242a34"/><SolidColorBrush x:Key="Alt" Color="#303744"/><SolidColorBrush x:Key="Text" Color="#f1f4fa"/><SolidColorBrush x:Key="Muted" Color="#b0bbc9"/><SolidColorBrush x:Key="Accent" Color="#9aaee5"/><SolidColorBrush x:Key="Line" Color="#485364"/>
  <Style TargetType="Button"><Setter Property="Foreground" Value="{DynamicResource Text}"/><Setter Property="Background" Value="{DynamicResource Alt}"/><Setter Property="BorderBrush" Value="{DynamicResource Line}"/><Setter Property="BorderThickness" Value="1"/><Setter Property="Padding" Value="15,9"/><Setter Property="MinHeight" Value="36"/><Setter Property="Cursor" Value="Hand"/><Setter Property="FontSize" Value="12"/><Setter Property="Template"><Setter.Value><ControlTemplate TargetType="Button"><Border x:Name="Box" Background="{TemplateBinding Background}" BorderBrush="{TemplateBinding BorderBrush}" BorderThickness="{TemplateBinding BorderThickness}" CornerRadius="8" Padding="{TemplateBinding Padding}"><ContentPresenter HorizontalAlignment="Center" VerticalAlignment="Center"/></Border><ControlTemplate.Triggers><Trigger Property="IsMouseOver" Value="True"><Setter TargetName="Box" Property="Opacity" Value="0.85"/></Trigger><Trigger Property="IsEnabled" Value="False"><Setter TargetName="Box" Property="Opacity" Value="0.4"/></Trigger><Trigger Property="IsKeyboardFocused" Value="True"><Setter TargetName="Box" Property="BorderThickness" Value="2"/></Trigger></ControlTemplate.Triggers></ControlTemplate></Setter.Value></Setter></Style>
 </Window.Resources>
 <Border x:Name="RootBorder" Background="{DynamicResource Bg}" Padding="28,18,28,0">
  <Grid><Grid.RowDefinitions><RowDefinition Height="Auto"/><RowDefinition Height="*"/><RowDefinition Height="Auto"/><RowDefinition Height="Auto"/></Grid.RowDefinitions>
   <Grid Margin="0,0,0,8"><Grid.ColumnDefinitions><ColumnDefinition Width="Auto"/><ColumnDefinition Width="*"/><ColumnDefinition Width="Auto"/></Grid.ColumnDefinitions><Border Width="34" Height="34" CornerRadius="10" Background="{DynamicResource Alt}"><TextBlock Text="T.I" Foreground="{DynamicResource Accent}" FontWeight="Bold" FontSize="14" HorizontalAlignment="Center" VerticalAlignment="Center"/></Border><StackPanel Grid.Column="1" Margin="10,0,0,0" VerticalAlignment="Center"><TextBlock Text="T.I. DIGITAL" Foreground="{DynamicResource Text}" FontSize="12" FontWeight="SemiBold"/><TextBlock Text="INSTALAÇÃO DO GESTOR WINDOWS" Foreground="{DynamicResource Muted}" FontSize="9" Margin="0,3,0,0"/></StackPanel><Border Grid.Column="2" CornerRadius="12" BorderBrush="{DynamicResource Line}" BorderThickness="1" Padding="10,5" VerticalAlignment="Center"><TextBlock x:Name="StageLabel" Text="PREPARANDO" Foreground="{DynamicResource Accent}" FontSize="9" FontWeight="SemiBold"/></Border></Grid>
   <ScrollViewer Grid.Row="1" VerticalScrollBarVisibility="Auto" HorizontalScrollBarVisibility="Disabled">
    <StackPanel Margin="0,8,0,16"><TextBlock x:Name="TitleText" Text="Seu ambiente começa aqui." Foreground="{DynamicResource Text}" HorizontalAlignment="Center" FontSize="26" FontWeight="SemiBold"/><TextBlock x:Name="SubtitleText" Text="Preparação, instalação e configuração em uma única janela." Foreground="{DynamicResource Muted}" HorizontalAlignment="Center" FontSize="12" Margin="0,8,0,0"/>
     <Grid Height="296" Margin="0,12,0,0"><Grid.ColumnDefinitions><ColumnDefinition Width="*"/><ColumnDefinition Width="Auto"/><ColumnDefinition Width="*"/></Grid.ColumnDefinitions>
      <StackPanel VerticalAlignment="Center" HorizontalAlignment="Right" Margin="0,0,6,0"><TextBlock x:Name="Step0" Text="○  Preparar" Foreground="{DynamicResource Muted}" FontSize="11" Margin="0,0,0,22"/><TextBlock x:Name="Step1" Text="○  Receber" Foreground="{DynamicResource Muted}" FontSize="11" Margin="0,0,0,22"/><TextBlock x:Name="Step2" Text="○  Validar" Foreground="{DynamicResource Muted}" FontSize="11"/></StackPanel>
      <Grid Grid.Column="1" Width="282" Height="282"><Ellipse Width="280" Height="280" Stroke="{DynamicResource Line}" StrokeThickness="1" Opacity="0.45"/><Grid x:Name="RingHost" Width="252" Height="252"/><Ellipse x:Name="Orbit" Width="272" Height="272" Stroke="{DynamicResource Accent}" StrokeThickness="2" StrokeDashArray="2,17" Opacity="0.4" RenderTransformOrigin="0.5,0.5"><Ellipse.RenderTransform><RotateTransform Angle="0"/></Ellipse.RenderTransform></Ellipse><StackPanel VerticalAlignment="Center" HorizontalAlignment="Center"><TextBlock x:Name="CircleTag" Text="INSTALANDO" Foreground="{DynamicResource Accent}" FontSize="9" FontWeight="SemiBold" HorizontalAlignment="Center"/><TextBlock x:Name="PercentText" Text="0%" Foreground="{DynamicResource Text}" FontSize="65" FontWeight="SemiBold" HorizontalAlignment="Center" Margin="0,5,0,0"/><TextBlock x:Name="CircleHint" Text="Etapas verificadas" Foreground="{DynamicResource Muted}" FontSize="10" HorizontalAlignment="Center"/></StackPanel></Grid>
      <StackPanel Grid.Column="2" VerticalAlignment="Center" HorizontalAlignment="Left" Margin="6,0,0,0"><TextBlock x:Name="Step3" Text="○  Preparar arquivos" Foreground="{DynamicResource Muted}" FontSize="11" Margin="0,0,0,22"/><TextBlock x:Name="Step4" Text="○  Instalar" Foreground="{DynamicResource Muted}" FontSize="11" Margin="0,0,0,22"/><TextBlock x:Name="Step5" Text="○  Concluir" Foreground="{DynamicResource Muted}" FontSize="11"/></StackPanel>
     </Grid><TextBlock x:Name="StatusText" Text="Verificando o ambiente Windows…" Foreground="{DynamicResource Text}" TextWrapping="Wrap" TextAlignment="Center" FontSize="14" Margin="10,4,10,0"/><TextBlock x:Name="DetailText" Text="A janela permanece disponível enquanto os arquivos são preparados." Foreground="{DynamicResource Muted}" TextWrapping="Wrap" TextAlignment="Center" FontSize="11" Margin="16,8,16,0"/><Border x:Name="NoticeBox" Visibility="Collapsed" Background="{DynamicResource Alt}" BorderBrush="{DynamicResource Line}" BorderThickness="1" CornerRadius="8" Padding="10" Margin="0,12,0,0"><TextBlock x:Name="NoticeText" Foreground="{DynamicResource Text}" TextWrapping="Wrap" FontSize="11"/></Border>
    </StackPanel>
   </ScrollViewer>
   <Expander x:Name="Diagnostics" Grid.Row="2" Header="Diagnóstico desta instalação" Foreground="{DynamicResource Muted}" FontSize="11" Margin="0,0,0,10"><TextBox x:Name="LogText" Height="110" Margin="0,9,0,0" IsReadOnly="True" TextWrapping="Wrap" VerticalScrollBarVisibility="Auto" FontFamily="Consolas" FontSize="10" Background="{DynamicResource Surface}" Foreground="{DynamicResource Text}" BorderBrush="{DynamicResource Line}" Padding="10"/></Expander>
   <Grid Grid.Row="3" Margin="-28,0,-28,0" Background="{DynamicResource Surface}"><Grid.ColumnDefinitions><ColumnDefinition Width="*"/><ColumnDefinition Width="Auto"/><ColumnDefinition Width="Auto"/></Grid.ColumnDefinitions><StackPanel Margin="28,12,8,12" VerticalAlignment="Center"><TextBlock x:Name="ElapsedText" Text="Preparando…" Foreground="{DynamicResource Muted}" FontSize="10"/><TextBlock x:Name="PreviewLabel" Text="PRÉVIA · DADOS DE EXEMPLO" Foreground="{DynamicResource Muted}" FontSize="8" Margin="0,4,0,0" Visibility="Collapsed"/></StackPanel><Button x:Name="CopyButton" Grid.Column="1" Content="Copiar diagnóstico" Margin="0,12,8,12"/><Button x:Name="FinishButton" Grid.Column="2" Content="Concluir" IsEnabled="False" Margin="0,12,28,12" Background="{DynamicResource Accent}"/></Grid>
  </Grid>
 </Border>
</Window>
'@
$window=[Windows.Markup.XamlReader]::Parse($xaml)
foreach($key in @('Bg','Surface','Alt','Text','Muted','Accent','Line')){$window.Resources[$key]=New-Object Windows.Media.SolidColorBrush([Windows.Media.ColorConverter]::ConvertFromString($t[$key]))}
$window.Background=$window.Resources['Bg'];$window.Foreground=$window.Resources['Text']
$controls=@{};foreach($name in @('RootBorder','StageLabel','TitleText','SubtitleText','RingHost','Orbit','CircleTag','PercentText','CircleHint','StatusText','DetailText','NoticeBox','NoticeText','Diagnostics','LogText','ElapsedText','PreviewLabel','CopyButton','FinishButton','Step0','Step1','Step2','Step3','Step4','Step5')){$controls[$name]=$window.FindName($name);if(-not $controls[$name]){throw ('Controle do instalador ausente: '+$name)}}
$script:ring=New-Object TiInstaller.ProgressRing
$script:ring.Width=252;$script:ring.Height=252;$script:ring.Stroke=$window.Resources['Accent'];$script:ring.Track=$window.Resources['Line'];$controls.RingHost.Children.Add($script:ring)|Out-Null
# Texto do botao sobre o destaque mantem contraste nos dez temas.
$accent=[Windows.Media.ColorConverter]::ConvertFromString($t.Accent)
$controls.FinishButton.Foreground=[Windows.Media.Brushes]::White
if(($accent.R*299+$accent.G*587+$accent.B*114)/1000 -gt 150){$controls.FinishButton.Foreground=[Windows.Media.Brushes]::Black}
$script:proc=$null;$script:complete=$false;$script:success=$false;$script:percent=0;$script:session='';$script:stdout='';$script:stderr='';$script:lastLength=-1L;$script:sawDone=$false;$script:failure='';$script:pending='';$script:started=[DateTime]::UtcNow
$script:stepNames=@('Preparar','Receber','Validar','Preparar arquivos','Instalar','Concluir')
function Show-InstallProgress([int]$Percent,[string]$Message,[string]$State='working'){
 $p=[Math]::Max(0,[Math]::Min(100,$Percent));$script:ring.Progress=$p;$controls.PercentText.Text=([string]$p)+'%';$controls.StatusText.Text=Protect-InstallText $Message
 $stage=Get-InstallStage $p;$controls.StageLabel.Text=$script:stepNames[$stage].ToUpperInvariant()
 for($i=0;$i -lt 6;$i++){$step=$controls[('Step'+$i)];$symbol='○';$brush=$window.Resources['Muted'];if($i -lt $stage){$symbol='✓';$brush=$window.Resources['Accent']}elseif($i -eq $stage){$symbol='●';$brush=$window.Resources['Text']};$step.Text=$symbol+'  '+$script:stepNames[$i];$step.Foreground=$brush}
 if($State -eq 'ready'){$script:ring.Stroke=New-Object Windows.Media.SolidColorBrush([Windows.Media.ColorConverter]::ConvertFromString('#22b78a'));$controls.CircleTag.Text='CONCLUÍDO';$controls.CircleHint.Text='Gestor instalado';$controls.StageLabel.Text='TUDO PRONTO';$controls.TitleText.Text='Seu gestor está pronto.';$controls.FinishButton.IsEnabled=$true;$controls.Orbit.Visibility='Collapsed';$controls.DetailText.Text='A instalação foi concluída e o gestor foi iniciado.';foreach($i in 0..5){$controls[('Step'+$i)].Text='✓  '+$script:stepNames[$i]}}
 elseif($State -eq 'failed'){$script:ring.Stroke=New-Object Windows.Media.SolidColorBrush([Windows.Media.ColorConverter]::ConvertFromString('#e26d7b'));$controls.CircleTag.Text='ATENÇÃO';$controls.CircleHint.Text='Instalação interrompida';$controls.StageLabel.Text='NÃO CONCLUÍDA';$controls.TitleText.Text='Vamos verificar o que aconteceu.';$controls.FinishButton.IsEnabled=$true;$controls.Orbit.Visibility='Collapsed';$controls.DetailText.Text='Consulte o diagnóstico abaixo. Não é necessário abrir outras janelas.';$controls.Diagnostics.IsExpanded=$true}
}
$timer=New-Object Windows.Threading.DispatcherTimer;$timer.Interval=[TimeSpan]::FromMilliseconds(450)
function Read-WorkerOutput {
 if(-not $script:stdout -or -not(Test-Path -LiteralPath $script:stdout)){return}
 $length=(Get-Item -LiteralPath $script:stdout).Length
 if($length -eq $script:lastLength){return};$script:lastLength=$length
 $lines=@(Get-Content -LiteralPath $script:stdout -Encoding UTF8 -Tail 160 -ErrorAction SilentlyContinue)
 $progress=Read-InstallProgress $lines $script:percent;$script:percent=$progress.Percent
 if($progress.Done){$script:sawDone=$true};if($progress.Failure){$script:failure=$progress.Failure};if($progress.Pending){$script:pending=$progress.Pending}
 $controls.LogText.Text=Protect-InstallText ($lines -join [Environment]::NewLine);if($controls.Diagnostics.IsExpanded){$controls.LogText.ScrollToEnd()}
 if($progress.Message){Show-InstallProgress $script:percent $progress.Message}
}
$timer.Add_Tick({
 try{
  Read-WorkerOutput
  $elapsed=[DateTime]::UtcNow-$script:started;$controls.ElapsedText.Text='Instalação · '+$elapsed.ToString('mm\:ss')+' · '+$t.Id
  if($script:proc -and $script:proc.HasExited -and -not $script:complete){
   Read-WorkerOutput;$script:complete=$true;$timer.Stop();$script:success=($script:proc.ExitCode -eq 0 -and $script:sawDone)
   if($script:success){Show-InstallProgress 100 'Instalação concluída. Gestor iniciado.' 'ready';if($script:pending){$controls.NoticeBox.Visibility='Visible';$controls.NoticeText.Text='O gestor foi instalado. '+$script:pending}}
   else{if(-not $script:failure){$script:failure='O processo encerrou sem confirmar a conclusão.'};Show-InstallProgress $script:percent $script:failure 'failed';if(Test-Path -LiteralPath $script:stderr){$extra=Get-Content -LiteralPath $script:stderr -Encoding UTF8 -Tail 12 -ErrorAction SilentlyContinue;$controls.LogText.AppendText([Environment]::NewLine+(Protect-InstallText ($extra -join [Environment]::NewLine)))}}
  }
 }catch{$controls.NoticeBox.Visibility='Visible';$controls.NoticeText.Text='O diagnóstico está temporariamente indisponível; a preparação continua em segundo plano.'}
})
$controls.CopyButton.Add_Click({try{[Windows.Clipboard]::SetText((Protect-InstallText ($controls.StatusText.Text+[Environment]::NewLine+$controls.LogText.Text)));$controls.NoticeBox.Visibility='Visible';$controls.NoticeText.Text='Diagnóstico copiado.'}catch{$controls.NoticeBox.Visibility='Visible';$controls.NoticeText.Text='Não foi possível copiar o diagnóstico.'}})
$controls.FinishButton.Add_Click({$window.Close()})
$window.Add_Closing({param($sender,$eventArgs)if(-not $script:complete -and -not $Preview -and -not $ValidateOnly -and -not $RenderPreview){$eventArgs.Cancel=$true;$controls.NoticeBox.Visibility='Visible';$controls.NoticeText.Text='A instalação ainda está em andamento. Você pode minimizar esta janela e acompanhar a conclusão.'}})
if($ValidateOnly){
 $checks=0
 foreach($value in @(-5,0,8,25,35,62,82,99,100,150)){if((Get-InstallStage $value) -notin 0..5){throw 'Etapa inválida'};$checks++}
 $p=Read-InstallProgress @('[62] Compilando...','[35] Preparação anterior','[100] Concluído') 50
 if($p.Percent -ne 99 -or -not $p.Done){throw 'Progresso sem confirmação deve parar em 99%'};$checks++
 $p=Read-InstallProgress @('[12] Baixando...','FALHA: download indisponível') 62
 if($p.Percent -ne 62 -or $p.Failure -ne 'download indisponível'){throw 'Regressão no estado de falha'};$checks++
 if((Protect-InstallText ('https://fixture.test/?t='+('a'*64))) -match ('a'*64)){throw 'Token exposto no diagnóstico'};$checks++
 if($controls.Count -lt 25){throw 'Janela incompleta'};$checks++
 foreach($state in @('working','ready','failed')){Show-InstallProgress 62 'Teste do estado' $state;$checks++}
 if($script:ring.Progress -ne 62){throw 'Porcentagem inventada'};$checks++
 $window.Close();Write-Output ('PASS '+$checks+' validações do instalador WPF; nenhum download ou instalação executados.');exit 0
}
if($Preview -or $RenderPreview){
 $controls.PreviewLabel.Visibility='Visible';$controls.ElapsedText.Text='PRÉVIA · '+$t.Id
 $previewPercent=@{preparing=0;download=16;verify=28;compile=62;install=88;ready=100;failed=62}[$PreviewState]
 $state='working';if($PreviewState -in @('ready','failed')){$state=$PreviewState}
 Show-InstallProgress $previewPercent (@{preparing='Verificando o ambiente Windows…';download='Recebendo os arquivos da plataforma…';verify='Conferindo a integridade SHA-256…';compile='Preparando os arquivos do gestor…';install='Instalando e configurando o gestor…';ready='Instalação concluída. Gestor iniciado.';failed='A conexão foi interrompida durante a preparação.'}[$PreviewState]) $state
 $controls.LogText.Text='PRÉVIA: dados de exemplo. Nenhuma instalação foi executada.';$script:complete=$true
 if($RenderPreview){$root=$controls.RootBorder;foreach($key in @($window.Resources.Keys)){$root.Resources[$key]=$window.Resources[$key]};$window.Content=$null;$root.Width=820;$root.Height=650;$root.Measure((New-Object Windows.Size(820,650)));$root.Arrange((New-Object Windows.Rect(0,0,820,650)));$root.UpdateLayout();$bitmap=New-Object Windows.Media.Imaging.RenderTargetBitmap(820,650,96,96,[Windows.Media.PixelFormats]::Pbgra32);$bitmap.Render($root);$encoder=New-Object Windows.Media.Imaging.PngBitmapEncoder;$encoder.Frames.Add([Windows.Media.Imaging.BitmapFrame]::Create($bitmap));$file=[IO.File]::Create([IO.Path]::GetFullPath($RenderPreview));try{$encoder.Save($file)}finally{$file.Dispose()};$window.Close();Write-Output 'Prévia WPF renderizada sem instalar.';exit 0}
}else{
 $window.Add_ContentRendered({
  try{
   $script:session=New-PrivateInstallFolder
   $logs=Join-Path $env:LOCALAPPDATA 'TI-Digital\logs';[IO.Directory]::CreateDirectory($logs)|Out-Null
   $script:stdout=Join-Path $logs ('Instalacao-'+(Get-Date -Format 'yyyyMMdd-HHmmss')+'-'+[guid]::NewGuid().ToString('N').Substring(0,8)+'.log')
   $script:stderr=Join-Path $script:session 'worker-error.log'
   $worker=Join-Path $script:session 'install-worker.ps1';$config=Join-Path $script:session 'install-job.json'
   [IO.File]::WriteAllText($worker,$workerText,(New-Object Text.UTF8Encoding($true)))
   [IO.File]::WriteAllText($config,(@{url=$packageUrl}|ConvertTo-Json -Compress),(New-Object Text.UTF8Encoding($false)))
   $engine=Join-Path $PSHOME 'powershell.exe';$arguments='-NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "'+$worker+'" -ConfigPath "'+$config+'"'
   $script:proc=Start-Process -FilePath $engine -ArgumentList $arguments -WindowStyle Hidden -PassThru -RedirectStandardOutput $script:stdout -RedirectStandardError $script:stderr
   $script:started=[DateTime]::UtcNow;$timer.Start()
   if([Windows.SystemParameters]::ClientAreaAnimation){$animation=New-Object Windows.Media.Animation.DoubleAnimation(0,360,(New-Object Windows.Duration([TimeSpan]::FromSeconds(16))));$animation.RepeatBehavior=[Windows.Media.Animation.RepeatBehavior]::Forever;$controls.Orbit.RenderTransform.BeginAnimation([Windows.Media.RotateTransform]::AngleProperty,$animation)}
  }catch{$script:complete=$true;Show-InstallProgress 0 ('Não foi possível iniciar: '+(Protect-InstallText $_.Exception.Message)) 'failed'}
 })
}
try{[void]$window.ShowDialog()}finally{$timer.Stop();if($script:session -and $script:complete){Remove-Item -LiteralPath $script:session -Recurse -Force -ErrorAction SilentlyContinue}}
if($Preview -or $script:success){exit 0};exit 1
