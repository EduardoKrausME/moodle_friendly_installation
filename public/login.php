<?php

use app\Auth;

require_once __DIR__ . '/app/bootstrap.php';

if (Auth::check()) {
    redirect_to('/');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (Auth::attempt($username, $password)) {
        redirect_to('/');
    }
    $error = t('login.invalid_credentials');
}

render_header(t('login.title'));
echo render_app_template('page/login', [
    'has_error' => $error != null,
    'error' => $error,
    'username' => 'admin',
]);
render_footer();
