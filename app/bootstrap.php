<?php
declare(strict_types=1);

session_name(getenv('WAREHOUSE_SESSION_NAME') ?: 'NTOU_WAREHOUSE_SID');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    // HTTPS terminates at the production proxy; only deployment configuration can opt in.
    'secure' => getenv('WAREHOUSE_COOKIE_SECURE') === '1'
        || (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');
session_start();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function db(): PDO
{
    static $connection;
    if (!$connection) {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '3306';
        $name = getenv('DB_NAME') ?: 'warehouse';
        $connection = new PDO(
            "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",
            getenv('DB_USER') ?: 'warehouse',
            getenv('DB_PASSWORD') ?: '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $connection->exec("SET time_zone = '+08:00'");
    }
    return $connection;
}

function rows(string $sql, array $parameters = []): array
{
    $statement = db()->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchAll();
}

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function go(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

function current_user(): ?array
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    $user = rows('SELECT u.id, u.username, u.role, u.client_id, u.employee_id, u.password_hash,
                        COALESCE(c.name, e.name) AS display_name
                 FROM users u LEFT JOIN clients c ON c.id = u.client_id
                 LEFT JOIN employees e ON e.id = u.employee_id WHERE u.id = ?', [$_SESSION['user_id']])[0] ?? null;
    if (!$user || !hash_equals(hash('sha256', $user['password_hash']), $_SESSION['auth_version'] ?? '')) {
        $_SESSION = [];
        return null;
    }
    unset($user['password_hash']);
    return $user;
}

function require_role(string $role): array
{
    $user = current_user();
    if (!$user) {
        go('index.php');
    }
    if ($user['role'] !== $role) {
        http_response_code(403);
        exit('此帳號沒有操作權限。');
    }
    return $user;
}

function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function mutation_token(string $action): string
{
    $tokens = $_SESSION['mutation_tokens'] ?? [];
    // Keep separately opened forms usable without allowing the session to grow indefinitely.
    if (count($tokens) >= 1024) {
        $tokens = array_slice($tokens, -1023, null, true);
    }
    $token = bin2hex(random_bytes(32));
    $tokens[$token] = $action;
    $_SESSION['mutation_tokens'] = $tokens;
    return $token;
}

function require_mutation_token(string $action): string
{
    $token = $_POST['mutation_token'] ?? null;
    if (!is_string($token) || ($_SESSION['mutation_tokens'][$token] ?? null) !== $action) {
        throw new DomainException('這張表單已送出或失效，請先確認結果，再重新操作。');
    }
    return $token;
}

function remember_form_input(string $action): void
{
    $fields = match ($action) {
        'goods_save' => ['id', 'client_id', 'name', 'unit'],
        'client_goods_save' => ['id', 'name', 'unit'],
        'client_request_in' => ['goods_id', 'quantity'],
        'client_request_out' => ['stock_id', 'quantity'],
        'client_request_cancel' => ['id'],
        'request_complete' => ['id', 'section_id'],
        'request_reject' => ['id', 'response_note'],
        'employee_save' => ['id', 'name', 'job_title', 'hourly_rate', 'manager_id', 'birth_date', 'overtime_hours', 'insurer'],
        'stock_in' => ['goods_id', 'section_id', 'quantity'],
        'stock_out' => ['stock_id', 'quantity'],
        default => [],
    };
    $values = [];
    foreach ($fields as $field) {
        if (isset($_POST[$field]) && is_string($_POST[$field])) {
            $values[$field] = $_POST[$field];
        }
    }
    if ($values) {
        $_SESSION['form_input'] = ['action' => $action, 'values' => $values];
    }
}

function take_form_input(string $action): array
{
    if (($_SESSION['form_input']['action'] ?? null) !== $action) {
        return [];
    }
    $values = $_SESSION['form_input']['values'];
    unset($_SESSION['form_input']);
    return $values;
}

function take_flash(): ?array
{
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $message;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function input_text(string $key, string $label, int $max): string
{
    $value = $_POST[$key] ?? '';
    if (!is_string($value)) {
        throw new DomainException("請確認{$label}的格式。");
    }
    $value = trim($value);
    if ($value === '' || !preg_match('/^.{1,' . $max . '}$/usD', $value)) {
        throw new DomainException("{$label}須填寫 1 至 {$max} 個字元。");
    }
    return $value;
}

function input_id(string $key, string $label, bool $optional = false): ?int
{
    $value = $_POST[$key] ?? '';
    if ($optional && $value === '') {
        return null;
    }
    if (!is_string($value) || !ctype_digit($value) || (int)$value < 1 || (int)$value > 2147483647) {
        throw new DomainException("請輸入有效的{$label}。");
    }
    return (int)$value;
}

function transaction(callable $operation): mixed
{
    $conn = db();
    $conn->beginTransaction();
    try {
        $result = $operation($conn);
        $conn->commit();
        return $result;
    } catch (Throwable $error) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $error;
    }
}

require_once __DIR__ . '/queries.php';
