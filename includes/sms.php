<?php
/*
 * includes/sms.php : send_sms() records every text in sms_log, then sends it through Africa's Talking.
 * It never throws: a failed text must not break a match. Settings live in config/sms.php (copy config/sms.example.php).
 * Without an API key the text is only logged as "queued". Use the admin page "Tests" (admin_tests.php) to check it.
 */
if (is_file(__DIR__ . '/../config/sms.php')) require_once __DIR__ . '/../config/sms.php';
foreach (['AT_USERNAME' => 'sandbox', 'AT_API_KEY' => '', 'AT_SENDER' => '', 'AT_SANDBOX' => true, 'AT_VERIFY_SSL' => true] as $k => $v) {
    if (!defined($k)) define($k, $v);
}

/** 0712345678, +254712345678 and 712345678 all become 254712345678. */
function sms_normalize_phone(string $phone): string
{
    $d = preg_replace('/\D+/', '', $phone);
    if (strlen($d) === 10 && $d[0] === '0') return '254' . substr($d, 1);
    if (strlen($d) === 9) return '254' . $d;
    return $d;
}

function send_sms(?int $userId, string $phone, string $text): string
{
    $text = mb_substr($text, 0, 300);
    $phone = sms_normalize_phone($phone);
    $id = 0;
    $set = function (string $status, ?string $error = null, ?string $ref = null) use (&$id): void {
        if (!$id) return;
        try { db()->prepare('UPDATE sms_log SET status = ?, error = ?, provider_ref = ? WHERE id = ?')->execute([$status, $error, $ref, $id]); }
        catch (Throwable $e) { error_log('sms_log update failed: ' . $e->getMessage()); }
    };
    try {
        db()->prepare("INSERT INTO sms_log (user_id, phone, message, status) VALUES (?,?,?, 'queued')")->execute([$userId, $phone, $text]);
        $id = (int)db()->lastInsertId();

        if (AT_API_KEY === '') { $set('queued', 'SMS provider not configured (create config/sms.php)'); return 'queued'; }
        if (!function_exists('curl_init')) { $set('failed', 'PHP curl is off: enable extension=curl in php.ini and restart Apache'); return 'failed'; }
        if (strlen($phone) < 11) { $set('failed', 'Phone number looks too short'); return 'failed'; }

        $url = AT_SANDBOX ? 'https://api.sandbox.africastalking.com/version1/messaging' : 'https://api.africastalking.com/version1/messaging';
        $fields = ['username' => AT_USERNAME, 'to' => '+' . $phone, 'message' => $text];
        if (AT_SENDER !== '') $fields['from'] = AT_SENDER;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => (bool)AT_VERIFY_SSL, CURLOPT_SSL_VERIFYHOST => AT_VERIFY_SSL ? 2 : 0,
            CURLOPT_HTTPHEADER => ['apiKey: ' . AT_API_KEY, 'Accept: application/json'],
            CURLOPT_POSTFIELDS => http_build_query($fields),
        ]);
        $raw = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) { $set('failed', mb_substr('Could not reach Africa\'s Talking: ' . $curlErr, 0, 250)); return 'failed'; }

        $res = json_decode((string)$raw, true);
        $r = $res['SMSMessageData']['Recipients'][0] ?? null;
        if ($r && ($r['status'] ?? '') === 'Success') { $set('sent', null, $r['messageId'] ?? null); return 'sent'; }

        $why = $r['status'] ?? ($res['SMSMessageData']['Message'] ?? ('HTTP ' . $http . ': ' . trim(strip_tags((string)$raw))));
        $set('failed', mb_substr((string)$why, 0, 250));
        return 'failed';
    } catch (Throwable $e) {
        error_log('SMS error: ' . $e->getMessage());
        $set('failed', mb_substr($e->getMessage(), 0, 250));
        return 'failed';
    }
}