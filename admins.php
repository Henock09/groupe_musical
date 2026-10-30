<?php
require __DIR__ . '/inc/bootstrap.php';
$me = require_owner();

if (is_post()) {
    csrf_check();
    $target = row("SELECT * FROM users WHERE id = ? AND statut = 'actif'", [(int)($_POST['user_id'] ?? 0)]);
    $a = $_POST['action'] ?? '';
    $perms = clean_perms((array)($_POST['perms'] ?? []));
    if (!$target || $target['role'] === 'owner') {
        flash('Membre introuvable.', 'err');
    } elseif ($a === 'promouvoir') {
        $nb = (int)val("SELECT COUNT(*) FROM users WHERE role = 'admin' AND statut = 'actif'");
        if ($target['role'] !== 'membre') flash('Ce compte est déjà administrateur.', 'err');
        elseif ($nb >= MAX_ADMINS) flash('Maximum de ' . MAX_ADMINS . " administrateurs atteint. Retirez-en un d'abord.", 'err');
        else { q("UPDATE users SET role = 'admin', permissions = ? WHERE id = ?", [$perms, $target['id']]); flash($target['nom'] . ' est maintenant administrateur avec les droits choisis.'); }
    } elseif ($a === 'droits' && $target['role'] === 'admin') {
        q('UPDATE users SET permissions = ? WHERE id = ?', [$perms, $target['id']]);
        flash('Droits de ' . $target['nom'] . ' mis à jour.');
    } elseif ($a === 'retrograder') {
        q("UPDATE users SET role = 'membre', permissions = '' WHERE id = ?", [$target['id']]);
        flash($target['nom'] . " n'est plus administrateur.");
    }
    redirect('admins.php');
}

$admins = rows("SELECT * FROM users WHERE role = 'admin' AND statut = 'actif' ORDER BY nom COLLATE NOCASE");
$membres = rows("SELECT * FROM users WHERE role = 'membre' AND statut = 'actif' ORDER BY nom COLLATE NOCASE");
$places = MAX_ADMINS - count($admins);

function perm_checks(array $selected): void {
    foreach (PERMISSIONS as $k => $label): ?>
      <label class="check"><input type="checkbox" name="perms[]" value="<?= e($k) ?>" <?= in_array($k, $selected, true) ? 'checked' : '' ?>> <?= e($label) ?></label>
    <?php endforeach;
}
page_start('Administrateurs', 'admins.php');
?>
<section class="panel">
  <h2>Administrateurs <small class="muted">(<?= count($admins) ?> / <?= MAX_ADMINS ?>)</small></h2>
  <p class="muted">Vous (administrateur principal) avez tous les droits et vous seul pouvez nommer, retirer ou limiter un administrateur.
     Chaque administrateur n'a accès qu'aux fonctions que vous cochez.</p>
  <ul class="list">
    <li><span><strong><?= e($me['nom']) ?></strong> <?= badge('Administrateur principal', 'info') ?></span><span class="muted">Tous les droits</span></li>
  </ul>
  <?php foreach ($admins as $a): $sel = explode(',', (string)$a['permissions']); ?>
    <details class="adm">
      <summary><strong><?= e($a['nom']) ?></strong> <small class="muted"><?= e($a['username']) ?></small>
        <?= badge(count(array_filter($sel)) . ' / ' . count(PERMISSIONS) . ' droits', 'info') ?></summary>
      <form method="post" class="form"><?= csrf_field() ?>
        <input type="hidden" name="action" value="droits"><input type="hidden" name="user_id" value="<?= (int)$a['id'] ?>">
        <div class="checks one"><?php perm_checks($sel); ?></div>
        <div class="row wrap"><button class="btn btn-sm">Enregistrer les droits</button>
          <button class="btn btn-danger btn-sm" name="action" value="retrograder" onclick="return confirm('Retirer les droits d\'administrateur à <?= e(addslashes($a['nom'])) ?> ?')">Retirer le statut d'administrateur</button></div>
      </form>
    </details>
  <?php endforeach; ?>
</section>

<section class="panel">
  <h2>Nommer un administrateur</h2>
  <?php if ($places <= 0): ?><p class="muted">Le maximum de <?= MAX_ADMINS ?> administrateurs est atteint. Retirez-en un pour en nommer un autre.</p>
  <?php elseif (!$membres): ?><p class="muted">Aucun membre actif à nommer. Ajoutez d'abord des membres.</p>
  <?php else: ?>
    <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="promouvoir">
      <label>Membre<select name="user_id" required><?php foreach ($membres as $m): ?><option value="<?= (int)$m['id'] ?>"><?= e($m['nom']) ?></option><?php endforeach; ?></select></label>
      <fieldset><legend>Droits accordés</legend><div class="checks one"><?php perm_checks(DEFAULT_ADMIN_PERMS); ?></div></fieldset>
      <div class="row wrap"><button class="btn">Nommer administrateur</button>
        <small class="muted"><?= $places ?> place<?= $places > 1 ? 's' : '' ?> restante<?= $places > 1 ? 's' : '' ?></small></div>
    </form>
  <?php endif; ?>
</section>
<?php page_end();
