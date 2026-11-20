<?php
use BarberLead\Status;
/**
 * @var array<string,int> $stats @var array<string,int> $byStatus @var list<array<string,mixed>> $due @var list<array<string,mixed>> $recent
 * @var list<array{id:int,name:string,count:int}> $districts @var list<array<string,mixed>> $forOffer @var list<array<string,mixed>> $researching
 * @var list<array<string,mixed>> $newWeek @var string $template @var string $myName
 */
?>
<div class="page-head">
  <div><h1>Днес</h1><p class="muted">Какво чака теб. Числата идват директно от базата.</p></div>
  <a class="btn btn-k" href="/businesses/new">+ Добави бизнес</a>
</div>

<div class="stats">
  <a class="stat" href="/businesses"><strong><?= $stats['barbershops'] ?></strong><span>бръснарници</span></a>
  <a class="stat" href="<?= e(url('/businesses', ['web' => 'social'])) ?>"><strong><?= $stats['social_only'] ?></strong><span>само Instagram/FB</span></a>
  <a class="stat" href="<?= e(url('/businesses', ['status' => 'qualified', 'rel' => 'all'])) ?>"><strong><?= $byStatus['qualified'] ?? 0 ?></strong><span>за оферта</span></a>
  <a class="stat" href="<?= e(url('/offers')) ?>"><strong><?= $byStatus['offer_sent'] ?? 0 ?></strong><span>пуснати оферти</span></a>
  <a class="stat" href="<?= e(url('/businesses', ['status' => 'replied', 'rel' => 'all'])) ?>"><strong><?= $byStatus['replied'] ?? 0 ?></strong><span>в разговор</span></a>
  <a class="stat" href="<?= e(url('/businesses', ['status' => 'won', 'rel' => 'all'])) ?>"><strong><?= $byStatus['won'] ?? 0 ?></strong><span>спечелени</span></a>
</div>

<div class="split">
  <div class="cards">
    <section class="card">
      <h2>Да пиша пак днес</h2>
      <?php if (!$due): ?>
        <p class="muted">Никой не чака отговор от теб днес.</p>
      <?php else: ?>
        <table class="list"><tbody>
        <?php foreach ($due as $o): ?>
          <tr>
            <td class="name"><a href="/businesses/<?= (int) $o['business_id'] ?>"><?= e($o['business_name']) ?></a>
              <span class="sub">Оферта от <?= fmt_date($o['sent_at']) ?> · <?= e(Status::CHANNELS[$o['channel']] ?? $o['channel']) ?></span></td>
            <td class="nowrap">до <?= fmt_date($o['follow_up_at']) ?></td>
            <td class="actions">
              <?php if ($u = safe_href($o['instagram_url'] ?? null)): ?><a class="btn btn-sm" href="<?= e($u) ?>" target="_blank" rel="noopener noreferrer">Instagram</a><?php endif; ?>
              <form method="post" action="/offers/<?= (int) $o['id'] ?>/follow-up-done" class="inline"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="back" value="/"><button class="btn btn-sm">Готово</button></form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody></table>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="row row-between"><h2>Готови за оферта</h2><a class="small" href="<?= e(url('/businesses', ['status' => 'qualified', 'rel' => 'all'])) ?>">всички →</a></div>
      <?php if (!$forOffer): ?>
        <p class="muted">Няма. Премести бизнеси от „Проучвам“ с бутона „За оферта“.</p>
      <?php else: ?>
        <table class="list"><tbody>
        <?php foreach ($forOffer as $b): ?>
          <tr>
            <td class="name"><a href="/businesses/<?= (int) $b['id'] ?>"><?= e($b['name']) ?></a> <?= web_kind_badge($b) ?>
              <?= contact_links($b) ?></td>
            <td class="actions"><?= write_button($b, $template, $myName) ?><a class="btn btn-sm" href="/businesses/<?= (int) $b['id'] ?>#offer">Пусната оферта</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="row row-between"><h2>Проучвам</h2><a class="small" href="<?= e(url('/businesses', ['status' => 'researching'])) ?>">всички →</a></div>
      <?php if (!$researching): ?>
        <p class="muted">Нищо не проучваш в момента.</p>
      <?php else: ?>
        <table class="list"><tbody>
        <?php foreach ($researching as $b): ?>
          <tr>
            <td class="name"><a href="/businesses/<?= (int) $b['id'] ?>"><?= e($b['name']) ?></a> <?= web_kind_badge($b) ?>
              <span class="sub"><?= e($app->areas->label($b['area_id'] !== null ? (int) $b['area_id'] : null)) ?><?= $b['reviews_count'] !== null ? ' · ' . (int) $b['reviews_count'] . ' отзива' : '' ?></span></td>
            <td class="actions"><?= status_button((int) $b['id'], 'qualified', 'За оферта', $csrf) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table>
      <?php endif; ?>
    </section>
  </div>

  <div class="cards">
    <section class="card">
      <h2>Нови бръснарници тази седмица</h2>
      <?php if (!$newWeek): ?>
        <p class="muted">Сканиранията не са намерили нови през последните 7 дни.</p>
      <?php else: ?>
        <table class="list"><tbody>
        <?php foreach ($newWeek as $b): ?>
          <tr>
            <td class="name"><a href="/businesses/<?= (int) $b['id'] ?>"><?= e($b['name']) ?></a>
              <span class="sub"><?= e($app->areas->label($b['area_id'] !== null ? (int) $b['area_id'] : null)) ?><?= $b['reviews_count'] !== null ? ' · ' . (int) $b['reviews_count'] . ' отзива' : '' ?></span></td>
            <td><?= web_kind_badge($b) ?></td>
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

    <section class="card">
      <h2>София по райони</h2>
      <table class="list"><tbody>
      <?php foreach ($districts as $d): ?>
        <tr><td><a href="<?= e(url('/businesses', ['area' => $d['id']])) ?>"><?= e($d['name']) ?></a></td><td><?= $d['count'] ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    </section>
  </div>
</div>
