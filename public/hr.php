<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
require_role('admin');
$view = is_string($_GET['view'] ?? null) && $_GET['view'] === 'staff' ? 'staff' : 'overview';
$parameters = ['view' => $view];
foreach (['edit', 'q'] as $key) {
    if (isset($_GET[$key]) && is_string($_GET[$key])) $parameters[$key] = $_GET[$key];
}
go('admin.php?' . http_build_query($parameters));
