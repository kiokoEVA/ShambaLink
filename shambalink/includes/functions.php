<?php
// ---------------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------------
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function redirect(string $url): void { header('Location: ' . $url); exit; }

function redirect_self(): void {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    redirect(basename($_SERVER['SCRIPT_NAME']) . ($qs !== '' ? '?' . $qs : ''));
}

function flash_set(string $msg, string $type = 'success'): void { $_SESSION['flash'] = [$msg, $type]; }

function flash_get(): string {
    if (empty($_SESSION['flash'])) return '';
    [$m, $t] = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return '<div class="alert alert-' . e($t) . '" role="alert">' . e($m) . '</div>';
}

function fetch_all(string $sql, array $p = []): array { $s = db()->prepare($sql); $s->execute($p); return $s->fetchAll(); }
function fetch_one(string $sql, array $p = []): ?array { $s = db()->prepare($sql); $s->execute($p); return $s->fetch() ?: null; }

function valid_date($s): bool {
    $d = DateTime::createFromFormat('Y-m-d', (string)$s);
    return $d && $d->format('Y-m-d') === $s;
}

function normalize_phone(string $p): ?string {
    $d = preg_replace('/\D/', '', $p);
    if (preg_match('/^0([17]\d{8})$/', $d, $m)) $d = '254' . $m[1];
    elseif (preg_match('/^([17]\d{8})$/', $d, $m)) $d = '254' . $m[1];
    return preg_match('/^254[17]\d{8}$/', $d) ? '+' . $d : null;
}

function location_options(?int $selected = null): string {
    $h = '<option value="">Choose a location</option>';
    foreach (fetch_all('SELECT id, name FROM locations ORDER BY name') as $l) {
        $h .= '<option value="' . $l['id'] . '"' . ($selected === (int)$l['id'] ? ' selected' : '') . '>' . e($l['name']) . '</option>';
    }
    return $h;
}

function badge(string $s): string {
    return '<span class="st st-' . e($s) . '">' . e(ucfirst(str_replace('_', ' ', $s))) . '</span>';
}

function get_load(int $id): ?array {
    return fetch_one(
        'SELECT l.*, o.name AS origin, d.name AS destination, f.name AS farmer, f.phone AS farmer_phone
         FROM loads l
         JOIN locations o ON o.id = l.origin_id
         JOIN locations d ON d.id = l.destination_id
         JOIN users f ON f.id = l.farmer_id
         WHERE l.id = ?', [$id]);
}

function get_offer(int $id): ?array {
    return fetch_one(
        'SELECT o.*, u.name AS transporter, u.phone, b.name AS base
         FROM offers o JOIN users u ON u.id = o.transporter_id JOIN locations b ON b.id = o.base_id
         WHERE o.id = ?', [$id]);
}

// ---------------------------------------------------------------------------
// Matching engine: rule-based, weighted scoring (Chapter 2, section 2.6)
// ---------------------------------------------------------------------------
const W_PROXIMITY = 0.30;      // how close the truck is to the pickup point
const W_CAPACITY  = 0.30;      // how well the load fills the truck
const W_AVAIL     = 0.20;      // how comfortably the pickup date fits the truck's availability
const W_TIMELINE  = 0.20;      // how much time buffer there is to deliver
const MAX_PICKUP_KM   = 300;   // at or beyond this distance the proximity score is 0
const ROAD_FACTOR     = 1.25;  // straight-line distance to approximate road distance
const AVG_SPEED_KMH   = 40;
const LOADING_HOURS   = 2;
const DRIVE_HOURS_DAY = 12;    // usable driving hours per calendar day

function loc_km(int $a, int $b): float {
    static $L = null;
    if ($L === null) {
        $L = [];
        foreach (fetch_all('SELECT id, lat, lng FROM locations') as $r) $L[(int)$r['id']] = $r;
    }
    if ($a === $b || !isset($L[$a], $L[$b])) return 0.0;
    $p1 = deg2rad((float)$L[$a]['lat']); $p2 = deg2rad((float)$L[$b]['lat']);
    $dp = $p2 - $p1; $dl = deg2rad((float)$L[$b]['lng'] - (float)$L[$a]['lng']);
    $h = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
    return 6371 * 2 * asin(sqrt($h)) * ROAD_FACTOR;
}

/**
 * Score one offer against one load. Returns null when the offer cannot do the job
 * (too small, not available on the pickup date, or cannot deliver in time).
 */
function score_match(array $load, array $offer): ?array {
    if ((int)$offer['capacity_kg'] < (int)$load['weight_kg']) return null;
    if ($load['pickup_date'] < $offer['available_from'] || $load['pickup_date'] > $offer['available_to']) return null;

    $pickupKm = loc_km((int)$offer['base_id'], (int)$load['origin_id']);
    $routeKm  = loc_km((int)$load['origin_id'], (int)$load['destination_id']);

    $hours  = ($pickupKm + $routeKm) / AVG_SPEED_KMH + LOADING_HOURS;
    $days   = (strtotime($load['deliver_by']) - strtotime($load['pickup_date'])) / 86400 + 1;
    $window = $days * DRIVE_HOURS_DAY;
    if ($hours > $window) return null;

    // Proximity: 100 at the pickup point, falling to 0 at MAX_PICKUP_KM
    $prox = 100 * max(0, 1 - $pickupKm / MAX_PICKUP_KM);
    // Capacity fit: full marks once the load fills 80% or more of the truck
    $ratio = $load['weight_kg'] / $offer['capacity_kg'];
    $cap = $ratio >= 0.8 ? 100 : $ratio / 0.8 * 100;
    // Availability: already free on the pickup date = 60, each extra day of notice adds 20
    $early = (int)floor((strtotime($load['pickup_date']) - strtotime($offer['available_from'])) / 86400);
    $avail = min(100, 60 + 20 * $early);
    // Timeline: full marks when the trip uses up to 80% of the window, tapering to 50 at 100%
    $use = $hours / $window;
    $time = $use <= 0.8 ? 100 : 100 - ($use - 0.8) / 0.2 * 50;

    $total = W_PROXIMITY * $prox + W_CAPACITY * $cap + W_AVAIL * $avail + W_TIMELINE * $time;
    return [
        'total' => (int)round($total), 'prox' => (int)round($prox), 'cap' => (int)round($cap),
        'avail' => (int)round($avail), 'time' => (int)round($time),
        'pickup_km' => (int)round($pickupKm), 'route_km' => (int)round($routeKm),
        'hours' => round($hours, 1), 'cost' => (int)round($routeKm * (float)$offer['rate_per_km']),
    ];
}

/** All eligible active offers for a load, best first. */
function rank_offers(array $load): array {
    $out = [];
    $rows = fetch_all(
        'SELECT o.*, u.name AS transporter, u.phone, b.name AS base
         FROM offers o JOIN users u ON u.id = o.transporter_id JOIN locations b ON b.id = o.base_id
         WHERE o.active = 1');
    foreach ($rows as $o) {
        $sc = score_match($load, $o);
        if ($sc) $out[] = $o + ['score' => $sc];
    }
    usort($out, fn($a, $b) => $b['score']['total'] <=> $a['score']['total']);
    return $out;
}

function gauge(array $sc): string {
    $rows = ['prox' => 'Distance', 'cap' => 'Load fit', 'avail' => 'Availability', 'time' => 'Delivery time'];
    $h = '<div class="gauge">';
    foreach ($rows as $k => $label) {
        $v = (int)$sc[$k];
        $c = $v >= 75 ? '' : ($v >= 45 ? ' mid' : ' low');
        $h .= '<div class="gauge-row"><span>' . $label . '</span><span class="track"><span class="fill' . $c . '" style="width:' . $v . '%"></span></span><b>' . $v . '</b></div>';
    }
    return $h . '</div>';
}

// ---------------------------------------------------------------------------
// Notifications and SMS (Africa's Talking)
// ---------------------------------------------------------------------------
function notify(int $uid, string $msg, ?string $link = null, bool $sms = true): void {
    db()->prepare('INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)')
        ->execute([$uid, mb_substr($msg, 0, 250), $link]);
    if ($sms) {
        $ph = fetch_one('SELECT phone FROM users WHERE id = ?', [$uid]);
        if ($ph) send_sms($ph['phone'], APP_NAME . ': ' . $msg);
    }
}

function send_sms(string $phone, string $msg): void {
    $status = 'simulated';
    if (AT_API_KEY !== '' && function_exists('curl_init')) {
        $url = AT_USERNAME === 'sandbox'
            ? 'https://api.sandbox.africastalking.com/version1/messaging'
            : 'https://api.africastalking.com/version1/messaging';
        $fields = ['username' => AT_USERNAME, 'to' => $phone, 'message' => $msg];
        if (AT_SENDER !== '') $fields['from'] = AT_SENDER;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['apiKey: ' . AT_API_KEY, 'Accept: application/json'],
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $status = ($res !== false && $code < 300) ? 'sent' : 'failed';
    }
    db()->prepare('INSERT INTO sms_log (phone, message, status) VALUES (?, ?, ?)')
        ->execute([$phone, mb_substr($msg, 0, 480), $status]);
}
