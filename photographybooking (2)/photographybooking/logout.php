<?php
// logout.php - Logout Script
require_once 'config.php';

session_unset();
session_destroy();

header("Location: index.php?msg=logged_out");
exit;
?>
