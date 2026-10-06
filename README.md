# ERP Stock AI MVP

Prototype penelitian: ERP manajemen stok dengan conversational interface dan controlled execution.

## Instalasi
1. Taruh folder `erp-ai-mvp` di `C:\laragon\www\`.
2. Jalankan Apache dan MySQL di Laragon.
3. Buka phpMyAdmin atau MySQL terminal.
4. Import `database/schema.sql`.
5. Buka `http://localhost/erp-ai-mvp/public/`.
6. Login demo: `admin` / `admin123`.

## Contoh perintah AI
- Berapa stok kayu jati di Gudang A?
- Catat 25 kg kayu jati ke Gudang A.
- Pindahkan 20 kg kayu jati dari Gudang A ke Gudang B.

## Catatan
Versi ini adalah MVP lokal. Parser AI masih berupa rule-based mock agar flow penelitian bisa diuji tanpa API key. Tahap berikutnya dapat mengganti parser dengan LLM API dan mengganti stock backend dengan Odoo API adapter.

# ERP AI MVP

MVP penelitian Conversational Interface berbasis Large Language Model
untuk operasi manajemen stok pada ERP dengan mekanisme Controlled Execution.

## Konsep

User memasukkan instruksi menggunakan bahasa natural.

User
→ Mock LLM
→ Intent & Parameter Extraction
→ Validation Layer
→ User Confirmation
→ Controlled Execution
→ Database

## Fitur MVP

- Check stock berdasarkan material dan lokasi
- Check seluruh stock
- Stock in
- Stock transfer
- Validation material
- Validation lokasi
- Validation quantity
- Validation kecukupan stok sumber
- User confirmation sebelum transaksi
- Controlled execution
- Pencatatan AI request
- Pencatatan stock transaction

## Material Demo

- Kayu Jati
- Kayu Mahoni
- Kayu Pinus

## Keterbatasan MVP

Mock LLM masih menggunakan rule-based parser/regex dan belum menggunakan
LLM/API sebenarnya.

Sistem hanya mengenali pola instruksi tertentu dan belum mendukung
conversation state atau parameter yang diberikan secara bertahap.

## Teknologi

- PHP
- MySQL
- HTML/CSS/JavaScript
- Laragon