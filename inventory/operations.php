<?php
session_start();

// Cek autentikasi user
if (!isset($_SESSION['user_id'])) {
    header('Location: ../public/index.php');
    exit;
}

require_once '../config/database.php';

// Ambil data riwayat transaksi stok dari database
$rows = $pdo->query("
    SELECT 
        t.*, 
        m.name AS material, 
        m.unit, 
        sl.name AS source, 
        dl.name AS destination 
    FROM stock_transactions t 
    JOIN materials m ON m.id = t.material_id 
    LEFT JOIN locations sl ON sl.id = t.source_location_id 
    LEFT JOIN locations dl ON dl.id = t.destination_location_id 
    ORDER BY t.id DESC
")->fetchAll();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Operations</title>
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
            <a href="stock.php">Stock</a>
            <a class="active" href="operations.php">Operations</a>
            <a href="../ai/chat.php">🤖 AI Assistant</a>
        </aside>

        <!-- Main Content -->
        <main>
            <h1>Stock Operations</h1>
            <div class="panel">
                <table>
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Material</th>
                            <th>Qty</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= $r['transaction_type'] ?></td>
                            <td><?= htmlspecialchars($r['material']) ?></td>
                            <td><?= $r['quantity'] . ' ' . $r['unit'] ?></td>
                            <td><?= htmlspecialchars($r['source'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($r['destination'] ?? '-') ?></td>
                            <td><?= $r['created_at'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</body>
</html>