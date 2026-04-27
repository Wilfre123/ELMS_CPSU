<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - ELMS</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="login-page">

<?php

$isFirst = isset($_GET['first']) && $_GET['first'] == '1';
$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current  = $_POST['current_password'] ?? '';
    $new      = $_POST['new_password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    $user = getCurrentUser();

    if (!password_verify($current, $user['password'])) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($new) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $error = 'New passwords do not match.';
    } else {
        $hashed = password_hash($new, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("UPDATE users SET password = ?, is_first_login = 0 WHERE id = ?");
        $stmt->execute([$hashed, $_SESSION['user_id']]);
        $success = 'Password changed successfully!';
        if ($isFirst) {
            flashMessage('success', 'Welcome! Your password has been changed.');
            redirect(BASE_URL . '/dashboard.php');
        }
    }
}
?>

<div class="login-container">
    <div class="login-box">
        <div class="login-logo">
            <i class="fas fa-key"></i>
            <h1><?= $isFirst ? 'Set Your Password' : 'Change Password' ?></h1>
            <?php if ($isFirst): ?>
                <p class="text-warning"><i class="fas fa-exclamation-triangle"></i> You must change your password before continuing.</p>
            <?php endif; ?>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= sanitize($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?= sanitize($success) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label><i class="fas fa-lock"></i> Current Password</label>
                <input type="password" name="current_password" class="form-control" required>
            </div>
            <div class="form-group">
                <label><i class="fas fa-key"></i> New Password</label>
                <input type="password" name="new_password" class="form-control" minlength="8" required>
                <small class="form-text">Minimum 8 characters</small>
            </div>
            <div class="form-group">
                <label><i class="fas fa-check-circle"></i> Confirm New Password</label>
                <input type="password" name="confirm_password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block">
                <i class="fas fa-save"></i> Change Password
            </button>
            <?php if (!$isFirst): ?>
                <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-secondary btn-block mt-2">Cancel</a>
            <?php endif; ?>
        </form>
    </div>
</div>
</body>
</html>
