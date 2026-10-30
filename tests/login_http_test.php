<?php
declare(strict_types=1);
// Requires a separate application instance whose database ends in _login_http_test.
if (PHP_SAPI !== 'cli' || getenv('GESTI_LOGIN_HTTP_TEST') !== '1') { http_response_code(403); exit(2); }
$_SERVER['REQUEST_METHOD']='GET'; $_SERVER['REMOTE_ADDR']='127.0.0.1';
require dirname(__DIR__).'/includes/bootstrap.php';
if (!str_ends_with((string)(arc_config()['db_name'] ?? ''), '_login_http_test')) throw new RuntimeException('A disposable HTTP test database is required');
$pdo=arc_db(); arc_ensure_user_schema($pdo);
function check_http(bool $condition,string $message): void { if(!$condition)throw new RuntimeException($message); }
function request_http(string $path,array &$cookies,?array $data=null,bool $json=false):array {
    $headers=[];
    if($cookies) $headers[]='Cookie: '.implode('; ',array_map(static fn($k,$v)=>$k.'='.$v,array_keys($cookies),$cookies));
    if($data!==null)$headers[]='Content-Type: '.($json?'application/json':'application/x-www-form-urlencoded');
    $options=['method'=>$data===null?'GET':'POST','header'=>implode("\r\n",$headers),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>10];
    if($data!==null)$options['content']=$json?json_encode($data):http_build_query($data);
    $body=file_get_contents('http://127.0.0.1/'.$path,false,stream_context_create(['http'=>$options]));
    check_http(is_string($body),'HTTP request failed');
    $responseHeaders=$http_response_header;
    foreach($responseHeaders as $header)if(preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i',$header,$m))$cookies[$m[1]]=$m[2];
    preg_match('/\s(\d{3})\s/',$responseHeaders[0],$m);
    return [(int)$m[1],$body,$responseHeaders];
}
function browser_login(string $email,string $password,string $mode='login',?string $csrfOverride=null):array {
    $cookies=[];[$status,$body]=request_http('index.php',$cookies);
    check_http($status===200 && preg_match('/name="_csrf" value="([a-f0-9]+)"/',$body,$m)===1,'Login form unavailable');
    $data=['_csrf'=>$csrfOverride??$m[1],'email'=>$email,'password'=>$password,'mode'=>$mode];
    if($mode==='register')$data+=['display_name'=>'Test Account','confirm_password'=>$password];
    $response=request_http('index.php',$cookies,$data);
    $response[]=$cookies;
    return $response;
}
function api_login(string $password):array {
    global $fingerprint,$downloadKey;
    $cookies=[];
    return request_http('api/api.php?action=agent-settings-auth',$cookies,['fingerprint'=>$fingerprint,'site_code'=>'T3ST1','download_key'=>$downloadKey,'email'=>'http-user@example.test','password'=>$password],true);
}
function attempt_count():int {global $pdo;return (int)$pdo->query('SELECT COUNT(*) FROM '.arc_table('account_login_attempts'))->fetchColumn();}
$passed=0;
function http_test(string $name,callable $test):void {
    global $pdo,$passed;
    $pdo->exec('TRUNCATE TABLE '.arc_table('account_login_attempts'));
    $test();$passed++;echo "PASS $name\n";
}
arc_platform_save_setting($pdo,'platform_login_max_attempts','3');
arc_platform_save_setting($pdo,'platform_registration_enabled','1');
$password='test-only-password-12345';
$q=$pdo->prepare('INSERT INTO '.arc_table('user_accounts').' (email,email_hash,display_name,password_hash,access_notes) VALUES (?,?,?,?,?)');
$q->execute(['http-user@example.test',hash('sha256','http-user@example.test'),'HTTP Test',password_hash(base64_encode(hash('sha384',$password,true)),PASSWORD_DEFAULT),'']);
$userId=(int)$pdo->lastInsertId();
$q=$pdo->prepare('INSERT INTO '.arc_table('admins').' (email,password_hash) VALUES (?,?)');
$q->execute(['http-admin@example.test',password_hash($password,PASSWORD_DEFAULT)]);
$q=$pdo->prepare('INSERT INTO '.arc_table('managed_networks').' (account_id,name,site_code,monitoring_enabled,notes) VALUES (?,?,?,1,?)');
$q->execute([$userId,'HTTP Test Network','T3ST1','']);$networkId=(int)$pdo->lastInsertId();
$fingerprint=hash('sha256','http-test-agent');$downloadKey=bin2hex(random_bytes(32));
$q=$pdo->prepare('INSERT INTO '.arc_table('network_agents').' (fingerprint,account_id,network_id,site_code,status) VALUES (?,?,?,?,?)');
$q->execute([$fingerprint,$userId,$networkId,'T3ST1','approved']);
$q=$pdo->prepare('INSERT INTO '.arc_table('agent_download_keys').' (token_hash,account_id,network_id,site_code,fingerprint) VALUES (?,?,?,?,?)');
$q->execute([hash('sha256',$downloadKey),$userId,$networkId,'T3ST1',$fingerprint]);
http_test('invalid CSRF does not spend an authentication slot',function()use($password){
    [$status,$body]=browser_login('http-user@example.test',$password,'login','invalid');
    check_http($status===200 && str_contains($body,'Sua sessão expirou'),'CSRF was not rejected');
    check_http(attempt_count()===0,'Invalid CSRF consumed a slot');
});
http_test('user login succeeds without erasing another account failure',function()use($password){
    browser_login('other@example.test','wrong-password');
    [$status,,,$cookies]=browser_login('http-user@example.test',$password);
    check_http(request_http('index.php',$cookies)[0]===302,'User session not retained');
    check_http($status===303 && attempt_count()===1,'User login erased failures or did not redirect');
});
http_test('admin login succeeds without erasing another account failure',function()use($password){
    browser_login('other@example.test','wrong-password');
    [$status,,,$cookies]=browser_login('http-admin@example.test',$password);
    check_http(request_http('index.php',$cookies)[0]===302,'Admin session not retained');
    check_http($status===303 && attempt_count()===1,'Admin login erased failures or did not redirect');
});
http_test('browser and agent API share the configured limit and return 429',function()use($password){
    browser_login('other@example.test','wrong-password');
    check_http(api_login('wrong-password')[0]===403,'Expected API rejection');
    check_http(api_login('wrong-password')[0]===403,'Third slot should be available');
    [$status,,$headers]=browser_login('http-user@example.test',$password);
    check_http($status===429 && in_array('Retry-After: 900',$headers,true),'Browser throttle response incorrect');
    [$status,$body,$headers]=api_login($password);
    check_http($status===429 && in_array('Retry-After: 900',$headers,true),'API did not share the limit');
    check_http(json_decode($body,true)['ok']===false && attempt_count()===3,'Blocked requests changed attempts');
});
http_test('successful agent authentication preserves browser failures',function()use($password){
    browser_login('other@example.test','wrong-password');
    [$status,$body]=api_login($password);
    check_http($status===200 && json_decode($body,true)['ok']===true,'Agent authentication failed');
    check_http(attempt_count()===1,'Agent authentication erased browser failures');
});
http_test('expired limit permits a correct browser login',function()use($password,$pdo){
    for($i=0;$i<3;$i++)browser_login('other@example.test','wrong-password');
    $pdo->exec('UPDATE '.arc_table('account_login_attempts').' SET attempted_at=DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 16 MINUTE)');
    check_http(browser_login('http-user@example.test',$password)[0]===303,'Expired budget still blocked login');
});
http_test('registration succeeds without erasing login failures',function()use($password){
    browser_login('other@example.test','wrong-password');
    [$status,,,$cookies]=browser_login('http-new@example.test',$password,'register');
    check_http($status===303,'Registration failed');
    check_http(request_http('index.php',$cookies)[0]===302,'New account session not retained');
    check_http(attempt_count()===1,'Registration erased failures');
});
echo "$passed HTTP integration tests passed.\n";
