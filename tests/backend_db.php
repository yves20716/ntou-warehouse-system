<?php
declare(strict_types=1);

// Run with the private QA environment already loaded; never accept the main database.
if (getenv('DB_NAME') !== 'warehouse_qa' || getenv('DB_HOST') !== '127.0.0.1') {
    fwrite(STDERR, "Load the dedicated loopback warehouse_qa environment first.\n");
    exit(2);
}
putenv('WAREHOUSE_SESSION_NAME=NTOU_WAREHOUSE_DB_REGRESSION');
ini_set('session.save_path', sys_get_temp_dir());
session_id('qa' . bin2hex(random_bytes(16)));
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/mutations.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function employee_values(string $name, string $id = '', string $manager = ''): array
{
    return ['id' => $id, 'manager_id' => $manager, 'name' => $name, 'job_title' => '回歸測試員',
            'hourly_rate' => '210', 'birth_date' => '', 'overtime_hours' => '0', 'insurer' => ''];
}

if (($argv[1] ?? '') === 'worker') {
    echo "READY\n";
    fflush(STDOUT);
    fgets(STDIN);
    try {
        if ($argv[2] === 'out') {
            $_POST = ['stock_id' => $argv[3], 'quantity' => '7'];
            stock_out(2);
        } else {
            $_POST = employee_values($argv[5], $argv[3], $argv[4]);
            save_employee();
        }
        echo "success\n";
    } catch (DomainException $error) {
        echo "rejected\n";
    }
    exit;
}

/** Release two independent PHP processes after both are ready to enter a transaction. */
function concurrent(array $arguments): array
{
    $workers = [];
    foreach ($arguments as $args) {
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        check(is_resource($process), 'Could not start concurrent test process.');
        check(trim((string)fgets($pipes[1])) === 'READY', 'Worker failed to initialize.');
        $workers[] = [$process, $pipes];
    }
    foreach ($workers as [$process, $pipes]) {
        fwrite($pipes[0], "GO\n");
        fclose($pipes[0]);
    }
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $results[] = trim(stream_get_contents($pipes[1]));
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        check(proc_close($process) === 0 && $errors === '', 'Concurrent worker failed.');
    }
    sort($results);
    return $results;
}

$tag = bin2hex(random_bytes(4));
$goodsName = '交易回歸' . $tag;
$_POST = ['id' => '', 'client_id' => '1', 'name' => $goodsName, 'unit' => '箱'];
save_goods();
$goodsId = (int)rows('SELECT id FROM goods WHERE name = ?', [$goodsName])[0]['id'];
$_POST = ['goods_id' => (string)$goodsId, 'section_id' => '1', 'quantity' => '10'];
try {
    stock_in(2147483647);
    throw new RuntimeException('Missing employee unexpectedly accepted.');
} catch (PDOException $error) {
    check(in_array((int)$error->errorInfo[1], [1216, 1452], true), 'Expected a foreign-key failure; driver code ' . $error->errorInfo[1]);
}
check(!db()->inTransaction(), 'Failed receipt left an open transaction.');
check(rows('SELECT id FROM inventory WHERE goods_id = ?', [$goodsId]) === [], 'Failed receipt kept its inventory row.');
stock_in(2);
$batch = (int)rows('SELECT id FROM inventory WHERE goods_id = ?', [$goodsId])[0]['id'];
$_POST = ['stock_id' => (string)$batch, 'quantity' => '3'];
try {
    stock_out(2147483647);
    throw new RuntimeException('Missing employee unexpectedly accepted.');
} catch (PDOException $error) {
    check(in_array((int)$error->errorInfo[1], [1216, 1452], true), 'Expected a foreign-key failure; driver code ' . $error->errorInfo[1]);
}
check((int)rows('SELECT quantity FROM inventory WHERE id = ?', [$batch])[0]['quantity'] === 10, 'Failed dispatch changed the quantity.');
check(count(rows('SELECT id FROM movements WHERE stock_id = ?', [$batch])) === 1, 'Failed movement was retained.');
echo "PASS receipt and dispatch roll back when movement insertion fails\n";

check(concurrent([['out', (string)$batch], ['out', (string)$batch]]) === ['rejected', 'success'], 'Two workers both dispatched the same stock.');
check((int)rows('SELECT quantity FROM inventory WHERE id = ?', [$batch])[0]['quantity'] === 3, 'Concurrent dispatch balance is incorrect.');
$ledger = rows("SELECT SUM(IF(type = 'in', quantity, -quantity)) AS balance, COUNT(*) AS events FROM movements WHERE stock_id = ?", [$batch])[0];
check((int)$ledger['balance'] === 3 && (int)$ledger['events'] === 2, 'Movement ledger differs from stock.');
echo "PASS independent concurrent transactions prevent overship and preserve the ledger\n";

$people = [];
foreach (['甲', '乙'] as $suffix) {
    $name = '循環回歸' . $tag . $suffix;
    $_POST = employee_values($name);
    save_employee();
    $people[] = [(string)rows('SELECT id FROM employees WHERE name = ?', [$name])[0]['id'], $name];
}
[[$first, $firstName], [$second, $secondName]] = $people;
check(concurrent([['manager', $first, $second, $firstName], ['manager', $second, $first, $secondName]]) === ['rejected', 'success'], 'Concurrent supervisor assignments were both accepted.');
$reporting = array_column(rows('SELECT id, manager_id FROM employees WHERE id IN (?, ?)', [$first, $second]), 'manager_id', 'id');
check(!((string)$reporting[$first] === $second && (string)$reporting[$second] === $first), 'Concurrent edit created a supervisor cycle.');
echo "PASS independent concurrent supervisor edits cannot create a cycle\n";
echo "PASS backend database regressions; QA tag {$tag}\n";
