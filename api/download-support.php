<?php
/* Caminho: api/download-support.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/error-logging.php';

require dirname(__DIR__).'/includes/bootstrap.php';
$packageMode = (($_GET['mode'] ?? '') === 'package');
$secret=(string)(arc_config()['app_secret'] ?? '');
if(strlen($secret)<24){http_response_code(503);exit('Configure uma chave de aplicacao segura.');}
if($packageMode){
 $expiry=(string)($_SERVER['HTTP_X_ARCOLL_EXPIRES']??'');
 $signature=(string)($_SERVER['HTTP_X_ARCOLL_SIGNATURE']??'');
 if(!ctype_digit($expiry)||(int)$expiry<time()||(int)$expiry>time()+86400||!preg_match('/^[a-f0-9]{64}$/D',$signature)||!hash_equals(hash_hmac('sha256','support|'.$expiry,$secret),$signature)){http_response_code(403);exit('Instalador expirado ou invalido.');}
}else if(!arc_is_user()){http_response_code(401);exit('Entre na plataforma para baixar o aplicativo.');}
try{
 if(!class_exists('ZipArchive'))throw new RuntimeException('Ative a extensão PHP ZIP.');
 $pdo=arc_db();arc_ensure_user_schema($pdo);$settings=arc_setting_values($pdo);$systemName=trim((string)($settings['site_name']??'Sistema'));$systemAscii=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$systemName)?:$systemName;$systemSlug=trim((string)preg_replace('/[^A-Za-z0-9_-]+/','_',$systemAscii),'_')?:'Sistema';$origin=arc_billing_setting($pdo,'public_url');
 if($origin===''){
  $host=strtolower((string)($_SERVER['HTTP_HOST']??''));
  if(!preg_match('/^[a-z0-9.-]+(?::443)?$/D',$host))throw new RuntimeException('Configure a URL pública HTTPS da plataforma.');
  $origin='https://'.$host.rtrim(arc_workspace_base_url(),'/');
 }
 if(!preg_match('~^https://[a-z0-9.-]+(?::443)?(?:/[a-zA-Z0-9_/-]*)?$~D',$origin))throw new RuntimeException('Configure a URL HTTPS pública da plataforma em Gestão comercial.');
 // A entrega inicial e a atualização autenticada utilizam o mesmo ZIP transparente.
 // O usuário extrai os arquivos e executa o instalador visível do pacote.
 $tmp=tempnam(sys_get_temp_dir(),'arc-support-');if($tmp===false)throw new RuntimeException('Arquivo temporário indisponível.');
 $zip=new ZipArchive();if($zip->open($tmp,ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Não foi possível criar o instalador.');
 foreach(['arcoll_support.py','Instalar_Suporte.py','Instalar_Suporte.bat','requirements.txt'] as $name){$path=dirname(__DIR__).'/gestores/suporte/'.$name;if(!is_readable($path))throw new RuntimeException('Componente ausente: '.$name);$zip->addFile($path,$name);}
 $zip->addFromString('arcoll_release.py',arc_release_metadata_source());
 $zip->addFromString('favicon.ico',arc_support_icon($pdo,'support'));
 $zip->addFile(dirname(__DIR__).'/gestores/windows/arcoll_toolkit.py','arcoll_toolkit.py');$zip->addFromString('platform.json',json_encode(['origin'=>$origin,'version'=>arc_app_version(),'design'=>arc_support_design($pdo,'support')],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
 $zip->addFromString('LEIA-ME.txt',"Arcoll Suporte ".arc_app_version()."\r\nExtraia todos os arquivos e abra Instalar_Suporte.bat para iniciar a instalação visual. Python 3.10+ é necessário.\r\nO instalador cria um ambiente Python isolado por usuário e instala Pillow pelo PyPI com TLS.\r\nEntre opcionalmente na sua conta para listar suas redes, ou use o código de 5 caracteres exibido pelo gestor. Acesso por código exige confirmação local.\r\nSessões são temporárias, auditadas, revogáveis e limitadas a 30 minutos. O aplicativo não guarda senhas ou tokens em disco.\r\nO programa precisa de internet HTTPS na porta 443; não exige abrir portas de entrada.\r\nNão é um executável compilado/assinado. Instalação e controle físico devem ser validados no Windows.\r\n");
 $zip->close();header('X-Arcoll-SHA256: '.hash_file('sha256',$tmp));header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="Arcoll-Suporte-'.arc_app_version().'.zip"');header('Cache-Control: no-store, private');header('Content-Length: '.filesize($tmp));readfile($tmp);unlink($tmp);
}catch(Throwable $e){if(isset($tmp)&&is_file($tmp))@unlink($tmp);arc_log_error('Download suporte: '.$e->getMessage());http_response_code(503);echo 'Não foi possível gerar o aplicativo. Confira a configuração HTTPS e a extensão ZIP.';}
