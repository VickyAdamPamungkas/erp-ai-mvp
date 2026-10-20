# Migration Map

Peta migrasi file legacy ke arsitektur MVC target (WoodMind ERP).

## Tabel Pemetaan

| File Legacy | Peran Sekarang | Target Baru | Milestone |
|---|---|---|---|
| `ai/mock_llm.php` | Parser regex rule-based: fungsi `mockLLM()` mendeteksi intent (check_stock, stock_in, stock_transfer) dan parameter dari teks natural. | **TIDAK COCOK** → `app/Services/LlmService.php`. mock_llm.php bukan LLM service; ia parser regex fallback. LlmService.php akan memanggil Groq API. File ini dipertahankan sebagai fallback uji (AGENTS.md). | M2 |
| `ai/process.php` | Endpoint POST chat utama: memanggil `mockLLM()`, validasi entitas (material, lokasi, stok), simpan ke `ai_requests`, return JSON. Berisi helper `findMaterial()` dan `findLocation()`. | `app/Services/ValidationLayer.php` (validasi) + `app/Controllers/ChatController.php` (routing endpoint) | M3–M4 |
| `ai/confirm.php` | Endpoint POST eksekusi transaksi setelah konfirmasi user. Berisi logika stock_in dan stock_transfer dalam DB transaction (`beginTransaction`, `UPDATE stock`, `INSERT stock_transactions`, `UPDATE ai_requests`). | `app/Controllers/TransactionController.php` | M4 |
| `ai/chat.php` | Halaman UI chat: HTML layout + sidebar + form input + include `chat.js`. Tidak ada logika bisnis. | `app/Controllers/ChatController.php` (session guard) + `views/chat_ui.php` (HTML) | M4 |
| `inventory/materials.php` | Halaman CRUD-read tabel `materials`: `SELECT * FROM materials` + render tabel HTML. | `app/Models/ProductModel.php` (query) + view terpisah | M3 |
| `inventory/locations.php` | Halaman CRUD-read tabel `locations`: `SELECT * FROM locations` + render tabel HTML. | `app/Models/WarehouseModel.php` (query) + view terpisah | M3 |
| `inventory/stock.php` | Halaman tampilan stok gabungan: `SELECT` dari `stock` JOIN `materials`/`locations` + render tabel HTML. | `app/Models/StockModel.php` (query) + view terpisah | M3 |
| `inventory/operations.php` | Halaman riwayat transaksi: `SELECT` dari `stock_transactions` JOIN `materials`/`locations` + render tabel HTML. | `app/Controllers/TransactionController.php` (routing) / model `stock_movements` (query) + view terpisah | M4 |
| `dashboard/index.php` | Dashboard setelah login: 4 kartu statistik (`COUNT` materials, locations, stock_transactions, low stock) + diagram alur penelitian. | `views/dashboard.php` | M4 |
| `assets/js/chat.js` | Frontend chat: fetch ke `process.php`, render bubble, tombol konfirmasi (`confirmTx()` → fetch `confirm.php`), dan `cancelTx()`. | `public/assets/js/` | M4 |
| `assets/css/app.css` | Stylesheet seluruh aplikasi (sidebar, kartu, tabel, login, chat, konfirmasi). Isi terduplikasi: baris 1 minified + baris 2–208 readable. | `public/assets/css/` | M4 |
| `public/index.php` | Entry point + form login. Menggunakan `hash('sha256',...)` — belum `password_verify()`. | Tetap entry point | M4 |
| `public/logout.php` | Menghancurkan session dan redirect ke login. | Tetap di `public/` | M4 |
| `database/schema.sql` | DDL database `erp_ai_mvp`: tabel `users`, `materials`, `locations`, `stock`, `stock_transactions`, `ai_requests` + seed data. | Tetap di `database/` (legacy, jangan ubah) | — |
| `config/database.php` | Koneksi PDO ke MySQL dengan kredensial hardcode. | Tetap (legacy, jangan ubah). Digantikan oleh `config/db.php` di kode baru. | — |
| `README.md` | Dokumentasi proyek: instalasi, contoh, arsitektur, fitur, keterbatasan. | Tetap di root | — |

## Temuan

### Pemetaan Istilah

| Istilah MVP Lama | Istilah Target Baru | Catatan |
|---|---|---|
| `material` | `product` | Tabel DB tetap `materials` sampai skema baru dibuat |
| `location` | `warehouse` | Tabel DB tetap `locations` sampai skema baru dibuat |
| `operation` / `stock_transaction` | `stock_movement` | Tabel DB tetap `stock_transactions` sampai skema baru dibuat |

### Tabel Database (schema.sql lama)

Database: `erp_ai_mvp` (target baru: `woodmind_erp`)

| Tabel | Keterangan |
|---|---|
| `users` | User login (password SHA-256, bukan bcrypt) |
| `materials` | Master produk/material (name, unit) |
| `locations` | Master lokasi/gudang (name) |
| `stock` | Stok per material per lokasi (quantity DECIMAL(12,2)) |
| `stock_transactions` | Riwayat transaksi (IN, UPDATE, TRANSFER) |
| `ai_requests` | Log request AI (intent, parameter, validasi, eksekusi) |

### Lokasi Logika UPDATE Stok

- **File**: `ai/confirm.php`
- **Fungsi/blok**:
  - `stock_in` (baris 124–293): UPDATE atau INSERT ke tabel `stock`, INSERT ke `stock_transactions`, UPDATE `ai_requests`.
  - `stock_transfer` (baris 301–531): SELECT...FOR UPDATE stok sumber, UPDATE kurangi sumber, UPDATE/INSERT tujuan, INSERT `stock_transactions`, UPDATE `ai_requests`.
- Semua dalam `$pdo->beginTransaction()` / `commit()` / `rollBack()`.

### Lokasi Tombol Konfirmasi

- **File**: `assets/js/chat.js`
- **Fungsi**:
  - `confirmTx(requestId, button)` (baris 205–295): fetch POST ke `confirm.php` dengan `request_id`.
  - `cancelTx(button)` (baris 302–315): mengganti bubble HTML tanpa request server.
- Bubble konfirmasi dibuat di event listener `submit` (baris 133–174) saat `requires_confirmation === true`.

### Temuan Tambahan

1. **Password**: `public/index.php` memakai `hash('sha256',...)`, belum sesuai AGENTS.md (`password_hash()`/`password_verify()`).
2. **CSRF**: Tidak ada CSRF token di form manapun (belum sesuai AGENTS.md).
3. **`config/database.php` dilacak git**: File berisi kredensial hardcode dan terlacak oleh git — perlu di-untrack di milestone mendatang.
4. **`inventory/materials.php`**: Isi file terduplikasi (konten HTML muncul 2×).
5. **`assets/css/app.css`**: Baris 1 berisi versi minified, baris 2–208 versi readable — duplikasi.
6. **`ai/confirm.php` stock_in tidak pakai `SELECT...FOR UPDATE`**: Hanya stock_transfer yang memakai locking; stock_in langsung UPDATE tanpa row lock.
