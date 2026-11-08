# Caminho: gestores/windows/Preparar_Instalacao.ps1
param([Parameter(Mandatory=$true)][string]$Source)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
[System.Windows.Forms.Application]::EnableVisualStyles()
$state = Join-Path $env:LOCALAPPDATA 'Arcoll'
$logs = Join-Path $state 'logs'
New-Item -ItemType Directory -Path $state, $logs -Force | Out-Null
$log = Join-Path $logs 'installation.log'
# Não finalizar outras instâncias de PowerShell indiscriminadamente.
# A instalação exibirá o erro de arquivo em uso quando houver concorrência.
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
    try { Add-Content -LiteralPath (Join-Path $logs ("installation-{0}.log" -f $PID)) -Value $line -Encoding UTF8 -ErrorAction Stop } catch {}
}
$destination = 'C:\Arcoll'
# A identidade é carregada antes da criação dos controles.
$systemName = 'Gestor de Rede'
$systemDescription = 'Instalação e configuração deste computador'
try {
    $identityPath = Join-Path $Source 'gestor-config.json'
    if (Test-Path -LiteralPath $identityPath) {
        $identityConfig = Get-Content -LiteralPath $identityPath -Raw -Encoding UTF8 | ConvertFrom-Json
        if (-not [string]::IsNullOrWhiteSpace([string]$identityConfig.system_name)) { $systemName = [string]$identityConfig.system_name }
        if (-not [string]::IsNullOrWhiteSpace([string]$identityConfig.system_description)) { $systemDescription = [string]$identityConfig.system_description }
    }
} catch { Write-InstallLog ('Identidade da instalação: ' + $_.Exception.Message) }
$window = New-Object System.Windows.Forms.Form
$window.Text = $systemName + ' | Instalação do Gestor'
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
$header.Text = $systemName.ToUpperInvariant()
$header.ForeColor = [System.Drawing.Color]::FromArgb(85,212,246)
$header.Font = New-Object System.Drawing.Font('Segoe UI Semibold',11)
$header.SetBounds(35,27,550,30)
$window.Controls.Add($header)
$title = New-Object System.Windows.Forms.Label
$title.Text = 'Instalando o Gestor Windows'
$title.Font = New-Object System.Drawing.Font('Segoe UI Semibold',21)
$title.SetBounds(34,77,560,48)
$window.Controls.Add($title)
$subtitle = New-Object System.Windows.Forms.Label
$subtitle.Text = $systemDescription
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
$finish.Visible = $false
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
    $proc = Start-Process -FilePath $path -ArgumentList $arguments -PassThru
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
        Progress 5 'Validando arquivos do gestor...'
        $required = @('arcoll_gestor.py','arcoll_release.py','arcoll_app.py','arcoll_toolkit.py','arcoll_remote.py','arcoll_secure_helper.py','rt_engine.py','Instalar_Arcoll.ps1','gestor-config.json','favicon.ico')
        foreach($item in $required) {
            if (-not (Test-Path -LiteralPath (Join-Path $Source $item))) { throw "Arquivo obrigatório ausente: $item. Extraia o ZIP completo." }
        }
        $config = Get-Content -LiteralPath (Join-Path $Source 'gestor-config.json') -Raw | ConvertFrom-Json
        if ([string]$config.origin -notmatch '^https://[^/]+') { throw 'Endereço HTTPS inválido no pacote. Baixe novamente pelo painel da rede.' }
        $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
        if (-not ([Security.Principal.WindowsPrincipal]$identity).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
            throw 'A instalação requer permissão de administrador do Windows.'
        }
        Progress 12 ('Encerrando a versão anterior de ' + $systemName + '...')
        # Encerra somente processos Python cujo comando aponta para esta instalação.
        $targetRoot = [IO.Path]::GetFullPath($destination).TrimEnd('\') + '\'
        $ownPid = $PID
        Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
            $_.ProcessId -ne $ownPid -and $_.Name -match '^pythonw?\.exe$' -and
            $_.CommandLine -and ($_.CommandLine -match '(?i)(arcoll_desktop|arcoll_app|arcoll_gestor)\.py') -and
            $_.CommandLine.Contains($targetRoot)
        } | ForEach-Object {
            try { Stop-Process -Id $_.ProcessId -Force -ErrorAction Stop } catch { throw "Não foi possível encerrar o gestor anterior (PID $($_.ProcessId))." }
        }
        Start-Sleep -Milliseconds 450
        Progress 17 'Preparando a nova versão do gestor...' 
        New-Item -ItemType Directory -Path $destination -Force | Out-Null
        # Atualiza todos os arquivos de distribuição; mantém estado, bancos e logs locais.
        $sourceRoot = [IO.Path]::GetFullPath($Source).TrimEnd('\') + '\'
        $packageFiles = @(Get-ChildItem -LiteralPath $Source -File -Recurse | Where-Object {
            -not ($_.Attributes -band [IO.FileAttributes]::ReparsePoint) -and $_.Name -ne '.arcoll-installer-marker'
        })
        $incoming = @()
        foreach ($file in $packageFiles) {
            $relative = $file.FullName.Substring($sourceRoot.Length).Replace('\','/')
            if ($relative -notmatch '^[A-Za-z0-9_./ -]+$' -or $relative -match '(^|/)\.\.?(/|$)' -or $relative.Contains(':')) { throw 'Caminho inválido no pacote.' }
            $target = Join-Path $destination $relative
            if (-not ([IO.Path]::GetFullPath($target).StartsWith($targetRoot,[StringComparison]::OrdinalIgnoreCase))) { throw 'Destino fora da instalação.' }
            $parent = Split-Path -Parent $target
            New-Item -ItemType Directory -Path $parent -Force | Out-Null
            Copy-Item -LiteralPath $file.FullName -Destination $target -Force -ErrorAction Stop
            $incoming += $relative
        }
        Progress 26 'Removendo apenas componentes obsoletos...'
        $manifestPath = Join-Path $destination '.arcoll-managed-files.json'
        $prior = @()
        if (Test-Path -LiteralPath $manifestPath) {
            try { $prior = @(Get-Content -LiteralPath $manifestPath -Raw -Encoding UTF8 | ConvertFrom-Json) } catch { $prior = @() }
        }
        foreach ($old in $prior) {
            if ($old -isnot [string] -or $old -notmatch '^[A-Za-z0-9_./ -]+$' -or $old -match '(^|/)\.\.?(/|$)' -or $old.Contains(':')) { continue }
            if ($old -in @('gestor-config.json','favicon.ico','INICIAR_ARCOLL.bat','Instalar_Arcoll.ps1','Preparar_Instalacao.ps1')) { continue }
            if ($incoming -contains $old) { continue }
            $oldPath = Join-Path $destination $old
            if ((Test-Path -LiteralPath $oldPath -PathType Leaf) -and ([IO.Path]::GetFullPath($oldPath).StartsWith($targetRoot,[StringComparison]::OrdinalIgnoreCase))) {
                Remove-Item -LiteralPath $oldPath -Force -ErrorAction Stop
            }
        }
        $incoming | ConvertTo-Json | Set-Content -LiteralPath $manifestPath -Encoding UTF8
        Progress 31 'Configurando o iniciador do aplicativo...'
        # O script PowerShell lê gestor-config.json diretamente. Evita falhas por
        # codificação/for-f ao abrir o atalho do Desktop ou iniciar com o Windows.
        $launcher = '@rem Caminho: gestores/windows/INICIAR_ARCOLL.bat' + "`r`n" + '@echo off' + "`r`n" + 'setlocal' + "`r`n" + 'cd /d "%~dp0"' + "`r`n" + 'powershell.exe -NoProfile -ExecutionPolicy Bypass -STA -File "%~dp0Instalar_Arcoll.ps1" -Run %*' + "`r`n"
        [IO.File]::WriteAllText((Join-Path $destination 'INICIAR_ARCOLL.bat'),$launcher,[Text.Encoding]::ASCII)
        $env:ARCOLL_ORIGIN = [string]$config.origin
        Progress 40 'Instalando Python e dependências, se necessário...'
        # O instalador existente instala o runtime, configura atalhos e inicia o aplicativo.
        # Executado no diretório permanente, nunca na pasta temporária extraída.
        $scriptPath = Join-Path $destination 'Instalar_Arcoll.ps1'
        $proc = Start-Process -FilePath 'powershell.exe' -ArgumentList @('-NoLogo','-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-STA','-File',('"'+$scriptPath+'"')) -PassThru
        $pos=40
        while (-not $proc.HasExited) {
            if ($pos -lt 94) {$pos++}
            Progress $pos 'Configurando o gestor e os atalhos do Windows...'
            Start-Sleep -Milliseconds 550
            $proc.Refresh()
        }
        if ($proc.ExitCode -ne 0) { throw "O gestor não foi instalado (código $($proc.ExitCode)). Consulte $log." }
        Progress 100 'Instalação concluída. O gestor foi iniciado.'
        $script:installationDone = $true
        $title.Text = 'Instalação concluída'
        $subtitle.Text = $systemName + ' foi instalado com sucesso.'
        # A interface principal já é aberta pelo instalador; não existe tela de liberação legada.
        $finish.Enabled = $false
        $finish.Visible = $false
        $window.BeginInvoke([Action]{ $window.Close() }) | Out-Null
    } catch {
        $status.Text = 'Falha na instalação: ' + $_.Exception.Message
        $status.ForeColor = [System.Drawing.Color]::FromArgb(255,139,139)
        $title.Text = 'Não foi possível concluir'
        Write-InstallLog "ERRO: $($_.Exception.ToString())"
        [System.Windows.Forms.MessageBox]::Show("$($_.Exception.Message)`r`n`r`nRegistro: $log",$systemName + ' - Falha na instalação','OK','Error') | Out-Null
    } finally {
        if (-not $script:installationDone) {
            $finish.Text = 'Fechar'
            $finish.Enabled = $true
            $finish.Visible = $true
        }
    }
})
[void]$window.ShowDialog()
