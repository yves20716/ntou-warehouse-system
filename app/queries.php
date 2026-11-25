<?php
declare(strict_types=1);

/** Compose fixed predicates and bind all search terms as literal substrings. */
function dashboard_rows(string $sql, string $order, string $q, array $columns, ?int $tenant = null, ?int $warehouseId = null): array
{
    $conditions = [];
    $values = [];
    if ($tenant !== null) {
        $conditions[] = 'c.id = ?';
        $values[] = $tenant;
    }
    if ($warehouseId !== null) {
        $conditions[] = 'w.id = ?';
        $values[] = $warehouseId;
    }
    if ($q !== '') {
        $conditions[] = '(' . implode(' OR ', array_map(static fn($column) => "$column LIKE ? ESCAPE '!'", $columns)) . ')';
        $term = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $values = array_merge($values, array_fill(0, count($columns), $term));
    }
    $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
    return rows($sql . $where . ' ORDER BY ' . $order, $values);
}

function dashboard_data(string $q = '', ?int $warehouseId = null): array
{
    $user = current_user();
    if (!$user) {
        go('index.php');
    }
    $data = ['goods' => [], 'inventory' => [], 'movements' => [], 'employees' => [], 'clients' => [], 'sections' => []];
    if ($user['role'] === 'hr') {
        $data['employees'] = dashboard_rows(
            'SELECT e.id, e.name, e.job_title, e.hourly_rate, e.birth_date, e.overtime_hours, e.insurer, e.manager_id, m.name AS manager_name
             FROM employees e LEFT JOIN employees m ON e.manager_id = m.id',
            'e.id', $q, ['e.id', 'e.name', 'e.job_title', 'm.name']
        );
        return $data;
    }

    $tenant = $user['role'] === 'client' ? (int)$user['client_id'] : null;
    $data['goods'] = dashboard_rows(
        'SELECT g.id, g.client_id, c.name AS client_name, g.name, g.unit
         FROM goods g JOIN clients c ON c.id = g.client_id',
        'g.id', $q, ['g.id', 'c.name', 'g.name'], $tenant
    );
    $lots = ' JOIN goods g ON i.goods_id = g.id JOIN clients c ON c.id = g.client_id
              JOIN sections s ON s.id = i.section_id JOIN warehouses w ON w.id = s.warehouse_id ';
    $data['inventory'] = dashboard_rows(
        "SELECT i.id, g.id AS goods_id, g.name AS goods_name, c.name AS client_name, g.unit,
                w.id AS warehouse_id, w.name AS warehouse_name, s.id AS section_id, s.name AS section_name, i.quantity,
                i.arrival_date, IF(i.quantity > 0, 'in_stock', 'depleted') AS status
         FROM inventory i" . $lots,
        'i.id DESC', $q, ["CONCAT('#', i.id)", 'g.name', 'c.name', 's.name', 'w.name'], $tenant, $warehouseId
    );
    $data['movements'] = dashboard_rows(
        'SELECT m.id, m.stock_id, g.name AS goods_name, c.name AS client_name, s.name AS section_name,
                w.name AS warehouse_name, g.unit, m.type, m.quantity, m.moved_at, e.name AS employee_name
         FROM movements m JOIN inventory i ON i.id = m.stock_id' . $lots . 'JOIN employees e ON m.employee_id = e.id',
        'm.moved_at DESC, m.id DESC', $q, ['m.id', "CONCAT('#', m.stock_id)", 'g.name', 'c.name', 's.name', 'w.name'], $tenant
    );
    if ($user['role'] === 'employee') {
        $data['clients'] = rows('SELECT id, name FROM clients ORDER BY name');
        $data['sections'] = rows(
            'SELECT s.id, s.name, w.name AS warehouse_name, s.client_id, s.capacity
             FROM sections s JOIN warehouses w ON s.warehouse_id = w.id ORDER BY w.name, s.name'
        );
    }
    return $data;
}
