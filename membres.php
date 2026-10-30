<?php
require __DIR__ . '/inc/bootstrap.php';
$me = require_perm('membres');

if (is_post()) {
    csrf_check();
    $a = $_POST['action'] ?? '';
    if ($a === 'ajouter') {
        $nom = trim($_POST['nom'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $pw = (string)($_POST['password'] ?? '');
        $arrivee = $_POST['date_arrivee'] ?? date('Y-m-d');
        if ($nom === '') flash('Le nom est obligatoire.', 'err');
        elseif (!preg_match('/^[A-Za-z0-9._-]{3,30}$/', $username)) flash('Identifiant : 3 à 30 caractères (lettres, chiffres, . _ -).', 'err');
        elseif (!valid_email($email)) flash("Indiquez une adresse e-mail valide : elle permet au membre de récupérer son mot de passe.", 'err');
        elseif (strlen($pw) < 6) flash('Le mot de passe provisoire doit contenir au moins 6 caractères.', 'err');
        elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $arrivee)) flash("Date d'arrivée invalide.", 'err');
        elseif (username_taken($username)) flash('Cet identifiant est déjà utilisé.', 'err');
        elseif (email_taken($email)) flash('Cette adresse e-mail est déjà utilisée.', 'err');
        else {
            q("INSERT INTO users (nom, username, email, password_hash, role, statut, telephone, date_arrivee, must_change_password, created_at)
               VALUES (?, ?, ?, ?, 'membre', 'actif', ?, ?, 1, ?)",
              [$nom, $username, $email, password_hash($pw, PASSWORD_DEFAULT), trim($_POST['telephone'] ?? ''), $arrivee, date('c')]);
            flash("Membre ajouté. Communiquez-lui son identifiant « $username » et son mot de passe provisoire ; il devra le changer à la première connexion.");
        }
    } elseif (in_array($a, ['valider', 'refuser'], true)) {
        $r = row('SELECT * FROM inscriptions WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
        if (!$r) { flash('Demande introuvable (déjà traitée ?).', 'err'); }
        elseif ($a === 'valider') {
            if (username_taken_by_user($r['username']) || val('SELECT 1 FROM users WHERE email = ? COLLATE NOCASE', [$r['email']])) {
                flash("Impossible : cet identifiant ou cette adresse e-mail est maintenant utilisé par un autre compte. Refusez la demande.", 'err');
            } else {
                q('DELETE FROM inscriptions WHERE id = ?', [$r['id']]);
                create_member($r['nom'], $r['username'], $r['password_hash'], $r['email'], (string)$r['telephone']);
                send_mail($r['email'], 'Votre inscription est validée',
                    "Bonjour {$r['nom']},\n\nVotre inscription à " . setting('nom_groupe') . " est validée. Vous pouvez vous connecter avec l'identifiant « {$r['username']} » :\n" . base_url() . "login.php\n");
                flash($r['nom'] . ' a été accepté(e) comme membre.');
            }
        } else {
            q('DELETE FROM inscriptions WHERE id = ?', [$r['id']]);
            send_mail($r['email'], "Votre demande d'inscription", "Bonjour {$r['nom']},\n\nVotre demande d'inscription à " . setting('nom_groupe') . " n'a pas été retenue. Pour plus d'informations, contactez un administrateur du groupe.\n");
            flash('Demande refusée.');
        }
    }
    redirect('membres.php');
}

$demandes = rows('SELECT * FROM inscriptions ORDER BY created_at');
$voirCotis = can('cotisations') || can('paiements');
$voirPen = can('penalites') || can('paiements');
$filtre = $_GET['f'] ?? 'actif';
$where = $filtre === 'parti' ? "WHERE statut = 'parti'" : ($filtre === 'tous' ? '' : "WHERE statut = 'actif'");
$membres = rows("SELECT u.*,
    COALESCE((SELECT SUM(montant_du) FROM cotisation_membres WHERE user_id = u.id),0)
    - COALESCE((SELECT SUM(p.montant) FROM paiements p JOIN cotisation_membres cm ON cm.id = p.cotisation_membre_id WHERE cm.user_id = u.id),0) AS cotis_reste,
    COALESCE((SELECT SUM(montant) FROM penalites WHERE user_id = u.id AND statut = 'due'),0) AS amendes
    FROM users u $where ORDER BY u.nom COLLATE NOCASE");

page_start('Membres', 'membres.php');
?>
<?php if ($demandes): ?>
<section class="panel attention"><h2>Demandes d'inscription (<?= count($demandes) ?>)</h2>
  <p class="muted small">Ces personnes se sont inscrites sans code d'invitation. Vérifiez que vous les connaissez avant d'accepter.</p>
  <ul class="list">
  <?php foreach ($demandes as $d): ?>
    <li><span><strong><?= e($d['nom']) ?></strong> <small class="muted"><?= e($d['username']) ?> · <?= e($d['email']) ?><?= $d['telephone'] ? ' · ' . e($d['telephone']) : '' ?> · le <?= fdate(substr($d['created_at'], 0, 10)) ?></small></span>
      <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
        <button class="btn btn-sm" name="action" value="valider">Accepter</button>
        <button class="btn btn-danger btn-sm" name="action" value="refuser" onclick="return confirm('Refuser cette demande ?')">Refuser</button></form></li>
  <?php endforeach; ?></ul></section>
<?php endif; ?>

<div class="toolbar">
  <div class="tabs">
    <?php foreach (['actif' => 'Actifs', 'parti' => 'Partis', 'tous' => 'Tous'] as $k => $l): ?>
      <a href="?f=<?= $k ?>" class="<?= $filtre === $k ? 'on' : '' ?>"><?= $l ?></a><?php endforeach; ?>
  </div>
  <input type="search" placeholder="Rechercher un membre…" data-filter="tbl-membres" aria-label="Rechercher">
  <button class="btn" data-open-dialog="dlg-ajout">+ Nouveau membre</button>
</div>

<section class="panel">
<div class="table-wrap"><table id="tbl-membres">
  <thead><tr><th>Nom</th><th>Rôle</th><th>Arrivée</th><?php if ($voirCotis): ?><th class="r">Cotisations dues</th><?php endif; ?><?php if ($voirPen): ?><th class="r">Pénalités</th><?php endif; ?><th></th></tr></thead>
  <tbody>
  <?php foreach ($membres as $m): ?>
    <tr>
      <td><a href="membre.php?id=<?= (int)$m['id'] ?>"><strong><?= e($m['nom']) ?></strong></a><br><small class="muted"><?= e($m['username']) ?><?= $m['telephone'] ? ' · ' . e($m['telephone']) : '' ?></small></td>
      <td><?= $m['statut'] === 'parti' ? badge('Parti le ' . fdate($m['date_depart']), 'neutral') : ($m['role'] === 'owner' ? badge('Admin principal', 'info') : ($m['role'] === 'admin' ? badge('Admin', 'info') : 'Membre')) ?></td>
      <td><?= fdate($m['date_arrivee']) ?></td>
      <?php if ($voirCotis): ?><td class="r <?= $m['cotis_reste'] > 0 ? 'neg' : '' ?>"><?= money(max(0, $m['cotis_reste'])) ?></td><?php endif; ?>
      <?php if ($voirPen): ?><td class="r <?= $m['amendes'] > 0 ? 'neg' : '' ?>"><?= money($m['amendes']) ?></td><?php endif; ?>
      <td class="r"><a class="btn btn-ghost btn-sm" href="membre.php?id=<?= (int)$m['id'] ?>">Détails</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$membres): ?><tr><td colspan="6" class="muted">Aucun membre.</td></tr><?php endif; ?>
  </tbody></table></div>
</section>

<dialog id="dlg-ajout">
  <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="ajouter">
    <h2>Nouveau membre</h2>
    <label>Nom complet<input name="nom" required></label>
    <label>Identifiant de connexion<input name="username" id="new-username" required pattern="[A-Za-z0-9._-]{3,30}"></label>
    <label>Adresse e-mail (pour qu'il puisse récupérer son mot de passe)<input type="email" name="email" required autocomplete="off"></label>
    <label>Téléphone (facultatif)<input name="telephone"></label>
    <label>Date d'arrivée<input type="date" name="date_arrivee" value="<?= date('Y-m-d') ?>" required></label>
    <label>Mot de passe provisoire
      <span class="row"><input name="password" id="new-pw" required minlength="6" value="<?= e(random_password()) ?>">
      <button type="button" class="btn btn-ghost" data-genpw="new-pw">Générer</button></span></label>
    <div class="dlg-actions"><button type="button" class="btn btn-ghost" data-close>Annuler</button><button class="btn">Ajouter</button></div>
  </form>
</dialog>
<?php page_end();
