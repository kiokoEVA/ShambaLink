<?php
/*
 * api_poll.php : tiny JSON API used by live.js.
 * Returns {"v": "<hash>"}. The hash changes whenever anything this user should see has changed.
 */
require_once 'auth.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

$role = $_GET['role'] ?? '';
if (!in_array($role, ['farmer', 'transporter'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'bad role']);
    exit;
}
$u = require_login($role);

if ($role === 'farmer') {
    $state = [
        // my loads and the state of every request on them
        fetch_all("SELECT l.id, l.status,
                          (SELECT GROUP_CONCAT(CONCAT(mr.id, ':', mr.status) ORDER BY mr.id)
                           FROM match_requests mr WHERE mr.load_id = l.id) AS reqs
                   FROM loads l WHERE l.farmer_id = ? ORDER BY l.id", [$u['id']]),
        // trucks that can be ranked: new, paused or approved vehicles change the ranking
        fetch_one("SELECT (SELECT COUNT(*) FROM vehicles WHERE active = 1) AS vc,
                          (SELECT COALESCE(MAX(id), 0) FROM vehicles) AS vm,
                          (SELECT COUNT(*) FROM vehicle_availability) AS ac,
                          (SELECT COUNT(*) FROM users WHERE role = 'transporter' AND driver_status = 'approved') AS dc"),
    ];
} else {
    $state = [
        fetch_all("SELECT id FROM loads WHERE status = 'open' AND pickup_date >= CURDATE() ORDER BY id DESC LIMIT 50"),
        fetch_all("SELECT mr.id, mr.status, l.status AS ls
                   FROM match_requests mr JOIN vehicles v ON v.id = mr.vehicle_id JOIN loads l ON l.id = mr.load_id
                   WHERE v.owner_id = ? ORDER BY mr.id", [$u['id']]),
        fetch_all('SELECT id, active FROM vehicles WHERE owner_id = ? ORDER BY id', [$u['id']]),
        fetch_one('SELECT driver_status FROM users WHERE id = ?', [$u['id']]),
    ];
}

echo json_encode(['v' => md5(json_encode($state))]);