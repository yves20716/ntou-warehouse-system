<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/partials/layout.php';
$user = require_role('client');
$nav = ['overview' => '客戶總覽', 'goods' => '我的貨物', 'inventory' => '我的庫存', 'movements' => '進出貨紀錄'];
$view = ui_text('view');
$view = isset($nav[$view]) ? $view : 'overview';
$hints = ['overview' => '查看您寄存的貨物與最近的進出貨作業。', 'goods' => '查看登記在您名下的貨物品項與計量單位。', 'inventory' => '依批號查看寄存貨物的儲位、到倉日與剩餘數量。', 'movements' => '依作業時間查看每批貨物的入庫與出庫紀錄。'];
$q = $view === 'overview' ? '' : ui_text('q');
$data = dashboard_data($q);
ui_page($user, $nav, $view, $hints[$view]);
if ($view === 'overview') {
    ui_stats([['已建檔品項', count($data['goods']), '種'], ['在庫批次', count(array_filter($data['inventory'], fn($r) => $r['quantity'] > 0)), '批'], ['進出貨紀錄', count($data['movements']), '筆']]);
} else {
    ui_search($view, $q, $view === 'goods' ? '輸入貨物名稱' : '輸入貨物或儲區名稱');
}
if ($view === 'goods'): ?>
<section class="panel"><?php ui_section('貨物品項');
if (!$data['goods']): ui_no_records('目前沒有符合條件的品項。'); else: ?>
    <div class="table-scroll" tabindex="0" role="region" aria-label="貨物品項"><table><thead><tr><th>貨物編號</th><th>貨物名稱</th><th>計量單位</th></tr></thead><tbody>
    <?php foreach ($data['goods'] as $item): ?><tr><td class="ref">#<?= h($item['id']) ?></td><td><?= h($item['name']) ?></td><td><?= h($item['unit']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?></section>
<?php endif;
if (in_array($view, ['overview', 'inventory'], true)): ?>
<section class="panel"><?php ui_section('庫存批次', $view === 'overview' ? 'inventory' : null);
ui_inventory($view === 'overview' ? array_slice($data['inventory'], 0, 5) : $data['inventory'], false); ?></section>
<?php endif;
if (in_array($view, ['overview', 'movements'], true)): ?>
<section class="panel"><?php ui_section('進出貨明細', $view === 'overview' ? 'movements' : null);
ui_movements($view === 'overview' ? array_slice($data['movements'], 0, 5) : $data['movements'], false); ?></section>
<?php endif;
ui_end();
