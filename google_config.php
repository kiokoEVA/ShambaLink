<?php
// Keep the secret out of Git: set these as server environment variables if you can.
define('GOOGLE_CLIENT_ID',     getenv('GOOGLE_CLIENT_ID')     ?: 'PASTE_CLIENT_ID_HERE');
define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: 'PASTE_CLIENT_SECRET_HERE');
// Must match the "Authorised redirect URI" in Google Cloud Console exactly.
define('GOOGLE_REDIRECT_URI',  getenv('GOOGLE_REDIRECT_URI')  ?: 'http://localhost/google_callback.php');
