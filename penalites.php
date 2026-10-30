<?php
require __DIR__ . '/inc/bootstrap.php';
$me = require_login();

$voirTout = can('penalites') || can('paiements');
if (is_post()) {
    csrf_check();
    $p = row('SELECT * FROM penalites WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
    $a = $_POST['action'] ?? '';
    if ($p && !can($a === 'payer' ? 'paiements' : 'penalites')) { flash("Vous n'avez pas l'autorisation d'effectuer cette action.", 'err'); }
    elseif ($p) {
        if ($a === 'payer') { q("UPDATE penalites SET statut = 'payee', date_paiement = ? WHERE id = ?", [date('Y-m-d'), $p['id']]); flash('Pénalité marquée comme payée.'); }
        elseif ($a === 'annuler') { q("UPDATE penalites SET statut = 'annulee' WHERE id = ?", [$p['id']]); flash('Pénalité annulée (pardonnée).'); }
        elseif ($a === 'retablir') { q("UPDATE penalites SET statut = 'due', date_paiement = NULL WHERE id = ?", [$p['id']]); flash('Pénalité rétablie.'); }
    }
    redirect('penalites.php?f=' . urlencode($_GET['f'] ?? 'due'));
}

$f = $_GET['f'] ?? 'due';
$where = ['due' => "p.statut = 'due'", 'payee' => "p.statut = 'payee'", 'annulee' => "p.statut = 'annulee'"][$f] ?? '1=1';
$params = [];
if (!$voirTout) { $where .= ' AND p.user_id = ?'; $params[] = $me['id']; }
$liste = rows("SELECT p.*, u.nom, s.date, s.heure, s.type AS stype, s.titre FROM penalites p
    JOIN users u ON u.id = p.user_id JOIN seances s ON s.id = p.seance_id WHERE $where ORDER BY s.date DESC, u.nom COLLATE NOCASE", $params);
$total = array_sum(array_column($liste, 'montant'));

page_start('Pénalités', 'penalites.php');
?>
<p class="muted">Les pénalités sanctionnent un <strong>retard</strong> (<?= money(setting('amende_retard')) ?>) ou une <strong>absence</strong> (<?= money(setting('amende_absence')) ?>)
  <strong>non signalé à l'avance</strong>. Elles servent la discipline du groupe ; un administrateur peut les annuler en cas de force majeure.</p>
<div class="toolbar">
  <div class="tabs"><?php foreach (['due' => 'À payer', 'payee' => 'Payées', 'annulee' => 'Annulées', 'tout' => 'Toutes'] as $k => $l): ?>
    <a href="?f=<?= $k ?>" class="<?= $f === $k ? 'on' : '' ?>"><?= $l ?></a><?php endforeach; ?></div>
  <?php if ($voirTout): ?><input type="search" placeholder="Rechercher un membre…" data-filter="tbl-pen" aria-label="Rechercher"><?php endif; ?>
  <strong>Total : <?= money($total) ?></strong>
</div>
<section class="panel"><div class="table-wrap"><table id="tbl-pen">
  <thead><tr><?php if ($voirTout): ?><th>Membre</th><?php endif; ?><th>Séance</th><th>Motif</th><th class="r">Montant</th><th>État</th><?php if ($voirTout): ?><th></th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($liste as $p): ?>
    <tr><?php if ($voirTout): ?><td><a href="membre.php?id=<?= (int)$p['user_id'] ?>"><?= e($p['nom']) ?></a></td><?php endif; ?>
      <td><a href="seance.php?id=<?= (int)$p['seance_id'] ?>"><?= fdate($p['date'], true) ?></a></td>
      <td><?= $p['type'] === 'absence' ? 'Absence non signalée' : 'Retard non signalé' ?></td>
      <td class="r"><?= money($p['montant']) ?></td>
      <td><?= $p['statut'] === 'payee' ? badge('Payée le ' . fdate($p['date_paiement']), 'ok') : ($p['statut'] === 'annulee' ? badge('Annulée', 'neutral') : badge('À payer', 'err')) ?></td>
      <?php if ($voirTout): ?><td class="r nowrap">
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
        <?php if ($p['statut'] === 'due'): ?>
          <?php if (can('paiements')): ?><button class="btn btn-sm" name="action" value="payer">Marquer payée</button><?php endif; ?>
          <?php if (can('penalites')): ?><button class="btn btn-ghost btn-sm" name="action" value="annuler">Annuler</button><?php endif; ?>
        <?php elseif (can('penalites')): ?><button class="btn btn-ghost btn-sm" name="action" value="retablir">Rétablir</button><?php endif; ?>
        </form></td><?php endif; ?></tr>
  <?php endforeach; ?>
  <?php if (!$liste): ?><tr><td colspan="6" class="muted">Aucune pénalité dans cette catégorie.</td></tr><?php endif; ?>
  </tbody></table></div></section>
<?php page_end();
