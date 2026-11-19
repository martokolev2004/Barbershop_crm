<?php
use BarberLead\Status;
/** @var array<string,mixed> $v @var array<string,string> $errors @var ?int $id @var array<int,string> $areaOptions @var list<array<string,mixed>> $duplicates @var string $title */
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
      <label>Име *<input name="name" required maxlength="200" value="<?= form_value($v, 'name') ?>"><?= form_error($errors, 'name') ?></label>
      <label>Марка / верига<input name="brand" maxlength="200" value="<?= form_value($v, 'brand') ?>"><?= form_error($errors, 'brand') ?></label>
      <label>Квартал<select name="area_id">
        <option value="">— неизвестен —</option>
        <?php foreach ($areaOptions as $aid => $label): ?><option value="<?= (int) $aid ?>"<?= (string) ($v['area_id'] ?? '') === (string) $aid ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select><?= form_error($errors, 'area_id') ?></label>
      <label>Адрес<input name="address" maxlength="255" value="<?= form_value($v, 'address') ?>"><?= form_error($errors, 'address') ?></label>
      <label>Ширина (lat)<input name="lat" inputmode="decimal" value="<?= form_value($v, 'lat') ?>" placeholder="42.68…"><?= form_error($errors, 'lat') ?></label>
      <label>Дължина (lng)<input name="lng" inputmode="decimal" value="<?= form_value($v, 'lng') ?>" placeholder="23.32…"><?= form_error($errors, 'lng') ?></label>
    </div>
  </fieldset>
  <fieldset><legend>Контакти и профили</legend>
    <div class="grid2">
      <label>Телефон<input name="phone" type="tel" maxlength="40" value="<?= form_value($v, 'phone') ?>"><?= form_error($errors, 'phone') ?></label>
      <label>Имейл<input name="email" type="email" maxlength="190" value="<?= form_value($v, 'email') ?>"><?= form_error($errors, 'email') ?></label>
      <label>Сайт<input name="website_url" maxlength="500" value="<?= form_value($v, 'website_url') ?>" placeholder="https://…"><?= form_error($errors, 'website_url') ?></label>
      <label>Instagram<input name="instagram_url" maxlength="300" value="<?= form_value($v, 'instagram_url') ?>" placeholder="https://instagram.com/…"><?= form_error($errors, 'instagram_url') ?></label>
      <label>Facebook<input name="facebook_url" maxlength="300" value="<?= form_value($v, 'facebook_url') ?>"><?= form_error($errors, 'facebook_url') ?></label>
      <label>TikTok<input name="tiktok_url" maxlength="300" value="<?= form_value($v, 'tiktok_url') ?>"><?= form_error($errors, 'tiktok_url') ?></label>
    </div>
  </fieldset>
  <fieldset><legend>Бизнес</legend>
    <div class="grid3">
      <label>Рейтинг (0–5)<input name="rating" inputmode="decimal" value="<?= form_value($v, 'rating') ?>"><?= form_error($errors, 'rating') ?></label>
      <label>Брой отзиви<input name="reviews_count" inputmode="numeric" value="<?= form_value($v, 'reviews_count') ?>"><?= form_error($errors, 'reviews_count') ?></label>
      <label>Начин на записване<input name="booking_method" maxlength="120" value="<?= form_value($v, 'booking_method') ?>" placeholder="телефон, Fresha, Instagram…"><?= form_error($errors, 'booking_method') ?></label>
    </div>
    <label>Работно време<input name="working_hours" maxlength="500" value="<?= form_value($v, 'working_hours') ?>"><?= form_error($errors, 'working_hours') ?></label>
  </fieldset>
  <fieldset><legend>Оценка</legend>
    <div class="grid2">
      <label>Сайт<select name="website_status">
        <?php foreach (Status::WEBSITE as $k => $l): ?><option value="<?= e($k) ?>"<?= ($v['website_status'] ?? 'unknown') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
      </select><?= form_error($errors, 'website_status') ?></label>
      <label>Ръчна оценка (0–100, празно = автоматична)<input name="lead_score_override" inputmode="numeric" value="<?= form_value($v, 'lead_score_override') ?>"><?= form_error($errors, 'lead_score_override') ?></label>
    </div>
  </fieldset>
  <label>Бележки<textarea name="notes" maxlength="10000"><?= form_value($v, 'notes') ?></textarea><?= form_error($errors, 'notes') ?></label>
  <div class="row row-end">
    <a class="btn" href="<?= $id ? "/businesses/$id" : '/businesses' ?>">Отказ</a>
    <button class="btn btn-k">Запиши</button>
  </div>
</form>
