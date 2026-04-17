<?php
session_start();

// Hapus semua session data
$_SESSION = [];

// Hapus session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

session_destroy();

// Redirect ke login dengan pesan
header("Location: login.php?logged_out=1");
exit();
?>