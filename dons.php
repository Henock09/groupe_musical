<?php
require __DIR__ . '/inc/bootstrap.php';
$me = require_login();
$gere = can('dons');   // seuls les administrateurs autorisés voient les donateurs et enregistrent les dons

if (is_post()) {
    require_perm('dons');
    csrf_check();
    $a = $_POST['action'] ?? '';
    if ($a === 'ajouter') {
        $nature = ($_POST['nature'] ?? '') === 'nature' ? 'nature' : 'argent';
        $montant = $nature === 'argent' ? num($_POST['montant'] ?? 0) : null;
        $date = $_POST['date_don'] ?? '';
        $desc = trim($_POST['description'] ?? '');
        if ($nature === 'argent' && $montant <= 0) flash('Indiquez un montant supérieur à 0.', 'err');
        elseif ($nature === 'nature' && $desc === '') flash('Décrivez le don en nature (ex. : 2 micros).', 'err');
        elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) flash('Date invalide.', 'err');
        else {
            q('INSERT INTO dons (donateur, nature, montant, description, date_don, enregistre_par, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
              [trim($_POST['donateur'] ?? '') ?: 'Anonyme', $nature, $montant, mb_substr($desc, 0, 300), $date, $me['id'], date('c')]);
            flash('Don enregistré. Merci aux donateurs !');
        }
    } elseif ($a === 'supprimer') {
        q('DELETE FROM dons WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
        flash('Don supprimé.');
    }
    redirect('dons.php');
}

$total = (float)val("SELECT COALESCE(SUM(montant),0) FROM dons WHERE nature = 'argent'");
$nb = (int)val('SELECT COUNT(*) FROM dons');
$dons = $gere ? rows('SELECT d.* FROM dons d ORDER BY d.date_don DESC, d.id DESC') : [];
page_start('Dons', 'dons.php');
?>
<div class="cards">
  <div class="card stat"><span>Total des dons en argent</span><strong class="pos"><?= money($total) ?></strong></div>
  <div class="card stat"><span>Nombre de dons</span><strong><?= $nb ?></strong></div>
</div>
<?php if (!$gere): ?>
  <section class="panel"><p>Merci à toutes les personnes qui soutiennent le groupe. 🙏</p>
    <p class="muted small">Le détail des dons (donateurs, montants individuels) est réservé aux administrateurs.</p></section>
<?php else: ?>
<div class="toolbar"><span class="muted">Merci à toutes les personnes qui soutiennent le groupe.</span><button class="btn" data-open-dialog="dlg-don">+ Enregistrer un don</button></div>
<section class="panel">
  <?php if (!$dons): ?><p class="muted">Aucun don enregistré pour le moment.</p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Date</th><th>Donateur</th><th>Don</th><th></th></tr></thead><tbody>
    <?php foreach ($dons as $d): ?>
      <tr><td><?= fdate($d['date_don']) ?></td><td><?= e($d['donateur']) ?></td>
        <td><?= $d['nature'] === 'argent' ? '<strong>' . money($d['montant']) . '</strong>' . ($d['description'] ? ' · ' . e($d['description']) : '') : badge('En nature', 'info') . ' ' . e($d['description']) ?></td>
        <td class="r"><form method="post" data-confirm="Supprimer ce don ?"><?= csrf_field() ?><input type="hidden" name="action" value="supprimer"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="btn btn-ghost btn-sm">✕</button></form></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?></section>

<dialog id="dlg-don"><form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="ajouter">
  <h2>Enregistrer un don</h2>
  <label>Donateur (laisser vide = anonyme)<input name="donateur"></label>
  <label>Type de don<select name="nature" id="don-nature"><option value="argent">Argent</option><option value="nature">En nature (matériel…)</option></select></label>
  <label data-show-for="argent">Montant (<?= e(setting('devise')) ?>)<input name="montant" inputmode="decimal"></label>
  <label>Description <span class="muted">(obligatoire pour un don en nature)</span><input name="description" maxlength="300"></label>
  <label>Date<input type="date" name="date_don" value="<?= date('Y-m-d') ?>" required></label>
  <div class="dlg-actions"><button type="button" class="btn btn-ghost" data-close>Annuler</button><button class="btn">Enregistrer</button></div></form></dialog>
<?php endif; page_end();
