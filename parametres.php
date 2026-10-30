<?php
require __DIR__ . '/inc/bootstrap.php';
$me = require_perm('parametres');

if (is_post()) {
    csrf_check();
    if (($_POST['action'] ?? '') === 'regen_code') {
        set_setting('code_invitation', strtoupper(bin2hex(random_bytes(4))));
        flash("Nouveau code d'invitation créé. L'ancien ne fonctionne plus.");
        redirect('parametres.php');
    }
    $nom = trim($_POST['nom_groupe'] ?? '');
    $devise = trim($_POST['devise'] ?? '');
    $heureSamedi = $_POST['heure_seance_samedi'] ?? '';
    $heureDimanche = $_POST['heure_seance_dimanche'] ?? '';
    $retard = num($_POST['amende_retard'] ?? 0);
    $abs = num($_POST['amende_absence'] ?? 0);
    $cm = num($_POST['cotisation_mensuelle'] ?? 0);
    if ($nom === '' || $devise === '' || !preg_match('/^\d{2}:\d{2}$/', $heureSamedi)
        || !preg_match('/^\d{2}:\d{2}$/', $heureDimanche) || min($retard, $abs, $cm) < 0) {
        flash('Vérifiez les champs : valeurs vides ou négatives.', 'err');
    } else {
        set_setting('nom_groupe', $nom); set_setting('devise', $devise);
        set_setting('heure_seance_samedi', $heureSamedi); set_setting('heure_seance_dimanche', $heureDimanche);
        set_setting('amende_retard', (string)$retard); set_setting('amende_absence', (string)$abs);
        set_setting('cotisation_mensuelle', (string)$cm);
        set_setting('inscription_active', isset($_POST['inscription_active']) ? '1' : '0');
        flash("Paramètres enregistrés. Les nouveaux montants s'appliquent aux prochaines pénalités et cotisations.");
    }
    redirect('parametres.php');
}
$code = setting('code_invitation');
page_start('Paramètres', 'parametres.php');
?>
<section class="panel"><form method="post" class="form narrow"><?= csrf_field() ?>
  <h2>Groupe</h2>
  <label>Nom du groupe<input name="nom_groupe" required value="<?= e(setting('nom_groupe')) ?>"></label>
  <label>Devise (affichée à côté des montants)<input name="devise" required value="<?= e(setting('devise')) ?>"></label>
  <h2>Séances</h2>
  <label>Heure des séances du samedi<input type="time" name="heure_seance_samedi" required value="<?= e(setting('heure_seance_samedi', '16:45')) ?>"></label>
  <label>Heure des séances du dimanche<input type="time" name="heure_seance_dimanche" required value="<?= e(setting('heure_seance_dimanche', '17:00')) ?>"></label>
  <p class="muted small">Les horaires s'appliquent aux séances normales à venir. Les séances passées ne sont pas modifiées.</p>
  <h2>Montants</h2>
  <label>Cotisation mensuelle par défaut<input name="cotisation_mensuelle" inputmode="decimal" required value="<?= e(setting('cotisation_mensuelle')) ?>"></label>
  <label>Pénalité de retard non signalé<input name="amende_retard" inputmode="decimal" required value="<?= e(setting('amende_retard')) ?>"></label>
  <label>Pénalité d'absence non signalée<input name="amende_absence" inputmode="decimal" required value="<?= e(setting('amende_absence')) ?>"></label>
  <h2>Inscriptions</h2>
  <label class="check"><input type="checkbox" name="inscription_active" value="1" <?= setting('inscription_active') === '1' ? 'checked' : '' ?>> Autoriser les nouvelles personnes à s'inscrire elles-mêmes</label>
  <p class="muted small">Sans code d'invitation, la demande attend la validation d'un administrateur. Avec le bon code, le compte est créé immédiatement.</p>
  <button class="btn">Enregistrer</button>
</form></section>

<section class="panel"><h2>Code d'invitation</h2>
  <p>Code actuel : <strong class="code"><?= e($code) ?></strong></p>
  <p class="muted small">Donnez ce code uniquement aux personnes que vous connaissez, ou envoyez-leur ce lien qui le contient déjà :<br>
    <code><?= e(base_url() . 'inscription.php?code=' . $code) ?></code></p>
  <form method="post" data-confirm="Créer un nouveau code ? L'ancien ne fonctionnera plus."><?= csrf_field() ?><input type="hidden" name="action" value="regen_code">
    <button class="btn btn-ghost">Générer un nouveau code</button></form>
</section>
<?php page_end();
