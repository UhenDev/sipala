<?php

/**
 * FILE       : admin/unit/index.php
 * MODUL      : Manajemen Unit Fisik Peralatan & Barcode
 * FUNGSI     : Melacak inventaris unit fisik individual, barcode unik, nomor seri, status pelabelan, kondisi, dan status peminjaman
 * AKSES      : Administrator / Petugas Laboratorium
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../../includes/qrcode.php';
require_once __DIR__ . '/../../includes/barcode.php';

// Handle single delete via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    require_csrf();
    $unitId = (int)($_POST['id'] ?? 0);

    try {
        $st = $pdo->prepare("SELECT id, peralatan_id, kode_unit, barcode, status FROM unit_peralatan WHERE id = ?");
        $st->execute([$unitId]);
        $unit = $st->fetch();

        if (!$unit) {
            flash('error', 'Unit peralatan tidak ditemukan.');
        } elseif ($unit['status'] === 'DIPINJAM') {
            flash('error', 'Unit tidak dapat dihapus karena saat ini sedang berstatus DIPINJAM.');
        } else {
            $del = $pdo->prepare("DELETE FROM unit_peralatan WHERE id = ?");
            $del->execute([$unitId]);

            sync_peralatan_stock((int)$unit['peralatan_id']);
            audit_log('DELETE', 'UNIT_PERALATAN', $unitId, "Menghapus unit peralatan {$unit['kode_unit']} (Barcode: {$unit['barcode']})");

            flash('success', "Unit peralatan {$unit['kode_unit']} berhasil dihapus.");
        }
    } catch (Throwable $e) {
        flash('error', 'Gagal menghapus unit: ' . $e->getMessage());
    }
    redirect('index.php');
}

// Ambil daftar master peralatan untuk dropdown filter
$equipmentList = $pdo->query("SELECT id, nama_barang, kode_barang FROM peralatan ORDER BY nama_barang ASC")->fetchAll();

// Filter parameters
$selectedAlat = (int)($_GET['peralatan_id'] ?? 0);
$selectedStatus = trim((string)($_GET['status'] ?? ''));
$selectedKondisi = trim((string)($_GET['kondisi'] ?? ''));
$selectedLabel = trim((string)($_GET['status_label'] ?? ''));
$search = trim((string)($_GET['q'] ?? ''));

$sql = "
    SELECT u.id, u.kode_unit, u.barcode, u.serial_number, u.rfid_uid, u.kondisi, u.lokasi, u.status,
           u.status_label, u.label_printed_at, u.catatan, u.created_at,
           p.id AS peralatan_id, p.nama_barang, p.kode_barang, k.nama_kategori
    FROM unit_peralatan u
    JOIN peralatan p ON p.id = u.peralatan_id
    LEFT JOIN kategori k ON k.id = p.kategori_id
    WHERE 1=1
";
$params = [];

if ($selectedAlat > 0) {
    $sql .= " AND u.peralatan_id = ?";
    $params[] = $selectedAlat;
}

if ($selectedStatus !== '') {
    $sql .= " AND u.status = ?";
    $params[] = $selectedStatus;
}

if ($selectedKondisi !== '') {
    $sql .= " AND u.kondisi = ?";
    $params[] = $selectedKondisi;
}

if ($selectedLabel !== '') {
    $sql .= " AND u.status_label = ?";
    $params[] = $selectedLabel;
}

if ($search !== '') {
    $sql .= " AND (u.barcode LIKE ? OR u.kode_unit LIKE ? OR u.serial_number LIKE ? OR u.rfid_uid LIKE ? OR u.lokasi LIKE ? OR p.nama_barang LIKE ?)";
    $term = '%' . $search . '%';
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$sql .= " ORDER BY p.nama_barang ASC, u.kode_unit ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Global stats
$totalUnits = (int)$pdo->query("SELECT COUNT(*) FROM unit_peralatan")->fetchColumn();
$totalTersedia = (int)$pdo->query("SELECT COUNT(*) FROM unit_peralatan WHERE status = 'TERSEDIA' AND kondisi != 'Rusak Berat'")->fetchColumn();
$totalDipinjam = (int)$pdo->query("SELECT COUNT(*) FROM unit_peralatan WHERE status = 'DIPINJAM'")->fetchColumn();
$totalBelumLabel = (int)$pdo->query("SELECT COUNT(*) FROM unit_peralatan WHERE status_label = 'BELUM_DILABELI'")->fetchColumn();

// Helper badge status label fisik
function label_status_badge(string $statusLabel): string {
    return match ($statusLabel) {
        'TERVERIFIKASI' => '<span style="display:inline-flex;align-items:center;gap:3px;padding:3px 8px;border-radius:12px;font-size:10.5px;font-weight:700;background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;">✓ Terverifikasi</span>',
        'SUDAH_DILABELI' => '<span style="display:inline-flex;align-items:center;gap:3px;padding:3px 8px;border-radius:12px;font-size:10.5px;font-weight:700;background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;">🖨 Sudah Dicetak</span>',
        default => '<span style="display:inline-flex;align-items:center;gap:3px;padding:3px 8px;border-radius:12px;font-size:10.5px;font-weight:700;background:#fef3c7;color:#b45309;border:1px solid #fde68a;">⚠️ Belum Dilabeli</span>'
    };
}

page_start('Unit Peralatan', 'Pelacakan inventaris unit fisik & barcode laboratorium', 'unit');
?>

<div class="head">
    <div>
        <h2>Unit Fisik Peralatan & Barcode Laboratorium</h2>
        <p>Kelola nomor barcode unik per unit alat, nomor seri fisik, pencetakan label stiker, dan status verifikasi.</p>
    </div>
    <div class="actions">
        <a class="btn" href="../peralatan/index.php">← Master Peralatan</a>
        <button type="button" class="btn" onclick="openScannerTestModal()" style="background:#f8fafc;color:#0f172a;border:1px solid #cbd5e1;font-weight:600;display:inline-flex;align-items:center;gap:6px;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7V5a2 2 0 0 1 2-2h2"></path><path d="M17 3h2a2 2 0 0 1 2 2v2"></path><path d="M21 17v2a2 2 0 0 1-2 2h-2"></path><path d="M7 21H5a2 2 0 0 1-2-2v-2"></path><line x1="3" y1="12" x2="21" y2="12"></line></svg>
            Scan & Uji Barcode
        </button>
        <button type="button" id="btnBulkPrint" class="btn" onclick="printSelectedUnits()" disabled style="background:#f8fafc;color:#475569;border:1px solid #cbd5e1;font-weight:600;display:inline-flex;align-items:center;gap:6px;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
            Cetak Barcode Terpilih (<span id="selectedCount">0</span>)
        </button>
        <a class="btn" href="cetak_label.php" target="_blank" style="background:#f8fafc;color:#0f172a;border:1px solid #cbd5e1;font-weight:600;display:inline-flex;align-items:center;gap:6px;">
            Cetak Semua Label
        </a>
        <a class="btn btn-primary" href="tambah.php">+ Tambah Unit Fisik</a>
    </div>
</div>

<div class="stats">
    <div class="stat"><small>Total Seluruh Unit</small><strong><?= $totalUnits ?></strong></div>
    <div class="stat"><small>Unit Tersedia (Siap)</small><strong style="color:#16a34a;"><?= $totalTersedia ?></strong></div>
    <div class="stat"><small>Unit Sedang Dipinjam</small><strong style="color:#2563eb;"><?= $totalDipinjam ?></strong></div>
    <div class="stat"><small>Belum Ditempeli Label</small><strong style="color:#d97706;"><?= $totalBelumLabel ?></strong></div>
</div>

<div class="panel" style="margin-bottom: 20px;">
    <form method="GET" action="index.php" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
        <div class="field" style="flex:1;min-width:220px;">
            <label>Cari Barcode / Unit / Serial / Nama Alat</label>
            <input type="text" name="q" placeholder="Ketik barcode SIP-ALT-..., kode unit, serial..." value="<?= e($search) ?>">
        </div>

        <div class="field" style="min-width:180px;">
            <label>Master Peralatan</label>
            <select name="peralatan_id">
                <option value="">-- Semua Peralatan --</option>
                <?php foreach ($equipmentList as $eq): ?>
                    <option value="<?= $eq['id'] ?>" <?= $selectedAlat === (int)$eq['id'] ? 'selected' : '' ?>>
                        <?= e($eq['nama_barang']) ?> (<?= e($eq['kode_barang']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field" style="min-width:140px;">
            <label>Status Pinjam</label>
            <select name="status">
                <option value="">-- Semua Status --</option>
                <option value="TERSEDIA" <?= $selectedStatus === 'TERSEDIA' ? 'selected' : '' ?>>TERSEDIA</option>
                <option value="DIPINJAM" <?= $selectedStatus === 'DIPINJAM' ? 'selected' : '' ?>>DIPINJAM</option>
                <option value="RUSAK" <?= $selectedStatus === 'RUSAK' ? 'selected' : '' ?>>RUSAK</option>
                <option value="HILANG" <?= $selectedStatus === 'HILANG' ? 'selected' : '' ?>>HILANG</option>
                <option value="MAINTENANCE" <?= $selectedStatus === 'MAINTENANCE' ? 'selected' : '' ?>>MAINTENANCE</option>
            </select>
        </div>

        <div class="field" style="min-width:140px;">
            <label>Status Label Stiker</label>
            <select name="status_label">
                <option value="">-- Semua Label --</option>
                <option value="BELUM_DILABELI" <?= $selectedLabel === 'BELUM_DILABELI' ? 'selected' : '' ?>>Belum Dilabeli</option>
                <option value="SUDAH_DILABELI" <?= $selectedLabel === 'SUDAH_DILABELI' ? 'selected' : '' ?>>Sudah Dicetak</option>
                <option value="TERVERIFIKASI" <?= $selectedLabel === 'TERVERIFIKASI' ? 'selected' : '' ?>>Terverifikasi</option>
            </select>
        </div>

        <div style="display:flex;gap:6px;">
            <button type="submit" class="btn btn-primary" style="height:38px;">Filter</button>
            <?php if ($selectedAlat || $selectedStatus || $selectedKondisi || $selectedLabel || $search): ?>
                <a href="index.php" class="btn" style="height:38px;">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="panel">
    <div class="panel-title" style="display:flex;justify-content:space-between;align-items:center;">
        <span>Daftar Unit Fisik & Barcode Alat (<?= count($rows) ?> unit)</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:36px;text-align:center;">
                        <input type="checkbox" id="checkAllUnits" onclick="toggleSelectAll(this)" title="Pilih Semua Unit di Halaman Ini">
                    </th>
                    <th style="width:40px;">No</th>
                    <th>Barcode & Kode Unit</th>
                    <th>Alat Master</th>
                    <th>No. Seri / RFID</th>
                    <th>Kondisi Fisik</th>
                    <th>Lokasi Rak</th>
                    <th>Status Unit</th>
                    <th>Status Label</th>
                    <th style="text-align:right;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="10"><div class="empty">Tidak ada unit peralatan yang sesuai dengan filter.</div></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $i => $r): ?>
                        <?php 
                            $barcodeStr = (string)($r['barcode'] ?: $r['kode_unit']);
                        ?>
                        <tr id="row_unit_<?= $r['id'] ?>">
                            <td style="text-align:center;">
                                <input type="checkbox" class="unit-cb" value="<?= $r['id'] ?>" onchange="updateSelectedCount()">
                            </td>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <div style="display:flex;flex-direction:column;gap:2px;">
                                    <strong style="font-family:monospace;font-size:13px;color:#1e40af;letter-spacing:0.5px;">
                                        <?= e($barcodeStr) ?>
                                    </strong>
                                    <?php if ($r['kode_unit'] !== $barcodeStr): ?>
                                        <div style="font-size:11px;color:#64748b;font-family:monospace;">Unit: <?= e($r['kode_unit']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <strong><?= e($r['nama_barang']) ?></strong>
                                <div style="font-size:11px;color:#64748b;"><?= e($r['nama_kategori'] ?? '-') ?> (Kode: <?= e($r['kode_barang']) ?>)</div>
                            </td>
                            <td>
                                <?php if ($r['serial_number']): ?>
                                    <div style="font-size:11px;font-family:monospace;color:#475569;">SN: <?= e($r['serial_number']) ?></div>
                                <?php endif; ?>
                                <?php if ($r['rfid_uid']): ?>
                                    <span style="display:inline-flex;align-items:center;font-size:10px;background:#f1f5f9;padding:2px 6px;border-radius:4px;border:1px solid #cbd5e1;font-family:monospace;">
                                        RFID: <?= e($r['rfid_uid']) ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color:#94a3b8;font-size:11px;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $r['kondisi'] === 'Baik' ? 'aktif' : ($r['kondisi'] === 'Rusak Ringan' ? 'MENUNGGU' : 'DITOLAK') ?>">
                                    <?= e($r['kondisi']) ?>
                                </span>
                            </td>
                            <td><?= e($r['lokasi'] ?: '-') ?></td>
                            <td><?= unit_status_badge($r['status']) ?></td>
                            <td><?= label_status_badge($r['status_label'] ?? 'BELUM_DILABELI') ?></td>
                            <td style="text-align:right;">
                                <div class="actions" style="justify-content:flex-end;">
                                    <a class="btn btn-sm" href="cetak_label.php?id=<?= $r['id'] ?>" target="_blank" 
                                       title="Cetak label barcode untuk unit ini" 
                                       style="font-size:11.5px;padding:5px 9px;background:#2563eb;color:#ffffff;border:none;font-weight:600;">
                                        🏷️ Label
                                    </a>
                                    <a class="btn btn-edit" href="edit.php?id=<?= $r['id'] ?>" style="font-size:11.5px;padding:5px 8px;">Edit</a>
                                    <?php if ($r['status'] !== 'DIPINJAM'): ?>
                                        <form method="POST" action="index.php" style="display:inline;" onsubmit="return confirm('Apakah Anda yakin ingin menghapus unit <?= e($r['kode_unit']) ?>?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                            <button type="submit" class="btn btn-danger" style="font-size:11.5px;padding:5px 8px;">Hapus</button>
                                        </form>
                                    <?php else: ?>
                                        <button type="button" class="btn" style="font-size:11.5px;padding:5px 8px;opacity:0.5;cursor:not-allowed;" title="Tidak dapat dihapus saat sedang dipinjam">Dipinjam</button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL SCAN & UJI BARCODE CEPAT -->
<div class="modal" id="scannerTestModal">
    <div class="modal-box" style="max-width:500px;">
        <h3 style="display:flex;align-items:center;gap:8px;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7V5a2 2 0 0 1 2-2h2"></path><path d="M17 3h2a2 2 0 0 1 2 2v2"></path><path d="M21 17v2a2 2 0 0 1-2 2h-2"></path><path d="M7 21H5a2 2 0 0 1-2-2v-2"></path><line x1="3" y1="12" x2="21" y2="12"></line></svg>
            Uji & Verifikasi Barcode Scanner
        </h3>
        <p style="color:#64748b;font-size:13px;margin-bottom:14px;">
            Pindai label barcode fisik dengan scanner USB atau ketik kode inventaris secara manual untuk menguji keterbacaan data.
        </p>

        <div class="field" style="margin-bottom:12px;">
            <label style="font-weight:700;">Arahkan Scanner ke Kolom Ini:</label>
            <input type="text" id="quickScannerInput" placeholder="Tempelkan atau scan barcode di sini..." 
                   autocomplete="off" style="font-family:monospace;font-size:16px;padding:10px 14px;border:2px solid #2563eb;border-radius:6px;width:100%;">
        </div>

        <div id="quickScanResult" style="display:none;padding:12px;border-radius:6px;font-size:13px;margin-bottom:14px;"></div>

        <div class="modal-actions">
            <button type="button" class="btn" onclick="closeScannerTestModal()">Tutup</button>
        </div>
    </div>
</div>

<script>
function toggleSelectAll(masterCb) {
    const cbs = document.querySelectorAll('.unit-cb');
    cbs.forEach(cb => cb.checked = masterCb.checked);
    updateSelectedCount();
}

function updateSelectedCount() {
    const checked = document.querySelectorAll('.unit-cb:checked');
    const count = checked.length;
    const countSpan = document.getElementById('selectedCount');
    const btn = document.getElementById('btnBulkPrint');
    
    if (countSpan) countSpan.textContent = count.toString();
    if (btn) {
        if (count > 0) {
            btn.disabled = false;
            btn.style.background = '#2563eb';
            btn.style.color = '#ffffff';
            btn.style.borderColor = '#1d4ed8';
        } else {
            btn.disabled = true;
            btn.style.background = '#f8fafc';
            btn.style.color = '#475569';
            btn.style.borderColor = '#cbd5e1';
        }
    }
}

function printSelectedUnits() {
    const checked = document.querySelectorAll('.unit-cb:checked');
    if (checked.length === 0) {
        alert('Silakan centang minimal satu unit yang ingin dicetak label barcodenya.');
        return;
    }
    const ids = Array.from(checked).map(cb => cb.value).join(',');
    window.open('cetak_label.php?ids=' + encodeURIComponent(ids), '_blank');
}

function openScannerTestModal() {
    const modal = document.getElementById('scannerTestModal');
    const inp = document.getElementById('quickScannerInput');
    const res = document.getElementById('quickScanResult');
    if (modal) modal.classList.add('show');
    if (res) res.style.display = 'none';
    if (inp) {
        inp.value = '';
        setTimeout(() => inp.focus(), 200);
    }
}

function closeScannerTestModal() {
    const modal = document.getElementById('scannerTestModal');
    if (modal) modal.classList.remove('show');
}

// Scanner event handler inside quick modal
document.addEventListener('DOMContentLoaded', function() {
    const inp = document.getElementById('quickScannerInput');
    const res = document.getElementById('quickScanResult');
    if (!inp) return;

    let scanTimer = null;
    inp.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            executeScanLookup(this.value.trim());
        }
    });

    inp.addEventListener('input', function() {
        clearTimeout(scanTimer);
        const val = this.value.trim();
        if (val.length >= 6) {
            scanTimer = setTimeout(() => {
                executeScanLookup(val);
            }, 300);
        }
    });

    function executeScanLookup(code) {
        if (!code) return;
        res.style.display = 'block';
        res.style.background = '#f8fafc';
        res.style.color = '#334155';
        res.innerHTML = 'Memeriksa barcode: <strong>' + escapeHtml(code) + '</strong>...';

        fetch('../api_barcode_lookup.php?action=lookup_unit&barcode=' + encodeURIComponent(code))
            .then(r => r.json())
            .then(data => {
                if (data.success && data.unit) {
                    res.style.background = '#dcfce7';
                    res.style.color = '#15803d';
                    res.innerHTML = '<strong>✓ Barcode Valid & Terdaftar!</strong><br>' +
                        'Alat: <strong>' + escapeHtml(data.unit.nama_barang) + '</strong><br>' +
                        'Barcode: <code>' + escapeHtml(data.unit.barcode) + '</code> &bull; Unit: <code>' + escapeHtml(data.unit.kode_unit) + '</code><br>' +
                        'Lokasi: ' + escapeHtml(data.unit.lokasi) + ' &bull; Status: <strong>' + escapeHtml(data.unit.status) + '</strong>';

                    // Jika belum diverifikasi, tawarkan tombol verifikasi label
                    if (data.unit.status_label !== 'TERVERIFIKASI') {
                        res.innerHTML += '<div style="margin-top:10px;"><button type="button" class="btn btn-sm btn-primary" onclick="markLabelVerified(' + data.unit.id + ')">Verifikasi Penempelan Label Fisik</button></div>';
                    }
                } else {
                    res.style.background = '#fee2e2';
                    res.style.color = '#b91c1c';
                    res.innerHTML = '<strong>❌ Tidak Ditemukan:</strong><br>' + escapeHtml(data.message || 'Kode barcode tidak terdaftar.');
                }
                inp.value = '';
                inp.focus();
            })
            .catch(() => {
                res.style.background = '#fee2e2';
                res.style.color = '#b91c1c';
                res.innerHTML = 'Gagal menghubungi server verifikasi barcode.';
            });
    }

    function escapeHtml(s) {
        return (s || '').replace(/[&<>"']/g, function(m) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[m];
        });
    }
});

function markLabelVerified(unitId) {
    const formData = new FormData();
    formData.append('action', 'verify_label');
    formData.append('unit_id', unitId.toString());
    formData.append('csrf_token', '<?= csrf_token() ?>');

    fetch('../api_barcode_lookup.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('Label barcode berhasil diverifikasi!');
            window.location.reload();
        } else {
            alert('Gagal: ' + (data.message || 'Terjadi kesalahan'));
        }
    });
}
</script>

<?php page_end(); ?>
