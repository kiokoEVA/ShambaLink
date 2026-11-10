<?php
/*
 * includes/mail.php : send_email() sends through SMTP (PHPMailer) and records every message in email_log.
 * It never throws: a failed email must not break a match, a delivery or a payment.
 * Settings live in config/mail.php (copy config/mail.example.php). Without them the message is only logged as "queued".
 */
use PHPMailer\PHPMailer\PHPMailer;

if (is_file(__DIR__ . '/../config/mail.php')) require_once __DIR__ . '/../config/mail.php';

function send_email(?int $userId, string $to, string $subject, string $html, string $text = ''): string
{
    $to = trim($to);
    $subject = mb_substr($subject, 0, 200);
    $id = 0;
    try {
        db()->prepare("INSERT INTO email_log (user_id, email, subject, status) VALUES (?,?,?, 'queued')")->execute([$userId, mb_substr($to, 0, 190), $subject]);
        $id = (int)db()->lastInsertId();
    } catch (Throwable $e) {
        error_log('email_log insert failed: ' . $e->getMessage());   // a missing log table must not stop the email
    }
    $mark = function (string $status, ?string $err = null) use ($id): void {
        if (!$id) return;
        try { db()->prepare('UPDATE email_log SET status = ?, error = ? WHERE id = ?')->execute([$status, $err, $id]); }
        catch (Throwable $e) { error_log('email_log update failed: ' . $e->getMessage()); }
    };

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { $mark('failed', 'Invalid email address'); return 'failed'; }
    if (!defined('MAIL_HOST') || MAIL_HOST === '' || !defined('MAIL_USER') || MAIL_USER === '') {
        $mark('queued', 'Email is not configured (create config/mail.php)');
        return 'queued';
    }

    try {
        require_once __DIR__ . '/PHPMailer/Exception.php';
        require_once __DIR__ . '/PHPMailer/PHPMailer.php';
        require_once __DIR__ . '/PHPMailer/SMTP.php';
        $m = new PHPMailer(true);
        $m->isSMTP();
        $m->Host = MAIL_HOST;
        $m->Port = defined('MAIL_PORT') ? (int)MAIL_PORT : 587;
        $m->SMTPAuth = true;
        $m->Username = MAIL_USER;
        $m->Password = defined('MAIL_PASS') ? MAIL_PASS : '';
        $m->SMTPSecure = (defined('MAIL_SECURE') && MAIL_SECURE === 'ssl') ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        if (defined('MAIL_VERIFY_SSL') && MAIL_VERIFY_SSL === false) {
            $m->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
        }
        $m->Timeout = 12;
        $m->CharSet = 'UTF-8';
        $m->setFrom(defined('MAIL_FROM') && MAIL_FROM !== '' ? MAIL_FROM : MAIL_USER, defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'ShambaLink');
        $m->addAddress($to);
        $m->isHTML(true);
        $m->Subject = $subject;
        $m->Body = $html;
        $m->AltBody = $text !== '' ? $text : trim(preg_replace('/\s+/', ' ', strip_tags($html)));
        $m->send();
        $mark('sent');
        return 'sent';
    } catch (Throwable $e) {
        $mark('failed', mb_substr($e->getMessage(), 0, 250));
        error_log('Email error: ' . $e->getMessage());
        return 'failed';
    }
}