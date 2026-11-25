<?php
declare(strict_types=1);

function ui_text(string $key): string {
    $value = $_GET[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function ui_head(string $title): void { ?>
<!doctype html>
<html lang="zh-Hant-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#1c2b45">
    <title><?= h($title) ?> ｜ NEXUS WMS</title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="assets/styles.css?v=20261126d">
    <link rel="stylesheet" href="assets/workspace.css?v=20261126e">
    <script src="assets/app.js?v=20261126b" defer></script>
    <script src="assets/table-tools.js?v=20261126e" defer></script>
    <script src="assets/workbench.js?v=20261126c" defer></script>
</head>
<body>
<?php }

function ui_brand(): void { ?>
    <div class="brand"><svg class="brand-mark" aria-hidden="true" viewBox="0 0 32 32" fill="none"><path d="M4 10 16 3l12 7v13l-12 7-12-7V10Z" stroke="currentColor" stroke-width="2"/><path d="m4 10 12 7 12-7M16 17v13M10 6.5l12 7V20" stroke="currentColor" stroke-width="2"/></svg><span>NEXUS <b>WMS</b></span></div>
<?php }

function ui_nav_icon(string $key): void {
    $paths = [
        'overview' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'goods' => '<path d="m3 7 9-5 9 5v10l-9 5-9-5V7Zm0 0 9 5 9-5M12 12v10M7.5 4.5l9 5V15"/>',
        'inventory' => '<path d="M3 21V7l9-5 9 5v14M1 21h22M7 21V11h10v10M7 16h10"/>',
        'movements' => '<path d="M3 7h17m-4-4 4 4-4 4M21 17H4m4-4-4 4 4 4"/>',
        'requests' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V2h6v2M9 10h6M9 15h6"/>',
        'accounts' => '<rect x="3" y="5" width="18" height="15" rx="2"/><circle cx="8" cy="11" r="2"/><path d="M5 17v-1a3 3 0 0 1 6 0v1M14 10h4M14 14h4"/>',
        'permissions' => '<path d="m12 2 9 4v6c0 5-9 10-9 10S3 17 3 12V6l9-4Z"/><path d="m8 12 3 3 5-6"/>',
        'audit' => '<path d="M9 3h11v18H4V8l5-5Zm0 0v5H4M8 12h8M8 16h8"/>',
        'staff' => '<circle cx="9" cy="7" r="4"/><path d="M2 21v-2a7 7 0 0 1 14 0v2M17 3a4 4 0 0 1 0 8M22 21v-2a7 7 0 0 0-4-6"/>',
    ];
    echo '<svg class="nav-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'.($paths[$key] ?? $paths['overview']).'</svg>';
}

function ui_flash(): void {
    $flash = take_flash();
    if ($flash): ?>
    <div role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>" class="notice <?= $flash['type'] === 'error' ? 'error' : 'success' ?>"><span class="notice-mark" aria-hidden="true"><?= $flash['type'] === 'error' ? '!' : '✓' ?></span><span><?= h($flash['message']) ?></span></div>
    <?php endif;
}

function ui_fields(string $action): void {
    // One token per action in this response. A successful POST redirects and renders fresh tokens.
    static $pageTokens = [];
    ?>
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="<?= h($action) ?>">
    <?php if (in_array($action, ['goods_save', 'goods_delete', 'stock_in', 'stock_out', 'employee_save', 'employee_delete', 'client_goods_save', 'client_request_in', 'client_request_out', 'client_request_cancel', 'request_complete', 'request_reject', 'account_create', 'account_status', 'account_reset_request'], true)): ?>
        <input type="hidden" name="mutation_token" value="<?= h($pageTokens[$action] ??= mutation_token($action)) ?>">
    <?php endif; ?>
<?php }

function ui_page(array $user, array $nav, string $view, string $subtitle): void {
    $role = ['employee' => '倉儲作業', 'client' => '客戶專區', 'admin' => '帳號與人員管理'][$user['role']];
    $title = $nav[$view];
    ui_head($title); ?>
<a class="skip-link" href="#main">跳至主要內容</a>
<div class="shell" data-role="<?= h($user['role']) ?>">
    <header class="app-bar">
        <a class="brand-home" href="?view=overview" aria-label="回到<?= h($role) ?>首頁"><?php ui_brand(); ?></a>
        <span class="app-role"><?= h($role) ?></span>
        <div class="account"><span class="account-name"><?= h($user['display_name']) ?></span><form method="post" action="actions.php"><?php ui_fields('logout'); ?><button class="logout-button" type="submit">登出</button></form></div>
    </header>
    <nav class="workspace-nav" aria-label="<?= h($role) ?>導覽"><?php foreach ($nav as $key => $label): ?>
        <a href="?view=<?= h($key) ?>" <?= $key === $view ? 'aria-current="page"' : '' ?>><?php ui_nav_icon($key); ?><span><?= h($label) ?></span></a>
    <?php endforeach; ?></nav>
    <main id="main" class="main" data-view="<?= h($view) ?>">
        <header class="page-heading"><div><h1><?= h($title) ?></h1><?php if ($subtitle !== ''): ?><p><?= h($subtitle) ?></p><?php endif; ?></div>
        <div class="heading-actions">
        <?php if ($user['role'] === 'employee'): ?>
            <?php if ($view === 'goods'): ?><a class="button primary" href="?view=goods#goods-form">新增貨物</a>
            <?php elseif ($view !== 'requests'): ?><a class="button" href="<?= $view === 'inventory' ? '#stock-out-form' : '?view=inventory#stock-out-form' ?>">登記出庫</a><a class="button primary" href="<?= $view === 'inventory' ? '#stock-in-form' : '?view=inventory#stock-in-form' ?>">登記入庫</a><?php endif; ?>
        <?php elseif ($user['role'] === 'admin'): ?>
            <?php if ($view === 'staff'): ?><a class="button primary" href="?view=staff#staff-form">新增員工</a>
            <?php elseif (in_array($view, ['overview', 'accounts'], true)): ?><a class="button primary" href="?view=accounts#account-form">新增帳號</a><?php endif; ?>
        <?php elseif ($view === 'goods'): ?><a class="button primary" href="?view=goods#client-goods-form">建立品項</a>
        <?php else: ?><a class="button" href="?view=inventory">選貨出庫</a><a class="button primary" href="?view=requests#request-in-form">申請入庫</a><?php endif; ?>
        </div></header>
        <?php ui_flash();
}

function ui_end(): void { ?>
    </main>
</div>
</body>
</html>
<?php }

function ui_stats(array $stats): void { ?>
    <div class="stats">
    <?php foreach ($stats as [$label, $count, $unit]): ?>
        <div class="metric"><div><?= h($label) ?></div><strong><?= number_format($count) ?></strong> <small><?= h($unit) ?></small></div>
    <?php endforeach; ?>
    </div>
<?php }

function ui_search(string $view, string $q, string $hint, ?int $warehouseId = null): void { ?>
    <?php if ($warehouseId !== null): ?><p class="filter-note">已按倉庫篩選。<a href="?view=<?= h($view) ?>">查看全部倉庫</a></p><?php endif; ?>
    <form class="search" method="get" role="search">
        <input type="hidden" name="view" value="<?= h($view) ?>">
        <?php if ($warehouseId !== null): ?><input type="hidden" name="warehouse" value="<?= $warehouseId ?>"><?php endif; ?>
        <label class="search-field"><span class="sr-only"><?= h($hint) ?></span><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="10" cy="10" r="6"/><path d="m15 15 5 5"/></svg><input name="q" type="search" maxlength="100" placeholder="<?= h($hint) ?>" value="<?= h($q) ?>"><kbd aria-hidden="true">/</kbd></label>
        <button type="submit" class="button outline">搜尋</button>
        <?php if ($q !== ''): ?><a class="quiet-link" href="?view=<?= h($view) ?><?= $warehouseId !== null ? '&amp;warehouse=' . $warehouseId : '' ?>">清除搜尋</a><?php endif; ?>
    </form>
<?php }

function ui_section(string $title, ?string $more = null): void { ?>
    <div class="panel-head"><h2><?= h($title) ?></h2><?php if ($more !== null): ?><a class="text-link" href="?view=<?= h($more) ?>" aria-label="查看全部<?= h($title) ?>">查看全部 <span aria-hidden="true">→</span></a><?php endif; ?></div>
<?php }

function ui_no_records(string $message): void { ?>
    <p class="empty"><?= h($message) ?></p>
<?php }

function ui_inventory(array $rows, bool $showClient, string $q = ''): void {
    if (!$rows) { ui_no_records($q === '' ? '尚無入庫批次。' : '找不到符合條件的批次。'); return; }
    $onInventory = ui_text('view') === 'inventory'; ?>
    <div class="table-scroll" tabindex="0" role="region" aria-label="庫存批次"><table class="inventory-table">
    <thead><tr><th scope="col">貨物／批次</th><th scope="col">存放位置</th><th scope="col">剩餘數量</th><th scope="col">到倉日</th><th scope="col">操作</th></tr></thead>
    <tbody><?php foreach ($rows as $row): ?><tr>
        <td data-label="貨物"><a class="record-title" href="#lot-<?= h($row['id']) ?>"><?= h($row['goods_name']) ?></a><small class="record-sub">批次 #<?= h($row['id']) ?><?= $showClient ? ' · '.h($row['client_name']) : '' ?></small></td>
        <td data-label="存放位置"><?= h($row['warehouse_name']) ?><small class="record-sub"><?= h($row['section_name']) ?></small></td>
        <td data-label="剩餘數量"><strong class="balance"><?= number_format((int)$row['quantity']) ?></strong> <?= h($row['unit']) ?><small class="record-sub"><?= $row['status'] === 'depleted' ? '<span class="pill gray">已出清</span>' : '<span class="pill green">在庫</span>' ?></small></td>
        <td data-label="到倉日" class="num"><?= h(substr($row['arrival_date'], 0, 10)) ?></td>
        <td data-label="操作"><div class="row-actions"><a href="#lot-<?= h($row['id']) ?>">明細</a>
        <?php if ((int)$row['quantity'] > 0): ?>
            <?php if ($showClient): ?><a class="batch-action" data-stock-id="<?= h($row['id']) ?>" aria-label="從批次 #<?= h($row['id']) ?> 出庫" href="<?= $onInventory ? '#stock-out-form' : '?view=inventory&amp;out='.h($row['id']).'#stock-out-form' ?>">出庫 →</a>
            <?php else: ?><a class="batch-action" aria-label="申請批次 #<?= h($row['id']) ?> 出庫" href="?view=requests&amp;out=<?= h($row['id']) ?>#request-out-form">申請出庫 →</a><?php endif; ?>
        <?php endif; ?></div></td>
    </tr><?php endforeach; ?></tbody></table></div>
<?php }

function ui_movements(array $rows, bool $showClient, string $q = ''): void {
    if (!$rows) { ui_no_records($q === '' ? '尚無進出貨紀錄。' : '找不到符合搜尋條件的進出貨紀錄。'); return; } ?>
    <div class="table-scroll" tabindex="0" role="region" aria-label="進出貨明細"><table><thead><tr>
    <th scope="col">時間</th><th scope="col">作業</th><th scope="col">貨物／批號</th><?php if ($showClient): ?><th scope="col">客戶</th><?php endif; ?><th scope="col">倉庫／儲區</th><th scope="col">數量</th><?php if ($showClient): ?><th scope="col">承辦員工</th><?php endif; ?>
    </tr></thead><tbody>
    <?php foreach ($rows as $row): ?><tr>
    <td class="num"><?= h(substr($row['moved_at'], 0, 16)) ?></td><td><?= $row['type'] === 'in' ? '<span class="pill green">入庫</span>' : '<span class="pill amber">出庫</span>' ?></td>
    <td><?= h($row['goods_name']) ?> <span class="muted">#<?= h($row['stock_id']) ?></span></td>
    <?php if ($showClient): ?><td><?= h($row['client_name']) ?></td><?php endif; ?>
    <td><?= h($row['warehouse_name']) ?>／<?= h($row['section_name']) ?></td><td class="num"><?= number_format((int)$row['quantity']) ?> <?= h($row['unit']) ?></td>
    <?php if ($showClient): ?><td><?= h($row['employee_name']) ?></td><?php endif; ?></tr>
    <?php endforeach; ?></tbody></table></div>
<?php }

function ui_delete(string $action, int $id, string $name): void { ?>
    <details class="delete-confirm"><summary>刪除</summary><form method="post" action="actions.php">
        <?php ui_fields($action); ?><input type="hidden" name="id" value="<?= $id ?>">
        <p>確定刪除「<?= h($name) ?>」？</p><button class="button danger" type="submit">確認刪除</button>
    </form></details>
<?php }

function ui_stock_summary(array $inventory): void
{
    $warehouses = [];
    foreach ($inventory as $lot) {
        if ((int)$lot['quantity'] === 0) continue;
        $id = (int)$lot['warehouse_id'];
        if (!isset($warehouses[$id])) {
            $warehouses[$id] = ['name' => (string)$lot['warehouse_name'], 'units' => [], 'sections' => [], 'lots' => 0];
        }
        $warehouse = &$warehouses[$id];
        $warehouse['units'][$lot['unit']] = ($warehouse['units'][$lot['unit']] ?? 0) + (int)$lot['quantity'];
        $warehouse['sections'][$lot['section_id']] = $lot['section_name'];
        $warehouse['lots']++;
        unset($warehouse);
    }
    if (!$warehouses) return;
    uasort($warehouses, static fn($a, $b) => strcmp($a['name'], $b['name']));
    ?>
    <section class="stock-distribution" aria-label="各倉庫庫存分布">
        <div class="distribution-heading"><div><span class="section-index">01 / LOCATIONS</span><h2>倉庫與儲區</h2></div><p>選擇倉庫，查看存放的批次。<br><span class="distribution-total"><?= count($warehouses) ?> 座倉庫有在庫貨物</span></p></div>
        <div class="warehouse-list">
        <?php foreach ($warehouses as $id => $warehouse): ?>
            <a class="warehouse-card" href="?view=inventory&amp;warehouse=<?= $id ?>">
                <div class="warehouse-title"><span class="warehouse-code">WH / <?= str_pad((string)$id, 2, '0', STR_PAD_LEFT) ?></span><span class="warehouse-arrow" aria-hidden="true">↗</span></div>
                <div class="warehouse-body"><div><h3><?= h($warehouse['name']) ?></h3>
                <div class="warehouse-quantity"><?php foreach ($warehouse['units'] as $unit => $quantity): ?><span><strong><?= number_format($quantity) ?></strong> <?= h($unit) ?></span><?php endforeach; ?></div>
                </div><svg class="warehouse-glyph" aria-hidden="true" viewBox="0 0 140 105" fill="none" stroke="currentColor" stroke-width="1.3"><path d="m12 43 55-28 61 28-56 31-60-31Zm0 0v33l60 29 56-29V43M72 74v31"/><path d="m29 36 59 29M47 26l60 29M28 52v25l16 8V60M92 66v24l18-9V56"/><path d="m28 62 16 8m-16 2 16 8m48-4 18-9m-18 19 18-9"/></svg></div>
                <div class="warehouse-sections"><?php foreach ($warehouse['sections'] as $section): ?><span><?= h($section) ?></span><?php endforeach; ?></div>
                <div class="warehouse-detail"><span><?= count($warehouse['sections']) ?> 個儲區</span><span><?= $warehouse['lots'] ?> 批在庫</span></div>
            </a>
        <?php endforeach; ?>
        </div>
    </section>
    <?php
}
