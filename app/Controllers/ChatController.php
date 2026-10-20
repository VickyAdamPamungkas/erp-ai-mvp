<?php

require_once __DIR__ . '/../Services/LlmService.php';
require_once __DIR__ . '/../Services/ValidationLayer.php';
require_once __DIR__ . '/../Models/AuditModel.php';
require_once __DIR__ . '/../Models/StockModel.php';
require_once __DIR__ . '/../Models/ProductModel.php';
require_once __DIR__ . '/../Models/WarehouseModel.php';

/**
 * ChatController — Controller untuk memproses percakapan teks pengguna.
 *
 * Sesuai arsitektur WoodMind ERP:
 * - Mengorkestrasi pipeline: LlmService -> ValidationLayer -> Session / Confirmation -> AuditModel.
 * - Memastikan autentikasi (user_id & role) diambil STRICTLY dari $_SESSION, bukan request body.
 * - Memverifikasi CSRF token pada setiap request.
 * - Append-only audit: mencatat EXECUTED (untuk CHECK_STOCK), REJECTED, atau CLARIFIED.
 * - TIDAK PERNAH melakukan INSERT/UPDATE pada tabel stocks atau stock_movements (Controlled Execution).
 */
class ChatController
{
    /** @var object */
    private $llmService;

    /** @var object */
    private $validator;

    /** @var object|null */
    private $auditModel;

    /** @var object|null */
    private $stockModel;

    /** @var object|null */
    private $warehouseModel;

    /**
     * Inisialisasi controller dengan Dependency Injection opsional untuk kemudahan unit testing.
     *
     * @param object|null $llmService
     * @param object|null $validator
     * @param object|null $auditModel
     * @param object|null $stockModel
     * @param object|null $warehouseModel
     * @param PDO|null $pdo
     */
    public function __construct(
        $llmService = null,
        $validator = null,
        $auditModel = null,
        $stockModel = null,
        $warehouseModel = null,
        ?PDO $pdo = null
    ) {
        if ($pdo === null && ($auditModel === null || $stockModel === null || $validator === null || $warehouseModel === null)) {
            $pdo = self::resolvePdo();
        }

        $this->llmService     = $llmService ?? new LlmService();
        $this->stockModel     = $stockModel ?? ($pdo ? new StockModel($pdo) : null);
        $this->auditModel     = $auditModel ?? ($pdo ? new AuditModel($pdo) : null);
        $this->warehouseModel = $warehouseModel ?? ($pdo ? new WarehouseModel($pdo) : null);

        if ($validator !== null) {
            $this->validator = $validator;
        } elseif ($pdo !== null) {
            $productModel    = new ProductModel($pdo);
            $this->validator = new ValidationLayer($productModel, $this->warehouseModel, $this->stockModel);
        }
    }

    /**
     * Memproses request pesan percakapan inventaris dari pengguna.
     *
     * @param array<string, mixed> $requestPayload Body payload request (misal dari json_decode / $_POST)
     * @return array<string, mixed> Response JSON terstruktur
     */
    public function process(array $requestPayload): array
    {
        // -----------------------------------------------------------------
        // 1. Session Guard (Autentikasi WAJIB dari $_SESSION)
        // -----------------------------------------------------------------
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        $userId   = $_SESSION['user_id'] ?? null;
        $userRole = $_SESSION['role'] ?? $_SESSION['user_role'] ?? null;

        if (empty($userId) || empty($userRole)) {
            if (!headers_sent()) {
                http_response_code(401);
            }
            return [
                'status'  => 'error',
                'message' => 'Unauthorized: Pengguna belum login atau sesi tidak valid',
            ];
        }

        $userId   = (int) $userId;
        $userRole = strtoupper(trim((string) $userRole));

        // -----------------------------------------------------------------
        // 2. CSRF Token Guard
        // -----------------------------------------------------------------
        $sessionCsrf = (string) ($_SESSION['csrf_token'] ?? '');
        $requestCsrf = (string) ($requestPayload['csrf_token'] ?? '');

        if ($sessionCsrf === '' || $requestCsrf === '' || !hash_equals($sessionCsrf, $requestCsrf)) {
            if (!headers_sent()) {
                http_response_code(403);
            }
            return [
                'status'  => 'error',
                'message' => 'Token CSRF tidak valid atau tidak disertakan',
            ];
        }

        // -----------------------------------------------------------------
        // 3. Sanitasi Input Prompt
        // -----------------------------------------------------------------
        $prompt = trim((string) ($requestPayload['message'] ?? $requestPayload['prompt'] ?? ''));

        if ($prompt === '') {
            if (!headers_sent()) {
                http_response_code(400);
            }
            return [
                'status'  => 'error',
                'message' => 'Instruksi kosong.',
            ];
        }

        // -----------------------------------------------------------------
        // 4. Ekstraksi LLM (LlmService)
        // -----------------------------------------------------------------
        $llmResult = $this->llmService->extract($prompt);

        if (!$llmResult['ok'] || !is_array($llmResult['data'])) {
            $reason = $llmResult['error'] ?? 'Gagal mengekstrak JSON dari LLM';

            if ($this->auditModel !== null) {
                $this->auditModel->log([
                    'user_id'           => $userId,
                    'raw_prompt'        => $prompt,
                    'llm_json_response' => $llmResult['raw'] ?? null,
                    'decision'          => 'REJECTED',
                    'reason'            => $reason,
                    'movement_id'       => null,
                    'model_name'        => $llmResult['model'] ?? null,
                    'latency_ms'        => $llmResult['latency_ms'] ?? null,
                ]);
            }

            return [
                'status' => 'rejected',
                'reason' => 'Gagal memproses instruksi: ' . $reason,
            ];
        }

        // -----------------------------------------------------------------
        // 5. Validasi Berlapis (ValidationLayer)
        // -----------------------------------------------------------------
        $validationResult = $this->validator->validate($llmResult['data'], $userRole);
        $validationStatus = $validationResult['status'] ?? 'rejected';
        $rawLlmResponse   = $llmResult['raw'] ?? json_encode($llmResult['data'], JSON_UNESCAPED_UNICODE);

        // -----------------------------------------------------------------
        // 6. Keputusan Validasi: CLARIFY
        // -----------------------------------------------------------------
        if ($validationStatus === 'clarify') {
            if ($this->auditModel !== null) {
                $this->auditModel->log([
                    'user_id'           => $userId,
                    'raw_prompt'        => $prompt,
                    'llm_json_response' => $rawLlmResponse,
                    'decision'          => 'CLARIFIED',
                    'reason'            => $validationResult['reason'] ?? 'Permintaan klarifikasi',
                    'movement_id'       => null,
                    'model_name'        => $llmResult['model'] ?? null,
                    'latency_ms'        => $llmResult['latency_ms'] ?? null,
                ]);
            }

            return [
                'status'  => 'clarify',
                'message' => $validationResult['reason'] ?? 'Maaf, perintah Anda kurang lengkap. Tolong sebutkan nama produk, jumlah, dan lokasinya.',
            ];
        }

        // -----------------------------------------------------------------
        // 7. Keputusan Validasi: REJECTED
        // -----------------------------------------------------------------
        if ($validationStatus === 'rejected') {
            if ($this->auditModel !== null) {
                $this->auditModel->log([
                    'user_id'           => $userId,
                    'raw_prompt'        => $prompt,
                    'llm_json_response' => $rawLlmResponse,
                    'decision'          => 'REJECTED',
                    'reason'            => $validationResult['reason'] ?? 'Validasi gagal',
                    'movement_id'       => null,
                    'model_name'        => $llmResult['model'] ?? null,
                    'latency_ms'        => $llmResult['latency_ms'] ?? null,
                ]);
            }

            return [
                'status' => 'rejected',
                'reason' => $validationResult['reason'] ?? 'Validasi gagal',
            ];
        }

        // -----------------------------------------------------------------
        // 8. Keputusan Validasi: OK & REQUIRES_CONFIRMATION = TRUE
        // -----------------------------------------------------------------
        if ($validationStatus === 'ok' && !empty($validationResult['requires_confirmation'])) {
            $confirmToken = bin2hex(random_bytes(16)); // 32 karakter hex

            if (!isset($_SESSION['pending_transactions']) || !is_array($_SESSION['pending_transactions'])) {
                $_SESSION['pending_transactions'] = [];
            }

            // Simpan pending transaction (berlaku 5 menit)
            // audit_id disiapkan null karena audit log mutasi stok dieksekusi di TransactionController
            $_SESSION['pending_transactions'][$confirmToken] = [
                'token'         => $confirmToken,
                'user_id'       => $userId,
                'raw_prompt'    => $prompt,
                'llm_result'    => $llmResult,
                'payload'       => $validationResult['normalized'],
                'created_at'    => time(),
                'expires_at'    => time() + 300, // 5 menit
                'audit_id'      => null,
            ];

            return [
                'status'        => 'requires_confirmation',
                'confirm_token' => $confirmToken,
                'intent'        => $validationResult['normalized']['intent'],
                'data'          => $validationResult['normalized'],
            ];
        }

        // -----------------------------------------------------------------
        // 9. Keputusan Validasi: OK & REQUIRES_CONFIRMATION = FALSE (CHECK_STOCK)
        // -----------------------------------------------------------------
        if ($validationStatus === 'ok' && empty($validationResult['requires_confirmation'])) {
            $norm        = $validationResult['normalized'];
            $productId   = (int) ($norm['product_id'] ?? 0);
            $productName = (string) ($norm['product_name'] ?? '');
            $unit        = (string) ($norm['unit'] ?? '');
            $warehouseId = $norm['source_warehouse_id'] ?? null;

            $warehouseName = 'semua gudang';
            $qty = 0.0;

            if ($warehouseId !== null) {
                $warehouseId = (int) $warehouseId;

                // Cari nama gudang jika warehouseModel tersedia
                if ($this->warehouseModel !== null) {
                    $allWh = $this->warehouseModel->getAllActive();
                    foreach ($allWh as $wh) {
                        if ((int) $wh['id'] === $warehouseId) {
                            $warehouseName = (string) $wh['warehouse_name'];
                            break;
                        }
                    }
                }

                if ($warehouseName === 'semua gudang' && !empty($llmResult['data']['source_warehouse'])) {
                    $warehouseName = (string) $llmResult['data']['source_warehouse'];
                }

                if ($this->stockModel !== null) {
                    $qty = $this->stockModel->getStock($productId, $warehouseId);
                }
            } else {
                $qty = isset($norm['source_stock']) && $norm['source_stock'] !== null
                    ? (float) $norm['source_stock']
                    : 0.0;
            }

            $qtyDisplay = (fmod($qty, 1.0) === 0.0) ? (int) $qty : $qty;
            $message    = "Stok {$productName} di {$warehouseName}: {$qtyDisplay} {$unit}";

            if ($this->auditModel !== null) {
                $this->auditModel->log([
                    'user_id'           => $userId,
                    'raw_prompt'        => $prompt,
                    'llm_json_response' => $rawLlmResponse,
                    'decision'          => 'EXECUTED',
                    'reason'            => $message,
                    'movement_id'       => null,
                    'model_name'        => $llmResult['model'] ?? null,
                    'latency_ms'        => $llmResult['latency_ms'] ?? null,
                ]);
            }

            return [
                'status'  => 'info',
                'message' => $message,
                'data'    => [
                    'product'   => $productName,
                    'warehouse' => $warehouseName,
                    'quantity'  => $qty,
                    'unit'      => $unit,
                ],
            ];
        }

        // Fail-closed fallback
        return [
            'status' => 'rejected',
            'reason' => 'Status validasi tidak dikenali',
        ];
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
