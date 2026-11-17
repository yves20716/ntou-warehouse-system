<?php
declare(strict_types=1);

function save_goods(): void
{
    $id = input_id('id', '貨物編號', true);
    $client = input_id('client_id', '客戶');
    $name = input_text('name', '貨物名稱', 100);
    $unit = input_text('unit', '單位', 16);
    transaction(function (PDO $conn) use ($id, $client, $name, $unit) {
        if ($id === null) {
            $conn->prepare('INSERT INTO goods (client_id, name, unit) VALUES (?, ?, ?)')->execute([$client, $name, $unit]);
            return;
        }
        $old = rows('SELECT client_id, unit FROM goods WHERE id = ? FOR UPDATE', [$id])[0] ?? null;
        if (!$old) {
            throw new DomainException('找不到這筆貨物。');
        }
        if (((int)$old['client_id'] !== $client || $old['unit'] !== $unit)
            && rows('SELECT id FROM inventory WHERE goods_id = ? LIMIT 1', [$id])) {
            throw new DomainException('這筆貨物已有庫存紀錄，無法更換所屬客戶或計量單位。');
        }
        $conn->prepare('UPDATE goods SET client_id = ?, name = ?, unit = ? WHERE id = ?')->execute([$client, $name, $unit, $id]);
    });
}

function delete_record(string $table): void
{
    $id = input_id('id', '編號');
    $sql = match ($table) {
        'goods' => 'DELETE FROM goods WHERE id = ?',
        'employees' => 'DELETE FROM employees WHERE id = ?',
    };
    $statement = db()->prepare($sql);
    $statement->execute([$id]);
    if ($statement->rowCount() === 0) {
        throw new DomainException('這筆資料已不存在。');
    }
}

function decimal_input(string $key, string $label, int $wholeDigits, ?string $default = null): string
{
    $value = $_POST[$key] ?? $default;
    if (!is_string($value) || !preg_match('/^[0-9]{1,' . $wholeDigits . '}(?:\.[0-9]{1,2})?$/D', $value)) {
        throw new DomainException("請填寫有效的{$label}，小數最多兩位。");
    }
    return $value;
}

function save_employee(): void
{
    $id = input_id('id', '員工編號', true);
    $manager = input_id('manager_id', '主管', true);
    $name = input_text('name', '姓名', 100);
    $job = input_text('job_title', '職務', 80);
    $rate = decimal_input('hourly_rate', '時薪', 6);
    $overtime = decimal_input('overtime_hours', '加班時數', 4, '0');
    $insurer = empty($_POST['insurer']) ? null : input_text('insurer', '保險公司', 100);
    $birth = $_POST['birth_date'] ?? '';
    if ($birth !== '') {
        if (!is_string($birth) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $birth)) {
            throw new DomainException('請填寫有效的出生日期。');
        }
        $zone = new DateTimeZone('Asia/Taipei');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $birth, $zone);
        if (!$date || $date->format('Y-m-d') !== $birth || $birth < '1000-01-01' || $date > new DateTimeImmutable('today', $zone)) {
            throw new DomainException('請填寫有效的出生日期。');
        }
    }
    transaction(function (PDO $conn) use ($id, $manager, $name, $job, $rate, $birth, $overtime, $insurer) {
        // Lock the small personnel roster so concurrent supervisor edits cannot create a cycle.
        $reporting = array_column(rows('SELECT id, manager_id FROM employees ORDER BY id FOR UPDATE'), 'manager_id', 'id');
        if ($id !== null && !array_key_exists($id, $reporting)) {
            throw new DomainException('找不到這位員工。');
        }
        if ($manager !== null && !array_key_exists($manager, $reporting)) {
            throw new DomainException('找不到這位主管。');
        }
        $seen = [];
        for ($next = $manager; $next !== null; $next = $reporting[$next]) {
            if ((int)$next === $id || isset($seen[$next])) {
                throw new DomainException('主管安排會形成循環，請選擇其他主管。');
            }
            $seen[$next] = true;
        }
        $values = [$name, $job, $rate, $manager, $birth ?: null, $overtime, $insurer];
        if ($id === null) {
            $conn->prepare('INSERT INTO employees (name, job_title, hourly_rate, manager_id, birth_date, overtime_hours, insurer)
                            VALUES (?, ?, ?, ?, ?, ?, ?)')->execute($values);
        } else {
            $values[] = $id;
            $conn->prepare('UPDATE employees SET name=?, job_title=?, hourly_rate=?, manager_id=?, birth_date=?, overtime_hours=?, insurer=?
                            WHERE id=?')->execute($values);
        }
    });
}

function stock_in(int $employee): void
{
    $goodsId = input_id('goods_id', '貨物');
    $sectionId = input_id('section_id', '儲區');
    $quantity = input_id('quantity', '入庫數量');
    transaction(function (PDO $conn) use ($goodsId, $sectionId, $quantity, $employee) {
        $item = rows('SELECT client_id FROM goods WHERE id = ? FOR UPDATE', [$goodsId])[0] ?? null;
        $section = rows('SELECT client_id FROM sections WHERE id = ? FOR UPDATE', [$sectionId])[0] ?? null;
        if (!$item || !$section || (int)$item['client_id'] !== (int)$section['client_id']) {
            throw new DomainException('請選擇貨物所屬客戶承租的儲區。');
        }
        $conn->prepare('INSERT INTO inventory (goods_id, section_id, quantity, arrival_date)
                        VALUES (?, ?, ?, CURRENT_DATE)')->execute([$goodsId, $sectionId, $quantity]);
        $stockId = (int)$conn->lastInsertId();
        $conn->prepare("INSERT INTO movements (stock_id, type, quantity, employee_id)
                        VALUES (?, 'in', ?, ?)")->execute([$stockId, $quantity, $employee]);
    });
}

function stock_out(int $employee): void
{
    $stockId = input_id('stock_id', '庫存批次');
    $quantity = input_id('quantity', '出庫數量');
    transaction(function (PDO $conn) use ($stockId, $quantity, $employee) {
        $stock = rows('SELECT quantity FROM inventory WHERE id = ? FOR UPDATE', [$stockId])[0] ?? null;
        if (!$stock || (int)$stock['quantity'] < $quantity) {
            throw new DomainException('找不到批次或可出庫數量不足。');
        }
        $conn->prepare('UPDATE inventory SET quantity = quantity - ? WHERE id = ?')->execute([$quantity, $stockId]);
        $conn->prepare("INSERT INTO movements (stock_id, type, quantity, employee_id)
                        VALUES (?, 'out', ?, ?)")->execute([$stockId, $quantity, $employee]);
    });
}
