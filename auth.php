<?php
// Include this at the top of every page. It loads config, starts the session
// and provides the login guard.
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function current_user(): ?array {
    static $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['uid'])) {
            $u = fetch_one(
                'SELECT u.*, l.name AS location FROM users u LEFT JOIN locations l ON l.id = u.location_id WHERE u.id = ?',
                [$_SESSION['uid']]
            );
        }
    }
    return $u;
}

function home_for(string $role): string {
    return ['farmer' => 'farmer_dashboard.php', 'transporter' => 'transporter_dashboard.php', 'admin' => 'admin_dashboard.php'][$role];
}

/** Redirects guests to login, and sends people with the wrong role to their own dashboard. */
function require_login(?string $role = null): array {
    $u = current_user();
    if (!$u) redirect('login.php');
    if ($role !== null && $u['role'] !== $role) redirect(home_for($u['role']));
    return $u;
}

function csrf_field(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return '<input type="hidden" name="csrf" value="' . $_SESSION['csrf'] . '">';
}

function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419);
        exit('Your session expired. Go back, refresh the page and try again.');
    }
}
