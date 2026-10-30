<?php
require __DIR__ . '/inc/bootstrap.php';
if (current_user()) redirect('dashboard.php');

$sent = false;
$err = '';
if (is_post()) {
    csrf_check();
    if (rate_blocked('reset', 5)) {
        $err = 'Trop de demandes. Réessayez dans 15 minutes.';
    } else {
        rate_hit('reset');
        $email = trim((string)($_POST['email'] ?? ''));
        $u = valid_email($email) ? row("SELECT * FROM users WHERE email = ? COLLATE NOCASE AND statut = 'actif'", [$email]) : null;
        if ($u) {
            $token = bin2hex(random_bytes(32));
            q('DELETE FROM password_resets WHERE user_id = ?', [$u['id']]);
            q('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)', [$u['id'], hash('sha256', $token), time() + 3600]);
            $link = base_url() . 'reinitialiser.php?token=' . $token;
            send_mail($u['email'], 'Réinitialisation de votre mot de passe',
                "Bonjour {$u['nom']},\n\nVous avez demandé à réinitialiser votre mot de passe ({$u['username']}).\n"
                . "Cliquez sur ce lien (valable 1 heure) :\n$link\n\nSi vous n'êtes pas à l'origine de cette demande, ignorez ce message : votre mot de passe reste inchangé.\n");
        }
        $sent = true; // même réponse que l'adresse existe ou non (pas de fuite d'information)
    }
}
page_start('Mot de passe oublié');
?>
<div class="auth-card">
  <h1>Mot de passe oublié</h1>
  <?php if ($sent): ?>
    <div class="flash flash-ok">Si cette adresse correspond à un compte, un e-mail avec un lien de réinitialisation vient d'être envoyé (valable 1 heure). Pensez à regarder les courriers indésirables.</div>
    <p><a href="login.php">← Retour à la connexion</a></p>
  <?php else: ?>
    <p class="muted">Saisissez l'adresse e-mail de votre compte : nous vous enverrons un lien pour choisir un nouveau mot de passe.</p>
    <?php if ($err): ?><div class="flash flash-err"><?= e($err) ?></div><?php endif; ?>
    <form method="post" class="form"><?= csrf_field() ?>
      <label>Adresse e-mail<input type="email" name="email" required autofocus autocomplete="email"></label>
      <button class="btn">Envoyer le lien</button>
    </form>
    <p class="muted small">Pas d'e-mail enregistré sur votre compte ? Demandez à un administrateur de réinitialiser votre mot de passe.</p>
    <p class="small"><a href="login.php">← Retour à la connexion</a></p>
  <?php endif; ?>
</div>
<?php page_end();
