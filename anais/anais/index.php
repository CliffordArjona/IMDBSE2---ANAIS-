<?php
// Redirect to login or dashboard
require_once __DIR__ . '/includes/auth.php';
if (isLoggedIn()) {
    header('Location: /anais/dashboard.php');
} else {
    header('Location: /anais/login.php');
}
exit;
