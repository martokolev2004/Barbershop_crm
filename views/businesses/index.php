<?php
use BarberLead\Status;
/**
 * @var array<string,mixed> $filters @var ?array<string,mixed> $area @var list<array<string,mixed>> $areaPath
 * @var ?array<string,int> $areaStats @var array{rows:list<array<string,mixed>>,total:int,page:int,pages:int} $result
 */
?>
<div class="page-head">
  <div>
    <?php if ($area): ?>
      <div class="crumbs"><a href="/businesses">Всички</a>
        <?php foreach ($areaPath as $p): if ($p['type'] === 'country') continue; ?> / <a href="<?= e(url('/businesses', ['area' => $p['id']])) ?>"><?= e($p['name_bg']) ?></a><?php endforeach; ?>
      </div>
      <h1><?= e($area['name_bg']) ?></h1>
      <p class="muted small">
        <?= e(['city' => 'Град', 'district' => 'Административен район', 'neighborhood' => 'Квартал', 'country' => 'Държава'][$area['type']] ?? '') ?>
        <?php if ($area['geo_confidence'] === 'approx'): ?> · <span class="geo-approx">границите и принадлежността към район са приблизителни (ще се проверят с OpenStreetMap)</span><?php endif; ?>
      </p>
    <?php else: ?>
      <h1>Бизнеси</h1>
    <?php endif; ?>
  </div>
  <a class="btn btn-k" href="<?= e(url('/businesses/new', ['area' => $area['id'] ?? null])) ?>">+ Добави бизнес</a>
</div>

<?php if ($areaStats !== null): ?>
<div class="stats">
  <div class="stat"><strong><?= $areaStats['total'] ?></strong><span>открити бизнеса</span></div>
  <a class="stat" href="<?= e(biz_list_url($filters, $area, ['status' => 'qualified', 'page' => null])) ?>"><strong><?= $areaStats['qualified'] ?></strong><span>квалифицирани</span></a>
  <a class="stat" href="<?= e(biz_list_url($filters, $area, ['web' => 'none', 'page' => null])) ?>"><strong><?= $areaStats['no_website'] ?></strong><span>без открит сайт</span></a>
  <a class="stat" href="<?= e(biz_list_url($filters, $area, ['web' => 'weak', 'page' => null])) ?>"><strong><?= $areaStats['weak_website'] ?></strong><span>слаб сайт</span></a>
  <a class="stat" href="<?= e(biz_list_url($filters, $area, ['status' => 'offer_sent', 'page' => null])) ?>"><strong><?= $areaStats['offer_sent'] ?></strong><span>пуснати оферти</span></a>
  <a class="stat" href="<?= e(biz_list_url($filters, $area, ['rel' => 'low', 'page' => null])) ?>"><strong><?= $areaStats['low_relevance'] ?></strong><span>неподходящи (скрити)</span></a>
</div>
<div class="scan-panel">
  <section class="card">
    <h2>Сканиране</h2>
    <form method="post" action="/areas/<?= (int) $area['id'] ?>/scan" class="form">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <p class="muted small">Търси бръснарници и фризьори в границите на „<?= e($area['name_bg']) ?>“. Съществуващите записи се обновяват, не се дублират.</p>
      <div class="row">
        <label class="check"><input type="checkbox" checked disabled> OpenStreetMap</label>
        <label class="check"><input type="checkbox" name="google" value="1"<?= $hasGoogleKey ? ' checked' : ' disabled' ?>> Google Places<?= $hasGoogleKey ? '' : ' <span class="muted small">(няма ключ — <a href="/settings#google">Настройки</a>)</span>' ?></label>
      </div>
      <div class="row"><button class="btn btn-k"<?= $activeJob ? ' disabled' : '' ?>>Сканирай квартала</button></div>
    </form>
    <div id="job-progress" class="progress<?= $activeJob ? '' : ' hidden' ?>" data-job="<?= (int) $activeJob ?>">
      <strong id="job-status">Зареждам…</strong>
      <span id="job-counts" class="small muted"></span>
      <div id="job-log" class="pre"></div>
    </div>
    <form method="post" action="/areas/<?= (int) $area['id'] ?>/schedule" class="row small">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <label class="check"><input type="checkbox" name="enabled" value="1"<?= $schedule && $schedule['enabled'] ? ' checked' : '' ?>> Автоматично на всеки</label>
      <input type="number" name="interval_days" min="7" max="365" value="<?= (int) ($schedule['interval_days'] ?? 30) ?>" class="w-num"> дни
      <button class="btn btn-sm">Запиши</button>
      <?php if ($schedule && $schedule['enabled']): ?><span class="muted">следващо: <?= fmt_date($schedule['next_run_at']) ?></span><?php endif; ?>
    </form>
  </section>
  <section class="card">
    <h2>История на сканиранията</h2>
    <?php if (!$scans): ?>
      <p class="muted small">Кварталът още не е сканиран.</p>
    <?php else: ?>
      <table class="list"><tbody>
      <?php foreach ($scans as $j): $src = json_decode((string) $j['params'], true)['sources'] ?? []; ?>
        <tr>
          <td class="nowrap small"><?= fmt_date($j['created_at'], true) ?><span class="sub"><?= e(implode(' + ', array_map(fn ($x) => $x === 'osm' ? 'OSM' : 'Google', $src))) ?></span></td>
          <td><?= job_badge($j['status']) ?><span class="sub"><?= e(duration($j['started_at'], $j['finished_at'])) ?></span></td>
          <td class="small"><?= (int) $j['found_count'] ?> намерени · <?= (int) $j['new_count'] ?> нови · <?= (int) $j['updated_count'] ?> обновени<?= $j['review_count'] ? ' · <a href="/duplicates">' . (int) $j['review_count'] . ' за преглед</a>' : '' ?>
            <?php if ($j['error']): ?><span class="sub field-err"><?= nl2br(e($j['error'])) ?></span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table>
      <p class="muted small">„Завършено“ значи, че всички избрани източници са обработени, не че са намерени всички бизнеси в квартала.</p>
    <?php endif; ?>
  </section>
</div>
<?php endif; ?>

<form class="filters" method="get" action="/businesses" role="search">
  <?php if ($area): ?><input type="hidden" name="area" value="<?= (int) $area['id'] ?>"><?php endif; ?>
  <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Име, адрес, телефон, квартал, Instagram, сайт…" aria-label="Търсене">
  <select name="status" aria-label="Статус" data-autosubmit>
    <option value="">Всички статуси (без архив)</option>
    <?php foreach (Status::LABELS as $k => $l): ?><option value="<?= e($k) ?>"<?= $filters['status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
  </select>
  <select name="web" aria-label="Сайт" data-autosubmit>
    <option value="">Всякакъв сайт</option>
    <?php foreach (Status::WEBSITE as $k => $l): ?><option value="<?= e($k) ?>"<?= $filters['website'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
  </select>
  <select name="rel" aria-label="Подходящи" data-autosubmit>
    <?php foreach (['relevant' => 'Подходящи (без дамски/козметика)', 'high' => 'Само бръснарници / мъжки', 'medium' => 'Само фризьори (неясно)', 'low' => 'Неподходящи', 'all' => 'Всички'] as $k => $l): ?><option value="<?= e($k) ?>"<?= $filters['relevance'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
  </select>
  <button class="btn">Търси</button>
  <?php if ($filters['q'] !== '' || $filters['status'] !== '' || $filters['website'] !== ''): ?><a class="btn" href="<?= e(url('/businesses', ['area' => $area['id'] ?? null])) ?>">Изчисти</a><?php endif; ?>
</form>

<?php if (!$result['rows']): ?>
  <div class="card empty">
    <?php if ($filters['q'] !== '' || $filters['status'] !== '' || $filters['website'] !== ''): ?>
      Няма бизнеси, които отговарят на филтрите.
    <?php else: ?>
      Още няма бизнеси<?= $area ? ' в ' . e($area['name_bg']) : '' ?>. Добави първия ръчно — автоматичното сканиране идва във Фаза 2.
    <?php endif; ?>
  </div>
<?php else: ?>
<div class="table-wrap">
<table class="list responsive">
  <thead><tr>
    <th><?= sort_link($filters, $area, 'name', 'Бизнес') ?></th>
    <th>Квартал</th>
    <th>Сайт</th>
    <th>Статус</th>
    <th><?= sort_link($filters, $area, 'score', 'Оценка') ?></th>
    <th><?= sort_link($filters, $area, 'rating', 'Рейтинг') ?></th>
    <th><?= sort_link($filters, $area, 'updated', 'Обновен') ?></th>
  </tr></thead>
  <tbody>
  <?php foreach ($result['rows'] as $b): $score = $b['score']; ?>
    <tr>
      <td class="name"><a href="/businesses/<?= (int) $b['id'] ?>"><?= e($b['name']) ?></a>
        <span class="sub"><?= relevance_badge($b['relevance']) ?> <?= e($b['address'] ?? '') ?><?= $b['phone'] ? ' · ' . e($b['phone']) : '' ?></span></td>
      <td data-label="Квартал"><?= e($app->areas->label($b['area_id'] !== null ? (int) $b['area_id'] : null)) ?></td>
      <td data-label="Сайт"><?= website_badge($b['website_status']) ?></td>
      <td data-label="Статус"><?= status_badge($b['status']) ?><?php if ($b['last_offer_at']): ?><span class="sub">оферта <?= fmt_date($b['last_offer_at']) ?></span><?php endif; ?></td>
      <td data-label="Оценка"><?= $score !== null ? '<span class="score' . ((int) $score >= 70 ? ' hi' : '') . '">' . (int) $score . '</span>' : '<span class="muted">—</span>' ?></td>
      <td data-label="Рейтинг" class="nowrap"><?= $b['rating'] !== null ? e(number_format((float) $b['rating'], 1, ',', '')) . ' ★' . ($b['reviews_count'] !== null ? ' <span class="muted small">(' . (int) $b['reviews_count'] . ')</span>' : '') : '—' ?></td>
      <td data-label="Обновен" class="nowrap"><?= fmt_date($b['updated_at']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<div class="pager">
  <span class="muted"><?= $result['total'] ?> общо · стр. <?= $result['page'] ?> от <?= $result['pages'] ?></span>
  <?php if ($result['page'] > 1): ?><a class="btn btn-sm" href="<?= e(biz_list_url($filters, $area, ['page' => $result['page'] - 1])) ?>">← Назад</a><?php endif; ?>
  <?php if ($result['page'] < $result['pages']): ?><a class="btn btn-sm" href="<?= e(biz_list_url($filters, $area, ['page' => $result['page'] + 1])) ?>">Напред →</a><?php endif; ?>
</div>
<?php endif; ?>
