<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
    <title>AI Stock Assistant | WoodMind ERP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="bg-slate-100 text-slate-800 font-['Inter',sans-serif] antialiased">
    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar Navigasi -->
        <aside class="w-64 bg-slate-900 text-white flex flex-col justify-between shrink-0 shadow-xl z-20">
            <div>
                <!-- Brand Header -->
                <div class="p-6 border-b border-slate-800 flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white font-bold text-lg shadow-md">
                        WM
                    </div>
                    <div>
                        <h1 class="font-bold text-base tracking-wide text-white">WoodMind ERP</h1>
                        <p class="text-xs text-indigo-300 font-medium tracking-wider uppercase">Controlled AI Layer</p>
                    </div>
                </div>

                <!-- Navigation Links -->
                <nav class="p-4 space-y-1.5 text-sm font-medium">
                    <a href="chat" class="flex items-center space-x-3 px-4 py-3 rounded-xl bg-indigo-600 text-white font-semibold shadow-md shadow-indigo-600/30">
                        <span class="text-lg">🤖</span>
                        <span>AI Assistant</span>
                    </a>

                    <?php
                        $userRole = strtoupper(trim((string) ($_SESSION['role'] ?? '')));
                        if ($userRole === 'SUPERVISOR' || $userRole === 'ADMIN'):
                    ?>
                    <a href="audit" class="flex items-center space-x-3 px-4 py-3 rounded-xl text-slate-300 hover:bg-slate-800 hover:text-white transition group">
                        <span class="text-lg">📋</span>
                        <span>Audit Log</span>
                    </a>
                    <?php endif; ?>
                </nav>
            </div>

            <!-- User Info & Logout -->
            <div class="p-4 border-t border-slate-800 bg-slate-950/40">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-3 truncate">
                        <div class="w-9 h-9 rounded-full bg-indigo-500/20 border border-indigo-400/30 text-indigo-300 flex items-center justify-center font-bold text-xs uppercase">
                            <?= htmlspecialchars(substr($_SESSION['username'] ?? 'U', 0, 2), ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div class="truncate">
                            <p class="text-xs font-semibold text-white truncate"><?= htmlspecialchars($_SESSION['username'] ?? 'User', ENT_QUOTES, 'UTF-8') ?></p>
                            <span class="inline-block px-1.5 py-0.5 text-[10px] font-bold rounded bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 uppercase">
                                <?= htmlspecialchars($_SESSION['role'] ?? 'STAFF', ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                    </div>
                    <a href="logout" title="Logout" class="p-2 text-slate-400 hover:text-rose-400 hover:bg-rose-500/10 rounded-lg transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                        </svg>
                    </a>
                </div>
            </div>
        </aside>

        <!-- Main Chat Area -->
        <main class="flex-1 flex flex-col h-screen overflow-hidden bg-slate-100">
            <!-- Header Top Bar -->
            <header class="h-16 bg-white border-b border-slate-200 px-8 flex items-center justify-between shrink-0 shadow-sm z-10">
                <div class="flex items-center space-x-3">
                    <div class="relative">
                        <div class="w-10 h-10 rounded-full bg-indigo-100 border border-indigo-200 flex items-center justify-center text-xl">
                            🤖
                        </div>
                        <span class="w-3 h-3 rounded-full bg-emerald-500 border-2 border-white absolute bottom-0 right-0"></span>
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-slate-800">WoodMind AI Stock Assistant</h2>
                        <p class="text-xs text-slate-500 flex items-center space-x-1">
                            <span class="inline-block w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                            <span>Groq LLaMA 3.3 • 4-Stage Validation Layer • Single-Use Token</span>
                        </p>
                    </div>
                </div>

                <div class="flex items-center space-x-3 text-xs">
                    <span class="bg-indigo-50 text-indigo-700 px-3 py-1.5 rounded-full font-medium border border-indigo-100">
                        Controlled Execution Layer V1
                    </span>
                </div>
            </header>

            <!-- Chat Message Box -->
            <div id="messagesBox" class="messages-list bg-slate-50">
                <!-- Initial Welcome Bubble -->
                <div class="bubble ai">
                    <div class="font-semibold text-slate-900 mb-1 flex items-center space-x-1.5">
                        <span>👋</span>
                        <span>Halo, <?= htmlspecialchars($_SESSION['username'] ?? 'User', ENT_QUOTES, 'UTF-8') ?>!</span>
                    </div>
                    <p class="text-sm text-slate-700">
                        Saya asisten AI WoodMind ERP. Anda dapat menginstruksikan saya menggunakan bahasa alami untuk operasi inventaris kayu:
                    </p>
                    <ul class="mt-2 text-xs text-slate-600 space-y-1 list-disc list-inside">
                        <li><b>Cek Stok:</b> "Berapa stok kayu jati di Gudang Utama?"</li>
                        <li><b>Barang Masuk:</b> "Tambahkan 20 batang kayu jati ke Gudang Utama"</li>
                        <li><b>Barang Keluar:</b> "Keluarkan 5 batang kayu jati dari Gudang Utama"</li>
                        <li><b>Transfer Antar Gudang:</b> "Pindahkan 10 batang kayu jati dari Gudang Utama ke Gudang Sisa"</li>
                    </ul>
                    <p class="mt-2 text-[11px] text-slate-500 italic">
                        * Setiap mutasi stok wajib melewati kartu konfirmasi untuk mencegah manipulasi.
                    </p>
                </div>
            </div>

            <!-- Quick Suggestions Bar -->
            <div class="px-6 py-2.5 bg-slate-50 border-t border-slate-200/80 flex items-center space-x-2 overflow-x-auto shrink-0">
                <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider shrink-0 mr-1">Saran:</span>
                <button type="button" class="suggestion-chip px-3 py-1 bg-white hover:bg-indigo-50 hover:text-indigo-600 hover:border-indigo-300 text-slate-600 text-xs rounded-full border border-slate-200 transition shadow-sm shrink-0" data-prompt="Berapa stok Kayu Jati di Gudang Utama?">
                    🔍 Cek Kayu Jati
                </button>
                <button type="button" class="suggestion-chip px-3 py-1 bg-white hover:bg-indigo-50 hover:text-indigo-600 hover:border-indigo-300 text-slate-600 text-xs rounded-full border border-slate-200 transition shadow-sm shrink-0" data-prompt="Tambahkan 50 batang Kayu Jati ke Gudang Utama">
                    📥 Tambah 50 Jati
                </button>
                <button type="button" class="suggestion-chip px-3 py-1 bg-white hover:bg-indigo-50 hover:text-indigo-600 hover:border-indigo-300 text-slate-600 text-xs rounded-full border border-slate-200 transition shadow-sm shrink-0" data-prompt="Transfer 10 batang Kayu Jati dari Gudang Utama ke Gudang Sisa">
                    🔄 Transfer 10 Jati ke Gudang Sisa
                </button>
                <button type="button" class="suggestion-chip px-3 py-1 bg-white hover:bg-indigo-50 hover:text-indigo-600 hover:border-indigo-300 text-slate-600 text-xs rounded-full border border-slate-200 transition shadow-sm shrink-0" data-prompt="Keluarkan 5 batang Kayu Mahoni dari Gudang Sisa">
                    📤 Keluar 5 Mahoni
                </button>
            </div>

            <!-- Input Bar Form -->
            <footer class="p-4 bg-white border-t border-slate-200 shrink-0">
                <form id="chatForm" class="flex items-center space-x-3 max-w-5xl mx-auto">
                    <div class="relative flex-1">
                        <input
                            type="text"
                            id="messageInput"
                            placeholder="Tulis instruksi stok... (contoh: Masukkan 20 batang Kayu Jati ke Gudang Utama)"
                            autocomplete="off"
                            required
                            class="w-full px-5 py-3.5 bg-slate-50 border border-slate-300 rounded-2xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:bg-white text-slate-800 placeholder-slate-400 transition pr-12 shadow-inner"
                        >
                    </div>
                    <button
                        type="submit"
                        id="sendButton"
                        class="px-6 py-3.5 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-sm rounded-2xl transition shadow-md shadow-indigo-600/25 flex items-center space-x-2 shrink-0 active:scale-95 disabled:opacity-50"
                    >
                        <span>Kirim</span>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </button>
                </form>
            </footer>
        </main>
    </div>

    <script src="assets/js/chat.js"></script>
</body>
</html>
