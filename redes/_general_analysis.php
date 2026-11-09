<?php
declare(strict_types=1);
$pfRoot=dirname(__DIR__).'/gestores/servidor/Arcoll.WinUI3/Assets/hybrid/pentefino/';
$html=file_get_contents($pfRoot.'index.html');preg_match('~<body[^>]*>(.*)</body>~s',$html,$body);$markup=preg_replace('~<script\b[^>]*>.*?</script>~si','',$body[1]??'');
$css=file_get_contents($pfRoot.'styles.css');$css=str_replace(':root',':host',$css);$css=preg_replace('/(?<![\w-])html\b/',':host',$css);$css=preg_replace('/(?<![\w-])body\b/','.pf-body',$css);
$pfSettings=arc_clean_settings_get($pdo,(int)$selectedGestorId,(int)$networkId,(int)$account);
$pfConfig=['api'=>arc_workspace_base_url().'api/api.php','network'=>(int)$networkId,'gestor'=>(int)$selectedGestorId,'csrf'=>$csrf,'catalog'=>['catalog'=>arc_clean_catalog(),'registry'=>arc_clean_registry_checks()],'preferences'=>$pfSettings['general_preferences']??arc_general_defaults(),'toolsUrl'=>arc_workspace_base_url().'redes/clean.php?'.http_build_query(['network'=>(int)$networkId,'gestor'=>(int)$selectedGestorId])];
?>
<div id="pf-analysis" data-config="<?=arc_h(json_encode($pfConfig,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))?>"><template id="pf-analysis-template"><style><?=$css?>
:host{display:block;height:100%;min-height:0}.pf-body{height:100%;min-height:0}.top,.summary-foot{display:none!important}#app{height:100%!important}#history{display:none!important}
</style><div class="pf-body"><?=$markup?></div></template></div>
<dialog id="pf-web-confirm" aria-labelledby="pf-web-confirm-title"><h2 id="pf-web-confirm-title">Confirma ajustes e correções?</h2><p>As áreas selecionadas serão corrigidas no computador. Novas correções não guardam cópias de recuperação.</p><label><input id="pf-web-restore" type="checkbox"> Criar ponto de restauração no Windows</label><footer><button type="button" data-confirm-cancel>Cancelar</button><button type="button" data-confirm-apply>Corrigir</button></footer></dialog>
<style>#pf-web-confirm{width:min(420px,calc(100vw - 24px));padding:20px;border:1px solid var(--border);border-radius:10px;background:var(--surface);color:var(--text)}#pf-web-confirm h2{font-size:16px;margin:0 0 12px}#pf-web-confirm p{font-size:12px;color:var(--muted);line-height:1.6}#pf-web-confirm label{display:flex;align-items:center;gap:8px;font-size:12px;margin:18px 0}#pf-web-confirm footer{display:flex;justify-content:flex-end;gap:8px}#pf-web-confirm button{border:1px solid var(--border);padding:8px 14px;border-radius:6px;color:var(--text);background:var(--surface-alt);cursor:pointer}#pf-web-confirm [data-confirm-apply]{background:var(--primary);color:var(--onprimary,#fff)}#pf-web-confirm::backdrop{background:#071529a6;backdrop-filter:blur(3px)}</style>
<script src="<?=arc_h(arc_workspace_base_url().'assets/gestao/general-bridge.js')?>" defer></script>
<script src="<?=arc_h(arc_workspace_base_url().'gestores/servidor/Arcoll.WinUI3/Assets/hybrid/pentefino/app.js')?>" defer></script>
