<?php
declare(strict_types=1);

function save_goods(?int $owner = null): string
{
    $id = input_id('id', '貨物編號', true);
    $client = $owner ?? input_id('client_id', '客戶');
    $name = input_text('name', '貨物名稱', 100);
    $unit = input_text('unit', '單位', 16);
    transaction(function (PDO $conn) use ($id, $client, $name, $unit, $owner) {
        if ($id === null) {
            $conn->prepare('INSERT INTO goods (client_id, name, unit) VALUES (?, ?, ?)')->execute([$client, $name, $unit]);
            return;
        }
        $old = rows('SELECT client_id, unit FROM goods WHERE id = ?' . ($owner === null ? '' : ' AND client_id = ?') . ' FOR UPDATE', $owner === null ? [$id] : [$id, $owner])[0] ?? null;
        if (!$old) {
            throw new DomainException('找不到這筆貨物。');
        }
        if (((int)$old['client_id'] !== $client || $old['unit'] !== $unit)
            && (rows('SELECT id FROM inventory WHERE goods_id = ? LIMIT 1', [$id])
                || rows('SELECT id FROM stock_requests WHERE goods_id = ? LIMIT 1', [$id]))) {
            throw new DomainException('這筆貨物已有入庫或申請紀錄，無法更換所屬客戶或計量單位。');
        }
        $conn->prepare('UPDATE goods SET client_id = ?, name = ?, unit = ? WHERE id = ?')->execute([$client, $name, $unit, $id]);
    });
    return $id === null ? "已新增貨物「{$name}」。" : "已更新「{$name}」的貨物資料。";
}

function delete_record(string $table): string
{
    $id = input_id('id', '編號');
    if ($table === 'employees') {
        transaction(function (PDO $conn) use ($id) {
            if (!rows('SELECT id FROM employees WHERE id=? FOR UPDATE', [$id])) throw new DomainException('這筆資料已不存在。');
            assert_manageable_employee($id);
            $conn->prepare('DELETE FROM employees WHERE id=?')->execute([$id]);
            admin_audit_record('employee_deleted', "刪除無關聯的員工資料 #{$id}。");
        });
        return '已刪除員工資料。';
    }
    if ($table !== 'goods') throw new LogicException('Unsupported table.');
    transaction(function (PDO $conn) use ($id) {
        $statement = $conn->prepare('DELETE FROM goods WHERE id=?');
        $statement->execute([$id]);
        if ($statement->rowCount() === 0) throw new DomainException('這筆資料已不存在。');
    });
    return '已刪除貨物。';
}

function decimal_input(string $key, string $label, int $wholeDigits, ?string $default = null): string
{
    $value = $_POST[$key] ?? $default;
    if (!is_string($value) || !preg_match('/^[0-9]{1,' . $wholeDigits . '}(?:\.[0-9]{1,2})?$/D', $value)) {
        throw new DomainException("請填寫有效的{$label}，小數最多兩位。");
    }
    return $value;
}

function save_employee(): string
{
    $id = input_id('id', '員工編號', true);
    $manager = input_id('manager_id', '主管', true);
    $name = input_text('name', '姓名', 100);
    $job = input_text('job_title', '職務', 80);
    $salary = ($_POST['monthly_salary'] ?? '') === '' ? null : decimal_input('monthly_salary', '月薪', 8);
    $status = $_POST['employment_status'] ?? 'active';
    if (!in_array($status, ['active', 'inactive'], true)) throw new DomainException('請選擇有效的在職狀態。');
    $overtime = decimal_input('overtime_hours', '加班時數', 4, '0');
    $insurer = $_POST['insurer'] ?? '';
    if (!is_string($insurer)) {
        throw new DomainException('請確認保險公司的格式。');
    }
    $insurer = trim($insurer) === '' ? null : input_text('insurer', '保險公司', 100);
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
    transaction(function (PDO $conn) use ($id, $manager, $name, $job, $salary, $status, $birth, $overtime, $insurer) {
        // Lock the small personnel roster so concurrent supervisor edits cannot create a cycle.
        $roster = rows('SELECT id, manager_id, employment_status FROM employees ORDER BY id FOR UPDATE');
        $reporting = array_column($roster, 'manager_id', 'id');
        $statuses = array_column($roster, 'employment_status', 'id');
        if ($id !== null) assert_manageable_employee($id);
        if ($id !== null && !array_key_exists($id, $reporting)) {
            throw new DomainException('找不到這位員工。');
        }
        if ($manager !== null && !array_key_exists($manager, $reporting)) {
            throw new DomainException('找不到這位主管。');
        }
        if ($manager !== null && $statuses[$manager] !== 'active') throw new DomainException('請選擇在職主管。');
        $seen = [];
        for ($next = $manager; $next !== null; $next = $reporting[$next]) {
            if ((int)$next === $id || isset($seen[$next])) {
                throw new DomainException('主管安排會形成循環，請選擇其他主管。');
            }
            $seen[$next] = true;
        }
        $values = [$name, $job, $salary, $status, $manager, $birth ?: null, $overtime, $insurer];
        if ($id === null) {
            $conn->prepare('INSERT INTO employees (name, job_title, monthly_salary, employment_status, manager_id, birth_date, overtime_hours, insurer)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute($values);
            $newId = (int)$conn->lastInsertId();
            admin_audit_record('employee_created', "新增員工資料 #{$newId}。", null, $newId);
        } else {
            $values[] = $id;
            $conn->prepare('UPDATE employees SET name=?, job_title=?, monthly_salary=?, employment_status=?, manager_id=?, birth_date=?, overtime_hours=?, insurer=?
                            WHERE id=?')->execute($values);
            $detail = "更新員工資料 #{$id}。";
            if ($statuses[$id] !== $status) {
                $labels = ['active' => '在職', 'inactive' => '離職'];
                $detail .= '在職狀態由「' . $labels[$statuses[$id]] . '」改為「' . $labels[$status] . '」。';
                $accounts = rows('SELECT id FROM users WHERE employee_id=? FOR UPDATE', [$id]);
                if ($status === 'inactive') {
                    $conn->prepare("UPDATE users SET status='disabled',auth_version=auth_version+1 WHERE employee_id=?")->execute([$id]);
                } else {
                    $conn->prepare('UPDATE users SET auth_version=auth_version+1 WHERE employee_id=?')->execute([$id]);
                }
                foreach ($accounts as $account) revoke_account_tokens((int)$account['id']);
            }
            admin_audit_record('employee_updated', $detail, null, $id);
        }
    });
    return $id === null ? "已將{$name}加入員工名冊。" : "已更新{$name}的員工資料。";
}

/** Must run inside the caller's transaction. */
function record_stock_in(PDO $conn, int $goodsId, int $sectionId, int $quantity, int $employee): array
{
    $item = rows('SELECT client_id, unit FROM goods WHERE id = ? FOR UPDATE', [$goodsId])[0] ?? null;
    $section = rows('SELECT client_id FROM sections WHERE id = ? FOR UPDATE', [$sectionId])[0] ?? null;
    if (!$item || !$section || (int)$item['client_id'] !== (int)$section['client_id']) {
        throw new DomainException('請選擇貨物所屬客戶承租的儲區。');
    }
    $conn->prepare('INSERT INTO inventory (goods_id, section_id, quantity, arrival_date)
                    VALUES (?, ?, ?, CURRENT_DATE)')->execute([$goodsId, $sectionId, $quantity]);
    $stockId = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO movements (stock_id, type, quantity, employee_id)
                    VALUES (?, 'in', ?, ?)")->execute([$stockId, $quantity, $employee]);
    return ['stock_id' => $stockId, 'movement_id' => (int)$conn->lastInsertId(), 'unit' => $item['unit']];
}

/** Must run inside the caller's transaction. */
function record_stock_out(PDO $conn, int $stockId, int $quantity, int $employee): array
{
    $stock = rows('SELECT i.quantity, g.unit FROM inventory i JOIN goods g ON g.id = i.goods_id
                   WHERE i.id = ? FOR UPDATE OF i', [$stockId])[0] ?? null;
    if (!$stock) {
        throw new DomainException('找不到這個庫存批次，請重新選擇。');
    }
    $remaining = (int)$stock['quantity'] - $quantity;
    if ($remaining < 0) {
        throw new DomainException(sprintf('批號 #%d 只剩 %s %s，請調整出庫數量。', $stockId, number_format((int)$stock['quantity']), $stock['unit']));
    }
    $conn->prepare('UPDATE inventory SET quantity = quantity - ? WHERE id = ?')->execute([$quantity, $stockId]);
    $conn->prepare("INSERT INTO movements (stock_id, type, quantity, employee_id)
                    VALUES (?, 'out', ?, ?)")->execute([$stockId, $quantity, $employee]);
    return ['stock_id' => $stockId, 'movement_id' => (int)$conn->lastInsertId(), 'unit' => $stock['unit'], 'remaining' => $remaining];
}

function stock_in(int $employee): string
{
    $goodsId = input_id('goods_id', '貨物');
    $sectionId = input_id('section_id', '儲區');
    $quantity = input_id('quantity', '入庫數量');
    $result = transaction(fn(PDO $conn) => record_stock_in($conn, $goodsId, $sectionId, $quantity, $employee));
    return sprintf('已入庫 %s %s，批號 #%d。', number_format($quantity), $result['unit'], $result['stock_id']);
}

function stock_out(int $employee): string
{
    $stockId = input_id('stock_id', '庫存批次');
    $quantity = input_id('quantity', '出庫數量');
    $result = transaction(fn(PDO $conn) => record_stock_out($conn, $stockId, $quantity, $employee));
    return sprintf('已出庫 %s %s，批號 #%d 剩餘 %s %s。', number_format($quantity), $result['unit'], $stockId, number_format($result['remaining']), $result['unit']);
}

require_once __DIR__ . '/requests.php';
