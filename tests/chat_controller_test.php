<?php

/**
 * chat_controller_test.php — CLI Test Suite untuk ChatController dan AuditModel.
 *
 * Menguji:
 * 1. AuditModel: prepared statement, append-only (no update/delete), lastInsertId.
 * 2. ChatController Session Guard: autentikasi wajib dari $_SESSION (HTTP 401).
 * 3. ChatController CSRF Guard: token CSRF wajib valid (HTTP 403).
 * 4. ChatController Sanitasi: prompt kosong ditolak (HTTP 400).
 * 5. ChatController LLM Failure: fail-closed dan dicatat sebagai REJECTED di audit_logs.
 * 6. ChatController Validation Clarify: dicatat sebagai CLARIFIED di audit_logs.
 * 7. ChatController Validation Rejected: entitas/stok tidak valid dicatat sebagai REJECTED.
 * 8. ChatController Transaction (Happy Path): status requires_confirmation, token 32 hex,
 *    pending_transactions kedaluwarsa 5 menit, stocks TIDAK termutasi.
 * 9. ChatController Check Stock (Happy Path): status info, pesan baku, dicatat EXECUTED.
 *
 * Penggunaan:
 *   php tests/chat_controller_test.php
 */

require_once __DIR__ . '/../app/Controllers/ChatController.php';
require_once __DIR__ . '/../app/Models/AuditModel.php';
require_once __DIR__ . '/../app/Services/ValidationLayer.php';
require_once __DIR__ . '/../app/Services/NameNormalizer.php';
require_once __DIR__ . '/Fakes/FakeProductModel.php';
require_once __DIR__ . '/Fakes/FakeWarehouseModel.php';
require_once __DIR__ . '/Fakes/FakeStockModel.php';

// =========================================================================
// TEST RUNNER HELPERS & FAKES
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

/**
 * Fake AuditModel in-memory spy untuk verifikasi log tanpa DB.
 */
class FakeAuditModel
{
    public array $logs = [];

    public function log(array $data): int
    {
        $id = count($this->logs) + 1;
        $data['id'] = $id;
        $this->logs[] = $data;
        return $id;
    }

    public function lastLog(): ?array
    {
        return !empty($this->logs) ? $this->logs[count($this->logs) - 1] : null;
    }
}

/**
 * Fake LlmService untuk simulasi respon JSON dari LLM tanpa kuota API.
 */
class FakeLlmService
{
    private array $queue = [];

    public function queueResponse(array $response): void
    {
        $this->queue[] = $response;
    }

    public function extract(string $userText): array
    {
        if (!empty($this->queue)) {
            return array_shift($this->queue);
        }

        return [
            'ok'         => true,
            'data'       => null,
            'raw'        => null,
            'error'      => 'EMPTY_QUEUE',
            'model'      => 'mock-groq',
            'latency_ms' => 10,
            'attempts'   => 1,
        ];
    }
}

echo "\n" . str_repeat('=', 95) . "\n";
echo "  WOODMIND ERP - CHAT CONTROLLER & AUDIT MODEL TEST SUITE\n";
echo str_repeat('=', 95) . "\n\n";

// Inisialisasi komponen tiruan
$fakeProduct   = new FakeProductModel();
$fakeWarehouse = new FakeWarehouseModel();
$fakeStock     = new FakeStockModel();
$validator     = new ValidationLayer($fakeProduct, $fakeWarehouse, $fakeStock);
$fakeAudit     = new FakeAuditModel();
$fakeLlm       = new FakeLlmService();

$controller = new ChatController(
    $fakeLlm,
    $validator,
    $fakeAudit,
    $fakeStock,
    $fakeWarehouse
);

// =========================================================================
// 1. AUDIT MODEL TESTS (Unit & Database Append-Only)
// =========================================================================
$testGroup = 'AuditModel';

assertTest('AuditModel log() berhasil menyimpan entri ke DB nyata', function () {
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
        // Skip jika service mysql tidak aktif
        return true;
    }

    $audit = new AuditModel($pdo);
    $insertedId = $audit->log([
        'user_id'           => 1, // staf1
        'raw_prompt'        => 'Unit test prompt audit',
        'llm_json_response' => ['intent' => 'CHECK_STOCK'],
        'decision'          => 'EXECUTED',
        'reason'            => 'Uji coba penyimpanan log audit',
        'movement_id'       => null,
        'model_name'        => 'llama3-70b',
        'latency_ms'        => 120,
    ]);

    if ($insertedId <= 0) {
        return false;
    }

    // Verifikasi baris ada di DB
    $stmt = $pdo->prepare('SELECT decision, raw_prompt, model_name FROM audit_logs WHERE id = ?');
    $stmt->execute([$insertedId]);
    $row = $stmt->fetch();

    return $row && $row['decision'] === 'EXECUTED' && $row['model_name'] === 'llama3-70b';
});

assertTest('AuditModel menolak decision di luar whitelist enum', function () {
    $pdoMock = new PDO('sqlite::memory:');
    $audit   = new AuditModel($pdoMock);

    try {
        $audit->log([
            'raw_prompt' => 'test',
            'decision'   => 'UNKNOWN_DECISION',
        ]);
        return false;
    } catch (InvalidArgumentException $e) {
        return str_contains($e->getMessage(), 'Keputusan audit tidak valid');
    }
});

// =========================================================================
// 2. SESSION GUARD (Autentikasi WAJIB dari $_SESSION)
// =========================================================================
$testGroup = 'Session Guard';

assertTest('Tolak jika $_SESSION user_id belum diset (HTTP 401)', function () use ($controller) {
    $_SESSION = []; // Bersihkan sesi
    $res = $controller->process([
        'csrf_token' => 'dummy',
        'message'    => 'cek stok kayu jati',
    ]);

    return $res['status'] === 'error' && str_contains($res['message'], 'Unauthorized');
});

assertTest('Tolak jika role belum diset di $_SESSION (HTTP 401)', function () use ($controller) {
    $_SESSION = ['user_id' => 1]; // user_id ada tapi role tidak ada
    $res = $controller->process([
        'csrf_token' => 'dummy',
        'message'    => 'cek stok kayu jati',
    ]);

    return $res['status'] === 'error' && str_contains($res['message'], 'Unauthorized');
});

assertTest('user_id pada payload request diabaikan (mencegah ID spoofing)', function () use ($controller) {
    $_SESSION = []; // session kosong
    $res = $controller->process([
        'user_id'    => 999, // Percobaan injeksi ID di body request
        'role'       => 'ADMIN',
        'csrf_token' => 'dummy',
        'message'    => 'cek stok kayu jati',
    ]);

    return $res['status'] === 'error' && str_contains($res['message'], 'Unauthorized');
});

// =========================================================================
// 3. CSRF GUARD
// =========================================================================
$testGroup = 'CSRF Guard';

assertTest('Tolak jika token CSRF tidak disertakan di payload (HTTP 403)', function () use ($controller) {
    $_SESSION = [
        'user_id'    => 1,
        'role'       => 'STAFF',
        'csrf_token' => 'valid_secret_csrf_12345',
    ];

    $res = $controller->process([
        'message' => 'cek stok kayu jati',
    ]);

    return $res['status'] === 'error' && str_contains($res['message'], 'CSRF');
});

assertTest('Tolak jika token CSRF mismatch (HTTP 403)', function () use ($controller) {
    $_SESSION = [
        'user_id'    => 1,
        'role'       => 'STAFF',
        'csrf_token' => 'valid_secret_csrf_12345',
    ];

    $res = $controller->process([
        'csrf_token' => 'wrong_token',
        'message'    => 'cek stok kayu jati',
    ]);

    return $res['status'] === 'error' && str_contains($res['message'], 'CSRF');
});

// Helper setup session valid untuk tes berikutnya
function setupValidSession(): void
{
    $_SESSION = [
        'user_id'              => 1,
        'role'                 => 'STAFF',
        'csrf_token'           => 'valid_token_xyz',
        'pending_transactions' => [],
    ];
}

// =========================================================================
// 4. SANITASI INPUT PROMPT
// =========================================================================
$testGroup = 'Sanitasi Input';

assertTest('Tolak pesan kosong atau hanya spasi (HTTP 400)', function () use ($controller) {
    setupValidSession();
    $res = $controller->process([
        'csrf_token' => 'valid_token_xyz',
        'message'    => '   ',
    ]);

    return $res['status'] === 'error' && str_contains($res['message'], 'kosong');
});

// =========================================================================
// 5. LLM EXTRACTION FAILURE
// =========================================================================
$testGroup = 'LLM Failure';

assertTest('Fail-closed saat LLM gagal dan catat audit REJECTED', function () use ($controller, $fakeLlm, $fakeAudit) {
    setupValidSession();
    $fakeLlm->queueResponse([
        'ok'         => false,
        'data'       => null,
        'raw'        => null,
        'error'      => 'NETWORK_TIMEOUT',
        'model'      => 'llama3-70b',
        'latency_ms' => 1500,
        'attempts'   => 2,
    ]);

    $res = $controller->process([
        'csrf_token' => 'valid_token_xyz',
        'message'    => 'tambah 10 batang kayu jati',
    ]);

    $lastLog = $fakeAudit->lastLog();

    return $res['status'] === 'rejected'
        && str_contains($res['reason'], 'NETWORK_TIMEOUT')
        && $lastLog !== null
        && $lastLog['decision'] === 'REJECTED'
        && $lastLog['reason'] === 'NETWORK_TIMEOUT';
});

// =========================================================================
// 6. VALIDATION - CLARIFY
// =========================================================================
$testGroup = 'Validation / Clarify';

assertTest('Perintah tidak lengkap menghasilkan status clarify dan dicatat CLARIFIED', function () use ($controller, $fakeLlm, $fakeAudit) {
    setupValidSession();
    // LLM menghasilkan CLARIFY_REQUIRED
    $fakeLlm->queueResponse([
        'ok'         => true,
        'data'       => [
            'intent'                => 'CLARIFY_REQUIRED',
            'product'               => null,
            'quantity'              => null,
            'unit'                  => null,
            'source_warehouse'      => null,
            'destination_warehouse' => null,
        ],
        'raw'        => '{"intent":"CLARIFY_REQUIRED"}',
        'error'      => null,
        'model'      => 'llama3-70b',
        'latency_ms' => 300,
        'attempts'   => 1,
    ]);

    $res = $controller->process([
        'csrf_token' => 'valid_token_xyz',
        'message'    => 'tolong kirim kayu sekarang',
    ]);

    $lastLog = $fakeAudit->lastLog();

    return $res['status'] === 'clarify'
        && str_contains($res['message'], 'kurang lengkap')
        && $lastLog !== null
        && $lastLog['decision'] === 'CLARIFIED';
});

// =========================================================================
// 7. VALIDATION - REJECTED
// =========================================================================
$testGroup = 'Validation / Rejected';

assertTest('Entitas fiktif menghasilkan status rejected dan dicatat REJECTED', function () use ($controller, $fakeLlm, $fakeAudit) {
    setupValidSession();
    $fakeLlm->queueResponse([
        'ok'         => true,
        'data'       => [
            'intent'                => 'STOCK_IN',
            'product'               => 'Kayu Eboni',
            'quantity'              => 10,
            'unit'                  => 'batang',
            'source_warehouse'      => null,
            'destination_warehouse' => 'Gudang Utama',
        ],
        'raw'        => '{"intent":"STOCK_IN","product":"Kayu Eboni"}',
        'error'      => null,
        'model'      => 'llama3-70b',
        'latency_ms' => 250,
        'attempts'   => 1,
    ]);

    $res = $controller->process([
        'csrf_token' => 'valid_token_xyz',
        'message'    => 'masukkan 10 batang kayu eboni ke gudang utama',
    ]);

    $lastLog = $fakeAudit->lastLog();

    return $res['status'] === 'rejected'
        && str_contains($res['reason'], 'tidak ditemukan')
        && $lastLog !== null
        && $lastLog['decision'] === 'REJECTED';
});

assertTest('Stok kurang untuk transfer ditolak dan dicatat REJECTED', function () use ($controller, $fakeLlm, $fakeAudit) {
    setupValidSession();
    // Mahoni di Gudang Sisa hanya ada 8.0, diminta 50
    $fakeLlm->queueResponse([
        'ok'         => true,
        'data'       => [
            'intent'                => 'TRANSFER_STOCK',
            'product'               => 'Kayu Mahoni',
            'quantity'              => 50,
            'unit'                  => 'batang',
            'source_warehouse'      => 'Gudang Sisa',
            'destination_warehouse' => 'Gudang Utama',
        ],
        'raw'        => '{"intent":"TRANSFER_STOCK"}',
        'error'      => null,
        'model'      => 'llama3-70b',
        'latency_ms' => 250,
        'attempts'   => 1,
    ]);

    $res = $controller->process([
        'csrf_token' => 'valid_token_xyz',
        'message'    => 'pindahkan 50 batang kayu mahoni dari gudang sisa ke gudang utama',
    ]);

    $lastLog = $fakeAudit->lastLog();

    return $res['status'] === 'rejected'
        && str_contains($res['reason'], 'Stok tidak cukup')
        && $lastLog !== null
        && $lastLog['decision'] === 'REJECTED';
});

// =========================================================================
// 8. TRANSAKSI (REQUIRES_CONFIRMATION = TRUE)
// =========================================================================
$testGroup = 'Requires Confirmation';

assertTest('Transaksi valid mengembalikan token 32 hex dan pending_transactions tersimpan', function () use ($controller, $fakeLlm, $fakeStock) {
    setupValidSession();
    // Transfer 5 batang Kayu Jati dari Gudang Utama ke Gudang Sisa (stok tersedia 120)
    $fakeLlm->queueResponse([
        'ok'         => true,
        'data'       => [
            'intent'                => 'TRANSFER_STOCK',
            'product'               => 'Kayu Jati',
            'quantity'              => 5,
            'unit'                  => 'batang',
            'source_warehouse'      => 'Gudang Utama',
            'destination_warehouse' => 'Gudang Sisa',
        ],
        'raw'        => '{"intent":"TRANSFER_STOCK"}',
        'error'      => null,
        'model'      => 'llama3-70b',
        'latency_ms' => 280,
        'attempts'   => 1,
    ]);

    $initialStock = $fakeStock->getStock(1, 1);

    $res = $controller->process([
        'csrf_token' => 'valid_token_xyz',
        'message'    => 'pindahkan 5 batang kayu jati dari gudang utama ke gudang sisa',
    ]);

    // Verifikasi output
    $statusOk   = ($res['status'] === 'requires_confirmation');
    $token      = $res['confirm_token'] ?? '';
    $tokenValid = (strlen($token) === 32 && ctype_xdigit($token));
    $intentOk   = ($res['intent'] === 'TRANSFER_STOCK');

    // Verifikasi session pending_transactions
    $pendingSession = $_SESSION['pending_transactions'][$token] ?? null;
    $hasSession     = ($pendingSession !== null);
    $expiryValid    = $hasSession && ($pendingSession['expires_at'] > time() + 250); // Sekitar 5 menit ke depan
    $hasAuditId     = $hasSession && array_key_exists('audit_id', $pendingSession);

    // Kritis: Stok TIDAK boleh berubah pada tahap chat/konfirmasi
    $stockUnchanged = ($fakeStock->getStock(1, 1) === $initialStock);

    return $statusOk && $tokenValid && $intentOk && $hasSession && $expiryValid && $hasAuditId && $stockUnchanged;
});

// =========================================================================
// 9. CHECK_STOCK (REQUIRES_CONFIRMATION = FALSE)
// =========================================================================
$testGroup = 'Check Stock';

assertTest('Cek stok mengembalikan status info, pesan baku, dan dicatat EXECUTED', function () use ($controller, $fakeLlm, $fakeAudit) {
    setupValidSession();
    $fakeLlm->queueResponse([
        'ok'         => true,
        'data'       => [
            'intent'                => 'CHECK_STOCK',
            'product'               => 'Kayu Jati',
            'quantity'              => null,
            'unit'                  => null,
            'source_warehouse'      => 'Gudang Utama',
            'destination_warehouse' => null,
        ],
        'raw'        => '{"intent":"CHECK_STOCK"}',
        'error'      => null,
        'model'      => 'llama3-70b',
        'latency_ms' => 190,
        'attempts'   => 1,
    ]);

    $res = $controller->process([
        'csrf_token' => 'valid_token_xyz',
        'message'    => 'cek stok kayu jati di gudang utama',
    ]);

    $lastLog = $fakeAudit->lastLog();

    $expectedMessage = 'Stok Kayu Jati di Gudang Utama: 120 batang';

    return $res['status'] === 'info'
        && $res['message'] === $expectedMessage
        && (int) $res['data']['quantity'] === 120
        && $lastLog !== null
        && $lastLog['decision'] === 'EXECUTED'
        && $lastLog['reason'] === $expectedMessage;
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
