<?php
require __DIR__ . '/inc/bootstrap.php';
if (current_user()) redirect('dashboard.php');

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$reset = preg_match('/^[0-9a-f]{64}$/', $token)
    ? row('SELECT * FROM password_resets WHERE token_hash = ? AND expires_at > ?', [hash('sha256', $token), time()]) : null;
$err = '';

if ($reset && is_post()) {
    csrf_check();
    $pw = (string)($_POST['password'] ?? '');
    if (strlen($pw) < 8) $err = 'Le mot de passe doit contenir au moins 8 caractères.';
    elseif ($pw !== ($_POST['password2'] ?? '')) $err = 'Les deux mots de passe ne correspondent pas.';
    else {
        q('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $reset['user_id']]);
        q('DELETE FROM password_resets WHERE user_id = ?', [$reset['user_id']]);
        flash('Mot de passe modifié. Vous pouvez vous connecter.');
        redirect('login.php');
    }
}
page_start('Nouveau mot de passe');
?>
<div class="auth-card">
  <h1>Nouveau mot de passe</h1>
  <?php if (!$reset): ?>
    <div class="flash flash-err">Ce lien est invalide ou a expiré.</div>
    <p><a href="mot-de-passe-oublie.php">Demander un nouveau lien</a></p>
  <?php else: ?>
    <?php if ($err): ?><div class="flash flash-err"><?= e($err) ?></div><?php endif; ?>
    <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
      <label>Nouveau mot de passe (8 caractères min.)<input type="password" name="password" required minlength="8" autofocus autocomplete="new-password"></label>
      <label>Confirmer<input type="password" name="password2" required minlength="8" autocomplete="new-password"></label>
      <button class="btn">Enregistrer</button>
    </form>
  <?php endif; ?>
</div>
<?php page_end();
