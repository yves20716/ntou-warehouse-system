<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/partials/layout.php';
$user = current_user();
if ($user) {
    $home = ['client' => 'client.php', 'employee' => 'employee.php', 'hr' => 'hr.php'];
    header('Location: '.$home[$user['role']]);
    exit;
}
ui_head('帳號登入');
?>
<main class="login-page">
    <section class="login-art" aria-label="公共倉儲管理系統">
        <?php ui_brand(); ?>
        <div class="logistics-drawing" aria-hidden="true"><img src="assets/warehouse-plan.svg" alt=""></div>
        <div class="login-caption"><span class="login-eyebrow">NEXUS WAREHOUSE MANAGEMENT</span>貨物有序，<br>收發有據。<span class="title-rule"></span><p>公共倉儲管理系統</p></div>
    </section>
    <section class="login-panel">
        <div class="login-box">
            <h1>登入帳號</h1><p class="intro">查詢庫存、登記收發，從這裡開始。</p>
            <?php ui_flash(); ?>
            <form class="login-form field-grid" method="post" action="actions.php">
                <?php ui_fields('login'); ?>
                <label>帳號<input name="username" required maxlength="80" autocomplete="username" autocapitalize="none" spellcheck="false" placeholder="輸入帳號"></label>
                <div class="password-group"><label for="login-password">密碼</label><span class="password-field"><input id="login-password" type="password" name="password" required autocomplete="current-password" placeholder="輸入密碼"><button class="password-toggle" type="button" aria-controls="login-password" aria-pressed="false">顯示密碼</button></span></div>
                <button type="submit" class="button primary">登入 <span aria-hidden="true">→</span></button>
            </form>
        </div>
    </section>
</main>
</body></html>
