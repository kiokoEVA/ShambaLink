<?php
require_once 'auth.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $_SESSION = [];
    session_destroy();
}
redirect('index.php');
