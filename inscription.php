<?php
require __DIR__ . '/inc/bootstrap.php';
if ((int)val('SELECT COUNT(*) FROM users') === 0) redirect('install.php');
if (current_user()) redirect('dashboard.php');

$open = setting('inscription_active') === '1';
$err = [];
$v = ['nom' => '', 'email' => '', 'telephone' => '', 'username' => '', 'code' => trim((string)($_GET['code'] ?? ''))];

if ($open && is_post()) {
    csrf_check();
    foreach (['nom', 'email', 'telephone', 'username', 'code'] as $k) $v[$k] = trim((string)($_POST[$k] ?? ''));
    $pw = (string)($_POST['password'] ?? '');
    if (rate_blocked('register', 10)) {
        $err[] = 'Trop de tentatives. Réessayez dans 15 minutes.';
    } else {
        rate_hit('register');
        if ($v['nom'] === '' || mb_strlen($v['nom']) > 80) $err[] = 'Indiquez votre nom complet.';
        if (!valid_email($v['email'])) $err[] = 'Indiquez une adresse e-mail valide (elle servira à récupérer votre mot de passe).';
        if (!preg_match('/^[A-Za-z0-9._-]{3,30}$/', $v['username'])) $err[] = 'Identifiant : 3 à 30 caractères (lettres, chiffres, . _ -).';
        if (strlen($pw) < 8) $err[] = 'Le mot de passe doit contenir au moins 8 caractères.';
        if ($pw !== ($_POST['password2'] ?? '')) $err[] = 'Les deux mots de passe ne correspondent pas.';
        $code = setting('code_invitation');
        $codeOk = $v['code'] !== '' && $code !== '' && hash_equals(strtoupper($code), strtoupper($v['code']));
        if ($v['code'] !== '' && !$codeOk) $err[] = "Code d'invitation incorrect. Laissez le champ vide pour envoyer une demande à valider par un administrateur.";
        if (!$err && username_taken($v['username'])) $err[] = 'Cet identifiant est déjà utilisé.';
        if (!$err && email_taken($v['email'])) $err[] = 'Cette adresse e-mail est déjà utilisée.';
        if (!$err) {
            $hash = password_hash($pw, PASSWORD_DEFAULT);
            if ($codeOk) {
                create_member($v['nom'], $v['username'], $hash, $v['email'], $v['telephone']);
                flash('Bienvenue ! Votre compte est créé, vous pouvez vous connecter.');
            } else {
                q('INSERT INTO inscriptions (nom, email, telephone, username, password_hash, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                  [$v['nom'], $v['email'], $v['telephone'], $v['username'], $hash, date('c')]);
                flash("Votre demande est envoyée. Vous pourrez vous connecter dès qu'un administrateur l'aura validée.", 'warn');
            }
            redirect('login.php');
        }
    }
}
page_start('Inscription');
?>
<div class="auth-card">
  <h1>Rejoindre <?= e(setting('nom_groupe')) ?></h1>
  <?php if (!$open): ?>
    <p class="muted">Les inscriptions sont fermées pour le moment. Contactez un administrateur du groupe.</p>
    <p><a href="login.php">← Retour à la connexion</a></p>
  <?php else: ?>
  <p class="muted">Avec un <strong>code d'invitation</strong>, votre compte est créé tout de suite. Sans code, un administrateur validera votre demande.</p>
  <?php foreach ($err as $m): ?><div class="flash flash-err"><?= e($m) ?></div><?php endforeach; ?>
  <form method="post" class="form"><?= csrf_field() ?>
    <label>Nom complet<input name="nom" required maxlength="80" value="<?= e($v['nom']) ?>"></label>
    <label>Adresse e-mail<input type="email" name="email" required value="<?= e($v['email']) ?>" autocomplete="email"></label>
    <label>Téléphone (facultatif)<input name="telephone" value="<?= e($v['telephone']) ?>"></label>
    <label>Identifiant de connexion<input name="username" required pattern="[A-Za-z0-9._-]{3,30}" value="<?= e($v['username']) ?>" autocomplete="username"></label>
    <label>Mot de passe (8 caractères min.)<input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
    <label>Confirmer le mot de passe<input type="password" name="password2" required minlength="8" autocomplete="new-password"></label>
    <label>Code d'invitation (facultatif)<input name="code" value="<?= e($v['code']) ?>" autocapitalize="characters" autocomplete="off"></label>
    <button class="btn">Créer mon compte</button>
  </form>
  <p class="muted small"><a href="login.php">J'ai déjà un compte</a></p>
  <?php endif; ?>
</div>
<?php page_end();
