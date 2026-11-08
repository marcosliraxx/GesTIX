<?php
/* Caminho: pages/painel.php */
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/network-inventory.php';
$pdo=arc_db();
arc_ensure_user_schema($pdo);
$admin=arc_is_admin();
$uid=arc_user_id();
if(!$admin && !$uid){header('Location: '.arc_workspace_base_url().'index.php',true,303);exit;}
$role=$admin?'admin':arc_v15_user_role($pdo);
// Convites pendentes são efetivados somente após a identidade do e-mail ser verificada no callback Google.
$arcError='';
$go=static function(string $target): never {
    header('Location: '.arc_workspace_base_url().ltrim($target,'/'),true,303);exit;
};
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=(string)($_POST['action']??'');
    $csrf=(string)($_POST['_csrf']??'');
    if ($csrf==='' || !hash_equals(arc_csrf_token(),$csrf)) {
        $arcError='Sessão expirada. Atualize a página e tente novamente.';
    } elseif ($action==='logout') {
        if(arc_is_user()) arc_user_audit($pdo,'account_logout','Logout');
        unset($_SESSION['arc_user_id'],$_SESSION['arc_user_email'],$_SESSION['arc_admin_id'],$_SESSION['arc_admin_email'],$_SESSION['arc_current_network_id'],$_SESSION['arc_network_monitor_sessions'],$_SESSION['arc_monitor_session'],$_SESSION['arc_csrf']);
        session_regenerate_id(true);
        $go('index.php');
    } elseif ($action === 'network_select' && arc_is_admin()) {
        $id=(int)($_POST['network_id']??0);
        if (!arc_select_network($pdo,$id)) $arcError='Rede não encontrada ou indisponível.';
        else {
            arc_audit($pdo,'admin_network_selected','Administrador #'.(int)arc_admin_id().' abriu rede #'.$id);
            $go('redes/painel.php?network='.$id);
        }
    } elseif (!arc_is_user()) {
        $arcError='Administradores podem consultar redes; criação, exclusão e arquivamento exigem uma conta proprietária.';
    } elseif ($action === 'network_create') {
        $name = trim((string)($_POST['network_name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 100) {
            $arcError = 'Informe um nome para a rede (até 100 caracteres).';
        } else {
            try {
                $pdo->beginTransaction();

                $lock = $pdo->prepare('SELECT id FROM ' . arc_table('user_accounts') . ' WHERE id=? FOR UPDATE');
                $lock->execute([arc_user_id()]);
                arc_billing_enforce($pdo,(int)arc_user_id(),'networks');

                $pdo->prepare(
                    'INSERT INTO ' . arc_table('managed_networks') .
                    ' (account_id,name,client_name,site_code,contact_name,contact_phone,address,vlan,public_ip,notes,monitoring_enabled)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    arc_user_id(),
                    $name,
                    mb_substr(trim((string)($_POST['client_name'] ?? '')), 0, 160),
                    '',
                    mb_substr(trim((string)($_POST['contact_name'] ?? '')), 0, 160),
                    mb_substr(trim((string)($_POST['contact_phone'] ?? '')), 0, 40),
                    mb_substr(trim((string)($_POST['address'] ?? '')), 0, 255),
                    mb_substr(trim((string)($_POST['vlan'] ?? '')), 0, 32),
                    mb_substr(trim((string)($_POST['public_ip'] ?? '')), 0, 45),
                    mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 5000),
                    1,
                ]);

                $networkId = (int)$pdo->lastInsertId();
                $networkExtras = [
                    'doc_number'=>18,'trade_name'=>160,'company_email'=>160,'zip_code'=>10,
                    'state_code'=>2,'city_name'=>100,'street_name'=>160,'street_number'=>20,
                    'address_extra'=>100,'district_name'=>100,'registration_status'=>100,
                    'main_activity'=>160,'legal_nature'=>160,'opening_date'=>30,
                ];
                $extraSet=[]; $extraValues=[];
                foreach($networkExtras as $field=>$limit){
                    $extraSet[]=$field.'=?';
                    $extraValues[]=mb_substr(trim((string)($_POST[$field]??'')),0,$limit);
                }
                $pdo->prepare('UPDATE '.arc_table('managed_networks').' SET '.implode(',',$extraSet).' WHERE id=? AND account_id=?')
                    ->execute(array_merge($extraValues,[$networkId,arc_user_id()]));

                $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
                do {
                    $siteCode = '';
                    for ($i = 0; $i < 5; $i++) $siteCode .= $alphabet[random_int(0, strlen($alphabet) - 1)];
                    $checkCode = $pdo->prepare('SELECT COUNT(*) FROM ' . arc_table('managed_networks') . ' WHERE UPPER(site_code)=? AND id<>?');
                    $checkCode->execute([$siteCode, $networkId]);
                } while ((int)$checkCode->fetchColumn() > 0);
                $pdo->prepare('UPDATE ' . arc_table('managed_networks') . ' SET site_code=? WHERE id=?')
                    ->execute([$siteCode, $networkId]);

                arc_save_network_logo($pdo, $networkId, arc_user_id());
                $pdo->commit();

                arc_select_network($pdo, $networkId);
                arc_user_audit($pdo, 'network_created', 'Rede: ' . mb_substr($name, 0, 200) . ' (' . $siteCode . ')');
                $_SESSION['arc_flash'] = 'Rede registrada com o código ' . $siteCode . '.';
                $go('redes/painel.php?network=' . $networkId);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Arcoll network create: ' . $e->getMessage());
                $arcError = $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível registrar a rede.';
            }
        }

    } elseif ($action === 'network_select') {
        $id = (int)($_POST['network_id'] ?? 0);

        if (!arc_select_network($pdo, $id)) {
            $arcError = 'Não foi possível selecionar essa rede.';
        } else {
            arc_user_audit($pdo, 'network_selected', 'Rede ID ' . $id);
            $_SESSION['arc_flash'] = 'Rede selecionada. Abrindo a gestão solicitada.';
            $go('redes/painel.php?network=' . $id);
        }

    } elseif ($action === 'network_delete') {
        $id = (int)($_POST['network_id'] ?? 0);

        if (arc_delete_network($pdo, $id, (int)arc_user_id())) {
            if (arc_current_network_id() === $id) {
                unset($_SESSION['arc_current_network_id']);
            }
            arc_user_audit($pdo, 'network_deleted', 'Rede ID ' . $id);
            $_SESSION['arc_flash'] = 'Rede excluída definitivamente.';
            $go('pages/painel.php');
        }
        $arcError = 'Não foi possível excluir essa rede.';

    } elseif ($action === 'network_archive') {
        $id  = (int)($_POST['network_id'] ?? 0);
        $upd = $pdo->prepare(
            'UPDATE ' . arc_table('managed_networks') . ' SET is_active=0 WHERE id=? AND account_id=? AND is_active=1'
        );
        $upd->execute([$id, arc_user_id()]);

        if ($upd->rowCount()) {
            if (arc_current_network_id() === $id) {
                unset($_SESSION['arc_current_network_id']);
            }
            arc_user_audit($pdo, 'network_archived', 'Rede ID ' . $id);
            $_SESSION['arc_flash'] = 'Rede arquivada.';
            $go('pages/painel.php');
        }
        $arcError = 'Não foi possível arquivar essa rede.';
    }
}

/* Redes e estado calculados pelos gestores aprovados vistos nos últimos 45s. */
$loadPanelNetworks = static function(PDO $pdo, bool $admin, ?int $uid): array {
    $sql='SELECT n.id,n.name,n.is_active,COUNT(a.id) AS gestor_total, '
        .'COALESCE(SUM(CASE WHEN a.last_seen_at >= DATE_SUB(NOW(),INTERVAL '.arc_inventory_presence_seconds().' SECOND) THEN 1 ELSE 0 END),0) AS gestor_online, UNIX_TIMESTAMP(MAX(a.last_seen_at)) AS last_seen_ts '
        .'FROM '.arc_table('managed_networks').' n LEFT JOIN '.arc_table('network_gestores').' a ON a.network_id=n.id AND a.account_id=n.account_id AND a.status=\'approved\'';
    $args=[];
    if (!$admin) { $sql.=' WHERE n.account_id=?';$args[]=(int)$uid; }
    $sql.=' GROUP BY n.id,n.name,n.is_active ORDER BY n.is_active DESC,n.name,n.id';
    $q=$pdo->prepare($sql);$q->execute($args);
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    // Redes compartilhadas aparecem no mesmo inventário da conta, sem conceder titularidade.
    if (!$admin && $uid) {
        $sharedSql='SELECT n.id,n.name,n.is_active,m.role,COUNT(a.id) AS gestor_total, '
            .'COALESCE(SUM(CASE WHEN a.last_seen_at >= DATE_SUB(NOW(),INTERVAL '.arc_inventory_presence_seconds().' SECOND) THEN 1 ELSE 0 END),0) AS gestor_online, '
            .'UNIX_TIMESTAMP(MAX(a.last_seen_at)) AS last_seen_ts '
            .'FROM '.arc_table('network_team_members').' m '
            .'JOIN '.arc_table('managed_networks').' n ON n.id=m.network_id AND n.account_id=m.account_id '
            .'LEFT JOIN '.arc_table('network_gestores').' a ON a.network_id=n.id AND a.account_id=n.account_id AND a.status=\'approved\' '
            .'WHERE m.member_user_id=? AND m.enabled=1 AND n.is_active=1 AND n.account_id<>? '
            .'GROUP BY n.id,n.name,n.is_active,m.role ORDER BY n.name,n.id';
        $sharedStmt=$pdo->prepare($sharedSql);
        $sharedStmt->execute([(int)$uid,(int)$uid]);
        foreach ($sharedStmt->fetchAll(PDO::FETCH_ASSOC) as $sharedRow) {
            $sharedRow['shared']=1;
            $rows[]=$sharedRow;
        }
    }
    foreach($rows as &$r){
        $count=(int)$r['gestor_total'];$online=(int)$r['gestor_online'];
        $r['gestor_state']=$count===0?'none':($online===0?'offline':($online===$count?'online':'partial'));
        $r['has_online_gestor']=$online>0?1:0;
    }
    unset($r);
    return $rows;
};
/* Dispositivos inventariados de uma rede (modal compacto). Restrito à titularidade da rede. */
if (($_GET['ajax'] ?? '') === 'network_devices') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    try {
        require_once dirname(__DIR__).'/includes/network-inventory.php';
        $netId=(int)($_GET['network'] ?? 0);
        $sql='SELECT n.id,n.account_id,n.name,n.is_active,n.monitoring_enabled FROM '.arc_table('managed_networks').' n WHERE n.id=?';
        $args=[$netId];
        if(!$admin){
            $sql.=' AND (n.account_id=? OR EXISTS (SELECT 1 FROM '.arc_table('network_team_members').' tm WHERE tm.account_id=n.account_id AND tm.network_id=n.id AND tm.member_user_id=? AND tm.enabled=1 AND n.is_active=1))';
            $args[]=(int)$uid;$args[]=(int)$uid;
        }
        $q=$pdo->prepare($sql);$q->execute($args);
        $net=$q->fetch(PDO::FETCH_ASSOC);
        if(!$net) arc_json(['ok'=>false,'error'=>'Rede não encontrada.'],404);
        $acc=(int)$net['account_id'];
        $dashboard=arc_inventory_dashboard($pdo,$acc,$netId);
        // A varredura segue a mesma regra da API de comandos: apenas o titular, rede ativa e com monitoramento.
        $canScan=$uid!==null && (int)$net['account_id']===(int)$uid && (int)$net['is_active']===1 && (int)$net['monitoring_enabled']===1;
        $reason='';
        if(!$canScan) $reason=(int)$net['is_active']!==1?'Rede arquivada.':((int)$net['monitoring_enabled']!==1?'Monitoramento desativado para esta rede.':'Somente o titular da rede pode solicitar a varredura.');
        arc_json(['ok'=>true,'name'=>(string)$net['name'],'can_scan'=>$canScan,'scan_reason'=>$reason]+$dashboard);
    } catch(Throwable $e) {
        error_log('Painel dispositivos da rede: '.$e->getMessage());
        arc_json(['ok'=>false,'error'=>'Não foi possível carregar os dispositivos.'],503);
    }
}
if (($_GET['ajax'] ?? '') === 'cnpj_lookup') {
    $cnpj=preg_replace('/\D+/', '', (string)($_GET['cnpj']??''));
    if (strlen($cnpj)!==14) arc_json(['ok'=>false,'error'=>'Informe um CNPJ com 14 dígitos.'],422);
    if (!function_exists('curl_init')) arc_json(['ok'=>false,'error'=>'Consulta indisponível no servidor (extensão cURL ausente).'],503);
    $ch=curl_init('https://brasilapi.com.br/api/cnpj/v1/'.$cnpj);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_USERAGENT=>'Arcoll-Networks/1.0']);
    $body=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if (!is_string($body)||$http!==200||strlen($body)>150000) arc_json(['ok'=>false,'error'=>'Não foi possível consultar o CNPJ no momento.'],503);
    $data=json_decode($body,true);
    if (!is_array($data)||empty($data['razao_social'])) arc_json(['ok'=>false,'error'=>'CNPJ não encontrado na consulta pública.'],404);
    $fields=['razao_social','nome_fantasia','ddd_telefone_1','ddd_telefone_2','email','cep','uf','logradouro','numero','complemento','bairro','municipio','descricao_situacao_cadastral','cnae_fiscal_descricao','cnae_fiscal','natureza_juridica','data_inicio_atividade'];
    $filtered=[];foreach($fields as $field){$v=$data[$field]??'';$filtered[$field]=is_scalar($v)?mb_substr((string)$v,0,300):'';}
    arc_json(['ok'=>true,'data'=>$filtered]);
}
if (($_GET['ajax'] ?? '') === 'offline_overview') {
    try {
        $rows=[];$onlineCount=0;
        foreach ($loadPanelNetworks($pdo,$admin,$uid) as $net) {
            $isOnline=(int)$net['is_active']===1 && (int)$net['has_online_gestor']===1;
            if ($isOnline) { $onlineCount++; continue; }
            $rows[]=['name'=>(string)$net['name'],'last_seen'=>(int)($net['last_seen_ts']??0),'inactive'=>(int)$net['is_active']!==1,'no_gestor'=>(int)$net['gestor_total']===0];
        }
        arc_json(['ok'=>true,'online'=>$onlineCount,'total'=>$onlineCount+count($rows),'offline'=>$rows,'now'=>time()]);
    } catch(Throwable $e) { error_log('Resumo de redes offline: '.$e->getMessage()); arc_json(['ok'=>false,'error'=>'Não foi possível consultar as redes.'],503); }
}
if (($_GET['ajax'] ?? '') === 'network_status') {
    try {
        $states=[];
        foreach($loadPanelNetworks($pdo,$admin,$uid) as $network) {
            $states[(string)$network['id']]=['online'=>(int)$network['has_online_gestor']===1,'state'=>$network['gestor_state'],'total'=>(int)$network['gestor_total'],'online_count'=>(int)$network['gestor_online']];
        }
        arc_json(['ok'=>true,'networks'=>$states]);
    } catch(Throwable $e) {
        error_log('Painel estado das redes: '.$e->getMessage());
        arc_json(['ok'=>false,'error'=>'Não foi possível atualizar as redes.'],503);
    }
}
$panelNetworks=[];$panelNetworksFailed=false;
try { $panelNetworks=$loadPanelNetworks($pdo,$admin,$uid); }
catch(Throwable $e) { $panelNetworksFailed=true;error_log('Painel redes: '.$e->getMessage()); }
$panelOnline=count(array_filter($panelNetworks,static fn(array $n):bool=>(int)$n['is_active']===1 && (int)$n['has_online_gestor']===1));
$panelOffline=count($panelNetworks)-$panelOnline;
$ARCPageBodyClass='arc-panel-page';
$ARCMode='app';$ARCPageTitle='Painel';$ARCActiveNav='overview';$ARCHeaderActions='';
require dirname(__DIR__).'/includes/header.php';require dirname(__DIR__).'/includes/sidebar.php';
?>
<style>

.arc-panel-search:focus-within{border-color:var(--primary,#2877dd)}
.arc-panel-error{padding:12px;border:1px solid #d47466;border-radius:8px;color:#b03e35;margin:12px 0}.arc-panel-new:hover{filter:brightness(1.08);color:#fff}

.arc-panel-network-link[hidden]{display:none!important}
.arc-panel-shared{display:block;margin-top:5px;font-size:10px;font-weight:500;color:var(--primary,#36b6e8)}

.arc-pn-dialog{box-sizing:border-box;width:min(94vw,460px);max-width:94vw;max-height:94dvh;margin:auto;padding:0;border:1px solid var(--border,#dbe5ee);border-radius:12px;background:var(--surface,#fff);color:var(--text,#263648);box-shadow:0 20px 60px #0b243d55;overflow:hidden}
.arc-pn-dialog[open]{display:flex;flex-direction:column}
.arc-pn-dialog::backdrop{background:rgba(10,28,45,.6)}
.arc-pn-head{display:flex;align-items:center;gap:4px;padding:7px 6px 7px 11px;border-bottom:1px solid var(--border,#dbe5ee);background:var(--surface-alt,#edf5fa)}
.arc-pn-title{flex:1;min-width:0}
.arc-pn-title strong{display:block;font-size:13px;line-height:1.25;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.arc-pn-counts{display:flex;gap:9px;margin-top:1px;font-size:11px;line-height:1.2;opacity:.9;white-space:nowrap}
.arc-pn-counts button{display:inline-flex;align-items:center;gap:4px;padding:1px 5px;border:1px solid transparent;border-radius:10px;background:transparent;color:inherit;font:inherit;cursor:pointer}.arc-pn-counts button[aria-pressed="true"]{border-color:#1687d9;background:#1687d9;font-weight:800}.arc-pn-counts button:focus-visible{outline:2px solid #1687d9}
.arc-pn-counts i{display:inline-block;width:8px;height:8px;border-radius:50%}
.arc-pn-counts .on i{background:#16a34a}.arc-pn-counts .off i{background:var(--surface-alt)}.arc-pn-counts .all i{background:#1687d9}.arc-pn-rank{position:absolute;top:2px;right:2px;padding:1px 3px;border-radius:4px;font-size:8px;line-height:1.2;background:#1687d9;color:var(--text);font-weight:800}.arc-pn-sort{display:flex;gap:2px;align-items:center}.arc-pn-sort .arc-pn-btn{width:25px;height:23px;font-size:13px}.arc-pn-sort .arc-pn-btn[aria-pressed="true"]{color:var(--text);background:#1687d9}
.arc-pn-btn{flex:0 0 auto;display:grid;place-items:center;width:30px;height:30px;padding:0;border:0;border-radius:8px;background:transparent;color:inherit;font-size:17px;line-height:1;text-decoration:none;cursor:pointer}
.arc-pn-btn:hover{background:#6487be}
.arc-pn-btn:focus-visible{outline:2px solid #1687d9;outline-offset:1px}
.arc-pn-btn:disabled{opacity:.4;cursor:not-allowed;background:transparent}
.arc-pn-btn.busy i{animation:arcpnspin .9s linear infinite}
.arc-pn-views{flex:0 0 auto;display:flex;gap:2px;padding:2px;border-radius:9px;background:#6487be}
.arc-pn-views .arc-pn-btn{width:26px;height:26px;font-size:13px;border-radius:7px}
.arc-pn-views .arc-pn-btn[aria-pressed="true"]{background:#1687d9;color:var(--text)}
.arc-pn-body{box-sizing:border-box;min-height:0;padding:8px;overflow:auto;overscroll-behavior:contain}
.arc-pn-sec{margin-bottom:6px}.arc-pn-sec:last-child{margin-bottom:0}
.arc-pn-sechead{display:flex;align-items:center;gap:6px;height:16px;margin-bottom:3px;font-size:9.5px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);white-space:nowrap}
.arc-pn-sechead::after{content:"";flex:1;min-width:8px;height:1px;background:var(--border,#cfdbe6)}
.arc-pn-sechead small{font-size:9.5px;font-weight:700;letter-spacing:0;color:var(--muted)}
.arc-pn-grid{display:grid;gap:4px;justify-content:center;align-content:start}
.arc-pn-tile{position:relative;box-sizing:border-box;min-width:0;min-height:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;padding:2px;margin:0;border:1px solid var(--border,#dbe5ee);border-radius:8px;background:transparent;font:inherit;color:var(--muted);text-align:center;overflow:hidden;cursor:pointer;transition:transform .15s,box-shadow .15s}
.arc-pn-tile:hover{transform:translateY(-1px);box-shadow:0 3px 10px rgba(11,36,61,.18)}
.arc-pn-tile:focus-visible{outline:2px solid #1687d9;outline-offset:1px}
.arc-pn-tile[data-online="1"]{color:var(--muted);border-color:#86d9a5;background:#16a34a}
.arc-pn-tile i{font-size:calc(var(--s,48px)*.4);line-height:1;color:inherit}
.arc-pn-tile span{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;width:100%;overflow:hidden;font-size:var(--fs,8px);font-weight:600;line-height:1.1;word-break:break-word;color:inherit}
.arc-pn-msg{margin:0;padding:18px 6px;text-align:center;font-size:12px;opacity:.75}
.arc-pn-foot{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:6px 11px;border-top:1px solid var(--border,#dbe5ee);background:var(--surface-alt,#edf5fa);font-size:10.5px;line-height:1.3}
.arc-pn-foot>span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.arc-pn-foot #arc-pn-run{max-width:28%;text-align:right;font-weight:600}
.arc-pn-foot #arc-pn-run[data-k="err"]{color:#c0392b}.arc-pn-foot #arc-pn-run[data-k="ok"]{color:var(--muted)}.arc-pn-foot #arc-pn-run[data-k="busy"]{color:var(--primary)}
.arc-pn-tile[data-transition="online"]{color:var(--muted);border-color:#16a34a;background:#16a34a;animation:arcDeviceOnline .8s ease-in-out infinite}
.arc-pn-tile[data-transition="offline"]{color:#b77908;border-color:#f59e0b;background:#f59e0b;animation:arcDeviceOffline .8s ease-in-out infinite}
.arc-pn-tile[data-transition]::after{content:"";position:absolute;left:3px;top:3px;width:6px;height:6px;border-radius:50%;background:currentColor}
.arc-pn-info[data-transition="online"] .arc-pni-ico{background:#16a34a;animation:arcDeviceOnline .8s ease-in-out infinite}
.arc-pn-info[data-transition="offline"] .arc-pni-ico{background:#f59e0b;animation:arcDeviceOffline .8s ease-in-out infinite}
@keyframes arcDeviceOnline{0%,100%{box-shadow:0 0 0 0 rgba(22,163,74,.12)}50%{box-shadow:0 0 0 3px rgba(22,163,74,.6);filter:brightness(1.18)}}
@keyframes arcDeviceOffline{0%,100%{box-shadow:0 0 0 0 rgba(245,158,11,.12)}50%{box-shadow:0 0 0 3px rgba(245,158,11,.65);filter:brightness(1.15)}}
.arc-pn-info{box-sizing:border-box;width:min(88vw,300px);max-width:88vw;max-height:90dvh;margin:auto;padding:0;border:1px solid var(--border,#dbe5ee);border-radius:12px;background:var(--surface,#fff);color:var(--text,#263648);box-shadow:0 20px 60px #0b243d77;overflow:hidden}
.arc-pn-info[open]{display:flex;flex-direction:column}
.arc-pn-info::backdrop{background:rgba(10,28,45,.45)}
.arc-pni-head{display:flex;align-items:center;gap:9px;padding:9px 6px 9px 11px;border-bottom:1px solid var(--border,#dbe5ee);background:var(--surface-alt,#edf5fa)}
.arc-pni-ico{flex:0 0 32px;width:32px;height:32px;border-radius:9px;display:grid;place-items:center;font-size:16px;color:var(--text);background:var(--surface-alt)}
.arc-pn-info[data-online="1"] .arc-pni-ico{background:#16a34a}
.arc-pni-t{flex:1;min-width:0}.arc-pni-t strong{display:block;font-size:13px;line-height:1.25;overflow-wrap:anywhere}.arc-pni-t span{display:block;font-size:10.5px;opacity:.7}
.arc-pni-status{padding:10px 12px 8px;text-align:center;border-bottom:1px dashed var(--border,#dbe5ee)}
.arc-pni-pill{display:inline-flex;align-items:center;gap:6px;padding:2px 10px;border-radius:12px;font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:var(--muted);background:color-mix(in srgb, var(--surface-alt) 18%, transparent)}
.arc-pni-pill i{width:8px;height:8px;border-radius:50%;background:var(--surface-alt)}
.arc-pn-info[data-online="1"] .arc-pni-pill{color:var(--muted);background:#16a34a}.arc-pn-info[data-online="1"] .arc-pni-pill i{background:#16a34a;animation:arcpnpulse 1.8s ease-in-out infinite}
.arc-pni-dur{margin-top:6px;font-size:17px;font-weight:800;line-height:1.2;font-variant-numeric:tabular-nums}
.arc-pni-since{margin-top:2px;font-size:11px;opacity:.8}
.arc-pni-warn{margin:8px 0 0;padding:5px 8px;border-radius:7px;font-size:10px;line-height:1.35;color:var(--text);background:#f0a93e}
.arc-pni-rows{margin:0;padding:6px 12px 10px;overflow:auto}
.arc-pni-rows div{display:grid;grid-template-columns:84px minmax(0,1fr);gap:8px;padding:4px 0;font-size:11px;border-bottom:1px solid color-mix(in srgb, var(--border) 14%, transparent)}.arc-pni-rows div:last-child{border-bottom:0}
.arc-pni-rows dt{margin:0;opacity:.65}.arc-pni-rows dd{margin:0;font-weight:600;overflow-wrap:anywhere;font-variant-numeric:tabular-nums}
@keyframes arcpnspin{to{transform:rotate(360deg)}}
@keyframes arcpnpulse{0%,100%{box-shadow:0 0 0 0 rgba(22,163,74,.5)}50%{box-shadow:0 0 0 4px rgba(22,163,74,0)}}
.app-main{display:flex;overflow:hidden;padding:14px 18px}
.arc-panel-unified{display:flex;flex-direction:column;gap:10px;flex:1;min-height:0;max-width:none;margin-inline:auto}
.arc-panel-heading{display:flex;align-items:center;justify-content:space-between;gap:8px;flex:none}
.arc-panel-eyebrow{font-size:10px;font-weight:750;letter-spacing:.16em;color:var(--primary)}
.arc-panel-heading h1{margin:5px 0 4px;font-size:clamp(18px,1.7vw,23px);line-height:1.2;letter-spacing:-.035em;color:var(--text)}
.arc-panel-heading p{margin:0;color:var(--muted);font-size:12px}
.arc-panel-sync{display:inline-flex;align-items:center;gap:7px;white-space:nowrap;font-size:11px;color:var(--muted)}
.arc-panel-sync>span{width:6px;height:6px;border-radius:50%;background:#21a67a;box-shadow:0 0 0 4px #21a67a13}.arc-panel-sync.is-stale{color:var(--warning)}
.arc-panel-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;flex:none}
.arc-panel-metric{position:relative;display:grid;grid-template-columns:30px 1fr;align-items:center;gap:0 8px;min-width:0;padding:9px 12px;text-align:left;color:var(--text);font:inherit;border:1px solid var(--border);border-radius:10px;background:var(--surface);cursor:pointer;box-shadow:0 3px 12px #17354e03}
.arc-panel-metric[aria-pressed=true]{border-color:var(--primary);box-shadow:0 0 0 1px color-mix(in srgb,var(--primary) 14%,transparent)}
.arc-panel-metric>span{min-width:0}.arc-panel-metric small{display:block;line-height:1.3;font-size:11px;color:var(--muted)}.arc-panel-metric strong{display:block;font-size:21px;line-height:1.2;letter-spacing:-.04em;margin-top:4px}
.arc-metric-icon{width:30px;height:30px;display:grid;place-items:center;border-radius:8px;background:#1687d910;color:var(--primary);font-size:18px}.arc-panel-metric.on .arc-metric-icon{color:#159774;background:#15977410}.arc-panel-metric.off .arc-metric-icon{color:#bc791a;background:#bc791a10}
.arc-metric-caption{grid-column:2;font-size:10px;color:var(--muted);margin-top:4px}
.arc-panel-commandbar{display:flex;align-items:center;gap:12px;flex-wrap:nowrap;min-width:0;margin:0;padding:0;border:0;border-radius:12px;background:none;box-shadow:none;flex:none}
.arc-panel-search{display:flex;align-items:center;gap:10px;flex:1 1 0;min-width:0;padding:0 14px;border:1px solid var(--border,#dbe5ee);border-radius:12px;height:38px;color:var(--muted,#718096);width:0;background:var(--surface);font-size:13px}
.arc-panel-new{display:inline-flex;align-items:center;justify-content:center;gap:8px;flex:0 0 auto;min-height:38px;padding:0 12px;text-decoration:none;border-radius:12px;background:var(--primary);color:#fff;font-size:12px;font-weight:700;border:0;cursor:pointer;width:auto;height:38px}
.arc-panel-new>span:first-child{font-size:23px;font-weight:400;line-height:1}
.arc-panel-network-block{background:transparent;box-shadow:none;border:0;padding:0;min-width:0;display:flex;flex-direction:column;flex:1;min-height:0;margin:0;gap:12px}
.arc-panel-section-heading{display:flex;justify-content:space-between;align-items:center;gap:8px;flex:none}
.arc-panel-section-heading h2{margin:0;font-size:14px;color:var(--text);opacity:1;font-weight:700;display:flex;align-items:center;gap:8px}
.arc-panel-section-heading h2>span{display:inline-grid;place-items:center;min-width:24px;min-height:22px;padding:1px 5px;border-radius:7px;background:var(--surface);color:var(--muted);font-size:11px;border:1px solid var(--border)}
.arc-panel-section-hint{font-size:11px;color:var(--muted)}
.arc-panel-network-rail{display:grid;flex-wrap:nowrap;gap:8px;overflow-x:auto;max-width:100%;min-width:0;padding:2px;scroll-snap-type:none;overscroll-behavior-x:contain;-webkit-overflow-scrolling:touch;scrollbar-width:thin;scroll-padding-inline:6px;grid-template-columns:repeat(var(--panel-cols,2),minmax(0,1fr));grid-template-rows:repeat(var(--panel-rows,1),minmax(0,1fr));flex:1;min-height:0;overflow:hidden;align-content:start}
.arc-panel-network-link{flex:0 0 128px;box-sizing:border-box;width:auto;min-width:0;max-width:none;display:flex;flex-direction:column;align-items:stretch;gap:4px;padding:10px;border:1px solid var(--border);border-radius:10px;color:var(--text);text-decoration:none;scroll-snap-align:start;background:var(--surface);position:relative;isolation:isolate;overflow:hidden;min-height:0;justify-content:flex-start;box-shadow:0 4px 16px #12304b04;transition:transform .24s cubic-bezier(.2,.8,.2,1),box-shadow .24s ease,border-color .24s ease,background-color .24s ease;transform:none;max-height:156px;text-align:left}

.arc-panel-card-top{display:flex;align-items:center;justify-content:space-between;gap:10px;flex:none}
.arc-panel-card-icon{display:grid;place-items:center;flex:none;width:28px;height:28px;color:var(--primary);background:color-mix(in srgb,var(--primary) 8%,transparent);border:1px solid color-mix(in srgb,var(--primary) 12%,transparent);border-radius:8px}
.arc-panel-card-icon svg{width:20px;height:20px}
.arc-panel-status-dot{width:8px;height:8px;border-radius:50%;background:var(--surface-alt);box-shadow:0 0 0 4px #94a3b811}
[data-online="1"] .arc-panel-status-dot{background:#159774;box-shadow:0 0 0 4px #15977412}
[data-gestor-state="partial"] .arc-panel-status-dot{background:#c68a20;box-shadow:0 0 0 4px #c68a2012}
[data-gestor-state="offline"] .arc-panel-status-dot{background:#ce5965;box-shadow:0 0 0 4px #ce596512}
.arc-panel-network-name{display:-webkit-box;box-sizing:border-box;width:100%;max-width:100%;min-width:0;white-space:normal;overflow:hidden;text-overflow:ellipsis;text-align:left;font-size:12px;font-weight:700;letter-spacing:-.015em;-webkit-line-clamp:2;-webkit-box-orient:vertical;line-height:1.3;padding:0;flex:none;margin-top:4px;overflow-wrap:anywhere}
.arc-panel-network-state{position:static;width:auto;height:auto;overflow:hidden;clip-path:none;text-overflow:ellipsis;white-space:nowrap;font-size:11px;color:var(--muted);flex:none}
.arc-panel-card-action{display:flex;justify-content:space-between;align-items:center;gap:6px;margin-top:auto;padding-top:8px;font-size:10px;color:var(--primary);border-top:1px solid var(--border);flex:none}.arc-panel-card-action>span{font-size:16px;line-height:1}
.arc-panel-network-link:hover{border-color:var(--primary);transform:none;box-shadow:0 6px 18px #12304b0b}.arc-panel-network-link:focus-visible{outline:2px solid var(--primary);outline-offset:-3px}
.arc-panel-pagination{display:flex;justify-content:space-between;align-items:center;gap:8px;flex:none;min-height:42px;font-size:11px;color:var(--muted)}
.arc-panel-pagination>div{display:flex;align-items:center;gap:10px}.arc-panel-pagination button{width:42px;height:42px;border:1px solid var(--border);border-radius:12px;background:var(--surface);color:var(--text);font:25px/1 var(--font);cursor:pointer}.arc-panel-pagination button:disabled{opacity:.35;cursor:default}
#arc-panel-page{min-width:40px;text-align:center;font-variant-numeric:tabular-nums}
.arc-panel-empty{display:flex;align-items:center;justify-content:center;flex-direction:column;gap:9px;text-align:center;flex:1;min-height:0;padding:12px;border:1px dashed var(--border);border-radius:18px;color:var(--muted);background:var(--surface)}
.arc-panel-empty h3{font-size:16px;color:var(--text);margin:0}.arc-panel-empty p{font-size:12px;margin:0;max-width:300px}.arc-panel-empty-icon{font-size:34px;line-height:1.2;color:var(--primary)}.arc-panel-empty .button{margin-top:7px}
[hidden]{display:none!important}

.arc-panel-search input{width:100%;min-width:0;background:transparent!important;border:0!important;outline:0!important;color:var(--text);font:inherit;font-size:14px;box-shadow:none!important;padding:0!important;min-height:0!important;height:100%;border-radius:0}

.arc-panel-network-rail[data-compact=true] .arc-panel-card-top{display:none}
@media(hover:none){.arc-panel-network-link:hover{transform:none;box-shadow:0 3px 12px rgba(20,48,87,.05)}}
@media(max-width:860px){
 .app-main{padding:10px 10px 4px}.arc-panel-unified{gap:8px}
 .arc-panel-heading h1{font-size:18px;margin-top:2px}.arc-panel-eyebrow{font-size:9px}.arc-panel-heading p{font-size:11px}.arc-panel-sync{display:none}
 .arc-panel-summary{gap:7px}.arc-panel-metric{padding:7px 8px;grid-template-columns:1fr;gap:2px;border-radius:13px}.arc-metric-icon{display:none}.arc-metric-caption{display:none}.arc-panel-metric small{font-size:10px}.arc-panel-metric strong{font-size:19px;margin-top:2px}.arc-panel-metric.on strong{color:#159774}.arc-panel-metric.off strong{color:#bc791a}
 .arc-panel-commandbar{gap:8px}.arc-panel-search{height:40px;padding-inline:11px}.arc-panel-search input{font-size:16px;line-height:1.2}.arc-panel-search input::placeholder{font-size:12px}.arc-panel-new{height:40px;min-height:40px;padding-inline:10px;font-size:11px;gap:6px}
 .arc-panel-section-hint{display:none}.arc-panel-section-heading h2{font-size:13px}.arc-panel-network-block{gap:8px}
 .arc-panel-network-rail{gap:7px}.arc-panel-network-link{padding:9px;gap:4px;border-radius:10px;max-height:150px}.arc-panel-card-icon{width:26px;height:26px;border-radius:8px}.arc-panel-card-icon svg{width:19px;height:19px}.arc-panel-network-name{font-size:13px}.arc-panel-network-state{font-size:10px}.arc-panel-card-action{padding-top:5px;font-size:9px}
 .arc-panel-pagination{min-height:42px;font-size:10px}.arc-panel-pagination>div{gap:6px}
 #modal-register .arc-register-grid .arc-field input{height:40px;min-height:40px;font-size:16px}
 #modal-register .arc-register-grid .arc-field textarea{height:40px;min-height:40px;font-size:16px}#modal-register .arc-register-grid{gap:10px}#modal-register .arc-register-grid .arc-field{font-size:11px}#modal-register>.modal{border-radius:20px;max-height:calc(100dvh - 24px)}#modal-register .arc-register-footer .button{min-height:44px}#modal-register .arc-register-footer{bottom:-10px}
 .arc-pn-dialog{border-radius:20px;max-height:calc(100dvh - 24px)}.arc-pn-head{padding:8px}.arc-pn-head>.arc-pn-btn{width:40px;height:40px}.arc-pn-body{overscroll-behavior:contain}
}
@media(max-width:480px){.arc-pn-dialog{width:96vw;max-width:96vw}.arc-pn-body{padding:6px}.arc-pn-head{padding-left:9px;gap:2px}.arc-pn-btn{width:28px;height:28px}.arc-pn-views .arc-pn-btn{width:24px;height:24px}.arc-pn-counts{gap:7px;font-size:10.5px}}
@media(max-height:650px){
 .app-main{padding-block:8px 4px}.arc-panel-unified{gap:8px}.arc-panel-heading p{display:none}.arc-panel-eyebrow{display:none}.arc-panel-heading h1{font-size:19px;margin:0}.arc-panel-metric{padding-block:8px}.arc-panel-metric strong{font-size:20px}.arc-panel-network-link{padding:10px;gap:4px}.arc-panel-card-icon{width:30px;height:30px}.arc-panel-card-icon svg{width:23px;height:23px}.arc-panel-card-action{padding-top:3px}
}
@media(max-height:460px){
 .arc-panel-heading{display:none}.arc-panel-summary{display:flex;gap:8px}.arc-panel-metric{flex:1;display:flex;align-items:center;justify-content:space-between;padding:5px 10px}.arc-panel-metric>span:not(.arc-metric-icon):not(.arc-metric-caption){display:flex;align-items:center;gap:8px}.arc-panel-metric strong{margin:0;font-size:18px}.arc-panel-unified{gap:6px}.arc-panel-network-block{gap:5px}.arc-panel-network-link{padding:8px;gap:3px}.arc-panel-card-top{display:none}.arc-panel-network-name{margin-top:0}.arc-panel-card-action{border:0;padding:0}.arc-panel-network-state{font-size:9px}.arc-panel-pagination{min-height:34px;height:34px}.arc-panel-pagination button{min-height:34px;height:34px}
}
@media(prefers-reduced-motion:reduce){.arc-pn-tile[data-transition]{animation:none!important;outline:2px solid currentColor}.arc-pn-info[data-transition] .arc-pni-ico{animation:none!important;outline:2px solid currentColor}.arc-pn-btn.busy i{animation:none;transition:none}.arc-pn-info[data-online="1"] .arc-pni-pill i{animation:none;transition:none}.arc-pn-tile{animation:none;transition:none}.arc-panel-network-link{transition:none!important;animation:none!important}.arc-panel-network-link:hover{transform:none}.arc-panel-network-link:active{transform:none}*{scroll-behavior:auto!important;transition:none!important}}



/* Painel: comandos em barra única e cartões centralizados */
.arc-panel-topbar{display:flex;align-items:center;gap:8px;min-width:0;flex:none}
.arc-panel-topbar .arc-panel-commandbar{display:contents}
.arc-panel-topbar .arc-panel-search{order:0;flex:1 1 180px;width:auto;min-width:65px}
.arc-panel-topbar .arc-panel-summary{order:1;display:flex;gap:6px;min-width:0;flex:0 1 auto}
.arc-panel-topbar .arc-panel-metric{display:flex;align-items:center;gap:6px;padding:5px 9px;min-height:38px;min-width:0;box-shadow:none}
.arc-panel-topbar .arc-panel-metric .arc-metric-icon,.arc-panel-topbar .arc-metric-caption{display:none}
.arc-panel-topbar .arc-panel-metric>span:not(.arc-metric-icon):not(.arc-metric-caption){display:flex;align-items:center;gap:5px}
.arc-panel-topbar .arc-panel-metric small{font-size:10px;white-space:nowrap}
.arc-panel-topbar .arc-panel-metric strong{font-size:15px;margin:0}
.arc-panel-topbar .arc-panel-new{order:2}
.arc-panel-network-rail{grid-template-columns:repeat(auto-fill,minmax(112px,1fr))!important;grid-template-rows:none!important;overflow-y:auto;align-content:start;flex:1}
.arc-panel-network-link{justify-content:center;align-items:center;min-height:110px;max-height:142px;text-align:center;padding:12px 8px}
.arc-panel-card-top{display:flex!important;justify-content:center;width:100%}
.arc-panel-card-icon{height:46px;width:46px}.arc-panel-card-icon svg{height:31px;width:31px}
.arc-panel-status-dot{position:absolute;right:9px;top:9px}
.arc-panel-network-name{display:block;-webkit-line-clamp:unset;text-align:center;white-space:normal;margin:3px 0 0;font-size:12px;overflow-wrap:anywhere}
@media(max-width:860px){
 .arc-panel-topbar{gap:5px}
 .arc-panel-topbar .arc-panel-search{height:36px;padding:0 7px;flex:1 1 80px}
 .arc-panel-topbar .arc-panel-search input{font-size:16px}
 .arc-panel-topbar .arc-panel-metric{height:36px;min-height:36px;padding:3px 5px}
 .arc-panel-topbar .arc-panel-metric small{display:none}
 .arc-panel-topbar .arc-panel-metric strong{font-size:12px}
 .arc-panel-topbar .arc-panel-new{height:36px;min-height:36px;width:36px;padding:0}
 .arc-panel-topbar .arc-panel-new>span:last-child{display:none}
 .arc-panel-network-rail{grid-template-columns:repeat(auto-fill,minmax(88px,1fr))!important;gap:7px}
 .arc-panel-network-link{min-height:96px;max-height:128px;padding:8px 5px}
 .arc-panel-network-name{font-size:11px}
}

/* Painel 3.9.1 — cartões, indicadores e paginação compactos */
.arc-panel-network-link{min-width:0;overflow:hidden}
.arc-panel-network-name{display:block!important;width:100%;max-width:100%;min-width:0;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important;overflow-wrap:normal!important;-webkit-line-clamp:unset!important;line-height:1.4}
.arc-panel-topbar .arc-panel-summary{gap:5px;flex:none}
.arc-panel-topbar .arc-panel-metric{display:inline-flex;flex:none;justify-content:center;align-items:center;gap:5px;height:36px;min-height:36px;padding:4px 7px;border-radius:10px;line-height:1}
.arc-panel-topbar .arc-panel-metric .arc-metric-icon{display:grid;place-items:center;width:20px;height:22px;font-size:15px;background:none}
.arc-panel-topbar .arc-panel-metric>span:not(.arc-metric-icon):not(.arc-metric-caption){display:flex;align-items:center;gap:0}
.arc-panel-topbar .arc-panel-metric small{display:none}
.arc-panel-topbar .arc-panel-metric strong{display:inline-flex;align-items:center;justify-content:center;min-width:17px;height:18px;padding:0 4px;border:1px solid color-mix(in srgb,var(--primary) 36%,transparent);border-radius:7px;background:color-mix(in srgb,var(--primary) 13%,transparent);font-size:11px;font-weight:800;line-height:1;color:var(--primary)}
.arc-panel-topbar .arc-panel-metric.on strong{color:#20be92;border-color:#20be9240;background:#20be9214}
.arc-panel-topbar .arc-panel-metric.off strong{color:#e4a74d;border-color:#e4a74d40;background:#e4a74d14}
.arc-panel-topbar .arc-panel-new{width:auto;min-width:36px;height:36px;min-height:36px;border:1px solid color-mix(in srgb,var(--primary) 55%,transparent);border-radius:10px;padding:0 10px;box-shadow:none}
.arc-panel-topbar .arc-panel-new>span:first-child{display:grid;place-items:center;font-size:16px}
.arc-panel-pagination:not([hidden]){min-height:30px;height:30px;gap:6px;font-size:10px}
.arc-panel-pagination>div{gap:5px}
.arc-panel-pagination button{width:28px;height:26px;border-radius:8px;font-size:17px}
#arc-panel-page{min-width:31px}
@media(max-width:860px){
 .arc-panel-topbar{gap:5px}
 .arc-panel-topbar .arc-panel-metric{height:34px;min-height:34px;padding:3px 4px;gap:2px;border-radius:9px}
 .arc-panel-topbar .arc-panel-metric .arc-metric-icon{display:grid;width:17px;height:18px;font-size:13px}
 .arc-panel-topbar .arc-panel-metric strong{min-width:15px;height:17px;padding:0 3px;font-size:10px}
 .arc-panel-topbar .arc-panel-new{width:34px;min-width:34px;height:34px;min-height:34px;padding:0}
 .arc-panel-topbar .arc-panel-search{min-width:40px;height:34px}
 .arc-panel-pagination:not([hidden]){min-height:27px;height:27px}
}
</style>
<main class="app-main"><div class="page-content arc-panel-unified" data-role="<?=arc_h($role)?>">
<header class="arc-panel-heading"><span class="arc-panel-sync" id="arc-panel-sync"><span></span> Atualização automática</span></header>
<div class="arc-panel-topbar"><div class="arc-panel-summary" role="group" aria-label="Filtrar redes por situação">
<button type="button" class="arc-panel-metric" data-panel-filter="all" title="Todas as redes" aria-pressed="true"><span class="arc-metric-icon"><i class="fa fa-sitemap" aria-hidden="true"></i></span><span><small>Redes registradas</small><strong id="arc-panel-total"><?=count($panelNetworks)?></strong></span><span class="arc-metric-caption">Todos os ambientes</span></button>
<button type="button" class="arc-panel-metric on" data-panel-filter="online" title="Redes conectadas" aria-pressed="false"><span class="arc-metric-icon"><i class="fa fa-signal" aria-hidden="true"></i></span><span><small>Conectadas</small><strong id="arc-panel-online"><?=$panelOnline?></strong></span><span class="arc-metric-caption">Gestores em comunicação</span></button>
<button type="button" class="arc-panel-metric off" data-panel-filter="attention" title="Redes sem conexão" aria-pressed="false"><span class="arc-metric-icon"><i class="fa fa-bell-o" aria-hidden="true"></i></span><span><small>Sem conexão</small><strong id="arc-panel-offline"><?=$panelOffline?></strong></span><span class="arc-metric-caption">Offline ou sem gestor</span></button>
</div>
<div class="arc-panel-commandbar" role="search" aria-label="Buscar redes">
<label class="arc-panel-search" for="arc-panel-search"><i class="fa fa-search" aria-hidden="true"></i><input id="arc-panel-search" type="search" placeholder="Buscar pelo nome da rede" autocomplete="off" aria-label="Buscar redes"></label>
<button class="arc-panel-new" type="button" data-open-register aria-label="Cadastrar nova rede"><span aria-hidden="true"><i class="fa fa-plus"></i></span><span>Nova rede</span></button>
</div></div>
<?php if($arcError!==''):?><p class="arc-panel-error" role="alert"><?=arc_h($arcError)?></p><?php endif;?>
<section class="arc-panel-network-block" aria-labelledby="panel-networks-title">
<div class="arc-panel-section-heading"><h2 id="panel-networks-title">Meus ambientes <span id="arc-panel-result-count"><?=count($panelNetworks)?></span></h2><span class="arc-panel-section-hint">Toque em uma rede para acompanhar</span></div>
<?php if($panelNetworksFailed):?><div class="arc-panel-empty" role="alert"><h3>Não foi possível carregar as redes</h3><p>Verifique sua conexão e atualize a página.</p><a class="button" href="pages/painel.php" aria-label="Tentar novamente" title="Tentar novamente"><i class="fa fa-arrow-right" aria-hidden="true"></i><span class="arc-action-label">Tentar novamente</span></a></div>
<?php else: ?>
<div class="arc-panel-network-rail" id="arc-panel-grid" role="region" aria-label="Redes registradas">
<?php foreach($panelNetworks as $network):$on=(int)$network['is_active']===1 && (int)$network['has_online_gestor']===1;$state=['online'=>'Todos online','partial'=>'Parte dos gestores offline','offline'=>'Gestores offline','none'=>'Sem gestores'][$network['gestor_state']]; if((int)$network['is_active']!==1)$state='Rede arquivada'; ?>
<a class="arc-panel-network-link" data-panel-network="<?=(int)$network['id']?>" data-shared="<?=!empty($network['shared'])?'1':'0'?>" data-gestor-state="<?=arc_h($network['gestor_state'])?>" data-online="<?=$on?'1':'0'?>" data-active="<?=(int)$network['is_active']===1?'1':'0'?>" href="<?=arc_h(arc_workspace_base_url()).'redes/painel.php?network='.(int)$network['id']?>" title="<?=arc_h((string)$network['name'].' — '.$state)?>" aria-label="<?=arc_h((string)$network['name'].' — '.$state)?>" data-name="<?=arc_h((string)$network['name'])?>">
<span class="arc-panel-card-top"><span class="arc-panel-card-icon" aria-hidden="true"><svg viewBox="0 0 32 32" fill="none"><rect x="5" y="5" width="22" height="15" rx="3" stroke="currentColor" stroke-width="1.8"/><path d="M16 20v6m-5 0h10M10 11h5m-5 4h9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span><span class="arc-panel-status-dot" aria-hidden="true"></span></span>
<span class="arc-panel-network-name"><?=arc_h((string)$network['name'])?><?php if(!empty($network['shared'])): ?> <small class="arc-panel-shared">Compartilhada · <?=arc_h(['administrator'=>'Administrador','operator'=>'Operador','viewer'=>'Visualizador'][(string)($network['role']??'viewer')]??'Visualizador')?></small><?php endif; ?></span>
</a>
<?php endforeach; ?>
</div>
<div class="arc-panel-empty" id="arc-panel-search-empty" hidden><span class="arc-panel-empty-icon" aria-hidden="true">⌕</span><h3 id="arc-panel-empty-title">Nenhuma rede encontrada</h3><p id="arc-panel-empty-text">Tente outro nome ou selecione todos os ambientes.</p><button type="button" class="button" id="arc-panel-clear" aria-label="Limpar filtros" title="Limpar filtros"><i class="fa fa-eraser" aria-hidden="true"></i><span class="arc-action-label">Limpar filtros</span></button><button type="button" class="button primary" id="arc-panel-empty-create" data-open-register hidden aria-label="Registrar minha primeira rede" title="Registrar minha primeira rede"><i class="fa fa-arrow-right" aria-hidden="true"></i><span class="arc-action-label">Registrar minha primeira rede</span></button></div>
<div class="arc-panel-pagination" aria-label="Páginas de redes" hidden><span id="arc-panel-range" role="status" aria-live="polite"></span><div><button type="button" id="arc-panel-prev" aria-label="Página anterior">‹</button><span id="arc-panel-page" aria-label="Página atual">1 / 1</span><button type="button" id="arc-panel-next" aria-label="Próxima página">›</button></div></div>
<?php endif; ?>
</section>
</div></main>
<div class="modal-overlay" id="modal-register" role="dialog" aria-modal="true" aria-labelledby="register-modal-title" aria-hidden="true">
    <div class="modal">
        <div class="modal-header">
            <h2 id="register-modal-title">Registrar nova rede</h2>
            <button class="modal-dismiss" type="button" data-close-modal><i class="fa fa-times" aria-hidden="true"></i></button>
        </div>
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data" id="form-register" action="<?=arc_h(arc_workspace_base_url())?>pages/painel.php">
                <input type="hidden" name="_csrf" value="<?= arc_h(arc_csrf_token()) ?>">
                <input type="hidden" name="action" value="network_create">

                <div class="arc-register-grid">
                    <div class="arc-register-primary">
                        <label class="arc-logo-picker" title="Selecionar a logo da rede">
                            <span id="arc-logo-preview"><i class="fa fa-image" aria-hidden="true"></i></span>
                            <span class="arc-logo-caption">Logo da rede</span>
                            <input id="reg-logo" type="file" name="network_logo" accept="image/png,image/jpeg,image/webp" aria-label="Selecionar logo (até 2 MB)">
                        </label>
                        <div class="arc-register-identify">
                            <label class="arc-field">CPF ou CNPJ <span class="arc-doc-wrap"><input name="doc_number" id="doc-number" inputmode="numeric" maxlength="18" autocomplete="off" placeholder="CPF ou CNPJ"><span class="doc-badge" id="doc-badge">Documento</span></span></label>
                            <label class="arc-field">Nome da rede<input name="network_name" id="reg-name" maxlength="100" required placeholder="Matriz, filial, escritório..."></label>
                        </div>
                    </div>
                    <p class="doc-status span2" id="doc-status" role="status" aria-live="polite">CNPJ: consulta automática ao completar os 14 dígitos.</p>
                    <label class="arc-field">Razão social / cliente<input name="client_name" id="reg-client" maxlength="160" required></label>
                    <label class="arc-field">Nome fantasia<input name="trade_name" id="reg-fantasy" maxlength="160"></label>
                    <label class="arc-field">Responsável<input name="contact_name" id="reg-contact" maxlength="160"></label>
                    <label class="arc-field">Telefone<input name="contact_phone" id="reg-phone" maxlength="40" inputmode="tel"></label>
                    <label class="arc-field">E-mail da empresa<input name="company_email" id="reg-email" type="email" maxlength="160"></label>
                    <label class="arc-field">CEP<input name="zip_code" id="reg-cep" inputmode="numeric" maxlength="9"></label>
                    <label class="arc-field">UF<input name="state_code" id="reg-uf" maxlength="2"></label>
                    <label class="arc-field">Município<input name="city_name" id="reg-city" maxlength="100"></label>
                    <label class="arc-field span2">Endereço completo<input name="address" id="reg-address" maxlength="255"></label>
                    <details class="arc-register-more span2"><summary><i class="fa fa-sliders" aria-hidden="true"></i> Mais detalhes do cadastro (opcional)</summary>
                        <div class="arc-register-more-grid">
                            <label class="arc-field">Logradouro<input name="street_name" id="reg-street" maxlength="160"></label>
                            <label class="arc-field">Número<input name="street_number" id="reg-number" maxlength="20"></label>
                            <label class="arc-field">Complemento<input name="address_extra" id="reg-complement" maxlength="100"></label>
                            <label class="arc-field">Bairro<input name="district_name" id="reg-district" maxlength="100"></label>
                            <label class="arc-field">Situação cadastral<input name="registration_status" id="reg-status" readonly></label>
                            <label class="arc-field">CNAE principal<input name="main_activity" id="reg-cnae" readonly></label>
                            <label class="arc-field">Natureza jurídica<input name="legal_nature" id="reg-legal" readonly></label>
                            <label class="arc-field">Data de abertura<input name="opening_date" id="reg-opening" readonly></label>
                            <label class="arc-field span2">Observações<textarea name="notes" id="reg-notes" maxlength="5000" rows="2"></textarea></label>
                        </div>
                    </details>
                </div>
                <div class="arc-register-footer"><span>O código da rede será gerado automaticamente.</span><button class="button primary" type="submit"><i class="fa fa-check" aria-hidden="true"></i> Registrar rede</button></div>

            </form>
        </div>
    </div>
</div>

<script>
(function(){
'use strict';
var modal=document.getElementById('modal-register');
if(!modal)return;
/* Delegação: o botão do footer é renderizado depois deste script. */
var previousFocus=null;
function open(){previousFocus=document.activeElement;modal.classList.add('open');modal.setAttribute('aria-hidden','false');document.body.classList.add('arc-modal-active');document.getElementById('doc-number')?.focus();}
function close(){modal.classList.remove('open');modal.setAttribute('aria-hidden','true');document.body.classList.remove('arc-modal-active');previousFocus?.focus();}
document.addEventListener('click',function(e){
    if(e.target.closest('[data-open-register]')) { e.preventDefault(); open(); }
});
modal.querySelector('[data-close-modal]')?.addEventListener('click',close);
modal.addEventListener('click',e=>{if(e.target===modal)close();});
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&modal.classList.contains('open'))close();});
if(new URLSearchParams(location.search).get('nova_rede')==='1')open();
    /* Documento com reconhecimento progressivo e consulta de CNPJ. */
    var docInput = document.getElementById('doc-number');
    var docBadge = document.getElementById('doc-badge');
    var docStatus = document.getElementById('doc-status');
    var lastCnpj = '', requestController = null, lookupTimer = null;
    function digits(value) { return String(value || '').replace(/\D/g, ''); }
    function maskDocument(value) {
        var d = digits(value).slice(0,14);
        if (d.length <= 11) return d.replace(/^(\d{3})(\d)/,'$1.$2').replace(/^(\d{3})\.(\d{3})(\d)/,'$1.$2.$3').replace(/(\d{3})(\d{1,2})$/,'$1-$2');
        return d.replace(/^(\d{2})(\d)/,'$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/,'$1.$2.$3').replace(/\.(\d{3})(\d)/,'.$1/$2').replace(/(\d{4})(\d{1,2})$/,'$1-$2');
    }
    function put(id, value) { var el = document.getElementById(id); if (el) el.value = value == null ? '' : String(value); }
    function status(message, type) { docStatus.textContent = message; docStatus.className = 'doc-status span2' + (type ? ' ' + type : ''); }
    function addressFromFields() {
        var get = function(id){ return (document.getElementById(id)?.value || '').trim(); };
        put('reg-address', [get('reg-street'), get('reg-number'), get('reg-complement'), get('reg-district'), [get('reg-city'),get('reg-uf')].filter(Boolean).join('/'), get('reg-cep') ? 'CEP ' + get('reg-cep') : ''].filter(Boolean).join(', ').slice(0,255));
    }
    ['reg-street','reg-number','reg-complement','reg-district','reg-city','reg-uf','reg-cep'].forEach(function(id) {
        var el=document.getElementById(id); if (el) el.addEventListener('change', addressFromFields);
    });
    function applyCnpj(data) {
        put('reg-client', data.razao_social || '');
        put('reg-fantasy', data.nome_fantasia || '');
        put('reg-name', data.nome_fantasia || data.razao_social || '');
        put('reg-contact', data.nome_fantasia || data.razao_social || '');
        put('reg-phone', data.ddd_telefone_1 || data.ddd_telefone_2 || '');
        put('reg-email', data.email || '');
        put('reg-cep', String(data.cep || '').replace(/^(\d{5})(\d{3})$/,'$1-$2'));
        put('reg-uf', data.uf || '');
        put('reg-street', data.logradouro || '');
        put('reg-number', data.numero || '');
        put('reg-complement', data.complemento || '');
        put('reg-district', data.bairro || '');
        put('reg-city', data.municipio || '');
        put('reg-status', data.descricao_situacao_cadastral || '');
        var cnae = data.cnae_fiscal_descricao || data.cnae_fiscal || '';
        put('reg-cnae', cnae);
        put('reg-legal', data.natureza_juridica || '');
        put('reg-opening', data.data_inicio_atividade || '');
        addressFromFields();
        status('Dados públicos do CNPJ preenchidos. Confira as informações antes de salvar.', 'success');
    }
    async function lookupCnpj(cnpj) {
        if (requestController) requestController.abort();
        requestController = new AbortController();
        status('Consultando dados públicos do CNPJ...', 'busy');
        try {
            var response = await fetch('pages/painel.php?ajax=cnpj_lookup&cnpj=' + encodeURIComponent(cnpj), {signal:requestController.signal,credentials:'same-origin',headers:{'Accept':'application/json'}});
            var payload=await response.json();
            if (!response.ok || !payload.ok) throw new Error(payload.error || 'Consulta indisponível');
            var data = payload.data;
            if (digits(docInput.value) !== cnpj) return;
            if (!data || !data.razao_social) throw new Error('CNPJ não encontrado');
            applyCnpj(data);
        } catch (error) {
            if (error.name === 'AbortError') return;
            if (digits(docInput.value) === cnpj) status(error.message || 'Consulta indisponível. Preencha os campos manualmente.', 'error');
            lastCnpj = '';
        }
    }
    var logoFile=document.getElementById('reg-logo'), logoPreview=document.getElementById('arc-logo-preview');
    if(logoFile&&logoPreview)logoFile.addEventListener('change',function(){
        var file=this.files&&this.files[0];if(!file)return;
        if(file.size>2097152||!/^image\/(png|jpeg|webp)$/.test(file.type)){
            this.value='';status('A logo deve ser PNG, JPG ou WEBP, com até 2 MB.','error');return;
        }
        var reader=new FileReader();reader.onload=function(){var image=document.createElement('img');image.src=String(reader.result);image.alt='Prévia da logo';logoPreview.replaceChildren(image);};reader.readAsDataURL(file);
    });
    if (docInput) docInput.addEventListener('input', function() {
        var caret=docInput.selectionStart||0;
        var before=digits(docInput.value.slice(0,caret)).length;
        var d=digits(docInput.value).slice(0,14);
        var formatted=maskDocument(d);
        if(docInput.value!==formatted){
            docInput.value=formatted;
            var pos=0,count=0;
            while(pos<formatted.length&&count<before){if(/\d/.test(formatted.charAt(pos)))count++;pos++;}
            docInput.setSelectionRange(pos,pos);
        }
        var type=d.length>11?'CNPJ':d.length===11?'CPF':'CPF/CNPJ';
        docBadge.textContent=type;
        docBadge.className='doc-badge '+(type==='CNPJ'?'cnpj':type==='CPF'?'cpf':'');
        if(lookupTimer)clearTimeout(lookupTimer);
        if(d.length!==14&&requestController){requestController.abort();requestController=null;}
        if(!d.length)status('Digite CPF ou CNPJ para identificar o documento.');
        else if(d.length===11)status('CPF identificado. Para CNPJ, continue digitando até 14 dígitos.');
        else if(d.length<14)status('Documento: '+d.length+' dígitos informados.');
        if(d.length===14&&d!==lastCnpj){
            lookupTimer=setTimeout(function(){if(digits(docInput.value)===d){lastCnpj=d;lookupCnpj(d);}},450);
        }
    });


})();
</script>
<script>
/* Adaptive network pages keep every card readable and reachable. */
(() => {
  'use strict';
  const grid = document.getElementById('arc-panel-grid');
  const pagination = document.querySelector('.arc-panel-pagination');
  if (!grid) return;
  const cards = Array.from(grid.querySelectorAll('[data-panel-network]'));
  const search = document.getElementById('arc-panel-search');
  const empty = document.getElementById('arc-panel-search-empty');
  const prev = document.getElementById('arc-panel-prev');
  const next = document.getElementById('arc-panel-next');
  const filters = Array.from(document.querySelectorAll('[data-panel-filter]'));
  let page = 0, capacity = 1, filter = 'all', frame = 0;
  const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
  const matches = () => {
    const term = normalize(search.value || '');
    return cards.filter(card => normalize(card.dataset.name || '').includes(term) &&
      (filter === 'all' || (filter === 'online' ? card.dataset.online === '1' : card.dataset.online !== '1')));
  };
  function render(reset = false) {
    if (reset) page = 0;
    document.getElementById('arc-panel-total').textContent = cards.length;
    const connected = cards.filter(card => card.dataset.online === '1').length;
    document.getElementById('arc-panel-online').textContent = connected;
    document.getElementById('arc-panel-offline').textContent = cards.length - connected;
    const matching = matches();
    const block = grid.parentElement;
    const blockStyle = getComputedStyle(block);
    const short = window.innerHeight <= 460;
    const gap = parseFloat(getComputedStyle(grid).columnGap) || 10;
    const width = Math.max(1, block.clientWidth - 4);
    const paginationHeight = pagination.hidden ? 0 : pagination.offsetHeight;
    const availableHeight = Math.max(1, block.clientHeight - block.querySelector('.arc-panel-section-heading').offsetHeight - paginationHeight - 2 * (parseFloat(blockStyle.rowGap) || 8) - 4);
    grid.dataset.compact = String(availableHeight < 150);
    const minCardWidth = window.innerWidth <= 860 ? 124 : 152;
    const minCardHeight = short ? 82 : 132;
    const columns = Math.max(1, Math.floor((width + gap) / (minCardWidth + gap)));
    const rows = Math.max(1, Math.floor((availableHeight + gap) / (minCardHeight + gap)));
    capacity = columns * rows;
    const pages = Math.max(1, Math.ceil(matching.length / capacity));
    pagination.hidden = pages <= 1;
    page = Math.max(0, Math.min(page, pages - 1));
    const shown = matching.slice(page * capacity, (page + 1) * capacity);
    const visible = new Set(shown);
    cards.forEach(card => { card.hidden = !visible.has(card); });
    grid.hidden = !matching.length;
    empty.hidden = matching.length > 0;
    if (matching.length) {
      grid.style.setProperty('--panel-cols', columns);
      grid.style.setProperty('--panel-rows', Math.ceil(shown.length / columns));
    }
    document.getElementById('arc-panel-result-count').textContent = matching.length;
    const range = matching.length ? `${page * capacity + 1}–${page * capacity + shown.length} de ${matching.length} redes` : '0 redes';
    const rangeElement = document.getElementById('arc-panel-range');
    if (rangeElement.textContent !== range) rangeElement.textContent = range;
    document.getElementById('arc-panel-page').textContent = `${page + 1} / ${pages}`;
    prev.disabled = page === 0;
    next.disabled = page >= pages - 1;
    filters.forEach(button => button.setAttribute('aria-pressed', String(button.dataset.panelFilter === filter)));
    if (!cards.length) {
      document.getElementById('arc-panel-empty-title').textContent = 'Sua primeira rede começa aqui';
      document.getElementById('arc-panel-empty-text').textContent = 'Registre um ambiente para começar a acompanhar suas conexões.';
      document.getElementById('arc-panel-clear').hidden = true;
      document.getElementById('arc-panel-empty-create').hidden = false;
    }
  }
  // Hidden empty states must not collapse the area used to calculate capacity.
  function schedule() {
    cancelAnimationFrame(frame);
    frame = requestAnimationFrame(() => render());
  }
  search.addEventListener('input', () => render(true));
  filters.forEach(button => button.addEventListener('click', () => { filter = button.dataset.panelFilter; render(true); }));
  document.getElementById('arc-panel-clear').addEventListener('click', () => { search.value = ''; filter = 'all'; render(true); search.focus(); });
  prev.addEventListener('click', () => { page--; render(); });
  next.addEventListener('click', () => { page++; render(); });
  // Arrow navigation is limited to the grid so text input editing remains native.
  grid.addEventListener('keydown', event => {
    if (event.key === 'ArrowRight' && !next.disabled) { event.preventDefault(); page++; render(); grid.querySelector('[data-panel-network]:not([hidden])')?.focus(); }
    if (event.key === 'ArrowLeft' && !prev.disabled) { event.preventDefault(); page--; render(); grid.querySelector('[data-panel-network]:not([hidden])')?.focus(); }
  });
  window.addEventListener('arc:network-status', schedule);
  window.addEventListener('resize', schedule);
  window.visualViewport?.addEventListener('resize', schedule);
  if ('ResizeObserver' in window) new ResizeObserver(schedule).observe(grid.parentElement);
  render();
})();

</script>
<?php require dirname(__DIR__).'/includes/footer.php'; ?>