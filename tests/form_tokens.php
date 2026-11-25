<?php
declare(strict_types=1);

// Run: php tests/form_tokens.php
// Render 513 pending requests without a database, environment files or a PHP session.

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// Load the production helpers without running bootstrap's environment/session setup.
// Their top-level declarations end with an unindented closing brace.
$bootstrap = file_get_contents(__DIR__ . '/../app/bootstrap.php');
check($bootstrap !== false, 'Could not read the production token helpers.');
preg_match_all('/^function (h|csrf_token|mutation_token|require_mutation_token)\b.*?^}/ms', $bootstrap, $definitions);
check(count($definitions[0]) === 4, 'Expected all four production helpers.');
foreach ($definitions[0] as $definition) {
    eval('declare(strict_types=1);' . $definition);
}

$_SESSION = [];
$_POST = [];
require __DIR__ . '/../public/partials/layout.php';
require __DIR__ . '/../public/partials/workflows.php';

$base = [
    'type' => 'in', 'status' => 'pending', 'quantity' => 12,
    'goods_name' => '表單回歸品項', 'unit' => '箱',
    'client_id' => 1, 'client_name' => '表單回歸客戶',
    'created_at' => '2026-11-26 10:00:00', 'handled_at' => null,
    'stock_id' => null, 'warehouse_name' => null, 'section_name' => null,
    'current_quantity' => null, 'employee_name' => null,
    'result_stock_id' => null, 'response_note' => null,
];
$requests = [];
for ($id = 1; $id <= 513; $id++) {
    $requests[] = ['id' => $id] + $base;
}
$sections = [['id' => 1, 'client_id' => 1, 'warehouse_name' => '甲倉', 'name' => 'A 區']];

ob_start();
try {
    ui_request_details($requests, $sections, true);
    $html = (string)ob_get_contents();
} finally {
    ob_end_clean();
}

// Read the rendered form fields; no DOM extension or third-party parser is needed.
preg_match_all('/<form\b[^>]*>(.*?)<\/form>/s', $html, $forms);
check(count($forms[1]) === 1026, 'Expected two forms for each of 513 pending requests.');
$tokens = [];
$byAction = ['request_complete' => [], 'request_reject' => []];
foreach ($forms[1] as $form) {
    preg_match_all('/<input\b[^>]*>/', $form, $inputs);
    $fields = [];
    foreach ($inputs[0] as $input) {
        if (preg_match('/\bname="([^"]+)"/', $input, $name)
            && preg_match('/\bvalue="([^"]*)"/', $input, $value)) {
            $fields[$name[1]] = html_entity_decode($value[1], ENT_QUOTES, 'UTF-8');
        }
    }
    $action = $fields['action'] ?? '';
    check(array_key_exists($action, $byAction), 'Unexpected action in the rendered request forms.');
    check(isset($fields['mutation_token']) && $fields['mutation_token'] !== '', 'A form is missing its mutation token.');
    $tokens[] = $fields['mutation_token'];
    $byAction[$action][] = $fields;
}

check(count($tokens) === 1026, 'Expected 1,026 rendered mutation-token fields.');
check(count(array_unique($tokens)) <= 2, 'Rendering one page created more than one token per action.');
foreach ($byAction as $action => $actionForms) {
    check(count($actionForms) === 513, "Expected 513 {$action} forms.");
    foreach ([0 => '1', 512 => '513'] as $position => $expectedId) {
        $fields = $actionForms[$position];
        check(($fields['id'] ?? '') === $expectedId, "Wrong request ID in the first/last {$action} form.");
        $_POST = $fields;
        check(require_mutation_token($action) === $fields['mutation_token'], "The {$action} token is no longer valid.");
    }
}

printf("PASS: 513 pending requests, 1,026 token fields, %d unique tokens; first and last forms accepted.\n", count(array_unique($tokens)));
