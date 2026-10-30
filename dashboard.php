<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
handle_signal_post($u);
ensure_seances();

$upcoming = rows("SELECT * FROM seances WHERE statut != 'annulee' AND (date || ' ' || heure) >= ? ORDER BY date, heure LIMIT 4", [date('Y-m-d H:i')]);

page_start(is_admin() ? 'Tableau de bord' : 'Mon espace', 'dashboard.php');

if (is_admin()) {
    $voirCotis = can('cotisations') || can('paiements');
    $voirPen = can('penalites') || can('paiements');
    $totalDu = (float)val('SELECT COALESCE(SUM(montant_du),0) FROM cotisation_membres');
    $totalPaye = (float)val('SELECT COALESCE(SUM(montant),0) FROM paiements');
    $amDues = (float)val("SELECT COALESCE(SUM(montant),0) FROM penalites WHERE statut = 'due'");
    $amPayees = (float)val("SELECT COALESCE(SUM(montant),0) FROM penalites WHERE statut = 'payee'");
    $dons = (float)val("SELECT COALESCE(SUM(montant),0) FROM dons WHERE nature = 'argent'");
    $caisse = $totalPaye + $amPayees + $dons;
    $enAttente = can('membres') ? (int)val('SELECT COUNT(*) FROM inscriptions') : 0;
    ?>
    <?php if ($enAttente): ?>
      <section class="panel attention"><strong><?= $enAttente ?> demande<?= $enAttente > 1 ? 's' : '' ?> d'inscription</strong> en attente de validation.
        <a class="btn btn-sm" href="membres.php">Voir les demandes</a></section>
    <?php endif; ?>
    <div class="cards">
      <?php if (can('membres')): ?><div class="card stat"><span>Membres actifs</span><strong><?= (int)val("SELECT COUNT(*) FROM users WHERE statut = 'actif'") ?></strong></div><?php endif; ?>
      <?php if (can('paiements') && can('dons')): ?>
      <div class="card stat"><span>En caisse (encaissé)</span><strong class="pos"><?= money($caisse) ?></strong>
        <small>Cotisations <?= money($totalPaye) ?> · Pénalités <?= money($amPayees) ?> · Dons <?= money($dons) ?></small></div>
      <?php endif; ?>
      <?php if ($voirCotis): ?><div class="card stat"><span>Cotisations restant à encaisser</span><strong class="<?= $totalDu - $totalPaye > 0 ? 'neg' : '' ?>"><?= money(max(0, $totalDu - $totalPaye)) ?></strong></div><?php endif; ?>
      <?php if ($voirPen): ?><div class="card stat"><span>Pénalités à encaisser</span><strong class="<?= $amDues > 0 ? 'neg' : '' ?>"><?= money($amDues) ?></strong></div><?php endif; ?>
      <?php if (can('dons') && !can('paiements')): ?><div class="card stat"><span>Dons en argent</span><strong class="pos"><?= money($dons) ?></strong></div><?php endif; ?>
    </div>

    <?php if (can('seances')):
        $aPointer = rows("SELECT * FROM seances WHERE statut = 'prevue' AND (date || ' ' || heure) < ? ORDER BY date DESC LIMIT 5", [date('Y-m-d H:i')]);
        if ($aPointer): ?>
    <section class="panel attention"><h2>Séances à pointer</h2>
      <p class="muted">Ces séances sont passées : faites l'appel pour générer les pénalités éventuelles.</p>
      <ul class="list">
      <?php foreach ($aPointer as $s): ?>
        <li><span><strong><?= e(seance_label($s)) ?></strong> — <?= fdate($s['date'], true) ?></span>
            <a class="btn btn-sm" href="seance.php?id=<?= (int)$s['id'] ?>">Faire l'appel</a></li>
      <?php endforeach; ?></ul></section>
    <?php endif; endif; ?>

    <div class="grid2">
      <section class="panel"><h2>Prochaines séances</h2>
        <?php if (!$upcoming): ?><p class="muted">Aucune séance à venir.</p><?php endif; ?>
        <ul class="list">
        <?php foreach ($upcoming as $s): ?>
          <li class="stack"><span><strong><?= e(seance_label($s)) ?></strong><br><small class="muted"><?= fdate($s['date'], true) ?> · <?= e($s['heure']) ?></small></span>
              <span><?php render_signal_box($s, (int)$u['id']); ?> <a class="btn btn-ghost btn-sm" href="seance.php?id=<?= (int)$s['id'] ?>">Ouvrir</a></span></li>
        <?php endforeach; ?></ul></section>
      <?php if (can('paiements')):
        $debiteurs = rows("SELECT u.id, u.nom,
          COALESCE((SELECT SUM(montant_du) FROM cotisation_membres WHERE user_id = u.id),0)
          - COALESCE((SELECT SUM(p.montant) FROM paiements p JOIN cotisation_membres cm ON cm.id = p.cotisation_membre_id WHERE cm.user_id = u.id),0)
          + COALESCE((SELECT SUM(montant) FROM penalites WHERE user_id = u.id AND statut = 'due'),0) AS reste
          FROM users u WHERE u.statut = 'actif' ORDER BY reste DESC LIMIT 6");
        $debiteurs = array_filter($debiteurs, fn($d) => $d['reste'] > 0); ?>
      <section class="panel"><h2>Soldes les plus élevés</h2>
        <ul class="list">
        <?php foreach ($debiteurs as $d): ?>
          <li><?= can('membres') ? '<a href="membre.php?id=' . (int)$d['id'] . '">' . e($d['nom']) . '</a>' : e($d['nom']) ?><strong class="neg"><?= money($d['reste']) ?></strong></li>
        <?php endforeach; ?>
        <?php if (!$debiteurs): ?><li class="muted">Tout le monde est à jour 🎉</li><?php endif; ?>
        </ul></section>
      <?php endif; ?>
    </div>
    <h2>Ma situation personnelle</h2>
    <?php
    render_situation((int)$u['id']);
} else {
    ?>
    <section class="panel"><h2>Prochaines séances</h2>
      <p class="muted small">Prévenez <strong>avant le début</strong> de la séance si vous serez en retard ou absent(e) : sinon, une pénalité s'applique
        (retard : <?= money(setting('amende_retard')) ?> · absence : <?= money(setting('amende_absence')) ?>).</p>
      <?php if (!$upcoming): ?><p class="muted">Aucune séance à venir.</p><?php endif; ?>
      <ul class="list">
      <?php foreach ($upcoming as $s): ?>
        <li class="stack"><span><strong><?= e(seance_label($s)) ?></strong>
          <?= $s['type'] === 'extraordinaire' ? badge('Extraordinaire', 'warn') : '' ?><br>
          <small class="muted"><?= fdate($s['date'], true) ?> · <?= e($s['heure']) ?><?= $s['lieu'] ? ' · ' . e($s['lieu']) : '' ?></small></span>
          <?php render_signal_box($s, (int)$u['id']); ?></li>
      <?php endforeach; ?></ul></section>
    <?php
    render_situation((int)$u['id']);
}
page_end();
