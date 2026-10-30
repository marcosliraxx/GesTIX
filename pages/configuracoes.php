<?php
/* Caminho: pages/configuracoes.php */
declare(strict_types=1);
require dirname(__DIR__).'/includes/admin-actions.php';
if(!arc_is_admin()){header('Location: '.arc_workspace_base_url().'index.php',true,302);exit;}
$pdo=arc_db();
$notice='';$error='';
$tabs=['dados'=>'Dados do sistema','login'=>'Login e acesso','smtp'=>'E-mail SMTP','apis'=>'Integrações','log'=>'Auditoria e logs','manutencao'=>'Manutenção'];
$schema=[
 'dados'=>[],

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
 'platform_google_client_id'=>['Google OAuth Client ID','text',''],
 'platform_google_client_secret'=>['Google OAuth Client Secret','secret',''],
 'platform_webhook_url'=>['Webhook de eventos (HTTPS)','url',''],
 'platform_api_external_enabled'=>['Habilitar integrações externas','bool','0']],
 'login'=>[
 'platform_registration_enabled'=>['Permitir cadastro público','bool','1'],
 'platform_google_enabled'=>['Permitir login com Google','bool','0'],
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
 'platform_login_max_attempts'=>['Tentativas de login por IP (15 minutos)','number:3:30','5'],
 'platform_session_idle_minutes'=>['Inatividade máxima (minutos)','number:5:1440','120']],
 'manutencao'=>[
 'platform_maintenance_enabled'=>['Ativar manutenção pública','bool','0'],
 'platform_maintenance_title'=>['Título da manutenção','text','Plataforma em manutenção'],
 'platform_maintenance_message'=>['Mensagem aos visitantes','textarea','Estamos aprimorando nossos serviços. Retornaremos em breve.'],
 'platform_maintenance_until'=>['Previsão de conclusão','datetime',''],
 'platform_maintenance_image'=>['Imagem da manutenção (URL HTTPS ou arquivo)','url','']],
];
$values=[];
foreach($schema as $items)foreach($items as $key=>$meta)$values[$key]=arc_platform_setting($pdo,$key,$meta[2]);
$tab=(string)($_POST['tab']??$_GET['tab']??'dados');
if(!isset($tabs[$tab]))$tab='dados';
function arc_cfg_validate(string $key,string $value,string $type):string {
 if(strlen($value)>2048)throw new RuntimeException('Campo muito longo: '.$key);
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
   if($action==='save'){
    if(!isset($schema[$tab]))throw new RuntimeException('Aba inválida.');
    $changes=[];
    foreach($schema[$tab] as $key=>$meta){
     $type=$meta[1];$value=$type==='bool'?(isset($_POST[$key])?'1':'0'):trim((string)($_POST[$key]??''));
     if($type==='secret'&&$value==='')continue;
     if($type==='secret')$value=arc_platform_encrypt($value);
     else $value=arc_cfg_validate($key,$value,$type);
     $changes[$key]=$value;
    }
    $pdo->beginTransaction();
    try{foreach($changes as $key=>$value)arc_platform_save_setting($pdo,$key,$value);$pdo->commit();}catch(Throwable $e){$pdo->rollBack();throw $e;}
    foreach($changes as $key=>$value)$values[$key]=$value;
    $notice='Configurações salvas e aplicadas.';
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
 $titles=['network_created'=>'Rede cadastrada','network_deleted'=>'Rede excluída','network_updated'=>'Rede atualizada','network_agent_requested'=>'Solicitação de novo agente','network_agent_approved'=>'Agente autorizado','network_agent_rejected'=>'Agente recusado','agent_command_queued'=>'Comando enviado ao agente','password_changed'=>'Senha alterada','admin.login'=>'Entrada de administrador','admin.logout'=>'Saída do administrador','login_success'=>'Acesso autorizado','login_failed'=>'Falha ao entrar','agent_online'=>'Agente conectado','agent_offline'=>'Agente desconectado'];
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
<div class="arc-settings-heading"><i class="fa fa-sliders"></i><div><h2><?=arc_h($label)?></h2><p>Alterações armazenadas na configuração central da plataforma.</p></div></div>
<?php if(in_array($key,['dados','apis'],true)):
$setting=static fn(string $name,string $default='')=>arc_platform_setting($pdo,$name,$default);
$groups=[
 'dados'=>[
 ['site_name','Nome do sistema','text','Arcoll Speed Connection'],
 ['tagline','Descrição institucional','text',''],
 ['domain_label','Domínio exibido','text',''],
 ['accent_color','Cor de destaque','color','#22d3ee']],
 'apis'=>[
 ['measurement_endpoint','Endpoint de medição','select','cloudflare'],
 ['monitor_interval_ms','Intervalo padrão (ms)','number','1000'],
 ['retention_days','Retenção de medições (dias)','number','90']],
];
?>
<form method="post" enctype="multipart/form-data" action="<?=arc_h(arc_workspace_base_url())?>pages/configuracoes.php" class="arc-config-section-form">
<input type="hidden" name="_csrf" value="<?=arc_h(arc_csrf_token())?>">
<input type="hidden" name="action" value="save_settings">
<input type="hidden" name="tab" value="<?=arc_h($key)?>">
<?php if($key==='dados'):?>
<div class="arc-brand-settings">
<label class="arc-brand-upload" title="Clique para mudar a logo"><input id="arc-logo-file" type="file" name="logo" accept="image/png,image/jpeg,image/webp"><img id="arc-logo-preview" src="<?=arc_h(arc_workspace_base_url())?>api/brand-logo.php" alt="Logo atual"><span><i class="fa fa-camera"></i> Alterar logo</span></label>
<div class="arc-brand-fields"><div class="arc-brand-main-fields">
<label>Nome do sistema<input name="site_name" type="text" required value="<?=arc_h($setting('site_name','Arcoll Speed Connection'))?>"></label>
<label>Descrição<input name="tagline" type="text" required value="<?=arc_h($setting('tagline',''))?>"></label>
<label>Domínio<input name="domain_label" type="text" value="<?=arc_h($setting('domain_label',''))?>"></label>
<label class="arc-color-field">Cor de destaque <span class="arc-color-circle"><input type="color" name="accent_color" value="<?=arc_h($setting('accent_color','#22d3ee'))?>" aria-label="Escolher cor de destaque"></span></label>
</div></div></div>
<?php else:?>
<div class="arc-settings-grid"><?php foreach($groups[$key] as [$field,$title,$type,$default]):?>
<label><?=arc_h($title)?><?php if($type==='select'):?><select name="<?=arc_h($field)?>"><option value="cloudflare" <?=$setting($field,$default)==='cloudflare'?'selected':''?>>Cloudflare</option><option value="google" <?=$setting($field,$default)==='google'?'selected':''?>>Google</option></select><?php else:?><input name="<?=arc_h($field)?>" type="<?=arc_h($type)?>" value="<?=arc_h($setting($field,$default))?>" <?= $field==='monitor_interval_ms'?'min="250" max="30000"':'' ?> <?= $field==='retention_days'?'min="1" max="3650"':'' ?>><?php endif;?></label><?php endforeach;?></div>
<?php endif;?>
<div class="arc-config-actions"><button class="button primary" type="submit"><i class="fa fa-save"></i> Salvar <?=arc_h($label)?></button></div>
</form>
<?php endif;?>
<?php if(!in_array($key,['dados'],true) && isset($schema[$key])):?>
<form method="post" action="<?=arc_h(arc_workspace_base_url())?>pages/configuracoes.php" class="arc-config-section-form">
<input type="hidden" name="_csrf" value="<?=arc_h(arc_csrf_token())?>">
<input type="hidden" name="tab" value="<?=arc_h($key)?>">
<input type="hidden" name="action" value="save">
<div class="arc-settings-grid">
<?php foreach($schema[$key] as $field=>$meta):
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

<?php elseif($key==='apis'):?>
<div class="arc-config-extra"><h3>Google OAuth 2.0</h3><p>Crie as credenciais em <a href="https://console.cloud.google.com/auth/clients" target="_blank" rel="noopener noreferrer">Google Cloud Console</a>. Configure a URI de retorno autorizada:</p><code><?=arc_h($redirect)?></code><span class="arc-page-help"><button type="button" class="arc-icon-action" data-open-dialog="page-help-1" aria-label="Saiba mais sobre esta área" title="Saiba mais"><i class="fa fa-info" aria-hidden="true"></i></button></span><dialog id="page-help-1" class="arc-service-dialog"><header class="arc-service-heading"><h2>Saiba mais</h2><button type="button" class="arc-icon-action" data-close-dialog aria-label="Fechar"><i class="fa fa-times" aria-hidden="true"></i></button></header><p>O segredo é armazenado criptografado. Integrações externas só devem ser ativadas após a implementação dos respectivos endpoints.</p></dialog></div>
<?php elseif($key==='smtp'):?>
<div class="arc-config-extra"><h3>Teste de envio</h3><span class="arc-page-help"><button type="button" class="arc-icon-action" data-open-dialog="page-help-2" aria-label="Saiba mais sobre esta área" title="Saiba mais"><i class="fa fa-info" aria-hidden="true"></i></button></span><dialog id="page-help-2" class="arc-service-dialog"><header class="arc-service-heading"><h2>Saiba mais</h2><button type="button" class="arc-icon-action" data-close-dialog aria-label="Fechar"><i class="fa fa-times" aria-hidden="true"></i></button></header><p>Salve as credenciais antes de testar. O teste utiliza autenticação SMTP com TLS e validação de certificado.</p></dialog><form method="post" action="<?=arc_h(arc_workspace_base_url())?>pages/configuracoes.php"><input type="hidden" name="_csrf" value="<?=arc_h(arc_csrf_token())?>"><input type="hidden" name="tab" value="smtp"><label>E-mail de destino<input type="email" name="test_email" id="arc-smtp-test-email" placeholder="seuemail@dominio.com"></label><button type="submit" name="action" value="smtp_test" class="button secondary" formnovalidate><i class="fa fa-paper-plane"></i> Enviar e-mail de teste</button></form><small>O envio automático em todos os módulos depende de cada rotina utilizar a função SMTP central.</small></div>
<?php elseif($key==='login'):?>
<div class="arc-config-extra"><h3>Autenticação Google</h3><span class="arc-page-help"><button type="button" class="arc-icon-action" data-open-dialog="page-help-3" aria-label="Saiba mais sobre esta área" title="Saiba mais"><i class="fa fa-info" aria-hidden="true"></i></button></span><dialog id="page-help-3" class="arc-service-dialog"><header class="arc-service-heading"><h2>Saiba mais</h2><button type="button" class="arc-icon-action" data-close-dialog aria-label="Fechar"><i class="fa fa-times" aria-hidden="true"></i></button></header><p>Configure o Client ID e Client Secret na aba APIs. O formulário de login utiliza a posição e os textos definidos nesta aba.</p></dialog></div>
<?php elseif($key==='manutencao'):?>
<div class="arc-config-extra"><form method="post" enctype="multipart/form-data" action="<?=arc_h(arc_workspace_base_url())?>pages/configuracoes.php"><input type="hidden" name="_csrf" value="<?=arc_h(arc_csrf_token())?>"><input type="hidden" name="tab" value="manutencao"><input type="hidden" name="action" value="upload_maintenance_image"><label>Importar imagem de manutenção (PNG, JPG ou WebP, até 1,5 MB)<input type="file" name="maintenance_image" accept="image/png,image/jpeg,image/webp" required></label><button class="button secondary" type="submit"><i class="fa fa-upload"></i> Importar imagem</button></form></div>
<div class="arc-config-extra"><span class="arc-page-help"><button type="button" class="arc-icon-action" data-open-dialog="page-help-4" aria-label="Saiba mais sobre esta área" title="Saiba mais"><i class="fa fa-info" aria-hidden="true"></i></button></span><dialog id="page-help-4" class="arc-service-dialog"><header class="arc-service-heading"><h2>Saiba mais</h2><button type="button" class="arc-icon-action" data-close-dialog aria-label="Fechar"><i class="fa fa-times" aria-hidden="true"></i></button></header><p>Durante a manutenção, visitantes recebem a tela pública de aviso com contagem regressiva. Administradores autenticados continuam com acesso às configurações. APIs dos agentes permanecem operacionais.</p></dialog></div>
<?php elseif($key==='log'):?>
<div class="arc-audit-console">
<div class="arc-log-commandbar">
<select id="arc-log-origin" aria-label="Origem dos registros" onchange="location.href='<?=arc_h(arc_workspace_base_url())?>pages/configuracoes.php?tab=log&source='+encodeURIComponent(this.value)+'#log'"><?php foreach($arcLogTables as $id=>$item):?><option value="<?=arc_h($id)?>" <?=$id===$arcLogChoice?'selected':''?>><?=arc_h($item['name'])?></option><?php endforeach;?></select>
<div class="arc-audit-search"><i class="fa fa-search"></i><input type="search" id="arc-audit-search" placeholder="Pesquisar registros..." aria-label="Pesquisar registros"></div>
<span class="arc-audit-count"><strong id="arc-visible-count"><?=count($arcLogRows)?></strong> / <?=number_format($arcLogTotal,0,',','.')?> registros</span>
<button type="button" id="arc-audit-delete-selected" class="button secondary" disabled title="Excluir registros selecionados"><i class="fa fa-trash-o"></i><span>Excluir seleção</span></button>
<button type="button" id="arc-audit-reset" class="button secondary" title="Limpar filtros"><i class="fa fa-filter"></i><span>Limpar filtros</span></button>
<a class="button secondary" title="Atualizar registros" href="<?=arc_h(arc_workspace_base_url())?>pages/configuracoes.php?tab=log&amp;source=<?=urlencode($arcLogChoice)?>#log"><i class="fa fa-refresh"></i></a>
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
<form id="arc-audit-delete-form" method="post" action="<?=arc_h(arc_workspace_base_url())?>pages/configuracoes.php?tab=log&amp;source=<?=urlencode($arcLogChoice)?>#log" hidden><input type="hidden" name="_csrf" value="<?=arc_h(arc_csrf_token())?>"><input type="hidden" name="tab" value="log"><input type="hidden" name="action" value="delete_audit_rows"><input type="hidden" name="audit_ids" id="arc-audit-ids"><input type="hidden" name="confirm_delete" value="yes"></form>
</div>
<div class="arc-audit-modal" id="arc-audit-modal" hidden role="dialog" aria-modal="true" aria-labelledby="arc-audit-modal-title"><div class="arc-audit-modal-inner"><header><div><small>REGISTRO DE AUDITORIA</small><h3 id="arc-audit-modal-title">Detalhes do evento</h3></div><button type="button" id="arc-audit-close" aria-label="Fechar"><i class="fa fa-times"></i></button></header><p>Informações registradas pelo sistema sobre o evento selecionado.</p><dl id="arc-audit-modal-fields"></dl><footer><button class="button secondary" type="button" id="arc-audit-close-bottom" aria-label="Fechar" title="Fechar"><i class="fa fa-arrow-right" aria-hidden="true"></i><span class="arc-action-label">Fechar</span></button></footer></div></div>
<?php endif;?>
</div></section>
<?php endforeach;?>
</div></main>
<style>
.arc-config-v3{--setting-border:rgba(116,143,175,.20);font-size:12px}.arc-config-compact-tabs{display:flex;flex-wrap:wrap;gap:5px;padding:5px;border:1px solid var(--setting-border);border-radius:11px;margin-bottom:10px;background:color-mix(in srgb, var(--surface-alt) 5%, transparent)}
.arc-config-compact-tabs button{border:0;border-radius:7px;padding:7px 10px;font-size:11px;font-weight:650;background:transparent;color:inherit;cursor:pointer;white-space:nowrap;display:flex;gap:6px;align-items:center}.arc-config-compact-tabs button.active{background:var(--accent-color,#2878df);color:var(--text)}.arc-config-compact-tabs button:not(.active):hover{background:#5c89be}
.arc-config-v3 .arc-settings-form{padding:13px 15px;border-radius:11px}.arc-config-v3 .arc-settings-heading{margin-bottom:9px}.arc-config-v3 .arc-settings-heading h2{font-size:15px;margin:0}.arc-config-v3 .arc-settings-heading p{margin:3px 0;font-size:11px;opacity:.7}.arc-config-v3 .arc-settings-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px 12px}.arc-config-v3 .arc-settings-grid>label{display:flex;flex-direction:column;gap:4px;font-size:11px;font-weight:650;min-width:0}.arc-config-v3 .arc-settings-grid input:not([type=checkbox]){height:33px;min-width:0;width:100%;font-size:12px;padding:6px 9px;border-radius:7px}.arc-config-v3 .arc-settings-grid select{height:33px;min-width:0;width:100%;font-size:12px;padding:6px 9px;border-radius:7px}.arc-config-v3 .arc-settings-grid textarea{height:55px;min-width:0;width:100%;font-size:12px;padding:6px 9px;border-radius:7px;resize:vertical}.arc-config-v3 .arc-settings-grid input[type=color]{padding:3px;width:72px}.arc-config-v3 .arc-settings-grid .arc-full{grid-column:1/-1}.arc-config-v3 .arc-settings-toggle{display:flex!important;flex-direction:row!important;gap:8px;align-items:center;border:1px solid var(--setting-border);padding:9px;border-radius:8px}.arc-config-v3 .arc-settings-toggle span{display:flex;flex-direction:column}.arc-config-v3 .arc-settings-toggle small{font-size:10px;font-weight:400;opacity:.65}.arc-config-v3 .arc-config-actions{margin-top:10px;padding-top:9px;border-top:1px solid var(--setting-border)}.arc-config-v3 .arc-config-extra{border:1px solid var(--setting-border);border-radius:9px;padding:12px;margin-top:10px}.arc-config-v3 .arc-config-extra h3{font-size:13px;margin:0 0 5px}.arc-config-v3 .arc-config-extra p{font-size:11px}.arc-log-selector{display:flex;align-items:end;gap:10px;flex-wrap:wrap;margin:10px 0}.arc-log-selector label{min-width:195px}.arc-log-table-scroll{overflow:auto;max-height:350px;border:1px solid var(--setting-border);border-radius:8px}.arc-log-table{width:100%;border-collapse:collapse;font-size:10px}.arc-log-table th{padding:7px 9px;white-space:nowrap;text-align:left;border-bottom:1px solid var(--setting-border)}.arc-log-table td{padding:7px 9px;white-space:nowrap;text-align:left;border-bottom:1px solid var(--setting-border)}.arc-log-table thead{position:sticky;top:0;background:var(--card-bg,#fff)}

.arc-brand-settings{display:grid;grid-template-columns:155px 1fr;gap:16px;align-items:stretch}.arc-brand-upload{position:relative;border:1px dashed var(--setting-border);border-radius:12px;min-height:140px;display:flex;align-items:center;justify-content:center;cursor:pointer;overflow:hidden;background:color-mix(in srgb, var(--surface-alt) 5%, transparent)}.arc-brand-upload img{width:100%;height:106px;object-fit:contain;padding:12px}.arc-brand-upload input{position:absolute;width:1px;height:1px;opacity:0}.arc-brand-upload span{position:absolute;bottom:4px;left:4px;right:4px;text-align:center;border-radius:7px;background:color-mix(in srgb, var(--surface-alt) 84%, transparent);color:var(--text);padding:6px;font-size:11px}.arc-brand-main-fields{display:grid;grid-template-columns:repeat(3,minmax(0,1fr)) 75px;gap:9px}.arc-brand-main-fields label{font-size:11px;font-weight:650;display:flex;flex-direction:column;gap:5px;min-width:0}.arc-brand-main-fields input:not([type=color]){height:35px;padding:7px 9px;border-radius:7px;width:100%;min-width:0}.arc-color-circle{display:flex;width:36px;height:36px;overflow:hidden;border-radius:50%;border:2px solid color-mix(in srgb, var(--border) 35%, transparent);cursor:pointer}.arc-color-circle input{width:50px;height:50px;min-width:50px;border:0;padding:0;cursor:pointer;transform:translate(-7px,-7px)}
.arc-audit-console{min-width:0}.arc-log-commandbar{display:flex;align-items:center;gap:7px;flex-wrap:nowrap;overflow-x:auto;padding:8px;border:1px solid var(--setting-border);border-radius:10px;margin:8px 0 10px}.arc-log-commandbar select{width:175px;min-width:130px;height:33px}.arc-audit-search{position:relative;flex:1;min-width:125px}.arc-audit-search i{position:absolute;left:10px;top:10px;opacity:.65}.arc-audit-search input{width:100%;height:33px;padding-left:30px!important}.arc-audit-count{white-space:nowrap;font-size:11px}.arc-log-commandbar .button{height:33px;display:inline-flex;align-items:center;gap:5px;flex:none;font-size:11px;white-space:nowrap;padding:6px 9px}.arc-audit-grid-wrap{max-height:480px;overflow:auto;border:1px solid var(--setting-border);border-radius:10px}.arc-audit-grid{width:100%;border-collapse:collapse;font-size:11px}.arc-audit-grid thead{position:sticky;top:0;z-index:1;background:var(--card-bg,#f8fafc)}.arc-audit-grid td{padding:10px 9px;border-bottom:1px solid var(--setting-border);text-align:left}.arc-audit-grid th{padding:10px 9px;border-bottom:1px solid var(--setting-border);text-align:left;white-space:nowrap}.arc-audit-grid th button{cursor:pointer;background:none;border:0;color:inherit;font:inherit;font-weight:700;padding:0}.arc-audit-grid th button i{opacity:.5}.arc-audit-grid td:nth-child(3){white-space:nowrap}.arc-audit-grid tbody tr:hover{background:#4b82c8}.arc-audit-grid tbody tr.arc-selected{background:#2d78dc}.arc-audit-desc{min-width:180px;max-width:480px;line-height:1.45}.arc-audit-event{font-weight:650;white-space:nowrap}.arc-audit-event i{color:var(--accent-color,#2878df);margin-right:5px}.arc-audit-row-actions{display:flex;gap:5px}.arc-audit-row-actions button{width:28px;height:28px;border:1px solid var(--setting-border);background:transparent;color:inherit;cursor:pointer;border-radius:7px}.arc-audit-row-actions button:hover:not(:disabled){background:#4682dc}.arc-audit-row-actions button:disabled{opacity:.25;cursor:not-allowed}.arc-audit-hint{opacity:.68;font-size:10px;margin:9px 0}.arc-audit-empty{text-align:center;opacity:.65;padding:28px}.arc-audit-modal[hidden]{display:none!important}.arc-audit-modal{position:fixed;inset:0;z-index:9000;background:color-mix(in srgb, var(--surface) 65%, transparent);display:flex;align-items:center;justify-content:center;padding:15px}.arc-audit-modal-inner{width:min(580px,100%);max-height:90vh;overflow:auto;border-radius:13px;background:var(--card-bg,#fff);color:inherit;padding:18px;box-shadow:0 22px 70px rgba(0,0,0,.24)}.arc-audit-modal header{display:flex;justify-content:space-between;align-items:center;gap:15px}.arc-audit-modal header small{font-size:10px;opacity:.6}.arc-audit-modal h3{font-size:17px;margin:4px 0}.arc-audit-modal header button{border:0;background:transparent;color:inherit;cursor:pointer;font-size:17px}.arc-audit-modal-inner>p{font-size:12px;opacity:.75}.arc-audit-modal dl{display:grid;grid-template-columns:150px 1fr;gap:0;font-size:12px}.arc-audit-modal dd{margin:0;padding:10px;border-bottom:1px solid var(--setting-border);overflow-wrap:anywhere}.arc-audit-modal dt{margin:0;padding:10px;border-bottom:1px solid var(--setting-border);overflow-wrap:anywhere;font-weight:700;opacity:.7}.arc-audit-modal footer{display:flex;justify-content:flex-end;margin-top:14px}
@media(max-width:800px){.arc-config-v3 .arc-settings-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:760px){.arc-brand-settings{grid-template-columns:110px 1fr}.arc-brand-main-fields{grid-template-columns:repeat(2,minmax(0,1fr))}.arc-audit-grid th{padding:8px 6px}.arc-audit-grid td{padding:8px 6px}}@media(max-width:520px){.arc-config-v3 .arc-settings-grid{grid-template-columns:1fr}.arc-config-compact-tabs{flex-wrap:nowrap;overflow-x:auto}.arc-config-compact-tabs button{flex:none;padding:7px}.arc-config-v3 .arc-settings-form{padding:9px}}@media(max-width:490px){.arc-brand-main-fields{grid-template-columns:1fr}.arc-brand-settings{grid-template-columns:100px 1fr}.arc-audit-modal dl{grid-template-columns:1fr}.arc-audit-modal dt{border:0;padding-bottom:0}}@media(prefers-reduced-motion:reduce){.arc-config-v3 *{scroll-behavior:auto!important}}
</style>
<script>
(function(){
const tabs=[...document.querySelectorAll('[data-config-tab]')],panels=[...document.querySelectorAll('[data-config-panel]')];
function show(key){tabs.forEach(t=>{let on=t.dataset.configTab===key;t.classList.toggle('active',on);t.setAttribute('aria-selected',String(on));});panels.forEach(p=>{let on=p.dataset.configPanel===key;p.classList.toggle('active',on);p.hidden=!on;p.querySelectorAll('input,select,textarea').forEach(el=>{el.disabled=!on;});});const canonical=new URL('pages/configuracoes.php',document.baseURI);
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
const hash=location.hash.substring(1);const requested=new URLSearchParams(location.search).get('tab');const preferred=tabs.some(t=>t.dataset.configTab===hash)?hash:(tabs.some(t=>t.dataset.configTab===requested)?requested:'dados');show(preferred);

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
<?php require dirname(__DIR__).'/includes/footer.php'; ?>
