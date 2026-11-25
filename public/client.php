<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/partials/layout.php';
require __DIR__.'/partials/workflows.php';
$user = require_role('client');
$nav = ['overview' => '我的倉儲', 'goods' => '貨物品項', 'inventory' => '庫存批次', 'movements' => '進出貨紀錄', 'requests' => '收發申請'];
$view = ui_text('view');
$view = isset($nav[$view]) ? $view : 'overview';
$hints = [
    'overview' => '追蹤收發申請，查看寄存在倉庫的貨物。',
    'goods' => '先建立貨物品項，再提出入庫申請。',
    'inventory' => '點選貨物查看批次明細；要取貨時，可直接申請出庫。',
    'movements' => '這裡列出已完成的實際收發，申請進度請到「收發申請」查看。',
    'requests' => '送出後由倉儲人員處理，完成收發才會更新庫存。',
];
$q = $view === 'overview' ? '' : ui_text('q');
$warehouse = $view === 'inventory' ? ui_text('warehouse') : '';
$warehouseId = $warehouse !== '' ? (ctype_digit($warehouse) ? (int)$warehouse : 0) : null;
$data = dashboard_data($q, $warehouseId);
$options = $q !== '' || $warehouseId !== null ? dashboard_data() : $data;
$requests = request_data($view === 'requests' ? $q : '');
ui_page($user, $nav, $view, $hints[$view]);
if ($view === 'overview') ui_workbench($data, $requests, false);
if ($view === 'goods'):
    $editId = ui_text('edit');
    $editing = null;
    foreach ($options['goods'] as $g) if ((string)$g['id'] === $editId) $editing = $g;
    $input = take_form_input('client_goods_save');
    if ((string)($input['id'] ?? '') !== (string)($editing['id'] ?? '')) $input = [];
    $values = array_replace($editing ?? [], $input);
    $locked = $editing && (array_filter($options['inventory'], fn($r) => (int)$r['goods_id'] === (int)$editing['id']) || array_filter($requests, fn($r) => $r['type'] === 'in' && (int)$r['goods_id'] === (int)$editing['id']));
    if ($locked) $values['unit'] = $editing['unit']; ?>
    <section class="panel"><?php ui_section('我的品項'); ui_search($view, $q, '輸入貨物名稱'); ?>
    <?php if (!$data['goods']): ?><div class="quiet-empty"><h3><?= $q !== '' ? '找不到這項貨物' : '先替第一項貨物建檔' ?></h3><p><?= $q !== '' ? '試試其他貨名，或清除搜尋。' : '填寫名稱與計量單位，之後就能申請入庫。' ?></p><a class="button primary" href="?view=goods#client-goods-form">建立品項</a></div><?php else: ?>
    <div class="table-scroll" tabindex="0" role="region" aria-label="貨物品項"><table><thead><tr><th scope="col">貨物名稱</th><th scope="col">目前在庫</th><th scope="col">操作</th></tr></thead><tbody>
    <?php foreach ($data['goods'] as $g): $total = array_sum(array_column(array_filter($options['inventory'], fn($r) => (int)$r['goods_id'] === (int)$g['id']), 'quantity')); ?>
    <tr><td><strong><?= h($g['name']) ?></strong><small class="record-sub">品項 #<?= h($g['id']) ?></small></td><td><strong class="balance"><?= number_format($total) ?></strong> <?= h($g['unit']) ?></td><td><div class="row-actions"><a href="?view=goods&amp;edit=<?= h($g['id']) ?>#client-goods-form">編輯</a><a class="batch-action" href="?view=requests&amp;goods=<?= h($g['id']) ?>#request-in-form">申請入庫 →</a></div></td></tr>
    <?php endforeach; ?></tbody></table></div><?php endif; ?></section>
    <section class="panel" id="client-goods-form" data-drawer <?= $input || $editId !== '' ? 'data-open' : '' ?>><?php ui_section($editing ? '編輯貨物品項' : '建立貨物品項'); ?>
        <?php if ($editId !== '' && !$editing): ?><p class="empty">找不到這項貨物，請從自己的品項清單重新選擇。</p><?php else: ?>
        <form class="field-grid panel-body" method="post" action="actions.php"><?php ui_fields('client_goods_save'); ?><input type="hidden" name="id" value="<?= h($editing['id'] ?? '') ?>">
        <label>貨物名稱<input name="name" maxlength="100" required value="<?= h($values['name'] ?? '') ?>"></label>
        <label>計量單位<input name="unit" maxlength="16" required placeholder="例如：箱、台" value="<?= h($values['unit'] ?? '') ?>" <?= $locked ? 'readonly' : '' ?>></label>
        <?php if ($locked): ?><p class="form-note">這項貨物已有申請或入庫紀錄，計量單位不能再改；仍可修改名稱。</p><?php endif; ?>
        <button class="button primary" type="submit"><?= $editing ? '儲存變更' : '建立品項' ?></button></form><?php endif; ?>
    </section>
<?php endif;
if ($view === 'inventory'): ?>
    <section class="panel"><?php ui_section('我的庫存'); ui_search($view, $q, '找貨名、批號或儲區', $warehouseId); ui_inventory($data['inventory'], false, $q); ?></section>
    <?php ui_lot_details($data['inventory'], $options['movements']); ?>
<?php endif;
if ($view === 'movements'): ?>
    <section class="panel"><?php ui_section('實際收發紀錄'); ui_search($view, $q, '找貨名、批號或儲區'); ui_movements($data['movements'], false, $q); ?></section>
<?php endif;
if ($view === 'requests'):
    $in = take_form_input('client_request_in');
    $out = take_form_input('client_request_out');
    $cancel = take_form_input('client_request_cancel');
    if (!$in && ctype_digit(ui_text('goods'))) $in['goods_id'] = ui_text('goods');
    if (!$out && ctype_digit(ui_text('out'))) $out['stock_id'] = ui_text('out');
    ui_search($view, $q, '搜尋申請的貨物名稱');
    ui_request_list($requests, false);
    ui_request_details($requests, [], false, ['client_request_cancel' => $cancel]); ?>
    <section class="panel" id="request-in-form" data-drawer <?= isset($in['quantity']) ? 'data-open' : '' ?>><?php ui_section('申請入庫'); ?>
    <?php if (!$options['goods']): ?><div class="panel-body"><p>還沒有貨物品項，先填寫貨物名稱與計量單位。</p><a class="button primary" href="?view=goods#client-goods-form">建立第一個品項</a></div><?php else: ?>
    <form class="field-grid panel-body" method="post" action="actions.php"><?php ui_fields('client_request_in'); ?>
    <label>要入庫的貨物<select name="goods_id" required><option value="">選擇貨物</option><?php foreach ($options['goods'] as $g): ?><option value="<?= h($g['id']) ?>" <?= (string)($in['goods_id'] ?? '') === (string)$g['id'] ? 'selected' : '' ?>><?= h($g['name']) ?>（<?= h($g['unit']) ?>）</option><?php endforeach; ?></select></label>
    <a class="text-link" href="?view=goods#client-goods-form">找不到品項？先建立貨物 →</a>
    <label>入庫數量<input type="number" name="quantity" required min="1" max="2147483647" step="1" inputmode="numeric" value="<?= h($in['quantity'] ?? '') ?>"></label>
    <p class="form-note">倉儲人員收到貨物後，會安排儲區並確認入庫。處理前可在申請明細取消。</p>
    <button class="button primary" type="submit">送出入庫申請</button></form><?php endif; ?></section>
    <section class="panel" id="request-out-form" data-drawer <?= isset($out['quantity']) ? 'data-open' : '' ?>><?php ui_section('申請出庫'); $available = array_filter($options['inventory'], fn($r) => $r['quantity'] > 0); ?>
    <?php if (!$available): ?><p class="empty">目前沒有可出庫的貨物。</p><?php else: ?>
    <form class="field-grid panel-body" method="post" action="actions.php"><?php ui_fields('client_request_out'); ?>
        <label>要取出的批次<select name="stock_id" id="out-stock" required><option value="">選擇批次</option><?php foreach ($available as $lot): ?><option value="<?= h($lot['id']) ?>" data-quantity="<?= h($lot['quantity']) ?>" data-name="<?= h($lot['goods_name']) ?>" data-unit="<?= h($lot['unit']) ?>" data-location="<?= h($lot['warehouse_name'].'／'.$lot['section_name']) ?>" <?= (string)($out['stock_id'] ?? '') === (string)$lot['id'] ? 'selected' : '' ?>>#<?= h($lot['id']) ?> <?= h($lot['goods_name']) ?> · <?= h($lot['warehouse_name'].'／'.$lot['section_name']) ?> · 餘 <?= h($lot['quantity']) ?> <?= h($lot['unit']) ?></option><?php endforeach; ?></select></label>
        <label>出庫數量<input type="number" name="quantity" id="out-quantity" required min="1" max="2147483647" step="1" inputmode="numeric" value="<?= h($out['quantity'] ?? '') ?>"></label>
        <p class="form-preview" id="out-preview" aria-live="polite" aria-atomic="true">選擇批次後，可確認目前數量。</p><p class="form-note">送出時不會扣除或保留庫存；倉儲人員完成出庫時，會再確認數量。</p><button class="button primary" type="submit">送出出庫申請</button>
    </form><?php endif; ?></section>
    <script src="assets/stock.js?v=20261126c" defer></script>
<?php endif;
ui_end();
