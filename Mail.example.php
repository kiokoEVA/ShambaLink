<?php
// Copy this file to config/mail.php and fill in your details. NEVER share config/mail.php or put it on GitHub.
//
// Option A, real inbox (Gmail): turn on 2-Step Verification, then create an "App password" at
//   https://myaccount.google.com/apppasswords  and paste it as MAIL_PASS (not your normal password).
// Option B, safe testing (Mailtrap): create a free inbox at https://mailtrap.io and use the SMTP details it shows.
define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587);
define('MAIL_SECURE', 'tls');                    // 'tls' for port 587, 'ssl' for port 465
define('MAIL_USER', 'yourname@gmail.com');
define('MAIL_PASS', 'your-app-password');
define('MAIL_FROM', 'yourname@gmail.com');
define('MAIL_FROM_NAME', 'ShambaLink');
define('MAIL_VERIFY_SSL', false);                // false on XAMPP if you get a certificate error; true in production