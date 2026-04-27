<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

if ($_SESSION['role'] === 'faculty' || $_SESSION['role'] === 'student') {
    $user = getCurrentUser();
    if ($user['is_first_login']) {
        redirect(BASE_URL . '/auth/change_password.php?first=1');
    }
}

redirect(BASE_URL . '/dashboard.php');
