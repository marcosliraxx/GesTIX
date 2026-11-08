<?php
/* Caminho: api/arquivos-api.php */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
try {
    require dirname(__DIR__).'/includes/bootstrap.php';
    if (!arc_is_admin()) { http_response_code(403); throw new RuntimeException('Entre como administrador antes de usar a limpeza.'); }
    $pdo=arc_db();
    if (!class_exists('ZipArchive')) throw new RuntimeException('A extensão PHP ZipArchive é necessária.');
    $root=realpath(dirname(__DIR__));
    if ($root===false) throw new RuntimeException('Raiz não localizada.');
    function remov_json(array $data,int $status=200): never { http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);exit; }
    function remov_protected(string $rel): bool {
        if ($rel==='' || str_contains($rel,'\\') || str_contains($rel,'..') || str_starts_with($rel,'/')) return true;
        if (preg_match('~(^|/)(bkp|logs|\.git|vendor|node_modules|includes/storage|uploads|\.well-known)(/|$)~i',$rel)) return true;
        if (preg_match('~(^|/)(\.env[^/]*|\.htaccess|\.user\.ini|web\.config|composer\.(json|lock)|config\.php|arquivos-api\.php)$~i',$rel)) return true;
        if (preg_match('~(^|/)(database\.php|db\.php|bootstrap\.php|admin-actions\.php)$~i',$rel)) return true;
        return false;
    }
    function remov_candidate(string $rel): ?string {
        $base=basename($rel);
        if (remov_protected($rel)) return null;
        if (preg_match('/\.(?:bak|old|orig|rej|swp|swo|backup|tmp|save)$/i',$base)) return 'Extensão típica de cópia ou temporário';
        if (preg_match('/(?:^|[._-])(?:backup|bak|old|copy|copia)(?:[._-]|$)/i',$base) && preg_match('/\.(php|html|js|css|json|txt|ps1|py|cs|xaml)$/i',$base)) return 'Possível cópia antiga';
        if (preg_match('/^(\.DS_Store|Thumbs\.db)$/i',$base)) return 'Artefato do sistema operacional';
        return null;
    }
    function remov_inventory(string $root): array {
        $found=[];$scanned=0;$directories=0;$candidates=0;$limit=20000;$maxRows=5000;
        $dir=new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS);
        $filter=new RecursiveCallbackFilterIterator($dir,static function($file)use($root){
            $rel=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));
            if ($file->isLink() || remov_protected($rel)) return false;
            if ($file->isDir() && preg_match('~(^|/)(bin|obj|\.vs|cache|tmp)(/|$)~i',$rel)) return false;
            return true;
        });
        $it=new RecursiveIteratorIterator($filter,RecursiveIteratorIterator::SELF_FIRST);
        foreach($it as $file) {
            if ($file->isDir()){$directories++;continue;}
            if (++$scanned>$limit || count($found)>=$maxRows) break;
            if (!$file->isFile() || $file->isLink()) continue;
            $rel=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));
            $reason=remov_candidate($rel); if($reason!==null)$candidates++;
            $found[]=['path'=>$rel,'bytes'=>$file->getSize(),'mtime'=>$file->getMTime(),'status'=>$reason!==null?'candidate':'active','reason'=>$reason??'Sem evidência suficiente para classificar como obsoleto'];
        }
        return ['files'=>$found,'scanned'=>$scanned,'directories'=>$directories,'candidates'=>$candidates,'truncated'=>$scanned>$limit||count($found)>=$maxRows];
    }
    function remov_target(string $root,string $rel,bool $requireCandidate=false): string {
        if(strlen($rel)>600 || remov_protected($rel) || ($requireCandidate && remov_candidate($rel)===null)) throw new RuntimeException('Arquivo não permitido.');
        $full=$root.'/'.$rel;$real=realpath($full);
        if(!$real||!str_starts_with($real,$root.DIRECTORY_SEPARATOR)||is_link($full)||!is_file($real)||!is_readable($real)) throw new RuntimeException('Arquivo não encontrado ou protegido: '.$rel);
        return $real;
    }
    function remov_backup(string $root,array $files): string {
        $backup=$root.'/bkp';if(!is_dir($backup)&&!mkdir($backup,0700,true))throw new RuntimeException('Sem permissão para criar bkp.');
        if(!is_file($backup.'/.htaccess'))file_put_contents($backup.'/.htaccess',"Require all denied\nOptions -Indexes\n");
        if(!is_file($backup.'/index.html'))file_put_contents($backup.'/index.html','');
        $name='arquivos-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.zip';$zipPath=$backup.'/'.$name;
        $zip=new ZipArchive();if($zip->open($zipPath,ZipArchive::CREATE|ZipArchive::EXCL)!==true)throw new RuntimeException('Não foi possível criar o backup.');
        foreach($files as $rel=>$real){if(!$zip->addFile($real,$rel)){$zip->close();@unlink($zipPath);throw new RuntimeException('Falha ao copiar arquivo para backup.');}}
        if(!$zip->close())throw new RuntimeException('Falha ao finalizar backup.');
        $verify=new ZipArchive();if($verify->open($zipPath)!==true)throw new RuntimeException('Falha ao abrir backup para verificar.');
        $valid=$verify->numFiles===count($files);
        foreach($files as $rel=>$real){if($verify->locateName($rel)===false || $verify->getFromName($rel)===false || hash('sha256',$verify->getFromName($rel))!==hash_file('sha256',$real)){$valid=false;break;}}
        $verify->close();if(!$valid)throw new RuntimeException('Backup não passou na verificação; alterações canceladas.');
        return 'bkp/'.$name;
    }
    $action=(string)($_POST['action']??$_GET['action']??'');
    if ($action==='session') remov_json(['ok'=>true,'csrf'=>arc_csrf_token()]);
    if ($_SERVER['REQUEST_METHOD']!=='POST') remov_json(['ok'=>false,'message'=>'Método não permitido.'],405);
    if(!hash_equals(arc_csrf_token(),(string)($_POST['_csrf']??'')))remov_json(['ok'=>false,'message'=>'Token CSRF inválido.'],403);
    // Confirmação de operação com autorização vinculada ao conteúdo, usuário e sessão.
    function gsti_approval_signature(string $operation,array $fields): string {
        if ($operation==='delete') {
            $paths=$fields['paths']??[];
            if(!is_array($paths)||!$paths||count($paths)>100) throw new RuntimeException('Seleção inválida.');
            foreach($paths as $path) if(!is_string($path)) throw new RuntimeException('Caminho inválido.');
            $paths=array_values(array_unique($paths));sort($paths,SORT_STRING);
            return hash('sha256',json_encode(['delete',$paths],JSON_THROW_ON_ERROR));
        }
        if ($operation==='edit') return hash('sha256',json_encode(['edit',(string)($fields['path']??''),hash('sha256',(string)($fields['content']??''))],JSON_THROW_ON_ERROR));
        if ($operation==='rename') return hash('sha256',json_encode(['rename',(string)($fields['path']??''),(string)($fields['name']??'')],JSON_THROW_ON_ERROR));
        throw new RuntimeException('Operação não permitida.');
    }
    if ($action==='authorize') {
        $operation=(string)($_POST['operation']??'');
        $signature=gsti_approval_signature($operation,$_POST);
        $nonce=bin2hex(random_bytes(24));
        $_SESSION['gsti_file_approval']=['admin'=>(int)arc_admin_id(),'operation'=>$operation,'signature'=>$signature,'nonce'=>$nonce,'expires'=>time()+90];
        remov_json(['ok'=>true,'approval'=>$nonce]);
    }
    function gsti_require_approval(string $operation,array $fields): void {
        $approval=$_SESSION['gsti_file_approval']??null;
        unset($_SESSION['gsti_file_approval']); // uso único mesmo em caso de falha
        if(!is_array($approval)||($approval['expires']??0)<time()||($approval['admin']??0)!==(int)arc_admin_id()||($approval['operation']??'')!==$operation||!hash_equals((string)($approval['nonce']??''),(string)($fields['approval']??''))||!hash_equals((string)($approval['signature']??''),gsti_approval_signature($operation,$fields))) {
            throw new RuntimeException('Confirmação expirada ou inválida. Repita a operação.');
        }
    }
    if ($action==='download') {
        $rel=(string)($_POST['path']??'');$real=remov_target($root,$rel);
        if(!is_readable($real)) throw new RuntimeException('Arquivo não disponível para download.');
        while(ob_get_level()>0){ob_end_clean();}
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.str_replace(['"',"\r","\n"],'_',basename($real)).'"');
        header('Content-Length: '.filesize($real));
        header('X-Content-Type-Options: nosniff');
        readfile($real);exit;
    }
    if ($action==='web') {
        $rel=(string)($_POST['path']??'');remov_target($root,$rel);
        // Não executar código PHP ou scripts administrativos diretamente via botão Web.
        if(!preg_match('/\.(?:html?|css|js|png|jpe?g|gif|webp|svg|pdf|txt)$/i',$rel)) throw new RuntimeException('Este arquivo não possui abertura web segura; utilize Baixar ou Editar.');
        $base=arc_workspace_base_url();
        $parts=array_map('rawurlencode',explode('/',$rel));
        remov_json(['ok'=>true,'url'=>$base.implode('/',$parts)]);
    }
    if ($action==='scan') remov_json(['ok'=>true,'report'=>remov_inventory($root),'note'=>'Lista de candidatos por padrões de nome. Não comprova que o arquivo não seja utilizado. Examine antes de selecionar.']);
    if(in_array($action,['read','edit','rename'],true)) {
        $rel=(string)($_POST['path']??'');$real=remov_target($root,$rel);
        if(!preg_match('/\.(php|html|htm|css|js|json|txt|md|xml|yml|yaml|ini|ps1|py|cs|xaml|sql|bat|vbs|sh|log|old|bak)$/i',$rel))throw new RuntimeException('Formato não editável neste gerenciador.');
        if(filesize($real)>524288)throw new RuntimeException('Arquivo maior que 512 KB; utilize um editor dedicado.');
        if($action==='read') {
            $content=file_get_contents($real);
            if($content===false || str_contains($content,"\0"))throw new RuntimeException('Arquivo binário não é editável.');
            remov_json(['ok'=>true,'content'=>$content]);
        }
        gsti_require_approval($action,$_POST);
        if($action==='edit') {
            $new=(string)($_POST['content']??'');
            if(strlen($new)>524288||str_contains($new,"\0"))throw new RuntimeException('Conteúdo inválido ou muito grande.');
            $archive=remov_backup($root,[$rel=>$real]);
            $tmp=tempnam(dirname($real),'.gsti-');if(!$tmp)throw new RuntimeException('Sem permissão para criar arquivo temporário.');
            if(file_put_contents($tmp,$new,LOCK_EX)!==strlen($new)){@unlink($tmp);throw new RuntimeException('Não foi possível escrever o arquivo.');}
            @chmod($tmp,fileperms($real)&0777);
            if(!rename($tmp,$real)){@unlink($tmp);throw new RuntimeException('Falha ao substituir o arquivo.');}
            error_log('Arquivos: admin '.arc_admin_id().' editou '.$rel.' backup '.$archive);
            remov_json(['ok'=>true,'archive'=>$archive]);
        }
        $name=(string)($_POST['name']??'');
        if(!preg_match('/^[a-zA-Z0-9_ .()\-]{1,160}$/D',$name) || $name==='.' || $name==='..')throw new RuntimeException('Nome inválido.');
        $dest=dirname($real).'/'.$name;
        if(file_exists($dest)||remov_protected(str_replace('\\','/',substr($dest,strlen($root)+1))))throw new RuntimeException('Destino inválido ou já existente.');
        $archive=remov_backup($root,[$rel=>$real]);
        if(!rename($real,$dest))throw new RuntimeException('Falha ao renomear.');
        error_log('Arquivos: admin '.arc_admin_id().' renomeou '.$rel.' backup '.$archive);
        remov_json(['ok'=>true,'archive'=>$archive]);
    }
    if ($action!=='delete') remov_json(['ok'=>false,'message'=>'Ação inválida.'],400);
    gsti_require_approval('delete',$_POST);
    $paths=$_POST['paths']??[];
    if(!is_array($paths)||!$paths||count($paths)>100)remov_json(['ok'=>false,'message'=>'Selecione de 1 a 100 arquivos.'],400);
    $targets=[];$size=0;
    foreach(array_unique($paths) as $rel){
        if(!is_string($rel))throw new RuntimeException('Caminho inválido.');
        $real=remov_target($root,$rel);
        $targets[$rel]=$real;$size+=filesize($real);
    }
    if($size>250*1024*1024)throw new RuntimeException('Lote acima de 250 MB.');
    $archive=remov_backup($root,$targets);
    $deleted=[];$failed=[];
    foreach($targets as $rel=>$real){if(@unlink($real))$deleted[]=$rel;else $failed[]=$rel;}
    error_log('Arquivos: operador admin '.arc_admin_id().' backup '.$archive.' excluídos '.count($deleted).' falhas '.count($failed));
    remov_json(['ok'=>true,'archive'=>$archive,'removed'=>$deleted,'failed'=>$failed,'message'=>'Backup gerado antes da exclusão; confira os resultados.']);
} catch(Throwable $e) {http_response_code(400);echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);}
