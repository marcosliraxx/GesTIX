<?php
/* Caminho: api/download-support.php */
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
if(!arc_is_user()){http_response_code(401);exit('Entre na plataforma para baixar o aplicativo.');}
try{
 if(!class_exists('ZipArchive'))throw new RuntimeException('Ative a extensão PHP ZIP.');
 $pdo=arc_db();$origin=arc_billing_setting($pdo,'public_url');
 if($origin===''){
  $host=strtolower((string)($_SERVER['HTTP_HOST']??''));
  if(!preg_match('/^[a-z0-9.-]+(?::443)?$/D',$host))throw new RuntimeException('Configure a URL pública HTTPS da plataforma.');
  $origin='https://'.$host.rtrim(arc_workspace_base_url(),'/');
 }
 if(!preg_match('~^https://[a-z0-9.-]+(?::443)?(?:/[a-zA-Z0-9_/-]*)?$~D',$origin))throw new RuntimeException('Configure a URL HTTPS pública da plataforma em Gestão comercial.');
 $tmp=tempnam(sys_get_temp_dir(),'arc-support-');if($tmp===false)throw new RuntimeException('Arquivo temporário indisponível.');
 $zip=new ZipArchive();if($zip->open($tmp,ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Não foi possível criar o instalador.');
 foreach(['arcoll_support.py','Instalar_Suporte.py','Instalar_Suporte.cmd','requirements.txt'] as $name){$path=dirname(__DIR__).'/support/'.$name;if(!is_readable($path))throw new RuntimeException('Componente ausente: '.$name);$zip->addFile($path,$name);}
 $zip->addFile(dirname(__DIR__).'/agent/arcoll_toolkit.py','arcoll_toolkit.py');$zip->addFromString('platform.json',json_encode(['origin'=>$origin,'version'=>ARC_APP_VERSION],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
 $zip->addFromString('LEIA-ME.txt',"Arcoll Suporte ".ARC_APP_VERSION."\r\nExtraia todos os arquivos, instale Python 3.10+ se necessário e abra Instalar_Suporte.cmd.\r\nO instalador cria um ambiente Python isolado por usuário e instala Pillow pelo PyPI com TLS.\r\nNa plataforma, abra a rede > Suporte > Conectar pelo aplicativo Windows. Cole o código em até dois minutos.\r\nSessões são temporárias, auditadas, revogáveis e limitadas a 30 minutos. O aplicativo não guarda senhas ou tokens em disco.\r\nO programa precisa de internet HTTPS na porta 443; não exige abrir portas de entrada.\r\nNão é um executável compilado/assinado. Instalação e controle físico devem ser validados no Windows.\r\n");
 $zip->close();header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="Arcoll-Suporte-'.ARC_APP_VERSION.'.zip"');header('Cache-Control: no-store, private');header('Content-Length: '.filesize($tmp));readfile($tmp);unlink($tmp);
}catch(Throwable $e){if(isset($tmp)&&is_file($tmp))@unlink($tmp);error_log('Download suporte: '.$e->getMessage());http_response_code(503);echo 'Não foi possível gerar o aplicativo. Confira a configuração HTTPS e a extensão ZIP.';}
