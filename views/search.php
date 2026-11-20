<?php
/**
 * @var string $q @var list<array<string,mixed>> $ours @var ?list<array<string,mixed>> $google
 * @var bool $hasGoogleKey @var string $template @var string $myName
 */
?>
<div class="page-head">
  <div><h1>Търсене</h1><p class="muted">Видял си бизнес някъде? Първо търся в нашата база. Ако го няма, търся в Google.</p></div>
</div>

<form method="get" action="/search" class="card search-big">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Име, телефон, Instagram, адрес…" aria-label="Търсене" autofocus>
  <button class="btn btn-k">Търси</button>
</form>

<?php if ($q !== ''): ?>
<section class="card">
  <h2>При нас <span class="muted small">(<?= count($ours) ?>)</span></h2>
  <?php if (!$ours): ?>
    <p class="muted">Нямаме „<?= e($q) ?>“ в базата.</p>
  <?php else: ?>
    <table class="list"><tbody>
    <?php foreach ($ours as $b): ?>
      <tr>
        <td class="name"><a href="/businesses/<?= (int) $b['id'] ?>"><?= e($b['name']) ?></a> <?= web_kind_badge($b) ?>
          <span class="sub"><?= e($b['address'] ?? '') ?></span><?= contact_links($b) ?></td>
        <td class="nowrap"><?= status_badge((string) $b['status']) ?> <?= relevance_badge((string) $b['relevance']) ?></td>
        <td class="actions"><a class="btn btn-sm" href="/businesses/<?= (int) $b['id'] ?>">Отвори</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>
</section>

<section class="card" id="google">
  <div class="row row-between">
    <h2>В Google</h2>
    <?php if ($hasGoogleKey): ?>
      <form method="post" action="/search/google" class="inline">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="q" value="<?= e($q) ?>">
        <button class="btn btn-sm <?= $ours ? '' : 'btn-k' ?>"><?= $google === null ? 'Търси в Google' : 'Търси пак' ?></button>
      </form>
    <?php endif; ?>
  </div>
  <?php if (!$hasGoogleKey): ?>
    <p class="muted">Добави Google ключ в <a href="/settings">Настройки</a>, за да търсиш и в Google.</p>
  <?php elseif ($google === null): ?>
    <p class="muted">Една заявка към Google (брои се към месечния лимит). Добре е да напишеш и квартала или града, напр. „Prime Barbershop Лозенец“.</p>
  <?php elseif (!$google): ?>
    <p class="muted">Google не намери нищо за „<?= e($q) ?>“.</p>
  <?php else: ?>
    <table class="list"><tbody>
    <?php foreach ($google as $g): ?>
      <tr>
        <td class="name"><?= e($g['name'] ?? '') ?> <?= relevance_badge((string) $g['relevance']) ?>
          <?php if (!empty($g['closed'])): ?><span class="badge">затворен</span><?php endif; ?>
          <span class="sub"><?= e($g['address'] ?? '') ?><?= isset($g['rating']) ? ' · ★ ' . e($g['rating']) . ' (' . (int) ($g['reviews_count'] ?? 0) . ')' : '' ?></span>
          <span class="sub"><?= e(implode(' · ', array_filter([$g['phone'] ?? null, $g['website'] ?? null, $g['instagram'] ?? null, $g['facebook'] ?? null]))) ?: 'без сайт и телефон в Google' ?></span></td>
        <td class="actions">
          <?php if ($u = safe_href($g['url'] ?? null)): ?><a class="btn btn-sm" href="<?= e($u) ?>" target="_blank" rel="noopener noreferrer">Карта</a><?php endif; ?>
          <?php if ($g['business_id'] !== null): ?>
            <a class="btn btn-sm" href="/businesses/<?= (int) $g['business_id'] ?>">Вече го имаме →</a>
          <?php else: ?>
            <form method="post" action="/search/add" class="inline">
              <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="place" value="<?= e($g['id']) ?>">
              <button class="btn btn-sm btn-k">+ Добави</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>
</section>
<?php endif; ?>
