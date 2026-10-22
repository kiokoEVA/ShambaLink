<?php
// Every button that changes a match goes through here: request, accept, decline, cancel, start trip, deliver.
require_once 'auth.php';
require_once 'includes/matching.php';
require_once 'includes/notify.php';
require_once 'includes/sms.php';
$u = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(home_for($u['role']));
csrf_check();

$action = $_POST['action'] ?? '';
$back = $_POST['back'] ?? '';
if (!preg_match('~^[a-z_]+\.php(\?id=\d+)?$~', $back)) $back = home_for($u['role']);

function fail(string $msg, string $back): void { flash_set($msg, 'danger'); redirect($back); }
function person(int $id): array { return fetch_one('SELECT id, name, phone FROM users WHERE id = ?', [$id]) ?? ['id' => $id, 'name' => '', 'phone' => '']; }
function load_brief(int $id): array {
    return fetch_one('SELECT l.*, p.name AS pickup_name FROM loads l JOIN locations p ON p.id = l.origin_id WHERE l.id = ?', [$id]) ?? [];
}
function request_info(int $id): ?array {
    return fetch_one(<<<'SQL'
        SELECT mr.*, l.farmer_id, l.status AS load_status, l.produce, l.weight_kg, l.pickup_date, p.name AS pickup_name, v.owner_id, v.label
        FROM match_requests mr JOIN loads l ON l.id = mr.load_id JOIN vehicles v ON v.id = mr.vehicle_id JOIN locations p ON p.id = l.origin_id
        WHERE mr.id = ?
SQL, [$id]);
}
function save_request(int $loadId, int $vehId, string $by, array $s): void {
    db()->prepare(<<<'SQL'
        INSERT INTO match_requests (load_id, vehicle_id, requested_by, status, score_total, score_prox, score_cap, score_avail, score_time, distance_km)
        VALUES (?,?,?, 'open', ?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE requested_by = VALUES(requested_by), status = 'open', score_total = VALUES(score_total), score_prox = VALUES(score_prox),
            score_cap = VALUES(score_cap), score_avail = VALUES(score_avail), score_time = VALUES(score_time), distance_km = VALUES(distance_km),
            responded_at = NULL, created_at = NOW()
SQL)->execute([$loadId, $vehId, $by, $s['total'], $s['prox'], $s['cap'], $s['avail'], $s['time'], $s['km']]);
}
function short_load(array $l): string {
    return number_format((int)$l['weight_kg']) . ' kg of ' . $l['produce'] . ' from ' . $l['pickup_name'] . ' on ' . date('j M', strtotime($l['pickup_date']));
}

switch ($action) {

case 'request': {   // farmer asks a truck
    $loadId = (int)($_POST['load_id'] ?? 0); $vehId = (int)($_POST['vehicle_id'] ?? 0);
    if ($u['role'] !== 'farmer') fail('Only farmers can request a truck.', $back);
    $load = fetch_one("SELECT id FROM loads WHERE id = ? AND farmer_id = ? AND status = 'open'", [$loadId, $u['id']]);
    if (!$load) fail('That load is not open.', $back);
    $hit = null;
    foreach (rank_vehicles_for_load($loadId) as $row) if ((int)$row['veh']['id'] === $vehId) $hit = $row;
    if (!$hit) fail('That truck is no longer available for this load.', $back);
    if ($hit['req'] && in_array($hit['req']['status'], ['open', 'accepted'], true)) fail('You have already requested this truck.', $back);
    save_request($loadId, $vehId, 'farmer', $hit['s']);
    $owner = person((int)$hit['veh']['owner_id']); $lb = load_brief($loadId);
    notify($owner['id'], 'New request: ' . short_load($lb), 'transporter_dashboard.php');
    send_sms($owner['id'], $owner['phone'], APP_NAME . ': New request to carry ' . short_load($lb) . '. Open the app to reply.');
    flash_set('Request sent to ' . $hit['veh']['owner_name'] . '. You will be told when they reply.');
    redirect($back);
}

case 'request_load': {   // transporter offers a truck for a load
    $loadId = (int)($_POST['load_id'] ?? 0); $vehId = (int)($_POST['vehicle_id'] ?? 0);
    if ($u['role'] !== 'transporter') fail('Only transporters can offer a truck.', $back);
    if (!fetch_one('SELECT id FROM vehicles WHERE id = ? AND owner_id = ? AND active = 1', [$vehId, $u['id']])) fail('Choose one of your active vehicles.', $back);
    $hit = null;
    foreach (rank_loads_for_vehicle($vehId, 500) as $row) if ((int)$row['load']['id'] === $loadId) $hit = $row;
    if (!$hit) fail('That load no longer fits this vehicle.', $back);
    if ($hit['req'] && in_array($hit['req']['status'], ['open', 'accepted'], true)) fail('You have already offered for this load.', $back);
    save_request($loadId, $vehId, 'transporter', $hit['s']);
    $farmer = person((int)$hit['load']['farmer_id']); $lb = load_brief($loadId);
    notify($farmer['id'], $u['name'] . ' offered a truck for ' . short_load($lb), 'load.php?id=' . $loadId);
    send_sms($farmer['id'], $farmer['phone'], APP_NAME . ': ' . $u['name'] . ' offered a truck for your ' . short_load($lb) . '. Open the app to reply.');
    flash_set('Offer sent to ' . $hit['load']['farmer_name'] . '.');
    redirect($back);
}

case 'accept': {
    $id = (int)($_POST['request_id'] ?? 0);
    $r = request_info($id);
    if (!$r || $r['status'] !== 'open') fail('That request is no longer open.', $back);
    $responder = $r['requested_by'] === 'farmer' ? (int)$r['owner_id'] : (int)$r['farmer_id'];
    if ($responder !== (int)$u['id']) fail('You cannot respond to this request.', $back);

    $pdo = db(); $pdo->beginTransaction();
    try {
        $load = fetch_one('SELECT status FROM loads WHERE id = ? FOR UPDATE', [$r['load_id']]);
        $req  = fetch_one('SELECT status FROM match_requests WHERE id = ? FOR UPDATE', [$id]);
        if (!$load || $load['status'] !== 'open' || $req['status'] !== 'open') throw new RuntimeException('This load is no longer open.');
        $busy = fetch_one("SELECT COUNT(*) AS c FROM match_requests mr JOIN loads l ON l.id = mr.load_id
                           WHERE mr.vehicle_id = ? AND mr.status = 'accepted' AND l.status IN ('matched','in_transit') AND l.pickup_date = ? AND l.id <> ?",
                          [$r['vehicle_id'], $r['pickup_date'], $r['load_id']]);
        if ((int)$busy['c'] > 0) throw new RuntimeException('That truck already has a job on that date.');
        $others = fetch_all("SELECT v.owner_id FROM match_requests mr JOIN vehicles v ON v.id = mr.vehicle_id WHERE mr.load_id = ? AND mr.id <> ? AND mr.status = 'open'", [$r['load_id'], $id]);
        $pdo->prepare("UPDATE match_requests SET status = 'accepted', responded_at = NOW() WHERE id = ?")->execute([$id]);
        $pdo->prepare("UPDATE loads SET status = 'matched' WHERE id = ?")->execute([$r['load_id']]);
        $pdo->prepare("UPDATE match_requests SET status = 'declined', responded_at = NOW() WHERE load_id = ? AND id <> ? AND status = 'open'")->execute([$r['load_id'], $id]);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        if (!($ex instanceof RuntimeException)) error_log('accept failed: ' . $ex->getMessage());
        fail($ex instanceof RuntimeException ? $ex->getMessage() : 'Something went wrong. Please try again.', $back);
    }
    $farmer = person((int)$r['farmer_id']); $driver = person((int)$r['owner_id']); $lb = load_brief((int)$r['load_id']);
    notify($farmer['id'], 'Confirmed: ' . $driver['name'] . ' (' . format_phone($driver['phone']) . ') will carry your ' . $r['produce'] . '.', 'load.php?id=' . $r['load_id']);
    notify($driver['id'], 'Confirmed: ' . short_load($lb) . '. Farmer ' . $farmer['name'] . ' (' . format_phone($farmer['phone']) . ').', 'transporter_dashboard.php');
    send_sms($farmer['id'], $farmer['phone'], APP_NAME . ': Confirmed. ' . $driver['name'] . ' ' . format_phone($driver['phone']) . ' will carry your ' . $r['produce'] . '. Pickup ' . date('j M', strtotime($r['pickup_date'])) . '.');
    send_sms($driver['id'], $driver['phone'], APP_NAME . ': Confirmed. ' . short_load($lb) . '. Farmer ' . $farmer['name'] . ' ' . format_phone($farmer['phone']) . '.');
    foreach ($others as $o) notify((int)$o['owner_id'], 'A load you were requested for has been given to another truck.', 'transporter_dashboard.php');
    flash_set('Confirmed. Both of you have been sent each other\'s phone number.');
    redirect($back);
}

case 'decline': case 'cancel_request': {
    $id = (int)($_POST['request_id'] ?? 0);
    $r = request_info($id);
    if (!$r || $r['status'] !== 'open') fail('That request is no longer open.', $back);
    $requesterId = $r['requested_by'] === 'farmer' ? (int)$r['farmer_id'] : (int)$r['owner_id'];
    $responderId = $r['requested_by'] === 'farmer' ? (int)$r['owner_id'] : (int)$r['farmer_id'];
    if ($action === 'decline') {
        if ($u['id'] != $responderId) fail('You cannot respond to this request.', $back);
        db()->prepare("UPDATE match_requests SET status = 'declined', responded_at = NOW() WHERE id = ?")->execute([$id]);
        notify($requesterId, 'Your request for ' . $r['produce'] . ' was declined.', $requesterId == $r['farmer_id'] ? 'load.php?id=' . $r['load_id'] : 'transporter_dashboard.php');
        flash_set('Request declined.');
    } else {
        if ($u['id'] != $requesterId) fail('You cannot cancel this request.', $back);
        db()->prepare("UPDATE match_requests SET status = 'cancelled', responded_at = NOW() WHERE id = ?")->execute([$id]);
        flash_set('Request cancelled.');
    }
    redirect($back);
}

case 'cancel_load': {
    $loadId = (int)($_POST['load_id'] ?? 0);
    $l = fetch_one("SELECT * FROM loads WHERE id = ? AND farmer_id = ? AND status IN ('open','matched')", [$loadId, $u['id']]);
    if (!$l) fail('That load cannot be cancelled.', $back);
    $acc = fetch_one("SELECT v.owner_id FROM match_requests mr JOIN vehicles v ON v.id = mr.vehicle_id WHERE mr.load_id = ? AND mr.status = 'accepted'", [$loadId]);
    $pdo = db(); $pdo->beginTransaction();
    $pdo->prepare("UPDATE match_requests SET status = 'cancelled', responded_at = NOW() WHERE load_id = ? AND status IN ('open','accepted')")->execute([$loadId]);
    $pdo->prepare("UPDATE loads SET status = 'cancelled' WHERE id = ?")->execute([$loadId]);
    $pdo->commit();
    if ($acc) {
        $d = person((int)$acc['owner_id']); $lb = load_brief($loadId);
        notify($d['id'], 'Cancelled by the farmer: ' . short_load($lb), 'transporter_dashboard.php');
        send_sms($d['id'], $d['phone'], APP_NAME . ': The farmer cancelled ' . short_load($lb) . '.');
    }
    flash_set('Load cancelled.');
    redirect('farmer_dashboard.php');
}

case 'start_trip': case 'deliver': {
    $loadId = (int)($_POST['load_id'] ?? 0);
    $job = fetch_one("SELECT l.status, l.farmer_id, l.produce FROM match_requests mr JOIN vehicles v ON v.id = mr.vehicle_id JOIN loads l ON l.id = mr.load_id
                      WHERE mr.load_id = ? AND mr.status = 'accepted' AND v.owner_id = ?", [$loadId, $u['id']]);
    if (!$job) fail('You do not have this job.', $back);
    $from = $action === 'start_trip' ? 'matched' : 'in_transit';
    $to   = $action === 'start_trip' ? 'in_transit' : 'delivered';
    if ($job['status'] !== $from) fail('That step is not available right now.', $back);
    db()->prepare('UPDATE loads SET status = ? WHERE id = ?')->execute([$to, $loadId]);
    notify((int)$job['farmer_id'], $action === 'start_trip' ? 'Your ' . $job['produce'] . ' is on the way.' : 'Delivered: your ' . $job['produce'] . ' has reached its destination.', 'load.php?id=' . $loadId);
    flash_set($action === 'start_trip' ? 'Trip started.' : 'Marked as delivered. Thank you.');
    redirect($back);
}

default:
    fail('Unknown action.', $back);
}