<?php
// Handles every POST from the dashboards. Call handle_post($user) near the top of a page.
function fail(string $msg): void { flash_set($msg, 'danger'); redirect_self(); }

function handle_post(array $u): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    csrf_check();
    $pdo  = db();
    $act  = $_POST['action'] ?? '';
    $role = $u['role'];
    $uid  = (int)$u['id'];
    $today = date('Y-m-d');

    // ---- Farmer: post a load ------------------------------------------------
    if ($act === 'post_load' && $role === 'farmer') {
        $c = trim($_POST['commodity'] ?? '');
        $w = (int)($_POST['weight_kg'] ?? 0);
        $o = (int)($_POST['origin_id'] ?? 0);
        $d = (int)($_POST['destination_id'] ?? 0);
        $p = $_POST['pickup_date'] ?? '';
        $b = $_POST['deliver_by'] ?? '';
        if ($c === '' || mb_strlen($c) > 80 || $w < 1 || $w > 40000) fail('Enter the produce and a weight between 1 and 40,000 kg.');
        if (!valid_date($p) || !valid_date($b) || $p < $today || $b < $p) fail('Pickup must be today or later, and delivery on or after pickup.');
        $n = fetch_one('SELECT COUNT(*) AS n FROM locations WHERE id IN (?, ?)', [$o, $d]);
        if ($o === $d || (int)$n['n'] !== 2) fail('Choose two different locations.');

        $pdo->prepare('INSERT INTO loads (farmer_id, commodity, weight_kg, origin_id, destination_id, pickup_date, deliver_by) VALUES (?,?,?,?,?,?,?)')
            ->execute([$uid, $c, $w, $o, $d, $p, $b]);
        $load = get_load((int)$pdo->lastInsertId());
        $seen = [];
        foreach (rank_offers($load) as $r) {           // in-app heads-up for the best-fitting transporters
            if (count($seen) >= 5) break;
            if (isset($seen[$r['transporter_id']])) continue;
            $seen[$r['transporter_id']] = true;
            notify((int)$r['transporter_id'], "New load fits your {$r['vehicle_type']}: $c, $w kg, {$load['origin']} to {$load['destination']}.", 'transporter_dashboard.php', false);
        }
        flash_set('Load posted. Choose "Find transporters" to see your ranked matches.');
        redirect_self();
    }

    // ---- Farmer: cancel an open load ---------------------------------------
    if ($act === 'cancel_load' && $role === 'farmer') {
        $l = get_load((int)($_POST['load_id'] ?? 0));
        if (!$l || (int)$l['farmer_id'] !== $uid || $l['status'] !== 'open') fail('Only your open loads can be cancelled.');
        $pdo->prepare("UPDATE loads SET status = 'cancelled' WHERE id = ?")->execute([$l['id']]);
        $pdo->prepare("UPDATE matches SET status = 'cancelled' WHERE load_id = ? AND status = 'pending'")->execute([$l['id']]);
        flash_set('Load cancelled.');
        redirect_self();
    }

    // ---- Farmer picks a transporter / transporter applies to a load --------
    if (($act === 'request' && $role === 'farmer') || ($act === 'apply' && $role === 'transporter')) {
        $l = get_load((int)($_POST['load_id'] ?? 0));
        $o = get_offer((int)($_POST['offer_id'] ?? 0));
        if (!$l || !$o || $l['status'] !== 'open' || !(int)$o['active']) fail('That request is no longer possible.');
        if ($role === 'farmer' && (int)$l['farmer_id'] !== $uid) fail('That is not your load.');
        if ($role === 'transporter' && (int)$o['transporter_id'] !== $uid) fail('That is not your vehicle.');
        $sc = score_match($l, $o);
        if (!$sc) fail('That vehicle no longer fits this load.');

        $pdo->prepare("INSERT INTO matches (load_id, offer_id, initiated_by, score) VALUES (?,?,?,?)
                       ON DUPLICATE KEY UPDATE status = 'pending', initiated_by = VALUES(initiated_by), score = VALUES(score)")
            ->execute([$l['id'], $o['id'], $role, $sc['total']]);

        if ($role === 'farmer') {
            notify((int)$o['transporter_id'], "Transport request: {$l['commodity']}, {$l['weight_kg']} kg, {$l['origin']} to {$l['destination']} on {$l['pickup_date']}.", 'transporter_dashboard.php');
            flash_set('Request sent to ' . $o['transporter'] . '. You will be told when they reply.');
        } else {
            notify((int)$l['farmer_id'], "{$u['name']} can carry your {$l['commodity']} (match score {$sc['total']}/100).", 'farmer_dashboard.php');
            flash_set('Application sent to the farmer.');
        }
        redirect_self();
    }

    // ---- Accept or decline (done by the side that did not start the request)
    if (in_array($act, ['accept', 'decline'], true)) {
        $m = fetch_one('SELECT m.*, o.transporter_id FROM matches m JOIN offers o ON o.id = m.offer_id WHERE m.id = ?', [(int)($_POST['match_id'] ?? 0)]);
        $l = $m ? get_load((int)$m['load_id']) : null;
        if (!$m || !$l || $m['status'] !== 'pending' || $l['status'] !== 'open') fail('This request is no longer open.');
        $responder = $m['initiated_by'] === 'farmer' ? (int)$m['transporter_id'] : (int)$l['farmer_id'];
        $initiator = $m['initiated_by'] === 'farmer' ? (int)$l['farmer_id'] : (int)$m['transporter_id'];
        if ($responder !== $uid) fail('Only the other side can reply to this request.');

        if ($act === 'decline') {
            $pdo->prepare("UPDATE matches SET status = 'declined' WHERE id = ?")->execute([$m['id']]);
            notify($initiator, "Your request for {$l['commodity']} ({$l['origin']} to {$l['destination']}) was declined.", $m['initiated_by'] === 'farmer' ? 'farmer_dashboard.php' : 'transporter_dashboard.php', false);
            flash_set('Request declined.');
            redirect_self();
        }

        $pdo->beginTransaction();
        $won = $pdo->prepare("UPDATE loads SET status = 'matched' WHERE id = ? AND status = 'open'");
        $won->execute([$l['id']]);
        if ($won->rowCount() === 0) { $pdo->rollBack(); fail('Someone else got there first.'); }
        $pdo->prepare("UPDATE matches SET status = 'accepted' WHERE id = ?")->execute([$m['id']]);
        $pdo->prepare("UPDATE matches SET status = 'cancelled' WHERE load_id = ? AND id <> ? AND status = 'pending'")->execute([$l['id'], $m['id']]);
        $pdo->commit();

        $t = fetch_one('SELECT name, phone FROM users WHERE id = ?', [$m['transporter_id']]);
        notify((int)$l['farmer_id'], "Match confirmed: {$t['name']} ({$t['phone']}) will carry your {$l['commodity']} from {$l['origin']} on {$l['pickup_date']}.", 'farmer_dashboard.php');
        notify((int)$m['transporter_id'], "Match confirmed: collect {$l['weight_kg']} kg {$l['commodity']} at {$l['origin']} on {$l['pickup_date']}. Farmer {$l['farmer']}: {$l['farmer_phone']}.", 'transporter_dashboard.php');
        flash_set('Match confirmed. Both of you have been sent the contact details.');
        redirect_self();
    }

    // ---- Transporter: update trip status (manual, no GPS) -------------------
    if ($act === 'trip' && $role === 'transporter') {
        $l  = get_load((int)($_POST['load_id'] ?? 0));
        $to = $_POST['to'] ?? '';
        $mine = $l ? fetch_one("SELECT m.id FROM matches m JOIN offers o ON o.id = m.offer_id WHERE m.load_id = ? AND m.status = 'accepted' AND o.transporter_id = ?", [$l['id'], $uid]) : null;
        $next = ['matched' => 'in_transit', 'in_transit' => 'delivered'];
        if (!$l || !$mine || ($next[$l['status']] ?? null) !== $to) fail('That status change is not allowed.');
        $pdo->prepare('UPDATE loads SET status = ? WHERE id = ?')->execute([$to, $l['id']]);
        notify((int)$l['farmer_id'], $to === 'in_transit' ? "Your {$l['commodity']} is on the way." : "Your {$l['commodity']} was delivered to {$l['destination']}.", 'farmer_dashboard.php');
        flash_set($to === 'in_transit' ? 'Trip started.' : 'Marked as delivered.');
        redirect_self();
    }

    // ---- Transporter: post or pause a vehicle offer -------------------------
    if ($act === 'post_offer' && $role === 'transporter') {
        $vt  = trim($_POST['vehicle_type'] ?? '');
        $cap = (int)($_POST['capacity_kg'] ?? 0);
        $bid = (int)($_POST['base_id'] ?? 0);
        $f   = $_POST['available_from'] ?? '';
        $t   = $_POST['available_to'] ?? '';
        $r   = (float)($_POST['rate_per_km'] ?? 0);
        if ($vt === '' || mb_strlen($vt) > 60 || $cap < 100 || $cap > 40000) fail('Enter a vehicle type and a capacity between 100 and 40,000 kg.');
        if (!valid_date($f) || !valid_date($t) || $t < $f || $t < $today) fail('Check your availability dates.');
        if ($r < 1 || $r > 1000) fail('Enter a rate per km between KES 1 and 1,000.');
        if (!fetch_one('SELECT id FROM locations WHERE id = ?', [$bid])) fail('Choose where the vehicle is based.');
        $pdo->prepare('INSERT INTO offers (transporter_id, vehicle_type, capacity_kg, base_id, available_from, available_to, rate_per_km) VALUES (?,?,?,?,?,?,?)')
            ->execute([$uid, $vt, $cap, $bid, $f, $t, $r]);
        flash_set('Vehicle offer posted. Farmers can now find it.');
        redirect_self();
    }

    if ($act === 'toggle_offer' && $role === 'transporter') {
        $pdo->prepare('UPDATE offers SET active = 1 - active WHERE id = ? AND transporter_id = ?')->execute([(int)($_POST['offer_id'] ?? 0), $uid]);
        redirect_self();
    }
}
