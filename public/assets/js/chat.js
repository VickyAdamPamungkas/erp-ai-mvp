/**
 * WoodMind ERP — Chat UI Client Logic
 *
 * Mengendalikan komunikasi AJAX antara frontend dengan:
 * - /api/chat-process (ChatController)
 * - /api/execute-transaction (TransactionController)
 *
 * Menerapkan:
 * - Anti double-submit pada tombol konfirmasi dan formulir
 * - Sanitasi output teks dengan escapeHtml untuk mencegah XSS
 * - Indikator animasi memproses pesan
 */

document.addEventListener('DOMContentLoaded', () => {
    const chatForm      = document.getElementById('chatForm');
    const messageInput  = document.getElementById('messageInput');
    const messagesBox   = document.getElementById('messagesBox');
    const sendButton    = document.getElementById('sendButton');
    const csrfMeta      = document.querySelector('meta[name="csrf-token"]');
    const csrfToken     = csrfMeta ? csrfMeta.getAttribute('content') : '';

    // Deteksi base URL untuk subfolder maupun root
    const baseUrl = window.location.pathname.replace(/\/chat\/?$/, '').replace(/\/+$/, '');

    /**
     * Helper sanitasi HTML untuk mencegah XSS
     */
    function escapeHtml(text) {
        if (!text) return '';
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }

    /**
     * Gulir otomatis ke pesan terbawah
     */
    function scrollToBottom() {
        if (messagesBox) {
            messagesBox.scrollTop = messagesBox.scrollHeight;
        }
    }

    /**
     * Menambahkan bubble teks ke kotak percakapan
     */
    function addBubble(content, type = 'ai', extraClass = '') {
        const bubble = document.createElement('div');
        bubble.className = `bubble ${type} ${extraClass}`.trim();
        bubble.innerHTML = content;
        messagesBox.appendChild(bubble);
        scrollToBottom();
        return bubble;
    }

    /**
     * Menampilkan bubble indikator mengetik (loading)
     */
    function showTypingIndicator() {
        const id = 'typingIndicator_' + Date.now();
        const bubble = document.createElement('div');
        bubble.id = id;
        bubble.className = 'bubble ai';
        bubble.innerHTML = `
            <div class="typing-indicator">
                <span class="typing-dot"></span>
                <span class="typing-dot"></span>
                <span class="typing-dot"></span>
            </div>
        `;
        messagesBox.appendChild(bubble);
        scrollToBottom();
        return id;
    }

    /**
     * Menghapus bubble indikator mengetik
     */
    function removeTypingIndicator(id) {
        const elem = document.getElementById(id);
        if (elem) {
            elem.remove();
        }
    }

    /**
     * Render Kartu Konfirmasi Transaksi (Kuning Amber)
     */
    function renderConfirmationCard(data, confirmToken) {
        const norm = data || {};
        const intent = norm.intent || '';

        let title = 'Konfirmasi Mutasi Stok';
        let detailHtml = '';

        const qtyDisplay = norm.qty !== undefined ? norm.qty : '';
        const unitDisplay = norm.unit ? escapeHtml(norm.unit) : '';
        const prodDisplay = norm.product_name ? escapeHtml(norm.product_name) : 'Produk';
        const srcWh = norm.source_warehouse_name ? escapeHtml(norm.source_warehouse_name) : 'Gudang Asal';
        const dstWh = norm.dest_warehouse_name ? escapeHtml(norm.dest_warehouse_name) : 'Gudang Tujuan';

        if (intent === 'STOCK_IN') {
            title = '📥 Konfirmasi Barang Masuk (STOCK_IN)';
            detailHtml = `
                <div><b>Produk:</b> ${prodDisplay}</div>
                <div><b>Jumlah:</b> ${qtyDisplay} ${unitDisplay}</div>
                <div><b>Gudang Tujuan:</b> ${dstWh}</div>
            `;
        } else if (intent === 'STOCK_OUT') {
            title = '📤 Konfirmasi Barang Keluar (STOCK_OUT)';
            detailHtml = `
                <div><b>Produk:</b> ${prodDisplay}</div>
                <div><b>Jumlah:</b> ${qtyDisplay} ${unitDisplay}</div>
                <div><b>Gudang Asal:</b> ${srcWh}</div>
            `;
        } else if (intent === 'TRANSFER_STOCK') {
            title = '🔄 Konfirmasi Transfer Stok (TRANSFER)';
            detailHtml = `
                <div><b>Produk:</b> ${prodDisplay}</div>
                <div><b>Jumlah:</b> ${qtyDisplay} ${unitDisplay}</div>
                <div><b>Dari Gudang:</b> ${srcWh}</div>
                <div><b>Ke Gudang:</b> ${dstWh}</div>
            `;
        } else {
            title = `⚠️ Konfirmasi Operasi (${escapeHtml(intent)})`;
            detailHtml = `
                <div><b>Produk:</b> ${prodDisplay}</div>
                <div><b>Jumlah:</b> ${qtyDisplay} ${unitDisplay}</div>
            `;
        }

        const card = document.createElement('div');
        card.className = 'bubble ai confirmation-card';
        card.innerHTML = `
            <div class="confirmation-header">
                <span>⚠️</span>
                <span>${escapeHtml(title)}</span>
            </div>
            <div class="confirmation-details">
                ${detailHtml}
                <div class="text-[11px] text-amber-600/70 mt-2 font-mono">Token: ${escapeHtml(confirmToken.substring(0, 12))}... (Masa berlaku 5 menit)</div>
            </div>
            <div class="confirmation-actions">
                <button type="button" class="btn-confirm" data-token="${escapeHtml(confirmToken)}" data-action="confirm">
                    <span>✅</span> <span>Konfirmasi</span>
                </button>
                <button type="button" class="btn-cancel" data-token="${escapeHtml(confirmToken)}" data-action="cancel">
                    <span>❌</span> <span>Batal</span>
                </button>
            </div>
        `;

        messagesBox.appendChild(card);
        scrollToBottom();

        // Bind aksi klik tombol konfirmasi & batal
        const confirmBtn = card.querySelector('.btn-confirm');
        const cancelBtn  = card.querySelector('.btn-cancel');

        const handleAction = async (action, clickedBtn) => {
            // Anti double-submit: Matikan kedua tombol seketika
            confirmBtn.disabled = true;
            cancelBtn.disabled  = true;
            clickedBtn.innerHTML = `<span>⏳</span> <span>Memproses...</span>`;

            try {
                const endpoint = (baseUrl ? baseUrl : '') + '/api/execute-transaction';
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        confirm_token: confirmToken,
                        action: action,
                        csrf_token: csrfToken
                    })
                });

                const result = await response.json();

                if (result.status === 'success') {
                    card.className = 'bubble ai confirmation-card !border-emerald-300 !bg-emerald-50';
                    card.innerHTML = `
                        <div class="flex items-center space-x-2 text-emerald-800 font-bold mb-1">
                            <span>✅</span>
                            <span>Transaksi Berhasil Dieksekusi</span>
                        </div>
                        <p class="text-sm text-emerald-700">${escapeHtml(result.message)}</p>
                        ${result.movement_id ? `<span class="inline-block mt-2 px-2 py-0.5 text-xs bg-emerald-200/60 text-emerald-800 font-mono rounded">Movement #${escapeHtml(String(result.movement_id))}</span>` : ''}
                    `;
                } else if (result.status === 'cancelled') {
                    card.className = 'bubble ai confirmation-card !border-slate-300 !bg-slate-100';
                    card.innerHTML = `
                        <div class="flex items-center space-x-2 text-slate-700 font-semibold">
                            <span>❌</span>
                            <span>Transaksi Dibatalkan oleh Pengguna</span>
                        </div>
                    `;
                } else {
                    card.className = 'bubble ai confirmation-card !border-rose-300 !bg-rose-50';
                    card.innerHTML = `
                        <div class="flex items-center space-x-2 text-rose-800 font-bold mb-1">
                            <span>⛔</span>
                            <span>Transaksi Ditolak</span>
                        </div>
                        <p class="text-sm text-rose-700">${escapeHtml(result.reason || result.message || 'Eksekusi transaksi gagal.')}</p>
                    `;
                }
            } catch (err) {
                console.error(err);
                card.className = 'bubble ai confirmation-card !border-rose-300 !bg-rose-50';
                card.innerHTML = `
                    <div class="flex items-center space-x-2 text-rose-800 font-bold mb-1">
                        <span>⚠️</span>
                        <span>Kesalahan Jaringan</span>
                    </div>
                    <p class="text-sm text-rose-700">Tidak dapat terhubung ke server untuk mengeksekusi transaksi.</p>
                `;
            }

            scrollToBottom();
        };

        confirmBtn.addEventListener('click', () => handleAction('confirm', confirmBtn));
        cancelBtn.addEventListener('click', () => handleAction('cancel', cancelBtn));
    }

    /**
     * Submit formulir pesan percakapan
     */
    if (chatForm) {
        chatForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            const text = messageInput.value.trim();
            if (!text) return;

            // Render bubble user seketika
            addBubble(escapeHtml(text), 'user');
            messageInput.value = '';

            // Cegah double submit
            messageInput.disabled = true;
            if (sendButton) sendButton.disabled = true;

            const typingId = showTypingIndicator();

            try {
                const endpoint = (baseUrl ? baseUrl : '') + '/api/chat-process';
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        message: text,
                        csrf_token: csrfToken
                    })
                });

                removeTypingIndicator(typingId);

                const result = await response.json();

                if (result.status === 'info') {
                    // Cek stok atau pesan informasi
                    addBubble(escapeHtml(result.message), 'ai', 'success');
                } else if (result.status === 'clarify') {
                    // Permintaan klarifikasi
                    addBubble(`⚠️ <b>Perlu Klarifikasi:</b><br>${escapeHtml(result.message)}`, 'ai', 'warning');
                } else if (result.status === 'rejected') {
                    // Validasi ditolak / entity tidak ditemukan / stok kurang
                    addBubble(`❌ <b>Ditolak:</b><br>${escapeHtml(result.reason || result.message || 'Permintaan tidak dapat diproses.')}`, 'ai', 'error');
                } else if (result.status === 'requires_confirmation') {
                    // Butuh konfirmasi user
                    renderConfirmationCard(result.data, result.confirm_token);
                } else {
                    // Respon error lainnya
                    addBubble(`❌ <b>Error:</b><br>${escapeHtml(result.message || 'Terjadi kesalahan sistem.')}`, 'ai', 'error');
                }
            } catch (err) {
                removeTypingIndicator(typingId);
                console.error(err);
                addBubble('❌ <b>Gagal terhubung ke server:</b> Silakan periksa koneksi atau coba sesaat lagi.', 'ai', 'error');
            } finally {
                messageInput.disabled = false;
                if (sendButton) sendButton.disabled = false;
                messageInput.focus();
            }
        });
    }

    // Quick suggestion chips handler
    document.querySelectorAll('.suggestion-chip').forEach(chip => {
        chip.addEventListener('click', () => {
            if (messageInput) {
                messageInput.value = chip.getAttribute('data-prompt') || chip.innerText.trim();
                messageInput.focus();
            }
        });
    });
});
