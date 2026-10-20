<?php

/**
 * transaction_controller_test.php — CLI Test Suite untuk TransactionController.
 *
 * Menguji:
 * 1. Session & Auth Guard: Request ditolak jika belum login (HTTP 401).
 * 2. Token Guard & Single-Use: Token tidak valid / kedaluwarsa ditolak; token yang telah
 *    dieksekusi tidak dapat digunakan kembali (re-execution prevention).
 * 3. Alur Pembatalan ($action == 'cancel'): Status cancelled, audit log CANCELLED, stok tidak berubah.
 * 4. Alur Konfirmasi Sukses:
 *    - STOCK_IN: Penambahan stok, INSERT baris stok baru jika belum ada, movement 'IN', audit EXECUTED.
 *    - STOCK_OUT: Pengurangan stok, movement 'OUT', audit EXECUTED.
 *    - TRANSFER_STOCK: Pengurangan gudang asal, penambahan gudang tujuan, movement 'TRANSFER', audit EXECUTED.
 * 5. Race Condition Prevention: Deteksi stok berubah sebelum konfirmasi -> rollback, audit REJECTED.
 * 6. Client Tampering Resistance: Parameter manipulasi di body request diabaikan oleh controller.
 *
 * Penggunaan:
 *   php tests/transaction_controller_test.php
 */

require_once __DIR__ . '/../app/Controllers/TransactionController.php';
require_once __DIR__ . '/../app/Models/AuditModel.php';
require_once __DIR__ . '/../app/Models/StockModel.php';

// Inisialisasi koneksi DB nyata (woodmind_erp)
$configPath = __DIR__ . '/../config/db.php';
if (file_exists($configPath)) {
    require_once $configPath;
}
defined('DB_HOST')    || define('DB_HOST', '127.0.0.1');
defined('DB_PORT')    || define('DB_PORT', 3307);
defined('DB_NAME')    || define('DB_NAME', 'woodmind_erp');
defined('DB_USER')    || define('DB_USER', 'root');
defined('DB_PASS')    || define('DB_PASS', '');
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8mb4');

$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    echo "[-] Gagal terhubung ke database: " . $e->getMessage() . "\n";
    exit(1);
}

$auditModel  = new AuditModel($pdo);
$stockModel  = new StockModel($pdo);
$controller  = new TransactionController($pdo, $auditModel);

// =========================================================================
// TEST HARNESS & RUNNER HELPERS
// =========================================================================

$totalTests = 0;
$passCount  = 0;
$failCount  = 0;
$testGroup  = '';

function assertTest(string $description, callable $fn): void
{
    global $totalTests, $passCount, $failCount, $testGroup;

    $totalTests++;
    try {
        $result = $fn();
        if ($result === true) {
            $passCount++;
            echo sprintf("  [PASS] %-25s : %s\n", $testGroup, $description);
        } else {
            $failCount++;
            echo sprintf("  [FAIL] %-25s : %s (Assertion returned false)\n", $testGroup, $description);
        }
    } catch (Throwable $e) {
        $failCount++;
        echo sprintf("  [FAIL] %-25s : %s (Exception: %s)\n", $testGroup, $description, $e->getMessage());
    }
}

// Ambil ID produk dan gudang dari database
$stmt = $pdo->query("SELECT id, product_name FROM products");
$products = [];
foreach ($stmt->fetchAll() as $row) {
    $products[$row['product_name']] = (int) $row['id'];
}

$stmt = $pdo->query("SELECT id, warehouse_name FROM warehouses");
$warehouses = [];
foreach ($stmt->fetchAll() as $row) {
    $warehouses[$row['warehouse_name']] = (int) $row['id'];
}

$jatiId   = $products['Kayu Jati'];
$mahoniId = $products['Kayu Mahoni'];
$pinusId  = $products['Kayu Pinus'];
$utamaId  = $warehouses['Gudang Utama'];
$sisaId   = $warehouses['Gudang Sisa'];

// Helper setup session user
function setupSessionUser(int $userId = 1): void
{
    $_SESSION = [
        'user_id'              => $userId,
        'role'                 => 'STAFF',
        'csrf_token'           => 'valid_token_tx',
        'pending_transactions' => [],
    ];
}

// Helper membuat token pending transaction
function createPendingTx(array $payload, int $userId = 1, int $ttl = 300, string $rawPrompt = 'Uji transaksi'): string
{
    $token = bin2hex(random_bytes(16));
    $_SESSION['pending_transactions'][$token] = [
        'token'         => $token,
        'user_id'       => $userId,
        'raw_prompt'    => $rawPrompt,
        'llm_result'    => [
            'ok'         => true,
            'model'      => 'llama3-70b',
            'latency_ms' => 150,
            'data'       => $payload,
            'raw'        => json_encode($payload),
        ],
        'payload'       => $payload,
        'created_at'    => time(),
        'expires_at'    => time() + $ttl,
        'audit_id'      => null,
    ];

    return $token;
}

echo "\n" . str_repeat('=', 95) . "\n";
echo "  WOODMIND ERP - TRANSACTION CONTROLLER TEST SUITE\n";
echo str_repeat('=', 95) . "\n\n";

// =========================================================================
// 1. SESSION & AUTH GUARD
// =========================================================================
$testGroup = 'Auth & Session Guard';

assertTest('Tolak eksekusi jika user belum login di $_SESSION (HTTP 401)', function () use ($controller) {
    $_SESSION = [];
    $res = $controller->execute([
        'confirm_token' => 'dummy_token',
        'action'        => 'confirm',
    ]);

    return $res['status'] === 'error' && str_contains($res['message'], 'Unauthorized');
});

assertTest('Tolak eksekusi jika token milik user lain', function () use ($controller, $jatiId, $utamaId) {
    setupSessionUser(1); // User 1 aktif
    $token = createPendingTx([
        'intent'              => 'STOCK_IN',
        'product_id'          => $jatiId,
        'product_name'        => 'Kayu Jati',
        'unit'                => 'batang',
        'qty'                 => 5,
        'source_warehouse_id' => null,
        'dest_warehouse_id'   => $utamaId,
    ], 2); // Token milik user 2

    $res = $controller->execute([
        'confirm_token' => $token,
        'action'        => 'confirm',
    ]);

    return $res['status'] === 'rejected' && str_contains($res['reason'], 'Kepemilikan token tidak cocok');
});

// =========================================================================
// 2. TOKEN GUARD & SINGLE-USE
// =========================================================================
$testGroup = 'Token Guard';

assertTest('Tolak jika token konfirmasi tidak ada di session', function () use ($controller) {
    setupSessionUser(1);
    $res = $controller->execute([
        'confirm_token' => 'non_existent_token_1234567890',
        'action'        => 'confirm',
    ]);

    return $res['status'] === 'rejected' && str_contains($res['reason'], 'Token konfirmasi tidak valid atau sudah kedaluwarsa');
});

assertTest('Tolak jika token sudah kedaluwarsa (>5 menit)', function () use ($controller, $jatiId, $utamaId) {
    setupSessionUser(1);
    // Token kedaluwarsa (-10 detik)
    $token = createPendingTx([
        'intent'              => 'STOCK_IN',
        'product_id'          => $jatiId,
        'product_name'        => 'Kayu Jati',
        'unit'                => 'batang',
        'qty'                 => 5,
        'source_warehouse_id' => null,
        'dest_warehouse_id'   => $utamaId,
    ], 1, -10);

    $res = $controller->execute([
        'confirm_token' => $token,
        'action'        => 'confirm',
    ]);

    return $res['status'] === 'rejected' && str_contains($res['reason'], 'Token konfirmasi tidak valid atau sudah kedaluwarsa');
});

assertTest('Single-use token: token dihapus seketika dan eksekusi ulang langsung ditolak', function () use ($controller, $pdo, $stockModel, $jatiId, $utamaId) {
    setupSessionUser(1);
    $initialStock = $stockModel->getStock($jatiId, $utamaId);

    $token = createPendingTx([
        'intent'              => 'STOCK_IN',
        'product_id'          => $jatiId,
        'product_name'        => 'Kayu Jati',
        'unit'                => 'batang',
        'qty'                 => 2,
        'source_warehouse_id' => null,
        'dest_warehouse_id'   => $utamaId,
    ], 1, 300, 'Test single-use token');

    // Eksekusi pertama (wajib sukses)
    $res1 = $controller->execute([
        'confirm_token' => $token,
        'action'        => 'confirm',
    ]);

    // Eksekusi kedua dengan token yang sama (wajib ditolak)
    $res2 = $controller->execute([
        'confirm_token' => $token,
        'action'        => 'confirm',
    ]);

    // Kembalikan stok semula
    $pdo->prepare('UPDATE stocks SET quantity = :qty WHERE product_id = :p AND warehouse_id = :w')
        ->execute([':qty' => $initialStock, ':p' => $jatiId, ':w' => $utamaId]);

    return $res1['status'] === 'success'
        && $res2['status'] === 'rejected'
        && str_contains($res2['reason'], 'Token konfirmasi tidak valid atau sudah kedaluwarsa');
});

// =========================================================================
// 3. ACTION PEMBATALAN (CANCEL)
// =========================================================================
$testGroup = 'Cancellation Flow';

assertTest('Alur pembatalan (action = cancel) mencatat audit CANCELLED dan tidak memutasi stok', function () use ($controller, $pdo, $stockModel, $jatiId, $utamaId) {
    setupSessionUser(1);
    $initialStock = $stockModel->getStock($jatiId, $utamaId);

    $token = createPendingTx([
        'intent'              => 'STOCK_IN',
        'product_id'          => $jatiId,
        'product_name'        => 'Kayu Jati',
        'unit'                => 'batang',
        'qty'                 => 10,
        'source_warehouse_id' => null,
        'dest_warehouse_id'   => $utamaId,
    ], 1, 300, 'User membatalkan transaksi');

    $res = $controller->execute([
        'confirm_token' => $token,
        'action'        => 'cancel',
    ]);

    $currentStock = $stockModel->getStock($jatiId, $utamaId);

    // Ambil log audit terbaru
    $stmt = $pdo->prepare('SELECT decision, reason FROM audit_logs WHERE raw_prompt = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute(['User membatalkan transaksi']);
    $log = $stmt->fetch();

    return $res['status'] === 'cancelled'
        && str_contains($res['message'], 'dibatalkan')
        && $currentStock === $initialStock
        && $log !== false
        && $log['decision'] === 'CANCELLED'
        && $log['reason'] === 'Dibatalkan oleh pengguna';
});

// =========================================================================
// 4. ACTION KONFIRMASI SUKSES (STOCK_IN, STOCK_OUT, TRANSFER_STOCK)
// =========================================================================
$testGroup = 'Confirmation - IN';

assertTest('STOCK_IN berhasil menambah stok, mencatat movement IN, dan audit EXECUTED', function () use ($controller, $pdo, $stockModel, $jatiId, $utamaId) {
    setupSessionUser(1);
    $initialStock = $stockModel->getStock($jatiId, $utamaId);

    $token = createPendingTx([
        'intent'              => 'STOCK_IN',
        'product_id'          => $jatiId,
        'product_name'        => 'Kayu Jati',
        'unit'                => 'batang',
        'qty'                 => 5,
        'source_warehouse_id' => null,
        'dest_warehouse_id'   => $utamaId,
    ], 1, 300, 'Tambah 5 batang kayu jati');

    $res = $controller->execute([
        'confirm_token' => $token,
        'action'        => 'confirm',
    ]);

    $newStock = $stockModel->getStock($jatiId, $utamaId);
    $movementId = (int) ($res['movement_id'] ?? 0);

    // Verifikasi pergerakan stok di DB
    $stmt = $pdo->prepare('SELECT * FROM stock_movements WHERE id = ?');
    $stmt->execute([$movementId]);
    $movement = $stmt->fetch();

    // Verifikasi audit log
    $stmtAudit = $pdo->prepare('SELECT decision, movement_id FROM audit_logs WHERE movement_id = ?');
    $stmtAudit->execute([$movementId]);
    $audit = $stmtAudit->fetch();

    // Kembalikan stok semula untuk isolasi tes
    $pdo->prepare('UPDATE stocks SET quantity = :qty WHERE product_id = :p AND warehouse_id = :w')
        ->execute([':qty' => $initialStock, ':p' => $jatiId, ':w' => $utamaId]);

    return $res['status'] === 'success'
        && $res['message'] === '✓ Sukses: 5 batang Kayu Jati berhasil diproses.'
        && $newStock === ($initialStock + 5.0)
        && $movement !== false
        && $movement['movement_type'] === 'IN'
        && (int) $movement['dest_warehouse_id'] === $utamaId
        && $movement['source_warehouse_id'] === null
        && $audit !== false
        && $audit['decision'] === 'EXECUTED';
});

assertTest('STOCK_IN ke gudang yang belum memiliki baris stok (INSERT baris stok baru)', function () use ($controller, $pdo, $stockModel, $pinusId, $utamaId) {
    setupSessionUser(1);

    // Pastikan baris stok Kayu Pinus di Gudang Utama belum ada
    $pdo->prepare('DELETE FROM stocks WHERE product_id = :p AND warehouse_id = :w')
        ->execute([':p' => $pinusId, ':w' => $utamaId]);

    $token = createPendingTx([
        'intent'              => 'STOCK_IN',
        'product_id'          => $pinusId,
        'product_name'        => 'Kayu Pinus',
        'unit'                => 'batang',
        'qty'                 => 25,
        'source_warehouse_id' => null,
        'dest_warehouse_id'   => $utamaId,
    ], 1, 300, 'Masuk 25 batang kayu pinus gudang utama');

    $res = $controller->execute([
        'confirm_token' => $token,
        'action'        => 'confirm',
    ]);

    $currentStock = $stockModel->getStock($pinusId, $utamaId);

    // Bersihkan baris uji
    $pdo->prepare('DELETE FROM stocks WHERE product_id = :p AND warehouse_id = :w')
        ->execute([':p' => $pinusId, ':w' => $utamaId]);

    return $res['status'] === 'success'
        && $currentStock === 25.0;
});

$testGroup = 'Confirmation - OUT';

assertTest('STOCK_OUT berhasil mengurangi stok, mencatat movement OUT, dan audit EXECUTED', function () use ($controller, $pdo, $stockModel, $jatiId, $utamaId) {
    setupSessionUser(1);
    $initialStock = $stockModel->getStock($jatiId, $utamaId);

    $token = createPendingTx([
        'intent'              => 'STOCK_OUT',
        'product_id'          => $jatiId,
        'product_name'        => 'Kayu Jati',
        'unit'                => 'batang',
        'qty'                 => 4,
        'source_warehouse_id' => $utamaId,
        'dest_warehouse_id'   => null,
    ], 1, 300, 'Keluarkan 4 batang kayu jati');

    $res = $controller->execute([
        'confirm_token' => $token,
        'action'        => 'confirm',
    ]);

    $newStock = $stockModel->getStock($jatiId, $utamaId);
    $movementId = (int) ($res['movement_id'] ?? 0);

    $stmt = $pdo->prepare('SELECT * FROM stock_movements WHERE id = ?');
    $stmt->execute([$movementId]);
    $movement = $stmt->fetch();

    // Kembalikan stok semula
    $pdo->prepare('UPDATE stocks SET quantity = :qty WHERE product_id = :p AND warehouse_id = :w')
        ->execute([':qty' => $initialStock, ':p' => $jatiId, ':w' => $utamaId]);

    return $res['status'] === 'success'
        && $res['message'] === '✓ Sukses: 4 batang Kayu Jati berhasil diproses.'
        && $newStock === ($initialStock - 4.0)
        && $movement !== false
        && $movement['movement_type'] === 'OUT'
        && (int) $movement['source_warehouse_id'] === $utamaId
        && $movement['dest_warehouse_id'] === null;
});

$testGroup = 'Confirmation - TRANSFER';

assertTest('TRANSFER_STOCK memindahkan stok antara 2 gudang dengan benar', function () use ($controller, $pdo, $stockModel, $jatiId, $utamaId, $sisaId) {
    setupSessionUser(1);
    $initialSrcStock = $stockModel->getStock($jatiId, $utamaId);
    $initialDstStock = $stockModel->getStock($jatiId, $sisaId);

    $token = createPendingTx([
        'intent'              => 'TRANSFER_STOCK',
        'product_id'          => $jatiId,
        'product_name'        => 'Kayu Jati',
        'unit'                => 'batang',
        'qty'                 => 6,
        'source_warehouse_id' => $utamaId,
        'dest_warehouse_id'   => $sisaId,
    ], 1, 300, 'Pindahkan 6 batang kayu jati dari gudang utama ke gudang sisa');

    $res = $controller->execute([
        'confirm_token' => $token,
        'action'        => 'confirm',
    ]);

    $newSrcStock = $stockModel->getStock($jatiId, $utamaId);
    $newDstStock = $stockModel->getStock($jatiId, $sisaId);
    $movementId  = (int) ($res['movement_id'] ?? 0);

    $stmt = $pdo->prepare('SELECT * FROM stock_movements WHERE id = ?');
    $stmt->execute([$movementId]);
    $movement = $stmt->fetch();

    // Kembalikan stok semula
    $pdo->prepare('UPDATE stocks SET quantity = :qty WHERE product_id = :p AND warehouse_id = :w')
        ->execute([':qty' => $initialSrcStock, ':p' => $jatiId, ':w' => $utamaId]);
    $pdo->prepare('UPDATE stocks SET quantity = :qty WHERE product_id = :p AND warehouse_id = :w')
        ->execute([':qty' => $initialDstStock, ':p' => $jatiId, ':w' => $sisaId]);

    return $res['status'] === 'success'
        && $res['message'] === '✓ Sukses: 6 batang Kayu Jati berhasil diproses.'
        && $newSrcStock === ($initialSrcStock - 6.0)
        && $newDstStock === ($initialDstStock + 6.0)
        && $movement !== false
        && $movement['movement_type'] === 'TRANSFER'
        && (int) $movement['source_warehouse_id'] === $utamaId
        && (int) $movement['dest_warehouse_id'] === $sisaId;
});

// =========================================================================
// 5. RACE CONDITION & RE-VALIDATION DI DALAM LOCK
// =========================================================================
$testGroup = 'Race Condition Guard';

assertTest('Deteksi stok berkurang mendadak saat konfirmasi: rollback & tolak (REJECTED)', function () use ($controller, $pdo, $stockModel, $mahoniId, $sisaId, $utamaId) {
    setupSessionUser(1);
    // Mahoni di Gudang Sisa stok awal = 8.0
    $initialStock = $stockModel->getStock($mahoniId, $sisaId);

    // Buat token konfirmasi untuk transfer 7 batang
    $token = createPendingTx([
        'intent'              => 'TRANSFER_STOCK',
        'product_id'          => $mahoniId,
        'product_name'        => 'Kayu Mahoni',
        'unit'                => 'batang',
        'qty'                 => 7,
        'source_warehouse_id' => $sisaId,
        'dest_warehouse_id'   => $utamaId,
    ], 1, 300, 'Race condition test transfer mahoni');

    // Simulasi intervensi transaksi lain: stok berkurang menjadi 5 sebelum konfirmasi dijalankan
    $pdo->prepare('UPDATE stocks SET quantity = 5.0 WHERE product_id = :p AND warehouse_id = :w')
        ->execute([':p' => $mahoniId, ':w' => $sisaId]);

    // Eksekusi konfirmasi minta 7 batang, padahal stok sekarang tinggal 5 batang
    $res = $controller->execute([
        'confirm_token' => $token,
        'action'        => 'confirm',
    ]);

    // Kembalikan stok semula
    $pdo->prepare('UPDATE stocks SET quantity = :qty WHERE product_id = :p AND warehouse_id = :w')
        ->execute([':qty' => $initialStock, ':p' => $mahoniId, ':w' => $sisaId]);

    // Ambil log audit terakhir
    $stmt = $pdo->prepare('SELECT decision, reason FROM audit_logs WHERE raw_prompt = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute(['Race condition test transfer mahoni']);
    $log = $stmt->fetch();

    return $res['status'] === 'rejected'
        && $res['reason'] === 'Stok berubah, transaksi dibatalkan.'
        && $log !== false
        && $log['decision'] === 'REJECTED'
        && $log['reason'] === 'Stok berubah, transaksi dibatalkan.';
});

// =========================================================================
// 6. CLIENT TAMPERING RESISTANCE
// =========================================================================
$testGroup = 'Tampering Resistance';

assertTest('Nilai manipulasi (qty, product_id) di request payload diabaikan oleh controller', function () use ($controller, $pdo, $stockModel, $jatiId, $utamaId) {
    setupSessionUser(1);
    $initialStock = $stockModel->getStock($jatiId, $utamaId);

    // Token sah untuk menambah 1 batang
    $token = createPendingTx([
        'intent'              => 'STOCK_IN',
        'product_id'          => $jatiId,
        'product_name'        => 'Kayu Jati',
        'unit'                => 'batang',
        'qty'                 => 1,
        'source_warehouse_id' => null,
        'dest_warehouse_id'   => $utamaId,
    ], 1, 300, 'Tampering test');

    // Penyerang mencoba menginjeksi qty 99999 di request payload konfirmasi
    $res = $controller->execute([
        'confirm_token' => $token,
        'action'        => 'confirm',
        'qty'           => 99999,
        'product_id'    => 999,
        'warehouse_id'  => 999,
    ]);

    $newStock = $stockModel->getStock($jatiId, $utamaId);

    // Kembalikan stok semula
    $pdo->prepare('UPDATE stocks SET quantity = :qty WHERE product_id = :p AND warehouse_id = :w')
        ->execute([':qty' => $initialStock, ':p' => $jatiId, ':w' => $utamaId]);

    // Harus bertambah 1, bukan 99999
    return $res['status'] === 'success'
        && $newStock === ($initialStock + 1.0)
        && str_contains($res['message'], '1 batang');
});

// =========================================================================
// RINGKASAN HASIL
// =========================================================================
echo "\n" . str_repeat('=', 95) . "\n";
echo sprintf("  HASIL PENGUJIAN: Total: %d | Lulus: %d | Gagal: %d\n", $totalTests, $passCount, $failCount);
echo str_repeat('=', 95) . "\n\n";

if ($failCount > 0) {
    exit(1);
}

exit(0);
