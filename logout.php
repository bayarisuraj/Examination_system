<?php
session_start();

// 1. Set flash message BEFORE wiping session
$_SESSION['flash_success'] = 'You have been successfully logged out.';

// 2. Clear the superglobal (removes all in-memory session data)
$old_flash = $_SESSION['flash_success'];
$_SESSION = [];

// 3. Expire the session cookie in the browser
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

// 4. Destroy the server-side session file
session_destroy();

// 5. Start a clean new session just to carry the flash message
session_start();
session_regenerate_id(true);
$_SESSION['flash_success'] = $old_flash;

// 6. Redirect to login
header("Location: login.php");
exit;