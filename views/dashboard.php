<?php
use BarberLead\Status;
/** @var array<string,int> $stats @var array<string,int> $byStatus @var list<array<string,mixed>> $due @var list<array<string,mixed>> $recent @var list<array{id:int,name:string,count:int}> $districts */
?>
<div class="page-head">
  <div><h1>Табло</h1><p class="muted">Всички числа идват директно от базата.</p></div>
  <a class="btn btn-k" href="/businesses/new">+ Добави бизнес</a>
</div>

<div class="stats">
  <a class="stat" href="/businesses"><strong><?= $stats['total'] ?></strong><span>подходящи бизнеса в базата</span></a>
  <a class="stat" href="<?= e(url('/businesses', ['sort' => 'created'])) ?>"><strong><?= $stats['new_recent'] ?></strong><span>нови за последните 15 дни</span></a>
  <a class="stat" href="<?= e(url('/businesses', ['web' => 'none'])) ?>"><strong><?= $stats['no_website'] ?></strong><span>без открит сайт</span></a>
  <a class="stat" href="<?= e(url('/businesses', ['web' => 'weak'])) ?>"><strong><?= $stats['weak_website'] ?></strong><span>със слаб сайт</span></a>
  <a class="stat" href="<?= e(url('/businesses', ['status' => 'qualified'])) ?>"><strong><?= $stats['qualified'] ?></strong><span>квалифицирани / с демо</span></a>
  <a class="stat" href="<?= e(url('/businesses', ['status' => 'offer_sent'])) ?>"><strong><?= $stats['offer_sent'] ?></strong><span>пуснати оферти</span></a>
  <a class="stat" href="<?= e(url('/businesses', ['status' => 'won'])) ?>"><strong><?= $stats['won'] ?></strong><span>спечелени</span></a>
</div>

<div class="split">
  <div class="cards">
    <section class="card">
      <h2>Напомняния за днес</h2>
      <?php if (!$due): ?>
        <p class="muted">Няма оферти, на които да се обадиш отново днес.</p>
      <?php else: ?>
        <table class="list"><tbody>
        <?php foreach ($due as $o): ?>
          <tr>
            <td class="name"><a href="/businesses/<?= (int) $o['business_id'] ?>"><?= e($o['business_name']) ?></a>
              <span class="sub">Оферта от <?= fmt_date($o['sent_at']) ?> · <?= e(Status::CHANNELS[$o['channel']] ?? $o['channel']) ?></span></td>
            <td class="nowrap">до <?= fmt_date($o['follow_up_at']) ?></td>
            <td><form method="post" action="/offers/<?= (int) $o['id'] ?>/follow-up-done"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="back" value="/"><button class="btn btn-sm">Готово</button></form></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>Последни действия</h2>
      <?php if (!$recent): ?>
        <p class="muted">Още няма действия.</p>
      <?php else: ?>
        <ul class="timeline">
        <?php foreach ($recent as $ev): ?>
          <li><time><?= fmt_date($ev['created_at'], true) ?></time>
            <div><a href="/businesses/<?= (int) $ev['business_id'] ?>"><strong><?= e($ev['business_name']) ?></strong></a> · <?= e($ev['message']) ?></div></li>
        <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  </div>

  <div class="cards">
    <section class="card">
      <h2>Фуния</h2>
      <table class="list"><tbody>
      <?php foreach (Status::LABELS as $key => $label): if ($key === 'archived') continue; ?>
        <tr><td><a href="<?= e(url('/businesses', ['status' => $key])) ?>"><?= e($label) ?></a></td><td class="nowrap"><strong><?= $byStatus[$key] ?? 0 ?></strong></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    </section>

    <section class="card">
      <h2>София по райони</h2>
      <table class="list"><tbody>
      <?php foreach ($districts as $d): ?>
        <tr><td><a href="<?= e(url('/businesses', ['area' => $d['id']])) ?>"><?= e($d['name']) ?></a></td><td><?= $d['count'] ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    </section>

    <section class="card">
      <h2>Автоматично търсене</h2>
      <p class="muted small">Районите на София се сканират автоматично (настройва се от страницата на града). Резултатите и грешките са в <a href="/jobs">Сканирания</a>.</p>
    </section>
  </div>
</div>
