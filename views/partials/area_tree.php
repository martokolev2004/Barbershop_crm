<?php
/** @var list<array<string,mixed>> $nodes @var array<int,int> $counts @var int $selected @var int $depth */
// Countries are not shown; their cities are rendered at the top level.
if ($depth === 0) {
    $flat = [];
    foreach ($nodes as $n) {
        foreach ($n['children'] as $c) {
            $flat[] = $c;
        }
    }
    $nodes = $flat;
}
?>
<ul class="<?= $depth === 0 ? 'tree' : '' ?>">
<?php foreach ($nodes as $n):
    $id = (int) $n['id'];
    $cnt = $counts[$id] ?? 0;
    $link = '<a href="' . e(url('/businesses', ['area' => $id])) . '" class="' . ($id === $selected ? 'on ' : '') . ($cnt > 0 ? 'has' : '') . '">'
        . '<span>' . e($n['name_bg']) . '</span><span class="n">' . $cnt . '</span></a>';
?>
  <li data-search="<?= e(mb_strtolower($n['name_bg'] . ' ' . ($n['name_en'] ?? ''))) ?>" class="<?= $n['children'] ? '' : 'leaf' ?>">
    <?php if ($n['children']): ?>
      <details<?= area_contains($n, $selected) ? ' open' : '' ?>>
        <summary><?= $link ?></summary>
        <?= $view->partial('partials/area_tree', ['nodes' => $n['children'], 'counts' => $counts, 'selected' => $selected, 'depth' => $depth + 1]) ?>
      </details>
    <?php else: ?>
      <?= $link ?>
    <?php endif; ?>
  </li>
<?php endforeach; ?>
</ul>
