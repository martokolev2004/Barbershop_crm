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
    <?php if ($pending): ?>
    <section class="card">
      <h2>Обновяване на базата</h2>
      <p class="muted small">Чакащи промени: <?= e(implode(', ', $pending)) ?>. Преди това се прави бекъп.</p>
      <form method="post" action="/settings/migrate"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="btn btn-k btn-sm">Обнови базата</button></form>
    </section>
    <?php endif; ?>
    <section class="card" id="google">
      <h2>Google Places</h2>
      <p class="muted small">Ключът се пази само в базата на сървъра и не се показва никъде. Ограничи го в Google Cloud само до „Places API (New)“ и до IP адреса на хостинга (87.121.18.11).</p>
      <form method="post" action="/settings/google" class="form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>API ключ<input name="google_api_key" type="password" autocomplete="off" placeholder="<?= $googleKeySuffix ? 'записан ключ …' . e($googleKeySuffix) . ' (остави празно, за да го запазиш)' : 'AIza…' ?>"></label>
        <label>Месечен лимит заявки (безплатно: до 1000 за данни с телефон/сайт)<input name="google_monthly_cap" type="number" min="0" max="100000" value="<?= e($googleCap) ?>"></label>
        <p class="small">Този месец: <strong><?= (int) $googleUsage ?></strong> заявки.</p>
        <div class="row">
          <button class="btn btn-sm">Запиши</button>
          <?php if ($googleKeySuffix): ?><label class="check small"><input type="checkbox" name="remove_key" value="1"> изтрий ключа</label><?php endif; ?>
        </div>
      </form>
    </section>
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
