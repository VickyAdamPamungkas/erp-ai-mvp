<?php

require_once __DIR__ . '/../Models/AuditModel.php';

/**
 * TransactionController — Controller untuk mengeksekusi konfirmasi transaksi inventaris.
 *
 * Sesuai arsitektur WoodMind ERP:
 * - Controlled Execution Layer: Eksekusi fisik mutasi stok HANYA dilakukan di sini setelah konfirmasi user.
 * - Single-use confirmation token (dibersihkan dari session segera setelah dibaca).
 * - Seluruh parameter mutasi stok (product_id, qty, warehouse_id) STRICTLY diambil dari $_SESSION,
 *   klien DILARANG memasok data mutasi melalui payload request konfirmasi.
 * - Mutasi stok dijamin ACID dalam DB transaction ($pdo->beginTransaction()) dan row-locking (SELECT ... FOR UPDATE).
 * - Validasi ketersediaan stok diulang di dalam lock transaksi untuk mencegah race condition.
 * - Fail-closed: exception internal selalu memicu rollback dan menghasilkan status rejected / error.
 * - Append-only audit: setiap eksekusi, penolakan, atau pembatalan dicatat ke audit_logs via AuditModel.
 */
class TransactionController
{
    private ?PDO $pdo;
    private ?AuditModel $auditModel;

    /**
     * @param PDO|null $pdo
     * @param AuditModel|null $auditModel
     */
    public function __construct(?PDO $pdo = null, ?AuditModel $auditModel = null)
    {
        $this->pdo = $pdo ?? self::resolvePdo();
        $this->auditModel = $auditModel ?? ($this->pdo ? new AuditModel($this->pdo) : null);
    }

    /**
     * Mengeksekusi aksi konfirmasi ('confirm') atau pembatalan ('cancel') transaksi.
     *
     * @param array<string, mixed> $requestPayload Payload request (confirm_token, action)
     * @return array<string, mixed> Response terstruktur
     */
    public function execute(array $requestPayload): array
    {
        // -----------------------------------------------------------------
        // 1. Session & Auth Guard (Autentikasi wajib dari $_SESSION)
        // -----------------------------------------------------------------
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        $userId = $_SESSION['user_id'] ?? null;
        if (empty($userId)) {
            if (!headers_sent()) {
                http_response_code(401);
            }
            return [
                'status'  => 'error',
                'message' => 'Unauthorized: Pengguna belum login atau sesi tidak valid',
            ];
        }
        $userId = (int) $userId;

        // -----------------------------------------------------------------
        // 2. CSRF Guard (Jika token CSRF disediakan, validasi kecocokan)
        // -----------------------------------------------------------------
        $sessionCsrf = (string) ($_SESSION['csrf_token'] ?? '');
        $requestCsrf = (string) ($requestPayload['csrf_token'] ?? '');
        if ($sessionCsrf !== '' && $requestCsrf !== '' && !hash_equals($sessionCsrf, $requestCsrf)) {
            if (!headers_sent()) {
                http_response_code(403);
            }
            return [
                'status'  => 'error',
                'message' => 'Token CSRF tidak valid',
            ];
        }

        // -----------------------------------------------------------------
        // 3. Token Guard & Single-Use Extraction
        // -----------------------------------------------------------------
        $confirmToken = (string) ($requestPayload['confirm_token'] ?? '');
        $action       = strtolower(trim((string) ($requestPayload['action'] ?? 'confirm')));

        if (
            $confirmToken === '' ||
            !isset($_SESSION['pending_transactions'][$confirmToken]) ||
            !is_array($_SESSION['pending_transactions'][$confirmToken])
        ) {
            return [
                'status' => 'rejected',
                'reason' => 'Token konfirmasi tidak valid atau sudah kedaluwarsa',
            ];
        }

        // Ambil data transaksi dari session, lalu segera hapus (single-use token)
        $pendingTx = $_SESSION['pending_transactions'][$confirmToken];
        unset($_SESSION['pending_transactions'][$confirmToken]);

        // Validasi masa berlaku token (> 5 menit / time() > expires_at)
        $expiresAt = (int) ($pendingTx['expires_at'] ?? 0);
        if ($expiresAt <= 0 || time() > $expiresAt) {
            return [
                'status' => 'rejected',
                'reason' => 'Token konfirmasi tidak valid atau sudah kedaluwarsa',
            ];
        }

        // Verifikasi kesesuaian user pemilik token dengan user aktif di session
        if (!empty($pendingTx['user_id']) && (int) $pendingTx['user_id'] !== $userId) {
            return [
                'status' => 'rejected',
                'reason' => 'Akses ditolak: Kepemilikan token tidak cocok',
            ];
        }

        $rawPrompt = (string) ($pendingTx['raw_prompt'] ?? '');
        $llmResult = $pendingTx['llm_result'] ?? [];
        $payload   = $pendingTx['payload'] ?? [];

        // -----------------------------------------------------------------
        // 4. JIKA $action == 'cancel': Pembatalan Transaksi
        // -----------------------------------------------------------------
        if ($action === 'cancel') {
            if ($this->auditModel !== null) {
                $this->auditModel->log([
                    'user_id'           => $userId,
                    'raw_prompt'        => $rawPrompt,
                    'llm_json_response' => $llmResult['raw'] ?? ($llmResult['data'] ?? null),
                    'decision'          => 'CANCELLED',
                    'reason'            => 'Dibatalkan oleh pengguna',
                    'movement_id'       => null,
                    'model_name'        => $llmResult['model'] ?? null,
                    'latency_ms'        => $llmResult['latency_ms'] ?? null,
                ]);
            }

            return [
                'status'  => 'cancelled',
                'message' => 'Transaksi dibatalkan.',
            ];
        }

        // Tindakan selain 'confirm' ditolak
        if ($action !== 'confirm') {
            return [
                'status' => 'rejected',
                'reason' => "Aksi '{$action}' tidak dikenal",
            ];
        }

        // -----------------------------------------------------------------
        // 5. JIKA $action == 'confirm': Eksekusi Transaksi Mutasi Stok
        // -----------------------------------------------------------------
        if ($this->pdo === null) {
            return [
                'status' => 'rejected',
                'reason' => 'Koneksi database tidak tersedia',
            ];
        }

        $intent      = (string) ($payload['intent'] ?? '');
        $productId   = (int) ($payload['product_id'] ?? 0);
        $productName = (string) ($payload['product_name'] ?? 'Produk');
        $unit        = (string) ($payload['unit'] ?? '');
        $qty         = isset($payload['qty']) ? (float) $payload['qty'] : 0.0;
        $srcWhId     = isset($payload['source_warehouse_id']) && $payload['source_warehouse_id'] !== null
            ? (int) $payload['source_warehouse_id']
            : null;
        $dstWhId     = isset($payload['dest_warehouse_id']) && $payload['dest_warehouse_id'] !== null
            ? (int) $payload['dest_warehouse_id']
            : null;

        if ($productId <= 0 || $qty <= 0) {
            return [
                'status' => 'rejected',
                'reason' => 'Parameter transaksi pada sesi tidak valid',
            ];
        }

        try {
            $this->pdo->beginTransaction();

            $movementType = match ($intent) {
                'STOCK_IN'       => 'IN',
                'STOCK_OUT'      => 'OUT',
                'TRANSFER_STOCK' => 'TRANSFER',
                default          => null,
            };

            if ($movementType === null) {
                $this->pdo->rollBack();
                return [
                    'status' => 'rejected',
                    'reason' => "Intent '{$intent}' tidak didukung untuk eksekusi mutasi",
                ];
            }

            // =============================================================
            // A. EKSEKUSI STOCK_IN
            // =============================================================
            if ($movementType === 'IN') {
                if ($dstWhId === null) {
                    $this->pdo->rollBack();
                    return [
                        'status' => 'rejected',
                        'reason' => 'Gudang tujuan wajib ditentukan untuk transaksi masuk',
                    ];
                }

                // Kunci baris stok produk & gudang tujuan
                $stmtLock = $this->pdo->prepare(
                    'SELECT id, quantity FROM stocks WHERE product_id = :product_id AND warehouse_id = :warehouse_id FOR UPDATE'
                );
                $stmtLock->execute([
                    ':product_id'   => $productId,
                    ':warehouse_id' => $dstWhId,
                ]);
                $stockRow = $stmtLock->fetch(PDO::FETCH_ASSOC);

                if ($stockRow) {
                    $stmtUpd = $this->pdo->prepare(
                        'UPDATE stocks SET quantity = quantity + :qty WHERE id = :id'
                    );
                    $stmtUpd->execute([
                        ':qty' => $qty,
                        ':id'  => (int) $stockRow['id'],
                    ]);
                } else {
                    $stmtIns = $this->pdo->prepare(
                        'INSERT INTO stocks (product_id, warehouse_id, quantity) VALUES (:product_id, :warehouse_id, :qty)'
                    );
                    $stmtIns->execute([
                        ':product_id'   => $productId,
                        ':warehouse_id' => $dstWhId,
                        ':qty'          => $qty,
                    ]);
                }

                // Catat ke stock_movements
                $stmtMov = $this->pdo->prepare(
                    'INSERT INTO stock_movements (movement_type, product_id, qty, source_warehouse_id, dest_warehouse_id, user_id)
                     VALUES ("IN", :product_id, :qty, NULL, :dest_warehouse_id, :user_id)'
                );
                $stmtMov->execute([
                    ':product_id'        => $productId,
                    ':qty'               => $qty,
                    ':dest_warehouse_id' => $dstWhId,
                    ':user_id'           => $userId,
                ]);
            }

            // =============================================================
            // B. EKSEKUSI STOCK_OUT
            // =============================================================
            elseif ($movementType === 'OUT') {
                if ($srcWhId === null) {
                    $this->pdo->rollBack();
                    return [
                        'status' => 'rejected',
                        'reason' => 'Gudang asal wajib ditentukan untuk transaksi keluar',
                    ];
                }

                // Kunci baris stok produk & gudang asal
                $stmtLock = $this->pdo->prepare(
                    'SELECT id, quantity FROM stocks WHERE product_id = :product_id AND warehouse_id = :warehouse_id FOR UPDATE'
                );
                $stmtLock->execute([
                    ':product_id'   => $productId,
                    ':warehouse_id' => $srcWhId,
                ]);
                $stockRow = $stmtLock->fetch(PDO::FETCH_ASSOC);

                $availMilli = $stockRow ? (int) round(((float) $stockRow['quantity']) * 1000) : 0;
                $reqMilli   = (int) round($qty * 1000);

                // Validasi ulang ketersediaan stok di dalam lock DB
                if ($availMilli < $reqMilli) {
                    $this->pdo->rollBack();

                    if ($this->auditModel !== null) {
                        $this->auditModel->log([
                            'user_id'           => $userId,
                            'raw_prompt'        => $rawPrompt,
                            'llm_json_response' => $llmResult['raw'] ?? ($llmResult['data'] ?? null),
                            'decision'          => 'REJECTED',
                            'reason'            => 'Stok berubah, transaksi dibatalkan.',
                            'movement_id'       => null,
                            'model_name'        => $llmResult['model'] ?? null,
                            'latency_ms'        => $llmResult['latency_ms'] ?? null,
                        ]);
                    }

                    return [
                        'status' => 'rejected',
                        'reason' => 'Stok berubah, transaksi dibatalkan.',
                    ];
                }

                // Kurangi stok
                $stmtUpd = $this->pdo->prepare(
                    'UPDATE stocks SET quantity = quantity - :qty WHERE id = :id'
                );
                $stmtUpd->execute([
                    ':qty' => $qty,
                    ':id'  => (int) $stockRow['id'],
                ]);

                // Catat ke stock_movements
                $stmtMov = $this->pdo->prepare(
                    'INSERT INTO stock_movements (movement_type, product_id, qty, source_warehouse_id, dest_warehouse_id, user_id)
                     VALUES ("OUT", :product_id, :qty, :source_warehouse_id, NULL, :user_id)'
                );
                $stmtMov->execute([
                    ':product_id'          => $productId,
                    ':qty'                 => $qty,
                    ':source_warehouse_id' => $srcWhId,
                    ':user_id'             => $userId,
                ]);
            }

            // =============================================================
            // C. EKSEKUSI TRANSFER_STOCK
            // =============================================================
            elseif ($movementType === 'TRANSFER') {
                if ($srcWhId === null || $dstWhId === null || $srcWhId === $dstWhId) {
                    $this->pdo->rollBack();
                    return [
                        'status' => 'rejected',
                        'reason' => 'Gudang asal dan tujuan tidak valid untuk transfer',
                    ];
                }

                // Kunci baris stok kedua gudang terurut untuk mencegah DB deadlock
                $stmtLock = $this->pdo->prepare(
                    'SELECT id, warehouse_id, quantity
                     FROM stocks
                     WHERE product_id = :product_id AND warehouse_id IN (:src_id, :dst_id)
                     ORDER BY warehouse_id ASC
                     FOR UPDATE'
                );
                $stmtLock->execute([
                    ':product_id' => $productId,
                    ':src_id'     => $srcWhId,
                    ':dst_id'     => $dstWhId,
                ]);
                $lockedRows = $stmtLock->fetchAll(PDO::FETCH_ASSOC);

                $stocksByWh = [];
                foreach ($lockedRows as $row) {
                    $stocksByWh[(int) $row['warehouse_id']] = $row;
                }

                $srcRow     = $stocksByWh[$srcWhId] ?? null;
                $availMilli = $srcRow ? (int) round(((float) $srcRow['quantity']) * 1000) : 0;
                $reqMilli   = (int) round($qty * 1000);

                // Validasi ulang ketersediaan stok asal di dalam lock DB
                if ($availMilli < $reqMilli) {
                    $this->pdo->rollBack();

                    if ($this->auditModel !== null) {
                        $this->auditModel->log([
                            'user_id'           => $userId,
                            'raw_prompt'        => $rawPrompt,
                            'llm_json_response' => $llmResult['raw'] ?? ($llmResult['data'] ?? null),
                            'decision'          => 'REJECTED',
                            'reason'            => 'Stok berubah, transaksi dibatalkan.',
                            'movement_id'       => null,
                            'model_name'        => $llmResult['model'] ?? null,
                            'latency_ms'        => $llmResult['latency_ms'] ?? null,
                        ]);
                    }

                    return [
                        'status' => 'rejected',
                        'reason' => 'Stok berubah, transaksi dibatalkan.',
                    ];
                }

                // 1. Kurangi stok gudang asal
                $stmtSrcUpd = $this->pdo->prepare(
                    'UPDATE stocks SET quantity = quantity - :qty WHERE id = :id'
                );
                $stmtSrcUpd->execute([
                    ':qty' => $qty,
                    ':id'  => (int) $srcRow['id'],
                ]);

                // 2. Tambah stok gudang tujuan (UPDATE jika ada, INSERT jika belum ada)
                $dstRow = $stocksByWh[$dstWhId] ?? null;
                if ($dstRow) {
                    $stmtDstUpd = $this->pdo->prepare(
                        'UPDATE stocks SET quantity = quantity + :qty WHERE id = :id'
                    );
                    $stmtDstUpd->execute([
                        ':qty' => $qty,
                        ':id'  => (int) $dstRow['id'],
                    ]);
                } else {
                    $stmtDstIns = $this->pdo->prepare(
                        'INSERT INTO stocks (product_id, warehouse_id, quantity) VALUES (:product_id, :warehouse_id, :qty)'
                    );
                    $stmtDstIns->execute([
                        ':product_id'   => $productId,
                        ':warehouse_id' => $dstWhId,
                        ':qty'          => $qty,
                    ]);
                }

                // 3. Catat ke stock_movements
                $stmtMov = $this->pdo->prepare(
                    'INSERT INTO stock_movements (movement_type, product_id, qty, source_warehouse_id, dest_warehouse_id, user_id)
                     VALUES ("TRANSFER", :product_id, :qty, :source_warehouse_id, :dest_warehouse_id, :user_id)'
                );
                $stmtMov->execute([
                    ':product_id'          => $productId,
                    ':qty'                 => $qty,
                    ':source_warehouse_id' => $srcWhId,
                    ':dest_warehouse_id'   => $dstWhId,
                    ':user_id'             => $userId,
                ]);
            }

            $movementId = (int) $this->pdo->lastInsertId();
            $qtyDisplay = (fmod($qty, 1.0) === 0.0) ? (int) $qty : $qty;
            $message    = "✓ Sukses: {$qtyDisplay} {$unit} {$productName} berhasil diproses.";

            // Catat log audit transaksi sukses (EXECUTED)
            if ($this->auditModel !== null) {
                $this->auditModel->log([
                    'user_id'           => $userId,
                    'raw_prompt'        => $rawPrompt,
                    'llm_json_response' => $llmResult['raw'] ?? ($llmResult['data'] ?? null),
                    'decision'          => 'EXECUTED',
                    'reason'            => $message,
                    'movement_id'       => $movementId,
                    'model_name'        => $llmResult['model'] ?? null,
                    'latency_ms'        => $llmResult['latency_ms'] ?? null,
                ]);
            }

            $this->pdo->commit();

            return [
                'status'      => 'success',
                'message'     => $message,
                'movement_id' => $movementId,
            ];

        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            if ($this->auditModel !== null) {
                try {
                    $this->auditModel->log([
                        'user_id'           => $userId,
                        'raw_prompt'        => $rawPrompt,
                        'llm_json_response' => $llmResult['raw'] ?? ($llmResult['data'] ?? null),
                        'decision'          => 'REJECTED',
                        'reason'            => 'Kesalahan eksekusi database: ' . mb_substr($e->getMessage(), 0, 200),
                        'movement_id'       => null,
                        'model_name'        => $llmResult['model'] ?? null,
                        'latency_ms'        => $llmResult['latency_ms'] ?? null,
                    ]);
                } catch (\Throwable $ignored) {
                    // Fail-closed
                }
            }

            return [
                'status' => 'rejected',
                'reason' => 'Terjadi kesalahan sistem saat mengeksekusi transaksi',
            ];
        }
    }

    /**
     * Resolusi koneksi PDO dari konfigurasi database lokal.
     */
    private static function resolvePdo(): ?PDO
    {
        $configPath = __DIR__ . '/../../config/db.php';
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
            return new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
