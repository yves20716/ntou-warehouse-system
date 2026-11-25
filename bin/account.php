<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$command = $argv[1] ?? '';
$username = $argv[2] ?? '';
$validUsername = preg_match('/\A[A-Za-z0-9_.-]{1,80}\z/D', $username);
if (!$validUsername || !in_array($command, ['create', 'password', 'invite'], true)
    || ($command === 'password' && $argc !== 3)
    || ($command === 'create' && $argc !== 5)
    || ($command === 'invite' && ($argc !== 5 || ($argv[3] ?? '') !== '--base-url'))) {
    fwrite(STDERR, "用法：php bin/account.php create 帳號 client|employee|admin 對應編號\n"
        . "或：php bin/account.php password 帳號\n"
        . "或：php bin/account.php invite 帳號 --base-url https://網站網域\n"
        . "create/password 的密碼由標準輸入讀取。invite 產生一次性設定連結。\n");
    exit(1);
}
require __DIR__ . '/../app/bootstrap.php';
try {
    if ($command === 'invite') {
        $link = issue_account_link($username, $argv[4]);
        fwrite(STDOUT, $link . "\n");
        fwrite(STDERR, "連結有效 30 分鐘，請直接交給帳號本人。\n");
        exit;
    }
    $role = $argv[3] ?? '';
    if ($role === 'hr') $role = 'admin'; // Compatibility for existing deployment scripts.
    $id = filter_var($argv[4] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
    if ($command === 'create' && (!in_array($role, ['client', 'employee', 'admin'], true) || $id === false)) {
        throw new DomainException('請指定正確身分與既有的客戶或員工編號。');
    }
    if (function_exists('stream_isatty') && stream_isatty(STDIN)) {
        throw new DomainException('請使用部署說明中的隱藏輸入方式傳入密碼。');
    }
    $password = validate_new_password(rtrim((string)fgets(STDIN, 1024), "\r\n"));
    $hash = password_hash($password, PASSWORD_DEFAULT);
    unset($password);
    transaction(function (PDO $conn) use ($command, $username, $role, $id, $hash) {
        if ($command === 'create') {
            if ($role !== 'client') {
                $employee = rows('SELECT employment_status FROM employees WHERE id=? FOR UPDATE', [$id])[0] ?? null;
                if (!$employee || $employee['employment_status'] !== 'active') throw new DomainException('請選擇在職員工。');
                if ($role === 'employee') assert_manageable_employee($id);
            }
            $conn->prepare('INSERT INTO users (username,password_hash,role,client_id,employee_id) VALUES (?,?,?,?,?)')
                ->execute([$username, $hash, $role, $role === 'client' ? $id : null, $role === 'client' ? null : $id]);
            admin_audit_record('account_created', '主機管理者建立帳號。', (int)$conn->lastInsertId());
        } else {
            $account = rows('SELECT * FROM users WHERE username=? FOR UPDATE', [$username])[0] ?? null;
            if (!$account) throw new DomainException('找不到這個帳號，未修改任何密碼。');
            $status = $account['status'] === 'pending' ? 'active' : $account['status'];
            $conn->prepare('UPDATE users SET password_hash=?,password_set_at=CURRENT_TIMESTAMP,reset_requested_at=NULL,
                            auth_version=auth_version+1,status=? WHERE id=?')->execute([$hash, $status, $account['id']]);
            revoke_account_tokens((int)$account['id']);
            admin_audit_record('password_changed', '主機管理者更新密碼，原登入已失效。', (int)$account['id']);
        }
    });
    fwrite(STDOUT, $command === 'create' ? "帳號已建立。\n" : "密碼已更新，該帳號需重新登入。\n");
} catch (DomainException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
} catch (PDOException $error) {
    fwrite(STDERR, "操作失敗。請確認資料庫連線、帳號是否重複，以及對應資料是否存在。\n");
    exit(1);
}
