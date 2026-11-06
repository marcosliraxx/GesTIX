# Caminho: agentes/windows/Arcoll.WinUI3/Verificar-Integracao.ps1
# Diagnóstico somente leitura da integração do agente nativo com a plataforma. Não altera nada.
$ErrorActionPreference='Stop'
$cfg=@{}
foreach($file in @((Join-Path $env:APPDATA 'Arcoll\agent.json'),(Join-Path $PSScriptRoot 'agent-config.json'),(Join-Path (Split-Path $PSScriptRoot -Parent) 'agent-config.json'))){
    if(-not (Test-Path -LiteralPath $file)){continue}
    try{ $j=Get-Content -LiteralPath $file -Raw -Encoding UTF8 | ConvertFrom-Json; foreach($k in 'origin','site_code','download_key','fingerprint'){ if(-not $cfg[$k] -and $j.$k){$cfg[$k]=[string]$j.$k} } }
    catch{ Write-Warning ("Configuração ilegível: $file") }
}
if(-not $cfg['origin'] -or -not $cfg['origin'].StartsWith('https://')){ Write-Error 'CONFIGURAÇÃO: origem HTTPS ausente.'; exit 1 }
if($cfg['site_code'] -notmatch '^[A-Za-z0-9]{5}$' -or $cfg['download_key'] -notmatch '^[a-fA-F0-9]{64}$'){ Write-Error 'CONFIGURAÇÃO: código do local ou chave inválidos.'; exit 1 }
Write-Host ('CONFIGURAÇÃO: OK | Servidor: ' + $cfg['origin'].TrimEnd('/'))
if($cfg['fingerprint']){
    try{
        $body=@{fingerprint=$cfg['fingerprint'];site_code=$cfg['site_code'].ToUpper();download_key=$cfg['download_key']} | ConvertTo-Json
        $r=Invoke-RestMethod -Method Post -Uri ($cfg['origin'].TrimEnd('/')+'/api/api.php?action=agent-poll') -Body $body -ContentType 'application/json' -TimeoutSec 10
        Write-Host ('AUTORIZAÇÃO: ' + $r.status + ' | Rede: ' + $r.network_name)
    }catch{ Write-Warning ('AUTORIZAÇÃO: ' + $_.Exception.Message) }
}else{ Write-Host 'AUTORIZAÇÃO: o aplicativo calcula o fingerprint na primeira execução.' }
$theme=Join-Path $env:LOCALAPPDATA 'Arcoll\winui-theme.json'
if(Test-Path -LiteralPath $theme){
    try{ $t=Get-Content -LiteralPath $theme -Raw | ConvertFrom-Json; Write-Host ('TEMA CACHE: {0} / {1}' -f $t.id,$t.mode) }
    catch{ Write-Warning 'Cache de tema inválido; o aplicativo restaura o último válido.' }
}
Write-Host 'Diagnóstico finalizado, sem alterações.'
