<?php
declare(strict_types=1);
/** Caminho: redes/_base.php — operações das redes. */


if (!is_file(dirname(__DIR__) . '/includes/config.php')) {
    header('Location: install.php', true, 302);
    exit;
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/network-invite-template.php';

$pdo = arc_db();
arc_ensure_user_schema($pdo);
header('Cache-Control: no-store, private');

$arcError = '';
$go = static function (string $url = 'pages/painel.php'): never {
    header('Location: ' . (str_starts_with($url, '../') ? arc_workspace_base_url() . substr($url, 3) : (str_starts_with($url, 'pages/') ? arc_workspace_base_url() . $url : arc_workspace_base_url() . (str_starts_with($url, 'redes/') ? $url : 'pages/' . ltrim($url, '/')))), true, 303);
    exit;
};

if (!arc_is_user()) {
    $_SESSION['arc_flash'] = 'Entre na sua conta para acessar as redes.';
    $go('../index.php');
}

/* ============================================================
   1. CARREGAMENTO DA REDE (somente da conta logada)
   ============================================================ */

$networkId = (int)($_GET['network'] ?? 0);

$access = arc_network_team_access($pdo, $networkId, (int)arc_user_id());
if (!$access) {
    $_SESSION['arc_flash'] = 'Rede não encontrada ou acesso revogado.';
    $go('pages/painel.php');
}
$account = $access['account_id'];
$teamRole = $access['role'];
$isNetworkOwner = $teamRole === 'owner';
$load = $pdo->prepare('SELECT * FROM '.arc_table('managed_networks').' WHERE id=? AND account_id=? LIMIT 1');
$load->execute([$networkId, $account]);
$network = $load->fetch(PDO::FETCH_ASSOC);
if (!$network) $go('pages/painel.php');
$csrf    = arc_csrf_token();

/* BLOCO 01 — GATE NATIVO DE ATIVAÇÃO DA REDE.
   Bloqueio no servidor: nenhuma aba da gestão é renderizada sem agente aprovado.
   A página de liberação tem fluxo próprio e autorização vinculada à conta/rede. */
$arcGate = $pdo->prepare('SELECT COUNT(*) FROM '.arc_table('network_agents')." WHERE network_id=? AND account_id=? AND status='approved'");
$arcGate->execute([$networkId,(int)$account]);
if (!(int)$arcGate->fetchColumn() && !in_array(($arcSectionFile ?? ''), ['cadastros','usuarios','preferencias','permissoes'], true)) {
    header('Location: '.arc_workspace_base_url().'redes/liberacao.php?network='.$networkId, true, 303);
    exit;
}


/* Perfis customizados nunca podem ampliar o nível básico; restringem as páginas disponíveis. */
if (!$isNetworkOwner && isset($arcSectionFile)) {
    $allowedCustom=arc_network_profile_rights($pdo,$networkId,(int)arc_user_id());
    if ($allowedCustom!==null && !in_array($arcSectionFile,$allowedCustom,true) && !(in_array($arcSectionFile,['remoto','arquivos','chamados','agenda'],true)&&in_array('suporte',$allowedCustom,true))) {
        http_response_code(403);exit('Seu perfil não permite acessar esta página da rede.');
    }
}

/* ============================================================
   2. AÇÕES POST (edição / exclusão / arquivamento)
   ============================================================ */

$action = (string)($_POST['action'] ?? '');
$token  = (string)($_POST['_csrf'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($action,['ticket_create','ticket_update'],true)) {
    if (!$isNetworkOwner) { http_response_code(403); exit('Operação restrita ao proprietário da rede.'); }
    if ($token === '' || !hash_equals($csrf, $token)) {
        $arcError = 'Sessão expirada. Atualize a página e tente novamente.';

    } elseif (in_array($action, ['agent_approve','agent_reject','agent_block','agent_delete'], true)) {
        $agentId = (int)($_POST['agent_id'] ?? 0);
        $agentLookup = $pdo->prepare('SELECT id,machine_name,status,notes FROM ' . arc_table('network_agents') . ' WHERE id=? AND network_id=? AND account_id=? LIMIT 1');
        $agentLookup->execute([$agentId,$networkId,$account]);
        $managedAgent = $agentLookup->fetch();
        if (!$managedAgent) {
            $arcError = 'Agente não encontrado nesta rede.';
        } elseif ($action === 'agent_delete') {
            $pdo->prepare('DELETE FROM ' . arc_table('network_agents') . ' WHERE id=? AND network_id=? AND account_id=?')->execute([$agentId,$networkId,$account]);
            arc_user_audit($pdo,'network_agent_deleted','Agente removido: '.(string)$managedAgent['machine_name'].' (Rede ID '.$networkId.')');
            $_SESSION['arc_flash'] = 'Agente removido. Se o mesmo computador tentar se cadastrar novamente, precisará de nova aprovação.';
            $go('redes/servidor.php?network='.$networkId.'#agents');
        } elseif ($action==='agent_approve' && (str_starts_with((string)($managedAgent['notes']??''),'DESINSTALADO|') || (string)($managedAgent['notes']??'')==='Instalação revogada pelo gestor')) {
            $_SESSION['arc_flash']='O agente foi desinstalado. Instale novamente para solicitar uma nova liberação.';
            $go('redes/liberacao.php?network='.$networkId);
        } else {
            if ($action==='agent_block') {
                // Bloquear = exigir login na tela do agente. O agente segue ativo e o status continua "approved".
                $pdo->prepare('UPDATE '.arc_table('network_agents').' SET ui_locked=1 WHERE id=? AND network_id=? AND account_id=? AND status=\'approved\'')->execute([$agentId,$networkId,$account]);
                arc_user_audit($pdo,'network_agent_locked','Tela do agente protegida por login: '.(string)$managedAgent['machine_name'].' (Rede ID '.$networkId.')');
                $_SESSION['arc_flash']='Agente bloqueado: a tela só abre com usuário e senha.';
                $go('redes/servidor.php?network='.$networkId.'#agents');
            }
            $newStatus = match($action) {'agent_approve'=>'approved','agent_reject'=>'rejected'};
            $pdo->beginTransaction();
            try{
                $q=$pdo->prepare('SELECT id FROM '.arc_table('user_accounts').' WHERE id=? FOR UPDATE');$q->execute([$account]);
                $q=$pdo->prepare('SELECT status FROM '.arc_table('network_agents').' WHERE id=? AND account_id=? FOR UPDATE');$q->execute([$agentId,$account]);
                if($newStatus==='approved'&&$q->fetchColumn()!=='approved')arc_billing_enforce($pdo,$account,'agents');
            $pdo->prepare('UPDATE ' . arc_table('network_agents') . ' SET status=?,ui_locked=CASE WHEN ?=\'approved\' THEN 0 ELSE ui_locked END,approved_at=CASE WHEN ?=\'approved\' THEN CURRENT_TIMESTAMP ELSE approved_at END WHERE id=? AND network_id=? AND account_id=?')
                ->execute([$newStatus,$newStatus,$newStatus,$agentId,$networkId,$account]);
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$_SESSION['arc_flash']=$e instanceof RuntimeException?$e->getMessage():'Não foi possível alterar o agente.';$go('redes/servidor.php?network='.$networkId);}
            arc_user_audit($pdo,'network_agent_'.$newStatus,'Agente '.$newStatus.': '.(string)$managedAgent['machine_name'].' (Rede ID '.$networkId.')');
            $_SESSION['arc_flash'] = match($newStatus){'approved'=>'Agente aprovado e autorizado.','rejected'=>'Cadastro do agente rejeitado.'};
            $go('redes/servidor.php?network='.$networkId.'#agents');
        }

    } elseif ($action === 'agent_headless_toggle') {
        $agentId = (int)($_POST['agent_id'] ?? 0);
        $agentLookup = $pdo->prepare('SELECT id,machine_name,headless_mode FROM ' . arc_table('network_agents') . ' WHERE id=? AND network_id=? AND account_id=? LIMIT 1');
        $agentLookup->execute([$agentId,$networkId,$account]);
        $managedAgent = $agentLookup->fetch();
        if (!$managedAgent) {
            $arcError = 'Agente não encontrado nesta rede.';
        } else {
            $newHeadless = (int)$managedAgent['headless_mode'] === 1 ? 0 : 1;
            $pdo->prepare('UPDATE ' . arc_table('network_agents') . ' SET headless_mode=? WHERE id=? AND network_id=? AND account_id=?')
                ->execute([$newHeadless,$agentId,$networkId,$account]);
            arc_user_audit($pdo, $newHeadless ? 'network_agent_headless_enabled' : 'network_agent_headless_disabled',
                ($newHeadless ? 'Acesso remoto automático ativado' : 'Acesso remoto automático desativado').' para o agente '.(string)$managedAgent['machine_name'].' (Rede ID '.$networkId.')');
            $_SESSION['arc_flash'] = $newHeadless
                ? 'Agente marcado como servidor sem monitor/teclado/mouse. O acesso remoto não vai mais esperar confirmação na tela do Windows (que nunca apareceria), e fica sempre registrado na auditoria.'
                : 'Agente voltou a exigir a confirmação local de acesso remoto na tela do Windows.';
        }
        $go('redes/servidor.php?network='.$networkId.'#agents');

    } elseif ($action === 'network_monitoring_toggle') {
        $newMonitoring = (int)($network['monitoring_enabled'] ?? 0) === 1 ? 0 : 1;
        $pdo->prepare('UPDATE ' . arc_table('managed_networks') . ' SET monitoring_enabled=? WHERE id=? AND account_id=? AND is_active=1')
            ->execute([$newMonitoring, $networkId, $account]);
        arc_user_audit($pdo, $newMonitoring ? 'network_monitoring_enabled' : 'network_monitoring_disabled', ($newMonitoring ? 'Monitoramento ativado' : 'Monitoramento desativado') . ' na rede ID ' . $networkId);
        $_SESSION['arc_flash'] = $newMonitoring ? 'Monitoramento ativado. Os agentes desta rede poderão se comunicar.' : 'Monitoramento desativado. Os agentes desta rede serão bloqueados até a reativação.';
        $go('redes/servidor.php?network=' . $networkId . '#settings');

    } elseif ($action === 'network_update') {
        $values = [
            mb_substr(trim((string)($_POST['network_name'] ?? '')), 0, 100),
            mb_substr(trim((string)($_POST['client_name'] ?? '')), 0, 160),
            (string)$network['site_code'],
            mb_substr(trim((string)($_POST['contact_name'] ?? '')), 0, 160),
            mb_substr(trim((string)($_POST['contact_phone'] ?? '')), 0, 40),
            mb_substr(trim((string)($_POST['address'] ?? '')), 0, 255),
            mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 5000),
            isset($_POST['monitoring_enabled']) ? 1 : 0,
        ];

        if ($values[0] === '') {
            $arcError = 'Informe o nome da rede.';
        } else {
            $pdo->prepare(
                'UPDATE ' . arc_table('managed_networks') .
                ' SET name=?,client_name=?,site_code=?,contact_name=?,contact_phone=?,address=?,
                    notes=?,monitoring_enabled=?
                 WHERE id=? AND account_id=?'
            )->execute(array_merge($values, [$networkId, $account]));

            // Campos empresariais idênticos aos do cadastro inicial do Painel.
            $networkExtras = ['doc_number'=>18,'trade_name'=>160,'company_email'=>160,'zip_code'=>10,
                'state_code'=>2,'city_name'=>100,'street_name'=>160,'street_number'=>20,
                'address_extra'=>100,'district_name'=>100,'registration_status'=>100,
                'main_activity'=>160,'legal_nature'=>160,'opening_date'=>30];
            $extraSet=[]; $extraValues=[];
            foreach ($networkExtras as $field=>$limit) {
                $extraSet[]=$field.'=?';
                $extraValues[]=mb_substr(trim((string)($_POST[$field]??'')),0,$limit);
            }
            $pdo->prepare('UPDATE '.arc_table('managed_networks').' SET '.implode(',',$extraSet).' WHERE id=? AND account_id=?')
                ->execute(array_merge($extraValues,[$networkId,$account]));
            try {
                arc_save_network_logo($pdo, $networkId, $account);
                arc_user_audit($pdo, 'network_updated', 'Rede ID ' . $networkId . ' atualizada');
                $_SESSION['arc_flash'] = 'Dados da rede atualizados.';
                $go('redes/'.($arcSectionFile==='cadastros'?'cadastros.php':'servidor.php').'?' . 'network=' . $networkId . ($arcSectionFile==='cadastros'?'':'#cadastro'));
            } catch (Throwable $e) {
                $arcError = 'Dados da rede salvos, mas a logo não pôde ser atualizada: ' . $e->getMessage();
            }
        }

    } elseif ($action === 'network_preferences_update') {
        $prefs = [
            'monitor_refresh' => in_array((string)($_POST['monitor_refresh'] ?? '30'), ['instant','1','5','10','15','30','60'], true) ? (string)$_POST['monitor_refresh'] : '30',
            'alert_latency' => in_array((string)($_POST['alert_latency'] ?? '150'), ['80','100','150','200','300'], true) ? (string)$_POST['alert_latency'] : '150',
            'alert_low_speed' => isset($_POST['alert_low_speed']) ? '1' : '0',
            'email_alerts' => isset($_POST['email_alerts']) ? '1' : '0',
        ];
        $savePref = $pdo->prepare('INSERT INTO ' . arc_table('network_user_preferences') . ' (account_id,network_id,preference_key,preference_value) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE preference_value=VALUES(preference_value)');
        foreach ($prefs as $key => $value) $savePref->execute([$account,$networkId,$key,$value]);
        arc_user_audit($pdo, 'network_preferences_updated', 'Preferências da rede ID ' . $networkId . ' atualizadas');
        $_SESSION['arc_flash'] = 'Preferências desta rede salvas.';
        $go('redes/'.($arcSectionFile==='preferencias'?'preferencias.php':'servidor.php').'?' . 'network=' . $networkId . ($arcSectionFile==='preferencias'?'':'#preferences'));

    } elseif ($action === 'network_delete') {
        // Nunca aceitar apenas confirmação visual: valide a senha no servidor.
        $password=(string)($_POST['manager_password']??'');
        $auth=$pdo->prepare('SELECT password_hash FROM '.arc_table('user_accounts').' WHERE id=? LIMIT 1');
        $auth->execute([(int)arc_user_id()]);
        $stored=(string)$auth->fetchColumn();
        if (!$isNetworkOwner || $password==='' || $stored==='' || !(password_verify(base64_encode(hash('sha384',$password,true)),$stored) || password_verify($password,$stored))) {
            $arcError='Senha do gestor incorreta. A rede não foi excluída.';
        } elseif (arc_delete_network($pdo,$networkId,(int)$account)) {
            if (arc_current_network_id() === $networkId) unset($_SESSION['arc_current_network_id']);
            arc_user_audit($pdo,'network_deleted','Rede ID '.$networkId);
            $_SESSION['arc_flash']='Rede excluída definitivamente do servidor.';
            $go('pages/painel.php');
        } else {
            $arcError='Não foi possível excluir a rede. Nenhuma confirmação de exclusão foi emitida.';
        }

    } elseif ($action === 'network_archive') {
        $upd = $pdo->prepare(
            'UPDATE ' . arc_table('managed_networks') . ' SET is_active=0 WHERE id=? AND account_id=? AND is_active=1'
        );
        $upd->execute([$networkId, $account]);

        if ($upd->rowCount()) {
            if (arc_current_network_id() === $networkId) {
                unset($_SESSION['arc_current_network_id']);
            }
            arc_user_audit($pdo, 'network_archived', 'Rede ID ' . $networkId);
            $_SESSION['arc_flash'] = 'Rede arquivada.';
            $go('pages/painel.php');
        }
        $arcError = 'Não foi possível arquivar essa rede.';
    }
}

/* Equipe vinculada a esta rede. Vínculos não concedem acesso remoto sem validação na API. */
if ($_SERVER['REQUEST_METHOD']==='POST' && in_array($action,['team_save','team_remove','team_toggle'],true) && !$arcError) {
    $userId=(int)($_POST['member_user_id']??0);
    if ($action==='team_save') {
        $email=mb_strtolower(trim((string)($_POST['member_email']??'')));
        $role=(string)($_POST['member_role']??'viewer');
        $selectedProfile=(int)($_POST['member_profile_id']??0);
        if($selectedProfile>0){
          $pick=$pdo->prepare('SELECT base_role FROM '.arc_table('network_access_profiles').' WHERE id=? AND account_id=? AND network_id=?');
          $pick->execute([$selectedProfile,$account,$networkId]);
          $realRole=$pick->fetchColumn();
          if($realRole!==false)$role=(string)$realRole;else $selectedProfile=0;
        }

        if (!filter_var($email,FILTER_VALIDATE_EMAIL) || !in_array($role,['viewer','operator','administrator'],true)) {
            $arcError='E-mail ou perfil inválido.';
        } else {
            $q=$pdo->prepare('SELECT id FROM '.arc_table('user_accounts')." WHERE email_hash=? AND account_status='active' LIMIT 1");
            $q->execute([hash('sha256',$email)]);
            $userId=(int)$q->fetchColumn();
            if (!$userId) {
                // Convite pendente; não concede acesso até a identidade do e-mail ser verificada.
                $q=$pdo->prepare('INSERT INTO '.arc_table('network_team_invitations').' (account_id,network_id,email_hash,email,role,profile_id,created_at) VALUES (?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE role=VALUES(role),profile_id=VALUES(profile_id),email=VALUES(email),created_at=NOW()');
                $q->execute([$account,$networkId,hash('sha256',$email),$email,$role,$selectedProfile>0?$selectedProfile:null]);
                $mailStatus='Convite cadastrado. Configure o SMTP para enviar o aviso.';
                try{
                    if(arc_platform_setting($pdo,'platform_smtp_enabled','0')==='1'){
                        arc_platform_smtp_send($pdo,$email,'Convite para participar da rede '.(string)$network['name'], arc_network_invite_email($pdo,$networkId,(string)$network['name'],false),true);
                        $mailStatus='Convite enviado por e-mail.';
                    }
                }catch(Throwable $mailError){error_log('Convite rede: '.$mailError->getMessage());}
                $_SESSION['arc_flash']=$mailStatus.' O vínculo será efetivado após login com e-mail verificado.';
                $go('redes/'.($arcSectionFile==='usuarios'?'usuarios.php':'servidor.php').'?network='.$networkId.($arcSectionFile==='usuarios'?'':'#team'));
            }
            if ($userId===$account) $arcError='O proprietário já possui acesso a esta rede.';
            else {
                try{arc_team_save($pdo,$account,$networkId,$userId,$role); if($selectedProfile>0){$pdo->prepare('UPDATE '.arc_table('network_team_members').' SET profile_id=? WHERE account_id=? AND network_id=? AND member_user_id=?')->execute([$selectedProfile,$account,$networkId,$userId]);} else {$pdo->prepare('UPDATE '.arc_table('network_team_members').' SET profile_id=NULL WHERE account_id=? AND network_id=? AND member_user_id=?')->execute([$account,$networkId,$userId]);}}catch(RuntimeException $e){$_SESSION['arc_flash']=$e->getMessage();$go('redes/servidor.php?network='.$networkId);}
                arc_user_audit($pdo,'network_team_saved','Membro ID '.$userId.' configurado na rede ID '.$networkId.' como '.$role);
                $mailStatus='O aviso por e-mail não foi enviado.';
                try{
                    if(arc_platform_setting($pdo,'platform_smtp_enabled','0')==='1'){
                        $teamLink=rtrim(arc_base_url(),'/').'/pages/painel.php';
                        arc_platform_smtp_send($pdo,$email,'Acesso à rede '.(string)$network['name'],arc_network_invite_email($pdo,$networkId,(string)$network['name'],true),true);
                        $mailStatus='Aviso enviado por e-mail.';
                    }
                }catch(Throwable $mailError){error_log('E-mail equipe de rede: '.$mailError->getMessage());}
                $_SESSION['arc_flash']='Membro registrado na equipe. '.$mailStatus.' O acesso à visualização da rede está disponível no painel; ações remotas permanecem protegidas.';
                $go('redes/'.($arcSectionFile==='usuarios'?'usuarios.php':'servidor.php').'?network='.$networkId.($arcSectionFile==='usuarios'?'':'#team'));
            }
        }
    } elseif ($action==='team_toggle') {
        if (!$isNetworkOwner || $userId===$account || $userId<=0) {
            $arcError='Somente o gestor pode alterar o acesso de integrantes.';
        } else {
            $q=$pdo->prepare('UPDATE '.arc_table('network_team_members').' SET enabled=IF(enabled=1,0,1) WHERE account_id=? AND network_id=? AND member_user_id=?');
            $q->execute([$account,$networkId,$userId]);
            if ($q->rowCount()!==1) $arcError='Integrante não encontrado.';
            else {
                arc_user_audit($pdo,'network_team_access_toggled','Rede ID '.$networkId.' - Membro ID '.$userId.' teve acesso alterado');
                $_SESSION['arc_flash']='Acesso do integrante atualizado.';
                $go('redes/usuarios.php?network='.$networkId);
            }
        }
    } elseif ($userId>0) {
        $q=$pdo->prepare('DELETE FROM '.arc_table('network_team_members').' WHERE account_id=? AND network_id=? AND member_user_id=?');
        $q->execute([$account,$networkId,$userId]);
        arc_user_audit($pdo,'network_team_removed','Membro ID '.$userId.' removido da rede ID '.$networkId);
        $_SESSION['arc_flash']='Membro removido da equipe.';
        $go('redes/'.($arcSectionFile==='usuarios'?'usuarios.php':'servidor.php').'?network='.$networkId.($arcSectionFile==='usuarios'?'':'#team'));
    }
}
$profileSchemaReady=false;
try {$profileSchemaCheck=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND BINARY TABLE_NAME=BINARY ? AND BINARY COLUMN_NAME=BINARY ?');$profileSchemaCheck->execute([arc_prefix().'network_team_members','profile_id']);$profileSchemaReady=(bool)$profileSchemaCheck->fetchColumn();}catch(PDOException $e){}
$inviteProfileReady=false;
try {$profileSchemaCheck->execute([arc_prefix().'network_team_invitations','profile_id']);$inviteProfileReady=(bool)$profileSchemaCheck->fetchColumn();}catch(PDOException $e){}
$teamInvites=$pdo->prepare('SELECT email,role,'.($inviteProfileReady?'profile_id':'NULL AS profile_id').',created_at FROM '.arc_table('network_team_invitations').' WHERE account_id=? AND network_id=? ORDER BY created_at DESC');
$teamInvites->execute([$account,$networkId]);
$pendingTeamInvites=$teamInvites->fetchAll(PDO::FETCH_ASSOC);
$teamQuery=$pdo->prepare('SELECT m.member_user_id,m.role,m.enabled,'.($profileSchemaReady?'m.profile_id':'NULL AS profile_id').',u.display_name,u.email,u.avatar_url FROM '.arc_table('network_team_members').' m JOIN '.arc_table('user_accounts').' u ON u.id=m.member_user_id WHERE m.account_id=? AND m.network_id=? ORDER BY u.display_name');
$teamQuery->execute([$account,$networkId]);
$networkTeamMembers=$teamQuery->fetchAll(PDO::FETCH_ASSOC);
$ownerQuery=$pdo->prepare('SELECT id,display_name,email,avatar_url FROM '.arc_table('user_accounts').' WHERE id=? LIMIT 1');
$ownerQuery->execute([$account]);
$networkOwner=$ownerQuery->fetch(PDO::FETCH_ASSOC) ?: ['id'=>$account,'display_name'=>'Proprietário da rede','email'=>''];

/* Recarrega a rede após eventuais alterações. */
$load->execute([$networkId, $account]);
$network = $load->fetch();

/* ============================================================
   3. DADOS DETALHADOS POR ASSUNTO
   ============================================================ */

/* --- Equipamentos da rede --- */
$dev = $pdo->prepare(
    'SELECT d.* FROM ' . arc_table('devices') . ' d ' .
    'JOIN ' . arc_table('network_sessions') . ' ns ON ns.session_id=d.session_id ' .
    'WHERE ns.network_id=? ORDER BY d.last_seen_ms DESC LIMIT 1000'
);
$dev->execute([$networkId]);
$devices = $dev->fetchAll();

/* --- Instalações (dispositivos com o app instalado) --- */
$inst = $pdo->prepare(
    'SELECT * FROM ' . arc_table('pwa_installations') .
    ' WHERE network_id=? AND account_id=? ORDER BY last_seen_at DESC'
);
$inst->execute([$networkId, $account]);
$installations = $inst->fetchAll();
$agentQuery = $pdo->prepare('SELECT * FROM ' . arc_table('network_agents') . ' WHERE network_id=? AND account_id=? ORDER BY CASE status WHEN \'pending\' THEN 0 WHEN \'approved\' THEN 1 WHEN \'blocked\' THEN 2 ELSE 3 END, last_seen_at DESC');
$agentQuery->execute([$networkId,$account]);
$networkAgents = $agentQuery->fetchAll();
$selectedAgentId = (int)($_GET['agent'] ?? 0);
$approvedAgentIds = array_map('intval', array_column(array_filter($networkAgents, static fn($a) => ($a['status'] ?? '') === 'approved'), 'id'));
if (!in_array($selectedAgentId, $approvedAgentIds, true)) $selectedAgentId = $approvedAgentIds[0] ?? 0;
$remoteEvents = [];
try {
    $remoteStmt = $pdo->prepare('SELECT t.event_type,t.payload_json,t.captured_at,a.machine_name FROM ' . arc_table('agent_telemetry') . ' t JOIN ' . arc_table('network_agents') . ' a ON a.id=t.agent_id AND a.network_id=t.network_id WHERE t.network_id=? AND t.account_id=? AND t.agent_id=? AND t.event_type<>\'server_metrics\' ORDER BY t.id DESC LIMIT 150');
    $remoteStmt->execute([$networkId,$account,$selectedAgentId]);
    $remoteEvents = $remoteStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('Arcoll remote dashboard: '.$e->getMessage()); }
$remoteByType=[];
foreach ($remoteEvents as $event) { $remoteByType[(string)$event['event_type']][]=$event; }
$pendingAgentCount = count(array_filter($networkAgents, static fn($a) => (string)$a['status'] === 'pending'));
$offlineDeviceCount = count(array_filter($devices, static fn($d) => empty($d['last_seen_ms']) || (int)floor((int)$d['last_seen_ms'] / 1000) < time() - 90));
$recentInstallationCount = count(array_filter($installations, static function ($i) { $t = strtotime((string)($i['installed_at'] ?? '')); return $t !== false && $t >= time() - 86400; }));
$networkPrefs = ['monitor_refresh'=>'30','alert_latency'=>'150','alert_low_speed'=>'0','email_alerts'=>'1'];
$np = $pdo->prepare('SELECT preference_key,preference_value FROM ' . arc_table('network_user_preferences') . ' WHERE account_id=? AND network_id=?');
$np->execute([$account,$networkId]);
foreach ($np->fetchAll() as $pr) if (array_key_exists((string)$pr['preference_key'],$networkPrefs)) $networkPrefs[(string)$pr['preference_key']] = (string)$pr['preference_value'];


/* --- Atividades relacionadas à rede --- */
$act = $pdo->prepare(
    'SELECT event_type,details,created_at FROM ' . arc_table('account_audit') .
    ' WHERE account_id=? AND (details REGEXP ? OR details LIKE ?) ORDER BY created_at DESC LIMIT 200'
);
$act->execute([
    $account,
    'Rede ID ' . $networkId . '([^0-9]|$)',
    '%(' . (string)$network['site_code'] . ')%',
]);
$activities = $act->fetchAll();

/* --- Medições recentes (se disponíveis) --- */
$samples = [];
try {
    $overview = arc_network_overview($pdo, $networkId);
    $samples  = $overview['samples'] ?? [];
} catch (Throwable $e) {
    $samples = [];
}

$isActive = (int)$network['is_active'] === 1;
$lastSeen = (string)($network['last_seen_at'] ?? '');
$live     = $isActive && $lastSeen !== '' && strtotime($lastSeen) >= time() - 90;

/* ============================================================
   4. SAÍDA — WORKSPACE PADRÃO
   ============================================================ */

$ARCPageBodyClass = 'arc-page-redes';
$ARCMode      = 'app';
$ARCPageTitle = (string)$network['name'];
$ARCActiveNav = 'networks';

$arcNetId   = (int)$network['id'];
$arcNetName = (string)$network['name'];

$arcCsrfAttr = htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8');
$arcNameAttr = htmlspecialchars($arcNetName, ENT_QUOTES, 'UTF-8');

$ARCHeaderActions = '';

/* Confirmação de ações destrutivas (formulários com data-confirm). As abas usam o JS padrão do footer. */
$ARCPageJs = <<<'ARCJS'
<script>
document.addEventListener('DOMContentLoaded',function(){
 const modal=document.getElementById('arc-network-confirm');
 if(!modal)return;
 const text=modal.querySelector('[data-confirm-text]');
 const cancel=modal.querySelector('[data-confirm-cancel]');
 const proceed=modal.querySelector('[data-confirm-proceed]');
 let pending=null;
 document.querySelectorAll('form[data-confirm]').forEach(function(form){
   form.addEventListener('submit',function(e){
     if(form.dataset.confirmed==='1')return;
     e.preventDefault();pending=form;text.textContent=form.getAttribute('data-confirm')||'Confirmar operação?';
     if(typeof modal.showModal==='function')modal.showModal();else modal.setAttribute('open','');
   });
 });
 function close(){if(typeof modal.close==='function')modal.close();else modal.removeAttribute('open');pending=null;}
 cancel.addEventListener('click',close);
 proceed.addEventListener('click',function(){if(!pending)return;const form=pending;close();form.dataset.confirmed='1';if(form.requestSubmit)form.requestSubmit();else form.submit();});
 modal.addEventListener('click',function(e){if(e.target===modal)close();});
 modal.addEventListener('close',function(){pending=null;});
});
</script>
ARCJS;
