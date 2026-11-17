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
$editId = ui_text('edit');
$editing = null;
if ($view === 'staff' && $editId !== '') {
    foreach ($employees as $person) {
        if ((string)$person['id'] === $editId) { $editing = $person; break; }
    }
}
ui_page($user, $nav, $view, $view === 'overview' ? '查看人力配置與員工名冊。' : '查詢員工資料，維護職務、時薪與直屬主管。');
if ($view === 'overview') {
    $managerIds = array_unique(array_filter(array_column($employees, 'manager_id')));
    ui_stats([['員工人數', count($employees), '人'], ['職務種類', count(array_unique(array_column($employees, 'job_title'))), '種'], ['帶領部屬的主管', count($managerIds), '人']]);
}
?>
<section class="panel"><?php ui_section('員工名冊', $view === 'overview' ? 'staff' : null);
if ($view === 'staff') ui_search($view, $q, '輸入員工姓名或職務');
if (!$employees): ui_no_records('目前沒有符合條件的員工。'); else: ?>
<div class="table-scroll" tabindex="0" role="region" aria-label="員工名冊"><table>
    <thead><tr><th>員工編號</th><th>姓名</th><th>職務</th><th>時薪</th><th>直屬主管</th><th>操作</th></tr></thead>
    <tbody><?php foreach (($view === 'overview' ? array_slice($employees, 0, 6) : $employees) as $person): ?><tr>
        <td class="ref">#<?= h($person['id']) ?></td><td><?= h($person['name']) ?></td><td><?= h($person['job_title']) ?></td><td class="num"><?= number_format((float)$person['hourly_rate'], 2) ?> 元</td><td><?= h($person['manager_name'] ?? '無') ?></td>
        <td><div class="row-actions"><a href="?view=staff&amp;edit=<?= h($person['id']) ?>#staff-form">編輯</a><?php if ($view === 'staff') ui_delete('employee_delete', (int)$person['id'], $person['name']); ?></div></td>
    </tr><?php endforeach; ?></tbody>
</table></div><?php endif; ?></section>
<?php if ($view === 'staff'):
    $managerOptions = $q === '' ? $employees : dashboard_data()['employees']; ?>
    <section class="panel" id="staff-form"><?php ui_section($editing ? '編輯員工資料' : '新增員工'); ?>
        <form class="field-grid cols panel-body" method="post" action="actions.php">
            <?php ui_fields('employee_save'); ?><input type="hidden" name="id" value="<?= h($editing['id'] ?? '') ?>">
            <label>員工姓名<input name="name" maxlength="100" required value="<?= h($editing['name'] ?? '') ?>"></label>
            <label>職務<input name="job_title" maxlength="80" required value="<?= h($editing['job_title'] ?? '') ?>"></label>
            <label>時薪（元）<input type="number" name="hourly_rate" min="0" max="999999.99" step="0.01" required value="<?= h($editing['hourly_rate'] ?? '') ?>"></label>
            <label>直屬主管<select name="manager_id"><option value="">無直屬主管</option>
                <?php foreach ($managerOptions as $person): if ((string)$person['id'] === (string)($editing['id'] ?? '')) continue; ?>
                    <option value="<?= h($person['id']) ?>" <?= (string)$person['id'] === (string)($editing['manager_id'] ?? '') ? 'selected' : '' ?>><?= h($person['name']) ?>（#<?= h($person['id']) ?>）</option>
                <?php endforeach; ?>
            </select></label>
            <label>生日（選填）<input type="date" name="birth_date" min="1000-01-01" max="<?= h((new DateTimeImmutable('today', new DateTimeZone('Asia/Taipei')))->format('Y-m-d')) ?>" value="<?= h($editing['birth_date'] ?? '') ?>"></label>
            <label>加班時數<input type="number" name="overtime_hours" required min="0" max="9999.99" step="0.01" value="<?= h($editing['overtime_hours'] ?? '0') ?>"></label>
            <label>保險公司（選填）<input name="insurer" maxlength="100" value="<?= h($editing['insurer'] ?? '') ?>"></label>
            <div class="submit-row"><button type="submit" class="button primary"><?= $editing ? '儲存變更' : '新增員工' ?></button><?php if ($editing): ?><a href="?view=staff#staff-form">取消編輯</a><?php endif; ?></div>
        </form>
    </section>
<?php endif;
ui_end();
