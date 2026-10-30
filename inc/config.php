<?php
// ---- Configuration générale -------------------------------------------------
// Fuseau horaire : adaptez-le à votre pays (ex. Africa/Douala, Africa/Abidjan,
// Africa/Lome, Africa/Porto-Novo, Europe/Paris...).
date_default_timezone_set('Africa/Abidjan');

// Emplacement de la base SQLite (créée automatiquement au premier lancement).
define('DB_PATH', __DIR__ . '/../data/groupe.sqlite');

// Nombre maximum d'administrateurs (en plus de l'administrateur principal).
define('MAX_ADMINS', 7);

// Tentatives de connexion échouées tolérées par IP sur 15 minutes.
define('MAX_LOGIN_FAILS', 8);

// ---- E-mails (récupération de mot de passe, validation d'inscription) -------
// Adresse d'expédition. Sur un hébergeur, utilisez une adresse de VOTRE domaine
// (ex. noreply@monsite.com), sinon les e-mails risquent d'aller dans les spams.
define('MAIL_FROM', 'noreply@example.com');

// Adresse publique du site (ex. https://monsite.com/chorale/). Laissez vide pour
// la détecter automatiquement.
define('BASE_URL', '');

// true = copie TOUS les e-mails dans data/mail.log (utile pour tester en local).
// Même à false, un e-mail qui ne peut pas partir (cas du PC local) est écrit dans ce fichier.
define('MAIL_LOG_ALWAYS', false);

// Envoi par un serveur SMTP (recommandé : fiable chez tous les hébergeurs, et utilisable depuis votre PC).
// Exemple Gmail : SMTP_HOST 'smtp.gmail.com', SMTP_PORT 587, SMTP_SECURE 'tls',
// SMTP_USER = votre adresse Gmail, SMTP_PASS = un « mot de passe d'application » Google.
// Laissez SMTP_HOST vide pour utiliser la fonction mail() de PHP (hébergement classique).
define('SMTP_HOST', '');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls');   // 'tls' (port 587), 'ssl' (port 465) ou '' (aucun)
define('SMTP_USER', '');
define('SMTP_PASS', '');
