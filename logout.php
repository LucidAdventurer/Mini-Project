<?php
/* ========================================
   PTA LOGOUT HANDLER
   File: logout.php

   SECURITY CHANGE:
   Remember-me token is deleted from the DB on logout
   so the cookie cannot be replayed after sign-out.
   ======================================== */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "config.php";

// ── Delete remember-me token from DB if one exists ──
if (isset($_COOKIE['remember_me'])) {
    $parts    = explode(':', $_COOKIE['remember_me'], 2);
    $selector = $parts[0] ?? '';

    if ($selector !== '' && preg_match('/^[0-9a-f]{24}$/', $selector)) {
        try {
            $stmt = $conn->prepare("DELETE FROM remember_tokens WHERE selector = ?");
            $stmt->execute([$selector]);
        } catch (PDOException $e) {
            error_log("logout.php: failed to delete remember token: " . $e->getMessage());
        }
    }

    // Expire the cookie on the client
    $isSecure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
    setcookie('remember_me', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

// ── Destroy session ──
$_SESSION = [];
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}
session_destroy();

header("Location: index.html");
exit;