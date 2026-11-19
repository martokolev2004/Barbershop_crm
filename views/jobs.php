<?php
/** @var list<array<string,mixed>> $jobs @var ?string $workerSeen @var int $googleUsage @var int $googleCap @var bool $hasGoogleKey */
$workerAge = $workerSeen ? time() - (int) strtotime($workerSeen) : null;
?>
<div class="page-head"><div><h1>Сканирания</h1><p class="muted">Всички задачи за търсене на бизнеси.</p></div></div>
<div class="stats">
  <div class="stat"><strong><?= $workerAge === null ? 'никога' : ($workerAge < 900 ? 'работи' : 'спрял') ?></strong>
    <span>фонов процес (cron)<?= $workerSeen ? ' · последно ' . fmt_date($workerSeen, true) : '' ?></span></div>
  <div class="stat"><strong><?= $hasGoogleKey ? $googleUsage . ' / ' . $googleCap : 'изключен' ?></strong><span>Google заявки този месец</span></div>
</div>
<?php if ($workerAge === null || $workerAge >= 900): ?>
  <div class="notice small">Фоновият процес не е отчитал скоро. Сканиранията продължават, докато страницата на квартала е отворена; иначе изчакват следващото пускане на cron.</div><p></p>
<?php endif; ?>
<?php if (!$jobs): ?>
  <div class="card empty">Още няма сканирания. Избери квартал отляво и натисни „Сканирай квартала“.</div>
<?php else: ?>
<div class="table-wrap"><table class="list responsive">
  <thead><tr><th>Квартал</th><th>Започнато</th><th>Статус</th><th>Резултат</th><th>Продължителност</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($jobs as $j): ?>
    <tr>
      <td class="name"><a href="<?= e(url('/businesses', ['area' => $j['area_id']])) ?>"><?= e($j['area_name'] ?? '—') ?></a><span class="sub"><?= e($j['requested_by'] ?? '') ?></span></td>
      <td data-label="Започнато" class="nowrap"><?= fmt_date($j['created_at'], true) ?></td>
      <td data-label="Статус"><?= job_badge($j['status']) ?><span class="sub"><?= e($j['progress'] ?? '') ?></span>
        <?php if ($j['run_after'] && in_array($j['status'], ['queued', 'running'], true)): ?><span class="sub">нов опит след <?= fmt_date($j['run_after'], true) ?></span><?php endif; ?></td>
      <td data-label="Резултат" class="small"><?= (int) $j['found_count'] ?> намерени · <?= (int) $j['new_count'] ?> нови · <?= (int) $j['updated_count'] ?> обновени · <?= (int) $j['review_count'] ?> за преглед
        <?php if ($j['error']): ?><span class="sub field-err"><?= nl2br(e($j['error'])) ?></span><?php endif; ?>
        <?php if ($j['log']): ?><span class="sub"><?= nl2br(e($j['log'])) ?></span><?php endif; ?></td>
      <td data-label="Продължителност" class="nowrap"><?= e(duration($j['started_at'], $j['finished_at'])) ?></td>
      <td><?php if (in_array($j['status'], ['queued', 'running'], true)): ?>
        <form method="post" action="/jobs/<?= (int) $j['id'] ?>/cancel" data-confirm="Да спра ли сканирането?"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="btn btn-sm">Спри</button></form>
      <?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
