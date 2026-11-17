<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/partials/layout.php';
$user = require_role('employee');
$nav = ['overview' => '倉儲總覽', 'goods' => '貨物管理', 'inventory' => '庫存與收發', 'movements' => '進出貨紀錄'];
$view = ui_text('view');
$view = isset($nav[$view]) ? $view : 'overview';
$hints = ['overview' => '查看倉庫目前的庫存批次與最近的收發紀錄。', 'goods' => '維護客戶託管的貨物名稱及計量單位。', 'inventory' => '登記貨物入庫，或從指定庫存批次辦理出庫。', 'movements' => '查詢各批貨物的收發時間、儲區及承辦員工。'];
$q = $view === 'overview' ? '' : ui_text('q');
$data = dashboard_data($q);
$editId = ui_text('edit');
$editing = null;
if ($view === 'goods' && $editId !== '') {
    foreach ($data['goods'] as $item) {
        if ((string)$item['id'] === $editId) { $editing = $item; break; }
    }
}
ui_page($user, $nav, $view, $hints[$view]);
if ($view === 'overview') {
    ui_stats([['託管貨物', count($data['goods']), '種'], ['在庫批次', count(array_filter($data['inventory'], fn($r) => $r['quantity'] > 0)), '批'], ['使用中儲區', count(array_unique(array_column(array_filter($data['inventory'], fn($r) => $r['quantity'] > 0), 'section_id'))), '區']]);
}
if ($view === 'goods'): ?>
    <section class="panel">
        <?php ui_section('貨物清單'); ui_search($view, $q, '輸入貨物或客戶名稱');
        if (!$data['goods']): ui_no_records('目前沒有符合條件的貨物。'); else: ?>
        <div class="table-scroll" tabindex="0" role="region" aria-label="貨物清單"><table>
            <thead><tr><th>貨物編號</th><th>貨物名稱</th><th>所屬客戶</th><th>計量單位</th><th>操作</th></tr></thead>
            <tbody><?php foreach ($data['goods'] as $item): ?><tr>
                <td class="ref">#<?= h($item['id']) ?></td><td><?= h($item['name']) ?></td><td><?= h($item['client_name']) ?></td><td><?= h($item['unit']) ?></td>
                <td><div class="row-actions"><a href="?view=goods&amp;edit=<?= h($item['id']) ?>#goods-form">編輯</a><?php ui_delete('goods_delete', (int)$item['id'], $item['name']); ?></div></td>
            </tr><?php endforeach; ?></tbody>
        </table></div><?php endif; ?>
    </section>
    <section class="panel" id="goods-form">
        <?php ui_section($editing ? '編輯貨物' : '新增貨物'); ?>
        <form class="field-grid cols panel-body" method="post" action="actions.php">
            <?php ui_fields('goods_save'); ?>
            <input type="hidden" name="id" value="<?= h($editing['id'] ?? '') ?>">
            <label>貨物名稱<input name="name" required maxlength="100" value="<?= h($editing['name'] ?? '') ?>"></label>
            <label>所屬客戶<select name="client_id" required><option value="">選擇客戶</option>
                <?php foreach ($data['clients'] as $client): ?><option value="<?= h($client['id']) ?>" <?= (string)($editing['client_id'] ?? '') === (string)$client['id'] ? 'selected' : '' ?>><?= h($client['name']) ?></option><?php endforeach; ?>
            </select></label>
            <label>計量單位<input name="unit" required maxlength="16" placeholder="例如：箱" value="<?= h($editing['unit'] ?? '') ?>"></label>
            <div class="submit-row"><button class="button primary" type="submit"><?= $editing ? '儲存變更' : '新增貨物' ?></button><?php if ($editing): ?><a href="?view=goods#goods-form">取消編輯</a><?php endif; ?></div>
        </form>
    </section>
<?php endif;
if ($view === 'inventory'):
    $options = $q === '' ? $data : dashboard_data();
    $available = array_filter($options['inventory'], fn($r) => $r['quantity'] > 0); ?>
    <div class="two-up">
    <section class="panel"><div class="panel-head"><h2>入庫登記</h2><span class="pill green">新增批次</span></div>
    <?php if ($options['goods'] && $options['sections']): ?>
        <form method="post" action="actions.php" class="field-grid panel-body">
            <?php ui_fields('stock_in'); ?>
            <label>貨物<select name="goods_id" id="in-goods" required><option value="">選擇貨物</option>
            <?php foreach ($options['goods'] as $item): ?><option value="<?= h($item['id']) ?>" data-client="<?= h($item['client_id']) ?>"><?= h($item['client_name']) ?> ／ <?= h($item['name']) ?>（<?= h($item['unit']) ?>）</option><?php endforeach; ?>
            </select></label>
            <label>收貨儲區<select name="section_id" id="in-section" required><option value="">選擇儲區</option>
            <?php foreach ($options['sections'] as $item): ?><option value="<?= h($item['id']) ?>" data-client="<?= h($item['client_id']) ?>"><?= h($item['warehouse_name']) ?> ／ <?= h($item['name']) ?></option><?php endforeach; ?>
            </select></label>
            <label>入庫數量<input type="number" name="quantity" required min="1" max="2147483647" step="1" inputmode="numeric"></label>
            <div><button class="button primary" type="submit">登記入庫</button></div>
        </form>
    <?php else: ui_no_records('登記入庫前，需要先建立貨物並配置儲區。'); endif; ?></section>
    <section class="panel"><div class="panel-head"><h2>出庫登記</h2><span class="pill amber">扣除庫存</span></div>
    <?php if ($available): ?>
        <form method="post" action="actions.php" class="field-grid panel-body">
            <?php ui_fields('stock_out'); ?>
            <label>庫存批次<select name="stock_id" id="out-stock" required><option value="">選擇批次</option>
            <?php foreach ($available as $item): ?><option value="<?= h($item['id']) ?>" data-quantity="<?= h($item['quantity']) ?>">#<?= h($item['id']) ?> <?= h($item['goods_name']) ?> ｜ <?= h($item['warehouse_name']) ?>／<?= h($item['section_name']) ?> ｜ 餘 <?= h($item['quantity']) ?> <?= h($item['unit']) ?></option><?php endforeach; ?>
            </select></label>
            <label>出庫數量<input type="number" name="quantity" id="out-quantity" required min="1" max="2147483647" step="1" inputmode="numeric"></label>
            <div><button type="submit" class="button primary">登記出庫</button></div>
        </form>
    <?php else: ui_no_records('目前沒有可出庫的批次。'); endif; ?></section></div>
    <script src="assets/stock.js" defer></script>
<?php endif;
if (in_array($view, ['overview', 'inventory'], true)): ?>
    <section class="panel"><?php ui_section('庫存批次', $view === 'overview' ? 'inventory' : null);
        if ($view === 'inventory') ui_search($view, $q, '輸入貨物或儲區名稱');
        ui_inventory($view === 'overview' ? array_slice($data['inventory'], 0, 5) : $data['inventory'], true); ?></section>
<?php endif;
if (in_array($view, ['overview', 'movements'], true)): ?>
    <section class="panel"><?php ui_section('進出貨明細', $view === 'overview' ? 'movements' : null);
        if ($view === 'movements') ui_search($view, $q, '輸入貨物或儲區名稱');
        ui_movements($view === 'overview' ? array_slice($data['movements'], 0, 5) : $data['movements'], true); ?></section>
<?php endif;
ui_end();
