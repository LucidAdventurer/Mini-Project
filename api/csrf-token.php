<?php
/* ========================================
   CSRF Token Endpoint
   File: api/csrf-token.php

   Returns the session CSRF token for authenticated pages.
   Does NOT call validateSession() — that does a full DB
   round-trip and fails if the session cookie path doesn't
   match. Instead just checks session variables directly.
   ======================================== */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// Check session has a logged-in user
$uid  = $_SESSION['uid']       ?? $_SESSION['user_id']   ?? 0;
$role = $_SESSION['role']      ?? $_SESSION['user_type'] ?? '';

if (!$uid || !$role) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Session expired. Please log in again.']);
    exit;
}

// Seed token if somehow missing
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

echo json_encode(['success' => true, 'token' => $_SESSION['csrf_token']]);