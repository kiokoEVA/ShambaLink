<?php
// Admin-only: opens an uploaded driver document. The files themselves are not reachable by URL.
require_once 'auth.php';
$u = require_login('admin');
$doc = fetch_one('SELECT * FROM driver_documents WHERE id = ?', [(int)($_GET['id'] ?? 0)]);
if (!$doc) { http_response_code(404); exit('Document not found.'); }
$path = __DIR__ . '/storage/drivers/' . basename($doc['file_name']);
if (!is_file($path)) { http_response_code(404); exit('File is missing on the server.'); }
header('Content-Type: ' . (new finfo(FILEINFO_MIME_TYPE))->file($path));
header('Content-Disposition: inline; filename="' . rawurlencode($doc['original_name']) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);