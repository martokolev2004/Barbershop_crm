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
      <h1><?= ['rejected' => 'Отхвърлени', 'researching' => 'Проучвам'][$filters['status']] ?? 'Бизнеси' ?></h1>
      <?php if ($filters['status'] === 'researching'): ?><p class="muted small">Бизнесите, които проучваш. Не се показват в общия списък, докато са тук. Като решиш: „Квалифициран“ (продължава напред) или „Отхвърли“.</p><?php endif; ?>
      <?php if ($filters['status'] === 'rejected'): ?><p class="muted small">Тези бизнеси не се показват в списъка, Kanban и статистиките. Повторните сканирания не ги връщат.</p><?php endif; ?>
    <?php endif; ?>
  </div>
  <a class="btn btn-k" href="<?= e(url('/businesses/new', ['area' => $area['id'] ?? null])) ?>">+ Добави бизнес</a>
</div>

<?php if ($areaStats !== null): ?>
<div class="stats">
  <a class="stat" href="<?= e(biz_list_url($filters, $area, ['rel' => 'high', 'page' => null])) ?>"><strong><?= $areaStats['barbershops'] ?></strong><span>бръснарници</span></a>
  <a class="stat" href="<?= e(biz_list_url($filters, $area, ['rel' => 'medium', 'page' => null])) ?>"><strong><?= $areaStats['salons'] ?></strong><span>фризьорски салони</span></a>
  <a class="stat" href="<?= e(biz_list_url($filters, $area, ['status' => 'qualified', 'page' => null])) ?>"><strong><?= $areaStats['qualified'] ?></strong><span>квалифицирани</span></a>
  <a class="stat" href="<?= e(biz_list_url($filters, $area, ['web' => 'none', 'page' => null])) ?>"><strong><?= $areaStats['no_website'] ?></strong><span>без открит сайт</span></a>
  <a class="stat" href="<?= e(biz_list_url($filters, $area, ['web' => 'weak', 'page' => null])) ?>"><strong><?= $areaStats['weak_website'] ?></strong><span>слаб сайт</span></a>
  <a class="stat" href="<?= e(biz_list_url($filters, $area, ['status' => 'offer_sent', 'page' => null])) ?>"><strong><?= $areaStats['offer_sent'] ?></strong><span>пуснати оферти</span></a>
  <a class="stat" href="<?= e(biz_list_url($filters, $area, ['rel' => 'low', 'page' => null])) ?>"><strong><?= $areaStats['low_relevance'] ?></strong><span>неподходящи (скрити)</span></a>
</div>
<?php if ($cityPlan !== null): ?>
<section class="card">
  <div class="row row-between"><h2>Автоматично сканиране на целия град</h2>
    <span class="small muted"><?= $cityPlan['enabled'] ?> от <?= count($cityPlan['districts']) ?> района са включени</span></div>
  <p class="muted small">Всеки район се сканира с всичките си квартали; новите бизнеси се добавят, а съществуващите се обновяват. Районите се пускат един след друг, за да не се претоварват източниците.</p>
  <form method="post" action="/areas/<?= (int) $area['id'] ?>/schedule-all" class="row small">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <label class="check"><input type="checkbox" name="enabled" value="1"<?= $cityPlan['enabled'] ? ' checked' : '' ?>> Сканирай всички райони на всеки</label>
    <input type="number" name="interval_days" min="7" max="365" value="<?= (int) $cityPlan['interval'] ?>" class="w-num"> дни
    <button class="btn btn-sm">Запиши</button>
  </form>
  <?php if ($cityPlan['districts']): ?>
  <p class="small">Граници: <strong><?= (int) $cityPlan['withGeometry'] ?> от <?= count($cityPlan['districts']) ?></strong> района. Районите без граници си ги търсят сами при сканиране (OpenStreetMap, после Google).</p>
  <table class="list"><thead><tr><th>Район</th><th>Граници</th><th>Последно сканиране</th><th>Следващо</th></tr></thead><tbody>
    <?php foreach ($cityPlan['districts'] as $d): ?>
      <tr>
        <td><a href="<?= e(url('/businesses', ['area' => $d['id']])) ?>"><?= e($d['name_bg']) ?></a></td>
        <td class="small nowrap"><?= (int) $d['has_geometry'] !== 1 ? '<span class="field-err">няма</span>' : (str_starts_with((string) $d['geometry_source'], 'osm:') ? '✓ точни (OSM)' : '≈ приблизителни (Google)') ?></td>
        <td class="small"><?= $d['last_status'] ? job_badge($d['last_status']) . ' ' . fmt_date($d['last_finished'], true) . ' · ' . (int) $d['last_found'] . ' намерени' : '<span class="muted">още не</span>' ?></td>
        <td class="small nowrap"><?= (int) $d['enabled'] === 1 ? fmt_date($d['next_run_at'], true) : '<span class="muted">изключено</span>' ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</section>
<p></p>
<?php else: ?>
<div class="scan-panel">
  <section class="card">
    <h2>Сканиране</h2>
    <form method="post" action="/areas/<?= (int) $area['id'] ?>/scan" class="form">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <p class="muted small">Търси бръснарници и фризьори в границите на „<?= e($area['name_bg']) ?>“. Съществуващите записи се обновяват, не се дублират.</p>
      <div class="row">
        <label class="check"><input type="checkbox" name="osm" value="1"<?= $hasGoogleKey ? '' : ' checked' ?>> OpenStreetMap <span class="muted small">(безплатно, но без телефони)</span></label>
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
<?php endif; ?>

<div class="tabs" role="tablist">
  <?php foreach (['high' => 'Бръснарници', 'medium' => 'Салони', 'low' => 'Неподходящи', 'all' => 'Всички'] as $k => $l): ?>
    <a role="tab" class="tab<?= $filters['relevance'] === $k ? ' on' : '' ?>" href="<?= e(biz_list_url($filters, $area, ['rel' => $k, 'page' => null])) ?>"><?= e($l) ?> <span><?= (int) ($tabCounts[$k] ?? 0) ?></span></a>
  <?php endforeach; ?>
</div>
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
  <input type="hidden" name="rel" value="<?= e($filters['relevance']) ?>">
  <button class="btn">Търси</button>
  <?php if ($filters['q'] !== '' || $filters['status'] !== '' || $filters['website'] !== ''): ?><a class="btn" href="<?= e(url('/businesses', ['area' => $area['id'] ?? null])) ?>">Изчисти</a><?php endif; ?>
</form>

<?php if (!$result['rows']): ?>
  <div class="card empty">
    <?php if ($filters['q'] !== '' || $filters['status'] !== '' || $filters['website'] !== ''): ?>
      Няма бизнеси, които отговарят на филтрите.
    <?php else: ?>
      Още няма бизнеси<?= $area ? ' в ' . e($area['name_bg']) : '' ?>.<?= $area ? ' Пусни „Сканирай квартала“ или добави бизнес ръчно.' : '' ?>
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
    <th></th>
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
      <td class="actions">
        <?php if ($b['status'] === 'rejected'): ?>
          <form method="post" action="/businesses/<?= (int) $b['id'] ?>/restore"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? '/businesses') ?>"><button class="btn btn-sm">Върни</button></form>
        <?php else: ?>
          <?php if ($b['relevance'] === 'high'): ?><?= relevance_button((int) $b['id'], 'medium', '→ Салон', $csrf) ?>
          <?php else: ?><?= relevance_button((int) $b['id'], 'high', '→ Бръснарница', $csrf) ?><?php endif; ?>
          <?php if ($b['status'] === 'researching'): ?>
            <?= status_button((int) $b['id'], 'qualified', 'Квалифициран', $csrf) ?>
          <?php elseif (in_array($b['status'], ['new', 'researched'], true)): ?>
            <?= status_button((int) $b['id'], 'researching', 'Проучвам', $csrf) ?>
          <?php endif; ?>
          <form method="post" action="/businesses/<?= (int) $b['id'] ?>/reject" class="reject-form inline"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? '/businesses') ?>"><input type="hidden" name="reason" value=""><button class="btn btn-sm btn-reject" title="Премести в Отхвърлени">Отхвърли</button></form>
        <?php endif; ?>
      </td>
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
