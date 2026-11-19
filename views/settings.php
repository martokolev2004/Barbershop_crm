<?php
/** @var string $template @var string $myName @var string $followUpDays @var list<array{name:string,size:int|false,time:int|false}> $backups @var list<array<string,mixed>> $audit */
?>
<div class="page-head"><div><h1>Настройки</h1></div></div>
<div class="split">
  <div class="cards">
    <section class="card">
      <h2>Шаблон за съобщение</h2>
      <form method="post" action="/settings" class="form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <p class="muted small">Може да ползваш <code>{name}</code> (име на бизнеса), <code>{demo_url}</code> (последното демо) и <code>{my_name}</code>.</p>
        <textarea name="outreach_template" rows="14" maxlength="5000"><?= e($template) ?></textarea>
        <div class="grid2">
          <label>Твоето име в съобщенията<input name="my_name" maxlength="80" value="<?= e($myName) ?>"></label>
          <label>Напомняне по подразбиране (дни)<input name="follow_up_days" type="number" min="0" max="60" value="<?= e($followUpDays) ?>"></label>
        </div>
        <div class="row row-end"><button class="btn btn-k">Запиши</button></div>
      </form>
    </section>
    <section class="card">
      <h2>Последни действия по сигурността</h2>
      <table class="list"><tbody>
      <?php foreach ($audit as $a): ?>
        <tr><td class="nowrap small"><?= fmt_date($a['created_at'], true) ?></td><td><?= e($a['action']) ?></td><td class="small muted"><?= e($a['actor'] ?? '') ?> · <?= e($a['ip'] ?? '') ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    </section>
  </div>
  <div class="cards">
    <section class="card" id="password">
      <h2>Смяна на парола</h2>
      <form method="post" action="/settings/password" class="form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Текуща парола<input name="current_password" type="password" required autocomplete="current-password"></label>
        <label>Нова парола (поне 10 символа)<input name="new_password" type="password" required minlength="10" autocomplete="new-password"></label>
        <label>Новата още веднъж<input name="new_password2" type="password" required minlength="10" autocomplete="new-password"></label>
        <button class="btn">Смени паролата</button>
      </form>
    </section>
    <section class="card" id="backups">
      <h2>Бекъпи на базата</h2>
      <p class="muted small">Пазят се на сървъра извън публичната папка. Автоматичен нощен бекъп се пуска с cron (виж README).</p>
      <form method="post" action="/settings/backup"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="btn btn-sm">Направи бекъп сега</button></form>
      <ul class="timeline">
        <?php foreach ($backups as $bk): ?><li><time><?= $bk['time'] ? date('d.m.Y H:i', $bk['time']) : '' ?></time><div class="small"><?= e($bk['name']) ?> · <?= $bk['size'] ? round($bk['size'] / 1024) . ' KB' : '' ?></div></li><?php endforeach; ?>
        <?php if (!$backups): ?><li><span class="muted small">Още няма бекъпи.</span></li><?php endif; ?>
      </ul>
    </section>
  </div>
</div>
