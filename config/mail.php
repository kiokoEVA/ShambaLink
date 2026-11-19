<?php
// ShambaLink sender account. Keep this file private: never share it or put it on GitHub (.gitignore already excludes it).
define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587);
define('MAIL_SECURE', 'tls');
define('MAIL_USER', 'shambalink.web@gmail.com');
define('MAIL_PASS', 'nlmb lbyy ttfq nrbh');   // from https://myaccount.google.com/apppasswords, no spaces
define('MAIL_FROM', 'shambalink.web@gmail.com');
define('MAIL_FROM_NAME', 'Shambalink');
define('MAIL_VERIFY_SSL', false);   // false on XAMPP if you get a certificate error; true in production