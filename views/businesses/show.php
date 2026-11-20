<?php
use BarberLead\Repositories\DemoRepository;
use BarberLead\Status;
/**
 * @var array<string,mixed> $b @var string $areaLabel @var list<array<string,mixed>> $events @var list<array<string,mixed>> $offers
 * @var list<array<string,mixed>> $demos @var ?string $demoUrl @var string $outreach @var int $followUpDays @var list<array<string,mixed>> $duplicates
 */
$id = (int) $b['id'];
$score = $b['lead_score_override'] ?? $b['lead_score'];
?>
<div class="page-head">
  <div>
    <div class="crumbs"><a href="/businesses">Бизнеси</a><?php if ($b['area_id']): ?> / <a href="<?= e(url('/businesses', ['area' => $b['area_id']])) ?>"><?= e($areaLabel) ?></a><?php endif; ?></div>
    <h1><?= e($b['name']) ?></h1>
    <div class="row small"><?= status_badge($b['status']) ?> <?= web_kind_badge($b) ?> <?= relevance_badge($b['relevance']) ?>
      <?php if ($score !== null): ?><span class="score<?= (int) $score >= 70 ? ' hi' : '' ?>" title="Оценка на лийда"><?= (int) $score ?></span><?php endif; ?></div>
  </div>
  <div class="row">
    <a class="btn btn-offer" href="#offer">Пусната оферта</a>
    <a class="btn" href="/businesses/<?= $id ?>/edit">Редактирай</a>
    <?php if (in_array($b['status'], ['new', 'researched'], true)): ?><?= status_button($id, 'researching', 'Проучвам', $csrf, 'btn') ?><?php endif; ?>
    <?php if ($b['status'] === 'researching'): ?><?= status_button($id, 'qualified', 'За оферта', $csrf, 'btn') ?><?php endif; ?>
    <?php if ($b['status'] === 'rejected'): ?>
      <form method="post" action="/businesses/<?= $id ?>/restore" class="inline"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="btn">Върни от Отхвърлени</button></form>
    <?php else: ?>
      <form method="post" action="/businesses/<?= $id ?>/reject" class="inline reject-form"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="reason" value=""><button class="btn btn-reject">Отхвърли</button></form>
    <?php endif; ?>
  </div>
</div>

<?php if ($duplicates): ?>
  <div class="notice">Възможен дубликат (същото име, телефон или домейн):
    <?php foreach ($duplicates as $i => $d): ?><?= $i ? ', ' : '' ?><a href="/businesses/<?= (int) $d['id'] ?>"><?= e($d['name']) ?></a><?php endforeach; ?>.
    Сливането на записи идва с Фаза 2.</div><p></p>
<?php endif; ?>

<div class="split">
  <div class="cards">
    <section class="card">
      <h2>Данни</h2>
      <dl class="kv">
        <dt>Квартал</dt><dd><?= e($areaLabel) ?><?= $b['geo_confidence'] === 'approx' ? ' <span class="geo-approx">(приблизително)</span>' : '' ?></dd>
        <dt>Вид</dt><dd><?= relevance_badge($b['relevance']) ?><?= $b['relevance_reason'] ? ' <span class="muted small">' . e($b['relevance_reason']) . '</span>' : '' ?>
          <div class="row small">
            <?php foreach (['high' => '→ Бръснарница', 'medium' => '→ Салон', 'low' => '→ Неподходящ'] as $rk => $rl): if ($rk === $b['relevance']) continue; ?><?= relevance_button($id, $rk, $rl, $csrf) ?><?php endforeach; ?>
          </div></dd>
        <dt>Адрес</dt><dd><?= unknown_or($b['address']) ?></dd>
        <dt>Телефон</dt><dd><?= $b['phone'] ? '<a href="tel:' . e($b['phone_normalized'] ?? $b['phone']) . '">' . e($b['phone']) . '</a>' : unknown_or(null) ?></dd>
        <dt>Имейл</dt><dd><?= $b['email'] ? '<a href="mailto:' . e($b['email']) . '">' . e($b['email']) . '</a>' : unknown_or(null) ?></dd>
        <dt>Сайт</dt><dd><?= $b['website_url'] ? ext_link($b['website_url']) : unknown_or(null) ?></dd>
        <dt>Instagram</dt><dd><?= $b['instagram_url'] ? ext_link($b['instagram_url']) : '<a href="' . e('https://www.google.com/search?q=' . rawurlencode($b['name'] . ' instagram')) . '" target="_blank" rel="noopener noreferrer">търси в Google →</a>' ?></dd>
        <dt>Facebook</dt><dd><?= $b['facebook_url'] ? ext_link($b['facebook_url']) : unknown_or(null) ?></dd>
        <dt>TikTok</dt><dd><?= $b['tiktok_url'] ? ext_link($b['tiktok_url']) : unknown_or(null) ?></dd>
        <dt>Работно време</dt><dd><?= unknown_or($b['working_hours']) ?></dd>
        <dt>Записване</dt><dd><?= unknown_or($b['booking_method']) ?></dd>
        <dt>Рейтинг</dt><dd><?= $b['rating'] !== null ? e(number_format((float) $b['rating'], 1, ',', '')) . ' ★' . ($b['reviews_count'] !== null ? ' (' . (int) $b['reviews_count'] . ' отзива)' : '') : unknown_or(null) ?></dd>
        <?php if ($b['lat'] !== null && $b['lng'] !== null): ?>
        <dt>Карта</dt><dd><a href="<?= e('https://www.openstreetmap.org/?mlat=' . $b['lat'] . '&mlon=' . $b['lng'] . '#map=18/' . $b['lat'] . '/' . $b['lng']) ?>" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> · <a href="<?= e('https://www.google.com/maps/search/?api=1&query=' . $b['lat'] . ',' . $b['lng']) ?>" target="_blank" rel="noopener noreferrer">Google Maps</a></dd>
        <?php endif; ?>
        <dt>Създаден</dt><dd><?= fmt_date($b['created_at'], true) ?> · обновен <?= fmt_date($b['updated_at'], true) ?></dd>
      </dl>
    </section>

    <section class="card">
      <h2>Сайт и оценка</h2>
      <dl class="kv">
        <dt>Сайт</dt><dd><?= web_kind_badge($b) ?></dd>
        <dt>Оценка</dt><dd><?= $score !== null ? (int) $score . ' / 100' . ($b['lead_score_override'] !== null ? ' <span class="muted small">(ръчна)</span>' : '') : '<span class="muted">още не е изчислена</span>' ?></dd>
        <?php if ($b['score_explanation']): ?><dt>Защо</dt><dd class="small"><?= nl2br(e($b['score_explanation'])) ?></dd><?php endif; ?>
      </dl>
      <p class="muted small">Автоматичната проверка на сайта и точкуването идват във Фаза 3. Дотогава оценката на сайта и ръчната оценка се задават от „Редактирай“.</p>
    </section>

    <section class="card" id="sources">
      <h2>Източници</h2>
      <?php if (!$sources): ?>
        <p class="muted small">Добавен ръчно, без външен източник.</p>
      <?php else: ?>
        <p class="muted small">Какво казва всеки източник и кога е проверено. Различията се записват в историята като „Различни данни“.</p>
        <?php foreach ($sources as $src): $d = json_decode((string) $src['data'], true) ?: []; ?>
          <details class="box">
            <summary class="small"><?= e(\BarberLead\Discovery\Importer::sourceLabel($src['source'])) ?> · видян последно <?= fmt_date($src['last_seen_at'], true) ?><?= $src['match_reason'] && $src['match_reason'] !== 'нов запис' ? ' · свързан: ' . e($src['match_reason']) : '' ?></summary>
            <dl class="kv small">
              <?php foreach (['name' => 'Име', 'address' => 'Адрес', 'phone' => 'Телефон', 'website' => 'Сайт', 'instagram' => 'Instagram', 'facebook' => 'Facebook', 'opening_hours' => 'Работно време', 'rating' => 'Рейтинг', 'reviews_count' => 'Отзиви'] as $k => $label): if (!isset($d[$k])) continue; ?>
                <dt><?= e($label) ?></dt><dd><?= in_array($k, ['website', 'instagram', 'facebook'], true) ? ext_link((string) $d[$k]) : e(is_scalar($d[$k]) ? (string) $d[$k] : '') ?></dd>
              <?php endforeach; ?>
              <?php if (!empty($d['purged'])): ?><dt>Данни</dt><dd class="muted">изчистени след 30 дни (условия на Google); пази се само идентификаторът</dd><?php endif; ?>
              <?php if ($src['url']): ?><dt>Линк</dt><dd><?= ext_link($src['url'], 'отвори в източника') ?></dd><?php endif; ?>
            </dl>
          </details>
        <?php endforeach; ?>
        <p class="muted small">Данни от OpenStreetMap © OpenStreetMap contributors (ODbL).</p>
      <?php endif; ?>
    </section>

    <?php if ($b['notes']): ?>
    <section class="card"><h2>Бележки</h2><p class="small timeline t-note"><?= nl2br(e($b['notes'])) ?></p></section>
    <?php endif; ?>

    <section class="card" id="history">
      <h2>История</h2>
      <form method="post" action="/businesses/<?= $id ?>/note" class="form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <textarea name="note" maxlength="2000" placeholder="Добави бележка в историята (напр. „обадих се, ще мислят“)…" required></textarea>
        <div class="row row-end"><button class="btn btn-sm">Добави бележка</button></div>
      </form>
      <ul class="timeline">
        <?php foreach ($events as $ev): ?>
          <li><time><?= fmt_date($ev['created_at'], true) ?></time><div class="t-<?= e($ev['type']) ?>"><?= e($ev['message']) ?><?php if ($ev['actor'] && $ev['actor'] !== 'auto' && $ev['actor'] !== 'system'): ?> <span class="muted small">· <?= e($ev['actor']) ?></span><?php endif; ?></div></li>
        <?php endforeach; ?>
      </ul>
    </section>
  </div>

  <div class="cards">
    <section class="card">
      <h2>Статус</h2>
      <form method="post" action="/businesses/<?= $id ?>/status" class="row">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <select name="status" aria-label="Статус" data-autosubmit>
          <?php foreach (Status::options($b['status']) as $k => $l): ?><option value="<?= e($k) ?>"<?= $b['status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select>
        <noscript><button class="btn btn-sm">Запиши</button></noscript>
      </form>
    </section>

    <section class="card offer-box" id="offer">
      <h2>Пусната оферта</h2>
      <p class="muted small">Само записва, че ти си пратил офертата. Системата не праща нищо на бизнеса.</p>
      <form method="post" action="/businesses/<?= $id ?>/offer" class="form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="grid2">
          <label>Канал<select name="channel" required>
            <?php foreach (Status::CHANNELS as $k => $l): ?><option value="<?= e($k) ?>"<?= $k === 'instagram' ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
          </select></label>
          <label>Сума, € (по желание)<input name="amount_eur" inputmode="decimal" placeholder="напр. 250"></label>
        </div>
        <label>Демо, изпратено в офертата<input name="demo_url" type="url" value="<?= e($demoUrl ?? '') ?>" placeholder="https://…"></label>
        <label>Бележка<textarea name="note" maxlength="5000" placeholder="Какво точно им предложи…"></textarea></label>
        <label>Напомни ми след (дни, 0 = без)<input name="follow_up_days" type="number" min="0" max="365" value="<?= (int) $followUpDays ?>"></label>
        <button class="btn btn-offer">Пусната оферта</button>
      </form>
      <?php if ($offers): ?>
        <h3 class="small muted" id="offers">Предишни оферти</h3>
        <ul class="timeline">
          <?php foreach ($offers as $o): ?>
            <li><time><?= fmt_date($o['sent_at'], true) ?></time>
              <div><?= e(Status::CHANNELS[$o['channel']] ?? $o['channel']) ?><?= $o['amount_eur'] !== null ? ' · ' . e(number_format((float) $o['amount_eur'], 2, ',', ' ')) . ' €' : '' ?>
                <?php if ($o['demo_url']): ?><br><?= ext_link($o['demo_url']) ?><?php endif; ?>
                <?php if ($o['note']): ?><br><span class="small t-note"><?= e($o['note']) ?></span><?php endif; ?>
                <?php if ($o['follow_up_at']): ?><br><span class="small muted">Напомняне: <?= fmt_date($o['follow_up_at']) ?><?= $o['follow_up_done_at'] ? ' ✓ направено' : '' ?></span><?php endif; ?>
              </div></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="row row-between"><h2>Съобщение</h2>
        <span class="row"><button type="button" class="btn btn-sm" data-copy="outreach-text">Копирай</button><?= write_button($b + ['demo_url' => $demoUrl], (string) $app->settings->get('outreach_template', ''), (string) $app->settings->get('my_name', ''), 'btn btn-sm btn-k btn-write') ?></span></div>
      <?= contact_links($b) ?>
      <div class="outreach" id="outreach-text"><?= e($outreach) ?></div>
      <p class="muted small">Шаблонът се сменя от <a href="/settings">Настройки</a>.</p>
    </section>

    <section class="card" id="demos">
      <h2>Демо сайтове</h2>
      <?php if (!$demos): ?><p class="muted small">Още няма демо.</p><?php endif; ?>
      <ul class="timeline">
        <?php foreach ($demos as $d): ?>
          <li><time><?= fmt_date($d['created_at']) ?></time><div><?= ext_link($d['url']) ?><br><span class="small muted">v<?= (int) $d['version'] ?> · <?= e(DemoRepository::STATUSES[$d['status']] ?? $d['status']) ?></span>
            <?php if ($d['notes']): ?><br><span class="small"><?= e($d['notes']) ?></span><?php endif; ?></div></li>
        <?php endforeach; ?>
      </ul>
      <details class="box"><summary class="small">+ Добави демо ръчно</summary>
        <form method="post" action="/businesses/<?= $id ?>/demos" class="form">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <label>Адрес<input name="url" type="url" required placeholder="https://име.testvane.org/"></label>
          <label>Бележка<input name="notes" maxlength="1000"></label>
          <button class="btn btn-sm">Добави</button>
        </form>
      </details>
    </section>
  </div>
</div>
