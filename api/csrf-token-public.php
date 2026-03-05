<?php
/* ========================================
   PUBLIC CSRF TOKEN ENDPOINT
   File: api/csrf-token-public.php

   For unauthenticated pages (registration, login).
   Does NOT require a logged-in session.
   config.php seeds $_SESSION['csrf_token'] on session start,
   so the token is always available here.
   ======================================== */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Session not initialized.']);
    exit;
}

echo json_encode(['success' => true, 'token' => $_SESSION['csrf_token']]);