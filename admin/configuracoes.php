<?php
/* Caminho: admin/configuracoes.php */
declare(strict_types=1);
require dirname(__DIR__).'/includes/admin-actions.php';
if(!arc_is_admin()){header('Location: '.arc_workspace_base_url().'index.php',true,302);exit;}
$pdo=arc_db();
$notice='';$error='';
$tabs=['aparencia'=>'Temas globais','designer'=>'Designer','dados'=>'Dados do sistema','login'=>'Login e acesso','smtp'=>'E-mail SMTP','apis'=>'Drive','log'=>'Auditoria e logs','manutencao'=>'Manutenção'];
$schema=[
 'aparencia'=>[
 'platform_visual_theme'=>['Tema para toda a plataforma e agentes','select:windows|ocean|crystal|graphite|neon|emerald','windows'],
 'platform_visual_mode'=>['Luminosidade (tema = padrão do tema)','select:theme|light|dark|auto','theme']],
 'designer'=>[
 'platform_agent_palette'=>['Paleta do agente','select:azul|violeta|verde|laranja|personalizada','azul'],
 'platform_support_palette'=>['Paleta do suporte','select:azul|violeta|verde|laranja|personalizada','violeta'],
 'platform_agent_accent'=>['Cor personalizada do agente','color','#087eac'],
 'platform_support_accent'=>['Cor personalizada do suporte','color','#8b5cf6'],
 'platform_agent_icon'=>['Ícone do agente (URL HTTPS; vazio usa a logo do sistema)','url',''],
 'platform_support_icon'=>['Ícone do suporte (URL HTTPS; vazio usa a logo do sistema)','url','']],
 'dados'=>[
 'platform_webhook_url'=>['Webhook de eventos (HTTPS)','url',''],
 'platform_api_external_enabled'=>['Habilitar integrações externas','bool','0']],
 'smtp'=>[
 'platform_smtp_enabled'=>['Ativar envio SMTP','bool','0'],
 'platform_smtp_host'=>['Servidor SMTP','text',''],
 'platform_smtp_port'=>['Porta SMTP','number:1:65535','587'],
 'platform_smtp_encryption'=>['Criptografia','select:starttls|tls','starttls'],
 'platform_smtp_username'=>['Usuário SMTP','text',''],
 'platform_smtp_password'=>['Senha SMTP','secret',''],
 'platform_smtp_from_email'=>['E-mail remetente','email',''],
 'platform_smtp_from_name'=>['Nome do remetente','text','Arcoll']],
 'apis'=>[
],
 'login'=>[
 'platform_registration_enabled'=>['Permitir cadastro público','bool','1'],
 'platform_google_enabled'=>['Permitir login com Google','bool','0'],
 'platform_google_client_id'=>['Google OAuth · Client ID','text',''],
 'platform_google_client_secret'=>['Google OAuth · Client Secret','secret',''],
 'platform_login_compact'=>['Formulário compacto','bool','1'],
 'platform_login_show_showcase'=>['Exibir painel de apresentação','bool','0'],
 'platform_login_show_logo'=>['Exibir logotipo','bool','1'],
 'platform_login_show_subtitle'=>['Exibir subtítulo','bool','0'],
 'platform_login_show_footer'=>['Exibir texto informativo do rodapé','bool','0'],
 'platform_login_show_theme'=>['Exibir seletor de tema','bool','0'],
 'platform_registration_password_policy'=>['Exigência de senha no cadastro','select:none|basic|medium|advanced','medium'],
 'platform_login_layout'=>['Posição do formulário','select:left|center|right','right'],
 'platform_login_heading'=>['Título do formulário','text','Bem-vindo de volta'],
 'platform_login_subtitle'=>['Subtítulo do formulário','textarea','Entre com suas credenciais para acessar a plataforma.'],
 'platform_login_hero_eyebrow'=>['Selo do painel de apresentação','text','Gestão inteligente de redes'],
 'platform_login_hero_title'=>['Título do painel de apresentação','text','Sua rede sob controle'],
 'platform_login_hero_description'=>['Descrição do painel de apresentação','textarea','Monitore dispositivos, conexões e atendimentos em um único ambiente seguro.'],
 'platform_login_footer_text'=>['Aviso no rodapé do login','textarea','Área restrita. Sua sessão é protegida e os acessos são registrados.'],
 'platform_login_show_password_toggle'=>['Permitir mostrar/ocultar senha','bool','1'],
 'platform_login_show_strength'=>['Mostrar indicador de força da senha no cadastro','bool','1'],
 'platform_login_max_attempts'=>['Tentativas de login por IP (15 minutos)','number:3:30','5'],
 'platform_session_idle_minutes'=>['Inatividade máxima (minutos)','number:5:1440','120']],
 'manutencao'=>[
 'platform_maintenance_enabled'=>['Ativar manutenção pública','bool','0'],
 'platform_maintenance_title'=>['Título da manutenção','text','Plataforma em manutenção'],
 'platform_maintenance_message'=>['Mensagem aos visitantes','textarea','Estamos aprimorando nossos serviços. Retornaremos em breve.'],
 'platform_maintenance_until'=>['Previsão de conclusão','datetime',''],
 'platform_maintenance_image'=>['Imagem da manutenção (URL HTTPS ou arquivo)','url','']],
];
$schema['manutencao']['platform_cloud_max_file_mb']=['Limite por arquivo (MB)','number:1:51200','5120'];
$schema['manutencao']['platform_cloud_allow_own']=['Backup: permitir conta própria do cliente (Google Drive, OneDrive, Dropbox, S3)','bool','1'];
$schema['manutencao']['platform_cloud_allow_platform']=['Backup: permitir o Drive da plataforma','bool','1'];
$schema['manutencao']['platform_cloud_allow_vdrive']=['Backup: permitir o disco virtual no Windows (abrir os arquivos da nuvem pelo Explorador)','bool','1'];
$schema['manutencao']['platform_cloud_allow_folder_picker']=['Backup: permitir escolher discos e pastas do computador pela plataforma','bool','1'];
foreach (arc_cloud_providers() as $provider=>$meta) {
 $prefix='platform_cloud_'.$provider.'_';
 $schema['apis'][$prefix.'enabled']=['Disponibilizar '.$meta['name'],'bool','0'];
 if ($meta['oauth']) {
  $schema['apis'][$prefix.'client_id']=[$meta['name'].' · Client ID','text',''];
  $schema['apis'][$prefix.'client_secret']=[$meta['name'].' · Client Secret','secret',''];
 } else {
  $schema['apis'][$prefix.'endpoint']=['S3 compatível · endpoint HTTPS (vazio: Amazon)','url',''];
  $schema['apis'][$prefix.'region']=['S3 · região padrão','text','us-east-1'];
 }
 if ($provider==='onedrive') $schema['apis'][$prefix.'tenant']=['Microsoft · tenant (common ou ID)','text','common'];
}
$values=[];
foreach($schema as $items)foreach($items as $key=>$meta)$values[$key]=arc_platform_setting($pdo,$key,$meta[2]);
$tab=(string)($_POST['tab']??$_GET['tab']??'dados');
if($tab==='nuvem')$tab='apis';
if($tab==='monitoramento')$tab='manutencao';
if($tab==='seguranca')$tab='login';
if(!isset($tabs[$tab]))$tab='dados';
function arc_cfg_validate(string $key,string $value,string $type):string {
 if(strlen($value)>2048)throw new RuntimeException('Campo muito longo: '.$key);
 if($type==='version'&&!preg_match('/^\d+\.\d+\.\d+(?:[-.][A-Za-z0-9]+)?$/D',$value))throw new RuntimeException('Versão inválida. Utilize X.Y.Z.');
 if($type==='email'&&$value!==''&&!filter_var($value,FILTER_VALIDATE_EMAIL))throw new RuntimeException('E-mail inválido.');
 if($type==='url'&&$value!==''&&(!filter_var($value,FILTER_VALIDATE_URL)||!str_starts_with($value,'https://')))throw new RuntimeException('Utilize uma URL HTTPS.');
 if($type==='color'&&!preg_match('/^#[a-f0-9]{6}$/i',$value))throw new RuntimeException('Cor inválida.');
 if(str_starts_with($type,'select:')&&!in_array($value,explode('|',substr($type,7)),true))throw new RuntimeException('Opção inválida.');
 if(str_starts_with($type,'number:')){$bounds=explode(':',$type);if(filter_var($value,FILTER_VALIDATE_INT)===false||(int)$value<(int)$bounds[1]||(int)$value>(int)$bounds[2])throw new RuntimeException('Número fora do intervalo.');}
 if($type==='datetime'&&$value!==''&&(!DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value)))throw new RuntimeException('Data inválida.');
 return $value;
}
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!hash_equals(arc_csrf_token(),(string)($_POST['_csrf']??''))){http_response_code(403);$error='Sessão expirada. Atualize a página.';}
 else{
  try{
   $action=(string)($_POST['action']??'save');
   if($action==='save'||$action==='save_group'){
    if(!isset($schema[$tab]))throw new RuntimeException('Aba inválida.');
    $changes=[];
    $allowed=$schema[$tab];
    if($action==='save_group'){
     if($tab!=='apis')throw new RuntimeException('Grupo inválido.');
     $group=(string)($_POST['group']??'');
     $prefixes=['cloud_gdrive'=>['platform_cloud_gdrive_'],'cloud_onedrive'=>['platform_cloud_onedrive_'],
       'cloud_dropbox'=>['platform_cloud_dropbox_'],'cloud_s3'=>['platform_cloud_s3_']];
     if(!isset($prefixes[$group]))throw new RuntimeException('Grupo não reconhecido.');
     $allowed=array_filter($schema[$tab],static function($meta,$name)use($prefixes,$group){
      foreach($prefixes[$group] as $prefix)if(str_starts_with($name,$prefix))return true;return false;
     },ARRAY_FILTER_USE_BOTH);
    }
    foreach($allowed as $key=>$meta){
     $type=$meta[1];$value=$type==='bool'?(isset($_POST[$key])?'1':'0'):trim((string)($_POST[$key]??''));
     if($type==='secret'&&$value==='')continue;
     if($type==='secret')$value=arc_platform_encrypt($value);
     else $value=arc_cfg_validate($key,$value,$type);
     $changes[$key]=$value;
    }
    $pdo->beginTransaction();
    try{foreach($changes as $key=>$value)arc_platform_save_setting($pdo,$key,$value);$pdo->commit();}catch(Throwable $e){$pdo->rollBack();throw $e;}
    foreach($changes as $key=>$value)$values[$key]=$value;
    $notice=$action==='save_group'?'Integração salva. O destaque verde indica credenciais completas e provedor habilitado.':'Configurações salvas e aplicadas.';
   }elseif($action==='upload_designer_icon'){
    $component=(string)($_POST['component']??'');if(!in_array($component,['agent','support'],true))throw new RuntimeException('Aplicativo inválido.');
    $file=$_FILES['designer_icon']??null;if(!$file||(int)$file['error']!==UPLOAD_ERR_OK||(int)$file['size']>1572864)throw new RuntimeException('Envie uma imagem de até 1,5 MB.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);$ext=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'][$mime]??null;$info=getimagesize($file['tmp_name']);if(!$ext||!$info||$info[0]>2048||$info[1]>2048)throw new RuntimeException('Use PNG, JPG ou WebP de até 2048 pixels.');
    $name=bin2hex(random_bytes(16)).'.'.$ext;if(!move_uploaded_file($file['tmp_name'],arc_upload_dir('brand').'/'.$name))throw new RuntimeException('Não foi possível salvar o ícone.');
    require_once dirname(__DIR__).'/includes/media-optimizer.php';arc_optimize_uploaded_image(arc_upload_dir('brand').'/'.$name,1200);
    $url='https://'.(string)$_SERVER['HTTP_HOST'].arc_workspace_base_url().'uploads/brand/'.$name;arc_platform_save_setting($pdo,'platform_'.$component.'_icon',$url);$values['platform_'.$component.'_icon']=$url;$notice='Ícone salvo.';
   }elseif($action==='smtp_test'){
    $to=trim((string)($_POST['test_email']??''));
    if(!filter_var($to,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Informe um e-mail válido.');
    arc_platform_smtp_send($pdo,$to,'Teste SMTP Arcoll','Esta é uma mensagem de teste das configurações SMTP do Arcoll.');
    $notice='E-mail de teste enviado para '.$to.'.';
    }elseif($action==='upload_maintenance_image'){
    $file=$_FILES['maintenance_image']??null;
    if(!$file || (int)$file['error']!==UPLOAD_ERR_OK || (int)$file['size']>1572864)throw new RuntimeException('Envie uma imagem de até 1,5 MB.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $ext=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'][$mime]??null;
    if(!$ext || !getimagesize($file['tmp_name']))throw new RuntimeException('Imagem inválida.');
    $dir=arc_upload_dir('brand');$name='maintenance-'.bin2hex(random_bytes(16)).'.'.$ext;
    if(!move_uploaded_file($file['tmp_name'],$dir.'/'.$name))throw new RuntimeException('Falha ao gravar imagem.');
    $url=rtrim(parse_url(arc_workspace_base_url(),PHP_URL_PATH)?:'','/').'/uploads/brand/'.$name;
    arc_platform_save_setting($pdo,'platform_maintenance_image',$url);
    $values['platform_maintenance_image']=$url;
    $notice='Imagem importada e configurada.';
   }elseif($action==='save_log_policies'){
    $catalog=arc_log_catalog($pdo);$policies=[];
    foreach($catalog as $key=>$cfg){
     $days=filter_var($_POST['days'][$key]??'90',FILTER_VALIDATE_INT);
     $mb=filter_var($_POST['max_mb'][$key]??'0',FILTER_VALIDATE_INT);
     if($days===false||$days<0||$days>3650||$mb===false||$mb<0||$mb>2048)throw new RuntimeException('Retenção ou limite de armazenamento inválido.');
     $policies[$key]=['enabled'=>isset($_POST['enabled'][$key]),'days'=>$days,'max_mb'=>$mb,'details'=>isset($_POST['details'][$key]),'ip'=>isset($_POST['ip'][$key])];
    }
    arc_platform_save_setting($pdo,'platform_log_policies',json_encode($policies,JSON_THROW_ON_ERROR));
    $notice='Políticas de logs salvas. Execute a retenção na Central de Manutenção ou ative o cron do servidor.';
   }elseif($action==='clear_logs'){
    $limit=(int)($_POST['days']??30);
    if(!in_array($limit,[7,30,90],true))throw new RuntimeException('Prazo inválido.');
    $audit=arc_table('account_audit');
    $stmt=$pdo->prepare("DELETE FROM $audit WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
    $stmt->execute([$limit]);
    $notice='Registros de auditoria anteriores a '.$limit.' dias eliminados: '.$stmt->rowCount().'.';
   }elseif($action==='delete_audit_rows'){
    $ids=array_values(array_unique(array_filter(array_map('intval',explode(',',(string)($_POST['audit_ids']??''))),static fn($x)=>$x>0)));
    if(!$ids||count($ids)>100)throw new RuntimeException('Selecione de 1 a 100 registros de auditoria.');
    if((string)($_POST['confirm_delete']??'')!=='yes')throw new RuntimeException('Confirme a exclusão dos registros.');
    $table=arc_table('account_audit');
    $marks=implode(',',array_fill(0,count($ids),'?'));
    $stmt=$pdo->prepare("DELETE FROM $table WHERE id IN ($marks)");
    $stmt->execute($ids);
    $notice=$stmt->rowCount().' registro(s) de auditoria excluído(s).';
   }elseif($action==='clear_cache'){
    // Limpa somente o cache de sessão desta solicitação; não remove dados de agentes ou usuários.
    clearstatcache(true);
    if(function_exists('opcache_reset'))@opcache_reset();
    $notice='Cache de arquivos e OPcache reiniciados, quando disponíveis.';
   }
  }catch(Throwable $e){error_log('Arcoll configurações: '.$e->getMessage());$error=$e->getMessage();}
 }
}
// Consulta de logs somente nas tabelas conhecidas e existentes neste banco.
$arcLogSources=['account_audit'=>'Auditoria administrativa','login_attempts'=>'Tentativas de autenticação'];
$arcLogTables=[];$arcLogRows=[];$arcLogColumns=[];$arcLogTotal=0;$arcLogError='';
try {
 $present=$pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
 foreach($arcLogSources as $logical=>$name){$physical=arc_table($logical);if(in_array($physical,$present,true))$arcLogTables[$logical]=['table'=>$physical,'name'=>$name];}
 // Tabelas adicionais de log só aparecem em leitura, sem opção de exclusão.
 foreach($present as $physical){if(preg_match('/(?:^|_)(?:logs?|audit|events?)(?:_|$)/i',$physical)&&!in_array($physical,array_column($arcLogTables,'table'),true))$arcLogTables['read_'.count($arcLogTables)]=['table'=>$physical,'name'=>'Eventos: '.$physical];}
 $arcLogChoice=(string)($_GET['source']??'account_audit');if(!isset($arcLogTables[$arcLogChoice]))$arcLogChoice=(string)(array_key_first($arcLogTables)??'');
 if($arcLogChoice!==''){
  $table=$arcLogTables[$arcLogChoice]['table'];
  $arcLogColumns=array_values(array_filter($pdo->query('SHOW COLUMNS FROM `'.str_replace('`','``',$table).'`')->fetchAll(PDO::FETCH_COLUMN),static fn($c)=>in_array($c,['id','created_at','updated_at','attempted_at','event_type','event','action','status','user_id','admin_id','network_id','agent_id','message','description','details','account_id','attempted_at','ip_address'],true))); if(!$arcLogColumns)$arcLogColumns=['id'];
  $arcLogTotal=(int)$pdo->query('SELECT COUNT(*) FROM `'.str_replace('`','``',$table).'`')->fetchColumn();
  $order=in_array('created_at',$arcLogColumns,true)?'created_at':(in_array('id',$arcLogColumns,true)?'id':$arcLogColumns[0]);
  $arcLogRows=$pdo->query('SELECT '.implode(',',array_map(static fn($c)=>'`'.str_replace('`','``',$c).'`',$arcLogColumns)).' FROM `'.str_replace('`','``',$table).'` ORDER BY `'.str_replace('`','``',$order).'` DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
 }
} catch(Throwable $e){error_log('Consulta de logs: '.$e->getMessage());$arcLogError='Não foi possível consultar os logs com as permissões atuais.';}
function arc_cfg_log_title(string $event):string {
 $titles=['network_created'=>'Rede cadastrada','network_deleted'=>'Rede excluída','network_updated'=>'Rede atualizada','network_agent_requested'=>'Solicitação de novo agente','network_agent_approved'=>'Agente autorizado','network_agent_rejected'=>'Agente recusado','agent_command_queued'=>'Ação enviada ao agente','password_changed'=>'Senha alterada','admin.login'=>'Entrada de administrador','admin.logout'=>'Saída do administrador','login_success'=>'Acesso autorizado','login_failed'=>'Falha ao entrar','agent_online'=>'Agente conectado','agent_offline'=>'Agente desconectado'];
 if(isset($titles[$event]))return $titles[$event];
 $clean=str_replace(['_','.','-'],' ',$event);return mb_convert_case($clean,MB_CASE_TITLE,'UTF-8');
}
function arc_cfg_log_description(array $row):string {
 $details=trim((string)($row['details']??$row['description']??$row['message']??''));
 $event=(string)($row['event_type']??$row['event']??$row['action']??'');
 if($event==='agent_command_queued')$details=str_replace(['monitor_start','monitor_stop','approved'],['iniciar monitoramento','parar monitoramento','aprovado'],$details);
 if($event==='network_agent_approved')$details=str_replace('approved','aprovado',$details);
 if($details==='')return 'O sistema registrou este evento; não há descrição complementar disponível.';
 return $details;
}
function arc_cfg_log_safe_data(array $row):array {
 $labels=['id'=>'Registro','created_at'=>'Data e hora','updated_at'=>'Atualização','attempted_at'=>'Data da tentativa','event_type'=>'Ocorrência','event'=>'Ocorrência','action'=>'Ação','status'=>'Situação','user_id'=>'Usuário (ID)','account_id'=>'Conta (ID)','admin_id'=>'Administrador (ID)','agent_id'=>'Agente (ID)','network_id'=>'Rede (ID)','message'=>'Mensagem','description'=>'Descrição','details'=>'Detalhes','ip_address'=>'Endereço IP'];
 $out=[];foreach($row as $key=>$value){if(preg_match('/pass|token|secret|cookie|session|credential|authorization|api_key|ip_hash|identifier_hash/i',(string)$key))continue;$text=trim((string)($value??''));$out[$labels[$key]??mb_convert_case(str_replace('_',' ',(string)$key),MB_CASE_TITLE,'UTF-8')]=$text===''?'Não informado':mb_substr($text,0,500);}
 return $out;
}
$base=arc_workspace_base_url();
$redirect=(arc_is_https()?'https':'http').'://'.(string)($_SERVER['HTTP_HOST']??'').$base.'google-auth.php';
$ARCPageTitle='Configurações da plataforma';$ARCActiveNav='platform_settings';$ARCMode='app';
if($notice!=='')$_SESSION['arc_flash']=$notice;
if($error!=='')$arcError=$error;
require dirname(__DIR__).'/includes/header.php';
require dirname(__DIR__).'/includes/sidebar.php';
?>
<main class="app-main"><div class="page-content arc-platform-settings arc-config-v3">
<nav class="arc-config-tabs arc-config-compact-tabs" role="tablist" aria-label="Configurações">
<?php foreach($tabs as $key=>$label):?><button type="button" class="<?= $key===$tab?'active':'' ?>" data-config-tab="<?=arc_h($key)?>" role="tab" aria-selected="<?= $key===$tab?'true':'false' ?>"><i class="fa <?=arc_h(match($key){'dados'=>'fa-building-o','seguranca'=>'fa-shield','smtp'=>'fa-envelope-o','apis'=>'fa-plug','login'=>'fa-sign-in','log'=>'fa-history',default=>'fa-wrench'})?>"></i><span><?=arc_h($label)?></span></button><?php endforeach;?>
</nav>
<div class="arc-config-form" id="arc-config-form">

<?php foreach($tabs as $key=>$label):?>
<section class="arc-config-panel <?= $key===$tab?'active':'' ?>" data-config-panel="<?=arc_h($key)?>" role="tabpanel" <?= $key===$tab?'':'hidden' ?>>
<div class="card arc-settings-form">
<div class="arc-settings-heading"><i class="fa fa-sliders"></i><div><h2><?=arc_h($label)?></h2><p>Alterações armazenadas na configuração central da plataforma.</p></div><?php if($key==='log'):?><button type="button" class="button secondary arc-log-settings-open" onclick="document.getElementById('arc-log-policy-modal').hidden=false"><i class="fa fa-cog"></i> Configurações do Log</button><?php endif;?></div>
<?php if($key==='dados'):
$setting=static fn(string $name,string $default='')=>arc_platform_setting($pdo,$name,$default);
?>
<form method="post" enctype="multipart/form-data" action="<?=arc_h(arc_workspace_base_url())?>admin/configuracoes.php" class="arc-config-section-form">
<input type="hidden" name="_csrf" value="<?=arc_h(arc_csrf_token())?>">
<input type="hidden" name="action" value="save_settings">
<input type="hidden" name="tab" value="<?=arc_h($key)?>">
<div class="arc-brand-settings">
<label class="arc-brand-upload" title="Clique para mudar a logo"><input id="arc-logo-file" type="file" name="logo" accept="image/png,image/jpeg,image/webp"><img id="arc-logo-preview" src="<?=arc_h(arc_workspace_base_url())?>api/brand-logo.php" alt="Logo atual"><span><i class="fa fa-camera"></i> Alterar logo</span></label>
<div class="arc-brand-fields"><div class="arc-brand-main-fields">
<label>Nome do sistema<input name="site_name" type="text" required value="<?=arc_h($setting('site_name','Arcoll Speed Connection'))?>"></label>
<label>Descrição<input name="tagline" type="text" required value="<?=arc_h($setting('tagline',''))?>"></label>
<label>Domínio<input name="domain_label" type="text" value="<?=arc_h($setting('domain_label',''))?>"></label>
<label class="arc-color-field">Cor de destaque <span class="arc-color-circle"><input type="color" name="accent_color" value="<?=arc_h($setting('accent_color','#22d3ee'))?>" aria-label="Escolher cor de destaque"></span></label>
</div></div></div>
<div class="arc-config-actions"><button class="button primary" type="submit"><i class="fa fa-save"></i> Salvar <?=arc_h($label)?></button></div>
</form>
<?php endif;?>
<?php if(!in_array($key,['dados','apis'],true) && isset($schema[$key])):?>
<form method="post" action="<?=arc_h(arc_workspace_base_url())?>admin/configuracoes.php" class="arc-config-section-form">
<input type="hidden" name="_csrf" value="<?=arc_h(arc_csrf_token())?>">
<input type="hidden" name="tab" value="<?=arc_h($key)?>">
<input type="hidden" name="action" value="save">
<?php if($key==='aparencia'):?><div class="arc-theme-library"><p>O tema global é obrigatório e é sincronizado com a interface Windows dos agentes conectados. Na opção Automático, cada equipamento acompanha o modo de luminosidade do sistema operacional.</p><div class="arc-theme-tiles"><?php foreach(arc_visual_themes() as $themeId=>$preset):?><label class="arc-theme-tile" style="--tile-bg:<?=arc_h($preset['bg'])?>;--tile-accent:<?=arc_h($preset['accent'])?>;--tile-surface:<?=arc_h($preset['surface'])?>"><input type="radio" name="platform_visual_theme" value="<?=arc_h($themeId)?>" <?=($values['platform_visual_theme']??'windows')===$themeId?'checked':''?>><span class="arc-theme-swatch"><i></i><b></b><b></b></span><strong><?=arc_h($preset['name'])?></strong></label><?php endforeach;?></div></div><?php endif;?>
<div class="arc-settings-grid">
<?php foreach($schema[$key] as $field=>$meta): if($key==='aparencia' && $field==='platform_visual_theme')continue;
[$title,$type,$default]=$meta;$value=$values[$field]??$default;$secret=$type==='secret';
?>
<?php if($type==='bool'):?>
<label class="arc-settings-toggle"><input type="checkbox" name="<?=arc_h($field)?>" value="1" <?=$value==='1'?'checked':''?>><span><strong><?=arc_h($title)?></strong><small>Aplicar esta preferência à plataforma.</small></span></label>
<?php elseif($type==='textarea'):?>
<label class="arc-full"><?=arc_h($title)?><textarea name="<?=arc_h($field)?>" maxlength="2048" rows="3"><?=arc_h($value)?></textarea></label>
<?php elseif(str_starts_with($type,'select:')):?>
<label><?=arc_h($title)?><select name="<?=arc_h($field)?>"><?php foreach(explode('|',substr($type,7)) as $opt):?><option value="<?=arc_h($opt)?>" <?=$value===$opt?'selected':''?>><?=arc_h(ucfirst($opt))?></option><?php endforeach;?></select></label>
<?php else:?>
<label><?=arc_h($title)?><input name="<?=arc_h($field)?>" type="<?=arc_h(match(true){$secret=>'password',$type==='color'=>'color',$type==='email'=>'email',$type==='url'=>'url',$type==='datetime'=>'datetime-local',str_starts_with($type,'number:')=>'number',default=>'text'})?>" value="<?= $secret?'':arc_h($value) ?>" <?= $secret?'autocomplete="new-password" placeholder="Deixe vazio para manter"':'' ?> <?=str_starts_with($type,'number:')?'min="'.arc_h(explode(':',$type)[1]).'" max="'.arc_h(explode(':',$type)[2]).'"':''?>></label>
<?php endif;endforeach;?>
</div>
<div class="arc-config-actions"><button class="button primary" type="submit"><i class="fa fa-save"></i> Salvar <?=arc_h($label)?></button></div></form>
<?php endif;?>
<?php if($key==='dados'):?>
<section class="arc-config-extra"><h3>Integrações externas</h3><p class="arc-config-context">Configure aqui somente a URL HTTPS que recebe eventos de sistemas externos.</p>
<form method="post" action="<?=arc_h($base)?>admin/configuracoes.php?tab=dados#dados">
<input type="hidden" name="_csrf" value="<?=arc_h(arc_csrf_token())?>"><input type="hidden" name="tab" value="dados"><input type="hidden" name="action" value="save">
<label>Webhook de eventos (HTTPS)<input type="url" name="platform_webhook_url" value="<?=arc_h($values['platform_webhook_url']??'')?>" placeholder="https://"></label>
<label class="arc-settings-toggle"><input type="checkbox" name="platform_api_external_enabled" value="1" <?=($values['platform_api_external_enabled']??'0')==='1'?'checked':''?>><span><strong>Habilitar integrações externas</strong></span></label>
<button type="submit" class="button secondary"><i class="fa fa-save"></i> Salvar integração</button></form></section>
<?php elseif($key==='designer'):?>
<div class="arc-config-extra"><?php foreach(['agent'=>'Agente Windows','support'=>'Aplicativo de suporte'] as $component=>$name):?><form method="post" enctype="multipart/form-data"><input type="hidden" name="_csrf" value="<?=arc_h(arc_csrf_token())?>"><input type="hidden" name="tab" value="designer"><input type="hidden" name="action" value="upload_designer_icon"><input type="hidden" name="component" value="<?=$component?>"><label>Ícone · <?=$name?><input type="file" name="designer_icon" accept="image/png,image/jpeg,image/webp" required></label><button class="button" type="submit">Enviar ícone</button></form><?php endforeach;?></div>
<?php elseif($key==='apis'):?>
<?php
$cfgFields=static function(array $fieldNames)use($schema,$values):void {
 foreach($fieldNames as $field){
  if(!isset($schema['apis'][$field]))continue;
  [$title,$type,$default]=$schema['apis'][$field];$value=$values[$field]??$default;$secret=$type==='secret';
  echo '<label class="arc-api-field"><strong>'.arc_h($title).'</strong>';
  if($type==='bool')echo '<input type="checkbox" name="'.arc_h($field).'" value="1" '.($value==='1'?'checked':'').'>';
  elseif(str_starts_with($type,'number:')){ $range=explode(':',$type);echo '<input type="number" name="'.arc_h($field).'" min="'.arc_h($range[1]).'" max="'.arc_h($range[2]).'" value="'.arc_h($value).'">';}
  else echo '<input name="'.arc_h($field).'" type="'.($secret?'password':($type==='url'?'url':'text')).'" value="'.($secret?'':arc_h($value)).'" autocomplete="'.($secret?'new-password':'off').'" placeholder="'.($secret?'Deixe em branco para manter o segredo':'').'">';
  $explain=match(true){
   str_ends_with($field,'client_id')=>'Copie o identificador do aplicativo no console da empresa acima.',
   str_ends_with($field,'client_secret')=>'Copie o segredo do mesmo aplicativo. Deixe vazio para manter o valor salvo.',
   str_ends_with($field,'tenant')=>'Use o ID do diretório Microsoft ou common quando apropriado.',
   str_ends_with($field,'endpoint')=>'URL HTTPS do serviço S3 compatível; vazio usa Amazon S3.',
   str_ends_with($field,'region')=>'Região onde o bucket está criado, como us-east-1.',
   str_ends_with($field,'enabled')=>'Habilite somente após preencher as credenciais e testar o provedor.',
   $field==='platform_cloud_max_file_mb'=>'Limite por arquivo em megabytes, aplicado a todas as redes.',
   $field==='platform_cloud_allow_own'=>'Quando ativo, o gestor da rede escolhe entre conectar a própria conta ou usar o Drive da plataforma.',
   $field==='platform_cloud_allow_vdrive'=>'Quando ativo, o gestor pode montar a nuvem do backup como uma unidade (ex.: Z:) no Windows do computador. Acesso somente leitura.',
   $field==='platform_cloud_allow_folder_picker'=>'Quando ativo, o gestor navega pelos discos e pastas do computador na própria plataforma (somente nomes de pastas) para escolher o que sincronizar.',
   $field==='platform_cloud_allow_platform'=>'Quando ativo e com uma conta conectada em Drive da plataforma, as redes podem armazenar o backup nela, em pastas separadas por rede e computador.',
   $field==='platform_webhook_url'=>'URL HTTPS do serviço receptor dos eventos, caso esta integração esteja implementada.',
   $field==='platform_api_external_enabled'=>'Permite acesso às integrações externas implementadas no sistema.',
   default=>''
  };
  if($explain!=='')echo '<small>'.arc_h($explain).'</small>';
  echo '</label>';
 }
};
$apiFormStart=static function(string $group)use($base):void{echo '<form class="arc-api-group-form" method="post" action="'.arc_h($base).'admin/configuracoes.php?tab=apis#apis"><input type="hidden" name="_csrf" value="'.arc_h(arc_csrf_token()).'"><input type="hidden" name="tab" value="apis"><input type="hidden" name="action" value="save_group"><input type="hidden" name="group" value="'.arc_h($group).'">';};
$apiFormEnd=static function():void{echo '<button class="button primary" type="submit"><i class="fa fa-save"></i> Salvar esta integração</button></form>';};
$providerDocs=[
 'gdrive'=>['fa-google','https://console.cloud.google.com/auth/clients', 'Google Cloud Console',[
 'Crie ou selecione um projeto no Google Cloud e ative a Google Drive API.',
 'Em Google Auth Platform, configure a tela de consentimento e crie um cliente OAuth do tipo Aplicativo da Web.',
 'Cadastre a URL de retorno abaixo em URIs de redirecionamento autorizados. Copie o Client ID e Client Secret para os campos correspondentes.',
 'Autorize o escopo drive.file na tela de consentimento e publique o aplicativo quando estiver pronto.']],
 'onedrive'=>['fa-windows','https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade', 'Microsoft Entra',[
 'Entre em Entra ID → Registros de aplicativos → Novo registro. Escolha os tipos de conta adequados.',
 'Em Autenticação, adicione plataforma Web e registre exatamente a URL de retorno abaixo.',
 'Copie a ID do aplicativo (cliente). Em Certificados e segredos, crie um segredo e copie o VALOR (não a ID do segredo).',
 'Em Permissões de API, adicione Microsoft Graph → Delegadas: Files.ReadWrite, User.Read e offline_access. Informe o tenant ou use common.']],
 'dropbox'=>['fa-dropbox','https://www.dropbox.com/developers/apps', 'Dropbox App Console',[
 'Crie um aplicativo Scoped access e escolha o acesso a pasta do app ou ao Dropbox completo, conforme a necessidade.',
 'Em Permissions, habilite os escopos de arquivos utilizados pela conexão.',
 'Em OAuth 2, cadastre exatamente a URL de retorno abaixo.',
 'Copie App key para Client ID e App secret para Client Secret. A autorização usa acesso offline para renovação.']],
 's3'=>['fa-cloud','https://console.aws.amazon.com/iam/home', 'AWS IAM',[
 'Crie o bucket na console S3 e selecione a região em que ele foi criado.',
 'No IAM, crie credenciais com acesso mínimo ao bucket específico, sem usar credenciais de root.',
 'Aqui configure apenas endpoint e região padrão da plataforma. Cada rede informa o bucket e suas próprias chaves de acesso ao conectar.',
 'Para provedores S3 compatíveis, informe a URL HTTPS do endpoint; deixe vazia para Amazon S3.']]
];
?>
<p class="arc-config-context">Clique no provedor para configurar o armazenamento. Verde indica integração habilitada com as credenciais necessárias preenchidas.</p>
<div class="arc-drive-grid" aria-label="Provedores de armazenamento">
<?php foreach(arc_cloud_providers() as $provider=>$meta):
 $prefix='platform_cloud_'.$provider.'_';
 $configured=($values[$prefix.'enabled']??'0')==='1' && ($provider==='s3' || (($values[$prefix.'client_id']??'')!=='' && arc_platform_decrypt($values[$prefix.'client_secret']??'')!==''));
 $doc=$providerDocs[$provider];
?>
<button class="arc-drive-tile <?= $configured?'arc-drive-active':'arc-drive-inactive' ?>" type="button" data-open-dialog="arc-drive-<?=arc_h($provider)?>" aria-label="Configurar <?=arc_h($meta['name'])?>" aria-haspopup="dialog">
<span class="arc-api-logo <?= $provider==='s3'?'arc-api-logo-aws':'' ?>"><?php if($provider==='s3'):?>aws<?php else:?><i class="fa <?=arc_h($doc[0])?>" aria-hidden="true"></i><?php endif;?></span><span><?=arc_h($meta['name'])?></span><?php if($configured):?><i class="fa fa-check-circle arc-drive-check" aria-label="Ativo"></i><?php endif;?></button>
<?php endforeach;?></div>
<?php foreach(arc_cloud_providers() as $provider=>$meta):$doc=$providerDocs[$provider];$prefix='platform_cloud_'.$provider.'_';?>
<dialog class="arc-service-dialog arc-drive-dialog" id="arc-drive-<?=arc_h($provider)?>" aria-labelledby="arc-drive-title-<?=arc_h($provider)?>"><header class="arc-service-heading"><button type="button" class="arc-drive-close" data-close-dialog aria-label="Fechar"><i class="fa fa-times"></i></button><span class="arc-api-logo <?= $provider==='s3'?'arc-api-logo-aws':'' ?>"><?php if($provider==='s3'):?>aws<?php else:?><i class="fa <?=arc_h($doc[0])?>"></i><?php endif;?></span><div><h3 id="arc-drive-title-<?=arc_h($provider)?>"><?=arc_h($meta['name'])?></h3><p><?= $meta['oauth']?'Conexão segura por autorização OAuth, sem pedir senha da conta ao usuário.':'Armazenamento em buckets Amazon S3 ou serviço compatível.' ?></p></div></header>
<div class="arc-api-guide"><h4>Como gerar as credenciais</h4><ol><?php foreach($doc[3] as $step):?><li><?=arc_h($step)?></li><?php endforeach;?></ol><?php if($meta['oauth']):?><div class="arc-api-return"><span>Copie esta URL e cole no campo <strong>URI de redirecionamento / Redirect URI</strong> do aplicativo <?=arc_h($meta['name'])?>:</span><code><?=arc_h(arc_cloud_redirect_uri())?></code><button class="button secondary" type="button" data-copy-uri="<?=arc_h(arc_cloud_redirect_uri())?>">Copiar URL</button></div><?php endif;?><a href="<?=arc_h($doc[1])?>" target="_blank" rel="noopener noreferrer"><i class="fa fa-external-link"></i> Abrir <?=arc_h($doc[2])?></a></div>
<?php $apiFormStart('cloud_'.$provider);?><div class="arc-api-fields"><?php $cfgFields([$prefix.'enabled',$prefix.'client_id',$prefix.'client_secret',$prefix.'tenant',$prefix.'endpoint',$prefix.'region']);?></div>
<div class="arc-api-field-help"><?php if($provider==='s3'):?>Endpoint: URL HTTPS da API S3 compatível; em branco usa Amazon S3. Região: código como us-east-1. O bucket e as chaves pertencem a cada rede.<?php else:?>Client ID: identificador público do aplicativo no console do provedor. Client Secret: segredo criado no mesmo aplicativo (campo vazio mantém o atual).<?php if($provider==='onedrive'):?> Tenant: ID do diretório Microsoft ou <code>common</code> para permitir contas compatíveis.<?php endif;?><?php endif;?></div>
<?php $apiFormEnd();?>
<?php if($_SERVER['REQUEST_METHOD']==='POST' && (string)($_POST['action']??'')==='save_group' && (string)($_POST['group']??'')==='cloud_'.$provider):?>
<p class="arc-managed-feedback" role="status"><?=arc_h($error!==''?$error:($notice!==''?$notice:'Configuração processada.'))?></p>
<?php endif;?></dialog>
<?php endforeach;?>

<?php
arc_cloud_ensure_schema($pdo);
$managedQ=$pdo->query('SELECT * FROM '.arc_table('cloud_connections').' WHERE network_id=0 AND account_id=0 ORDER BY id');
$managedConnections=[];foreach($managedQ->fetchAll(PDO::FETCH_ASSOC) as $managedRow)$managedConnections[$managedRow['provider']]=$managedRow;
?>
<section class="arc-managed-drive" aria-labelledby="arc-managed-drive-title">
<h3 id="arc-managed-drive-title">Drive da plataforma</h3>
<p class="arc-config-context">Conecte uma conta administrada pela plataforma para oferecer armazenamento às redes. Cada computador terá uma pasta separada dentro do destino. O cadastro de Client ID e Client Secret acima não conecta uma conta automaticamente.</p>
<div class="arc-managed-grid">
<?php foreach(arc_cloud_providers() as $provider=>$meta):$managed=$managedConnections[$provider]??null;$ready=arc_cloud_provider_config($pdo,$provider)['ready']??false; ?>
<div class="arc-managed-item"><strong><?=arc_h($meta['name'])?></strong>
<?php if($managed):?><span class="arc-managed-status"><i class="fa fa-check-circle"></i> Conta conectada<?=($managed['status']??'')==='error'?' · verificar acesso':''?></span>
<button type="button" class="button secondary" data-managed-disconnect="<?=(int)$managed['id']?>">Desconectar conta</button>
<?php else:?>
<button type="button" class="button secondary" data-managed-connect="<?=arc_h($provider)?>" <?=$ready?'':'disabled'?>><i class="fa fa-link"></i> Conectar conta</button>
<?php if(!$ready):?><small>Ative o provedor e salve as credenciais acima.</small><?php endif;?>
<?php if($provider==='s3'):?><div class="arc-managed-s3" id="arc-managed-s3" hidden><label>Bucket<input name="bucket" autocomplete="off"></label><label>Access Key ID<input name="access_key" autocomplete="off"></label><label>Secret Access Key<input name="secret_key" type="password" autocomplete="new-password"></label><label>Região<input name="region" value="<?=arc_h(arc_cloud_provider_config($pdo,'s3')['region'])?>"></label><label>Prefixo opcional<input name="prefix"></label><button class="button primary" type="button" data-managed-s3-save>Salvar conexão S3</button></div><?php endif;?>
<?php endif;?></div>
<?php endforeach;?></div>
<p id="arc-managed-feedback" role="status" aria-live="polite"></p>
</section>

<?php elseif($key==='smtp'):?>
<div class="arc-config-extra"><h3>Teste de envio</h3><span class="arc-page-help"><button type="button" class="arc-icon-action" data-open-dialog="page-help-2" aria-label="Saiba mais sobre esta área" title="Saiba mais"><i class="fa fa-info" aria-hidden="true"></i></button></span><dialog id="page-help-2" class="arc-service-dialog"><header class="arc-service-heading"><h2>Saiba mais</h2><button type="button" class="arc-icon-action" data-close-dialog aria-label="Fechar"><i class="fa fa-times" aria-hidden="true"></i></button></header><p>Salve as credenciais antes de testar. O teste utiliza autenticação SMTP com TLS e validação de certificado.</p></dialog><form method="post" action="<?=arc_h(arc_workspace_base_url())?>admin/configuracoes.php"><input type="hidden" name="_csrf" value="<?=arc_h(arc_csrf_token())?>"><input type="hidden" name="tab" value="smtp"><label>E-mail de destino<input type="email" name="test_email" id="arc-smtp-test-email" placeholder="seuemail@dominio.com"></label><button type="submit" name="action" value="smtp_test" class="button secondary" formnovalidate><i class="fa fa-paper-plane"></i> Enviar e-mail de teste</button></form><small>O envio automático em todos os módulos depende de cada rotina utilizar a função SMTP central.</small></div>
<?php elseif($key==='login'):?>
<div class="arc-config-extra"><h3><i class="fa fa-google"></i> Login com Google</h3>
<p>O login Google utiliza credenciais OAuth próprias, diferentes das credenciais do Google Drive. Ative a opção acima e salve o Client ID e o Client Secret no mesmo formulário.</p>
<ol><li>Acesse <a href="https://console.cloud.google.com/auth/clients" target="_blank" rel="noopener noreferrer">Google Cloud Console — clientes OAuth</a>.</li><li>Configure a tela de consentimento e crie um cliente do tipo <strong>Aplicativo da Web</strong>.</li><li>Em <strong>URIs de redirecionamento autorizados</strong>, cole exatamente o endereço abaixo. O domínio precisa usar HTTPS.</li><li>Cadastre o Client ID e o Client Secret acima, salve e teste o botão “Entrar com Google” em uma janela privada.</li></ol>
<div class="arc-api-return"><code><?=arc_h($redirect)?></code><button type="button" class="button secondary" data-copy-uri="<?=arc_h($redirect)?>">Copiar URL de retorno</button></div>
<?php if(($values['platform_google_enabled']??'0')==='1' && ($values['platform_google_client_id']??'')!=='' && arc_platform_decrypt($values['platform_google_client_secret']??'')===''):?><p role="alert">O Client Secret do Google não está disponível ou não pôde ser decifrado. Salve novamente o segredo emitido no Google Cloud Console.</p><?php endif;?>
</div>
<?php elseif($key==='manutencao'):?>
<section class="arc-config-extra" aria-labelledby="arc-retention-heading"><h3 id="arc-retention-heading">Histórico de medições e alertas</h3><p class="arc-config-context">Define por quantos dias as amostras e alertas de rede são mantidos. A limpeza desses registros é aplicada pelo backend ao receber novas medições de cada sessão; não altera a frequência de monitoramento dos agentes.</p>
<form method="post" action="<?=arc_h($base)?>admin/configuracoes.php?tab=manutencao#manutencao"><input type="hidden" name="_csrf" value="<?=arc_h(arc_csrf_token())?>"><input type="hidden" name="action" value="save_settings"><input type="hidden" name="tab" value="retencao"><label>Retenção de medições e alertas (dias)<input type="number" min="1" max="3650" name="retention_days" required value="<?=arc_h(arc_platform_setting($pdo,'retention_days','90'))?>"></label><button class="button secondary" type="submit"><i class="fa fa-save"></i> Salvar retenção</button></form></section>
<div class="arc-config-extra"><form method="post" enctype="multipart/form-data" action="<?=arc_h(arc_workspace_base_url())?>admin/configuracoes.php"><input type="hidden" name="_csrf" value="<?=arc_h(arc_csrf_token())?>"><input type="hidden" name="tab" value="manutencao"><input type="hidden" name="action" value="upload_maintenance_image"><label>Importar imagem de manutenção (PNG, JPG ou WebP, até 1,5 MB)<input type="file" name="maintenance_image" accept="image/png,image/jpeg,image/webp" required></label><button class="button secondary" type="submit"><i class="fa fa-upload"></i> Importar imagem</button></form></div>
<div class="arc-config-extra"><span class="arc-page-help"><button type="button" class="arc-icon-action" data-open-dialog="page-help-4" aria-label="Saiba mais sobre esta área" title="Saiba mais"><i class="fa fa-info" aria-hidden="true"></i></button></span><dialog id="page-help-4" class="arc-service-dialog"><header class="arc-service-heading"><h2>Saiba mais</h2><button type="button" class="arc-icon-action" data-close-dialog aria-label="Fechar"><i class="fa fa-times" aria-hidden="true"></i></button></header><p>Durante a manutenção, visitantes recebem a tela pública de aviso com contagem regressiva. Administradores autenticados continuam com acesso às configurações. APIs dos agentes permanecem operacionais.</p></dialog></div>
<?php elseif($key==='log'):?>
<div class="arc-audit-console"><div class="arc-log-admin-actions"><button class="button secondary" type="button" id="arcLogCleanOpen"><i class="fa fa-trash-o"></i> Limpar todas as auditorias e logs</button><span>Limpeza total, backup e manutenção centralizados na Central de Manutenção.</span></div>

<div class="arc-log-commandbar">
<select id="arc-log-origin" aria-label="Origem dos registros" onchange="location.href='<?=arc_h(arc_workspace_base_url())?>admin/configuracoes.php?tab=log&source='+encodeURIComponent(this.value)+'#log'"><?php foreach($arcLogTables as $id=>$item):?><option value="<?=arc_h($id)?>" <?=$id===$arcLogChoice?'selected':''?>><?=arc_h($item['name'])?></option><?php endforeach;?></select>
<div class="arc-audit-search"><i class="fa fa-search"></i><input type="search" id="arc-audit-search" placeholder="Pesquisar registros..." aria-label="Pesquisar registros"></div>
<span class="arc-audit-count"><strong id="arc-visible-count"><?=count($arcLogRows)?></strong> / <?=number_format($arcLogTotal,0,',','.')?> registros</span>
<button type="button" id="arc-audit-delete-selected" class="button secondary" disabled title="Excluir registros selecionados"><i class="fa fa-trash-o"></i><span>Excluir seleção</span></button>
<button type="button" id="arc-audit-reset" class="button secondary" title="Limpar filtros"><i class="fa fa-filter"></i><span>Limpar filtros</span></button>
<a class="button secondary" title="Atualizar registros" href="<?=arc_h(arc_workspace_base_url())?>admin/configuracoes.php?tab=log&amp;source=<?=urlencode($arcLogChoice)?>#log"><i class="fa fa-refresh"></i></a>
</div>
<?php if($arcLogError!==''):?><p class="arc-settings-error"><?=arc_h($arcLogError)?></p><?php elseif(!$arcLogRows):?><p class="arc-audit-empty">Nenhum registro encontrado nesta origem.</p><?php else:?>
<div class="arc-audit-grid-wrap"><table class="arc-responsive-table arc-audit-grid" id="arc-audit-grid"><thead><tr><th><input type="checkbox" id="arc-audit-all" aria-label="Selecionar todos os registros visíveis"></th><th><button type="button" data-sort="id">Nº <i class="fa fa-sort"></i></button></th><th><button type="button" data-sort="date">Data e hora <i class="fa fa-sort"></i></button></th><th><button type="button" data-sort="event">O que aconteceu <i class="fa fa-sort"></i></button></th><th><button type="button" data-sort="description">Descrição <i class="fa fa-sort"></i></button></th><th>Ações</th></tr></thead><tbody>
<?php foreach($arcLogRows as $logRow):
 $logID=(int)($logRow['id']??0);$event=(string)($logRow['event_type']??$logRow['event']??$logRow['action']??'event');$label=arc_cfg_log_title($event);$desc=arc_cfg_log_description($logRow);$dt=(string)($logRow['created_at']??$logRow['attempted_at']??$logRow['updated_at']??'');$prettyDate=$dt!==''?(date('d/m/Y H:i:s',strtotime($dt)?:time())):'Não informada';
 $detail=arc_cfg_log_safe_data($logRow);$detail['Resumo']=$label;$detail['Explicação']=$desc;
 $deletable=$arcLogChoice==='account_audit'&&$logID>0;
?><tr data-log-id="<?=$logID?>" data-delete="<?=$deletable?'1':'0'?>" data-search="<?=arc_h(mb_strtolower($label.' '.$desc.' '.$prettyDate.' '.$logID,'UTF-8'))?>" data-id="<?=$logID?>" data-date="<?=arc_h($dt)?>" data-event="<?=arc_h(mb_strtolower($label,'UTF-8'))?>" data-description="<?=arc_h(mb_strtolower($desc,'UTF-8'))?>">
<td><input class="arc-audit-row-check" type="checkbox" value="<?=$logID?>" aria-label="Selecionar registro <?=$logID?>" <?=$deletable?'':'disabled title="Consulta somente leitura"'?>></td><td data-label="Nº"><strong>#<?=$logID?></strong></td><td data-label="Data e hora"><?=arc_h($prettyDate)?></td><td data-label="O que aconteceu"><span class="arc-audit-event"><i class="fa fa-history"></i> <?=arc_h($label)?></span></td><td class="arc-audit-desc" title="<?=arc_h($desc)?>" data-label="Descrição"><?=arc_h($desc)?></td><td data-label="Ações"><div class="arc-audit-row-actions"><button type="button" class="arc-audit-view" aria-label="Ver detalhes" data-detail="<?=arc_h(json_encode($detail,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP))?>"><i class="fa fa-eye"></i></button><button type="button" class="arc-audit-delete" title="Excluir registro" aria-label="Excluir registro" <?=$deletable?'':'disabled'?>><i class="fa fa-trash-o"></i></button></div></td></tr><?php endforeach;?></tbody></table></div>
<span class="arc-page-help"><button type="button" class="arc-icon-action" data-open-dialog="page-help-5" aria-label="Saiba mais sobre esta área" title="Saiba mais"><i class="fa fa-info" aria-hidden="true"></i></button></span><dialog id="page-help-5" class="arc-service-dialog"><header class="arc-service-heading"><h2>Saiba mais</h2><button type="button" class="arc-icon-action" data-close-dialog aria-label="Fechar"><i class="fa fa-times" aria-hidden="true"></i></button></header><p>Exibindo até 50 registros recentes. A busca e a ordenação atuam sobre os registros carregados. Exclusão disponível apenas para auditoria administrativa.</p></dialog>
<?php endif;?>
<form id="arc-audit-delete-form" method="post" action="<?=arc_h(arc_workspace_base_url())?>admin/configuracoes.php?tab=log&amp;source=<?=urlencode($arcLogChoice)?>#log" hidden><input type="hidden" name="_csrf" value="<?=arc_h(arc_csrf_token())?>"><input type="hidden" name="tab" value="log"><input type="hidden" name="action" value="delete_audit_rows"><input type="hidden" name="audit_ids" id="arc-audit-ids"><input type="hidden" name="confirm_delete" value="yes"></form>
</div>
<div class="arc-audit-modal" id="arc-log-clean-modal" hidden role="dialog" aria-modal="true" aria-labelledby="arc-log-clean-title"><div class="arc-audit-modal-inner"><header><h3 id="arc-log-clean-title">Limpar auditorias e logs</h3><button type="button" id="arcLogCleanClose" aria-label="Fechar"><i class="fa fa-times"></i></button></header><p>Selecione os históricos para exclusão. Nenhum cadastro ou rede será removido.</p><form id="arcLogCleanForm"><input name="_csrf" type="hidden" value="<?=arc_h(arc_csrf_token())?>"><input name="action" type="hidden" value="logs_purge"><input name="ajax" type="hidden" value="1"><div class="arc-log-clean-options"><?php foreach(arc_log_catalog($pdo) as $key=>$source):?><label><input type="checkbox" name="sources[]" value="<?=arc_h($key)?>"> <?=arc_h($source['name'])?></label><?php endforeach;?></div><p id="arcLogCleanResult" role="status" aria-live="polite"></p><footer><button type="button" class="button secondary" id="arcLogCleanCancel">Cancelar</button><button type="submit" class="button primary" id="arcLogCleanSubmit">Limpar selecionados</button></footer></form></div></div>
<div class="arc-audit-modal" id="arc-log-policy-modal" hidden role="dialog" aria-modal="true" aria-labelledby="arc-log-policy-title"><div class="arc-audit-modal-inner arc-log-policy-inner">
<header><div><small>CONFIGURAÇÕES GLOBAIS</small><h3 id="arc-log-policy-title">Políticas de logs</h3></div><button type="button" aria-label="Fechar" onclick="this.closest('.arc-audit-modal').hidden=true"><i class="fa fa-times"></i></button></header>
<div class="arc-log-policy-tabs"><button type="button" class="active" data-log-pane-button="record">O que registrar</button><button type="button" data-log-pane-button="retention">Retenção e espaço</button></div>
<form method="post" action="<?=arc_h(arc_workspace_base_url())?>admin/configuracoes.php?tab=log#log" class="arc-log-policy-form">
<input type="hidden" name="_csrf" value="<?=arc_h(arc_csrf_token())?>"><input type="hidden" name="tab" value="log"><input type="hidden" name="action" value="save_log_policies">
<?php $logPolicies=arc_log_policies($pdo);$logCatalog=arc_log_catalog($pdo);?>
<div data-log-pane="record"><p>Habilite ou desabilite a gravação nas rotinas de auditoria integradas. Tentativas de autenticação continuam registradas para proteção contra ataques; esta seleção não altera mecanismos de segurança.</p>
<?php foreach($logCatalog as $name=>$cfg):?><label class="arc-log-policy-row"><span><strong><?=arc_h($cfg['name'])?></strong><small><?=arc_h($name)?></small></span><span class="arc-log-field-opts"><label title="Guardar descrição dos eventos"><input type="checkbox" name="details[<?=arc_h($name)?>]" <?=($logPolicies[$name]['details']??true)?'checked':''?>> Detalhes</label><label title="Guardar hash do IP, sem armazenar o endereço completo"><input type="checkbox" name="ip[<?=arc_h($name)?>]" <?=($logPolicies[$name]['ip']??true)?'checked':''?>> Hash IP</label><label title="Registrar eventos de auditoria"><input type="checkbox" name="enabled[<?=arc_h($name)?>]" value="1" <?=($logPolicies[$name]['enabled']??true)?'checked':''?> <?=in_array($name,['login_attempts','account_login_attempts'],true)?'disabled':''?>> Ativo</label></span></label><?php endforeach;?>
</div>
<div data-log-pane="retention" hidden><p>0 dias significa sem retenção por prazo. 0 MB significa sem teto de espaço. O teto usa o tamanho aproximado da tabela, não o tamanho exato dos registros.</p>
<?php foreach($logCatalog as $name=>$cfg):?><div class="arc-log-retention-row"><strong><?=arc_h($cfg['name'])?></strong><label>Manter por dias<input type="number" min="0" max="3650" name="days[<?=arc_h($name)?>]" value="<?=(int)($logPolicies[$name]['days']??90)?>"></label><label>Teto aproximado (MB)<input type="number" min="0" max="2048" name="max_mb[<?=arc_h($name)?>]" value="<?=(int)($logPolicies[$name]['max_mb']??0)?>"></label></div><?php endforeach;?></div>
<footer><button type="button" class="button secondary" onclick="document.getElementById('arc-log-policy-modal').hidden=true">Cancelar</button><button type="submit" class="button primary"><i class="fa fa-save"></i> Salvar políticas</button></footer>
</form></div></div>
<div class="arc-audit-modal" id="arc-audit-modal" hidden role="dialog" aria-modal="true" aria-labelledby="arc-audit-modal-title"><div class="arc-audit-modal-inner"><header><div><small>REGISTRO DE AUDITORIA</small><h3 id="arc-audit-modal-title">Detalhes do evento</h3></div><button type="button" id="arc-audit-close" aria-label="Fechar"><i class="fa fa-times"></i></button></header><p>Informações registradas pelo sistema sobre o evento selecionado.</p><dl id="arc-audit-modal-fields"></dl><footer><button class="button secondary" type="button" id="arc-audit-close-bottom" aria-label="Fechar" title="Fechar"><i class="fa fa-arrow-right" aria-hidden="true"></i><span class="arc-action-label">Fechar</span></button></footer></div></div>
<?php endif;?>
</div></section>
<?php endforeach;?>
</div></main>
<style>
.arc-config-panel[hidden]{display:none!important}
.arc-config-compact-tabs{scroll-margin-top:12px}

.arc-config-v3{--setting-border:rgba(116,143,175,.20);font-size:12px}.arc-config-compact-tabs{display:flex;flex-wrap:wrap;gap:5px;padding:5px;border:1px solid var(--setting-border);border-radius:11px;margin-bottom:10px;background:color-mix(in srgb, var(--surface-alt) 5%, transparent)}
.arc-config-compact-tabs button{border:0;border-radius:7px;padding:7px 10px;font-size:11px;font-weight:650;background:transparent;color:inherit;cursor:pointer;white-space:nowrap;display:flex;gap:6px;align-items:center}.arc-config-compact-tabs button.active{background:var(--accent-color,#2878df);color:var(--text)}.arc-config-compact-tabs button:not(.active):hover{background:#5c89be}
.arc-config-v3 .arc-settings-form{padding:13px 15px;border-radius:11px}.arc-config-v3 .arc-settings-heading{margin-bottom:9px}.arc-config-v3 .arc-settings-heading h2{font-size:15px;margin:0}.arc-config-v3 .arc-settings-heading p{margin:3px 0;font-size:11px;opacity:.7}.arc-config-v3 .arc-settings-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px 12px}.arc-config-v3 .arc-settings-grid>label{display:flex;flex-direction:column;gap:4px;font-size:11px;font-weight:650;min-width:0}.arc-config-v3 .arc-settings-grid input:not([type=checkbox]){height:33px;min-width:0;width:100%;font-size:12px;padding:6px 9px;border-radius:7px}.arc-config-v3 .arc-settings-grid select{height:33px;min-width:0;width:100%;font-size:12px;padding:6px 9px;border-radius:7px}.arc-config-v3 .arc-settings-grid textarea{height:55px;min-width:0;width:100%;font-size:12px;padding:6px 9px;border-radius:7px;resize:vertical}.arc-config-v3 .arc-settings-grid input[type=color]{padding:3px;width:72px}.arc-config-v3 .arc-settings-grid .arc-full{grid-column:1/-1}.arc-config-v3 .arc-settings-toggle{display:flex!important;flex-direction:row!important;gap:8px;align-items:center;border:1px solid var(--setting-border);padding:9px;border-radius:8px}.arc-config-v3 .arc-settings-toggle span{display:flex;flex-direction:column}.arc-config-v3 .arc-settings-toggle small{font-size:10px;font-weight:400;opacity:.65}.arc-config-v3 .arc-config-actions{margin-top:10px;padding-top:9px;border-top:1px solid var(--setting-border)}.arc-config-v3 .arc-config-extra{border:1px solid var(--setting-border);border-radius:9px;padding:12px;margin-top:10px}.arc-config-v3 .arc-config-extra h3{font-size:13px;margin:0 0 5px}.arc-config-v3 .arc-config-extra p{font-size:11px}.arc-log-selector{display:flex;align-items:end;gap:10px;flex-wrap:wrap;margin:10px 0}.arc-log-selector label{min-width:195px}.arc-log-table-scroll{overflow:auto;max-height:350px;border:1px solid var(--setting-border);border-radius:8px}.arc-log-table{width:100%;border-collapse:collapse;font-size:10px}.arc-log-table th{padding:7px 9px;white-space:nowrap;text-align:left;border-bottom:1px solid var(--setting-border)}.arc-log-table td{padding:7px 9px;white-space:nowrap;text-align:left;border-bottom:1px solid var(--setting-border)}.arc-log-table thead{position:sticky;top:0;background:var(--bg,#fff)}

.arc-brand-settings{display:grid;grid-template-columns:155px 1fr;gap:16px;align-items:stretch}.arc-brand-upload{position:relative;border:1px dashed var(--setting-border);border-radius:12px;min-height:140px;display:flex;align-items:center;justify-content:center;cursor:pointer;overflow:hidden;background:color-mix(in srgb, var(--surface-alt) 5%, transparent)}.arc-brand-upload img{width:100%;height:106px;object-fit:contain;padding:12px}.arc-brand-upload input{position:absolute;width:1px;height:1px;opacity:0}.arc-brand-upload span{position:absolute;bottom:4px;left:4px;right:4px;text-align:center;border-radius:7px;background:color-mix(in srgb, var(--surface-alt) 84%, transparent);color:var(--text);padding:6px;font-size:11px}.arc-brand-main-fields{display:grid;grid-template-columns:repeat(3,minmax(0,1fr)) 75px;gap:9px}.arc-brand-main-fields label{font-size:11px;font-weight:650;display:flex;flex-direction:column;gap:5px;min-width:0}.arc-brand-main-fields input:not([type=color]){height:35px;padding:7px 9px;border-radius:7px;width:100%;min-width:0}.arc-color-circle{display:flex;width:36px;height:36px;overflow:hidden;border-radius:50%;border:2px solid color-mix(in srgb, var(--border) 35%, transparent);cursor:pointer}.arc-color-circle input{width:50px;height:50px;min-width:50px;border:0;padding:0;cursor:pointer;transform:translate(-7px,-7px)}
.arc-audit-console{min-width:0}.arc-log-commandbar{display:flex;align-items:center;gap:7px;flex-wrap:nowrap;overflow-x:auto;padding:8px;border:1px solid var(--setting-border);border-radius:10px;margin:8px 0 10px}.arc-log-commandbar select{width:175px;min-width:130px;height:33px}.arc-audit-search{position:relative;flex:1;min-width:125px}.arc-audit-search i{position:absolute;left:10px;top:10px;opacity:.65}.arc-audit-search input{width:100%;height:33px;padding-left:30px!important}.arc-audit-count{white-space:nowrap;font-size:11px}.arc-log-commandbar .button{height:33px;display:inline-flex;align-items:center;gap:5px;flex:none;font-size:11px;white-space:nowrap;padding:6px 9px}.arc-audit-grid-wrap{max-height:480px;overflow:auto;border:1px solid var(--setting-border);border-radius:10px}.arc-audit-grid{width:100%;border-collapse:collapse;font-size:11px}.arc-audit-grid thead{position:sticky;top:0;z-index:1;background:var(--bg,#f8fafc)}.arc-audit-grid td{padding:10px 9px;border-bottom:1px solid var(--setting-border);text-align:left}.arc-audit-grid th{padding:10px 9px;border-bottom:1px solid var(--setting-border);text-align:left;white-space:nowrap}.arc-audit-grid th button{cursor:pointer;background:none;border:0;color:inherit;font:inherit;font-weight:700;padding:0}.arc-audit-grid th button i{opacity:.5}.arc-audit-grid td:nth-child(3){white-space:nowrap}.arc-audit-grid tbody tr:hover{background:#4b82c8}.arc-audit-grid tbody tr.arc-selected{background:#2d78dc}.arc-audit-desc{min-width:180px;max-width:480px;line-height:1.45}.arc-audit-event{font-weight:650;white-space:nowrap}.arc-audit-event i{color:var(--accent-color,#2878df);margin-right:5px}.arc-audit-row-actions{display:flex;gap:5px}.arc-audit-row-actions button{width:28px;height:28px;border:1px solid var(--setting-border);background:transparent;color:inherit;cursor:pointer;border-radius:7px}.arc-audit-row-actions button:hover:not(:disabled){background:#4682dc}.arc-audit-row-actions button:disabled{opacity:.25;cursor:not-allowed}.arc-audit-hint{opacity:.68;font-size:10px;margin:9px 0}.arc-audit-empty{text-align:center;opacity:.65;padding:28px}.arc-audit-modal[hidden]{display:none!important}.arc-audit-modal{position:fixed;inset:0;z-index:9000;background:color-mix(in srgb, var(--surface) 65%, transparent);display:flex;align-items:center;justify-content:center;padding:15px}.arc-audit-modal-inner{width:min(580px,100%);max-height:90vh;overflow:auto;border-radius:13px;background:var(--bg,#fff);color:inherit;padding:18px;box-shadow:0 22px 70px rgba(0,0,0,.24)}.arc-audit-modal header{display:flex;justify-content:space-between;align-items:center;gap:15px}.arc-audit-modal header small{font-size:10px;opacity:.6}.arc-audit-modal h3{font-size:17px;margin:4px 0}.arc-audit-modal header button{border:0;background:transparent;color:inherit;cursor:pointer;font-size:17px}.arc-audit-modal-inner>p{font-size:12px;opacity:.75}.arc-audit-modal dl{display:grid;grid-template-columns:150px 1fr;gap:0;font-size:12px}.arc-audit-modal dd{margin:0;padding:10px;border-bottom:1px solid var(--setting-border);overflow-wrap:anywhere}.arc-audit-modal dt{margin:0;padding:10px;border-bottom:1px solid var(--setting-border);overflow-wrap:anywhere;font-weight:700;opacity:.7}.arc-audit-modal footer{display:flex;justify-content:flex-end;margin-top:14px}
@media(max-width:800px){.arc-config-v3 .arc-settings-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:760px){.arc-brand-settings{grid-template-columns:110px 1fr}.arc-brand-main-fields{grid-template-columns:repeat(2,minmax(0,1fr))}.arc-audit-grid th{padding:8px 6px}.arc-audit-grid td{padding:8px 6px}}@media(max-width:520px){.arc-config-v3 .arc-settings-grid{grid-template-columns:1fr}.arc-config-compact-tabs{flex-wrap:nowrap;overflow-x:auto}.arc-config-compact-tabs button{flex:none;padding:7px}.arc-config-v3 .arc-settings-form{padding:9px}}@media(max-width:490px){.arc-brand-main-fields{grid-template-columns:1fr}.arc-brand-settings{grid-template-columns:100px 1fr}.arc-audit-modal dl{grid-template-columns:1fr}.arc-audit-modal dt{border:0;padding-bottom:0}}@media(prefers-reduced-motion:reduce){.arc-config-v3 *{scroll-behavior:auto!important}}
.arc-log-clean-options{display:grid;gap:8px;margin:12px 0}.arc-log-clean-options label{display:flex;gap:9px;align-items:center;padding:10px;border:1px solid var(--setting-border);border-radius:8px}.arc-log-field-opts{display:flex;gap:9px;flex-wrap:wrap;font-size:11px}.arc-log-field-opts label{display:flex;gap:4px;align-items:center}.arc-log-settings-open{margin-left:auto;white-space:nowrap}.arc-log-admin-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:10px 0;font-size:11px}.arc-log-admin-actions span{opacity:.7}.arc-log-policy-inner{max-height:85vh;display:flex;flex-direction:column;gap:10px}.arc-log-policy-form{min-height:0;overflow:auto}.arc-log-policy-tabs{display:flex;border-bottom:1px solid var(--setting-border);gap:6px}.arc-log-policy-tabs button{border:0;background:transparent;color:inherit;padding:10px;cursor:pointer}.arc-log-policy-tabs button.active{border-bottom:2px solid var(--accent-color,#2878df);font-weight:700}.arc-log-policy-row,.arc-log-retention-row{display:flex;align-items:center;gap:10px;justify-content:space-between;border-bottom:1px solid var(--setting-border);padding:9px}.arc-log-policy-row small{display:block;opacity:.65}.arc-log-retention-row{flex-wrap:wrap}.arc-log-retention-row label{display:grid;font-size:11px;gap:4px}.arc-log-retention-row input{width:100px;padding:8px;background:var(--surface);color:inherit;border:1px solid var(--setting-border);border-radius:7px}.arc-log-policy-form footer{display:flex;justify-content:end;gap:8px;padding-top:12px}@media(max-width:650px){.arc-settings-heading{flex-wrap:wrap}.arc-log-field-opts{display:flex;gap:9px;flex-wrap:wrap;font-size:11px}.arc-log-field-opts label{display:flex;gap:4px;align-items:center}.arc-log-settings-open{margin-left:0}}

.arc-config-context{font-size:12px;line-height:1.6;opacity:.85;margin:12px 0}
/* APIs: serviços independentes, ajuda contextual e navegação direta. */
.arc-api-provider{border:1px solid var(--setting-border);border-radius:12px;padding:16px;margin:12px 0;background:var(--surface,transparent)}
.arc-api-provider{scroll-margin-top:24px}.arc-api-provider header{display:flex;align-items:center;gap:13px;margin-bottom:12px}.arc-api-provider h3{margin:0 0 5px;font-size:16px}.arc-api-provider p{margin:5px 0 9px;line-height:1.55;opacity:.85;font-size:12px}
.arc-api-logo{flex:none;width:43px;height:43px;display:grid;place-items:center;border-radius:10px;background:color-mix(in srgb,var(--accent-color,#2878df) 12%,transparent);font-size:23px;color:var(--text-color,inherit)}.arc-api-logo-aws{font-weight:850;font-size:19px;letter-spacing:-2px;color:#f59e0b}
.arc-api-return{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin:10px 0;background:color-mix(in srgb,var(--accent-color,#2878df) 5%,transparent);border:1px dashed var(--setting-border);border-radius:8px;padding:10px}.arc-api-return code{overflow-wrap:anywhere;font-size:12px;flex:1 1 260px}.arc-api-return span{flex-basis:100%;font-size:12px}
.arc-api-guide{margin:10px 0 14px;border:1px solid var(--setting-border);border-radius:9px;padding:11px 14px}.arc-api-guide h4{font-weight:700;font-size:12px;margin:0 0 6px}.arc-api-guide li,.arc-api-provider li{margin:7px 0;font-size:12px;line-height:1.5}.arc-api-guide a{display:inline-block;margin:5px 0}
.arc-api-group-form{margin:10px 0 0}.arc-api-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.arc-api-field{display:flex;flex-direction:column;min-width:0;gap:6px;font-size:12px}.arc-api-field input:not([type=checkbox]){width:100%;max-width:100%;min-width:0;height:37px;padding:7px 10px;border:1px solid var(--setting-border);border-radius:8px;background:var(--surface-alt,transparent);color:inherit}.arc-api-field input[type=checkbox]{width:20px;height:20px}.arc-api-field small{font-size:11px;opacity:.75;font-weight:400;line-height:1.4}.arc-api-field-help{font-size:12px;line-height:1.5;opacity:.8;margin:10px 0}.arc-api-group-form>.button{margin-top:13px}
@media(max-width:650px){.arc-api-fields{grid-template-columns:1fr}.arc-api-provider{padding:11px}}

/* Provedores em cartões compactos; configurações acessíveis exclusivamente por modal. */
.arc-managed-feedback{margin:12px 0 0;color:var(--text-color,inherit);font-weight:650}.arc-managed-drive{margin-top:22px;padding:16px;border-top:1px solid var(--setting-border)}.arc-managed-drive h3{margin:0 0 7px}.arc-managed-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}.arc-managed-item{display:grid;align-content:start;gap:10px;padding:13px;border:1px solid var(--setting-border);border-radius:10px}.arc-managed-status{color:#36c384}.arc-managed-s3{display:grid;gap:8px}.arc-managed-s3[hidden]{display:none}.arc-managed-s3 label{display:grid;gap:4px}.arc-managed-s3 input{width:100%;min-width:0;background:var(--surface);color:inherit;border:1px solid var(--setting-border);border-radius:6px;padding:9px}
.arc-drive-grid{display:flex;align-items:stretch;flex-wrap:wrap;gap:12px;margin:16px 0}
.arc-drive-tile{display:inline-flex;align-items:center;gap:9px;min-width:160px;min-height:68px;padding:10px 14px;border:1px solid var(--setting-border);border-radius:12px;background:var(--surface,transparent);color:inherit;font-weight:700;cursor:pointer;text-align:left}
.arc-drive-tile:hover,.arc-drive-tile:focus-visible{border-color:var(--accent-color,#48b5d9);outline-offset:2px}
.arc-drive-tile.arc-drive-active{border-color:#22aa69;background:rgba(34,170,105,.13);box-shadow:inset 0 0 0 1px rgba(34,170,105,.23)}
.arc-drive-tile.arc-drive-inactive{opacity:.47;filter:saturate(.45)}.arc-drive-tile.arc-drive-inactive:hover,.arc-drive-tile.arc-drive-inactive:focus-visible{opacity:1;filter:none}
.arc-drive-check{margin-left:auto;color:#22b573}
.arc-drive-dialog{max-width:min(760px,calc(100vw - 24px));width:100%;max-height:calc(100dvh - 32px);overflow:auto;padding:20px;color:inherit}
.arc-drive-dialog::backdrop{background:rgba(3,8,17,.7)}
.arc-drive-dialog .arc-service-heading{display:flex;align-items:center;gap:10px;padding-right:35px}
.arc-drive-dialog .arc-drive-close{position:absolute;right:14px;top:12px;border:0;background:transparent;color:inherit;font-size:19px;cursor:pointer}
@media(max-width:520px){.arc-drive-tile{flex:1 1 calc(50% - 8px);min-width:130px}.arc-drive-dialog{padding:12px}}
</style>
<script>
(function(){
document.querySelectorAll('[data-copy-uri]').forEach(button=>button.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(button.dataset.copyUri);button.textContent='Copiado';setTimeout(()=>button.textContent='Copiar',1500)}catch{button.textContent='Selecione e copie o endereço acima';}}));
const tabs=[...document.querySelectorAll('[data-config-tab]')],panels=[...document.querySelectorAll('[data-config-panel]')];
function show(key){tabs.forEach(t=>{let on=t.dataset.configTab===key;t.classList.toggle('active',on);t.setAttribute('aria-selected',String(on));});panels.forEach(p=>{let on=p.dataset.configPanel===key;p.classList.toggle('active',on);p.hidden=!on;p.querySelectorAll('input,select,textarea').forEach(el=>{el.disabled=!on;});});const canonical=new URL('admin/configuracoes.php',document.baseURI);
const parameters=new URLSearchParams(location.search);
parameters.set('tab',key);
canonical.search=parameters.toString();
canonical.hash=key;
if(location.pathname+location.search+location.hash!==canonical.pathname+canonical.search+canonical.hash){
  history.replaceState(null,'',canonical.pathname+canonical.search+canonical.hash);
}}
tabs.forEach(t=>t.addEventListener('click',()=>show(t.dataset.configTab)));
document.querySelectorAll('[data-open-settings]').forEach(b=>b.addEventListener('click',()=>{show(b.dataset.openSettings);window.scrollTo({top:0,behavior:'smooth'});}));
const filter=document.getElementById('arc-db-filter');if(filter)filter.addEventListener('input',()=>{const query=filter.value.toLocaleLowerCase('pt-BR').trim();document.querySelectorAll('[data-db-search]').forEach(t=>{t.hidden=query!==''&&!t.dataset.dbSearch.includes(query);});});
const remap=key=>key==='nuvem'?'apis':(key==='seguranca'?'login':(key==='monitoramento'?'manutencao':key));const hash=remap(location.hash.substring(1));const requested=remap(new URLSearchParams(location.search).get('tab'));const preferred=tabs.some(t=>t.dataset.configTab===hash)?hash:(tabs.some(t=>t.dataset.configTab===requested)?requested:'dados');show(preferred);

document.querySelectorAll('[data-log-pane-button]').forEach(button=>button.addEventListener('click',()=>{const id=button.dataset.logPaneButton;document.querySelectorAll('[data-log-pane-button]').forEach(b=>b.classList.toggle('active',b===button));document.querySelectorAll('[data-log-pane]').forEach(p=>p.hidden=p.dataset.logPane!==id)}));
const cleanModal=document.getElementById('arc-log-clean-modal'),cleanForm=document.getElementById('arcLogCleanForm');if(cleanModal&&cleanForm){const close=()=>cleanModal.hidden=true;document.getElementById('arcLogCleanOpen')?.addEventListener('click',()=>{cleanForm.reset();document.getElementById('arcLogCleanResult').textContent='';cleanModal.hidden=false});document.getElementById('arcLogCleanClose').onclick=document.getElementById('arcLogCleanCancel').onclick=close;cleanModal.addEventListener('click',e=>{if(e.target===cleanModal)close()});document.addEventListener('keydown',e=>{if(e.key==='Escape')close()});cleanForm.addEventListener('submit',async e=>{e.preventDefault();const output=document.getElementById('arcLogCleanResult'),button=document.getElementById('arcLogCleanSubmit');if(!cleanForm.querySelector('[name="sources[]"]:checked')){output.textContent='Selecione pelo menos uma categoria.';return}button.disabled=true;output.textContent='Limpando…';try{const r=await fetch(<?=json_encode(arc_workspace_base_url().'admin/clean.php')?>,{method:'POST',credentials:'same-origin',body:new FormData(cleanForm)});const contentType=r.headers.get('content-type')||'';if(!contentType.toLowerCase().includes('application/json'))throw Error('Servidor retornou uma resposta inesperada (HTTP '+r.status+'). Confira a sessao administrativa e o log do PHP.');const j=await r.json();if(!r.ok||!j.ok)throw Error(j.message||'Falha ao limpar');output.textContent=j.message;setTimeout(()=>location.reload(),1300)}catch(error){output.textContent='Erro: '+error.message}finally{button.disabled=false}})}
const policyModal=document.getElementById('arc-log-policy-modal');if(policyModal){policyModal.addEventListener('click',e=>{if(e.target===policyModal)policyModal.hidden=true});document.addEventListener('keydown',e=>{if(e.key==='Escape')policyModal.hidden=true})}
const logoInput=document.getElementById('arc-logo-file');if(logoInput)logoInput.addEventListener('change',()=>{const file=logoInput.files&&logoInput.files[0];if(!file)return;if(!['image/png','image/jpeg','image/webp'].includes(file.type)||file.size>1572864){alert('Envie PNG, JPG ou WebP de até 1,5 MB.');logoInput.value='';return;}const img=document.getElementById('arc-logo-preview');if(img){const previous=img.dataset.preview;if(previous)URL.revokeObjectURL(previous);img.dataset.preview=URL.createObjectURL(file);img.src=img.dataset.preview;}});
const grid=document.getElementById('arc-audit-grid'), modal=document.getElementById('arc-audit-modal');
if(grid){const body=grid.tBodies[0],search=document.getElementById('arc-audit-search'),all=document.getElementById('arc-audit-all'),bulk=document.getElementById('arc-audit-delete-selected'),count=document.getElementById('arc-visible-count');let direction={};
const visible=()=>[...body.rows].filter(r=>!r.hidden);
const refresh=()=>{const q=(search.value||'').toLocaleLowerCase('pt-BR').trim();[...body.rows].forEach(r=>r.hidden=q!==''&&!r.dataset.search.includes(q));count.textContent=visible().length;const checks=[...body.querySelectorAll('.arc-audit-row-check:not(:disabled)')];bulk.disabled=!checks.some(c=>c.checked);all.checked=visible().length>0&&visible().filter(r=>!r.querySelector('.arc-audit-row-check').disabled).every(r=>r.querySelector('.arc-audit-row-check').checked);};
search.addEventListener('input',refresh);document.getElementById('arc-audit-reset').addEventListener('click',()=>{search.value='';[...body.querySelectorAll('.arc-audit-row-check')].forEach(c=>{c.checked=false;c.closest('tr').classList.remove('arc-selected')});refresh()});
all.addEventListener('change',()=>{visible().forEach(r=>{const c=r.querySelector('.arc-audit-row-check');if(!c.disabled){c.checked=all.checked;r.classList.toggle('arc-selected',c.checked)}});refresh()});
body.addEventListener('change',e=>{if(e.target.matches('.arc-audit-row-check')){e.target.closest('tr').classList.toggle('arc-selected',e.target.checked);refresh()}});
const requestDeletion=ids=>{if(!ids.length)return;if(!confirm('Excluir definitivamente '+ids.length+' registro(s) de auditoria? Esta ação não pode ser desfeita.'))return;document.getElementById('arc-audit-ids').value=ids.join(',');document.getElementById('arc-audit-delete-form').submit()};
bulk.addEventListener('click',()=>requestDeletion([...body.querySelectorAll('.arc-audit-row-check:checked')].map(c=>c.value)));
body.addEventListener('click',e=>{const view=e.target.closest('.arc-audit-view');if(view){let detail;try{detail=JSON.parse(view.dataset.detail)}catch{return}const fields=document.getElementById('arc-audit-modal-fields');fields.replaceChildren();Object.entries(detail).forEach(([key,value])=>{const dt=document.createElement('dt'),dd=document.createElement('dd');dt.textContent=key;dd.textContent=String(value);fields.append(dt,dd)});modal.hidden=false;document.getElementById('arc-audit-close').focus();return}const del=e.target.closest('.arc-audit-delete');if(del&&!del.disabled)requestDeletion([del.closest('tr').dataset.logId])});
grid.querySelectorAll('th [data-sort]').forEach(btn=>btn.addEventListener('click',()=>{const key=btn.dataset.sort;direction[key]=!direction[key];const rows=[...body.rows];rows.sort((a,b)=>{const aa=a.dataset[key]||'',bb=b.dataset[key]||'';return (key==='id'?Number(aa)-Number(bb):aa.localeCompare(bb,'pt-BR',{numeric:true}))*(direction[key]?1:-1)});rows.forEach(r=>body.appendChild(r));}));refresh();}
if(modal){const close=()=>modal.hidden=true;document.getElementById('arc-audit-close').addEventListener('click',close);document.getElementById('arc-audit-close-bottom').addEventListener('click',close);modal.addEventListener('click',e=>{if(e.target===modal)close()});document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!modal.hidden)close()})}
})();
</script>
<script>
/* Conexão OAuth e S3 de armazenamento gerenciado, separada das credenciais de aplicativos. */
(()=>{
const endpoint=<?=json_encode($base.'api/cloud.php',JSON_UNESCAPED_SLASHES)?>,csrf=<?=json_encode(arc_csrf_token())?>;
const previousGroup=<?=json_encode($_SERVER['REQUEST_METHOD']==='POST' && (string)($_POST['action']??'')==='save_group'?(string)($_POST['group']??''):'')?>;
if(previousGroup.startsWith('cloud_'))document.getElementById('arc-drive-'+previousGroup.slice(6))?.showModal();
const output=document.getElementById('arc-managed-feedback');if(!output)return;
const send=async(action,data={})=>{const response=await fetch(endpoint+'?action=platform-'+action,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(data)});const result=await response.json().catch(()=>({}));if(!response.ok||!result.ok)throw Error(result.error||'Não foi possível conectar a conta.');return result};
const run=async fn=>{output.textContent='Processando a solicitação...';try{await fn()}catch(e){output.textContent=e.message}};
document.querySelectorAll('[data-managed-connect]').forEach(btn=>btn.addEventListener('click',()=>{
 if(btn.dataset.managedConnect==='s3'){const block=document.getElementById('arc-managed-s3');if(block)block.hidden=!block.hidden;return;}
 run(async()=>{const result=await send('connect',{provider:btn.dataset.managedConnect});if(result.url)location.assign(result.url)});
}));
document.querySelectorAll('[data-managed-disconnect]').forEach(btn=>btn.addEventListener('click',()=>{
 if(!confirm('Desconectar o Drive da plataforma? Os backups que usam esta conta serão pausados. Os arquivos na nuvem serão preservados.'))return;
 run(async()=>{await send('disconnect',{connection_id:Number(btn.dataset.managedDisconnect),confirmed:true});location.reload()});
}));
document.querySelectorAll('[data-managed-s3-save]').forEach(btn=>btn.addEventListener('click',()=>run(async()=>{
 const form=btn.closest('.arc-managed-s3'),credentials={};form.querySelectorAll('input[name]').forEach(input=>credentials[input.name]=input.value);
 await send('connect',{provider:'s3',credentials});form.querySelector('[name=secret_key]').value='';location.reload();
})));
})();
</script>
<?php require dirname(__DIR__).'/includes/footer.php'; ?>
