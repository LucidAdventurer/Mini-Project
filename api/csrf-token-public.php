<?php
/* ========================================
   PUBLIC CSRF TOKEN ENDPOINT
   File: api/csrf-token-public.php

   For unauthenticated pages (registration, login).
   Does NOT require a logged-in session.
   Starts the session and seeds the token itself —
   does not rely on config.php to have done it.
   ======================================== */

require_once __DIR__ . '/../config.php';

// Ensure session is active (config.php may or may not call session_start)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// Seed token if missing
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

echo json_encode(['success' => true, 'token' => $_SESSION['csrf_token']]);
