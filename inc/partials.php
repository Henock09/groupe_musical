<?php
/** Traite les actions « signaler » / « retirer » d'un membre (pages dashboard et séances). */
function handle_signal_post(array $u): void {
    $a = $_POST['action'] ?? '';
    if (!is_post() || !in_array($a, ['signaler', 'retirer_signal'], true)) return;
    csrf_check();
    $back = $_SERVER['REQUEST_URI'];
    $s = row('SELECT * FROM seances WHERE id = ?', [(int)($_POST['seance_id'] ?? 0)]);
    if (!$s || !seance_open_for_signal($s)) {
        flash('Les signalements sont fermés pour cette séance (elle a commencé, ou elle est annulée).', 'err');
        redirect($back);
    }
    if ($a === 'retirer_signal') {
        q('DELETE FROM signalements WHERE seance_id = ? AND user_id = ?', [$s['id'], $u['id']]);
        flash('Signalement retiré.');
        redirect($back);
    }
    $type = $_POST['type'] ?? '';
    if (!in_array($type, ['retard', 'absence'], true)) { flash('Type de signalement invalide.', 'err'); redirect($back); }
    $motif = trim((string)($_POST['motif'] ?? ''));
    q('INSERT INTO signalements (seance_id, user_id, type, motif, created_at) VALUES (?, ?, ?, ?, ?)
       ON CONFLICT(seance_id, user_id) DO UPDATE SET type = excluded.type, motif = excluded.motif',
      [$s['id'], $u['id'], $type, mb_substr($motif, 0, 300), date('c')]);
    flash($type === 'absence' ? 'Absence signalée. Merci de prévenir à temps !' : 'Retard signalé. Merci de prévenir à temps !');
    redirect($back);
}

/** Bloc « Je signale mon retard / mon absence » pour une séance. */
function render_signal_box(array $s, int $uid): void {
    $sig = row('SELECT * FROM signalements WHERE seance_id = ? AND user_id = ?', [$s['id'], $uid]);
    $open = seance_open_for_signal($s);
    if ($sig): ?>
      <div class="signal done">
        <?= badge($sig['type'] === 'absence' ? 'Absence signalée' : 'Retard signalé', 'info') ?>
        <?php if ($sig['motif']): ?><span class="muted">« <?= e($sig['motif']) ?> »</span><?php endif; ?>
        <?php if ($open): ?>
          <form method="post" class="inline"><?= csrf_field() ?>
            <input type="hidden" name="action" value="retirer_signal"><input type="hidden" name="seance_id" value="<?= (int)$s['id'] ?>">
            <button class="btn btn-ghost btn-sm">Retirer</button></form>
        <?php endif; ?>
      </div>
    <?php elseif ($open): ?>
      <form method="post" class="signal">
        <?= csrf_field() ?><input type="hidden" name="action" value="signaler"><input type="hidden" name="seance_id" value="<?= (int)$s['id'] ?>">
        <select name="type" aria-label="Type"><option value="retard">Je serai en retard</option><option value="absence">Je serai absent(e)</option></select>
        <input name="motif" maxlength="300" placeholder="Motif (facultatif)" aria-label="Motif">
        <button class="btn btn-sm">Signaler</button>
      </form>
    <?php else: ?>
      <span class="muted small">Signalement fermé</span>
    <?php endif;
}

/** Situation complète d'un membre : cotisations, amendes, présences. */
function render_situation(int $uid, bool $showCotis = true, bool $showPen = true): void {
    $s = situation($uid);
    $lines = rows("SELECT c.titre, c.type, c.mois, c.echeance, cm.id AS cmid, cm.montant_du,
                   COALESCE((SELECT SUM(montant) FROM paiements WHERE cotisation_membre_id = cm.id),0) AS paye
                   FROM cotisation_membres cm JOIN cotisations c ON c.id = cm.cotisation_id
                   WHERE cm.user_id = ? ORDER BY c.created_at DESC, c.id DESC", [$uid]);
    $pen = rows("SELECT p.*, s.date, s.type AS stype, s.titre FROM penalites p JOIN seances s ON s.id = p.seance_id
                 WHERE p.user_id = ? ORDER BY s.date DESC", [$uid]);
    $att = rows("SELECT pr.statut AS statut, COUNT(*) n FROM presences pr JOIN seances s ON s.id = pr.seance_id
                 WHERE pr.user_id = ? AND s.statut != 'annulee' GROUP BY pr.statut", [$uid]);
    $att = array_column($att, 'n', 'statut');
    ?>
    <div class="cards">
      <?php if ($showCotis): ?><div class="card stat"><span>Cotisations à régler</span><strong class="<?= $s['cotis_reste'] > 0 ? 'neg' : 'pos' ?>"><?= money($s['cotis_reste']) ?></strong></div><?php endif; ?>
      <?php if ($showPen): ?><div class="card stat"><span>Pénalités dues</span><strong class="<?= $s['amendes_dues'] > 0 ? 'neg' : 'pos' ?>"><?= money($s['amendes_dues']) ?></strong></div><?php endif; ?>
      <div class="card stat"><span>Présences / retards / absences</span><strong><?= (int)($att['present'] ?? 0) ?> / <?= (int)($att['retard'] ?? 0) ?> / <?= (int)($att['absent'] ?? 0) ?></strong></div>
    </div>

    <?php if ($showCotis): ?><section class="panel"><h2>Cotisations</h2>
    <?php if (!$lines): ?><p class="muted">Aucune cotisation pour le moment.</p><?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Cotisation</th><th>Échéance</th><th class="r">Montant</th><th class="r">Payé</th><th>État</th></tr></thead>
      <tbody>
      <?php foreach ($lines as $l): $reste = $l['montant_du'] - $l['paye']; ?>
        <tr><td><?= e($l['titre']) ?> <?= $l['type'] === 'extraordinaire' ? badge('Extraordinaire', 'warn') : '' ?></td>
            <td><?= fdate($l['echeance']) ?></td>
            <td class="r"><?= money($l['montant_du']) ?></td><td class="r"><?= money($l['paye']) ?></td>
            <td><?= $reste <= 0 ? badge('Soldée', 'ok') : badge('Reste ' . money($reste), $l['paye'] > 0 ? 'warn' : 'err') ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
    <?php endif; ?></section><?php endif; ?>

    <?php if ($showPen): ?><section class="panel"><h2>Pénalités</h2>
    <?php if (!$pen): ?><p class="muted">Aucune pénalité. Bravo pour l'assiduité !</p><?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Séance</th><th>Motif</th><th class="r">Montant</th><th>État</th></tr></thead>
      <tbody>
      <?php foreach ($pen as $p): ?>
        <tr><td><?= fdate($p['date'], true) ?></td><td><?= $p['type'] === 'absence' ? 'Absence non signalée' : 'Retard non signalé' ?></td>
            <td class="r"><?= money($p['montant']) ?></td>
            <td><?= $p['statut'] === 'payee' ? badge('Payée', 'ok') : ($p['statut'] === 'annulee' ? badge('Annulée', 'neutral') : badge('À payer', 'err')) ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
    <?php endif; ?></section><?php endif; ?>
    <?php
}
