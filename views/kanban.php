<?php
use BarberLead\Status;
/** @var array<string,array{statuses:list<string>,cards:list<array<string,mixed>>}> $columns */
?>
<div class="page-head"><div><h1>Kanban</h1><p class="muted">Влачи картите между колоните. На телефон смени статуса от падащото меню.</p></div></div>
<div class="kanban">
<?php foreach ($columns as $title => $col): ?>
  <section class="col" data-statuses="<?= e(implode(' ', $col['statuses'])) ?>" data-target="<?= e($col['statuses'][0]) ?>">
    <h3><?= e($title) ?> <span class="cnt"><?= count($col['cards']) ?></span></h3>
    <div class="cards">
      <?php foreach ($col['cards'] as $c): ?>
        <article class="kcard" draggable="true" data-id="<?= (int) $c['id'] ?>" data-status="<?= e($c['status']) ?>">
          <a href="/businesses/<?= (int) $c['id'] ?>"><?= e($c['name']) ?></a>
          <div class="meta"><span><?= e($c['area_label']) ?></span><?php if ($c['score'] !== null): ?><span class="score"><?= (int) $c['score'] ?></span><?php endif; ?></div>
          <select aria-label="Статус">
            <?php foreach (Status::options($c['status']) as $k => $l): ?><option value="<?= e($k) ?>"<?= $c['status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
          </select>
        </article>
      <?php endforeach; ?>
    </div>
  </section>
<?php endforeach; ?>
</div>
