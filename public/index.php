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
    <section class="login-box" aria-labelledby="login-title">
        <?php ui_brand(); ?>
        <div class="login-card">
            <h1 id="login-title">登入</h1><p class="intro">公共倉儲管理系統</p>
            <?php ui_flash(); ?>
            <form class="login-form field-grid" method="post" action="actions.php">
                <?php ui_fields('login'); ?>
                <label>帳號<input name="username" required maxlength="80" autocomplete="username" autocapitalize="none" spellcheck="false" placeholder="輸入帳號"></label>
                <div class="password-group"><label for="login-password">密碼</label><span class="password-field"><input id="login-password" type="password" name="password" required autocomplete="current-password" placeholder="輸入密碼"><button class="password-toggle" type="button" aria-controls="login-password" aria-pressed="false">顯示密碼</button></span></div>
                <button type="submit" class="button primary">登入</button>
            </form>
        </div>
    </section>
</main>
</body></html>
