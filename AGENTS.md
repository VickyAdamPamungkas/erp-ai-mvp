# WoodMind ERP - Aturan Proyek untuk AI Agent

## Tujuan
Membangun Controlled Execution Layer antara LLM dan database untuk modul inventaris kayu (artefak Design Science Research).
Lingkup V1: Cek Stok, Barang Masuk, Barang Keluar, Transfer.

## Aturan Arsitektur (TIDAK BOLEH DILANGGAR)
- LLM tidak punya akses DB. LLM hanya mengubah teks menjadi JSON. Output LLM = input tidak tepercaya.
- Pipeline: LlmService -> ValidationLayer (1 Schema, 2 Entity, 3 Permission, 4 Business Rule) -> Confirmation (controller, token sekali pakai) -> eksekusi.
- Fail-closed: ragu, error, atau JSON rusak = tolak. Jangan pernah mengembalikan status ok karena exception.
- INSERT/UPDATE/DELETE stok hanya dipanggil dari TransactionController, di dalam DB transaction + SELECT ... FOR UPDATE, dan hanya setelah konfirmasi user. Validasi diulang saat eksekusi.
- LlmService dan ValidationLayer tidak menyentuh tulis-DB. Model hanya berisi query, tanpa logika bisnis.
- Setiap prompt WAJIB tercatat di audit_logs (EXECUTED, REJECTED, CLARIFIED, CANCELLED) lewat AuditModel. audit_logs append-only.

## Tech Stack
- PHP 8.2 native MVC: app/Controllers, app/Services, app/Models, views, public.
- PDO + MySQL/MariaDB XAMPP, database woodmind_erp. Tailwind CSS v3, Vanilla JS. Groq API lewat cURL.
- Dilarang: framework PHP/JS, composer, SDK LLM, atau library baru tanpa persetujuan.

## Keamanan
- Prepared statements SAJA. Dilarang menyisipkan variabel ke string SQL.
- user_id dari $_SESSION, bukan dari request. Saat konfirmasi klien hanya mengirim confirm_token; payload diambil dari session.
- CSRF token di semua POST. htmlspecialchars pada semua output.
- API key dan kredensial DB hanya di config/api.php dan config/db.php (di-.gitignore). Jangan hardcode, commit, atau cetak ke log/error.
- Password memakai password_hash() / password_verify().

## Kontrak
- Endpoint AJAX wajib header Content-Type: application/json.
- Intent valid: CHECK_STOCK, STOCK_IN, STOCK_OUT, TRANSFER_STOCK, CLARIFY_REQUIRED. Selain itu ditolak.
- LLM: temperature 0, balas JSON mentah. LLM menyalin nama produk/gudang persis seperti diketik, tanpa koreksi typo. Typo ditangani Entity Validation lewat NameNormalizer.
- Status respons: requires_confirmation, clarify, rejected, info, error, success.

## Aturan Bisnis
- Stok tidak boleh negatif. qty > 0.
- Satuan batang = bilangan bulat. Satuan m3 = maksimal 3 desimal. Bandingkan angka sebagai integer milli-unit, bukan float.
- Satuan input harus cocok dengan satuan produk (alias: btg = batang, kubik/m3 = m3).
- Transfer: gudang asal tidak boleh sama dengan tujuan. Produk dan gudang harus ada dan is_active = 1.
- Pesan baku:
  - "Validasi Gagal: Entitas '{nama}' tidak ditemukan di database"
  - "Validasi Gagal: Stok tidak cukup (tersedia: X, diminta: Y)"
  - Ambigu: "Maaf, perintah Anda kurang lengkap. Tolong sebutkan nama produk, jumlah, dan lokasinya."

## Non-goals V1 (jangan dibangun)
Akuntansi, HRD, Odoo, notifikasi, multi-bahasa, pembuatan produk/gudang lewat chat, intent DELETE, perubahan skema di luar database/schema.sql.

## Lingkungan
- Windows + XAMPP. Terminal = PowerShell. Jangan memakai sintaks bash (heredoc, operator < dan &&).
- PHP: C:/xampp/php/php.exe. DB: host 127.0.0.1, port 3307.
- Cek sintaks: php -l <file>. Jalankan tes: php tests/run.php.

## File legacy (JANGAN diubah sampai ada instruksi eksplisit)
ai/, inventory/, dashboard/, public/, assets/, config/database.php, database/schema.sql.
ai/mock_llm.php dipertahankan sebagai fallback uji dan tidak dipanggil dari kode baru.
Peta migrasi: docs/MIGRATION_MAP.md.

## Cara Kerja
- Satu tugas per sesi. Baca file yang disebut di prompt dan jangan menebak isi file.
- Tulis rencana singkat dan TUNGGU persetujuan sebelum membuat atau mengubah file.
- Jangan membuat file di luar daftar tugas. Jangan menambah fitur, tabel, atau kolom di luar spesifikasi; tanyakan dulu.
- Tulis tes untuk tiap aturan validasi. Jangan melonggarkan ekspektasi tes agar lulus.
- Jangan mengklaim "sudah dites" tanpa output nyata. Jangan menjalankan tes yang memakai kuota API kecuali diminta.
- Laporan akhir maksimal 15 baris: file dibuat, hasil verifikasi, asumsi, keraguan.
- Jangan melakukan git commit. User yang commit.

## Keputusan Final
- audit_logs append-only (trigger BEFORE UPDATE dan BEFORE DELETE).
- Koreksi stok lewat transaksi pembalik, bukan hapus.