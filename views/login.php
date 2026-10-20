<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | WoodMind ERP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="bg-gradient-to-tr from-slate-900 via-indigo-950 to-slate-900 min-h-screen flex items-center justify-center p-4 font-['Inter',sans-serif]">
    <div class="bg-white/95 backdrop-blur-md w-full max-w-md p-8 rounded-3xl shadow-2xl border border-white/20">
        <!-- Brand Header -->
        <div class="text-center mb-8">
            <div class="w-14 h-14 mx-auto rounded-2xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white font-bold text-2xl shadow-lg shadow-indigo-500/30 mb-4">
                WM
            </div>
            <h1 class="text-2xl font-bold text-slate-900 tracking-tight">WoodMind ERP</h1>
            <p class="text-xs text-slate-500 mt-1 uppercase font-semibold tracking-wider">Controlled AI Execution Layer</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="mb-5 p-3.5 bg-rose-50 border border-rose-200 text-rose-700 text-sm rounded-xl flex items-center space-x-2">
                <span class="text-base">⚠️</span>
                <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        <?php endif; ?>

        <!-- Login Form -->
        <form method="POST" action="login" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

            <div>
                <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">Username</label>
                <input
                    type="text"
                    name="username"
                    required
                    autocomplete="username"
                    value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="Masukkan username"
                    class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:bg-white transition"
                >
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">Password</label>
                <input
                    type="password"
                    name="password"
                    required
                    autocomplete="current-password"
                    placeholder="••••••••"
                    class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:bg-white transition"
                >
            </div>

            <button
                type="submit"
                class="w-full py-3.5 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-sm rounded-xl transition shadow-lg shadow-indigo-600/30 active:scale-[0.99] mt-2"
            >
                Masuk ke Sistem
            </button>
        </form>

        <!-- Hint Box -->
        <div class="mt-8 pt-6 border-t border-slate-100 text-center text-xs text-slate-500">
            <p class="font-medium text-slate-700 mb-1">Demo Credentials:</p>
            <div class="bg-slate-50 p-2.5 rounded-lg border border-slate-200/80 font-mono text-[11px] text-slate-600 space-y-0.5">
                <div>Staff: <span class="text-indigo-600 font-bold">staf1</span> / password</div>
                <div>Supervisor: <span class="text-indigo-600 font-bold">spv1</span> / password</div>
            </div>
        </div>
    </div>
</body>
</html>
