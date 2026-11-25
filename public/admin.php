<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/partials/layout.php';
$user = require_role('admin');
$nav = ['overview' => '管理總覽', 'accounts' => '帳號管理', 'staff' => '人員名冊', 'permissions' => '權限一覽', 'audit' => '管理紀錄'];
$view = isset($nav[ui_text('view')]) ? ui_text('view') : 'overview';
$q = in_array($view, ['accounts', 'staff', 'audit'], true) ? ui_text('q') : '';
$data = admin_data($q);
$options = $q === '' ? $data : admin_data();
$roleNames = ['client' => '客戶', 'employee' => '倉儲員工', 'admin' => '管理員'];
$statusNames = ['pending' => '待啟用', 'active' => '啟用', 'disabled' => '停用'];
$statusColors = ['pending' => 'amber', 'active' => 'green', 'disabled' => 'gray'];
$editId = $view === 'staff' ? ui_text('edit') : '';
$editing = null;
foreach ($options['employees'] as $person) {
    if ((string)$person['id'] === $editId) { $editing = $person; break; }
}
$staffInput = $view === 'staff' ? take_form_input('employee_save') : [];
if ((string)($staffInput['id'] ?? '') !== (string)($editing['id'] ?? '')) $staffInput = [];
$staffValues = array_replace($editing ?? [], $staffInput);
$accountInput = $view === 'accounts' ? take_form_input('account_create') : [];
$accountRole = ($accountInput['role'] ?? '') === 'client' ? 'client' : 'employee';

function admin_ui_accounts(array $accounts, array $roleNames, array $statusNames, array $statusColors, bool $preview = false): void {
    if (!$accounts) { ui_no_records('找不到符合條件的帳號。'); return; } ?>
    <div class="table-scroll" tabindex="0" role="region" aria-label="帳號清單"><table class="account-table">
    <thead><tr><th scope="col">帳號</th><th scope="col">角色</th><th scope="col">所屬員工／客戶</th><th scope="col">狀態</th><th scope="col">操作</th></tr></thead><tbody>
    <?php foreach ($accounts as $account): ?>
        <tr>
            <td><a class="record-title" href="<?= $preview ? '?view=accounts' : '' ?>#account-<?= h($account['id']) ?>"><?= h($account['username']) ?></a></td>
            <td><?= h($roleNames[$account['role']]) ?></td>
            <td><?= h($account['display_name']) ?><small class="record-sub"><?= $account['role'] === 'client' ? '客戶' : '員工' ?> #<?= h($account['client_id'] ?? $account['employee_id']) ?></small></td>
            <td><span class="pill <?= h($statusColors[$account['status']]) ?>"><?= h($statusNames[$account['status']]) ?></span><?php if ($account['reset_requested_at']): ?><small class="record-sub">待重設密碼</small><?php endif; ?><?php if (($account['employment_status'] ?? '') === 'inactive'): ?><small class="record-sub">員工已離職</small><?php endif; ?></td>
            <td><div class="row-actions"><a href="<?= $preview ? '?view=accounts' : '' ?>#account-<?= h($account['id']) ?>">管理</a></div></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
<?php }

function admin_ui_audit(array $entries, bool $preview = false): void {
    if (!$entries) { ui_no_records('尚無管理紀錄。'); return; } ?>
    <div class="table-scroll" tabindex="0" role="region" aria-label="管理紀錄"><table class="audit-table">
    <thead><tr><th scope="col">時間</th><th scope="col">操作者</th><th scope="col">操作</th><th scope="col">對象</th><?php if (!$preview): ?><th scope="col">變更內容</th><?php endif; ?></tr></thead><tbody>
    <?php foreach ($entries as $entry): ?>
        <tr><td class="num"><?= h(substr($entry['created_at'], 0, 16)) ?></td><td><?= h($entry['actor_name'] ?? '主機管理者') ?></td><td><?= h(admin_event_label($entry['event'])) ?></td><td><?= h($entry['target_name'] ?? ($entry['target_employee_id'] ? '員工 #'.$entry['target_employee_id'] : '—')) ?></td><?php if (!$preview): ?><td class="audit-detail"><?= h(admin_audit_summary($entry)) ?></td><?php endif; ?></tr>
    <?php endforeach; ?>
    </tbody></table></div>
<?php }

ui_page($user, $nav, $view, '');
if ($view === 'overview'):
    $counts = array_count_values(array_column($data['accounts'], 'status'));
    ui_stats([['啟用帳號', $counts['active'] ?? 0, '個'], ['待啟用帳號', $counts['pending'] ?? 0, '個'], ['停用帳號', $counts['disabled'] ?? 0, '個']]); ?>
    <section class="panel"><?php ui_section('待啟用與待重設', 'accounts');
        $pending = array_values(array_filter($data['accounts'], static fn($a) => $a['status'] === 'pending' || $a['reset_requested_at'] !== null));
        if ($pending) admin_ui_accounts(array_slice($pending, 0, 6), $roleNames, $statusNames, $statusColors, true);
        else ui_no_records('目前沒有待啟用或待重設的帳號。'); ?></section>
    <section class="panel"><?php ui_section('近期管理紀錄', 'audit'); admin_ui_audit(array_slice($data['audit'], 0, 6), true); ?></section>
<?php endif;
if ($view === 'accounts'): ?>
    <section class="panel"><?php ui_section('帳號清單'); ui_search($view, $q, '輸入帳號、員工或客戶名稱'); admin_ui_accounts($data['accounts'], $roleNames, $statusNames, $statusColors); ?></section>
    <section class="panel" id="account-form" data-drawer <?= $accountInput ? 'data-open' : '' ?>><?php ui_section('新增帳號'); ?>
        <form class="field-grid panel-body" method="post" action="actions.php" id="account-create-form">
            <?php ui_fields('account_create'); ?>
            <label>帳號<input name="username" required maxlength="80" pattern="[A-Za-z0-9_.\-]+" autocomplete="off" autocapitalize="none" spellcheck="false" aria-describedby="username-note" value="<?= h($accountInput['username'] ?? '') ?>"></label>
            <p class="form-note" id="username-note">使用英文字母、數字、底線、連字號或句點。</p>
            <label>角色<select name="role" id="account-role" required><option value="employee" <?= $accountRole === 'employee' ? 'selected' : '' ?>>倉儲員工</option><option value="client" <?= $accountRole === 'client' ? 'selected' : '' ?>>客戶</option></select></label>
            <label data-account-binding="employee">所屬員工<select name="employee_id"><option value="">選擇員工</option>
                <?php foreach ($options['employees'] as $person): if ($person['is_admin'] || $person['employment_status'] !== 'active') continue; ?>
                <option value="<?= h($person['id']) ?>" <?= (string)$person['id'] === (string)($accountInput['employee_id'] ?? '') ? 'selected' : '' ?>><?= h($person['name']) ?>（#<?= h($person['id']) ?>）</option><?php endforeach; ?>
            </select></label>
            <label data-account-binding="client">所屬客戶<select name="client_id"><option value="">選擇客戶</option>
                <?php foreach ($options['clients'] as $client): ?><option value="<?= h($client['id']) ?>" <?= (string)$client['id'] === (string)($accountInput['client_id'] ?? '') ? 'selected' : '' ?>><?= h($client['name']) ?>（#<?= h($client['id']) ?>）</option><?php endforeach; ?>
            </select></label>
            <p class="form-note">建立後為待啟用，由本人設定密碼。角色與所屬員工／客戶建立後固定。</p>
            <div class="submit-row"><button class="button primary" type="submit">建立帳號</button></div>
        </form>
    </section>
    <?php foreach ($data['accounts'] as $account): $managed = $account['role'] !== 'admin'; ?>
    <section class="panel" id="account-<?= h($account['id']) ?>" data-drawer><?php ui_section('帳號明細'); ?>
        <div class="panel-body"><span class="eyebrow"><?= h($roleNames[$account['role']]) ?></span><h3 class="detail-name"><?= h($account['username']) ?></h3>
            <dl class="detail-facts"><div><dt>所屬<?= $account['role'] === 'client' ? '客戶' : '員工' ?></dt><dd><?= h($account['display_name']) ?></dd></div><div><dt>狀態</dt><dd><span class="pill <?= h($statusColors[$account['status']]) ?>"><?= h($statusNames[$account['status']]) ?></span></dd></div>
            <?php if ($account['employee_id']): ?><div><dt>在職狀態</dt><dd><?= $account['employment_status'] === 'active' ? '在職' : '離職' ?></dd></div><?php endif; ?>
            <?php if ($account['reset_requested_at']): ?><div><dt>重設申請</dt><dd><?= h(substr($account['reset_requested_at'], 0, 16)) ?></dd></div><?php endif; ?></dl>
            <p class="account-permission-link"><a href="?view=permissions#role-<?= h($account['role']) ?>">查看角色權限</a></p>
            <?php if (!$managed): ?><p class="form-note">管理員帳號由主機管理者維護。</p>
            <?php elseif ($account['status'] === 'pending'): ?><p class="form-note">等待本人完成密碼設定。</p>
            <?php elseif ($account['employment_status'] === 'inactive'): ?><p class="form-note">員工已離職，無法登入。</p><?php endif; ?>
        </div>
        <?php if ($managed): ?>
        <div class="panel-body operation-block account-operation"><h3>帳號狀態</h3>
            <?php if ($account['status'] !== 'disabled'): ?>
                <form method="post" action="actions.php" class="field-grid"><?php ui_fields('account_status'); ?><input type="hidden" name="id" value="<?= h($account['id']) ?>"><input type="hidden" name="status" value="disabled"><p class="form-note">停用後，既有登入會失效，歷史紀錄保留。</p><div><button type="submit" class="button danger">停用帳號</button></div></form>
            <?php elseif ($account['employment_status'] !== 'inactive'): ?>
                <form method="post" action="actions.php"><?php ui_fields('account_status'); ?><input type="hidden" name="id" value="<?= h($account['id']) ?>"><input type="hidden" name="status" value="active"><button type="submit" class="button">恢復帳號</button></form>
            <?php else: ?><p class="form-note">請先確認員工的在職狀態。</p><?php endif; ?>
        </div>
        <?php if ($account['status'] === 'active' && !$account['reset_requested_at']): ?>
        <div class="panel-body operation-block account-operation"><h3>密碼重設</h3><form method="post" action="actions.php" class="field-grid"><?php ui_fields('account_reset_request'); ?><input type="hidden" name="id" value="<?= h($account['id']) ?>"><p class="form-note">提出申請後，由主機管理者將重設連結交給本人。</p><div><button type="submit" class="button">申請重設密碼</button></div></form></div>
        <?php elseif ($account['reset_requested_at']): ?><div class="panel-body operation-block account-operation"><h3>密碼重設</h3><p class="form-note">已提出重設申請。</p></div><?php endif; endif; ?>
    </section>
    <?php endforeach; ?>
    <script src="assets/accounts.js?v=20261126a" defer></script>
<?php endif;
if ($view === 'staff'): ?>
    <section class="panel"><?php ui_section('人員名冊'); ui_search($view, $q, '輸入員工姓名、職務或主管');
    if (!$data['employees']): ui_no_records($q === '' ? '尚無員工資料。' : '找不到符合條件的員工。'); else: ?>
        <div class="table-scroll" tabindex="0" role="region" aria-label="人員名冊"><table class="staff-table"><thead><tr><th scope="col">員工</th><th scope="col">職務</th><th scope="col">直屬主管</th><th scope="col">在職狀態</th><th scope="col">操作</th></tr></thead><tbody>
        <?php foreach ($data['employees'] as $person): ?><tr><td><a class="record-title" href="?view=staff&amp;edit=<?= h($person['id']) ?>#staff-form"><?= h($person['name']) ?></a><small class="record-sub">員工 #<?= h($person['id']) ?></small></td><td><?= h($person['job_title']) ?></td><td><?= h($person['manager_name'] ?? '無') ?></td><td><span class="pill <?= $person['employment_status'] === 'active' ? 'green' : 'gray' ?>"><?= $person['employment_status'] === 'active' ? '在職' : '離職' ?></span></td><td><div class="row-actions"><a href="?view=staff&amp;edit=<?= h($person['id']) ?>#staff-form"><?= $person['is_admin'] ? '查看' : '編輯' ?></a><?php if (!$person['is_admin']) ui_delete('employee_delete', (int)$person['id'], $person['name']); ?></div></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </section>
    <section class="panel" id="staff-form" data-drawer <?= $staffInput || $editId !== '' ? 'data-open' : '' ?>><?php ui_section($editing && $editing['is_admin'] ? '員工資料' : ($editId !== '' ? '編輯員工資料' : '新增員工'));
    if ($editId !== '' && !$editing): ?>
        <div class="panel-body"><p class="form-note">找不到這位員工。</p><a class="text-link" href="?view=staff">返回人員名冊</a></div>
    <?php elseif ($editing && $editing['is_admin']): ?>
        <div class="panel-body"><h3 class="detail-name"><?= h($editing['name']) ?></h3><dl class="detail-facts"><div><dt>職務</dt><dd><?= h($editing['job_title']) ?></dd></div><div><dt>直屬主管</dt><dd><?= h($editing['manager_name'] ?? '無') ?></dd></div><div><dt>在職狀態</dt><dd><?= $editing['employment_status'] === 'active' ? '在職' : '離職' ?></dd></div><div><dt>月薪</dt><dd><?= $editing['monthly_salary'] === null ? '未設定' : number_format((float)$editing['monthly_salary'], 2).' 元' ?></dd></div></dl><p class="account-permission-link form-note">管理員資料由主機管理者維護。</p></div>
    <?php else: ?>
        <form class="field-grid cols panel-body" method="post" action="actions.php">
            <?php ui_fields('employee_save'); ?><input type="hidden" name="id" value="<?= h($editing['id'] ?? '') ?>">
            <label>員工姓名<input name="name" maxlength="100" required value="<?= h($staffValues['name'] ?? '') ?>"></label>
            <label>職務<input name="job_title" maxlength="80" required value="<?= h($staffValues['job_title'] ?? '') ?>"></label>
            <label>在職狀態<select name="employment_status" required><option value="active" <?= ($staffValues['employment_status'] ?? 'active') === 'active' ? 'selected' : '' ?>>在職</option><option value="inactive" <?= ($staffValues['employment_status'] ?? '') === 'inactive' ? 'selected' : '' ?>>離職</option></select></label>
            <label>直屬主管<select name="manager_id"><option value="">無直屬主管</option><?php foreach ($options['employees'] as $person): if ((string)$person['id'] === (string)($editing['id'] ?? '')) continue; ?><option value="<?= h($person['id']) ?>" <?= (string)$person['id'] === (string)($staffValues['manager_id'] ?? '') ? 'selected' : '' ?>><?= h($person['name']) ?>（#<?= h($person['id']) ?>）</option><?php endforeach; ?></select></label>
            <label>月薪（元，選填）<input type="number" name="monthly_salary" min="0" max="99999999.99" step="0.01" inputmode="decimal" placeholder="未設定" value="<?= h($staffValues['monthly_salary'] ?? '') ?>"></label>
            <label>生日（選填）<input type="date" name="birth_date" min="1000-01-01" max="<?= h((new DateTimeImmutable('today', new DateTimeZone('Asia/Taipei')))->format('Y-m-d')) ?>" value="<?= h($staffValues['birth_date'] ?? '') ?>"></label>
            <label>加班時數<input type="number" name="overtime_hours" required min="0" max="9999.99" step="0.01" inputmode="decimal" value="<?= h($staffValues['overtime_hours'] ?? '0') ?>"></label>
            <label>保險公司（選填）<input name="insurer" maxlength="100" value="<?= h($staffValues['insurer'] ?? '') ?>"></label>
            <p class="form-note">離職後，該員工的帳號無法登入，歷史紀錄保留。</p>
            <div class="submit-row"><button type="submit" class="button primary"><?= $editing ? '儲存變更' : '新增員工' ?></button><?php if ($editing): ?><a href="?view=staff#staff-form">取消編輯</a><?php endif; ?></div>
        </form>
    <?php endif; ?></section>
<?php endif;
if ($view === 'permissions'): ?>
    <section class="panel"><?php ui_section('角色權限'); ?><div class="table-scroll" tabindex="0" role="region" aria-label="角色權限"><table class="permissions-table"><thead><tr><th scope="col">功能</th><th scope="col" id="role-admin">管理員</th><th scope="col" id="role-employee">倉儲員工</th><th scope="col" id="role-client">客戶</th></tr></thead><tbody>
    <tr><th scope="row">一般帳號建立、啟停與重設申請</th><td>可管理</td><td>—</td><td>—</td></tr>
    <tr><th scope="row">員工資料與在職狀態</th><td>可管理一般員工</td><td>—</td><td>—</td></tr>
    <tr><th scope="row">管理紀錄</th><td>可查詢</td><td>—</td><td>—</td></tr>
    <tr><th scope="row">貨物建檔與修改</th><td>—</td><td>全部客戶</td><td>所屬客戶</td></tr>
    <tr><th scope="row">提出或取消收發申請</th><td>—</td><td>—</td><td>所屬客戶</td></tr>
    <tr><th scope="row">確認收發、登記現場收發</th><td>—</td><td>可操作</td><td>—</td></tr>
    <tr><th scope="row">庫存與進出貨紀錄</th><td>—</td><td>全部客戶</td><td>所屬客戶</td></tr>
    </tbody></table></div><div class="panel-body"><p class="form-note">管理員帳號由主機管理者建立。一般帳號的角色與所屬對象建立後固定。</p></div></section>
<?php endif;
if ($view === 'audit'): ?>
    <section class="panel"><?php ui_section('管理紀錄'); ui_search($view, $q, '輸入操作者或對象名稱'); admin_ui_audit($data['audit']); ?></section>
<?php endif;
ui_end();
