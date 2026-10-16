<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
try {
    require 'post_load.php';
} catch (Throwable $e) {
    echo '<pre style="white-space:pre-wrap">' . $e . '</pre>';
}