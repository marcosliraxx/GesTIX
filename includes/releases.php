<?php
/* Caminho: includes/releases.php */
declare(strict_types=1);
require_once __DIR__ . '/error-logging.php';


function arc_app_version(): string {
    $value=trim((string)@file_get_contents(dirname(__DIR__).'/VERSION'));
    if(!preg_match('/^\d+\.\d+\.\d+$/D',$value))throw new RuntimeException('VERSION ausente ou inválido. Conclua a atualização da plataforma.');return $value;
}
/** O cliente Python legado (3.x/4.x) ja executa gestor_update pelo canal autenticado.
 * A atualizacao do Python atua apenas como ponte verificada para instalar WinUI 3;
 * nenhuma reinstalacao manual e exigida enquanto o gestor anterior estiver conectado
 * e tiver suporte ao comando. Versoes nao identificaveis exigem diagnostico, nunca
 * liberacao silenciosa de comandos sem autenticacao.
 */
function arc_gestor_upgrade_policy(string $installed, string $published): array {
    $valid = static fn(string $v): bool => (bool)preg_match('/^\d+\.\d+\.\d+$/D', $v);
    $known = $valid($installed);
    $migration = $known && $valid($published) && version_compare($published, '5.0.0', '>=')
        && version_compare($installed, '5.0.0', '<');
    return [
        'requires_reinstall' => !$known,
        'can_remote_update' => $known,
        'migrate_native' => $migration,
        'message' => !$known
            ? 'A versao deste gestor nao foi identificada. Verifique se esta conectado e responde a comandos antes de atualizar.'
            : ($migration ? 'Migracao remota do gestor antigo para WinUI 3. O Windows deve permitir a instalacao das dependencias nativas.' : ''),
    ];
}
/** Manifesto da release precompilada publicada (gestores/releases/<arch>/current/release.json), ou null. */
function arc_gestor_release_manifest(string $arch): ?array {
    static $cache=[];
    if(array_key_exists($arch,$cache))return $cache[$arch];
    $manifest=null;
    if(in_array($arch,['win-x64','win-arm64'],true)){
        $file=dirname(__DIR__).'/gestores/releases/'.$arch.'/current/release.json';
        if(is_file($file)&&is_readable($file)&&(int)@filesize($file)<=350000){
            $data=json_decode((string)@file_get_contents($file),true);
            if(is_array($data)&&($data['arch']??null)===$arch&&preg_match('/^\d+\.\d+\.\d+$/D',(string)($data['version']??''))
                &&isset($data['files'])&&is_array($data['files'])&&isset($data['files']['Arcoll.WinUI3.exe']))$manifest=$data;
        }
    }
    return $cache[$arch]=$manifest;
}
/** 'precompiled' quando há binários publicados; 'source' quando o gestor ainda precisa compilar no computador. */
function arc_gestor_release_mode(): string {
    return (arc_gestor_release_manifest('win-x64')||arc_gestor_release_manifest('win-arm64'))?'precompiled':'source';
}
/** Versão que os gestores devem ter. Com release precompilada, é a versão dos binários publicados
 *  (não muda a cada ajuste de PHP/CSS da plataforma); sem ela, acompanha a versão da plataforma. */
function arc_gestor_target_version(): string {
    $best=null;
    foreach(['win-x64','win-arm64'] as $arch){
        $m=arc_gestor_release_manifest($arch);
        if($m&&($best===null||version_compare((string)$m['version'],$best,'>')))$best=(string)$m['version'];
    }
    return $best??arc_app_version();
}
function arc_release_skip(string $path): bool {
    // Binários precompilados do gestor ficam fora do inventário: são grandes e têm versão própria (release.json).
    $normalized=str_replace('\\','/',$path);
    if($normalized==='gestores/releases'||str_starts_with($normalized,'gestores/releases/'))return true;
    return (str_starts_with(basename($path),'.')&&basename($path)!=='.htaccess')||preg_match('~(?:^|/)(?:storage|uploads|backups|secrets|_sql|__pycache__|tests|scripts|docs|pacotes)(?:/|$)~',$path)||in_array(basename($path),['config.php','gestor-config.json','gestor.json','error_log','_manifest.json','VERSION'],true)||preg_match('/\.(?:log|pyc|zip|env|pem|key|pfx|tmp|updating)$/i',$path);
}
/** Percorre o inventário da plataforma uma única vez. $onFile recebe (caminho relativo, caminho absoluto); $onDir, o relativo de cada pasta. */
function arc_release_walk(string $root,callable $onFile,?callable $onDir=null): void {
    $count=0;
    foreach(new DirectoryIterator($root) as $top){
        if($top->isDot()||$top->isLink()||!$top->isDir()||arc_release_skip($top->getFilename()))continue;$folder=$top->getFilename();
        $walk=function(string $directory)use(&$walk,&$count,$root,$onFile,$onDir):void{
            foreach(new DirectoryIterator($directory) as $entry){if($entry->isDot()||$entry->isLink())continue;$relative=substr($entry->getPathname(),strlen($root)+1);if(arc_release_skip($relative))continue;
                if($entry->isDir()){if($onDir)$onDir($relative);$walk($entry->getPathname());}
                elseif($entry->isFile())$onFile($relative,$entry->getPathname());
                if(++$count>20000)throw new RuntimeException('Inventário excede o limite de segurança.');
            }
        };$walk($root.'/'.$folder);
    }
    foreach(glob($root.'/*')?:[] as $path)if(is_file($path)&&!is_link($path)&&preg_match('/\.(?:php|js|css|html|json)$/i',$path)&&!arc_release_skip(basename($path)))$onFile(basename($path),$path);
    if(is_file($root.'/.htaccess'))$onFile('.htaccess',$root.'/.htaccess');
}
function arc_release_code_manifest(?string $root=null): array {
    $files=[];
    arc_release_walk($root??dirname(__DIR__),static function(string $relative,string $path)use(&$files):void{
        $hash=hash_file('sha256',$path);if($hash===false)throw new RuntimeException('Não foi possível verificar um arquivo da plataforma.');$files[$relative]=$hash;
    },static function(string $relative)use(&$files):void{$files[$relative.'/']='directory';});
    ksort($files,SORT_STRING);return $files;
}
/** Assinatura barata (caminho, tamanho e data) do inventário: só quando muda é preciso recalcular os SHA-256. */
function arc_release_stat_signature(?string $root=null): string {
    $lines=[];
    arc_release_walk($root??dirname(__DIR__),static function(string $relative,string $path)use(&$lines):void{
        clearstatcache(true,$path);$lines[]=$relative.'|'.(int)@filesize($path).'|'.(int)@filemtime($path);
    },static function(string $relative)use(&$lines):void{$lines[]=$relative.'/';});
    sort($lines,SORT_STRING);return hash('sha256',implode("\n",$lines));
}
function arc_release_write_version(string $version): void {
    if(!preg_match('/^\d+\.\d+\.\d+$/D',$version))throw new RuntimeException('Informe uma versão no formato X.Y.Z.');
    $root=dirname(__DIR__);$temp=tempnam($root,'.release-');if($temp===false)throw new RuntimeException('A pasta raiz precisa permitir gravação para publicar a versão.');
    try{if(file_put_contents($temp,$version."\n",LOCK_EX)===false||!rename($temp,$root.'/VERSION'))throw new RuntimeException('Não foi possível atualizar VERSION. Confira as permissões.');chmod($root.'/VERSION',0644);}finally{if(is_file($temp))unlink($temp);}
}
function arc_release_next(string $version): string {$parts=array_map('intval',explode('.',$version));$parts[2]++;return implode('.',$parts);}
function arc_release_sync(PDO $pdo,bool $force=false,?string $manualVersion=null,string $notes='',?int $admin=null): void {
    if(!$force&&(arc_config()['release_auto_detect']??true)===false)return;
    $directory=__DIR__.'/storage/releases';if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new RuntimeException('Armazenamento de versões indisponível.');
    $stateFile=$directory.'/scan.json';$scan=json_decode((string)@file_get_contents($stateFile),true)??[];
    if(!$force&&time()-(int)($scan['checked_at']??0)<5)return;
    /* Sem alteração nos arquivos (caminho, tamanho e data) não há o que recalcular: evita refazer todos os SHA-256 a cada intervalo. */
    $signature=arc_release_stat_signature();
    if(!$force&&($scan['signature']??'')===$signature&&is_file($directory.'/publish.lock')){file_put_contents($stateFile,json_encode(['checked_at'=>time(),'signature'=>$signature]),LOCK_EX);return;}
    $lock=fopen($directory.'/publish.lock','c+');if(!$lock||!flock($lock,LOCK_EX))return;
    try{
        $scan=json_decode((string)@file_get_contents($stateFile),true)??[];
        $manifest=arc_release_code_manifest();$hash=hash('sha256',json_encode($manifest,JSON_UNESCAPED_SLASHES));$version=arc_app_version();
        $q=$pdo->query('SELECT * FROM '.arc_table('platform_release_state').' WHERE id=1');$old=$q->fetch();
        $changed=$old&&(string)$old['fingerprint']!==$hash;
        // Publicação imediata: fingerprint diferente é uma alteração completa, sem espera adicional.
        // A versão publicada no banco prevalece quando o arquivo VERSION estiver atrasado.
        if($old&&version_compare($version,(string)$old['version'],'<'))$version=(string)$old['version'];
        if($manualVersion===null&&$changed&&$version===(string)$old['version']){
            $version=arc_release_next($version);
            $manualVersion=$version;
            $notes='Alterações de arquivos detectadas automaticamente.';
        }
        $publish=!$old||$manualVersion!==null||$version!==($old['version']??null);
        if($publish){
            $version=$manualVersion??$version;
            if($old&&$version!==$old['version']&&version_compare($version,$old['version'],'<'))throw new RuntimeException('A nova versão precisa ser superior à publicada.');
            $previous=json_decode((string)($old['files_json']??'{}'),true)??[];$changes=['added'=>array_values(array_diff(array_keys($manifest),array_keys($previous))),'removed'=>array_values(array_diff(array_keys($previous),array_keys($manifest))),'modified'=>array_values(array_filter(array_keys(array_intersect_key($manifest,$previous)),static fn($key)=>$manifest[$key]!==$previous[$key]))];
            foreach($manifest as $path=>$digest)if(str_ends_with($path,'.php'))token_get_all((string)file_get_contents(dirname(__DIR__).'/'.$path),TOKEN_PARSE);
            $previousVersion=arc_app_version();$pdo->beginTransaction();try{
                $existing=$pdo->prepare('SELECT fingerprint FROM '.arc_table('platform_release_history').' WHERE version=?');$existing->execute([$version]);$known=$existing->fetchColumn();
                if($known!==false&&$known!==$hash)throw new RuntimeException('Esta versão já identifica outro conteúdo. Informe uma versão nova.');
                $pdo->prepare('INSERT INTO '.arc_table('platform_release_history').' (version,fingerprint,notes,source,created_by,files_count,changes_json) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE notes=VALUES(notes)')->execute([$version,$hash,$notes!==''?$notes:'Publicação centralizada da plataforma.',$admin?'manual':($old?'automatic':'initial'),$admin,count($manifest),json_encode($changes,JSON_UNESCAPED_SLASHES)]);
                $pdo->prepare('INSERT INTO '.arc_table('platform_release_state').' (id,version,fingerprint,files_json) VALUES (1,?,?,?) ON DUPLICATE KEY UPDATE version=VALUES(version),fingerprint=VALUES(fingerprint),files_json=VALUES(files_json)')->execute([$version,$hash,json_encode($manifest,JSON_UNESCAPED_SLASHES)]);
                if(arc_app_version()!==$version)arc_release_write_version($version);$pdo->commit();$scan=['checked_at'=>time(),'signature'=>$signature];
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if(arc_app_version()!==$previousVersion)arc_release_write_version($previousVersion);throw $e;}
        }
        // Recupera instalações em que VERSION ficou atrás do estado já publicado.
        if(!$publish&&$old&&arc_app_version()!==$version)arc_release_write_version($version);
        $scan=['checked_at'=>time(),'signature'=>$signature];file_put_contents($stateFile,json_encode($scan),LOCK_EX);chmod($stateFile,0600);
    }catch(Throwable $e){if($force)throw $e;arc_log_error('Publicação automática: '.$e->getMessage());}finally{flock($lock,LOCK_UN);fclose($lock);}
}
function arc_release_require_published(): void {
    static $verified=false;
    if($verified)return;
    $row=arc_db()->query('SELECT version,fingerprint FROM '.arc_table('platform_release_state').' WHERE id=1')->fetch();
    // Caminho rápido: se nenhum arquivo mudou desde a última publicação (caminho, tamanho e data),
    // não recalcula o SHA-256 de toda a plataforma a cada consulta do modal/atualização.
    $scan=json_decode((string)@file_get_contents(__DIR__.'/storage/releases/scan.json'),true);
    if($row&&$row['version']===arc_app_version()&&is_array($scan)&&($scan['signature']??'')!==''&&hash_equals((string)$scan['signature'],arc_release_stat_signature())){$verified=true;return;}
    $hash=hash('sha256',json_encode(arc_release_code_manifest(),JSON_UNESCAPED_SLASHES));
    if(!$row||$row['version']!==arc_app_version()||!hash_equals($row['fingerprint'],$hash)){
        // Atualização remota: sincroniza o release antes de servir bytes autenticados.
        // Mantém a publicação transacional, inventário SHA-256 e validação de PHP.
        arc_release_sync(arc_db(),true);
        $row=arc_db()->query('SELECT version,fingerprint FROM '.arc_table('platform_release_state').' WHERE id=1')->fetch();
        $hash=hash('sha256',json_encode(arc_release_code_manifest(),JSON_UNESCAPED_SLASHES));
        if(!$row||$row['version']!==arc_app_version()||!hash_equals((string)$row['fingerprint'],$hash))
            throw new RuntimeException('Não foi possível publicar a atualização do gestor. Verifique as permissões da pasta raiz e os logs da plataforma.');
    }
    $verified=true;
}
function arc_release_metadata_source(): string {
    arc_release_require_published();$version=arc_app_version();$q=arc_db()->prepare('SELECT notes,fingerprint FROM '.arc_table('platform_release_history').' WHERE version=?');$q->execute([$version]);$row=$q->fetch()?:[];
    return "# Caminho: gestores/windows/arcoll_release.py\n# Metadados da instalação, gerados da versão central publicada.\nVERSION = ".json_encode($version)."\nBUILD = ".json_encode($row['fingerprint']??'')."\nNOTES = ".json_encode($row['notes']??'',JSON_UNESCAPED_SLASHES)."\n";
}

/** Complete managed installer sources, including future resource directories. Never includes enrollment secrets. */
function arc_gestor_installer_sources(): array {
    $root=dirname(__DIR__).'/gestores/windows';$files=arc_gestor_release_sources();
    $walk=function(string $directory)use(&$walk,&$files,$root):void{
        foreach(new DirectoryIterator($directory) as $entry){if($entry->isDot()||$entry->isLink())continue;$name=$entry->getFilename();$relative=str_replace('\\','/',substr($entry->getPathname(),strlen($root)+1));
            if(in_array(strtolower($name),['__pycache__','backup','.dados','instalar_arcoll.py','arcoll_session.py','gestor-config.json','gestor.json','appearance.json'],true)||str_starts_with($name,'.')||preg_match('/\.(?:log|pyc|env|updating)$/i',$name))continue;
            if(!preg_match('~^[A-Za-z0-9_./ -]+$~D',$relative)||str_contains($relative,'..'))throw new RuntimeException('Nome de recurso incompatível com o instalador.');
            if($entry->isDir()){$walk($entry->getPathname());continue;}
            if(!preg_match('/\.(?:py|ps1|bat|cmd|json|ico|png|jpg|jpeg|svg|txt|ttf|woff2?|dll|exe|pyd|dat)$/i',$name))continue;
            if($entry->getSize()===0&&preg_match('/\.(?:py|ps1)$/i',$name))continue;
            if($entry->getSize()>33554432)throw new RuntimeException('Recurso do instalador excede 32 MB.');
            if(!isset($files[$relative]))$files[$relative]=file_get_contents($entry->getPathname());
        }
    };$walk($root);$files['VERSION']=arc_app_version()."\n";$files['favicon.ico']=arc_support_icon(arc_db(),'gestor');
    foreach($files as $name=>&$data)if(str_ends_with(strtolower($name),'.ps1')&&!str_starts_with($data,"\xEF\xBB\xBF"))$data="\xEF\xBB\xBF".$data;unset($data);
    ksort($files,SORT_STRING);if(count($files)>2000||array_sum(array_map('strlen',$files))>134217728)throw new RuntimeException('Instalador excede o limite.');return $files;
}
function arc_gestor_installer_package(): array {
    $version=arc_app_version();$sources=arc_gestor_installer_sources();$temp=tempnam(sys_get_temp_dir(),'arc-installer-');if($temp===false)throw new RuntimeException('Instalador indisponível.');$zip=new ZipArchive();
    try{
        if($zip->open($temp,ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Não foi possível gerar o instalador.');$manifest=[];
        foreach($sources as $name=>$bytes){$zip->addFromString($name,$bytes);$manifest[]=['path'=>$name,'sha256'=>hash('sha256',$bytes),'size'=>strlen($bytes)];}
        $zip->close();$bytes=file_get_contents($temp);if(!is_string($bytes)||strlen($bytes)>33554432)throw new RuntimeException('Instalador excede o limite de download.');
        $q=arc_db()->prepare('SELECT notes FROM '.arc_table('platform_release_history').' WHERE version=?');$q->execute([arc_app_version()]);
        arc_release_require_published();if($version!==arc_app_version())throw new RuntimeException('A versão mudou durante o download. Tente novamente.');
        return ['ok'=>true,'version'=>$version,'notes'=>(string)($q->fetchColumn()?:''),'sha256'=>hash('sha256',$bytes),'archive'=>base64_encode($bytes),'manifest'=>$manifest];
    }finally{if(is_file($temp))unlink($temp);}
}
