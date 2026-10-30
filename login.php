<?php
require __DIR__ . '/inc/bootstrap.php';
if ((int)val('SELECT COUNT(*) FROM users') === 0) redirect('install.php');
if (current_user()) redirect('dashboard.php');

$err = '';
if (is_post()) {
    csrf_check();
    if (login_blocked()) {
        $err = 'Trop de tentatives. Réessayez dans 15 minutes.';
    } elseif (try_login(trim($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''))) {
        redirect('dashboard.php');
    } else {
        $err = 'Identifiant ou mot de passe incorrect (ou compte désactivé).';
    }
}
page_start('Connexion');
?>
<div class="auth-card">
  <h1><?= e(setting('nom_groupe')) ?></h1>
  <p class="muted">Connectez-vous pour accéder à l'espace du groupe.</p>
  <?php if ($err): ?><div class="flash flash-err"><?= e($err) ?></div><?php endif; ?>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <label>Identifiant<input name="username" required autofocus autocomplete="username"></label>
    <label>Mot de passe<input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn">Se connecter</button>
  </form>
  <p class="small"><a href="mot-de-passe-oublie.php">Mot de passe oublié ?</a></p>
  <?php if (setting('inscription_active') === '1'): ?><p class="muted small">Pas encore de compte ? <a href="inscription.php">Créer un compte</a></p><?php endif; ?>
</div>
<?php page_end();
