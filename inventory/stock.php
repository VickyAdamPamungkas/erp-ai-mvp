<?php
session_start();

// Cek autentikasi user
if (!isset($_SESSION['user_id'])) {
    header('Location: ../public/index.php');
    exit;
}

require_once '../config/database.php';

// Ambil data stok gabungan dari database
$rows = $pdo->query("
    SELECT 
        s.quantity, 
        m.name AS material, 
        m.unit, 
        l.name AS location 
    FROM stock s 
    JOIN materials m ON m.id = s.material_id 
    JOIN locations l ON l.id = s.location_id 
    ORDER BY m.name, l.name
")->fetchAll();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Stock</title>
    <link rel="stylesheet" href="../assets/css/app.css">
</head>
<body>
    <div class="layout">
        <!-- Sidebar Navigation -->
        <aside>
            <h2>ERP Stock AI</h2>
            <a href="../dashboard/index.php">Dashboard</a>
            <a href="materials.php">Materials</a>
            <a href="locations.php">Locations</a>
            <a class="active" href="stock.php">Stock</a>
            <a href="operations.php">Operations</a>
            <a href="../ai/chat.php">🤖 AI Assistant</a>
        </aside>

        <!-- Main Content -->
        <main>
            <h1>Stock</h1>
            <div class="panel">
                <table>
                    <thead>
                        <tr>
                            <th>Material</th>
                            <th>Location</th>
                            <th>Quantity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['material']) ?></td>
                            <td><?= htmlspecialchars($r['location']) ?></td>
                            <td><?= htmlspecialchars($r['quantity'] . ' ' . $r['unit']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</body>
</html>