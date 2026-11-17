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
        <div class="warehouse-art" aria-hidden="true">
            <div class="rack rack-back"><?php for ($i = 0; $i < 16; $i++): ?><i></i><?php endfor; ?></div>
            <div class="rack rack-front"><?php for ($i = 0; $i < 20; $i++): ?><i></i><?php endfor; ?></div>
            <div class="floor-lines"></div>
        </div>
        <div class="login-caption">公共倉儲<br>管理系統<span class="title-rule"></span></div>
    </section>
    <section class="login-panel">
        <div class="login-box">
            <h1>登入帳號</h1><p class="intro">使用客戶、倉儲員工或人資帳號登入。</p>
            <?php ui_flash(); ?>
            <form class="login-form field-grid" method="post" action="actions.php">
                <?php ui_fields('login'); ?>
                <label>帳號<input name="username" required maxlength="100" autocomplete="username" autocapitalize="none" spellcheck="false" placeholder="輸入帳號"></label>
                <label>密碼<input type="password" name="password" required autocomplete="current-password" placeholder="輸入密碼"></label>
                <button type="submit" class="button primary">登入 <span aria-hidden="true">→</span></button>
            </form>
        </div>
    </section>
</main>
</body></html>
