<?php
session_start();

// Optional: Log the logout event
// $userId = $_SESSION['uid'];
// logLogoutEvent($userId);

// Destroy session
session_unset();
session_destroy();

// Clear session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time()-3600, '/');
}

// Redirect to login
header("Location: index.html");
exit;
?>