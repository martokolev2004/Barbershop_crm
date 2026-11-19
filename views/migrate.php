<?php /** @var list<string> $pending */ ?>
<div class="card">
  <h1>Нужно е обновяване на базата</h1>
  <p class="muted">Качена е нова версия на CRM-а. Преди да продължиш, базата трябва да се обнови. Първо се прави автоматичен бекъп.</p>
  <ul class="small"><?php foreach ($pending as $v): ?><li><?= e($v) ?></li><?php endforeach; ?></ul>
  <form method="post" action="/settings/migrate" class="row">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <button class="btn btn-k">Обнови базата</button>
  </form>
</div>
