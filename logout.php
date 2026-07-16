<?php
session_start();

// Destroy all session data
$_SESSION = [];
session_unset();
session_destroy();

// Send them back to the login page
header("Location: login.php");
exit;