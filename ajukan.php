<?php
declare(strict_types=1);
session_name('sipala_session');
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/database.php';
$pdo = db();
function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'peminjam') { header('Location: login.php'); exit; }
$userId=(int)$_SESSION['user_id'];
$preselectedTool=(int)($_GET['alat']??0);
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if (!hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token']??''))) throw new RuntimeException('Sesi keamanan tidak valid.');
        $tglPinjam=(string)($_POST['tanggal_pinjam']??date('Y-m-d'));
        $tglKembali=(string)($_POST['tanggal_rencana_kembali']??'');
        $keperluan=trim((string)($_POST['keperluan']??''));
        $items=$_POST['items']??[];
        $valid=[];
        foreach($items as $id=>$qty){$qty=(int)$qty;if($qty>0)$valid[(int)$id]=$qty;}
        if (!$valid) throw new RuntimeException('Pilih minimal satu alat.');
        if ($keperluan==='') throw new RuntimeException('Keperluan wajib diisi.');
        if ($tglPinjam < date('Y-m-d')) throw new RuntimeException('Tanggal pinjam tidak boleh sebelum hari ini.');
        if ($tglKembali < $tglPinjam) throw new RuntimeException('Tanggal rencana kembali harus setelah atau sama dengan tanggal pinjam.');
        $cek=$pdo->prepare("SELECT COUNT(*) FROM peminjaman WHERE user_id=? AND status IN ('MENUNGGU','DISETUJUI','DIPINJAM','MENUNGGU_PENGEMBALIAN')");$cek->execute([$userId]);
        if ((int)$cek->fetchColumn()>0) throw new RuntimeException('Masih ada peminjaman Anda yang belum selesai. Selesaikan dulu sebelum mengajukan peminjaman baru.');
        $pdo->beginTransaction();
        $kode='PJM-'.date('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,6));
        $ins=$pdo->prepare('INSERT INTO peminjaman (kode_peminjaman,user_id,rfid_uid,tanggal_pengajuan,tanggal_pinjam,tanggal_rencana_kembali,keperluan,status) VALUES (?,?,?,?,?,?,?,"MENUNGGU")');
        $ins->execute([$kode,$userId,$_SESSION['rfid_uid']??null,date('Y-m-d'),$tglPinjam,$tglKembali,$keperluan]);
        $pid=(int)$pdo->lastInsertId();
        $get=$pdo->prepare('SELECT id,nama_barang,jumlah_tersedia,status FROM peralatan WHERE id=? FOR UPDATE');
        $det=$pdo->prepare('INSERT INTO detail_peminjaman (peminjaman_id,peralatan_id,jumlah,kondisi_awal) VALUES (?,?,?,"Baik")');
        foreach($valid as $itemId=>$qty){
            $get->execute([$itemId]);$item=$get->fetch();
            if(!$item || $item['status']!=='aktif') throw new RuntimeException('Ada alat yang sudah tidak aktif.');
            if((int)$item['jumlah_tersedia']<$qty) throw new RuntimeException('Stok '.$item['nama_barang'].' tidak mencukupi.');
            $det->execute([$pid,$itemId,$qty]);
        }
        $pdo->commit();
        try{$lg=$pdo->prepare("INSERT INTO log_aktivitas (user_id,aktivitas,deskripsi,ip_address) VALUES (?, 'PENGAJUAN_PEMINJAMAN', ?, ?)");$lg->execute([$userId,'Mengajukan '.$kode,$_SERVER['REMOTE_ADDR']??null]);}catch(Throwable $e){}
        $_SESSION['flash_success']='Pengajuan '.$kode.' berhasil dikirim dan menunggu persetujuan petugas.';
        header('Location: index.php'); exit;
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}
}
$catalog=$pdo->query('SELECT p.id,p.kode_barang,p.nama_barang,p.jumlah_tersedia,p.kondisi,p.foto,k.nama_kategori FROM peralatan p LEFT JOIN kategori k ON k.id=p.kategori_id WHERE p.status="aktif" AND p.jumlah_tersedia>0 ORDER BY p.nama_barang')->fetchAll();
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ajukan Peminjaman - SIPALA</title><link rel="stylesheet" href="assets/css/rfid.css"><style>
body{margin:0;font-family:'Segoe UI',sans-serif;background:#f8fafc;color:#0f172a}.navbar{background:#fff;border-bottom:1px solid #e2e8f0;padding:16px 32px;display:flex;justify-content:space-between;align-items:center}.brand{font-weight:800;font-size:20px;color:#1d4ed8}.btn{display:inline-flex;align-items:center;justify-content:center;padding:9px 15px;border-radius:8px;border:1px solid #cbd5e1;background:#fff;text-decoration:none;color:#334155;font-weight:600;font-size:13px}.primary{background:#2563eb;color:#fff;border-color:#2563eb}.wrap{max-width:1100px;margin:30px auto;padding:0 20px}.panel{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:24px;margin-bottom:20px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.field{display:flex;flex-direction:column;gap:7px}.field.full{grid-column:1/-1}.field label{font-size:12px;font-weight:700;color:#475569}.field input,.field textarea{padding:11px 13px;border:1px solid #cbd5e1;border-radius:8px;font:inherit}.items{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px}.item{border:1px solid #e2e8f0;border-radius:12px;padding:15px}.item h4{margin:0 0 4px}.qty{width:80px;padding:8px;border:1px solid #cbd5e1;border-radius:7px}.alert{padding:13px 16px;border-radius:9px;margin-bottom:18px}.error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}@media(max-width:700px){.grid{grid-template-columns:1fr}.navbar{padding:14px 18px}}

.item.selected { border-color:#93c5fd; background:#eff6ff; box-shadow:0 0 0 2px rgba(37,99,235,.08); }
.item .tool-photo { width:100%;height:120px;object-fit:cover;border-radius:9px;background:#f8fafc;border:1px solid #e2e8f0;margin-bottom:10px; }
.item .tool-photo.empty { display:flex;align-items:center;justify-content:center;font-size:30px;color:#94a3b8; }
@media(max-width:700px){.wrap{margin:20px auto}.panel{padding:18px}.items{grid-template-columns:1fr}.navbar .btn{padding:8px 11px}}
</style></head><body><nav class="navbar"><div class="brand">📦 SIPALA</div><a class="btn" href="index.php">← Kembali</a></nav><main class="wrap"><h1 style="margin-bottom:4px;">Ajukan Peminjaman</h1><p style="color:#64748b">Pilih alat yang diperlukan. Pengajuan akan diperiksa petugas sebelum alat diserahkan.</p><?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?><form method="post"><input type="hidden" name="csrf_token" value="<?=e($_SESSION['csrf_token'])?>"><div class="panel"><h3>Jadwal & Keperluan</h3><div class="grid"><div class="field"><label>Tanggal Pinjam</label><input type="date" name="tanggal_pinjam" min="<?=date('Y-m-d')?>" value="<?=e($_POST['tanggal_pinjam']??date('Y-m-d'))?>" required></div><div class="field"><label>Rencana Kembali</label><input type="date" name="tanggal_rencana_kembali" min="<?=date('Y-m-d')?>" value="<?=e($_POST['tanggal_rencana_kembali']??date('Y-m-d',strtotime('+1 day')))?>" required></div><div class="field full"><label>Keperluan</label><textarea name="keperluan" rows="3" placeholder="Contoh: Praktikum jaringan di lab..." required><?=e($_POST['keperluan']??'')?></textarea></div></div></div><div class="panel"><h3>Pilih Peralatan</h3><div class="items"><?php foreach($catalog as $it):?><div class="item <?= $preselectedTool === (int)$it['id'] ? 'selected' : '' ?>">
<?php if (!empty($it['foto'])): ?><img class="tool-photo" src="<?=e($it['foto'])?>" alt="<?=e($it['nama_barang'])?>"><?php else: ?><div class="tool-photo empty">🔧</div><?php endif; ?>
<h4><?=e($it['nama_barang'])?></h4><div style="font-size:12px;color:#64748b"><?=e($it['kode_barang'])?> · <?=e($it['nama_kategori']??'-')?></div><div style="margin-top:10px"><span>Stok: <b><?=e((string)$it['jumlah_tersedia'])?></b></span><label style="float:right;font-size:12px;font-weight:700">Jumlah <input class="qty" type="number" min="0" max="<?=e((string)$it['jumlah_tersedia'])?>" name="items[<?=e((string)$it['id'])?>]" value="<?= ($preselectedTool === (int)$it['id']) ? 1 : 0 ?>"></label></div></div><?php endforeach;?></div><?php if(!$catalog):?><p style="color:#64748b">Belum ada alat yang tersedia.</p><?php endif;?></div><div style="display:flex;justify-content:flex-end;gap:10px"><a class="btn" href="index.php">Batal</a><button class="btn primary" type="submit">📨 Ajukan Peminjaman</button></div></form></main></body></html>
