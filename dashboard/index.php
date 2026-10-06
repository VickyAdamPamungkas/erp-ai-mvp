<?php
session_start();

// Cek autentikasi user
if (!isset($_SESSION['user_id'])) {
    header('Location: ../public/index.php');
    exit;
}

require_once '../config/database.php';

// Ambil data statistik untuk dashboard
$materials = $pdo->query("SELECT COUNT(*) c FROM materials")->fetch()['c'];
$locations = $pdo->query("SELECT COUNT(*) c FROM locations")->fetch()['c'];
$tx        = $pdo->query("SELECT COUNT(*) c FROM stock_transactions")->fetch()['c'];
$low       = $pdo->query("SELECT COUNT(*) c FROM stock WHERE quantity < 20")->fetch()['c'];
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Dashboard</title>
    <link rel="stylesheet" href="../assets/css/app.css">
</head>
<body>
    <div class="layout">
        <!-- Sidebar Navigation -->
        <aside>
            <h2>ERP Stock AI</h2>
            <a class="active" href="index.php">Dashboard</a>
            <a href="../inventory/materials.php">Materials</a>
            <a href="../inventory/locations.php">Locations</a>
            <a href="../inventory/stock.php">Stock</a>
            <a href="../inventory/operations.php">Operations</a>
            <a href="../ai/chat.php">🤖 AI Assistant</a>
            <a href="../public/logout.php">Logout</a>
        </aside>

        <!-- Main Content -->
        <main>
            <div class="top">
                <h1>Dashboard</h1>
                <span><?= htmlspecialchars($_SESSION['username']) ?></span>
            </div>

            <!-- Statistics Cards -->
            <div class="cards">
                <div>
                    <b><?= $materials ?></b>
                    <span>Materials</span>
                </div>
                <div>
                    <b><?= $locations ?></b>
                    <span>Locations</span>
                </div>
                <div>
                    <b><?= $tx ?></b>
                    <span>Transactions</span>
                </div>
                <div>
                    <b><?= $low ?></b>
                    <span>Low Stock</span>
                </div>
            </div>

            <!-- Information Panel -->
            <div class="panel">
                <h2>Prototype Research Flow</h2>
                <p>User → Conversational Interface → LLM → Validation Layer → Confirmation → Controlled Tool → Stock System</p>
                <p class="muted">MVP ini memakai database lokal terlebih dahulu. Integrasi Odoo API dapat ditambahkan setelah flow valid.</p>
            </div>
        </main>
    </div>
</body>
</html>