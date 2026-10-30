<?php
declare(strict_types=1);

// Run against a disposable test database only. Never use production credentials.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$dsn = getenv('GESTI_TEST_DSN');
if (!$dsn) { fwrite(STDERR, "Set GESTI_TEST_DSN to a disposable MySQL/MariaDB database.\n"); exit(2); }
function test_connection(): PDO {
    return new PDO((string)getenv('GESTI_TEST_DSN'), (string)(getenv('GESTI_TEST_DB_USER') ?: 'root'), (string)(getenv('GESTI_TEST_DB_PASSWORD') ?: ''), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
}
$pdo = test_connection();
$database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$table = ($argv[1] ?? '') === 'worker' ? (string)($argv[2] ?? '') : 'arc_login_test_' . bin2hex(random_bytes(6));
if (!preg_match('/^arc_login_test_[a-f0-9]{12}$/', $table)) throw new RuntimeException('Invalid test table');
$limit = 5;
$settingsFailure = false;
$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
function arc_config(): array { global $database; return ['db_name'=>$database, 'app_secret'=>'integration-test-only']; }
function arc_table(string $name): string { global $table; if ($name !== 'account_login_attempts') throw new RuntimeException('Unexpected table'); return '`'.$table.'`'; }
function arc_platform_setting(PDO $pdo, string $key, string $default = ''): string {
    global $limit, $settingsFailure;
    if ($settingsFailure) throw new RuntimeException('Simulated settings failure');
    return $limit === null ? $default : (string)$limit;
}
require dirname(__DIR__).'/includes/login-security.php';
if (($argv[1] ?? '') === 'worker') {
    $limit = 3;
    echo "READY\n"; flush();
    $id = arc_login_attempt_begin($pdo, 'parallel@example.test');
    echo ($id === null ? 'blocked' : 'reserved')."\n";
    exit;
}
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rows(PDO $pdo): int { return (int)$pdo->query('SELECT COUNT(*) FROM '.arc_table('account_login_attempts'))->fetchColumn(); }
$passed = 0;
function run_test(string $name, callable $test): void {
    global $pdo, $limit, $settingsFailure, $passed;
    $pdo->exec('TRUNCATE TABLE '.arc_table('account_login_attempts'));
    $limit = 5; $settingsFailure = false; $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
    $test(); $passed++; echo "PASS $name\n";
}
$pdo->exec('CREATE TABLE '.arc_table('account_login_attempts').' (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ip_hash CHAR(64) NOT NULL, identifier_hash CHAR(64) NOT NULL, attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (ip_hash,attempted_at)) ENGINE=InnoDB');
try {
    run_test('shared IP budget across different accounts', function() use ($pdo) {
        global $limit; $limit = 3;
        foreach (['a','b','c'] as $account) check(arc_login_attempt_begin($pdo, $account.'@example.test') !== null, 'Allowed slot rejected');
        check(arc_login_attempt_begin($pdo, 'd@example.test') === null, 'Limit exceeded');
        check(rows($pdo) === 3, 'Blocked request added a row');
    });
    run_test('successful authentication preserves unrelated and previous failures', function() use ($pdo) {
        $failure = arc_login_attempt_begin($pdo, 'other@example.test');
        arc_login_attempt_begin($pdo, 'owner@example.test');
        $success = arc_login_attempt_begin($pdo, 'owner@example.test');
        arc_login_attempt_succeeded($pdo, $success);
        check(rows($pdo) === 2, 'Success erased previous failures');
        $q=$pdo->prepare('SELECT COUNT(*) FROM '.arc_table('account_login_attempts').' WHERE id=?'); $q->execute([$failure]);
        check((int)$q->fetchColumn() === 1, 'Unrelated account failure removed');
    });
    run_test('successful login does not consume a failure slot', function() use ($pdo) {
        $id = arc_login_attempt_begin($pdo, 'owner@example.test'); arc_login_attempt_succeeded($pdo, $id);
        check(rows($pdo) === 0, 'Successful login counted as failure');
    });
    run_test('different IP has its own budget and cannot delete another IP slot', function() use ($pdo) {
        global $limit; $limit=3;
        $id=arc_login_attempt_begin($pdo,'owner@example.test');
        arc_login_attempt_begin($pdo,'owner@example.test'); arc_login_attempt_begin($pdo,'owner@example.test');
        $_SERVER['REMOTE_ADDR']='192.0.2.11';
        check(arc_login_attempt_begin($pdo,'owner@example.test') !== null, 'Other IP blocked');
        arc_login_attempt_succeeded($pdo,$id); check(rows($pdo) === 4,'Other IP row deleted');
    });
    run_test('expired attempts no longer block authentication', function() use ($pdo) {
        global $limit; $limit=3;
        for($i=0;$i<3;$i++) arc_login_attempt_begin($pdo,'owner@example.test');
        $pdo->exec('UPDATE '.arc_table('account_login_attempts').' SET attempted_at=DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 16 MINUTE)');
        check(arc_login_attempt_begin($pdo,'owner@example.test') !== null,'Expired attempts counted');
    });
    run_test('default and configured bounds are enforced', function() use ($pdo) {
        global $limit;
        foreach ([[null,5],[1,3],[99,30]] as [$setting,$expected]) {
            $pdo->exec('TRUNCATE TABLE '.arc_table('account_login_attempts')); $limit=$setting;
            for($i=0;$i<$expected;$i++) check(arc_login_attempt_begin($pdo,'owner@example.test') !== null,'Expected slot rejected');
            check(arc_login_attempt_begin($pdo,'owner@example.test') === null,'Limit not enforced');
        }
    });
    run_test('forwarded headers cannot reset an IP budget', function() use ($pdo) {
        global $limit; $limit=3;
        for($i=0;$i<3;$i++) { $_SERVER['HTTP_X_FORWARDED_FOR']='192.0.2.'.(20+$i); arc_login_attempt_begin($pdo,'owner@example.test'); }
        $_SERVER['HTTP_X_FORWARDED_FOR']='192.0.2.99';
        check(arc_login_attempt_begin($pdo,'owner@example.test') === null,'Untrusted header bypassed limit');
    });
    run_test('database lock is released on failure and on throttled requests', function() use ($pdo) {
        global $limit,$settingsFailure;
        $settingsFailure=true;
        try { arc_login_attempt_begin($pdo,'owner@example.test'); throw new LogicException('Expected settings failure'); }
        catch (RuntimeException $expected) { check($expected->getMessage()==='Simulated settings failure','Unexpected failure'); }
        $settingsFailure=false; $limit=3; $other=test_connection();
        for($i=0;$i<3;$i++) check(arc_login_attempt_begin($other,'owner@example.test') !== null,'Lock leaked after exception');
        check(arc_login_attempt_begin($pdo,'owner@example.test') === null,'Expected throttle');
        $limit=5; check(arc_login_attempt_begin($other,'owner@example.test') !== null,'Lock leaked on throttle');
    });
    run_test('concurrent connections cannot overbook the final slots', function() use ($pdo,$table) {
        global $database;
        $lock='arc-login:'.substr(hash('sha256',$database.arc_table('account_login_attempts').arc_login_ip_hash()),0,54);
        $q=$pdo->prepare('SELECT GET_LOCK(?, 2)'); $q->execute([$lock]); check((int)$q->fetchColumn()===1,'Cannot set test barrier');
        $workers=[];
        try {
            for($i=0;$i<6;$i++) {
                $process=proc_open([PHP_BINARY,__FILE__,'worker',$table],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
                check(is_resource($process),'Worker could not start'); fclose($pipes[0]);
                $workers[]=[$process,$pipes];
                check(trim((string)fgets($pipes[1]))==='READY','Worker failed before barrier');
            }
        } finally { $q=$pdo->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([$lock]); }
        $reserved=0; $blocked=0;
        foreach($workers as [$process,$pipes]) {
            $result=trim(stream_get_contents($pipes[1])); $error=stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); check(proc_close($process)===0,'Worker failure: '.$error);
            $reserved+=(int)($result==='reserved'); $blocked+=(int)($result==='blocked');
        }
        check($reserved===3 && $blocked===3 && rows($pdo)===3,'Concurrent requests exceeded the budget');
    });
    echo "$passed database integration tests passed.\n";
} finally { $pdo->exec('DROP TABLE IF EXISTS '.arc_table('account_login_attempts')); }
