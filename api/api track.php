<?php
/*
 * api_track.php : live tracking API.
 *   POST (driver)  : save the truck's current GPS position for a load that is in transit.
 *   GET  (farmer)  : ?load_id=N returns the latest position, the recent trail, and a rough ETA.
 */
require_once 'auth.php';
require_once 'includes/matching.php';   // haversine_km() and the speed / road-factor constants
header('Content-Type: application/json');
header('Cache-Control: no-store');
$u = require_login();

function out(array $data, int $code = 200): void { http_response_code($code); echo json_encode($data); exit; }

// ---------- Driver sends a position ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($u['role'] !== 'transporter') out(['ok' => false, 'error' => 'Only the driver can share a location.'], 403);

    $loadId = (int)($_POST['load_id'] ?? 0);
    $lat = filter_var($_POST['lat'] ?? null, FILTER_VALIDATE_FLOAT);
    $lng = filter_var($_POST['lng'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($lat === false || $lng === false || abs($lat) > 90 || abs($lng) > 180) out(['ok' => false, 'error' => 'Bad position.'], 400);

    // The driver must own the vehicle accepted for this load, and the load must be in transit
    $job = fetch_one("SELECT mr.vehicle_id FROM match_requests mr
                      JOIN vehicles v ON v.id = mr.vehicle_id
                      JOIN loads l ON l.id = mr.load_id
                      WHERE mr.load_id = ? AND mr.status = 'accepted' AND v.owner_id = ? AND l.status = 'in_transit'", [$loadId, $u['id']]);
    if (!$job) out(['ok' => false, 'error' => 'This trip is not in transit for you.'], 403);

    // Ignore positions that arrive less than 4 seconds after the last one
    $last = fetch_one('SELECT TIMESTAMPDIFF(SECOND, recorded_at, NOW()) AS s FROM trip_locations WHERE load_id = ? ORDER BY id DESC LIMIT 1', [$loadId]);
    if ($last && (int)$last['s'] < 4) out(['ok' => true, 'skipped' => true]);

    $speed = (isset($_POST['speed']) && is_numeric($_POST['speed'])) ? max(0, min(200, (int)$_POST['speed'])) : null;
    db()->prepare('INSERT INTO trip_locations (load_id, vehicle_id, latitude, longitude, speed_kmh) VALUES (?,?,?,?,?)')
        ->execute([$loadId, $job['vehicle_id'], $lat, $lng, $speed]);
    out(['ok' => true]);
}

// ---------- Farmer (or admin, or the driver) reads the position ----------
$loadId = (int)($_GET['load_id'] ?? 0);
$load = fetch_one("SELECT l.id, l.status, l.farmer_id,
                          p.name AS o_name, p.latitude AS o_lat, p.longitude AS o_lng,
                          d.name AS d_name, d.latitude AS d_lat, d.longitude AS d_lng
                   FROM loads l
                   JOIN locations p ON p.id = l.origin_id
                   JOIN locations d ON d.id = l.destination_id
                   WHERE l.id = ?", [$loadId]);
if (!$load) out(['error' => 'Load not found.'], 404);

$allowed = $u['role'] === 'admin' || (int)$load['farmer_id'] === (int)$u['id'];
if (!$allowed && $u['role'] === 'transporter') {
    $allowed = (bool)fetch_one("SELECT mr.id FROM match_requests mr JOIN vehicles v ON v.id = mr.vehicle_id
                                WHERE mr.load_id = ? AND mr.status = 'accepted' AND v.owner_id = ?", [$loadId, $u['id']]);
}
if (!$allowed) out(['error' => 'Not allowed.'], 403);

$rows = fetch_all('SELECT latitude, longitude, speed_kmh, TIMESTAMPDIFF(SECOND, recorded_at, NOW()) AS ago
                   FROM trip_locations WHERE load_id = ? ORDER BY id DESC LIMIT 60', [$loadId]);
$trail = array_map(fn($r) => [(float)$r['latitude'], (float)$r['longitude']], array_reverse($rows));

$lastPos = null; $remainingKm = null; $etaHours = null; $progress = null; $liveSpeed = null;
if ($rows) {
    $r = $rows[0];
    $lastPos = ['lat' => (float)$r['latitude'], 'lng' => (float)$r['longitude'], 'ago' => (int)$r['ago'],
                'speed' => $r['speed_kmh'] !== null ? (int)$r['speed_kmh'] : null];
    $remainingKm = haversine_km($lastPos['lat'], $lastPos['lng'], (float)$load['d_lat'], (float)$load['d_lng']) * MATCH_ROAD_FACTOR;
    // Speed used for the ETA: the average of the last few moving readings (clamped 20 to 90 km/h), else the default
    $moving = [];
    foreach (array_slice($rows, 0, 6) as $q) if ($q['speed_kmh'] !== null && (int)$q['speed_kmh'] >= 5) $moving[] = (int)$q['speed_kmh'];
    $liveSpeed = $moving ? array_sum($moving) / count($moving) : null;
    $speedUsed = $liveSpeed !== null ? max(20, min(90, $liveSpeed)) : MATCH_SPEED_KMH;
    $etaHours = $remainingKm / $speedUsed;
    // How far along the route the truck is, as a percentage of the pickup-to-drop-off distance
    $routeKm = haversine_km((float)$load['o_lat'], (float)$load['o_lng'], (float)$load['d_lat'], (float)$load['d_lng']) * MATCH_ROAD_FACTOR;
    if ($routeKm > 0) $progress = (int)max(0, min(100, round(100 * (1 - $remainingKm / $routeKm))));
}

out([
    'status'       => $load['status'],
    'origin'       => ['name' => $load['o_name'], 'lat' => (float)$load['o_lat'], 'lng' => (float)$load['o_lng']],
    'dest'         => ['name' => $load['d_name'], 'lat' => (float)$load['d_lat'], 'lng' => (float)$load['d_lng']],
    'last'         => $lastPos,
    'trail'        => $trail,
    'remaining_km' => $remainingKm !== null ? round($remainingKm) : null,
    'eta_hours'    => $etaHours !== null ? round($etaHours, 1) : null,
    'progress'     => $progress,
    'avg_speed'    => $liveSpeed !== null ? (int)round($liveSpeed) : null,
]);