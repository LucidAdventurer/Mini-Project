<?php
/* ========================================
   CSRF Token Endpoint
   File: api/csrf-token.php

   GET only. Returns the session CSRF token so your
   JS can attach it to all POST requests.

   Usage in JS (call once after page load):
     const { token } = await fetch('/api/csrf-token.php').then(r => r.json());
     // store token, then on every POST:
     headers: { 'X-CSRF-Token': token, 'Content-Type': 'application/json' }
   ======================================== */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db-guard.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// validateSession() aborts if no session exists
validateSession($conn);

echo json_encode(['success' => true, 'token' => getCsrfToken()]);