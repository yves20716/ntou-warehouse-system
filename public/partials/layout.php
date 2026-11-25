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
    <meta name="theme-color" content="#152e30">
    <title><?= h($title) ?> ｜ NEXUS WMS</title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="assets/styles.css?v=20261126b">
    <script src="assets/app.js?v=20261126b" defer></script>
    <script src="assets/table-tools.js?v=20261126b" defer></script>
</head>
<body>
<?php }

function ui_brand(): void { ?>
    <div class="brand"><span class="brand-mark" aria-hidden="true"><i></i><i></i><i></i></span><span>NEXUS <b>WMS</b></span></div>
<?php }

function ui_flash(): void {
    $flash = take_flash();
    if ($flash): ?>
    <div role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>" class="notice <?= $flash['type'] === 'error' ? 'error' : 'success' ?>"><span class="notice-mark" aria-hidden="true"><?= $flash['type'] === 'error' ? '!' : '✓' ?></span><span><?= h($flash['message']) ?></span></div>
    <?php endif;
}

function ui_fields(string $action): void { ?>
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="<?= h($action) ?>">
    <?php if (in_array($action, ['goods_save', 'goods_delete', 'stock_in', 'stock_out', 'employee_save', 'employee_delete'], true)): ?>
        <input type="hidden" name="mutation_token" value="<?= h(mutation_token($action)) ?>">
    <?php endif; ?>
<?php }

function ui_page(array $user, array $nav, string $view, string $subtitle): void {
    $titles = ['employee' => '倉儲作業', 'client' => '客戶專區', 'hr' => '人資管理'];
    $role = $titles[$user['role']];
    $title = $nav[$view];
    $icons = ['overview' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>', 'goods' => '<path d="m3 7 9-4 9 4v10l-9 4-9-4V7Zm0 0 9 4 9-4M12 11v10M7 5l9 4"/>', 'inventory' => '<path d="M3 10 12 3l9 7v11H3V10Zm4 11V10h10v11M7 14h10M7 18h10"/>', 'movements' => '<path d="M3 7h18m-5-5 5 5-5 5M21 17H3l5-5m-5 5 5 5"/>', 'staff' => '<circle cx="9" cy="7" r="4"/><path d="M2 21v-3a7 7 0 0 1 14 0v3M16 3a4 4 0 0 1 0 8m3 3a6 6 0 0 1 3 5v2"/>'];
    $today = new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei'));
    ui_head($title); ?>
<a class="skip-link" href="#main">跳至主要內容</a>
<div class="shell">
    <aside class="side">
        <?php ui_brand(); ?>
        <span class="nav-caption"><?= h($role) ?></span>
        <nav aria-label="<?= h($role) ?>導覽">
            <?php foreach ($nav as $key => $label): ?>
            <a href="?view=<?= h($key) ?>" <?= $key === $view ? 'aria-current="page"' : '' ?>><svg class="nav-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"><?= $icons[$key] ?></svg><?= h($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <form class="logout" method="post" action="actions.php">
            <?php ui_fields('logout'); ?><button type="submit">登出帳號 <span aria-hidden="true">↗</span></button>
        </form>
    </aside>
    <div class="workspace">
        <header class="topbar"><span>公共倉儲管理系統 <span class="crumb">／ <?= h($role) ?></span></span><div class="account"><time datetime="<?= h($today->format('Y-m-d')) ?>"><?= h($today->format('Y.m.d')) ?></time><span class="signed-in"><span class="avatar" aria-hidden="true"><?= h($user['role'] === 'client' ? '客' : ($user['role'] === 'hr' ? '人' : '倉')) ?></span><span><?= h($user['display_name']) ?><small><?= h($role) ?></small></span></span></div></header>
        <main id="main" class="main" data-view="<?= h($view) ?>">
            <header class="page-heading"><div><span class="page-kicker">NEXUS / <?= h($role) ?></span><h1><?= h($title) ?></h1><p><?= h($subtitle) ?></p></div>
                <div class="heading-actions">
                <?php if ($user['role'] === 'employee'): ?>
                    <?php if ($view === 'goods'): ?><a class="button primary" href="?view=goods#goods-form">＋ 新增貨物</a>
                    <?php else: ?><a class="button" href="<?= $view === 'inventory' ? '#stock-out-form' : '?view=inventory#stock-out-form' ?>">登記出庫 <span aria-hidden="true">↗</span></a><a class="button primary" href="<?= $view === 'inventory' ? '#stock-in-form' : '?view=inventory#stock-in-form' ?>">登記入庫 <span aria-hidden="true">↙</span></a><?php endif; ?>
                <?php elseif ($user['role'] === 'hr'): ?><a class="button primary" href="?view=staff#staff-form">＋ 新增員工</a>
                <?php elseif ($view !== 'movements'): ?><a class="button" href="?view=movements">查看進出貨 <span aria-hidden="true">↗</span></a><?php endif; ?>
                </div>
            </header>
            <?php ui_flash();
}

function ui_end(): void { ?>
        </main>
    </div>
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
    if (!$rows) { ui_no_records($q === '' ? '目前還沒有入庫的貨物。' : '找不到符合搜尋條件的庫存批次。'); return; } ?>
    <div class="table-scroll" tabindex="0" role="region" aria-label="庫存批次"><table>
    <thead><tr><th scope="col">批號</th><th scope="col">貨物名稱</th><?php if ($showClient): ?><th scope="col">客戶</th><?php endif; ?><th scope="col">倉庫／儲區</th><th scope="col">庫存量</th><th scope="col">到倉日</th><th scope="col">狀態</th><?php if ($showClient): ?><th scope="col">操作</th><?php endif; ?></tr></thead>
    <tbody><?php foreach ($rows as $row): ?><tr>
        <td class="ref">#<?= h($row['id']) ?></td><td><?= h($row['goods_name']) ?></td>
        <?php if ($showClient): ?><td><?= h($row['client_name']) ?></td><?php endif; ?>
        <td><?= h($row['warehouse_name']) ?>／<?= h($row['section_name']) ?></td><td class="num"><?= number_format((int)$row['quantity']) ?> <?= h($row['unit']) ?></td>
        <td class="num"><?= h(substr($row['arrival_date'], 0, 10)) ?></td><td><?= $row['status'] === 'depleted' ? '<span class="pill gray">已出清</span>' : '<span class="pill green">在庫</span>' ?></td>
        <?php if ($showClient): ?><td><?php if ((int)$row['quantity'] > 0): ?><a class="batch-action" aria-label="從批次 #<?= h($row['id']) ?> 出庫" href="?view=inventory&amp;out=<?= h($row['id']) ?>#stock-out-form">出庫 <span aria-hidden="true">↗</span></a><?php else: ?><span class="muted">—</span><?php endif; ?></td><?php endif; ?>
    </tr><?php endforeach; ?></tbody></table></div>
<?php }

function ui_movements(array $rows, bool $showClient, string $q = ''): void {
    if (!$rows) { ui_no_records($q === '' ? '目前還沒有進出貨紀錄。' : '找不到符合搜尋條件的進出貨紀錄。'); return; } ?>
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
