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
        $account = rows('SELECT u.*, e.employment_status FROM users u LEFT JOIN employees e ON e.id=u.employee_id WHERE u.username = ?', [$username])[0] ?? null;
        if (!is_string($password) || !$account || !account_can_login($account) || !password_verify($password, $account['password_hash'])) {
            throw new DomainException('帳號或密碼有誤。');
        }
        session_regenerate_id(true);
        $_SESSION = ['user_id' => $account['id'], 'auth_version' => account_auth_version($account)];
        go($account['role'] . '.php');
    }
    if ($action === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        go('index.php');
    }

    if ($action === 'password_activate') {
        $destination = 'activate.php';
        $message = set_password_from_token();
        flash('success', $message);
        go('index.php');
    }

    $routes = [
        'account_create' => ['admin', 'admin.php?view=accounts'],
        'account_status' => ['admin', 'admin.php?view=accounts'],
        'account_reset_request' => ['admin', 'admin.php?view=accounts'],
        'goods_save' => ['employee', 'employee.php?view=goods'],
        'goods_delete' => ['employee', 'employee.php?view=goods'],
        'stock_in' => ['employee', 'employee.php?view=inventory'],
        'stock_out' => ['employee', 'employee.php?view=inventory'],
        'employee_save' => ['admin', 'admin.php?view=staff'],
        'employee_delete' => ['admin', 'admin.php?view=staff'],
        'client_goods_save' => ['client', 'client.php?view=goods'],
        'client_request_in' => ['client', 'client.php?view=requests'],
        'client_request_out' => ['client', 'client.php?view=requests'],
        'client_request_cancel' => ['client', 'client.php?view=requests'],
        'request_complete' => ['employee', 'employee.php?view=requests'],
        'request_reject' => ['employee', 'employee.php?view=requests'],
    ];
    if (!isset($routes[$action])) {
        http_response_code(400);
        exit('無法辨識操作。');
    }
    [$role, $destination] = $routes[$action];
    $user = require_role($role);
    if (in_array($action, ['goods_save', 'client_goods_save', 'employee_save'], true)) {
        $id = $_POST['id'] ?? '';
        if (is_string($id) && ctype_digit($id) && (int)$id >= 1 && (int)$id <= 2147483647) {
            $destination .= '&edit=' . (int)$id;
        }
    }
    $token = require_mutation_token($action);
    $message = match ($action) {
        'account_create' => create_managed_account(),
        'account_status' => change_account_status(),
        'account_reset_request' => request_account_reset(),
        'goods_save' => save_goods(),
        'goods_delete' => delete_record('goods'),
        'stock_in' => stock_in((int)$user['employee_id']),
        'stock_out' => stock_out((int)$user['employee_id']),
        'employee_save' => save_employee(),
        'employee_delete' => delete_record('employees'),
        'client_goods_save' => save_goods((int)$user['client_id']),
        'client_request_in' => create_stock_request('in', (int)$user['client_id']),
        'client_request_out' => create_stock_request('out', (int)$user['client_id']),
        'client_request_cancel' => cancel_stock_request((int)$user['client_id']),
        'request_complete' => complete_stock_request((int)$user['employee_id']),
        'request_reject' => reject_stock_request((int)$user['employee_id']),
    };
    // PHP holds the session lock until this request finishes, including the database commit.
    unset($_SESSION['mutation_tokens'][$token], $_SESSION['form_input']);
    $destination = $routes[$action][1];
    flash('success', $message);
} catch (DomainException $error) {
    remember_form_input($action);
    if ($action === 'password_activate' && is_string($_POST['token'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/D', $_POST['token'])) {
        $_SESSION['password_setup_token'] = ['token' => $_POST['token'], 'until' => time() + 600];
    }
    flash('error', $error->getMessage());
} catch (PDOException $error) {
    remember_form_input($action);
    $code = (int)($error->errorInfo[1] ?? 0);
    $message = match ($code) {
        1062 => $action === 'account_create' ? '此帳號名稱已有人使用。' : (in_array($action, ['goods_save', 'client_goods_save'], true) ? '這位客戶已有同名貨物，請確認貨名。' : '這筆資料已經存在，請重新確認。'),
        1217, 1451 => $action === 'goods_delete' ? '這項貨物已有入庫紀錄或收發申請，無法刪除。' : '這位員工仍有部屬、登入帳號、收發申請或進出貨紀錄，無法刪除。',
        1216, 1452 => '選取的資料已不存在，請重新整理後再選一次。',
        1205, 1213 => '資料更新發生衝突，請重新整理後再試。',
        default => '目前無法完成操作，請稍後再試。',
    };
    error_log('Warehouse operation failed. SQLSTATE=' . $error->getCode() . ' driver=' . $code);
    flash('error', $message);
}
go($destination);
