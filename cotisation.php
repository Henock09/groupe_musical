<?php
require __DIR__ . '/inc/bootstrap.php';
$me = require_login();
if (!can('cotisations') && !can('paiements')) { http_response_code(403); exit("Vous n'avez pas l'autorisation de consulter cette page."); }
$id = (int)($_GET['id'] ?? 0);
$c = row('SELECT * FROM cotisations WHERE id = ?', [$id]);
if (!$c) { http_response_code(404); exit('Cotisation introuvable.'); }
$url = "cotisation.php?id=$id";

if (is_post()) {
    csrf_check();
    $a = $_POST['action'] ?? '';
    $needs = in_array($a, ['encaisser', 'suppr_paiement'], true) ? 'paiements' : 'cotisations';
    if (!can($needs)) { flash("Vous n'avez pas l'autorisation d'effectuer cette action.", 'err'); redirect($url); }
    if ($a === 'encaisser') {
        $cm = row('SELECT cm.*, COALESCE((SELECT SUM(montant) FROM paiements WHERE cotisation_membre_id = cm.id),0) AS paye
                   FROM cotisation_membres cm WHERE cm.id = ? AND cm.cotisation_id = ?', [(int)($_POST['cm_id'] ?? 0), $id]);
        $montant = num($_POST['montant'] ?? 0);
        $date = $_POST['date_paiement'] ?? date('Y-m-d');
        if (!$cm) flash('Ligne introuvable.', 'err');
        elseif ($montant <= 0) flash('Le montant doit être supérieur à 0.', 'err');
        elseif ($montant > $cm['montant_du'] - $cm['paye'] + 0.001) flash('Ce montant dépasse le reste à payer (' . money($cm['montant_du'] - $cm['paye']) . ').', 'err');
        elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) flash('Date invalide.', 'err');
        else {
            q('INSERT INTO paiements (cotisation_membre_id, montant, date_paiement, note, enregistre_par, created_at) VALUES (?, ?, ?, ?, ?, ?)',
              [$cm['id'], $montant, $date, mb_substr(trim($_POST['note'] ?? ''), 0, 200), $me['id'], date('c')]);
            flash('Paiement de ' . money($montant) . ' enregistré.');
        }
    } elseif ($a === 'suppr_paiement') {
        q('DELETE FROM paiements WHERE id = ? AND cotisation_membre_id IN (SELECT id FROM cotisation_membres WHERE cotisation_id = ?)', [(int)($_POST['paiement_id'] ?? 0), $id]);
        flash('Paiement supprimé.');
    } elseif ($a === 'ajuster') {
        $montant = num($_POST['montant'] ?? -1);
        if ($montant < 0) flash('Montant invalide.', 'err');
        else { q('UPDATE cotisation_membres SET montant_du = ? WHERE id = ? AND cotisation_id = ?', [$montant, (int)($_POST['cm_id'] ?? 0), $id]); flash('Montant dû mis à jour.'); }
    } elseif ($a === 'retirer_ligne') {
        $cmId = (int)($_POST['cm_id'] ?? 0);
        if ((int)val('SELECT COUNT(*) FROM paiements WHERE cotisation_membre_id = ?', [$cmId]) > 0) flash("Impossible : ce membre a déjà payé. Supprimez d'abord ses paiements.", 'err');
        else { q('DELETE FROM cotisation_membres WHERE id = ? AND cotisation_id = ?', [$cmId, $id]); flash('Membre retiré de cette cotisation.'); }
    } elseif ($a === 'ajouter_manquants') {
        $n = q("INSERT INTO cotisation_membres (cotisation_id, user_id, montant_du)
                SELECT ?, id, ? FROM users WHERE statut = 'actif' AND id NOT IN (SELECT user_id FROM cotisation_membres WHERE cotisation_id = ?)",
               [$id, $c['montant'], $id])->rowCount();
        flash($n ? "$n membre(s) ajouté(s)." : 'Tous les membres actifs sont déjà inclus.');
    } elseif ($a === 'supprimer') {
        $paye = (float)val('SELECT COALESCE(SUM(p.montant),0) FROM paiements p JOIN cotisation_membres cm ON cm.id = p.cotisation_membre_id WHERE cm.cotisation_id = ?', [$id]);
        if ($paye > 0) { flash('Impossible de supprimer une cotisation qui a déjà des paiements.', 'err'); }
        else { q('DELETE FROM cotisations WHERE id = ?', [$id]); flash('Cotisation supprimée.'); redirect('cotisations.php'); }
    }
    redirect($url);
}

$lignes = rows("SELECT cm.id, cm.montant_du, u.id AS uid, u.nom, u.statut,
    COALESCE((SELECT SUM(montant) FROM paiements WHERE cotisation_membre_id = cm.id),0) AS paye
    FROM cotisation_membres cm JOIN users u ON u.id = cm.user_id WHERE cm.cotisation_id = ? ORDER BY u.nom COLLATE NOCASE", [$id]);
$paiements = [];
foreach (rows('SELECT p.* FROM paiements p JOIN cotisation_membres cm ON cm.id = p.cotisation_membre_id WHERE cm.cotisation_id = ? ORDER BY p.date_paiement, p.id', [$id]) as $p)
    $paiements[$p['cotisation_membre_id']][] = $p;
$du = array_sum(array_column($lignes, 'montant_du'));
$paye = array_sum(array_column($lignes, 'paye'));

page_start($c['titre'], 'cotisations.php');
?>
<p><a href="cotisations.php">← Toutes les cotisations</a></p>
<div class="cards">
  <div class="card stat"><span>Type</span><strong><?= $c['type'] === 'mensuelle' ? 'Mensuelle' : 'Extraordinaire' ?></strong><small>Échéance : <?= fdate($c['echeance']) ?></small></div>
  <div class="card stat"><span>Encaissé</span><strong class="pos"><?= money($paye) ?></strong><small>sur <?= money($du) ?> attendus</small></div>
  <div class="card stat"><span>Reste à encaisser</span><strong class="<?= $du - $paye > 0 ? 'neg' : 'pos' ?>"><?= money(max(0, $du - $paye)) ?></strong></div>
</div>
<?php if ($c['description']): ?><p class="muted"><?= e($c['description']) ?></p><?php endif; ?>

<div class="toolbar">
  <input type="search" placeholder="Rechercher un membre…" data-filter="tbl-lignes" aria-label="Rechercher">
  <?php if (can('cotisations')): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="ajouter_manquants"><button class="btn btn-ghost">Ajouter les membres actifs manquants</button></form><?php endif; ?>
</div>
<section class="panel"><div class="table-wrap"><table id="tbl-lignes">
  <thead><tr><th>Membre</th><th class="r">Dû</th><th class="r">Payé</th><th>État</th><th></th></tr></thead><tbody>
  <?php foreach ($lignes as $l): $reste = $l['montant_du'] - $l['paye']; ?>
    <tr>
      <td><a href="membre.php?id=<?= (int)$l['uid'] ?>"><?= e($l['nom']) ?></a><?= $l['statut'] === 'parti' ? ' ' . badge('parti', 'neutral') : '' ?>
        <?php if (!empty($paiements[$l['id']])): ?>
          <details class="pay-hist"><summary><?= count($paiements[$l['id']]) ?> paiement(s)</summary>
            <?php foreach ($paiements[$l['id']] as $p): ?>
              <div class="row"><span><?= fdate($p['date_paiement']) ?> — <?= money($p['montant']) ?><?= $p['note'] ? ' · ' . e($p['note']) : '' ?></span>
                <?php if (can('paiements')): ?><form method="post" data-confirm="Supprimer ce paiement ?"><?= csrf_field() ?><input type="hidden" name="action" value="suppr_paiement"><input type="hidden" name="paiement_id" value="<?= (int)$p['id'] ?>"><button class="link danger">supprimer</button></form><?php endif; ?></div>
            <?php endforeach; ?></details>
        <?php endif; ?></td>
      <td class="r"><?= money($l['montant_du']) ?></td><td class="r"><?= money($l['paye']) ?></td>
      <td><?= $reste <= 0 ? badge('Soldée', 'ok') : badge('Reste ' . money($reste), $l['paye'] > 0 ? 'warn' : 'err') ?></td>
      <td class="r nowrap">
        <?php if ($reste > 0 && can('paiements')): ?><button class="btn btn-sm" data-open-dialog="dlg-pay" data-cm="<?= (int)$l['id'] ?>" data-name="<?= e($l['nom']) ?>" data-reste="<?= e($reste) ?>">Encaisser</button><?php endif; ?>
        <?php if (can('cotisations')): ?><button class="btn btn-ghost btn-sm" data-open-dialog="dlg-adj" data-cm="<?= (int)$l['id'] ?>" data-name="<?= e($l['nom']) ?>" data-montant="<?= e($l['montant_du']) ?>">Ajuster</button><?php endif; ?>
        <?php if (!$l['paye'] && can('cotisations')): ?><form method="post" class="inline" data-confirm="Retirer <?= e($l['nom']) ?> de cette cotisation ?"><?= csrf_field() ?><input type="hidden" name="action" value="retirer_ligne"><input type="hidden" name="cm_id" value="<?= (int)$l['id'] ?>"><button class="btn btn-ghost btn-sm" title="Retirer">✕</button></form><?php endif; ?>
      </td></tr>
  <?php endforeach; ?></tbody></table></div></section>

<?php if ($paye == 0 && can('cotisations')): ?>
<form method="post" data-confirm="Supprimer définitivement cette cotisation ?"><?= csrf_field() ?><input type="hidden" name="action" value="supprimer"><button class="btn btn-danger btn-sm">Supprimer cette cotisation</button></form>
<?php endif; ?>

<?php if (can('paiements')): ?><dialog id="dlg-pay"><form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="encaisser"><input type="hidden" name="cm_id" data-fill="cm">
  <h2>Encaisser — <span data-text="name"></span></h2>
  <label>Montant<input name="montant" inputmode="decimal" required data-fill="reste"></label>
  <label>Date<input type="date" name="date_paiement" value="<?= date('Y-m-d') ?>" required></label>
  <label>Note (facultatif)<input name="note" maxlength="200" placeholder="Ex. : Mobile Money, espèces…"></label>
  <div class="dlg-actions"><button type="button" class="btn btn-ghost" data-close>Annuler</button><button class="btn">Enregistrer</button></div></form></dialog>

<?php endif; if (can('cotisations')): ?><dialog id="dlg-adj"><form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="ajuster"><input type="hidden" name="cm_id" data-fill="cm">
  <h2>Ajuster le montant dû — <span data-text="name"></span></h2>
  <p class="muted small">Utile pour une réduction ou une exemption (mettre 0).</p>
  <label>Montant dû<input name="montant" inputmode="decimal" required data-fill="montant"></label>
  <div class="dlg-actions"><button type="button" class="btn btn-ghost" data-close>Annuler</button><button class="btn">Enregistrer</button></div></form></dialog><?php endif; ?>
<?php page_end();
