<?php
declare(strict_types=1);

// Administrative CLI only; this directory must stay outside the document root.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$command = $argv[1] ?? '';
$username = $argv[2] ?? '';
if (!in_array($command, ['create', 'password'], true)
    || !preg_match('/\A[A-Za-z0-9_.-]{1,80}\z/D', $username)
    || ($command === 'password' && $argc !== 3)
    || ($command === 'create' && $argc !== 5)) {
    fwrite(STDERR, "用法：php bin/account.php create 帳號 client|employee|hr 對應編號\n"
        . "或：php bin/account.php password 帳號\n密碼由標準輸入讀取，不接受命令列密碼。\n");
    exit(1);
}
$role = $argv[3] ?? '';
$id = filter_var($argv[4] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($command === 'create' && (!in_array($role, ['client', 'employee', 'hr'], true) || $id === false)) {
    fwrite(STDERR, "請指定正確身分與既有的客戶或員工編號。\n");
    exit(1);
}
if (function_exists('stream_isatty') && stream_isatty(STDIN)) {
    fwrite(STDERR, "請使用部署說明中的隱藏輸入方式傳入密碼。\n");
    exit(1);
}
$password = rtrim((string)fgets(STDIN, 1024), "\r\n");
if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")
    || trim($password) === '' || $password === 'WarehouseDemo!26') {
    fwrite(STDERR, "密碼請使用 12 至 72 bytes，且不可沿用公開示範密碼。\n");
    exit(1);
}
$hash = password_hash($password, PASSWORD_DEFAULT);
unset($password);
require __DIR__ . '/../app/bootstrap.php';
try {
    if ($command === 'create') {
        $statement = db()->prepare('INSERT INTO users (username, password_hash, role, client_id, employee_id) VALUES (?, ?, ?, ?, ?)');
        $statement->execute([$username, $hash, $role, $role === 'client' ? $id : null, $role === 'client' ? null : $id]);
    } else {
        $statement = db()->prepare('UPDATE users SET password_hash = ? WHERE username = ?');
        $statement->execute([$hash, $username]);
        if ($statement->rowCount() !== 1) {
            fwrite(STDERR, "找不到這個帳號，未修改任何密碼。\n");
            exit(1);
        }
    }
    fwrite(STDOUT, $command === 'create' ? "帳號已建立。\n" : "密碼已更新，該帳號需重新登入。\n");
} catch (PDOException $error) {
    fwrite(STDERR, "操作失敗。請確認資料庫連線、帳號是否重複，以及對應的客戶或員工是否存在。\n");
    exit(1);
}
