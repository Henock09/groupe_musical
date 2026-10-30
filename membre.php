<?php
require __DIR__ . '/inc/bootstrap.php';
$me = require_perm('membres');
$id = (int)($_GET['id'] ?? 0);
$m = row('SELECT * FROM users WHERE id = ?', [$id]);
if (!$m) { http_response_code(404); exit('Membre introuvable.'); }

// Un administrateur simple ne gère que les membres ; l'administrateur principal gère tout le monde.
$canManage = $m['role'] === 'membre' || is_owner();
if ($m['role'] === 'owner') $canManage = ((int)$m['id'] === (int)$me['id']);

if (is_post()) {
    csrf_check();
    $a = $_POST['action'] ?? '';
    $self = (int)$m['id'] === (int)$me['id'];
    if (!$canManage) { flash("Vous n'avez pas le droit de modifier ce compte.", 'err'); redirect("membre.php?id=$id"); }

    if ($a === 'modifier') {
        $nom = trim($_POST['nom'] ?? '');
        $arr = $_POST['date_arrivee'] ?? '';
        $email = trim($_POST['email'] ?? '');
        if ($nom === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $arr)) flash('Nom ou date invalide.', 'err');
        elseif ($email !== '' && !valid_email($email)) flash('Adresse e-mail invalide.', 'err');
        elseif ($email !== '' && email_taken($email, $id)) flash('Cette adresse e-mail est déjà utilisée.', 'err');
        else { q('UPDATE users SET nom = ?, telephone = ?, email = ?, date_arrivee = ? WHERE id = ?', [$nom, trim($_POST['telephone'] ?? ''), $email ?: null, $arr, $id]); flash('Informations enregistrées.'); }
    } elseif ($a === 'parti' && !$self && $m['role'] !== 'owner') {
        q("UPDATE users SET statut = 'parti', date_depart = ?, role = CASE WHEN role = 'admin' THEN 'membre' ELSE role END WHERE id = ?", [date('Y-m-d'), $id]);
        flash($m['nom'] . ' est marqué(e) comme parti(e). Son historique est conservé.');
    } elseif ($a === 'reactiver' && $m['role'] !== 'owner') {
        q("UPDATE users SET statut = 'actif', date_depart = NULL WHERE id = ?", [$id]);
        flash($m['nom'] . ' est de nouveau actif(ve).');
    } elseif ($a === 'reset_pw') {
        $pw = random_password();
        q('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $id]);
        flash("Nouveau mot de passe provisoire pour « {$m['username']} » : $pw (à communiquer au membre ; il devra le changer).", 'warn');
    }
    redirect("membre.php?id=$id");
}

page_start($m['nom'], 'membres.php');
?>
<p><a href="membres.php">← Tous les membres</a></p>
<div class="grid2">
<section class="panel"><h2>Informations</h2>
  <?php if ($canManage): ?>
  <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="modifier">
    <label>Nom complet<input name="nom" required value="<?= e($m['nom']) ?>"></label>
    <label>Téléphone<input name="telephone" value="<?= e($m['telephone']) ?>"></label>
    <label>Adresse e-mail (récupération du mot de passe)<input type="email" name="email" value="<?= e($m['email']) ?>"></label>
    <label>Date d'arrivée<input type="date" name="date_arrivee" required value="<?= e($m['date_arrivee']) ?>"></label>
    <button class="btn">Enregistrer</button>
  </form>
  <?php else: ?>
    <p><?= e($m['nom']) ?><br><span class="muted"><?= e($m['telephone']) ?></span></p>
    <p class="muted small">Seul l'administrateur principal peut modifier ce compte.</p>
  <?php endif; ?>
</section>
<section class="panel"><h2>Compte</h2>
  <p>Identifiant : <strong><?= e($m['username']) ?></strong><br>
     Statut : <?= $m['statut'] === 'actif' ? badge('Actif', 'ok') : badge('Parti le ' . fdate($m['date_depart']), 'neutral') ?>
     · <?= $m['role'] === 'owner' ? 'Administrateur principal' : ($m['role'] === 'admin' ? 'Administrateur' : 'Membre') ?></p>
  <?php if ($canManage): ?>
  <div class="row wrap">
    <form method="post" data-confirm="Générer un nouveau mot de passe provisoire pour ce compte ?"><?= csrf_field() ?><input type="hidden" name="action" value="reset_pw"><button class="btn btn-ghost">Réinitialiser le mot de passe</button></form>
    <?php if ($m['role'] !== 'owner' && (int)$m['id'] !== (int)$me['id']): ?>
      <?php if ($m['statut'] === 'actif'): ?>
        <form method="post" data-confirm="Marquer ce membre comme parti ? Il ne pourra plus se connecter ; son historique est conservé."><?= csrf_field() ?><input type="hidden" name="action" value="parti"><button class="btn btn-danger">Marquer comme parti</button></form>
      <?php else: ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reactiver"><button class="btn">Réactiver</button></form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</section>
</div>
<?php render_situation($id, can('cotisations') || can('paiements'), can('penalites') || can('paiements')); page_end();
