param([Parameter(Mandatory=$true)][string]$Source)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
[System.Windows.Forms.Application]::EnableVisualStyles()
$state = Join-Path $env:LOCALAPPDATA 'Arcoll'
New-Item -ItemType Directory -Path $state -Force | Out-Null
$log = Join-Path $state 'installation.log'
# Encerra execuções anteriores (ocultas/travadas) deste instalador e do script filho, que mantêm o log aberto.
try {
    Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
        $_.ProcessId -ne $PID -and $_.Name -match '^powershell(_ise)?\.exe$' -and $_.CommandLine -and
        ($_.CommandLine -match '(?i)Preparar_Instalacao\.ps1' -or $_.CommandLine -match '(?i)C:\\Arcoll\\Instalar_Arcoll\.ps1')
    } | ForEach-Object { try { Stop-Process -Id $_.ProcessId -Force -ErrorAction Stop } catch {} }
    Start-Sleep -Milliseconds 700
} catch {}
$script:lastLogged = ''
function Write-InstallLog([string]$line) {
    # Nunca deixa uma falha de gravação derrubar o instalador (arquivo em uso, antivírus etc.).
    for ($i = 0; $i -lt 5; $i++) {
        try {
            $fs = New-Object System.IO.FileStream($log, [IO.FileMode]::Append, [IO.FileAccess]::Write, [IO.FileShare]::ReadWrite)
            try { $sw = New-Object System.IO.StreamWriter($fs, (New-Object System.Text.UTF8Encoding($false))); $sw.WriteLine($line); $sw.Flush() } finally { $fs.Dispose() }
            return
        } catch { Start-Sleep -Milliseconds 80 }
    }
    try { Add-Content -LiteralPath (Join-Path $state ("installation-{0}.log" -f $PID)) -Value $line -Encoding UTF8 -ErrorAction Stop } catch {}
}
$destination = 'C:\Arcoll'
$window = New-Object System.Windows.Forms.Form
$window.Text = 'Arcoll | Instalação do Agente'
$window.Size = New-Object System.Drawing.Size(650,410)
$window.MinimumSize = New-Object System.Drawing.Size(650,410)
$window.MaximumSize = New-Object System.Drawing.Size(650,410)
$window.StartPosition = 'CenterScreen'
$window.BackColor = [System.Drawing.Color]::FromArgb(12,23,39)
$window.ForeColor = [System.Drawing.Color]::White
$window.Font = New-Object System.Drawing.Font('Segoe UI',10)
$window.MaximizeBox = $false
$window.MinimizeBox = $false
$header = New-Object System.Windows.Forms.Label
$header.Text = 'ARCOLL  /  SPEED CONNECTION'
$header.ForeColor = [System.Drawing.Color]::FromArgb(85,212,246)
$header.Font = New-Object System.Drawing.Font('Segoe UI Semibold',11)
$header.SetBounds(35,27,550,30)
$window.Controls.Add($header)
$title = New-Object System.Windows.Forms.Label
$title.Text = 'Instalando o agente de rede'
$title.Font = New-Object System.Drawing.Font('Segoe UI Semibold',21)
$title.SetBounds(34,77,560,48)
$window.Controls.Add($title)
$subtitle = New-Object System.Windows.Forms.Label
$subtitle.Text = 'Configuração segura e integração com a plataforma Arcoll.'
$subtitle.ForeColor = [System.Drawing.Color]::FromArgb(165,187,210)
$subtitle.SetBounds(36,133,560,26)
$window.Controls.Add($subtitle)
$status = New-Object System.Windows.Forms.Label
$status.Text = 'Preparando instalação...'
$status.ForeColor = [System.Drawing.Color]::FromArgb(216,232,244)
$status.SetBounds(36,205,560,30)
$window.Controls.Add($status)
$bar = New-Object System.Windows.Forms.ProgressBar
$bar.Style = 'Continuous'
$bar.Minimum = 0
$bar.Maximum = 100
$bar.Value = 0
$bar.SetBounds(36,244,560,20)
$window.Controls.Add($bar)
$percent = New-Object System.Windows.Forms.Label
$percent.Text = '0%'
$percent.ForeColor = [System.Drawing.Color]::FromArgb(90,208,245)
$percent.SetBounds(540,278,55,23)
$percent.TextAlign = 'MiddleRight'
$window.Controls.Add($percent)
$finish = New-Object System.Windows.Forms.Button
$finish.Text = 'Concluir'
$finish.BackColor = [System.Drawing.Color]::FromArgb(13,120,173)
$finish.ForeColor = [System.Drawing.Color]::White
$finish.FlatStyle = 'Flat'
$finish.SetBounds(456,310,140,35)
$finish.Enabled = $false
$finish.Add_Click({$window.Close()})
$window.Controls.Add($finish)
$script:installationDone = $false
$window.Add_FormClosing({if (-not $finish.Enabled -and -not $script:installationDone) { $_.Cancel = $true }})
function Progress([int]$value,[string]$message) {
    $bar.Value = [Math]::Min(100,[Math]::Max(0,$value))
    $percent.Text = "$($bar.Value)%"
    $status.Text = $message
    if ($message -ne $script:lastLogged) { $script:lastLogged = $message; Write-InstallLog "$(Get-Date -Format s) [$value] $message" }
    [System.Windows.Forms.Application]::DoEvents()
}
function Await-Process([string]$path,[string]$arguments,[int]$from,[int]$to,[string]$message) {
    $proc = Start-Process -FilePath $path -ArgumentList $arguments -PassThru -WindowStyle Hidden
    $position=$from
    while (-not $proc.HasExited) {
        if ($position -lt ($to-1)) { $position++ }
        Progress $position $message
        Start-Sleep -Milliseconds 350
        $proc.Refresh()
    }
    if ($proc.ExitCode -ne 0) { throw "$message (código $($proc.ExitCode))." }
    Progress $to $message
}
$window.Add_Shown({
    $ErrorActionPreference = 'Stop'
    try {
        Progress 5 'Validando arquivos do agente...'
        $required = @('arcoll_agent.py','arcoll_desktop.py','arcoll_app.py','arcoll_toolkit.py','rt_engine.py','Instalar_Arcoll.ps1','agent-config.json','favicon.ico')
        foreach($item in $required) {
            if (-not (Test-Path -LiteralPath (Join-Path $Source $item))) { throw "Arquivo obrigatório ausente: $item. Extraia o ZIP completo." }
        }
        $config = Get-Content -LiteralPath (Join-Path $Source 'agent-config.json') -Raw | ConvertFrom-Json
        if ([string]$config.origin -notmatch '^https://[^/]+') { throw 'Endereço HTTPS inválido no pacote. Baixe novamente pelo painel da rede.' }
        $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
        if (-not ([Security.Principal.WindowsPrincipal]$identity).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
            throw 'A instalação requer permissão de administrador do Windows.'
        }
        Progress 12 'Encerrando a versão anterior do Arcoll...'
        # Encerra somente processos Python cujo comando aponta para esta instalação.
        $targetRoot = [IO.Path]::GetFullPath($destination).TrimEnd('\') + '\'
        $ownPid = $PID
        Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
            $_.ProcessId -ne $ownPid -and $_.Name -match '^pythonw?\.exe$' -and
            $_.CommandLine -and ($_.CommandLine -match '(?i)(arcoll_desktop|arcoll_app|arcoll_agent)\.py') -and
            $_.CommandLine.Contains($targetRoot)
        } | ForEach-Object {
            try { Stop-Process -Id $_.ProcessId -Force -ErrorAction Stop } catch { throw "Não foi possível encerrar o agente anterior (PID $($_.ProcessId))." }
        }
        Start-Sleep -Milliseconds 450
        Progress 17 'Preparando a nova versão em C:\Arcoll...'
        New-Item -ItemType Directory -Path $destination -Force | Out-Null
        # Atualiza todos os arquivos de distribuição; mantém estado, bancos e logs locais.
        $packageFiles = @(Get-ChildItem -LiteralPath $Source -File | Where-Object {
            $_.Name -match '^[A-Za-z0-9_.-]+$' -and $_.Name -ne '.arcoll-installer-marker'
        })
        $incoming = @($packageFiles | ForEach-Object { $_.Name })
        foreach ($file in $packageFiles) {
            $target = Join-Path $destination $file.Name
            Copy-Item -LiteralPath $file.FullName -Destination $target -Force -ErrorAction Stop
        }
        Progress 26 'Removendo apenas componentes obsoletos...'
        $manifestPath = Join-Path $destination '.arcoll-managed-files.json'
        $prior = @()
        if (Test-Path -LiteralPath $manifestPath) {
            try { $prior = @(Get-Content -LiteralPath $manifestPath -Raw -Encoding UTF8 | ConvertFrom-Json) } catch { $prior = @() }
        }
        foreach ($old in $prior) {
            if ($old -isnot [string] -or $old -notmatch '^[A-Za-z0-9_.-]+$') { continue }
            if ($old -in @('agent-config.json','favicon.ico','INICIAR_ARCOLL.bat','Instalar_Arcoll.ps1','Preparar_Instalacao.ps1')) { continue }
            if ($incoming -contains $old) { continue }
            $oldPath = Join-Path $destination $old
            if ((Test-Path -LiteralPath $oldPath -PathType Leaf) -and ([IO.Path]::GetFullPath($oldPath).StartsWith($targetRoot,[StringComparison]::OrdinalIgnoreCase))) {
                Remove-Item -LiteralPath $oldPath -Force -ErrorAction Stop
            }
        }
        $incoming | ConvertTo-Json | Set-Content -LiteralPath $manifestPath -Encoding UTF8
        Progress 31 'Configurando o iniciador do aplicativo...'
        # O script PowerShell lê agent-config.json diretamente. Evita falhas por
        # codificação/for-f ao abrir o atalho do Desktop ou iniciar com o Windows.
        $launcher = '@echo off' + "`r`n" + 'setlocal' + "`r`n" + 'cd /d "%~dp0"' + "`r`n" + 'powershell.exe -NoProfile -ExecutionPolicy Bypass -STA -WindowStyle Hidden -File "%~dp0Instalar_Arcoll.ps1" -Run %*' + "`r`n"
        [IO.File]::WriteAllText((Join-Path $destination 'INICIAR_ARCOLL.bat'),$launcher,[Text.Encoding]::ASCII)
        $env:ARCOLL_ORIGIN = [string]$config.origin
        Progress 40 'Instalando Python e dependências, se necessário...'
        # O instalador existente instala o runtime, configura atalhos e inicia o aplicativo.
        # Executado no diretório permanente, nunca na pasta temporária extraída.
        $scriptPath = Join-Path $destination 'Instalar_Arcoll.ps1'
        $proc = Start-Process -FilePath 'powershell.exe' -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-STA','-File',('"'+$scriptPath+'"')) -PassThru -WindowStyle Hidden
        $pos=40
        while (-not $proc.HasExited) {
            if ($pos -lt 94) {$pos++}
            Progress $pos 'Configurando o agente e os atalhos do Windows...'
            Start-Sleep -Milliseconds 550
            $proc.Refresh()
        }
        if ($proc.ExitCode -ne 0) { throw "O agente não foi instalado (código $($proc.ExitCode)). Consulte $log." }
        Progress 100 'Instalação concluída. O agente foi iniciado.'
        Start-Sleep -Milliseconds 500
        $script:installationDone = $true
        $window.Close()
        $title.Text = 'Instalação concluída'
        $subtitle.Text = 'O Arcoll foi instalado em C:\Arcoll.'
    } catch {
        $status.Text = 'Falha na instalação: ' + $_.Exception.Message
        $status.ForeColor = [System.Drawing.Color]::FromArgb(255,139,139)
        $title.Text = 'Não foi possível concluir'
        Write-InstallLog "ERRO: $($_.Exception.ToString())"
        [System.Windows.Forms.MessageBox]::Show("$($_.Exception.Message)`r`n`r`nRegistro: $log",'Arcoll - Falha na instalação','OK','Error') | Out-Null
    } finally {
        $finish.Enabled = $true
        $finish.Text = 'Fechar'
    }
})
[void]$window.ShowDialog()
