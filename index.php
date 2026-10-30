<?php
require __DIR__ . '/inc/bootstrap.php';
if ((int)val('SELECT COUNT(*) FROM users') === 0) redirect('install.php');
redirect(current_user() ? 'dashboard.php' : 'login.php');
