<?php /** @var ?string $error @var string $next @var string $username @var string $csrf */ ?>
<div class="auth-page">
  <main class="auth-box">
    <div class="brand-lg"><b>BARBER</b>LEAD</div>
    <?php if ($error): ?><div class="flash err" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="/login" class="form">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <label>Потребителско име<input name="username" value="<?= e($username) ?>" required autocomplete="username" autocapitalize="off" autofocus></label>
      <label>Парола<input name="password" type="password" required autocomplete="current-password"></label>
      <button class="btn btn-k">Вход</button>
    </form>
  </main>
</div>
