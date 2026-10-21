<?php
$page_title = 'Users';
require_once __DIR__ . '/includes/header.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add') {
            $name = trim($_POST['full_name'] ?? ''); $user = trim($_POST['username'] ?? '');
            $pass = $_POST['password'] ?? ''; $role = $_POST['role'] === 'admin' ? 'admin' : 'staff';
            if ($name === '' || $user === '' || strlen($pass) < 6) throw new RuntimeException('Fill in all fields; password needs 6+ characters.');
            $pdo->prepare("INSERT INTO users (full_name, username, password, role) VALUES (?,?,?,?)")
                ->execute([$name, $user, password_hash($pass, PASSWORD_DEFAULT), $role]);
            flash('success', 'User created.');
        } elseif ($action === 'reset') {
            $pass = $_POST['password'] ?? '';
            if (strlen($pass) < 6) throw new RuntimeException('Password needs 6+ characters.');
            $pdo->prepare("UPDATE users SET password=? WHERE user_id=?")->execute([password_hash($pass, PASSWORD_DEFAULT), (int)$_POST['user_id']]);
            flash('success', 'Password reset.');
        } elseif ($action === 'delete') {
            $uid = (int)$_POST['user_id'];
            if ($uid === (int)$_SESSION['user_id']) throw new RuntimeException('You cannot delete your own account.');
            $pdo->prepare("DELETE FROM users WHERE user_id=?")->execute([$uid]);
            flash('success', 'User deleted.');
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    } catch (PDOException $ex) {
        $m = $ex->getCode() == 23000
            ? ($action === 'delete' ? 'This user has recorded sales or stock movements and cannot be deleted.' : 'That username is already taken.')
            : 'Database error.';
        flash('error', $m);
    }
    redirect('users.php');
}
$users = $pdo->query("SELECT * FROM users ORDER BY role, full_name")->fetchAll();
?>
<h1>Users</h1>
<div class="card"><h2>Add user</h2>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="form-grid">
        <div><label>Full name</label><input name="full_name" required></div>
        <div><label>Username</label><input name="username" required></div>
        <div><label>Password</label><input type="password" name="password" minlength="6" required></div>
        <div><label>Role</label><select name="role"><option value="staff">Staff</option><option value="admin">Admin</option></select></div>
    </div>
    <p><button class="btn">Create User</button></p>
</form></div>
<div class="card table-wrap"><table>
    <tr><th>Name</th><th>Username</th><th>Role</th><th>Reset password</th><th></th></tr>
    <?php foreach ($users as $u): ?>
    <tr><td><?= e($u['full_name']) ?></td><td><?= e($u['username']) ?></td><td><span class="badge"><?= e($u['role']) ?></span></td>
        <td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
            <input type="password" name="password" minlength="6" placeholder="New password" style="width:160px;display:inline-block" required> <button class="btn btn-sm">Reset</button></form></td>
        <td><?php if ((int)$u['user_id'] !== (int)$_SESSION['user_id']): ?>
            <form method="post" class="inline" onsubmit="return confirm('Delete this user?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>"><button class="btn btn-sm btn-danger">Delete</button></form>
            <?php else: ?><span class="muted">(you)</span><?php endif; ?></td></tr>
    <?php endforeach; ?>
</table></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
