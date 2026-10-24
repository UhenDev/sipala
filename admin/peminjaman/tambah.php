<?php
require_once __DIR__ . '/../includes/admin.php';
require_petugas();
require_once __DIR__ . '/../../includes/qrcode.php';
require_once __DIR__ . '/../../includes/whatsapp.php';

// Ambil daftar alat lab yang tersedia
$items = $pdo->query('
    SELECT p.id, p.kode_barang, p.nama_barang, p.jumlah_tersedia, p.kondisi, p.lokasi, k.nama_kategori 
    FROM peralatan p 
    LEFT JOIN kategori k ON k.id = p.kategori_id 
    WHERE p.status = "aktif" AND p.jumlah_tersedia > 0 
    ORDER BY p.nama_barang ASC
')->fetchAll();

// Ambil daftar pengguna peminjam
$users = $pdo->query('
    SELECT id, nama, nis, kelas, rfid_uid 
    FROM users 
    WHERE status = "aktif" 
    ORDER BY nama ASC
')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $userId = (int)($_POST['user_id'] ?? 0);
    $rfidUid = trim((string)($_POST['rfid_uid'] ?? ''));
    $tglPinjam = (string)($_POST['tanggal_pinjam'] ?? date('Y-m-d'));
    $tglKembali = (string)($_POST['tanggal_rencana_kembali'] ?? date('Y-m-d', strtotime('+3 days')));
    $keperluan = trim((string)($_POST['keperluan'] ?? 'Praktikum Laboratorium'));
    $selectedItems = $_POST['items'] ?? []; // array [peralatan_id => jumlah]

    try {
        if ($userId <= 0) {
            throw new RuntimeException('Silakan tap kartu RFID atau pilih peminjam terlebih dahulu.');
        }

        $stUser = $pdo->prepare('SELECT id, status, nama FROM users WHERE id = ?');
        $stUser->execute([$userId]);
        $uInfo = $stUser->fetch();
        if (!$uInfo || $uInfo['status'] !== 'aktif') {
            throw new RuntimeException('Akun siswa/peminjam tidak ditemukan atau sedang dinonaktifkan.');
        }

        if ($tglKembali < $tglPinjam) {
            throw new RuntimeException('Tanggal rencana pengembalian tidak boleh mendahului tanggal pinjam.');
        }

        // Filter item yang jumlahnya > 0
        $validItems = [];
        foreach ($selectedItems as $itemId => $qty) {
            $qty = (int)$qty;
            if ($qty > 0) {
                $validItems[(int)$itemId] = $qty;
            }
        }

        if (empty($validItems)) {
            throw new RuntimeException('Pilih minimal satu alat laboratorium yang dipinjam.');
        }

        $pdo->beginTransaction();

        // Generate Kode Peminjaman
        $kode = 'PJM-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

        // Insert Peminjaman dengan validasi RFID jika tersedia
        $stmt = $pdo->prepare('
            INSERT INTO peminjaman (
                kode_peminjaman, user_id, rfid_uid, rfid_verified_at, rfid_verified_by,
                tanggal_pengajuan, tanggal_pinjam, tanggal_rencana_kembali,
                keperluan, status, disetujui_oleh, tanggal_disetujui
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "DIPINJAM", ?, NOW())
        ');

        $now = date('Y-m-d H:i:s');
        $verifiedAt = $rfidUid !== '' ? $now : null;
        $verifiedBy = $rfidUid !== '' ? (int)$_SESSION['user_id'] : null;

        $stmt->execute([
            $kode,
            $userId,
            $rfidUid !== '' ? $rfidUid : null,
            $verifiedAt,
            $verifiedBy,
            date('Y-m-d'),
            $tglPinjam,
            $tglKembali,
            $keperluan !== '' ? $keperluan : 'Praktikum',
            (int)$_SESSION['user_id']
        ]);

        $peminjamanId = (int)$pdo->lastInsertId();

        // Alokasikan unit fisik spesifik dan perbarui status unit menjadi DIPINJAM
        $stAvail = $pdo->prepare("
            SELECT id, kode_unit, serial_number, barcode 
            FROM unit_peralatan 
            WHERE peralatan_id = ? AND status = 'TERSEDIA' AND kondisi != 'Rusak Berat' 
            ORDER BY id ASC
            LIMIT ? FOR UPDATE
        ");
        $stUpdUnit = $pdo->prepare("UPDATE unit_peralatan SET status = 'DIPINJAM' WHERE id = ?");
        $insertDetail = $pdo->prepare('
            INSERT INTO detail_peminjaman (peminjaman_id, peralatan_id, unit_peralatan_id, kode_unit_snapshot, barcode_snapshot, jumlah, kondisi_awal)
            VALUES (?, ?, ?, ?, ?, 1, "Baik")
        ');

        foreach ($validItems as $itemId => $qty) {
            $stName = $pdo->prepare("SELECT nama_barang FROM peralatan WHERE id = ?");
            $stName->execute([$itemId]);
            $alatName = (string)($stName->fetchColumn() ?: "ID #$itemId");

            $stAvail->bindValue(1, $itemId, PDO::PARAM_INT);
            $stAvail->bindValue(2, $qty, PDO::PARAM_INT);
            $stAvail->execute();
            $availUnits = $stAvail->fetchAll();

            if (count($availUnits) < $qty) {
                throw new RuntimeException("Unit fisik yang siap dipinjam tidak mencukupi untuk '$alatName' (Dibutuhkan: $qty unit, Tersedia: " . count($availUnits) . " unit).");
            }

            foreach ($availUnits as $u) {
                $stUpdUnit->execute([$u['id']]);
                $insertDetail->execute([$peminjamanId, $itemId, $u['id'], $u['kode_unit'], $u['barcode']]);
            }

            // Sinkronkan stok master peralatan secara otomatis berdasarkan data unit_peralatan
            sync_peralatan_stock($itemId);
        }

        $pdo->commit();

        audit_log('BORROW', 'PEMINJAMAN', $peminjamanId, "Peminjaman manual dibuat untuk $kode (" . ($rfidUid !== '' ? "Verifikasi RFID: $rfidUid" : "Manual") . ")");
        send_notification($userId, 'Peminjaman Alat Laboratorium Baru', "Peminjaman alat ($kode) telah dicatat dan diserahkan oleh petugas lab.", 'info', 'index.php');

        // Kirim Notifikasi WhatsApp Serah Terima ke Siswa
        try {
            $stInfo = $pdo->prepare("
                SELECT u.nama, u.no_hp,
                       GROUP_CONCAT(CONCAT(pr.nama_barang, ' (', COALESCE(up.kode_unit, '-'), ')') SEPARATOR ', ') AS rincian_barang
                FROM peminjaman p
                JOIN users u ON u.id = p.user_id
                JOIN detail_peminjaman dp ON dp.peminjaman_id = p.id
                JOIN peralatan pr ON pr.id = dp.peralatan_id
                LEFT JOIN unit_peralatan up ON up.id = dp.unit_peralatan_id
                WHERE p.id = ? GROUP BY p.id
            ");
            $stInfo->execute([$peminjamanId]);
            $info = $stInfo->fetch();
            if ($info && !empty($info['no_hp'])) {
                send_wa_notification('serah_terima', [
                    'nama' => $info['nama'],
                    'no_hp' => $info['no_hp'],
                    'kode_peminjaman' => $kode,
                    'alat' => $info['rincian_barang'],
                    'kode_unit' => $info['rincian_barang'],
                    'tgl_pinjam' => $tglPinjam,
                    'tgl_kembali' => $tglKembali,
                    'peminjaman_id' => $peminjamanId
                ]);
            }
        } catch (Throwable $eWa) {
            error_log('Gagal kirim WA direct borrow: ' . $eWa->getMessage());
        }

        flash('success', 'Peminjaman ' . $kode . ' berhasil dibuat & unit fisik telah dialokasikan!');
        redirect('detail.php?id=' . $peminjamanId);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $e->getMessage());
    }
}

page_start('Peminjaman Baru (RFID)', 'Proses peminjaman alat lab dengan validasi smart card', 'peminjaman');
?>

<div class="head">
    <div>
        <h2>Peminjaman Alat Laboratorium Baru</h2>
        <p>Validasi identitas siswa menggunakan reader RFID sebelum menyerahkan alat.</p>
    </div>
    <a class="btn" href="index.php">Kembali ke Daftar</a>
</div>

<form method="post" id="formPeminjaman">
    <?= csrf_field() ?>
    <!-- TAHAP 1: RFID READER -->
    <div class="rfid-box" style="margin-bottom:24px;">
        <div style="display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap;">
            <div class="rfid-wave-icon" style="margin-top:2px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="5" width="20" height="14" rx="2"></rect>
                    <line x1="2" y1="10" x2="22" y2="10"></line>
                </svg>
            </div>
            <div style="flex:1;min-width:280px;">
                <h3>Langkah 1: Tap Kartu Siswa ke Reader RFID</h3>
                <p style="margin-bottom:12px;">
                    Dekatkan ID Card Siswa ke reader USB. Data siswa peminjam akan otomatis terverifikasi secara instan.
                </p>

                <div style="display:flex;gap:10px;">
                    <input type="text" id="rfidScanInput" placeholder="Tempelkan kartu siswa di sini..." 
                           class="rfid-autofocus" data-rfid-scanner="true"
                           autocomplete="off"
                           style="flex:1;padding:9px 13px;border-radius:6px;border:1px solid #cbd5e1;background:#ffffff;color:#0f172a;font-size:14.5px;font-family:'JetBrains Mono', monospace;font-weight:600;">
                    <button type="button" class="btn btn-primary" onclick="lookupRfid()" style="padding:8px 16px;">
                        Cek Kartu
                    </button>
                </div>
            </div>

            <!-- HASIL VALIDASI IDENTITAS SISWA -->
            <div id="studentCardPreview" style="flex:1;min-width:280px;background:#ffffff;border:1px solid #bfdbfe;border-radius:10px;padding:14px;box-shadow:0 1px 3px rgba(0,0,0,0.03);">
                <div id="noStudentState" style="text-align:center;padding:14px 10px;color:#64748b;">
                    <div style="font-size:22px;margin-bottom:4px;">💳</div>
                    <div style="font-weight:600;font-size:13px;color:#1e293b;">Menunggu pemindaian kartu RFID siswa...</div>
                    <small style="color:#64748b;font-size:12px;">Atau pilih manual dari opsi di bawah jika kartu tertinggal.</small>
                </div>
                <div id="verifiedStudentState" style="display:none;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                        <span class="rfid-badge rfid-badge-active" style="font-size:11.5px;">✓ Kartu Terverifikasi Sah</span>
                        <small id="displayRfid" style="color:#1e40af;font-family:monospace;font-weight:600;"></small>
                    </div>
                    <div style="font-size:16px;font-weight:700;color:#0f172a;" id="displayNama">-</div>
                    <div style="font-size:13px;color:#475569;margin-top:2px;">
                        NIS: <strong id="displayNis" style="color:#0f172a;">-</strong> &bull; Kelas: <strong id="displayKelas" style="color:#0f172a;">-</strong>
                    </div>
                    <div style="margin-top:8px;font-size:12px;color:#15803d;font-weight:600;" id="displayStatusPinjam">
                        Status: Bebas pinjaman aktif
                    </div>
                </div>
            </div>
        </div>

        <input type="hidden" name="user_id" id="inputUserId" required>
        <input type="hidden" name="rfid_uid" id="inputRfidUid">
    </div>

    <!-- OPSI TAMBAHAN: PILIH SISWA MANUAL DENGAN FITUR SEARCH -->
    <div class="panel" style="margin-bottom:24px;">
        <div class="panel-title" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
            <span>Opsi Tambahan: Pilih Siswa Secara Manual (Jika Tanpa Kartu)</span>
            <span style="font-size:12px;color:#64748b;font-weight:normal;">Gunakan pencarian jika kartu siswa tertinggal</span>
        </div>
        <div style="padding:16px 22px;">
            <!-- Kotak Pencarian Siswa Manual -->
            <div style="display:flex;gap:10px;margin-bottom:12px;">
                <div style="position:relative;flex:1;">
                    <input type="text" id="searchManualStudent" 
                           placeholder="Ketik nama siswa, NIS, atau kelas untuk mencari..." 
                           oninput="filterManualStudents()" 
                           autocomplete="off"
                           style="width:100%;padding:10px 14px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;outline:none;">
                </div>
                <button type="button" class="btn" onclick="resetManualSearch()">Reset</button>
            </div>

            <!-- Hasil Pencarian Cepat -->
            <div id="manualStudentResults" style="display:none;margin-bottom:12px;"></div>

            <!-- Select Dropdown Manual -->
            <label style="display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:6px;">
                Atau pilih langsung dari daftar siswa:
            </label>
            <select id="selectManualUser" onchange="manualUserSelected(this)" style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;background:#ffffff;">
                <option value="">-- Pilih Nama Siswa Manual Dari Daftar --</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= $u['id'] ?>" data-nama="<?= e($u['nama']) ?>" data-nis="<?= e($u['nis'] ?: '-') ?>" data-kelas="<?= e($u['kelas'] ?: '-') ?>" data-rfid="<?= e($u['rfid_uid'] ?: '') ?>">
                        <?= e($u['nama']) ?> (NIS: <?= e($u['nis'] ?: '-') ?> | Kelas: <?= e($u['kelas'] ?: '-') ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- TAHAP 2: PILIH PERALATAN LAB -->
    <div class="panel" style="margin-bottom:24px;">
        <div class="panel-title" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
            <span>Langkah 2: Pilih Peralatan Laboratorium yang Dipinjam</span>
            <span style="font-size:12px;color:#64748b;font-weight:normal;">Tentukan jumlah alat yang diambil</span>
        </div>
        <div style="padding:10px 20px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
            <div style="position:relative;flex:1;max-width:380px;">
                <input type="text" id="searchItemInput" placeholder="Cari nama alat, kode barang, kategori, lokasi..." 
                       oninput="filterItemTable()" 
                       autocomplete="off"
                       style="width:100%;padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;background:#ffffff;">
            </div>
            <span id="searchItemCount" style="font-size:12.5px;color:#64748b;">Menampilkan <?= count($items) ?> peralatan lab</span>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width:50px;">Pilih</th>
                        <th>Kode & Nama Alat</th>
                        <th>Kategori</th>
                        <th>Kondisi</th>
                        <th>Lokasi Simpan</th>
                        <th>Stok Tersedia</th>
                        <th style="width:140px;">Jumlah Pinjam</th>
                    </tr>
                </thead>
                <tbody id="itemsTableBody">
                    <?php if (empty($items)): ?>
                        <tr>
                            <td colspan="7">
                                <div class="empty">Tidak ada peralatan lab yang memiliki stok tersedia untuk dipinjam saat ini.</div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($items as $it): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" id="chk_<?= $it['id'] ?>" onchange="toggleItem(<?= $it['id'] ?>)" style="width:18px;height:18px;cursor:pointer;">
                                </td>
                                <td>
                                    <strong><?= e($it['nama_barang']) ?></strong>
                                    <div style="font-size:12px;color:#64748b;font-family:monospace;"><?= e($it['kode_barang']) ?></div>
                                </td>
                                <td><?= e($it['nama_kategori'] ?: '-') ?></td>
                                <td><span class="badge aktif"><?= e($it['kondisi']) ?></span></td>
                                <td><?= e($it['lokasi'] ?: '-') ?></td>
                                <td>
                                    <span style="font-size:15px;font-weight:700;color:#16a34a;"><?= $it['jumlah_tersedia'] ?></span> unit
                                </td>
                                <td>
                                    <input type="number" name="items[<?= $it['id'] ?>]" id="qty_<?= $it['id'] ?>" 
                                           min="1" max="<?= $it['jumlah_tersedia'] ?>" value="1" disabled
                                           style="width:90px;padding:6px 10px;border:1px solid #cbd5e1;border-radius:6px;">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- TAHAP 3: INFORMASI TANGGAL & KEPERLUAN -->
    <div class="form-panel">
        <h3 style="margin:0 0 16px 0;font-size:16px;">Langkah 3: Detail Tanggal & Keperluan</h3>
        <div class="grid">
            <div class="field">
                <label>Tanggal Pinjam *</label>
                <input type="date" name="tanggal_pinjam" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="field">
                <label>Tanggal Rencana Kembali *</label>
                <input type="date" name="tanggal_rencana_kembali" value="<?= date('Y-m-d', strtotime('+3 days')) ?>" required>
            </div>
            <div class="field full">
                <label>Keperluan Peminjaman *</label>
                <input type="text" name="keperluan" required value="Praktikum Mandiri Laboratorium" placeholder="Contoh: Praktikum Kimia Organik / Eksperimen Fisika">
            </div>
        </div>

        <div class="form-actions" style="display:flex;gap:10px;margin-top:20px;">
            <a class="btn" href="index.php">Batal</a>
            <button type="submit" class="btn btn-primary" style="padding:12px 28px;font-size:14px;" id="btnSubmit">
                Konfirmasi & Serahkan Alat
            </button>
        </div>
    </div>
</form>

<script>
const allStudents = <?= json_encode(array_map(fn($u) => [
    'id' => (int)$u['id'],
    'nama' => $u['nama'],
    'nis' => $u['nis'] ?: '-',
    'kelas' => $u['kelas'] ?: '-',
    'rfid_uid' => $u['rfid_uid'] ?: ''
], $users)) ?>;

function filterManualStudents() {
    const q = document.getElementById('searchManualStudent').value.toLowerCase().trim();
    const sel = document.getElementById('selectManualUser');
    const resultBox = document.getElementById('manualStudentResults');

    // 1. Filter dropdown options
    for (let i = 1; i < sel.options.length; i++) {
        const opt = sel.options[i];
        const text = opt.text.toLowerCase();
        opt.style.display = (q === '' || text.includes(q)) ? '' : 'none';
    }

    // 2. Render quick select items jika mencari
    if (q.length >= 1) {
        const matches = allStudents.filter(s => 
            s.nama.toLowerCase().includes(q) || 
            s.nis.toLowerCase().includes(q) || 
            s.kelas.toLowerCase().includes(q)
        );

        if (matches.length > 0) {
            resultBox.style.display = 'block';
            let html = '<div style="font-size:12px;color:#475569;margin-bottom:6px;font-weight:600;">Hasil Pencarian Siswa (' + matches.length + ' ditemukan):</div>';
            html += '<div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(260px, 1fr));gap:8px;max-height:160px;overflow-y:auto;padding-right:4px;">';
            matches.forEach(m => {
                html += '<div onclick="selectQuickStudent(' + m.id + ')" style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;" onmouseover="this.style.background=\'#edf6fd\'" onmouseout="this.style.background=\'#f8fafc\'">';
                html += '<div><strong>' + escapeHtml(m.nama) + '</strong><div style="font-size:11.5px;color:#64748b;">NIS: ' + escapeHtml(m.nis) + ' | ' + escapeHtml(m.kelas) + '</div></div>';
                html += '<button type="button" class="btn btn-sm btn-edit" style="pointer-events:none;">Pilih</button>';
                html += '</div>';
            });
            html += '</div>';
            resultBox.innerHTML = html;
        } else {
            resultBox.style.display = 'block';
            resultBox.innerHTML = '<div style="font-size:12.5px;color:#94a3b8;padding:6px 0;">Tidak ditemukan siswa dengan kata kunci "' + escapeHtml(q) + '".</div>';
        }
    } else {
        resultBox.style.display = 'none';
        resultBox.innerHTML = '';
    }
}

function selectQuickStudent(id) {
    const sel = document.getElementById('selectManualUser');
    sel.value = id;
    manualUserSelected(sel);
    document.getElementById('manualStudentResults').style.display = 'none';
}

function resetManualSearch() {
    const input = document.getElementById('searchManualStudent');
    input.value = '';
    filterManualStudents();
    document.getElementById('selectManualUser').selectedIndex = 0;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function toggleItem(id) {
    const chk = document.getElementById('chk_' + id);
    const qty = document.getElementById('qty_' + id);
    if (chk && qty) {
        qty.disabled = !chk.checked;
        if (chk.checked) qty.focus();
    }
}

function filterItemTable() {
    const input = document.getElementById('searchItemInput');
    const query = (input ? input.value : '').toLowerCase().trim();
    const rows = document.querySelectorAll('#itemsTableBody tr');
    let visibleCount = 0;
    rows.forEach(tr => {
        const text = tr.textContent.toLowerCase();
        if (query === '' || text.includes(query)) {
            tr.style.display = '';
            visibleCount++;
        } else {
            tr.style.display = 'none';
        }
    });
    const countElem = document.getElementById('searchItemCount');
    if (countElem) {
        countElem.textContent = 'Menampilkan ' + visibleCount + ' peralatan lab';
    }
}

function setStudentData(user) {
    document.getElementById('inputUserId').value = user.id;
    document.getElementById('inputRfidUid').value = user.rfid_uid || '';
    
    document.getElementById('noStudentState').style.display = 'none';
    document.getElementById('verifiedStudentState').style.display = 'block';

    document.getElementById('displayNama').textContent = user.nama;
    document.getElementById('displayNis').textContent = user.nis;
    document.getElementById('displayKelas').textContent = user.kelas;
    document.getElementById('displayRfid').textContent = user.rfid_uid ? 'CHIP: ' + user.rfid_uid : 'TANPA CHIP';
    
    const statusPinjam = document.getElementById('displayStatusPinjam');
    if (user.active_loans > 0) {
        statusPinjam.textContent = 'Perhatian: Siswa memiliki ' + user.active_loans + ' transaksi pinjam aktif yang belum dikembalikan.';
        statusPinjam.style.color = '#f59e0b';
    } else {
        statusPinjam.textContent = '✓ Siswa bebas pinjaman aktif, siap meminjam.';
        statusPinjam.style.color = '#86efac';
    }
}

function lookupRfid() {
    const input = document.getElementById('rfidScanInput');
    const val = input.value.trim();
    if (!val) {
        alert('Silakan tempelkan kartu RFID ke alat reader.');
        input.focus();
        return;
    }

    fetch('ajax_rfid.php?uid=' + encodeURIComponent(val))
        .then(res => res.json())
        .then(data => {
            if (data.success && data.user) {
                if (window.SIPALASound) SIPALASound.beepSuccess();
                setStudentData(data.user);
            } else {
                if (window.SIPALASound) SIPALASound.beepError();
                alert(data.message || 'Kartu tidak dikenali!');
                input.select();
            }
        })
        .catch(err => {
            console.error(err);
            alert('Gagal menghubungi server untuk validasi RFID.');
        });
}

// Otomatis lookup jika reader mengirim Enter
document.getElementById('rfidScanInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        lookupRfid();
    }
});

function manualUserSelected(sel) {
    const opt = sel.options[sel.selectedIndex];
    if (!opt.value) return;
    setStudentData({
        id: opt.value,
        nama: opt.getAttribute('data-nama'),
        nis: opt.getAttribute('data-nis'),
        kelas: opt.getAttribute('data-kelas'),
        rfid_uid: opt.getAttribute('data-rfid'),
        active_loans: 0
    });
}
</script>

<?php page_end(); ?>
