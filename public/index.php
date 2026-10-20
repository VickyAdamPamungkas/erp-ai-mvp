<?php
/**
 * WoodMind ERP — Front Controller Router
 *
 * Semua request masuk lewat file ini (via .htaccess atau XAMPP virtual-host).
 * Routing berdasarkan REQUEST_URI. Konfigurasi DB dari config/db.php.
 */

// ── 1. Bootstrap ────────────────────────────────────────────────────────────

// Load konfigurasi database (woodmind_erp, port 3307) — BUKAN config/database.php legacy.
require_once __DIR__ . '/../config/db.php';

// Buat koneksi PDO
$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    DB_HOST,
    DB_PORT,
    DB_NAME
);

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Koneksi database gagal']);
    exit;
}

// Session
session_start();

// CSRF token — buat jika belum ada
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── 2. Parse route ──────────────────────────────────────────────────────────

// Kalkulasi $basePath dinamis agar redirect aman di sub-folder XAMPP
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$basePath  = ($scriptDir === '' || $scriptDir === '/' || $scriptDir === '\\') ? '' : $scriptDir;

$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path       = '/' . ltrim(substr($requestUri, strlen($basePath)), '/');
$path       = rtrim($path, '/') ?: '/';
$method     = $_SERVER['REQUEST_METHOD'];

// ── 3. Helper: cek login ────────────────────────────────────────────────────

$isLoggedIn = !empty($_SESSION['user_id']);

// ── 4. Routing ──────────────────────────────────────────────────────────────

// ---------- GET / atau /login atau /chat ----------
if (($path === '/' || $path === '/login' || $path === '/chat') && $method === 'GET') {

    if (!$isLoggedIn) {
        $error = '';
        require __DIR__ . '/../views/login.php';
    } elseif ($path === '/login') {
        // Sudah login tapi mengakses /login → redirect ke chat
        header("Location: {$basePath}/chat");
    } else {
        require __DIR__ . '/../views/chat_ui.php';
    }
    exit;
}

// ---------- POST /login ----------
if ($path === '/login' && $method === 'POST') {

    // Validasi CSRF
    $csrfInput   = $_POST['csrf_token'] ?? '';
    $csrfSession = $_SESSION['csrf_token'] ?? '';
    if ($csrfSession === '' || !hash_equals($csrfSession, $csrfInput)) {
        $error = 'Token CSRF tidak valid. Silakan muat ulang halaman.';
        require __DIR__ . '/../views/login.php';
        exit;
    }

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Username dan password wajib diisi.';
        require __DIR__ . '/../views/login.php';
        exit;
    }

    // Query user dari tabel users + JOIN roles untuk mendapatkan role_name
    $stmt = $pdo->prepare(
        'SELECT u.id, u.username, u.password_hash, r.role_name AS role
         FROM users u
         JOIN roles r ON u.role_id = r.id
         WHERE u.username = :u
         LIMIT 1'
    );
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        // Regenerate session ID untuk cegah session fixation
        session_regenerate_id(true);

        $_SESSION['user_id']  = (int) $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role']     = $user['role'];

        // Regenerate CSRF token setelah login
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        header("Location: {$basePath}/chat");
        exit;
    }

    $error = 'Username atau password salah.';
    require __DIR__ . '/../views/login.php';
    exit;
}

// ---------- GET /logout ----------
if ($path === '/logout' && $method === 'GET') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']
        );
    }
    session_destroy();
    header("Location: {$basePath}/login");
    exit;
}

// ---------- GET /audit ----------
if ($path === '/audit' && $method === 'GET') {

    if (!$isLoggedIn) {
        header("Location: {$basePath}/login");
        exit;
    }

    $role = strtoupper($_SESSION['role'] ?? '');
    if ($role !== 'SUPERVISOR' && $role !== 'ADMIN') {
        http_response_code(403);
        require __DIR__ . '/../views/403.php';
        exit;
    }

    require_once __DIR__ . '/../app/Controllers/AuditController.php';
    $auditController = new AuditController($pdo);
    $auditController->index(true);
    exit;
}

// ---------- POST /api/chat-process ----------
if ($path === '/api/chat-process' && $method === 'POST') {

    header('Content-Type: application/json');

    if (!$isLoggedIn) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }

    $rawBody        = file_get_contents('php://input');
    $requestPayload = json_decode($rawBody, true);

    if (!is_array($requestPayload)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Request body harus JSON yang valid']);
        exit;
    }

    require_once __DIR__ . '/../app/Controllers/ChatController.php';
    $chatController = new ChatController(null, null, null, null, null, $pdo);
    $response       = $chatController->process($requestPayload);

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- POST /api/execute-transaction ----------
if ($path === '/api/execute-transaction' && $method === 'POST') {

    header('Content-Type: application/json');

    if (!$isLoggedIn) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }

    $rawBody        = file_get_contents('php://input');
    $requestPayload = json_decode($rawBody, true);

    if (!is_array($requestPayload)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Request body harus JSON yang valid']);
        exit;
    }

    require_once __DIR__ . '/../app/Controllers/TransactionController.php';
    $txController = new TransactionController($pdo);
    $response     = $txController->execute($requestPayload);

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- Fallback: 404 ----------
http_response_code(404);
if (strpos($path, '/api/') === 0) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Endpoint tidak ditemukan']);
} else {
    echo '<!doctype html><html><body><h1>404 — Halaman tidak ditemukan</h1></body></html>';
}
exit;