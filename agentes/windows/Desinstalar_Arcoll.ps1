# Caminho: agentes/windows/Desinstalar_Arcoll.ps1
param([switch]$Elevated,[switch]$Authorized,[switch]$Detached,[switch]$Silent,[int]$Delay = 0)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
[System.Windows.Forms.Application]::EnableVisualStyles()

$installed = 'C:\Arcoll'
$configPath = Join-Path $installed 'agent-config.json'
$systemName = 'Agente de Rede'
try {
    if (Test-Path -LiteralPath $configPath) {
        $cfg = Get-Content -LiteralPath $configPath -Raw -Encoding UTF8 | ConvertFrom-Json
        if ($cfg.system_name) { $systemName = [string]$cfg.system_name }
    }
} catch {}
$script:failed = $false

function Invoke-RefreshShell {
    try {
        Add-Type -Namespace Arcoll -Name Native -MemberDefinition @"
using System;
using System.Runtime.InteropServices;
public static class Native {
    [DllImport("shell32.dll")] public static extern void SHChangeNotify(int wEventId, uint uFlags, IntPtr dwItem1, IntPtr dwItem2);
}
"@
        [Arcoll.Native]::SHChangeNotify(0x08000000, 0x0000, [IntPtr]::Zero, [IntPtr]::Zero)
    } catch {}
}
function Remove-AgentShortcut([string]$folder,[string]$name) {
    if (-not $folder -or -not (Test-Path -LiteralPath $folder)) { return }
    $shell = $null
    try { $shell = New-Object -ComObject WScript.Shell } catch {}
    Get-ChildItem -LiteralPath $folder -Filter '*.lnk' -Force -ErrorAction SilentlyContinue | ForEach-Object {
        $remove = $false
        if ($_.Name -ieq ($name + '.lnk')) { $remove = $true }
        if (-not $remove -and $shell) {
            try {
                $sc = $shell.CreateShortcut($_.FullName)
                $target = [string]($sc.TargetPath)
                $args = [string]($sc.Arguments)
                $wd = [string]($sc.WorkingDirectory)
                $blob = ($target + ' ' + $args + ' ' + $wd).ToLowerInvariant()
                if ($blob.Contains('c:\arcoll') -or $blob.Contains('instalar_arcoll.ps1') -or $blob.Contains('arcoll_desktop.py') -or $blob.Contains('arcoll_app.py')) { $remove = $true }
            } catch {}
        }
        if ($remove) {
            try { Remove-Item -LiteralPath $_.FullName -Force -ErrorAction Stop } catch {}
        }
    }
}
function DeleteIfPresent([string]$path) {
    if (-not (Test-Path -LiteralPath $path)) { return }
    for ($attempt=1; $attempt -le 8; $attempt++) {
        try {
            Remove-Item -LiteralPath $path -Recurse -Force -ErrorAction Stop
            return
        } catch {
            if ($attempt -eq 8) { throw }
            Start-Sleep -Milliseconds 650
        }
    }
}

# O desinstalador nunca deve apagar a pasta de onde ele próprio está sendo executado.
# Executa uma cópia independente, com diretório de trabalho em TEMP.
if (-not $Detached) {
    $stageDir = Join-Path $env:TEMP ('Arcoll-Uninstall-' + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $stageDir -Force | Out-Null
    $stageFile = Join-Path $stageDir 'Desinstalar_Arcoll.ps1'
    Copy-Item -LiteralPath $PSCommandPath -Destination $stageFile -Force
    $principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
    $admin = $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
    $startArgs = @{
        FilePath = 'powershell.exe'
        WorkingDirectory = $stageDir
        WindowStyle = 'Hidden'
        ArgumentList = @('-NoLogo','-NoProfile','-ExecutionPolicy','Bypass','-STA','-WindowStyle','Hidden','-File',('"'+$stageFile+'"'),'-Elevated','-Detached')
    }
    if ($Silent) { $startArgs.ArgumentList += @('-Silent','-Delay',[string]$Delay) }
    if (-not $admin) { $startArgs.Verb = 'RunAs' }
    try { Start-Process @startArgs | Out-Null }
    catch {
        if ($Silent) { exit 1 }
        [System.Windows.Forms.MessageBox]::Show('O Windows não autorizou a desinstalação. ' + $_.Exception.Message,$systemName,[System.Windows.Forms.MessageBoxButtons]::OK,[System.Windows.Forms.MessageBoxIcon]::Error) | Out-Null
        exit 1
    }
    exit 0
}

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    if ($Silent) { exit 1 }
    [System.Windows.Forms.MessageBox]::Show('Permissão de administrador necessária para concluir a desinstalação.',$systemName,[System.Windows.Forms.MessageBoxButtons]::OK,[System.Windows.Forms.MessageBoxIcon]::Error) | Out-Null
    exit 1
}

$ui = New-Object System.Windows.Forms.Form
$ui.Text = 'Desinstalando o agente'
$ui.Size = New-Object System.Drawing.Size(520,275)
$ui.StartPosition = 'CenterScreen'
$ui.BackColor = [System.Drawing.Color]::FromArgb(13,30,40)
$ui.ForeColor = [System.Drawing.Color]::White
$ui.FormBorderStyle = 'FixedDialog'
$ui.MaximizeBox = $false
$ui.MinimizeBox = $false
$ui.ControlBox = $true

$heading = New-Object System.Windows.Forms.Label
$heading.Text = 'DESINSTALAÇÃO DO AGENTE'
$heading.Font = New-Object System.Drawing.Font('Segoe UI',15,[System.Drawing.FontStyle]::Bold)
$heading.Location = New-Object System.Drawing.Point(24,20)
$heading.AutoSize = $true
$ui.Controls.Add($heading)

$detail = New-Object System.Windows.Forms.Label
$detail.Text = 'Preparando a desinstalação segura deste computador...'
$detail.Location = New-Object System.Drawing.Point(27,74)
$detail.Size = New-Object System.Drawing.Size(455,48)
$detail.Font = New-Object System.Drawing.Font('Segoe UI',10)
$ui.Controls.Add($detail)

$bar = New-Object System.Windows.Forms.ProgressBar
$bar.Location = New-Object System.Drawing.Point(27,132)
$bar.Size = New-Object System.Drawing.Size(455,20)
$bar.Minimum = 0
$bar.Maximum = 100
$bar.Style = 'Continuous'
$ui.Controls.Add($bar)

$percent = New-Object System.Windows.Forms.Label
$percent.Location = New-Object System.Drawing.Point(432,158)
$percent.Size = New-Object System.Drawing.Size(50,22)
$percent.TextAlign = 'MiddleRight'
$percent.Text = '0%'
$ui.Controls.Add($percent)

$done = New-Object System.Windows.Forms.Button
$done.Text = 'Aguarde...'
$done.Enabled = $false
$done.Location = New-Object System.Drawing.Point(379,197)
$done.Size = New-Object System.Drawing.Size(103,32)
$done.FlatStyle = 'Flat'
$done.BackColor = [System.Drawing.Color]::FromArgb(11,132,181)
$done.ForeColor = [System.Drawing.Color]::White
$done.Add_Click({ $ui.Close() })
$ui.Controls.Add($done)

function Stage([int]$progress,[string]$message) {
    $bar.Value = [Math]::Min(100,[Math]::Max(0,$progress))
    $percent.Text = "$progress%"
    $detail.Text = $message
    $ui.Refresh()
    [System.Windows.Forms.Application]::DoEvents()
}

function RunRemoval {
    try {
        Stage 8 'Verificando a instalação deste computador...'
        if (Test-Path -LiteralPath $installed) {
            $root = [IO.Path]::GetFullPath($installed).TrimEnd('\') + '\'
            Stage 20 'Encerrando os processos do agente...'
            Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
                $_.ProcessId -ne $PID -and $_.Name -match '^pythonw?\.exe$' -and
                $_.CommandLine -and $_.CommandLine.Contains($root) -and
                $_.CommandLine -match '(?i)arcoll_(agent|desktop|app)\.py'
            } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
        }

        Stage 38 'Removendo inicializações, tarefas e serviços do agente...'
        foreach ($name in @('ArcollLocalCore','ArcollSecureDesktop')) {
            try { Stop-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue } catch {}
            try { Unregister-ScheduledTask -TaskName $name -Confirm:$false -ErrorAction SilentlyContinue } catch {}
        }
        Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
            $_.CommandLine -and $_.CommandLine -match '(?i)arcoll_secure_helper\.py'
        } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }

        Stage 58 'Removendo atalhos, configurações e vínculos locais...'
        $folders = @([Environment]::GetFolderPath('Desktop'),[Environment]::GetFolderPath('Startup'),[Environment]::GetFolderPath('CommonDesktopDirectory'),[Environment]::GetFolderPath('CommonStartup'))
        foreach ($folder in $folders) {
            Remove-AgentShortcut $folder $systemName
        }
        DeleteIfPresent (Join-Path $env:APPDATA 'Arcoll\agent.json')
        DeleteIfPresent (Join-Path $env:LOCALAPPDATA 'Arcoll')
        # Banco local e configurações de qualquer usuário deste computador (não só o administrador atual).
        Get-ChildItem -LiteralPath 'C:\Users' -Directory -Force -ErrorAction SilentlyContinue | ForEach-Object {
            DeleteIfPresent (Join-Path $_.FullName 'AppData\Roaming\Arcoll')
            DeleteIfPresent (Join-Path $_.FullName 'AppData\Local\Arcoll')
        }

        # Aguarda os processos Python vinculados ao agente terminarem antes de remover binários.
        Start-Sleep -Milliseconds 1000
        Stage 78 'Removendo componentes instalados do agente...'
        foreach ($p in @((Join-Path $env:ProgramFiles 'Arcoll'), (Join-Path $env:ProgramData 'Arcoll'))) {
            if (Test-Path -LiteralPath $p) { DeleteIfPresent $p }
        }
        if (Test-Path -LiteralPath $installed) { DeleteIfPresent $installed }

        Stage 95 'Conferindo a limpeza final desta instalação...'
        foreach ($check in @($installed, (Join-Path $env:ProgramFiles 'Arcoll'), (Join-Path $env:ProgramData 'Arcoll'))) {
            if (Test-Path -LiteralPath $check) { throw 'Ainda existem arquivos do agente neste computador. Tente executar a desinstalação novamente como administrador.' }
        }
        Invoke-RefreshShell
        Stage 100 'Desinstalação concluída. Aperte em Fechar para sair.'
    } catch {
        $script:failed = $true
        $detail.Text = 'Não foi possível concluir a desinstalação.' + "`r`n" + $_.Exception.Message
    }
    $done.Text = 'Fechar'
    $done.Enabled = $true
}

if ($Silent) {
    # O aplicativo confirma a revogação no servidor durante este intervalo; depois nada impede a remoção.
    if ($Delay -gt 0) { Start-Sleep -Seconds ([Math]::Min($Delay,60)) }
    RunRemoval
    if ($script:failed) { exit 1 }
    exit 0
}
$ui.Add_Shown({ $ui.BeginInvoke([Action]{ RunRemoval }) | Out-Null })
[void]$ui.ShowDialog()
if ($script:failed) { exit 1 }
exit 0
