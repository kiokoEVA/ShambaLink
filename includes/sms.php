<?php
if (is_file(__DIR__ . '/../config/sms.php')) require_once __DIR__ . '/../config/sms.php';

/** Records the text in sms_log, then tries to send it. Never throws: a failed text must not break a match. */
function send_sms(?int $userId, string $phone, string $text): string {
    $text = mb_substr($text, 0, 300);
    try {
        db()->prepare("INSERT INTO sms_log (user_id, phone, message, status) VALUES (?,?,?, 'queued')")->execute([$userId, $phone, $text]);
        $id = (int)db()->lastInsertId();
        if (!defined('AT_API_KEY') || AT_API_KEY === '') {
            db()->prepare('UPDATE sms_log SET error = ? WHERE id = ?')->execute(['SMS provider not configured', $id]);
            return 'queued';
        }
        $url = AT_SANDBOX ? 'https://api.sandbox.africastalking.com/version1/messaging' : 'https://api.africastalking.com/version1/messaging';
        $fields = ['username' => AT_USERNAME, 'to' => '+' . $phone, 'message' => $text];
        if (AT_SENDER !== '') $fields['from'] = AT_SENDER;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['apiKey: ' . AT_API_KEY, 'Accept: application/json'],
            CURLOPT_POSTFIELDS => http_build_query($fields),
        ]);
        $res = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        $r = $res['SMSMessageData']['Recipients'][0] ?? null;
        if ($r && ($r['status'] ?? '') === 'Success') {
            db()->prepare("UPDATE sms_log SET status='sent', provider_ref=? WHERE id=?")->execute([$r['messageId'] ?? null, $id]);
            return 'sent';
        }
        db()->prepare("UPDATE sms_log SET status='failed', error=? WHERE id=?")->execute([mb_substr($r['status'] ?? 'No response from provider', 0, 250), $id]);
        return 'failed';
    } catch (Throwable $e) {
        error_log('SMS error: ' . $e->getMessage());
        return 'failed';
    }
}
