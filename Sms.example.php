<?php
// Copy this file to config/sms.php and fill in your Africa's Talking details. NEVER share config/sms.php or put it on GitHub.
// Sandbox: log in at https://account.africastalking.com, open the Sandbox app, create an API key.
// In the sandbox, texts appear in the Sandbox simulator (Sandbox > SMS), they are NOT delivered to real phones.
define('AT_USERNAME', 'sandbox');                // 'sandbox' while testing, your app username when live
define('AT_API_KEY', 'PASTE_YOUR_SANDBOX_KEY');
define('AT_SENDER', '');                         // leave empty in the sandbox
define('AT_SANDBOX', true);                      // false when you go live
define('AT_VERIFY_SSL', false);                  // false on XAMPP if you get an SSL certificate error; true in production