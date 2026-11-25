<?php
declare(strict_types=1);

function request_label(string $status): string {
    return ['pending' => '待處理', 'completed' => '已完成', 'rejected' => '未受理', 'cancelled' => '已取消'][$status] ?? $status;
}

function ui_request_list(array $requests, bool $employee, bool $preview = false): void { ?>
    <section class="panel request-list">
        <?php ui_section($preview ? ($employee ? '等待處理的申請' : '我的申請進度') : '收發申請', $preview ? 'requests' : null); ?>
        <?php if (!$requests): ?><div class="quiet-empty"><span aria-hidden="true">✓</span><h3><?= $employee ? '目前沒有待處理申請' : '目前沒有收發申請' ?></h3><p><?= $employee ? '客戶送出後，會出現在這裡。' : '貨物要送進倉庫時，先建立品項，再填寫入庫數量。' ?></p><?php if (!$employee): ?><a class="text-link" href="?view=requests#request-in-form">填寫入庫申請 →</a><?php endif; ?></div>
        <?php else: ?>
        <div class="table-scroll" tabindex="0" role="region" aria-label="收發申請"><table class="request-table"><thead><tr><th scope="col">申請內容</th><th scope="col"><?= $employee ? '客戶' : '送出時間' ?></th><th scope="col">進度</th><th scope="col">操作</th></tr></thead><tbody>
            <?php foreach ($requests as $r): ?><tr>
            <td data-label="申請內容"><a class="record-title" href="#request-<?= h($r['id']) ?>"><?= h($r['goods_name']) ?></a><small class="record-sub"><span class="direction <?= $r['type'] === 'in' ? 'incoming' : 'outgoing' ?>"><?= $r['type'] === 'in' ? '↙ 入庫' : '↗ 出庫' ?></span> <?= number_format((int)$r['quantity']) ?> <?= h($r['unit']) ?> <span class="muted">· #<?= h($r['id']) ?></span></small></td>
            <td data-label="<?= $employee ? '客戶' : '送出時間' ?>"><?php if ($employee): ?><?= h($r['client_name']) ?><small class="record-sub"><?= h(substr($r['created_at'], 0, 16)) ?></small><?php else: ?><?= h(substr($r['created_at'], 0, 16)) ?><?php endif; ?></td>
            <td data-label="進度"><span class="pill <?= $r['status'] === 'pending' ? 'amber' : ($r['status'] === 'completed' ? 'green' : 'gray') ?>"><?= h(request_label($r['status'])) ?></span></td>
            <td data-label="操作"><a class="batch-action" href="#request-<?= h($r['id']) ?>"><?= $employee && $r['status'] === 'pending' ? '處理申請 →' : '查看明細 →' ?></a></td>
            </tr><?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
    </section>
<?php }

function ui_request_details(array $requests, array $sections, bool $employee, array $formInputs = []): void {
    foreach ($requests as $r):
        $open = false;
        foreach ($formInputs as $input) if ((string)($input['id'] ?? '') === (string)$r['id']) $open = true;
        ?>
        <section class="panel" data-drawer <?= $open ? 'data-open' : '' ?> id="request-<?= h($r['id']) ?>">
        <?php ui_section('收發申請 #'.$r['id']); ?>
        <div class="panel-body">
            <span class="eyebrow"><?= $r['type'] === 'in' ? '入庫申請' : '出庫申請' ?> · <?= h(request_label($r['status'])) ?></span>
            <h3 class="detail-name"><?= h($r['goods_name']) ?></h3>
            <p class="detail-quantity"><?= number_format((int)$r['quantity']) ?> <small><?= h($r['unit']) ?></small></p>
            <dl class="detail-facts"><div><dt>客戶</dt><dd><?= h($r['client_name']) ?></dd></div><div><dt>送出時間</dt><dd><?= h($r['created_at']) ?></dd></div>
            <?php if ($r['stock_id']): ?><div><dt>指定批次</dt><dd>#<?= h($r['stock_id']) ?> · <?= h($r['warehouse_name']) ?>／<?= h($r['section_name']) ?></dd></div><div><dt>目前庫存</dt><dd><?= number_format((int)$r['current_quantity']) ?> <?= h($r['unit']) ?></dd></div><?php endif; ?>
            <?php if ($r['handled_at']): ?><div><dt>處理時間</dt><dd><?= h($r['handled_at']) ?></dd></div><?php endif; ?>
            <?php if ($r['employee_name']): ?><div><dt>承辦員工</dt><dd><?= h($r['employee_name']) ?></dd></div><?php endif; ?>
            <?php if ($r['result_stock_id']): ?><div><dt>收發批次</dt><dd>#<?= h($r['result_stock_id']) ?></dd></div><?php endif; ?></dl>
            <?php if ($r['response_note']): ?><p class="response-note"><?= h($r['response_note']) ?></p><?php endif; ?>
        </div>
        <?php if ($r['status'] === 'pending'): ?>
            <?php if ($employee): ?>
                <form class="field-grid panel-body operation-block" method="post" action="actions.php">
                    <?php ui_fields('request_complete'); ?><input type="hidden" name="id" value="<?= h($r['id']) ?>">
                    <h3>確認本次收發</h3>
                    <?php if ($r['type'] === 'in'): ?><label>實際收貨儲區<select name="section_id" required><option value="">選擇儲區</option><?php foreach ($sections as $section): if ((int)$section['client_id'] !== (int)$r['client_id']) continue; ?><option value="<?= h($section['id']) ?>" <?= (string)($formInputs['request_complete']['id'] ?? '') === (string)$r['id'] && (string)($formInputs['request_complete']['section_id'] ?? '') === (string)$section['id'] ? 'selected' : '' ?>><?= h($section['warehouse_name']) ?>／<?= h($section['name']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
                    <p class="form-note"><?= $r['type'] === 'in' ? '確認已收到貨物後再完成。系統會建立庫存批次與入庫紀錄。' : '確認已交出貨物後再完成。系統會扣除指定批次的庫存並留下出庫紀錄。' ?></p>
                    <button class="button primary" type="submit">確認<?= $r['type'] === 'in' ? '入庫' : '出庫' ?> <?= number_format((int)$r['quantity']) ?> <?= h($r['unit']) ?></button>
                </form>
                <details class="request-reject panel-body" <?= (string)($formInputs['request_reject']['id'] ?? '') === (string)$r['id'] ? 'open' : '' ?>><summary>無法受理這筆申請</summary><form class="field-grid" method="post" action="actions.php"><?php ui_fields('request_reject'); ?><input type="hidden" name="id" value="<?= h($r['id']) ?>"><label>說明原因<input <?= (string)($formInputs['request_reject']['id'] ?? '') === (string)$r['id'] ? 'data-drawer-focus' : '' ?> name="response_note" required maxlength="250" value="<?= (string)($formInputs['request_reject']['id'] ?? '') === (string)$r['id'] ? h($formInputs['request_reject']['response_note'] ?? '') : '' ?>"></label><button class="button danger" type="submit">回覆不受理</button></form></details>
            <?php else: ?><form class="panel-body operation-block" method="post" action="actions.php"><?php ui_fields('client_request_cancel'); ?><input type="hidden" name="id" value="<?= h($r['id']) ?>"><p class="form-note">倉儲人員處理前，可以取消申請。送出申請還不會改變庫存數量。</p><button class="button" type="submit">取消這筆申請</button></form><?php endif; ?>
        <?php endif; ?></section>
    <?php endforeach;
}

function ui_lot_details(array $lots, array $movements): void {
    foreach ($lots as $lot): ?>
    <section class="panel" id="lot-<?= h($lot['id']) ?>" data-drawer>
        <?php ui_section('批次 #'.$lot['id']); ?>
        <div class="panel-body"><span class="eyebrow"><?= h($lot['warehouse_name']) ?> ／ <?= h($lot['section_name']) ?></span><h3 class="detail-name"><?= h($lot['goods_name']) ?></h3><p class="detail-quantity"><?= number_format((int)$lot['quantity']) ?> <small><?= h($lot['unit']) ?>在庫</small></p><p class="muted">到倉日 <?= h($lot['arrival_date']) ?></p>
        <h3 class="timeline-heading">這一批的收發紀錄</h3><ol class="lot-timeline">
        <?php foreach ($movements as $movement): if ((int)$movement['stock_id'] !== (int)$lot['id']) continue; ?>
            <li><span class="timeline-dot <?= $movement['type'] === 'in' ? 'incoming' : 'outgoing' ?>" aria-hidden="true"><?= $movement['type'] === 'in' ? '↙' : '↗' ?></span><div><strong><?= $movement['type'] === 'in' ? '入庫' : '出庫' ?> <?= number_format((int)$movement['quantity']) ?> <?= h($movement['unit']) ?></strong><small class="record-sub"><?= h($movement['moved_at']) ?></small></div></li>
        <?php endforeach; ?></ol></div>
    </section>
    <?php endforeach;
}

function ui_activity_rail(array $movements): void { ?>
    <aside class="activity-rail"><div class="rail-heading"><h2>最近收發</h2><a href="?view=movements">全部 →</a></div>
    <?php if (!$movements): ?><p class="muted">完成第一筆收發後，紀錄會列在這裡。</p><?php endif; ?>
    <ol class="activity-feed"><?php foreach (array_slice($movements, 0, 5) as $m): ?><li><div class="activity-meta"><span class="direction <?= $m['type'] === 'in' ? 'incoming' : 'outgoing' ?>"><?= $m['type'] === 'in' ? '↙ 入庫' : '↗ 出庫' ?></span><time><?= h(substr($m['moved_at'], 5, 11)) ?></time></div><strong><?= h($m['goods_name']) ?></strong><div class="activity-bottom"><span><?= h($m['warehouse_name']) ?>／<?= h($m['section_name']) ?></span><b><?= number_format((int)$m['quantity']) ?> <?= h($m['unit']) ?></b></div></li><?php endforeach; ?></ol></aside>
<?php }

function ui_workbench(array $data, array $requests, bool $employee): void {
    $pending = array_values(array_filter($requests, fn($r) => $r['status'] === 'pending'));
    $positive = array_filter($data['inventory'], fn($r) => (int)$r['quantity'] > 0);
    ?>
    <div class="work-context"><span><b><?= count($pending) ?></b> 筆申請待處理</span><span><b><?= count($positive) ?></b> 批在庫</span><a href="?view=goods"><?= count($data['goods']) ?> 個貨物品項 →</a></div>
    <div class="workbench-grid"><div>
        <?php if ($employee && !$pending): ?><div class="queue-clear"><span aria-hidden="true">✓</span><span>目前沒有待處理申請</span><a href="?view=requests">查看申請紀錄 →</a></div>
        <?php else: ui_request_list(array_slice($employee ? $pending : $requests, 0, 5), $employee, true); endif; ?>
        <section class="panel"><div class="panel-head"><div><h2><?= $employee ? '查找庫存' : '我的庫存' ?></h2><p>點選貨物，查看這一批的完整收發紀錄。</p></div><a class="text-link" href="?view=inventory">全部批次 →</a></div>
            <?php ui_search('inventory', '', '找貨名、批號或儲區'); ui_inventory(array_slice(array_values($positive), 0, 5), $employee); ?>
        </section>
    </div><?php ui_activity_rail($data['movements']); ?></div>
    <?php ui_request_details(array_slice($employee ? $pending : $requests, 0, 5), $data['sections'], $employee); ui_lot_details(array_slice(array_values($positive), 0, 5), $data['movements']);
}
