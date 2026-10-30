<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
// Un simple membre ne peut modifier que son mot de passe (et ajouter son e-mail de récupération s'il n'en a pas).
// Les administrateurs peuvent aussi modifier leurs coordonnées.
$peutInfos = is_admin();
$peutEmail = is_admin() || (string)$u['email'] === '';

if (is_post()) {
    csrf_check();
    $a = $_POST['action'] ?? '';
    if ($a === 'infos' && $peutInfos) {
        $nom = trim($_POST['nom'] ?? '');
        if ($nom === '') flash('Le nom ne peut pas être vide.', 'err');
        else { q('UPDATE users SET nom = ?, telephone = ? WHERE id = ?', [$nom, trim($_POST['telephone'] ?? ''), $u['id']]); flash('Profil mis à jour.'); }
    } elseif ($a === 'email' && $peutEmail) {
        $email = trim($_POST['email'] ?? '');
        if (!password_verify((string)($_POST['pw'] ?? ''), $u['password_hash'])) flash('Mot de passe actuel incorrect.', 'err');
        elseif (!valid_email($email)) flash('Adresse e-mail invalide.', 'err');
        elseif (email_taken($email, (int)$u['id'])) flash('Cette adresse e-mail est déjà utilisée.', 'err');
        else { q('UPDATE users SET email = ? WHERE id = ?', [$email, $u['id']]); flash('Adresse e-mail enregistrée.'); }
    } elseif ($a === 'password') {
        $new = (string)($_POST['new'] ?? '');
        if (!password_verify((string)($_POST['old'] ?? ''), $u['password_hash'])) flash('Mot de passe actuel incorrect.', 'err');
        elseif (strlen($new) < 8) flash('Le nouveau mot de passe doit contenir au moins 8 caractères.', 'err');
        elseif ($new !== ($_POST['new2'] ?? '')) flash('Les deux mots de passe ne correspondent pas.', 'err');
        else {
            q('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
            flash('Mot de passe modifié.');
        }
    }
    redirect('profil.php');
}
page_start('Mon profil', 'profil.php');
?>
<div class="grid2">
<section class="panel"><h2>Mes informations</h2>
  <?php if ($peutInfos): ?>
  <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="infos">
    <label>Nom complet<input name="nom" required value="<?= e($u['nom']) ?>"></label>
    <label>Téléphone<input name="telephone" value="<?= e($u['telephone']) ?>"></label>
    <label>Identifiant<input value="<?= e($u['username']) ?>" disabled></label>
    <button class="btn">Enregistrer</button></form>
  <?php else: ?>
    <p><strong><?= e($u['nom']) ?></strong><br><span class="muted">Identifiant : <?= e($u['username']) ?><?= $u['telephone'] ? ' · ' . e($u['telephone']) : '' ?></span></p>
    <p class="muted small">Pour corriger votre nom ou votre téléphone, contactez un administrateur.</p>
  <?php endif; ?>
  <hr>
  <h2>Adresse e-mail de récupération</h2>
  <?php if ($peutEmail): ?>
    <p class="muted small">Elle sert à recevoir le lien si vous oubliez votre mot de passe.<?= $u['email'] ? ' Actuelle : ' . e($u['email']) : '' ?></p>
    <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="email">
      <label>Adresse e-mail<input type="email" name="email" required value="<?= e($u['email']) ?>"></label>
      <label>Mot de passe actuel (par sécurité)<input type="password" name="pw" required autocomplete="current-password"></label>
      <button class="btn"><?= $u['email'] ? "Changer l'adresse" : "Enregistrer l'adresse" ?></button></form>
  <?php else: ?>
    <p class="muted"><?= e($u['email']) ?></p>
    <p class="muted small">Pour la modifier, contactez un administrateur.</p>
  <?php endif; ?>
</section>
<section class="panel"><h2>Changer mon mot de passe</h2>
  <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="password">
    <label>Mot de passe actuel<input type="password" name="old" required autocomplete="current-password"></label>
    <label>Nouveau mot de passe (8 caractères min.)<input type="password" name="new" required autocomplete="new-password"></label>
    <label>Confirmer<input type="password" name="new2" required autocomplete="new-password"></label>
    <button class="btn">Modifier</button></form></section>
</div>
<?php page_end();
