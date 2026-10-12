<?php
// Step 2: Google sends the person back here with a one-time code.
require_once 'auth.php';
require_once 'google_config.php';

function google_fail(): void {
    flash_set('Google sign-in did not work. Please try again or use your phone number.');
    redirect('login.php');
}

$state = $_GET['state'] ?? '';
if (isset($_GET['error']) || empty($_GET['code'])
    || empty($_SESSION['g_state']) || !hash_equals($_SESSION['g_state'], $state)) {
    google_fail();
}
unset($_SESSION['g_state']);

// Swap the code for the person's identity, server to server over HTTPS.
$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_POSTFIELDS     => http_build_query([
        'code'          => $_GET['code'],
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'grant_type'    => 'authorization_code',
    ]),
]);
$res = json_decode((string)curl_exec($ch), true);
curl_close($ch);

$parts  = explode('.', $res['id_token'] ?? '');
$claims = json_decode(base64_decode(strtr($parts[1] ?? '', '-_', '+/')), true);

$ok = is_array($claims)
    && ($claims['aud'] ?? '') === GOOGLE_CLIENT_ID
    && in_array($claims['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)
    && ($claims['exp'] ?? 0) > time()
    && !empty($claims['sub'])
    && !empty($claims['email_verified']);
if (!$ok) google_fail();

// Returning Google user: log in.
$row = fetch_one('SELECT id, role FROM users WHERE google_id = ?', [$claims['sub']]);
if ($row) {
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$row['id'];
    redirect(home_for($row['role']));
}

// New Google user: we still need a phone number, role and location.
$_SESSION['google'] = [
    'sub'   => $claims['sub'],
    'email' => $claims['email'] ?? '',
    'name'  => $claims['name'] ?? '',
    'role'  => $_SESSION['g_role'] ?? 'farmer',
];
redirect('google_complete.php');
