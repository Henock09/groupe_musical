<?php
// ---- Affichage --------------------------------------------------------------
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function setting(string $k, string $default = ''): string {
    static $cache = null;
    if ($cache === null) $cache = array_column(rows('SELECT k, v FROM settings'), 'v', 'k');
    return $cache[$k] ?? $default;
}
function set_setting(string $k, string $v): void {
    q('INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v', [$k, $v]);
}

function money($n): string {
    $n = round((float)$n, 2);
    $dec = floor($n) == $n ? 0 : 2;
    return number_format($n, $dec, ',', ' ') . ' ' . setting('devise', 'FCFA');
}
function num($v): float { return round((float)str_replace([' ', ','], ['', '.'], (string)$v), 2); }

const MOIS_FR = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
const JOURS_FR = ['', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];

function fdate(?string $d, bool $withDay = false): string {
    if (!$d) return '—';
    $t = strtotime($d);
    if (!$t) return e($d);
    $s = (int)date('j', $t) . ' ' . MOIS_FR[(int)date('n', $t)] . ' ' . date('Y', $t);
    return $withDay ? JOURS_FR[(int)date('N', $t)] . ' ' . $s : $s;
}
function fmois(string $ym): string {
    [$y, $m] = explode('-', $ym) + [1 => '1'];
    return ucfirst(MOIS_FR[(int)$m]) . ' ' . $y;
}

function badge(string $text, string $kind = 'neutral'): string {
    return '<span class="badge badge-' . e($kind) . '">' . e($text) . '</span>';
}

// ---- Navigation / messages --------------------------------------------------
function redirect(string $url): never { header('Location: ' . $url); exit; }

function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'][] = [$type, $msg]; }

// ---- CSRF -------------------------------------------------------------------
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void {
    $ok = isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', (string)$_POST['csrf']);
    if (!$ok) { http_response_code(400); exit('Session expirée ou requête invalide. Rechargez la page.'); }
}

function is_post(): bool { return $_SERVER['REQUEST_METHOD'] === 'POST'; }

function random_password(int $len = 8): string {
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < $len; $i++) $s .= $chars[random_int(0, strlen($chars) - 1)];
    return $s;
}

// ---- Séances ----------------------------------------------------------------
/** Crée les séances normales (samedi et dimanche soir) des 4 prochaines semaines. */
function ensure_seances(): void {
    $today = date('Y-m-d');
    $heureSamedi = setting('heure_seance_samedi', '16:45');
    $heureDimanche = setting('heure_seance_dimanche', '17:00');
    q("UPDATE seances SET heure = CASE strftime('%w', date)
        WHEN '6' THEN ? WHEN '0' THEN ? END
        WHERE type = 'normale' AND date >= ? AND strftime('%w', date) IN ('0', '6')",
      [$heureSamedi, $heureDimanche, $today]);
    if (setting('derniere_generation') === $today) return;
    $d = new DateTime(setting('seances_debut', $today));
    $end = new DateTime('+28 days');
    $st = db()->prepare("INSERT OR IGNORE INTO seances (date, heure, type, statut, created_at) VALUES (?, ?, 'normale', 'prevue', ?)");
    db()->beginTransaction();
    for (; $d <= $end; $d->modify('+1 day')) {
        $jour = (int)$d->format('N');
        if ($jour === 6 || $jour === 7) {
            $heure = $jour === 6 ? $heureSamedi : $heureDimanche;
            $st->execute([$d->format('Y-m-d'), $heure, date('c')]);
        }
    }
    db()->commit();
    set_setting('derniere_generation', $today);
}

function seance_label(array $s): string {
    if ($s['type'] === 'extraordinaire') return $s['titre'] ?: 'Séance extraordinaire';
    return 'Séance du ' . JOURS_FR[(int)date('N', strtotime($s['date']))] . ' soir';
}
function seance_start(array $s): int { return strtotime($s['date'] . ' ' . $s['heure']); }
function seance_open_for_signal(array $s): bool {
    return $s['statut'] === 'prevue' && time() < seance_start($s);
}

/**
 * Crée / met à jour / supprime l'amende associée à un pointage.
 * Une absence ou un retard est amendé seulement s'il n'est pas excusé
 * (c'est-à-dire non signalé à l'avance).
 */
function apply_penalty(int $seanceId, int $userId, string $statut, bool $excuse): void {
    $existing = row('SELECT * FROM penalites WHERE seance_id = ? AND user_id = ?', [$seanceId, $userId]);
    $type = $statut === 'absent' ? 'absence' : ($statut === 'retard' ? 'retard' : null);
    $fined = $type !== null && !$excuse;
    if (!$fined) {
        if ($existing && $existing['statut'] !== 'payee') q('DELETE FROM penalites WHERE id = ?', [$existing['id']]);
        return;
    }
    $montant = num(setting($type === 'absence' ? 'amende_absence' : 'amende_retard', '0'));
    if ($existing) {
        if ($existing['statut'] === 'due' || $existing['statut'] === 'annulee') {
            // si l'admin avait pardonné l'amende, on garde son choix tant que le type ne change pas
            $newStatut = ($existing['statut'] === 'annulee' && $existing['type'] === $type) ? 'annulee' : 'due';
            $m = $newStatut === 'annulee' ? $existing['montant'] : $montant;
            q('UPDATE penalites SET type = ?, montant = ?, statut = ? WHERE id = ?', [$type, $m, $newStatut, $existing['id']]);
        }
    } else {
        q("INSERT INTO penalites (user_id, seance_id, type, montant, statut, created_at) VALUES (?, ?, ?, ?, 'due', ?)",
          [$userId, $seanceId, $type, $montant, date('c')]);
    }
}

// ---- Finances ---------------------------------------------------------------
/** Situation financière d'un membre : cotisations dues/payées, amendes dues. */
function situation(int $uid): array {
    $du   = (float)val('SELECT COALESCE(SUM(montant_du),0) FROM cotisation_membres WHERE user_id = ?', [$uid]);
    $paye = (float)val('SELECT COALESCE(SUM(p.montant),0) FROM paiements p JOIN cotisation_membres cm ON cm.id = p.cotisation_membre_id WHERE cm.user_id = ?', [$uid]);
    $amd  = (float)val("SELECT COALESCE(SUM(montant),0) FROM penalites WHERE user_id = ? AND statut = 'due'", [$uid]);
    return ['cotis_due' => $du, 'cotis_payee' => $paye, 'cotis_reste' => max(0, $du - $paye), 'amendes_dues' => $amd];
}

// ---- Droits des administrateurs ---------------------------------------------
const PERMISSIONS = [
    'membres'     => 'Gérer les membres et valider les demandes d\'inscription',
    'cotisations' => 'Créer et modifier les cotisations',
    'paiements'   => 'Encaisser les cotisations et les pénalités',
    'seances'     => 'Créer / annuler des séances et faire l\'appel',
    'penalites'   => 'Annuler (pardonner) ou rétablir des pénalités',
    'dons'        => 'Enregistrer les dons et voir les donateurs',
    'parametres'  => 'Modifier les paramètres et le code d\'invitation',
];
const DEFAULT_ADMIN_PERMS = ['membres', 'cotisations', 'paiements', 'seances', 'penalites', 'dons'];

function clean_perms(array $in): string {
    return implode(',', array_values(array_intersect(array_keys(PERMISSIONS), array_map('strval', $in))));
}

// ---- E-mails ----------------------------------------------------------------
function valid_email(string $e): bool { return strlen($e) <= 120 && filter_var($e, FILTER_VALIDATE_EMAIL) !== false; }

function email_taken(string $email, int $exceptUserId = 0): bool {
    return (bool)val('SELECT 1 FROM users WHERE email = ? COLLATE NOCASE AND id != ?', [$email, $exceptUserId])
        || (bool)val('SELECT 1 FROM inscriptions WHERE email = ? COLLATE NOCASE', [$email]);
}
function username_taken_by_user(string $u): bool {
    return (bool)val('SELECT 1 FROM users WHERE username = ?', [$u]);
}
function username_taken(string $u): bool {
    return (bool)val('SELECT 1 FROM users WHERE username = ?', [$u]) || (bool)val('SELECT 1 FROM inscriptions WHERE username = ? COLLATE NOCASE', [$u]);
}

function base_url(): string {
    if (BASE_URL !== '') return rtrim(BASE_URL, '/') . '/';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) $host = 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . "://$host$dir/";
}

/** Envoi SMTP minimal (AUTH LOGIN, STARTTLS ou SSL). Retourne true si le serveur a accepté le message. */
function smtp_send(string $to, string $subject, string $body): bool {
    $fp = @stream_socket_client((SMTP_SECURE === 'ssl' ? 'ssl://' : '') . SMTP_HOST . ':' . SMTP_PORT, $en, $es, 10);
    if (!$fp) return false;
    stream_set_timeout($fp, 10);
    $read = function () use ($fp): string {
        $out = '';
        while (($l = fgets($fp, 515)) !== false) { $out .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; }
        return $out;
    };
    $cmd = function (string $c, string $expect) use ($fp, $read): bool {
        if ($c !== '') fwrite($fp, $c . "\r\n");
        return str_starts_with($read(), $expect);
    };
    $from = MAIL_FROM;
    $ok = $cmd('', '220') && $cmd('EHLO localhost', '250');
    if ($ok && SMTP_SECURE === 'tls') {
        $ok = $cmd('STARTTLS', '220') && stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) && $cmd('EHLO localhost', '250');
    }
    if ($ok && SMTP_USER !== '') $ok = $cmd('AUTH LOGIN', '334') && $cmd(base64_encode(SMTP_USER), '334') && $cmd(base64_encode(SMTP_PASS), '235');
    $ok = $ok && $cmd("MAIL FROM:<$from>", '250') && $cmd("RCPT TO:<$to>", '25') && $cmd('DATA', '354');
    if ($ok) {
        $name = '=?UTF-8?B?' . base64_encode(setting('nom_groupe', 'Groupe')) . '?=';
        $msg = "From: $name <$from>\r\nTo: <$to>\r\nSubject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\nDate: " . date('r')
             . "\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
             . chunk_split(base64_encode($body));
        fwrite($fp, $msg . "\r\n.\r\n");
        $ok = str_starts_with($read(), '250');
    }
    @fwrite($fp, "QUIT\r\n");
    @fclose($fp);
    return $ok;
}

/** Envoie un e-mail (SMTP si configuré, sinon mail()). Si l'envoi échoue (PC local), il est écrit dans data/mail.log. */
function send_mail(string $to, string $subject, string $body): bool {
    if (SMTP_HOST !== '') {
        $ok = smtp_send($to, $subject, $body);
    } else {
        $headers = "From: " . setting('nom_groupe', 'Groupe') . " <" . MAIL_FROM . ">\r\nContent-Type: text/plain; charset=UTF-8\r\nMIME-Version: 1.0";
        $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
    }
    if (!$ok || MAIL_LOG_ALWAYS) {
        @file_put_contents(dirname(DB_PATH) . '/mail.log',
            "=== " . date('c') . ($ok ? '' : ' (envoi impossible, copie locale)') . "\nÀ : $to\nObjet : $subject\n\n$body\n\n", FILE_APPEND | LOCK_EX);
    }
    return $ok;
}

/** Ajoute le membre issu d'une inscription (validée ou par code). */
function create_member(string $nom, string $username, string $hash, ?string $email, string $tel): int {
    q("INSERT INTO users (nom, username, password_hash, role, statut, telephone, email, date_arrivee, must_change_password, created_at)
       VALUES (?, ?, ?, 'membre', 'actif', ?, ?, ?, 0, ?)",
      [$nom, $username, $hash, $tel, $email ?: null, date('Y-m-d'), date('c')]);
    return (int)db()->lastInsertId();
}
