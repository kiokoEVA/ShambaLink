<?php
// Run once in the browser (http://localhost/shambalink/install.php) to create an admin and demo accounts.
// Delete this file afterwards.
require_once 'auth.php';
header('Content-Type: text/plain; charset=utf-8');
if (fetch_one("SELECT id FROM users WHERE role = 'admin'")) exit("Already installed. Delete install.php.\n");

$loc = fn(string $n) => (int)fetch_one('SELECT id FROM locations WHERE name = ?', [$n])['id'];
$add = function (string $name, string $phone, string $role, string $where, string $pw) use ($loc) {
    db()->prepare('INSERT INTO users (name, phone, role, location_id, password_hash) VALUES (?,?,?,?,?)')
        ->execute([$name, $phone, $role, $loc($where), password_hash($pw, PASSWORD_DEFAULT)]);
    return (int)db()->lastInsertId();
};
$add('Administrator', '+254700000000', 'admin', 'Nairobi', 'Admin@12345');
$add('Wanjiru Kamau', '+254711000001', 'farmer', 'Mlolongo', 'Password123');
$from = date('Y-m-d'); $to = date('Y-m-d', strtotime('+60 days'));
$fleet = [
    ['Kioko Haulage',      '+254722000001', 'Machakos', 'Isuzu FRR, 5 tonne', 5000, 65],
    ['Mwangi Transporters','+254722000002', 'Nairobi',  'Canter, 3 tonne',    3000, 55],
    ['Otieno Logistics',   '+254722000003', 'Kisumu',   'Lorry, 10 tonne',   10000, 90],
    ['Njeri Pickups',      '+254722000004', 'Mlolongo', 'Pickup, 1 tonne',    1000, 40],
];
foreach ($fleet as [$n, $ph, $where, $veh, $cap, $rate]) {
    $id = $add($n, $ph, 'transporter', $where, 'Password123');
    db()->prepare('INSERT INTO offers (transporter_id, vehicle_type, capacity_kg, base_id, available_from, available_to, rate_per_km) VALUES (?,?,?,?,?,?,?)')
        ->execute([$id, $veh, $cap, $loc($where), $from, $to, $rate]);
}
echo "Done. Delete install.php now.\n\nAdmin       0700000000  Admin@12345\nFarmer      0711000001  Password123\nTransporter 0722000001 to 0722000004  Password123\n";
