<?php
/* Caminho: admin/clean.php | v1.1.0 - Central de manutenção e recuperação */
declare(strict_types=1);
require_once __DIR__ . '/../includes/error-logging.php';

require dirname(__DIR__).'/includes/admin-actions.php';
require_once dirname(__DIR__).'/includes/storage-policy.php';
if(!arc_is_admin()){http_response_code(403);exit('Acesso restrito.');}
$pdo=arc_db();$csrf=arc_csrf_token();$notice='';$error='';
function arc_clean_password(PDO $pdo,string $password): void {
    if($password==='')throw new RuntimeException('Digite a senha administrativa.');
    $q=$pdo->prepare('SELECT password_hash FROM '.arc_table('admins').' WHERE id=? LIMIT 1');
    $q->execute([arc_admin_id()]);
    if(!arc_admin_pw_check($password,(string)$q->fetchColumn()))throw new RuntimeException('Senha administrativa incorreta.');
}
function arc_clean_schema(PDO $pdo): array {
    $q=$pdo->prepare('SELECT TABLE_NAME, COALESCE(TABLE_ROWS,0) AS rows_est,COALESCE(DATA_LENGTH+INDEX_LENGTH,0) AS bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE ? ORDER BY TABLE_NAME');
    $q->execute([arc_prefix().'%']);return $q->fetchAll(PDO::FETCH_ASSOC);
}
$tables=arc_clean_schema($pdo);$logs=arc_log_catalog($pdo);$storagePolicy=arc_storage_policy($pdo);$storageCatalog=arc_storage_catalog();
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!hash_equals($csrf,(string)($_POST['_csrf']??'')))throw new RuntimeException('Sessão expirada. Atualize a página.');
        $action=(string)($_POST['action']??'');
        $ajax=(string)($_POST['ajax']??'')==='1';
        if($action==='backup'){
            // Exportação SQL em fluxo, sem persistir cópia pública no servidor.
            @set_time_limit(0);header('Content-Type: application/sql; charset=utf-8');header('Content-Disposition: attachment; filename="arcoll-backup-'.gmdate('Ymd-His').'.sql"');
            header('Cache-Control: no-store, no-cache, must-revalidate');header('X-Content-Type-Options: nosniff');
            echo "-- Backup Arcoll / ".gmdate('c')."\n-- CONFIDENCIAL: contém credenciais e dados privados.\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n";
            foreach($tables as $t){
                $name=(string)$t['TABLE_NAME'];if(!preg_match('/^[a-zA-Z0-9_]+$/D',$name))continue;
                $quoted='`'.$name.'`';$create=$pdo->query('SHOW CREATE TABLE '.$quoted)->fetch(PDO::FETCH_NUM);
                if(!$create)continue;
                echo "\nDROP TABLE IF EXISTS $quoted;\n".$create[1].";\n";
                $stmt=$pdo->query('SELECT * FROM '.$quoted);
                while($row=$stmt->fetch(PDO::FETCH_ASSOC)){
                    $fields=implode(',',array_map(fn($x)=>'`'.str_replace('`','``',$x).'`',array_keys($row)));
                    $values=[];foreach($row as $value)$values[]=$value===null?'NULL':$pdo->quote((string)$value);
                    echo 'INSERT INTO '.$quoted.' ('.$fields.') VALUES ('.implode(',',$values).');' . "\n";
                }
                flush();
            }
            echo "SET FOREIGN_KEY_CHECKS=1;\n";exit;
        }
        if($action==='storage_policy_save'){
            $new=arc_storage_validate_post($_POST);
            arc_platform_save_setting($pdo,'platform_storage_policy',json_encode($new,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            $storagePolicy=arc_storage_policy($pdo);
            $notice='Politica global salva. Agende o cron para executar automaticamente.';
        }elseif($action==='storage_preview'||$action==='storage_execute'){
            $execute=$action==='storage_execute';
            if($execute&&!$storagePolicy['enabled'])throw new RuntimeException('Ative a politica antes de executar a limpeza.');
            $out=arc_storage_run($pdo,$execute,false);
            if($execute&&function_exists('arc_support_files_cleanup'))arc_support_files_cleanup($pdo);
            if($ajax)arc_json(['ok'=>true,'message'=>$execute?'Lote executado.':'Simulacao concluida.','report'=>$out]);
            $notice=$execute?'Lote executado.':'Simulacao concluida.';
        }elseif($action==='logs_purge'){
            // Autorização pela sessão administrativa e token CSRF.
            $selected=$_POST['sources']??[];
            if(!is_array($selected)||!$selected)throw new RuntimeException('Selecione pelo menos uma origem.');
            $sum=0;$report=[];$hasMore=false;
            foreach(array_unique($selected) as $key){
                if(!is_string($key)||!isset($logs[$key]))throw new RuntimeException('Origem inválida.');
                $table='`'.$logs[$key]['table'].'`';$count=0;
                do{$q=$pdo->prepare("DELETE FROM $table LIMIT 1000");$q->execute();$n=$q->rowCount();$count+=$n;}while($n===1000 && $count<200000);
                $report[]=$key.': '.$count;$sum+=$count;if($count>=200000)$hasMore=true;
            }
            $notice=$sum.' registros removidos: '.implode('; ',$report).'.'.($hasMore?' Execute novamente para continuar.':'');
        }elseif($action==='retention_run'){
            $report=arc_log_cleanup($pdo,null,1000);
            $notice='Políticas de retenção executadas: '.implode('; ',array_map(fn($k,$v)=>$k.': '.$v,array_keys($report),array_values($report))).'.';
        }elseif($action==='cache'){
            clearstatcache(true);if(function_exists('opcache_reset'))@opcache_reset();
            $notice='Cache de estatísticas e OPcache reiniciado quando permitido pelo servidor.';
        }else throw new RuntimeException('Ação não disponível.');
        if($ajax)arc_json(['ok'=>true,'message'=>$notice]);
    }catch(Throwable $e){arc_log_error('Admin clean: '.$e->getMessage());$error=$e->getMessage();if((string)($_POST['ajax']??'')==='1')arc_json(['ok'=>false,'message'=>$error],400);}
}
$ARCMode='app';$ARCPageTitle='Central de manutenção';$ARCActiveNav='platform_settings';
require dirname(__DIR__).'/includes/header.php';require dirname(__DIR__).'/includes/sidebar.php';
?>
<style>
.arc-clean{padding:12px;display:grid;gap:13px;max-width:1250px;margin:auto}.arc-clean h1{font-size:20px;margin:4px 0}.arc-clean h2{font-size:15px;margin:0 0 9px}.arc-clean p{font-size:12px;line-height:1.5;color:var(--muted)}.arc-clean-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}.arc-clean-card{padding:17px;border:1px solid var(--border);background:var(--surface);border-radius:13px;min-width:0}.arc-clean-form{display:grid;gap:9px}.arc-clean-form label{font-size:12px;display:grid;gap:5px}.arc-clean-form input:not([type=checkbox]){width:100%;padding:9px;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:inherit}.arc-clean-form button{justify-self:start}.arc-clean-sources{display:grid;grid-template-columns:1fr 1fr;gap:8px}.arc-clean-sources label{display:flex;align-items:center;gap:6px;border:1px solid var(--border);padding:10px;border-radius:7px}.arc-clean-scroll{max-height:310px;overflow:auto}.arc-clean-table{width:100%;font-size:12px;border-collapse:collapse}.arc-clean-table th,.arc-clean-table td{padding:9px;border-bottom:1px solid var(--border);text-align:left}.arc-clean-alert{padding:12px;border:1px solid var(--border);border-radius:9px}.arc-clean-warning{border-color:#cf903a}.arc-clean-error{border-color:#d33}@media(max-width:760px){.arc-clean-grid{grid-template-columns:1fr}.arc-clean-sources{grid-template-columns:1fr}}
.arc-clean-modal[hidden]{display:none!important}.arc-clean-modal{position:fixed;inset:0;z-index:30050;background:#071326bb;display:flex;justify-content:center;align-items:center;padding:16px}.arc-clean-dialog{width:min(490px,100%);max-height:90dvh;display:flex;flex-direction:column;border-radius:14px;background:var(--surface);color:var(--text);border:1px solid var(--border);box-shadow:0 24px 80px #0007}.arc-clean-dialog header{display:flex;justify-content:space-between;align-items:center;padding:17px;border-bottom:1px solid var(--border)}.arc-clean-dialog header h2{margin:0}.arc-clean-dialog header button{background:none;color:inherit;border:0;cursor:pointer}.arc-clean-dialog-body{padding:16px;overflow:auto}.arc-clean-dialog footer{display:flex;gap:8px;justify-content:flex-end;margin-top:14px}.arc-clean-dialog #arcCleanResult{min-height:18px}.arc-clean-dialog .arc-clean-sources{margin:12px 0}#arcCleanSubmit:disabled{opacity:.5;cursor:wait}
</style>
<main class="app-main"><div class="page-content arc-clean">
<header><h1><i class="fa fa-database"></i> Central de manutenção</h1><p>Gerencie registros de auditoria, armazenamento e exportação de segurança. Ações disponíveis para administradores autenticados. Limpezas irreversíveis exigem confirmação no modal.</p></header>
<?php if($notice!==''):?><div class="arc-clean-alert" role="status"><?=arc_h($notice)?></div><?php endif;?>
<?php if($error!==''):?><div class="arc-clean-alert arc-clean-error" role="alert"><?=arc_h($error)?></div><?php endif;?>
<div class="arc-clean-grid">
<section class="arc-clean-card"><h2><i class="fa fa-download"></i> Backup completo do banco</h2><p>Exportação SQL das tabelas diretamente para o navegador. O arquivo contém informações confidenciais.</p><form method="post"><input type="hidden" name="_csrf" value="<?=arc_h($csrf)?>"><input type="hidden" name="action" value="backup"><button class="button primary" type="submit"><i class="fa fa-database"></i> Gerar backup SQL</button></form></section>
<section class="arc-clean-card"><h2><i class="fa fa-clock-o"></i> Retenção automática</h2><p>Execute as regras configuradas. Remoção limitada a 1.000 registros por categoria por execução.</p><button type="button" class="button secondary" data-clean-action="retention_run"><i class="fa fa-clock-o"></i> Executar retenção</button></section>
<section class="arc-clean-card"><h2><i class="fa fa-trash-o"></i> Auditorias e logs</h2><p>Escolha no modal quais históricos excluir. Cadastros, redes e credenciais não são apagados.</p><button type="button" class="button secondary" data-open-clean="logs"><i class="fa fa-trash"></i> Limpar registros</button></section>
<section class="arc-clean-card"><h2><i class="fa fa-refresh"></i> Cache e recuperação</h2><p>Reinicie os caches internos disponíveis ao PHP. Dados de clientes e gestores são preservados.</p><button type="button" class="button secondary" data-open-clean="cache"><i class="fa fa-refresh"></i> Limpar cache</button><div class="arc-clean-alert arc-clean-warning" style="margin-top:15px"><strong>Restauração / modo de fábrica</strong><p>Por segurança, a limpeza total da instalação e a restauração integral do banco devem ser realizadas em ambiente de manutenção, com backup verificado.</p></div></section>
</div>
<div class="arc-clean-modal" id="arcCleanModal" hidden role="dialog" aria-modal="true" aria-labelledby="arcCleanTitle"><section class="arc-clean-dialog"><header><h2 id="arcCleanTitle">Limpeza</h2><button type="button" id="arcCleanClose" aria-label="Fechar"><i class="fa fa-times"></i></button></header><div class="arc-clean-dialog-body"><p id="arcCleanDescription"></p><form id="arcCleanForm"><input type="hidden" name="_csrf" value="<?=arc_h($csrf)?>"><input type="hidden" name="ajax" value="1"><input type="hidden" id="arcCleanAction" name="action" value="logs_purge"><div id="arcCleanSources" class="arc-clean-sources"><?php foreach($logs as $key=>$cfg):?><label><input type="checkbox" name="sources[]" value="<?=arc_h($key)?>"> <?=arc_h($cfg['name'])?></label><?php endforeach;?></div><p id="arcCleanResult" role="status" aria-live="polite"></p><footer><button type="button" class="button secondary" id="arcCleanCancel">Cancelar</button><button type="submit" class="button primary" id="arcCleanSubmit">Limpar selecionados</button></footer></form></div></section></div>
<section class="arc-clean-card" style="grid-column:1/-1" id="arcSmartStorage">
 <h2><i class="fa fa-database"></i> Armazenamento inteligente</h2>
 <p>Configure por categoria o prazo de retenção. A rotina remove somente registros antigos e encerrados, em pequenos lotes. Dados cadastrais, inventário atual, credenciais e tarefas abertas são preservados. O limite em MB é um indicador de alerta, não uma exclusão forçada.</p>
 <form id="arcStorageForm" class="arc-clean-form" method="post">
  <input type="hidden" name="_csrf" value="<?=arc_h($csrf)?>"><input type="hidden" name="ajax" value="1"><input type="hidden" name="action" value="storage_policy_save">
  <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center"><label style="display:flex;align-items:center;gap:7px"><input type="checkbox" name="enabled" value="1" <?=!empty($storagePolicy['enabled'])?'checked':''?>> Ativar limpeza automática</label><label style="display:flex;align-items:center;gap:7px"><input type="checkbox" name="files" value="1" <?=!empty($storagePolicy['files'])?'checked':''?>> Arquivos temporários seguros</label><label>Dias dos temporários <input style="max-width:80px" type="number" name="file_days" min="1" max="30" value="<?= (int)$storagePolicy['file_days']?>"></label><label>Resultados do explorador (dias) <input style="max-width:80px" type="number" name="browser_payload_days" min="1" max="30" value="<?= (int)$storagePolicy['browser_payload_days']?>"></label><label>Intervalo de métricas (segundos) <input style="max-width:90px" type="number" name="metrics_interval" min="10" max="300" value="<?= (int)$storagePolicy['metrics_interval']?>"></label></div>
  <div class="arc-clean-scroll" style="max-height:none;overflow-x:auto"><table class="arc-clean-table"><thead><tr><th>Ativar</th><th>Origem</th><th>Reter (dias)</th><th>Alerta (MB)</th></tr></thead><tbody>
  <?php foreach($storageCatalog as $key=>$item):$cfg=$storagePolicy['categories'][$key];?><tr><td><input type="checkbox" name="category_enabled[<?=arc_h($key)?>]" value="1" <?=$cfg['enabled']?'checked':''?>></td><td><?=arc_h($item['label'])?></td><td><input type="number" name="days[<?=arc_h($key)?>]" min="2" max="3650" value="<?= (int)$cfg['days']?>" style="max-width:90px"></td><td><input type="number" name="max_mb[<?=arc_h($key)?>]" min="0" max="102400" value="<?= (int)$cfg['max_mb']?>" style="max-width:90px"></td></tr><?php endforeach;?>
  </tbody></table></div>
  <div style="display:flex;flex-wrap:wrap;gap:8px"><button type="submit" class="button primary"><i class="fa fa-save"></i> Salvar política</button><button type="button" class="button secondary" id="arcStoragePreview">Simular limpeza</button><button type="button" class="button secondary" id="arcStorageExecute">Executar 1 lote</button></div>
  <p id="arcStorageFeedback" role="status" aria-live="polite"></p>
 </form>
 <div id="arcStorageReport" class="arc-clean-scroll" hidden></div>
 <p>Última execução automática registrada: <strong><?=arc_h(arc_platform_setting($pdo,'platform_storage_cron_last_run','Não registrada (verifique o cron do cPanel)'))?></strong></p>
 <p>Agendamento no cPanel: <code>php /CAMINHO/maintenance/storage-retention.php --execute</code>. Consulte o arquivo INSTALAR_ARMAZENAMENTO.txt do ZIP.</p>
</section>
<section class="arc-clean-card"><h2>Inventário de tabelas do sistema</h2><p>Tamanhos físicos aproximados fornecidos pelo MySQL. Não equivalem exatamente ao espaço liberável por exclusão.</p><div class="arc-clean-scroll"><table class="arc-clean-table"><thead><tr><th>Tabela</th><th>Linhas (estimativa)</th><th>Armazenamento</th></tr></thead><tbody><?php foreach($tables as $t):?><tr><td><?=arc_h($t['TABLE_NAME'])?></td><td><?=number_format((float)$t['rows_est'],0,',','.')?></td><td><?=number_format((float)$t['bytes']/1048576,2,',','.')?> MB</td></tr><?php endforeach;?></tbody></table></div></section>
</div></main>
<script>
(()=>{'use strict';const modal=document.getElementById('arcCleanModal'),form=document.getElementById('arcCleanForm'),result=document.getElementById('arcCleanResult'),act=document.getElementById('arcCleanAction'),sources=document.getElementById('arcCleanSources'),submit=document.getElementById('arcCleanSubmit'),title=document.getElementById('arcCleanTitle'),desc=document.getElementById('arcCleanDescription');let busy=false;function open(type){act.value=type==='cache'?'cache':'logs_purge';title.textContent=type==='cache'?'Limpar cache':'Limpar auditorias e logs';desc.textContent=type==='cache'?'Reiniciar cache do PHP e OPcache quando autorizado pela hospedagem. Nenhum cadastro será alterado.':'Marque as origens desejadas. Os históricos selecionados serão excluídos definitivamente.';sources.hidden=type==='cache';submit.textContent=type==='cache'?'Limpar cache':'Limpar selecionados';result.textContent='';form.reset();modal.hidden=false;submit.focus()}function close(){if(!busy)modal.hidden=true}document.querySelectorAll('[data-open-clean]').forEach(b=>b.addEventListener('click',()=>open(b.dataset.openClean)));document.getElementById('arcCleanClose').onclick=document.getElementById('arcCleanCancel').onclick=close;modal.addEventListener('click',e=>{if(e.target===modal)close()});document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!modal.hidden)close()});async function run(formData){busy=true;submit.disabled=true;result.textContent='Executando…';try{const res=await fetch(location.pathname,{method:'POST',credentials:'same-origin',headers:{'Accept':'application/json'},body:formData});const contentType=res.headers.get('content-type')||'';if(!contentType.toLowerCase().includes('application/json'))throw Error('Resposta inesperada do servidor (HTTP '+res.status+'). Verifique a sessao e o log do PHP.');let json=await res.json();if(!res.ok||!json.ok)throw Error(json.message||'Falha ao executar');result.textContent=json.message||'Operação concluída.';if(act.value==='logs_purge')setTimeout(()=>location.reload(),1200)}catch(e){result.textContent='Erro: '+e.message}finally{busy=false;submit.disabled=false}}form.addEventListener('submit',e=>{e.preventDefault();if(act.value==='logs_purge'&&!form.querySelector('input[name="sources[]"]:checked')){result.textContent='Marque pelo menos uma categoria.';return}run(new FormData(form))});document.querySelectorAll('[data-clean-action]').forEach(b=>b.addEventListener('click',async()=>{b.disabled=true;const fd=new FormData();fd.set('_csrf',<?=json_encode($csrf)?>);fd.set('ajax','1');fd.set('action',b.dataset.cleanAction);try{let r=await fetch(location.pathname,{method:'POST',body:fd,credentials:'same-origin'});if(!(r.headers.get('content-type')||'').toLowerCase().includes('application/json'))throw Error('Resposta inesperada do servidor (HTTP '+r.status+').');let j=await r.json();if(!r.ok||!j.ok)throw Error(j.message||'Falha na operacao');alert(j.message||'Operação concluída.')}catch(e){alert('Falha: '+e.message)}finally{b.disabled=false}}))})();
</script>
<script>
(()=>{'use strict';const f=document.getElementById('arcStorageForm'),m=document.getElementById('arcStorageFeedback'),r=document.getElementById('arcStorageReport');if(!f)return;
async function call(action){const b=[...f.querySelectorAll('button')];b.forEach(x=>x.disabled=true);m.textContent='Processando...';
 try{const fd=action==='storage_policy_save'?new FormData(f):new FormData();fd.set('_csrf',f.querySelector('[name="_csrf"]').value);fd.set('ajax','1');fd.set('action',action);
 const response=await fetch(location.pathname,{method:'POST',body:fd,credentials:'same-origin',headers:{'Accept':'application/json'}});
 if(!(response.headers.get('content-type')||'').includes('application/json'))throw Error('Servidor respondeu HTML (HTTP '+response.status+').');const data=await response.json();if(!response.ok||!data.ok)throw Error(data.message||'Falha');m.textContent=data.message||'Concluído.';
 if(data.report){r.hidden=false;const entries=Object.entries(data.report.tables||{});const safe=v=>String(v).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;');r.innerHTML='<table class="arc-clean-table"><thead><tr><th>Categoria</th><th>Elegíveis</th><th>Removidos</th><th>Tamanho físico</th></tr></thead><tbody>'+entries.map(([k,x])=>'<tr><td>'+safe(x.label)+'</td><td>'+Number(x.eligible).toLocaleString('pt-BR')+'</td><td>'+Number(x.deleted).toLocaleString('pt-BR')+'</td><td>'+(x.size_bytes/1048576).toFixed(2)+' MB</td></tr>').join('')+'</tbody></table><p>Arquivos temporários elegíveis: '+Number(data.report.files.count)+'; removidos: '+Number(data.report.files.deleted||0)+'.</p>';}
 }catch(e){m.textContent='Erro: '+e.message}finally{b.forEach(x=>x.disabled=false)}}
 f.addEventListener('submit',e=>{e.preventDefault();call('storage_policy_save')});document.getElementById('arcStoragePreview').addEventListener('click',()=>call('storage_preview'));document.getElementById('arcStorageExecute').addEventListener('click',()=>call('storage_execute'));
})();
</script>
<?php require dirname(__DIR__).'/includes/footer.php';?>
