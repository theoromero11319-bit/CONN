<?php
session_start();

// 1. Clear all session variables
$_SESSION = array();

// 2. Destroy the cookie session lifecycle completely
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), 
        '', 
        time() - 42000,
        $params["path"], 
        $params["domain"],
        $params["secure"], 
        $params["httponly"]
    );
}

// 3. Terminate the active session container
session_destroy();

// 4. Securely redirect back to the entry gateway login page
header("Location: index.php");
exit();
?>