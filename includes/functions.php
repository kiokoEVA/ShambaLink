<?php
require_once __DIR__ . '/sms.php';

// XAMPP has mbstring on by default; these keep the site working if a host has it off.
if (!function_exists('mb_strlen'))  { function mb_strlen($s)             { return strlen(utf8_decode((string)$s)); } }
if (!function_exists('mb_substr'))  { function mb_substr($s, $a, $l = null) { return $l === null ? substr($s, $a) : substr($s, $a, $l); } }

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function redirect(string $url): void { header('Location: ' . $url); exit; }

function flash_set(string $msg, string $type = 'success'): void { $_SESSION['flash'] = [$type, $msg]; }
function flash_get(): string {
    if (empty($_SESSION['flash'])) return '';
    [$type, $msg] = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return '<div class="alert alert-' . e($type) . '" role="status">' . e($msg) . '</div>';
}

/** Kenyan mobile numbers: 0712 345 678, +254712345678, 712345678 all become 254712345678. */
function normalize_phone(string $raw): ?string {
    $d = preg_replace('/\D+/', '', $raw);
    if (preg_match('/^0([17]\d{8})$/', $d, $m) || preg_match('/^([17]\d{8})$/', $d, $m)) $d = '254' . $m[1];
    return preg_match('/^254[17]\d{8}$/', $d) ? $d : null;
}
function format_phone(?string $p): string {
    if (!$p || strlen($p) !== 12) return (string)$p;
    return '0' . substr($p, 3, 3) . ' ' . substr($p, 6, 3) . ' ' . substr($p, 9, 3);
}

function location_options(int $selected = 0, string $placeholder = 'Choose a place'): string {
    $o = '<option value="">' . e($placeholder) . '</option>';
    foreach (fetch_all('SELECT id, name FROM locations ORDER BY name') as $l) {
        $o .= '<option value="' . (int)$l['id'] . '"' . ((int)$l['id'] === $selected ? ' selected' : '') . '>' . e($l['name']) . '</option>';
    }
    return $o;
}

function fmt_date(?string $d): string { return $d ? date('D j M Y', strtotime($d)) : ''; }

function status_badge(string $s): string {
    $labels = ['in_transit' => 'In transit', 'open' => 'Open', 'matched' => 'Matched', 'delivered' => 'Delivered',
               'cancelled' => 'Cancelled', 'accepted' => 'Accepted', 'declined' => 'Declined'];
    return '<span class="st st-' . e($s) . '">' . e($labels[$s] ?? ucfirst($s)) . '</span>';
}

function notify(int $userId, string $message, ?string $link = null): void {
    db()->prepare('INSERT INTO notifications (user_id, message, link) VALUES (?,?,?)')->execute([$userId, mb_substr($message, 0, 255), $link]);
}
function unread_count(int $userId): int {
    return (int)(fetch_one('SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0', [$userId])['c'] ?? 0);
}

/** The four-part score bars. $s needs keys prox, cap, avail, time. */
function gauge(array $s): string {
    $rows = ['prox' => 'Distance', 'cap' => 'Load size', 'avail' => 'Availability', 'time' => 'Delivery time'];
    $h = '<div class="gauge">';
    foreach ($rows as $k => $label) {
        $v = max(0, min(100, (int)($s[$k] ?? 0)));
        $cls = $v >= 75 ? '' : ($v >= 50 ? ' mid' : ' low');
        $h .= '<div class="gauge-row"><span>' . $label . '</span><span class="track"><span class="fill' . $cls . '" style="width:' . $v . '%"></span></span><b>' . $v . '</b></div>';
    }
    return $h . '</div>';
}

/** A small POST form that calls request_action.php. */
function action_btn(string $action, array $fields, string $label, string $cls = 'btn-crate btn-sm'): string {
    $back = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH)) . (isset($_GET['id']) ? '?id=' . (int)$_GET['id'] : '');
    $h = '<form method="post" action="request_action.php" class="d-inline">' . csrf_field()
       . '<input type="hidden" name="action" value="' . e($action) . '"><input type="hidden" name="back" value="' . e($back) . '">';
    foreach ($fields as $k => $v) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    return $h . '<button class="btn ' . e($cls) . '">' . e($label) . '</button></form> ';
}

function valid_date(string $s): bool {
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return $d && $d->format('Y-m-d') === $s;
}
