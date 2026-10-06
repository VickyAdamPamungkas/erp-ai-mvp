const form = document.getElementById('chatForm');
const input = document.getElementById('message');
const box = document.getElementById('messages');


// ==========================================
// TAMBAH PESAN KE CHAT
// ==========================================

function add(text, who = 'ai') {

    const d = document.createElement('div');

    d.className = 'bubble ' + who;

    d.innerHTML = text;

    box.appendChild(d);

    box.scrollTop = box.scrollHeight;

    return d;
}


// ==========================================
// SUBMIT CHAT
// ==========================================

form.addEventListener('submit', async (e) => {

    e.preventDefault();

    const msg = input.value.trim();

    if (!msg) return;


    // Tampilkan pesan user
    add(msg, 'user');

    input.value = '';


    try {

        const fd = new FormData();

        fd.append('message', msg);


        const r = await fetch('process.php', {
            method: 'POST',
            body: fd
        });


        const x = await r.json();


        // Debug response
        console.log('Response process.php:', x);


        // ==========================================
        // RESPONSE ERROR
        // ==========================================

        if (!x.ok) {

            add(
                '❌ ' + (x.message || 'Terjadi kesalahan.')
            );

            return;
        }


        // ==========================================
        // MEMBUTUHKAN KONFIRMASI
        // ==========================================

        if (x.requires_confirmation === true) {

            const requestId = x.request_id;

            const params = x.parameters || {};

            let detail = '';


            // STOCK IN
            if (x.intent === 'stock_in') {

                detail = `
                    <b>Tambah Stok</b><br>
                    Material: ${params.material}<br>
                    Quantity: ${params.quantity} ${params.unit}<br>
                    Lokasi: ${params.location}
                `;

            }


            // TRANSFER
            else if (x.intent === 'stock_transfer') {

                detail = `
                    <b>Transfer Stok</b><br>
                    Material: ${params.material}<br>
                    Quantity: ${params.quantity} ${params.unit}<br>
                    Dari: ${params.source}<br>
                    Ke: ${params.destination}
                `;

            }


            // INTENT LAIN
            else {

                detail = `
                    Intent: ${x.intent}
                `;

            }


            // ==========================================
            // BUAT BUBBLE KONFIRMASI
            // ==========================================

            const d = document.createElement('div');

            d.className = 'bubble ai confirmation';

            d.innerHTML = `

                <div>
                    ⚠️ <b>Konfirmasi Transaksi</b>
                </div>

                <br>

                ${detail}

                <br>

                <div>
                    ${x.message}
                </div>

                <br>

                <button
                    class="confirm"
                    onclick="confirmTx(${requestId}, this)">
                    ✅ Konfirmasi
                </button>

                <button
                    class="cancel"
                    onclick="cancelTx(this)">
                    ❌ Batal
                </button>

            `;


            box.appendChild(d);

            box.scrollTop = box.scrollHeight;

            return;
        }


        // ==========================================
        // RESPONSE BIASA
        // ==========================================

        add(
            x.message || 'Tidak ada response.'
        );

    }

    catch (error) {

        console.error(error);

        add(
            '❌ Tidak dapat terhubung ke server.'
        );

    }

});


// ==========================================
// KONFIRMASI TRANSAKSI
// ==========================================

async function confirmTx(requestId, button) {

    // Cegah double click
    button.disabled = true;

    button.innerText = 'Memproses...';


    try {

        const fd = new FormData();

        fd.append('request_id', requestId);


        const r = await fetch('confirm.php', {

            method: 'POST',

            body: fd

        });


        const x = await r.json();


        console.log('Response confirm.php:', x);


        // ==========================================
        // BERHASIL
        // ==========================================

        if (x.ok) {

            const bubble = button.closest('.bubble');


            if (bubble) {

                bubble.innerHTML = `

                    <div>
                        ✅ <b>Transaksi Berhasil</b>
                    </div>

                    <br>

                    ${x.message}

                `;

            }

        }


        // ==========================================
        // GAGAL
        // ==========================================

        else {

            add(
                '❌ ' + (x.message || 'Transaksi gagal.')
            );

            button.disabled = false;

            button.innerText = '✅ Konfirmasi';

        }

    }

    catch (error) {

        console.error(error);

        add(
            '❌ Terjadi kesalahan saat menjalankan transaksi.'
        );

        button.disabled = false;

        button.innerText = '✅ Konfirmasi';

    }

}


// ==========================================
// BATAL TRANSAKSI
// ==========================================

function cancelTx(button) {

    const bubble = button.closest('.bubble');


    if (bubble) {

        bubble.innerHTML = `
            ❌ <b>Transaksi dibatalkan.</b>
        `;

    }

}