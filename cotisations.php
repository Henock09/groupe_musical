<?php
require __DIR__ . '/inc/bootstrap.php';
$me = require_login();

if (is_post()) {
    // Seuls les administrateurs autorisés peuvent créer des cotisations.
    require_perm('cotisations');
    csrf_check();
    if (($_POST['action'] ?? '') === 'creer') {
        $type = $_POST['type'] ?? '';
        $montant = num($_POST['montant'] ?? 0);
        $echeance = trim($_POST['echeance'] ?? '') ?: null;
        $desc = trim($_POST['description'] ?? '');
        $mois = null;
        $err = '';
        if (!in_array($type, ['mensuelle', 'extraordinaire'], true)) $err = 'Type de cotisation invalide.';
        elseif ($montant <= 0) $err = 'Le montant doit être supérieur à 0.';
        elseif ($echeance && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $echeance)) $err = "Date d'échéance invalide.";
        if ($type === 'mensuelle') {
            $mois = $_POST['mois'] ?? '';
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mois)) $err = $err ?: 'Mois invalide.';
            $titre = 'Cotisation ' . ($err ? '' : fmois($mois));
        } else {
            $titre = trim($_POST['titre'] ?? '');
            if ($titre === '') $err = $err ?: "Donnez un nom à la cotisation (ex. : Concert de Noël).";
        }
        $ids = array_map('intval', (array)($_POST['membres'] ?? []));
        if (!$err && !$ids) $err = 'Sélectionnez au moins un membre.';
        if ($err) { flash($err, 'err'); redirect('cotisations.php'); }

        try {
            db()->beginTransaction();
            q('INSERT INTO cotisations (titre, type, montant, mois, echeance, description, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
              [$titre, $type, $montant, $mois, $echeance, $desc, $me['id'], date('c')]);
            $cid = (int)db()->lastInsertId();
            $ins = db()->prepare("INSERT INTO cotisation_membres (cotisation_id, user_id, montant_du)
                                  SELECT ?, id, ? FROM users WHERE id = ? AND statut = 'actif'");
            foreach ($ids as $uid) $ins->execute([$cid, $montant, $uid]);
            db()->commit();
            flash('Cotisation créée pour ' . (int)val('SELECT COUNT(*) FROM cotisation_membres WHERE cotisation_id = ?', [$cid]) . ' membre(s).');
            redirect("cotisation.php?id=$cid");
        } catch (PDOException $ex) {
            if (db()->inTransaction()) db()->rollBack();
            flash($type === 'mensuelle' ? 'La cotisation de ce mois existe déjà.' : 'Erreur : ' . $ex->getMessage(), 'err');
            redirect('cotisations.php');
        }
    }
    redirect('cotisations.php');
}

page_start('Cotisations', 'cotisations.php');

if (can('cotisations') || can('paiements')):
    $liste = rows("SELECT c.*, COUNT(cm.id) AS nb, COALESCE(SUM(cm.montant_du),0) AS du,
        COALESCE((SELECT SUM(p.montant) FROM paiements p JOIN cotisation_membres x ON x.id = p.cotisation_membre_id WHERE x.cotisation_id = c.id),0) AS paye
        FROM cotisations c LEFT JOIN cotisation_membres cm ON cm.cotisation_id = c.id GROUP BY c.id ORDER BY c.created_at DESC, c.id DESC");
    $actifs = rows("SELECT id, nom FROM users WHERE statut = 'actif' ORDER BY nom COLLATE NOCASE");
    ?>
    <div class="toolbar"><span class="muted">Seuls les administrateurs autorisés peuvent créer des cotisations.</span>
      <?php if (can('cotisations')): ?><button class="btn" data-open-dialog="dlg-cotis">+ Nouvelle cotisation</button><?php endif; ?></div>
    <section class="panel">
    <?php if (!$liste): ?><p class="muted">Aucune cotisation. Créez la cotisation du mois ou une cotisation extraordinaire (concert, semaine de la musique…).</p><?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Cotisation</th><th>Échéance</th><th class="r">Par membre</th><th>Membres</th><th class="r">Encaissé</th><th>Avancement</th></tr></thead>
      <tbody>
      <?php foreach ($liste as $c): $pct = $c['du'] > 0 ? min(100, round($c['paye'] / $c['du'] * 100)) : 0; ?>
        <tr><td><a href="cotisation.php?id=<?= (int)$c['id'] ?>"><strong><?= e($c['titre']) ?></strong></a>
              <?= $c['type'] === 'extraordinaire' ? badge('Extraordinaire', 'warn') : badge('Mensuelle', 'neutral') ?></td>
            <td><?= fdate($c['echeance']) ?></td>
            <td class="r"><?= money($c['montant']) ?></td><td><?= (int)$c['nb'] ?></td>
            <td class="r"><?= money($c['paye']) ?> / <?= money($c['du']) ?></td>
            <td><div class="bar" title="<?= $pct ?> %"><i style="width:<?= $pct ?>%"></i></div></td></tr>
      <?php endforeach; ?></tbody></table></div>
    <?php endif; ?></section>

    <?php if (can('cotisations')): ?>
    <dialog id="dlg-cotis">
      <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="creer">
        <h2>Nouvelle cotisation</h2>
        <label>Type
          <select name="type" id="cotis-type"><option value="mensuelle">Mensuelle</option><option value="extraordinaire">Extraordinaire (concert, semaine de la musique…)</option></select></label>
        <label data-show-for="mensuelle">Mois<input type="month" name="mois" value="<?= date('Y-m') ?>"></label>
        <label data-show-for="extraordinaire" hidden>Nom de la cotisation<input name="titre" placeholder="Ex. : Semaine de la musique"></label>
        <label>Montant par membre (<?= e(setting('devise')) ?>)<input name="montant" inputmode="decimal" required value="<?= e(setting('cotisation_mensuelle')) ?>"></label>
        <label>Date limite (facultatif)<input type="date" name="echeance"></label>
        <label>Description (facultatif)<input name="description" maxlength="300"></label>
        <fieldset><legend>Membres concernés <button type="button" class="link" data-check-all="membres-box">tout cocher / décocher</button></legend>
          <div class="checks" id="membres-box">
            <?php foreach ($actifs as $a): ?><label class="check"><input type="checkbox" name="membres[]" value="<?= (int)$a['id'] ?>" checked> <?= e($a['nom']) ?></label><?php endforeach; ?>
          </div></fieldset>
        <div class="dlg-actions"><button type="button" class="btn btn-ghost" data-close>Annuler</button><button class="btn">Créer</button></div>
      </form>
    </dialog>
    <?php endif; ?>
    <?php
else:
    $lines = rows("SELECT c.titre, c.type, c.echeance, cm.montant_du,
        COALESCE((SELECT SUM(montant) FROM paiements WHERE cotisation_membre_id = cm.id),0) AS paye
        FROM cotisation_membres cm JOIN cotisations c ON c.id = cm.cotisation_id WHERE cm.user_id = ? ORDER BY c.created_at DESC", [$me['id']]);
    ?>
    <section class="panel"><h2>Mes cotisations</h2>
    <?php if (!$lines): ?><p class="muted">Aucune cotisation pour le moment.</p><?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Cotisation</th><th>Échéance</th><th class="r">Montant</th><th class="r">Payé</th><th>État</th></tr></thead><tbody>
      <?php foreach ($lines as $l): $reste = $l['montant_du'] - $l['paye']; ?>
        <tr><td><?= e($l['titre']) ?> <?= $l['type'] === 'extraordinaire' ? badge('Extraordinaire', 'warn') : '' ?></td><td><?= fdate($l['echeance']) ?></td>
            <td class="r"><?= money($l['montant_du']) ?></td><td class="r"><?= money($l['paye']) ?></td>
            <td><?= $reste <= 0 ? badge('Soldée', 'ok') : badge('Reste ' . money($reste), $l['paye'] > 0 ? 'warn' : 'err') ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
    <?php endif; ?></section>
    <?php
endif;
page_end();
