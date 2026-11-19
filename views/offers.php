<?php
use BarberLead\Status;
/** @var string $filter @var list<array<string,mixed>> $offers */
$tabs = ['all' => 'Всички', 'due' => 'Напомняния за днес', 'open' => 'Без отметнато напомняне'];
?>
<div class="page-head"><div><h1>Оферти</h1><p class="muted">Офертите се пращат от теб. Тук се пази кога, как и какво.</p></div></div>
<div class="filters">
  <?php foreach ($tabs as $k => $l): ?><a class="btn btn-sm<?= $filter === $k ? ' btn-k' : '' ?>" href="<?= e(url('/offers', ['f' => $k === 'all' ? null : $k])) ?>"><?= e($l) ?></a><?php endforeach; ?>
</div>
<?php if (!$offers): ?>
  <div class="card empty">Няма оферти<?= $filter === 'due' ? ' за напомняне днес' : '' ?>.</div>
<?php else: ?>
<div class="table-wrap"><table class="list responsive">
  <thead><tr><th>Бизнес</th><th>Пусната</th><th>Канал</th><th>Сума</th><th>Демо</th><th>Напомняне</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($offers as $o): $due = $o['follow_up_at'] && !$o['follow_up_done_at'] && $o['follow_up_at'] <= date('Y-m-d'); ?>
    <tr>
      <td class="name"><a href="/businesses/<?= (int) $o['business_id'] ?>"><?= e($o['business_name']) ?></a><span class="sub"><?= status_badge($o['business_status']) ?></span></td>
      <td data-label="Пусната" class="nowrap"><?= fmt_date($o['sent_at'], true) ?></td>
      <td data-label="Канал"><?= e(Status::CHANNELS[$o['channel']] ?? $o['channel']) ?></td>
      <td data-label="Сума" class="nowrap"><?= $o['amount_eur'] !== null ? e(number_format((float) $o['amount_eur'], 2, ',', ' ')) . ' €' : '—' ?></td>
      <td data-label="Демо"><?php $h = safe_href($o['demo_url']); echo $h ? '<a href="' . e($h) . '" target="_blank" rel="noopener noreferrer">отвори</a>' : '—'; ?></td>
      <td data-label="Напомняне" class="nowrap"><?php if (!$o['follow_up_at']): ?>—<?php elseif ($o['follow_up_done_at']): ?><span class="muted">✓ <?= fmt_date($o['follow_up_at']) ?></span><?php else: ?><strong class="<?= $due ? 'field-err' : '' ?>"><?= fmt_date($o['follow_up_at']) ?></strong><?php endif; ?></td>
      <td><?php if ($o['follow_up_at'] && !$o['follow_up_done_at']): ?>
        <form method="post" action="/offers/<?= (int) $o['id'] ?>/follow-up-done"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="back" value="<?= e(url('/offers', ['f' => $filter === 'all' ? null : $filter])) ?>"><button class="btn btn-sm">Готово</button></form>
      <?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
