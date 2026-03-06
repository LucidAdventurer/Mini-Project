<?php
// ============================================================
// api/debug-session.php
// TEMPORARY DIAGNOSTIC — DELETE AFTER FIXING
//
// Visit this URL while logged in as admin to see what's in
// the session and why validateSession() is failing.
// ============================================================

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

echo json_encode([
    'session_status'   => session_status(), // 2 = active
    'session_id'       => session_id(),
    'session_keys'     => array_keys($_SESSION),
    'uid'              => $_SESSION['uid']       ?? 'NOT SET',
    'user_id'          => $_SESSION['user_id']   ?? 'NOT SET',
    'role'             => $_SESSION['role']       ?? 'NOT SET',
    'user_type'        => $_SESSION['user_type']  ?? 'NOT SET',
    'authenticated'    => $_SESSION['authenticated'] ?? 'NOT SET',
    'csrf_token_set'   => !empty($_SESSION['csrf_token']),
    'cookie_sent'      => isset($_COOKIE[session_name()]),
    'cookie_name'      => session_name(),
    'php_version'      => PHP_VERSION,
], JSON_PRETTY_PRINT);
