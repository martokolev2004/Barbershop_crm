<?php /** @var list<array<string,mixed>> $rows */ ?>
<div class="page-head"><div><h1>Възможни дубликати</h1>
  <p class="muted">Записи, които си приличат, но системата не е сигурна, че са едно и също място. Нищо не се слива без теб.</p></div></div>
<?php if (!$rows): ?>
  <div class="card empty">Няма нищо за преглед.</div>
<?php endif; ?>
<?php foreach ($rows as $d): ?>
  <section class="card">
    <div class="row row-between"><h2>Съвпадение <?= (int) round((float) $d['score'] * 100) ?>%</h2><span class="muted small"><?= e($d['reasons']) ?></span></div>
    <div class="dup">
      <?php foreach (['a' => (int) $d['business_id'], 'b' => (int) $d['other_id']] as $p => $bid): ?>
        <div class="card">
          <h3><a href="/businesses/<?= $bid ?>" target="_blank"><?= e($d[$p . '_name']) ?></a></h3>
          <dl class="kv small">
            <dt>Квартал</dt><dd><?= e($app->areas->label($d[$p . '_area'] !== null ? (int) $d[$p . '_area'] : null)) ?></dd>
            <dt>Адрес</dt><dd><?= unknown_or($d[$p . '_address']) ?></dd>
            <dt>Телефон</dt><dd><?= unknown_or($d[$p . '_phone']) ?></dd>
            <dt>Сайт</dt><dd><?= ext_link($d[$p . '_web']) ?></dd>
            <dt>Instagram</dt><dd><?= ext_link($d[$p . '_insta']) ?></dd>
            <dt>Добавен</dt><dd><?= fmt_date($d[$p . '_created'], true) ?></dd>
          </dl>
          <form method="post" action="/duplicates/<?= (int) $d['id'] ?>/merge" data-confirm="Да слея ли двата записа и да запазя този?">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="keep" value="<?= $bid ?>">
            <button class="btn btn-sm">Същото място — запази този</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
    <form method="post" action="/duplicates/<?= (int) $d['id'] ?>/dismiss" class="row row-end">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="btn btn-sm">Различни бизнеси (напр. два филиала)</button>
    </form>
  </section>
<?php endforeach; ?>
