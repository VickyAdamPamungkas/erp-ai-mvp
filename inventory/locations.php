<?php
session_start();

// Cek autentikasi user
if (!isset($_SESSION['user_id'])) {
    header('Location: ../public/index.php');
    exit;
}

require_once '../config/database.php';

// Ambil data lokasi dari database
$rows = $pdo->query("SELECT * FROM locations ORDER BY id")->fetchAll();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Locations</title>
    <link rel="stylesheet" href="../assets/css/app.css">
</head>
<body>
    <div class="layout">
        <!-- Sidebar Navigation -->
        <aside>
            <h2>ERP Stock AI</h2>
            <a href="../dashboard/index.php">Dashboard</a>
            <a href="materials.php">Materials</a>
            <a class="active" href="locations.php">Locations</a>
            <a href="stock.php">Stock</a>
            <a href="operations.php">Operations</a>
            <a href="../ai/chat.php">🤖 AI Assistant</a>
        </aside>

        <!-- Main Content -->
        <main>
            <h1>Locations</h1>
            <div class="panel">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Location</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= $r['id'] ?></td>
                            <td><?= htmlspecialchars($r['name']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</body>
</html>