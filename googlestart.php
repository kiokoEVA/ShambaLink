<?php
// Step 1: send the person to Google's sign-in page.
require_once 'auth.php';
require_once 'google_config.php';
if ($cu = current_user()) redirect(home_for($cu['role']));

$_SESSION['g_role']  = in_array($_GET['role'] ?? '', ['farmer', 'transporter'], true) ? $_GET['role'] : 'farmer';
$_SESSION['g_state'] = bin2hex(random_bytes(16));   // proves the reply belongs to this browser

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'state'         => $_SESSION['g_state'],
    'prompt'        => 'select_account',
]));
exit;