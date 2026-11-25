<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/partials/layout.php';
$user = require_role('client');
$nav = ['overview' => '客戶總覽', 'goods' => '我的貨物', 'inventory' => '我的庫存', 'movements' => '進出貨紀錄'];
$view = ui_text('view');
$view = isset($nav[$view]) ? $view : 'overview';
$hints = [
    'overview' => '查看寄存貨物的存放位置、剩餘數量與最近收發。',
    'goods' => '已建檔的貨物都列在這裡，包括尚未入庫的品項。',
    'inventory' => '同一品項分次入庫時，每一批都會列出存放位置和剩餘數量。',
    'movements' => '每筆紀錄都列出進出貨時間、批號和數量，最新的排在最上面。',
];
$q = $view === 'overview' ? '' : ui_text('q');
$warehouse = $view === 'inventory' ? ui_text('warehouse') : '';
$warehouseId = $warehouse !== '' ? (ctype_digit($warehouse) ? (int)$warehouse : 0) : null;
$data = dashboard_data($q, $warehouseId);
ui_page($user, $nav, $view, $hints[$view]);
if ($view === 'overview') {
    ui_stats([['已建檔品項', count($data['goods']), '種'], ['在庫批次', count(array_filter($data['inventory'], fn($r) => $r['quantity'] > 0)), '批'], ['進出貨紀錄', count($data['movements']), '筆']]);
    ui_stock_summary($data['inventory']);
} else {
    ui_search($view, $q, $view === 'goods' ? '輸入貨物名稱' : '輸入貨名、批號或儲區', $warehouseId);
}
if ($view === 'goods'): ?>
<section class="panel"><?php ui_section('貨物品項');
if (!$data['goods']): ui_no_records($q === '' ? '目前還沒有建檔的貨物。' : '找不到符合搜尋條件的貨物。'); else: ?>
    <div class="table-scroll" tabindex="0" role="region" aria-label="貨物品項"><table><thead><tr><th scope="col">貨物編號</th><th scope="col">貨物名稱</th><th scope="col">計量單位</th></tr></thead><tbody>
    <?php foreach ($data['goods'] as $item): ?><tr><td class="ref">#<?= h($item['id']) ?></td><td><?= h($item['name']) ?></td><td><?= h($item['unit']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?></section>
<?php endif;
if (in_array($view, ['overview', 'inventory'], true)): ?>
<section class="panel"><?php ui_section('庫存批次', $view === 'overview' ? 'inventory' : null);
ui_inventory($view === 'overview' ? array_slice($data['inventory'], 0, 5) : $data['inventory'], false, $q); ?></section>
<?php endif;
if (in_array($view, ['overview', 'movements'], true)): ?>
<section class="panel"><?php ui_section('進出貨明細', $view === 'overview' ? 'movements' : null);
ui_movements($view === 'overview' ? array_slice($data['movements'], 0, 5) : $data['movements'], false, $q); ?></section>
<?php endif;
ui_end();
