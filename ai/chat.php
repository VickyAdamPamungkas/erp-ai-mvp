<?php
session_start(); if (!isset($_SESSION['user_id'])) { header('Location: ../public/index.php'); exit; }
?>
<!doctype html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <title>AI Assistant</title>
        <link rel="stylesheet" href="../assets/css/app.css"></head>
<body>
    <div class="layout">
    <aside>
            <h2>ERP Stock AI</h2>
        <a href="../dashboard/index.php">Dashboard</a>
        <a href="../inventory/materials.php">Materials</a>
        <a href="../inventory/locations.php">Locations</a>
        <a href="../inventory/stock.php">Stock</a>
        <a href="../inventory/operations.php">Operations</a>
        <a class="active" href="chat.php">🤖 AI Assistant</a>
    </aside>
    <main>
    <h1>AI Stock Assistant</h1>
    <div class="chat panel">
        <div id="messages">
            <div class="bubble ai">Halo! Coba perintah seperti: <b>“Berapa stok kayu jati di Gudang A?”</b>
        </div>
    </div>
<form id="chatForm">
    <input id="message" placeholder="Tulis instruksi stok..." autocomplete="off">
    <button>Kirim</button>
</form>
        </div>
     </main>
    </div>
<script src="../assets/js/chat.js"></script>
</body>
</html>
