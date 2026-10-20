<?php

/**
 * AuditController — Controller untuk memantau log transaksi audit_logs.
 *
 * Sesuai arsitektur WoodMind ERP:
 * - Khusus SUPERVISOR dan ADMIN (Role-Based Access Control).
 * - Read-only query dengan Prepared Statements PDO.
 * - Memungkinkan filtering berdasarkan decision (status), tanggal, dan pencarian kata kunci.
 */
class AuditController
{
    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? self::resolvePdo();
    }

    /**
     * Memproses request tampilan audit log.
     * Mengembalikan data array jika dipanggil terprogram, atau me-render views/audit.php.
     *
     * @param bool $renderView
     * @return array<string, mixed>|null
     */
    public function index(bool $renderView = true): ?array
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        $userId = $_SESSION['user_id'] ?? null;
        if (empty($userId)) {
            if ($renderView) {
                header('Location: login');
                exit;
            }
            if (!headers_sent()) {
                http_response_code(401);
            }
            return ['status' => 'error', 'message' => 'Unauthorized'];
        }

        $role = strtoupper(trim((string) ($_SESSION['role'] ?? $_SESSION['user_role'] ?? '')));
        if ($role !== 'SUPERVISOR' && $role !== 'ADMIN') {
            if (!headers_sent()) {
                http_response_code(403);
            }
            if ($renderView) {
                $errorTitle = '403 Akses Ditolak';
                $errorMessage = 'Halaman Audit Log hanya dapat diakses oleh SUPERVISOR atau ADMIN.';
                if (file_exists(__DIR__ . '/../../views/403.php')) {
                    require __DIR__ . '/../../views/403.php';
                } else {
                    echo "<!doctype html><html lang='id'><head><meta charset='utf-8'><title>403 Forbidden</title></head><body style='font-family:sans-serif;padding:40px;text-align:center;'><h2>403 - Akses Ditolak</h2><p>" . htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') . "</p><p><a href='chat'>&larr; Kembali ke Chat</a></p></body></html>";
                }
                exit;
            }
            return ['status' => 'error', 'message' => 'Forbidden'];
        }

        $filters = [
            'decision'  => trim((string) ($_GET['decision'] ?? '')),
            'date_from' => trim((string) ($_GET['date_from'] ?? '')),
            'date_to'   => trim((string) ($_GET['date_to'] ?? '')),
            'search'    => trim((string) ($_GET['search'] ?? '')),
        ];

        $logs  = $this->getFilteredLogs($filters);
        $stats = $this->getStatistics();

        if ($renderView) {
            require __DIR__ . '/../../views/audit.php';
            return null;
        }

        return [
            'status'  => 'success',
            'filters' => $filters,
            'stats'   => $stats,
            'logs'    => $logs,
        ];
    }

    /**
     * Query data audit_logs dengan filter menggunakan prepared statements.
     *
     * @param array<string, string> $filters
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function getFilteredLogs(array $filters = [], int $limit = 100): array
    {
        if ($this->pdo === null) {
            return [];
        }

        $sql = 'SELECT a.id, a.user_id, a.raw_prompt, a.llm_json_response, a.decision,
                       a.reason, a.movement_id, a.model_name, a.latency_ms, a.created_at,
                       u.username, r.role_name
                FROM audit_logs a
                LEFT JOIN users u ON a.user_id = u.id
                LEFT JOIN roles r ON u.role_id = r.id
                WHERE 1=1';

        $params = [];

        if (!empty($filters['decision'])) {
            $validDecisions = ['EXECUTED', 'REJECTED', 'CLARIFIED', 'CANCELLED'];
            $dec = strtoupper(trim((string) $filters['decision']));
            if (in_array($dec, $validDecisions, true)) {
                $sql .= ' AND a.decision = :decision';
                $params[':decision'] = $dec;
            }
        }

        if (!empty($filters['date_from'])) {
            $sql .= ' AND DATE(a.created_at) >= :date_from';
            $params[':date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= ' AND DATE(a.created_at) <= :date_to';
            $params[':date_to'] = $filters['date_to'];
        }

        if (!empty($filters['search'])) {
            $sql .= ' AND (a.raw_prompt LIKE :search OR a.reason LIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $sql .= ' ORDER BY a.created_at DESC, a.id DESC LIMIT ' . (int) $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Menghitung statistik ringkas untuk header kartu metrik.
     *
     * @return array{total: int, executed: int, rejected: int, clarified: int, cancelled: int}
     */
    public function getStatistics(): array
    {
        if ($this->pdo === null) {
            return ['total' => 0, 'executed' => 0, 'rejected' => 0, 'clarified' => 0, 'cancelled' => 0];
        }

        $sql = 'SELECT 
                    COUNT(*) AS total,
                    SUM(CASE WHEN decision = "EXECUTED" THEN 1 ELSE 0 END) AS executed,
                    SUM(CASE WHEN decision = "REJECTED" THEN 1 ELSE 0 END) AS rejected,
                    SUM(CASE WHEN decision = "CLARIFIED" THEN 1 ELSE 0 END) AS clarified,
                    SUM(CASE WHEN decision = "CANCELLED" THEN 1 ELSE 0 END) AS cancelled
                FROM audit_logs';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'total'     => (int) ($row['total'] ?? 0),
            'executed'  => (int) ($row['executed'] ?? 0),
            'rejected'  => (int) ($row['rejected'] ?? 0),
            'clarified' => (int) ($row['clarified'] ?? 0),
            'cancelled' => (int) ($row['cancelled'] ?? 0),
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
