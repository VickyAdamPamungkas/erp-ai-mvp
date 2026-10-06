<?php
session_start();

// Jika user sudah login, arahkan langsung ke halaman dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: ../dashboard/index.php');
    exit;
}

$error = '';

// Proses form login ketika disubmit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../config/database.php';

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    // Verifikasi password menggunakan SHA-256
    if ($user && hash('sha256', $password) === $user['password']) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        
        header('Location: ../dashboard/index.php');
        exit;
    }

    $error = 'Username atau password salah.';
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>ERP AI MVP</title>
    <link rel="stylesheet" href="../assets/css/app.css">
</head>
<body class="login-page">
    <div class="login-card">
        <h1>ERP Stock AI</h1>
        <p>Conversational Inventory MVP</p>

        <?php if ($error): ?>
            <div class="alert error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <label>Username</label>
            <input name="username" required>

            <label>Password</label>
            <input type="password" name="password" required>

            <button type="submit">Masuk</button>
        </form>

        <div class="hint">Demo: admin / admin123</div>
    </div>
</body>
</html>