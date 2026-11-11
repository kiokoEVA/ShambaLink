<?php
/*
 * includes/receipt.php : Uber-style email receipts.
 *   send_trip_receipt($loadId)    : when a load is marked delivered (farmer gets the receipt, driver a trip summary)
 *   send_payment_receipt($loadId) : when the driver confirms the payment
 * Neither function throws.
 */
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/matching.php';   // haversine_km() and the road factor

function receipt_data(int $loadId): ?array
{
    $r = fetch_one(
        "SELECT l.id, l.produce, l.weight_kg, l.pickup_date, l.status, l.agreed_fare_kes,
                l.payment_status, l.payment_method, l.payment_ref, l.paid_at,
                f.id AS farmer_id, f.name AS farmer_name, f.email AS farmer_email,
                d.id AS driver_id, d.name AS driver_name, d.email AS driver_email, d.phone AS driver_phone,
                v.label, v.plate,
                p.name AS pickup_name, p.latitude AS o_lat, p.longitude AS o_lng,
                t.name AS drop_name, t.latitude AS d_lat, t.longitude AS d_lng
         FROM loads l
         JOIN users f ON f.id = l.farmer_id
         JOIN match_requests mr ON mr.load_id = l.id AND mr.status = 'accepted'
         JOIN vehicles v ON v.id = mr.vehicle_id
         JOIN users d ON d.id = v.owner_id
         JOIN locations p ON p.id = l.origin_id
         JOIN locations t ON t.id = l.destination_id
         WHERE l.id = ?", [$loadId]);
    return $r ?: null;
}

function receipt_number(array $r): string { return 'SL-' . str_pad((string)$r['id'], 6, '0', STR_PAD_LEFT); }

function receipt_html(array $r, string $headline, string $intro): string
{
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $km = (int)round(haversine_km((float)$r['o_lat'], (float)$r['o_lng'], (float)$r['d_lat'], (float)$r['d_lng']) * MATCH_ROAD_FACTOR);
    $fare = $r['agreed_fare_kes'] ? 'KES ' . number_format((int)$r['agreed_fare_kes']) : 'Not recorded';
    $pay = match ($r['payment_status']) {
        'confirmed'   => 'Paid (' . ($r['payment_method'] === 'mpesa' ? 'M-Pesa ' . $r['payment_ref'] : 'cash') . '), confirmed by the driver',
        'farmer_paid' => 'Marked as paid by the farmer, waiting for the driver to confirm',
        default       => 'Unpaid. Please pay the driver directly (cash or M-Pesa) and record it in the app',
    };
    $row = fn($k, $v) => '<tr><td style="padding:8px 0;color:#667;width:38%;vertical-align:top">' . $e($k) . '</td><td style="padding:8px 0;color:#111;vertical-align:top">' . $v . '</td></tr>';

    return '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:560px;margin:0 auto;color:#111">'
        . '<div style="background:#0b221b;color:#fff;padding:18px 22px;border-radius:12px 12px 0 0"><b style="font-size:20px">ShambaLink</b></div>'
        . '<div style="border:1px solid #e3e8e5;border-top:0;border-radius:0 0 12px 12px;padding:22px">'
        . '<h2 style="margin:0 0 6px">' . $e($headline) . '</h2>'
        . '<p style="margin:0 0 16px;color:#445">' . $e($intro) . '</p>'
        . '<div style="font-size:34px;font-weight:700;margin:8px 0 2px">' . $e($fare) . '</div>'
        . '<div style="color:#667;margin-bottom:16px">Receipt ' . $e(receipt_number($r)) . '</div>'
        . '<table style="width:100%;border-collapse:collapse;border-top:1px solid #e3e8e5">'
        . $row('Produce', $e($r['produce']) . ', ' . number_format((int)$r['weight_kg']) . ' kg')
        . $row('Route', $e($r['pickup_name']) . ' &rarr; ' . $e($r['drop_name']) . ' (about ' . $km . ' km)')
        . $row('Pickup date', $e(date('j M Y', strtotime($r['pickup_date']))))
        . $row('Driver', $e($r['driver_name']) . ', ' . $e($r['driver_phone']))
        . $row('Truck', $e($r['label']) . ($r['plate'] ? ', ' . $e($r['plate']) : ''))
        . $row('Farmer', $e($r['farmer_name']))
        . $row('Payment', $e($pay))
        . '</table>'
        . '<p style="margin:18px 0 0;font-size:12px;color:#889">ShambaLink records payments but does not process money. Pay the driver directly.</p>'
        . '</div></div>';
}

function send_trip_receipt(int $loadId): void
{
    try {
        $r = receipt_data($loadId);
        if (!$r) return;
        $no = receipt_number($r);
        if (!empty($r['farmer_email'])) {
            send_email((int)$r['farmer_id'], $r['farmer_email'], "ShambaLink receipt $no: " . $r['produce'],
                receipt_html($r, 'Your delivery receipt', 'Your produce has been delivered. Here is your receipt.'));
        }
        if (!empty($r['driver_email'])) {
            send_email((int)$r['driver_id'], $r['driver_email'], "Trip completed $no: " . $r['produce'],
                receipt_html($r, 'Trip completed', 'Thank you for the delivery. The farmer should now pay you directly.'));
        }
    } catch (Throwable $e) { error_log('trip receipt failed: ' . $e->getMessage()); }
}

function send_payment_receipt(int $loadId): void
{
    try {
        $r = receipt_data($loadId);
        if (!$r) return;
        $no = receipt_number($r);
        if (!empty($r['farmer_email'])) {
            send_email((int)$r['farmer_id'], $r['farmer_email'], "Payment confirmed $no",
                receipt_html($r, 'Payment confirmed', 'The driver confirmed your payment. Thank you.'));
        }
        if (!empty($r['driver_email'])) {
            send_email((int)$r['driver_id'], $r['driver_email'], "Payment received $no",
                receipt_html($r, 'Payment received', 'You confirmed this payment.'));
        }
    } catch (Throwable $e) { error_log('payment receipt failed: ' . $e->getMessage()); }
}