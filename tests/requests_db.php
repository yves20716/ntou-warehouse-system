<?php
declare(strict_types=1);

if (getenv('DB_NAME') !== 'warehouse_qa' || getenv('DB_HOST') !== '127.0.0.1') {
    fwrite(STDERR, "Use only the dedicated loopback warehouse_qa database.\n");
    exit(2);
}
putenv('WAREHOUSE_SESSION_NAME=NTOU_WAREHOUSE_REQUEST_REGRESSION');
ini_set('session.save_path', sys_get_temp_dir());
session_id('qa' . bin2hex(random_bytes(16)));
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/mutations.php';
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function denied(callable $action): void {
    try { $action(); } catch (DomainException $e) { return; }
    throw new RuntimeException('Expected operation rejection.');
}
function request_id(int $goods): int {
    return (int)rows('SELECT id FROM stock_requests WHERE goods_id = ? ORDER BY id DESC LIMIT 1', [$goods])[0]['id'];
}
function balance(int $lot): int { return (int)rows('SELECT quantity FROM inventory WHERE id = ?', [$lot])[0]['quantity']; }
if (($argv[1] ?? '') === 'worker') {
    echo "READY\n"; fflush(STDOUT); fgets(STDIN);
    try {
        $_POST = ['id' => $argv[2]];
        complete_stock_request(2);
        echo "success\n";
    } catch (DomainException $e) { echo "rejected\n"; }
    exit;
}
function concurrent(int $first, int $second): array {
    $workers=[];
    foreach ([$first,$second] as $id) {
        $proc=proc_open([PHP_BINARY,__FILE__,'worker',(string)$id], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        check(is_resource($proc) && trim((string)fgets($pipes[1]))==='READY','Worker start failed.');
        $workers[]=[$proc,$pipes];
    }
    foreach ($workers as [$proc,$pipes]) { fwrite($pipes[0],"GO\n"); fclose($pipes[0]); }
    $results=[];
    foreach ($workers as [$proc,$pipes]) {
        $results[]=trim(stream_get_contents($pipes[1])); $err=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);check(proc_close($proc)===0 && $err==='','Worker failed: '.$err);
    }
    sort($results); return $results;
}
$tag=bin2hex(random_bytes(4)); $name='申請回歸'.$tag;
$_POST=['id'=>'','client_id'=>'2','name'=>$name,'unit'=>'箱'];save_goods(1);
$goods=(int)rows('SELECT id FROM goods WHERE name=? AND client_id=1',[$name])[0]['id'];
check(rows('SELECT id FROM goods WHERE name=? AND client_id=2',[$name])===[],'Forged client ownership accepted.');
$_POST=['id'=>(string)$goods,'name'=>$name.'非法修改','unit'=>'箱'];denied(fn()=>save_goods(2));
$_POST=['goods_id'=>(string)$goods,'quantity'=>'10'];denied(fn()=>create_stock_request('in',2));
create_stock_request('in',1);$in=request_id($goods);
check(rows('SELECT id FROM inventory WHERE goods_id=?',[$goods])===[],'Pending receipt changed stock.');
$_POST=['id'=>(string)$goods,'client_id'=>'2','name'=>$name,'unit'=>'台'];denied(fn()=>save_goods());
$_POST=['id'=>(string)$in];denied(fn()=>cancel_stock_request(2));
$_SESSION['user_id']=rows("SELECT id FROM users WHERE role='client' AND client_id=2 LIMIT 1")[0]['id'];
check(request_data($name)===[],'Request query leaked another tenant.');
$_SESSION['user_id']=rows("SELECT id FROM users WHERE role='client' AND client_id=1 LIMIT 1")[0]['id'];
check(count(request_data($name))===1,'Own request missing.');
echo "PASS tenant-owned goods and requests, owner/unit history lock, request query isolation\n";
$_POST=['id'=>(string)$in,'section_id'=>'3'];denied(fn()=>complete_stock_request(2));
check(rows('SELECT status FROM stock_requests WHERE id=?',[$in])[0]['status']==='pending','Failed receipt changed request status.');
$_POST=['id'=>(string)$in,'section_id'=>'1'];
try {complete_stock_request(2147483647);throw new RuntimeException('Missing employee accepted.');}
catch(PDOException $e) {check(in_array((int)$e->errorInfo[1],[1216,1452],true),'Unexpected SQL failure.');}
check(rows('SELECT id FROM inventory WHERE goods_id=?',[$goods])===[],'Failed receipt retained lot.');
complete_stock_request(2);
$lot=(int)rows('SELECT id FROM inventory WHERE goods_id=?',[$goods])[0]['id'];
check(balance($lot)===10,'Receipt balance incorrect.');denied(fn()=>complete_stock_request(2));
check(count(rows('SELECT id FROM movements WHERE stock_id=?',[$lot]))===1,'Repeated completion created extra movement.');
echo "PASS receipt validation, atomic rollback and repeated completion rejection\n";
$_POST=['stock_id'=>(string)$lot,'quantity'=>'3'];denied(fn()=>create_stock_request('out',2));
create_stock_request('out',1);$out=(int)rows('SELECT id FROM stock_requests WHERE stock_id=? ORDER BY id DESC LIMIT 1',[$lot])[0]['id'];
check(balance($lot)===10,'Pending dispatch reserved/deducted stock.');
$_POST=['id'=>(string)$out];
try {complete_stock_request(2147483647);throw new RuntimeException('Missing employee accepted.');}
catch(PDOException $e) {check(in_array((int)$e->errorInfo[1],[1216,1452],true),'Unexpected SQL failure.');}
check(balance($lot)===10,'Failed dispatch changed stock.');
check(concurrent($out,$out)===['rejected','success'],'Concurrent completion applied twice.');
check(balance($lot)===7,'Dispatch should leave seven.');
$_POST=['stock_id'=>(string)$lot,'quantity'=>'8'];denied(fn()=>create_stock_request('out',1));
$pending=[];
foreach ([1,2] as $unused) {
    $_POST=['stock_id'=>(string)$lot,'quantity'=>'5'];create_stock_request('out',1);
    $pending[]=(int)rows('SELECT id FROM stock_requests WHERE stock_id=? ORDER BY id DESC LIMIT 1',[$lot])[0]['id'];
}
check(concurrent($pending[0],$pending[1])===['rejected','success'],'Competing requests overshipped.');
check(balance($lot)===2,'Competing requests left wrong balance.');
$ledger=rows("SELECT SUM(IF(type='in',quantity,-quantity)) balance FROM movements WHERE stock_id=?",[$lot])[0];
check((int)$ledger['balance']===2,'Ledger mismatch.');
echo "PASS dispatch tenant checks, no pending deduction, concurrent completion and overship protection\n";
foreach (['cancel','reject'] as $mode) {
    $_POST=['goods_id'=>(string)$goods,'quantity'=>'1'];create_stock_request('in',1);$id=request_id($goods);
    $_POST=['id'=>(string)$id,'response_note'=>'請確認實際到貨數量。'];
    if($mode==='cancel')cancel_stock_request(1);else reject_stock_request(2);
    denied(fn()=>complete_stock_request(2));
    check(balance($lot)===2,'Closed request changed stock.');
}
echo "PASS cancellation/rejection preserve history and cannot complete\n";
echo "PASS request database regressions; QA tag {$tag}\n";
