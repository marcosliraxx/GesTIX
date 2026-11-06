# Caminho: agentes/windows/Arcoll.WinUI3/ATUALIZACAO_VISUAL.ps1
# Janela independente: permanece aberta durante a troca do executavel principal.
[CmdletBinding()]
param(
    [string]$UpdateId,
    [string]$StateFolder = (Join-Path $env:LOCALAPPDATA 'TI-Digital'),
    [string]$AgentPath = (Join-Path $env:LOCALAPPDATA 'TI-Digital\WinUI3\Arcoll.WinUI3.exe'),
    [switch]$TestMode
)

function Get-UpdateDisplayState {
    param([string]$ExpectedId, $ProgressState, $ResultState, [int]$PreviousPercent = 0, [DateTime]$Now = [DateTime]::UtcNow, [DateTime]$Deadline = [DateTime]::MinValue)
    if ($ResultState -and [string]$ResultState.update_id -eq $ExpectedId) {
        if ($ResultState.ok -eq $true) {
            if ($Deadline -eq [DateTime]::MinValue) { $Deadline = $Now.AddSeconds(3) }
            $seconds = [Math]::Max(0, [int][Math]::Ceiling(($Deadline - $Now).TotalSeconds))
            $unit = if ($seconds -eq 1) { 'segundo' } else { 'segundos' }
            $title = if ($ResultState.unchanged -eq $true) { 'Agente já está atualizado' } else { 'Agente atualizado com sucesso' }
            return [pscustomobject]@{ state = 'success'; percent = 100; title = $title; message = ('Abrindo o agente em ' + $seconds + ' ' + $unit + '…'); deadline = $Deadline; open_agent = ($seconds -eq 0) }
        }
        return [pscustomobject]@{ state = 'failed'; percent = $PreviousPercent; title = 'Não foi possível atualizar o agente'; message = [string]$ResultState.message; deadline = [DateTime]::MinValue; open_agent = $false }
    }
    $percent = [Math]::Min(99, [Math]::Max(0, $PreviousPercent)); $message = 'Preparando atualização…'
    if ($ProgressState -and [string]$ProgressState.update_id -eq $ExpectedId) {
        $percent = [Math]::Max($percent, [Math]::Min(99, [Math]::Max(0, [int]$ProgressState.percent)))
        $message = [string]$ProgressState.message
    }
    return [pscustomobject]@{ state = 'working'; percent = $percent; title = 'Atualizando agente'; message = $message; deadline = [DateTime]::MinValue; open_agent = $false }
}
if ($TestMode) { return }
if (-not [Environment]::UserInteractive -or (Get-Process -Id $PID).SessionId -eq 0) { exit 0 }
$guid = [guid]::Empty
if (-not [guid]::TryParse($UpdateId, [ref]$guid)) { exit 2 }
$created = $false
$mutex = [Threading.Mutex]::new($true, ('Local\TIDigital.UpdateProgress.' + $guid.ToString('N')), [ref]$created)
if (-not $created) { $mutex.Dispose(); exit 0 }
try {
    Add-Type -AssemblyName System.Windows.Forms
    Add-Type -AssemblyName System.Drawing
    [System.Windows.Forms.Application]::EnableVisualStyles()
    $form = New-Object System.Windows.Forms.Form
    $form.Text = 'T.I Digital | Atualização do agente'
    $form.ClientSize = New-Object Drawing.Size(550,250)
    $form.MinimumSize = New-Object Drawing.Size(566,289)
    $form.StartPosition = 'CenterScreen'
    $form.AutoScaleMode = 'Dpi'
    $form.Font = New-Object Drawing.Font('Segoe UI',11)
    $form.MaximizeBox = $false
    $heading = New-Object Windows.Forms.Label
    $heading.SetBounds(24,22,502,48); $heading.Text = 'Atualizando agente'
    $heading.Font = New-Object Drawing.Font('Segoe UI Semibold',18)
    $form.Controls.Add($heading)
    $message = New-Object Windows.Forms.Label
    $message.SetBounds(24,82,502,48); $message.Text = 'Preparando atualização…'
    $form.Controls.Add($message)
    $bar = New-Object Windows.Forms.ProgressBar
    $bar.SetBounds(24,140,442,25); $bar.Minimum = 0; $bar.Maximum = 100
    $form.Controls.Add($bar)
    $percent = New-Object Windows.Forms.Label
    $percent.SetBounds(473,140,60,28); $percent.Text = '0%'
    $form.Controls.Add($percent)
    $close = New-Object Windows.Forms.Button
    $close.SetBounds(410,192,116,34); $close.Text = 'Fechar'; $close.Enabled = $false
    $close.Add_Click({ $form.Close() }); $form.Controls.Add($close)
    $script:display = Get-UpdateDisplayState -ExpectedId $UpdateId
    $script:finished = $false; $script:openAgent = $false
    $started = [DateTime]::UtcNow
    $timer = New-Object Windows.Forms.Timer; $timer.Interval = 250
    $timer.Add_Tick({
        $progress = $null; $result = $null
        try { $progress = Get-Content -LiteralPath (Join-Path $StateFolder 'update-progress.json') -Raw -Encoding UTF8 -ErrorAction Stop | ConvertFrom-Json } catch { }
        # Recibo independente do consumido pela confirmacao do comando na plataforma.
        try { $result = Get-Content -LiteralPath (Join-Path $StateFolder 'update-ui-result.json') -Raw -Encoding UTF8 -ErrorAction Stop | ConvertFrom-Json } catch { }
        # Depois do sucesso confirmado, mantem o recibo durante toda a contagem.
        if ($script:display.state -eq 'success') { $result = $script:lastResult }
        if ($result -and [string]$result.update_id -eq $UpdateId) { $script:lastResult = $result }
        $script:display = Get-UpdateDisplayState -ExpectedId $UpdateId -ProgressState $progress -ResultState $result -PreviousPercent $script:display.percent -Deadline $script:display.deadline
        $heading.Text = $script:display.title; $message.Text = $script:display.message
        $bar.Value = $script:display.percent; $percent.Text = $script:display.percent.ToString() + '%'
        if ($script:display.state -eq 'failed') { $script:finished = $true; $close.Enabled = $true; $timer.Stop() }
        if ($script:display.open_agent) { $script:finished = $true; $script:openAgent = $true; $timer.Stop(); $form.Close() }
        if (-not $script:finished -and [DateTime]::UtcNow -gt $started.AddMinutes(35)) {
            $script:finished = $true; $close.Enabled = $true; $timer.Stop()
            $heading.Text = 'Atualização sem confirmação'; $message.Text = 'Consulte os registros em TI-Digital\Logs. A instalação pode continuar em andamento.'
        }
    })
    $form.Add_FormClosing({ if (-not $script:finished -and $_.CloseReason -eq 'UserClosing') { $_.Cancel = $true } })
    $form.Add_Shown({ $timer.Start() })
    [void][Windows.Forms.Application]::Run($form)
    $timer.Dispose(); $form.Dispose()
    if ($script:openAgent -and (Test-Path -LiteralPath $AgentPath -PathType Leaf)) {
        # Sem --background: a instancia unica abre a janela do agente ja validado.
        Start-Process -FilePath $AgentPath -WorkingDirectory (Split-Path -Parent $AgentPath) | Out-Null
    }
} finally { $mutex.ReleaseMutex(); $mutex.Dispose() }
