<?php
require __DIR__ . '/inc/bootstrap.php';
if ((int)val('SELECT COUNT(*) FROM users') > 0) { redirect('login.php'); }

$err = [];
if (is_post()) {
    csrf_check();
    $groupe = trim($_POST['groupe'] ?? '');
    $nom = trim($_POST['nom'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $pw = (string)($_POST['password'] ?? '');
    if ($groupe === '') $err[] = 'Indiquez le nom du groupe.';
    if ($nom === '') $err[] = 'Indiquez votre nom.';
    if (!valid_email($email)) $err[] = 'Indiquez une adresse e-mail valide (elle servira à récupérer votre mot de passe).';
    if (!preg_match('/^[A-Za-z0-9._-]{3,30}$/', $username)) $err[] = "Identifiant : 3 à 30 caractères (lettres, chiffres, . _ -).";
    if (strlen($pw) < 8) $err[] = 'Le mot de passe doit contenir au moins 8 caractères.';
    if ($pw !== ($_POST['password2'] ?? '')) $err[] = 'Les deux mots de passe ne correspondent pas.';
    if (!$err) {
        q("INSERT INTO users (nom, username, email, password_hash, role, statut, date_arrivee, created_at) VALUES (?, ?, ?, ?, 'owner', 'actif', ?, ?)",
          [$nom, $username, $email, password_hash($pw, PASSWORD_DEFAULT), date('Y-m-d'), date('c')]);
        set_setting('nom_groupe', $groupe);
        set_setting('seances_debut', date('Y-m-d'));
        flash('Installation terminée. Connectez-vous avec votre compte.');
        redirect('login.php');
    }
}
page_start('Installation');
?>
<div class="auth-card">
  <h1>Bienvenue 🎶</h1>
  <p class="muted">Créez le compte de l'<strong>administrateur principal</strong> (propriétaire de l'application).</p>
  <?php foreach ($err as $m): ?><div class="flash flash-err"><?= e($m) ?></div><?php endforeach; ?>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <label>Nom du groupe<input name="groupe" required value="<?= e($_POST['groupe'] ?? '') ?>"></label>
    <label>Votre nom complet<input name="nom" required value="<?= e($_POST['nom'] ?? '') ?>"></label>
    <label>Adresse e-mail (pour récupérer votre mot de passe)<input type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>"></label>
    <label>Identifiant de connexion<input name="username" required autocomplete="username" value="<?= e($_POST['username'] ?? '') ?>"></label>
    <label>Mot de passe (8 caractères min.)<input type="password" name="password" required autocomplete="new-password"></label>
    <label>Confirmer le mot de passe<input type="password" name="password2" required autocomplete="new-password"></label>
    <button class="btn">Créer l'application</button>
  </form>
</div>
<?php page_end();
