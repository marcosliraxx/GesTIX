# Caminho: gestores/servidor/Arcoll.WinUI3/INSTALADOR_VISUAL_WINUI3.ps1
# Instalador grafico WinForms: progresso circular, etapas reais, sem consoles auxiliares visiveis.
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
[System.Windows.Forms.Application]::EnableVisualStyles()

$packageUrl = '__PACKAGE_URL__'
$logs = Join-Path $env:LOCALAPPDATA 'TI-Digital\logs'
New-Item -ItemType Directory -Path $logs -Force | Out-Null
$session = Join-Path $env:TEMP ('ti-digital-winui-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $session -Force | Out-Null
$worker = Join-Path $session 'instalar-worker.ps1'
$stdout = Join-Path $logs ('Instalador-Visual-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.log')
$stderr = Join-Path $logs ('Instalador-Visual-erro-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.log')
$workerText = @'
# Caminho: gestores/servidor/Arcoll.WinUI3/INSTALADOR_VISUAL_WINUI3.ps1 (processo interno)
param([Parameter(Mandatory=$true)][string]$Url,[Parameter(Mandatory=$true)][string]$WorkingDir)
$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
try {
  Write-Output '[10] Baixando os arquivos da nova interface Windows...'
  $archive=Join-Path $WorkingDir 'pacote.zip'
  Invoke-WebRequest -UseBasicParsing -Uri $Url -OutFile $archive -ErrorAction Stop
  if ((Get-Item -LiteralPath $archive).Length -lt 1000) { throw 'O pacote recebido esta vazio ou incompleto.' }
  Write-Output '[25] Verificando e extraindo pacote...'
  Expand-Archive -LiteralPath $archive -DestinationPath $WorkingDir -Force -ErrorAction Stop
  $installer=Join-Path $WorkingDir 'Arcoll.WinUI3\INSTALAR_WINUI3.ps1'
  if (-not (Test-Path -LiteralPath $installer)) { throw 'O instalador da interface WinUI3 nao existe no pacote.' }
  Write-Output '[35] Verificando .NET e preparando a compilacao...'
  & $installer 2>&1 |
    ForEach-Object { Write-Output ([string]$_) }
  if ($LASTEXITCODE -ne 0) { throw ('A instalacao nao foi concluida. Codigo: ' + $LASTEXITCODE) }
  Write-Output '[100] Instalacao finalizada com sucesso.'
  exit 0
} catch {
  Write-Output ('FALHA: ' + $_.Exception.Message)
  exit 1
}
'@
[IO.File]::WriteAllText($worker,$workerText,(New-Object System.Text.UTF8Encoding($true)))

$form = New-Object System.Windows.Forms.Form
$form.Text = 'T.I Digital | Instalacao do Gestor Windows'
$form.FormBorderStyle = 'FixedDialog'
$form.MaximizeBox = $false
$form.Size = New-Object System.Drawing.Size(700,590)
$form.MinimumSize = $form.Size
$form.MaximumSize = $form.Size
$form.StartPosition = 'CenterScreen'
$form.BackColor = [Drawing.Color]::FromArgb(12,23,39)
$form.ForeColor = [Drawing.Color]::White
$form.Font = New-Object Drawing.Font('Segoe UI',10)
$form.MaximizeBox = $false
$form.MinimizeBox = $false
$header = New-Object System.Windows.Forms.Label
$header.Text = 'T.I DIGITAL'
$header.Font = New-Object Drawing.Font('Segoe UI Semibold',11)
$header.ForeColor = [Drawing.Color]::FromArgb(85,212,246)
$header.SetBounds(32,23,610,25)
$form.Controls.Add($header)
$title = New-Object System.Windows.Forms.Label
$title.Text = 'Instalando o Gestor Windows'
$title.Font = New-Object Drawing.Font('Segoe UI Semibold',20)
$title.SetBounds(30,63,635,48)
$form.Controls.Add($title)
$subtitle = New-Object System.Windows.Forms.Label
$subtitle.Text = 'Aplicativo nativo C# + WinUI 3  |  Windows 10/11  |  Executa em segundo plano'
$subtitle.ForeColor = [Drawing.Color]::FromArgb(165,187,210)
$subtitle.SetBounds(33,114,640,28)
$form.Controls.Add($subtitle)
$status = New-Object System.Windows.Forms.Label
$status.Text = 'Preparando instalacao...'
$status.ForeColor = [Drawing.Color]::FromArgb(216,232,244)
$status.SetBounds(33,380,640,27)
$form.Controls.Add($status)
# Um unico painel e responsavel pelo anel, texto percentual e marcadores de etapas.
$ring = New-Object System.Windows.Forms.Panel
$ring.SetBounds(125,150,450,225)
$ring.BackColor = $form.BackColor
$ring.Add_Paint({
 param($sender,$e)
 $g=$e.Graphics
 $g.SmoothingMode=[Drawing.Drawing2D.SmoothingMode]::AntiAlias
 $bounds=New-Object Drawing.Rectangle(130,12,180,180)
 $track=New-Object Drawing.Pen(([Drawing.Color]::FromArgb(57,88,116)),7)
 $progress=New-Object Drawing.Pen(([Drawing.Color]::FromArgb(37,192,239)),8)
 try {
  $track.StartCap=[Drawing.Drawing2D.LineCap]::Round
  $track.EndCap=[Drawing.Drawing2D.LineCap]::Round
  $progress.StartCap=[Drawing.Drawing2D.LineCap]::Round
  $progress.EndCap=[Drawing.Drawing2D.LineCap]::Round
  $g.DrawArc($track,$bounds,-90,359)
  if ($script:shown -gt 0) { $g.DrawArc($progress,$bounds,-90,([single]([Math]::Min(359, $script:shown * 3.59)))) }
  $numberFont=New-Object Drawing.Font('Segoe UI Semibold',37,[Drawing.FontStyle]::Bold)
  $captionFont=New-Object Drawing.Font('Segoe UI',9)
  $badgeFont=New-Object Drawing.Font('Segoe UI',8)
  $white=New-Object Drawing.SolidBrush(([Drawing.Color]::FromArgb(235,246,255)))
  $muted=New-Object Drawing.SolidBrush(([Drawing.Color]::FromArgb(157,186,211)))
  try {
   $text=([int]$script:shown).ToString()+'%'
   $size=$g.MeasureString($text,$numberFont)
   $g.DrawString($text,$numberFont,$white,([single](220-$size.Width/2)),[single]69)
   $caption='Instalacao por etapas'
   $size=$g.MeasureString($caption,$captionFont)
   $g.DrawString($caption,$captionFont,$muted,([single](220-$size.Width/2)),[single]130)
   $tags=@('Verificando','Recebendo','Validando','Preparando','Reiniciando')
   $points=@(@(220,0),@(395,54),@(393,175),@(220,202),@(50,175))
   for($i=0;$i -lt $tags.Count;$i++) {
    $name=$tags[$i];$w=$g.MeasureString($name,$badgeFont).Width+13
    $brush=if($i -eq ([Math]::Min(4,[Math]::Floor($script:shown/22)))){[Drawing.Brushes]::DeepSkyBlue}else{$muted}
    $g.DrawString($name,$badgeFont,$brush,([single]($points[$i][0]-$w/2)),([single]$points[$i][1]))
   }
  } finally { $numberFont.Dispose();$captionFont.Dispose();$badgeFont.Dispose();$white.Dispose();$muted.Dispose() }
 } finally {$track.Dispose();$progress.Dispose()}
})
$form.Controls.Add($ring)
$steps = New-Object System.Windows.Forms.Label
$steps.Text = 'VERIFICAR   •   RECEBER   •   VALIDAR   •   PREPARAR   •   REINICIAR'
$steps.ForeColor = [Drawing.Color]::FromArgb(120,189,216)
$steps.SetBounds(33,413,630,25)
$form.Controls.Add($steps)
$details = New-Object System.Windows.Forms.TextBox
$details.Multiline = $true
$details.ReadOnly = $true
$details.ScrollBars = 'Vertical'
$details.BackColor = [Drawing.Color]::FromArgb(18,35,52)
$details.ForeColor = [Drawing.Color]::FromArgb(218,234,243)
$details.BorderStyle = 'FixedSingle'
$details.Font = New-Object Drawing.Font('Consolas',9)
$details.SetBounds(33,446,630,58)
$form.Controls.Add($details)
$finish = New-Object System.Windows.Forms.Button
$finish.Text = 'Concluir'
$finish.BackColor = [Drawing.Color]::FromArgb(13,120,173)
$finish.ForeColor = [Drawing.Color]::White
$finish.FlatStyle = 'Flat'
$finish.SetBounds(526,510,136,32)
$finish.Enabled = $false
$finish.FlatAppearance.BorderSize = 0
$finish.Add_Click({$form.Close()})
$form.Controls.Add($finish)
$logButton = New-Object System.Windows.Forms.Button
$logButton.Text = 'Abrir diagnostico'
$logButton.BackColor = [Drawing.Color]::FromArgb(32,54,70)
$logButton.ForeColor = [Drawing.Color]::White
$logButton.FlatStyle = 'Flat'
$logButton.SetBounds(33,510,175,32)
$logButton.Add_Click({Start-Process explorer.exe -ArgumentList @($logs)})
$form.Controls.Add($logButton)

$script:proc = $null
$script:complete = $false
$script:lastSize = -1
$script:lastStatus = ''
$script:target = 0
$script:shown = 0
$script:tick = 0
$timer = New-Object System.Windows.Forms.Timer
$timer.Interval = 600
$timer.Add_Tick({
 try {
   if (Test-Path -LiteralPath $stdout) {
     $fi = Get-Item -LiteralPath $stdout -ErrorAction SilentlyContinue
     if ($fi -and $fi.Length -ne $script:lastSize) {
       $script:lastSize = $fi.Length
       $all = [string](Get-Content -LiteralPath $stdout -Raw -ErrorAction SilentlyContinue)
       $marks = [regex]::Matches($all, '(?m)^\[(\d+)\]\s*(.*)$')
       if ($marks.Count) { $m = $marks[$marks.Count - 1]; $script:target = [Math]::Min(100, [int]$m.Groups[1].Value); $script:lastStatus = $m.Groups[2].Value.Trim() }
       $lines = @(Get-Content -LiteralPath $stdout -Tail 9 -ErrorAction SilentlyContinue)
       $details.Text = ($lines -join [Environment]::NewLine)
       $details.SelectionStart = $details.Text.Length
       $details.ScrollToCaret()
       foreach ($line in $lines) {
         if ($line -match 'Restaurando pacotes|Compilando a interface|SDK .NET|Instalado em|ERRO:|FALHA:') { $script:lastStatus = $line }
       }
       if ($script:lastStatus) { $status.Text = [string]$script:lastStatus }
     }
   }
   $script:tick++
   if (-not $script:complete) {
     if ($script:shown -lt $script:target) { $script:shown = [Math]::Min($script:target, $script:shown + 2) }
     $ring.Invalidate()
   }
   if ($script:proc -and $script:proc.HasExited -and -not $script:complete) {
     $script:complete = $true
     $timer.Stop()
     $finish.Enabled = $true
     if ($script:proc.ExitCode -eq 0) {
       $script:shown = 100
       $ring.Invalidate()
       $status.Text = 'Instalacao concluida. A janela do gestor foi aberta.'
       $steps.Text = 'Atalhos criados e interface aberta no Windows.'
       $bootPending = @(Get-Content -LiteralPath $stdout -ErrorAction SilentlyContinue | Where-Object { $_ -match '^BOOT-PENDING:' } | Select-Object -Last 1)
       if ($bootPending.Count) {
           $status.Text = 'Gestor instalado. Inicializacao antes do login pendente.'
           $steps.Text = [string]$bootPending[0]
       }
     } else {
       $ring.Invalidate()
       $status.Text = 'Nao foi possivel concluir a instalacao.'
       $reason = @(Get-Content -LiteralPath $stdout -ErrorAction SilentlyContinue | Where-Object { $_ -match '^(ERRO|FALHA):' } | Select-Object -Last 1)
       if ($reason.Count -and $reason[0]) { $status.Text = [string]$reason[0] }
       $steps.Text = 'Consulte o diagnostico para identificar a falha.'
       if (Test-Path $stderr) {
         $tail = @(Get-Content -LiteralPath $stderr -Tail 4 -ErrorAction SilentlyContinue)
         if ($tail.Count) { $details.AppendText([Environment]::NewLine + ($tail -join [Environment]::NewLine)) }
       }
     }
   }
 } catch {
   $status.Text = 'Falha ao atualizar a janela de instalacao.'
 }
})
$form.Add_Shown({
 try {
   $args = '-NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' + $worker + '" -Url "' + $packageUrl + '" -WorkingDir "' + $session + '"'
   $script:proc = Start-Process -FilePath 'powershell.exe' -ArgumentList $args -WindowStyle Hidden -PassThru -RedirectStandardOutput $stdout -RedirectStandardError $stderr
   $timer.Start()
 } catch {
   $script:complete = $true
   $status.Text = 'Falha ao iniciar instalador: ' + $_.Exception.Message
   $finish.Enabled = $true
 }
})
$form.Add_FormClosing({
 if (-not $script:complete) {
   [System.Windows.Forms.MessageBox]::Show('A instalacao ainda esta em andamento. A janela sera liberada ao finalizar.', 'T.I Digital', 'OK', 'Information') | Out-Null
   $_.Cancel = $true
 }
})
[void][System.Windows.Forms.Application]::Run($form)
if ($script:proc -and $script:proc.HasExited -and $script:proc.ExitCode -eq 0) { exit 0 }
exit 1
