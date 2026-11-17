<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/mutations.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('請使用表單送出操作。');
}
if (!is_string($_POST['csrf'] ?? null) || !hash_equals(csrf_token(), $_POST['csrf'])) {
    http_response_code(403);
    exit('表單已失效，請返回頁面重新整理後再試。');
}
$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
$destination = 'index.php';

try {
    if ($action === 'login') {
        $username = input_text('username', '帳號', 80);
        $password = $_POST['password'] ?? null;
        $account = rows('SELECT id, role, password_hash FROM users WHERE username = ?', [$username])[0] ?? null;
        if (!is_string($password) || !$account || !password_verify($password, $account['password_hash'])) {
            throw new DomainException('帳號或密碼有誤。');
        }
        session_regenerate_id(true);
        $_SESSION = ['user_id' => $account['id']];
        go($account['role'] . '.php');
    }
    if ($action === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        go('index.php');
    }

    $routes = [
        'goods_save' => ['employee', 'employee.php?view=goods', '已儲存貨物資料。'],
        'goods_delete' => ['employee', 'employee.php?view=goods', '已刪除貨物。'],
        'stock_in' => ['employee', 'employee.php?view=inventory', '入庫完成，已建立新批次及異動紀錄。'],
        'stock_out' => ['employee', 'employee.php?view=inventory', '出庫完成，已更新批次餘量。'],
        'employee_save' => ['hr', 'hr.php?view=staff', '已儲存員工資料。'],
        'employee_delete' => ['hr', 'hr.php?view=staff', '已刪除員工資料。'],
    ];
    if (!isset($routes[$action])) {
        http_response_code(400);
        exit('無法辨識操作。');
    }
    [$role, $destination, $message] = $routes[$action];
    $user = require_role($role);
    match ($action) {
        'goods_save' => save_goods(),
        'goods_delete' => delete_record('goods'),
        'stock_in' => stock_in((int)$user['employee_id']),
        'stock_out' => stock_out((int)$user['employee_id']),
        'employee_save' => save_employee(),
        'employee_delete' => delete_record('employees'),
    };
    flash('success', $message);
} catch (DomainException $error) {
    flash('error', $error->getMessage());
} catch (PDOException $error) {
    $code = (int)($error->errorInfo[1] ?? 0);
    $message = match ($code) {
        1062 => '相同資料已經存在，請確認名稱。',
        1451 => '這筆資料有關聯紀錄，請保留原有資料。',
        1452 => '關聯資料已變更，請重新整理後再試。',
        1205, 1213 => '其他人正在更新資料，請重新整理後再試。',
        default => '目前無法完成操作，請稍後再試。',
    };
    error_log('Warehouse operation failed. SQLSTATE=' . $error->getCode() . ' driver=' . $code);
    flash('error', $message);
}
go($destination);
