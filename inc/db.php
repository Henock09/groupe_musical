<?php
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
    migrate($pdo);
    return $pdo;
}

function q(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }
function rows(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function row(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r ?: null; }
function val(string $sql, array $p = []) { $r = q($sql, $p)->fetchColumn(); return $r === false ? null : $r; }

function has_column(PDO $pdo, string $table, string $col): bool {
    foreach ($pdo->query("PRAGMA table_info($table)") as $r) if ($r['name'] === $col) return true;
    return false;
}

function migrate(PDO $pdo): void {
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT NOT NULL);

    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nom TEXT NOT NULL,
        username TEXT NOT NULL UNIQUE COLLATE NOCASE,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'membre' CHECK (role IN ('owner','admin','membre')),
        statut TEXT NOT NULL DEFAULT 'actif' CHECK (statut IN ('actif','parti')),
        telephone TEXT,
        date_arrivee TEXT NOT NULL,
        date_depart TEXT,
        must_change_password INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL
    );

    CREATE TABLE IF NOT EXISTS cotisations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        titre TEXT NOT NULL,
        type TEXT NOT NULL CHECK (type IN ('mensuelle','extraordinaire')),
        montant REAL NOT NULL,
        mois TEXT,
        echeance TEXT,
        description TEXT,
        created_by INTEGER REFERENCES users(id),
        created_at TEXT NOT NULL
    );
    CREATE UNIQUE INDEX IF NOT EXISTS ux_cotis_mois ON cotisations(mois) WHERE type = 'mensuelle';

    CREATE TABLE IF NOT EXISTS cotisation_membres (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cotisation_id INTEGER NOT NULL REFERENCES cotisations(id) ON DELETE CASCADE,
        user_id INTEGER NOT NULL REFERENCES users(id),
        montant_du REAL NOT NULL,
        UNIQUE (cotisation_id, user_id)
    );

    CREATE TABLE IF NOT EXISTS paiements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cotisation_membre_id INTEGER NOT NULL REFERENCES cotisation_membres(id) ON DELETE CASCADE,
        montant REAL NOT NULL,
        date_paiement TEXT NOT NULL,
        note TEXT,
        enregistre_par INTEGER REFERENCES users(id),
        created_at TEXT NOT NULL
    );

    CREATE TABLE IF NOT EXISTS seances (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        date TEXT NOT NULL,
        heure TEXT NOT NULL,
        type TEXT NOT NULL CHECK (type IN ('normale','extraordinaire')),
        titre TEXT,
        lieu TEXT,
        statut TEXT NOT NULL DEFAULT 'prevue' CHECK (statut IN ('prevue','annulee','terminee')),
        motif_annulation TEXT,
        created_by INTEGER REFERENCES users(id),
        created_at TEXT NOT NULL
    );
    CREATE UNIQUE INDEX IF NOT EXISTS ux_seance_normale ON seances(date) WHERE type = 'normale';

    CREATE TABLE IF NOT EXISTS signalements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        seance_id INTEGER NOT NULL REFERENCES seances(id) ON DELETE CASCADE,
        user_id INTEGER NOT NULL REFERENCES users(id),
        type TEXT NOT NULL CHECK (type IN ('retard','absence')),
        motif TEXT,
        created_at TEXT NOT NULL,
        UNIQUE (seance_id, user_id)
    );

    CREATE TABLE IF NOT EXISTS presences (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        seance_id INTEGER NOT NULL REFERENCES seances(id) ON DELETE CASCADE,
        user_id INTEGER NOT NULL REFERENCES users(id),
        statut TEXT NOT NULL CHECK (statut IN ('present','retard','absent')),
        excuse INTEGER NOT NULL DEFAULT 0,
        UNIQUE (seance_id, user_id)
    );

    CREATE TABLE IF NOT EXISTS penalites (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id),
        seance_id INTEGER NOT NULL REFERENCES seances(id) ON DELETE CASCADE,
        type TEXT NOT NULL CHECK (type IN ('retard','absence')),
        montant REAL NOT NULL,
        statut TEXT NOT NULL DEFAULT 'due' CHECK (statut IN ('due','payee','annulee')),
        date_paiement TEXT,
        created_at TEXT NOT NULL,
        UNIQUE (seance_id, user_id)
    );

    CREATE TABLE IF NOT EXISTS dons (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        donateur TEXT NOT NULL,
        nature TEXT NOT NULL DEFAULT 'argent' CHECK (nature IN ('argent','nature')),
        montant REAL,
        description TEXT,
        date_don TEXT NOT NULL,
        enregistre_par INTEGER REFERENCES users(id),
        created_at TEXT NOT NULL
    );

    CREATE TABLE IF NOT EXISTS login_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip TEXT NOT NULL,
        at INTEGER NOT NULL
    );
    ");

    $pdo->exec("
    CREATE TABLE IF NOT EXISTS inscriptions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nom TEXT NOT NULL,
        email TEXT NOT NULL,
        telephone TEXT,
        username TEXT NOT NULL,
        password_hash TEXT NOT NULL,
        created_at TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS password_resets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        token_hash TEXT NOT NULL,
        expires_at INTEGER NOT NULL
    );
    ");
    // Évolutions de la base (compatibles avec une base créée par une version précédente)
    if (!has_column($pdo, 'users', 'email')) $pdo->exec('ALTER TABLE users ADD COLUMN email TEXT');
    if (!has_column($pdo, 'users', 'permissions')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN permissions TEXT NOT NULL DEFAULT ''");
        $pdo->exec("UPDATE users SET permissions = 'membres,cotisations,paiements,seances,penalites,dons' WHERE role = 'admin'");
    }
    if (!has_column($pdo, 'login_attempts', 'kind')) $pdo->exec("ALTER TABLE login_attempts ADD COLUMN kind TEXT NOT NULL DEFAULT 'login'");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_users_email ON users(email COLLATE NOCASE) WHERE email IS NOT NULL AND email <> ''");

    $defaults = [
        'inscription_active' => '1',
        'code_invitation'  => strtoupper(bin2hex(random_bytes(4))),
        'nom_groupe'       => 'Mon groupe musical',
        'devise'           => 'FCFA',
        'heure_seance_samedi' => '16:45',
        'heure_seance_dimanche' => '17:00',
        'amende_retard'    => '500',
        'amende_absence'   => '1000',
        'cotisation_mensuelle' => '1000',
        'seances_debut'    => date('Y-m-d'),
        'derniere_generation' => '',
    ];
    $st = $pdo->prepare('INSERT OR IGNORE INTO settings (k, v) VALUES (?, ?)');
    foreach ($defaults as $k => $v) $st->execute([$k, $v]);
}
