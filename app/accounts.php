<?php
declare(strict_types=1);

function admin_audit_record(string $event, string $details, ?int $targetUser = null, ?int $targetEmployee = null, ?int $actor = null): void
{
    $actor ??= isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    db()->prepare('INSERT INTO admin_audit (actor_user_id, target_user_id, target_employee_id, event, details) VALUES (?, ?, ?, ?, ?)')
        ->execute([$actor, $targetUser, $targetEmployee, $event, $details]);
}

function admin_event_label(string $event): string
{
    return [
        'account_created' => '建立帳號', 'account_disabled' => '停用帳號',
        'account_enabled' => '恢復帳號', 'reset_requested' => '要求重設密碼',
        'link_issued' => '產生設定連結', 'account_activated' => '完成帳號啟用',
        'password_changed' => '更新密碼', 'employee_created' => '新增員工',
        'employee_updated' => '更新員工', 'employee_deleted' => '刪除員工',
    ][$event] ?? '帳號異動';
}

function admin_audit_summary(array $entry): string
{
    return (string)($entry['details'] ?? '');
}

function admin_data(string $q = ''): array
{
    require_role('admin');
    $accounts = dashboard_rows(
        'SELECT u.id, u.username, u.role, u.status, u.client_id, u.employee_id,
                u.password_set_at, u.reset_requested_at, COALESCE(c.name, e.name) AS display_name,
                e.employment_status
         FROM users u LEFT JOIN clients c ON c.id=u.client_id LEFT JOIN employees e ON e.id=u.employee_id',
        'u.id', $q, ['u.username', 'c.name', 'e.name']
    );
    $employees = dashboard_rows(
        "SELECT e.id, e.name, e.job_title, e.monthly_salary, e.employment_status, e.manager_id,
                m.name AS manager_name, e.birth_date, e.overtime_hours, e.insurer,
                EXISTS(SELECT 1 FROM users a WHERE a.employee_id=e.id AND a.role='admin') AS is_admin
         FROM employees e LEFT JOIN employees m ON m.id=e.manager_id",
        'e.id', $q, ['e.id', 'e.name', 'e.job_title', 'm.name']
    );
    $audit = dashboard_rows(
        "SELECT a.id, a.event, a.details, a.created_at, a.actor_user_id, a.target_user_id, a.target_employee_id,
                COALESCE(actor.username, '主機管理者') AS actor_name,
                COALESCE(target.username, e.name, '員工資料') AS target_name
         FROM admin_audit a LEFT JOIN users actor ON actor.id=a.actor_user_id
         LEFT JOIN users target ON target.id=a.target_user_id LEFT JOIN employees e ON e.id=a.target_employee_id",
        'a.id DESC LIMIT 500', $q, ['actor.username', 'target.username', 'e.name', 'a.details']
    );
    return ['accounts' => $accounts, 'employees' => $employees,
        'clients' => rows('SELECT id, name FROM clients ORDER BY name'), 'audit' => $audit];
}

function assert_manageable_employee(int $id): void
{
    if (rows("SELECT id FROM users WHERE employee_id=? AND role='admin' LIMIT 1", [$id])) {
        throw new DomainException('管理員的人員資料由主機管理者維護。');
    }
}

function manageable_account(int $id): array
{
    $account = rows('SELECT * FROM users WHERE id=? FOR UPDATE', [$id])[0] ?? null;
    if (!$account) throw new DomainException('找不到這個帳號。');
    if ($account['role'] === 'admin' || $id === (int)($_SESSION['user_id'] ?? 0)) {
        throw new DomainException('無法在此修改管理員帳號。');
    }
    if ($account['employee_id'] !== null) assert_manageable_employee((int)$account['employee_id']);
    return $account;
}

function revoke_account_tokens(int $id): void
{
    db()->prepare('UPDATE account_tokens SET used_at=CURRENT_TIMESTAMP WHERE user_id=? AND used_at IS NULL')->execute([$id]);
}

function create_managed_account(): string
{
    $username = input_text('username', '帳號', 80);
    if (!preg_match('/\A[A-Za-z0-9_.-]{1,80}\z/D', $username)) {
        throw new DomainException('帳號限用英文字母、數字、底線、句點及連字號。');
    }
    $role = $_POST['role'] ?? '';
    if (!in_array($role, ['client', 'employee'], true)) throw new DomainException('請選擇客戶或倉儲員工。');
    $client = input_id('client_id', '客戶', true);
    $employee = input_id('employee_id', '員工', true);
    if (($role === 'client' && ($client === null || $employee !== null))
        || ($role === 'employee' && ($employee === null || $client !== null))) {
        throw new DomainException('請選擇對應的客戶或員工。');
    }
    transaction(function (PDO $conn) use ($username, $role, $client, $employee) {
        if ($role === 'client') {
            if (!rows('SELECT id FROM clients WHERE id=? FOR SHARE', [$client])) throw new DomainException('找不到這位客戶。');
        } else {
            $person = rows('SELECT employment_status FROM employees WHERE id=? FOR UPDATE', [$employee])[0] ?? null;
            if (!$person || $person['employment_status'] !== 'active') throw new DomainException('請選擇在職員工。');
            assert_manageable_employee($employee);
        }
        // No usable credential is created or returned to the web administrator.
        $hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $conn->prepare("INSERT INTO users (username,password_hash,role,client_id,employee_id,status,password_set_at)
                        VALUES (?,?,?,?,?,'pending',NULL)")
            ->execute([$username, $hash, $role, $client, $employee]);
        $id = (int)$conn->lastInsertId();
        admin_audit_record('account_created', '建立一般帳號，等待本人設定密碼。', $id);
    });
    return '帳號已建立，等待本人完成啟用。';
}

function change_account_status(): string
{
    $id = input_id('id', '帳號');
    $requested = $_POST['status'] ?? '';
    if (!in_array($requested, ['active', 'disabled'], true)) throw new DomainException('請選擇有效的帳號狀態。');
    $status = transaction(function (PDO $conn) use ($id, $requested) {
        $account = manageable_account($id);
        $status = $requested === 'active' && $account['password_set_at'] === null ? 'pending' : $requested;
        if ($account['status'] === $status) throw new DomainException('帳號已是這個狀態。');
        if ($requested === 'active' && $account['employee_id'] !== null) {
            $person = rows('SELECT employment_status FROM employees WHERE id=? FOR SHARE', [$account['employee_id']])[0] ?? null;
            if (!$person || $person['employment_status'] !== 'active') throw new DomainException('員工已離職，無法恢復帳號。');
        }
        $conn->prepare('UPDATE users SET status=?, auth_version=auth_version+1 WHERE id=?')->execute([$status, $id]);
        revoke_account_tokens($id);
        $labels = ['active' => '啟用', 'disabled' => '停用', 'pending' => '等待啟用'];
        admin_audit_record($status === 'disabled' ? 'account_disabled' : 'account_enabled',
            '狀態由「' . $labels[$account['status']] . '」改為「' . $labels[$status] . '」。', $id);
        return $status;
    });
    return match ($status) {
        'pending' => '帳號已恢復，等待本人完成啟用。',
        'active' => '帳號已恢復。',
        default => '帳號已停用，原登入已失效。',
    };
}

function request_account_reset(): string
{
    $id = input_id('id', '帳號');
    transaction(function (PDO $conn) use ($id) {
        $account = manageable_account($id);
        if ($account['status'] !== 'active' || $account['password_set_at'] === null) {
            throw new DomainException('請先恢復帳號並完成啟用。');
        }
        if ($account['reset_requested_at'] !== null) throw new DomainException('此帳號已在等待重設密碼。');
        $conn->prepare('UPDATE users SET reset_requested_at=CURRENT_TIMESTAMP, auth_version=auth_version+1 WHERE id=?')->execute([$id]);
        revoke_account_tokens($id);
        admin_audit_record('reset_requested', '要求本人重設密碼，原登入已失效。', $id);
    });
    return '已提出密碼重設需求。';
}

function take_password_setup_token(): string
{
    $saved = $_SESSION['password_setup_token'] ?? null;
    unset($_SESSION['password_setup_token']);
    return is_array($saved) && ($saved['until'] ?? 0) >= time() ? (string)($saved['token'] ?? '') : '';
}

function validate_new_password(mixed $password, mixed $confirmation = null): string
{
    if (!is_string($password) || str_contains($password, "\0")
        || !preg_match('/\A.{12,}\z/usD', $password) || trim($password) === '') {
        throw new DomainException('密碼至少需 12 個字元。');
    }
    if (strlen($password) > 72) throw new DomainException('密碼過長，請減少字數。');
    if ($password === 'WarehouseDemo!26') throw new DomainException('請勿使用公開示範密碼。');
    if ($confirmation !== null && (!is_string($confirmation) || !hash_equals($password, $confirmation))) {
        throw new DomainException('兩次輸入的密碼不同。');
    }
    return $password;
}

function set_password_from_token(): string
{
    if (current_user()) throw new DomainException('請先登出，再開啟密碼設定連結。');
    $token = $_POST['token'] ?? '';
    if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/D', $token)) {
        throw new DomainException('設定連結已失效，請向主機管理者取得新連結。');
    }
    $password = validate_new_password($_POST['password'] ?? null, $_POST['password_confirmation'] ?? '');
    $digest = hash('sha256', $token);
    transaction(function (PDO $conn) use ($digest, $password) {
        $reference = rows('SELECT user_id FROM account_tokens WHERE token_hash=?', [$digest])[0] ?? null;
        if (!$reference) throw new DomainException('設定連結已失效，請向主機管理者取得新連結。');
        $account = rows('SELECT * FROM users WHERE id=? FOR UPDATE', [$reference['user_id']])[0] ?? null;
        $link = rows('SELECT *, expires_at > CURRENT_TIMESTAMP AS unexpired FROM account_tokens WHERE token_hash=? FOR UPDATE', [$digest])[0] ?? null;
        if (!$account || !$link || !$link['unexpired'] || $link['used_at'] !== null
            || (int)$link['auth_version'] !== (int)$account['auth_version']
            || $account['status'] === 'disabled'
            || ($link['purpose'] === 'activate' && ($account['status'] !== 'pending' || $account['password_set_at'] !== null))
            || ($link['purpose'] === 'reset' && ($account['status'] !== 'active' || $account['password_set_at'] === null))) {
            throw new DomainException('設定連結已失效，請向主機管理者取得新連結。');
        }
        if ($account['employee_id'] !== null) {
            $employee = rows('SELECT employment_status FROM employees WHERE id=? FOR SHARE', [$account['employee_id']])[0] ?? null;
            if (!$employee || $employee['employment_status'] !== 'active') throw new DomainException('此帳號目前無法啟用，請聯絡管理員。');
        }
        $conn->prepare("UPDATE users SET password_hash=?,status='active',password_set_at=CURRENT_TIMESTAMP,
                        reset_requested_at=NULL,auth_version=auth_version+1 WHERE id=?")
            ->execute([password_hash($password, PASSWORD_DEFAULT), $account['id']]);
        revoke_account_tokens((int)$account['id']);
        admin_audit_record($link['purpose'] === 'activate' ? 'account_activated' : 'password_changed',
            $link['purpose'] === 'activate' ? '本人完成帳號啟用。' : '本人完成密碼重設。',
            (int)$account['id'], null, (int)$account['id']);
    });
    unset($_SESSION['password_setup_token']);
    session_regenerate_id(true);
    return '密碼已設定，請登入。';
}

function validated_setup_base_url(string $base): string
{
    if ($base === '' || preg_match('/[\x00-\x20\x7f\\\\]/', rawurldecode($base))
        || filter_var($base, FILTER_VALIDATE_URL) === false) {
        throw new DomainException('請提供有效的網站網址。');
    }
    $parts = parse_url($base);
    if (!$parts || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        throw new DomainException('網站網址不可包含帳密、查詢參數或片段。');
    }
    $scheme = strtolower($parts['scheme'] ?? '');
    $host = strtolower(trim($parts['host'] ?? '', '[]'));
    if ($scheme !== 'https' && !($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true))) {
        throw new DomainException('公開網站須使用 HTTPS；HTTP 僅限本機網址。');
    }
    return rtrim($base, '/');
}

function issue_account_link(string $username, string $base): string
{
    if (PHP_SAPI !== 'cli') throw new LogicException('CLI only.');
    $base = validated_setup_base_url($base);
    $token = bin2hex(random_bytes(32));
    transaction(function (PDO $conn) use ($username, $token) {
        $account = rows('SELECT * FROM users WHERE username=? FOR UPDATE', [$username])[0] ?? null;
        if (!$account) throw new DomainException('找不到這個帳號。');
        if ($account['status'] === 'disabled') throw new DomainException('帳號已停用，請先恢復帳號。');
        if ($account['employee_id'] !== null) {
            $employee = rows('SELECT employment_status FROM employees WHERE id=? FOR SHARE', [$account['employee_id']])[0] ?? null;
            if (!$employee || $employee['employment_status'] !== 'active') throw new DomainException('員工已離職，無法產生連結。');
        }
        $purpose = $account['password_set_at'] === null ? 'activate' : 'reset';
        if ($purpose === 'activate' && $account['status'] !== 'pending') throw new DomainException('帳號狀態不符，請確認資料。');
        $conn->prepare('UPDATE users SET auth_version=auth_version+1,reset_requested_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$account['id']]);
        revoke_account_tokens((int)$account['id']);
        $conn->prepare('INSERT INTO account_tokens (user_id,token_hash,purpose,auth_version,expires_at)
                        VALUES (?,?,?,?,DATE_ADD(CURRENT_TIMESTAMP,INTERVAL 30 MINUTE))')
            ->execute([$account['id'], hash('sha256', $token), $purpose, (int)$account['auth_version'] + 1]);
        admin_audit_record('link_issued', $purpose === 'activate' ? '主機管理者產生啟用連結，有效 30 分鐘。' : '主機管理者產生重設連結，有效 30 分鐘。', (int)$account['id']);
    });
    return $base . '/activate.php#token=' . $token;
}
