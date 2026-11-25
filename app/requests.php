<?php
declare(strict_types=1);

function create_stock_request(string $type, int $client): string
{
    $quantity = input_id('quantity', $type === 'in' ? '入庫數量' : '出庫數量');
    $target = input_id($type === 'in' ? 'goods_id' : 'stock_id', $type === 'in' ? '貨物' : '庫存批次');
    $id = transaction(function (PDO $conn) use ($type, $client, $quantity, $target): int {
        if ($type === 'in') {
            // Share the goods lock with edits, so an accepted request cannot change owner/unit later.
            $item = rows('SELECT id FROM goods WHERE id = ? AND client_id = ? FOR UPDATE', [$target, $client])[0] ?? null;
            if (!$item) throw new DomainException('找不到這項貨物，請從自己的貨物清單選擇。');
        } else {
            $lot = rows('SELECT i.quantity, g.unit FROM inventory i JOIN goods g ON g.id = i.goods_id
                         WHERE i.id = ? AND g.client_id = ? FOR UPDATE OF i', [$target, $client])[0] ?? null;
            if (!$lot) throw new DomainException('找不到這個庫存批次，請重新選擇。');
            if ($quantity > (int)$lot['quantity']) {
                throw new DomainException(sprintf('這批目前剩餘 %s %s，請調整申請數量。', number_format((int)$lot['quantity']), $lot['unit']));
            }
        }
        $conn->prepare('INSERT INTO stock_requests (type, goods_id, stock_id, quantity) VALUES (?, ?, ?, ?)')
            ->execute([$type, $type === 'in' ? $target : null, $type === 'out' ? $target : null, $quantity]);
        return (int)$conn->lastInsertId();
    });
    return sprintf('已送出%s申請 #%d，等待倉儲人員處理。', $type === 'in' ? '入庫' : '出庫', $id);
}

function cancel_stock_request(int $client): string
{
    $id = input_id('id', '申請編號');
    transaction(function (PDO $conn) use ($id, $client): void {
        $request = rows('SELECT r.id, r.status FROM stock_requests r
                        LEFT JOIN inventory i ON i.id = r.stock_id
                        JOIN goods g ON g.id = COALESCE(r.goods_id, i.goods_id)
                        WHERE r.id = ? AND g.client_id = ? FOR UPDATE OF r', [$id, $client])[0] ?? null;
        if (!$request) throw new DomainException('找不到這筆申請。');
        if ($request['status'] !== 'pending') throw new DomainException('這筆申請已處理，無法取消。');
        $conn->prepare("UPDATE stock_requests SET status = 'cancelled', handled_at = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute([$id]);
    });
    return "已取消申請 #{$id}。";
}

function complete_stock_request(int $employee): string
{
    $id = input_id('id', '申請編號');
    $result = transaction(function (PDO $conn) use ($id, $employee): array {
        $request = rows('SELECT * FROM stock_requests WHERE id = ? FOR UPDATE', [$id])[0] ?? null;
        if (!$request) throw new DomainException('找不到這筆申請。');
        if ($request['status'] !== 'pending') throw new DomainException('這筆申請已處理，請查看最新結果。');
        // Request, quantity and ledger commit together; never call transaction() inside this transaction.
        if ($request['type'] === 'in') {
            $result = record_stock_in($conn, (int)$request['goods_id'], input_id('section_id', '收貨儲區'), (int)$request['quantity'], $employee);
        } else {
            $result = record_stock_out($conn, (int)$request['stock_id'], (int)$request['quantity'], $employee);
        }
        $conn->prepare("UPDATE stock_requests SET status = 'completed', handled_at = CURRENT_TIMESTAMP,
                       employee_id = ?, movement_id = ? WHERE id = ?")
            ->execute([$employee, $result['movement_id'], $id]);
        return $result;
    });
    return sprintf('已完成申請 #%d，批號 #%d 的庫存與收發紀錄已更新。', $id, $result['stock_id']);
}

function reject_stock_request(int $employee): string
{
    $id = input_id('id', '申請編號');
    $note = input_text('response_note', '未受理原因', 255);
    transaction(function (PDO $conn) use ($id, $employee, $note): void {
        $request = rows('SELECT status FROM stock_requests WHERE id = ? FOR UPDATE', [$id])[0] ?? null;
        if (!$request) throw new DomainException('找不到這筆申請。');
        if ($request['status'] !== 'pending') throw new DomainException('這筆申請已處理，請查看最新結果。');
        $conn->prepare("UPDATE stock_requests SET status = 'rejected', handled_at = CURRENT_TIMESTAMP,
                       employee_id = ?, response_note = ? WHERE id = ?")->execute([$employee, $note, $id]);
    });
    return "已將申請 #{$id} 標記為未受理，客戶可查看原因。";
}
