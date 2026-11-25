<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/partials/layout.php';
require __DIR__.'/partials/workflows.php';
$user = require_role('employee');
$nav = ['overview' => '收發工作台', 'goods' => '貨物管理', 'inventory' => '庫存與收發', 'movements' => '進出貨紀錄', 'requests' => '客戶申請'];
$view = ui_text('view');
$view = isset($nav[$view]) ? $view : 'overview';
$q = $view === 'overview' ? '' : ui_text('q');
$warehouse = $view === 'inventory' ? ui_text('warehouse') : '';
$warehouseId = $warehouse !== '' ? (ctype_digit($warehouse) ? (int)$warehouse : 0) : null;
$data = dashboard_data($q, $warehouseId);
$editId = ui_text('edit');
$editing = null;
$goodsOptions = $view === 'goods' && $q !== '' ? dashboard_data() : $data;
if ($view === 'goods' && $editId !== '') {
    foreach ($goodsOptions['goods'] as $item) {
        if ((string)$item['id'] === $editId) { $editing = $item; break; }
    }
}
$goodsInput = $view === 'goods' ? take_form_input('goods_save') : [];
if ((string)($goodsInput['id'] ?? '') !== (string)($editing['id'] ?? '')) {
    $goodsInput = [];
}
$goodsValues = array_replace($editing ?? [], $goodsInput);
$requests = request_data($view === 'requests' ? $q : '');
$goodsLocked = $editing && (array_filter($goodsOptions['inventory'], fn($row) => (string)$row['goods_id'] === (string)$editing['id']) || array_filter($requests, fn($r) => $r['type'] === 'in' && (string)$r['goods_id'] === (string)$editing['id']));
if ($goodsLocked) {
    $goodsValues['client_id'] = $editing['client_id'];
    $goodsValues['unit'] = $editing['unit'];
}
ui_page($user, $nav, $view, '');
if ($view === 'overview') ui_workbench($data, $requests, true);
if ($view === 'goods'): ?>
    <section class="panel">
        <?php ui_section('貨物清單'); ui_search($view, $q, '輸入貨物或客戶名稱');
        if (!$data['goods']): ui_no_records($q === '' ? '尚無貨物品項。' : '找不到符合條件的貨物。'); else: ?>
        <div class="table-scroll" tabindex="0" role="region" aria-label="貨物清單"><table>
            <thead><tr><th scope="col">貨物編號</th><th scope="col">貨物名稱</th><th scope="col">所屬客戶</th><th scope="col">計量單位</th><th scope="col">操作</th></tr></thead>
            <tbody><?php foreach ($data['goods'] as $item): ?><tr>
                <td class="ref">#<?= h($item['id']) ?></td><td><?= h($item['name']) ?></td><td><?= h($item['client_name']) ?></td><td><?= h($item['unit']) ?></td>
                <td><div class="row-actions"><a href="?view=goods&amp;edit=<?= h($item['id']) ?>#goods-form">編輯</a><?php ui_delete('goods_delete', (int)$item['id'], $item['name']); ?></div></td>
            </tr><?php endforeach; ?></tbody>
        </table></div><?php endif; ?>
    </section>
    <section class="panel" id="goods-form" data-drawer <?= $goodsInput || $editId !== '' ? 'data-open' : '' ?>>
        <?php ui_section($editId !== '' ? '編輯貨物' : '新增貨物');
        if ($editId !== '' && !$editing): ?>
        <div class="panel-body"><p class="form-note">找不到這項貨物。</p><a class="text-link" href="?view=goods">返回貨物管理</a></div>
        <?php else: ?>
        <form class="field-grid cols panel-body" method="post" action="actions.php">
            <?php ui_fields('goods_save'); ?>
            <input type="hidden" name="id" value="<?= h($editing['id'] ?? '') ?>">
            <?php if ($goodsLocked): ?><p class="form-note" id="goods-lock-note">已有申請或入庫紀錄，僅可修改貨物名稱。</p><?php endif; ?>
            <label>貨物名稱<input name="name" required maxlength="100" value="<?= h($goodsValues['name'] ?? '') ?>"></label>
            <label>所屬客戶<select name="client_id" required <?= $goodsLocked ? 'disabled aria-describedby="goods-lock-note"' : '' ?>><option value="">選擇客戶</option>
                <?php foreach ($goodsOptions['clients'] as $client): ?><option value="<?= h($client['id']) ?>" <?= (string)($goodsValues['client_id'] ?? '') === (string)$client['id'] ? 'selected' : '' ?>><?= h($client['name']) ?></option><?php endforeach; ?>
            </select></label>
            <?php if ($goodsLocked): ?><input type="hidden" name="client_id" value="<?= h($editing['client_id']) ?>"><?php endif; ?>
            <label>計量單位<input name="unit" required maxlength="16" placeholder="箱、台等" value="<?= h($goodsValues['unit'] ?? '') ?>" <?= $goodsLocked ? 'readonly aria-describedby="goods-lock-note"' : '' ?>></label>
            <div class="submit-row"><button class="button primary" type="submit"><?= $editing ? '儲存變更' : '新增貨物' ?></button><?php if ($editing): ?><a href="?view=goods#goods-form">取消編輯</a><?php endif; ?></div>
        </form>
        <?php endif; ?>
    </section>
<?php endif;
if ($view === 'inventory'): ?>
    <section class="panel"><?php ui_section('庫存批次', $view === 'overview' ? 'inventory' : null);
        if ($view === 'inventory') ui_search($view, $q, '輸入貨名、批號或儲區', $warehouseId);
        ui_inventory($view === 'overview' ? array_slice($data['inventory'], 0, 5) : $data['inventory'], true, $q); ?></section>
<?php endif;
if ($view === 'inventory'):
    $options = $q === '' && $warehouseId === null ? $data : dashboard_data();
    $available = array_filter($options['inventory'], fn($r) => $r['quantity'] > 0);
    $inValues = take_form_input('stock_in');
    $outValues = take_form_input('stock_out');
    if (!$outValues && ctype_digit(ui_text('out'))) {
        foreach ($available as $lot) {
            if ((string)$lot['id'] === ui_text('out')) { $outValues['stock_id'] = (string)$lot['id']; break; }
        }
    } ?>
    <div class="two-up">
    <section class="panel" id="stock-in-form" data-drawer <?= $inValues ? 'data-open' : '' ?>><?php ui_section('入庫登記'); ?>
    <?php if ($options['goods'] && $options['sections']): ?>
        <form method="post" action="actions.php" class="field-grid panel-body">
            <?php ui_fields('stock_in'); ?>
            <label>貨物<select name="goods_id" id="in-goods" required aria-describedby="in-preview"><option value="">選擇貨物</option>
            <?php foreach ($options['goods'] as $item): ?><option value="<?= h($item['id']) ?>" data-client="<?= h($item['client_id']) ?>" data-name="<?= h($item['name']) ?>" data-unit="<?= h($item['unit']) ?>" <?= (string)($inValues['goods_id'] ?? '') === (string)$item['id'] ? 'selected' : '' ?>><?= h($item['client_name']) ?> ／ <?= h($item['name']) ?>（<?= h($item['unit']) ?>）</option><?php endforeach; ?>
            </select></label>
            <label>收貨儲區<select name="section_id" id="in-section" required aria-describedby="in-preview"><option value="">選擇儲區</option>
            <?php foreach ($options['sections'] as $item): ?><option value="<?= h($item['id']) ?>" data-client="<?= h($item['client_id']) ?>" <?= (string)($inValues['section_id'] ?? '') === (string)$item['id'] ? 'selected' : '' ?>><?= h($item['warehouse_name']) ?> ／ <?= h($item['name']) ?></option><?php endforeach; ?>
            </select></label>
            <label>入庫數量<input type="number" name="quantity" id="in-quantity" required min="1" max="2147483647" step="1" inputmode="numeric" value="<?= h($inValues['quantity'] ?? '') ?>" aria-describedby="in-preview"></label>
            <p class="form-preview" id="in-preview" aria-live="polite" aria-atomic="true">請選擇貨物與儲區。</p>
            <div><button class="button primary" type="submit">登記入庫</button></div>
        </form>
    <?php else: ui_no_records(!$options['goods'] ? '尚無貨物品項。' : '尚無可用儲區。'); endif; ?></section>
    <section class="panel" id="stock-out-form" data-drawer <?= $outValues ? 'data-open' : '' ?>><?php ui_section('出庫登記'); ?>
    <?php if ($available): ?>
        <form method="post" action="actions.php" class="field-grid panel-body">
            <?php ui_fields('stock_out'); ?>
            <label>庫存批次<select name="stock_id" id="out-stock" required aria-describedby="out-preview"><option value="">選擇批次</option>
            <?php foreach ($available as $item): ?><option value="<?= h($item['id']) ?>" data-quantity="<?= h($item['quantity']) ?>" data-name="<?= h($item['goods_name']) ?>" data-unit="<?= h($item['unit']) ?>" data-location="<?= h($item['warehouse_name'] . '／' . $item['section_name']) ?>" <?= (string)($outValues['stock_id'] ?? '') === (string)$item['id'] ? 'selected' : '' ?>>#<?= h($item['id']) ?> <?= h($item['goods_name']) ?> ｜ <?= h($item['warehouse_name']) ?>／<?= h($item['section_name']) ?> ｜ 餘 <?= number_format((int)$item['quantity']) ?> <?= h($item['unit']) ?></option><?php endforeach; ?>
            </select></label>
            <label>出庫數量<input type="number" name="quantity" id="out-quantity" required min="1" max="2147483647" step="1" inputmode="numeric" value="<?= h($outValues['quantity'] ?? '') ?>" aria-describedby="out-preview"></label>
            <p class="form-preview" id="out-preview" aria-live="polite" aria-atomic="true">請選擇庫存批次。</p>
            <div><button type="submit" class="button primary">登記出庫</button></div>
        </form>
    <?php else: ui_no_records('尚無可出庫的貨物。'); endif; ?></section></div>
    <script src="assets/stock.js?v=20261126d" defer></script>
<?php endif;
if ($view === 'movements'): ?>
    <section class="panel"><?php ui_section('進出貨明細', $view === 'overview' ? 'movements' : null);
        if ($view === 'movements') ui_search($view, $q, '輸入貨名、批號或儲區');
        ui_movements($view === 'overview' ? array_slice($data['movements'], 0, 5) : $data['movements'], true, $q); ?></section>
<?php endif;
if ($view === 'inventory') ui_lot_details($data['inventory'], $options['movements']);
if ($view === 'requests') {
    ui_search($view, $q, '搜尋貨物或客戶');
    ui_request_list($requests, true);
    ui_request_details($requests, $data['sections'], true, ['request_complete' => take_form_input('request_complete'), 'request_reject' => take_form_input('request_reject')]);
}
ui_end();
