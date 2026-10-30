<?php
function current_user(): ?array {
    static $u = false;
    if ($u !== false) return $u;
    $u = null;
    if (!empty($_SESSION['uid'])) {
        $u = row("SELECT * FROM users WHERE id = ? AND statut = 'actif'", [$_SESSION['uid']]);
        if (!$u) unset($_SESSION['uid']);
    }
    return $u;
}
function is_admin(): bool { $u = current_user(); return $u && in_array($u['role'], ['owner', 'admin'], true); }
function is_owner(): bool { $u = current_user(); return $u && $u['role'] === 'owner'; }

/** Droit précis d'un administrateur (l'administrateur principal a tous les droits). */
function can(string $perm): bool {
    $u = current_user();
    if (!$u) return false;
    if ($u['role'] === 'owner') return true;
    return $u['role'] === 'admin' && in_array($perm, explode(',', (string)$u['permissions']), true);
}
function require_perm(string $perm): array {
    $u = require_login();
    if (!can($perm)) { http_response_code(403); exit("Vous n'avez pas l'autorisation d'effectuer cette action."); }
    return $u;
}

function require_login(): array {
    $u = current_user();
    if (!$u) redirect('login.php');
    $self = basename($_SERVER['SCRIPT_NAME']);
    if ($u['must_change_password'] && !in_array($self, ['profil.php', 'logout.php'], true)) {
        flash('Pour votre sécurité, choisissez un nouveau mot de passe avant de continuer.', 'warn');
        redirect('profil.php');
    }
    return $u;
}
function require_admin(): array {
    $u = require_login();
    if (!is_admin()) { http_response_code(403); exit('Accès réservé aux administrateurs.'); }
    return $u;
}
function require_owner(): array {
    $u = require_login();
    if (!is_owner()) { http_response_code(403); exit("Accès réservé à l'administrateur principal."); }
    return $u;
}

function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? ''; }
function rate_blocked(string $kind, int $max): bool {
    q('DELETE FROM login_attempts WHERE at < ?', [time() - 900]);
    return (int)val('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND kind = ?', [client_ip(), $kind]) >= $max;
}
function rate_hit(string $kind): void { q('INSERT INTO login_attempts (ip, at, kind) VALUES (?, ?, ?)', [client_ip(), time(), $kind]); }
function login_blocked(): bool { return rate_blocked('login', MAX_LOGIN_FAILS); }
function try_login(string $username, string $password): bool {
    $u = row("SELECT * FROM users WHERE username = ? AND statut = 'actif'", [$username]);
    if ($u && password_verify($password, $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        q("DELETE FROM login_attempts WHERE ip = ? AND kind = 'login'", [client_ip()]);
        return true;
    }
    rate_hit('login');
    return false;
}
