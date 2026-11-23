<?php
/*
 * config/sms.php : Africa's Talking settings for ShambaLink.
 * Keep this file private: never commit it or share it.
 */
define('AT_USERNAME', 'sandbox');   // must stay "sandbox" while testing in the Sandbox app
define('AT_API_KEY', 'atsk_5c8387453a34b9ee7da5aa322bf2460340d0de47ecf029efa08ce75df632df2a9bf2643d');
define('AT_SENDER', '');            // leave empty in the sandbox
define('AT_SANDBOX', true);         // set to false only when you go live with a real app
define('AT_VERIFY_SSL', true);      // if the test says "SSL certificate problem" on localhost, set to false (local testing only)