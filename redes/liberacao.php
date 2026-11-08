<?php
// Caminho: redes/liberacao.php
/** Tela de instalação/liberação de gestores da rede com grade pesquisável e ordenação por coluna. */
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$pdo = arc_db();
arc_ensure_user_schema($pdo);
header('Cache-Control: private, no-store, max-age=0');

if (!arc_is_user() && !arc_is_admin()) {
    header('Location: ' . arc_workspace_base_url() . 'index.php', true, 303);
    exit;
}

$networkId = (int)($_GET['network'] ?? $_POST['network'] ?? 0);
$access = arc_network_team_access($pdo, $networkId, (int)arc_user_id());
if (!$access) {
    http_response_code(403);
    exit('Rede não autorizada.');
}

if (!in_array($access['role'], ['owner', 'system_admin'], true)) { $rights=arc_network_profile_rights($pdo,$networkId,(int)arc_user_id());if($rights!==null&&!in_array('liberacao',$rights,true)){http_response_code(403);exit('Seu perfil não permite abrir Liberações.');} }
$account = (int)$access['account_id'];
$isOwner = in_array($access['role'], ['owner', 'system_admin'], true);
$isNetworkOwner = $isOwner;
$q = $pdo->prepare('SELECT id,name,site_code,logo_file,monitoring_enabled,is_active FROM ' . arc_table('managed_networks') . ' WHERE id=? AND account_id=? LIMIT 1');
$q->execute([$networkId, $account]);
$network = $q->fetch(PDO::FETCH_ASSOC);
if (!$network || !(int)$network['is_active']) {
    http_response_code(404);
    exit('Rede indisponível.');
}

$csrf = arc_csrf_token();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isOwner) {
        http_response_code(403);
        exit('Somente o proprietário da rede pode liberar gestores.');
    }
    arc_verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if (in_array($action,['rebind_approve','rebind_reject'],true)) {
        $id=(int)($_POST['request_id']??0);
        try {
            $pdo->beginTransaction();
            $q=$pdo->prepare('SELECT * FROM '.arc_table('gestor_rebind_requests')." WHERE id=? AND account_id=? AND network_id=? AND status='pending' FOR UPDATE");
            $q->execute([$id,$account,$networkId]);$candidate=$q->fetch(PDO::FETCH_ASSOC);
            if (!$candidate) throw new RuntimeException('Solicitação de vinculação não encontrada.');
            if ($action==='rebind_approve') {
                $q=$pdo->prepare('SELECT id,account_id,network_id,status FROM '.arc_table('network_gestores').' WHERE fingerprint=? FOR UPDATE');
                $q->execute([(string)$candidate['fingerprint']]);$existing=$q->fetch(PDO::FETCH_ASSOC);
                if ($existing && (int)$existing['account_id']!==$account) {
                    throw new RuntimeException('O computador já pertence a outra conta.');
                }
                // Computador vindo de outra rede da mesma conta: o cadastro antigo é preservado como
                // histórico (bloqueado/transferido) e um novo cadastro aprovado é criado nesta rede.
                if ($existing && (int)$existing['network_id']!==$networkId) {
                    $historic=hash('sha256','historico:'.(string)$candidate['fingerprint'].':'.$existing['id']);
                    $q=$pdo->prepare('UPDATE '.arc_table('network_gestores')." SET fingerprint=?,status='blocked',ui_locked=0,uninstall_requested=0,notes=? WHERE id=?");
                    $q->execute([$historic,mb_substr('TRANSFERIDO|'.date('Y-m-d H:i:s').'|Novo local: '.(string)$candidate['site_code'],0,500),(int)$existing['id']]);
                    // As chaves da rede anterior deixam de valer para este computador.
                    $q=$pdo->prepare('UPDATE '.arc_table('gestor_download_keys').' SET fingerprint=? WHERE fingerprint=? AND network_id=? AND account_id=?');
                    $q->execute([$historic,(string)$candidate['fingerprint'],(int)$existing['network_id'],$account]);
                    $existing=false;
                }
                if (!$existing) {
                    arc_billing_enforce($pdo,$account,'gestores');
                    $q=$pdo->prepare('INSERT INTO '.arc_table('network_gestores')." (fingerprint,account_id,network_id,site_code,machine_name,platform,status,approved_at) VALUES (?,?,?,?,?,'Windows','approved',NOW())");
                    $q->execute([(string)$candidate['fingerprint'],$account,$networkId,(string)$candidate['site_code'],(string)$candidate['machine_name']]);
                } else {
                    if ((string)$existing['status']!=='approved')arc_billing_enforce($pdo,$account,'gestores');
                    $q=$pdo->prepare('UPDATE '.arc_table('network_gestores')." SET status='approved',uninstall_requested=0,ui_locked=0,notes='',approved_at=NOW(),machine_name=?,site_code=? WHERE id=?");
                    $q->execute([(string)$candidate['machine_name'],(string)$candidate['site_code'],(int)$existing['id']]);
                }
                // Rotação da chave revogável apenas após a aprovação expressa do gestor.
                $q=$pdo->prepare('DELETE FROM '.arc_table('gestor_download_keys').' WHERE fingerprint=? AND network_id=? AND account_id=? AND token_hash<>?');
                $q->execute([(string)$candidate['fingerprint'],$networkId,$account,(string)$candidate['token_hash']]);
                // A chave pode já existir (pacote baixado pelo painel e usado no pedido automático do instalador).
                $q=$pdo->prepare('SELECT id,fingerprint,network_id,account_id FROM '.arc_table('gestor_download_keys').' WHERE token_hash=? LIMIT 1 FOR UPDATE');
                $q->execute([(string)$candidate['token_hash']]);$keyRow=$q->fetch(PDO::FETCH_ASSOC);
                if ($keyRow) {
                    $keyFp=strtolower(trim((string)($keyRow['fingerprint']??'')));
                    if ((int)$keyRow['network_id']!==$networkId || (int)$keyRow['account_id']!==$account ||
                        ($keyFp!=='' && !hash_equals($keyFp,(string)$candidate['fingerprint']))) {
                        throw new RuntimeException('Esta solicitação já foi utilizada.');
                    }
                    $q=$pdo->prepare('UPDATE '.arc_table('gestor_download_keys').' SET fingerprint=?,used_at=COALESCE(used_at,NOW()) WHERE id=?');
                    $q->execute([(string)$candidate['fingerprint'],(int)$keyRow['id']]);
                } else {
                    $q=$pdo->prepare('INSERT INTO '.arc_table('gestor_download_keys').' (token_hash,account_id,network_id,site_code,fingerprint,used_at) VALUES (?,?,?,?,?,NOW())');
                    $q->execute([(string)$candidate['token_hash'],$account,$networkId,(string)$candidate['site_code'],(string)$candidate['fingerprint']]);
                }
            }
            $q=$pdo->prepare('UPDATE '.arc_table('gestor_rebind_requests').' SET status=?,reviewed_at=NOW() WHERE id=?');
            $q->execute([$action==='rebind_approve'?'approved':'rejected',$id]);
            $pdo->commit();
            arc_user_audit($pdo,$action==='rebind_approve'?'gestor_rebind_approved':'gestor_rebind_rejected','Recuperação de credencial: '.(string)$candidate['machine_name'].' ('.(string)$candidate['site_code'].')');
            header('Location: '.arc_workspace_base_url().'redes/liberacao.php?network='.$networkId,true,303);exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction())$pdo->rollBack();
            $error=$e instanceof RuntimeException?$e->getMessage():'Não foi possível concluir a liberação.';
        }
    }
    if ($action === 'monitoring_enable') {
        $q = $pdo->prepare('UPDATE ' . arc_table('managed_networks') . ' SET monitoring_enabled=1 WHERE id=? AND account_id=? AND is_active=1');
        $q->execute([$networkId, $account]);
        arc_user_audit($pdo, 'network_monitoring_enabled', 'Monitoramento ativado na liberação da rede ID ' . $networkId);
        header('Location: ' . arc_workspace_base_url() . 'redes/liberacao.php?network=' . $networkId, true, 303);
        exit;
    }

    if (in_array($action, ['gestor_reject','gestor_lock_toggle','gestor_headless_toggle','gestor_uninstall_toggle'], true)) {
        $id = (int)($_POST['gestor_id'] ?? 0);
        $q = $pdo->prepare('SELECT id,machine_name,status,headless_mode,ui_locked,uninstall_requested,notes FROM ' . arc_table('network_gestores') . ' WHERE id=? AND network_id=? AND account_id=? LIMIT 1');
        $q->execute([$id,$networkId,$account]);
        $item=$q->fetch(PDO::FETCH_ASSOC);
        if (!$item) { $error='Gestor não encontrado nesta rede.'; }
        else {
            $oldStatus=(string)$item['status'];
            $alreadyRemoved=str_starts_with((string)($item['notes']??''),'DESINSTALADO|');
            $uninstallPending=(int)$item['uninstall_requested']===1;
            $valid=($action==='gestor_reject' && $oldStatus==='pending')
                || ($action==='gestor_lock_toggle' && $oldStatus==='approved' && !$uninstallPending)
                || ($action==='gestor_headless_toggle' && $oldStatus==='approved')
                || ($action==='gestor_uninstall_toggle' && !$alreadyRemoved && ($uninstallPending || $oldStatus==='approved'));
            if (!$valid) {
                $error='Esta ação não está disponível para o estado atual do gestor.';
            } elseif ($action === 'gestor_headless_toggle') {
                $next=(int)$item['headless_mode']===1?0:1;
                $q=$pdo->prepare('UPDATE '.arc_table('network_gestores').' SET headless_mode=? WHERE id=? AND network_id=? AND account_id=?');
                $q->execute([$next,$id,$networkId,$account]);
                arc_user_audit($pdo,$next?'network_gestor_headless_enabled':'network_gestor_headless_disabled','Modo sem monitor modificado para o gestor '.$id);
            } elseif ($action === 'gestor_lock_toggle') {
                // Bloquear não derruba o gestor: ele segue ativo e monitorando; só a tela local passa a exigir login.
                $next=(int)$item['ui_locked']===1?0:1;
                $q=$pdo->prepare('UPDATE '.arc_table('network_gestores').' SET ui_locked=? WHERE id=? AND network_id=? AND account_id=? AND status=\'approved\'');
                $q->execute([$next,$id,$networkId,$account]);
                arc_user_audit($pdo,$next?'network_gestor_locked':'network_gestor_unlocked','Tela do gestor '.($next?'protegida por login':'liberada sem login').': gestor '.$id);
            } elseif ($action === 'gestor_uninstall_toggle') {
                $next=$uninstallPending?0:1;
                $q=$pdo->prepare('UPDATE '.arc_table('network_gestores').' SET uninstall_requested=?,ui_locked=0 WHERE id=? AND network_id=? AND account_id=?');
                $q->execute([$next,$id,$networkId,$account]);
                arc_user_audit($pdo,$next?'network_gestor_uninstall_requested':'network_gestor_uninstall_cancelled','Desinstalação '.($next?'solicitada':'cancelada').' para o gestor '.$id);
            } else {
                $q=$pdo->prepare('UPDATE '.arc_table('network_gestores').' SET status=\'rejected\' WHERE id=? AND network_id=? AND account_id=?');
                $q->execute([$id,$networkId,$account]);
                arc_user_audit($pdo,'network_gestor_rejected','Estado alterado para rejected no gestor '.$id);
            }
            header('Location: '.arc_workspace_base_url().'redes/liberacao.php?network='.$networkId,true,303);exit;
        }
    }
    if ($action === 'gestor_approve') {
        $id = (int)($_POST['gestor_id'] ?? 0);
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT id FROM ' . arc_table('user_accounts') . ' WHERE id=? FOR UPDATE');
            $lock->execute([$account]);
            $get = $pdo->prepare('SELECT id,machine_name,status,notes FROM ' . arc_table('network_gestores') . ' WHERE id=? AND account_id=? AND network_id=? FOR UPDATE');
            $get->execute([$id, $account, $networkId]);
            $candidate = $get->fetch(PDO::FETCH_ASSOC);
            if (!$candidate || !in_array((string)$candidate['status'], ['pending', 'blocked'], true) || str_starts_with((string)($candidate['notes'] ?? ''),'DESINSTALADO|')) {
                throw new RuntimeException('O gestor não está disponível para liberação.');
            }
            arc_billing_enforce($pdo, $account, 'gestores');
            $approve = $pdo->prepare("UPDATE " . arc_table('network_gestores') . " SET status='approved',ui_locked=0,approved_at=NOW() WHERE id=? AND account_id=? AND network_id=? AND status IN ('pending','blocked')");
            $approve->execute([$id, $account, $networkId]);
            if ($approve->rowCount() !== 1) {
                throw new RuntimeException('Não foi possível confirmar a liberação.');
            }
            $pdo->commit();
            arc_user_audit($pdo, 'network_gestor_approved', 'Gestor liberado: ' . (string)$candidate['machine_name'] . ' (Rede ID ' . $networkId . ')');
            header('Location: ' . arc_workspace_base_url() . 'redes/liberacao.php?network=' . $networkId, true, 303);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível liberar o gestor.';
        }
    }
}

$getGestores = $pdo->prepare('SELECT id,fingerprint,machine_name,platform,local_ip,gestor_version,status,first_seen_at,last_seen_at,approved_at,machine_details,headless_mode,ui_locked,uninstall_requested,notes FROM ' . arc_table('network_gestores') . ' WHERE network_id=? AND account_id=? ORDER BY first_seen_at DESC,id DESC');
$getGestores->execute([$networkId, $account]);
$gestores = $getGestores->fetchAll(PDO::FETCH_ASSOC);
$rebindStmt=$pdo->prepare('SELECT id,machine_name,fingerprint,requested_at,status FROM '.arc_table('gestor_rebind_requests')." WHERE network_id=? AND account_id=? AND status='pending' ORDER BY requested_at DESC");
$rebindStmt->execute([$networkId,$account]);
$rebindRequests=$rebindStmt->fetchAll(PDO::FETCH_ASSOC);
// Solicitações de recuperação entram na própria grade. O cadastro existente é
// aproveitado quando há fingerprint correspondente, evitando linhas duplicadas.
$rebindByFingerprint=[];
$existingFingerprints=[];
foreach ($gestores as $gestor) {
    $existingFingerprints[(string)$gestor['fingerprint']]=true;
}
foreach ($rebindRequests as $request) {
    $fingerprint=(string)$request['fingerprint'];
    $rebindByFingerprint[$fingerprint]=$request;
    if (!isset($existingFingerprints[$fingerprint])) {
        $gestores[]=[
            'id'=>0,'fingerprint'=>$fingerprint,'machine_name'=>(string)$request['machine_name'],
            'platform'=>'Windows','local_ip'=>'','gestor_version'=>'',
            'status'=>'pending','first_seen_at'=>(string)$request['requested_at'],
            'last_seen_at'=>null,'approved_at'=>null,'machine_details'=>null,
            'headless_mode'=>0,'ui_locked'=>0,'uninstall_requested'=>0,'notes'=>''
        ];
    }
}


$gestoresSignature = hash('sha256', json_encode(array_map(static fn(array $a): array => [(int)$a['id'], (string)$a['status'], str_starts_with((string)($a['notes'] ?? ''), 'DESINSTALADO|'), (int)$a['ui_locked'], (int)$a['uninstall_requested']], $gestores)));
$gestoresSignature = hash('sha256', $gestoresSignature . json_encode(array_map(static fn(array $r): array => [(int)$r['id'],(string)$r['status']],$rebindRequests)));
$approved = array_values(array_filter($gestores, static fn(array $a): bool => (string)$a['status'] === 'approved' && !isset($rebindByFingerprint[(string)$a['fingerprint']])));
$pending = array_values(array_filter($gestores, static fn(array $a): bool => in_array((string)$a['status'], ['pending', 'blocked', 'rejected'], true)));
$stage = $approved ? 'approved' : (($pending || $rebindRequests) ? 'pending' : 'install');

if (($_GET['status'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode([
        'ok' => true,
        'stage' => $stage,
        'signature' => $gestoresSignature,
        'pending' => array_map(static fn(array $a): array => [
            'id' => (int)$a['id'],
            'name' => (string)$a['machine_name'],
            'status' => (string)$a['status'],
        ], $pending),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

$statusMap = [
    'pending' => ['label' => 'Aguardando liberação', 'class' => 'is-pending'],
    'rebind_pending' => ['label' => 'Aguardando nova vinculação', 'class' => 'is-pending'],
    'blocked' => ['label' => 'Acesso bloqueado', 'class' => 'is-blocked'],
    'rejected' => ['label' => 'Aguardando nova liberação', 'class' => 'is-rejected'],
    'approved' => ['label' => 'Liberado', 'class' => 'is-approved'],
    'uninstalled' => ['label' => 'GESTOR DESINSTALADO', 'class' => 'is-uninstalled'],
    'locked' => ['label' => 'Bloqueado (exige login)', 'class' => 'is-locked'],
    'uninstalling' => ['label' => 'Desinstalação pendente', 'class' => 'is-uninstalling'],
];

$rows = [];
foreach ($gestores as $gestor) {
    $details = [];
    if (!empty($gestor['machine_details'])) {
        try {
            $decoded = json_decode((string)$gestor['machine_details'], true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $details = $decoded;
            }
        } catch (Throwable $e) {
            $details = [];
        }
    }
    $machine = trim((string)($gestor['machine_name'] ?? '')) !== '' ? (string)$gestor['machine_name'] : 'Computador detectado';
    $platform = trim((string)($details['os_caption'] ?? $details['os_name'] ?? $gestor['platform'] ?? ''));
    if ($platform === '') {
        $platform = 'Windows';
    }
    $version = trim((string)($gestor['gestor_version'] ?? '')) !== '' ? (string)$gestor['gestor_version'] : '—';
    $localIp = trim((string)($gestor['local_ip'] ?? ''));
    $isRemoved = str_starts_with((string)($gestor['notes'] ?? ''), 'DESINSTALADO|');
    $isUninstalling = !$isRemoved && (int)($gestor['uninstall_requested'] ?? 0) === 1;
    $isLocked = !$isRemoved && !$isUninstalling && (string)$gestor['status'] === 'approved' && (int)($gestor['ui_locked'] ?? 0) === 1;
    $effective = $isRemoved ? 'uninstalled' : ($isUninstalling ? 'uninstalling' : ($isLocked ? 'locked' : (string)$gestor['status']));
    $rebindRequest=$rebindByFingerprint[(string)$gestor['fingerprint']] ?? null;
    if ($rebindRequest!==null) $effective='rebind_pending';
    $rows[] = [
        'id' => (int)$gestor['id'],
        'rebind_id' => $rebindRequest!==null ? (int)$rebindRequest['id'] : 0,
        'machine' => $machine,
        'status' => $effective,
        'details' => $details,
        'notes' => (string)($gestor['notes'] ?? ''),
        'status_label' => $statusMap[$effective]['label'] ?? 'Pendente',
        'status_class' => $statusMap[$effective]['class'] ?? 'is-pending',
        'platform' => $platform,
        'version' => $version,
        'installed' => arc_when($gestor['first_seen_at'] ?? null),
        'installed_ts' => $gestor['first_seen_at'] ? (int)(strtotime((string)$gestor['first_seen_at']) ?: 0) : 0,
        'last_seen' => arc_when($gestor['last_seen_at'] ?? null),
        'last_seen_ts' => $gestor['last_seen_at'] ? (int)(strtotime((string)$gestor['last_seen_at']) ?: 0) : 0,
        'local_ip' => $localIp !== '' ? $localIp : '—',
        'uninstalled' => $isRemoved,
        'uninstalling' => $isUninstalling,
        'locked' => $isLocked,
        'raw_status' => (string)$gestor['status'],
        'headless' => (int)($gestor['headless_mode']??0) === 1,
    ];
}

$networkLogoUrl = !empty($network['logo_file']) ? arc_workspace_base_url() . 'api/network-logo.php?network=' . $networkId : '';
$download = arc_workspace_base_url() . 'api/download-gestor.php?network=' . $networkId;
$poll = arc_workspace_base_url() . 'redes/liberacao.php?network=' . $networkId . '&status=1';
$pendingCount = count($rows);
$basePath = arc_workspace_base_url();
$searchHint = 'Buscar computador, sistema, IP ou versão';

// Integração com o header e as abas nativas da gestão de rede.
$arcSectionFile = 'liberacao';
$ARCPageBodyClass = 'arc-page-redes';
$teamRole = (string)$access['role'];
$networkGestores = $gestores;
$selectedGestorId = 0;
foreach ($gestores as $candidate) {
    if ((string)$candidate['status'] === 'approved') {
        $selectedGestorId = (int)$candidate['id'];
        break;
    }
}
require __DIR__ . '/_topo_config.php';
?>
<section class="arc-release-page" aria-label="Liberação da rede">
    <section class="arc-release-shell">
        <div class="arc-release-toolbar">
            <div class="arc-release-network">
                <span class="arc-release-logo" aria-hidden="true">
                    <?php if ($networkLogoUrl !== ''): ?>
                        <img src="<?= arc_h($networkLogoUrl) ?>" alt="<?= arc_h((string)$network['name']) ?>" onerror="this.hidden=true;this.nextElementSibling.hidden=false">
                        <i class="fa fa-sitemap" hidden></i>
                    <?php else: ?>
                        <i class="fa fa-sitemap"></i>
                    <?php endif; ?>
                </span>
                <div class="arc-release-network-copy">
                    <small>Liberação do Gestor Windows</small>
                    <strong><?= arc_h((string)$network['name']) ?></strong>
                </div>
            </div>

            <div class="arc-release-toolbar-actions">
                <label class="arc-release-search" aria-label="Buscar gestor">
                    <i class="fa fa-search" aria-hidden="true"></i>
                    <input id="releaseSearch" type="search" placeholder="<?= arc_h($searchHint) ?>">
                </label>
                <label class="arc-release-filter" aria-label="Filtrar por situação">
                    <i class="fa fa-filter" aria-hidden="true"></i>
                    <select id="releaseFilter">
                        <option value="all">Todos</option>
                        <option value="pending">Aguardando liberação</option>
                        <option value="rebind_pending">Nova vinculação solicitada</option>
                        <option value="locked">Bloqueados (exige login)</option>
                        <option value="blocked">Acesso bloqueado (antigo)</option>
                        <option value="uninstalling">Desinstalação pendente</option>
                        <option value="uninstalled">Desinstalados</option>
                        <option value="rejected">Aguardando nova liberação</option>
                    </select>
                </label>

                <?php if ((int)$network['monitoring_enabled'] === 1): ?>
                    <a class="arc-release-download" href="<?= arc_h($download) ?>" title="Baixar o instalador (.exe dentro de um ZIP). Extraia e execute o instalador; ele pede permissão de administrador."><i class="fa fa-download"></i><span>Baixar gestor (ZIP)</span></a>
                    <button class="arc-release-download arc-release-cmd" type="button" id="releaseCmdOpen" title="Instala sem o aviso &quot;O Windows protegeu o computador&quot; (SmartScreen): copie um comando e cole no PowerShell do computador."><i class="fa fa-terminal"></i><span>Instalar sem aviso</span></button>
                <?php elseif ($isOwner): ?>
                    <form method="post" class="arc-release-inline-form">
                        <input type="hidden" name="network" value="<?= $networkId ?>">
                        <input type="hidden" name="_csrf" value="<?= arc_h($csrf) ?>">
                        <input type="hidden" name="action" value="monitoring_enable">
                        <button class="arc-release-download" type="submit"><i class="fa fa-power-off"></i><span>Ativar monitoramento</span></button>
                    </form>
                <?php else: ?>
                    <button class="arc-release-download is-disabled" type="button" disabled><i class="fa fa-ban"></i><span>Monitoramento desativado</span></button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="arc-release-feedback is-error" role="alert"><?= arc_h($error) ?></div><?php endif; ?>

        <div class="arc-release-grid-wrap">
            <div class="arc-release-grid-scroll">
                <table class="arc-release-grid" id="releaseTable">
                    <thead>
                    <tr>
                        <th><button type="button" class="arc-sort" data-sort="machine">Computador <i class="fa fa-sort"></i></button></th>
                                                <th><button type="button" class="arc-sort" data-sort="platform">Sist. op. <i class="fa fa-sort"></i></button></th>
                        <th><button type="button" class="arc-sort" data-sort="version">Versão <i class="fa fa-sort"></i></button></th>
                        <th><button type="button" class="arc-sort" data-sort="installed_ts">Instalado <i class="fa fa-sort"></i></button></th>
                        <th><button type="button" class="arc-sort" data-sort="last_seen_ts">Último acesso <i class="fa fa-sort"></i></button></th>
                        <th><button type="button" class="arc-sort" data-sort="status">Status <i class="fa fa-sort"></i></button></th>
                        <th class="is-actions">Ações</th>
                    </tr>
                    </thead>
                    <tbody id="releaseRows">
                    <?php if ($rows): ?>
                        <?php foreach ($rows as $row): ?>
                            <tr
                                data-row
                                data-machine="<?= arc_h(mb_strtolower($row['machine'], 'UTF-8')) ?>"
                                data-status="<?= arc_h($row['status']) ?>"
                                data-details="<?= arc_h(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)) ?>"
                                data-platform="<?= arc_h(mb_strtolower($row['platform'], 'UTF-8')) ?>"
                                data-version="<?= arc_h(mb_strtolower($row['version'], 'UTF-8')) ?>"
                                data-installed_ts="<?= (int)$row['installed_ts'] ?>"
                                data-last_seen_ts="<?= (int)$row['last_seen_ts'] ?>"
                                data-search="<?= arc_h(mb_strtolower(trim($row['machine'] . ' ' . $row['platform'] . ' ' . $row['version'] . ' ' . $row['local_ip']), 'UTF-8')) ?>"
                            >
                                <td>
                                    <div class="arc-gestor-cell-main">
                                        <strong><?= arc_h($row['machine']) ?></strong>
                                        <small><?= arc_h($row['local_ip']) ?></small>
                                    </div>
                                </td>
                                <td><?= arc_h($row['platform']) ?></td>
                                <td><?= arc_h($row['version']) ?></td>
                                <td><?= arc_h($row['installed']) ?></td>
                                <td><?= arc_h($row['last_seen']) ?></td>
                                <td><span class="arc-gestor-status <?= arc_h($row['status_class']) ?>"><?= arc_h($row['status_label']) ?></span></td>
                                <td class="is-actions">
                                    <?php if ($isOwner): ?>
                                    <?php
                                    $btnForm = static function (string $op, int $gestorId, int $netId, string $csrfToken, string $icon, string $tip, string $label, string $confirmText = '', string $cls = ''): string {
                                        return '<form method="post" class="arc-release-action-form"' . ($confirmText !== '' ? ' data-confirm="' . arc_h($confirmText) . '"' : '') . '>'
                                            . '<input type="hidden" name="network" value="' . $netId . '"><input type="hidden" name="_csrf" value="' . arc_h($csrfToken) . '">'
                                            . '<input type="hidden" name="action" value="' . arc_h($op) . '"><input type="hidden" name="gestor_id" value="' . $gestorId . '">'
                                            . '<button type="submit" class="arc-release-icon-btn ' . arc_h($cls) . '" title="' . arc_h($tip) . '" aria-label="' . arc_h($tip . ' — ' . $label) . '"><i class="fa ' . arc_h($icon) . '"></i></button></form>';
                                    };
                                    $rid = (int)$row['id'];
                                    $isApprovedRow = $row['raw_status'] === 'approved' && !$row['uninstalled'] && $row['rebind_id'] === 0;
                                    ?>
                                    <div class="arc-release-row-actions">
                                        <?php if ($row['rebind_id'] > 0): ?>
                                            <form method="post" class="arc-release-action-form" data-confirm="Autorizar uma nova credencial para este computador? A credencial anterior será revogada.">
                                                <input type="hidden" name="network" value="<?= $networkId ?>"><input type="hidden" name="_csrf" value="<?= arc_h($csrf) ?>">
                                                <input type="hidden" name="request_id" value="<?= (int)$row['rebind_id'] ?>"><input type="hidden" name="action" value="rebind_approve">
                                                <button type="submit" class="arc-release-icon-btn" title="Liberar nova vinculação" aria-label="Liberar nova vinculação — <?= arc_h($row['machine']) ?>"><i class="fa fa-check-circle"></i></button>
                                            </form>
                                            <form method="post" class="arc-release-action-form" data-confirm="Rejeitar o pedido de nova vinculação deste computador?">
                                                <input type="hidden" name="network" value="<?= $networkId ?>"><input type="hidden" name="_csrf" value="<?= arc_h($csrf) ?>">
                                                <input type="hidden" name="request_id" value="<?= (int)$row['rebind_id'] ?>"><input type="hidden" name="action" value="rebind_reject">
                                                <button type="submit" class="arc-release-icon-btn is-danger" title="Rejeitar nova vinculação" aria-label="Rejeitar nova vinculação — <?= arc_h($row['machine']) ?>"><i class="fa fa-times-circle"></i></button>
                                            </form>
                                        <?php elseif ($row['raw_status'] === 'pending'): ?>
                                            <?= $btnForm('gestor_approve', $rid, $networkId, $csrf, 'fa-check-circle', 'Liberar gestor', $row['machine']) ?>
                                            <?= $btnForm('gestor_reject', $rid, $networkId, $csrf, 'fa-times-circle', 'Rejeitar autorização', $row['machine'], 'Rejeitar este computador?', 'is-danger') ?>
                                        <?php endif; ?>

                                        <?php if ($isApprovedRow && !$row['uninstalling']): ?>
                                            <?php if ($row['locked']): ?>
                                                <?= $btnForm('gestor_lock_toggle', $rid, $networkId, $csrf, 'fa-lock', 'Bloqueado — clique para desbloquear (liberar a tela sem login)', $row['machine'], 'Liberar a tela do gestor neste computador sem pedir usuário e senha?', 'is-locked') ?>
                                            <?php else: ?>
                                                <?= $btnForm('gestor_lock_toggle', $rid, $networkId, $csrf, 'fa-unlock-alt', 'Desbloqueado — clique para bloquear (exigir login para abrir a tela)', $row['machine'], 'O gestor continuará em execução, mas para abrir a tela do gestor será exigido usuário e senha. Bloquear agora?') ?>
                                            <?php endif; ?>
                                            <?= $btnForm('gestor_headless_toggle', $rid, $networkId, $csrf, 'fa-television', ($row['headless'] ? 'Desativar' : 'Ativar') . ' modo sem monitor', $row['machine'], 'Alterar o modo sem monitor deste computador? Essa opção modifica as exigências de confirmação do acesso remoto.', $row['headless'] ? 'is-selected' : '') ?>
                                        <?php endif; ?>

                                        <?php if ($row['uninstalling']): ?>
                                            <?= $btnForm('gestor_uninstall_toggle', $rid, $networkId, $csrf, 'fa-hourglass-half', 'Desinstalação pendente — clique para cancelar', $row['machine'], 'Cancelar a desinstalação pendente deste gestor?', 'is-uninstalling') ?>
                                        <?php elseif ($isApprovedRow): ?>
                                            <?= $btnForm('gestor_uninstall_toggle', $rid, $networkId, $csrf, 'fa-trash', 'Desinstalar gestor deste computador', $row['machine'], 'Desinstalar o gestor deste computador? O gestor será removido da máquina (inclusive os dados locais) assim que ela estiver online. Esta ação não pode ser desfeita pela plataforma.', 'is-danger') ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php else: ?><span class="arc-gestor-awaiting">Aguardando proprietário</span><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr class="arc-release-empty-row" id="releaseEmptyRow">
                            <td colspan="7">
                                <div class="arc-release-empty">
                                    <i class="fa fa-desktop"></i>
                                    <strong>Nenhum gestor cadastrado nesta rede.</strong>
                                    <span>Se instalou o gestor, verifique na tela de instalação se o cadastro foi aceito pela plataforma. Caso haja erro, baixe um novo instalador desta rede e confira o código do local.</span>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</section>
<style>
.arc-release-page{height:100%;min-height:0;padding:0;background:linear-gradient(135deg,#071423 0%,#0d2138 46%,#08182b 100%);box-sizing:border-box}
.arc-release-shell{display:flex;flex-direction:column;gap:9px;height:100%;min-height:0}
.arc-release-toolbar,.arc-release-summary,.arc-release-grid-wrap{border:1px solid rgba(90,149,188,.32);background:rgba(20,38,60,.94);border-radius:16px;box-shadow:0 10px 35px rgba(0,0,0,.18)}
.arc-release-toolbar{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:16px 18px;flex-wrap:wrap}
.arc-release-network{display:flex;align-items:center;gap:13px;min-width:0}
.arc-release-logo{width:52px;height:52px;border-radius:14px;border:1px solid rgba(103,201,240,.25);background:#12324a;display:grid;place-items:center;color:#49c6f3;flex:0 0 52px;overflow:hidden}
.arc-release-logo img{width:100%;height:100%;object-fit:contain;background:#0f2940}
.arc-release-logo i{font-size:22px}
.arc-release-network-copy{display:grid;gap:4px;min-width:0}.arc-release-network-copy small{font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#63caf4;font-weight:700}.arc-release-network-copy strong{font-size:25px;line-height:1.1;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:min(52vw,630px)}
.arc-release-toolbar-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-left:auto}
.arc-release-search,.arc-release-filter{display:flex;align-items:center;gap:8px;height:42px;border:1px solid #2c4e6d;border-radius:12px;background:#11253c;color:#9ec4dd;padding:0 12px;min-width:0}
.arc-release-search{min-width:min(100%,320px);flex:1 1 290px}.arc-release-search input,.arc-release-filter select{border:0;background:transparent;color:#eef7ff;outline:none;font:600 13px/1.2 Segoe UI,Arial,sans-serif;width:100%}.arc-release-search input::placeholder{color:#7da1bc}.arc-release-filter{min-width:210px;flex:0 1 220px}.arc-release-filter select{appearance:none;cursor:pointer}
.arc-release-download{display:inline-flex;align-items:center;justify-content:center;gap:9px;height:42px;padding:0 16px;border-radius:12px;border:1px solid #2fc3f7;background:#1095c9;color:#fff;text-decoration:none;font:700 13px/1 Segoe UI,Arial,sans-serif;white-space:nowrap;cursor:pointer}.arc-release-download:hover{background:#0cb0ea}.arc-release-download.is-disabled{opacity:.55;cursor:not-allowed;background:#23445e;border-color:#355f7c}.arc-release-inline-form{display:inline-flex}
.arc-release-cmd{background:#23445e;border-color:#355f7c}.arc-release-cmd:hover{background:#2c5778}
.arc-cmd-modal{position:fixed;inset:0;background:rgba(4,11,20,.72);display:none;align-items:center;justify-content:center;padding:18px;z-index:1300}.arc-cmd-modal.show{display:flex}
.arc-cmd-box{width:min(720px,100%);background:#172234;border:1px solid #38506c;border-radius:14px;color:#eef5ff;padding:18px 20px;box-shadow:0 20px 60px rgba(0,0,0,.5);font-family:Segoe UI,Arial,sans-serif}
.arc-cmd-box h3{margin:0 0 6px;font-size:17px}.arc-cmd-box p{margin:0 0 10px;color:#b7cbe2;font-size:13px;line-height:1.5}.arc-cmd-box ol{margin:0 0 12px;padding-left:20px;color:#b7cbe2;font-size:13px;line-height:1.6}
.arc-cmd-code{width:100%;min-height:92px;resize:vertical;background:#0b1626;border:1px solid #455b78;border-radius:9px;color:#d7ecff;font:12px/1.5 Consolas,monospace;padding:10px;box-sizing:border-box}
.arc-cmd-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:12px;flex-wrap:wrap}.arc-cmd-actions button{height:38px;padding:0 16px;border-radius:9px;border:0;font:700 13px Segoe UI,Arial,sans-serif;cursor:pointer;background:#1095c9;color:#fff}.arc-cmd-actions button.alt{background:#354c6a}.arc-cmd-actions button:disabled{opacity:.5;cursor:not-allowed}
.arc-cmd-msg{min-height:18px;font-size:12px;color:#ffd0d2;margin-top:6px}.arc-cmd-msg.ok{color:#baffdf}
.arc-release-summary{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:14px 18px;flex-wrap:wrap}
.arc-release-summary-item{display:flex;align-items:center;gap:12px;min-width:0}.arc-release-indicator{width:12px;height:12px;border-radius:50%;background:#efc24f;box-shadow:0 0 0 5px rgba(239,194,79,.16);animation:releasePulse 1.4s ease-in-out infinite}.arc-release-summary-item strong{display:block;color:#fff;font-size:15px;line-height:1.3}.arc-release-summary-item small{display:block;color:#9cc0d6;font-size:12px;line-height:1.45}.arc-release-counters{display:flex;align-items:center;gap:16px;flex-wrap:wrap;color:#b2d0e3;font-size:12px}.arc-release-counters b{color:#fff}
@keyframes releasePulse{50%{transform:scale(.84);opacity:.52}}
.arc-release-feedback{padding:12px 14px;border-radius:12px;border:1px solid #733c3c;background:#402327;color:#ffd7d7;font-size:12px}.arc-release-feedback.is-error{box-shadow:0 6px 18px rgba(64,35,39,.28)}
.arc-release-grid-wrap{display:flex;flex-direction:column;gap:0;overflow:hidden;min-height:0;flex:1}.arc-release-grid-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 18px;border-bottom:1px solid rgba(90,149,188,.22)}.arc-release-grid-toolbar strong{font-size:15px;color:#fff}.arc-release-grid-toolbar small{font-size:12px;color:#91b6cc}
.arc-release-grid-scroll{overflow:auto;min-height:0;flex:1}
.arc-release-grid{width:100%;border-collapse:separate;border-spacing:0;min-width:980px}.arc-release-grid th,.arc-release-grid td{padding:14px 12px;border-bottom:1px solid rgba(90,149,188,.16);text-align:left;font-size:12px;color:#d6ebf8;background:transparent;vertical-align:middle}.arc-release-grid th{position:sticky;top:0;background:#162d47;z-index:2;padding-top:12px;padding-bottom:12px}.arc-release-grid tbody tr:hover td{background:rgba(255,255,255,.015)}
.arc-sort{border:0;background:none;padding:0;display:inline-flex;align-items:center;gap:7px;color:#b7d6ea;font:700 12px/1.2 Segoe UI,Arial,sans-serif;cursor:pointer}.arc-sort i{opacity:.72}.arc-sort[data-dir="asc"] i:before{content:"\f0de"}.arc-sort[data-dir="desc"] i:before{content:"\f0dd"}
.arc-gestor-cell-main{display:grid;gap:4px}.arc-gestor-cell-main strong{font-size:13px;color:#fff}.arc-gestor-cell-main small{font-size:11px;color:#8eb8ce}
.arc-gestor-status{display:inline-flex;align-items:center;justify-content:center;padding:6px 10px;border-radius:999px;font-size:11px;font-weight:700;border:1px solid transparent;white-space:nowrap}.arc-gestor-status.is-pending{background:#3d3421;color:#ffd978;border-color:#735f25}.arc-gestor-status.is-blocked{background:#442732;color:#ffb2b8;border-color:#7f404d}.arc-gestor-status.is-rejected{background:#3a2744;color:#e2b8ff;border-color:#6a4481}.arc-gestor-status.is-uninstalled{background:#253342;color:#9dc9d7;border-color:#4e7484}.arc-gestor-status.is-approved{background:#203a2b;color:#98f5b5;border-color:#38774f}.arc-gestor-status.is-locked{background:#442732;color:#ffb2b8;border-color:#7f404d}.arc-gestor-status.is-uninstalling{background:#3d3421;color:#ffd978;border-color:#735f25}
.arc-gestor-action-form{display:flex;justify-content:flex-end}.arc-gestor-action{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:34px;padding:0 12px;border-radius:9px;border:1px solid #30bdea;background:#129fd3;color:#fff;font:700 12px/1 Segoe UI,Arial,sans-serif;cursor:pointer;white-space:nowrap}.arc-gestor-action:hover{background:#0fb4eb}.arc-gestor-awaiting{font-size:11px;color:#89adc4;white-space:nowrap}
.arc-release-grid .is-actions{text-align:right}.arc-release-empty-row td{padding:34px 20px!important;border-bottom:0!important}.arc-release-empty{display:grid;justify-items:center;gap:8px;color:#9fc3d8;text-align:center}.arc-release-empty i{font-size:26px;color:#55c8f4}.arc-release-empty strong{font-size:14px;color:#fff}.arc-release-empty span{max-width:580px;font-size:12px;line-height:1.6}
@media (max-width:1200px){.arc-release-network-copy strong{max-width:min(44vw,420px);font-size:22px}}
@media (max-width:900px){.arc-release-page{padding:0}.arc-release-toolbar{padding:14px}.arc-release-toolbar-actions{width:100%;margin-left:0}.arc-release-search{min-width:0;flex:1 1 100%}.arc-release-filter{flex:1 1 180px;min-width:160px}.arc-release-download{flex:1 1 220px}.arc-release-network-copy strong{max-width:100%;white-space:normal;font-size:20px}.arc-release-summary{padding:14px}.arc-release-grid-toolbar{padding:12px 14px}}
@media (max-width:640px){.arc-release-page{padding:0}.arc-release-toolbar,.arc-release-summary,.arc-release-grid-wrap{border-radius:14px}.arc-release-network{width:100%}.arc-release-logo{width:46px;height:46px;flex-basis:46px;border-radius:12px}.arc-release-network-copy small{font-size:10px}.arc-release-network-copy strong{font-size:17px}.arc-release-toolbar-actions{gap:8px}.arc-release-search,.arc-release-filter,.arc-release-download{height:40px;width:100%}.arc-release-filter{min-width:0}.arc-release-summary-item{align-items:flex-start}.arc-release-counters{width:100%;justify-content:space-between;gap:10px}.arc-release-grid-toolbar{align-items:flex-start;flex-direction:column}.arc-release-grid-scroll{margin:0 -1px}}

/* Interface compatível com páginas independentes de Usuários, Preferências e Cadastro. */
.arc-release-page{height:100%;min-height:0;display:flex;flex-direction:column;overflow:hidden;padding:9px;box-sizing:border-box;background:transparent}
.arc-release-shell{flex:1;min-height:0;display:flex;flex-direction:column;gap:9px;overflow:hidden}
.arc-release-toolbar,.arc-release-grid-wrap{background:var(--surface-alt,#172b42);border:1px solid var(--border,#2e506a);border-radius:9px;box-shadow:none}
.arc-release-toolbar{flex:0 0 auto;min-height:43px;display:flex;flex-wrap:nowrap;align-items:center;gap:10px;padding:7px 11px}
.arc-release-network{min-width:0;max-width:30%;flex:0 1 30%;display:flex;align-items:center;gap:8px;overflow:hidden}
.arc-release-logo{width:29px;height:29px;flex:0 0 29px;border-radius:7px}.arc-release-logo i{font-size:15px}
.arc-release-network-copy{min-width:0;max-width:100%;display:block;overflow:hidden}.arc-release-network-copy small{display:none}.arc-release-network-copy strong{display:block;max-width:100%;font-size:12px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.arc-release-toolbar-actions{margin-left:auto;flex:1;min-width:0;width:auto;display:flex;align-items:center;gap:7px;flex-wrap:nowrap}
.arc-release-search{flex:1 1 auto;min-width:80px;width:auto;max-width:none}.arc-release-filter{flex:0 0 138px;min-width:0;width:138px}
.arc-release-search,.arc-release-filter,.arc-release-download{height:34px;min-height:34px;border-radius:7px;padding:0 9px;font-size:11px;background:var(--surface,#102238);border-color:var(--border,#2d526c)}
.arc-release-search input,.arc-release-filter select{font-size:11px;min-width:0}.arc-release-filter select,.arc-release-filter select option{color:#f0f8ff;background-color:#142b43;color-scheme:dark}
.arc-release-download{background:#0c92c3;border-color:#168fc4;white-space:nowrap;flex:0 0 auto;color:#fff!important}
.arc-release-grid-wrap{flex:1;min-height:0;overflow:hidden;display:flex;flex-direction:column}
.arc-release-grid-scroll{flex:1;min-height:0;overflow:auto}
.arc-release-grid{width:100%;min-width:840px}.arc-release-grid th,.arc-release-grid td{font-size:11px;padding:10px 9px;border-bottom:1px solid var(--border,#31516c)}
.arc-release-grid th{background:var(--surface-alt,#172b42)}.arc-release-grid .is-actions{white-space:nowrap;width:215px;text-align:right}
.arc-release-row-actions{display:flex;align-items:center;justify-content:flex-end;gap:4px}.arc-release-action-form{display:inline-flex;margin:0}
.arc-release-icon-btn{width:29px;height:29px;border:1px solid var(--border,#31516c);border-radius:6px;display:inline-grid;place-items:center;background:var(--surface,#102238);color:var(--text,#e9f3ff);cursor:pointer;font-size:12px}
.arc-release-icon-btn:hover:not(:disabled){border-color:#45bdec;background:#15405c}.arc-release-icon-btn.is-danger{color:#ffb1b1}.arc-release-icon-btn.is-selected{color:#6ce1ae}.arc-release-icon-btn.is-locked{color:#fff;background:#a6334a;border-color:#d4617a}.arc-release-icon-btn.is-uninstalling{color:#ffd978;border-color:#735f25}.arc-release-icon-btn:disabled{opacity:.35;cursor:not-allowed}
.arc-release-grid tbody tr{cursor:pointer}.arc-release-grid tbody tr:focus-visible{outline:2px solid #40c7ff;outline-offset:-2px}
@media(max-width:750px){.arc-release-page{padding:7px 7px 8px}.arc-release-toolbar{padding:6px;gap:5px;min-height:39px}.arc-release-network{display:none}.arc-release-toolbar-actions{margin:0;gap:5px;width:100%}.arc-release-search{min-width:0;flex:1}.arc-release-filter{flex:0 0 39px;width:39px;padding:0 9px;overflow:hidden}.arc-release-filter select{width:100%;font-size:0;appearance:none}.arc-release-filter select option{font-size:12px}.arc-release-download{width:39px;min-width:39px;padding:0;justify-content:center}.arc-release-download span{display:none}.arc-release-grid{min-width:0;table-layout:fixed}.arc-release-grid th,.arc-release-grid td{padding:9px 6px}.arc-release-grid th:nth-child(2),.arc-release-grid td:nth-child(2),.arc-release-grid th:nth-child(3),.arc-release-grid td:nth-child(3),.arc-release-grid th:nth-child(4),.arc-release-grid td:nth-child(4),.arc-release-grid th:nth-child(5),.arc-release-grid td:nth-child(5){display:none}.arc-release-grid th:first-child{width:auto}.arc-release-grid th:nth-child(6){width:100px}.arc-release-grid th:last-child{width:120px}.arc-gestor-cell-main{min-width:0}.arc-gestor-cell-main strong{display:block;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}.arc-gestor-status{font-size:0;padding:6px}.arc-gestor-status::before{content:'●';font-size:12px}.arc-release-grid .is-actions{width:120px}.arc-release-icon-btn{width:25px;height:26px;font-size:11px}.arc-release-row-actions{gap:2px}}

.arc-gestor-modal{width:min(760px,94vw);max-height:88dvh;overflow:auto;background:#14283e;color:#ecf6ff;border:1px solid #376382;border-radius:14px;padding:0;box-shadow:0 25px 75px #000a}
.arc-gestor-modal::backdrop{background:#020b17cf}.arc-modal-head{display:flex;align-items:center;gap:12px;padding:16px 18px;background:#102238;border-bottom:1px solid #33536c}.arc-modal-head h3{margin:0;font-size:17px}.arc-modal-close{margin-left:auto;border:1px solid #42647c;background:#1a3751;color:#fff;border-radius:8px;padding:8px 12px;cursor:pointer}
.arc-modal-content{padding:18px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.arc-modal-info{min-width:0;padding:12px;border:1px solid #2d4b63;border-radius:9px;background:#102339}.arc-modal-info small{display:block;color:#8bb3ca;margin-bottom:5px}.arc-modal-info strong{word-break:break-word;font-size:13px}.arc-modal-wide{grid-column:1/-1}@media(max-width:600px){.arc-modal-content{grid-template-columns:1fr}.arc-modal-wide{grid-column:auto}}
</style>
<dialog id="arcGestorDetailModal" class="arc-gestor-modal" aria-labelledby="arcGestorModalTitle"><div class="arc-modal-head"><i class="fa fa-desktop"></i><h3 id="arcGestorModalTitle">Detalhes do computador</h3><button type="button" class="arc-modal-close" id="arcGestorModalClose" aria-label="Fechar detalhes"><i class="fa fa-times"></i></button></div><div class="arc-modal-content" id="arcGestorModalBody"></div></dialog>
<div class="arc-cmd-modal" id="releaseCmdModal" aria-hidden="true">
    <div class="arc-cmd-box" role="dialog" aria-modal="true" aria-labelledby="releaseCmdTitle">
        <h3 id="releaseCmdTitle">Instalar sem o aviso do Windows</h3>
        <p>O aviso "O Windows protegeu o computador" aparece apenas em arquivos <strong>baixados pelo navegador</strong>. Este comando baixa o instalador direto pelo PowerShell, então o arquivo não recebe essa marca e a tela não aparece. Não precisa de certificado.</p>
        <ol>
            <li>No computador que receberá o gestor, abra o <strong>PowerShell</strong> (menu Iniciar &rarr; digite PowerShell).</li>
            <li>Cole o comando abaixo e pressione Enter.</li>
            <li>Confirme o aviso de administrador (UAC) quando o Windows pedir.</li>
        </ol>
        <textarea class="arc-cmd-code" id="releaseCmdText" readonly spellcheck="false" placeholder="Gerando comando..."></textarea>
        <div class="arc-cmd-msg" id="releaseCmdMsg" role="status"></div>
        <p style="margin-top:8px">O comando vale por 24 horas e serve para <strong>um</strong> computador (a chave é vinculada na primeira instalação). Gere outro para cada computador e não o compartilhe.</p>
        <div class="arc-cmd-actions"><button type="button" class="alt" id="releaseCmdNew">Gerar outro</button><button type="button" id="releaseCmdCopy" disabled>Copiar comando</button><button type="button" class="alt" id="releaseCmdClose">Fechar</button></div>
    </div>
</div>
<script>
(function(){
    'use strict';
    const modal=document.getElementById('releaseCmdModal'),text=document.getElementById('releaseCmdText'),msg=document.getElementById('releaseCmdMsg'),copy=document.getElementById('releaseCmdCopy'),open=document.getElementById('releaseCmdOpen');
    if(!modal||!open)return;
    const url=<?= json_encode(arc_workspace_base_url() . 'api/download-gestor.php?mode=command') ?>,network=<?= (int)$networkId ?>,csrf=<?= json_encode($csrf) ?>;
    const say=(t,ok)=>{msg.textContent=t||'';msg.className='arc-cmd-msg'+(ok?' ok':'')};
    async function generate(){
        text.value='';copy.disabled=true;say('Gerando comando...',true);
        try{
            const body=new URLSearchParams({network:String(network),_csrf:csrf});
            const response=await fetch(url,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'X-CSRF-Token':csrf},body});
            const data=await response.json().catch(()=>({}));
            if(!response.ok||!data.ok)throw new Error(data.error||'Não foi possível gerar o comando.');
            text.value=data.command;copy.disabled=false;say('Comando pronto. Válido por '+(data.expires_hours||24)+' horas.',true);
        }catch(e){say(e.message,false)}
    }
    const show=v=>{modal.classList.toggle('show',v);modal.setAttribute('aria-hidden',v?'false':'true')};
    open.addEventListener('click',()=>{show(true);generate()});
    document.getElementById('releaseCmdNew').addEventListener('click',generate);
    document.getElementById('releaseCmdClose').addEventListener('click',()=>show(false));
    modal.addEventListener('click',e=>{if(e.target===modal)show(false)});
    copy.addEventListener('click',async()=>{
        try{await navigator.clipboard.writeText(text.value);say('Comando copiado.',true)}
        catch{text.focus();text.select();say('Selecione e copie o texto (Ctrl+C).',true)}
    });
})();
</script>
<script>
(function(){
    'use strict';
    const endpoint = <?= json_encode($poll) ?>;
    const approvedUrl = <?= json_encode(arc_workspace_base_url() . 'redes/painel.php?network=' . $networkId) ?>;
    let signature = <?= json_encode($gestoresSignature) ?>;
    let current = <?= json_encode($stage) ?>;
    let busy = false;

    const tbody = document.getElementById('releaseRows');
    const searchInput = document.getElementById('releaseSearch');
    const filterSelect = document.getElementById('releaseFilter');
    const rows = () => Array.from(tbody.querySelectorAll('tr[data-row]'));

    const sortState = { key: 'last_seen_ts', dir: 'desc' };
    const heads = Array.from(document.querySelectorAll('.arc-sort'));

    function compare(a,b,key,dir){
        const av = a.dataset[key] ?? '';
        const bv = b.dataset[key] ?? '';
        const na = Number(av), nb = Number(bv);
        let result;
        if(!Number.isNaN(na) && !Number.isNaN(nb) && av !== '' && bv !== ''){
            result = na === nb ? 0 : (na > nb ? 1 : -1);
        }else{
            result = String(av).localeCompare(String(bv), 'pt-BR', {numeric:true, sensitivity:'base'});
        }
        return dir === 'asc' ? result : result * -1;
    }

    function applySortIndicators(){
        heads.forEach((button)=>{
            if(button.dataset.sort === sortState.key){
                button.dataset.dir = sortState.dir;
            }else{
                button.removeAttribute('data-dir');
            }
        });
    }

    function updateEmptyRow(visibleCount){
        let empty = document.getElementById('releaseEmptyRow');
        if(!empty){
            empty = document.createElement('tr');
            empty.className = 'arc-release-empty-row';
            empty.id = 'releaseEmptyRow';
            empty.innerHTML = '<td colspan="7"><div class="arc-release-empty"><i class="fa fa-search"></i><strong>Nenhum gestor encontrado.</strong><span>Ajuste a busca ou o filtro para localizar outro gestor.</span></div></td>';
            tbody.appendChild(empty);
        }
        empty.style.display = visibleCount ? 'none' : '';
    }

    function renderRows(){
        const term = (searchInput.value || '').trim().toLocaleLowerCase('pt-BR');
        const filter = filterSelect.value || 'all';
        const allRows = rows();
        const filtered = allRows.filter((row)=>{
            const okSearch = !term || (row.dataset.search || '').includes(term);
            const okFilter = filter === 'all' || row.dataset.status === filter;
            row.hidden = !(okSearch && okFilter);
            return okSearch && okFilter;
        });
        filtered.sort((a,b)=>compare(a,b,sortState.key,sortState.dir));
        filtered.forEach((row)=>tbody.appendChild(row));
        allRows.filter((row)=>row.hidden).forEach((row)=>tbody.appendChild(row));
        updateEmptyRow(filtered.length);
        applySortIndicators();
    }

    heads.forEach((button)=>{
        button.addEventListener('click', ()=>{
            const key = button.dataset.sort;
            if(sortState.key === key){
                sortState.dir = sortState.dir === 'asc' ? 'desc' : 'asc';
            }else{
                sortState.key = key;
                sortState.dir = key.endsWith('_ts') ? 'desc' : 'asc';
            }
            renderRows();
        });
    });

    searchInput.addEventListener('input', renderRows);
    filterSelect.addEventListener('change', renderRows);
    renderRows();
    const modal = document.getElementById('arcGestorDetailModal');
    const modalBody = document.getElementById('arcGestorModalBody');
    const safe = v => String(v ?? '—').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const field = (name, value, wide=false) => `<div class="arc-modal-info${wide?' arc-modal-wide':''}"><small>${safe(name)}</small><strong>${safe(value)}</strong></div>`;
    tbody.addEventListener('click', e => {
        if(e.target.closest('button,form,a,input,select')) return;
        const tr = e.target.closest('tr[data-row]'); if(!tr) return;
        let data; try {data = JSON.parse(tr.dataset.details);} catch(e){return;}
        const detail=data.details && typeof data.details === 'object' ? data.details : {};
        document.getElementById('arcGestorModalTitle').textContent = data.machine || 'Detalhes do computador';
        let html = field('Status',data.status_label)+field('Versão do gestor',data.version)+field('Sistema operacional',data.platform,true)+field('IP local',data.local_ip)+field('Instalado em',data.installed)+field('Último acesso',data.last_seen)+field('Código do gestor',data.id);
        for(const [k,v] of Object.entries(detail)){if(v===null || typeof v==='object') continue;html+=field(k,v);}
        if(data.notes) html+=field('Observações do registro',data.notes,true);
        modalBody.innerHTML=html;
        if(typeof modal.showModal === 'function') modal.showModal(); else modal.setAttribute('open','');
    });
    document.getElementById('arcGestorModalClose').addEventListener('click',()=>modal.close());
    modal.addEventListener('click',e=>{if(e.target===modal)modal.close()});
    document.querySelectorAll('form[data-confirm]').forEach(form=>form.addEventListener('submit',ev=>{if(!confirm(form.dataset.confirm||'Confirmar esta ação?'))ev.preventDefault()}));

    async function check(){
        if(busy) return;
        busy = true;
        try{
            const response = await fetch(endpoint,{credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}});
            if(!response.ok) return;
            const value = await response.json();
            if(!value.ok) return;
            if(value.signature && value.signature !== signature){
                location.reload();
                return;
            }
        }catch(e){}
        finally{ busy = false; }
    }

    check();
    setInterval(()=>{if(!document.hidden)check();}, 2500);
    document.addEventListener('visibilitychange', ()=>{ if(!document.hidden) check(); });
})();
</script>
</div></div></main>
<?php if(!empty($networkId)) require __DIR__.'/_gestor_update_dialog.php'; ?>
<?php require dirname(__DIR__) . '/includes/footer.php';
