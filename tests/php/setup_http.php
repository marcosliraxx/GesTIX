<?php
declare(strict_types=1);
$source=dirname(__DIR__,2);$root=__DIR__.'/site';
function copyTree(string $source,string $destination):void{if(!is_dir($destination))mkdir($destination,0700,true);foreach(new DirectoryIterator($source)as $entry){if($entry->isDot()||$entry->isLink()||in_array($entry->getFilename(),['bin','obj','__pycache__'],true))continue;$dest=$destination.'/'.$entry->getFilename();if($entry->isDir())copyTree($entry->getPathname(),$dest);else copy($entry->getPathname(),$dest);}}
foreach(['api','includes','assets/themes','gestores/windows']as $directory)@mkdir($root.'/'.$directory,0700,true);
foreach(['gestor-logs.php','download-gestor.php']as $name)copy($source.'/api/'.$name,$root.'/api/'.$name);
foreach(['error-logging.php','central-logs.php','visual-themes.php']as $name)copy($source.'/includes/'.$name,$root.'/includes/'.$name);
copy($source.'/assets/themes/catalog.json',$root.'/assets/themes/catalog.json');copyTree($source.'/gestores/servidor',$root.'/gestores/servidor');copyTree($source.'/gestores/windows',$root.'/gestores/windows');
file_put_contents($root.'/includes/config.php','<?php // Synthetic fixture. No production configuration.');
$auth=file_get_contents($source.'/includes/bootstrap.php');$auth=substr($auth,strpos($auth,'function arc_gestor_authenticate('));
$bootstrap=<<<'PHP'
<?php
declare(strict_types=1);
require_once __DIR__.'/error-logging.php';
final class FixturePDO extends PDO {
 public function prepare(string $query,array $options=[]):PDOStatement|false {
  $query=preg_replace('/DATE_SUB\(NOW\(\),\s*INTERVAL\s+24\s+HOUR\)/i',"datetime('now','-24 hours')",$query);
  return parent::prepare($query,$options);
 }
}
function arc_db():PDO{static $pdo;return $pdo??=new FixturePDO('sqlite:'.dirname(__DIR__).'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}
function arc_table(string $table):string{return '`'.$table.'`';}
function arc_ensure_user_schema(PDO $pdo):void{}
function arc_json(array $data,int $status=200):never{http_response_code($status);header('Content-Type: application/json');echo json_encode($data);exit;}
function arc_app_version():string{return '5.0.152';}
function arc_base_url():string{return 'https://fixture.test';}
function arc_is_user():bool{return ($_SERVER['HTTP_X_FIXTURE_USER']??'')==='1';}
function arc_user_id():int{return 1;}
function arc_current_network_id():int{return 2;}
function arc_verify_csrf():void{if(($_SERVER['HTTP_X_FIXTURE_CSRF']??'')!=='fixture')arc_json(['ok'=>false],403);}
function arc_support_icon(PDO $p,string $kind):string{return 'synthetic-icon';}
function arc_program_install_catalog():array{return [];}
function arc_clean_catalog():array{return [];}
function arc_clean_registry_checks():array{return [];}
function arc_clean_health_sets():array{return [];}
function arc_clean_tabs():array{return [];}
function arc_clean_age_options():array{return [];}
function arc_visual_theme(PDO $pdo):array{require_once __DIR__.'/visual-themes.php';return arc_theme_catalog()['sapphire']+['mode'=>'theme'];}
PHP;
file_put_contents($root.'/includes/bootstrap.php',$bootstrap."\n".$auth);
file_put_contents($root.'/includes/support.php','<?php');file_put_contents($root.'/includes/network-clean.php','<?php');
require $root.'/includes/bootstrap.php';$pdo=arc_db();
$pdo->exec('CREATE TABLE user_accounts(id INTEGER,account_status TEXT);CREATE TABLE managed_networks(id INTEGER,account_id INTEGER,is_active INTEGER,monitoring_enabled INTEGER,site_code TEXT);CREATE TABLE network_gestores(id INTEGER,network_id INTEGER,account_id INTEGER,fingerprint TEXT,site_code TEXT,status TEXT);CREATE TABLE gestor_download_keys(token_hash TEXT,account_id INTEGER,network_id INTEGER,site_code TEXT,created_at TEXT,fingerprint TEXT);');
$pdo->exec("INSERT INTO user_accounts VALUES(1,'active'),(9,'active');INSERT INTO managed_networks VALUES(2,1,1,1,'TEST1'),(8,9,1,1,'TEST9')");
$pdo->prepare("INSERT INTO network_gestores VALUES(3,2,1,?,'TEST1','approved')")->execute([str_repeat('b',64)]);
$q=$pdo->prepare('INSERT INTO gestor_download_keys VALUES(?,?,?,?,datetime(\'now\'),?)');$q->execute([hash('sha256',str_repeat('a',64)),1,2,'TEST1',str_repeat('b',64)]);$q->execute([hash('sha256',str_repeat('c',64)),1,2,'TEST1',null]);$q->execute([hash('sha256',str_repeat('d',64)),9,8,'TEST9',null]);
echo "Synthetic log/install API site ready.\n";
