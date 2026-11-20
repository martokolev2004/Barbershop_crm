<?php
/** @var string $content @var string $title @var string $path @var string $csrf @var ?string $user @var int $dueFollowUps @var list<array{string,string}> $flashes */
$nav = [
    '/' => 'Табло',
    '/businesses' => 'Бизнеси',
    '/businesses?status=researching' => 'Проучвам',
    '/kanban' => 'Kanban',
    '/offers' => 'Оферти',
    '/jobs' => 'Сканирания',
    '/duplicates' => 'Дубликати',
    '/businesses?status=rejected' => 'Отхвърлени',
    '/settings' => 'Настройки',
];
$selectedArea = isset($area) && is_array($area) ? (int) $area['id'] : 0;
?>
<!DOCTYPE html>
<html lang="bg">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf" content="<?= e($csrf) ?>">
<title><?= e($title ?? 'BarberLead') ?> · BarberLead</title>
<link rel="stylesheet" href="/assets/app.css?v=7">
<script src="/assets/app.js?v=4" defer></script>
</head>
<body>
<?php if ($user === null): ?>
<?= $content ?>
<?php else: ?>
<div class="topbar"><a class="brand" href="/">BARBERLEAD</a><button type="button" id="menu-toggle" aria-label="Меню">Меню</button></div>
<div class="shell">
  <aside class="side" aria-label="Навигация">
    <a class="brand" href="/"><b>BARBER</b>LEAD<small>КЛИЕНТИ · ДЕМА · ОФЕРТИ</small></a>
    <nav class="nav">
      <?php foreach ($nav as $href => $label): ?>
        <a href="<?= e($href) ?>" class="<?= nav_active($path, $href) ? 'on' : '' ?>"><?= e($label) ?>
          <?php if ($href === '/offers' && $dueFollowUps > 0): ?><span class="pill" title="Напомняния за днес"><?= (int) $dueFollowUps ?></span><?php endif; ?>
          <?php if ($href === '/businesses?status=researching' && $researchingCount > 0): ?><span class="pill"><?= (int) $researchingCount ?></span><?php endif; ?>
          <?php if ($href === '/businesses?status=rejected' && $rejectedCount > 0): ?><span class="n-muted"><?= (int) $rejectedCount ?></span><?php endif; ?>
          <?php if ($href === '/duplicates' && $openDuplicates > 0): ?><span class="pill" title="За преглед"><?= (int) $openDuplicates ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="side-areas">
      <h4>Градове и квартали</h4>
      <input type="search" id="area-search" placeholder="Търси квартал…" aria-label="Търси квартал">
      <?= $view->partial('partials/area_tree', ['nodes' => $app->areas->tree(), 'counts' => $app->areas->totalCounts(), 'selected' => $selectedArea, 'depth' => 0]) ?>
    </div>
    <div class="side-foot">
      <span><?= e($user) ?></span>
      <form method="post" action="/logout" class="inline"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="linkbtn">Изход</button></form>
    </div>
  </aside>
  <main class="main">
    <?php if ($pendingMigrations > 0 && $path !== '/settings'): ?>
      <div class="banner"><span>Има нова версия: базата трябва да се обнови.</span>
        <form method="post" action="/settings/migrate"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="btn btn-sm">Обнови базата</button></form></div>
    <?php endif; ?>
    <?php foreach ($flashes ?? [] as [$type, $msg]): ?>
      <div class="flash <?= e($type) ?>" role="status"><?= e($msg) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
  </main>
</div>
<?php endif; ?>
</body>
</html>
