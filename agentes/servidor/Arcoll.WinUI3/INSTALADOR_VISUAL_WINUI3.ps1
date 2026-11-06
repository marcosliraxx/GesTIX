# Caminho: agentes/servidor/Arcoll.WinUI3/INSTALADOR_VISUAL_WINUI3.ps1
# Instalador grafico WinForms acionado pelo BAT: barra de progresso em porcentagem, atalhos e execucao em segundo plano.
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
[System.Windows.Forms.Application]::EnableVisualStyles()

$packageUrl = '__PACKAGE_URL__'
$logs = Join-Path $env:LOCALAPPDATA 'TI-Digital\Logs'
New-Item -ItemType Directory -Path $logs -Force | Out-Null
$session = Join-Path $env:TEMP ('ti-digital-winui-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $session -Force | Out-Null
$worker = Join-Path $session 'instalar-worker.ps1'
$stdout = Join-Path $logs ('Instalador-Visual-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.log')
$stderr = Join-Path $session 'erro-worker.log'
$workerText = @'
# Caminho: agentes/servidor/Arcoll.WinUI3/INSTALADOR_VISUAL_WINUI3.ps1 (processo interno)
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
  & powershell.exe -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $installer 2>&1 |
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
$form.Text = 'T.I Digital | Instalacao do Agente Windows'
$form.Size = New-Object System.Drawing.Size(700,470)
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
$title.Text = 'Instalando o Agente Windows'
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
$status.SetBounds(33,163,640,27)
$form.Controls.Add($status)
$bar = New-Object System.Windows.Forms.ProgressBar
$bar.Style = 'Continuous'
$bar.Minimum = 0
$bar.Maximum = 100
$bar.Value = 0
$bar.SetBounds(33,201,570,20)
$form.Controls.Add($bar)
$pct = New-Object System.Windows.Forms.Label
$pct.Text = '0%'
$pct.Font = New-Object Drawing.Font('Segoe UI Semibold',11)
$pct.ForeColor = [Drawing.Color]::FromArgb(85,212,246)
$pct.TextAlign = 'MiddleRight'
$pct.SetBounds(608,198,55,26)
$form.Controls.Add($pct)
$steps = New-Object System.Windows.Forms.Label
$steps.Text = 'Baixar pacote  >  Validar  >  Compilar  >  Instalar  >  Atalhos e segundo plano'
$steps.ForeColor = [Drawing.Color]::FromArgb(120,189,216)
$steps.SetBounds(33,239,630,25)
$form.Controls.Add($steps)
$details = New-Object System.Windows.Forms.TextBox
$details.Multiline = $true
$details.ReadOnly = $true
$details.ScrollBars = 'Vertical'
$details.BackColor = [Drawing.Color]::FromArgb(18,35,52)
$details.ForeColor = [Drawing.Color]::FromArgb(218,234,243)
$details.BorderStyle = 'FixedSingle'
$details.Font = New-Object Drawing.Font('Consolas',9)
$details.SetBounds(33,275,630,92)
$form.Controls.Add($details)
$finish = New-Object System.Windows.Forms.Button
$finish.Text = 'Concluir'
$finish.BackColor = [Drawing.Color]::FromArgb(13,120,173)
$finish.ForeColor = [Drawing.Color]::White
$finish.FlatStyle = 'Flat'
$finish.SetBounds(526,380,136,32)
$finish.Enabled = $false
$finish.Add_Click({$form.Close()})
$form.Controls.Add($finish)
$logButton = New-Object System.Windows.Forms.Button
$logButton.Text = 'Abrir diagnostico'
$logButton.BackColor = [Drawing.Color]::FromArgb(32,54,70)
$logButton.ForeColor = [Drawing.Color]::White
$logButton.FlatStyle = 'Flat'
$logButton.SetBounds(33,380,175,32)
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
     elseif ($script:target -ge 50 -and $script:target -lt 82 -and $script:shown -lt 81 -and ($script:tick % 6) -eq 0) { $script:shown++ }
     $bar.Value = [Math]::Min(100, $script:shown)
     $pct.Text = ([int]$bar.Value).ToString() + '%'
   }
   if ($script:proc -and $script:proc.HasExited -and -not $script:complete) {
     $script:complete = $true
     $timer.Stop()
     $finish.Enabled = $true
     if ($script:proc.ExitCode -eq 0) {
       $bar.Value = 100
       $pct.Text = '100%'
       $status.Text = 'Instalacao concluida. O agente esta em execucao em segundo plano.'
       $steps.Text = 'Atalhos criados. A interface inicia ao entrar no Windows.'
       $bootPending = @(Get-Content -LiteralPath $stdout -ErrorAction SilentlyContinue | Where-Object { $_ -match '^BOOT-PENDING:' } | Select-Object -Last 1)
       if ($bootPending.Count) {
           $status.Text = 'Agente instalado. Inicializacao antes do login pendente.'
           $steps.Text = [string]$bootPending[0]
       }
     } else {
       $pct.Text = ([int]$bar.Value).ToString() + '%'
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
