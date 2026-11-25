<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/partials/layout.php';
$user = require_role('hr');
$nav = ['overview' => '人資總覽', 'staff' => '員工管理'];
$view = ui_text('view') === 'staff' ? 'staff' : 'overview';
$q = $view === 'overview' ? '' : ui_text('q');
$data = dashboard_data($q);
$employees = $data['employees'];
$managerOptions = $view === 'staff' && $q !== '' ? dashboard_data()['employees'] : $employees;
$editId = ui_text('edit');
$editing = null;
if ($view === 'staff' && $editId !== '') {
    foreach ($managerOptions as $person) {
        if ((string)$person['id'] === $editId) { $editing = $person; break; }
    }
}
$staffInput = $view === 'staff' ? take_form_input('employee_save') : [];
if ((string)($staffInput['id'] ?? '') !== (string)($editing['id'] ?? '')) {
    $staffInput = [];
}
$staffValues = array_replace($editing ?? [], $staffInput);
ui_page($user, $nav, $view, '');
if ($view === 'overview') {
    $managerIds = array_unique(array_filter(array_column($employees, 'manager_id')));
    ui_stats([['員工人數', count($employees), '人'], ['職務種類', count(array_unique(array_column($employees, 'job_title'))), '種'], ['主管人數', count($managerIds), '人']]);
}
?>
<section class="panel"><?php ui_section('員工名冊', $view === 'overview' ? 'staff' : null);
if ($view === 'staff') ui_search($view, $q, '輸入員工姓名或職務');
if (!$employees): ui_no_records($q === '' ? '尚無員工資料。' : '找不到符合條件的員工。'); else: ?>
<div class="table-scroll" tabindex="0" role="region" aria-label="員工名冊"><table>
    <thead><tr><th scope="col">員工編號</th><th scope="col">姓名</th><th scope="col">職務</th><th scope="col">時薪</th><th scope="col">直屬主管</th><th scope="col">操作</th></tr></thead>
    <tbody><?php foreach (($view === 'overview' ? array_slice($employees, 0, 6) : $employees) as $person): ?><tr>
        <td class="ref">#<?= h($person['id']) ?></td><td><?= h($person['name']) ?></td><td><?= h($person['job_title']) ?></td><td class="num"><?= number_format((float)$person['hourly_rate'], 2) ?> 元</td><td><?= h($person['manager_name'] ?? '無') ?></td>
        <td><div class="row-actions"><a href="?view=staff&amp;edit=<?= h($person['id']) ?>#staff-form">編輯</a><?php if ($view === 'staff') ui_delete('employee_delete', (int)$person['id'], $person['name']); ?></div></td>
    </tr><?php endforeach; ?></tbody>
</table></div><?php endif; ?></section>
<?php if ($view === 'staff'): ?>
    <section class="panel" id="staff-form" data-drawer <?= $staffInput || $editId !== '' ? 'data-open' : '' ?>><?php ui_section($editId !== '' ? '編輯員工資料' : '新增員工');
        if ($editId !== '' && !$editing): ?>
        <div class="panel-body"><p class="form-note">找不到這位員工。</p><a class="text-link" href="?view=staff">返回員工管理</a></div>
        <?php else: ?>
        <form class="field-grid cols panel-body" method="post" action="actions.php">
            <?php ui_fields('employee_save'); ?><input type="hidden" name="id" value="<?= h($editing['id'] ?? '') ?>">
            <label>員工姓名<input name="name" maxlength="100" required value="<?= h($staffValues['name'] ?? '') ?>"></label>
            <label>職務<input name="job_title" maxlength="80" required value="<?= h($staffValues['job_title'] ?? '') ?>"></label>
            <label>時薪（元）<input type="number" name="hourly_rate" min="0" max="999999.99" step="0.01" inputmode="decimal" required value="<?= h($staffValues['hourly_rate'] ?? '') ?>"></label>
            <label>直屬主管<select name="manager_id"><option value="">無直屬主管</option>
                <?php foreach ($managerOptions as $person): if ((string)$person['id'] === (string)($editing['id'] ?? '')) continue; ?>
                    <option value="<?= h($person['id']) ?>" <?= (string)$person['id'] === (string)($staffValues['manager_id'] ?? '') ? 'selected' : '' ?>><?= h($person['name']) ?>（#<?= h($person['id']) ?>）</option>
                <?php endforeach; ?>
            </select></label>
            <label>生日（選填）<input type="date" name="birth_date" min="1000-01-01" max="<?= h((new DateTimeImmutable('today', new DateTimeZone('Asia/Taipei')))->format('Y-m-d')) ?>" value="<?= h($staffValues['birth_date'] ?? '') ?>"></label>
            <label>加班時數<input type="number" name="overtime_hours" required min="0" max="9999.99" step="0.01" inputmode="decimal" value="<?= h($staffValues['overtime_hours'] ?? '0') ?>"></label>
            <label>保險公司（選填）<input name="insurer" maxlength="100" value="<?= h($staffValues['insurer'] ?? '') ?>"></label>
            <div class="submit-row"><button type="submit" class="button primary"><?= $editing ? '儲存變更' : '新增員工' ?></button><?php if ($editing): ?><a href="?view=staff#staff-form">取消編輯</a><?php endif; ?></div>
        </form>
        <?php endif; ?>
    </section>
<?php endif;
ui_end();
