<?php
use BarberLead\Status;
/** @var array<string,mixed> $v @var array<string,string> $errors @var ?int $id @var array<int,string> $areaOptions @var list<array<string,mixed>> $duplicates @var string $title */
$f = fn (string $k) => e(is_scalar($v[$k] ?? null) ? (string) $v[$k] : '');
$err = fn (string $k) => isset($errors[$k]) ? '<span class="field-err">' . e($errors[$k]) . '</span>' : '';
$action = $id ? "/businesses/$id" : '/businesses';
?>
<div class="page-head">
  <div><div class="crumbs"><a href="/businesses">Бизнеси</a><?php if ($id): ?> / <a href="/businesses/<?= $id ?>">профил</a><?php endif; ?></div><h1><?= e($title) ?></h1></div>
</div>
<?php if ($errors): ?><div class="flash err" role="alert">Провери отбелязаните полета.</div><?php endif; ?>
<?php if ($duplicates): ?>
  <div class="notice">Изглежда вече има такъв бизнес:
    <?php foreach ($duplicates as $i => $d): ?><?= $i ? ', ' : '' ?><a href="/businesses/<?= (int) $d['id'] ?>" target="_blank"><?= e($d['name']) ?></a><?= $d['address'] ? ' (' . e($d['address']) . ')' : '' ?><?php endforeach; ?>.
    Ако е друг обект (напр. друг филиал), натисни „Запиши“ отново.</div><p></p>
<?php endif; ?>

<form method="post" action="<?= e($action) ?>" class="form card">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <?php if ($duplicates): ?><input type="hidden" name="confirm_duplicate" value="1"><?php endif; ?>
  <fieldset><legend>Основно</legend>
    <div class="grid2">
      <label>Име *<input name="name" required maxlength="200" value="<?= $f('name') ?>"><?= $err('name') ?></label>
      <label>Марка / верига<input name="brand" maxlength="200" value="<?= $f('brand') ?>"><?= $err('brand') ?></label>
      <label>Квартал<select name="area_id">
        <option value="">— неизвестен —</option>
        <?php foreach ($areaOptions as $aid => $label): ?><option value="<?= (int) $aid ?>"<?= (string) ($v['area_id'] ?? '') === (string) $aid ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select><?= $err('area_id') ?></label>
      <label>Адрес<input name="address" maxlength="255" value="<?= $f('address') ?>"><?= $err('address') ?></label>
      <label>Ширина (lat)<input name="lat" inputmode="decimal" value="<?= $f('lat') ?>" placeholder="42.68…"><?= $err('lat') ?></label>
      <label>Дължина (lng)<input name="lng" inputmode="decimal" value="<?= $f('lng') ?>" placeholder="23.32…"><?= $err('lng') ?></label>
    </div>
  </fieldset>
  <fieldset><legend>Контакти и профили</legend>
    <div class="grid2">
      <label>Телефон<input name="phone" type="tel" maxlength="40" value="<?= $f('phone') ?>"><?= $err('phone') ?></label>
      <label>Имейл<input name="email" type="email" maxlength="190" value="<?= $f('email') ?>"><?= $err('email') ?></label>
      <label>Сайт<input name="website_url" maxlength="500" value="<?= $f('website_url') ?>" placeholder="https://…"><?= $err('website_url') ?></label>
      <label>Instagram<input name="instagram_url" maxlength="300" value="<?= $f('instagram_url') ?>" placeholder="https://instagram.com/…"><?= $err('instagram_url') ?></label>
      <label>Facebook<input name="facebook_url" maxlength="300" value="<?= $f('facebook_url') ?>"><?= $err('facebook_url') ?></label>
      <label>TikTok<input name="tiktok_url" maxlength="300" value="<?= $f('tiktok_url') ?>"><?= $err('tiktok_url') ?></label>
    </div>
  </fieldset>
  <fieldset><legend>Бизнес</legend>
    <div class="grid3">
      <label>Рейтинг (0–5)<input name="rating" inputmode="decimal" value="<?= $f('rating') ?>"><?= $err('rating') ?></label>
      <label>Брой отзиви<input name="reviews_count" inputmode="numeric" value="<?= $f('reviews_count') ?>"><?= $err('reviews_count') ?></label>
      <label>Начин на записване<input name="booking_method" maxlength="120" value="<?= $f('booking_method') ?>" placeholder="телефон, Fresha, Instagram…"><?= $err('booking_method') ?></label>
    </div>
    <label>Работно време<input name="working_hours" maxlength="500" value="<?= $f('working_hours') ?>"><?= $err('working_hours') ?></label>
  </fieldset>
  <fieldset><legend>Оценка</legend>
    <div class="grid2">
      <label>Сайт<select name="website_status">
        <?php foreach (Status::WEBSITE as $k => $l): ?><option value="<?= e($k) ?>"<?= ($v['website_status'] ?? 'unknown') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
      </select><?= $err('website_status') ?></label>
      <label>Ръчна оценка (0–100, празно = автоматична)<input name="lead_score_override" inputmode="numeric" value="<?= $f('lead_score_override') ?>"><?= $err('lead_score_override') ?></label>
    </div>
  </fieldset>
  <label>Бележки<textarea name="notes" maxlength="10000"><?= $f('notes') ?></textarea><?= $err('notes') ?></label>
  <div class="row row-end">
    <a class="btn" href="<?= $id ? "/businesses/$id" : '/businesses' ?>">Отказ</a>
    <button class="btn btn-k">Запиши</button>
  </div>
</form>
