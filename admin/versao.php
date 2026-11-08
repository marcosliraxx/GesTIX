<?php
/* Caminho: admin/versao.php */
declare(strict_types=1);
require_once __DIR__.'/../includes/error-logging.php';
require dirname(__DIR__).'/includes/bootstrap.php';
arc_require_admin();
$pdo=arc_db();
arc_ensure_user_schema($pdo);
$error='';$notice='';$activeTab='versao';
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        arc_verify_csrf();
        $action=(string)($_POST['action']??'');
        if($action==='detect'){
            arc_release_sync($pdo,true);
            $notice='Conferência finalizada. O inventário monitorado foi verificado.';
        }elseif($action==='save'){
            $activeTab=in_array((string)($_POST['active_tab']??''),['versao','formato','arquivos','historicos'],true)?(string)$_POST['active_tab']:'formato';
            $raw=(string)($_POST['selection_json']??'');
            if($raw===''||strlen($raw)>1024*1024)throw new RuntimeException('Não foi possível receber a seleção de arquivos. Atualize a página e tente novamente.');
            $selection=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
            if(!is_array($selection)||!isset($selection['includes'],$selection['excludes'])||!is_array($selection['includes'])||!is_array($selection['excludes']))throw new RuntimeException('A seleção de arquivos recebida é inválida.');
            $count=filter_var($_POST['segments']??3,FILTER_VALIDATE_INT,['options'=>['min_range'=>2,'max_range'=>5]]);
            if($count===false)throw new RuntimeException('Informe entre 2 e 5 casas.');
            $widths=[];$limits=[];
            for($i=0;$i<$count;$i++){
                $widths[]=filter_var($_POST['width'][$i]??null,FILTER_VALIDATE_INT);
                $limits[]=filter_var($_POST['limit'][$i]??null,FILTER_VALIDATE_INT);
            }
            $policy=arc_release_policy_validate(['includes'=>$selection['includes'],'excludes'=>$selection['excludes'],'widths'=>$widths,'limits'=>$limits],arc_app_version());
            arc_release_save_policy($policy);
            // O salvamento é confirmado independentemente de falhas posteriores na publicação.
            $stored=arc_release_policy();
            if($stored!==$policy)throw new RuntimeException('As regras gravadas não passaram na conferência de leitura.');
            $notice='Regras de versão salvas e confirmadas.';
            try{
                arc_release_sync($pdo,true);
                $notice.=' O inventário foi sincronizado.';
            }catch(Throwable $syncError){
                arc_log_error('Regras de versão salvas; publicação pendente: '.$syncError->getMessage());
                $notice.=' A publicação automática ficou pendente: '.$syncError->getMessage();
            }
        }else throw new RuntimeException('Ação inválida.');
    }catch(Throwable $e){
        $error=$e instanceof RuntimeException?$e->getMessage():'Não foi possível salvar. Verifique os dados e os logs.';
        arc_log_error('Gestão de versões: '.$e->getMessage());
    }
}
$policy=arc_release_policy();
$inventory=arc_release_inventory_paths();
$manifest=arc_release_code_manifest();
$history=$pdo->query('SELECT * FROM '.arc_table('platform_release_history').' ORDER BY id DESC LIMIT 100')->fetchAll();
$version=arc_app_version();
$ARCMode='app';$ARCPageTitle='Gestão de versões';$ARCActiveNav='versions';
require dirname(__DIR__).'/includes/header.php';
require dirname(__DIR__).'/includes/sidebar.php';
?>
<style>
/* Caminho: admin/versao.php — estilos isolados da administração de versões. */
.ver-wrap{display:grid;gap:15px}.ver-top{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}.ver-title{margin:0}.ver-muted{font-size:13px;opacity:.73}.ver-version{font-size:clamp(23px,3.3vw,37px);font-weight:800;overflow-wrap:anywhere}.ver-stats{display:flex;flex-wrap:wrap;gap:9px}.ver-stat{border:1px solid var(--border-color,#dbe3ed);border-radius:11px;padding:10px 15px;min-width:125px}.ver-stat strong{display:block;font-size:19px}.ver-tabs{display:flex;gap:7px;flex-wrap:wrap;border-bottom:1px solid var(--border-color,#dbe3ed);padding-bottom:10px}.ver-tabs button{border:1px solid transparent;background:transparent;border-radius:9px;padding:11px 17px;cursor:pointer;color:inherit;font-weight:650}.ver-tabs button[aria-selected=true]{background:var(--primary-color,#2358b8);color:white}.ver-panel[hidden]{display:none!important}.ver-panel{min-width:0}.ver-controls{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin:13px 0}.ver-controls input[type=search]{flex:1;min-width:200px;max-width:500px}.ver-controls select{min-width:150px}.ver-numbers{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:12px}.ver-number{display:grid;gap:7px;border:1px solid var(--border-color,#dbe3ed);border-radius:12px;padding:12px}.ver-number input{width:100%;min-width:0}.ver-number label{font-size:12px}.ver-preview{font-size:20px;font-weight:750;overflow-wrap:anywhere}.ver-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.ver-tree{border:1px solid var(--border-color,#dbe3ed);border-radius:12px;overflow:auto;max-height:520px;background:var(--card-bg,var(--surface,#fff));min-height:220px}.ver-tree-head,.ver-tree-row{display:grid;grid-template-columns:minmax(250px,1fr) 100px 100px;align-items:center}.ver-tree-head{position:sticky;top:0;background:var(--card-bg,var(--surface,#fff));z-index:2;font-weight:700;border-bottom:1px solid var(--border-color,#dbe3ed)}.ver-tree-head>span,.ver-tree-row>span{padding:9px 11px}.ver-tree-row{border-bottom:1px solid var(--border-color,#edf0f4);font-size:13px}.ver-tree-row:hover{background:rgba(90,120,175,.09)}.ver-tree-row input{width:17px;height:17px;cursor:pointer}.ver-tree-name{display:flex;align-items:center;gap:7px;min-width:0;user-select:none;cursor:pointer}.ver-tree-name .ver-icon{width:20px;display:inline-block;text-align:center;flex-shrink:0}.ver-tree-name .ver-node-text{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.ver-tree-row .ver-check{text-align:center}.ver-tree-row .ver-check label{display:inline-block;cursor:pointer}.ver-count{padding:8px 0}.ver-info{padding:10px 13px;border:1px solid var(--border-color,#dbe3ed);border-radius:10px;margin:10px 0}.ver-status{padding:12px;border-radius:10px;border:1px solid var(--border-color,#dbe3ed)}.ver-status.ver-error{border-color:#c94545;color:#be3737}.ver-status.ver-success{border-color:#238b67;color:#16835c}.ver-footer{display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap;border-top:1px solid var(--border-color,#dbe3ed);padding-top:12px}.ver-overflow{overflow:auto}.ver-overflow table{width:100%}.ver-empty{padding:22px;text-align:center;opacity:.7}.ver-hide{display:none!important}@media(max-width:610px){.ver-tree-head,.ver-tree-row{grid-template-columns:minmax(170px,1fr) 75px 75px}.ver-tree-head>span,.ver-tree-row>span{padding:8px 4px}.ver-tree-row{font-size:12px}.ver-tabs button{flex:1;padding:10px 6px}.ver-stat{flex:1;min-width:85px}}
</style>
<main class="app-main"><div class="ver-wrap">
<section class="card"><div class="ver-top"><div><h1 class="ver-title">Gestão de versões</h1><p class="ver-muted">Controle de numeração, arquivos monitorados e histórico de publicações.</p></div><div><div class="ver-muted">Versão publicada</div><div class="ver-version">v<?=arc_h($version)?></div></div></div>
<?php if($error):?><p class="ver-status ver-error" role="alert"><?=arc_h($error)?></p><?php endif;?>
<?php if($notice):?><p class="ver-status ver-success" role="status"><?=arc_h($notice)?></p><?php endif;?>
<div class="ver-stats"><div class="ver-stat"><strong><?=count($manifest)?></strong><span class="ver-muted">Itens monitorados</span></div><div class="ver-stat"><strong><?=count($inventory['dirs'])?></strong><span class="ver-muted">Pastas elegíveis</span></div><div class="ver-stat"><strong><?=count($inventory['files'])?></strong><span class="ver-muted">Arquivos elegíveis</span></div></div>
</section>
<form method="post" id="ver-policy" class="ver-wrap"><input type="hidden" name="_csrf" value="<?=arc_h(arc_csrf_token())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="active_tab" id="ver-active-tab" value="<?=arc_h($activeTab)?>"><input type="hidden" name="selection_json" id="ver-selection-json" value="">
<nav class="ver-tabs card" role="tablist" aria-label="Áreas da gestão de versões"><button type="button" role="tab" id="ver-tab-versao" aria-controls="ver-panel-versao" aria-selected="false" data-tab="versao">Versão</button><button type="button" role="tab" id="ver-tab-formato" aria-controls="ver-panel-formato" aria-selected="false" data-tab="formato">Formato</button><button type="button" role="tab" id="ver-tab-arquivos" aria-controls="ver-panel-arquivos" aria-selected="false" data-tab="arquivos">Arquivos</button><button type="button" role="tab" id="ver-tab-historicos" aria-controls="ver-panel-historicos" aria-selected="false" data-tab="historicos">Históricos</button></nav>
<section class="card ver-panel" id="ver-panel-versao" role="tabpanel" aria-labelledby="ver-tab-versao" hidden><h2>Versão atual</h2><div class="ver-version">v<?=arc_h($version)?></div><p class="ver-muted">A versão muda quando alterações são identificadas nos arquivos incluídos na seleção da aba Arquivos. O incremento segue as regras da aba Formato.</p><div class="ver-info"><strong>Monitoramento automático</strong><p class="ver-muted">O sistema acompanha os caminhos permitidos e registra mudanças de conteúdo no histórico de publicações. Arquivos privados, chaves, logs, uploads, pacotes e backups não entram na verificação.</p></div><div class="ver-actions"><button type="submit" class="button" name="action" value="detect" form="ver-detect-form" onclick="return true">Conferir alterações agora</button></div></section>
<section class="card ver-panel" id="ver-panel-formato" role="tabpanel" aria-labelledby="ver-tab-formato" hidden><h2>Formato da numeração</h2><div class="ver-controls"><label for="ver-segments">Número de casas</label><select class="form-control" id="ver-segments" name="segments"><?php for($n=2;$n<=5;$n++):?><option value="<?=$n?>" <?=count($policy['widths'])===$n?'selected':''?>><?=$n?> casas</option><?php endfor;?></select></div>
<div class="ver-numbers" id="ver-numbers"><?php for($i=0;$i<5;$i++):$w=$policy['widths'][$i]??($i===0?2:3);$lim=$policy['limits'][$i]??(10**$w-1);?><div class="ver-number" data-idx="<?=$i?>"><strong>Casa <?=$i+1?></strong><label>Largura (dígitos)<input class="form-control" type="number" name="width[<?=$i?>]" min="1" max="5" value="<?=$w?>" required></label><label>Limite para avançar<input class="form-control" type="number" name="limit[<?=$i?>]" min="1" max="99999" value="<?=$lim?>" required></label></div><?php endfor;?></div>
<p class="ver-muted">Exemplo: <strong>v.00.000.000.000</strong> usa quatro casas de larguras 2, 3, 3 e 3. Quando a última alcança seu limite, volta a zero e incrementa a anterior.</p><p class="ver-muted">Próxima versão prevista:</p><div class="ver-preview" id="ver-preview"></div></section>
<section class="card ver-panel" id="ver-panel-arquivos" role="tabpanel" aria-labelledby="ver-tab-arquivos" hidden><h2>Explorador de arquivos monitorados</h2><p class="ver-muted">As pastas aparecem como no Gerenciador de Arquivos do cPanel. <strong>Dê dois cliques na pasta</strong> para abrir ou recolher subpastas. Marque Monitorar para acompanhar o conteúdo inteiro, ou Ignorar para excluir uma pasta ou arquivo. A exclusão tem prioridade.</p>
<div class="ver-controls"><input type="search" class="form-control" id="ver-search" placeholder="Buscar pasta ou arquivo pelo nome ou caminho…" aria-label="Buscar arquivos"><select id="ver-kind" class="form-control"><option value="all">Pastas e arquivos</option><option value="dir">Só pastas</option><option value="file">Só arquivos</option></select><button type="button" class="button" id="ver-expand">Expandir tudo</button><button type="button" class="button" id="ver-collapse">Recolher</button></div>
<div class="ver-info"><label><input type="checkbox" id="ver-all"> <strong>Monitorar todos os arquivos elegíveis (*)</strong></label><span class="ver-muted"> — Use Ignorar em subpastas e arquivos específicos.</span></div>
<div class="ver-tree" role="treegrid" aria-label="Explorador de arquivos da plataforma"><div class="ver-tree-head"><span>Nome do arquivo ou pasta</span><span style="text-align:center">Monitorar</span><span style="text-align:center">Ignorar</span></div><div id="ver-tree-rows"></div></div>
<p class="ver-muted ver-count" id="ver-count" aria-live="polite"></p><p class="ver-muted">Arquivos protegidos e pastas de conteúdo privado não são exibidos nem podem ser selecionados.</p></section>
<section class="card ver-panel" id="ver-panel-historicos" role="tabpanel" aria-labelledby="ver-tab-historicos" hidden><h2>Histórico de publicações</h2><div class="ver-overflow"><table class="data-table"><thead><tr><th>Versão</th><th>Publicação</th><th>Origem</th><th>Alterações</th></tr></thead><tbody><?php foreach($history as $item):?><tr><td><?=arc_h((string)$item['version'])?></td><td><?=arc_h(arc_when($item['created_at']))?></td><td><?=arc_h((string)$item['source'])?></td><td style="white-space:pre-wrap;overflow-wrap:anywhere"><?=arc_h((string)$item['notes'])?></td></tr><?php endforeach;?><?php if(!$history):?><tr><td colspan="4" class="ver-empty">Nenhuma publicação registrada.</td></tr><?php endif;?></tbody></table></div></section>
<div class="card ver-footer"><span class="ver-muted" id="ver-save-info">Alterações só entram em vigor após salvar.</span><button type="submit" class="button" id="ver-save">Salvar configurações</button></div>
</form>
<form method="post" id="ver-detect-form"><input type="hidden" name="_csrf" value="<?=arc_h(arc_csrf_token())?>"><input type="hidden" name="action" value="detect"></form>
</div></main>
<script>
/* Caminho: admin/versao.php — árvore de arquivos, abas e serialização segura do formulário. */
(()=>{'use strict';
const inventory=<?=json_encode($inventory,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)?>;
const saved=<?=json_encode(['includes'=>$policy['includes'],'excludes'=>$policy['excludes']],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES)?>;
const current=<?=json_encode($version,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
const includes=new Set(saved.includes),excludes=new Set(saved.excludes),expanded=new Set();
const form=document.getElementById('ver-policy'),selection=document.getElementById('ver-selection-json'),rootCheck=document.getElementById('ver-all'),tree=document.getElementById('ver-tree-rows'),search=document.getElementById('ver-search'),kind=document.getElementById('ver-kind'),counter=document.getElementById('ver-count');
const nodes=new Map();
const add=(path,dir)=>{if(!path||nodes.has(path))return;const segments=path.split('/');const parent=segments.length>1?segments.slice(0,-1).join('/'):'';nodes.set(path,{path,name:segments.at(-1),parent,dir,children:[]});};
for(const path of inventory.dirs)add(path,true);for(const path of inventory.files)add(path,false);
for(const node of nodes.values()){if(node.parent&&nodes.has(node.parent))nodes.get(node.parent).children.push(node.path);}
const cmp=(a,b)=>Number(nodes.get(b).dir)-Number(nodes.get(a).dir)||nodes.get(a).name.localeCompare(nodes.get(b).name,'pt-BR',{numeric:true,sensitivity:'base'});
const roots=[...nodes.values()].filter(n=>!n.parent||!nodes.has(n.parent)).map(n=>n.path).sort(cmp);
for(const node of nodes.values())node.children.sort(cmp);
const icon=(name)=>{const span=document.createElement('span');span.className='ver-icon';span.textContent=name;return span;};
function syncAll(){rootCheck.checked=includes.has('*');}
function mark(path,mode,checked){const active=mode==='includes'?includes:excludes,other=mode==='includes'?excludes:includes;if(checked){active.add(path);other.delete(path);}else active.delete(path);if(path==='*')syncAll();render();}
function makeRow(node,depth){const row=document.createElement('div');row.className='ver-tree-row';row.setAttribute('role','row');row.dataset.path=node.path;
const first=document.createElement('span'),name=document.createElement('div');name.className='ver-tree-name';name.style.paddingLeft=Math.min(depth*19,190)+'px';name.setAttribute('title',node.path);name.tabIndex=0;name.setAttribute('aria-label',node.dir?'Pasta '+node.path+'; dois cliques para expandir':'Arquivo '+node.path);
name.append(icon(node.dir?(expanded.has(node.path)?'▾ 📂':'▸ 📁'):'📄'));const label=document.createElement('span');label.className='ver-node-text';label.textContent=node.name;name.append(label);
if(node.dir){const toggle=()=>{if(expanded.has(node.path))expanded.delete(node.path);else expanded.add(node.path);render();};name.addEventListener('dblclick',toggle);name.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();toggle();}});}first.append(name);row.append(first);
for(const mode of ['includes','excludes']){const cell=document.createElement('span');cell.className='ver-check';const lab=document.createElement('label'),box=document.createElement('input');box.type='checkbox';box.checked=(mode==='includes'?includes:excludes).has(node.path);box.setAttribute('aria-label',(mode==='includes'?'Monitorar ':'Ignorar ')+node.path);box.addEventListener('change',()=>mark(node.path,mode,box.checked));lab.append(box);cell.append(lab);row.append(cell);}return row;}
function render(){syncAll();const q=search.value.trim().toLocaleLowerCase('pt-BR'),type=kind.value;const matches=new Set();if(q||type!=='all'){for(const n of nodes.values()){if((type==='all'||(type==='dir')===n.dir)&&n.path.toLocaleLowerCase('pt-BR').includes(q)){let cur=n;while(cur){matches.add(cur.path);cur=nodes.get(cur.parent);}}}}
const frag=document.createDocumentFragment();let visible=0;
const walk=(p,depth)=>{const node=nodes.get(p);if(!node)return;if((q||type!=='all')&&!matches.has(p))return;frag.append(makeRow(node,depth));visible++;if(node.dir&&(expanded.has(p)||q||type!=='all'))for(const c of node.children)walk(c,depth+1);};
for(const p of roots)walk(p,0);tree.replaceChildren(frag);counter.textContent=`${visible} itens exibidos • ${nodes.size} itens elegíveis • ${includes.size} inclusões • ${excludes.size} exclusões`;}
rootCheck.addEventListener('change',()=>mark('*','includes',rootCheck.checked));search.addEventListener('input',render);kind.addEventListener('change',render);document.getElementById('ver-expand').addEventListener('click',()=>{for(const n of nodes.values())if(n.dir)expanded.add(n.path);render();});document.getElementById('ver-collapse').addEventListener('click',()=>{expanded.clear();render();});
const segments=document.getElementById('ver-segments'),preview=document.getElementById('ver-preview');
function updateFormat(){const count=Number(segments.value),parts=current.split('.').map(Number),widths=[],limits=[];let valid=true;
for(const box of document.querySelectorAll('.ver-number')){const i=Number(box.dataset.idx),active=i<count;box.classList.toggle('ver-hide',!active);for(const inp of box.querySelectorAll('input'))inp.disabled=!active;if(!active)continue;const w=Number(box.querySelector('input[name^="width"]').value),max=Number(box.querySelector('input[name^="limit"]').value);if(!Number.isInteger(w)||w<1||w>5||!Number.isInteger(max)||max<1||max>=10**w||max<(parts[i]||0))valid=false;widths.push(w);limits.push(max);}
if(parts.length>count&&parts.slice(count).some(Boolean))valid=false;
if(!valid){preview.textContent='Revise a largura e o limite de cada casa.';return;}
const values=Array.from({length:count},(_,i)=>parts[i]||0);let success=false;for(let i=count-1;i>=0;i--){if(values[i]<limits[i]){values[i]++;success=true;break;}values[i]=0;}preview.textContent=success?'v'+values.map((v,i)=>String(v).padStart(widths[i],'0')).join('.'): 'Limite máximo atingido. Amplie o formato.';}
segments.addEventListener('change',updateFormat);document.querySelectorAll('.ver-number input').forEach(n=>n.addEventListener('input',updateFormat));
const buttons=[...document.querySelectorAll('.ver-tabs [role="tab"]')];
function setTab(id){if(!['versao','formato','arquivos','historicos'].includes(id))id='versao';for(const b of buttons){const yes=b.dataset.tab===id;b.setAttribute('aria-selected',String(yes));document.getElementById('ver-panel-'+b.dataset.tab).hidden=!yes;}document.getElementById('ver-active-tab').value=id;}
for(const b of buttons)b.addEventListener('click',()=>{setTab(b.dataset.tab);if(history.replaceState)history.replaceState(null,'','#'+b.dataset.tab);});
form.addEventListener('submit',e=>{if(!form.checkValidity()){setTab('formato');form.reportValidity();e.preventDefault();return;}selection.value=JSON.stringify({includes:[...includes],excludes:[...excludes]});document.getElementById('ver-save').disabled=true;});
setTab((location.hash||'').slice(1)||document.getElementById('ver-active-tab').value);updateFormat();render();
})();
</script>
<?php require dirname(__DIR__).'/includes/footer.php';?>
