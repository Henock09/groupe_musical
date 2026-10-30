<?php
function page_start(string $title, string $active = ''): void {
    $u = current_user();
    ensure_seances();
    $nav = [['dashboard.php', 'home', $u && is_admin() ? 'Tableau de bord' : 'Mon espace'],
            ['seances.php', 'calendar', 'Séances'],
            ['cotisations.php', 'wallet', 'Cotisations'],
            ['penalites.php', 'alert', 'Pénalités'],
            ['dons.php', 'gift', 'Dons']];
    if (can('membres')) {
        $pend = (int)val('SELECT COUNT(*) FROM inscriptions');
        array_splice($nav, 1, 0, [['membres.php', 'users', 'Membres' . ($pend ? " ($pend)" : '')]]);
    }
    if (can('parametres')) $nav[] = ['parametres.php', 'cog', 'Paramètres'];
    if (is_owner()) $nav[] = ['admins.php', 'shield', 'Administrateurs'];
    $icons = [
        'home' => 'M3 11l9-8 9 8v10a1 1 0 01-1 1h-5v-6H9v6H4a1 1 0 01-1-1z',
        'users' => 'M16 11a4 4 0 10-8 0 4 4 0 008 0zM4 21v-1a6 6 0 0116 0v1',
        'calendar' => 'M4 6h16v14H4zM4 10h16M8 3v4M16 3v4',
        'wallet' => 'M3 7h16a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2zM3 7l2-3h12M16 14h3',
        'alert' => 'M12 3l10 18H2zM12 10v5M12 18v.5',
        'gift' => 'M4 10h16v10H4zM2 7h20v3H2zM12 7v13M12 7C9 7 8 3 10 3s2 4 2 4zm0 0c3 0 4-4 2-4s-2 4-2 4z',
        'cog' => 'M12 15a3 3 0 100-6 3 3 0 000 6zM19 12l2-1-2-4-2 .5-1.5-1L15 4h-4l-.5 2.5-1.5 1L7 7l-2 4 2 1v2l-2 1 2 4 2-.5 1.5 1L11 20h4l.5-2.5 1.5-1 2 .5 2-4-2-1z',
        'shield' => 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z',
    ];
    ?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e(setting('nom_groupe')) ?></title>
<link rel="stylesheet" href="assets/css/style.css">
<script src="assets/js/app.js" defer></script>
</head>
<body>
<?php if ($u): ?>
<div class="shell">
  <aside class="side" id="side">
    <div class="brand"><span class="brand-mark">♪</span><span><?= e(setting('nom_groupe')) ?></span></div>
    <nav>
      <?php foreach ($nav as [$href, $ic, $label]): ?>
        <a href="<?= $href ?>" class="<?= $active === $href ? 'on' : '' ?>">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="<?= $icons[$ic] ?>"/></svg><?= e($label) ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="side-foot">
      <div class="who"><strong><?= e($u['nom']) ?></strong>
        <small><?= $u['role'] === 'owner' ? 'Administrateur principal' : ($u['role'] === 'admin' ? 'Administrateur' : 'Membre') ?></small></div>
      <a href="profil.php">Mon profil</a>
      <a href="logout.php">Déconnexion</a>
    </div>
  </aside>
  <div class="main">
    <header class="top">
      <button class="burger" type="button" data-toggle="side" aria-label="Menu">☰</button>
      <h1><?= e($title) ?></h1>
    </header>
    <main class="content">
<?php else: ?>
<main class="auth">
<?php endif; ?>
<?php if ($u && !$u['email'] && basename($_SERVER['SCRIPT_NAME']) !== 'profil.php'): ?>
  <div class="flash flash-warn">Vous n'avez pas encore d'adresse e-mail : sans elle, vous ne pourrez pas récupérer votre mot de passe en cas d'oubli. <a href="profil.php">Ajouter mon e-mail</a></div>
<?php endif; ?>
<?php foreach ($_SESSION['flash'] ?? [] as [$type, $msg]): ?>
  <div class="flash flash-<?= e($type) ?>" role="status"><?= e($msg) ?></div>
<?php endforeach; unset($_SESSION['flash']); ?>
<?php
}

function page_end(): void {
    $u = current_user();
    echo $u ? "\n    </main>\n  </div>\n</div>\n" : "\n</main>\n";
    echo "</body>\n</html>\n";
}
