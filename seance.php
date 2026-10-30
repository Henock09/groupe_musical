<?php
require __DIR__ . '/inc/bootstrap.php';
$me = require_login();
handle_signal_post($me);
$id = (int)($_GET['id'] ?? 0);
$s = row('SELECT * FROM seances WHERE id = ?', [$id]);
if (!$s) { http_response_code(404); exit('Séance introuvable.'); }
$url = "seance.php?id=$id";

if (is_post() && in_array($_POST['action'] ?? '', ['annuler', 'retablir', 'pointer'], true)) {
    require_perm('seances');
    csrf_check();
    $a = $_POST['action'];
    if ($a === 'annuler') {
        $motif = trim($_POST['motif'] ?? '');
        q("UPDATE seances SET statut = 'annulee', motif_annulation = ? WHERE id = ?", [mb_substr($motif, 0, 300), $id]);
        q("DELETE FROM penalites WHERE seance_id = ? AND statut != 'payee'", [$id]);
        q('DELETE FROM presences WHERE seance_id = ?', [$id]);
        flash("Séance annulée : aucune pénalité ne sera appliquée.");
    } elseif ($a === 'retablir') {
        q("UPDATE seances SET statut = 'prevue', motif_annulation = NULL WHERE id = ?", [$id]);
        flash('Séance rétablie.');
    } elseif ($a === 'pointer' && $s['statut'] !== 'annulee') {
        if (time() < seance_start($s)) { flash("L'appel n'est possible qu'à partir de l'heure de la séance.", 'err'); redirect($url); }
        db()->beginTransaction();
        $nb = 0;
        foreach ((array)($_POST['statut'] ?? []) as $uid => $st) {
            $uid = (int)$uid;
            if (!in_array($st, ['present', 'retard', 'absent'], true) || !val('SELECT 1 FROM users WHERE id = ?', [$uid])) continue;
            $excuse = $st !== 'present' && isset($_POST['excuse'][$uid]) ? 1 : 0;
            q('INSERT INTO presences (seance_id, user_id, statut, excuse) VALUES (?, ?, ?, ?)
               ON CONFLICT(seance_id, user_id) DO UPDATE SET statut = excluded.statut, excuse = excluded.excuse', [$id, $uid, $st, $excuse]);
            apply_penalty($id, $uid, $st, (bool)$excuse);
            $nb++;
        }
        q("UPDATE seances SET statut = 'terminee' WHERE id = ?", [$id]);
        db()->commit();
        flash("Appel enregistré ($nb membres). Les pénalités ont été générées pour les retards et absences non signalés.");
    }
    redirect($url);
}

$signals = [];
foreach (rows('SELECT * FROM signalements WHERE seance_id = ?', [$id]) as $g) $signals[$g['user_id']] = $g;
$started = time() >= seance_start($s);

page_start(seance_label($s), 'seances.php');
?>
<p><a href="seances.php">← Toutes les séances</a></p>
<section class="panel">
  <p><strong><?= fdate($s['date'], true) ?></strong> à <?= e($s['heure']) ?><?= $s['lieu'] ? ' · ' . e($s['lieu']) : '' ?>
    <?= $s['statut'] === 'annulee' ? badge('Annulée', 'err') : ($s['statut'] === 'terminee' ? badge('Appel fait', 'ok') : '') ?>
    <?= $s['type'] === 'extraordinaire' ? badge('Extraordinaire', 'warn') : '' ?></p>
  <?php if ($s['statut'] === 'annulee'): ?><p>Motif de l'annulation : <?= e($s['motif_annulation'] ?: 'non précisé') ?></p><?php endif; ?>
  <?php if ($s['statut'] === 'prevue'): ?><p><?php render_signal_box($s, (int)$me['id']); ?></p><?php endif; ?>

  <?php if (can('seances')): ?>
  <div class="row wrap">
    <?php if ($s['statut'] === 'annulee'): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="retablir"><button class="btn">Rétablir la séance</button></form>
    <?php else: ?>
      <form method="post" class="row wrap" data-confirm="Annuler cette séance ? Aucune pénalité ne sera appliquée."><?= csrf_field() ?><input type="hidden" name="action" value="annuler">
        <input name="motif" placeholder="Motif de l'annulation" maxlength="300" aria-label="Motif">
        <button class="btn btn-danger">Annuler la séance</button></form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</section>

<?php if (can('seances') && $s['statut'] !== 'annulee'):
    $membres = rows("SELECT u.id, u.nom FROM users u WHERE (u.statut = 'actif' AND u.date_arrivee <= ?)
                     OR u.id IN (SELECT user_id FROM presences WHERE seance_id = ?) ORDER BY u.nom COLLATE NOCASE", [$s['date'], $id]);
    $presences = array_column(rows('SELECT * FROM presences WHERE seance_id = ?', [$id]), null, 'user_id');
    ?>
<section class="panel"><h2>Appel et signalements</h2>
  <?php if (!$started): ?>
    <p class="muted">L'appel sera possible à partir de <?= e($s['heure']) ?>. Signalements reçus : <strong><?= count($signals) ?></strong>.</p>
    <ul class="list"><?php foreach ($membres as $m): if (!isset($signals[$m['id']])) continue; $g = $signals[$m['id']]; ?>
      <li><span><?= e($m['nom']) ?></span><span><?= badge($g['type'] === 'absence' ? 'Absence' : 'Retard', 'info') ?> <small class="muted"><?= e($g['motif']) ?></small></span></li><?php endforeach; ?></ul>
  <?php else: ?>
  <p class="muted small">Un retard ou une absence <strong>signalé à l'avance</strong> est automatiquement excusé (case cochée). Les autres retards/absences entraînent une pénalité.
     Vous pouvez excuser manuellement un membre en cochant la case.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="pointer">
  <div class="table-wrap"><table class="appel">
    <thead><tr><th>Membre</th><th>Signalement</th><th>Présence</th><th>Excusé</th></tr></thead><tbody>
    <?php foreach ($membres as $m):
        $uid = (int)$m['id']; $g = $signals[$uid] ?? null; $p = $presences[$uid] ?? null;
        $cur = $p['statut'] ?? ($g ? ($g['type'] === 'absence' ? 'absent' : 'retard') : 'present');
        $exc = $p ? (bool)$p['excuse'] : (bool)$g; ?>
      <tr><td><?= e($m['nom']) ?></td>
        <td><?= $g ? badge($g['type'] === 'absence' ? 'Absence signalée' : 'Retard signalé', 'info') . ($g['motif'] ? ' <small class="muted">' . e($g['motif']) . '</small>' : '') : '<span class="muted">—</span>' ?></td>
        <td class="seg">
          <?php foreach (['present' => 'Présent', 'retard' => 'Retard', 'absent' => 'Absent'] as $v => $l): ?>
            <label><input type="radio" name="statut[<?= $uid ?>]" value="<?= $v ?>" <?= $cur === $v ? 'checked' : '' ?>><span><?= $l ?></span></label>
          <?php endforeach; ?></td>
        <td><input type="checkbox" name="excuse[<?= $uid ?>]" value="1" <?= $exc ? 'checked' : '' ?> aria-label="Excusé"></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <p><button class="btn"><?= $s['statut'] === 'terminee' ? "Mettre à jour l'appel" : "Enregistrer l'appel" ?></button></p>
  </form>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php page_end();
