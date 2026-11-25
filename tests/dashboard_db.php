<?php
declare(strict_types=1);

// All fixtures are rolled back, and this check must never use the main database.
if (getenv('DB_NAME') !== 'warehouse_qa' || getenv('DB_HOST') !== '127.0.0.1') {
    fwrite(STDERR, "Load the dedicated loopback warehouse_qa environment first.\n");
    exit(2);
}
putenv('WAREHOUSE_SESSION_NAME=NTOU_WAREHOUSE_DASHBOARD_REGRESSION');
ini_set('session.save_path', sys_get_temp_dir());
session_id('qa' . bin2hex(random_bytes(16)));
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../public/partials/layout.php';

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function fixture(string $table, array $values): int {
    $columns = implode(', ', array_keys($values));
    $marks = implode(', ', array_fill(0, count($values), '?'));
    db()->prepare("INSERT INTO $table ($columns) VALUES ($marks)")->execute(array_values($values));
    return (int)db()->lastInsertId();
}

db()->beginTransaction();
try {
    $tag = bin2hex(random_bytes(4));
    $warehouse = fixture('warehouses', ['name' => '台北倉' . $tag, 'address' => '測試資料']);
    $similar = fixture('warehouses', ['name' => '新台北倉' . $tag, 'address' => '測試資料']);
    $numeric = fixture('warehouses', ['name' => (string)random_int(100000000, 999999999), 'address' => '測試資料']);
    foreach ([[$warehouse, 1], [$warehouse, 2], [$similar, 1], [$numeric, 1]] as [$id, $client]) {
        $section = fixture('sections', ['warehouse_id' => $id, 'client_id' => $client, 'name' => '測試區' . $client, 'capacity' => 100]);
        $good = fixture('goods', ['client_id' => $client, 'name' => '台北倉' . $tag . '-' . $id, 'unit' => '箱']);
        fixture('inventory', ['goods_id' => $good, 'section_id' => $section, 'quantity' => 10, 'arrival_date' => '2026-11-26']);
    }
    $_SESSION['user_id'] = rows("SELECT id FROM users WHERE role = 'employee' LIMIT 1")[0]['id'];
    $result = dashboard_data('', $warehouse)['inventory'];
    check(count($result) === 2, 'Warehouse selection included a similarly named warehouse or goods.');
    check(count(dashboard_data('台北倉' . $tag, $warehouse)['inventory']) === 2, 'Searching lost the exact warehouse constraint.');
    ob_start();
    ui_stock_summary(dashboard_data('', $numeric)['inventory']);
    $html = ob_get_clean();
    check(str_contains($html, 'warehouse=' . $numeric), 'Numeric warehouse name did not render a usable link.');
    $_SESSION['user_id'] = rows("SELECT id FROM users WHERE role = 'client' AND client_id = 1 LIMIT 1")[0]['id'];
    $clientRows = dashboard_data('', $warehouse)['inventory'];
    check(count($clientRows) === 1, 'Warehouse filter exposed another client\'s stock.');
    check(dashboard_data('', 0)['inventory'] === [], 'Invalid warehouse filter returned stock.');
    echo "PASS exact warehouse filtering, combined search, numeric names, and client isolation\n";
} finally {
    db()->rollBack();
}
