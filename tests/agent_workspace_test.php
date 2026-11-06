<?php
/* Caminho: tests/agent_workspace_test.php */
declare(strict_types=1);
function arc_table(string $name): string { return 'test_'.$name; }
function arc_billing_assert_active(PDO $pdo,int $account): void { if($account!==10)throw new RuntimeException('Conta incorreta na cobrança.'); }
define('ARC_PRIVATE_DIR',sys_get_temp_dir().'/agent-workspace-fixture');
require __DIR__.'/../includes/agent-workspace.php';
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE test_managed_networks (id INTEGER,account_id INTEGER,name TEXT,logo_file TEXT)');
$pdo->exec("INSERT INTO test_managed_networks VALUES (1,10,'Rede própria',''),(2,20,'Outra conta',''),(3,10,'Outra rede','')");
$pdo->exec('CREATE TABLE test_support_tickets (id INTEGER PRIMARY KEY AUTOINCREMENT,account_id INTEGER,network_id INTEGER,agent_id INTEGER,created_by INTEGER,title TEXT,description TEXT,priority TEXT,status TEXT DEFAULT "open",due_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE test_support_events (ticket_id INTEGER,actor_id INTEGER,event_type TEXT,message TEXT,minutes_spent INTEGER)');
$pdo->exec("INSERT INTO test_support_tickets(account_id,network_id,agent_id,title,created_at) VALUES(10,1,7,'Próprio','2000-01-01'),(10,1,8,'Outro computador','2000-01-01'),(20,2,7,'Outra conta','2000-01-01'),(10,3,7,'Outra rede','2000-01-01')");
$agent=['account_id'=>10,'network_id'=>1,'id'=>7];
$info=arc_agent_workspace($pdo,$agent,['op'=>'info','account_id'=>20,'network_id'=>2,'agent_id'=>8]);
check($info['network_id']===1&&$info['agent_id']===7,'Corpo alterou escopo da identidade.');
$list=arc_agent_workspace($pdo,$agent,['op'=>'tickets','agent_id'=>8]);check(count($list['tickets'])===1&&$list['tickets'][0]['title']==='Próprio','Vazamento de chamados entre contas/redes/computadores.');
$made=arc_agent_workspace($pdo,$agent,['op'=>'ticket-create','account_id'=>20,'network_id'=>2,'agent_id'=>8,'title'=>'Teste sintético','description'=>'Sem dados reais.','priority'=>'normal']);
$row=$pdo->query('SELECT * FROM test_support_tickets WHERE id='.(int)$made['ticket_id'])->fetch(PDO::FETCH_ASSOC);
check((int)$row['account_id']===10&&(int)$row['network_id']===1&&(int)$row['agent_id']===7,'Chamado criado em outro escopo.');
check(str_starts_with($row['description'],'Origem: agente Windows #7'),'Origem automatizada não foi identificada.');
check($pdo->query('SELECT event_type FROM test_support_events')->fetchColumn()==='agent_created','Evento não identifica abertura pelo agente.');
try{arc_agent_workspace($pdo,$agent,['op'=>'ticket-create','title'=>'','description'=>'bad']);throw new RuntimeException('Título vazio aceito.');}catch(InvalidArgumentException $expected){}
for($i=0;$i<4;$i++)arc_agent_workspace($pdo,$agent,['op'=>'ticket-create','title'=>'Sintético '.$i,'description'=>'Teste','priority'=>'low']);
try{arc_agent_workspace($pdo,$agent,['op'=>'ticket-create','title'=>'Limite','description'=>'Teste']);throw new RuntimeException('Limite não aplicado.');}catch(DomainException $expected){check($expected->getCode()===429,'Código de limite incorreto.');}
$pdo->exec("UPDATE test_managed_networks SET logo_file='../../private' WHERE id=1");
check(arc_agent_workspace($pdo,$agent,['op'=>'info'])['logo_base64']==='','Nome inválido abriu arquivo privado.');
echo "PASS: escopo autenticado, chamados isolados, origem identificada, validação, limite e logo.\n";
