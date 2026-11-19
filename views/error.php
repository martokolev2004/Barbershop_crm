<?php /** @var string $message @var int $code */ ?>
<div class="card">
  <h1><?= (int) $code === 404 ? 'Не е намерено' : 'Грешка' ?></h1>
  <p class="muted"><?= e($message) ?></p>
  <p><a class="btn" href="/">Към таблото</a></p>
</div>
