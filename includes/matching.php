<?php
/*
 * includes/matching.php
 * Ranks vehicles for a load. Every vehicle gets four sub-scores (0 to 100),
 * combined into one total out of 100:
 *
 *   Proximity     35%  how close the truck's base is to the pickup place
 *   Capacity      25%  how well the load fills the truck (a right-sized truck scores higher)
 *   Availability  20%  the truck is free for the pickup day and the whole trip
 *   Time          20%  how comfortably the trip fits before the deliver-by date
 *
 * A vehicle is left out completely if it is paused, too small, not free on the
 * pickup date, already has an accepted job that day, or cannot finish before
 * the deliver-by date.
 */

const MATCH_WEIGHTS = ['prox' => 0.35, 'cap' => 0.25, 'avail' => 0.20, 'time' => 0.20];
const MATCH_ROAD_FACTOR = 1.3;   // road distance is about 1.3 x straight-line distance
const MATCH_SPEED_KMH = 50;      // average truck speed
const MATCH_PROX_RANGE_KM = 300; // 0 km scores 100, 300 km or more scores 0

function haversine_km(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return 2 * 6371 * asin(min(1, sqrt($a)));
}

/** Score one vehicle for one load. Returns null if the vehicle cannot do the trip in time. */
function score_vehicle_for_load(array $load, array $veh): ?array
{
    $toPickup = haversine_km((float)$veh['b_lat'], (float)$veh['b_lng'], (float)$load['o_lat'], (float)$load['o_lng']) * MATCH_ROAD_FACTOR;
    $route    = haversine_km((float)$load['o_lat'], (float)$load['o_lng'], (float)$load['d_lat'], (float)$load['d_lng']) * MATCH_ROAD_FACTOR;
    $hours    = ($toPickup + $route) / MATCH_SPEED_KMH;

    // Proximity
    $prox = max(0, 100 - 100 * $toPickup / MATCH_PROX_RANGE_KM);

    // Capacity: share of the truck the load fills
    $cap = min(100, 100 * (int)$load['weight_kg'] / max(1, (int)$veh['capacity_kg']));

    // Availability: free through the last day of the trip?
    $tripDays = max(1, (int)ceil($hours / 24));
    $tripEnd  = date('Y-m-d', strtotime($load['pickup_date'] . ' +' . ($tripDays - 1) . ' days'));
    $avail    = $veh['available_to'] >= $tripEnd ? 100 : 60;

    // Time: how much of the allowed window the trip uses
    if (!empty($load['deliver_by'])) {
        $windowHours = ((strtotime($load['deliver_by']) - strtotime($load['pickup_date'])) / 86400 + 1) * 24;
        if ($hours > $windowHours) return null;
    } else {
        $windowHours = 48;
    }
    $time = max(0, 100 * (1 - $hours / $windowHours));

    $total = MATCH_WEIGHTS['prox'] * $prox + MATCH_WEIGHTS['cap'] * $cap
           + MATCH_WEIGHTS['avail'] * $avail + MATCH_WEIGHTS['time'] * $time;

    return [
        'prox' => (int)round($prox), 'cap' => (int)round($cap),
        'avail' => (int)round($avail), 'time' => (int)round($time),
        'total' => (int)round($total),
        'km' => (int)round($toPickup), 'distance_km' => round($toPickup, 1),
        'hours' => round($hours, 1),
    ];
}

/** Ranked list of vehicles for a load: [['veh' => vehicle row, 's' => scores, 'req' => existing request or null], ...] */
function rank_vehicles_for_load(int $loadId): array
{
    $load = fetch_one(
        'SELECT l.*, o.latitude AS o_lat, o.longitude AS o_lng, d.latitude AS d_lat, d.longitude AS d_lng
         FROM loads l
         JOIN locations o ON o.id = l.origin_id
         JOIN locations d ON d.id = l.destination_id
         WHERE l.id = ?', [$loadId]);
    if (!$load) return [];

    $vehicles = fetch_all(
        "SELECT v.*, u.name AS owner_name, b.name AS base_name, b.latitude AS b_lat, b.longitude AS b_lng,
                a.available_from, a.available_to
         FROM vehicles v
         JOIN users u ON u.id = v.owner_id
         JOIN locations b ON b.id = v.base_location_id
         JOIN vehicle_availability a ON a.vehicle_id = v.id
         WHERE v.active = 1
           AND u.driver_status = 'approved'
           AND NOT EXISTS (
                SELECT 1 FROM driver_documents dd
                WHERE dd.user_id = u.id AND dd.expiry_date IS NOT NULL AND dd.expiry_date < ?)
           AND v.capacity_kg >= ?
           AND a.available_from <= ? AND a.available_to >= ?
           AND NOT EXISTS (
                SELECT 1 FROM match_requests mr JOIN loads l2 ON l2.id = mr.load_id
                WHERE mr.vehicle_id = v.id AND mr.status = 'accepted'
                  AND l2.status IN ('matched','in_transit')
                  AND l2.pickup_date = ? AND l2.id <> ?)
         ORDER BY a.available_to DESC",
        [$load['pickup_date'], (int)$load['weight_kg'], $load['pickup_date'], $load['pickup_date'], $load['pickup_date'], $loadId]);

    // Latest request per vehicle for this load
    $reqs = [];
    foreach (fetch_all('SELECT * FROM match_requests WHERE load_id = ? ORDER BY id DESC', [$loadId]) as $r) {
        if (!isset($reqs[$r['vehicle_id']])) $reqs[$r['vehicle_id']] = $r;
    }

    $ranked = [];
    $seen = [];
    foreach ($vehicles as $v) {
        if (isset($seen[$v['id']])) continue;   // a vehicle may have several availability windows
        $seen[$v['id']] = true;
        $s = score_vehicle_for_load($load, $v);
        if ($s) $ranked[] = ['veh' => $v, 's' => $s, 'req' => $reqs[$v['id']] ?? null];
    }

    usort($ranked, fn($a, $b) => [$b['s']['total'], $a['s']['km']] <=> [$a['s']['total'], $b['s']['km']]);
    return $ranked;
}

/** Open loads that at least one of this transporter's active vehicles can carry, best fit first. */
function open_loads_board(int $ownerId): array
{
    $vehicles = fetch_all(
        "SELECT v.*, b.latitude AS b_lat, b.longitude AS b_lng, a.available_from, a.available_to
         FROM vehicles v
         JOIN users u ON u.id = v.owner_id
         JOIN locations b ON b.id = v.base_location_id
         JOIN vehicle_availability a ON a.vehicle_id = v.id
         WHERE v.owner_id = ? AND v.active = 1 AND u.driver_status = 'approved'", [$ownerId]);
    if (!$vehicles) return [];

    $row = fetch_one('SELECT MIN(expiry_date) AS d FROM driver_documents WHERE user_id = ?', [$ownerId]);
    $minExpiry = $row['d'] ?? null;

    $busy = [];   // vehicle id => pickup dates already taken by an accepted job
    foreach (fetch_all("SELECT mr.vehicle_id, l.pickup_date
                        FROM match_requests mr JOIN vehicles v ON v.id = mr.vehicle_id JOIN loads l ON l.id = mr.load_id
                        WHERE v.owner_id = ? AND mr.status = 'accepted' AND l.status IN ('matched','in_transit')", [$ownerId]) as $b) {
        $busy[$b['vehicle_id']][$b['pickup_date']] = true;
    }

    $state = [];  // load id => 'requested' (farmer asked me) or 'offered' (I offered)
    foreach (fetch_all("SELECT mr.load_id, mr.requested_by
                        FROM match_requests mr JOIN vehicles v ON v.id = mr.vehicle_id
                        WHERE v.owner_id = ? AND mr.status = 'open' ORDER BY mr.id", [$ownerId]) as $m) {
        $state[$m['load_id']] = $m['requested_by'] === 'farmer' ? 'requested' : 'offered';
    }

    $loads = fetch_all(
        "SELECT l.*, p.name AS pickup_name, p.latitude AS o_lat, p.longitude AS o_lng,
                d.name AS drop_name, d.latitude AS d_lat, d.longitude AS d_lng
         FROM loads l
         JOIN locations p ON p.id = l.origin_id
         JOIN locations d ON d.id = l.destination_id
         WHERE l.status = 'open' AND l.pickup_date >= CURDATE()
         ORDER BY l.id DESC LIMIT 50");

    $board = [];
    foreach ($loads as $l) {
        if ($minExpiry && $minExpiry < $l['pickup_date']) continue;   // a document expires before pickup
        $best = null;
        foreach ($vehicles as $v) {
            if ((int)$v['capacity_kg'] < (int)$l['weight_kg']) continue;
            if ($v['available_from'] > $l['pickup_date'] || $v['available_to'] < $l['pickup_date']) continue;
            if (!empty($busy[$v['id']][$l['pickup_date']])) continue;
            $s = score_vehicle_for_load($l, $v);
            if ($s && (!$best || $s['total'] > $best['s']['total'])) $best = ['s' => $s, 'veh' => $v];
        }
        if ($best) $board[] = ['load' => $l, 'best' => $best, 'state' => $state[$l['id']] ?? null];
    }
    usort($board, fn($a, $b) => $b['best']['s']['total'] <=> $a['best']['s']['total']);
    return $board;
}