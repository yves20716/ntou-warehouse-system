<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/partials/layout.php';
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
$token = take_password_setup_token();
$user = current_user();
ui_head('設定密碼');
?>
<main class="login-page">
    <section class="login-box" aria-labelledby="setup-title">
        <?php ui_brand(); ?>
        <div class="login-card">
            <h1 id="setup-title">設定密碼</h1><p class="intro">公共倉儲管理系統</p>
            <?php ui_flash(); ?>
            <?php if ($user): ?>
                <p class="form-note">請先登出目前帳號，再開啟密碼設定連結。</p>
                <form class="field-grid account-setup-actions" method="post" action="actions.php"><?php ui_fields('logout'); ?><button type="submit" class="button">登出</button></form>
            <?php else: ?>
                <form class="login-form field-grid" method="post" action="actions.php" id="password-setup-form">
                    <?php ui_fields('password_activate'); ?><input type="hidden" name="token" id="setup-token" value="<?= h($token) ?>" autocomplete="off">
                    <div class="password-group"><label for="setup-password">新密碼</label><span class="password-field"><input id="setup-password" type="password" name="password" required minlength="12" maxlength="72" autocomplete="new-password" aria-describedby="password-note"><button class="password-toggle" type="button" aria-controls="setup-password" aria-pressed="false">顯示密碼</button></span></div>
                    <label>再次輸入密碼<input type="password" name="password_confirmation" id="setup-confirmation" required minlength="12" maxlength="72" autocomplete="new-password"></label>
                    <p class="form-note" id="password-note">至少 12 個字元，建議混用英文、數字與符號。</p>
                    <p class="form-note" id="setup-token-note" role="status" hidden>請使用主機管理者提供的有效連結。</p>
                    <button type="submit" class="button primary" id="setup-submit">設定密碼</button>
                    <noscript><p class="form-note">請開啟瀏覽器的 JavaScript，再重新開啟密碼設定連結。</p></noscript>
                </form>
                <p class="account-permission-link"><a href="index.php">返回登入</a></p>
            <?php endif; ?>
        </div>
    </section>
</main>
<script src="assets/activate.js?v=20261126b" defer></script>
</body></html>
