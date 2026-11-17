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
    <meta name="theme-color" content="#112b43">
    <title><?= h($title) ?> ｜ NEXUS WMS</title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<?php }

function ui_brand(): void { ?>
    <div class="brand"><span class="brand-mark" aria-hidden="true"><i></i><i></i><i></i></span><span>NEXUS <b>WMS</b></span></div>
<?php }

function ui_flash(): void {
    $flash = take_flash();
    if ($flash): ?>
    <div role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>" class="notice <?= $flash['type'] === 'error' ? 'error' : 'success' ?>"><?= h($flash['message']) ?></div>
    <?php endif;
}

function ui_fields(string $action): void { ?>
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="<?= h($action) ?>">
<?php }

function ui_page(array $user, array $nav, string $view, string $subtitle): void {
    $titles = ['employee' => '倉儲作業', 'client' => '客戶專區', 'hr' => '人資管理'];
    $role = $titles[$user['role']];
    $title = $nav[$view];
    $icons = ['overview' => '▦', 'goods' => '◇', 'inventory' => '▤', 'movements' => '⇄', 'staff' => '◎'];
    ui_head($title); ?>
<a class="skip-link" href="#main">跳至主要內容</a>
<div class="shell">
    <aside class="side">
        <?php ui_brand(); ?>
        <span class="nav-caption"><?= h($role) ?></span>
        <nav aria-label="<?= h($role) ?>導覽">
            <?php foreach ($nav as $key => $label): ?>
            <a href="?view=<?= h($key) ?>" <?= $key === $view ? 'aria-current="page"' : '' ?>><span class="nav-icon" aria-hidden="true"><?= $icons[$key] ?></span><?= h($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <form class="logout" method="post" action="actions.php">
            <?php ui_fields('logout'); ?><button type="submit">登出帳號 <span aria-hidden="true">↗</span></button>
        </form>
    </aside>
    <div class="workspace">
        <header class="topbar"><span>公共倉儲管理系統 <span class="crumb">／ <?= h($role) ?></span></span><span class="signed-in"><i aria-hidden="true"></i><?= h($user['display_name']) ?></span></header>
        <main id="main" class="main">
            <header class="page-heading"><div><h1><?= h($title) ?></h1><p><?= h($subtitle) ?></p></div></header>
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

function ui_search(string $view, string $q, string $hint): void { ?>
    <form class="search" method="get" role="search">
        <input type="hidden" name="view" value="<?= h($view) ?>">
        <label><span class="sr-only">搜尋條件</span><input name="q" type="search" maxlength="100" placeholder="<?= h($hint) ?>" value="<?= h($q) ?>"></label>
        <button type="submit" class="button outline">搜尋</button>
        <?php if ($q !== ''): ?><a class="quiet-link" href="?view=<?= h($view) ?>">清除</a><?php endif; ?>
    </form>
<?php }

function ui_section(string $title, ?string $more = null): void { ?>
    <div class="panel-head"><h2><?= h($title) ?></h2><?php if ($more !== null): ?><a class="text-link" href="?view=<?= h($more) ?>">查看全部 <span aria-hidden="true">→</span></a><?php endif; ?></div>
<?php }

function ui_no_records(string $message): void { ?>
    <p class="empty"><?= h($message) ?></p>
<?php }

function ui_inventory(array $rows, bool $showClient): void {
    if (!$rows) { ui_no_records('目前沒有符合條件的庫存批次。'); return; } ?>
    <div class="table-scroll" tabindex="0" role="region" aria-label="庫存批次"><table>
    <thead><tr><th>批號</th><th>貨物名稱</th><?php if ($showClient): ?><th>客戶</th><?php endif; ?><th>倉庫／儲區</th><th>庫存量</th><th>到倉日</th><th>狀態</th></tr></thead>
    <tbody><?php foreach ($rows as $row): ?><tr>
        <td class="ref">#<?= h($row['id']) ?></td><td><?= h($row['goods_name']) ?></td>
        <?php if ($showClient): ?><td><?= h($row['client_name']) ?></td><?php endif; ?>
        <td><?= h($row['warehouse_name']) ?>／<?= h($row['section_name']) ?></td><td class="num"><?= number_format((int)$row['quantity']) ?> <?= h($row['unit']) ?></td>
        <td class="num"><?= h(substr($row['arrival_date'], 0, 10)) ?></td><td><?= $row['status'] === 'depleted' ? '<span class="pill gray">已出清</span>' : '<span class="pill green">在庫</span>' ?></td>
    </tr><?php endforeach; ?></tbody></table></div>
<?php }

function ui_movements(array $rows, bool $showClient): void {
    if (!$rows) { ui_no_records('目前沒有符合條件的進出貨紀錄。'); return; } ?>
    <div class="table-scroll" tabindex="0" role="region" aria-label="進出貨明細"><table><thead><tr>
    <th>時間</th><th>作業</th><th>貨物／批號</th><?php if ($showClient): ?><th>客戶</th><?php endif; ?><th>倉庫／儲區</th><th>數量</th><?php if ($showClient): ?><th>承辦員工</th><?php endif; ?>
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
