<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('dean', 'secretary');

$pageTitle = 'Manage Users';
$action = $_GET['action'] ?? 'list';
$error = $success = '';

// Handle create
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName  = trim($_POST['first_name'] ?? '');
    $lastName   = trim($_POST['last_name'] ?? '');
    $username   = trim($_POST['username'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $role       = $_POST['role'] ?? '';
    $department = trim($_POST['department'] ?? '');
    $password   = $_POST['password'] ?? '';

    if (empty($firstName) || empty($lastName) || empty($username) || empty($email) || empty($role) || empty($password)) {
        $error = 'All required fields must be filled.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } elseif (!in_array($role, ['faculty', 'student'])) {
        $error = 'Invalid role. Secretary can only create faculty or student accounts.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        // Check unique
        $chk = $pdo->prepare("SELECT id FROM users WHERE username=? OR email=?");
        $chk->execute([$username, $email]);
        if ($chk->fetch()) {
            $error = 'Username or email already exists.';
        } else {
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password, first_name, last_name, email, role, department, is_first_login) VALUES (?,?,?,?,?,?,?,1)");
            $stmt->execute([$username, $hashed, $firstName, $lastName, $email, $role, $department]);
            flashMessage('success', 'User account created successfully.');
            redirect(BASE_URL . '/admin/users.php');
        }
    }
}

// Handle toggle active
if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $uid = (int)$_GET['toggle'];
    $pdo->prepare("UPDATE users SET is_active = NOT is_active WHERE id=? AND role NOT IN ('dean','secretary')")->execute([$uid]);
    flashMessage('success', 'User status updated.');
    redirect(BASE_URL . '/admin/users.php');
}

// Handle delete
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $uid = (int)$_GET['delete'];
    $pdo->prepare("UPDATE users SET is_active=0 WHERE id=? AND role NOT IN ('dean','secretary')")->execute([$uid]);
    flashMessage('success', 'User deactivated.');
    redirect(BASE_URL . '/admin/users.php');
}

// List users
$roleFilter = $_GET['role_filter'] ?? '';
$search     = $_GET['search'] ?? '';
$sql = "SELECT * FROM users WHERE role NOT IN ('dean','secretary')";
$params = [];
if ($roleFilter) { $sql .= " AND role=?"; $params[] = $roleFilter; }
if ($search)     { $sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR username LIKE ? OR email LIKE ?)"; $s = "%$search%"; $params = array_merge($params, [$s,$s,$s,$s]); }
$sql .= " ORDER BY role, last_name, first_name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

include '../includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-users"></i> Manage Users</h1>
    <?php if ($_SESSION['role'] === 'secretary'): ?>
    <a href="?action=create" class="btn btn-primary"><i class="fas fa-user-plus"></i> Add User</a>
    <?php endif; ?>
</div>

<?php if ($action === 'create'): ?>
<div class="card mb-4">
    <div class="card-header">
        <h3><i class="fas fa-user-plus"></i> Create New Account</h3>
        <a href="<?= BASE_URL ?>/admin/users.php" class="btn btn-sm btn-secondary">Back</a>
    </div>
    <div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger"><?= sanitize($error) ?></div><?php endif; ?>
        <form method="POST">
            <div class="form-row">
                <div class="form-group col-6">
                    <label>First Name *</label>
                    <input type="text" name="first_name" class="form-control" value="<?= sanitize($_POST['first_name'] ?? '') ?>" required>
                </div>
                <div class="form-group col-6">
                    <label>Last Name *</label>
                    <input type="text" name="last_name" class="form-control" value="<?= sanitize($_POST['last_name'] ?? '') ?>" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group col-6">
                    <label>Username *</label>
                    <input type="text" name="username" class="form-control" value="<?= sanitize($_POST['username'] ?? '') ?>" required>
                </div>
                <div class="form-group col-6">
                    <label>Email *</label>
                    <input type="email" name="email" class="form-control" value="<?= sanitize($_POST['email'] ?? '') ?>" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group col-4">
                    <label>Role *</label>
                    <select name="role" class="form-control" required>
                        <option value="">Select Role</option>
                        <option value="faculty" <?= ($_POST['role'] ?? '') === 'faculty' ? 'selected' : '' ?>>Faculty</option>
                        <option value="student" <?= ($_POST['role'] ?? '') === 'student' ? 'selected' : '' ?>>Student</option>
                    </select>
                </div>
                <div class="form-group col-4">
                    <label>Department</label>
                    <input type="text" name="department" class="form-control" value="<?= sanitize($_POST['department'] ?? '') ?>">
                </div>
                <div class="form-group col-4">
                    <label>Temporary Password *</label>
                    <input type="text" name="password" class="form-control" minlength="8" placeholder="Min 8 characters" required>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Create Account</button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Filter -->
<div class="card mb-3">
    <div class="card-body filter-bar">
        <form method="GET" class="filter-form">
            <input type="text" name="search" class="form-control" placeholder="Search by name, username, email..." value="<?= sanitize($search) ?>">
            <select name="role_filter" class="form-control">
                <option value="">All Roles</option>
                <option value="faculty" <?= $roleFilter === 'faculty' ? 'selected' : '' ?>>Faculty</option>
                <option value="student" <?= $roleFilter === 'student' ? 'selected' : '' ?>>Student</option>
            </select>
            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
            <a href="?" class="btn btn-secondary">Reset</a>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Department</th>
                    <th>Status</th>
                    <th>First Login</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= sanitize($u['last_name'] . ', ' . $u['first_name']) ?></td>
                    <td><?= sanitize($u['username']) ?></td>
                    <td><?= sanitize($u['email']) ?></td>
                    <td><span class="badge badge-<?= $u['role'] ?>"><?= ucfirst($u['role']) ?></span></td>
                    <td><?= sanitize($u['department'] ?? '-') ?></td>
                    <td>
                        <span class="badge <?= $u['is_active'] ? 'badge-success' : 'badge-danger' ?>">
                            <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($u['is_first_login']): ?>
                            <span class="badge badge-warning">Pending</span>
                        <?php else: ?>
                            <span class="badge badge-success">Done</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($_SESSION['role'] === 'secretary'): ?>
                        <a href="?action=edit&id=<?= $u['id'] ?>" class="btn btn-sm btn-info" title="Edit">
                            <i class="fas fa-edit"></i>
                        </a>
                        <a href="?toggle=<?= $u['id'] ?>" class="btn btn-sm <?= $u['is_active'] ? 'btn-warning' : 'btn-success' ?>" 
                           onclick="return confirm('Toggle user status?')" title="Toggle Status">
                            <i class="fas fa-<?= $u['is_active'] ? 'ban' : 'check' ?>"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($users)): ?>
                <tr><td colspan="8" class="text-center text-muted">No users found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
