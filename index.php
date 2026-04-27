<?php require_once __DIR__ . '/includes/functions.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ELMS - E-Learning Management System</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="login-page">
<?php
if (isLoggedIn()) {
    redirect(BASE_URL . '/dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter your username and password.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['role']      = $user['role'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['name']      = $user['first_name'] . ' ' . $user['last_name'];

            if ($user['is_first_login'] && in_array($user['role'], ['faculty', 'student'])) {
                redirect(BASE_URL . '/auth/change_password.php?first=1');
            } else {
                redirect(BASE_URL . '/dashboard.php');
            }
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?>

<div class="login-container">
    <div class="login-box">
        <div class="login-logo">
            <i class="fas fa-graduation-cap"></i>
            <h1>ELMS</h1>
            <p>E-Learning Management System</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= sanitize($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label><i class="fas fa-user"></i> Username</label>
                <input type="text" name="username" class="form-control" placeholder="Enter your username"
                    value="<?= sanitize($_POST['username'] ?? '') ?>" required autofocus>
            </div>
            <div class="form-group">
                <label><i class="fas fa-lock"></i> Password</label>
                <div class="input-group">
                    <input type="password" name="password" id="password" class="form-control" placeholder="Enter your password" required>
                    <button type="button" class="toggle-password" onclick="togglePassword()">
                        <i class="fas fa-eye" id="eye-icon"></i>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-block">
                <i class="fas fa-sign-in-alt"></i> Login
            </button>
        </form>

        <div class="login-footer">
            <small>For account concerns, please contact the Secretary.</small>
        </div>
    </div>
</div>

<script>
function togglePassword() {
    const pwd = document.getElementById('password');
    const icon = document.getElementById('eye-icon');
    if (pwd.type === 'password') {
        pwd.type = 'text';
        icon.className = 'fas fa-eye-slash';
    } else {
        pwd.type = 'password';
        icon.className = 'fas fa-eye';
    }
}
</script>
</body>
</html>
