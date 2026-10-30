<?php
require __DIR__ . '/inc/bootstrap.php';
$me = require_login();
handle_signal_post($me);

if (is_post() && ($_POST['action'] ?? '') === 'creer_extra') {
    // Seuls les administrateurs peuvent créer une séance extraordinaire.
    require_perm('seances');
    csrf_check();
    $titre = trim($_POST['titre'] ?? '');
    $date = $_POST['date'] ?? '';
    $heure = $_POST['heure'] ?? '';
    if ($titre === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $heure)) {
        flash('Renseignez un titre, une date et une heure valides.', 'err');
    } else {
        q("INSERT INTO seances (date, heure, type, titre, lieu, statut, created_by, created_at) VALUES (?, ?, 'extraordinaire', ?, ?, 'prevue', ?, ?)",
          [$date, $heure, $titre, trim($_POST['lieu'] ?? ''), $me['id'], date('c')]);
        flash('Séance extraordinaire créée.');
    }
    redirect('seances.php');
}

ensure_seances();
$maintenant = date('Y-m-d H:i');
$aVenir = rows("SELECT * FROM seances WHERE (date || ' ' || heure) >= ? ORDER BY date, heure", [$maintenant]);
$passees = rows("SELECT * FROM seances WHERE (date || ' ' || heure) < ? ORDER BY date DESC, heure DESC LIMIT 30", [$maintenant]);

function seance_badge(array $s): string {
    if ($s['statut'] === 'annulee') return badge('Annulée', 'err');
    if ($s['statut'] === 'terminee') return badge('Appel fait', 'ok');
    return $s['type'] === 'extraordinaire' ? badge('Extraordinaire', 'warn') : '';
}

page_start('Séances', 'seances.php');
if (can('seances')): ?>
<div class="toolbar"><span class="muted">Les séances du samedi et du dimanche soir sont créées automatiquement.</span>
  <button class="btn" data-open-dialog="dlg-extra">+ Séance extraordinaire</button></div>
<?php endif; ?>
<p class="muted small">Prévenez <strong>avant le début</strong> d'une séance si vous serez en retard ou absent(e), sinon une pénalité s'applique
  (retard : <?= money(setting('amende_retard')) ?> · absence : <?= money(setting('amende_absence')) ?>).</p>

<section class="panel"><h2>À venir</h2>
  <?php if (!$aVenir): ?><p class="muted">Aucune séance à venir.</p><?php endif; ?>
  <ul class="list">
  <?php foreach ($aVenir as $s): ?>
    <li class="stack <?= $s['statut'] === 'annulee' ? 'cancelled' : '' ?>">
      <span><a href="seance.php?id=<?= (int)$s['id'] ?>"><strong><?= e(seance_label($s)) ?></strong></a> <?= seance_badge($s) ?><br>
        <small class="muted"><?= fdate($s['date'], true) ?> · <?= e($s['heure']) ?><?= $s['lieu'] ? ' · ' . e($s['lieu']) : '' ?><?= $s['motif_annulation'] ? ' · Motif : ' . e($s['motif_annulation']) : '' ?></small></span>
      <?php if ($s['statut'] === 'prevue') render_signal_box($s, (int)$me['id']); ?>
    </li>
  <?php endforeach; ?></ul></section>

<section class="panel"><h2>Passées</h2>
  <?php if (!$passees): ?><p class="muted">Aucune séance passée.</p><?php endif; ?>
  <ul class="list">
  <?php foreach ($passees as $s):
      $pr = row('SELECT statut, excuse FROM presences WHERE seance_id = ? AND user_id = ?', [$s['id'], $me['id']]); ?>
    <li class="<?= $s['statut'] === 'annulee' ? 'cancelled' : '' ?>">
      <span><a href="seance.php?id=<?= (int)$s['id'] ?>"><strong><?= e(seance_label($s)) ?></strong></a> <?= seance_badge($s) ?><br>
        <small class="muted"><?= fdate($s['date'], true) ?> · <?= e($s['heure']) ?></small></span>
      <span>
        <?php if ($pr): echo $pr['statut'] === 'present' ? badge('Présent(e)', 'ok') : badge(($pr['statut'] === 'retard' ? 'En retard' : 'Absent(e)') . ($pr['excuse'] ? ' (excusé)' : ''), $pr['excuse'] ? 'info' : 'err'); endif; ?>
        <?php if (can('seances') && $s['statut'] === 'prevue'): ?><a class="btn btn-sm" href="seance.php?id=<?= (int)$s['id'] ?>">Faire l'appel</a><?php endif; ?>
      </span></li>
  <?php endforeach; ?></ul></section>

<?php if (can('seances')): ?>
<dialog id="dlg-extra"><form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="creer_extra">
  <h2>Séance extraordinaire</h2>
  <label>Titre<input name="titre" required placeholder="Ex. : Répétition générale du concert"></label>
  <label>Date<input type="date" name="date" required value="<?= date('Y-m-d') ?>"></label>
  <label>Heure<input type="time" name="heure" required value="<?= e(setting('heure_seance_samedi', '16:45')) ?>"></label>
  <label>Lieu (facultatif)<input name="lieu"></label>
  <div class="dlg-actions"><button type="button" class="btn btn-ghost" data-close>Annuler</button><button class="btn">Créer</button></div></form></dialog>
<?php endif;
page_end();
